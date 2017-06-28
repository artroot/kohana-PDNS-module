<?php

/**
 * Created by PhpStorm.
 * User: artroot
 * Date: 21.06.17
 * Time: 10:50
 * @property int    $id
 * @property string $name
 * @property string $master
 * @property int    $last_check
 * @property string $type
 * @property int    $notified_serial
 * @property string $account
 *
 * @property object $records    Relations with Model_Record
 * @property string $mode    Forward || Reverse
 * @var      array  $SOAContentKeys     Default content keys in SOA record
 */

use Model_Record as Records;

class Model_Domain extends ORM
{
    const REVERSE_PREFIX = '.in-addr.arpa';
    const MASTER = 'MASTER', SLAVE = 'SLAVE', CACHE = 'CACHE', STEALTH = 'STEALTH', ROOT = 'ROOT';
    const FORWARD_MODE = 'forward';
    const REVERSE_MODE = 'reverse';
    protected $_db_group = 'powerdns';
    protected $_has_many = [
        'records' => [
            'model' => 'Record',
            'foreign_key' => 'domain_id',
        ]
    ];
    private $mode;

    public static $SOAContentKeys = [
        'origin' => 'ns.local.',
        'mail_addr' => null,
        'serial' => null,
        'refresh' => 10800,
        'retry' => 3600,
        'expire' => 604800,
        'minimum' => 86400
    ];

    public function create(Validation $validation = NULL)
    {
        // Check zone existence
        $this->checkExist();
        // Fwd to parent
        parent::create($validation);
        // Create default SOA & NS records
        $this->SOA()->create();
        $this->NS()->create();
        return $this;
    }

    /**
     * Work with forward zones
     * @param array $properties
     * @return $this
     * @throws Exception
     */
    public function forward($properties = []) // $domain->forward($properties)->create();
    {
        $this->mode = self::FORWARD_MODE;
        foreach ($properties as $property => $value) $this->{$property} = $value;
        return $this;
    }

    /**
     * Work with reverse zones
     * @param array $properties
     * @return $this
     * @throws Exception
     */
    public function reverse($properties = []) // $domain->reverse($properties)->create();
    {
        if (isset($properties['name']) and $properties['name'] = substr(self::reverseCase($properties['name']), 2) and $properties['name'] === false) throw new Exception('Bad name');
        $this->mode = self::REVERSE_MODE;
        foreach ($properties as $property => $value) $this->{$property} = $value;
        return $this;
    }

    /**
     * Check zone existence (doubles)
     * @return $this
     * @throws Exception
     */
    private function checkExist(){
        if (empty($this->name)) {
            $this->reset();
            throw new Exception('Пустое имя зоны.');
        }
        $domain = new self(['name' =>  $this->name]);
        if (isset($domain->id)) {
            $this->reset();
            throw new Exception('Зона уже существует.');
        }
    }

    /**
     * @param string $ip
     * @return bool|string Reverse $ip + .in-addr.arpa
     */
    public static function reverseCase($ip = null)
    {
        return (filter_var($ip, FILTER_VALIDATE_IP) ? implode('.',array_reverse(explode('.',$ip))) . self::REVERSE_PREFIX : false);
    }

    /**
     * Validate SOA content && existence of serial.
     * Explode @param string $SOAContent to array $SOAContentParameters
     * @return array Content parameters
     * @throws Exception
     */
    private static function validateSOAContent($SOAContent = null){
        if (empty($SOAContent) or !is_string($SOAContent)) throw new Exception('SOA content пуст или не строка.');
        if ($SOAContentValues = preg_split('/\s+/', trim($SOAContent)) and count($SOAContentValues) < count(array_keys(self::$SOAContentKeys))) throw new Exception('Неправильный SOA content.');
        if ($SOAContentParameters = array_combine(array_keys(self::$SOAContentKeys), $SOAContentValues) and !$SOAContentParameters) throw new Exception('Неправильный SOA content.');
        if (!is_numeric($SOAContentParameters['serial'])) throw new Exception('Неправильный SOA content serial.'); // TODO more rules
        return $SOAContentParameters;
    }

    /**
     * Work with SOA records
     * @throws Exception
     * @return Records object
     */
    public function SOA()
    {
        $SOAContent = implode(' ',(array_replace(self::$SOAContentKeys, ['mail_addr' => $this->name], ['serial' => date('YmdH')])));
        self::validateSOAContent($SOAContent);
        return $this->records->record(Records::SOA, [
                'domain_id' => $this->id,
                'name' => $this->name,
                'content' => $SOAContent,
                'ttl' => Records::DEFAULT_TTL,
        ]);
    }

    /**
     * Work with SOA records
     * @throws Exception
     * @return Records object
     */
    public function NS()
    {
        return $this->records->record(Records::NS, [
                'domain_id' => $this->id,
                'name' => $this->name,
                'content' => rtrim(self::$SOAContentKeys['origin'], '.'),
                'ttl' => Records::DEFAULT_TTL,
        ]);
    }

    /**
     * Work with A records
     * @param array $properties ['name', 'content', 'ttl']
     * @throws Exception
     * @return Records object
     */
    public function A($properties = [])
    {
        if ($this->mode == self::REVERSE_MODE) throw new Exception('A запись только для прямых зон.');
        if (!isset($properties['name']) or empty($properties['name'])) throw new Exception('Имя не должно быть пустым.');
        if (!isset($properties['content']) or empty($properties['content'])) throw new Exception('Контент не должен быть пустым.');
        return $this->records->record(Records::A, [
                'domain_id' => $this->id,
                'name' => trim($properties['name'] . '.' . $this->name),
                'content' => trim($properties['content']),
                'ttl' => ((isset($properties['ttl']) and is_numeric(intval($properties['ttl']))) ? intval($properties['ttl']) : Records::DEFAULT_TTL),
        ]);
    }

    /**
     * Work with PTR records
     * @param array $properties ['name', 'content', 'ttl']
     * @throws Exception
     * @return Records object
     */
    public function PTR($properties = [])
    {
        if ($this->mode == self::FORWARD_MODE) throw new Exception('PTR запись только для обратных зон.');
        if (!isset($properties['name']) or empty($properties['name'])) throw new Exception('Имя не должно быть пустым.');
        if (!isset($properties['content']) or empty($properties['content'])) throw new Exception('Контент не должен быть пустым.');
        $properties['name'] = self::reverseCase($properties['name']);
        return $this->records->record(Records::PTR, [
                'domain_id' => $this->id,
                'name' => trim($properties['name']),
                'content' => trim($properties['content']),
                'ttl' => ((isset($properties['ttl']) and is_numeric(intval($properties['ttl']))) ? intval($properties['ttl']) : Records::DEFAULT_TTL),
        ]);
    }


    /**
     * Increment serial parameter in SOA record
     * @return bool
     * @throws Exception
     */
    public function refreshSOA()
    {
        if ($SOAParameters = $this->getSOAContentParameters() and (!is_array($SOAParameters) or !isset($SOAParameters['serial']))) throw new Exception('Ошибка получения SOA content serial');

        if ($SOA = $this->records->getSOA())
        if (!empty($SOAParameters['serial']) and $SOAParameters['serial']++)
        if ($SOAContent = implode(' ', $SOAParameters) and self::validateSOAContent($SOAContent)) $SOA->content = $SOAContent;
        return ($SOA->save() ? true : false);
    }

    /**
     * @return array
     */
    private function getSOAContentParameters()
    {
        return self::validateSOAContent($this->records->getSOA()->content);
    }

}