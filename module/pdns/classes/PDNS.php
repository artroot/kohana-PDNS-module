<?php  defined('SYSPATH') or die('No direct access allowed.');
/**
 * Created by PhpStorm.
 * User: artroot
 * Date: 21.06.17
 * Time: 12:35
 */

use Model_Domain as Domains;
use Model_Record as Records;

class PDNS extends Kohana_ORM
{
    const DEFAULT_ZONE = 'local';
    const CALL_RM_REC = 'rmRecord';
    
    /**
     * Zones & records synchronization
     * @param array $goals Should be a array of [name => ip ]
     * @param array $forward Forward domain options ['name', 'type']; 'type' - Optional; default: @see Domains::MASTER
     * @param array $reverse Optional Reverse domain options ['type']; default: @see Domains::MASTER
     * @param array $recordTypes Optional array of record types; default: @see Records::A, @see Records::PTR
     *
     * @param string $callback
     * @param array $callbackParams
     * @return bool
     */
    public static function synchronize($goals = [], $forward = [], $reverse = [], $recordTypes = [], $callback = false, $callbackParams = [])
    {
        try {
            // Call callback
            if ($callback and is_callable([get_called_class(), $callback], true, $callable)) call_user_func_array($callable, $callbackParams);

            if ($goals = self::rebuildOverNetworks($goals) and !is_array($goals) or empty($goals)) throw new Exception('Пустой список синхронизации.');
            if (!isset($forward['name']) or empty($forward['name']) or !is_string($forward['name'])) throw new Exception('Прямая зона не указана.');
            $forward['type'] = ((isset($forward['type']) and !empty($forward['type'])) ? $forward['type'] : Domains::MASTER);
            $reverse['type'] = ((isset($reverse['type']) and !empty($reverse['type'])) ? $reverse['type'] : Domains::MASTER);

            $fwdDomain = self::getDomain(Domains::FORWARD_MODE, $forward);
        }catch (Exception $e){
            Kohana::$log->add(Kohana::ERROR, Kohana::exception_text($e))->write();
            return false;
        }

        foreach ($goals as $network => $goal){
            try{
                if (!filter_var($network, FILTER_VALIDATE_IP)) throw new Exception('Неверный формат network.');
                $revDomain = self::getDomain(Domains::REVERSE_MODE, array_merge($reverse, ['name' => $network]));
            }catch (Exception $e){
                Kohana::$log->add(Kohana::ERROR, 'Синхронизация: ' . @$network . ' - ' . Kohana::exception_text($e))->write();
                continue;
            }
            if (!is_array($goal) or (is_array($goal) and empty($goal))) {
                Kohana::$log->add(Kohana::ERROR, 'Синхронизация: ' . @$network . ' - ' . Kohana::exception_text($e))->write();
                continue;
            }
            self::rmRecord((!empty($recordTypes) ? $recordTypes : [Records::A, Records::PTR]), $goal, $forward['name']);
            foreach ($goal as $ip => $name) {
                try{
                    // If сertain types of record create it instead defaults
                    if (!empty($recordTypes)){
                        foreach ($recordTypes as $type) {
                           if ($fwdDomain->{$type}(['name' => $name, 'content' => $ip])->create()) 
                            Kohana::$log->add(Kohana::INFO, 'Синхронизация: ' . $type . ' запись для ' . $name . ' ' . $ip . ' - добавлена.')->write();
                        }
                    }
                    // Create default records
                    else{
                        if ($fwdDomain->A(['name' => $name, 'content' => $ip])->create()) 
                            Kohana::$log->add(Kohana::INFO, 'Синхронизация: A запись для ' . $name . ' ' . $ip . ' - добавлена.')->write();
                        if ($revDomain->PTR(['name' => $ip, 'content' => $name . '.' . $fwdDomain->name])->create()) 
                            Kohana::$log->add(Kohana::INFO, 'Синхронизация: PTR запись для ' . $name . ' ' . $ip . ' - добавлена.')->write();
                    }
                }catch (Exception $e){
                    Kohana::$log->add(Kohana::ERROR, 'Синхронизация: ' . @$name . ' ' . @$ip  . ' - ' . Kohana::exception_text($e))->write();
                continue;
                }
            }
            $fwdDomain->refreshSOA();
            $revDomain->refreshSOA();
        }
        return true;
    }

