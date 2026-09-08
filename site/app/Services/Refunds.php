<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\Problem;
use Chengyu\Payments\Tools;
/** A network timeout is an uncertain outcome, never a failed payment or a wallet credit. */
final class Refunds
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    public function queue(int $actor,int $order,int $after): int
    {
        $a=$this->a;return $a->db->transaction(function()use($a,$actor,$order,$after):int{
            $o=$a->db->one('SELECT * FROM cy_orders WHERE id=?'.$a->db->lock(),[$order]);if(!$o||$o['status']!=='refund_requested'||$o['kind']!=='content'){throw new Problem('Only a reviewed content refund can be queued.',409);}
            $r=$a->db->one('SELECT * FROM cy_aftersales WHERE id=? AND order_id=?',[$after,$order]);if(!$r||$r['state']!=='refund_pending'){throw new Problem('An approved service request is required.',409);}
            $old=$a->db->one('SELECT id FROM cy_refunds WHERE order_id=?',[$order]);if($old){return (int)$old['id'];}
            $m=json_decode($o['metadata'],true,32,JSON_THROW_ON_ERROR);
            if(!empty($m['invitation_id'])){$invite=$a->db->one('SELECT * FROM cy_invitations WHERE id=?'.$a->db->lock(),[(int)$m['invitation_id']]);if(!$invite||(int)$invite['uses']>0){throw new Problem('A used invitation cannot be automatically refunded.',409);}$a->db->update('cy_invitations',(int)$invite['id'],['active'=>0]);}
            if((int)$o['sale_id']>0){$s=$a->db->one('SELECT * FROM cy_sales WHERE id=?',[(int)$o['sale_id']]);if(!$s||$s['status']!=='paid'){throw new Problem('The original payment must be confirmed first.',409);}}
            $id=$a->db->insert('cy_refunds',['refund_no'=>Commerce::orderNumber(),'order_id'=>$order,'sale_id'=>(int)$o['sale_id'],'aftersale_id'=>$after,'amount'=>(int)$o['amount'],'status'=>'queued','created_at'=>time(),'updated_at'=>time()]);
            $a->activity->audit($actor,'refund.queued',(string)$id);return $id;
        });
    }
    private function finish(int $id): void
    {
        $a=$this->a;$a->db->transaction(function()use($a,$id):void{
            $hint=$a->db->one('SELECT order_id FROM cy_refunds WHERE id=?',[$id]);if(!$hint){throw new Problem('Refund not found.',404);}
            $a->db->one('SELECT id FROM cy_orders WHERE id=?'.$a->db->lock(),[(int)$hint['order_id']]);$r=$a->db->one('SELECT * FROM cy_refunds WHERE id=?'.$a->db->lock(),[$id]);
            if($r['status']==='succeeded'){return;}if($r['status']!=='confirmed'){throw new Problem('A confirmed refund is required.',409);}
            $a->commerce->reverseConfirmed((int)$r['order_id']);$a->db->update('cy_refunds',$id,['status'=>'succeeded','lease_until'=>0,'last_error'=>'','updated_at'=>time()]);
            if((int)$r['aftersale_id']){$a->db->execute("UPDATE cy_aftersales SET state='completed',revision=revision+1,updated_at=? WHERE id=?",[time(),(int)$r['aftersale_id']]);}$a->activity->audit(0,'refund.completed',(string)$id);
        });
    }
    public function run(int $id): array
    {
        $a=$this->a;$r=$a->db->one('SELECT * FROM cy_refunds WHERE id=?',[$id]);if(!$r){throw new Problem('Refund not found.',404);}if($r['status']==='confirmed'){$this->finish($id);return $a->db->one('SELECT * FROM cy_refunds WHERE id=?',[$id]);}
        if(in_array($r['status'],['succeeded','failed'],true)){return $r;}
        $claimed=$a->db->transaction(function()use($a,$id):?array{
            $r=$a->db->one('SELECT * FROM cy_refunds WHERE id=?'.$a->db->lock(),[$id]);if(!in_array($r['status'],['queued','pending','uncertain','running'],true)||(int)$r['lease_until']>time()){return null;}
            $a->db->update('cy_refunds',$id,['status'=>'running','lease_until'=>time()+90,'attempts'=>(int)$r['attempts']+1,'attempted_at'=>(int)$r['attempted_at']?:time(),'updated_at'=>time()]);return $r;
        });if(!$claimed){return $a->db->one('SELECT * FROM cy_refunds WHERE id=?',[$id]);}
        try{
            $sale=(int)$claimed['sale_id']?$a->db->one('SELECT * FROM cy_sales WHERE id=?',[(int)$claimed['sale_id']]):null;
            if(!$sale||$sale['gateway']==='wallet'||(int)$claimed['amount']===0){$result=['state'=>'succeeded','id'=>'local_'.$claimed['refund_no']];}
            else{$driver=$a->directPayments->driver($sale['gateway'],$a->checkout->config($sale));$result=(int)$claimed['attempted_at']>0?$driver->queryRefund($sale,$claimed):$driver->refund($sale,$claimed);}
            $state=\Chengyu\Core\Input::choice($result['state']??'',['succeeded','pending','failed']);$remote=Tools::identifier($result['id']??'');
            $a->db->transaction(function()use($a,$id,$state,$remote):void{$r=$a->db->one('SELECT * FROM cy_refunds WHERE id=?'.$a->db->lock(),[$id]);if(in_array($r['status'],['confirmed','succeeded'],true)){return;}
                if($r['remote_id']!==''&&$r['remote_id']!==$remote){throw new Problem('Conflicting refund identifiers.',409);}
                $a->db->update('cy_refunds',$id,['status'=>$state==='succeeded'?'confirmed':$state,'remote_id'=>$remote,'lease_until'=>0,'last_error'=>$state==='failed'?'The provider reported a final failure. Merchant review is required.':'','updated_at'=>time()]);
            });
        }catch(\Throwable $e){$a->db->execute("UPDATE cy_refunds SET status='uncertain',lease_until=0,last_error=?,updated_at=? WHERE id=? AND status NOT IN ('confirmed','succeeded','failed')",['The refund outcome is unconfirmed. No wallet credit or stock release was assumed.',time(),$id]);throw $e;}
        $r=$a->db->one('SELECT * FROM cy_refunds WHERE id=?',[$id]);if($r['status']==='confirmed'){$this->finish($id);}return $a->db->one('SELECT * FROM cy_refunds WHERE id=?',[$id]);
    }
}
