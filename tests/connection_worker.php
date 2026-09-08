<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}define('CY_BOOT',true);
require dirname(__DIR__).'/site/app/autoload.php';
use Chengyu\Core\{Database,HttpClient};use Chengyu\Services\Payment;
$c=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if($c['database']['driver']==='sqlite'&&!in_array('sqlite',PDO::getAvailableDrivers(),true)){require __DIR__.'/Support/FfiSqlite.php';Database::useTestFactory(static function(array $c){return new FfiSqlite($c['path']);});}
$a=new Chengyu\App($c);$mode=$argv[2];$uid=(int)$argv[3];$id=(int)$argv[4];
if($mode==='badge'){$a->badges->grant((int)$argv[5],$uid,$id);echo 'accepted';exit;}
$order=$a->db->one('SELECT * FROM cy_orders WHERE id=?',[$id]);
$fields=['pid'=>'10015','type'=>'alipay','out_trade_no'=>$order['order_no'],'trade_no'=>'CONCURRENT-'.$id,'money'=>'1.00','trade_status'=>'TRADE_SUCCESS'];
if($mode==='callback'){$fields['sign']=Payment::sign($fields,'old-query-secret');$a->payment->settle('epay',$fields);echo 'callback';exit;}
$http=new HttpClient(static function()use($fields){usleep(50000);$body=json_encode(array_merge($fields,['code'=>1,'status'=>1]),JSON_THROW_ON_ERROR);return "HTTP/1.1 200 OK\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;});
$p=new Payment($a->db,$a->settings,$a->crypto,$a->wallet,$a->activity,$c['url'],$http);$result=$p->reconcile($uid,$id);echo $result['status'];
