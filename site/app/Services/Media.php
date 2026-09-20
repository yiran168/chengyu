<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Input, Problem, FileType};
final class Media
{
    // Even if web-server deny rules are absent, a blob never executes or exposes its payload as PHP.
    public const GUARD = "<?php http_response_code(404); exit; __halt_compiler();\n";
    private Database $db; private Settings $settings; private Content $content; private string $storage;
    public function __construct(Database $db, Settings $settings, Content $content, string $storage)
    { $this->db = $db; $this->settings = $settings; $this->content = $content; $this->storage = $storage; }
    private ?Learning $learning=null;
    public function learning(Learning $learning): void{$this->learning=$learning;}
    private ?ResourceLibrary $resources=null;
    public function resources(ResourceLibrary $resources): void{$this->resources=$resources;}
    public function upload(array $user, array $file, bool $private): int
    {
        $staff = in_array($user['role'], ['admin', 'editor'], true);
        if (!$staff && !$this->settings->get('user_uploads')) { throw new Problem('Member uploads are disabled.'); }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) { throw new Problem('Upload failed. Check the host upload limit.'); }
        return $this->storeLocal($user,$file['tmp_name'],(string)$file['name'],$private,(int)$this->settings->get('max_upload_mb')*1048576);
    }
    /** Internal ingestion. $onRegistered may update DB state in the same transaction, without external side effects. */
    public function storeLocal(array $user,string $source,string $name,bool $private,int $maximum,int $offset=0,?callable $onRegistered=null): int
    {
        $staff=in_array($user['role'],['admin','editor'],true);
        $size=filesize($source);$bytes=$size===false?false:$size-$offset;
        if($offset!==0 && $offset!==strlen(self::GUARD)){throw new \InvalidArgumentException('Invalid envelope offset');}
        $sample=null;if($offset){$probe=fopen($source,'rb');if(!$probe){throw new Problem('File unavailable.');}try{if(fread($probe,$offset)!==self::GUARD){throw new Problem('Invalid media envelope.');}$sample=fread($probe,FileType::PROBE_BYTES);}finally{fclose($probe);}}
        if ($bytes === false || $bytes < 1 || $bytes > $maximum) { throw new Problem('File exceeds the upload limit.'); }
        $signature=$sample===null?file_get_contents($source,false,null,0,min((int)$bytes,FileType::PROBE_BYTES)):$sample;
        if($signature===false){throw new Problem('File unavailable.');}
        $inspection=FileType::inspect($signature,(int)$bytes,static function(int $start,int $length)use($source,$offset){return file_get_contents($source,false,null,$offset+$start,$length);});
        $mime=$inspection['mime'];
        $images = FileType::IMAGES;
        $allowed = $staff ? array_values(FileType::EXTENSIONS) : $images;
        if (!in_array($mime, $allowed, true)) { throw new Problem('This file type is not allowed. SVG and executable files are blocked.'); }
        if (in_array($mime, $images, true)) {
            $image = $inspection['image'];
            if (!$image || ($image['mime']??'')!==$mime || $image[0]<1 || $image[1]<1 || $image[0] > 12000 || $image[1] > 12000 || $image[0] * $image[1] > 40000000) { throw new Problem('Invalid image or image dimensions are too large.'); }
        } else { $private = true; }
        $key = bin2hex(random_bytes(24)) . '.php'; $dir = $this->storage . '/blobs';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) { throw new \RuntimeException('Could not create media storage'); }
        $path = $dir . '/' . $key;
        $output = fopen($path, 'xb'); $input = fopen($source, 'rb');
        if (!$output || !$input) { if ($output) { fclose($output); } if ($input) { fclose($input); } @unlink($path); throw new \RuntimeException('Could not store media'); }
        $writeError=null;
        try {
            if($offset && fseek($input,$offset)!==0){throw new \RuntimeException('Could not seek source');}
            if (fwrite($output, self::GUARD) !== strlen(self::GUARD) || stream_copy_to_stream($input, $output) !== $bytes) { throw new \RuntimeException('Incomplete media write'); }
            if (!fflush($output)) { throw new \RuntimeException('Incomplete media write'); }
        } catch (\Throwable $e) { $writeError=$e; }
        finally { fclose($input); fclose($output); }
        // Windows cannot unlink an open stream. Close both handles before cleanup.
        if($writeError!==null){@unlink($path);throw $writeError;}
        @chmod($path, 0640);
        try {
            // File copying is outside the retried transaction: a DB retry must not create orphan blobs.
            return $this->db->transaction(function()use($user,$name,$mime,$bytes,$key,$private,$onRegistered):int{
                $id=$this->db->insert('cy_media', ['owner_id' => (int)$user['id'], 'name' => Input::text(basename($name), 255), 'mime' => $mime, 'bytes' => (int)$bytes, 'file_key' => $key, 'is_private' => $private ? 1 : 0, 'created_at' => time()]);
                if($onRegistered!==null){$onRegistered($id);}return $id;
            });
        } catch (\Throwable $e) { @unlink($path); throw $e; }
    }
    public function readable(int $id, ?array $user): array
    {
        $media = $this->db->one('SELECT * FROM cy_media WHERE id=?', [$id]);
        if (!$media) { throw new Problem('File not found.', 404); }
        if (!(int)$media['is_private']) { return $media; }
        if ($user && ((int)$user['id'] === (int)$media['owner_id'] || in_array($user['role'], ['admin', 'editor'], true))) { return $media; }
        // Walk bounded batches, not an arbitrary first-100 permission cutoff.
        $after = 0;
        do {
            $links = $this->db->all('SELECT id FROM cy_contents WHERE resource_id=? AND status=? AND publish_at<=? AND id>? ORDER BY id LIMIT 100', [$id, 'published', time(), $after]);
            foreach ($links as $link) {
                $after = (int)$link['id'];
                try { $item = $this->content->get($after, $user); if ($this->content->access($item, $user)) { return $media; } }
                catch (Problem $e) { continue; }
            }
        } while (count($links) === 100);
        if($this->learning && $this->learning->canReadMedia($id,$user)){return $media;}
        if($this->resources && $this->resources->canReadMedia($id,$user)){return $media;}
        throw new Problem('You do not have access to this file.', 403);
    }
    public function recordDownload(int $id, ?array $user): void
    {
        $media=$this->readable($id,$user);
        if (!$user || !(int)$media['is_private']) {return;}
        $this->db->transaction(function()use($id,$user):void {
            $account=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[(int)$user['id']]);
            if (!$account || $account['status']!=='active') {throw new Problem('Account unavailable.',403);}
            $since=(int)strtotime(date('Y-m-d'));
            if($this->db->one('SELECT id FROM cy_downloads WHERE user_id=? AND media_id=? AND created_at>=?',[(int)$user['id'],$id,$since])){return;}
            DownloadQuota::check($this->db,$this->settings,$account);
            $this->db->insert('cy_downloads',['media_id'=>$id,'user_id'=>(int)$user['id'],'created_at'=>time()]);
        });
    }
    /** Returns a single byte range; multiple ranges are intentionally unsupported. */
    public static function range(string $header, int $bytes): array
    {
        if ($header==='') {return [0,$bytes-1,false];}
        if ($bytes<1 || !preg_match('/^bytes=(\d*)-(\d*)$/D',$header,$m) || ($m[1]==='' && $m[2]==='')) {throw new Problem('Invalid byte range.',416);}
        if ($m[1]==='') {$length=(int)$m[2];if($length<1){throw new Problem('Invalid byte range.',416);}$start=max(0,$bytes-$length);$end=$bytes-1;}
        else {$start=(int)$m[1];$end=$m[2]===''?$bytes-1:min($bytes-1,(int)$m[2]);}
        if ($start>=$bytes || $start>$end) {throw new Problem('Requested range is unavailable.',416);}
        return [$start,$end,true];
    }
    public function stream(array $media, ?array $user=null): void
    {
        if(($media['backend']??'local')==='s3'){if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){$this->recordDownload((int)$media['id'],$user);}header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('Location: '.$GLOBALS['chengyu']->objects->download($media),true,302);return;}
        if (!preg_match('/^[a-f0-9]{48}\.php$/D', $media['file_key'])) {throw new \RuntimeException('Invalid file key');}
        $path=$this->storage.'/blobs/'.$media['file_key'];$bytes=(int)$media['bytes'];
        if(!is_file($path)){throw new Problem('File is missing from storage.',404);}
        $handle=fopen($path,'rb');
        if(!$handle || fread($handle,strlen(self::GUARD))!==self::GUARD || filesize($path)!==$bytes+strlen(self::GUARD)){if($handle){fclose($handle);}throw new \RuntimeException('Invalid file envelope');}
        try{
            try{[$start,$end,$partial]=self::range((string)($_SERVER['HTTP_RANGE']??''),$bytes);}catch(Problem $e){header('Content-Range: bytes */'.$bytes);throw $e;}
            if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){$this->recordDownload((int)$media['id'],$user);}
            $inline=(!(int)$media['is_private'] && strpos($media['mime'],'image/')===0)||in_array($media['mime'],['video/mp4','audio/mpeg'],true);
            if($partial){http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$bytes);}
            header('Accept-Ranges: bytes');header('Content-Type: '.$media['mime']);header('Content-Length: '.($end-$start+1));
            header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="file-'.(int)$media['id'].'"; filename*=UTF-8\'\''.rawurlencode($media['name']));
            header('Cache-Control: '.(!(int)$media['is_private']?'public, max-age=86400':'private, no-store'));
            header("Content-Security-Policy: sandbox; default-src 'none'");
            if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}
            if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD'){return;}
            fseek($handle,strlen(self::GUARD)+$start);$remaining=$end-$start+1;
            while($remaining>0 && !feof($handle) && !connection_aborted()){$chunk=fread($handle,min(1048576,$remaining));if($chunk===false || $chunk===''){break;}echo $chunk;$remaining-=strlen($chunk);}
        }finally{fclose($handle);}
    }
}
