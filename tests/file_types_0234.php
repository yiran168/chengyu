<?php
declare(strict_types=1);
use Chengyu\Core\FileType;
use Chengyu\Services\{Media,Uploads};

// Container fixtures deliberately test identification, not codec playback.
function box234(string $type,string $body,bool $wide=false): string {
    return $wide ? pack('N',1).$type.pack('NN',0,16+strlen($body)).$body : pack('N',8+strlen($body)).$type.$body;
}
function videos234(): array {
    $brands='isom'.pack('N',512).'isommp42';$ftyp=box234('ftyp',$brands);$data=box234('mdat','DATA');
    return ['large-padding'=>box234('free',str_repeat('P',300000)).$ftyp.$data,
        'extended-ftyp'=>box234('ftyp',$brands,true).$data,
        'extended-data'=>$ftyp.box234('mdat','DATA',true),
        'to-eof-data'=>$ftyp.pack('N',0).'mdatDATA',
        'init-segment'=>$ftyp.box234('moov',box234('mvex',box234('trex',str_repeat("\0",24)))),
        'compatible-brand'=>box234('ftyp','abcd'.pack('N',0).'mp42').$data];
}
foreach(videos234() as $name=>$data){test('0234 MP4 container '.$name,static function()use($name,$data,$a,$admin,$u,$tmp):void{
    same('video/mp4',sniff232($data));$path=$tmp.'/video234-'.$name;file_put_contents($path,$data);
    $id=$a->media->storeLocal(account($admin),$path,'wrong.jpg',false,1048576);$row=$a->media->readable($id,account($admin));
    same('video/mp4',$row['mime']);same(1,(int)$row['is_private']);same(Media::GUARD.$data,file_get_contents($a->config['storage'].'/blobs/'.$row['file_key']));
    reject(static function()use($a,$u,$path):void{$a->media->storeLocal(account($u),$path,'fake.jpg',false,1048576);});
});}
test('0234 MP4 rejects invalid boxes after a recognized brand',static function():void{
    $ftyp=substr(fixtures232()['mp4'],0,24);
    foreach([$ftyp.str_repeat('X',20),$ftyp.pack('N',100).'mdatDATA',$ftyp.pack('N',1).'mdat'.pack('NN',4294967295,4294967295),
        $ftyp.box234('free','padding'),$ftyp.box234('mdat',''),$ftyp.box234('mdat','DATA').'x',
        $ftyp.$ftyp.box234('mdat','DATA'),box234('ftyp','abcd'.pack('N',0).'abcd').box234('mdat','DATA'),
        pack('N',1).'ftyp'.pack('NN',0,12).str_repeat('A',30)] as $data){same('application/octet-stream',sniff232($data));}
});
test('0234 MP4 skips payloads using bounded exact ranges',static function():void{
    $data=videos234()['large-padding'];$calls=0;$bytes=0;
    same('video/mp4',FileType::detect(substr($data,0,FileType::PROBE_BYTES),strlen($data),static function($at,$n)use($data,&$calls,&$bytes){++$calls;$bytes+=$n;return substr($data,$at,$n);}));
    truth($calls<=4&&$bytes<=64);
    reject(static function()use($data):void{FileType::detect(substr($data,0,FileType::PROBE_BYTES),strlen($data),static function(){return '';});});
    same('application/octet-stream',sniff232(str_repeat(box234('free',''),129).fixtures232()['mp4']));
});
test('0234 MP4 uint64 offsets and excessive range requests remain bounded',static function():void{
    $offset=4294967312;$tail=fixtures232()['mp4'];$head=pack('N',1).'free'.pack('NN',1,16).str_repeat('P',FileType::PROBE_BYTES-16);$requests=0;
    same('video/mp4',FileType::detect($head,$offset+strlen($tail),static function($at,$n)use($offset,$tail,&$requests){++$requests;truth($at>=$offset);return substr($tail,$at-$offset,$n);}));same(3,$requests);
    $data=box234('free',str_repeat('P',300000)).str_repeat(box234('free',''),40).$tail;$requests=0;
    reject(static function()use($data,&$requests):void{FileType::detect(substr($data,0,FileType::PROBE_BYTES),strlen($data),static function($at,$n)use($data,&$requests){++$requests;return substr($data,$at,$n);});});same(32,$requests);
});
test('0234 PNG rejects corrupt IHDR and illegal format fields',static function()use($a,$admin,$tmp):void{
    $base=fixtures232()['png'];$variants=[];$crc=$base;$crc[29]=chr(ord($crc[29])^1);$variants[]=$crc;
    foreach([[8,1],[9,1],[10,1],[11,1],[12,2]] as $change){$ihdr=substr($base,16,13);$ihdr[$change[0]]=chr($change[1]);$variants[]=substr($base,0,8).pngchunk232('IHDR',$ihdr).substr($base,33);}
    foreach($variants as $i=>$data){same('application/octet-stream',sniff232($data));$path=$tmp.'/badpng234-'.$i;file_put_contents($path,$data);reject(static function()use($a,$admin,$path):void{$a->media->storeLocal(account($admin),$path,'bad.png',false,1048576);});}
});
test('0234 malformed MP4 and PNG prefixes emit no PHP warnings',static function():void{
    set_error_handler(static function($level,$message){throw new RuntimeException($message);});
    try{foreach(array_merge(videos234(),['png'=>fixtures232()['png']]) as $data){for($n=1;$n<min(100,strlen($data));++$n){inspect233(substr($data,0,$n));}}}finally{restore_error_handler();}
});
foreach(['payload','guard','missing','truncated'] as $mode){test('0234 resume repairs damaged chunk '.$mode,static function()use($a,$admin,$tmp,$mode):void{
    $actor=account($admin);$data='Original file bytes';$source=$tmp.'/repair234-'.$mode;file_put_contents($source,$data);
    $r=$a->uploads->start($actor,'repair.txt',strlen($data),hash('sha256',$data));$key=$r['upload_key'];$dir=$a->config['storage'].'/chunks/'.$key;$target=$dir.'/0.php';
    try{$a->uploads->put($actor,$key,0,$source);
        if($mode==='missing'){unlink($target);}else{file_put_contents($target,$mode==='guard'?str_repeat('x',strlen(Media::GUARD)).$data:Media::GUARD.($mode==='truncated'?'x':str_repeat('x',strlen($data))));}
        $a->uploads->put($actor,$key,0,$source);same(Media::GUARD.$data,file_get_contents($target));
        $id=$a->uploads->finish($actor,$key);same($id,$a->uploads->finish($actor,$key));same('text/plain',$a->media->readable($id,$actor)['mime']);
    }finally{if($a->uploads->status($actor,$key)['state']==='open'){$a->uploads->cancel($actor,$key);}}
});}
test('0234 failed merge removes corrupt part from resume status and cleans assembly',static function()use($a,$admin,$tmp):void{
    $actor=account($admin);$source=$tmp.'/merge234';$data='Retry after a storage fault';file_put_contents($source,$data);
    $r=$a->uploads->start($actor,'retry.txt',strlen($data));$key=$r['upload_key'];$dir=$a->config['storage'].'/chunks/'.$key;
    try{$a->uploads->put($actor,$key,0,$source);file_put_contents($dir.'/0.php',Media::GUARD.str_repeat('x',strlen($data)));
        reject(static function()use($a,$actor,$key):void{$a->uploads->finish($actor,$key);},409);
        truth(!is_file($dir.'/assembled.php'));same([],$a->uploads->status($actor,$key)['parts']);
        $a->uploads->put($actor,$key,0,$source);$id=$a->uploads->finish($actor,$key);truth($id>0);
    }finally{if($a->uploads->status($actor,$key)['state']==='open'){$a->uploads->cancel($actor,$key);}}
});
foreach(['chunk-write','chunk-flush','assembly-write','assembly-flush'] as $mode){test('0234 disk failure cleanup '.$mode,static function()use($a,$admin,$tmp,$mode):void{
    $actor=account($admin);$source=$tmp.'/fault234';file_put_contents($source,'Disk fault fixture');$r=$a->uploads->start($actor,'fault.txt',filesize($source));$key=$r['upload_key'];$dir=$a->config['storage'].'/chunks/'.$key;
    try{
        $assembly=strpos($mode,'assembly')===0;
        if($assembly){$a->uploads->put($actor,$key,0,$source);}
        $GLOBALS['upload_io_fault']=['operation'=>substr($mode,-5)==='write'?'write':'flush','path'=>$assembly?'/assembled.php':'/part-','after'=>$mode==='chunk-write'?2:1];
        try{reject(static function()use($a,$actor,$key,$source,$assembly):void{if($assembly){$a->uploads->finish($actor,$key);}else{$a->uploads->put($actor,$key,0,$source);}},$assembly?507:503);}finally{unset($GLOBALS['upload_io_fault']);}
        same([],glob($dir.'/part-*.php'));truth(!is_file($dir.'/assembled.php'));
        same($assembly?[0]:[],$a->uploads->status($actor,$key)['parts']);
        $a->uploads->put($actor,$key,0,$source);truth($a->uploads->finish($actor,$key)>0);
    }finally{unset($GLOBALS['upload_io_fault']);if($a->uploads->status($actor,$key)['state']==='open'){$a->uploads->cancel($actor,$key);}}
});}
test('0234 media flush failure leaves no file or database record',static function()use($a,$admin,$tmp):void{
    $source=$tmp.'/flush234';file_put_contents($source,'Media flush fixture');$before=glob($a->config['storage'].'/blobs/*.php');$rows=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');$failed=false;
    $GLOBALS['upload_io_fault']=['operation'=>'flush','path'=>'/blobs/','after'=>1];
    try{$a->media->storeLocal(account($admin),$source,'flush.txt',true,1048576);}catch(RuntimeException $e){$failed=true;}finally{unset($GLOBALS['upload_io_fault']);}
    truth($failed);same($before,glob($a->config['storage'].'/blobs/*.php'));same($rows,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));
});
test('0234 database rollback cleans the copied blob and retry copies only once',static function()use($a,$admin,$tmp):void{
    $source=$tmp.'/rollback234';file_put_contents($source,'Transaction fixture');$before=glob($a->config['storage'].'/blobs/*.php');$rows=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');
    reject(static function()use($a,$admin,$source):void{$a->media->storeLocal(account($admin),$source,'rollback.txt',true,1048576,0,static function(){throw new \Chengyu\Core\Problem('Fixture rollback');});});
    same($before,glob($a->config['storage'].'/blobs/*.php'));same($rows,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));
    $attempts=0;$id=$a->media->storeLocal(account($admin),$source,'retry.txt',true,1048576,0,static function()use(&$attempts){if(++$attempts<3){$e=new PDOException('Simulated deadlock');$e->errorInfo=['40001',1213,'Fixture'];throw $e;}});
    same(3,$attempts);same(count($before)+1,count(glob($a->config['storage'].'/blobs/*.php')));same($rows+1,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));truth($id>0);
});
test('0234 completion transaction failure preserves resumable parts without orphan media',static function()use($a,$admin,$tmp):void{
    $actor=account($admin);$source=$tmp.'/dbfault234';file_put_contents($source,'Database failure fixture');$r=$a->uploads->start($actor,'db.txt',filesize($source));$key=$r['upload_key'];$dir=$a->config['storage'].'/chunks/'.$key;
    $a->uploads->put($actor,$key,0,$source);$before=glob($a->config['storage'].'/blobs/*.php');$rows=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');
    $sql=$a->db->driver()==='mysql'?"CREATE TRIGGER fail_upload234 BEFORE UPDATE ON cy_upload_sessions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture failure'":"CREATE TRIGGER fail_upload234 BEFORE UPDATE ON cy_upload_sessions BEGIN SELECT RAISE(ABORT,'Fixture failure'); END";
    $a->db->raw($sql);$failed=false;
    try{$a->uploads->finish($actor,$key);}catch(PDOException $e){$failed=true;}finally{$a->db->raw('DROP TRIGGER fail_upload234');}
    try{truth($failed);same($before,glob($a->config['storage'].'/blobs/*.php'));same($rows,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));truth(!is_file($dir.'/assembled.php'));same([0],$a->uploads->status($actor,$key)['parts']);truth($a->uploads->finish($actor,$key)>0);}
    finally{if($a->uploads->status($actor,$key)['state']==='open'){$a->uploads->cancel($actor,$key);}}
});
test('0234 all 30 legal PNG color depth and interlace combinations remain uploadable',static function()use($a,$admin,$tmp):void{
    foreach(json_decode(file_get_contents(__DIR__.'/fixtures/png-formats.json'),true,32,JSON_THROW_ON_ERROR) as $name=>$encoded){
        $data=base64_decode($encoded);$result=inspect233($data);same('image/png',$result['mime']);same(1,$result['image'][0]);same(1,$result['image'][1]);
        $path=$tmp.'/png234-'.$name;file_put_contents($path,$data);$id=$a->media->storeLocal(account($admin),$path,'format.png',false,1048576);same('image/png',$a->media->readable($id,null)['mime']);
    }
});
test('0234 indexed PNG needs a valid palette and known critical chunks',static function():void{
    $files=json_decode(file_get_contents(__DIR__.'/fixtures/png-formats.json'),true);$data=base64_decode($files['color-3-depth-1-interlace-0']);
    $palette=substr($data,33,15);$tail=substr($data,48);
    foreach([substr($data,0,33).$tail,substr($data,0,33).$palette.$palette.$tail,
        substr($data,0,33).pngchunk232('PLTE',str_repeat('x',9)).$tail,
        substr($data,0,33).pngchunk232('ABCD','x').$palette.$tail] as $bad){same('application/octet-stream',sniff232($bad));}
    $data[47]=chr(ord($data[47])^1);same('application/octet-stream',sniff232($data));
});
$remote234=fixtures232();$remote234['padded-mp4']=videos234()['large-padding'];
$remote234['bad-png']=substr_replace(fixtures232()['png'],str_repeat("\0",4),29,4);
$remote234['bad-mp4']=substr(fixtures232()['mp4'],0,24).str_repeat('X',20);
$remote234['changed-mp4']=videos234()['large-padding'];
$frames234=[];$gif234=fixtures232()['gif'];$frameAt234=strpos($gif234,',');
$frames234['oversized.gif']=substr_replace($gif234,pack('vv',65535,65535),$frameAt234+5,4);
$frames234['outside.gif']=substr_replace($gif234,pack('v',65535),$frameAt234+1,2);
$variants234=array_map('base64_decode',json_decode(file_get_contents(__DIR__.'/fixtures/file-types-variants.json'),true));
foreach(['webp-alpha','webp-animation'] as $name){$frames234[$name.'.webp']=substr_replace($variants234[$name],str_repeat("\0",6),24,6);}
foreach($frames234 as $name=>$data){test('0234 reject inconsistent first frame '.$name,static function()use($a,$admin,$tmp,$name,$data):void{
    same('application/octet-stream',sniff232($data));$path=$tmp.'/frame234-'.$name;file_put_contents($path,$data);$before=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');
    reject(static function()use($a,$admin,$path,$name):void{$a->media->storeLocal(account($admin),$path,$name,false,1048576);});same($before,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));
});}
$remote234['bad-gif']=$frames234['oversized.gif'];$remote234['bad-webp']=$frames234['webp-animation.webp'];
$remote234['lease-before-copy-mp4']=fixtures232()['mp4'];$remote234['lease-before-register-mp4']=fixtures232()['mp4'];
test('0234 WebP validates frame rectangle and encoded dimensions independently',static function()use($variants234):void{
    $data=$variants234['webp-animation'];$at=strpos($data,'ANMF');truth($at!==false);
    // Keep canvas large enough, but make the frame dimensions disagree with its bitstream.
    $bad=substr_replace($data,"\x01\0\0",$at+10,3);same('application/octet-stream',sniff232($bad));
    $bad=$variants234['webp-alpha'];$bad[20]=chr(ord($bad[20])|128);same('application/octet-stream',sniff232($bad));
    $bad=$variants234['webp-lossless'];$at=strpos($bad,'VP8L');$bad[$at+12]=chr(ord($bad[$at+12])|224);same('application/octet-stream',sniff232($bad));
});
test('0234 GIF permits a smaller frame positioned inside its canvas',static function():void{
    $data=fixtures232()['gif'];$at=strpos($data,',');$data=substr_replace($data,pack('vv',10,10),6,4);$data=substr_replace($data,pack('vv',2,3),$at+1,4);
    $result=inspect233($data);same('image/gif',$result['mime']);same(10,$result['image'][0]);same(10,$result['image'][1]);
});
foreach($remote234 as $name=>$data){test('0234 remote shared policy '.$name,static function()use($a,$admin,$name,$data):void{
    $saved=[];foreach(['object_storage_enabled','s3_endpoint','s3_region','s3_access','s3_secret','s3_bucket','s3_prefix'] as $key){$saved[$key]=$a->settings->get($key);}
    $seed=['object_storage_enabled'=>true];foreach(s3config16() as $key=>$value){if($key!=='token'){$seed['s3_'.$key]=$value;}}settings('media_plus',$seed);
    try{
        $ext=strpos($name,'-')!==false?substr($name,strrpos($name,'-')+1):$name;$mime=FileType::EXTENSIONS[$ext];$manifest=null;$copied=false;$reads=0;$etag='"format234"';$before=(int)$a->db->value('SELECT COUNT(*) FROM cy_media');
        $http=new \Chengyu\Core\HttpClient(static function($method,$url,$headers)use($a,&$manifest,$data,$mime,$etag,&$copied,&$reads,$name){
            $final=strpos($url,'/media/')!==false;$h=['Content-Type'=>$mime,'Content-Length'=>(string)strlen($data),'ETag'=>$etag,'x-amz-meta-cy-id'=>$manifest['upload_key']];
            if($final){$h['x-amz-meta-cy-source']=hash('sha256',$etag);}
            if(($name==='lease-before-copy-mp4'&&$method==='GET')||($name==='lease-before-register-mp4'&&$method==='HEAD'&&$final&&$copied)){
                // Model a new worker taking over after the original lease expired during network IO.
                $a->db->execute('UPDATE cy_remote_uploads SET lease_until=lease_until+1000 WHERE id=?',[(int)$manifest['id']]);
            }
            if($method==='HEAD'){return $final&&!$copied?raw16('',[],404):raw16('',$h);}
            if($method==='GET'){
                same($etag,$headers['if-match']);truth((bool)preg_match('/^bytes=(\d+)-(\d+)$/D',$headers['range'],$m));++$reads;$n=(int)$m[2]-(int)$m[1]+1;
                $h['Content-Length']=(string)$n;$h['Content-Range']='bytes '.$m[1].'-'.$m[2].'/'.strlen($data);
                if($name==='changed-mp4'&&$reads>1){$h['ETag']='"changed"';}return raw16(substr($data,(int)$m[1],$n),$h,206);
            }
            same('PUT',$method);same($etag,$headers['x-amz-copy-source-if-match']);$copied=true;return raw16('<CopyObjectResult><ETag>"copied"</ETag></CopyObjectResult>');
        });
        $objects=new \Chengyu\Services\ObjectStorage($a,$http);$actor=account($admin);$manifest=$objects->prepare($actor,'fixture.'.$ext,strlen($data));
        if(strpos($name,'bad-')===0||$name==='changed-mp4'||strpos($name,'lease-')===0){
            reject(static function()use($objects,$actor,$manifest):void{$objects->finish($actor,$manifest['upload_key']);},409);same($name==='lease-before-register-mp4',$copied);same($before,(int)$a->db->value('SELECT COUNT(*) FROM cy_media'));
            $lease=(int)$a->db->value('SELECT lease_until FROM cy_remote_uploads WHERE id=?',[(int)$manifest['id']]);
            if(strpos($name,'lease-')===0){truth($lease>time()+900);}else{same(0,$lease);}
        }else{$id=$objects->finish($actor,$manifest['upload_key']);same($id,$objects->finish($actor,$manifest['upload_key']));$row=$a->media->readable($id,$actor);same($mime,$row['mime']);same(1,(int)$row['is_private']);truth($copied);}
    }finally{
        if($manifest!==null){$a->db->execute('UPDATE cy_remote_uploads SET expires_at=0 WHERE id=? AND state<>?',[(int)$manifest['id'],'complete']);}
        foreach($saved as $key=>$value){if($value===''&&($a->settings->schema()[$key][2]??'')==='secret'){$saved['clear_'.$key]='1';}}settings('media_plus',$saved);
    }
});}
