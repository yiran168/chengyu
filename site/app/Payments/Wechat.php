<?php
declare(strict_types=1);
namespace Chengyu\Payments;
use Chengyu\Core\{HttpClient,Input,Problem};
final class Wechat implements Gateway
{
    private array $c;private HttpClient $http;
    public function __construct(array $config,HttpClient $http){$this->c=$config;$this->http=$http;}
    public function verify(string $raw,array $headers): void
    {
        $time=(string)($headers['wechatpay-timestamp']??'');$nonce=(string)($headers['wechatpay-nonce']??'');
        if(!preg_match('/^[0-9]{10,12}$/D',$time)||abs(time()-(int)$time)>300||strlen($nonce)>128||$nonce===''||($headers['wechatpay-serial']??'')!==$this->c['wechat_platform_serial']){throw new Problem('Stale WeChat response or unrecognized platform key. Rotate the verified key, never disable verification.',403);}
        Tools::rsaVerify($time."\n".$nonce."\n".$raw."\n",(string)($headers['wechatpay-signature']??''),$this->c['wechat_platform_public']);
    }
    private function call(string $method,string $path,array $data=[]): array
    {
        $body=$method==='GET'?'':json_encode($data?:new \stdClass(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $stamp=(string)time();$nonce=bin2hex(random_bytes(16));
        $signature=Tools::rsaSign($method."\n".$path."\n".$stamp."\n".$nonce."\n".$body."\n",$this->c['wechat_private']);
        $authorization='WECHATPAY2-SHA256-RSA2048 mchid="'.Tools::identifier($this->c['wechat_mchid']).'",nonce_str="'.$nonce.'",timestamp="'.$stamp.'",serial_no="'.Tools::identifier($this->c['wechat_serial']).'",signature="'.$signature.'"';
        $r=$this->http->request($method,'https://api.mch.weixin.qq.com'.$path,$body,['Authorization'=>$authorization,'Content-Type'=>'application/json','Wechatpay-Serial'=>Tools::identifier($this->c['wechat_platform_serial'])]);
        $this->verify($r['body'],$r['headers']);return HttpClient::object($r['body']);
    }
    public function create(array $sale): array
    {
        if(!filter_var($sale['payer_ip'],FILTER_VALIDATE_IP)){throw new Problem('A valid client IP is required for WeChat H5.');}
        $r=$this->call('POST','/v3/pay/transactions/h5',['appid'=>$this->c['wechat_appid'],'mchid'=>$this->c['wechat_mchid'],'description'=>'Order '.$sale['sale_no'],'out_trade_no'=>$sale['sale_no'],
            'notify_url'=>$sale['notify_url'],'time_expire'=>gmdate('Y-m-d\TH:i:s\Z',(int)$sale['expires_at']),'amount'=>['total'=>(int)$sale['amount'],'currency'=>'CNY'],
            'scene_info'=>['payer_client_ip'=>$sale['payer_ip'],'h5_info'=>['type'=>'Wap']]]);
        $url=Tools::url((string)($r['h5_url']??''),['wx.tenpay.com','payapp.weixin.qq.com','pay.weixin.qq.com']);
        return ['id'=>$sale['sale_no'],'url'=>$url.(strpos($url,'?')===false?'?':'&').'redirect_url='.rawurlencode($sale['return_url'])];
    }
    public function query(array $sale): array
    {
        $r=$this->call('GET','/v3/pay/transactions/out-trade-no/'.Tools::identifier($sale['sale_no'],32).'?mchid='.rawurlencode($this->c['wechat_mchid']));
        if(($r['mchid']??'')!==$this->c['wechat_mchid']||($r['appid']??'')!==$this->c['wechat_appid']||($r['out_trade_no']??'')!==$sale['sale_no']){throw new Problem('WeChat merchant or order mismatch.',409);}
        if(($r['trade_state']??'')==='SUCCESS'){return Tools::receipt($sale,(string)($r['transaction_id']??''),Input::integer($r['amount']['total']??-1,0,100000000000),(string)($r['amount']['currency']??''),$sale['sale_no']);}
        return ['state'=>in_array($r['trade_state']??'',['CLOSED','REVOKED'],true)?'closed':'pending'];
    }
    private function refunded(array $sale,array $refund,array $r): array
    {
        if(($r['out_trade_no']??'')!==$sale['sale_no']||($r['out_refund_no']??'')!==$refund['refund_no']||($r['transaction_id']??'')!==$sale['trade_no']){throw new Problem('WeChat refund binding mismatch.',409);}
        $state=($r['status']??'')==='SUCCESS'?'succeeded':(in_array($r['status']??'',['CLOSED','ABNORMAL'],true)?'failed':'pending');
        return Tools::refundResult($sale,$refund,$r,(string)($r['refund_id']??''),$state,Input::integer($r['amount']['refund']??-1,0,100000000000),(string)($r['amount']['currency']??''));
    }
    public function refund(array $sale,array $refund): array
    {
        $r=$this->call('POST','/v3/refund/domestic/refunds',['transaction_id'=>$sale['trade_no'],'out_refund_no'=>$refund['refund_no'],'reason'=>'Approved aftersales request','amount'=>['refund'=>(int)$refund['amount'],'total'=>(int)$sale['amount'],'currency'=>$sale['currency']]]);
        return $this->refunded($sale,$refund,$r);
    }
    public function queryRefund(array $sale,array $refund): array
    {
        return $this->refunded($sale,$refund,$this->call('GET','/v3/refund/domestic/refunds/'.Tools::identifier($refund['refund_no'],32)));
    }
    public function notification(string $raw,array $headers): string
    {
        $this->verify($raw,$headers);$event=HttpClient::object($raw);
        if(($event['event_type']??'')!=='TRANSACTION.SUCCESS'){return '';}$r=$event['resource']??[];
        if(($r['algorithm']??'')!=='AEAD_AES_256_GCM'||strlen($this->c['wechat_v3_key'])!==32){throw new Problem('Invalid WeChat encrypted resource.',403);}
        $binary=base64_decode((string)($r['ciphertext']??''),true);if($binary===false||strlen($binary)<17){throw new Problem('Invalid WeChat encrypted resource.',403);}
        $plain=openssl_decrypt(substr($binary,0,-16),'aes-256-gcm',$this->c['wechat_v3_key'],OPENSSL_RAW_DATA,(string)($r['nonce']??''),substr($binary,-16),(string)($r['associated_data']??''));
        if($plain===false){throw new Problem('WeChat encrypted resource authentication failed.',403);}$data=HttpClient::object($plain);
        if(($data['mchid']??'')!==$this->c['wechat_mchid']||($data['appid']??'')!==$this->c['wechat_appid']){throw new Problem('WeChat notification merchant mismatch.',403);}
        return Tools::identifier($data['out_trade_no']??'',32);
    }
}
