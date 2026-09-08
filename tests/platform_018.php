<?php
// Loaded by run.php, using only its disposable database and protocol fixtures.
use Chengyu\Services\RemoteSearch;
use Chengyu\Core\HttpClient;
function raises18(callable $fn,string $class): void {$caught=false;try{$fn();}catch(Throwable $e){$caught=$e instanceof $class;}truth($caught);}
function remote18(callable $transport): RemoteSearch {global $a;return new RemoteSearch($a->db,$a->settings,$a->activity,new HttpClient($transport));}
function remoteConfig18(): void {settings('discovery',['search_engine'=>'portable','meili_endpoint'=>'https://search.example.test','meili_index'=>'cy_tests','meili_key'=>'fixture-only-key','meili_consent'=>1]);}
function queue18(int $content): array {global $a;RemoteSearch::enqueue($a->db,$content);return $a->db->one('SELECT * FROM cy_search_outbox WHERE content_id=?',[$content]);}
function ready18(int $id): void {global $a;$a->db->update('cy_search_outbox',$id,['next_attempt_at'=>0,'lease_until'=>0]);}

test('018 JSON transport rejects duplicate case-insensitive headers before network I/O',static function(){
    $called=false;$h=new HttpClient(static function()use(&$called){$called=true;return response15([]);});
    raises18(static function()use($h){$h->json('GET','https://example.com',[],['accept'=>'application/json']);},InvalidArgumentException::class);same(false,$called);
});
test('018 form requests share the one-MiB bound',static function(){
    $called=false;$h=new HttpClient(static function()use(&$called){$called=true;return response15([]);});
    reject(static function()use($h){$h->json('POST','https://example.com',['large'=>str_repeat('x',1048577)]);});same(false,$called);
});
test('018 ambiguous HEAD framing is rejected',static function(){
    $h=new HttpClient(static function(){return "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nTransfer-Encoding: chunked\r\n\r\n";});
    reject(static function()use($h){$h->request('HEAD','https://example.com');},502);
});
test('018 JSON refactor retains form encoding and response decoding',static function(){
    $h=new HttpClient(static function($method,$url,$headers,$body){same('POST',$method);same('a=x%20y',$body);same('application/x-www-form-urlencoded',$headers['Content-Type']);return response15(['ok'=>1]);});same(['ok'=>1],$h->json('POST','https://example.com',['a'=>'x y']));
});
test('018 multi-dimensional denial rolls back every quota increment',static function()use($a){
    $a->db->execute('DELETE FROM cy_rate_limits');$a->activity->rateMany([['quota-one','u',1,600],['quota-two','ip',2,3600]]);
    $before=$a->db->all('SELECT * FROM cy_rate_limits ORDER BY bucket');reject(static function()use($a){$a->activity->rateMany([['quota-one','u',1,600],['quota-two','ip',2,3600]]);},429);same($before,$a->db->all('SELECT * FROM cy_rate_limits ORDER BY bucket'));
});
test('018 duplicate quota definitions are rejected without writes',static function()use($a){
    $before=(int)$a->db->value('SELECT COUNT(*) FROM cy_rate_limits');raises18(static function()use($a){$a->activity->rateMany([['same','u',3,60],['same','u',3,60]]);},InvalidArgumentException::class);same($before,(int)$a->db->value('SELECT COUNT(*) FROM cy_rate_limits'));
});
test('018 delivery quotas share normalized recipient identity',static function()use($a){
    $a->db->execute('DELETE FROM cy_rate_limits');$a->verification->rateDelivery('QuotaCase@Example.test');reject(static function()use($a){$a->verification->rateDelivery('quotacase@example.test');},429);
    same(7,(int)$a->db->value('SELECT COUNT(*) FROM cy_rate_limits'));same(7,(int)$a->db->value('SELECT SUM(hits) FROM cy_rate_limits'));
});
test('018 all three IP windows are configurable',static function()use($a){
    $a->db->execute('DELETE FROM cy_rate_limits');settings('security',['verification_ip_10m'=>10,'verification_ip_hour'=>1,'verification_ip_day'=>10]);
    $a->verification->rateDelivery('first-quota@example.test');reject(static function()use($a){$a->verification->rateDelivery('second-quota@example.test');},429);
    settings('security',['verification_ip_10m'=>3,'verification_ip_hour'=>30,'verification_ip_day'=>150]);$a->db->execute('DELETE FROM cy_rate_limits');
});
test('018 one order supports isolated named parcels',static function()use($a,$admin){
    [$u,$sale,$order,$first]=shipment17('yunda','YD018MAIN');$key=str_repeat('a',32);
    $second=$a->tracking->attach($admin,['order_id'=>$order['id'],'revision'=>0,'package_key'=>$key,'package_label'=>'Accessories','carrier'=>'shunfeng','tracking_number'=>'SF018EXTRA']);
    same(2,count($a->tracking->parcels((int)$order['id'],account($u))));$a->tracking->addEvent($admin,$second,1,time(),'SECOND-ONLY');
    same(0,count($a->tracking->view((int)$order['id'],account($u),$first)['events']));same('SECOND-ONLY',$a->tracking->view((int)$order['id'],account($u),$second)['events'][0]['text']);
});
test('018 explicit parcel creation key is idempotent',static function()use($a,$admin){
    [$u,$sale,$order,$first]=shipment17('yunda','YD018IDEMP');$in=['order_id'=>$order['id'],'revision'=>0,'package_key'=>str_repeat('b',32),'package_label'=>'Second parcel','carrier'=>'shunfeng','tracking_number'=>'SF018IDEMP'];
    $id=$a->tracking->attach($admin,$in);$count=(int)$a->db->value('SELECT COUNT(*) FROM cy_notifications WHERE user_id=?',[$u]);same($id,$a->tracking->attach($admin,$in));same($count,(int)$a->db->value('SELECT COUNT(*) FROM cy_notifications WHERE user_id=?',[$u]));
});
test('018 repeated waybill under another key is rejected',static function()use($a,$admin){
    [$u,$sale,$order]=shipment17('yunda','YD018DUP');reject(static function()use($a,$admin,$order){$a->tracking->attach($admin,['order_id'=>$order['id'],'revision'=>0,'package_key'=>str_repeat('c',32),'carrier'=>'yunda','tracking_number'=>'YD018DUP']);},409);
});
test('018 parcel selection cannot cross order ownership',static function()use($a){
    [$u,$sale,$order,$first]=shipment17('yunda','YD018FIRST');[$other,$sale2,$order2,$second]=shipment17('yunda','YD018SECOND');
    reject(static function()use($a,$u,$order,$second){$a->tracking->view((int)$order['id'],account($u),$second);},404);reject(static function()use($a,$other,$order){$a->tracking->parcels((int)$order['id'],account($other));},404);
});
test('018 shipment migration is restartable and preserves parcel IDs and events',static function()use($a){
    $before=$a->db->all('SELECT * FROM cy_shipments ORDER BY id');$events=$a->db->all('SELECT * FROM cy_tracking_events ORDER BY id');\Chengyu\Core\Migration018::up($a->db);\Chengyu\Core\Migration018::up($a->db);same($before,$a->db->all('SELECT * FROM cy_shipments ORDER BY id'));same($events,$a->db->all('SELECT * FROM cy_tracking_events ORDER BY id'));
});
test('018 a literal zero query is not mistaken for an empty search',static function()use($a){
    settings('discovery',['search_engine'=>'legacy']);$yes=product();$no=product();$a->db->update('cy_contents',$yes,['title'=>'Literal 0 token','excerpt'=>'','tags'=>'']);$a->db->update('cy_contents',$no,['title'=>'No digit in this title','excerpt'=>'','tags'=>'']);
    $ids=array_column($a->content->feed('product',null,['q'=>'0','size'=>30])['items'],'id');truth(in_array($yes,array_map('intval',$ids),true));truth(!in_array($no,array_map('intval',$ids),true));settings('discovery',['search_engine'=>'portable']);
});
remoteConfig18();
test('018 search document excludes bodies, credentials and private categories',static function()use($a){
    $id=product();$a->db->update('cy_contents',$id,['body'=>'PRIVATE-BODY-018','protected_body'=>'PAYWALL-018','delivery_text'=>'SECRET-CODE-018']);$doc=$a->remoteSearch->document($id);truth($doc!==null);$text=json_encode($doc);truth(strpos($text,'PRIVATE-BODY-018')===false && strpos($text,'PAYWALL-018')===false && strpos($text,'SECRET-CODE-018')===false);
    $category=$a->db->insert('cy_categories',['name'=>'Private search','kind'=>'product','description'=>'','access_level'=>'login']);$a->db->update('cy_contents',$id,['category_id'=>$category]);same(null,$a->remoteSearch->document($id));
});
test('018 search export hides future and archived entries',static function()use($a){
    $id=product();$a->db->update('cy_contents',$id,['publish_at'=>time()+3600]);same(null,$a->remoteSearch->document($id));$a->db->update('cy_contents',$id,['publish_at'=>0,'status'=>'archived']);same(null,$a->remoteSearch->document($id));
});
test('018 accepted indexing task remains queued until a matching success',static function()use($a){
    $id=product();$queue=queue18($id);$calls=0;
    $remote=remote18(static function($method,$url,$headers,$body)use(&$calls,$id){$calls++;same('Bearer fixture-only-key',$headers['Authorization']);if($calls===1){same('POST',$method);truth(strpos($url,'/documents?primaryKey=id')!==false);same($id,json_decode($body,true)[0]['id']);return response15(['taskUid'=>1801,'indexUid'=>'cy_tests','status'=>'enqueued']);}same('GET',$method);return response15(['uid'=>1801,'indexUid'=>'cy_tests','status'=>'succeeded']);});
    same('pending',$remote->run((int)$queue['id'])['status']);truth((bool)$a->db->one('SELECT id FROM cy_search_outbox WHERE id=?',[$queue['id']]));ready18((int)$queue['id']);same('succeeded',$remote->run((int)$queue['id'])['status']);same(null,$a->db->one('SELECT id FROM cy_search_outbox WHERE id=?',[$queue['id']]));
});
test('018 stale remote acknowledgements cannot drop a newer content update',static function()use($a){
    $id=product();$queue=queue18($id);$remote=remote18(static function($method){return response15($method==='POST'?['taskUid'=>1802,'indexUid'=>'cy_tests']:['uid'=>1802,'indexUid'=>'cy_tests','status'=>'succeeded']);});
    $remote->run((int)$queue['id']);RemoteSearch::enqueue($a->db,$id);ready18((int)$queue['id']);same('queued',$remote->run((int)$queue['id'])['status']);same(-1,(int)$a->db->value('SELECT task_uid FROM cy_search_outbox WHERE id=?',[$queue['id']]));
});
test('018 remote processing and failure never claim completion',static function()use($a){
    $id=product();$queue=queue18($id);$state='processing';$remote=remote18(static function($method)use(&$state){return response15($method==='POST'?['taskUid'=>1803]:['uid'=>1803,'indexUid'=>'cy_tests','status'=>$state]);});
    $remote->run((int)$queue['id']);ready18((int)$queue['id']);same('pending',$remote->run((int)$queue['id'])['status']);$state='failed';ready18((int)$queue['id']);same('retry',$remote->run((int)$queue['id'])['status']);truth((int)$a->db->value('SELECT next_attempt_at FROM cy_search_outbox WHERE id=?',[$queue['id']])>time());
});
test('018 leased search work cannot be submitted twice',static function()use($a){
    $id=product();$queue=queue18($id);$a->db->update('cy_search_outbox',(int)$queue['id'],['lease_until'=>time()+90]);$called=false;$remote=remote18(static function()use(&$called){$called=true;return response15([]);});same('waiting',$remote->run((int)$queue['id'])['status']);same(false,$called);
});
test('018 transport uncertainty retains retryable search work',static function()use($a){
    $id=product();$queue=queue18($id);$remote=remote18(static function(){throw new RuntimeException('fixture timeout');});same('retry',$remote->run((int)$queue['id'])['status']);same(-1,(int)$a->db->value('SELECT task_uid FROM cy_search_outbox WHERE id=?',[$queue['id']]));
});
test('018 deleting a private entry uses the document deletion API',static function()use($a){
    $id=product();$a->db->update('cy_contents',$id,['status'=>'draft']);$queue=queue18($id);$remote=remote18(static function($method,$url,$headers,$body)use($id){truth(strpos($url,'/documents/delete-batch')!==false);same([$id],json_decode($body,true));return response15(['taskUid'=>1804]);});same('pending',$remote->run((int)$queue['id'])['status']);
});
test('018 task polling checks both task and index identity',static function()use($a){
    $id=product();$queue=queue18($id);$a->db->update('cy_search_outbox',(int)$queue['id'],['task_uid'=>1805,'task_generation'=>$queue['generation'],'target_hash'=>hash('sha256','v1:https://search.example.test:cy_tests')]);$remote=remote18(static function(){return response15(['uid'=>1805,'indexUid'=>'another_index','status'=>'succeeded']);});same('retry',$remote->run((int)$queue['id'])['status']);truth((bool)$a->db->one('SELECT id FROM cy_search_outbox WHERE id=?',[$queue['id']]));
});
test('018 remote search validates identity types and ignores supplied HTML',static function(){
    $r=remote18(static function(){return response15(['hits'=>[['id'=>'1 OR 1=1','version'=>1,'title'=>'<script>bad</script>']]]);});reject(static function()use($r){$r->query('fixture','en-US');},502);
});
test('018 remote search does not trust stale content revisions',static function()use($a){
    $id=product();$a->db->update('cy_contents',$id,['title'=>'Unique clean publication','excerpt'=>'','tags'=>'']);$r=remote18(static function()use($id){return response15(['hits'=>[['id'=>$id,'version'=>9999,'title'=>'Do not render this']]]);});$a->content->remoteSearch($r);settings('discovery',['search_engine'=>'meilisearch']);
    $feed=$a->content->feed('product',null,['q'=>'UNMATCHED-TERM-018','size'=>30]);truth(!in_array($id,array_map('intval',array_column($feed['items'],'id')),true));same('meilisearch+local',$feed['search_backend']);$a->content->remoteSearch($a->remoteSearch);settings('discovery',['search_engine'=>'portable']);
});
test('018 failed remote search falls back to local matching',static function()use($a){
    $id=product();$a->db->update('cy_contents',$id,['title'=>'FallbackUniqueEighteen','excerpt'=>'','tags'=>'']);$a->search->sync($id);$r=remote18(static function(){throw new RuntimeException('offline fixture');});$a->content->remoteSearch($r);settings('discovery',['search_engine'=>'meilisearch']);$feed=$a->content->feed('product',null,['q'=>'FallbackUniqueEighteen']);same('local-fallback',$feed['search_backend']);truth(in_array($id,array_map('intval',array_column($feed['items'],'id')),true));$a->content->remoteSearch($a->remoteSearch);settings('discovery',['search_engine'=>'portable']);
});
test('018 search configuration rejects private endpoints and index injection',static function(){
    reject(static function(){settings('discovery',['meili_endpoint'=>'https://127.0.0.1']);});reject(static function(){settings('discovery',['meili_index'=>'cy_a/../../other']);});
});
settings('discovery',['search_engine'=>'portable','meili_consent'=>0,'meili_jobs'=>0]);$a->db->execute('DELETE FROM cy_rate_limits');

test('018 recipient cooldown is elapsed-time based and resets atomically',static function()use($a){$a->db->execute('DELETE FROM cy_rate_limits');$a->activity->rateMany([['cooldown018','receiver',1,60,true]]);$row=$a->db->one('SELECT * FROM cy_rate_limits');truth((int)$row['expires_at']>=time()+58);reject(static function()use($a){$a->activity->rateMany([['cooldown018','receiver',1,60,true]]);},429);$a->db->execute('UPDATE cy_rate_limits SET expires_at=? WHERE bucket=?',[time()-1,$row['bucket']]);$a->activity->rateMany([['cooldown018','receiver',1,60,true]]);same(1,(int)$a->db->value('SELECT hits FROM cy_rate_limits WHERE bucket=?',[$row['bucket']]));$a->db->execute('DELETE FROM cy_rate_limits');});
