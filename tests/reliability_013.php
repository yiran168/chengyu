<?php
// Regressions reproduced against 0.12.0 before the repairs.
test('revalidation rejects a credential invalidated after Auth cache was populated',static function()use($a){
    $id=$a->db->insert('cy_users',['username'=>'stale_auth','email'=>'stale-auth@example.test','password_hash'=>password_hash('BeforeChange123!',PASSWORD_DEFAULT),'display_name'=>'Stale auth fixture','role'=>'user','status'=>'active','bio'=>'','created_at'=>time()]);
    $a->auth->login('stale_auth','BeforeChange123!');$a->auth->requireUser();
    $a->db->execute('UPDATE cy_users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash('AfterChange123!',PASSWORD_DEFAULT),$id]);
    try { reject(static function()use($a){$a->auth->verifyPassword('BeforeChange123!');}); }
    finally { $a->auth->logout(); }
});
test('private attachment linked after one hundred denied items remains readable',static function()use($a,$admin,$u){
    $id=$a->db->insert('cy_media',['owner_id'=>$admin,'name'=>'shared-reference.txt','mime'=>'text/plain','bytes'=>3,'file_key'=>bin2hex(random_bytes(24)).'.php','is_private'=>1,'created_at'=>time()]);
    $template=$a->db->one("SELECT * FROM cy_contents WHERE kind='article' LIMIT 1");unset($template['id']);
    $template=array_merge($template,['author_id'=>$admin,'category_id'=>0,'resource_id'=>$id,'access_level'=>'paid','vip_free'=>0,'status'=>'published','publish_at'=>0]);
    for($i=0;$i<101;$i++){$template['slug']='many-links-'.$i.'-'.key32();$template['access_level']=$i===100?'public':'paid';$a->db->insert('cy_contents',$template);}
    same($id,(int)$a->media->readable($id,null)['id']);
});
test('suspended sender cannot transfer assets through the service boundary',static function()use($a,$u,$v){
    settings('commerce',['transfers_enabled'=>'1']);$before=funds($u);$a->db->update('cy_users',$u,['status'=>'suspended']);
    try{reject(static function()use($a,$u,$v){$a->wallet->transfer($u,account($v)['username'],'balance',1,key32());},403);same($before,funds($u));}
    finally{$a->db->update('cy_users',$u,['status'=>'active']);}
});
