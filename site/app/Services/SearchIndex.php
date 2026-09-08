<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Input,Locale,Problem,Settings};
/** Portable, revision-bound inverted index of public metadata only. No protected bodies. */
final class SearchIndex
{
    private Database $db;private Settings $settings;
    public function __construct(Database $db,Settings $settings){$this->db=$db;$this->settings=$settings;}
    /** English words and CJK single-character/bigram tokens; not a linguistic stemmer. */
    public static function tokens(string $text,bool $query=false): array
    {
        if(!preg_match('//u',$text)){throw new Problem('Search text must be valid UTF-8.');}
        preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+|[\p{L}\p{N}]+/u',$text,$matches);$tokens=[];
        foreach($matches[0] as $word){
            if(preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+$/uD',$word)){
                $chars=preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY);$n=count($chars);
                if(!$query || $n===1){foreach($chars as $c){$tokens['c:'.$c]=true;}}
                for($i=0;$i<$n-1;$i++){$tokens['b:'.$chars[$i].$chars[$i+1]]=true;}
            }else{$tokens['w:'.strtolower($word)]=true;}
        }
        if($query && count($tokens)>32){throw new Problem('Use a shorter search phrase (at most 32 index tokens).');}
        return array_keys($tokens);
    }
    public function status(): array
    {
        $state=$this->db->one('SELECT * FROM cy_search_state WHERE id=1');
        $state['documents']=(int)$this->db->value('SELECT COUNT(DISTINCT content_id) FROM cy_search_terms');
        $state['terms']=(int)$this->db->value('SELECT COUNT(*) FROM cy_search_terms');
        $state['total']=(int)$this->db->value("SELECT COUNT(*) FROM cy_contents WHERE kind IN ('article','thread','product')");return $state;
    }
    public function ready(): bool
    { return in_array($this->settings->get('search_engine'),['portable','meilisearch'],true) && (bool)$this->db->value('SELECT completed FROM cy_search_state WHERE id=1'); }
    public function sync(int $id): void
    {
        $this->db->transaction(function()use($id):void{
            $item=$this->db->one('SELECT id,kind,status,edit_version,title,excerpt,tags FROM cy_contents WHERE id=?'.$this->db->lock(),[$id]);
            $this->db->execute('DELETE FROM cy_search_terms WHERE content_id=?',[$id]);
            RemoteSearch::enqueue($this->db,$id);
            if(!$item || $item['status']!=='published' || $item['kind']==='page'){return;}
            $texts=['base'=>$item];
            foreach($this->db->all("SELECT locale,title,excerpt FROM cy_translations WHERE content_id=? AND source_version=? AND status='published'",[$id,(int)$item['edit_version']]) as $t){$t['tags']='';$texts[$t['locale']]=$t;}
            foreach($texts as $locale=>$text){$weights=[];
                foreach(['title'=>10,'tags'=>5,'excerpt'=>1] as $field=>$weight){foreach(self::tokens($text[$field]) as $token){$hash=hash('sha256',$token);$weights[$hash]=($weights[$hash]??0)+$weight;}}
                foreach($weights as $hash=>$weight){$this->db->insert('cy_search_terms',['content_id'=>$id,'locale'=>$locale,'token_hash'=>$hash,'weight'=>$weight,'source_version'=>(int)$item['edit_version']]);}
            }
        });
    }
    /** Bounded restartable work: no daemon, CLI or vendor server is required. */
    public function rebuild(int $limit=10,bool $reset=false): array
    {
        Input::integer($limit,1,25);
        $this->db->transaction(function()use($limit,$reset):void{
            $state=$this->db->one('SELECT * FROM cy_search_state WHERE id=1'.$this->db->lock());
            if($reset){$this->db->execute('DELETE FROM cy_search_terms');$state['cursor_id']=0;}
            $rows=$this->db->all('SELECT id FROM cy_contents WHERE id>? ORDER BY id LIMIT ?',[(int)$state['cursor_id'],$limit]);$cursor=(int)$state['cursor_id'];
            foreach($rows as $row){$cursor=(int)$row['id'];$this->sync($cursor);}
            $complete=!$this->db->one('SELECT id FROM cy_contents WHERE id>? LIMIT 1',[$cursor]);
            $this->db->update('cy_search_state',1,['cursor_id'=>$cursor,'completed'=>$complete?1:0,'indexed_at'=>time()]);
        });return $this->status();
    }
    /** Returned SQL references only the fixed alias c and trusted identifiers. */
    public function query(string $phrase,string $locale): array
    {
        Input::choice($locale,Locale::SUPPORTED);$tokens=self::tokens(Input::text($phrase,100),true);
        if(!$tokens){return ['where'=>'1=0','params'=>[],'sort'=>'c.id DESC','sort_params'=>[]];}
        $clauses=[];$params=[];$hashes=[];
        foreach($tokens as $token){$hash=hash('sha256',$token);$hashes[]=$hash;
            $clauses[]="EXISTS(SELECT 1 FROM cy_search_terms si WHERE si.content_id=c.id AND si.source_version=c.edit_version AND si.locale IN ('base',?) AND si.token_hash=?)";array_push($params,$locale,$hash);
        }
        $sort="COALESCE((SELECT SUM(sr.weight) FROM cy_search_terms sr WHERE sr.content_id=c.id AND sr.source_version=c.edit_version AND sr.locale IN ('base',?) AND sr.token_hash IN (".implode(',',array_fill(0,count($hashes),'?')).')),0) DESC,c.pinned DESC,c.id DESC';
        return ['where'=>'('.implode(' AND ',$clauses).')','params'=>$params,'sort'=>$sort,'sort_params'=>array_merge([$locale],$hashes)];
    }
}
