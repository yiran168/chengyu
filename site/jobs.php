<?php
declare(strict_types=1);
define('CY_NO_SESSION',true);
require __DIR__.'/app/bootstrap.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){throw new \Chengyu\Core\Problem('POST required.',405);}
    $raw=file_get_contents('php://input',false,null,0,1025);if($raw===false||strlen($raw)>1024){throw new \Chengyu\Core\Problem('Invalid job request.');}
    $key=(string)app()->settings->get('jobs_secret');$time=$_SERVER['HTTP_X_CY_TIME']??'';$nonce=$_SERVER['HTTP_X_CY_NONCE']??'';$signature=$_SERVER['HTTP_X_CY_SIGNATURE']??'';
    if(strlen($key)<32||!is_string($time)||!preg_match('/^[0-9]{10,12}$/D',$time)||abs(time()-(int)$time)>300||!is_string($nonce)||!preg_match('/^[a-f0-9]{32}$/D',$nonce)||!is_string($signature)||!hash_equals(hash_hmac('sha256',$time."\n".$nonce."\n".hash('sha256',$raw),$key),$signature)){throw new \Chengyu\Core\Problem('Invalid job signature.',403);}
    // The timestamp participates in the bucket key, so crossing a rate-limit window cannot replay a nonce.
    $bucket=app()->crypto->digest('job-replay:'.$time.':'.$nonce);
    app()->db->transaction(function()use($bucket):void{if(app()->db->one('SELECT bucket FROM cy_rate_limits WHERE bucket=?',[ $bucket])){throw new \Chengyu\Core\Problem('Replayed job request.',409);}app()->db->insert('cy_rate_limits',['bucket'=>$bucket,'hits'=>1,'expires_at'=>time()+900]);});
    $in=json_decode($raw,true,4,JSON_THROW_ON_ERROR);if(!is_array($in)){throw new \Chengyu\Core\Problem('Invalid job body.');}$report=app()->jobs->run(\Chengyu\Core\Input::integer($in['limit']??1,1,10));
    header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>true,'result'=>$report],JSON_THROW_ON_ERROR);
}catch(\Throwable $e){http_response_code($e instanceof \Chengyu\Core\Problem?$e->status:400);header('Content-Type: application/json; charset=UTF-8');echo '{"ok":false}';}
