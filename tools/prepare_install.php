<?php
declare(strict_types=1);
/** Owner-run CLI helper. No HTTP entry point; does not connect to a database. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$site=realpath($argv[1]??dirname(__DIR__).'/site');
if(!$site || !is_file($site.'/install/index.php')){fwrite(STDERR,"Usage: php tools/prepare_install.php /path/to/uninstalled/site\n");exit(1);}
if(is_file($site.'/app/config.php')){fwrite(STDERR,"Site is already installed.\n");exit(1);}
define('CY_BOOT',true);
$current=require $site.'/install/key.php';
if($current!==''){fwrite(STDERR,"An owner verifier already exists. Keep the matching private key; this helper will not overwrite it.\n");exit(1);}
$path=dirname($site).'/INSTALL_KEY.txt';
$out=fopen($path,'x');if(!$out){fwrite(STDERR,"Cannot create a new private INSTALL_KEY.txt outside the site.\n");exit(1);}
$key=bin2hex(random_bytes(32));
try{
    if(fwrite($out,$key."\n")!==65){throw new RuntimeException('Private key write failed.');}
    fclose($out);$out=null;chmod($path,0600);
    $verifier="<?php\ndeclare(strict_types=1);\nif(!defined('CY_BOOT')){http_response_code(404);exit;}\nreturn '".hash('sha256',$key)."';\n";
    if(file_put_contents($site.'/install/key.php',$verifier,LOCK_EX)!==strlen($verifier)){throw new RuntimeException('Verifier write failed.');}
    echo "Ready. Keep ".$path." private; upload only the site directory.\n";
}catch(Throwable $e){if(is_resource($out)){fclose($out);}fwrite(STDERR,$e->getMessage()."\n");exit(1);}
