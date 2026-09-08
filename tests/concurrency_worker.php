<?php
if(PHP_SAPI!=='cli'){exit(1);}define('CY_BOOT',true);
require dirname(__DIR__).'/site/app/autoload.php';
$config=json_decode(file_get_contents($argv[1]),true);
if($config['database']['driver']==='sqlite' && !in_array('sqlite',PDO::getAvailableDrivers(),true)){require __DIR__.'/Support/FfiSqlite.php';Chengyu\Core\Database::useTestFactory(static function(array $c){return new FfiSqlite($c['path']);});}
$a=new Chengyu\App($config);
try{$a->wallet->adjust((int)$argv[2],'balance',-100,'race:'.$argv[3],'concurrent test');echo 'paid';}
catch(Chengyu\Core\Problem $e){if($e->getMessage()!=='Insufficient funds.'){throw $e;}echo 'declined';}
