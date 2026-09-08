<?php
declare(strict_types=1);
namespace Chengyu\Payments;
use Chengyu\Core\{Input,Problem,ExternalNavigation};
final class Tools
{
    public static function identifier($id,int $max=120): string
    {
        if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,'.(int)$max.'}$/D',$id)){throw new Problem('Invalid provider reference.',502);}return $id;
    }
    public static function rsaSign(string $message,string $private): string
    {
        $key=@openssl_pkey_get_private($private);
        if(!$key || !openssl_sign($message,$signature,$key,OPENSSL_ALGO_SHA256)){throw new Problem('RSA private key is invalid or cannot sign.',503);}
        return base64_encode($signature);
    }
    public static function rsaVerify(string $message,string $signature,string $public): void
    {
        $sig=base64_decode($signature,true);$key=@openssl_pkey_get_public($public);
        if($sig===false||!$key||openssl_verify($message,$sig,$key,OPENSSL_ALGO_SHA256)!==1){throw new Problem('Provider signature verification failed.',403);}
    }
    public static function url(string $url,array $hosts): string
    {
        $parts=ExternalNavigation::target($url);
        if(!in_array(strtolower($parts['host']),$hosts,true)){throw new Problem('Unexpected provider checkout host.',502);}return $url;
    }
    public static function receipt(array $sale,string $trade,int $amount,string $currency,string $order): array
    {
        if($order!==$sale['sale_no']||$amount!==(int)$sale['amount']||strtoupper($currency)!==$sale['currency']){throw new Problem('Provider amount, currency or order reference mismatch.',409);}
        return ['state'=>'paid','trade'=>self::identifier($trade),'amount'=>$amount,'currency'=>strtoupper($currency),'order'=>$order];
    }
    public static function refundResult(array $sale,array $refund,array $data,string $id,string $state,int $amount,string $currency): array
    {
        if(!empty($refund['remote_id'])&&$refund['remote_id']!==$id){throw new Problem('Provider refund reference mismatch.',409);}
        if($amount!==(int)$refund['amount']||strtoupper($currency)!==$sale['currency']){throw new Problem('Provider refund amount or currency mismatch.',409);}
        return ['id'=>self::identifier($id),'state'=>Input::choice($state,['succeeded','pending','failed'])];
    }
    /** Extract exact top-level JSON bytes for RSA-signed Alipay responses; reject duplicate members. */
    public static function members(string $json): array
    {
        try{json_decode($json,true,48,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new Problem('Invalid signed JSON.',502);}
        $n=strlen($json);$i=0;$out=[];$ws=static function()use(&$i,$n,$json):void{while($i<$n && strpos(" \r\n\t",$json[$i])!==false){$i++;}};
        $ws();if(($json[$i++]??'')!=='{'){throw new Problem('Invalid signed JSON.',502);}
        while(true){
            $ws();if(($json[$i]??'')==='}'){$i++;break;}
            $start=$i;if(($json[$i++]??'')!=='"'){throw new Problem('Invalid signed JSON member.',502);}
            $escaped=false;while($i<$n){$ch=$json[$i++];if($escaped){$escaped=false;}elseif($ch==='\\'){$escaped=true;}elseif($ch==='"'){break;}}
            $key=json_decode(substr($json,$start,$i-$start),true,4,JSON_THROW_ON_ERROR);
            if(!is_string($key)||isset($out[$key])){throw new Problem('Duplicate signed JSON member.',502);}
            $ws();if(($json[$i++]??'')!==':'){throw new Problem('Invalid signed JSON member.',502);}$ws();$start=$i;
            $depth=0;$string=false;$escaped=false;
            while($i<$n){$ch=$json[$i];if($string){if($escaped){$escaped=false;}elseif($ch==='\\'){$escaped=true;}elseif($ch==='"'){$string=false;}}else{if($ch==='"'){$string=true;}elseif($ch==='{'||$ch==='['){$depth++;}elseif($ch==='}'||$ch===']'){if($depth===0){break;}$depth--;}elseif($ch===','&&$depth===0){break;}}$i++;}
            $out[$key]=trim(substr($json,$start,$i-$start));if($out[$key]===''){throw new Problem('Empty signed JSON value.',502);}
            json_decode($out[$key],true,48,JSON_THROW_ON_ERROR);$ws();$delim=$json[$i++]??'';if($delim==='}'){break;}if($delim!==','){throw new Problem('Invalid signed JSON delimiter.',502);}
        }
        $ws();if($i!==$n){throw new Problem('Trailing signed JSON data.',502);}return $out;
    }
}
