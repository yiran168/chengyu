<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Input,Problem};

/** One integer-only pricing boundary for previews, SKU checkout and order snapshots. */
final class Pricing
{
    private Database $db; private Settings $settings; private Membership $membership; private Activity $activity;
    public function __construct(Database $db,Settings $settings,Membership $membership,Activity $activity)
    { $this->db=$db;$this->settings=$settings;$this->membership=$membership;$this->activity=$activity; }
    public static function base(int $amount): array
    {
        Input::integer($amount,0,1000000000);
        return ['base_amount'=>$amount,'amount'=>$amount,'source'=>'list','member_tier'=>0,'rate_bps'=>10000,'rule_id'=>0,'rule_revision'=>0,'ends_at'=>0,'coupon_id'=>0,'coupon_discount'=>0];
    }
    public function rule(int $content): ?array { return $this->db->one('SELECT * FROM cy_price_rules WHERE content_id=?',[$content]); }
    public static function date($value): int
    {
        $text=Input::text($value,40);if ($text==='') {return 0;}
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$text);
        if (!$date || $date->format('Y-m-d\TH:i')!==$text) {throw new Problem('Invalid date.');}
        return $date->getTimestamp();
    }
    public function save(int $actor,int $content,array $input): int
    {
        $data=[];
        foreach (['vip1_bps','vip2_bps','vip3_bps','promo_bps'] as $key) { $data[$key]=Input::integer($input[$key]??10000,0,10000); }
        $data['starts_at']=self::date($input['starts_at']??'');$data['ends_at']=self::date($input['ends_at']??'');
        if ($data['promo_bps']<10000 && (!$data['starts_at'] || $data['ends_at']<=$data['starts_at'])) {throw new Problem('A promotion needs a valid start and a later end.');}
        $data['active']=empty($input['active'])?0:1;$data['updated_at']=time();
        $expected=Input::integer($input['revision']??0);
        return $this->db->transaction(function()use($actor,$content,$data,$expected):int {
            $item=$this->db->one('SELECT kind FROM cy_contents WHERE id=?'.$this->db->lock(),[$content]);
            if (!$item || $item['kind']==='page') {throw new Problem('This content cannot have purchase pricing.',404);}
            $old=$this->rule($content);
            if ((int)($old['revision']??0)!==$expected) {throw new Problem('Pricing changed in another window. Reload before saving.',409);}
            $data['revision']=$expected+1;
            if ($old) {$id=(int)$old['id'];$this->db->update('cy_price_rules',$id,$data);}
            else {$data['content_id']=$content;$id=$this->db->insert('cy_price_rules',$data);}
            $this->activity->audit($actor,'pricing.saved',(string)$content);return $id;
        });
    }
    /** Membership and timed promotion do not compound: the lower eligible price wins.
     * Higher tiers inherit lower-tier benefits. Rates apply to each selected SKU. */
    public function price(array $item,?array $account,int $base): array
    {
        $q=self::base($base);
        if (!$this->settings->enabled('pricing')) {return $q;}
        $rule=$this->rule((int)$item['id']);if (!$rule || !(int)$rule['active']) {return $q;}
        $tier=$this->membership->tier($account);$member=10000;
        for ($i=1;$i<=min(3,$tier);$i++) {$member=min($member,(int)$rule['vip'.$i.'_bps']);}
        $promo=(int)$rule['starts_at']<=time() && (int)$rule['ends_at']>time()?(int)$rule['promo_bps']:10000;
        $bps=min($member,$promo);$q['amount']=intdiv($base*$bps,10000);
        $q['rate_bps']=$bps;$q['member_tier']=$tier;$q['rule_id']=(int)$rule['id'];$q['rule_revision']=(int)$rule['revision'];
        if ($bps<10000) {$q['source']=$member<=$promo?'member':'promotion';$q['ends_at']=$q['source']==='promotion'?(int)$rule['ends_at']:0;}
        return $q;
    }
    public function coupon(array $account,array $item,string $kind,array $quote,string $code,bool $reserve=false): array
    {
        $code=strtoupper(Input::text($code,64));if ($code==='') {return $quote;}
        if ($reserve && !$this->db->inTransaction()) {throw new \LogicException('Coupon reservation requires the checkout transaction');}
        if ($item['price_currency']!=='balance' || $quote['amount']===0) {throw new Problem('Coupons apply only to balance-priced purchases.');}
        if (!$this->settings->get('pricing_coupon_stack') && $quote['amount']<$quote['base_amount']) {throw new Problem('Choose either the current special price or a coupon; stacking is disabled.');}
        $offer=$this->db->one('SELECT * FROM cy_coupons WHERE code=?'.($reserve?$this->db->lock():''),[$code]);
        if (!$offer || !(int)$offer['active'] || (int)$offer['starts_at']>time() || (int)$offer['expires_at']<=time() || (int)$offer['uses']>=(int)$offer['max_uses'] || $quote['amount']<(int)$offer['min_amount']) {throw new Problem('Coupon is invalid or unavailable.');}
        if (($offer['scope_kind']!=='all' && $offer['scope_kind']!==$kind) || ((int)$offer['content_id']>0 && ($kind!=='content' || (int)$offer['content_id']!==(int)$item['id']))) {throw new Problem('This coupon does not apply to this item.');}
        if ((int)$offer['min_vip_tier']>0 && $this->membership->tier($account)<(int)$offer['min_vip_tier']) {throw new Problem('Your membership tier does not qualify for this coupon.',403);}
        $id=(int)$offer['id'];
        if ($this->db->one('SELECT id FROM cy_orders WHERE user_id=? AND coupon_id=?',[(int)$account['id'],$id])) {throw new Problem('You have already used this coupon.');}
        $old=$quote['amount'];
        $quote['amount']=$offer['mode']==='flat'?max(0,$old-(int)$offer['value_amount']):intdiv($old*(10000-(int)$offer['value_amount']),10000);
        $quote['coupon_id']=$id;$quote['coupon_discount']=$old-$quote['amount'];
        if ($reserve) {$this->db->execute('UPDATE cy_coupons SET uses=uses+1 WHERE id=?',[$id]);}
        return $quote;
    }
}
