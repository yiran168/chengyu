<?php
declare(strict_types=1);
// Called only by the disposable Python upgrade runner; never a website entry point.
if(PHP_SAPI!=='cli'){exit(1);}define('CY_BOOT',true);
$site=$argv[1];$phase=$argv[2];$private=$argv[3];
require $site.'/app/autoload.php';require $site.'/app/schema.php';
$config=json_decode(file_get_contents($private.'/config.json'),true);
if($phase==='seed'){\Chengyu\Schema::create(new \Chengyu\Core\Database($config['database']));}
$a=new \Chengyu\App($config);$GLOBALS['chengyu']=$a;
if($phase==='seed'){
    if(!in_array(\Chengyu\App::VERSION,['0.18.0','0.19.0','0.20.0','0.21.0','0.22.0','0.23.0','0.23.1','0.23.2','0.23.3','0.23.4'],true)){throw new RuntimeException('Supply an original 0.18–0.23.4 site directory.');}
    $admin=$a->db->insert('cy_users',['username'=>'upgrade_admin','email'=>'admin@example.test','password_hash'=>password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'display_name'=>'Upgrade admin','role'=>'admin','status'=>'active','bio'=>'','created_at'=>time()]);
    \Chengyu\Seed::run($a,$admin,false,'Upgrade fixture');
    $user=$a->db->insert('cy_users',['username'=>'upgrade_member','email'=>'member@example.test','password_hash'=>password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'display_name'=>'Upgrade member','role'=>'member','status'=>'active','bio'=>'','created_at'=>time()]);
    $actor=$a->db->one('SELECT * FROM cy_users WHERE id=?',[$admin]);
    $item=$a->content->save($actor,['kind'=>'article','title'=>'Preserved paid article','body'=>'Public original','protected_body'=>'PRESERVED-SECRET','access_level'=>'paid','price_currency'=>'balance','price'=>'5','status'=>'published'],true);
    $a->wallet->adjust($user,'balance',2500,'upgrade:initial','Isolated fixture');$a->commerce->buy($user,'content',$item,bin2hex(random_bytes(16)));
    $resource=0;if(version_compare(\Chengyu\App::VERSION,'0.19.0','>=')){$resource=$a->resources->save($actor,['content_id'=>$item,'label'=>'Preserved edition','mirror_url'=>'https://downloads.example.test/keep','active'=>1]);}
    if(\Chengyu\Core\Migrations::version($a->db)===15){file_put_contents($private.'/navigation.json',json_encode($a->db->all('SELECT * FROM cy_navigation ORDER BY id')));}
    file_put_contents($private.'/ids.json',json_encode(['admin'=>$admin,'user'=>$user,'item'=>$item,'resource'=>$resource,'navigation'=>$a->db->all('SELECT id,label,route FROM cy_navigation ORDER BY id')]));
    echo json_encode(['phase'=>'baseline','version'=>\Chengyu\App::VERSION,'schema'=>\Chengyu\Core\Migrations::version($a->db)]);exit;
}
$ids=json_decode(file_get_contents($private.'/ids.json'),true);$user=$a->db->one('SELECT * FROM cy_users WHERE id=?',[$ids['user']]);$item=$a->content->get((int)$ids['item'],$user);
$checks=['schema_current'=>\Chengyu\Core\Migrations::version($a->db)===\Chengyu\Core\Migrations::VERSION,'balance_preserved'=>(int)$user['balance']===2000,'one_original_order'=>(int)$a->db->value('SELECT COUNT(*) FROM cy_orders')===1,'paid_access_preserved'=>$a->content->access($item,$user),'protected_body_preserved'=>$item['protected_body']==='PRESERVED-SECRET','new_visual_table_created'=>(int)$a->db->value('SELECT COUNT(*) FROM cy_visual_assets')===0,'existing_covers_not_overwritten'=>$item['cover_art']===''];
if(!empty($ids['resource'])){$checks['encrypted_resource_preserved']=$a->resources->destination((int)$ids['resource'],$user,false)['url']==='https://downloads.example.test/keep';}
$checks['new_bulletins_empty']=(int)$a->db->value('SELECT COUNT(*) FROM cy_bulletins')===0;
$checks['old_attribution_preserved_as_unset']=$item['source_name']==='' && $item['source_url']==='' && $item['copyright_mode']==='default';
$checks['navigation_preserved']=$a->db->all('SELECT id,label,route FROM cy_navigation ORDER BY id')===$ids['navigation'];
$checks['old_navigation_keeps_top_level']=(int)$a->db->value('SELECT COUNT(*) FROM cy_navigation WHERE parent_id<>0 OR revision<>1')===0;
$checks['old_article_has_no_invented_gallery']=$item['image_layout']==='default' && $item['gallery_ids']==='[]' && $item['body_image_ids']==='[]';
if(is_file($private.'/navigation.json')){
    $original=json_decode(file_get_contents($private.'/navigation.json'),true);$current=$a->db->all('SELECT * FROM cy_navigation ORDER BY id');
    $checks['new_menu_fields_default_to_disabled']=count(array_filter($current,static function(array $row):bool{return (int)$row['category_id']!==0 || (int)$row['preview_count']!==0 || $row['preview_sort']!=='latest';}))===0;
    $checks['all_original_menu_fields_preserved']=array_map(static function(array $row):array{unset($row['category_id'],$row['preview_count'],$row['preview_sort']);return $row;},$current)===$original;
    \Chengyu\Core\Migration024::up($a->db);\Chengyu\Core\Migrations::upgrade($a->db,$config['storage']);
    $checks['migration_retry_preserves_all_menu_data']=$a->db->all('SELECT * FROM cy_navigation ORDER BY id')===$current;
}
\Chengyu\Core\SnapshotSchema::check($a->db);
echo json_encode(['phase'=>'upgraded','version'=>\Chengyu\App::VERSION,'checks'=>$checks]);
exit(in_array(false,$checks,true)?1:0);
