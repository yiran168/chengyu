<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);} define('CY_BOOT',true);
require dirname(__DIR__).'/site/app/autoload.php';
$c=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if($c['database']['driver']==='sqlite' && !in_array('sqlite',PDO::getAvailableDrivers(),true)){require __DIR__.'/Support/FfiSqlite.php';Chengyu\Core\Database::useTestFactory(static function(array $c){return new FfiSqlite($c['path']);});}
$a=new Chengyu\App($c);echo $a->creator->settleOne((int)$argv[2],(int)$argv[3])?'settled':'unchanged';
