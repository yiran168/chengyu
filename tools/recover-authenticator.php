<?php
declare(strict_types=1);
// Keep this file outside the public web root. No HTTP recovery endpoint exists.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==4 || $argv[3]!=='CONFIRM-REVOKE-ALL-SESSIONS'){
    fwrite(STDERR,"Usage: php recover-authenticator.php /absolute/site/root USER_ID CONFIRM-REVOKE-ALL-SESSIONS\nThis removes the user's authenticator and signs out all sessions. Back up first.\n");exit(2);
}
$root=realpath($argv[1]);
if(!$root || !is_file($root.'/app/config.php') || !ctype_digit($argv[2]) || (int)$argv[2]<1){fwrite(STDERR,"Installed site and positive user ID required.\n");exit(2);}
define('CY_BOOT',true);require $root.'/app/autoload.php';date_default_timezone_set('Asia/Shanghai');
try{$a=new \Chengyu\App(require $root.'/app/config.php');$a->secondFactor->recoverOffline((int)$argv[2]);fwrite(STDOUT,"Authenticator removed; all sessions revoked. The operation is audited.\n");}
catch(Throwable $e){fwrite(STDERR,"Recovery failed; inspect the local application/database configuration.\n");exit(1);}
