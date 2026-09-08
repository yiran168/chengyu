<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}define('CY_BOOT',true);
require dirname(__DIR__).'/site/app/autoload.php';
$c=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if($c['database']['driver']==='sqlite' && !in_array('sqlite',PDO::getAvailableDrivers(),true)){require __DIR__.'/Support/FfiSqlite.php';Chengyu\Core\Database::useTestFactory(static function(array $c){return new FfiSqlite($c['path']);});}
$a=new Chengyu\App($c);
try{if($argv[2]==='factor'){$a->db->transaction(static function()use($a,$argv){$a->secondFactor->verifyLocked($a->wallet->lockActive((int)$argv[3]),$argv[4]);});echo 'accepted';}
else{$a->commerce->buy((int)$argv[3],'content',(int)$argv[4],bin2hex(random_bytes(16)),$argv[5]);echo 'accepted';}}
catch(Chengyu\Core\Problem $e){echo 'rejected';}
