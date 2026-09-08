<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Crypto, Input, Problem};
final class Commerce
{
    private Database $db; private Settings $settings; private Crypto $crypto; private Wallet $wallet; private Activity $activity; private Inventory $inventory; private Membership $membership; private Creator $creator; private Pricing $pricing;
    public function __construct(Database $db, Settings $settings, Crypto $crypto, Wallet $wallet, Activity $activity, ?Pricing $pricing=null)
    { $this->db = $db; $this->settings = $settings; $this->crypto = $crypto; $this->wallet = $wallet; $this->activity = $activity; $this->inventory = new Inventory($db,$crypto,$activity); $this->membership = new Membership($db); $this->creator = new Creator($db,$settings,$wallet,$activity); $this->pricing=$pricing??new Pricing($db,$settings,$this->membership,$activity); }

    public static function orderNumber(): string { return gmdate('YmdHis') . strtoupper(bin2hex(random_bytes(8))); }
    public static function row(int $user, string $kind, int $item, string $title, int $amount, string $currency, string $intent): array
    {
        return ['order_no' => self::orderNumber(), 'request_key' => $user . ':' . Wallet::intent($intent), 'user_id' => $user,
            'kind' => $kind, 'item_id' => $item, 'title' => $title, 'amount' => $amount, 'currency' => $currency,
            'status' => 'pending', 'gateway' => '', 'gateway_type' => '', 'fulfillment' => 'pending', 'delivery_cipher' => '',
            'address_snapshot' => '', 'metadata' => '{}', 'created_at' => time()];
    }
    private function currencyEnabled(string $currency): void
    {
        Input::choice($currency, ['balance', 'points', 'tokens']);
        $this->settings->requireModule($currency === 'balance' ? 'wallet' : $currency);
    }
    private function existing(int $user, string $kind, int $item, string $intent): ?array
    {
        $row = $this->db->one('SELECT * FROM cy_orders WHERE request_key=?', [$user . ':' . $intent]);
        if ($row && ($row['kind'] !== $kind || (int)$row['item_id'] !== $item)) { throw new Problem('Idempotency key was reused with different data.', 409); }
        return $row;
    }

