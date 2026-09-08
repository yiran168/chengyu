<?php
declare(strict_types=1);
namespace Chengyu\Payments;
use Chengyu\Core\{HttpClient,Input,Problem};
use Chengyu\Services\Payment;
final class PayPal implements Gateway
{
    private array $c;private HttpClient $http;private string $token='';
    public function __construct(array $config,HttpClient $http){$this->c=$config;$this->http=$http;}
    private function origin(): string{return !empty($this->c['paypal_sandbox'])?'https://api-m.sandbox.paypal.com':'https://api-m.paypal.com';}
    private function token(): string
    {
        if($this->token===''){$r=$this->http->json('POST',$this->origin().'/v1/oauth2/token',['grant_type'=>'client_credentials'],['Authorization'=>'Basic '.base64_encode($this->c['paypal_id'].':'.$this->c['paypal_secret'])]);$this->token=Input::required($r['access_token']??'',4096);}
        return $this->token;
    }
    public function call(string $method,string $path,array $data=[],string $key=''): array
    {
        $h=['Authorization'=>'Bearer '.$this->token(),'Prefer'=>'return=representation'];if($key!==''){$h['PayPal-Request-Id']=$key;}
        return $this->http->jsonBody($method,$this->origin().$path,$data,$h);
    }
    public function create(array $sale): array
    {
        $r=$this->call('POST','/v2/checkout/orders',[
            'intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>$sale['sale_no'],'custom_id'=>$sale['sale_no'],'invoice_id'=>$sale['sale_no'],
            'payee'=>['merchant_id'=>$this->c['paypal_merchant']],
            'amount'=>['currency_code'=>$sale['currency'],'value'=>Payment::decimal((int)$sale['amount'])]]],
            'payment_source'=>['paypal'=>['experience_context'=>['payment_method_preference'=>'IMMEDIATE_PAYMENT_REQUIRED','user_action'=>'PAY_NOW','shipping_preference'=>'NO_SHIPPING','return_url'=>$sale['return_url'],'cancel_url'=>$sale['return_url']]]],
        ],'create-'.$sale['sale_no']);
        $url='';foreach(($r['links']??[]) as $link){if(in_array($link['rel']??'',['approve','payer-action'],true)){$url=(string)$link['href'];}}
        return ['id'=>Tools::identifier($r['id']??''),'url'=>Tools::url($url,['www.paypal.com','www.sandbox.paypal.com','paypal.com','sandbox.paypal.com'])];
    }
    public function query(array $sale): array
    {
        $id=Tools::identifier($sale['remote_id']);$r=$this->call('GET','/v2/checkout/orders/'.$id);
        if(($r['id']??'')!==$id){throw new Problem('PayPal order binding mismatch.',409);}
        if(($r['status']??'')==='APPROVED'){
            $this->validateUnit($sale,$r);
            $r=$this->call('POST','/v2/checkout/orders/'.$id.'/capture',[],'capture-'.$sale['sale_no']);
        }
        if(($r['status']??'')!=='COMPLETED'){return ['state'=>($r['status']??'')==='VOIDED'?'closed':'pending'];}
        $unit=$this->validateUnit($sale,$r);$captures=$unit['payments']['captures']??[];
        if(count($captures)!==1||($captures[0]['status']??'')!=='COMPLETED'){return ['state'=>'pending'];}$c=$captures[0];
        return Tools::receipt($sale,(string)($c['id']??''),Input::cents($c['amount']['value']??'',true),(string)($c['amount']['currency_code']??''),(string)$unit['reference_id']);
    }
    private function validateUnit(array $sale,array $r): array
    {
        $units=$r['purchase_units']??[];
        if(count($units)!==1||($units[0]['reference_id']??'')!==$sale['sale_no']||($units[0]['payee']['merchant_id']??'')!==$this->c['paypal_merchant']||Input::cents($units[0]['amount']['value']??'',true)!==(int)$sale['amount']||($units[0]['amount']['currency_code']??'')!==$sale['currency']){throw new Problem('PayPal merchant, amount or reference mismatch.',409);}return $units[0];
    }
    private function refunded(array $sale,array $refund,array $r): array
    {
        if(isset($r['invoice_id'])&&$r['invoice_id']!==$refund['refund_no']){throw new Problem('PayPal refund reference mismatch.',409);}
        $status=($r['status']??'')==='COMPLETED'?'succeeded':(in_array($r['status']??'',['FAILED','CANCELLED'],true)?'failed':'pending');
        return Tools::refundResult($sale,$refund,$r,(string)($r['id']??''),$status,Input::cents($r['amount']['value']??'',true),(string)($r['amount']['currency_code']??''));
    }
    public function refund(array $sale,array $refund): array
    {
        $r=$this->call('POST','/v2/payments/captures/'.Tools::identifier($sale['trade_no']).'/refund',['amount'=>['currency_code'=>$sale['currency'],'value'=>Payment::decimal((int)$refund['amount'])],'invoice_id'=>$refund['refund_no']],$refund['refund_no']);
        return $this->refunded($sale,$refund,$r);
    }
    public function queryRefund(array $sale,array $refund): array
    {
        if($refund['remote_id']!==''){return $this->refunded($sale,$refund,$this->call('GET','/v2/payments/refunds/'.Tools::identifier($refund['remote_id'])));}
        if((int)$refund['attempted_at']>0&&time()-(int)$refund['attempted_at']>18000){throw new Problem('Unknown PayPal refund outcome. Check the provider before retrying beyond five hours.',409);}
        return $this->refund($sale,$refund);
    }
    public function notification(string $raw,array $headers): string
    {
        $event=HttpClient::object($raw);$data=['webhook_id'=>$this->c['paypal_webhook_id'],'webhook_event'=>$event];
        foreach(['auth_algo'=>'paypal-auth-algo','cert_url'=>'paypal-cert-url','transmission_id'=>'paypal-transmission-id','transmission_sig'=>'paypal-transmission-sig','transmission_time'=>'paypal-transmission-time'] as $k=>$h){$data[$k]=Input::required($headers[$h]??'',4096);}
        if($data['webhook_id']===''){throw new Problem('PayPal webhook ID is not configured.',503);}
        $r=$this->call('POST','/v1/notifications/verify-webhook-signature',$data);
        if(($r['verification_status']??'')!=='SUCCESS'){throw new Problem('PayPal webhook signature mismatch.',403);}
        if(($event['event_type']??'')==='CHECKOUT.ORDER.APPROVED'){return Tools::identifier($event['resource']['id']??'');}
        if(($event['event_type']??'')==='PAYMENT.CAPTURE.COMPLETED'){return Tools::identifier($event['resource']['supplementary_data']['related_ids']['order_id']??'');}
        return '';
    }
    public function createPayout(array $payout,string $email): array
    {
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)){throw new Problem('A verified PayPal recipient email is required.');}
        return $this->call('POST','/v1/payments/payouts',['sender_batch_header'=>['sender_batch_id'=>$payout['batch_key'],'email_subject'=>'Your payout'],
            'items'=>[['recipient_type'=>'EMAIL','receiver'=>$email,'sender_item_id'=>$payout['batch_key'],'amount'=>['value'=>Payment::decimal((int)$payout['amount']),'currency'=>$payout['currency']]]]],$payout['batch_key']);
    }
    public function queryPayout(string $id): array{return $this->call('GET','/v1/payments/payouts/'.Tools::identifier($id).'?page_size=100');}
}