    /**
     * Remove records by goals
     * @param array $goals Should be a array of [name => ip ]
     * @param array $recordTypes Optional array of record types; default: @see Records::A, @see Records::PTR
     * @param string $zone Optional
     * @return bool
     */
    public static function rmRecord($goals = [], $recordTypes = [Records::A, Records::PTR], $zone = self::DEFAULT_ZONE)
    {
        try{
            if (!is_array($recordTypes) or empty($recordTypes)) throw new Exception('Тип записи не указан.');
            if ($goals = self::rebuildOverNetworks($goals) and !is_array($goals) or empty($goals)) throw new Exception('Пустой список для удаления.');
        }catch (Exception $e){
            Kohana::$log->add(Kohana::ERROR, Kohana::exception_text($e))->write();
            return false;
        }

        foreach ($goals as $network => $goal){
            foreach ($goal as $ip => $name) {
                foreach ($recordTypes as $type) {
                    $record = new Records();
                    switch ($type){
                        case Records::A:
                            if ($record = $record->where('type', '=', $type)->where_open()->where('name', '=', $name . '.' . $zone)->or_where('content', '=', $ip)->where_close()->find() and isset($record->id)) $record->delete();
                            $fwdDomain = new Domains(['name' => $zone]);
                            $fwdDomain->refreshSOA();
                            break;
                        case Records::PTR:
                            if ($record = $record->where('type', '=', $type)->where_open()->where('name', '=', Domains::reverseCase($ip))->or_where('content', '=', $name . '.' . $zone)->where_close()->find() and isset($record->id)) $record->delete();
                            $rewDomain = new Domains(['name' => substr(Domains::reverseCase($network), 2)]);
                            $rewDomain->refreshSOA();
                            break;
                        default:
                            if ($record = $record->where('type', '=', $type)->where('name', 'LIKE', '%' . $name . '%')->find() and isset($record->id)) $record->delete();
                            $fwdDomain = new Domains(['name' => $zone]);
                            $fwdDomain->refreshSOA();
                            break;
                    }
                }
            }
        }
        return true;
    }

    /**
     * Getting the relevant Domain Zone
     * @param bool $mode
     * @param array $options
     * @return object Model_Domain
     * @throws Exception
     */
    private static function getDomain($mode = false, $options = [])
    {
        switch ($mode){
            case Domains::FORWARD_MODE:
                $fwdDomain = new Domains(['name' => $options['name']]);
                if (isset($fwdDomain->id)) return $fwdDomain;
                else {
                    return $fwdDomain->forward(['name' => $options['name'], 'type' => $options['type']])->create();
                }
                break;
            case Domains::REVERSE_MODE:
                $revDomain = new Domains(['name' => substr(Domains::reverseCase($options['name']), 2)]);
                if (isset($revDomain->id)) return $revDomain;
                else {
                    return $revDomain->reverse(['name' => $options['name'], 'type' => $options['type']])->create();
                }
                break;
            default:
                throw new Exception('mode не передан.');
                break;
        }
    }

    /**
     * @param array $goals Goals list ['ip' => 'name']
     * @param array $nets Optional to merge
     * @return array Example [ network => [ [name => ip ], ...], ... ]
     */
    private static function rebuildOverNetworks($goals = [], $nets = [])
    {
        ksort($goals);
        unset($goals['0.0.0.0']);
        foreach($goals as $ip => $name){
            $net = preg_replace('/(\.[0-9]+)$/', '.0', $ip);
	        if (isset($nets[$net])) $nets[$net] = $nets[$net]+[$ip => $name];
	        else $nets[$net] = [$ip => $name];
        }
        return $nets;
    }

}