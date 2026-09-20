<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
/** Bounded, restartable local uploads. Chunks and assembled bytes never become executable files. */
final class Uploads
{
    public const CHUNK=262144;
    private App $a;public function __construct(App $a){$this->a=$a;}
    private function staff(array $user): void {if(!in_array($user['role'],['admin','editor'],true)||$user['status']!=='active'){throw new Problem('Staff access required.',403);}if(!$this->a->settings->get('large_uploads_enabled')){throw new Problem('Resumable uploads are disabled.',403);}}
    private function directory(array $r): string {if(!preg_match('/^[a-f0-9]{48}$/D',$r['upload_key'])){throw new \RuntimeException('Unsafe upload key');}return $this->a->config['storage'].'/chunks/'.$r['upload_key'];}
    private function locked(array $r,callable $fn) { $dir=$this->directory($r);if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir)){throw new Problem('Upload storage is not writable.',503);}$h=fopen($dir.'/lock.php','c+b');if(!$h||!flock($h,LOCK_EX)){if($h){fclose($h);}throw new Problem('Upload is busy.',409);}try{if(fstat($h)['size']===0){fwrite($h,Media::GUARD);}return $fn($dir);}finally{flock($h,LOCK_UN);fclose($h);}}
    private function owned(array $u,string $key): array {$this->staff($u);$key=Input::required($key,48);if(!preg_match('/^[a-f0-9]{48}$/D',$key)){throw new Problem('Upload not found.',404);}$r=$this->a->db->one('SELECT * FROM cy_upload_sessions WHERE upload_key=? AND user_id=?',[$key,(int)$u['id']]);if(!$r){throw new Problem('Upload not found.',404);}return $r;}
    public function start(array $u,string $name,int $bytes,string $hash='',bool $private=true): array
    {
        $this->staff($u);$name=Input::required(basename($name),255);$max=(int)$this->a->settings->get('large_upload_max_mb')*1048576;
        if($bytes<1||$bytes>$max||($hash!==''&&!preg_match('/^[a-f0-9]{64}$/D',$hash))){throw new Problem('Invalid file size or checksum.');}
        $space=@disk_free_space($this->a->config['storage']);if($space!==false&&$space<$bytes*3+10485760){throw new Problem('Insufficient temporary disk space. Use object storage or a smaller file.',507);}
        return $this->a->db->transaction(function()use($u,$name,$bytes,$hash,$private):array{$db=$this->a->db;$db->one('SELECT id FROM cy_users WHERE id=?'.$db->lock(),[(int)$u['id']]);$pending=$db->all('SELECT total_bytes FROM cy_upload_sessions WHERE user_id=? AND state=?',[(int)$u['id'],'open']);$reserved=array_sum(array_column($pending,'total_bytes'));if(count($pending)>=8||$reserved+$bytes>(int)$this->a->settings->get('large_upload_quota_mb')*1048576){throw new Problem('Unfinished upload quota reached. Cancel old uploads first.',429);}$id=$db->insert('cy_upload_sessions',['upload_key'=>bin2hex(random_bytes(24)),'user_id'=>(int)$u['id'],'name'=>$name,'total_bytes'=>$bytes,'chunk_bytes'=>self::CHUNK,'chunk_count'=>(int)ceil($bytes/self::CHUNK),'sha256'=>$hash,'is_private'=>$private?1:0,'state'=>'open','created_at'=>time(),'expires_at'=>time()+86400]);return $this->status($u,$db->value('SELECT upload_key FROM cy_upload_sessions WHERE id=?',[$id]));});
    }
    public function status(array $u,string $key): array {$r=$this->owned($u,$key);$r['parts']=array_map('intval',array_column($this->a->db->all('SELECT part_number FROM cy_upload_parts WHERE upload_id=? ORDER BY part_number',[(int)$r['id']]),'part_number'));unset($r['user_id']);return $r;}
    /** Only the HTTP controller may supply an is_uploaded_file-checked path. */
    public function put(array $u,string $key,int $part,string $source): array
    {
        $r=$this->owned($u,$key);
        return $this->locked($r,function($dir)use($u,$key,$part,$source):array{
            $r=$this->owned($u,$key);
            if($r['state']!=='open'||(int)$r['expires_at']<time()){throw new Problem('Upload is closed or expired.',409);}
            if($part<0||$part>=(int)$r['chunk_count']){throw new Problem('Invalid chunk number.');}
            $expected=min(self::CHUNK,(int)$r['total_bytes']-$part*self::CHUNK);
            // Hash and write the same bounded bytes, even if the input stream changes later.
            $data=@file_get_contents($source,false,null,0,self::CHUNK+1);
            if($data===false||strlen($data)!==$expected){throw new Problem('Chunk size does not match the upload manifest.');}
            $hash=hash('sha256',$data);$target=$dir.'/'.$part.'.php';
            $old=$this->a->db->one('SELECT * FROM cy_upload_parts WHERE upload_id=? AND part_number=?',[(int)$r['id'],$part]);
            if($old){
                if(!hash_equals($old['sha256'],$hash)){throw new Problem('A different chunk already occupies this position.',409);}
                if($this->chunk($target,$expected,$hash)!==null){return ['upload_key'=>$key,'part'=>$part,'state'=>'open'];}
            }
            $tmp=$dir.'/part-'.bin2hex(random_bytes(8)).'.php';$out=@fopen($tmp,'xb');
            if(!$out){throw new Problem('Could not write upload chunk.',503);}
            $error=null;
            try{
                if(fwrite($out,Media::GUARD)!==strlen(Media::GUARD)||fwrite($out,$data)!==$expected||!fflush($out)){throw new Problem('Incomplete chunk write.',503);}
            }catch(\Throwable $e){$error=$e;}finally{fclose($out);}
            if($error!==null){@unlink($tmp);throw $error;}
            if(!@rename($tmp,$target)){@unlink($tmp);throw new Problem('Could not commit upload chunk.',503);}
            @chmod($target,0640);
            if(!$old){$this->a->db->insert('cy_upload_parts',['upload_id'=>(int)$r['id'],'part_number'=>$part,'bytes'=>$expected,'sha256'=>$hash]);}
            return ['upload_key'=>$key,'part'=>$part,'state'=>'open'];
        });
    }
    /** One bounded verifier for resume deduplication and assembly; null means resend this part. */
    private function chunk(string $path,int $expected,string $hash): ?string
    {
        if($expected<1||$expected>self::CHUNK){return null;}$in=@fopen($path,'rb');if(!$in){return null;}
        try{
            if(fread($in,strlen(Media::GUARD))!==Media::GUARD){return null;}
            $data=stream_get_contents($in,self::CHUNK+1);
            return is_string($data)&&strlen($data)===$expected&&hash_equals($hash,hash('sha256',$data))?$data:null;
        }finally{fclose($in);}
    }
    public function finish(array $u,string $key): int
    {
        $r=$this->owned($u,$key);return $this->locked($r,function($dir)use($u,$key):int{$r=$this->owned($u,$key);if($r['state']==='complete'){return (int)$r['media_id'];}if($r['state']!=='open'||(int)$r['expires_at']<time()){throw new Problem('Upload is closed or expired.',409);}$parts=$this->a->db->all('SELECT * FROM cy_upload_parts WHERE upload_id=? ORDER BY part_number',[(int)$r['id']]);if(count($parts)!==(int)$r['chunk_count']){throw new Problem('Some chunks are still missing.',409);}
            // The assembly file lives outside the public application; .php guard protects misconfigured roots.
            $assembly=$dir.'/assembled.php';$out=@fopen($assembly,'wb');if(!$out){throw new Problem('Could not assemble the file.',503);}
            $hash=hash_init('sha256');$error=null;$damaged=false;
            try{
                if(fwrite($out,Media::GUARD)!==strlen(Media::GUARD)){throw new Problem('Assembly storage is full.',507);}
                foreach($parts as $i=>$part){
                    if((int)$part['part_number']!==$i){throw new Problem('Invalid chunk manifest.',409);}
                    $expected=min(self::CHUNK,(int)$r['total_bytes']-$i*self::CHUNK);$path=$dir.'/'.$i.'.php';
                    $data=(int)$part['bytes']===$expected?$this->chunk($path,$expected,$part['sha256']):null;
                    if($data===null){
                        $this->a->db->execute('DELETE FROM cy_upload_parts WHERE upload_id=? AND part_number=?',[(int)$r['id'],$i]);
                        @unlink($path);$damaged=true;continue;
                    }
                    if(fwrite($out,$data)!==strlen($data)){throw new Problem('Assembly storage is full.',507);}hash_update($hash,$data);
                }
                if($damaged){throw new Problem('Stored chunks were damaged. Retry the upload to resend them.',409);}
                if(!fflush($out)){throw new Problem('Assembly storage is full.',507);}
            }catch(\Throwable $e){$error=$e;}finally{fclose($out);}
            if($error!==null){@unlink($assembly);throw $error;}
            $actual=hash_final($hash);if($r['sha256']!==''&&!hash_equals($r['sha256'],$actual)){@unlink($assembly);throw new Problem('Whole-file checksum failed.',409);}
            try{$id=$this->a->media->storeLocal($u,$assembly,$r['name'],(bool)$r['is_private'],(int)$r['total_bytes'],strlen(Media::GUARD),function(int $id)use($r):void{
                $this->a->db->update('cy_upload_sessions',(int)$r['id'],['state'=>'complete','media_id'=>$id]);
            });}finally{@unlink($assembly);}foreach(glob($dir.'/*.php')?:[] as $f){if(basename($f)!=='lock.php'){@unlink($f);}}return $id;});
    }
    public function cancel(array $u,string $key): void {$r=$this->owned($u,$key);$this->locked($r,function($dir)use($u,$key):void{$r=$this->owned($u,$key);if($r['state']==='complete'){throw new Problem('Completed media cannot be deleted through an upload session.',409);}$this->a->db->update('cy_upload_sessions',(int)$r['id'],['state'=>'cancelled']);foreach(glob($dir.'/*.php')?:[] as $f){if(basename($f)!=='lock.php'){@unlink($f);}}$this->a->db->execute('DELETE FROM cy_upload_parts WHERE upload_id=?',[(int)$r['id']]);});}
}
