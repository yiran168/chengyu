<?php
use Chengyu\Services\{Navigation,ArticleImages};

function menu22(array $input=[]): array{return array_merge(['label'=>'Menu22 '.key32(),'route'=>'home','active'=>1,'request_key'=>key32()],$input);}
function nav22(?array $user=null): array{global $a;return (new Navigation($a->db,$a->settings,$a->activity))->visible($user,static function(array $row):string{return '/'.$row['route'];});}
function photo22(bool $private=false,?int $owner=null): int{global $a,$admin,$tmp;$file=$tmp.'/photo22.png';file_put_contents($file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWioAAAAASUVORK5CYII='));return $a->media->storeLocal(account($owner??$admin),$file,'photo22.png',$private,1048576);}

test('022 navigation rechecks actual administrator authority',static function()use($a){
    $id=user14('menu-admin22');$a->db->update('cy_users',$id,['role'=>'admin']);$a->db->update('cy_users',$id,['role'=>'editor']);
    reject(static function()use($a,$id){$a->admin->entity($id,'navigation',menu22());},403);
    $a->db->update('cy_users',$id,['role'=>'admin','status'=>'suspended']);reject(static function()use($a,$id){$a->admin->entity($id,'navigation',menu22());},403);
});
test('022 menu creates are idempotent with conflicting payload rejection',static function()use($a,$admin){
    $input=menu22();$id=$a->admin->entity($admin,'navigation',$input);same($id,$a->admin->entity($admin,'navigation',$input));
    reject(static function()use($a,$admin,$input){$a->admin->entity($admin,'navigation',array_merge($input,['label'=>'Changed22']));},409);
    reject(static function()use($a,$admin){$a->admin->entity($admin,'navigation',menu22(['request_key'=>'bad']));});
});
test('022 menu edits reject stale revisions missing parents and self cycles',static function()use($a,$admin){
    $input=menu22();$id=$a->admin->entity($admin,'navigation',$input);$edit=array_merge($input,['id'=>$id,'revision'=>1,'label'=>'Edited menu22']);$a->admin->entity($admin,'navigation',$edit);
    reject(static function()use($a,$admin,$edit){$a->admin->entity($admin,'navigation',$edit);},409);
    foreach([$id,2147483647] as $parent){reject(static function()use($a,$admin,$edit,$parent){$a->admin->entity($admin,'navigation',array_merge($edit,['revision'=>2,'parent_id'=>$parent]));});}
    same(0,(int)$a->db->value('SELECT parent_id FROM cy_navigation WHERE id=?',[$id]));
});
test('022 depth validation covers moved subtrees and indirect cycles',static function()use($a,$admin){
    $input=menu22();$root=$a->admin->entity($admin,'navigation',$input);$child=$a->admin->entity($admin,'navigation',menu22(['parent_id'=>$root]));$third=$a->admin->entity($admin,'navigation',menu22(['parent_id'=>$child]));
    reject(static function()use($a,$admin,$third){$a->admin->entity($admin,'navigation',menu22(['parent_id'=>$third]));});
    $other=$a->admin->entity($admin,'navigation',menu22());foreach([$other,$child] as $parent){reject(static function()use($a,$admin,$input,$root,$parent){$a->admin->entity($admin,'navigation',array_merge($input,['id'=>$root,'revision'=>1,'parent_id'=>$parent]));});}
});
test('022 parent visibility and enabled modules hide entire menu branches',static function()use($a,$admin){
    $input=menu22(['visibility'=>'login']);$root=$a->admin->entity($admin,'navigation',$input);$child=$a->admin->entity($admin,'navigation',menu22(['parent_id'=>$root]));
    truth(!in_array($child,array_column(nav22(),'id')));$user=account(user14('menu-reader22'));truth(in_array($child,array_map('intval',array_column(nav22($user),'id'))));
    $a->admin->entity($admin,'navigation',array_merge($input,['id'=>$root,'revision'=>1,'active'=>0]));truth(!in_array($child,array_column(nav22($user),'id')));
    $root2=$a->admin->entity($admin,'navigation',menu22(['route'=>'bulletins']));$kid2=$a->admin->entity($admin,'navigation',menu22(['parent_id'=>$root2]));$a->settings->savePartial('modules',['bulletins_enabled'=>0]);truth(!in_array($kid2,array_column(nav22($user),'id')));$a->settings->savePartial('modules',['bulletins_enabled'=>1]);
});
test('022 corrupt cyclic or orphaned menu rows fail closed on public reads',static function()use($a,$admin){
    $x=$a->admin->entity($admin,'navigation',menu22());$y=$a->admin->entity($admin,'navigation',menu22(['parent_id'=>$x]));$a->db->update('cy_navigation',$x,['parent_id'=>$y]);
    truth(!in_array($x,array_column(nav22(),'id')));truth(!in_array($y,array_column(nav22(),'id')));$a->db->update('cy_navigation',$x,['parent_id'=>0]);
    $a->db->update('cy_navigation',$y,['parent_id'=>2147483647]);truth(!in_array($y,array_column(nav22(),'id')));$a->db->update('cy_navigation',$y,['parent_id'=>$x]);
});
test('022 menu images reject private media and fall back after revocation',static function()use($a,$admin){
    $public=photo22();$private=photo22(true);reject(static function()use($a,$admin,$private){$a->admin->entity($admin,'navigation',menu22(['image_id'=>$private]));});
    $input=menu22(['image_id'=>$public]);$id=$a->admin->entity($admin,'navigation',$input);$map=array_column(nav22(),null,'id');same($public,(int)$map[$id]['image_id']);truth(!isset($map[$id]['request_key']));
    $a->db->update('cy_media',$public,['mime'=>'image/svg+xml']);$map=array_column(nav22(),null,'id');same(0,(int)$map[$id]['image_id']);
    $tree=Navigation::tree([['id'=>1,'parent_id'=>0],['id'=>2,'parent_id'=>1],['id'=>3,'parent_id'=>2]]);same(3,$tree[0]['children'][0]['children'][0]['id']);
});
test('022 Markdown image parsing excludes fenced and inline code',static function(){
    $text="![Sky](media:12)\n`![sample](media:13)`\n```\n![Code](media:14)\n```\n![Again](media:12)\n![Leaf](media:15)";
    same([12,15],\Chengyu\Core\Markdown::imageIds($text));$out=\Chengyu\Core\Markdown::render('`[example](https://example.org)`');truth(strpos($out,'<a ')===false);
});
test('022 explicit article images preserve order deduplicate and survive old editors',static function()use($a,$admin){
    $one=photo22();$two=photo22();$id=article20(['gallery_image_1'=>$two,'gallery_image_2'=>$one,'gallery_image_3'=>$two,'image_layout'=>'gallery']);$item=$a->content->get($id,null);
    same([$two,$one],ArticleImages::ids($item['gallery_ids']));$a->content->save(account($admin),['id'=>$id,'kind'=>'article','title'=>$item['title'],'body'=>'Updated22','status'=>'published','edit_version'=>$item['edit_version']],true);
    same([$two,$one],ArticleImages::ids($a->content->get($id,null)['gallery_ids']));
});
test('022 article gallery only indexes public-body images and avoids protected projection',static function()use($a,$admin){
    $one=photo22();$two=photo22();$secret=photo22(true);$other=photo22();$id=article20(['body'=>"![One](media:$one)\n![Two](media:$two)\n![Private](media:$secret)",'protected_body'=>"![Protected](media:$other)"]);
    $item=$a->content->get($id,null);same([$one,$two],ArticleImages::ids($item['body_image_ids']));
    $feed=$a->content->feed('article',account($admin),['q'=>$item['title']]);$row=array_column($feed['items'],null,'id')[$id];same([$one,$two],$row['public_images']);truth(!isset($row['body']));truth(!isset($row['protected_body']));
});
test('022 gallery permissions reject stolen private and nonimage media',static function()use($a){
    $member=account(user14('gallery-member22'));$someone=photo22();$private=photo22(true);foreach([$someone,$private,2147483647] as $id){reject(static function()use($a,$member,$id){$a->content->save($member,['kind'=>'article','title'=>'Denied gallery22','body'=>'Body','status'=>'draft','gallery_image_1'=>$id],false);});}
});
test('022 public media revocation removes both gallery URLs and inline images',static function()use($a){
    $one=photo22();$two=photo22();$id=article20(['gallery_image_1'=>$one,'gallery_image_2'=>$two]);$item=$a->content->get($id,null);$a->db->update('cy_media',$two,['is_private'=>1]);
    same([$one],ArticleImages::hydrate($a->db,[$item])[0]['public_images']);$html=ArticleImages::render($a->db,"![Private](media:$two)",'/sub');truth(strpos($html,'<img')===false);truth(strpos($html,'media.php')===false);
});
test('022 image rendering uses subdirectory URLs and escaped captions without remote fetching',static function()use($a){
    $id=photo22();$html=ArticleImages::render($a->db,"![<script>caption</script>](media:$id)",'/sub');truth(strpos($html,'/sub/media.php?id='.$id)!==false);truth(strpos($html,'<script>')===false);truth(strpos($html,'class="image-caption"')!==false);
    $plain=ArticleImages::render($a->db,"![Photo](media:$id)",'',false);truth(strpos($plain,'image-caption')===false);
    truth(strpos(ArticleImages::render($a->db,'![remote](https://127.0.0.1/private)',''),'<img')===false);
});
test('022 image presentation follows per-article settings and safe fallbacks',static function()use($a){
    $row=['kind'=>'article','image_layout'=>'default','public_images'=>[1,2]];$a->settings->savePartial('editorial',['article_multimage'=>1,'article_thumbnail'=>'left']);same('gallery',ArticleImages::mode($row,$a->settings));
    $a->settings->savePartial('editorial',['article_multimage'=>0]);same('single',ArticleImages::mode($row,$a->settings));$row['image_layout']='gallery';same('gallery',ArticleImages::mode($row,$a->settings));$row['public_images']=[1];same('single',ArticleImages::mode($row,$a->settings));$row['image_layout']='text';same('text',ArticleImages::mode($row,$a->settings));$row['kind']='product';same('single',ArticleImages::mode($row,$a->settings));$a->settings->savePartial('editorial',['article_multimage'=>1]);
});
test('022 schema repeat migration keeps data and registers the original gallery icon',static function()use($a){
    $before=(int)$a->db->value('SELECT COUNT(*) FROM cy_navigation');\Chengyu\Core\Migration022::up($a->db);\Chengyu\Core\Migration022::up($a->db);same($before,(int)$a->db->value('SELECT COUNT(*) FROM cy_navigation'));\Chengyu\Core\SnapshotSchema::check($a->db);
    truth(in_array('anime-gallery',\Chengyu\Core\Icons::NAMES,true));truth(strpos($a->visuals->image('icon:anime-gallery'),'icon-gallery.webp')!==false);
});
