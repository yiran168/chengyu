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
    if(\Chengyu\App::VERSION!=='0.18.0'){throw new RuntimeException('Supply the original 0.18 site directory.');}
    $admin=$a->db->insert('cy_users',['username'=>'upgrade_admin','email'=>'admin@example.test','password_hash'=>password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'display_name'=>'Upgrade admin','role'=>'admin','status'=>'active','bio'=>'','created_at'=>time()]);
    \Chengyu\Seed::run($a,$admin,false,'Upgrade fixture');
    $user=$a->db->insert('cy_users',['username'=>'upgrade_member','email'=>'member@example.test','password_hash'=>password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'display_name'=>'Upgrade member','role'=>'member','status'=>'active','bio'=>'','created_at'=>time()]);
    $actor=$a->db->one('SELECT * FROM cy_users WHERE id=?',[$admin]);
    $item=$a->content->save($actor,['kind'=>'article','title'=>'Preserved paid article','body'=>'Public original','protected_body'=>'PRESERVED-SECRET','access_level'=>'paid','price_currency'=>'balance','price'=>'5','status'=>'published'],true);
    $a->wallet->adjust($user,'balance',2500,'upgrade:initial','Isolated fixture');$a->commerce->buy($user,'content',$item,bin2hex(random_bytes(16)));
    file_put_contents($private.'/ids.json',json_encode(['admin'=>$admin,'user'=>$user,'item'=>$item]));
    echo json_encode(['phase'=>'baseline','version'=>\Chengyu\App::VERSION,'schema'=>\Chengyu\Core\Migrations::version($a->db)]);exit;
}
$ids=json_decode(file_get_contents($private.'/ids.json'),true);$user=$a->db->one('SELECT * FROM cy_users WHERE id=?',[$ids['user']]);$item=$a->content->get((int)$ids['item'],$user);
$checks=['schema_is_11'=>\Chengyu\Core\Migrations::version($a->db)===11,'balance_preserved'=>(int)$user['balance']===2000,'one_original_order'=>(int)$a->db->value('SELECT COUNT(*) FROM cy_orders')===1,'paid_access_preserved'=>$a->content->access($item,$user),'protected_body_preserved'=>$item['protected_body']==='PRESERVED-SECRET','new_tables_created'=>(int)$a->db->value('SELECT COUNT(*) FROM cy_resource_files')===0];
\Chengyu\Core\SnapshotSchema::check($a->db);
echo json_encode(['phase'=>'upgraded','version'=>\Chengyu\App::VERSION,'checks'=>$checks]);
exit(in_array(false,$checks,true)?1:0);
