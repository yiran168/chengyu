<?php
declare(strict_types=1);
use Chengyu\Core\FileType;
use Chengyu\Services\Media;

function fixtures232(): array {
    $files = array_map('base64_decode', json_decode(file_get_contents(__DIR__.'/fixtures/file-types.json'), true, 32, JSON_THROW_ON_ERROR));
    $files['txt'] = "Original UTF-8 text: 澄屿 \xf0\x9f\x8c\xb8\n";
    // Structural audio/video fixtures; these test identification, not playback/decoding.
    $frame = "\xff\xfb\x90\x00" . str_repeat("\0", 413);
    $files['mp3'] = $frame . $frame;
    $files['mp4'] = pack('N', 24).'ftypisom'.pack('N', 512).'isommp42'.pack('N', 12).'mdatDATA';
    return $files;
}
function sniff232(string $data): string {
    return FileType::detect(substr($data,0,FileType::PROBE_BYTES),strlen($data),static function(int $at,int $length)use($data):string{return substr($data,$at,$length);});
}
function id3size232(int $n): string {return chr(($n>>21)&127).chr(($n>>14)&127).chr(($n>>7)&127).chr($n&127);}
function pngchunk232(string $type,string $data): string {return pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);}

test('0232 environment evidence proves the requested Fileinfo state',static function():void {
    if(getenv('CY_EXPECT_NO_FILEINFO')==='1'){same(false,extension_loaded('fileinfo'));same(false,class_exists('finfo'));same(false,function_exists('finfo_open'));}
});
foreach(fixtures232() as $ext=>$data){
    test('0232 content detection '.$ext,static function()use($ext,$data):void{same(FileType::EXTENSIONS[$ext],sniff232($data));});
    test('0232 media storage and permissions '.$ext,static function()use($a,$admin,$u,$tmp,$ext,$data):void{
        $path=$tmp.'/fixture232-'.$ext;file_put_contents($path,$data);
        $image=in_array(FileType::EXTENSIONS[$ext],FileType::IMAGES,true);
        $id=$a->media->storeLocal(account($image?$u:$admin),$path,'misleading-name.exe',false,1048576);
        $media=$a->media->readable($id,account($admin));same(FileType::EXTENSIONS[$ext],$media['mime']);same($image?0:1,(int)$media['is_private']);
        same(Media::GUARD.$data,file_get_contents($a->config['storage'].'/blobs/'.$media['file_key']));
        if(!$image){reject(static function()use($a,$u,$path):void{$a->media->storeLocal(account($u),$path,'fake.jpg',false,1048576);});reject(static function()use($a,$id):void{$a->media->readable($id,null);},403);}
    });
}
test('0232 guarded image uses the payload offset for every range',static function()use($a,$admin,$tmp):void{
    $data=fixtures232()['png'];$data=substr($data,0,33).pngchunk232('tEXt','Note'."\0".str_repeat('A',300000)).substr($data,33);
    $path=$tmp.'/guarded232';file_put_contents($path,Media::GUARD.$data);
    $id=$a->media->storeLocal(account($admin),$path,'offset.png',false,1048576,strlen(Media::GUARD));
    same('image/png',$a->media->readable($id,null)['mime']);
    same(Media::GUARD.$data,file_get_contents($a->config['storage'].'/blobs/'.$a->media->readable($id,null)['file_key']));
});
test('0232 PNG permits an empty IDAT before actual image data',static function():void{
    $png=fixtures232()['png'];same('image/png',sniff232(substr($png,0,33).pngchunk232('IDAT','').substr($png,33)));
});
test('0232 large embedded MP3 tags use bounded reads and validate the footer',static function():void{
    $tag='ID3'."\x04\x00\x10".id3size232(300000);$data=$tag.str_repeat('A',300000).'3DI'.substr($tag,3).fixtures232()['mp3'];
    $readBytes=0;$calls=0;
    same('audio/mpeg',FileType::detect(substr($data,0,FileType::PROBE_BYTES),strlen($data),static function($at,$length)use($data,&$readBytes,&$calls){$readBytes+=$length;++$calls;return substr($data,$at,$length);}));
    truth($readBytes<=18&&$calls<=3);$data[300010]='X';same('application/octet-stream',sniff232($data));
});
test('0232 truncated reads fail closed rather than accepting a guessed type',static function():void{
    $data='%PDF-1.4'."\n".str_repeat('A',300000)."\n%%EOF\n";
    reject(static function()use($data):void{FileType::detect(substr($data,0,FileType::PROBE_BYTES),strlen($data),static function(){return '';});});
    reject(static function():void{FileType::detect('',1,static function(){return 'x';});});
});
test('0232 valid empty ZIP and a ZIP comment containing a false EOCD',static function():void{
    same('application/zip',sniff232("PK\x05\x06".str_repeat("\0",18)));
    $zip=fixtures232()['zip'];$comment="PK\x05\x06".str_repeat("\0",18);$zip=substr($zip,0,-2).pack('v',strlen($comment)).$comment;
    same('application/zip',sniff232($zip));
});
test('0232 ZIP64 end records are checked against the central directory',static function():void{
    $zip=fixtures232()['zip'];$eocd=substr($zip,-22);$body=substr($zip,0,-22);$end=unpack('Vsize/Voffset',substr($eocd,12,8));
    $record="PK\x06\x06".pack('VVvvVVVVVVVV',44,0,45,45,0,0,1,0,1,0,$end['size'],0).pack('VV',$end['offset'],0);
    $locator="PK\x06\x07".pack('VVVV',0,strlen($body),0,1);
    $footer="PK\x05\x06".pack('vvvvVVv',0,0,65535,65535,4294967295,4294967295,0);
    same('application/zip',sniff232($body.$record.$locator.$footer));
    $record[48]="\xff";same('application/octet-stream',sniff232($body.$record.$locator.$footer));
});
test('0232 Unicode text includes BOM, surrogate pairs and valid probe boundaries',static function():void{
    foreach(["\xef\xbb\xbf".'text',"\xff\xfeA\0\x3d\xd8\x00\xde", "\xfe\xff\0A\xd8\x3d\xde\x00"] as $text){same('text/plain',sniff232($text));}
    foreach(["\xe4\xb8\xad","\xf0\x9f\x8c\xb8"] as $code){for($n=1;$n<strlen($code);++$n){same('text/plain',sniff232(str_repeat('A',FileType::PROBE_BYTES-$n).$code.'tail'));}}
    same('application/octet-stream',sniff232(str_repeat('A',FileType::PROBE_BYTES-1)."\xff".'tail'));
    same('application/octet-stream',sniff232("\xff\xfeA\0\x3d\xd8"));
});
$bad232=[
 'svg'=>'<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', 'php'=>'<?php echo 1;', 'html'=>'<!doctype html><html></html>',
 'elf'=>"\x7fELF\x02\x01\0",'windows'=>'MZ executable', 'shell'=>"#!/bin/sh\necho 1",'binary'=>"text\0binary",'utf8'=>"\xef\xbb\xbf\xc0\xaf",
 'pdf'=>'%PDF-1.4' . "\nno end marker",'zip'=>"PK\x03\x04".str_repeat("\0",50),'mp3'=>'ID3'."\x04\0\0\0\0\0\0",
 'mp4'=>pack('N',24).'ftypisom'.str_repeat("\0",12), 'webp'=>'RIFF'.pack('V',40).'WEBPVP8X'.pack('V',10).str_repeat("\0",10).'JUNK'.pack('V',10).str_repeat('A',10),
];
foreach(['png','gif','jpg','webp'] as $ext){$bad232['truncated-'.$ext]=substr(fixtures232()[$ext],0,-4);}
foreach($bad232 as $name=>$data){test('0232 reject '.$name.' without creating media',static function()use($a,$admin,$tmp,$name,$data):void{
    same('application/octet-stream',sniff232($data));$count=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');$path=$tmp.'/bad232-'.$name;file_put_contents($path,$data);
    reject(static function()use($a,$admin,$path):void{$a->media->storeLocal(account($admin),$path,'fake.png',false,1048576);});same($count,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));
});}
test('0232 dimension limits still reject oversized images',static function()use($a,$admin,$tmp):void{
    $png=fixtures232()['png'];$ihdr=pack('NN',12001,2).substr($png,24,5);$path=$tmp.'/oversized232';file_put_contents($path,substr($png,0,8).pngchunk232('IHDR',$ihdr).substr($png,33));
    reject(static function()use($a,$admin,$path):void{$a->media->storeLocal(account($admin),$path,'too-wide.png',false,1048576);});
});
test('0232 truncated prefixes and random bytes produce no PHP warnings',static function():void{
    set_error_handler(static function($level,$message){throw new RuntimeException($message);});
    try{foreach(fixtures232() as $data){for($n=1;$n<min(80,strlen($data));++$n){sniff232(substr($data,0,$n));}}for($n=1;$n<=128;++$n){sniff232(substr(hash('sha512',(string)$n,true),0,min($n,64)));}}finally{restore_error_handler();}
});

