<?php
declare(strict_types=1);
namespace Chengyu\Core;
use Chengyu\Services\Media;
/** Shared structural verifier for inspection and restore; authenticated input is still untrusted. */
final class SnapshotReader
{
    public static function walk(string $path,string $password,?callable $consumer=null): array
    {
        $meta=null;$counts=array_fill_keys(SnapshotSchema::tables(),0);$ended=false;$phase='rows';$order=-1;
        $file=null;$fileHash=null;$part=0;$written=0;$files=0;$bytes=0;$local=[];$seen=[];
        EncryptedArchive::consume($path,$password,static function(array $r)use(&$meta,&$counts,&$ended,&$phase,&$order,&$file,&$fileHash,&$part,&$written,&$files,&$bytes,&$local,&$seen,$consumer):void{
            $type=$r['type'];if($ended){throw new Problem('Records follow the backup completion marker.');}
            if($meta===null){
                if($type!=='meta'||($r['version']??'')!==\Chengyu\App::VERSION||($r['schema']??0)!==Migrations::VERSION||!in_array($r['driver']??'', ['mysql','sqlite'],true)||($r['tables']??null)!==SnapshotSchema::tables()||!is_bool($r['local_media']??null)||!is_int($r['created_at']??null)||!is_string($r['secret']??null)||strlen($r['secret'])<32||strlen($r['secret'])>256||!is_string($r['url']??null)||Input::url($r['url'])===''){throw new Problem('Backup metadata does not match this application release.');}
                $meta=$r;
            }elseif($type==='row'){
                $table=$r['table']??'';$data=$r['data']??null;$position=array_search($table,SnapshotSchema::tables(),true);
                if($phase!=='rows'||!is_string($table)||$position===false||$position<$order||!is_array($data)||!$data||count($data)>256){throw new Problem('Invalid backup table or record ordering.');}$order=$position;
                foreach($data as $key=>$value){if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]*$/D',$key)||(!is_int($value)&&!is_string($value)&&$value!==null)){throw new Problem('Unsupported backup field or value.');}}
                if($table==='cy_media'&&($data['backend']??'local')==='local'){
                    $name=$data['file_key']??'';if(!is_string($name)||!preg_match('/^[a-f0-9]{48}\.php$/D',$name)||isset($local[$name])||!isset($data['bytes'])||!ctype_digit((string)$data['bytes'])){throw new Problem('Invalid local media backup record.');}$local[$name]=(int)$data['bytes']+strlen(Media::GUARD);
                }$counts[$table]++;
            }elseif($type==='file'){
                $name=$r['name']??'';if(!$meta['local_media']||$file!==null||!is_string($name)||!isset($local[$name])||isset($seen[$name])||!is_int($r['bytes']??null)||$r['bytes']!==$local[$name]||$r['bytes']<=strlen(Media::GUARD)||$r['bytes']>EncryptedArchive::ARCHIVE_MAX){throw new Problem('Invalid or duplicate backup media file.');}
                $phase='files';$file=$r;$part=0;$written=0;$fileHash=hash_init('sha256');$seen[$name]=true;
            }elseif($type==='chunk'){
                if($file===null||($r['part']??null)!==$part||!is_string($r['data']??null)||strlen($r['data'])>262144){throw new Problem('Invalid backup media chunk.');}
                $chunk=base64_decode($r['data'],true);if($chunk===false||$chunk===''||strlen($chunk)>196608||$written+strlen($chunk)>$file['bytes']||($part===0&&substr($chunk,0,strlen(Media::GUARD))!==Media::GUARD)){throw new Problem('Invalid media payload or executable file guard.');}
                hash_update($fileHash,$chunk);$part++;$written+=strlen($chunk);
            }elseif($type==='file_end'){
                if($file===null||$written!==$file['bytes']||!is_string($r['sha256']??null)||!hash_equals(hash_final($fileHash),$r['sha256'])){throw new Problem('Backup media checksum mismatch.');}$files++;$bytes+=$written;$file=null;$fileHash=null;
            }elseif($type==='end'){
                if($file!==null||($r['rows']??null)!==$counts||($r['files']??null)!==$files||($r['media_bytes']??null)!==$bytes||($meta['local_media']&&count($seen)!==count($local))){throw new Problem('Backup manifest is incomplete or inconsistent.');}$ended=true;
            }else{throw new Problem('Unknown backup record type.');}
            if($consumer){$consumer($r);}
        });
        if(!$ended||$meta===null){throw new Problem('Incomplete backup.');}
        return ['meta'=>$meta,'rows'=>$counts,'files'=>$files,'media_bytes'=>$bytes,'local_media_records'=>count($local)];
    }
    public static function describe(array $report): array
    {
        return ['application'=>$report['meta']['version'],'schema'=>$report['meta']['schema'],'source_database'=>$report['meta']['driver'],'created_at'=>gmdate(DATE_ATOM,$report['meta']['created_at']),'local_media_included'=>$report['meta']['local_media'],'table_count'=>count($report['rows']),'row_count'=>array_sum($report['rows']),'files'=>$report['files'],'media_bytes'=>$report['media_bytes'],'requires_original_remote_storage'=>true];
    }
}
