# Kohana-PDNS-module
Work with Power DNS zones(domains) & records

## Installation

### With composer
```sh
composer require artroot/kohana-pdns-module
```
### With git
```sh
git clone https://github.com/artroot/kohana-pdns-module
```
or download
```sh
wget https://github.com/artroot/kohana-pdns-module/archive/master.zip
```

## Regular Setup
Add new module, copy to your bootstrap.php in Kohana::modules array
```php
Kohana::modules(array(
    ...
	'pdns'  => MODPATH.'pdns',  // Work with Power DNS
));
```
Configure your Database.php, add new group for Power DNS
```php
...
'pdns' => array
    (
        'type'       => 'mysqli',
        'connection' => array(
            'hostname'   => 'localhost',
            'database'   => 'PdnsDbName',
            'username'   => 'username',
            'password'   => 'password',
            'persistent' => FALSE,
        ),
        'table_prefix' => '',
        'charset'      => 'utf8',
        'caching'      => FALSE,
        'profiling'    => TRUE,
    ),
```
Put the pdns folder into MODPATH

## Code
### Synchronize method
```php
$hosts = [
    '192.168.1.101' => 'host1',
    '192.168.1.102' => 'host2',
    '192.168.2.103' => 'host3',
];
$fwdZoneOptions = [
    'name' => 'local',
    'type' => Model_Domain::MASTER,
];
$revZoneOptions = [
    'type' => Model_Domain::MASTER,
];
$recordTypes = [
    Model_Record::A,
    Model_Record::PTR,
];
PDNS::synchronize($hosts, $fwdZoneOptions, $revZoneOptions, $recordTypes);
```
Will fill tables:
#### Domains table
| id | name | type |
| ------ | ------ | ------ |
| 1 | local | MASTER |
| 2 | 1.168.192.in-addr.arpa | MASTER |
| 3 | 2.168.192.in-addr.arpa | MASTER |
#### Records table
| id | domain_id | name | type | content | ttl | 
| ------ | ------ | ------ | ------ | ------ | ------ |
| 1 | 1 | local | SOA | ns.local. local 2017064352 10800 3600 604800 86400 | 3600 |
| 2 | 1 | local | NS | ns.local | 3600 |
| 3 | 2 | 1.168.192.in-addr.arpa | SOA | ns.local. 1.168.192.in-addr.arpa 2017064352 10800 3600 604800 86400 | 3600 |
| 4 | 2 | 1.168.192.in-addr.arpa | NS | ns.local | 3600 |
| 5 | 1 | host1 | A | 192.168.1.101 | 3600 |
| 6 | 2 | 101.1.168.192.in-addr.arpa | PTR | host1.local | 3600 |
| 7 | 1 | host2 | A | 192.168.1.102 | 3600 |
| 8 | 2 | 102.1.168.192.in-addr.arpa | PTR | host2.local | 3600 |
| 9 | 3 | 2.168.192.in-addr.arpa | SOA | ns.local. 2.168.192.in-addr.arpa 2017064352 10800 3600 604800 86400 | 3600 |
| 10 | 3 | 2.168.192.in-addr.arpa | NS | ns.local | 3600 |
| 11 | 1 | host3 | A | 192.168.2.103 | 3600 |
| 12 | 3 | 103.2.168.192.in-addr.arpa | PTR | host3.local | 3600 |

Do callback if you need to change one of the records. 
For Example:
```php
$oldHost = [
    '192.168.1.102' => 'host2',
];
$newHost = [
    '192.168.1.102' => 'host5',
];

PDNS::synchronize($newHost, $fwdZoneOptions, $revZoneOptions, $recordTypes, PDNS::CALL_RM_REC, [
    $oldHost
]);
```
Or use rmRecord() once
### RmRecord method
```php
$hosts = [
    '192.168.1.102' => 'host2',
    '192.168.1.103' => 'host3',
];
$recordTypes = [
    Model_Record::A,
    Model_Record::PTR,
];
$zoneName = 'local';
PDNS::rmRecord($hosts, $recordTypes, $zoneName);
```
Delete all records for hosts list.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details

