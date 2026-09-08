<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
/** Private customer support, revision-checked replies and idempotent browser retries. */
final class SupportDesk
{
    public const STATES=['open','waiting','resolved','closed'];
    private App $a;public function __construct(App $a){$this->a=$a;}
    private static function staff(array $user): bool{return $user['role']==='admin';}
    private static function key(array $user,string $nonce,string $purpose): string
    {if(!preg_match('/^[a-f0-9]{32,64}$/D',$nonce)){throw new Problem('Invalid request key.');}return hash('sha256',$purpose.':'.$user['id'].':'.$nonce);}
    private function member(array $user): void
    {$this->a->settings->requireModule('support');if(!empty($user['is_guest']) || $user['status']!=='active'){throw new Problem('Sign in with an active member account.',403);}}
    public function create(array $user,array $in): int
    {
        $this->member($user);$subject=Input::required($in['subject']??'',160);$body=Input::required($in['body']??'',4000);$order=Input::integer($in['order_id']??0);$key=self::key($user,(string)($in['request_key']??''),'ticket');
        return $this->a->db->transaction(function()use($user,$subject,$body,$order,$key):int{
            $db=$this->a->db;$db->one('SELECT id FROM cy_users WHERE id=?'.$db->lock(),[(int)$user['id']]);$old=$db->one('SELECT * FROM cy_support_tickets WHERE request_key=?',[$key]);
            if($old){$first=$db->value('SELECT body FROM cy_support_replies WHERE ticket_id=? ORDER BY id LIMIT 1',[(int)$old['id']]);if($old['subject']!==$subject || (int)$old['order_id']!==$order || $first!==$body){throw new Problem('A request key cannot be reused with different content.',409);}return (int)$old['id'];}
            if($order && !$db->one('SELECT id FROM cy_orders WHERE id=? AND user_id=?',[$order,(int)$user['id']])){throw new Problem('Order not found.',404);}
            $id=$db->insert('cy_support_tickets',['user_id'=>(int)$user['id'],'order_id'=>$order,'subject'=>$subject,'status'=>'open','priority'=>'normal','request_key'=>$key,'created_at'=>time(),'updated_at'=>time()]);
            $db->insert('cy_support_replies',['ticket_id'=>$id,'user_id'=>(int)$user['id'],'body'=>$body,'request_key'=>hash('sha256','first:'.$key),'created_at'=>time()]);$this->a->activity->audit((int)$user['id'],'support.created',(string)$id);return $id;
        });
    }
    public function ticket(array $user,int $id): array
    {
        $this->member($user);$row=$this->a->db->one('SELECT t.*,u.display_name FROM cy_support_tickets t JOIN cy_users u ON u.id=t.user_id WHERE t.id=?',[$id]);
        if(!$row || (!self::staff($user) && (int)$row['user_id']!==(int)$user['id'])){throw new Problem('Ticket not found.',404);}return $row;
    }
    public function replies(array $user,int $id,int $page=1): array
    {
        $this->ticket($user,$id);Input::integer($page,1,100000);return $this->a->db->all('SELECT r.id,r.body,r.created_at,r.user_id,u.display_name,u.role FROM cy_support_replies r JOIN cy_users u ON u.id=r.user_id WHERE r.ticket_id=? ORDER BY r.id LIMIT 50 OFFSET ?',[$id,($page-1)*50]);
    }
    public function reply(array $user,array $in): int
    {
        $this->member($user);$id=Input::integer($in['ticket_id']??0,1);$revision=Input::integer($in['revision']??0,1);$body=Input::required($in['body']??'',4000);$key=self::key($user,(string)($in['request_key']??''),'reply');
        return $this->a->db->transaction(function()use($user,$id,$revision,$body,$key):int{
            $db=$this->a->db;$ticket=$db->one('SELECT * FROM cy_support_tickets WHERE id=?'.$db->lock(),[$id]);
            if(!$ticket || (!self::staff($user) && (int)$ticket['user_id']!==(int)$user['id'])){throw new Problem('Ticket not found.',404);}
            $old=$db->one('SELECT * FROM cy_support_replies WHERE request_key=?',[$key]);if($old){if((int)$old['ticket_id']!==$id || $old['body']!==$body){throw new Problem('A request key cannot be reused with different content.',409);}return (int)$ticket['revision'];}
            if((int)$ticket['revision']!==$revision){throw new Problem('Ticket changed. Reload before replying.',409);}if($ticket['status']==='closed'){throw new Problem('Reopen the ticket before replying.',409);}
            if((int)$db->value('SELECT COUNT(*) FROM cy_support_replies WHERE ticket_id=?',[$id])>=1000){throw new Problem('Ticket reply limit reached. Start a new ticket.');}
            $db->insert('cy_support_replies',['ticket_id'=>$id,'user_id'=>(int)$user['id'],'body'=>$body,'request_key'=>$key,'created_at'=>time()]);
            $db->update('cy_support_tickets',$id,['status'=>self::staff($user)?'waiting':'open','revision'=>$revision+1,'updated_at'=>time()]);
            if(self::staff($user) && (int)$ticket['user_id']!==(int)$user['id']){$this->a->activity->notify((int)$ticket['user_id'],'Your support ticket has a reply',$ticket['subject'],'support&id='.$id);}
            $this->a->activity->audit((int)$user['id'],'support.replied',(string)$id);return $revision+1;
        });
    }
    public function status(array $user,int $id,int $revision,string $state,string $priority='normal'): void
    {
        $this->member($user);Input::choice($state,self::staff($user)?self::STATES:['open','closed']);Input::choice($priority,['normal','urgent']);
        $this->a->db->transaction(function()use($user,$id,$revision,$state,$priority):void{
            $db=$this->a->db;$row=$db->one('SELECT * FROM cy_support_tickets WHERE id=?'.$db->lock(),[$id]);
            if(!$row || (!self::staff($user)&&(int)$row['user_id']!==(int)$user['id'])){throw new Problem('Ticket not found.',404);}if((int)$row['revision']!==$revision){throw new Problem('Ticket changed. Reload before replying.',409);}
            $db->update('cy_support_tickets',$id,['status'=>$state,'priority'=>self::staff($user)?$priority:$row['priority'],'revision'=>$revision+1,'updated_at'=>time()]);$this->a->activity->audit((int)$user['id'],'support.status',$id.':'.$state);
        });
    }
    public function listing(array $user,int $page=1,string $state=''): array
    {
        $this->member($user);Input::integer($page,1,100000);if($state!==''){Input::choice($state,self::STATES);}$params=[];$where='1=1';
        if(!self::staff($user)){$where.=' AND t.user_id=?';$params[]=(int)$user['id'];}if($state!==''){$where.=' AND t.status=?';$params[]=$state;}
        $total=(int)$this->a->db->value('SELECT COUNT(*) FROM cy_support_tickets t WHERE '.$where,$params);$params[]=($page-1)*30;
        return ['items'=>$this->a->db->all('SELECT t.*,u.display_name FROM cy_support_tickets t JOIN cy_users u ON u.id=t.user_id WHERE '.$where.' ORDER BY t.updated_at DESC,t.id DESC LIMIT 30 OFFSET ?',$params),'page'=>$page,'pages'=>max(1,(int)ceil($total/30)),'total'=>$total];
    }
    public function template(array $actor,array $in): int
    {
        if(!self::staff($actor)){throw new Problem('Access denied.',403);}$id=Input::integer($in['id']??0);$revision=Input::integer($in['revision']??0);
        $data=['title'=>Input::required($in['title']??'',100),'body'=>Input::required($in['body']??'',4000),'active'=>empty($in['active'])?0:1,'updated_at'=>time(),'revision'=>$revision+1];
        return $this->a->db->transaction(function()use($actor,$id,$revision,$data):int{
            $db=$this->a->db;$db->one('SELECT value FROM cy_settings WHERE name=?'.$db->lock(),['_schema_version']);$old=$id?$db->one('SELECT * FROM cy_support_templates WHERE id=?'.$db->lock(),[$id]):null;
            if(($id && !$old)||($old?(int)$old['revision']:0)!==$revision){throw new Problem('Reply template changed. Reload before saving.',409);}
            if(!$old && (int)$db->value('SELECT COUNT(*) FROM cy_support_templates')>=100){throw new Problem('Maximum 100 reply templates.');}
            if($old){$db->update('cy_support_templates',$id,$data);}else{$id=$db->insert('cy_support_templates',$data);}$this->a->activity->audit((int)$actor['id'],'support.template',(string)$id);return $id;
        });
    }
}
