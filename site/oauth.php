<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){throw new \Chengyu\Core\Problem('GET is required.',405);}
foreach($_GET as $value){if(!is_scalar($value)){throw new \Chengyu\Core\Problem('Invalid input.');}}
$a=app();$route=$a->oauth->callback(\Chengyu\Core\Input::required($_GET['provider']??'',20),$_GET);
redirect($a->url($route));
