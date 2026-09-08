<?php
declare(strict_types=1);
define('CY_NO_SESSION',true);
require __DIR__.'/app/bootstrap.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){throw new \Chengyu\Core\Problem('POST required.',405);}
    if((int)($_SERVER['CONTENT_LENGTH']??0)>262144){throw new \Chengyu\Core\Problem('Notification too large.',413);}
    $raw=file_get_contents('php://input',false,null,0,262145);if($raw===false||strlen($raw)>262144){throw new \Chengyu\Core\Problem('Notification too large.',413);}
    $gateway=\Chengyu\Core\Input::choice($_GET['gateway']??'',array_keys(\Chengyu\Services\DirectPayments::NAMES));$headers=[];
    foreach($_SERVER as $k=>$v){if(strpos($k,'HTTP_')===0&&is_string($v)){$headers[strtolower(str_replace('_','-',substr($k,5)))]=$v;}}
    app()->checkout->webhook($gateway,$raw,$headers,\Chengyu\Core\Input::text($_GET['sale']??'',32));
    if($gateway==='wechat'){http_response_code(204);}else{header('Content-Type: text/plain; charset=UTF-8');echo $gateway==='alipay_direct'?'success':'ok';}
}catch(\Throwable $e){http_response_code(400);header('Content-Type: text/plain; charset=UTF-8');echo 'fail';}
