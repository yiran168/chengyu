<?php
use Chengyu\Services\MediaCatalog;
function namedPhoto23(int $owner,string $name,bool $private=false): int{global $a;$id=photo22($private,$owner);$a->db->update('cy_media',$id,['name'=>$name]);return $id;}
test('023 catalog rechecks active actor guest status and current role',static function()use($a){
    $c=new MediaCatalog($a->db);$u=user14('catalog-role23');reject(static function()use($c){$c->listing(2147483647,[]);},403);
    foreach([['status'=>'suspended'],['status'=>'active','is_guest'=>1]] as $state){$a->db->update('cy_users',$u,$state);reject(static function()use($c,$u){$c->listing($u,[]);},403);}
    $a->db->update('cy_users',$u,['is_guest'=>0,'role'=>'admin']);truth($c->listing($u,['scope'=>'all'])['can_browse_all']);
    $a->db->update('cy_users',$u,['role'=>'user']);reject(static function()use($c,$u){$c->listing($u,['scope'=>'all']);},403);
});
test('023 member catalog returns only own public supported images without private storage data',static function()use($a,$admin){
    $u=user14('catalog-owner23');$mine=namedPhoto23($u,'own23.png');namedPhoto23($u,'secret23.png',true);namedPhoto23($admin,'other23.png');$bad=namedPhoto23($u,'bad23.svg');$a->db->update('cy_media',$bad,['mime'=>'image/svg+xml']);
    $c=new MediaCatalog($a->db);$result=$c->listing($u,[]);same([$mine],array_column($result['items'],'id'));same(['id','name','bytes','mime'],array_keys($result['items'][0]));same(false,$result['has_more']);same(0,$result['next_before']);
});
test('023 filename wildcard characters are literal and scope is validated',static function()use($a){
    $u=user14('catalog-search23');$match=namedPhoto23($u,'Exact%_!23.png');namedPhoto23($u,'ExactAA23.png');$c=new MediaCatalog($a->db);
    same([$match],array_column($c->listing($u,['q'=>'%_!'])['items'],'id'));same([],$c->listing($u,['q'=>"' OR 1=1 --"])['items']);
    reject(static function()use($c,$u){$c->listing($u,['scope'=>'private']);});
});
test('023 staff may select another public image but members cannot enumerate or select it',static function()use($a,$admin){
    $u=user14('catalog-access23');$id=namedPhoto23($u,'staff-shared23.png');$c=new MediaCatalog($a->db);same($id,$c->select($admin,$id)['id']);truth(in_array($id,array_column($c->listing($admin,['scope'=>'all','q'=>'staff-shared23'])['items'],'id'),true));
    $other=user14('catalog-other23');reject(static function()use($c,$other,$id){$c->select($other,$id);},404);reject(static function()use($c,$other){$c->listing($other,['scope'=>'all']);},403);
});
test('023 keyset pages remain bounded and do not repeat after a newer upload',static function()use($a){
    $u=user14('catalog-pages23');$ids=[];for($i=0;$i<25;$i++){$ids[]=namedPhoto23($u,'page23-'.$i.'.png');}$c=new MediaCatalog($a->db);$first=$c->listing($u,['q'=>'page23-']);same(12,count($first['items']));truth($first['has_more']);
    namedPhoto23($u,'page23-new.png');$second=$c->listing($u,['q'=>'page23-','before'=>$first['next_before']]);$third=$c->listing($u,['q'=>'page23-','before'=>$second['next_before']]);same(12,count($second['items']));same(1,count($third['items']));truth(!$third['has_more']);
    $seen=array_merge(array_column($first['items'],'id'),array_column($second['items'],'id'),array_column($third['items'],'id'));same(25,count(array_unique($seen)));sort($seen);same($ids,$seen);
});
test('023 final selection rejects media revoked after listing',static function()use($a){
    $u=user14('catalog-revoke23');$id=namedPhoto23($u,'revoke23.png');$c=new MediaCatalog($a->db);same($id,$c->listing($u,[])['items'][0]['id']);$a->db->update('cy_media',$id,['is_private'=>1]);reject(static function()use($c,$u,$id){$c->select($u,$id);},404);
    $a->db->update('cy_media',$id,['is_private'=>0,'mime'=>'application/pdf']);reject(static function()use($c,$u,$id){$c->select($u,$id);},404);reject(static function()use($c,$u){$c->select($u,2147483647);},404);
});
test('023 selection reuses media without copying bytes and survives disabled new uploads',static function()use($a){
    $u=user14('catalog-reuse23');$id=namedPhoto23($u,'reuse23.png');$count=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');$c=new MediaCatalog($a->db);$old=$a->settings->get('user_uploads');$a->settings->savePartial('community',['user_uploads'=>false]);
    try{same($id,$c->select($u,$id)['id']);same($id,$c->select($u,$id)['id']);same($count,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));}finally{$a->settings->savePartial('community',['user_uploads'=>$old]);}
});
test('023 catalog indexes are restartable and snapshot schema stays valid',static function()use($a){
    \Chengyu\Core\Migration023::up($a->db);\Chengyu\Core\Migration023::up($a->db);\Chengyu\Core\SnapshotSchema::check($a->db);
    $rows=$a->db->driver()==='mysql'?$a->db->all('SHOW INDEX FROM cy_media'):$a->db->all('PRAGMA index_list(cy_media)');$names=array_map(static function(array $r):string{return $r['Key_name']??$r['name'];},$rows);truth(in_array('cy_media_owner_catalog',$names,true));truth(in_array('cy_media_public_catalog',$names,true));
});
