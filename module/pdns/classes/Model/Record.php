<?php

/**
 * Created by PhpStorm.
 * User: art
 * Date: 21.06.17
 * Time: 10:51
 * @property $id
 * @property $domain_id
 * @property $name
 * @property $type
 * @property $content
 * @property $ttl
 * @property $prio
 * @property $change_date
 * @property $disabled
 * @property $ordername
 * @property $auth
 *
 */

class Model_Record extends ORM
{
    protected $_db_group = 'powerdns';

    const SOA = 'SOA', A = 'A', MX = 'MX', NS = 'NS', PTR = 'PTR', A6 = 'A6', AAAA = 'AAAA', AFSDB = 'AFSDB', CNAME = 'CNAME';
    const DNAME = 'DNAME', DNSKEY = 'DNSKEY', DS = 'DS', HINFO = 'HINFO', ISDN = 'ISDN', KEY = 'KEY', KX = 'KX', LOC = 'LOC', MB = 'MB';
    const MG = 'MG', MINFO = 'MINFO', MR = 'MR', NAPTR = 'NAPTR', NULL = 'NULL', NSAP = 'NSAP', NSEC = 'NSEC', NSEC3 = 'NSEC3', NSEC3PARAM = 'NSEC3PARAM';
    const PX = 'PX', RP = 'RP', RRSIG = 'RRSIG', RT = 'RT', SIG = 'SIG', SRV = 'SRV', SSHFP = 'SSHFP', TKEY = 'TKEY', TLSA = 'TLSA';
    const TSIG = 'TSIG', TXT = 'TXT', WKS = 'WKS', X25 = 'X25';

    const DEFAULT_TTL = 3600;

    public function create(Validation $validation = NULL)
    {
        $this->checkExist();
        parent::create($validation);
    }

    /**
     * @return object
     */
    public function getSOA()
    {
        return $this->where('type', '=', 'SOA')->find();
    }

    /**
     * Check record existence (doubles)
     * @return $this
     * @throws Exception
     */
    private function checkExist(){
        if (empty($this->name)) throw new Exception('Пустое имя записи.');
        if (empty($this->domain_id)) throw new Exception('Нет связи с доменом.');
        if (empty($this->content)) throw new Exception('Пустой контент записи.');
        if (empty($this->type)) throw new Exception('Пустой тип записи.');
        $record = new self(['name' =>  $this->name, 'content' => $this->content, 'type' => $this->type]);
        if (isset($record->id)) throw new Exception('Запись уже существует.');
    }

    /**
     * Set record properties
     * @param string|bool $type
     * @param array $properties
     * @return $this
     * @throws Exception
     */
    public function record($type = false, $properties = [])
    {
        foreach ($properties as $property => $value) $this->{$property} = $value;

        if  ($type and $this->type = $type) return $this;
        else {
            $this->reset();
            throw new Exception('Тип записи не указан.');
        }
    }
}