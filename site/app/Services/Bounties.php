<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Input,Problem};

/** Points escrow: held once, then either awarded once or returned once. No external money. */
final class Bounties
{
    private Database $db;private Settings $settings;private Wallet $wallet;private Activity $activity;
    public function __construct(Database $db,Settings $settings,Wallet $wallet,Activity $activity)
    {$this->db=$db;$this->settings=$settings;$this->wallet=$wallet;$this->activity=$activity;}
    public function get(int $content): ?array {return $this->db->one('SELECT * FROM cy_bounties WHERE content_id=?',[$content]);}
    public function fund(array $actor,int $content,int $amount,int $days,Content $contents): array
    {
        $this->settings->requireModule('bounties');$this->settings->requireModule('points');$this->settings->requireModule('questions');
        Input::integer($amount,1,(int)$this->settings->get('bounty_max'));Input::integer($days,1,30);
        return $this->db->transaction(function()use($actor,$content,$amount,$days,$contents):array {
            $user=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[(int)$actor['id']]);
            if (!$user || $user['status']!=='active') {throw new Problem('Account unavailable.',403);}
            $this->db->one('SELECT id FROM cy_contents WHERE id=?'.$this->db->lock(),[$content]);
            $item=$contents->get($content,$user);$thread=$this->db->one('SELECT * FROM cy_threads WHERE content_id=?',[$content]);
            if ($item['status']!=='published' || $item['kind']!=='thread' || (int)$item['author_id']!==(int)$user['id'] || !$thread || $thread['mode']!=='question' || (int)$thread['accepted_comment_id']) {throw new Problem('Only the author can fund an open published question.',403);}
            $old=$this->get($content);
            if ($old) {if ((int)$old['amount']===$amount && $old['status']==='open') {return $old;}throw new Problem('This question already has a bounty history.',409);}
            $id=$this->db->insert('cy_bounties',['content_id'=>$content,'owner_id'=>(int)$user['id'],'amount'=>$amount,'status'=>'open','expires_at'=>time()+$days*86400,'created_at'=>time()]);
            $this->wallet->adjust((int)$user['id'],'points',-$amount,'bounty:'.$id.':hold','Question bounty held #'.$content);
            $this->activity->audit((int)$user['id'],'bounty.funded',(string)$content);
            return $this->get($content);
        });
    }
    /** Called inside the same content-locked transaction that accepts an answer. */
    public function award(int $content,array $answer): void
    {
        $row=$this->db->one('SELECT * FROM cy_bounties WHERE content_id=?'.$this->db->lock(),[$content]);
        if (!$row || $row['status']==='returned') {return;}
        if ($row['status']==='awarded') {
            if ((int)$row['answer_id']===(int)$answer['id']) {return;}
            throw new Problem('An awarded bounty cannot be reassigned.',409);
        }
        if ((int)$row['expires_at']<=time()) {throw new Problem('Return the expired bounty before accepting an answer.',409);}
        if ((int)$answer['user_id']===(int)$row['owner_id']) {throw new Problem('A bounty cannot be awarded to its owner.',403);}
        $winner=$this->db->one('SELECT status FROM cy_users WHERE id=?'.$this->db->lock(),[(int)$answer['user_id']]);
        if (!$winner || $winner['status']!=='active') {throw new Problem('The answer author is unavailable.',409);}
        $this->wallet->adjust((int)$answer['user_id'],'points',(int)$row['amount'],'bounty:'.$row['id'].':award','Question bounty earned #'.$content);
        $this->db->update('cy_bounties',(int)$row['id'],['status'=>'awarded','answer_id'=>(int)$answer['id'],'winner_id'=>(int)$answer['user_id'],'settled_at'=>time()]);
        $this->activity->audit((int)$row['owner_id'],'bounty.awarded',$content.':'.$answer['user_id']);
    }
    public function release(array $actor,int $content): void
    {
        // Releasing escrow remains available even if new bounties are disabled.
        $this->db->transaction(function()use($actor,$content):void {
            $this->db->one('SELECT id FROM cy_contents WHERE id=?'.$this->db->lock(),[$content]);
            $row=$this->db->one('SELECT * FROM cy_bounties WHERE content_id=?'.$this->db->lock(),[$content]);
            if (!$row) {throw new Problem('Bounty not found.',404);}
            $admin=$actor['role']==='admin';
            if (!$admin && ((int)$row['owner_id']!==(int)$actor['id'] || (int)$row['expires_at']>time())) {throw new Problem('Only the owner can return an expired bounty.',403);}
            if ($row['status']==='returned') {return;}
            if ($row['status']!=='open') {throw new Problem('This bounty has already been settled.',409);}
            $this->wallet->adjust((int)$row['owner_id'],'points',(int)$row['amount'],'bounty:'.$row['id'].':return','Question bounty returned #'.$content);
            $this->db->update('cy_bounties',(int)$row['id'],['status'=>'returned','settled_at'=>time()]);
            $this->activity->audit((int)$actor['id'],'bounty.returned',(string)$content);
        });
    }
}
