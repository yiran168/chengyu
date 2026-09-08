<?php
declare(strict_types=1);
// Keep this tool and the private database configuration outside the public document root.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc<4||!in_array($argv[1],['inspect','restore'],true)){
    fwrite(STDERR,"Inspect: php recover-backup.php inspect /absolute/site/root backup.cybackup\nRestore: php recover-backup.php restore /absolute/site/root backup.cybackup /private/target-database.php /new/storage /private/recovered-config.php CONFIRM-EMPTY-TARGET\nSupply the backup password on standard input, never as a command-line argument.\n");exit(2);
}
$root=realpath($argv[2]);if(!$root||!is_file($root.'/app/autoload.php')){fwrite(STDERR,"A matching application root is required.\n");exit(2);}
define('CY_BOOT',true);require $root.'/app/autoload.php';date_default_timezone_set('UTC');
fwrite(STDERR,"Backup password (stdin; interactive terminals may echo input): ");$line=fgets(STDIN,258);$password=$line===false?'':rtrim($line,"\r\n");
try{
    if($argv[1]==='inspect'){$out=\Chengyu\Core\SnapshotReader::describe(\Chengyu\Core\SnapshotReader::walk($argv[3],$password));}
    else{
        if($argc!==8||$argv[7]!=='CONFIRM-EMPTY-TARGET'||!is_file($argv[4])){throw new \RuntimeException('Restore requires a trusted local database configuration and explicit empty-target confirmation.');}
        $db=require $argv[4];if(!is_array($db)){throw new \RuntimeException('Target configuration must return a database connection array.');}
        $out=\Chengyu\Services\Recovery::restore($argv[3],$password,$db,$argv[5],$argv[6]);
    }
    fwrite(STDOUT,json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
}catch(Throwable $e){fwrite(STDERR,"Recovery did not complete: ".$e->getMessage()."\nNever publish a partially restored target. MySQL DDL may have created empty tables; use a new isolated target for retry.\n");exit(1);}
