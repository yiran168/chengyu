<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,HttpClient,Input,Locale,Problem,Settings};

/** Optional Meilisearch adapter. The database remains the permission and display authority.
 * An accepted remote task is NOT a completed synchronization. Network I/O never runs in a transaction.
 */
final class RemoteSearch
{
    public const CANDIDATES=200;
    private Database $db;private Settings $settings;private Activity $activity;private HttpClient $http;
    public function __construct(Database $db,Settings $settings,Activity $activity,?HttpClient $http=null)
    {$this->db=$db;$this->settings=$settings;$this->activity=$activity;$this->http=$http??new HttpClient();}
    public function configured(): bool
    {return (bool)$this->settings->get('meili_consent') && $this->settings->get('meili_endpoint')!=='' && $this->settings->get('meili_key')!=='';}
    private function target(): array
    {
        if(!$this->configured()){throw new Problem('Configure the search endpoint, API key and public-data consent first.',503);}
        $url=rtrim((string)$this->settings->get('meili_endpoint'),'/');HttpClient::endpoint($url);
        $index=(string)$this->settings->get('meili_index');
        if(!preg_match('/^cy_[a-z0-9_-]{1,60}$/D',$index) || parse_url($url,PHP_URL_QUERY)!==null){throw new Problem('Invalid dedicated search index.');}
        return ['url'=>$url,'index'=>$index,'hash'=>hash('sha256','v1:'.$url.':'.$index),'headers'=>['Authorization'=>'Bearer '.$this->settings->get('meili_key')]];
    }
    public static function enqueue(Database $db,int $id): void
    {
        Input::integer($id,1);$now=time();
        $suffix=$db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE generation=generation+1,next_attempt_at=0,updated_at=VALUES(updated_at)':' ON CONFLICT(content_id) DO UPDATE SET generation=generation+1,next_attempt_at=0,updated_at=excluded.updated_at';
        $db->execute('INSERT INTO cy_search_outbox (content_id,created_at,updated_at) VALUES (?,?,?)'.$suffix,[$id,$now,$now]);
    }
    /** Configuration/category changes may alter visibility without changing content revisions. */
    public static function enqueueAll(Database $db): void
    {
        $suffix=$db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE generation=generation+1,next_attempt_at=0,updated_at=VALUES(updated_at)':' ON CONFLICT(content_id) DO UPDATE SET generation=generation+1,next_attempt_at=0,updated_at=excluded.updated_at';
        $db->execute('INSERT INTO cy_search_outbox (content_id,created_at,updated_at) SELECT id,?,? FROM cy_contents WHERE 1=1'.$suffix,[time(),time()]);
    }
    public function rebuild(int $limit=25,bool $reset=false): array
    {
        Input::integer($limit,1,50);
        $this->db->transaction(function()use($limit,$reset):void{
            $state=$this->db->one('SELECT * FROM cy_external_search_state WHERE id=1'.$this->db->lock());
            $cursor=$reset?0:(int)$state['cursor_id'];
            foreach($this->db->all('SELECT id FROM cy_contents WHERE id>? ORDER BY id LIMIT ?',[$cursor,$limit]) as $row){$cursor=(int)$row['id'];self::enqueue($this->db,$cursor);}
            $complete=!$this->db->one('SELECT id FROM cy_contents WHERE id>? LIMIT 1',[$cursor]);
            $this->db->update('cy_external_search_state',1,['cursor_id'=>$cursor,'completed'=>$complete?1:0,'updated_at'=>time()]);
        });return $this->status();
    }
    public function status(): array
    {
        $state=$this->db->one('SELECT * FROM cy_external_search_state WHERE id=1');
        $state['queued']=(int)$this->db->value('SELECT COUNT(*) FROM cy_search_outbox');
        $state['waiting']=(int)$this->db->value('SELECT COUNT(*) FROM cy_search_outbox WHERE task_uid>=0');
        $state['retrying']=(int)$this->db->value("SELECT COUNT(*) FROM cy_search_outbox WHERE last_error<>''");
        $state['configured']=$this->configured();return $state;
    }
    public function due(int $limit=2): array
    {Input::integer($limit,1,10);return $this->db->all('SELECT id,updated_at FROM cy_search_outbox WHERE lease_until<=? AND next_attempt_at<=? ORDER BY next_attempt_at,id LIMIT ?',[time(),time(),$limit]);}
    /** Export only metadata already listable by an anonymous visitor. */
    public function document(int $id): ?array
    {
        $row=$this->db->one('SELECT id,kind,title,excerpt,tags,edit_version,status,publish_at,category_id FROM cy_contents WHERE id=?',[$id]);
        if(!$row || $row['status']!=='published' || (int)$row['publish_at']>time() || !isset(['article'=>1,'thread'=>1,'product'=>1][$row['kind']])){return null;}
        $module=['article'=>'articles','thread'=>'forum','product'=>'shop'][$row['kind']];
        if(!$this->settings->enabled($module) || !(new Categories($this->db,$this->settings,$this->activity))->allowed((int)$row['category_id'],null)){return null;}
        $doc=['id'=>$id,'version'=>(int)$row['edit_version'],'title'=>$row['title'],'excerpt'=>$row['excerpt'],'tags'=>$row['tags']];
        foreach($this->db->all("SELECT locale,title,excerpt FROM cy_translations WHERE content_id=? AND source_version=? AND status='published'",[$id,(int)$row['edit_version']]) as $t){
            if(in_array($t['locale'],Locale::SUPPORTED,true)){$suffix=$t['locale']==='zh-CN'?'zh':'en';$doc['title_'.$suffix]=$t['title'];$doc['excerpt_'.$suffix]=$t['excerpt'];}
        }return $doc;
    }
    /** Bounded remote ranking is combined with local matching by Content::feed(). */
    public function query(string $phrase,string $locale): array
    {
        Input::choice($locale,Locale::SUPPORTED);$q=Input::required($phrase,100);$target=$this->target();$suffix=$locale==='zh-CN'?'zh':'en';
        $data=$this->http->jsonBody('POST',$target['url'].'/indexes/'.$target['index'].'/search',[
            'q'=>$q,'limit'=>self::CANDIDATES,'attributesToRetrieve'=>['id','version'],
            'attributesToSearchOn'=>['title','title_'.$suffix,'tags','excerpt','excerpt_'.$suffix],
        ],$target['headers']);
        if(!isset($data['hits']) || !is_array($data['hits']) || count($data['hits'])>self::CANDIDATES){throw new Problem('Invalid search results.',502);}
        $clauses=[];$params=[];$sort=[];$sortParams=[];$seen=[];
        foreach($data['hits'] as $rank=>$hit){
            if(!is_array($hit) || !isset($hit['id'],$hit['version']) || !is_int($hit['id']) || !is_int($hit['version']) || $hit['id']<1 || $hit['version']<1 || isset($seen[$hit['id']])){throw new Problem('Invalid search result identity.',502);}
            $seen[$hit['id']]=true;$clauses[]='(c.id=? AND c.edit_version=?)';array_push($params,$hit['id'],$hit['version']);
            $sort[]='WHEN ? THEN '.(int)$rank;$sortParams[]=$hit['id'];
        }
        return ['where'=>$clauses?'('.implode(' OR ',$clauses).')':'1=0','params'=>$params,
            'sort'=>$sort?'CASE c.id '.implode(' ',$sort).' ELSE 100000 END ASC,c.id DESC':'c.id DESC','sort_params'=>$sortParams];
    }
    public function run(int $id): array
    {
        Input::integer($id,1);$target=$this->target();$token=bin2hex(random_bytes(24));$db=$this->db;
        $row=$db->transaction(function()use($db,$id,$token,$target):?array{
            $r=$db->one('SELECT * FROM cy_search_outbox WHERE id=?'.$db->lock(),[$id]);
            if(!$r || (int)$r['lease_until']>time() || (int)$r['next_attempt_at']>time()){return null;}
            if($r['target_hash']!==$target['hash']){$r['task_uid']=-1;$r['task_generation']=0;}
            $db->update('cy_search_outbox',$id,['lease_token'=>$token,'lease_until'=>time()+90,'target_hash'=>$target['hash'],'task_uid'=>(int)$r['task_uid'],'task_generation'=>(int)$r['task_generation']]);return $r;
        });
        if(!$row){return ['status'=>'waiting','id'=>$id];}
        try{
            if((int)$row['task_uid']>=0){
                $task=$this->http->jsonBody('GET',$target['url'].'/tasks/'.(int)$row['task_uid'],[],$target['headers']);
                if(!isset($task['uid'],$task['status']) || (int)$task['uid']!==(int)$row['task_uid'] || ($task['indexUid']??null)!==$target['index']){throw new Problem('Mismatched search task.',502);}
                $status=Input::choice($task['status'],['enqueued','processing','succeeded','failed','canceled']);
                if(in_array($status,['failed','canceled'],true)){return $this->finish($id,$token,['task_uid'=>-1,'task_generation'=>0,'last_error'=>'Search task failed. A retry is scheduled.','next_attempt_at'=>time()+60,'attempts'=>(int)$row['attempts']+1],'retry');}
                if($status!=='succeeded'){return $this->finish($id,$token,['next_attempt_at'=>time()+15],'pending');}
                return $db->transaction(function()use($db,$row,$id,$token):array{
                    $fresh=$db->one('SELECT * FROM cy_search_outbox WHERE id=?'.$db->lock(),[$id]);
                    if(!$fresh || !hash_equals($fresh['lease_token'],$token)){return ['status'=>'stale','id'=>$id];}
                    $future=$db->one("SELECT publish_at FROM cy_contents WHERE id=? AND status='published' AND publish_at>?",[(int)$row['content_id'],time()]);
                    if((int)$fresh['generation']===(int)$row['task_generation'] && !$future){$db->execute('DELETE FROM cy_search_outbox WHERE id=?',[$id]);return ['status'=>'succeeded','id'=>$id];}
                    $db->update('cy_search_outbox',$id,['task_uid'=>-1,'task_generation'=>0,'lease_token'=>'','lease_until'=>0,'next_attempt_at'=>$future?(int)$future['publish_at']:0,'last_error'=>'','attempts'=>0]);return ['status'=>'queued','id'=>$id];
                });
            }
            $doc=$this->document((int)$row['content_id']);$path='/indexes/'.$target['index'].'/documents';
            $task=$doc===null?$this->http->jsonBody('POST',$target['url'].$path.'/delete-batch',[(int)$row['content_id']],$target['headers']):$this->http->jsonBody('POST',$target['url'].$path.'?primaryKey=id',[$doc],$target['headers']);
            if(!isset($task['taskUid']) || !is_int($task['taskUid']) || $task['taskUid']<0 || (isset($task['indexUid']) && $task['indexUid']!==$target['index'])){throw new Problem('Invalid search task acknowledgement.',502);}
            return $this->finish($id,$token,['task_uid'=>$task['taskUid'],'task_generation'=>(int)$row['generation'],'next_attempt_at'=>time()+15,'last_error'=>''],'pending');
        }catch(\Throwable $e){
            $delay=min(3600,30*(2**min(6,(int)$row['attempts'])));
            return $this->finish($id,$token,['last_error'=>'Synchronization could not be confirmed. Retry is scheduled.','attempts'=>(int)$row['attempts']+1,'next_attempt_at'=>time()+$delay],'retry');
        }
    }
    private function finish(int $id,string $token,array $changes,string $status): array
    {
        return $this->db->transaction(function()use($id,$token,$changes,$status):array{
            $row=$this->db->one('SELECT * FROM cy_search_outbox WHERE id=?'.$this->db->lock(),[$id]);
            if(!$row || !hash_equals($row['lease_token'],$token)){return ['status'=>'stale','id'=>$id];}
            $this->db->update('cy_search_outbox',$id,array_merge($changes,['lease_token'=>'','lease_until'=>0,'updated_at'=>time()]));return ['status'=>$status,'id'=>$id];
        });
    }
}
