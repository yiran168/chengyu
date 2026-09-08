<?php
declare(strict_types=1);
namespace Chengyu\Core;

/** Bounded HTTPS transport shared by integrations. No cURL or URL fopen required.
 * DNS is checked and the chosen address is pinned for the TLS connection.
 * Redirects, compressed responses and ambiguous framing fail closed.
 */
final class HttpClient
{
    private $transport;
    private int $limit;
    public function __construct(?callable $transport=null, int $limit=262144)
    {
        if ($transport && !in_array(PHP_SAPI,['cli','cli-server'],true)) {throw new \LogicException('Test transport is CLI-only.');}
        $this->transport=$transport;$this->limit=$limit;
    }
    public static function endpoint(string $url): array
    {
        if (strlen($url)>4096 || preg_match('/[^\x21-\x7e]/',$url)) {throw new Problem('Invalid integration endpoint.');}
        $p=parse_url($url);
        if (!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {throw new Problem('Integrations require an HTTPS endpoint without credentials or fragments.');}
        $host=strtolower(trim($p['host'],'[]'));
        if (!preg_match('/^[a-z0-9.:-]+$/D',$host) || substr($host,-1)==='.' || $host==='localhost' || substr($host,-10)==='.localhost') {throw new Problem('Invalid integration host.');}
        if (filter_var($host,FILTER_VALIDATE_IP) && !self::publicIp($host)) {throw new Problem('Private network integration endpoints are not allowed.');}
        $port=(int)($p['port']??443);if ($port!==443) {throw new Problem('Integration endpoints must use HTTPS port 443.');}
        return ['host'=>$host,'port'=>$port,'path'=>($p['path']??'/').(isset($p['query'])?'?'.$p['query']:'')];
    }
    public static function publicIp(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {return false;}
        // PHP versions differ on special-purpose ranges. Reject non-global IPv6,
        // IPv4-mapped/transition addresses and the IPv4 shared/benchmark ranges.
        if (strpos($ip,':')!==false) {
            $p=inet_pton($ip);if ($p===false || (ord($p[0])&0xe0)!==0x20) {return false;}
            return strncmp($p,hex2bin('20010db8'),4)!==0 && strncmp($p,hex2bin('20010000'),4)!==0 && strncmp($p,hex2bin('2002'),2)!==0;
        }
        $n=explode('.',$ip);$a=(int)$n[0];$b=(int)$n[1];
        return !($a===100 && $b>=64 && $b<=127) && !($a===198 && ($b===18 || $b===19)) && !($a===192 && $b===0) && !($a===198 && $b===51 && (int)$n[2]===100) && !($a===203 && $b===0 && (int)$n[2]===113) && $a<224;
    }
    public function json(string $method,string $url,array $fields=[],array $headers=[]): array
    {
        Input::choice($method,['GET','POST']);
        $body='';
        if($method==='POST'){
            $body=http_build_query($fields,'','&',PHP_QUERY_RFC3986);
            $headers['Content-Type']='application/x-www-form-urlencoded';
        }elseif($fields){$url.=(strpos($url,'?')===false?'?':'&').http_build_query($fields,'','&',PHP_QUERY_RFC3986);}
        // One framing, duplicate-header and request-size boundary for every adapter.
        $response=$this->request($method,$url,$body,$headers);
        return self::object($response['body']);
    }

    /** Raw API request. Returned headers are lower-case; TLS, framing and size checks remain shared. */
    public function request(string $method,string $url,string $body='',array $headers=[]): array
    {
        Input::choice($method,['GET','POST','PUT','PATCH','DELETE','HEAD']);$p=self::endpoint($url);
        if(strlen($body)>1048576){throw new Problem('Integration request is too large.');}
        $headers=array_merge(['Accept'=>'application/json','Accept-Encoding'=>'identity','User-Agent'=>'Chengyu/'.\Chengyu\App::VERSION],$headers);
        $seen=[];foreach($headers as $name=>$value){$lower=strtolower((string)$name);if(!preg_match('/^[a-zA-Z0-9-]+$/D',(string)$name)||!is_string($value)||preg_match('/[\x00-\x1f\x7f]/',$value)||isset($seen[$lower])||in_array($lower,['host','content-length','connection','transfer-encoding'],true)){throw new \InvalidArgumentException('Unsafe integration header');}$seen[$lower]=true;}
        $raw=$this->transport?($this->transport)($method,$url,$headers,$body):$this->send($method,$p,$headers,$body);
        $boundary=strpos($raw,"\r\n\r\n");if($boundary===false || $boundary>16384){throw new Problem('Invalid integration response headers.',502);}
        $head=substr($raw,0,$boundary);$lines=explode("\r\n",$head);$status=array_shift($lines);$rh=[];
        if(!preg_match('/^HTTP\/1\.[01] ([0-9]{3})(?: |$)/D',$status,$m)){throw new Problem('Invalid integration status.',502);}
        foreach($lines as $line){$pair=explode(':',$line,2);if(count($pair)!==2||!preg_match('/^[A-Za-z0-9-]+$/D',$pair[0])){throw new Problem('Invalid integration headers.',502);}$key=strtolower($pair[0]);if(isset($rh[$key]) && in_array($key,['content-length','transfer-encoding','content-encoding','wechatpay-signature','wechatpay-timestamp','wechatpay-nonce','wechatpay-serial'],true)){throw new Problem('Ambiguous integration response framing.',502);}$rh[$key]=trim($pair[1]);}
        if($method==='HEAD'){
            // A HEAD response advertises the GET body length but carries no body.
            if((int)$m[1]<200 || (int)$m[1]>=300 || substr($raw,$boundary+4)!==''){throw new Problem('Object metadata request failed.',502);}
            if(isset($rh['transfer-encoding']) || (isset($rh['content-encoding']) && strtolower($rh['content-encoding'])!=='identity') || (isset($rh['content-length']) && !preg_match('/^[0-9]{1,18}$/D',$rh['content-length']))){throw new Problem('Ambiguous object metadata framing.',502);}
            $decoded='';
        }else{$decoded=self::decode($raw,$this->limit);}
        return ['status'=>(int)$m[1],'headers'=>$rh,'body'=>$decoded];
    }
    public function jsonBody(string $method,string $url,array $data=[],array $headers=[]): array
    {
        $body=$method==='GET'?'':json_encode($data?:new \stdClass(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $r=$this->request($method,$url,$body,array_merge(['Content-Type'=>'application/json'],$headers));
        return self::object($r['body']);
    }
    public static function object(string $body): array
    {
        try{$data=json_decode($body,true,48,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new Problem('The integration returned invalid JSON.',502);}
        if(!is_array($data)){throw new Problem('The integration returned an invalid response.',502);}return $data;
    }
    private function send(string $method,array $p,array $headers,string $body): string
    {
        if (!function_exists('stream_socket_client') || !extension_loaded('openssl')) {throw new Problem('Outbound TLS sockets and OpenSSL are required for this integration.',503);}
        $ips=filter_var($p['host'],FILTER_VALIDATE_IP)?[$p['host']]:gethostbynamel($p['host']);
        if(!$ips){throw new Problem('The integration host could not be resolved.',502);}
        foreach($ips as $ip){if(!self::publicIp($ip)){throw new Problem('Private network integration endpoints are not allowed.',403);}}
        $ip=$ips[0];$target=strpos($ip,':')!==false?'['.$ip.']':$ip;
        $tls=STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;if(defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')){$tls|=constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');}
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$p['host'],'SNI_enabled'=>true,'disable_compression'=>true,'crypto_method'=>$tls]]);
        $socket=@stream_socket_client('tls://'.$target.':443',$errno,$error,8,STREAM_CLIENT_CONNECT,$ctx);
        if(!$socket){throw new Problem('The integration TLS connection failed. No success was assumed.',502);}
        $deadline=microtime(true)+12;stream_set_timeout($socket,8);
        try{
            $host=strpos($p['host'],':')!==false?'['.$p['host'].']':$p['host'];
            $request=$method.' '.$p['path']." HTTP/1.1\r\nHost: ".$host."\r\nConnection: close\r\n";
            foreach($headers as $k=>$v){$request.=$k.': '.$v."\r\n";}
            if($body!=='' || in_array($method,['POST','PUT','PATCH','DELETE'],true)){$request.='Content-Length: '.strlen($body)."\r\n";}$request.="\r\n".$body;
            $offset=0;while($offset<strlen($request)){if(microtime(true)>$deadline){throw new Problem('The integration timed out.',502);}$n=fwrite($socket,substr($request,$offset));if(!$n){throw new Problem('The integration request could not be sent.',502);}$offset+=$n;}
            $raw='';while(!feof($socket)){
                if(microtime(true)>$deadline){throw new Problem('The integration timed out. No success was assumed.',502);}
                $chunk=fread($socket,8192);$meta=stream_get_meta_data($socket);
                if($chunk===false || $meta['timed_out']){throw new Problem('The integration response timed out. No success was assumed.',502);}
                $raw.=$chunk;if(strlen($raw)>$this->limit+32768){throw new Problem('The integration response is too large.',502);}
            }
            return $raw;
        }finally{fclose($socket);}
    }
    public static function decode(string $raw,int $limit=262144): string
    {
        $end=strpos($raw,"\r\n\r\n");if($end===false || $end>16384){throw new Problem('Invalid integration response headers.',502);}
        $lines=explode("\r\n",substr($raw,0,$end));$status=array_shift($lines);
        if(!preg_match('/^HTTP\/1\.[01] ([0-9]{3})(?: |$)/D',$status,$m) || (int)$m[1]<200 || (int)$m[1]>=300){throw new Problem('The integration rejected the request or attempted a redirect.',502);}
        $h=[];foreach($lines as $line){$pair=explode(':',$line,2);if(count($pair)!==2 || preg_match('/^[ \t]/',$line)){throw new Problem('Invalid integration response headers.',502);}$key=strtolower(trim($pair[0]));if(isset($h[$key]) && in_array($key,['content-length','transfer-encoding','content-encoding'],true)){throw new Problem('Ambiguous integration response framing.',502);}$h[$key]=trim($pair[1]);}
        if(isset($h['content-encoding']) && strtolower($h['content-encoding'])!=='identity'){throw new Problem('Unsupported integration content encoding.',502);}
        $body=substr($raw,$end+4);
        if(isset($h['transfer-encoding'])){
            if(isset($h['content-length']) || strtolower($h['transfer-encoding'])!=='chunked'){throw new Problem('Ambiguous integration response framing.',502);}
            $out='';$steps=0;while(true){
                if(++$steps>8192){throw new Problem('Invalid integration chunks.',502);}
                $pos=strpos($body,"\r\n");if($pos===false || $pos>128){throw new Problem('Invalid integration chunks.',502);}
                $size=substr($body,0,$pos);if(!preg_match('/^[0-9a-fA-F]{1,8}$/D',$size)){throw new Problem('Invalid integration chunks.',502);}
                $n=hexdec($size);$body=substr($body,$pos+2);
                if($n===0){if($body!=="\r\n"){throw new Problem('Unexpected integration trailers.',502);}break;}
                if($n>$limit-strlen($out) || strlen($body)<$n+2 || substr($body,$n,2)!=="\r\n"){throw new Problem('Invalid or oversized integration body.',502);}
                $out.=substr($body,0,$n);$body=substr($body,$n+2);
            }$body=$out;
        }elseif(isset($h['content-length'])){
            if(!preg_match('/^[0-9]{1,9}$/D',$h['content-length']) || (int)$h['content-length']!==strlen($body)){throw new Problem('Truncated integration response.',502);}
        }
        if(strlen($body)>$limit){throw new Problem('The integration response is too large.',502);}return $body;
    }
}
