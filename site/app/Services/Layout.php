<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Input,Problem};
/** Portable, non-executable page compositions. All fields are validated again on the server. */
final class Layout
{
    public const TYPES=['heading','text','content','collection','cta','stats','links','spacer','hero','faq','media','features','tabs','gallery','timeline','categories','plans','titles','noticeboard','creators','slider','buttons'];
    public const ITEM_TYPES=['features','tabs','gallery','timeline','slider','buttons'];
    public const SYMBOLS=\Chengyu\Core\Icons::NAMES;
    public const PAGES=['page1','page2','page3','page4','page5','page6','page7','page8','page9','page10','page11','page12'];
    public const SLOTS=['home','articles','forum','shop','global_before','global_after','article_before','article_after','page_before','page_after','page1','page2','page3','page4','page5','page6','page7','page8','page9','page10','page11','page12'];
    private Database $db;private Activity $activity;
    public function __construct(Database $db,Activity $activity){$this->db=$db;$this->activity=$activity;}
    public function get(string $slot):array
    {
        Input::choice($slot,self::SLOTS);$row=$this->db->one('SELECT * FROM cy_layouts WHERE slot=?',[$slot]);
        return ['revision'=>$row?(int)$row['revision']:0,'document'=>$row?json_decode($row['document'],true,32,JSON_THROW_ON_ERROR):['format'=>'chengyu-layout','version'=>1,'blocks'=>[]]];
    }
    public function validate(string $json):array
    {
        if(strlen($json)>100000){throw new Problem('Layout file is too large.');}
        try{$input=json_decode($json,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new Problem('Layout must be valid JSON.');}
        if(!is_array($input) || ($input['format']??'')!=='chengyu-layout' || ($input['version']??0)!==1 || !isset($input['blocks']) || !is_array($input['blocks']) || count($input['blocks'])>24){throw new Problem('Unsupported layout format or too many blocks.');}
        $blocks=[];$ids=[];
        foreach($input['blocks'] as $b){
            if(!is_array($b)){throw new Problem('Invalid layout block.');}
            if(array_key_exists('enabled',$b) && !is_bool($b['enabled'])){throw new Problem('Block enabled must be a JSON boolean.');}
            if(array_key_exists('autoplay',$b) && !is_bool($b['autoplay'])){throw new Problem('Autoplay must be a JSON boolean.');}
            $id=Input::required($b['id']??'',40);if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/D',$id) || isset($ids[$id])){throw new Problem('Each block needs a unique safe ID.');}$ids[$id]=true;
            $items=$b['items']??[];
            if(!is_array($items) || count($items)>12){throw new Problem('Maximum 12 entries per block.');}
            $entries=[];foreach($items as $entry){
                if(!is_array($entry)){throw new Problem('Invalid block entry.');}
                $entries[]=['title'=>Input::required($entry['title']??'',120),'text'=>Input::text($entry['text']??'',2000),'icon'=>Input::choice($entry['icon']??'sparkles',self::SYMBOLS),'media_id'=>Input::integer($entry['media_id']??0),'mobile_media_id'=>Input::integer($entry['mobile_media_id']??0),'url'=>Input::url($entry['url']??'')];
            }
            $blocks[]=['id'=>$id,'type'=>Input::choice($b['type']??'',self::TYPES),'enabled'=>($b['enabled']??true)?true:false,'autoplay'=>$b['autoplay']??false,'interval'=>Input::integer($b['interval']??6,3,20),
                'title'=>Input::text($b['title']??'',120),'text'=>Input::text($b['text']??'',5000),'button'=>Input::text($b['button']??'',80),
                'route'=>Input::choice($b['route']??'articles',Catalog::ROUTES),'kind'=>Input::choice($b['kind']??'article',['article','thread','product','all']),
                'category'=>Input::integer($b['category']??0),'collection'=>Input::integer($b['collection']??0),'size'=>Input::integer($b['size']??3,1,12),
                'align'=>Input::choice($b['align']??'left',['left','center']),'tone'=>Input::choice($b['tone']??'plain',['plain','glass','accent']),
                'device'=>Input::choice($b['device']??'all',['all','desktop','mobile']),'animation'=>Input::choice($b['animation']??'fade',['none','fade','rise']),
                'media_id'=>Input::integer($b['media_id']??0),'items'=>$entries,'columns'=>Input::integer($b['columns']??3,1,4),'background_id'=>Input::integer($b['background_id']??0),'audience'=>Input::choice($b['audience']??'all',['all','guest','member','vip']),
                'min_vip_tier'=>Input::integer($b['min_vip_tier']??1,1,3),'page_slot'=>Input::choice($b['page_slot']??'page1',self::PAGES)];
        }
        return ['format'=>'chengyu-layout','version'=>1,'blocks'=>$blocks];
    }
    public function save(int $actor,string $slot,string $json,int $revision):int
    {
        Input::choice($slot,self::SLOTS);$document=json_encode($this->validate($json),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        return $this->db->transaction(function()use($actor,$slot,$document,$revision):int{
            // Existing global settings row is a portable mutex for first creation as well.
            $this->db->one('SELECT value FROM cy_settings WHERE name=?'.$this->db->lock(),['_schema_version']);
            $row=$this->db->one('SELECT * FROM cy_layouts WHERE slot=?'.$this->db->lock(),[$slot]);
            if(($row?(int)$row['revision']:0)!==$revision){throw new Problem('Layout changed in another window. Reload before saving.',409);}
            $next=$revision+1;$data=['slot'=>$slot,'document'=>$document,'revision'=>$next,'actor_id'=>$actor,'updated_at'=>time()];
            if($row){$this->db->update('cy_layouts',(int)$row['id'],$data);}else{$this->db->insert('cy_layouts',$data);}
            $this->db->insert('cy_layout_history',['slot'=>$slot,'revision'=>$next,'document'=>$document,'actor_id'=>$actor,'created_at'=>time()]);
            $this->db->execute('DELETE FROM cy_layout_history WHERE slot=? AND revision<?',[$slot,max(1,$next-19)]);
            $this->activity->audit($actor,'layout.saved',$slot.':'.$next);return $next;
        });
    }

    public function editor(string $slot): array
    {
        $live=$this->get($slot);$draft=$this->db->one('SELECT * FROM cy_layout_drafts WHERE slot=?',[$slot]);
        return ['document'=>$draft?json_decode($draft['document'],true,32,JSON_THROW_ON_ERROR):$live['document'],
            'revision'=>$draft?(int)$draft['base_revision']:$live['revision'],'published_revision'=>$live['revision'],
            'draft_revision'=>$draft?(int)$draft['draft_revision']:0,'stale'=>$draft && (int)$draft['base_revision']!==$live['revision']];
    }
    public function history(string $slot): array
    {Input::choice($slot,self::SLOTS);return $this->db->all('SELECT id,revision,actor_id,created_at FROM cy_layout_history WHERE slot=? ORDER BY revision DESC LIMIT 20',[$slot]);}
    public function draft(int $actor,string $slot,string $json,int $revision,int $draftRevision): int
    {
        Input::choice($slot,self::SLOTS);$document=json_encode($this->validate($json),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        return $this->db->transaction(function()use($actor,$slot,$document,$revision,$draftRevision):int{
            $this->db->one('SELECT value FROM cy_settings WHERE name=?'.$this->db->lock(),['_schema_version']);
            $live=$this->get($slot);$old=$this->db->one('SELECT * FROM cy_layout_drafts WHERE slot=?'.$this->db->lock(),[$slot]);
            if($live['revision']!==$revision || ($old?(int)$old['draft_revision']:0)!==$draftRevision){throw new Problem('Layout or draft changed. Reload before saving.',409);}
            $next=$draftRevision+1;$data=['slot'=>$slot,'document'=>$document,'base_revision'=>$revision,'draft_revision'=>$next,'actor_id'=>$actor,'updated_at'=>time()];
            if($old){$this->db->update('cy_layout_drafts',(int)$old['id'],$data);}else{$this->db->insert('cy_layout_drafts',$data);}
            $this->activity->audit($actor,'layout.draft',$slot.':'.$next);return $next;
        });
    }
    public function publish(int $actor,string $slot,string $json,int $revision,int $draftRevision): int
    {
        return $this->db->transaction(function()use($actor,$slot,$json,$revision,$draftRevision):int{
            $this->db->one('SELECT value FROM cy_settings WHERE name=?'.$this->db->lock(),['_schema_version']);
            $old=$this->db->one('SELECT * FROM cy_layout_drafts WHERE slot=?'.$this->db->lock(),[$slot]);
            if(($old?(int)$old['draft_revision']:0)!==$draftRevision){throw new Problem('Layout or draft changed. Reload before saving.',409);}
            $next=$this->save($actor,$slot,$json,$revision);$this->db->execute('DELETE FROM cy_layout_drafts WHERE slot=?',[$slot]);return $next;
        });
    }
    public function discard(int $actor,string $slot,int $draftRevision): void
    {
        Input::choice($slot,self::SLOTS);
        $this->db->transaction(function()use($actor,$slot,$draftRevision):void{
            $this->db->one('SELECT value FROM cy_settings WHERE name=?'.$this->db->lock(),['_schema_version']);
            $row=$this->db->one('SELECT * FROM cy_layout_drafts WHERE slot=?'.$this->db->lock(),[$slot]);
            if(!$row || (int)$row['draft_revision']!==$draftRevision){throw new Problem('Layout or draft changed. Reload before saving.',409);}
            $this->db->execute('DELETE FROM cy_layout_drafts WHERE id=?',[(int)$row['id']]);$this->activity->audit($actor,'layout.discard',$slot);
        });
    }
    public function restore(int $actor,string $slot,int $historyRevision,int $currentRevision): int
    {
        Input::choice($slot,self::SLOTS);$old=$this->db->one('SELECT document FROM cy_layout_history WHERE slot=? AND revision=?',[$slot,$historyRevision]);
        if(!$old){throw new Problem('Revision not found.',404);}return $this->save($actor,$slot,$old['document'],$currentRevision);
    }
}
