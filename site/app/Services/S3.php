<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{HttpClient,Input,Problem};
/** S3 Signature V4, with no SDK or executable configuration. Works with bucket endpoints. */
final class S3
{
    private array $c;private HttpClient $http;
    public function __construct(array $config,?HttpClient $http=null){$this->c=self::validate($config);$this->http=$http??new HttpClient();}
    public static function validate(array $c): array
    {
        foreach(['endpoint','region','access','secret','bucket','prefix'] as $k){$c[$k]=Input::required($c[$k]??'',2048);}
        $p=HttpClient::endpoint($c['endpoint']);$url=parse_url($c['endpoint']);if(isset($url['query'])||isset($url['fragment'])||preg_match('/[%\x00-\x20]/',$c['endpoint'])||!preg_match('/^[a-zA-Z0-9._-]+$/D',$c['region'])||!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D',$c['bucket'])||!preg_match('/^[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*$/D',$c['prefix'])){throw new Problem('Invalid S3 bucket endpoint or namespace.');}
        $path=$url['path']??'';if($path!==''&&$path!=='/'&&$path!=='/'.$c['bucket']&&$path!=='/'.$c['bucket'].'/'){throw new Problem('S3 endpoint must be a bucket origin or an endpoint followed by the bucket name.');}
        $c['endpoint']=rtrim($c['endpoint'],'/');$c['token']=Input::text($c['token']??'',4096);return $c;
    }
    private static function uri(string $key): string {if($key===''||strpos($key,'..')!==false||!preg_match('/^[a-zA-Z0-9_\/.\-]+$/D',$key)){throw new Problem('Invalid object key.');}return implode('/',array_map('rawurlencode',explode('/',$key)));}
    private function url(string $key): string {return $this->c['endpoint'].'/'.self::uri($key);}
    public static function query(array $params): string {ksort($params,SORT_STRING);return http_build_query($params,'','&',PHP_QUERY_RFC3986);}
    private function signingKey(string $date): string {$k=hash_hmac('sha256',$date,'AWS4'.$this->c['secret'],true);foreach([$this->c['region'],'s3','aws4_request'] as $part){$k=hash_hmac('sha256',$part,$k,true);}return $k;}
    private function signature(string $canonical,string $date,string $scope): string{return hash_hmac('sha256',"AWS4-HMAC-SHA256\n".$date."\n".$scope."\n".hash('sha256',$canonical),$this->signingKey(substr($date,0,8)));}
    private static function headers(array $headers): array {$out=[];foreach($headers as $k=>$v){$k=strtolower((string)$k);if(!preg_match('/^[a-z0-9-]+$/D',$k)||!is_string($v)||preg_match('/[\x00-\x1f\x7f]/',$v)||isset($out[$k])){throw new Problem('Invalid S3 signed header.');}$out[$k]=trim((string)preg_replace('/ +/',' ',$v));}ksort($out,SORT_STRING);return $out;}
    private static function canonicalHeaders(array $h): string {$s='';foreach($h as $k=>$v){$s.=$k.':'.$v."\n";}return $s;}
    public function presign(string $method,string $key,int $seconds=120,array $headers=[],array $query=[],?int $now=null): array
    {
        Input::choice($method,['GET','PUT','HEAD']);if($seconds<1||$seconds>900){throw new Problem('Object link lifetime must be between 1 and 900 seconds.');}$url=$this->url($key);$date=gmdate('Ymd\THis\Z',$now??time());$scope=substr($date,0,8).'/'.$this->c['region'].'/s3/aws4_request';$h=self::headers($headers+['host'=>(string)parse_url($url,PHP_URL_HOST)]);$signed=implode(';',array_keys($h));foreach(array_keys($query) as $q){if(stripos((string)$q,'x-amz-')===0){throw new Problem('Reserved S3 query parameter.');}}
        $params=$query+['X-Amz-Algorithm'=>'AWS4-HMAC-SHA256','X-Amz-Credential'=>$this->c['access'].'/'.$scope,'X-Amz-Date'=>$date,'X-Amz-Expires'=>(string)$seconds,'X-Amz-SignedHeaders'=>$signed];if($this->c['token']!==''){$params['X-Amz-Security-Token']=$this->c['token'];}$canonical=$method."\n".parse_url($url,PHP_URL_PATH)."\n".self::query($params)."\n".self::canonicalHeaders($h)."\n".$signed."\nUNSIGNED-PAYLOAD";$params['X-Amz-Signature']=$this->signature($canonical,$date,$scope);unset($h['host']);return ['url'=>$url.'?'.self::query($params),'headers'=>$h,'expires_at'=>($now??time())+$seconds];
    }
    public function request(string $method,string $key,array $headers=[],string $body=''): array
    {
        $url=$this->url($key);$date=gmdate('Ymd\THis\Z');$scope=substr($date,0,8).'/'.$this->c['region'].'/s3/aws4_request';$h=$headers+['host'=>(string)parse_url($url,PHP_URL_HOST),'x-amz-date'=>$date,'x-amz-content-sha256'=>hash('sha256',$body)];if($this->c['token']!==''){$h['x-amz-security-token']=$this->c['token'];}$h=self::headers($h);$signed=implode(';',array_keys($h));$canonical=$method."\n".parse_url($url,PHP_URL_PATH)."\n\n".self::canonicalHeaders($h)."\n".$signed."\n".hash('sha256',$body);$h['authorization']='AWS4-HMAC-SHA256 Credential='.$this->c['access'].'/'.$scope.', SignedHeaders='.$signed.', Signature='.$this->signature($canonical,$date,$scope);unset($h['host']);return $this->http->request($method,$url,$body,$h);
    }
    public function copy(string $source,string $destination,string $etag,array $headers): void
    {
        if(!preg_match('/^"[a-zA-Z0-9_-]{1,100}"$/D',$etag)){throw new Problem('Unsupported S3 entity tag.',502);}
        $r=$this->request('PUT',$destination,$headers+['x-amz-copy-source'=>'/'.rawurlencode($this->c['bucket']).'/'.self::uri($source),'x-amz-copy-source-if-match'=>$etag,'x-amz-metadata-directive'=>'REPLACE']);
        // S3 may embed an Error in an HTTP 200 response. No XML entity parser is used.
        if(stripos($r['body'],'<!')!==false||stripos($r['body'],'<Error')!==false||!preg_match('/<CopyObjectResult(?:\s[^>]*)?>[\s\S]*<ETag>[\s\S]+<\/ETag>[\s\S]*<\/CopyObjectResult>/', $r['body'])){throw new Problem('Object copy was not confirmed.',502);}
    }
}
