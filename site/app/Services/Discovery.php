<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Input,Problem};

/** Private reading state. Public discovery always reuses Content's permission filter. */
final class Discovery
{
    private Database $db; private Settings $settings; private Content $content; private Wallet $wallet;
    public function __construct(Database $db,Settings $settings,Content $content,Wallet $wallet)
    {$this->db=$db;$this->settings=$settings;$this->content=$content;$this->wallet=$wallet;}
    public function record(array $user,int $content,int $progress): void
    {
        $this->settings->requireModule('reading_history'); Input::integer($progress,0,100);
        $this->db->transaction(function()use($user,$content,$progress):void {
            $fresh=$this->wallet->lockActive((int)$user['id']);
            if(!(int)$fresh['history_enabled']) {return;}
            $item=$this->content->get($content,$fresh);
            if(!$this->content->access($item,$fresh) || $item['status']!=='published') {throw new Problem('Unlock the content before saving reading progress.',403);}
            $old=$this->db->one('SELECT * FROM cy_reading_history WHERE user_id=? AND content_id=?',[(int)$user['id'],$content]);
            if($old) {$this->db->update('cy_reading_history',(int)$old['id'],['progress'=>max((int)$old['progress'],$progress),'updated_at'=>time()]);}
            else {$this->db->insert('cy_reading_history',['user_id'=>(int)$user['id'],'content_id'=>$content,'progress'=>$progress,'updated_at'=>time()]);}
            $limit=(int)$this->settings->get('reading_history_limit');
            $extra=$this->db->all('SELECT id FROM cy_reading_history WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 501 OFFSET ?',[(int)$user['id'],$limit]);
            foreach($extra as $row) {$this->db->execute('DELETE FROM cy_reading_history WHERE id=?',[(int)$row['id']]);}
        });
    }
    public function forget(int $user,int $content=0): void
    {
        if($content) {$this->db->execute('DELETE FROM cy_reading_history WHERE user_id=? AND content_id=?',[$user,$content]);}
        else {$this->db->execute('DELETE FROM cy_reading_history WHERE user_id=?',[$user]);}
    }
    public function preference(int $user,bool $enabled): void
    {
        $this->db->transaction(function()use($user,$enabled):void {
            $this->wallet->lockActive($user);
            $this->db->update('cy_users',$user,['history_enabled'=>$enabled?1:0]);
            if(!$enabled) {$this->forget($user);}
        });
    }
    public function suggestions(string $query,?array $user): array
    {
        $this->settings->requireModule('search');$this->settings->requireModule('search_suggestions');
        $query=Input::text($query,100); if(strlen($query)<2){return [];}
        $feed=$this->content->feed('all',$user,['q'=>$query,'size'=>6]);
        $out=[];foreach($feed['items'] as $item){$out[]=['id'=>(int)$item['id'],'title'=>$item['title'],'kind'=>$item['kind']];}return $out;
    }
}
