<?php
declare(strict_types=1);
// Destructive tests use a new temporary SQLite database, NEVER a production configuration.
if (PHP_SAPI !== 'cli') { exit(1); }
define('CY_BOOT', true); error_reporting(E_ALL); date_default_timezone_set('Asia/Shanghai');
require dirname(__DIR__) . '/site/app/autoload.php';
require dirname(__DIR__) . '/site/app/schema.php';
use Chengyu\Core\{Database,Input,Problem,Security,Markdown};
use Chengyu\Services\{Payment,Wallet};
use Chengyu\{App,Schema,Seed};
$mysqlHost = getenv('CY_TEST_MYSQL_HOST') ?: '';
$adapter = $mysqlHost !== '' ? 'native pdo_mysql / isolated temporary database' : 'pdo_sqlite';
if ($mysqlHost === '' && !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    require __DIR__ . '/Support/FfiSqlite.php';
    Database::useTestFactory(static function (array $c) { return new FfiSqlite($c['path']); });
    $adapter = 'test-only FFI / actual libsqlite3 (NOT native PDO)';
}
$tmp = sys_get_temp_dir() . '/chengyu-tests-' . bin2hex(random_bytes(6)); mkdir($tmp,0700,true); mkdir($tmp.'/storage',0700);
$config=['url'=>'https://island.example.test','secret'=>bin2hex(random_bytes(32)),'database'=>['driver'=>'sqlite','path'=>$tmp.'/test.sqlite'],'storage'=>$tmp.'/storage','schema_version'=>1];
if ($mysqlHost !== '') {
    // Never accept an existing database name. Create a random dedicated test database.
    if (!in_array('mysql',PDO::getAvailableDrivers(),true) || !preg_match('/^[a-zA-Z0-9._:-]+$/D',$mysqlHost)) { throw new RuntimeException('Native PDO MySQL and a valid test host are required.'); }
    $mysqlPort=(int)(getenv('CY_TEST_MYSQL_PORT')?:3306);
    $mysqlUser=getenv('CY_TEST_MYSQL_USER')?:'root';$mysqlPassword=getenv('CY_TEST_MYSQL_PASSWORD')?:'';
    $testName='chengyu_test_'.bin2hex(random_bytes(8));
    $owner=new PDO('mysql:host='.$mysqlHost.';port='.$mysqlPort.';charset=utf8mb4',$mysqlUser,$mysqlPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $owner->exec('CREATE DATABASE `'.$testName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static function()use($owner,$testName):void{$owner->exec('DROP DATABASE IF EXISTS `'.$testName.'`');});
    $config['database']=['driver'=>'mysql','host'=>$mysqlHost,'port'=>$mysqlPort,'name'=>$testName,'user'=>$mysqlUser,'password'=>$mysqlPassword];
}
$db = new Database($config['database']); Schema::create($db); $a = new App($config); $GLOBALS['chengyu']=$a;
Security::session(); $_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REMOTE_ADDR']='test-fixture';
$results=[]; $failures=0;
function test(string $label, callable $fn): void { global $results,$failures; try { $fn();$results[]=['test'=>$label,'ok'=>true]; } catch(Throwable $e) {$failures++;$results[]=['test'=>$label,'ok'=>false,'error'=>get_class($e).': '.$e->getMessage()];} }
function same($expected,$actual): void { if ($expected!==$actual) {throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));} }
function truth(bool $actual): void { same(true,$actual); }
function reject(callable $fn, ?int $status=null): void { try {$fn();} catch(Problem $e) {if($status!==null){same($status,$e->status);} return;} throw new RuntimeException('Expected rejection'); }
function key32(): string {return bin2hex(random_bytes(16));}
function account(int $id): array {global $a;return $a->db->one('SELECT * FROM cy_users WHERE id=?',[$id]);}
function funds(int $id,string $asset='balance'): int {return (int)account($id)[$asset];}
function settings(string $group,array $changes): void {global $a;$input=[];foreach($a->settings->schema() as $k=>$f){if($f[0]===$group){$v=$a->settings->get($k);$input[$k]=$f[2]==='bool'?($v?'1':'0'):$v;}}$a->settings->saveGroup($group,array_merge($input,$changes));}
function product(string $type='digital',string $currency='balance',string $price='1.00',int $inventory=-1): int {global $a,$admin;return $a->content->save(account($admin),['kind'=>'product','title'=>'Test '.key32(),'body'=>'Public summary','protected_body'=>'SECRET-PRODUCT','status'=>'published','product_type'=>$type,'price_currency'=>$currency,'price'=>$price,'inventory'=>$inventory,'comment_enabled'=>'1'],true);}
$admin=$a->db->insert('cy_users',['username'=>'admin','email'=>'admin@example.test','password_hash'=>password_hash('TestPassword!2026',PASSWORD_DEFAULT),'display_name'=>'Administrator','role'=>'admin','status'=>'active','bio'=>'','created_at'=>time()]);
Seed::run($a,$admin,true,'Test island');
$u=$a->auth->register(['username'=>'alice','email'=>'alice@example.test','password'=>'TestPassword!2026','display_name'=>'Alice']);
$v=$a->auth->register(['username'=>'bob','email'=>'bob@example.test','password'=>'TestPassword!2026','display_name'=>'Bob','referral'=>'alice']);
$a->wallet->adjust($u,'balance',100000,'fixture:alice','Test fixture');
$a->wallet->adjust($v,'balance',100000,'fixture:bob','Test fixture');
$urow=account($u);
foreach(['0.01'=>1,'1'=>100,'9.9'=>990,'9999999.99'=>999999999] as $input=>$expected){test('integer money '.$input,static function()use($input,$expected){same($expected,Input::cents((string)$input));});}
foreach(['1.001','1e2','-1','NaN','01',' 1','0'] as $input){test('invalid money '.$input,static function()use($input){reject(static function()use($input){Input::cents($input);});});}
test('negative ledger formatting',static function(){same('-0.50',Payment::decimal(-50));});
test('CSRF valid',static function(){Security::verify(Security::csrf());});
test('CSRF forgery rejected',static function(){reject(static function(){Security::verify('forged');},419);});
test('safe Markdown escapes scripts',static function(){truth(strpos(Markdown::render('<script>alert(1)</script>'),'&lt;script&gt;')!==false);});
test('safe Markdown blocks javascript link',static function(){truth(strpos(Markdown::render('[click](javascript:alert(1))'),'href=')===false);});
test('safe Markdown keeps https link',static function(){truth(strpos(Markdown::render('[docs](https://example.test/a)'),'rel="nofollow noopener noreferrer"')!==false);});
test('AES GCM round trip',static function()use($a){same('secret',$a->crypto->open($a->crypto->seal('secret')));});
test('AES GCM rejects corruption',static function()use($a){$x=base64_decode($a->crypto->seal('secret'));$x[30]=chr(ord($x[30])^1);try{$a->crypto->open(base64_encode($x));}catch(RuntimeException $e){return;}throw new RuntimeException('Corruption accepted');});
test('registration points',static function()use($a,$u){same((int)$a->settings->get('register_points'),funds($u,'points'));});
test('registration duplicate rejected',static function()use($a){reject(static function()use($a){$a->auth->register(['username'=>'alice','email'=>'alice@example.test','password'=>'TestPassword!2026']);});});
test('login authenticates and rotates session',static function()use($a,$u){$before=Security::csrf();$a->auth->login('alice','TestPassword!2026');same($u,(int)$a->auth->user()['id']);truth(Security::csrf()!==$before);});
test('ordinary user cannot manage',static function()use($a){same(false,$a->auth->can('manage'));});
test('ordinary user cannot access admin action',static function()use($a){reject(static function()use($a){$a->auth->requirePermission('manage');},403);});
test('external redirect blocked',static function()use($a){same('/',$a->localTarget('//evil.example'));same('/',$a->localTarget('https://evil.example'));});
test('local redirect allowed',static function()use($a){same('/index.php?r=orders',$a->localTarget('/index.php?r=orders'));});
test('wallet retry is idempotent',static function()use($a,$u){$b=funds($u);$a->wallet->adjust($u,'balance',50,'once','same');$a->wallet->adjust($u,'balance',50,'once','same');same($b+50,funds($u));});
test('wallet conflicting key rejected',static function()use($a,$u){reject(static function()use($a,$u){$a->wallet->adjust($u,'balance',51,'once','same');},409);});
test('wallet cannot overdraw',static function()use($a,$u){$b=funds($u);reject(static function()use($a,$u){$a->wallet->adjust($u,'balance',-100000000,'overdraw','fixture');});same($b,funds($u));});
test('outer transaction rollback includes ledger',static function()use($a,$u){$b=funds($u);try{$a->db->transaction(static function()use($a,$u){$a->wallet->adjust($u,'balance',100,'rollback','fixture');throw new RuntimeException('abort');});}catch(RuntimeException $e){}same($b,funds($u));same(0,(int)$a->db->value('SELECT COUNT(*) FROM cy_ledger WHERE idempotency_key=?',['rollback']));});
test('daily check-in credits once',static function()use($a,$u){$b=funds($u,'points');$reward=$a->wallet->checkin($u);same($b+$reward,funds($u,'points'));reject(static function()use($a,$u){$a->wallet->checkin($u);},409);});
test('7-day streak bonus',static function()use($a,$v){$a->db->update('cy_users',$v,['last_checkin'=>date('Y-m-d',strtotime('-1 day')),'checkin_streak'=>6]);same((int)$a->settings->get('checkin_points')+(int)$a->settings->get('streak_bonus'),$a->wallet->checkin($v));});
settings('commerce',['transfers_enabled'=>'1','affiliate_enabled'=>'1','withdrawals_enabled'=>'1','withdraw_min'=>100]);
test('transfer balances and idempotence',static function()use($a,$u,$v){$x=funds($u);$y=funds($v);$key=key32();$a->wallet->transfer($u,'bob','balance',100,$key);$a->wallet->transfer($u,'bob','balance',100,$key);same($x-100,funds($u));same($y+100,funds($v));});
test('self transfer blocked',static function()use($a,$u){reject(static function()use($a,$u){$a->wallet->transfer($u,'alice','balance',1,key32());});});
test('voucher exact once',static function()use($a,$admin,$u){$code=$a->commerce->vouchers($admin,'tokens',80,1,time()+600,'fixture')[0];$b=funds($u,'tokens');$a->commerce->redeem($u,$code);same($b+80,funds($u,'tokens'));reject(static function()use($a,$u,$code){$a->commerce->redeem($u,$code);});});
test('expired voucher rejected',static function()use($a,$admin,$u){$code=$a->commerce->vouchers($admin,'points',1,1,time()+600,'expired')[0];$a->db->execute('UPDATE cy_vouchers SET expires_at=? WHERE label=?',[time()-10,'expired']);reject(static function()use($a,$u,$code){$a->commerce->redeem($u,$code);});});
test('exchange preserves integer assets and idempotence',static function()use($a,$u){$b=funds($u);$p=funds($u,'points');$key=key32();$a->commerce->exchange($u,'points',200,$key);$a->commerce->exchange($u,'points',200,$key);same($b-200,funds($u));same($p+2*(int)$a->settings->get('points_rate'),funds($u,'points'));});
test('exchange rejects fractional yuan',static function()use($a,$u){reject(static function()use($a,$u){$a->commerce->exchange($u,'points',101,key32());});});
$digital=product();
test('digital purchase debits once and grants entitlement',static function()use($a,$u,$digital){$b=funds($u);$o=$a->commerce->buy($u,'content',$digital,key32());$retry=$a->commerce->buy($u,'content',$digital,key32());same((int)$o['id'],(int)$retry['id']);same($b-100,funds($u));truth($a->content->access($a->content->get($digital,account($u)),account($u)));});
test('refund credits back and removes entitlement',static function()use($a,$u,$admin,$digital){$o=$a->db->one('SELECT * FROM cy_orders WHERE user_id=? AND item_id=? AND kind=?',[$u,$digital,'content']);$b=funds($u);$a->commerce->requestRefund($u,(int)$o['id'],'fixture');$a->commerce->refund($admin,(int)$o['id'],true);same($b+100,funds($u));same(false,$a->content->access($a->content->get($digital,account($u)),account($u)));reject(static function()use($a,$admin,$o){$a->commerce->refund($admin,(int)$o['id'],true);});});
test('another user cannot request refund',static function()use($a,$u,$v,$digital){$o=$a->commerce->buy($u,'content',$digital,key32());reject(static function()use($a,$v,$o){$a->commerce->requestRefund($v,(int)$o['id'],'not mine');});});
test('insufficient purchase rolls back order and coupon',static function()use($a,$u){$id=product('digital','balance','999999.99');$cid=$a->db->insert('cy_coupons',['code'=>'TEST','mode'=>'flat','value_amount'=>1,'min_amount'=>0,'max_uses'=>1,'uses'=>0,'expires_at'=>time()+600,'active'=>1]);$count=(int)$a->db->value('SELECT COUNT(*) FROM cy_orders');reject(static function()use($a,$u,$id){$a->commerce->buy($u,'content',$id,key32(),'TEST');});same($count,(int)$a->db->value('SELECT COUNT(*) FROM cy_orders'));same(0,(int)$a->db->value('SELECT uses FROM cy_coupons WHERE id=?',[$cid]));});
test('points purchase uses points not balance',static function()use($a,$u){$id=product('digital','points','10');$b=funds($u);$p=funds($u,'points');$a->commerce->buy($u,'content',$id,key32());same($b,funds($u));same($p-10,funds($u,'points'));});
test('tokens purchase uses tokens',static function()use($a,$u){$id=product('digital','tokens','5');$p=funds($u,'tokens');$a->commerce->buy($u,'content',$id,key32());same($p-5,funds($u,'tokens'));});
test('membership extends duration on renewals',static function()use($a,$u){$before=(int)account($u)['vip_until'];$a->commerce->buy($u,'membership',1,key32());$after=(int)account($u)['vip_until'];truth($after>=max(time(),$before)+30*86400-2);$a->commerce->buy($u,'membership',1,key32());same($after+30*86400,(int)account($u)['vip_until']);});
$card=product('code');
test('unique card inventory imported',static function()use($a,$admin,$card){same(2,$a->commerce->importStock($admin,$card,"CARD-A\nCARD-B"));});
test('duplicate inventory import fully rolls back',static function()use($a,$admin,$card){reject(static function()use($a,$admin,$card){$a->commerce->importStock($admin,$card,"CARD-C\nCARD-A");});same(2,(int)$a->db->value('SELECT COUNT(*) FROM cy_stock_codes WHERE content_id=?',[$card]));});
$card1=$a->commerce->buy($u,'content',$card,key32());$card2=$a->commerce->buy($u,'content',$card,key32());
test('card allocation delivers distinct encrypted codes',static function()use($a,$card1,$card2){truth(strpos($a->commerce->delivery($card1),'CARD-A')!==false);truth(strpos($a->commerce->delivery($card2),'CARD-B')!==false);truth(strpos($card1['delivery_cipher'],'CARD-A')===false);});
test('empty inventory rejects without debit',static function()use($a,$u,$card){$b=funds($u);reject(static function()use($a,$u,$card){$a->commerce->buy($u,'content',$card,key32());});same($b,funds($u));});
test('refund one repeatable order preserves other entitlement',static function()use($a,$admin,$u,$card,$card1,$card2){$a->commerce->requestRefund($u,(int)$card1['id'],'fixture');$a->commerce->refund($admin,(int)$card1['id'],true);same((int)$card2['id'],(int)$a->db->value('SELECT order_id FROM cy_entitlements WHERE user_id=? AND content_id=?',[$u,$card]));same(0,(int)$a->db->value('SELECT COUNT(*) FROM cy_stock_codes WHERE content_id=? AND order_id IS NULL',[$card]));});
$physical=product('physical','balance','1.00',1);
$address=$a->db->insert('cy_addresses',['user_id'=>$u,'recipient'=>'Alice','phone'=>'000','address'=>'Test address','created_at'=>time()]);
test('physical order requires owned shipping address',static function()use($a,$v,$physical,$address){reject(static function()use($a,$v,$physical,$address){$a->commerce->buy($v,'content',$physical,key32(),'',$address);});same(1,(int)$a->db->value('SELECT inventory FROM cy_contents WHERE id=?',[$physical]));});
test('physical stock, encrypted address and manual shipment',static function()use($a,$u,$admin,$physical,$address){$o=$a->commerce->buy($u,'content',$physical,key32(),'',$address);same(0,(int)$a->db->value('SELECT inventory FROM cy_contents WHERE id=?',[$physical]));same('Test address',$a->commerce->address($o)['address']);truth(strpos($o['address_snapshot'],'Test address')===false);$a->commerce->ship($admin,(int)$o['id'],'TEST-TRACKING');same('shipped',$a->db->value('SELECT fulfillment FROM cy_orders WHERE id=?',[(int)$o['id']]));});
test('referral commission and refund reversal',static function()use($a,$admin,$u,$v){$id=product('digital','balance','100.00');$b=funds($u,'commission');$o=$a->commerce->buy($v,'content',$id,key32());truth(funds($u,'commission')>$b);$a->commerce->requestRefund($v,(int)$o['id'],'fixture');$a->commerce->refund($admin,(int)$o['id'],true);same($b,funds($u,'commission'));});
test('withdrawal hold and rejection release exactly once',static function()use($a,$admin,$u){$a->wallet->adjust($u,'commission',500,'commission-fixture','fixture');$b=funds($u,'commission');$key=key32();$a->commerce->withdraw($u,100,'test payout',$key);$a->commerce->withdraw($u,100,'test payout',$key);same($b-100,funds($u,'commission'));$id=(int)$a->db->value('SELECT id FROM cy_withdrawals WHERE request_key=?',[$u.':'.$key]);$a->commerce->reviewWithdrawal($admin,$id,false,'fixture rejected');same($b,funds($u,'commission'));reject(static function()use($a,$admin,$id){$a->commerce->reviewWithdrawal($admin,$id,false,'again');});});
test('guest cannot access VIP body',static function()use($a){same(false,$a->content->access($a->content->get(4),null));});
test('logged in required content gate',static function()use($a,$u){same(false,$a->content->access($a->content->get(6),null));truth($a->content->access($a->content->get(6,account($u)),account($u)));});
test('reply gate opens only after approved reply',static function()use($a,$v,$admin){same(false,$a->content->access($a->content->get(5),account($v)));$a->content->comment(account($v),5,'test reply');same(false,$a->content->access($a->content->get(5),account($v)));$id=(int)$a->db->value('SELECT MAX(id) FROM cy_comments');$a->admin->moderation($admin,'comments',$id,'approved');truth($a->content->access($a->content->get(5),account($v)));});
test('public search cannot match protected body',static function()use($a){same(0,$a->content->feed('all',null,['q'=>'SECRET-PRODUCT'])['total']);});
test('search SQL metacharacters are literal',static function()use($a){same(0,$a->content->feed('all',null,['q'=>"%' OR 1=1 --"])['total']);});
test('future publication hidden without scheduler',static function()use($a,$admin){$id=$a->content->save(account($admin),['kind'=>'article','title'=>'Future unique','body'=>'public','status'=>'published','publish_at'=>date('Y-m-d H:i',time()+86400)],true);reject(static function()use($a,$id){$a->content->get($id);},404);same(0,$a->content->feed('all',null,['q'=>'Future unique'])['total']);});
test('ordinary submission cannot publish or set price',static function()use($a,$v){$id=$a->content->save(account($v),['kind'=>'article','title'=>'Member draft','body'=>'text','status'=>'published','price'=>'100'],false);$row=$a->db->one('SELECT * FROM cy_contents WHERE id=?',[$id]);same('pending',$row['status']);same(0,(int)$row['price_amount']);});
test('like and bookmark toggle safely',static function()use($a,$u){truth($a->content->reaction(account($u),1,'like'));same(false,$a->content->reaction(account($u),1,'like'));truth($a->content->reaction(account($u),1,'favorite'));same(1,$a->content->feed('all',account($u),['favorites'=>true])['total']);});
test('private message stored with recipient binding',static function()use($a,$u,$v){$a->content->message($u,'bob','hello');same($v,(int)$a->db->value('SELECT recipient_id FROM cy_messages ORDER BY id DESC LIMIT 1'));});
test('self administrator demotion blocked',static function()use($a,$admin){reject(static function()use($a,$admin){$a->admin->user($admin,$admin,['role'=>'user','status'=>'active']);});});
test('CSV neutralizes formula injection and excludes hashes',static function()use($a,$v){$a->db->update('cy_users',$v,['display_name'=>'=1+1']);ob_start();$a->admin->export('users');$csv=ob_get_clean();truth(strpos($csv,"'=1+1")!==false);truth(strpos($csv,'password_hash')===false);});
settings('epay',['epay_enabled'=>'1','epay_endpoint'=>'https://gateway.example.test/submit.php','epay_merchant'=>'test-merchant','epay_secret'=>'test-only-old-key','epay_alipay'=>'1']);
$payment=$a->payment->create($u,1234,'epay','alipay',key32());
function callback(array $order,array $extra=[],string $key='test-only-old-key'): array {$fields=array_merge(['pid'=>'test-merchant','type'=>'alipay','out_trade_no'=>$order['order_no'],'trade_no'=>'test-'.$order['id'],'money'=>Payment::decimal((int)$order['amount']),'trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'],$extra);$fields['sign']=Payment::sign($fields,$key);return $fields;}
test('checkout signed fields and matching callback URL',static function()use($a,$payment){parse_str(parse_url($a->payment->checkout($payment),PHP_URL_QUERY),$q);same('12.34',$q['money']);same('https://island.example.test/notify.php?gateway=epay',$q['notify_url']);same(Payment::sign($q,'test-only-old-key'),$q['sign']);});
test('forged payment rejected without credit',static function()use($a,$u,$payment){$b=funds($u);$f=callback($payment);$f['sign']=str_repeat('0',32);reject(static function()use($a,$f){$a->payment->settle('epay',$f);},403);same($b,funds($u));});
foreach(['money'=>'12.33','pid'=>'wrong','type'=>'wxpay','trade_status'=>'WAIT_BUYER_PAY'] as $k=>$value){test('signed payment mismatch '.$k,static function()use($a,$payment,$k,$value){reject(static function()use($a,$payment,$k,$value){$a->payment->settle('epay',callback($payment,[$k=>$value]));});});}
test('gateway secrets encrypted in database',static function()use($a,$payment){truth(strpos($payment['metadata'],'test-only-old-key')===false);truth(strpos((string)$a->db->value('SELECT value FROM cy_settings WHERE name=?',['epay_secret']),'test-only-old-key')===false);});
settings('epay',['epay_secret'=>'test-only-new-key']);
test('old order verifies snapshot after key rotation; duplicate credits once',static function()use($a,$u,$payment){$b=funds($u);$a->payment->settle('epay',callback($payment));$a->payment->settle('epay',callback($payment));same($b+1234,funds($u));});
test('same order conflicting receipt rejected',static function()use($a,$payment){reject(static function()use($a,$payment){$a->payment->settle('epay',callback($payment,['trade_no'=>'another']));},409);});
test('receipt reuse across orders rejected',static function()use($a,$u,$payment){$o=$a->payment->create($u,1234,'epay','alipay',key32());reject(static function()use($a,$o,$payment){$a->payment->settle('epay',callback($o,['trade_no'=>'test-'.$payment['id']],'test-only-new-key'));},409);});
test('valid late payment credits expired top-up',static function()use($a,$u){$o=$a->payment->create($u,100,'epay','alipay',key32());$a->db->update('cy_orders',(int)$o['id'],['status'=>'expired']);$b=funds($u);$a->payment->settle('epay',callback($o,[],'test-only-new-key'));same($b+100,funds($u));});
test('HTTP canonical URL blocks payment creation',static function()use($a,$u,$config){$c=$config;$c['url']='http://island.example.test';$other=new App($c);reject(static function()use($other,$u){$other->payment->create($u,100,'epay','alipay',key32());});});
settings('codepay',['codepay_enabled'=>'1','codepay_endpoint'=>'https://code.example.test/creat_order/','codepay_merchant'=>'code-merchant','codepay_secret'=>'test-code-key','codepay_wxpay'=>'1']);
test('legacy CodePay signed exact amount protocol fixture',static function()use($a,$u){$o=$a->payment->create($u,111,'codepay','wxpay',key32());parse_str(parse_url($a->payment->checkout($o),PHP_URL_QUERY),$q);same('1.11',$q['price']);$f=['pay_id'=>$o['order_no'],'pay_no'=>'legacy-fixture','type'=>'3','param'=>$q['param'],'money'=>'1.11','price'=>'1.11'];$f['sign']=Payment::sign($f,'test-code-key');$b=funds($u);$a->payment->settle('codepay',$f);$a->payment->settle('codepay',$f);same($b+111,funds($u));});
test('legacy CodePay floating amount mode deliberately rejected',static function()use($a,$u){$o=$a->payment->create($u,200,'codepay','wxpay',key32());parse_str(parse_url($a->payment->checkout($o),PHP_URL_QUERY),$q);$f=['pay_id'=>$o['order_no'],'pay_no'=>'legacy-floating','type'=>'3','param'=>$q['param'],'money'=>'1.99','price'=>'2.00'];$f['sign']=Payment::sign($f,'test-code-key');reject(static function()use($a,$f){$a->payment->settle('codepay',$f);});});
test('cleanup does not delete financial ledger',static function()use($a){$count=(int)$a->db->value('SELECT COUNT(*) FROM cy_ledger');$a->admin->cleanup();same($count,(int)$a->db->value('SELECT COUNT(*) FROM cy_ledger'));});
require __DIR__ . '/extensions.php';
require __DIR__ . '/motion_community.php';
require __DIR__ . '/reliability_012.php';
require __DIR__ . '/community_commerce_012.php';
require __DIR__ . '/reliability_013.php';
require __DIR__.'/creator_discovery_013.php';
require __DIR__.'/reliability_014.php';
require __DIR__.'/security_pricing_014.php';
require __DIR__.'/reliability_015.php';
require __DIR__.'/integrations_015.php';
require __DIR__.'/commerce_016.php';
require __DIR__.'/platform_016.php';
require __DIR__.'/platform_017.php';
require __DIR__.'/platform_018.php';
require __DIR__.'/backups_018.php';
require __DIR__.'/reliability_019.php';
require __DIR__.'/platform_019.php';
require __DIR__.'/platform_020.php';
require __DIR__.'/platform_021.php';
require __DIR__.'/platform_022.php';
require __DIR__.'/platform_023.php';
require __DIR__.'/audit_20260915.php';
require __DIR__.'/file_types_0232.php';
require __DIR__.'/file_types_0233.php';
test('ledger reconciles all account balances',static function()use($a){foreach($a->db->all('SELECT * FROM cy_users') as $row){foreach(Wallet::CURRENCIES as $currency){same((int)$row[$currency],(int)$a->db->value('SELECT COALESCE(SUM(delta),0) FROM cy_ledger WHERE user_id=? AND currency=?',[(int)$row['id'],$currency]));}}});
// True multi-process race against the same database (not a mocked transaction).
$race=$a->db->insert('cy_users',['username'=>'race','email'=>'race@example.test','password_hash'=>'not-a-login','display_name'=>'Race','role'=>'user','status'=>'active','bio'=>'','created_at'=>time()]);$a->wallet->adjust($race,'balance',500,'race-funding','fixture');
file_put_contents($tmp.'/config.json',json_encode($config));
test('eight concurrent debits: five settle, no overdraft',static function()use($tmp,$race){$processes=[];for($i=0;$i<8;$i++){$p=proc_open([PHP_BINARY,'-d','ffi.enable=1',__DIR__.'/concurrency_worker.php',$tmp.'/config.json',(string)$race,(string)$i],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$processes[]=[$p,$pipes];}$codes=[];foreach($processes as [$process,$pipes]){$stdout=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($code!==0){throw new RuntimeException($error);}$codes[]=trim($stdout);}same(5,count(array_filter($codes,static function($x){return $x==='paid';})));same(3,count(array_filter($codes,static function($x){return $x==='declined';})));same(0,funds($race));});
test('eight concurrent votes from one account leave exactly one vote',static function()use($a,$tmp,$race){
    $poll=threadFixture();$choices=$a->threads->options($poll);$processes=[];
    for($i=0;$i<8;$i++){$p=proc_open([PHP_BINARY,'-d','ffi.enable=1',__DIR__.'/poll_worker.php',$tmp.'/config.json',(string)$race,(string)$poll,(string)$choices[$i%2]['id']],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$processes[]=[$p,$pipes];}
    $codes=[];foreach($processes as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);if($status!==0){throw new RuntimeException($error);}$codes[]=trim($out);}
    same(4,count(array_filter($codes,static function($x){return $x==='recorded';})));same(4,count(array_filter($codes,static function($x){return $x==='conflict';})));
    same(1,(int)$a->db->value('SELECT COUNT(*) FROM cy_poll_votes WHERE content_id=? AND user_id=?',[$poll,$race]));
    same(1,(int)$a->db->value("SELECT COUNT(*) FROM cy_audit WHERE action='poll.voted' AND target=?",[(string)$poll]));
});
require __DIR__.'/concurrency_012.php';
require __DIR__.'/concurrency_013.php';
require __DIR__.'/concurrency_014.php';
require __DIR__.'/concurrency_015.php';
$report=['php'=>PHP_VERSION,'fileinfo_loaded'=>extension_loaded('fileinfo'),'finfo_available'=>class_exists('finfo'),'adapter'=>$adapter,'time'=>date(DATE_ATOM),'total'=>count($results),'passed'=>count($results)-$failures,'failed'=>$failures,'tests'=>$results,'not_tested'=>['MySQL/InnoDB and native PDO when unavailable','real merchant network or callback delivery','SMTP network','real shared hosting','certificate issuance','full Zibll feature parity']];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
if(PHP_OS_FAMILY!=='Windows'){
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($tmp);
}
// Windows native PDO handles close at process exit. The matrix runner removes its isolated root then.
exit($failures?1:0);
