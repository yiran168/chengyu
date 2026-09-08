<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
final class Aftersales
{
    private App $a;
    public function __construct(App $a){$this->a=$a;}
    public function request(int $user,int $order,string $kind,string $reason,int $evidence=0): int
    {
        $kind=Input::choice($kind,['refund','return_refund','exchange']);$reason=Input::required($reason,2000);$a=$this->a;
        return $a->db->transaction(function()use($a,$user,$order,$kind,$reason,$evidence):int{
            $o=$a->db->one('SELECT * FROM cy_orders WHERE id=? AND user_id=?'.$a->db->lock(),[$order,$user]);if(!$o||$o['kind']!=='content'||!in_array($o['status'],['paid','refund_requested'],true)){throw new Problem('This order is not eligible for this service workflow.',409);}
            $old=$a->db->one("SELECT * FROM cy_aftersales WHERE order_id=? AND state NOT IN ('completed','rejected','cancelled') ORDER BY id DESC LIMIT 1",[$order]);
            if($old){if($old['kind']===$kind&&$old['reason']===$reason){return (int)$old['id'];}throw new Problem('An after-sales request is already in progress.',409);}
            if($o['status']!=='paid'){throw new Problem('Resolve the existing refund request first.',409);}
            if($a->db->one('SELECT id FROM cy_refunds WHERE order_id=?',[$order])){throw new Problem('This order already has a refund record. Contact the merchant.',409);}
            $m=json_decode($o['metadata'],true,32,JSON_THROW_ON_ERROR);$physical=($m['product_type']??'')==='physical';
            if($kind!=='refund'&&(!$physical||!in_array($o['fulfillment'],['shipped','received'],true))){throw new Problem('Returns and exchanges require a shipped physical product.');}
            if($kind==='refund'&&$physical&&in_array($o['fulfillment'],['shipped','received'],true)){throw new Problem('Use a return request for a shipped physical product.');}
            $since=(int)$o['received_at']?:(int)$o['paid_at'];if($o['fulfillment']!=='awaiting_shipment'&&$since+(int)$a->settings->get('aftersales_days')*86400<time()){throw new Problem('The self-service after-sales window has ended. Contact the merchant.');}
            if($evidence&&!$a->db->one('SELECT id FROM cy_media WHERE id=? AND owner_id=?',[$evidence,$user])){throw new Problem('Choose evidence uploaded by this account.',403);}
            $id=$a->db->insert('cy_aftersales',['order_id'=>$order,'user_id'=>$user,'kind'=>$kind,'state'=>'requested','reason'=>$reason,'evidence_id'=>$evidence,'return_tracking'=>'','replacement_tracking'=>'','note'=>'','created_at'=>time(),'updated_at'=>time()]);
            $a->db->update('cy_orders',$order,['status'=>'refund_requested']);$a->activity->audit($user,'aftersale.requested',(string)$id);return $id;
        });
    }
    private function locked(int $id,int $revision): array
    {
        $a=$this->a;$hint=$a->db->one('SELECT order_id FROM cy_aftersales WHERE id=?',[$id]);if(!$hint){throw new Problem('Service request not found.',404);}
        $order=$a->db->one('SELECT * FROM cy_orders WHERE id=?'.$a->db->lock(),[(int)$hint['order_id']]);$row=$a->db->one('SELECT * FROM cy_aftersales WHERE id=?'.$a->db->lock(),[$id]);
        if((int)$row['revision']!==$revision){throw new Problem('This service request changed. Reload before continuing.',409);}return [$row,$order];
    }
    private function update(array $row,array $changes,int $actor,string $action): void
    {
        $changes['revision']=(int)$row['revision']+1;$changes['updated_at']=time();$this->a->db->update('cy_aftersales',(int)$row['id'],$changes);$this->a->activity->audit($actor,'aftersale.'.$action,(string)$row['id']);
    }
    public function customer(int $user,int $id,int $revision,string $action,string $tracking=''): void
    {
        Input::choice($action,['return','cancel','received']);$a=$this->a;
        $a->db->transaction(function()use($a,$user,$id,$revision,$action,$tracking):void{
            [$r,$o]=$this->locked($id,$revision);if((int)$r['user_id']!==$user){throw new Problem('Access denied.',403);}
            if($action==='return'){
                if($r['state']!=='awaiting_return'){throw new Problem('The merchant has not requested a return.',409);}
                $tracking=Input::required($tracking,1000);$this->update($r,['state'=>'return_in_transit','return_tracking'=>$a->crypto->seal($tracking)],$user,'return_sent');
            }elseif($action==='cancel'){
                if(!in_array($r['state'],['requested','awaiting_return'],true)){throw new Problem('This request can no longer be cancelled.',409);}
                $this->update($r,['state'=>'cancelled'],$user,'cancelled');$a->db->update('cy_orders',(int)$o['id'],['status'=>'paid']);
            }else{
                if($r['state']!=='replacement_shipped'){throw new Problem('No replacement is awaiting receipt.',409);}
                $this->update($r,['state'=>'completed'],$user,'replacement_received');$a->db->update('cy_orders',(int)$o['id'],['status'=>'paid','fulfillment'=>'received','received_at'=>time()]);
            }
        });
    }
    public function review(int $actor,int $id,int $revision,string $action,string $note='',bool $sellable=false,string $tracking=''): void
    {
        Input::choice($action,['approve','reject','received','replace']);$note=Input::required($note,2000);$a=$this->a;
        $a->db->transaction(function()use($a,$actor,$id,$revision,$action,$note,$sellable,$tracking):void{
            [$r,$o]=$this->locked($id,$revision);
            if($action==='approve'){
                if($r['state']!=='requested'){throw new Problem('This request is no longer awaiting review.',409);}
                if($r['kind']==='refund'){$this->update($r,['state'=>'refund_pending','note'=>$note],$actor,'approved');$a->refunds->queue($actor,(int)$o['id'],$id);}
                else{$this->update($r,['state'=>'awaiting_return','note'=>$note],$actor,'return_authorized');}
            }elseif($action==='reject'){
                if(!in_array($r['state'],['requested','awaiting_return'],true)){throw new Problem('A received return or a submitted refund cannot be rejected here.',409);}
                $this->update($r,['state'=>'rejected','note'=>$note],$actor,'rejected');$a->db->update('cy_orders',(int)$o['id'],['status'=>'paid']);
            }elseif($action==='received'){
                if($r['state']!=='return_in_transit'){throw new Problem('A return shipment is required first.',409);}
                $this->update($r,['state'=>$r['kind']==='return_refund'?'refund_pending':'returned','sellable'=>$sellable?1:0,'note'=>$note],$actor,'return_received');
                if($r['kind']==='return_refund'){$a->refunds->queue($actor,(int)$o['id'],$id);}
            }else{
                if($r['kind']!=='exchange'||$r['state']!=='returned'){throw new Problem('Receive the exchange return before shipping a replacement.',409);}
                $tracking=Input::required($tracking,1000);$item=$a->db->one('SELECT * FROM cy_contents WHERE id=?'.$a->db->lock(),[(int)$o['item_id']]);
                if(!$item||$item['kind']!=='product'||$item['product_type']!=='physical'){throw new Problem('The replacement product is unavailable.',409);}
                if((int)$r['sellable']){$a->inventory->restore((int)$o['item_id'],(int)$o['variant_id']);$item=$a->db->one('SELECT * FROM cy_contents WHERE id=?',[(int)$o['item_id']]);}
                $selection=$a->inventory->selection($item,(int)$o['variant_id']);$a->inventory->consume($item,$selection,(int)$o['id']);
                $cipher=$a->crypto->seal($tracking);$this->update($r,['state'=>'replacement_shipped','replacement_tracking'=>$cipher,'note'=>$note],$actor,'replacement_sent');
                $a->db->update('cy_orders',(int)$o['id'],['fulfillment'=>'shipped','delivery_cipher'=>$cipher]);
            }
            $a->activity->notify((int)$r['user_id'],'Service request updated',$note,'receipt&id='.$o['sale_id']);
        });
    }
}
