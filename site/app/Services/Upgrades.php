<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem,IntegerMath};
/** Paid-value lots prevent list-price credits, repeated credit use and silent FX conversion. */
final class Upgrades
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    private function calculate(int $user,int $plan,string $mode,int $at): array
    {
        $a=$this->a;$a->settings->requireModule('membership');$a->settings->requireModule('upgrades');Input::choice($mode,['new_term','keep_expiry']);
        $account=$a->db->one('SELECT * FROM cy_users WHERE id=?',[$user]);if(!$account||$account['status']!=='active'||(int)$account['is_guest']){throw new Problem('A regular active account is required.',403);}
        $target=$a->db->one('SELECT * FROM cy_plans WHERE id=? AND active=1',[$plan]);if(!$target){throw new Problem('Membership plan is unavailable.',404);}
        $members=$a->db->all('SELECT tier,until_at FROM cy_memberships WHERE user_id=? ORDER BY tier',[$user]);$highest=0;$until=0;
        foreach($members as $m){if((int)$m['until_at']>$at){$highest=max($highest,(int)$m['tier']);$until=max($until,(int)$m['until_at']);}}
        if(!$highest&&(int)$account['vip_until']>$at){throw new Problem('Legacy membership rights need merchant reconciliation before an upgrade.',409);}
        if(!$highest||$highest>=(int)$target['tier']){throw new Problem('Choose a tier above your current active membership. Same-tier renewals use normal purchase.');}
        if($until>=Membership::FOREVER&&(int)$target['days']!==0){throw new Problem('A lifetime membership cannot be downgraded to a finite term during an upgrade.');}
        if($a->db->one("SELECT id FROM cy_orders WHERE user_id=? AND kind='membership' AND status='pending'",[$user])){throw new Problem('Resolve pending membership payments before upgrading.',409);}
        $currency=$target['price_currency'];$a->settings->requireModule($currency==='balance'?'wallet':$currency);$cost=(int)$target['price_amount'];
        $targetUntil=(int)$target['days']===0?Membership::FOREVER:$at+(int)$target['days']*86400;
        if($mode==='keep_expiry'&&(int)$target['days']>0){$targetUntil=$until;$cost=IntegerMath::mulDiv($cost,max(0,$until-$at),(int)$target['days']*86400,true);}
        if($cost>1000000000){throw new Problem('The preserved term exceeds the upgrade amount limit.');}
        $lots=$a->db->all("SELECT l.*,o.status AS order_status FROM cy_membership_lots l JOIN cy_orders o ON o.id=l.order_id WHERE l.user_id=? AND l.status='active' AND l.until_at>? AND l.tier<? ORDER BY l.id",[$user,$at,(int)$target['tier']]);
        $credit=0;$sources=[];
        foreach($lots as $lot){if($lot['order_status']!=='paid'){continue;}if($lot['currency']!==$currency){throw new Problem('Membership credits in different wallet assets cannot be converted automatically.');}
            $basis=(int)$lot['basis_amount'];$end=(int)$lot['until_at'];$start=(int)$lot['starts_at'];
            if($end>=Membership::FOREVER){$value=IntegerMath::mulDiv($basis,(int)$a->settings->get('upgrade_lifetime_credit_bps'),10000);}
            else{$duration=max(1,$end-$start);$remaining=max(0,min($duration,$end-$at));$value=IntegerMath::mulDiv($basis,$remaining,$duration);}
            $sources[]=['id'=>(int)$lot['id'],'order_id'=>(int)$lot['order_id'],'tier'=>(int)$lot['tier'],'start'=>$start,'until'=>$end,'basis'=>$basis,'credit'=>$value];$credit+=$value;
        }
        $untracked=(int)$a->db->value("SELECT COUNT(*) FROM cy_orders o LEFT JOIN cy_membership_lots l ON l.order_id=o.id WHERE o.user_id=? AND o.kind='membership' AND o.status='paid' AND l.id IS NULL",[$user]);
        $quote=['user'=>$user,'plan'=>$plan,'mode'=>$mode,'as_of'=>$at,'valid_until'=>$at+300,'name'=>$target['name'],'tier'=>(int)$target['tier'],'days'=>(int)$target['days'],'currency'=>$currency,
            'target_amount'=>$cost,'credit'=>min($cost,$credit),'unused_credit'=>max(0,$credit-$cost),'amount'=>max(0,$cost-$credit),'target_until'=>$targetUntil,'sources'=>$sources,'previous_memberships'=>$members,'untracked_orders'=>$untracked];
        $quote['token']=$a->crypto->digest('upgrade:'.json_encode($quote,JSON_THROW_ON_ERROR));return $quote;
    }
    public function quote(int $user,int $plan,string $mode='new_term'): array{return $this->calculate($user,$plan,$mode,time());}
    public function buy(int $user,int $plan,string $mode,int $asOf,string $token,string $intent): array
    {
        Wallet::intent($intent);$a=$this->a;
        return $a->db->transaction(function()use($a,$user,$plan,$mode,$asOf,$token,$intent):array{
            $a->wallet->lockActive($user);$old=$a->db->one('SELECT * FROM cy_orders WHERE request_key=?',[$user.':'.$intent]);
            if($old){$meta=json_decode($old['metadata'],true,48,JSON_THROW_ON_ERROR);if($old['kind']!=='membership'||(int)$old['item_id']!==$plan||($meta['upgrade']['mode']??'')!==$mode){throw new Problem('Idempotency key was reused with different data.',409);}return $old;}
            if($asOf>time()||$asOf<time()-300){throw new Problem('The upgrade quote expired. Request a fresh quote.',409);}
            $q=$this->calculate($user,$plan,$mode,$asOf);if(!hash_equals($q['token'],$token)){throw new Problem('Membership rights or prices changed. Review a fresh quote.',409);}
            if($q['untracked_orders']>0){throw new Problem('Legacy paid orders lack verified value lots. Import verified historical lots before consuming those memberships.',409);}
            $row=Commerce::row($user,'membership',$plan,$q['name'].' / upgrade',$q['amount'],$q['currency'],$intent);$row['status']='paid';$row['fulfillment']='fulfilled';$row['paid_at']=time();$row['fulfilled_at']=time();
            $row['money_currency']=(string)$a->settings->get('store_currency');$row['metadata']=json_encode(['days'=>$q['days'],'tier'=>$q['tier'],'upgrade'=>$q],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$id=$a->db->insert('cy_orders',$row);
            if($q['amount']>0){$a->wallet->adjust($user,$q['currency'],-$q['amount'],'order:'.$id.':purchase','Membership upgrade #'.$row['order_no']);}
            foreach($q['sources'] as $source){if($a->db->execute("UPDATE cy_membership_lots SET status='consumed',consumed_by=? WHERE id=? AND status='active'",[$id,$source['id']])!==1){throw new Problem('Upgrade credit was already consumed.',409);}}
            $a->db->execute('DELETE FROM cy_memberships WHERE user_id=? AND tier<?',[$user,$q['tier']]);$target=$a->db->one('SELECT id FROM cy_memberships WHERE user_id=? AND tier=?',[$user,$q['tier']]);
            if($target){$a->db->update('cy_memberships',(int)$target['id'],['until_at'=>$q['target_until']]);}else{$a->db->insert('cy_memberships',['user_id'=>$user,'tier'=>$q['tier'],'until_at'=>$q['target_until']]);}
            $a->db->update('cy_users',$user,['vip_until'=>(int)$a->db->value('SELECT COALESCE(MAX(until_at),0) FROM cy_memberships WHERE user_id=?',[$user])]);
            $a->db->insert('cy_membership_lots',['order_id'=>$id,'user_id'=>$user,'tier'=>$q['tier'],'starts_at'=>$asOf,'until_at'=>$q['target_until'],'basis_amount'=>$q['target_amount'],'currency'=>$q['currency'],'status'=>'active']);
            $a->activity->audit($user,'membership.upgraded',(string)$id);return $a->db->one('SELECT * FROM cy_orders WHERE id=?',[$id]);
        });
    }
    /** Merchant supplies historically verified dates, bounded by the actual paid receipt. */
    public function importLot(int $actor,int $order,int $starts,int $until): void
    {
        $a=$this->a;$a->db->transaction(function()use($a,$actor,$order,$starts,$until):void{
            $o=$a->db->one('SELECT * FROM cy_orders WHERE id=?'.$a->db->lock(),[$order]);if(!$o||$o['kind']!=='membership'||$o['status']!=='paid'){throw new Problem('Choose an existing paid membership order.');}
            $a->wallet->lockActive((int)$o['user_id']);if($a->db->one('SELECT id FROM cy_membership_lots WHERE order_id=?',[$order])){throw new Problem('This paid order already has a value lot.',409);}
            $m=json_decode($o['metadata'],true,48,JSON_THROW_ON_ERROR);$tier=Input::integer($m['tier']??0,1,3);$days=Input::integer($m['days']??-1,0,3650);
            $end=(int)$a->db->value('SELECT until_at FROM cy_memberships WHERE user_id=? AND tier=?',[(int)$o['user_id'],$tier]);
            if($starts<(int)$o['paid_at']||$until<=$starts||$until>$end||($days===0?$until!==Membership::FOREVER:$until-$starts!==$days*86400)){throw new Problem('The lot dates must match the original paid term and current entitlement.');}
            $a->db->insert('cy_membership_lots',['order_id'=>$order,'user_id'=>(int)$o['user_id'],'tier'=>$tier,'starts_at'=>$starts,'until_at'=>$until,'basis_amount'=>(int)$o['amount'],'currency'=>$o['currency'],'status'=>'active']);$a->activity->audit($actor,'membership.lot_imported',(string)$order);
        });
    }
    /** Upgrade refunds restore exact source dates; they do not mint unused credit as money. */
    public function refund(int $actor,int $order): void
    {
        $a=$this->a;$a->db->transaction(function()use($a,$actor,$order):void{
            $o=$a->db->one('SELECT * FROM cy_orders WHERE id=?'.$a->db->lock(),[$order]);if(!$o){throw new Problem('Order not found.',404);}if($o['status']==='refunded'){return;}$m=json_decode($o['metadata'],true,48,JSON_THROW_ON_ERROR);$q=$m['upgrade']??null;
            if($o['kind']!=='membership'||$o['status']!=='paid'||!is_array($q)){throw new Problem('Choose a completed wallet upgrade.');}$user=(int)$o['user_id'];$a->wallet->lockActive($user);
            $lot=$a->db->one('SELECT * FROM cy_membership_lots WHERE order_id=?'.$a->db->lock(),[$order]);$current=(int)$a->db->value('SELECT until_at FROM cy_memberships WHERE user_id=? AND tier=?',[$user,(int)$q['tier']]);
            if(!$lot||$lot['status']!=='active'||$current!==(int)$q['target_until']||$a->db->one("SELECT id FROM cy_orders WHERE user_id=? AND kind='membership' AND id>? AND status IN ('paid','pending','refund_requested')",[$user,$order])){throw new Problem('Later membership changes prevent automatic rollback. Merchant review is required.',409);}
            $expected=[];foreach($q['previous_memberships'] as $r){if((int)$r['tier']>(int)$q['tier']){$expected[(int)$r['tier']]=(int)$r['until_at'];}}$expected[(int)$q['tier']]=(int)$q['target_until'];ksort($expected);
            $actual=[];foreach($a->db->all('SELECT tier,until_at FROM cy_memberships WHERE user_id=? ORDER BY tier',[$user]) as $r){$actual[(int)$r['tier']]=(int)$r['until_at'];}
            if($expected!==$actual || (int)$a->db->value('SELECT vip_until FROM cy_users WHERE id=?',[$user])!==max($expected)){throw new Problem('Membership grants changed after this upgrade. Automatic rollback is blocked.',409);}
            $a->db->execute('DELETE FROM cy_memberships WHERE user_id=?',[$user]);foreach($q['previous_memberships'] as $r){$a->db->insert('cy_memberships',['user_id'=>$user,'tier'=>(int)$r['tier'],'until_at'=>(int)$r['until_at']]);}
            $a->db->execute("UPDATE cy_membership_lots SET status='active',consumed_by=0 WHERE consumed_by=? AND status='consumed'",[$order]);$a->db->update('cy_membership_lots',(int)$lot['id'],['status'=>'refunded']);
            $a->db->update('cy_users',$user,['vip_until'=>(int)$a->db->value('SELECT COALESCE(MAX(until_at),0) FROM cy_memberships WHERE user_id=?',[$user])]);if((int)$o['amount']>0){$a->wallet->adjust($user,$o['currency'],(int)$o['amount'],'order:'.$order.':refund','Upgrade rollback #'.$o['order_no']);}
            $a->db->update('cy_orders',$order,['status'=>'refunded']);$a->activity->audit($actor,'membership.upgrade_refunded',(string)$order);
        });
    }
}
