<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Settings,HttpClient,Problem,Input};
use Chengyu\Payments\{Gateway,Stripe,PayPal,Alipay,Wechat};
final class DirectPayments
{
    public const NAMES=['stripe'=>'Stripe','paypal'=>'PayPal','alipay_direct'=>'Alipay','wechat'=>'WeChat Pay'];
    private Settings $settings;private HttpClient $http;
    public function __construct(Settings $settings,?HttpClient $http=null){$this->settings=$settings;$this->http=$http??new HttpClient();}
    public function available(): array
    {
        $out=[];foreach(self::NAMES as $key=>$name){if($this->settings->enabled($key)){$out[$key]=$name;}}return $out;
    }
    public function snapshot(string $gateway,string $currency): array
    {
        Input::choice($gateway,array_keys(self::NAMES));$this->settings->requireModule($gateway);$c=[];
        foreach($this->settings->schema() as $key=>$field){if(strpos($key,$gateway.'_')===0){$c[$key]=$this->settings->get($key);}}
        if(in_array($gateway,['alipay_direct','wechat'],true)&&$currency!=='CNY'){throw new Problem('This direct payment channel requires CNY. Currency conversion is never implicit.');}
        if($gateway==='paypal' && !in_array($currency,['USD','EUR','GBP','HKD','SGD','CAD','AUD'],true)){throw new Problem('Configure a supported PayPal store currency before the first order. CNY conversion is not automatic.');}
        $required=['stripe'=>['secret'],'paypal'=>['id','secret','merchant'],'alipay_direct'=>['appid','seller','private','public'],'wechat'=>['appid','mchid','serial','private','platform_serial','platform_public','v3_key']];
        foreach($required[$gateway] as $suffix){if(empty($c[$gateway.'_'.$suffix])){throw new Problem('Complete the direct payment credentials before enabling checkout.',503);}}
        return $c;
    }
    public function driver(string $gateway,array $config): Gateway
    {
        switch($gateway){case 'stripe':return new Stripe($config,$this->http);case 'paypal':return new PayPal($config,$this->http);case 'alipay_direct':return new Alipay($config,$this->http);case 'wechat':return new Wechat($config,$this->http);}
        throw new Problem('Unknown direct payment driver.');
    }
}
