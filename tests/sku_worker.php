<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
define('CY_BOOT',true);
require dirname(__DIR__).'/site/app/autoload.php';
$config=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if($config['database']['driver']==='sqlite' && !in_array('sqlite',PDO::getAvailableDrivers(),true)){
    require __DIR__.'/Support/FfiSqlite.php';
    Chengyu\Core\Database::useTestFactory(static function(array $c){return new FfiSqlite($c['path']);});
}
$a=new Chengyu\App($config);
try{$a->commerce->buy((int)$argv[2],'content',(int)$argv[3],bin2hex(random_bytes(16)),'',(int)$argv[5],(int)$argv[4]);echo 'paid';}
catch(Chengyu\Core\Problem $e){if($e->getMessage()!=='Out of stock.'){throw $e;}echo 'declined';}
