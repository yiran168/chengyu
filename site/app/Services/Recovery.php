<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,SnapshotSchema,SnapshotReader,Migrations,Problem,Settings,Crypto};
/** Offline only. Refuse existing tables and storage; never overwrite a running site. */
final class Recovery
{
    public static function restore(string $archive,string $password,array $database,string $storage,string $configOut): array
    {
        if(PHP_SAPI!=='cli'){throw new Problem('Recovery is available only from the command line.',403);}
        if(file_exists($storage)||is_link($storage)||file_exists($configOut)||is_link($configOut)||!is_dir(dirname($storage))||!is_dir(dirname($configOut))){throw new Problem('Recovery requires a new storage path and a new private configuration file.');}
        // Authenticate the entire archive, including its final manifest, before creating tables.
        $report=SnapshotReader::walk($archive,$password);
        if(($database['driver']??'')!==$report['meta']['driver']){throw new Problem('Restore to the same database engine as the backup. Cross-engine migration is not supported.');}
        $fingerprint=hash_file('sha256',$archive);$db=new Database($database);
        if(SnapshotSchema::present($db)){throw new Problem('The target database is not empty. Recovery will not overwrite it.',409);}
        $stage=$storage.'.restore-'.bin2hex(random_bytes(8));if(!mkdir($stage,0700)||!mkdir($stage.'/blobs',0700)){throw new \RuntimeException('Could not create recovery staging storage.');}
        $handle=null;$promoted=false;$configCreated=false;
        try{
            require_once dirname(__DIR__).'/schema.php';\Chengyu\Schema::create($db);Migrations::upgrade($db,$stage);SnapshotSchema::check($db);
            $columns=[];foreach(SnapshotSchema::tables() as $table){$columns[$table]=SnapshotSchema::columns($db,$table);}
            $db->transaction(static function()use($db,$archive,$password,$stage,$columns,$report,&$handle,$fingerprint):void{
                foreach(SnapshotSchema::TABLES as $table){$db->execute('DELETE FROM `'.$table.'`');}
                SnapshotReader::walk($archive,$password,static function(array $r)use($db,$stage,$columns,&$handle):void{
                    if($r['type']==='row'){$expected=$columns[$r['table']];$actual=array_keys($r['data']);sort($expected);sort($actual);if($expected!==$actual){throw new Problem('Backup columns do not match the current schema.');}$db->insert($r['table'],$r['data']);}
                    elseif($r['type']==='file'){$handle=fopen($stage.'/blobs/'.$r['name'],'xb');if(!$handle){throw new \RuntimeException('Cannot create a recovered media file.');}@chmod($stage.'/blobs/'.$r['name'],0600);}
                    elseif($r['type']==='chunk'){$chunk=base64_decode($r['data'],true);$offset=0;while($offset<strlen($chunk)){$n=fwrite($handle,substr($chunk,$offset));if($n===false||$n===0){throw new \RuntimeException('Incomplete recovered file.');}$offset+=$n;}}
                    elseif($r['type']==='file_end'){fflush($handle);fclose($handle);$handle=null;}
                });
                if(!hash_equals($fingerprint,(string)hash_file('sha256',$archive))){throw new Problem('Backup changed during recovery.');}
                if(Migrations::version($db)!==Migrations::VERSION){throw new Problem('Backup schema marker is invalid.');}
                // Preserve balances and financial state, but never resume remote activity automatically.
                $schema=(new Settings($db,new Crypto($report['meta']['secret'])))->schema();
                $forced=['recovery_hold'=>true,'maintenance'=>true,'search_engine'=>'portable','meili_consent'=>false,'meili_jobs'=>false,'jobs_secret'=>'','email_registration_required'=>false];
                foreach($schema as $key=>$f){if($f[2]==='bool'&&in_array($f[0],['email','epay','codepay','direct_payments','automation','connections'],true)){$forced[$key]=false;}}
                foreach(['tracking_enabled','object_storage_enabled','translation_auto_enabled'] as $key){if(isset($schema[$key])){$forced[$key]=false;}}
                foreach($forced as $key=>$value){$encoded=json_encode($value,JSON_THROW_ON_ERROR);if($db->one('SELECT name FROM cy_settings WHERE name=?',[$key])){$db->execute('UPDATE cy_settings SET value=? WHERE name=?',[$encoded,$key]);}else{$db->insert('cy_settings',['name'=>$key,'value'=>$encoded]);}}
                $db->execute('UPDATE cy_users SET session_version=session_version+1');
                $db->execute("UPDATE cy_search_outbox SET task_uid=-1,task_generation=0,lease_token='',lease_until=0,target_hash='',next_attempt_at=0");
            });
            if(!rename($stage,$storage)){throw new \RuntimeException('Could not promote recovered storage.');}$promoted=true;
            $config=['url'=>$report['meta']['url'],'secret'=>$report['meta']['secret'],'database'=>$database,'storage'=>$storage,'schema_version'=>Migrations::VERSION];
            $out=fopen($configOut,'xb');if(!$out){throw new \RuntimeException('Cannot create the private recovery configuration.');}$configCreated=true;
            try{$data="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";if(fwrite($out,$data)!==strlen($data)||!fflush($out)){throw new \RuntimeException('Incomplete recovery configuration.');}}finally{fclose($out);}@chmod($configOut,0600);
            return SnapshotReader::describe($report)+['maintenance'=>true,'remote_services_disabled'=>true,'all_sessions_revoked'=>true,'note'=>'Database and storage restored. Move the private configuration only after checking URLs, remote storage, callbacks and financial state.'];
        }catch(\Throwable $e){
            if(is_resource($handle)){fclose($handle);}$cleanup=$promoted?$storage:$stage;
            if(is_dir($cleanup.'/blobs')){foreach(glob($cleanup.'/blobs/*.php')?:[] as $p){@unlink($p);}@rmdir($cleanup.'/blobs');}
            @unlink($cleanup.'/migration.lock.php');@rmdir($cleanup);if($configCreated){@unlink($configOut);}
            // MySQL DDL is not transactional: leave the isolated target for operator inspection.
            throw $e;
        }
    }
}
