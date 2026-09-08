<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Database,EncryptedArchive,SnapshotSchema,Problem};
/** Small-host recovery snapshots. Never include server credentials, source code or remote object bytes. */
final class Backups
{
    private App $a;
    public function __construct(App $app){$this->a=$app;}
    private function admin(int $actor): void
    {if(!$this->a->db->one("SELECT id FROM cy_users WHERE id=? AND role='admin' AND status='active'",[$actor])){throw new Problem('Access denied.',403);}}
    private function directory(): string
    {
        $dir=$this->a->config['storage'].'/backups';if(is_link($dir)){throw new Problem('The backup directory must not be a symbolic link.');}
        if(!is_dir($dir)&&!mkdir($dir,0700,true)){throw new \RuntimeException('Cannot create private backup storage.');}return $dir;
    }
    private function locked(callable $fn)
    {
        $dir=$this->directory();$path=$dir.'/lock.php';if(is_link($path)){throw new Problem('Invalid backup lock.');}$h=fopen($path,'c+b');
        if(!$h){throw new \RuntimeException('Cannot open backup lock.');}
        if(!flock($h,LOCK_EX|LOCK_NB)){fclose($h);throw new Problem('Another backup operation is running.',409);}
        try{ftruncate($h,0);fwrite($h,Media::GUARD);fflush($h);return $fn($dir);}
        finally{flock($h,LOCK_UN);fclose($h);}
    }
    public function listing(int $actor): array
    {$this->admin($actor);return $this->a->db->all('SELECT * FROM cy_backup_archives ORDER BY id DESC LIMIT 30');}
    public function file(int $actor,int $id): array
    {
        $this->admin($actor);$row=$this->a->db->one('SELECT * FROM cy_backup_archives WHERE id=?',[$id]);
        if(!$row||!preg_match('/^[a-f0-9]{48}$/D',$row['public_id'])){throw new Problem('Backup not found.',404);}
        $path=$this->directory().'/'.$row['public_id'].'.php';
        if(!is_file($path)||is_link($path)||filesize($path)!==(int)$row['bytes']||!hash_equals($row['checksum'],(string)hash_file('sha256',$path))){throw new Problem('Backup checksum verification failed. Keep other recovery copies.',409);}
        return ['path'=>$path,'record'=>$row];
    }
    public function create(int $actor,string $password,bool $media): int
    {
        $this->admin($actor);EncryptedArchive::password($password);SnapshotSchema::check($this->a->db);
        return $this->locked(function(string $dir)use($actor,$password,$media):int{
            $public=bin2hex(random_bytes(24));$path=$dir.'/'.$public.'.php';$h=fopen($path,'xb');if(!$h){throw new \RuntimeException('Cannot create backup file.');}
            $saved=false;$started=microtime(true);$host=(int)ini_get('max_execution_time');$budget=$host>0?min(20,max(1,$host-8)):20;
            $tick=static function()use($started,$budget):void{if(microtime(true)-$started>$budget){throw new Problem('Backup reached its safe request time budget. Use the host backup for this site.',413);}};
            try {
                if(fwrite($h,Media::GUARD)!==strlen(Media::GUARD)){throw new \RuntimeException('Cannot write backup guard.');}
                $writer=new EncryptedArchive($h,$password,(int)$this->a->settings->get('backup_max_mb')*1048576-strlen(Media::GUARD));
                $this->a->db->snapshot(function(Database $db)use($writer,$tick,$media):void{
                    $writer->add(['type'=>'meta','version'=>App::VERSION,'schema'=>\Chengyu\Core\Migrations::VERSION,'driver'=>$db->driver(),'created_at'=>time(),'url'=>$this->a->config['url'],'secret'=>$this->a->config['secret'],'local_media'=>$media,'tables'=>SnapshotSchema::tables()]);
                    $counts=[];$files=0;$bytes=0;
                    foreach(SnapshotSchema::tables() as $table){
                        $tick();$columns=SnapshotSchema::columns($db,$table);$key=in_array('id',$columns,true)?'id':$columns[0];$counts[$table]=0;$offset=0;
                        do{$rows=$db->all('SELECT * FROM `'.$table.'` ORDER BY `'.$key.'` LIMIT 10 OFFSET ?',[$offset]);
                            foreach($rows as $row){$tick();$writer->add(['type'=>'row','table'=>$table,'data'=>$row]);$counts[$table]++;}
                            $offset+=count($rows);
                        }while(count($rows)===10);
                    }
                    if($media){$after=0;do{$rows=$db->all("SELECT id,file_key,bytes FROM cy_media WHERE backend='local' AND id>? ORDER BY id LIMIT 20",[$after]);
                        foreach($rows as $row){$tick();$after=(int)$row['id'];$key=$row['file_key'];if(!preg_match('/^[a-f0-9]{48}\.php$/D',$key)){throw new Problem('A local media record has an invalid key.');}
                            $source=$this->a->config['storage'].'/blobs/'.$key;
                            if(is_link($source)||!is_file($source)||filesize($source)!==(int)$row['bytes']+strlen(Media::GUARD)){throw new Problem('Local media is missing or incomplete. A complete media backup cannot be created.');}
                            $in=fopen($source,'rb');if(!$in){throw new Problem('Local media cannot be read.');}
                            try{if(fread($in,strlen(Media::GUARD))!==Media::GUARD){throw new Problem('Invalid local media envelope.');}rewind($in);
                                $writer->add(['type'=>'file','name'=>$key,'bytes'=>(int)$row['bytes']+strlen(Media::GUARD)]);$hash=hash_init('sha256');$part=0;$written=0;
                                while(!feof($in)){$tick();$chunk=fread($in,196608);if($chunk===false){throw new Problem('Media backup read failed.');}if($chunk===''){break;}hash_update($hash,$chunk);$written+=strlen($chunk);$writer->add(['type'=>'chunk','part'=>$part++,'data'=>base64_encode($chunk)]);}
                                if($written!==(int)$row['bytes']+strlen(Media::GUARD)){throw new Problem('Local media changed during backup. Retry after uploads finish.');}
                                $writer->add(['type'=>'file_end','sha256'=>hash_final($hash)]);$files++;$bytes+=$written;
                            }finally{fclose($in);}
                        }
                    }while(count($rows)===20);}
                    $writer->add(['type'=>'end','rows'=>$counts,'files'=>$files,'media_bytes'=>$bytes]);
                });
                if(!$writer->complete()||!fflush($h)){throw new \RuntimeException('Incomplete backup.');}fclose($h);$h=null;@chmod($path,0600);
                $id=$this->a->db->transaction(function()use($actor,$public,$path):int{$this->admin($actor);$id=$this->a->db->insert('cy_backup_archives',['public_id'=>$public,'bytes'=>(int)filesize($path),'checksum'=>hash_file('sha256',$path),'actor_id'=>$actor,'created_at'=>time()]);$this->a->activity->audit($actor,'backup.created',(string)$id);return $id;});$saved=true;
                // A failed old-file removal never discards the newly verified archive.
                foreach($this->a->db->all('SELECT id,public_id FROM cy_backup_archives ORDER BY id DESC LIMIT 100 OFFSET ?',[(int)$this->a->settings->get('backup_retention')]) as $old){$oldPath=$dir.'/'.$old['public_id'].'.php';if(preg_match('/^[a-f0-9]{48}$/D',$old['public_id'])&&!is_link($oldPath)&&(!is_file($oldPath)||@unlink($oldPath))){$this->a->db->execute('DELETE FROM cy_backup_archives WHERE id=?',[(int)$old['id']]);}}
                return $id;
            }finally{if(is_resource($h)){fclose($h);}if(!$saved){@unlink($path);}}
        });
    }
    public function remove(int $actor,int $id): void
    {
        $this->admin($actor);$this->locked(function()use($actor,$id):void{$file=$this->file($actor,$id);if(!unlink($file['path'])){throw new Problem('Could not delete the backup file.');}$this->a->db->transaction(function()use($actor,$id):void{$this->a->db->execute('DELETE FROM cy_backup_archives WHERE id=?',[$id]);$this->a->activity->audit($actor,'backup.deleted',(string)$id);});});
    }
    public function download(int $actor,int $id): void
    {
        $file=$this->file($actor,$id);$h=fopen($file['path'],'rb');if(!$h){throw new Problem('Backup is unavailable.',404);}
        try{if(fread($h,strlen(Media::GUARD))!==Media::GUARD){throw new Problem('Invalid backup envelope.');}
            header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="chengyu-'.App::VERSION.'-'.$id.'.cybackup"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Length: '.((int)$file['record']['bytes']-strlen(Media::GUARD)));
            if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}fpassthru($h);
        }finally{fclose($h);}
    }
}
