<?php
use Chengyu\Services\Archives;
use Chengyu\Core\RuntimeLimits;

function article20(array $overrides=[]): int{
    global $a,$admin;
    return $a->content->save(account($admin),array_merge(['kind'=>'article','title'=>'Archive '.key32(),'body'=>'Public introduction','protected_body'=>'PRIVATE-BODY-020','status'=>'published','access_level'=>'public','comment_enabled'=>'1'],$overrides),true);
}
test('020 archive calendar uses exact local boundaries including leap days',static function(){
    [$from,$to]=Archives::interval('2024-02');same('2024-02-01 00:00:00',date('Y-m-d H:i:s',$from));same('2024-03-01 00:00:00',date('Y-m-d H:i:s',$to));same(29*86400,$to-$from);
    same([],Archives::interval(''));[$from,$to]=Archives::interval('2025');same('2026-01-01',date('Y-m-d',$to));same(365*86400,$to-$from);
    foreach(['2024-13','2024-00','24-02','2024-2','2024 OR 1=1','2101','1969','2024-02-30'] as $bad){reject(static function()use($bad){Archives::interval($bad);});}
});
test('020 archives paginate chronologically without loading bodies or draft titles',static function()use($a,$admin){
    $cat=$a->categories->save($admin,['name'=>'Archive fixture20','kind'=>'article']);$ids=[];$from=Archives::interval('2024-02')[0];
    for($i=0;$i<33;$i++){$id=article20(['category_id'=>$cat]);$a->db->update('cy_contents',$id,['created_at'=>$from+60*$i,'pinned'=>$i===0?1:0]);$ids[]=$id;}
    $draft=article20(['category_id'=>$cat,'status'=>'draft']);$a->db->update('cy_contents',$draft,['created_at'=>$from]);
    $future=article20(['category_id'=>$cat]);$a->db->update('cy_contents',$future,['created_at'=>$from,'publish_at'=>time()+86400]);
    $a->settings->savePartial('modules',['archives_enabled'=>1,'articles_enabled'=>1]);
    $p=Archives::page($a->content,$a->settings,null,['period'=>'2024-02','category'=>$cat]);$p2=Archives::page($a->content,$a->settings,null,['period'=>'2024-02','category'=>$cat,'page'=>2]);
    same(33,$p['feed']['total']);same(30,count($p['feed']['items']));same(3,count($p2['feed']['items']));same($ids[32],(int)$p['feed']['items'][0]['id']);
    same($ids[0],(int)$p2['feed']['items'][2]['id']);truth(!array_key_exists('body',$p['feed']['items'][0]));truth(!array_key_exists('protected_body',$p['feed']['items'][0]));
    same([],array_intersect(array_column($p['feed']['items'],'id'),array_column($p2['feed']['items'],'id')));
});
test('020 archive count and titles inherit ancestor access and module controls',static function()use($a,$admin){
    $parent=$a->categories->save($admin,['name'=>'Restricted20','kind'=>'article','access_level'=>'login']);$child=$a->categories->save($admin,['name'=>'Public child20','kind'=>'article','parent_id'=>$parent]);article20(['category_id'=>$child]);
    same(0,Archives::page($a->content,$a->settings,null,['category'=>$child])['feed']['total']);$u=user14('archive-reader20');same(1,Archives::page($a->content,$a->settings,account($u),['category'=>$child])['feed']['total']);
    $a->settings->savePartial('modules',['archives_enabled'=>0]);reject(static function()use($a){Archives::page($a->content,$a->settings,null,[]);},403);$a->settings->savePartial('modules',['archives_enabled'=>1]);
    $a->settings->savePartial('modules',['articles_enabled'=>0]);truth(!\Chengyu\Services\Navigation::enabled($a->settings,'archives'));reject(static function()use($a){Archives::page($a->content,$a->settings,null,[]);},403);$a->settings->savePartial('modules',['articles_enabled'=>1]);
});
test('020 category tiles respect limit after permission evaluation and change immediately',static function()use($a,$admin){
    $parent=$a->categories->save($admin,['name'=>'Private menu20','kind'=>'article','access_level'=>'login','sort_order'=>0]);$child=$a->categories->save($admin,['name'=>'Child menu20','kind'=>'article','parent_id'=>$parent]);
    $rows=$a->categories->visible('article',null,2);same(2,count($rows));truth(!in_array($child,array_map('intval',array_column($rows,'id')),true));
    $u=user14('category-reader20');$allowed=array_map('intval',array_column($a->categories->visible('article',account($u)),'id'));truth(in_array($child,$allowed,true));
    $a->db->update('cy_categories',$parent,['access_level'=>'vip']);truth(!in_array($child,array_map('intval',array_column($a->categories->visible('article',account($u)),'id')),true));
});
test('020 comments paginate beyond fifty with exact count and stable pinned order',static function()use($a,$admin){
    $id=article20();$a->settings->savePartial('community',['comments_enabled'=>1,'comment_visibility'=>'public']);$ids=[];
    for($i=0;$i<57;$i++){$ids[]=$a->db->insert('cy_comments',['content_id'=>$id,'user_id'=>$admin,'body'=>'Reply20-'.$i,'status'=>'approved','created_at'=>time(),'pinned'=>$i===0?1:0]);}
    $a->db->insert('cy_comments',['content_id'=>$id,'user_id'=>$admin,'body'=>'PENDING-COMMENT-020','status'=>'pending','created_at'=>time()]);
    $first=$a->content->commentPage($id,null);$next=$a->content->commentPage($id,null,['comment_page'=>2]);same(57,$first['total']);same(50,count($first['items']));same(7,count($next['items']));same($ids[0],(int)$first['items'][0]['id']);
    same([],array_intersect(array_column($first['items'],'id'),array_column($next['items'],'id')));same(2,$a->content->commentPage($id,null,['comment_page'=>999])['page']);
    $old=$a->content->commentPage($id,null,['comment_sort'=>'oldest']);same($ids[1],(int)$old['items'][1]['id']);
});
test('020 comment filters cannot reveal other authors or locked discussion data',static function()use($a,$admin){
    $id=article20();$u=user14('comment-reader20');foreach([$admin,$u] as $who){$a->db->insert('cy_comments',['content_id'=>$id,'user_id'=>$who,'body'=>'Reply by '.$who,'status'=>'approved','created_at'=>time()]);}
    $author=$a->content->commentPage($id,null,['comment_sort'=>'author']);same(1,$author['total']);same($admin,(int)$author['items'][0]['user_id']);
    $a->db->update('cy_contents',$id,['access_level'=>'paid']);same(0,$a->content->commentPage($id,account($u))['total']);
    $a->db->update('cy_contents',$id,['access_level'=>'reply']);same(2,$a->content->commentPage($id,null)['total']);
    $a->db->update('cy_contents',$id,['comment_enabled'=>0]);same(0,$a->content->commentPage($id,account($admin))['total']);
    reject(static function()use($a,$id){$a->content->commentPage($id,null,['comment_sort'=>'id;DROP TABLE']);});
});
test('020 runtime limits handle unlimited and overflow without PHP version coercion',static function(){
    same(2147483648,RuntimeLimits::bytes('2G'));same(262144,RuntimeLimits::bytes('256k'));same(-1,RuntimeLimits::bytes('-1'));same(null,RuntimeLimits::bytes('garbage'));same(PHP_INT_MAX,RuntimeLimits::bytes('99999999999999999999G'));
    $rows=RuntimeLimits::inspect(['memory_limit'=>'32M','post_max_size'=>'0','upload_max_filesize'=>'128K','max_execution_time'=>'10','max_input_time'=>'-1','file_uploads'=>'Off','max_input_vars'=>'1000']);$map=array_column($rows,'state','check');
    same('warning',$map['memory_limit']);same('pass',$map['post_max_size']);same('warning',$map['upload_max_filesize']);same('warning',$map['max_execution_time']);same('warning',$map['max_input_time']);same('warning',$map['file_uploads']);
});