foreach(['ok','changed-tail','wrong-range','wrong-mime'] as $mode){test('0232 object range verification '.$mode,static function()use($a,$admin,$mode):void{
    $saved=[];foreach(['object_storage_enabled','s3_endpoint','s3_region','s3_access','s3_secret','s3_bucket','s3_prefix'] as $key){$saved[$key]=$a->settings->get($key);}
    $cfg=s3config16();$seed=['object_storage_enabled'=>true];foreach(['endpoint','region','access','secret','bucket','prefix'] as $key){$seed['s3_'.$key]=$cfg[$key];}settings('media_plus',$seed);
    try{
        $data=$mode==='wrong-mime'?str_repeat('text',80000):'%PDF-1.4'."\n".str_repeat('A',300000)."\n%%EOF\n";
        $manifest=null;$copied=false;$reads=0;$etag='"range-fixture"';$before=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');
        $http=new \Chengyu\Core\HttpClient(static function($method,$url,$headers)use(&$manifest,$data,$etag,&$copied,&$reads,$mode){
            $final=strpos($url,'/media/')!==false;$head=['Content-Type'=>'application/pdf','Content-Length'=>(string)strlen($data),'ETag'=>$etag,'x-amz-meta-cy-id'=>$manifest['upload_key']];
            if($final){$head['x-amz-meta-cy-source']=hash('sha256',$etag);}
            if($method==='HEAD'){return $final&&!$copied?raw16('',[],404):raw16('',$head);}
            if($method==='GET'){
                same($etag,$headers['if-match']);truth((bool)preg_match('/^bytes=(\d+)-(\d+)$/D',$headers['range'],$match));++$reads;$length=(int)$match[2]-(int)$match[1]+1;
                $head['Content-Length']=(string)$length;$head['Content-Range']='bytes '.$match[1].'-'.$match[2].'/'.strlen($data);
                if($mode==='changed-tail'&&$reads>1){$head['ETag']='"changed"';}if($mode==='wrong-range'){$head['Content-Range']='bytes 1-2/3';}
                return raw16(substr($data,(int)$match[1],$length),$head,206);
            }
            same('PUT',$method);same($etag,$headers['x-amz-copy-source-if-match']);$copied=true;return raw16('<CopyObjectResult><ETag>"copy"</ETag></CopyObjectResult>');
        });
        $objects=new \Chengyu\Services\ObjectStorage($a,$http);$actor=account($admin);$manifest=$objects->prepare($actor,'file.pdf',strlen($data));
        if($mode==='ok'){$id=$objects->finish($actor,$manifest['upload_key']);truth($reads>=2);same($id,$objects->finish($actor,$manifest['upload_key']));same('application/pdf',$a->media->readable($id,$actor)['mime']);}
        else{reject(static function()use($objects,$actor,$manifest):void{$objects->finish($actor,$manifest['upload_key']);},409);same(false,$copied);same($before,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));same(0,(int)$a->db->value('SELECT lease_until FROM cy_remote_uploads WHERE upload_key=?',[$manifest['upload_key']]));}
    }finally{foreach($saved as $key=>$value){if($value===''&&($a->settings->schema()[$key][2]??'')==='secret'){$saved['clear_'.$key]='1';}}settings('media_plus',$saved);}
});}
