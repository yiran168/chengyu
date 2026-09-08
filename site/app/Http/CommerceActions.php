<?php
declare(strict_types=1);
namespace Chengyu\Http;
use Chengyu\App;
use Chengyu\Core\{Input,Problem,Security,Locale};
use Chengyu\Services\{Checkout,Cart};
/** The global action boundary has already checked POST, scalar inputs and CSRF. */
final class CommerceActions
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    public function owner(int $order=0,int $sale=0): array
    {
        $a=$this->a;$regular=$a->auth->user();$guest=$a->guests->current();
        if(!$order&&!$sale){$u=$regular??$guest;if(!$u){throw new Problem('Sign in or unlock your guest pass.',401);}return $u;}
        $row=$a->db->one('SELECT user_id FROM '.($sale?'cy_sales':'cy_orders').' WHERE id=?',[$sale?:$order]);
        if($row){foreach([$regular,$guest] as $u){if($u&&(int)$u['id']===(int)$row['user_id']){return $u;}}}throw new Problem('Order not found.',404);
    }
    public function run(string $action,array $in,array $response): ?array
    {
        $a=$this->a;
        if($action==='locale_select'){Locale::select(Input::choice($in['locale']??'',Locale::SUPPORTED),$a->basePath,strpos($a->config['url'],'https://')===0,Input::choice($in['scope']??Locale::scope(),['site','admin']));$response['message']=tr('Language updated.');return $response;}
        if($action==='guest_unlock'){
            $a->activity->rate('guest-unlock',Security::ip(),15,3600);$token=Input::required($in['token']??'',64);$a->guests->authorize($token);$_SESSION['guest_token']=$token;$response['redirect']=$a->url('guest_orders');return $response;
        }
        if($action==='guest_forget'){unset($_SESSION['guest_token']);$response['redirect']=$a->url('guest_orders');return $response;}
        if($action==='guest_claim'){
            $u=$a->auth->requireUser();if(empty($in['confirm'])){throw new Problem('Confirm that these guest orders should move to this account.');}
            $a->activity->rate('guest-claim',(string)$u['id'],10,3600);$a->guests->claim((int)$u['id'],Input::required($in['token']??($_SESSION['guest_token']??''),64));unset($_SESSION['guest_token']);$response['redirect']=$a->url('orders');return $response;
        }
        if($action==='checkout_review'){
            $a->activity->rate('checkout-quote',Security::ip(),60,3600);
            try{$lines=json_decode(Input::required($in['lines']??'',8192),true,24,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new Problem('Invalid checkout lines.');}if(!is_array($lines)){throw new Problem('Invalid checkout lines.');}
            if(isset($in['variant_id'])&&count($lines)===1){$lines[0]['variant_id']=Input::integer($in['variant_id']);}
            if(isset($in['quantity'])&&count($lines)===1){$lines[0]['quantity']=Input::integer($in['quantity'],1,10);}
            $lines=Checkout::lines($lines);$address=Checkout::address($in);$coupon=Input::text($in['coupon']??'',64);$owner=$a->auth->user()??$a->guests->current();
            $quote=$a->checkout->quote($owner,$lines,$address,$coupon);$gateway=Input::choice($in['gateway']??'',array_merge(['wallet'],array_keys(\Chengyu\Services\DirectPayments::NAMES)));
            $email=Input::text($in['email']??'',191);if(!$owner&&!filter_var($email,FILTER_VALIDATE_EMAIL)){throw new Problem('Enter a valid email address.');}
            $_SESSION['checkout_draft']=['lines'=>$lines,'address'=>$address,'coupon'=>$coupon,'gateway'=>$gateway,'email'=>$email,'quote'=>$quote,'intent'=>Security::token(),'expires_at'=>time()+900];$response['redirect']=$a->url('checkout_review');return $response;
        }
        if($action==='checkout_confirm'){
            $draft=$_SESSION['checkout_draft']??null;if(!is_array($draft)||!hash_equals($draft['intent'],(string)($in['intent']??''))){throw new Problem('This checkout draft changed. Review it again.',409);}
            if(!empty($draft['sale_id'])){$response['redirect']=$a->url('receipt',['id'=>$draft['sale_id']]);return $response;}
            if($draft['expires_at']<time()){throw new Problem('The checkout draft expired. Review it again.',409);}
            if(empty($in['confirm'])){throw new Problem('Confirm the items, total and service terms before purchasing.');}
            $a->activity->rate('checkout-create',Security::ip(),20,3600);$owner=$a->auth->user()??$a->guests->current();
            if(!$owner){$g=$a->guests->create($draft['email']);$_SESSION['guest_token']=$g['token'];$owner=$a->guests->authorize($g['token']);}
            $sale=$a->checkout->create((int)$owner['id'],$draft['lines'],$draft['address'],$draft['coupon'],$draft['gateway'],$draft['intent'],(int)$draft['quote']['total'],$draft['quote']['currency']);
            $_SESSION['checkout_draft']['sale_id']=(int)$sale['id'];$response['redirect']=$a->url('receipt',['id'=>$sale['id']]);$response['message']=tr('Order reserved. Payment status is confirmed separately.');return $response;
        }
        if(in_array($action,['checkout_open','checkout_check','checkout_cancel'],true)){
            $id=Input::integer($in['sale_id']??0,1);$owner=$this->owner(0,$id);$a->activity->rate('checkout-check',(string)$owner['id'],40,3600);
            if($action==='checkout_open'){$s=$a->checkout->start($id,(int)$owner['id']);$response['redirect']=$s['status']==='pending'?$s['checkout_url']:$a->url('receipt',['id'=>$id]);}
            elseif($action==='checkout_check'){$a->checkout->reconcile($id);$response['redirect']=$a->url('receipt',['id'=>$id]);}
            else{$a->checkout->cancelUnsent($id,(int)$owner['id']);$response['redirect']=$a->url('receipt',['id'=>$id]);}return $response;
        }
        if($action==='checkout_receive'){$order=Input::integer($in['order_id']??0,1);$u=$this->owner($order);$a->commerce->receive((int)$u['id'],$order);return $response;}
        if($action==='aftersale_request'){
            $order=Input::integer($in['order_id']??0,1);$u=$this->owner($order);$a->activity->rate('aftersale',(string)$u['id'],30,3600);
            $a->aftersales->request((int)$u['id'],$order,Input::choice($in['kind']??'refund',['refund','return_refund','exchange']),Input::required($in['reason']??'',2000),Input::integer($in['evidence_id']??0));return $response;
        }
        if($action==='aftersale_customer'){
            $id=Input::integer($in['id']??0,1);$r=$a->db->one('SELECT order_id FROM cy_aftersales WHERE id=?',[$id]);if(!$r){throw new Problem('Service request not found.',404);}$u=$this->owner((int)$r['order_id']);
            $a->aftersales->customer((int)$u['id'],$id,Input::integer($in['revision']??0),Input::required($in['operation']??'',20),Input::text($in['tracking']??'',1000));return $response;
        }
        if($action==='upgrade_quote'){$u=$a->auth->requireUser();$q=$a->upgrades->quote((int)$u['id'],Input::integer($in['plan_id']??0,1),Input::choice($in['mode']??'new_term',['new_term','keep_expiry']));$_SESSION['upgrade_quote']=$q;$response['redirect']=$a->url('upgrade',['plan_id'=>$q['plan']]);return $response;}
        if($action==='upgrade_buy'){
            $u=$a->auth->requireUser();$o=$a->upgrades->buy((int)$u['id'],Input::integer($in['plan_id']??0,1),Input::choice($in['mode']??'',['new_term','keep_expiry']),Input::integer($in['as_of']??0),Input::required($in['token']??'',64),Input::required($in['intent']??'',32));unset($_SESSION['upgrade_quote']);$response['redirect']=$a->url('orders',['id'=>$o['id']]);return $response;
        }
        $adminActions=['admin_rule_save','admin_shipping_bind','admin_checkout_check','admin_aftersale_review','admin_refund_run','admin_payout_queue','admin_payout_run','admin_jobs_run','admin_upgrade_refund','admin_lot_import'];
        if(!in_array($action,$adminActions,true)){return null;}$actor=$a->auth->requirePermission('manage');$uid=(int)$actor['id'];
        if(in_array($action,['admin_aftersale_review','admin_refund_run','admin_payout_queue','admin_payout_run','admin_jobs_run','admin_upgrade_refund','admin_lot_import'],true)){$a->activity->rate('commerce-admin',(string)$uid,40,3600);$a->auth->prove(Input::passwordValue($in['current_password']??''),Input::text($in['factor_code']??'',32));}
        switch($action){
            case 'admin_rule_save':$a->rulebooks->save($uid,Input::choice($in['kind']??'',['shipping','promotion']),$in);break;
            case 'admin_shipping_bind':$a->rulebooks->bindShipping($uid,Input::integer($in['content_id']??0,1),Input::integer($in['template_id']??0),Input::integer($in['weight_grams']??0,0,1000000));break;
            case 'admin_checkout_check':$a->checkout->reconcile(Input::integer($in['sale_id']??0,1));break;
            case 'admin_aftersale_review':$a->aftersales->review($uid,Input::integer($in['id']??0,1),Input::integer($in['revision']??0),Input::required($in['operation']??'',20),Input::required($in['note']??'',2000),!empty($in['sellable']),Input::text($in['tracking']??'',1000));break;
            case 'admin_refund_run':$a->refunds->run(Input::integer($in['id']??0,1));break;
            case 'admin_payout_queue':$a->payouts->queue($uid,Input::integer($in['withdrawal_id']??0,1));break;
            case 'admin_payout_run':$a->payouts->run(Input::integer($in['id']??0,1));break;
            case 'admin_jobs_run':$response['jobs']=$a->jobs->run(Input::integer($in['limit']??1,1,10));break;
            case 'admin_upgrade_refund':$a->upgrades->refund($uid,Input::integer($in['order_id']??0,1));break;
            case 'admin_lot_import':$a->upgrades->importLot($uid,Input::integer($in['order_id']??0,1),Input::integer($in['starts_at']??0,1,253402214400),Input::integer($in['until_at']??0,1,253402214400));break;
        }return $response;
    }
}
