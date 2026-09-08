<?php
test('019 long text media has portable bounded MIME detection',static function()use($a,$admin,$tmp){
    $file=$tmp.'/longtext019.txt';$body=str_repeat("A genuine long plain-text resource\n",15000);file_put_contents($file,$body);
    $id=$a->media->storeLocal(account($admin),$file,'long-resource.txt',true,1048576);
    $row=$a->db->one('SELECT mime,bytes,is_private FROM cy_media WHERE id=?',[$id]);same('text/plain',$row['mime']);same(strlen($body),(int)$row['bytes']);same(1,(int)$row['is_private']);
});
use Chengyu\Core\{Problem,SnapshotSchema};
use Chengyu\Services\CatalogFilters;

function resource19(array $changes=[]): array{
    global $a,$admin,$tmp;
    $content=product();$path=$tmp.'/resource-'.key32().'.txt';file_put_contents($path,'PRIVATE-RESOURCE-019');
    $media=$a->media->storeLocal(account($admin),$path,'edition.txt',true,1048576);
    $in=array_merge(['content_id'=>$content,'label'=>'Complete resource edition','release_version'=>'1.2.0','notes'=>'Original fixture notes','media_id'=>$media,'active'=>1],$changes);
    $id=$a->resources->save(account($admin),$in);return [$id,$content,$media,$in];
}
test('019 new resource tables are included in authenticated backups',static function()use($a){SnapshotSchema::check($a->db);foreach(['cy_resource_files','cy_resource_reports','cy_resource_downloads'] as $table){truth(in_array($table,SnapshotSchema::tables(),true));}});
test('019 edition files remain unavailable until real purchase and revoke on refund',static function()use($a,$admin){
    [$id,$content,$media]=resource19();$u=user14('resource-purchase');$a->wallet->adjust($u,'balance',1000,'resourcefund:'.$u,'Fixture');
    same([],$a->resources->entries($content,account($u)));reject(static function()use($a,$media,$u){$a->media->readable($media,account($u));},403);
    $order=$a->commerce->buy($u,'content',$content,key32());same($id,(int)$a->resources->entries($content,account($u))[0]['id']);same($media,(int)$a->media->readable($media,account($u))['id']);
    $a->commerce->requestRefund($u,(int)$order['id'],'Fixture refund');$a->commerce->refund($admin,(int)$order['id'],true);
    reject(static function()use($a,$id,$u){$a->resources->read($id,account($u));},403);reject(static function()use($a,$media,$u){$a->media->readable($media,account($u));},403);
});
test('019 mirrors are encrypted and cannot be listed by unauthorized readers',static function()use($a,$admin){
    [$id,$content]=resource19(['media_id'=>0,'mirror_url'=>'https://downloads.example.test/private-token','extraction_code'=>'p9q3']);
    $stored=$a->db->one('SELECT * FROM cy_resource_files WHERE id=?',[$id]);truth(strpos(json_encode($stored),'private-token')===false);truth(strpos(json_encode($stored),'p9q3')===false);
    same([],$a->resources->entries($content,null));$meta=$a->resources->entries($content,account($admin))[0];truth(!array_key_exists('mirror_cipher',$meta));
    same('p9q3',$a->resources->destination($id,account($admin),false)['code']);
});
test('019 resource configuration rejects dual sources, public files and unsafe URLs',static function()use($a,$admin){
    [$id,$content,$media,$in]=resource19();
    foreach(['javascript:alert(1)','http://plain.example.test','https://user:pass@example.test','https://example.test/#fragment'] as $url){reject(static function()use($a,$admin,$in,$url){$a->resources->save(account($admin),array_merge($in,['media_id'=>0,'mirror_url'=>$url]));});}
    reject(static function()use($a,$admin,$in){$a->resources->save(account($admin),array_merge($in,['mirror_url'=>'https://example.test/a']));});
    $a->db->update('cy_media',$media,['is_private'=>0]);reject(static function()use($a,$admin,$in){$a->resources->save(account($admin),$in);});
});
test('019 revision checks prevent lost updates and disabled editions stop new access',static function()use($a,$admin){
    [$id,$content,$media,$in]=resource19();$u=user14('edition-state');$a->wallet->adjust($u,'balance',1000,'editionfund:'.$u,'Fixture');$a->commerce->buy($u,'content',$content,key32());
    $updated=array_merge($in,['id'=>$id,'revision'=>1,'active'=>0]);$a->resources->save(account($admin),$updated);
    reject(static function()use($a,$admin,$updated){$a->resources->save(account($admin),$updated);},409);
    reject(static function()use($a,$id,$u){$a->resources->read($id,account($u));},404);reject(static function()use($a,$media,$u){$a->media->readable($media,account($u));},403);
});
test('019 mirror quota is shared with files and repeated access is idempotent',static function()use($a){
    [$id,$content]=resource19(['media_id'=>0,'mirror_url'=>'https://downloads.example.test/edition']);$u=user14('mirror-quota');$a->wallet->adjust($u,'balance',1000,'mirrorfund:'.$u,'Fixture');$a->commerce->buy($u,'content',$content,key32());
    $previous=$a->settings->get('download_daily_limit');$a->settings->savePartial('security',['download_daily_limit'=>1]);
    try{$a->resources->destination($id,account($u),false);same(0,(int)$a->db->value('SELECT COUNT(*) FROM cy_resource_downloads WHERE user_id=?',[$u]));$a->resources->destination($id,account($u));$a->resources->destination($id,account($u));same(1,(int)$a->db->value('SELECT COUNT(*) FROM cy_resource_downloads WHERE user_id=?',[$u]));
        [$next,$other,$media]=resource19();$a->commerce->buy($u,'content',$other,key32());reject(static function()use($a,$media,$u){$a->media->recordDownload($media,account($u));},429);
    }finally{$a->settings->savePartial('security',['download_daily_limit'=>$previous]);}
});
test('019 reports require access and resolving produces one notification',static function()use($a,$admin){
    [$id,$content]=resource19();$u=user14('resource-report');$a->wallet->adjust($u,'balance',1000,'reportfund:'.$u,'Fixture');
    reject(static function()use($a,$u,$id){$a->resources->report(account($u),['resource_id'=>$id,'reason'=>'unavailable']);},403);$a->commerce->buy($u,'content',$content,key32());
    $rid=$a->resources->report(account($u),['resource_id'=>$id,'reason'=>'unavailable','body'=>'Mirror stopped responding']);same($rid,$a->resources->report(account($u),['resource_id'=>$id,'reason'=>'unavailable']));
    $before=(int)$a->db->value('SELECT COUNT(*) FROM cy_notifications WHERE user_id=?',[$u]);$in=['id'=>$rid,'revision'=>1,'status'=>'resolved','resolution'=>'A new edition is available'];
    reject(static function()use($a,$u,$in){$a->resources->resolve(account($u),$in);},403);$a->resources->resolve(account($admin),$in);reject(static function()use($a,$admin,$in){$a->resources->resolve(account($admin),$in);},409);same($before+1,(int)$a->db->value('SELECT COUNT(*) FROM cy_notifications WHERE user_id=?',[$u]));
});
test('019 layout entries reject executable URLs and overfull blocks',static function()use($a){
    $base=['format'=>'chengyu-layout','version'=>1,'blocks'=>[['id'=>'features','type'=>'features','items'=>[['title'=>'One','url'=>'javascript:alert(1)']]]]];
    reject(static function()use($a,$base){$a->layout->validate(json_encode($base));});$base['blocks'][0]['items']=array_fill(0,13,['title'=>'Too many']);reject(static function()use($a,$base){$a->layout->validate(json_encode($base));});
    $base['blocks'][0]['items']=[['title'=>'Safe <script>','text'=>'A title is text','url'=>'https://example.test']];$out=$a->layout->validate(json_encode($base));same('Safe <script>',$out['blocks'][0]['items'][0]['title']);same(3,$out['blocks'][0]['columns']);same(22,count(\Chengyu\Services\Layout::TYPES));
});
test('019 filter validation keeps zero, integer assets and price ranges exact',static function(){
    same('0',CatalogFilters::normalize(['min_price'=>'0'])['min_price']);same('balance',CatalogFilters::normalize(['sort'=>'price'])['currency']);
    foreach([['min_price'=>'20','max_price'=>'10'],['currency'=>'points','min_price'=>'1.1'],['stock'=>'<script>'],['access'=>['paid']]] as $invalid){reject(static function()use($invalid){CatalogFilters::normalize($invalid);});}
});
test('019 shop filters combine tag, asset, list price, stock and delivery',static function()use($a,$admin){
    $marker='unique019'.key32();$one=product('physical','balance','9.00',3);$two=product('physical','points','9',3);$three=product('physical','balance','9.00',0);
    foreach([$one,$two,$three] as $id){$a->db->update('cy_contents',$id,['tags'=>$marker.', another']);}
    $f=['tag'=>$marker,'currency'=>'balance','min_price'=>'8','max_price'=>'10','stock'=>'available','product_type'=>'physical'];same([$one],array_map('intval',array_column($a->content->feed('product',null,$f)['items'],'id')));
    same(0,$a->content->feed('product',null,['tag'=>substr($marker,1)])['total']);
});
test('019 a resource module toggle revokes linked file access',static function()use($a){
    [$id,$content,$media]=resource19();$u=user14('resource-module');$a->wallet->adjust($u,'balance',1000,'modulefund:'.$u,'Fixture');$a->commerce->buy($u,'content',$content,key32());
    $a->settings->savePartial('modules',['resource_library_enabled'=>0]);try{reject(static function()use($a,$id,$u){$a->resources->read($id,account($u));},403);reject(static function()use($a,$media,$u){$a->media->readable($media,account($u));},403);}finally{$a->settings->savePartial('modules',['resource_library_enabled'=>1]);}
});

test('019 carousel validates mobile media and real boolean autoplay',static function()use($a){
    $input=['format'=>'chengyu-layout','version'=>1,'blocks'=>[['id'=>'carousel19','type'=>'slider','autoplay'=>true,'interval'=>8,'items'=>[['title'=>'One','media_id'=>12,'mobile_media_id'=>15]]]]];
    $block=$a->layout->validate(json_encode($input))['blocks'][0];same(true,$block['autoplay']);same(15,$block['items'][0]['mobile_media_id']);same(8,$block['interval']);
    $input['blocks'][0]['autoplay']='false';reject(static function()use($a,$input){$a->layout->validate(json_encode($input));});
    $input['blocks'][0]['autoplay']=false;$input['blocks'][0]['interval']=0;reject(static function()use($a,$input){$a->layout->validate(json_encode($input));});
});
test('019 decorative images never disclose private media',static function()use($a,$admin,$tmp){
    $p=$tmp.'/private-art19.txt';file_put_contents($p,'A private file');$id=$a->media->storeLocal(account($admin),$p,'art.txt',true,1048576);
    same('',public_image_url($id));same('',public_image_url(0));same('',public_image_url(99999999));
});
