<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem,ExternalNavigation};

/** Multiple resource editions, private mirrors and actionable failure reports. */
final class ResourceLibrary
{
    private App $a;
    public function __construct(App $a){$this->a=$a;}
    private static function staff(?array $user): bool{return $user && $user['status']==='active' && in_array($user['role'],['admin','editor'],true);}
    private function enabled(): void{$this->a->settings->requireModule('resource_library');}
    public function save(array $actor,array $in): int
    {
        $this->enabled();if(!self::staff($actor)){throw new Problem('Access denied.',403);}
        $id=Input::integer($in['id']??0);$content=Input::integer($in['content_id']??0,1);$revision=Input::integer($in['revision']??0);
        $media=Input::integer($in['media_id']??0);$mirror=Input::text($in['mirror_url']??'',1800);$code=Input::text($in['extraction_code']??'',100);
        if($mirror!==''){$mirror=ExternalNavigation::target($mirror)['target'];}
        // Exactly one source prevents ambiguous delivery or a silently public fallback.
        if(($media>0)===($mirror!=='')){throw new Problem('Choose exactly one private file or HTTPS mirror.');}
        $data=['content_id'=>$content,'label'=>Input::required($in['label']??'',120),'release_version'=>Input::text($in['release_version']??'',50),'notes'=>Input::text($in['notes']??'',4000),'media_id'=>$media,'mirror_cipher'=>$this->a->crypto->seal($mirror),'code_cipher'=>$this->a->crypto->seal($code),'active'=>empty($in['active'])?0:1,'sort_order'=>Input::integer($in['sort_order']??0,0,10000),'actor_id'=>(int)$actor['id'],'updated_at'=>time(),'revision'=>$revision+1];
        return $this->a->db->transaction(function()use($id,$content,$media,$revision,$data,$actor):int{
            $db=$this->a->db;$parent=$db->one('SELECT id,kind,product_type FROM cy_contents WHERE id=?'.$db->lock(),[$content]);
            if(!$parent || !in_array($parent['kind'],['article','product'],true) || ($parent['kind']==='product' && $parent['product_type']!=='digital')){throw new Problem('Choose an article or a digital product.');}
            $old=$id?$db->one('SELECT * FROM cy_resource_files WHERE id=?'.$db->lock(),[$id]):null;
            if(($id && !$old) || ($old?(int)$old['revision']:0)!==$revision){throw new Problem('Resource changed. Reload before saving.',409);}
            if($old && (int)$old['content_id']!==$content){throw new Problem('A resource keeps its original content.');}
            if(!$old && (int)$db->value('SELECT COUNT(*) FROM cy_resource_files WHERE content_id=?',[$content])>=40){throw new Problem('Maximum 40 resource entries per content.');}
            if($media){$file=$db->one('SELECT id,is_private FROM cy_media WHERE id=?',[$media]);if(!$file || !(int)$file['is_private']){throw new Problem('Choose a private uploaded resource.');}}
            if($old){$db->update('cy_resource_files',$id,$data);}else{$id=$db->insert('cy_resource_files',array_merge($data,['created_at'=>time()]));}
            $this->a->activity->audit((int)$actor['id'],'resource.saved',(string)$id);return $id;
        });
    }
    public function entries(int $content,?array $user): array
    {
        $this->enabled();$item=$this->a->content->get($content,$user);
        if(!$this->a->content->access($item,$user)){return [];}
        // No mirror URL or extraction code is exposed in the public metadata list.
        return $this->a->db->all('SELECT id,content_id,label,release_version,notes,media_id,active,revision,sort_order,updated_at FROM cy_resource_files WHERE content_id=?'.(self::staff($user)?'':' AND active=1').' ORDER BY sort_order,id DESC LIMIT 40',[$content]);
    }
    public function read(int $id,?array $user): array
    {
        $this->enabled();$file=$this->a->db->one('SELECT * FROM cy_resource_files WHERE id=?',[$id]);
        if(!$file || (!(int)$file['active'] && !self::staff($user))){throw new Problem('Resource not found.',404);}
        $item=$this->a->content->get((int)$file['content_id'],$user);
        if(!$this->a->content->access($item,$user)){throw new Problem('Unlock the content before downloading.',403);}
        return $file;
    }
    public function edit(array $actor,int $id): array
    {
        if(!self::staff($actor)){throw new Problem('Access denied.',403);}
        $row=$this->read($id,$actor);$row['mirror_url']=$this->a->crypto->open($row['mirror_cipher']);$row['extraction_code']=$this->a->crypto->open($row['code_cipher']);return $row;
    }
    public function canReadMedia(int $media,?array $user): bool
    {
        if(!$this->a->settings->enabled('resource_library')){return false;}$cursor=0;
        do{$rows=$this->a->db->all('SELECT id FROM cy_resource_files WHERE media_id=? AND active=1 AND id>? ORDER BY id LIMIT 50',[$media,$cursor]);
            foreach($rows as $row){$cursor=(int)$row['id'];try{$this->read($cursor,$user);return true;}catch(Problem $e){continue;}}
        }while(count($rows)===50);return false;
    }
    public function destination(int $id,?array $user,bool $count=true): array
    {
        $file=$this->read($id,$user);
        if((int)$file['media_id']){return ['file'=>$file,'url'=>$this->a->basePath.'/media.php?id='.(int)$file['media_id'],'code'=>'','external'=>false];}
        $url=ExternalNavigation::target($this->a->crypto->open($file['mirror_cipher']))['target'];
        if($count && $user){$this->a->db->transaction(function()use($file,$user):void{
            $db=$this->a->db;$account=$this->a->wallet->lockActive((int)$user['id']);$day=date('Y-m-d');
            if($db->one('SELECT id FROM cy_resource_downloads WHERE resource_id=? AND user_id=? AND day_key=?',[(int)$file['id'],(int)$user['id'],$day])){return;}
            DownloadQuota::check($this->a->db,$this->a->settings,$account);
            $db->insert('cy_resource_downloads',['resource_id'=>(int)$file['id'],'user_id'=>(int)$user['id'],'day_key'=>$day,'created_at'=>time()]);
        });}
        return ['file'=>$file,'url'=>$url,'code'=>$this->a->crypto->open($file['code_cipher']),'external'=>true];
    }
    public function report(array $user,array $in): int
    {
        $this->a->settings->requireModule('resource_reports');$id=Input::integer($in['resource_id']??0,1);$this->read($id,$user);
        if(!empty($user['is_guest'])){throw new Problem('Sign in with a member account.',403);}
        $reason=Input::choice($in['reason']??'',['unavailable','outdated','incorrect','other']);$body=Input::text($in['body']??'',2000);
        return $this->a->db->transaction(function()use($user,$id,$reason,$body):int{
            $db=$this->a->db;$this->a->wallet->lockActive((int)$user['id']);
            $old=$db->one('SELECT * FROM cy_resource_reports WHERE resource_id=? AND user_id=?',[$id,(int)$user['id']]);
            if($old && $old['status']==='open'){return (int)$old['id'];}
            $data=['resource_id'=>$id,'user_id'=>(int)$user['id'],'reason'=>$reason,'body'=>$body,'status'=>'open','resolution'=>'','updated_at'=>time(),'revision'=>($old?(int)$old['revision']:0)+1];
            if($old){$db->update('cy_resource_reports',(int)$old['id'],$data);return (int)$old['id'];}
            return $db->insert('cy_resource_reports',array_merge($data,['created_at'=>time()]));
        });
    }
    public function resolve(array $actor,array $in): void
    {
        if(!self::staff($actor)){throw new Problem('Access denied.',403);}$id=Input::integer($in['id']??0,1);$revision=Input::integer($in['revision']??0,1);$status=Input::choice($in['status']??'',['resolved','dismissed']);$note=Input::required($in['resolution']??'',1000);
        $this->a->db->transaction(function()use($actor,$id,$revision,$status,$note):void{
            $db=$this->a->db;$row=$db->one('SELECT * FROM cy_resource_reports WHERE id=?'.$db->lock(),[$id]);
            if(!$row){throw new Problem('Report not found.',404);}if((int)$row['revision']!==$revision || $row['status']!=='open'){throw new Problem('Report changed. Reload before saving.',409);}
            $db->update('cy_resource_reports',$id,['status'=>$status,'resolution'=>$note,'revision'=>$revision+1,'updated_at'=>time()]);
            $file=$db->one('SELECT content_id,label FROM cy_resource_files WHERE id=?',[(int)$row['resource_id']]);
            $this->a->activity->notify((int)$row['user_id'],'Your resource report has been reviewed',$note,$file?'article&id='.(int)$file['content_id']:'notifications');
            $this->a->activity->audit((int)$actor['id'],'resource.report.'.$status,(string)$id);
        });
    }
}
