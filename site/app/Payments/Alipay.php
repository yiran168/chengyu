<?php
declare(strict_types=1);
namespace Chengyu\Payments;
use Chengyu\Core\{HttpClient,Input,Problem};
use Chengyu\Services\Payment;
final class Alipay implements Gateway
{
    private array $c;private HttpClient $http;
    public function __construct(array $config,HttpClient $http){$this->c=$config;$this->http=$http;}
    private function endpoint(): string{return !empty($this->c['alipay_direct_sandbox'])?'https://openapi-sandbox.dl.alipaydev.com/gateway.do':'https://openapi.alipay.com/gateway.do';}
    public static function canonical(array $fields): string
    {
        unset($fields['sign'],$fields['sign_type']);ksort($fields,SORT_STRING);$out=[];
        foreach($fields as $k=>$v){if(!is_scalar($v)){throw new Problem('Invalid Alipay field.');}if((string)$v!==''){$out[]=$k.'='.(string)$v;}}
        return implode('&',$out);
    }
    private function fields(string $method,array $business,array $extra=[]): array
    {
        $f=array_merge(['app_id'=>$this->c['alipay_direct_appid'],'method'=>$method,'format'=>'JSON','charset'=>'utf-8','sign_type'=>'RSA2','timestamp'=>date('Y-m-d H:i:s'),'version'=>'1.0',
            'biz_content'=>json_encode($business,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)],$extra);
        // Request signatures include sign_type; notification signatures exclude it.
        $signed=$f;unset($signed['sign']);ksort($signed,SORT_STRING);$parts=[];foreach($signed as $k=>$v){if((string)$v!==''){$parts[]=$k.'='.$v;}}
        $f['sign']=Tools::rsaSign(implode('&',$parts),$this->c['alipay_direct_private']);return $f;
    }
    private function call(string $method,array $business): array
    {
        $body=http_build_query($this->fields($method,$business),'','&',PHP_QUERY_RFC3986);
        $response=$this->http->request('POST',$this->endpoint(),$body,['Content-Type'=>'application/x-www-form-urlencoded']);
        $parts=Tools::members($response['body']);$key=str_replace('.','_',$method).'_response';
        if(!isset($parts[$key],$parts['sign'])){throw new Problem('Unsigned Alipay response was rejected.',502);}
        Tools::rsaVerify($parts[$key],(string)json_decode($parts['sign'],true),$this->c['alipay_direct_public']);
        $r=HttpClient::object($parts[$key]);if(($r['code']??'')!=='10000'){throw new Problem('Alipay did not confirm this operation. Check the merchant console.',502);}return $r;
    }
    public function create(array $sale): array
    {
        $page=($this->c['alipay_direct_mode']??'wap')==='page';
        $fields=$this->fields($page?'alipay.trade.page.pay':'alipay.trade.wap.pay',['out_trade_no'=>$sale['sale_no'],'product_code'=>$page?'FAST_INSTANT_TRADE_PAY':'QUICK_WAP_WAY','total_amount'=>Payment::decimal((int)$sale['amount']),'subject'=>'Order '.$sale['sale_no'],
            'timeout_express'=>(string)max(1,(int)ceil(((int)$sale['expires_at']-time())/60)).'m'],['return_url'=>$sale['return_url'],'notify_url'=>$sale['notify_url']]);
        return ['id'=>$sale['sale_no'],'url'=>$this->endpoint().'?'.http_build_query($fields,'','&',PHP_QUERY_RFC3986)];
    }
    public function query(array $sale): array
    {
        $r=$this->call('alipay.trade.query',['out_trade_no'=>$sale['sale_no']]);
        if(($r['out_trade_no']??'')!==$sale['sale_no']){throw new Problem('Alipay order mismatch.',409);}
        if(in_array($r['trade_status']??'',['TRADE_SUCCESS','TRADE_FINISHED'],true)){return Tools::receipt($sale,(string)($r['trade_no']??''),Input::cents($r['total_amount']??'',true),'CNY',$sale['sale_no']);}
        return ['state'=>($r['trade_status']??'')==='TRADE_CLOSED'?'closed':'pending'];
    }
    public function refund(array $sale,array $refund): array
    {
        $r=$this->call('alipay.trade.refund',['out_trade_no'=>$sale['sale_no'],'trade_no'=>$sale['trade_no'],'out_request_no'=>$refund['refund_no'],'refund_amount'=>Payment::decimal((int)$refund['amount'])]);
        if(($r['trade_no']??'')!==$sale['trade_no']||($r['out_trade_no']??'')!==$sale['sale_no']){throw new Problem('Alipay refund binding mismatch.',409);}
        // Query rather than equating API acceptance or fund_change=N with final success.
        return $this->queryRefund($sale,$refund);
    }
    public function queryRefund(array $sale,array $refund): array
    {
        $r=$this->call('alipay.trade.fastpay.refund.query',['out_trade_no'=>$sale['sale_no'],'out_request_no'=>$refund['refund_no']]);
        if(($r['out_trade_no']??'')!==$sale['sale_no']||($r['out_request_no']??'')!==$refund['refund_no']||($r['trade_no']??'')!==$sale['trade_no']){throw new Problem('Alipay refund binding mismatch.',409);}
        return Tools::refundResult($sale,$refund,$r,$refund['refund_no'],($r['refund_status']??'')==='REFUND_SUCCESS'?'succeeded':'pending',Input::cents($r['refund_amount']??'',true),'CNY');
    }
    public function notification(string $raw,array $headers): string
    {
        parse_str($raw,$fields);if(count($fields)>80){throw new Problem('Invalid Alipay notification.',403);}
        if(($fields['sign_type']??'')!=='RSA2'||($fields['app_id']??'')!==$this->c['alipay_direct_appid']||($fields['seller_id']??'')!==$this->c['alipay_direct_seller']){throw new Problem('Alipay notification merchant mismatch.',403);}
        Tools::rsaVerify(self::canonical($fields),(string)($fields['sign']??''),$this->c['alipay_direct_public']);
        return Tools::identifier($fields['out_trade_no']??'',32);
    }
}