test('020 visual assets reject unauthorized actors and re-read a demoted account',static function()use($a,$admin){
    $u=user14('visual-reader20');$actor=account($u);$input=['slot'=>'icon:star','asset_key'=>'icon-shop'];
    reject(static function()use($a,$actor,$input){$a->visuals->save($actor,$input);},403);
    $a->db->update('cy_users',$u,['role'=>'admin']);$stale=account($u);$a->db->update('cy_users',$u,['role'=>'member']);
    reject(static function()use($a,$stale,$input){$a->visuals->save($stale,$input);},403);
    same(0,(int)$a->visuals->current('icon:star')['revision']);
});
test('020 visual bindings publish immediately and restore cannot bypass revision conflicts',static function()use($a,$admin){
    $actor=account($admin);$a->visuals->save($actor,['slot'=>'icon:star','asset_key'=>'icon-shop']);
    truth(strpos($a->visuals->image('icon:star'),'icon-shop.webp')!==false);
    reject(static function()use($a,$actor){$a->visuals->save($actor,['slot'=>'icon:star','operation'=>'reset','revision'=>0]);},409);
    $a->visuals->save($actor,['slot'=>'icon:star','operation'=>'reset','revision'=>1]);same('',$a->visuals->image('icon:star'));same(2,(int)$a->visuals->current('icon:star')['revision']);
    reject(static function()use($a,$actor){$a->visuals->save($actor,['slot'=>'icon:star','asset_key'=>'cover-space','revision'=>2]);});
    reject(static function()use($a,$actor){$a->visuals->save($actor,['slot'=>'scene:../config','asset_key'=>'icon-shop']);});
});
test('020 private nonimage missing and executable image media cannot be used as public visuals',static function()use($a,$admin,$tmp){
    $actor=account($admin);$path=$tmp.'/visual-020.png';file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWioAAAAASUVORK5CYII='));
    $public=$a->media->storeLocal($actor,$path,'visual.png',false,1048576);$private=$a->media->storeLocal($actor,$path,'private.png',true,1048576);
    $a->visuals->save($actor,['slot'=>'scene:empty','media_id'=>$public]);truth(strpos($a->visuals->image('scene:empty'),'id='.$public)!==false);
    foreach([$private,2147483647] as $bad){reject(static function()use($a,$actor,$bad){$a->visuals->save($actor,['slot'=>'scene:empty','revision'=>1,'media_id'=>$bad]);});}
    reject(static function()use($a,$actor,$public){$a->visuals->save($actor,['slot'=>'scene:empty','revision'=>1,'media_id'=>$public,'asset_key'=>'icon-shop']);});
    $a->db->update('cy_media',$public,['mime'=>'image/svg+xml']);$a->visuals->resetCache();same('',$a->visuals->image('scene:empty'));
    reject(static function()use($a,$actor,$public){$a->visuals->save($actor,['slot'=>'scene:empty','revision'=>1,'media_id'=>$public]);});
    $a->db->update('cy_media',$public,['mime'=>'image/png','is_private'=>1]);$a->visuals->resetCache();same('',$a->visuals->image('scene:empty'));
    $a->db->execute('DELETE FROM cy_media WHERE id=?',[$public]);$a->visuals->resetCache();same('',$a->visuals->image('scene:empty'));
});
test('020 covers survive edits and feed projection without reusing default illustrations',static function()use($a,$admin){
    $id=article20(['cover_art'=>'cover-space']);same('cover-space',$a->content->get($id,null)['cover_art']);
    $feed=$a->content->feed('article',account($admin),['q'=>$a->content->get($id,null)['title']]);same('cover-space',$feed['items'][0]['cover_art']);
    $old=$a->content->get($id,account($admin));$a->content->save(account($admin),['id'=>$id,'edit_version'=>$old['edit_version'],'title'=>$old['title'],'body'=>'Edited','status'=>'published'],true);
    same('cover-space',$a->content->get($id,null)['cover_art']);
    reject(static function(){article20(['cover_art'=>'../../config.php']);});
    truth(cover(['id'=>1234])!==cover(['id'=>1235]));
});
test('020 categories navigation and builder use the extended shared icon registry',static function()use($a,$admin){
    foreach(['cat','rocket','anime-shop'] as $symbol){
        $id=$a->categories->save($admin,['name'=>'Icon '.$symbol,'kind'=>'article','icon'=>$symbol]);same($symbol,$a->db->value('SELECT icon FROM cy_categories WHERE id=?',[$id]));
        $nav=$a->admin->entity($admin,'navigation',['label'=>'Icon '.$symbol,'route'=>'archives','icon'=>$symbol,'active'=>1]);same($symbol,$a->db->value('SELECT icon FROM cy_navigation WHERE id=?',[$nav]));
        $doc=$a->layout->validate(json_encode(['format'=>'chengyu-layout','version'=>1,'blocks'=>[['id'=>'icons20','type'=>'features','items'=>[['title'=>'Fixture','icon'=>$symbol]]]]],JSON_THROW_ON_ERROR));same($symbol,$doc['blocks'][0]['items'][0]['icon']);
    }
    \Chengyu\Core\Migration020::up($a->db);\Chengyu\Core\SnapshotSchema::check($a->db);truth(in_array('cy_visual_assets',\Chengyu\Core\SnapshotSchema::tables(),true));
});
