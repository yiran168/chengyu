<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {exit(1);}define('CY_BOOT',true);
require dirname(__DIR__).'/site/app/autoload.php';
$config=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if ($config['database']['driver']==='sqlite' && !in_array('sqlite',PDO::getAvailableDrivers(),true)) {require __DIR__.'/Support/FfiSqlite.php';Chengyu\Core\Database::useTestFactory(static function(array $c){return new FfiSqlite($c['path']);});}
$a=new Chengyu\App($config);$uid=(int)$argv[3];$id=(int)$argv[4];
try {
 if($argv[2]==='circle'){$a->commerce->buy($uid,'circle',$id,bin2hex(random_bytes(16)));echo 'joined';}
 elseif($argv[2]==='award'){$a->threads->accept($a->db->one('SELECT * FROM cy_users WHERE id=?',[$uid]),$id,(int)$argv[5],$a->content);echo 'accepted';}
 else{throw new RuntimeException('Unknown worker mode');}
} catch(Chengyu\Core\Problem $e){if($argv[2]==='circle' && $e->status===409){echo 'conflict';}else{throw $e;}}