    /** Every purchase is priced on the server and committed with stock, ledger and access. */
    public function buy(int $user, string $kind, int $itemId, string $intent, string $coupon='', int $addressId=0, int $variantId=0, ?int $priceCeiling=null, ?string $expectedCurrency=null): array
    {
        Input::choice($kind,['content','membership','circle']);Wallet::intent($intent);
        if($priceCeiling!==null){Input::integer($priceCeiling,0,1000000000);}
        if($expectedCurrency!==null){Input::choice($expectedCurrency,['balance','points','tokens']);}
        if ($kind==='circle') { if ($coupon!=='' || $addressId || $variantId) { throw new Problem('Circle joins do not use coupons, addresses or variants.'); } return $this->joinCircle($user,$itemId,$intent); }
        $coupon=strtoupper(Input::text($coupon,64));Input::integer($variantId);
        if ($kind==='membership') { $this->settings->requireModule('membership'); if ($variantId) { throw new Problem('Invalid variant.'); } }
        $fingerprint=hash('sha256',json_encode([$kind,$itemId,$coupon,$addressId,$variantId],JSON_THROW_ON_ERROR));
        return $this->db->transaction(function() use($user,$kind,$itemId,$intent,$coupon,$addressId,$variantId,$fingerprint,$priceCeiling,$expectedCurrency): array {
            $this->settings->assertMoneyCurrency();
            $account=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);
            if (!$account || $account['status']!=='active') { throw new Problem('Account unavailable.',403); }
            $existing=$this->existing($user,$kind,$itemId,$intent);
            if ($existing) {
                if ($existing['fingerprint']!=='' && !hash_equals($existing['fingerprint'],$fingerprint)) { throw new Problem('Idempotency key was reused with different data.',409); }
                return $existing;
            }
            $item=$this->db->one('SELECT * FROM '.($kind==='membership'?'cy_plans':'cy_contents').' WHERE id=?'.$this->db->lock(),[$itemId]);
            if (!$item) { throw new Problem('Item not found.',404); }
            $product=false;$physical=false;$selection=['variant'=>null,'price'=>(int)$item['price_amount'],'code'=>null];
            if ($kind==='membership') {
                if (!(int)$item['active']) { throw new Problem('Membership plan is unavailable.'); }
                if($this->membership->lifetimeTier($user)>=(int)$item['tier']){throw new Problem('You already have lifetime access at this tier or higher.',409);}
                $title=$item['name'];
            } else {
                if ($item['status']!=='published' || (int)$item['publish_at']>time() || $item['kind']==='page') { throw new Problem('Item is unavailable.'); }
                $this->settings->requireModule(['product'=>'shop','thread'=>'forum','article'=>'articles'][$item['kind']]);
                if (!(new Categories($this->db,$this->settings,$this->activity))->allowed((int)$item['category_id'],$account)) { throw new Problem('Category access is required before purchase.',403); }
                $product=$item['kind']==='product';$physical=$product && $item['product_type']==='physical';$title=$item['title'];
                if($physical&&(int)$item['shipping_template_id']>0){throw new Problem('Use secure checkout to calculate this product shipping fee.',409);}
                if (!$product && $item['access_level']!=='paid') { throw new Problem('This content is not sold separately.',403); }
                if (!$product || $item['product_type']==='digital') {
                    if ($variantId) { throw new Problem('This product does not have variants.'); }
                    $owned=$this->db->one("SELECT * FROM cy_orders WHERE user_id=? AND item_id=? AND kind='content' AND status IN ('paid','refund_requested') AND (access_until=0 OR access_until>?) ORDER BY id DESC LIMIT 1",[$user,$itemId,time()]);
                    if ($owned) { return $owned; }
                }
                if ($product) { $selection=$this->inventory->selection($item,$variantId); }
                elseif ($variantId) { throw new Problem('Invalid variant.'); }
                if ($selection['variant']) { $title=Input::clip($title,151).' / '.$selection['variant']['name']; }
            }
            $currency=$item['price_currency'];$this->currencyEnabled($currency);
            if($expectedCurrency!==null && $expectedCurrency!==$currency){throw new Problem('The price currency changed. Refresh and confirm again; nothing was charged.',409);}
            $quote=$kind==='content'?$this->pricing->price($item,$account,$selection['price']):Pricing::base($selection['price']);
            $quote=$this->pricing->coupon($account,$item,$kind,$quote,$coupon,true);
            $amount=$quote['amount'];$couponId=$quote['coupon_id'];
            if($priceCeiling!==null && $amount>$priceCeiling){throw new Problem('The price increased. Refresh the quote and confirm again; nothing was charged.',409);}
            $row=self::row($user,$kind,$itemId,$title,$amount,$currency,$intent);
            $row['money_currency']=(string)$this->settings->get('store_currency');
            $row=array_merge($row,['status'=>'paid','paid_at'=>time(),'coupon_id'=>$couponId,'variant_id'=>$variantId,'fingerprint'=>$fingerprint,'fulfillment'=>$physical?'awaiting_shipment':'fulfilled']);
            $meta=['list_amount'=>$selection['price'],'pricing'=>$quote];
            if ($kind==='membership') { $meta['days']=(int)$item['days'];$meta['tier']=(int)$item['tier']; }
            else {
                $meta['product_type']=$item['product_type'];$meta['content_kind']=$item['kind'];
                $meta['variant']=$selection['variant']?['sku'=>$selection['variant']['sku'],'name'=>$selection['variant']['name']]:null;
                $row['access_until']=(int)$item['access_days']?time()+(int)$item['access_days']*86400:0;
            }
            $row['metadata']=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if ($physical) {
                $address=$this->db->one('SELECT recipient,phone,address FROM cy_addresses WHERE id=? AND user_id=?',[$addressId,$user]);
                if (!$address) { throw new Problem('Please add and select a shipping address.'); }
                $row['address_snapshot']=$this->crypto->seal(json_encode($address,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            }
            $code=$selection['code'];
            if ($code) { $row['delivery_cipher']=$this->crypto->seal($this->crypto->open($code['secret_cipher'])."\n\n".$item['delivery_text']); }
            elseif ($kind==='content' && !$physical && $item['delivery_text']!=='') { $row['delivery_cipher']=$this->crypto->seal($item['delivery_text']); }
            if ($kind==='content' && $product && $item['product_type']==='invitation') {
                $this->settings->requireModule('invitation_products');
                $codes=(new Invitations($this->db,$this->crypto,$this->activity))->issue($user,1,(int)$item['invite_uses'],time()+(int)$item['invite_days']*86400,'Order '.$row['order_no']);
                $invitation=$this->db->one('SELECT id FROM cy_invitations WHERE code_hash=?',[$this->crypto->digest('invite:'.$codes[0])]);
                $meta['invitation_id']=(int)$invitation['id'];$meta['invite_uses']=(int)$item['invite_uses'];$meta['invite_days']=(int)$item['invite_days'];
                $row['metadata']=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $row['delivery_cipher']=$this->crypto->seal($codes[0]."\n\n".$item['delivery_text']);
            }
            $id=$this->db->insert('cy_orders',$row);
            if ($amount>0) { $this->wallet->adjust($user,$currency,-$amount,'order:'.$id.':purchase','Purchase #'.$row['order_no']); }
            $this->fulfill($id,false,$selection);
            return $this->db->one('SELECT * FROM cy_orders WHERE id=?',[$id]);
        });
    }


    /** Shared by wallet and direct checkout. Paid receipts, inventory and entitlements commit once. */
    public function fulfill(int $id,bool $stockReserved=false,?array $selection=null): void
    {
        $this->db->transaction(function()use($id,$stockReserved,$selection):void{
            $order=$this->db->one('SELECT * FROM cy_orders WHERE id=?'.$this->db->lock(),[$id]);
            if(!$order||$order['status']!=='paid'){throw new Problem('Only a paid order can be fulfilled.',409);}
            if((int)$order['fulfilled_at']>0){return;}
            $meta=json_decode($order['metadata'],true,32,JSON_THROW_ON_ERROR);$user=(int)$order['user_id'];
            $this->settings->assertMoneyCurrency();
            $account=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);if(!$account){throw new Problem('Order account is unavailable.',409);}
            if($order['kind']==='membership'){
                $this->membership->purchase($order,(int)$meta['tier'],(int)$meta['days']);
            }elseif($order['kind']==='content'){
                $item=$this->db->one('SELECT * FROM cy_contents WHERE id=?'.$this->db->lock(),[(int)$order['item_id']]);if(!$item){throw new Problem('The purchased content needs merchant review.',409);}
                $product=($meta['content_kind']??$item['kind'])==='product';
                if($product&&!$stockReserved){$this->inventory->consume($item,$selection??$this->inventory->selection($item,(int)$order['variant_id']),$id);}
                if($product&&($meta['product_type']??'')==='invitation'&&empty($meta['invitation_id'])){
                    $uses=(int)($meta['invite_uses']??$item['invite_uses']);$days=(int)($meta['invite_days']??$item['invite_days']);
                    $codes=(new Invitations($this->db,$this->crypto,$this->activity))->issue($user,1,$uses,time()+$days*86400,'Order '.$order['order_no']);
                    $invite=$this->db->one('SELECT id FROM cy_invitations WHERE code_hash=?',[$this->crypto->digest('invite:'.$codes[0])]);$meta['invitation_id']=(int)$invite['id'];
                    $this->db->update('cy_orders',$id,['delivery_cipher'=>$this->crypto->seal($codes[0]."\n\n".($order['delivery_cipher']!==''?$this->crypto->open($order['delivery_cipher']):'')),
                        'metadata'=>json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                }
                $this->db->execute('UPDATE cy_contents SET sold_count=sold_count+1 WHERE id=?',[(int)$order['item_id']]);
                $grant=$this->db->one('SELECT id FROM cy_entitlements WHERE user_id=? AND content_id=?',[$user,(int)$order['item_id']]);
                if($grant){$this->db->update('cy_entitlements',(int)$grant['id'],['order_id'=>$id,'created_at'=>time()]);}
                else{$this->db->insert('cy_entitlements',['user_id'=>$user,'content_id'=>(int)$order['item_id'],'order_id'=>$id,'created_at'=>time()]);}
            }else{throw new Problem('Unsupported purchase kind.');}
            $merchandise=max(0,(int)$order['amount']-(int)$order['shipping_amount']);
            if($order['currency']==='balance'&&$merchandise>0&&$this->settings->enabled('affiliate')&&(int)$account['referred_by']>0&&(int)$account['referred_by']!==$user){
                $referrer=$this->db->one('SELECT id FROM cy_users WHERE id=? AND status=?',[(int)$account['referred_by'],'active']);$commission=intdiv($merchandise*(int)$this->settings->get('commission_bps'),10000);
                if($referrer&&$commission>0){$this->wallet->adjust((int)$referrer['id'],'commission',$commission,'order:'.$id.':commission','Referral commission #'.$order['order_no']);$this->db->update('cy_orders',$id,['affiliate_id'=>(int)$referrer['id'],'commission_amount'=>$commission]);}
            }
            if($order['kind']==='content'){$earningOrder=$this->db->one('SELECT * FROM cy_orders WHERE id=?',[$id]);$earningOrder['amount']=$merchandise;$this->creator->accrue($earningOrder,$item);}
            $this->db->update('cy_orders',$id,['fulfilled_at'=>time()]);$this->activity->notify($user,'Purchase complete',$order['title'],'orders');
        });
    }

    /** Caller is a durable refund worker; external success must exist in the database first. */
    public function reverseConfirmed(int $id): void
    {
        $this->db->transaction(function()use($id):void{
            $o=$this->db->one('SELECT * FROM cy_orders WHERE id=?'.$this->db->lock(),[$id]);if(!$o){throw new Problem('Order not found.',404);}if($o['status']==='refunded'){return;}
            $r=$this->db->one('SELECT * FROM cy_refunds WHERE order_id=?'.$this->db->lock(),[$id]);
            if(!$r||!in_array($r['status'],['confirmed','succeeded'],true)||(int)$r['amount']!==(int)$o['amount']||$o['status']!=='refund_requested'){throw new Problem('A verified refund receipt is required.',409);}
            $meta=json_decode($o['metadata'],true,32,JSON_THROW_ON_ERROR);
            if(!empty($meta['invitation_id'])){$invite=$this->db->one('SELECT * FROM cy_invitations WHERE id=?'.$this->db->lock(),[(int)$meta['invitation_id']]);if(!$invite||(int)$invite['uses']>0){throw new Problem('This invitation needs manual dispute resolution.',409);}$this->db->update('cy_invitations',(int)$invite['id'],['active'=>0]);}
            $this->creator->reverse($o);if((int)$o['commission_amount']>0){$this->wallet->reverseCommission((int)$o['affiliate_id'],(int)$o['commission_amount'],$id,$o['order_no']);}
            if($o['gateway']==='wallet'||(int)$o['sale_id']===0){if((int)$o['amount']>0){$this->wallet->adjust((int)$o['user_id'],$o['currency'],(int)$o['amount'],'order:'.$id.':refund','Refund #'.$o['order_no']);}}
            if($o['kind']==='membership'){throw new Problem('Membership reversals require a reviewed entitlement snapshot.',409);}
            if($o['kind']==='content'){
                $other=$this->db->one("SELECT id FROM cy_orders WHERE user_id=? AND item_id=? AND kind='content' AND id<>? AND status IN ('paid','refund_requested') AND (access_until=0 OR access_until>?) ORDER BY id DESC LIMIT 1",[(int)$o['user_id'],(int)$o['item_id'],$id,time()]);
                if($other){$this->db->execute('UPDATE cy_entitlements SET order_id=? WHERE order_id=?',[(int)$other['id'],$id]);}else{$this->db->execute('DELETE FROM cy_entitlements WHERE order_id=?',[$id]);}
                $this->db->execute('UPDATE cy_contents SET sold_count=CASE WHEN sold_count>0 THEN sold_count-1 ELSE 0 END WHERE id=?',[(int)$o['item_id']]);
                $after=$r['aftersale_id']?$this->db->one('SELECT * FROM cy_aftersales WHERE id=?',[(int)$r['aftersale_id']]):null;
                if(($meta['product_type']??'')==='physical'&&($o['fulfillment']==='awaiting_shipment'||($after&&(int)$after['sellable']===1))){$this->inventory->restore((int)$o['item_id'],(int)$o['variant_id']);}
            }
            $this->db->update('cy_orders',$id,['status'=>'refunded']);$this->activity->notify((int)$o['user_id'],'Refund approved',$o['title'],'orders');
        });
    }

    public function joinCircle(int $user,int $category,string $intent): array
    {
        $this->settings->requireModule('circles');$this->settings->requireModule('forum');Wallet::intent($intent);
        return $this->db->transaction(function()use($user,$category,$intent):array {
            $this->settings->assertMoneyCurrency();
            $account=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);
            if (!$account || $account['status']!=='active') {throw new Problem('Account unavailable.',403);}
            $old=$this->existing($user,'circle',$category,$intent);if($old){return $old;}
            $circle=$this->db->one('SELECT * FROM cy_categories WHERE id=?'.$this->db->lock(),[$category]);
            if (!$circle || !(int)$circle['is_circle'] || !(int)$circle['join_open']) {throw new Problem('This circle is not accepting new members.',409);}
            $policy=new Categories($this->db,$this->settings,$this->activity);
            if (!$policy->allowed((int)$circle['parent_id'],$account)) {throw new Problem('Access to the parent category is required.',403);}
            $block=$this->db->one('SELECT blocked FROM cy_circle_members WHERE category_id=? AND user_id=?',[$category,$user]);
            if (!empty($block['blocked'])) {throw new Problem('Your circle membership is suspended.',403);}
            if ($policy->member($category,$user)) {throw new Problem('Your circle membership is already active. Renew after it expires.',409);}
            $amount=(int)$circle['join_amount'];$currency=$circle['join_currency'];
            if ($amount>0) {$this->currencyEnabled($currency);}
            $row=self::row($user,'circle',$category,$circle['name'],$amount,$currency,$intent);
            $row['money_currency']=(string)$this->settings->get('store_currency');
            $row=array_merge($row,['status'=>'paid','paid_at'=>time(),'fulfillment'=>'fulfilled','access_until'=>(int)$circle['join_days']?time()+(int)$circle['join_days']*86400:0,
                'metadata'=>json_encode(['days'=>(int)$circle['join_days'],'circle_name'=>$circle['name']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $id=$this->db->insert('cy_orders',$row);
            if ($amount>0) {$this->wallet->adjust($user,$currency,-$amount,'order:'.$id.':purchase','Circle membership #'.$row['order_no']);}
            $this->activity->notify($user,'You joined a circle',$circle['name'],'circle&id='.$category);
            $this->activity->audit($user,'circle.joined',(string)$category);
            return $this->db->one('SELECT * FROM cy_orders WHERE id=?',[$id]);
        });
    }

    /** Quantity is represented by per-unit orders; a checkout is all-or-nothing and replay safe. */
    public function checkout(int $user, array $lines, string $intent, int $address=0, string $coupon='', ?array $ceilings=null): array
    {
        Wallet::intent($intent);
        if (!$lines || count($lines)>20) { throw new Problem('Choose 1-20 cart lines.'); }
        $normalized=[];$units=0;
        foreach ($lines as $line) {
            if (!is_array($line)) { throw new Problem('Invalid cart line.'); }
            $item=Input::integer($line['item_id']??0,1);$variant=Input::integer($line['variant_id']??0);$quantity=Input::integer($line['quantity']??1,1,10);
            $key=$item.':'.$variant;
            if (isset($normalized[$key])) { throw new Problem('Duplicate cart line.'); }
            $normalized[$key]=['item_id'=>$item,'variant_id'=>$variant,'quantity'=>$quantity];$units+=$quantity;
        }
        if ($units>100) { throw new Problem('Checkout quantity limit reached.'); }
        if ($coupon!=='' && $units!==1) { throw new Problem('A coupon can be used on a single-unit checkout only.'); }
        ksort($normalized,SORT_STRING);
        $fingerprint=hash('sha256',json_encode([$normalized,$address,strtoupper(trim($coupon))],JSON_THROW_ON_ERROR));
        return $this->db->transaction(function() use($user,$normalized,$intent,$address,$coupon,$fingerprint,$ceilings): array {
            $account=$this->db->one('SELECT status FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);
            if (!$account || $account['status']!=='active') { throw new Problem('Account unavailable.',403); }
            $old=$this->db->one('SELECT * FROM cy_checkouts WHERE request_key=?',[$user.':'.$intent]);
            if ($old) {
                if (!hash_equals($old['fingerprint'],$fingerprint)) { throw new Problem('Idempotency key was reused with different data.',409); }
                return json_decode($old['order_ids'],true,16,JSON_THROW_ON_ERROR);
            }
            $orders=[];
            foreach ($normalized as $line) {
                $item=$this->db->one('SELECT product_type,kind FROM cy_contents WHERE id=?',[$line['item_id']]);
                if (!$item || $item['kind']!=='product') { throw new Problem('Only shop products can be added to the cart.'); }
                if ($item['product_type']==='digital' && $line['quantity']!==1) { throw new Problem('Digital access can only be purchased once per checkout.'); }
                for ($i=0;$i<$line['quantity'];$i++) {
                    $key=substr(hash('sha256',$intent.':'.$line['item_id'].':'.$line['variant_id'].':'.$i),0,32);
                    $order=$this->buy($user,'content',$line['item_id'],$key,$coupon,$address,$line['variant_id']);$orders[]=(int)$order['id'];
                }
            }
            if($ceilings!==null){
                $totals=['balance'=>0,'points'=>0,'tokens'=>0];foreach(array_unique($orders) as $orderId){$priced=$this->db->one('SELECT amount,currency FROM cy_orders WHERE id=?',[$orderId]);$totals[$priced['currency']]+=(int)$priced['amount'];}
                foreach($totals as $currency=>$value){$cap=Input::integer($ceilings[$currency]??-1,0,100000000000);if($value>$cap){throw new Problem('The cart total increased or changed currency. Review the bag again; nothing was charged.',409);}}
            }
            $this->db->insert('cy_checkouts',['user_id'=>$user,'request_key'=>$user.':'.$intent,'fingerprint'=>$fingerprint,'order_ids'=>json_encode($orders,JSON_THROW_ON_ERROR),'created_at'=>time()]);
            return $orders;
        });
    }

    public function exchange(int $user, string $currency, int $cents, string $intent): array
    {
        Input::choice($currency, ['points', 'tokens']); $this->currencyEnabled($currency); $this->settings->requireModule('wallet'); Wallet::intent($intent);
        if ($cents < 100 || $cents > 100000000 || $cents % 100 !== 0) { throw new Problem('Exchange an amount in whole currency units.'); }
        return $this->db->transaction(function () use ($user, $currency, $cents, $intent): array {
            $this->wallet->lockActive($user);
            $existing = $this->existing($user, 'exchange', $currency === 'points' ? 1 : 2, $intent);
            if ($existing) {
                if ((int)$existing['amount'] !== $cents) { throw new Problem('Idempotency key was reused with different data.', 409); }
                return $existing;
            }
            $units = intdiv($cents, 100) * (int)$this->settings->get($currency . '_rate');
            $row = self::row($user, 'exchange', $currency === 'points' ? 1 : 2, 'Exchange: ' . $currency, $cents, 'balance', $intent);
            $row['status'] = 'paid'; $row['paid_at'] = time(); $row['fulfillment'] = 'fulfilled';
            $row['metadata'] = json_encode(['currency' => $currency, 'units' => $units], JSON_THROW_ON_ERROR);
            $id = $this->db->insert('cy_orders', $row);
            $this->wallet->adjust($user, 'balance', -$cents, 'order:' . $id . ':exchange-out', 'Exchange #' . $row['order_no']);
            $this->wallet->adjust($user, $currency, $units, 'order:' . $id . ':exchange-in', 'Exchange #' . $row['order_no']);
            return $this->db->one('SELECT * FROM cy_orders WHERE id=?', [$id]);
        });
    }
    public function requestRefund(int $user, int $id, string $reason): void
    {
        $reason = Input::required($reason, 1000);
        $this->db->transaction(function () use ($user, $id, $reason): void {
            $order = $this->db->one('SELECT * FROM cy_orders WHERE id=? AND user_id=?' . $this->db->lock(), [$id, $user]);
            if (!$order || $order['status'] !== 'paid' || !in_array($order['kind'],['content','circle'],true)) { throw new Problem('This order cannot use the local refund workflow.'); }
            $meta = json_decode($order['metadata'], true); $meta['refund_reason'] = $reason;
            $this->db->update('cy_orders', $id, ['status' => 'refund_requested', 'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        });
    }
    public function refund(int $actor, int $id, bool $approve): void
    {
        $this->db->transaction(function () use ($actor, $id, $approve): void {
            $order = $this->db->one('SELECT * FROM cy_orders WHERE id=?' . $this->db->lock(), [$id]);
            if (!$order || $order['status'] !== 'refund_requested' || !in_array($order['kind'],['content','circle'],true)) { throw new Problem('Refund request is no longer pending.'); }
            if ((int)$order['sale_id']>0) { throw new Problem('Use the service center for checkout refunds. Original-route payments must not be refunded into a wallet.',409); }
            if ($approve) {
                $meta=json_decode($order['metadata'],true,16,JSON_THROW_ON_ERROR);
                if (!empty($meta['invitation_id'])) {
                    $invite=$this->db->one('SELECT * FROM cy_invitations WHERE id=?'.$this->db->lock(),[(int)$meta['invitation_id']]);
                    if (!$invite || (int)$invite['uses']>0) { throw new Problem('A used invitation requires manual dispute resolution; it cannot be automatically refunded.',409); }
                    $this->db->update('cy_invitations',(int)$invite['id'],['active'=>0]);
                }
                $this->creator->reverse($order);
                if ((int)$order['commission_amount'] > 0) {
                    $this->wallet->reverseCommission((int)$order['affiliate_id'], (int)$order['commission_amount'], $id, $order['order_no']);
                }
                if ((int)$order['amount'] > 0) { $this->wallet->adjust((int)$order['user_id'], $order['currency'], (int)$order['amount'], 'order:' . $id . ':refund', 'Refund #' . $order['order_no']); }
                if ($order['kind']==='content') {
                $other = $this->db->one("SELECT id FROM cy_orders WHERE user_id=? AND item_id=? AND kind='content' AND id<>? AND status IN ('paid','refund_requested') ORDER BY id LIMIT 1", [(int)$order['user_id'], (int)$order['item_id'], $id]);
                if ($other) { $this->db->execute('UPDATE cy_entitlements SET order_id=? WHERE order_id=?', [(int)$other['id'], $id]); }
                else { $this->db->execute('DELETE FROM cy_entitlements WHERE order_id=?', [$id]); }
                $this->db->execute('UPDATE cy_contents SET sold_count=CASE WHEN sold_count>0 THEN sold_count-1 ELSE 0 END WHERE id=?', [(int)$order['item_id']]);
                $meta = json_decode($order['metadata'], true);
                if (($meta['product_type'] ?? '') === 'physical' && $order['fulfillment'] === 'awaiting_shipment') {
                    $this->inventory->restore((int)$order['item_id'], (int)$order['variant_id']);
                }
                }
                $this->db->update('cy_orders', $id, ['status' => 'refunded']);
            } else { $this->db->update('cy_orders', $id, ['status' => 'paid']); }
            $this->activity->audit($actor, $approve ? 'order.refunded' : 'order.refund_rejected', (string)$id);
            $this->activity->notify((int)$order['user_id'], $approve ? 'Refund approved' : 'Refund rejected', $order['title'], 'orders');
        });
    }
    public function ship(int $actor, int $id, string $tracking): void
    {
        $tracking = Input::required($tracking, 1000);
        $this->db->transaction(function () use ($actor, $id, $tracking): void {
            $order = $this->db->one('SELECT * FROM cy_orders WHERE id=?' . $this->db->lock(), [$id]);
            if (!$order || $order['status'] !== 'paid' || !in_array($order['fulfillment'], ['awaiting_shipment','shipped'], true)) { throw new Problem('Order is not awaiting shipment.'); }
            $this->db->update('cy_orders', $id, ['fulfillment' => 'shipped', 'delivery_cipher' => $this->crypto->seal($tracking)]);
            $this->activity->audit($actor, 'order.shipped', (string)$id);
            $this->activity->notify((int)$order['user_id'], 'Your order has shipped', $tracking, 'orders');
        });
    }
    public function delivery(array $order): string { return in_array($order['status'],['paid','refund_requested'],true) && $order['delivery_cipher'] !== '' ? $this->crypto->open($order['delivery_cipher']) : ''; }
    public function address(array $order): array { return $order['address_snapshot'] !== '' ? json_decode($this->crypto->open($order['address_snapshot']), true) : []; }

    public function vouchers(int $actor, string $kind, int $amount, int $count, int $expires, string $label): array
    {
        Input::choice($kind, ['balance', 'points', 'tokens', 'membership']);
        Input::integer($amount, 1, $kind === 'membership' ? 3650 : 100000000); Input::integer($count, 1, 100);
        if ($expires <= time()) { throw new Problem('Expiry must be in the future.'); }
        return $this->db->transaction(function () use ($actor, $kind, $amount, $count, $expires, $label): array {
            $codes = [];
            for ($i = 0; $i < $count; $i++) {
                $code = strtoupper(bin2hex(random_bytes(12)));
                $this->db->insert('cy_vouchers', ['code_hash' => $this->crypto->digest($code), 'label' => Input::text($label, 100), 'kind' => $kind, 'amount' => $amount, 'expires_at' => $expires, 'created_at' => time()]);
                $codes[] = implode('-', str_split($code, 6));
            }
            $this->activity->audit($actor, 'voucher.batch_created', $kind . ':' . $count);
            return $codes;
        });
    }
    public function redeem(int $user, string $code): void
    {
        $this->settings->requireModule('vouchers');
        $code = strtoupper(str_replace(['-', ' '], '', Input::required($code, 64)));
        $this->activity->rate('redeem', (string)$user, 10, 3600);
        $this->db->transaction(function () use ($user, $code): void {
            $this->wallet->lockActive($user);
            $row = $this->db->one('SELECT * FROM cy_vouchers WHERE code_hash=?' . $this->db->lock(), [$this->crypto->digest($code)]);
            if (!$row || (int)$row['redeemed_by'] !== 0 || (int)$row['expires_at'] < time()) { throw new Problem('Code is invalid, expired or already redeemed.'); }
            $this->settings->requireModule($row['kind'] === 'balance' ? 'wallet' : $row['kind']);
            if ($row['kind'] === 'membership') {
                $account = $this->db->one('SELECT vip_until FROM cy_users WHERE id=?' . $this->db->lock(), [$user]);
                $this->membership->grant($user, 1, (int)$row['amount']);
            } else { $this->wallet->adjust($user, $row['kind'], (int)$row['amount'], 'voucher:' . $row['id'], 'Redeem code #' . $row['id']); }
            $this->db->update('cy_vouchers', (int)$row['id'], ['redeemed_by' => $user, 'redeemed_at' => time()]);
            $this->activity->notify($user, 'Code redeemed', $row['label'], 'wallet');
        });
    }
    public function withdraw(int $user, int $amount, string $account, string $intent, string $currency='commission'): void
    {
        $this->settings->requireModule('withdrawals'); Wallet::intent($intent); Input::choice($currency,['commission','earnings']);
        if ($amount < (int)$this->settings->get('withdraw_min') || $amount > 100000000) { throw new Problem('Invalid withdrawal amount.'); }
        $account = Input::required($account, 1000);
        $this->db->transaction(function () use ($user, $amount, $account, $intent, $currency): void {
            $this->wallet->lockActive($user);
            $key = $user . ':' . $intent;
            $existing = $this->db->one('SELECT * FROM cy_withdrawals WHERE request_key=?', [$key]);
            if ($existing) {
                if ($existing['currency'] !== $currency || (int)$existing['amount'] !== $amount || $this->crypto->open($existing['account_cipher']) !== $account) { throw new Problem('Idempotency key was reused with different data.', 409); }
                return;
            }
            $id = $this->db->insert('cy_withdrawals', ['user_id' => $user, 'currency' => $currency, 'amount' => $amount, 'account_cipher' => $this->crypto->seal($account), 'status' => 'pending', 'note' => '', 'request_key' => $key, 'created_at' => time(), 'updated_at' => time()]);
            $this->wallet->adjust($user, $currency, -$amount, 'withdraw:' . $id . ':hold', 'Withdrawal reserved #' . $id);
        });
    }
    public function reviewWithdrawal(int $actor, int $id, bool $paid, string $note): void
    {
        $note = Input::required($note, 1000);
        $this->db->transaction(function () use ($actor, $id, $paid, $note): void {
            $row = $this->db->one('SELECT * FROM cy_withdrawals WHERE id=?' . $this->db->lock(), [$id]);
            if (!$row || $row['status'] !== 'pending') { throw new Problem('Withdrawal is no longer pending.'); }
            if ($paid) { $account=$this->wallet->lockActive((int)$row['user_id']); if((int)$account[$row['currency']]<0) {throw new Problem('Payout is blocked by an earnings reversal. Reject the held request or reconcile the debt before marking it paid.',409);} }
            if (!$paid) { $this->wallet->adjust((int)$row['user_id'], $row['currency'], (int)$row['amount'], 'withdraw:' . $id . ':release', 'Withdrawal rejected #' . $id); }
            $this->db->update('cy_withdrawals', $id, ['status' => $paid ? 'paid' : 'rejected', 'note' => $note, 'updated_at' => time()]);
            $this->activity->audit($actor, $paid ? 'withdrawal.manual_paid' : 'withdrawal.rejected', (string)$id);
            $this->activity->notify((int)$row['user_id'], $paid ? 'Withdrawal marked paid' : 'Withdrawal rejected', $note, 'wallet');
        });
    }
    public function importStock(int $actor, int $content, string $codes, int $variant=0): int
    { return $this->inventory->import($actor,$content,$codes,$variant); }

    public function receive(int $user, int $id): void
    {
        $this->db->transaction(function() use($user,$id): void {
            $order=$this->db->one('SELECT * FROM cy_orders WHERE id=? AND user_id=?'.$this->db->lock(),[$id,$user]);
            if (!$order || $order['status']!=='paid' || !in_array($order['fulfillment'],['shipped','received'],true)) { throw new Problem('This order cannot be marked received.'); }
            if ($order['fulfillment']==='received') { return; }
            $this->db->update('cy_orders',$id,['fulfillment'=>'received','received_at'=>time()]);$this->activity->audit($user,'order.received',(string)$id);
        });
    }
}
