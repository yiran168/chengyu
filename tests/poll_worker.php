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
$a=new Chengyu\App($config);$GLOBALS['chengyu']=$a;
$user=$a->db->one('SELECT * FROM cy_users WHERE id=?',[(int)$argv[2]]);
try{$a->threads->vote($user,(int)$argv[3],(int)$argv[4],$a->content);echo 'recorded';}
catch(Chengyu\Core\Problem $e){if($e->status!==409){throw $e;}echo 'conflict';}
