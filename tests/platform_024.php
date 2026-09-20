<?php
use Chengyu\Services\Navigation as Nav24;

function category24(array $changes=[]): int{global $a,$admin;return $a->categories->save($admin,array_merge(['name'=>'Column24 '.key32(),'kind'=>'article'],$changes));}
function preview24(int $category,array $changes=[],?array $user=null): array{global $a;return $a->content->menuPreviews(['one'=>array_merge(['kind'=>'article','category_id'=>$category,'preview_count'=>6,'preview_sort'=>'latest'],$changes)],$user)['one'];}
function navmap24(?array $user=null): array{return array_column(nav22($user),null,'id');}

test('024 executable and delivery versions cannot drift',static function(){
    $release=json_decode(file_get_contents(dirname(__DIR__).'/RELEASE.json'),true);
    same(\Chengyu\App::VERSION,$release['version']);same(\Chengyu\Core\Migrations::VERSION,$release['database_schema_version']);
});
test('024 additive migration is restartable and preserves menu records',static function()use($a){
    $before=$a->db->all('SELECT * FROM cy_navigation ORDER BY id');
    \Chengyu\Core\Migration024::up($a->db);\Chengyu\Core\Migration024::up($a->db);
    same($before,$a->db->all('SELECT * FROM cy_navigation ORDER BY id'));\Chengyu\Core\SnapshotSchema::check($a->db);
});
test('024 menu settings reject incompatible categories and destinations',static function()use($a,$admin){
    $cat=category24();$other=category24(['kind'=>'thread']);
    foreach([['route'=>'home','category_id'=>$cat],['route'=>'home','preview_count'=>2],['route'=>'articles','category_id'=>$other],['route'=>'articles','category_id'=>2147483647],['route'=>'articles','preview_count'=>7],['route'=>'articles','preview_count'=>-1],['route'=>'articles','preview_sort'=>'id;DROP']] as $bad){reject(static function()use($a,$admin,$bad){$a->admin->entity($admin,'navigation',menu22($bad));});}
});
test('024 category links work with previews disabled',static function()use($a,$admin){
    $cat=category24();$id=$a->admin->entity($admin,'navigation',menu22(['route'=>'articles','category_id'=>$cat]));
    $row=navmap24()[$id];same($cat,(int)$row['category_id']);same([],$row['previews']);
    $actual=array_column($a->navigation(),null,'id');truth(strpos($actual[$id]['href'],'category='.$cat)!==false);
});
test('024 category permissions hide descendants even when menu visibility is public',static function()use($a,$admin){
    $parent=category24(['access_level'=>'login']);$cat=category24(['parent_id'=>$parent]);
    $id=$a->admin->entity($admin,'navigation',menu22(['route'=>'articles','category_id'=>$cat]));$child=$a->admin->entity($admin,'navigation',menu22(['parent_id'=>$id]));
    truth(!isset(navmap24()[$id]));truth(!isset(navmap24()[$child]));
    $user=account(user14('reader24'));truth(isset(navmap24($user)[$child]));
    $user['is_guest']=1;truth(!isset(navmap24($user)[$child]));$user['is_guest']=0;$user['status']='suspended';truth(!isset(navmap24($user)[$child]));
});
test('024 menu source always excludes drafts scheduled and other-category items',static function()use($a){
    $cat=category24();$one=article20(['category_id'=>$cat]);$draft=article20(['category_id'=>$cat,'status'=>'draft']);
    $future=article20(['category_id'=>$cat]);$a->db->update('cy_contents',$future,['publish_at'=>time()+3600]);article20(['category_id'=>category24()]);
    same([$one],array_map('intval',array_column(preview24($cat),'id')));
    $next=article20(['category_id'=>$cat]);same([$next,$one],array_map('intval',array_column(preview24($cat),'id')));
    $a->db->update('cy_contents',$one,['status'=>'archived']);same([$next],array_map('intval',array_column(preview24($cat),'id')));
});
test('024 published list and menu share inherited membership protection',static function()use($a){
    $parent=category24(['access_level'=>'vip','min_vip_tier'=>1]);$cat=category24(['parent_id'=>$parent]);article20(['category_id'=>$cat]);
    same([],$a->content->feed('article',null,['category'=>$cat])['items']);same([],preview24($cat));
    same([],preview24($cat,[],account(user14('novip24'))));
});
test('024 menu preview has no body password or resource fields',static function(){
    $cat=category24();article20(['category_id'=>$cat,'access_level'=>'paid','price'=>'1.00','body'=>'DO-NOT-SELECT-BODY24','protected_body'=>'DO-NOT-SELECT-SECRET24']);
    $row=preview24($cat)[0];same('paid',$row['access_level']);
    foreach(['body','protected_body','access_password','resource_id','email'] as $field){truth(!array_key_exists($field,$row));}
    truth(strpos(json_encode($row),'DO-NOT-SELECT')===false);
});
test('024 preview count ordering and stable ties are bounded',static function()use($a){
    $cat=category24();$ids=[];for($i=0;$i<8;$i++){$id=article20(['category_id'=>$cat]);$a->db->update('cy_contents',$id,['created_at'=>1000,'view_count'=>$i,'updated_at'=>2000-$i]);$ids[]=$id;}
    same([$ids[7],$ids[6]],array_map('intval',array_column(preview24($cat,['preview_count'=>2]),'id')));
    same($ids[7],(int)preview24($cat,['preview_sort'=>'popular'])[0]['id']);same($ids[0],(int)preview24($cat,['preview_sort'=>'updated'])[0]['id']);
    same(6,count(preview24($cat)));$a->db->update('cy_contents',$ids[0],['pinned'=>1]);same($ids[0],(int)preview24($cat)[0]['id']);
});
test('024 deleted or revoked preview cover falls back without leaking private media',static function()use($a){
    $cat=category24();$cover=photo22();article20(['category_id'=>$cat,'cover_id'=>$cover]);same($cover,(int)preview24($cat)[0]['cover_id']);
    $a->db->update('cy_media',$cover,['is_private'=>1]);same(0,(int)preview24($cat)[0]['cover_id']);
    $a->db->update('cy_media',$cover,['is_private'=>0,'mime'=>'application/pdf']);same(0,(int)preview24($cat)[0]['cover_id']);
});
test('024 source category deletion and type changes hide the menu branch',static function()use($a,$admin){
    $cat=category24();$id=$a->admin->entity($admin,'navigation',menu22(['route'=>'articles','category_id'=>$cat]));
    $a->db->update('cy_categories',$cat,['kind'=>'product']);truth(!isset(navmap24()[$id]));
    $a->db->execute('DELETE FROM cy_categories WHERE id=?',[$cat]);truth(!isset(navmap24(account($admin))[$id]));
});
test('024 preview settings participate in idempotency and revision checks',static function()use($a,$admin){
    $input=menu22(['route'=>'articles','category_id'=>category24(),'preview_count'=>2]);$id=$a->admin->entity($admin,'navigation',$input);
    same($id,$a->admin->entity($admin,'navigation',$input));reject(static function()use($a,$admin,$input){$a->admin->entity($admin,'navigation',array_merge($input,['preview_count'=>3]));},409);
    $a->admin->entity($admin,'navigation',array_merge($input,['id'=>$id,'revision'=>1,'preview_count'=>3]));
    reject(static function()use($a,$admin,$input,$id){$a->admin->entity($admin,'navigation',array_merge($input,['id'=>$id,'revision'=>1]));},409);
    $a->db->update('cy_navigation',$id,['preview_count'=>0]);
});
test('024 enabled content-menu budget is enforced inside the write transaction',static function()use($a,$admin){
    $created=[];try{
        for($i=0;$i<Nav24::MAX_PREVIEW_MENUS;$i++){$created[]=$a->admin->entity($admin,'navigation',menu22(['route'=>'articles','preview_count'=>1]));}
        reject(static function()use($a,$admin){$a->admin->entity($admin,'navigation',menu22(['route'=>'articles','preview_count'=>1]));});
        $disabled=$a->admin->entity($admin,'navigation',menu22(['route'=>'articles','preview_count'=>1,'active'=>0]));$created[]=$disabled;
        reject(static function()use($a,$admin,$disabled){$a->admin->entity($admin,'navigation',menu22(['id'=>$disabled,'revision'=>1,'route'=>'articles','preview_count'=>1]));});
    }finally{foreach($created as $id){$a->db->update('cy_navigation',$id,['preview_count'=>0]);}}
});
test('024 menus refresh after category access changes without shared HTML caching',static function()use($a,$admin){
    $cat=category24();article20(['category_id'=>$cat]);$id=$a->admin->entity($admin,'navigation',menu22(['route'=>'articles','category_id'=>$cat,'preview_count'=>2]));
    same(1,count(navmap24()[$id]['previews']));$a->db->update('cy_categories',$cat,['access_level'=>'login']);truth(!isset(navmap24()[$id]));
    truth(isset(navmap24(account(user14('signed24')))[$id]));$a->db->update('cy_navigation',$id,['preview_count'=>0]);
});
test('024 forum and shop preview routes follow their module switches',static function()use($a,$admin){
    foreach(['forum'=>'thread','shop'=>'product'] as $route=>$kind){$cat=category24(['kind'=>$kind]);$content=article20(['kind'=>$kind,'category_id'=>$cat]);$id=$a->admin->entity($admin,'navigation',menu22(['route'=>$route,'category_id'=>$cat,'preview_count'=>2]));
        same($content,(int)navmap24()[$id]['previews'][0]['id']);$a->settings->savePartial('modules',[$route.'_enabled'=>0]);truth(!isset(navmap24()[$id]));
        $a->settings->savePartial('modules',[$route.'_enabled'=>1]);$a->db->update('cy_navigation',$id,['preview_count'=>0]);}
});
test('024 duplicate preview configurations return identical bounded groups',static function()use($a){
    $cat=category24();article20(['category_id'=>$cat]);$group=['kind'=>'article','category_id'=>$cat,'preview_count'=>2];$out=$a->content->menuPreviews(['one'=>$group,'two'=>$group],null);same($out['one'],$out['two']);
    reject(static function()use($a,$group){$a->content->menuPreviews(array_fill(0,9,$group),null);});
});
test('024 new original icon slots allow administrator replacement',static function()use($a,$admin){
    foreach(['reading','support'] as $name){$slot='icon:anime-'.$name;truth(isset(\Chengyu\Services\VisualAssets::slots()[$slot]));truth(strpos($a->visuals->image($slot),'icon-'.$name.'.webp')!==false);}
    $slot='icon:anime-reading';$image=photo22();$a->visuals->save(account($admin),['slot'=>$slot,'revision'=>0,'media_id'=>$image]);truth(strpos($a->visuals->image($slot),'id='.$image)!==false);
    $a->visuals->save(account($admin),['slot'=>$slot,'revision'=>1,'operation'=>'reset']);truth(strpos($a->visuals->image($slot),'icon-reading.webp')!==false);
});
test('024 previews use current published translations without selecting translated bodies',static function()use($a,$admin){
    $cat=category24();$id=article20(['category_id'=>$cat,'title'=>'Original24']);$row=$a->db->one('SELECT edit_version FROM cy_contents WHERE id=?',[$id]);
    $locale=\Chengyu\Core\Locale::current();$a->translations->save($admin,['content_id'=>$id,'locale'=>$locale,'source_version'=>$row['edit_version'],'status'=>'published','title'=>'Translated24','body'=>'TRANSLATED-BODY24']);
    same('Translated24',preview24($cat)[0]['title']);truth(strpos(json_encode(preview24($cat)),'TRANSLATED-BODY24')===false);
    $a->db->update('cy_contents',$id,['edit_version'=>(int)$row['edit_version']+1]);same('Original24',preview24($cat)[0]['title']);
});
test('024 structured FAQ keeps multiline answers and literal separators',static function()use($a){
    $block=['id'=>'faq24','type'=>'faq','items'=>[['title'=>'Can I use A|B?','text'=>"Yes.\n\n**A|B** is supported."]]];
    $doc=$a->layout->validate(json_encode(['format'=>'chengyu-layout','version'=>1,'blocks'=>[$block]]));
    same([['title'=>'Can I use A|B?','text'=>"Yes.\n\n**A|B** is supported."]],\Chengyu\Services\Layout::faqEntries($doc['blocks'][0]));
    truth(in_array('faq',\Chengyu\Services\Layout::ITEM_TYPES,true));
});
test('024 legacy FAQ entries remain in order before newly added entries',static function(){
    $block=['text'=>"Old question|Old answer|literal separator\nnot a question\n|empty question\nEmpty answer|",'items'=>[['title'=>'New question','text'=>'New answer']]];
    same([['title'=>'Old question','text'=>'Old answer|literal separator'],['title'=>'New question','text'=>'New answer']],\Chengyu\Services\Layout::faqEntries($block));
});
test('024 FAQ validation rejects blank answers and oversized entry lists',static function()use($a){
    foreach([[['title'=>'Blank','text'=>' ']],array_fill(0,13,['title'=>'Question','text'=>'Answer'])] as $entries){reject(static function()use($a,$entries){$a->layout->validate(json_encode(['format'=>'chengyu-layout','version'=>1,'blocks'=>[['id'=>'invalid24','type'=>'faq','items'=>$entries]]]));});}
});
