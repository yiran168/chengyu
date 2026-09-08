<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem,Security};
use Chengyu\Payments\Tools;
/** Financial boundary: quote -> reserve -> provider -> verified receipt -> atomic fulfilment. */
final class Checkout
{
    private App $a;
    public function __construct(App $app){$this->a=$app;}
    public static function lines(array $lines): array
    {
        if(!$lines||count($lines)>20){throw new Problem('Choose 1-20 checkout lines.');}$out=[];$total=0;
        foreach($lines as $line){if(!is_array($line)){throw new Problem('Invalid checkout line.');}
            $kind=Input::choice($line['kind']??'content',['content','membership']);$id=Input::integer($line['item_id']??0,1);$v=Input::integer($line['variant_id']??0);$q=Input::integer($line['quantity']??1,1,10);
            if($kind==='membership'&&($v||$q!==1||count($lines)!==1)){throw new Problem('Purchase a membership separately.');}
            $key=$kind.':'.sprintf('%012d:%012d',$id,$v);if(isset($out[$key])){throw new Problem('Duplicate checkout line.');}$out[$key]=['kind'=>$kind,'item_id'=>$id,'variant_id'=>$v,'quantity'=>$q];$total+=$q;
        }if($total>100){throw new Problem('Checkout quantity limit reached.');}ksort($out,SORT_STRING);return array_values($out);
    }
    public static function address(array $input): array
    {
        $out=[];foreach(['recipient'=>100,'phone'=>40,'address'=>1000,'region'=>40] as $key=>$max){$out[$key]=Input::text($input[$key]??'',$max);}return $out;
    }
    private function compute(?array $user,array $lines,array $address,string $coupon,bool $lock=false): array
    {
        $a=$this->a;$units=[];$physical=false;$hasMembership=false;
        foreach($lines as $line){$kind=$line['kind'];$item=$a->db->one('SELECT * FROM '.($kind==='membership'?'cy_plans':'cy_contents').' WHERE id=?'.($lock?$a->db->lock():''),[$line['item_id']]);
            if(!$item){throw new Problem('Item not found.',404);}if($item['price_currency']!=='balance'){throw new Problem('Secure checkout uses the store money currency. Use the wallet purchase flow for points and tokens.');}
            $selection=['price'=>(int)$item['price_amount'],'variant'=>null,'code'=>null];
            if($kind==='membership'){
                $a->settings->requireModule('membership');if(!$user||(int)$user['is_guest']){throw new Problem('Sign in before purchasing a membership.',403);}if(!(int)$item['active']){throw new Problem('Membership plan is unavailable.');}
                if($a->membership->lifetimeTier((int)$user['id'])>=(int)$item['tier']){throw new Problem('You already have lifetime access at this tier or higher.',409);}
                if($lock&&(int)$a->db->value("SELECT COUNT(*) FROM cy_orders WHERE user_id=? AND kind='membership' AND status='pending'",[(int)$user['id']])){throw new Problem('Resolve the pending membership payment before creating another.',409);}
                $hasMembership=true;$title=$item['name'];$pricing=Pricing::base($selection['price']);
            }else{
                if($item['status']!=='published'||(int)$item['publish_at']>time()||!in_array($item['kind'],['article','thread','product'],true)){throw new Problem('Item is unavailable.',404);}
                $a->settings->requireModule(['product'=>'shop','article'=>'articles','thread'=>'forum'][$item['kind']]);
                if(!$a->categories->allowed((int)$item['category_id'],$user)){throw new Problem('Category access is required before purchase.',403);}
                $product=$item['kind']==='product';$isPhysical=$product&&$item['product_type']==='physical';$physical=$physical||$isPhysical;
                if(!$product&&$item['access_level']!=='paid'){throw new Problem('This content is not sold separately.');}
                if(!$product||$item['product_type']==='digital'){
                    if($line['quantity']!==1||$line['variant_id']){throw new Problem('Digital access is a single non-variant entitlement.');}
                    if($user&&$a->db->one("SELECT id FROM cy_orders WHERE user_id=? AND item_id=? AND kind='content' AND status IN ('pending','paid','refund_requested') AND (access_until=0 OR access_until>?)",[(int)$user['id'],(int)$item['id'],time()])){throw new Problem('An active or pending purchase already exists. Open your orders.',409);}
                }
                if($product){$selection=$a->inventory->selection($item,$line['variant_id']);$stock=(int)($selection['variant']['inventory']??$item['inventory']);if($stock>=0&&$stock<$line['quantity']){throw new Problem('Not enough stock for this quantity.');}}
                elseif($line['variant_id']){throw new Problem('Invalid variant.');}
                if($product&&$item['product_type']==='invitation'){$a->settings->requireModule('invitation_products');}
                $title=$item['title'].($selection['variant']?' / '.$selection['variant']['name']:'');$pricing=$a->pricing->price($item,$user,$selection['price']);
            }
            for($i=0;$i<$line['quantity'];$i++){$units[]=['kind'=>$kind,'item'=>$item,'variant_id'=>$line['variant_id'],'title'=>Input::clip($title,250),'pricing'=>$pricing,'amount'=>(int)$pricing['amount'],'shipping'=>0];}
        }
        if($physical){foreach(['recipient','phone','address'] as $k){if($address[$k]===''){throw new Problem('Complete the recipient, phone and shipping address.');}}}
        $promotions=$hasMembership?['amounts'=>array_column($units,'amount'),'applied'=>[]]:$a->rulebooks->promotions($units,$a->membership->tier($user));
        foreach($units as $i=>&$unit){$unit['amount']=$promotions['amounts'][$i];$unit['pricing']['amount']=$unit['amount'];}unset($unit);
        if($coupon!==''){
            if(!$user||(int)$user['is_guest']){throw new Problem('Sign in to use a coupon.',403);}if(count($units)!==1){throw new Problem('Coupons apply to a single-unit checkout.');}
            $u=&$units[0];$u['pricing']=$a->pricing->coupon($user,$u['item'],$u['kind'],$u['pricing'],$coupon,$lock);$u['amount']=(int)$u['pricing']['amount'];unset($u);
        }
        $shipping=$hasMembership?['fees'=>[0],'amount'=>0,'details'=>[]]:$a->rulebooks->shipping($units,$address['region']);
        $subtotal=0;foreach($units as $i=>&$unit){$subtotal+=$unit['amount'];$unit['shipping']=$shipping['fees'][$i];}unset($unit);
        $total=$subtotal+$shipping['amount'];if($total>1000000000){throw new Problem('Checkout amount exceeds the order limit.');}
        return ['units'=>$units,'subtotal'=>$subtotal,'shipping'=>$shipping['amount'],'total'=>$total,'currency'=>(string)$a->settings->get('store_currency'),'promotions'=>$promotions['applied'],'shipping_details'=>$shipping['details'],'physical'=>$physical];
    }
    public function quote(?array $user,array $lines,array $address=[],string $coupon=''): array
    {
        $this->a->settings->requireModule('checkout');$q=$this->compute($user,self::lines($lines),self::address($address),strtoupper(Input::text($coupon,64)));return self::publicQuote($q);
    }
    public static function publicQuote(array $q): array
    {
        return ['total'=>$q['total'],'subtotal'=>$q['subtotal'],'shipping'=>$q['shipping'],'currency'=>$q['currency'],'promotions'=>$q['promotions'],'shipping_details'=>$q['shipping_details'],'physical'=>$q['physical'],
            'items'=>array_map(static function($u){return ['kind'=>$u['kind'],'item_id'=>(int)$u['item']['id'],'variant_id'=>$u['variant_id'],'title'=>$u['title'],'amount'=>$u['amount'],'shipping'=>$u['shipping']];},$q['units'])];
    }
    public function create(int $user,array $lines,array $address,string $coupon,string $gateway,string $intent,int $ceiling,string $currency): array
    {
        $a=$this->a;$a->settings->requireModule('checkout');Wallet::intent($intent);Input::integer($ceiling,0,1000000000);
        $lines=self::lines($lines);$address=self::address($address);$coupon=strtoupper(Input::text($coupon,64));Input::choice($gateway,array_merge(['wallet'],array_keys(DirectPayments::NAMES)));
        $fingerprint=hash('sha256',json_encode([$lines,$address,$coupon,$gateway,$currency],JSON_THROW_ON_ERROR));
        return $a->db->transaction(function()use($a,$user,$lines,$address,$coupon,$gateway,$intent,$ceiling,$currency,$fingerprint):array{
            $a->settings->assertMoneyCurrency();
            $account=$a->db->one('SELECT * FROM cy_users WHERE id=?'.$a->db->lock(),[$user]);if(!$account||$account['status']!=='active'){throw new Problem('Account unavailable.',403);}
            $old=$a->db->one('SELECT * FROM cy_sales WHERE request_key=?',[$user.':'.$intent]);if($old){if(!hash_equals($old['fingerprint'],$fingerprint)){throw new Problem('Idempotency key was reused with different data.',409);}return $old;}
            if((int)$a->db->value("SELECT COUNT(*) FROM cy_sales WHERE user_id=? AND status='pending'",[$user])>=10){throw new Problem('Resolve pending payments before creating more orders.',429);}
            $q=$this->compute($account,$lines,$address,$coupon,true);if($q['currency']!==$currency||$q['total']>$ceiling){throw new Problem('The quote changed. Review and confirm again; nothing was charged.',409);}
            if($gateway==='paypal'&&$q['physical']&&$q['total']>0){throw new Problem('PayPal checkout currently supports digital items only. Choose another channel for physical goods.');}
            if($gateway==='wallet'&&(int)$account['is_guest']&&$q['total']>0){throw new Problem('Guest purchases require a direct payment channel.',403);}
            $config=[];if($gateway==='wallet'){$a->settings->requireModule('wallet');}elseif($q['total']>0){
                if(strpos($a->config['url'],'https://')!==0){throw new Problem('Configure the canonical HTTPS site URL before enabling direct checkout.',503);}$config=$a->directPayments->snapshot($gateway,$currency);
            }
            $no=Commerce::orderNumber();$minutes=max(35,(int)$a->settings->get('checkout_minutes'));$meta=['quote'=>self::publicQuote($q),'payer_ip'=>filter_var(Security::ip(),FILTER_VALIDATE_IP)?Security::ip():'127.0.0.1'];
            $id=$a->db->insert('cy_sales',['sale_no'=>$no,'user_id'=>$user,'request_key'=>$user.':'.$intent,'fingerprint'=>$fingerprint,'amount'=>$q['total'],'currency'=>$currency,'gateway'=>$q['total']===0?'wallet':$gateway,'status'=>'pending','checkout_url'=>'','config_cipher'=>$a->crypto->seal(json_encode($config,JSON_THROW_ON_ERROR)),
                'metadata'=>json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>time(),'expires_at'=>time()+$minutes*60]);
            foreach($q['units'] as $index=>$unit){
                $item=$unit['item'];$kind=$unit['kind'];$physical=$kind==='content'&&$item['kind']==='product'&&$item['product_type']==='physical';
                $o=Commerce::row($user,$kind,(int)$item['id'],$unit['title'],$unit['amount']+$unit['shipping'],'balance',substr(hash('sha256',$no.':'.$index),0,32));
                $m=['pricing'=>$unit['pricing'],'checkout_index'=>$index,'stock_reserved'=>false];
                if($kind==='membership'){$m['days']=(int)$item['days'];$m['tier']=(int)$item['tier'];}
                else{$m+=['content_kind'=>$item['kind'],'product_type'=>$item['product_type'],'access_days'=>(int)$item['access_days'],'invite_uses'=>(int)$item['invite_uses'],'invite_days'=>(int)$item['invite_days']];}
                $o+=['sale_id'=>$id,'shipping_amount'=>$unit['shipping'],'money_currency'=>$currency,'variant_id'=>$unit['variant_id'],'coupon_id'=>(int)($unit['pricing']['coupon_id']??0)];
                $o['gateway']=$q['total']===0?'wallet':$gateway;$o['expires_at']=time()+$minutes*60;$o['fulfillment']='reserved';$o['fingerprint']=$fingerprint;
                if($physical){$o['address_snapshot']=$a->crypto->seal(json_encode($address,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
                $selection=null;if($kind==='content'&&$item['kind']==='product'){
                    // Re-read each unit: code stock and finite quantities change inside this transaction.
                    $item=$a->db->one('SELECT * FROM cy_contents WHERE id=?'.$a->db->lock(),[(int)$item['id']]);$selection=$a->inventory->selection($item,$unit['variant_id']);$m['stock_reserved']=true;
                    $m['variant']=$selection['variant']?['sku'=>$selection['variant']['sku'],'name'=>$selection['variant']['name']]:null;
                }
                if($selection&&$selection['code']){$o['delivery_cipher']=$a->crypto->seal($a->crypto->open($selection['code']['secret_cipher'])."\n\n".$item['delivery_text']);}
                elseif($kind==='content'&&!$physical&&$item['delivery_text']!==''){$o['delivery_cipher']=$a->crypto->seal($item['delivery_text']);}
                $o['metadata']=json_encode($m,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$oid=$a->db->insert('cy_orders',$o);if($selection){$a->inventory->consume($item,$selection,$oid);}
            }
            $sale=$a->db->one('SELECT * FROM cy_sales WHERE id=?',[$id]);
            if($sale['gateway']==='wallet'){if($q['total']>0){$a->wallet->adjust($user,'balance',-$q['total'],'sale:'.$id.':purchase','Checkout #'.$no);}$this->settle($id,Tools::receipt($sale,'wallet_'.$no,$q['total'],$currency,$no));}
            $a->activity->audit($user,'checkout.created',$no);return $a->db->one('SELECT * FROM cy_sales WHERE id=?',[$id]);
        });
    }
    public function owned(int $id,int $user): array
    {
        $s=$this->a->db->one('SELECT * FROM cy_sales WHERE id=? AND user_id=?',[$id,$user]);if(!$s){throw new Problem('Order not found.',404);}return $s;
    }
    public function config(array $sale): array{return json_decode($this->a->crypto->open($sale['config_cipher']),true,32,JSON_THROW_ON_ERROR);}
    public function providerSale(array $sale): array
    {
        $meta=json_decode($sale['metadata'],true,32,JSON_THROW_ON_ERROR);$sale['payer_ip']=$meta['payer_ip'];$sale['return_url']=rtrim($this->a->config['url'],'/').'/index.php?r=receipt&id='.$sale['id'];
        $sale['notify_url']=rtrim($this->a->config['url'],'/').'/notify-direct.php?gateway='.$sale['gateway'].'&sale='.$sale['sale_no'];return $sale;
    }
    public function start(int $id,int $user): array
    {
        $a=$this->a;$sale=$this->owned($id,$user);if($sale['status']!=='pending'||$sale['gateway']==='wallet'){return $sale;}
        if($sale['checkout_url']!==''){return $sale;}
        if((int)$sale['attempted_at']>0&&time()-(int)$sale['attempted_at']>($sale['gateway']==='paypal'?18000:82800)) {throw new Problem('The remote checkout outcome needs merchant reconciliation; creating a second charge is blocked.',409);}
        if((int)$sale['expires_at']<=time()){throw new Problem('This checkout expired. Reconcile it before creating another.',409);}
        $a->db->transaction(function()use($a,$id):void{$s=$a->db->one('SELECT * FROM cy_sales WHERE id=?'.$a->db->lock(),[$id]);if(!(int)$s['attempted_at']){$a->db->update('cy_sales',$id,['attempted_at'=>time()]);}});
        $sale=$this->owned($id,$user);
        try{$r=$a->directPayments->driver($sale['gateway'],$this->config($sale))->create($this->providerSale($sale));
            $a->db->transaction(function()use($a,$id,$r):void{$current=$a->db->one('SELECT * FROM cy_sales WHERE id=?'.$a->db->lock(),[$id]);if($current['remote_id']!==''&&$current['remote_id']!==$r['id']){throw new Problem('Conflicting remote checkout identifiers. Merchant review is required.',409);}$a->db->update('cy_sales',$id,['remote_id'=>$r['id'],'checkout_url'=>$r['url'],'last_error'=>'']);});
        }catch(\Throwable $e){$this->error($id,'Checkout request is unconfirmed. Reconcile before retrying.');throw $e;}
        return $this->owned($id,$user);
    }
    public function reconcile(int $id): array
    {
        $a=$this->a;$sale=$a->db->one('SELECT * FROM cy_sales WHERE id=?',[$id]);if(!$sale){throw new Problem('Order not found.',404);}if(in_array($sale['status'],['paid','closed'],true)){return $sale;}
        if($sale['gateway']==='wallet'){throw new Problem('Wallet checkout needs local ledger review.',409);}
        if($sale['remote_id']===''&&in_array($sale['gateway'],['stripe','paypal'],true)){$this->error($id,'No confirmed remote ID. Reopen the same checkout within the safe retry window.');throw new Problem('No remote order identifier was confirmed. Retry opening this same checkout within its safe retry window.',409);}
        $a->db->update('cy_sales',$id,['last_check_at'=>time()]);
        try{$r=$a->directPayments->driver($sale['gateway'],$this->config($sale))->query($this->providerSale($sale));
            if(($r['state']??'')==='paid'){$this->settle($id,$r);}elseif(($r['state']??'')==='closed'){$this->releaseClosed($id,true);}else{$a->db->update('cy_sales',$id,['last_error'=>'']);}
        }catch(\Throwable $e){$this->error($id,'Payment query did not produce a verified final result.');throw $e;}
        return $a->db->one('SELECT * FROM cy_sales WHERE id=?',[$id]);
    }
    private function error(int $id,string $message): void{$this->a->db->update('cy_sales',$id,['last_error'=>$message,'last_check_at'=>time()]);}
    /** A browser return never calls this directly. All receipt fields are checked again. */
    public function settle(int $id,array $receipt): void
    {
        $a=$this->a;$a->db->transaction(function()use($a,$id,$receipt):void{
            $sale=$a->db->one('SELECT * FROM cy_sales WHERE id=?'.$a->db->lock(),[$id]);if(!$sale){throw new Problem('Unknown payment order.');}
            if(($receipt['state']??'')!=='paid'){throw new Problem('A verified paid receipt is required.',409);}
            $checked=Tools::receipt($sale,(string)($receipt['trade']??''),Input::integer($receipt['amount']??-1,0,1000000000),(string)($receipt['currency']??''),(string)($receipt['order']??''));
            if($sale['status']==='paid'){if($sale['trade_no']!==$checked['trade']){throw new Problem('Conflicting paid receipt.',409);}return;}
            if($sale['status']==='closed'){throw new Problem('A closed checkout received an inconsistent payment. Merchant review is required.',409);}
            $key=$sale['gateway'].':'.hash('sha256',$checked['trade']);$old=$a->db->one('SELECT * FROM cy_receipts WHERE receipt_key=?',[$key]);if($old&&(int)$old['sale_id']!==$id){throw new Problem('This payment receipt already belongs to another checkout.',409);}
            if(!$old){$a->db->insert('cy_receipts',['receipt_key'=>$key,'sale_id'=>$id,'created_at'=>time()]);}
            $orders=$a->db->all('SELECT * FROM cy_orders WHERE sale_id=? ORDER BY id'.$a->db->lock(),[$id]);if(!$orders||array_sum(array_column($orders,'amount'))!==(int)$sale['amount']){throw new Problem('Order total integrity check failed.',409);}
            foreach($orders as $o){if($o['status']!=='pending'){throw new Problem('Reserved order state is inconsistent.',409);}$m=json_decode($o['metadata'],true,32,JSON_THROW_ON_ERROR);$physical=($m['product_type']??'')==='physical';
                $a->db->update('cy_orders',(int)$o['id'],['status'=>'paid','paid_at'=>time(),'access_until'=>!empty($m['access_days'])?time()+(int)$m['access_days']*86400:0,'fulfillment'=>$physical?'awaiting_shipment':'fulfilled']);
                $a->commerce->fulfill((int)$o['id'],!empty($m['stock_reserved']));
            }
            $a->db->update('cy_sales',$id,['status'=>'paid','paid_at'=>time(),'trade_no'=>$checked['trade'],'last_error'=>'']);$a->activity->audit(0,'checkout.paid',$sale['sale_no']);
        });
    }
    /** Inventory is released only for never-sent requests or a verified provider closure. */
    private function releaseClosed(int $id,bool $providerConfirmed): void
    {
        $a=$this->a;$a->db->transaction(function()use($a,$id,$providerConfirmed):void{
            $s=$a->db->one('SELECT * FROM cy_sales WHERE id=?'.$a->db->lock(),[$id]);if($s['status']==='closed'){return;}if($s['status']!=='pending'||(!$providerConfirmed&&(int)$s['attempted_at']>0)){throw new Problem('Payment state must be reconciled before releasing stock.',409);}
            foreach($a->db->all('SELECT * FROM cy_orders WHERE sale_id=? ORDER BY item_id,id'.$a->db->lock(),[$id]) as $o){$m=json_decode($o['metadata'],true,32,JSON_THROW_ON_ERROR);
                if(!empty($m['stock_reserved'])){$a->db->one('SELECT id FROM cy_contents WHERE id=?'.$a->db->lock(),[(int)$o['item_id']]);if(($m['product_type']??'')==='code'){$a->db->execute('UPDATE cy_stock_codes SET order_id=NULL WHERE order_id=?',[(int)$o['id']]);$a->inventory->synchronize((int)$o['item_id']);}else{$a->inventory->restore((int)$o['item_id'],(int)$o['variant_id']);}}
                if((int)$o['coupon_id']>0){$a->db->execute('UPDATE cy_coupons SET uses=CASE WHEN uses>0 THEN uses-1 ELSE 0 END WHERE id=?',[(int)$o['coupon_id']]);}
                $a->db->update('cy_orders',(int)$o['id'],['status'=>'closed','fulfillment'=>'cancelled','delivery_cipher'=>'','coupon_id'=>0]);
            }$a->db->update('cy_sales',$id,['status'=>'closed','checkout_url'=>'','last_error'=>'']);$a->activity->audit(0,'checkout.closed',$s['sale_no']);
        });
    }
    public function cancelUnsent(int $id,int $user): void{$this->owned($id,$user);$this->releaseClosed($id,false);}
    public function webhook(string $gateway,string $raw,array $headers,string $saleNo=''): void
    {
        $a=$this->a;Input::choice($gateway,array_keys(DirectPayments::NAMES));if(strlen($raw)>262144){throw new Problem('Webhook is too large.',413);}
        $hint=$saleNo;if($gateway==='stripe'||$gateway==='paypal'){
            $event=\Chengyu\Core\HttpClient::object($raw);
            if($gateway==='stripe'){$hint=(string)($event['data']['object']['id']??'');}
            else{$hint=(string)($event['resource']['supplementary_data']['related_ids']['order_id']??$event['resource']['id']??'');}
        }
        $hint=Input::text($hint,120);$sale=$a->db->one('SELECT * FROM cy_sales WHERE gateway=? AND '.(in_array($gateway,['stripe','paypal'],true)?'remote_id':'sale_no').'=?',[$gateway,$hint]);
        if(!$sale){throw new Problem('Unknown checkout notification.',404);}
        $verified=$a->directPayments->driver($gateway,$this->config($sale))->notification($raw,$headers);
        if($verified===''){return;}if($verified!==$hint){throw new Problem('Webhook order binding mismatch.',403);}
        $this->reconcile((int)$sale['id']);
    }
}
