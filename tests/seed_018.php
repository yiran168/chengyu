<?php
// Disposable, original demonstration content. Never install test fixtures publicly.
$a->settings->seed(['tracking_enabled'=>true]);
$address18=$db->insert('cy_addresses',['user_id'=>$admin,'recipient'=>'Demo reader','phone'=>'00000000000','address'=>'Original demonstration address','created_at'=>time()]);
$visualProduct18=$a->content->save($db->one('SELECT * FROM cy_users WHERE id=?',[$admin]),['kind'=>'product','title'=>'为创作留一点安静 · 双包裹交付','excerpt'=>'原创演示数据，请勿用于真实发货。','body'=>'创作手记与配件分别发出，可以在同一个订单里跟踪每个包裹。','status'=>'published','access_level'=>'public','price_currency'=>'balance','price'=>'0','product_type'=>'physical','inventory'=>5],true);
$visualOrder18=$a->commerce->buy($admin,'content',$visualProduct18,bin2hex(random_bytes(16)),'',$address18);
$visualParcels18=[];
foreach(['创作手记 · 主包裹','收纳配件 · 第二包裹'] as $n=>$name){
    $id=$a->tracking->attach($admin,['order_id'=>$visualOrder18['id'],'package_key'=>bin2hex(random_bytes(16)),'package_label'=>$name,'carrier'=>'manual','tracking_number'=>'CYDEMO018'.($n+1),'revision'=>0]);
    $a->tracking->addEvent($admin,$id,1,time()-7200,'包裹已完成整理，商品清点无误。');
    $a->tracking->addEvent($admin,$id,2,time()-3600,'承运方已揽收，这份心意正在路上。');
    $visualParcels18[]=$id;
}
$visual018=['order'=>$visualOrder18['id'],'parcels'=>$visualParcels18];
$a->backups->create($admin,'Original preview backup password 2026!',false);
