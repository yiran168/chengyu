<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$a=app();$id=\Chengyu\Core\Input::integer($_GET['order']??0,1);$owner=(new \Chengyu\Http\CommerceActions($a))->owner($id);
$o=$a->db->one('SELECT * FROM cy_orders WHERE id=?',[$id]);
if($o['kind']!=='content'||!in_array($o['status'],['paid','refund_requested'],true)||((int)$o['access_until']>0&&(int)$o['access_until']<=time())){throw new \Chengyu\Core\Problem('Active purchase required.',403);}
$item=$a->content->get((int)$o['item_id'],$owner);if(!$a->content->access($item,$owner)||(int)$item['resource_id']<=0){throw new \Chengyu\Core\Problem('Download unavailable.',403);}
header('Referrer-Policy: no-referrer');$a->media->stream($a->media->readable((int)$item['resource_id'],$owner),$owner);
