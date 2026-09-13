<?php
use Chengyu\Services\Editorial;

function bulletin21(array $overrides=[]): array
{
    return array_merge(['title'=>'Bulletin21 '.key32(),'body'=>'A short public update.','status'=>'published','publish_at'=>date('Y-m-d\TH:i',time()-600),'request_key'=>key32()],$overrides);
}
test('021 editor identity is rechecked for staff content writes',static function()use($a){
    $uid=user14('stale-editor21');$a->db->update('cy_users',$uid,['role'=>'editor']);$stale=account($uid);$a->db->update('cy_users',$uid,['role'=>'user']);
    reject(static function()use($a,$stale){$a->content->save($stale,['kind'=>'article','title'=>'Forbidden21','body'=>'body','status'=>'published'],true);},403);
    same(0,(int)$a->db->value('SELECT COUNT(*) FROM cy_contents WHERE title=?',['Forbidden21']));
});
test('021 attribution survives old editors and escapes templates without execution',static function()use($a,$admin){
    $id=article20(['source_name'=>'Example <script>','source_url'=>'https://example.org/article','copyright_mode'=>'reprint']);$old=$a->content->get($id,null);
    same('https://example.org/article',$old['source_url']);
    $a->content->save(account($admin),['id'=>$id,'edit_version'=>$old['edit_version'],'title'=>$old['title'],'body'=>'Revision','status'=>'published'],true);
    $now=$a->content->get($id,null);same('reprint',$now['copyright_mode']);same($old['source_name'],$now['source_name']);
    $a->settings->savePartial('editorial',['copyright_reprint'=>'{author} / {site} / {title} / {url} <script>alert(1)</script>']);
    $notice=Editorial::copyright($a->settings,$now,'https://example.org/site');truth(strpos($notice,'{author}')===false);truth(strpos(e($notice),'<script>')===false);
    same('',Editorial::copyright($a->settings,array_merge($now,['copyright_mode'=>'none']),'url'));
    foreach(['javascript:alert(1)','//example.org','https://user:password@example.org'] as $bad){reject(static function()use($bad){article20(['source_url'=>$bad]);});}
    reject(static function(){article20(['copyright_mode'=>'php']);});
    $a->settings->savePartial('editorial',['copyright_reprint'=>'本文转载自注明的来源，权利归原作者所有。']);
});
test('021 reading navigation honors exact ordering and visibility without body projection',static function()use($a,$admin){
    $cat=$a->categories->save($admin,['name'=>'Editorial21','kind'=>'article']);$ids=[];
    for($i=0;$i<4;$i++){$id=article20(['category_id'=>$cat]);$a->db->update('cy_contents',$id,['created_at'=>1700000000,'pinned'=>$i===0?1:0]);$ids[]=$id;}
    article20(['category_id'=>$cat,'status'=>'draft']);article20(['category_id'=>$cat,'publish_at'=>date('Y-m-d\TH:i',time()+86400)]);
    $reading=Editorial::reading($a->content,$a->settings,$a->content->get($ids[1],null),null);
    same($ids[0],(int)$reading['previous']['id']);same($ids[2],(int)$reading['next']['id']);same(3,count($reading['related']));truth(!isset($reading['related'][0]['body']));truth(!isset($reading['related'][0]['protected_body']));
    $a->settings->savePartial('editorial',['article_neighbors'=>0,'article_related'=>0]);same(['previous'=>null,'next'=>null,'related'=>[]],Editorial::reading($a->content,$a->settings,$a->content->get($ids[1],null),null));
    $a->settings->savePartial('editorial',['article_neighbors'=>1,'article_related'=>1]);
    $parent=$a->categories->save($admin,['name'=>'Private editorial21','kind'=>'article','access_level'=>'login']);$private=article20(['category_id'=>$parent]);
    $reading=Editorial::reading($a->content,$a->settings,$a->content->get($private,account($admin)),null);same(['previous'=>null,'next'=>null,'related'=>[]],$reading);
});
test('021 bulletin create is idempotent and changed retries conflict',static function()use($a,$admin){
    $input=bulletin21();$id=$a->bulletins->save(account($admin),$input);same($id,$a->bulletins->save(account($admin),$input));
    reject(static function()use($a,$admin,$input){$a->bulletins->save(account($admin),array_merge($input,['title'=>'Conflicting retry']));},409);
    $row=$a->bulletins->get($id);same($input['body'],$row['body']);truth(!isset($row['request_key']));truth(!isset($row['fingerprint']));truth(!isset($row['actor_id']));
});
test('021 bulletin updates require revision and can be archived without deletion',static function()use($a,$admin){
    $input=bulletin21();$id=$a->bulletins->save(account($admin),$input);$input=array_merge($input,['id'=>$id,'revision'=>1,'title'=>'Edited21']);
    $a->bulletins->save(account($admin),$input);same(2,(int)$a->bulletins->get($id)['revision']);
    reject(static function()use($a,$admin,$input){$a->bulletins->save(account($admin),$input);},409);
    $a->bulletins->save(account($admin),array_merge($input,['revision'=>2,'status'=>'archived']));
    reject(static function()use($a,$id){$a->bulletins->get($id);},404);same('archived',$a->bulletins->get($id,account($admin))['status']);
});
test('021 future bulletins and drafts never leak in details counts or searches',static function()use($a,$admin){
    $title='Future21 '.key32();$future=$a->bulletins->save(account($admin),bulletin21(['title'=>$title,'publish_at'=>date('Y-m-d\TH:i',time()+86400)]));
    $draft=$a->bulletins->save(account($admin),bulletin21(['title'=>$title,'status'=>'draft']));
    same(0,$a->bulletins->page(['q'=>$title])['total']);same(2,$a->bulletins->page(['q'=>$title],account($admin))['total']);
    foreach([$future,$draft] as $id){reject(static function()use($a,$id){$a->bulletins->get($id);},404);}
    $a->db->update('cy_bulletins',$future,['publish_at'=>time()-1]);same(1,$a->bulletins->page(['q'=>$title])['total']);
});
test('021 bulletin timestamps reject normalization and non-date values',static function()use($a,$admin){
    foreach(['2026-02-30T12:00','2026-13-01T12:00','tomorrow','2101-01-01T00:00','1969-12-31T00:00','2026-09-12T25:00'] as $date){reject(static function()use($a,$admin,$date){$a->bulletins->save(account($admin),bulletin21(['publish_at'=>$date]));});}
    $id=$a->bulletins->save(account($admin),bulletin21(['publish_at'=>'2024-02-29T12:45']));same('2024-02-29T12:45',date('Y-m-d\TH:i',(int)$a->bulletins->get($id)['publish_at']));
});
test('021 bulletin pagination uses unique ties and literal search characters',static function()use($a,$admin){
    $ids=[];$word='Tied21 '.key32();for($i=0;$i<23;$i++){$ids[]=$a->bulletins->save(account($admin),bulletin21(['title'=>$word,'publish_at'=>'2025-03-01T10:00']));}
    $first=$a->bulletins->page(['q'=>$word,'period'=>'2025-03']);$next=$a->bulletins->page(['q'=>$word,'period'=>'2025-03','page'=>2]);
    same(23,$first['total']);same(20,count($first['items']));same(3,count($next['items']));same($ids[22],(int)$first['items'][0]['id']);same([],array_intersect(array_column($first['items'],'id'),array_column($next['items'],'id')));
    same(0,$a->bulletins->page(['q'=>"%' OR 1=1 --"])['total']);same(0,$a->bulletins->page(['q'=>$word,'period'=>'2025-04'])['total']);
    truth(!isset($first['items'][0]['request_key']));reject(static function()use($a){$a->bulletins->page(['period'=>'2025-13']);});
});
test('021 bulletin admin rights module state and image access are enforced',static function()use($a,$admin){
    $uid=user14('bulletin-editor21');$actor=account($uid);reject(static function()use($a,$actor){$a->bulletins->save($actor,bulletin21());},403);reject(static function()use($a,$actor){$a->bulletins->page([],$actor);},403);
    $a->db->update('cy_users',$uid,['role'=>'editor']);$actor=account($uid);$id=$a->bulletins->save($actor,bulletin21());
    $a->db->update('cy_users',$uid,['status'=>'blocked']);reject(static function()use($a,$actor){$a->bulletins->save($actor,bulletin21());},403);
    $a->settings->savePartial('modules',['bulletins_enabled'=>0]);reject(static function()use($a){$a->bulletins->page();},403);reject(static function()use($a,$id){$a->bulletins->get($id);},403);
    truth(count($a->bulletins->page([],account($admin))['items'])>0);$a->settings->savePartial('modules',['bulletins_enabled'=>1]);
    reject(static function()use($a,$admin){$a->bulletins->save(account($admin),bulletin21(['cover_id'=>9999999]));});
    $media=$a->db->one('SELECT id FROM cy_media WHERE is_private=1 LIMIT 1');if($media){reject(static function()use($a,$admin,$media){$a->bulletins->save(account($admin),bulletin21(['cover_id'=>$media['id']]));});}
    foreach(['javascript:alert(1)','https://user:password@example.org'] as $bad){reject(static function()use($a,$admin,$bad){$a->bulletins->save(account($admin),bulletin21(['source_url'=>$bad]));});}
    reject(static function()use($a,$admin){$a->bulletins->save(account($admin),bulletin21(['request_key'=>'invalid']));});
    reject(static function()use($a,$admin){$a->bulletins->save(account($admin),bulletin21(['body'=>str_repeat('字',4001)]));});
});
test('021 schema is restartable and recovery includes bulletins',static function()use($a){
    $before=(int)$a->db->value('SELECT COUNT(*) FROM cy_bulletins');\Chengyu\Core\Migration021::up($a->db);\Chengyu\Core\Migration021::up($a->db);same($before,(int)$a->db->value('SELECT COUNT(*) FROM cy_bulletins'));
    \Chengyu\Core\SnapshotSchema::check($a->db);truth(in_array('cy_bulletins',\Chengyu\Core\SnapshotSchema::tables(),true));
    truth(in_array('anime-news',\Chengyu\Core\Icons::NAMES,true));truth(strpos($a->visuals->image('icon:anime-news'),'icon-news.webp')!==false);
});
