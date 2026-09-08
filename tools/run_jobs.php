<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=isset($argv[1])?realpath($argv[1]):realpath(__DIR__.'/../site');
if(!$root||!is_file($root.'/app/config.php')){fwrite(STDERR,"Usage: php tools/run_jobs.php /absolute/site/root [1-10]\n");exit(2);}
define('CY_NO_SESSION',true);require $root.'/app/bootstrap.php';
try{$r=app()->jobs->run(\Chengyu\Core\Input::integer($argv[2]??1,1,10));echo json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";}catch(Throwable $e){fwrite(STDERR,"Job failed. Review the private application log and provider dashboard.\n");exit(1);}
