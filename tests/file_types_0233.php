<?php
declare(strict_types=1);
use Chengyu\Core\FileType;
use Chengyu\Services\Media;

function metadataJpeg233(): string {
    $jpeg=fixtures232()['jpg'];
    return substr($jpeg,0,2).str_repeat("\xff\xfe".pack('n',60002).str_repeat('A',60000),5).substr($jpeg,2);
}
function inspect233(string $data): array {
    return FileType::inspect(substr($data,0,FileType::PROBE_BYTES),strlen($data),static function($at,$length)use($data){return substr($data,$at,$length);});
}
foreach(json_decode(file_get_contents(__DIR__.'/fixtures/file-types-variants.json'),true,32,JSON_THROW_ON_ERROR) as $name=>$encoded){test('0233 real encoder variant '.$name,static function()use($a,$admin,$tmp,$name,$encoded):void{
    $data=base64_decode($encoded);$expected=strpos($name,'jpeg-')===0?'image/jpeg':(strpos($name,'webp-')===0?'image/webp':'image/png');
    $inspection=inspect233($data);same($expected,$inspection['mime']);same(3,$inspection['image'][0]);same(2,$inspection['image'][1]);
    $path=$tmp.'/variant233-'.$name;file_put_contents($path,$data);$id=$a->media->storeLocal(account($admin),$path,'variant.image',false,1048576);same($expected,$a->media->readable($id,null)['mime']);
});}
test('0233 legacy Chinese text is byte-preserving private media',static function()use($a,$admin,$u,$tmp):void{
    foreach(["\xd6\xd0\xce\xc4\n", "GB18030: \x94\x39\xda\x33\n", "price \x80", "\xc0\xaf"] as $i=>$data){
        same('text/plain',sniff232($data));$path=$tmp.'/legacy233-'.$i;file_put_contents($path,$data);$id=$a->media->storeLocal(account($admin),$path,'notes.txt',false,1048576);$media=$a->media->readable($id,account($admin));same(1,(int)$media['is_private']);same(Media::GUARD.$data,file_get_contents($a->config['storage'].'/blobs/'.$media['file_key']));
        reject(static function()use($a,$u,$path):void{$a->media->storeLocal(account($u),$path,'photo.jpg',false,1048576);});
    }
});
test('0233 legacy text rejects active markup, explicit invalid UTF-8 and broken sequences',static function():void{
    foreach(["\xd6\xd0<script>1</script>", "\xd6\xd0<?php echo 1;", "\xef\xbb\xbf\xd6\xd0", "\x81\x30\x81", "\x81\x30\xff\x30", "\xfe\x39\xfe\x39", "\x84\x32\xbd\x30", "\x81\x7f"] as $data){same('application/octet-stream',sniff232($data));}
    foreach(["\xd6\xd0", "\x94\x39\xda\x33"] as $sequence){for($n=1;$n<strlen($sequence);++$n){same('text/plain',sniff232(str_repeat('A',FileType::PROBE_BYTES-$n).$sequence.' tail'));}}
});
test('0233 ZIP comment may contain false ZIP64 and central directory candidates',static function():void{
    $zip=fixtures232()['zip'];
    foreach([pack('vvvvVVv',0,0,65535,65535,4294967295,4294967295,0),pack('vvvvVVv',0,0,1,1,46,30,0)] as $fields){
        $comment="PK\x05\x06".$fields;
        same('application/zip',sniff232(substr($zip,0,-2).pack('v',strlen($comment)).$comment));
    }
});
test('0233 valid empty single-volume ZIP64 and a damaged locator',static function():void{
    $record="PK\x06\x06".pack('VVvvVVVVVVVVVV',44,0,45,45,0,0,0,0,0,0,0,0,0,0);
    $locator="PK\x06\x07".pack('VVVV',0,0,0,1);$footer="PK\x05\x06".pack('vvvvVVv',0,0,65535,65535,4294967295,4294967295,0);
    same('application/zip',sniff232($record.$locator.$footer));$locator[4]="\x01";same('application/octet-stream',sniff232($record.$locator.$footer));
});
test('0233 ordinary text beginning with PK is not treated as a damaged ZIP',static function():void{
    same('text/plain',sniff232('PK competition notes: 澄屿'));
    same('text/plain',sniff232('RIFF container notes'));same('text/plain',sniff232('ID3 tag notes'));
});
test('0233 JPEG dimension inspection skips large metadata within one read budget',static function():void{
    $data=metadataJpeg233();$calls=0;$bytes=0;
    $result=FileType::inspect(substr($data,0,FileType::PROBE_BYTES),strlen($data),static function($at,$length)use($data,&$calls,&$bytes){++$calls;$bytes+=$length;return substr($data,$at,$length);});
    same('image/jpeg',$result['mime']);same(3,$result['image'][0]);same(2,$result['image'][1]);truth($calls<=32&&$bytes<=262144);
});
test('0233 JPEG is identical through plain, guarded and resumable storage',static function()use($a,$admin,$tmp):void{
    $data=metadataJpeg233();$actor=account($admin);$path=$tmp.'/metadata233.jpg';file_put_contents($path,$data);
    $plain=$a->media->storeLocal($actor,$path,'plain.jpg',false,1048576);
    $envelope=$tmp.'/metadata233.php';file_put_contents($envelope,Media::GUARD.$data);
    $guarded=$a->media->storeLocal($actor,$envelope,'guarded.jpg',false,1048576,strlen(Media::GUARD));
    $manifest=$a->uploads->start($actor,'chunks.jpg',strlen($data),hash('sha256',$data),false);
    foreach(str_split($data,\Chengyu\Services\Uploads::CHUNK) as $i=>$part){$piece=$tmp.'/jpeg233-part-'.$i;file_put_contents($piece,$part);$a->uploads->put($actor,$manifest['upload_key'],$i,$piece);}
    $merged=$a->uploads->finish($actor,$manifest['upload_key']);same($merged,$a->uploads->finish($actor,$manifest['upload_key']));
    foreach([$plain,$guarded,$merged] as $id){$media=$a->media->readable($id,null);same('image/jpeg',$media['mime']);same(strlen($data),(int)$media['bytes']);same(Media::GUARD.$data,file_get_contents($a->config['storage'].'/blobs/'.$media['file_key']));}
});
test('0233 invalid JPEG lengths and excessive marker padding cannot bypass dimensions',static function()use($a,$admin,$tmp):void{
    $base=fixtures232()['jpg'];$sof=strpos($base,"\xff\xc0");truth($sof!==false);
    $badLength=$base;$badLength[$sof+3]="\x02";
    $badComponents=$base;$badComponents[$sof+9]="\x04";
    $badWidth=$base;$badWidth[$sof+7]="\x00";$badWidth[$sof+8]="\x00";
    foreach([$badLength,$badComponents,$badWidth,"\xff\xd8".str_repeat("\xff",300).substr($base,2),"\xff\xd8\xff\xfe\xff\xff\xff\xd9"] as $i=>$data){
        $path=$tmp.'/bad-jpeg233-'.$i;file_put_contents($path,$data);$before=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');
        reject(static function()use($a,$admin,$path):void{$a->media->storeLocal(account($admin),$path,'bad.jpg',false,1048576);});same($before,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));
    }
});
test('0233 JPEG dimension reads fail when the source becomes unavailable',static function():void{
    $data=metadataJpeg233();$calls=0;
    reject(static function()use($data,&$calls):void{FileType::inspect(substr($data,0,FileType::PROBE_BYTES),strlen($data),static function($at,$length)use($data,&$calls){return ++$calls===1?substr($data,$at,$length):'';});});
});
test('0233 image inspection rejects malformed prefixes without PHP warnings',static function():void{
    set_error_handler(static function($level,$message){throw new RuntimeException($message);});
    try{foreach(fixtures232() as $data){for($n=1;$n<min(120,strlen($data));++$n){inspect233(substr($data,0,$n));}}for($n=0;$n<500;++$n){$data=fixtures232()['jpg'];$at=($n*17)%strlen($data);$data[$at]=chr($n%256);inspect233($data);}}finally{restore_error_handler();}
});
foreach(['ok','changed-metadata','oversized'] as $mode){test('0233 remote JPEG '.$mode,static function()use($a,$admin,$mode):void{
    $saved=[];foreach(['object_storage_enabled','s3_endpoint','s3_region','s3_access','s3_secret','s3_bucket','s3_prefix'] as $key){$saved[$key]=$a->settings->get($key);}
    $seed=['object_storage_enabled'=>true];$cfg=s3config16();foreach(['endpoint','region','access','secret','bucket','prefix'] as $key){$seed['s3_'.$key]=$cfg[$key];}settings('media_plus',$seed);
    try{
        $data=metadataJpeg233();if($mode==='oversized'){$at=strpos($data,"\xff\xc0");$data=substr_replace($data,pack('n',12001),$at+7,2);}
        $manifest=null;$copied=false;$reads=0;$etag='"jpeg233"';$before=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');
        $http=new \Chengyu\Core\HttpClient(static function($method,$url,$headers)use(&$manifest,$data,$etag,&$copied,&$reads,$mode){
            $final=strpos($url,'/media/')!==false;$head=['Content-Type'=>'image/jpeg','Content-Length'=>(string)strlen($data),'ETag'=>$etag,'x-amz-meta-cy-id'=>$manifest['upload_key']];
            if($final){$head['x-amz-meta-cy-source']=hash('sha256',$etag);}if($method==='HEAD'){return $final&&!$copied?raw16('',[],404):raw16('',$head);}
            if($method==='GET'){
                same($etag,$headers['if-match']);truth((bool)preg_match('/^bytes=(\d+)-(\d+)$/D',$headers['range'],$m));++$reads;$length=(int)$m[2]-(int)$m[1]+1;
                $head['Content-Length']=(string)$length;$head['Content-Range']='bytes '.$m[1].'-'.$m[2].'/'.strlen($data);
                if($mode==='changed-metadata'&&$reads>=3){$head['ETag']='"changed"';}return raw16(substr($data,(int)$m[1],$length),$head,206);
            }
            same('PUT',$method);same($etag,$headers['x-amz-copy-source-if-match']);$copied=true;return raw16('<CopyObjectResult><ETag>"copied"</ETag></CopyObjectResult>');
        });
        $objects=new \Chengyu\Services\ObjectStorage($a,$http);$actor=account($admin);$manifest=$objects->prepare($actor,'photo.jpg',strlen($data));
        if($mode==='ok'){$id=$objects->finish($actor,$manifest['upload_key']);same('image/jpeg',$a->media->readable($id,$actor)['mime']);truth($copied&&$reads>=3);same($id,$objects->finish($actor,$manifest['upload_key']));}
        else{reject(static function()use($objects,$actor,$manifest):void{$objects->finish($actor,$manifest['upload_key']);},$mode==='oversized'?400:409);same(false,$copied);same($before,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));}
    }finally{foreach($saved as $key=>$value){if($value===''&&($a->settings->schema()[$key][2]??'')==='secret'){$saved['clear_'.$key]='1';}}settings('media_plus',$saved);}
});}
