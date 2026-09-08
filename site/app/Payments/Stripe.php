<?php
declare(strict_types=1);
namespace Chengyu\Payments;
use Chengyu\Core\{HttpClient,Input,Problem};
final class Stripe implements Gateway
{
    private array $c;private HttpClient $http;
    public function __construct(array $config,HttpClient $http){$this->c=$config;$this->http=$http;}
    private function call(string $method,string $path,array $fields=[],string $key=''): array
    {
        $h=['Authorization'=>'Bearer '.$this->c['stripe_secret']];if($key!==''){$h['Idempotency-Key']=$key;}
        return $this->http->json($method,'https://api.stripe.com/v1/'.$path,$fields,$h);
    }
    public function create(array $sale): array
    {
        $r=$this->call('POST','checkout/sessions',[
            'mode'=>'payment','client_reference_id'=>$sale['sale_no'],
            'success_url'=>$sale['return_url'],'cancel_url'=>$sale['return_url'],
            'expires_at'=>(int)$sale['expires_at'],
            'metadata'=>['cy_sale'=>$sale['sale_no']],
            'payment_intent_data'=>['metadata'=>['cy_sale'=>$sale['sale_no']]],
            'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>strtolower($sale['currency']),'unit_amount'=>(int)$sale['amount'],'product_data'=>['name'=>'Order '.$sale['sale_no']]]]],
        ],'checkout-'.$sale['sale_no']);
        return ['id'=>Tools::identifier($r['id']??''),'url'=>Tools::url((string)($r['url']??''),['checkout.stripe.com'])];
    }
    public function query(array $sale): array
    {
        $r=$this->call('GET','checkout/sessions/'.Tools::identifier($sale['remote_id']));
        if(($r['mode']??'')!=='payment'){throw new Problem('Unexpected Stripe checkout mode.',409);}
        if(($r['id']??'')!==$sale['remote_id']||($r['client_reference_id']??'')!==$sale['sale_no']){throw new Problem('Stripe session binding mismatch.',409);}
        $live=strpos($this->c['stripe_secret'],'sk_live_')===0 || strpos($this->c['stripe_secret'],'rk_live_')===0;
        if(!array_key_exists('livemode',$r)||(bool)$r['livemode']!==$live){throw new Problem('Stripe test/live mode mismatch.',409);}
        if(($r['payment_status']??'')==='paid'){
            $pi=is_array($r['payment_intent']??null)?$r['payment_intent']['id']:($r['payment_intent']??'');
            return Tools::receipt($sale,(string)$pi,Input::integer($r['amount_total']??-1,0,100000000000),(string)($r['currency']??''),(string)$r['client_reference_id']);
        }
        return ['state'=>($r['status']??'')==='expired'?'closed':'pending'];
    }
    public function refund(array $sale,array $refund): array
    {
        $r=$this->call('POST','refunds',['payment_intent'=>Tools::identifier($sale['trade_no']),'amount'=>(int)$refund['amount'],'metadata'=>['cy_refund'=>$refund['refund_no']]],$refund['refund_no']);
        return $this->refunded($sale,$refund,$r);
    }
    private function refunded(array $sale,array $refund,array $r): array
    {
        if(($r['payment_intent']??'')!==$sale['trade_no']||($r['metadata']['cy_refund']??'')!==$refund['refund_no']){throw new Problem('Stripe refund binding mismatch.',409);}
        $state=$r['status']??'';$status=$state==='succeeded'?'succeeded':(in_array($state,['failed','canceled'],true)?'failed':'pending');
        return Tools::refundResult($sale,$refund,$r,(string)($r['id']??''),$status,Input::integer($r['amount']??-1,0,100000000000),(string)($r['currency']??''));
    }
    public function queryRefund(array $sale,array $refund): array
    {
        if($refund['remote_id']!==''){return $this->refunded($sale,$refund,$this->call('GET','refunds/'.Tools::identifier($refund['remote_id'])));}
        $r=$this->call('GET','refunds',['payment_intent'=>Tools::identifier($sale['trade_no']),'limit'=>100]);
        foreach(($r['data']??[]) as $item){if(($item['metadata']['cy_refund']??'')===$refund['refund_no']){return $this->refunded($sale,$refund,$item);}}
        if((int)$refund['attempted_at']>0 && time()-(int)$refund['attempted_at']>82800){throw new Problem('Refund outcome is uncertain beyond the safe retry window. Reconcile in the provider dashboard.',409);}
        return $this->refund($sale,$refund);
    }
    public function notification(string $raw,array $headers): string
    {
        $parts=explode(',',(string)($headers['stripe-signature']??''));$times=[];$sigs=[];
        foreach($parts as $part){$p=explode('=',trim($part),2);if(count($p)!==2){continue;}if($p[0]==='t'){$times[]=$p[1];}if($p[0]==='v1'){$sigs[]=$p[1];}}
        if(count($times)!==1||!preg_match('/^[0-9]{10,12}$/D',$times[0])||abs(time()-(int)$times[0])>300||$this->c['stripe_webhook_secret']===''){throw new Problem('Invalid or stale Stripe webhook.',403);}
        $expected=hash_hmac('sha256',$times[0].'.'.$raw,$this->c['stripe_webhook_secret']);$ok=false;
        foreach($sigs as $sig){if(hash_equals($expected,$sig)){$ok=true;}}
        if(!$ok){throw new Problem('Stripe webhook signature mismatch.',403);}
        $event=HttpClient::object($raw);
        if(!in_array($event['type']??'',['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'],true)){return '';}
        return Tools::identifier($event['data']['object']['id']??'');
    }
}
