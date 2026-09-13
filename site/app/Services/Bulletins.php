<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};

/** Short public updates. Publication time is evaluated on read, without a cron worker. */
final class Bulletins
{
    private App $a;
    public function __construct(App $a){$this->a=$a;}
    public function staff(array $actor,bool $lock=false): array
    {
        $row=$this->a->db->one('SELECT * FROM cy_users WHERE id=?'.($lock?$this->a->db->lock():''),[(int)$actor['id']]);
        if(!$row || $row['status']!=='active' || !in_array($row['role'],['admin','editor'],true)){throw new Problem('Access denied.',403);}
        return $row;
    }
    public function save(array $actor,array $input): int
    {
        $this->staff($actor);$id=Input::integer($input['id']??0);$revision=Input::integer($input['revision']??0);
        $key=Input::required($input['request_key']??'',64);
        if(!preg_match('/^[a-f0-9]{32,64}$/D',$key)){throw new Problem('Invalid request key.');}
        $at=Input::required($input['publish_at']??'',40);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$at);
        if(!$date || $date->format('Y-m-d\TH:i')!==$at || (int)$date->format('Y')<1970 || (int)$date->format('Y')>2100){throw new Problem('Invalid publication date.');}
        $data=['title'=>Input::required($input['title']??'',160),'body'=>Input::required($input['body']??'',4000),
            'source_name'=>Input::text($input['source_name']??'',120),'source_url'=>Input::url($input['source_url']??''),
            'cover_id'=>Input::integer($input['cover_id']??0),'status'=>Input::choice($input['status']??'draft',['draft','published','archived']),'publish_at'=>$date->getTimestamp()];
        $fingerprint=hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return $this->a->db->transaction(function()use($actor,$id,$revision,$key,$data,$fingerprint):int{
            $this->staff($actor,true);$db=$this->a->db;
            if($data['cover_id']){$media=$db->one('SELECT mime,is_private FROM cy_media WHERE id=?'.$db->lock(),[$data['cover_id']]);if(!$media || (int)$media['is_private'] || !in_array($media['mime'],VisualAssets::IMAGE_TYPES,true)){throw new Problem('Choose a public image.');}}
            if(!$id){
                $request=(int)$actor['id'].':'.$key;$old=$db->one('SELECT * FROM cy_bulletins WHERE request_key=?',[$request]);
                if($old){if(!hash_equals($old['fingerprint'],$fingerprint)){throw new Problem('A request key cannot be reused with different content.',409);}return (int)$old['id'];}
                $id=$db->insert('cy_bulletins',$data+['actor_id'=>(int)$actor['id'],'request_key'=>$request,'fingerprint'=>$fingerprint,'created_at'=>time(),'updated_at'=>time()]);
            }else{
                $old=$db->one('SELECT * FROM cy_bulletins WHERE id=?'.$db->lock(),[$id]);
                if(!$old){throw new Problem('Bulletin not found.',404);}
                if((int)$old['revision']!==$revision){throw new Problem('This bulletin changed in another window. Reload before saving.',409);}
                $db->update('cy_bulletins',$id,$data+['revision'=>$revision+1,'actor_id'=>(int)$actor['id'],'updated_at'=>time()]);
            }
            $this->a->activity->audit((int)$actor['id'],'bulletin.saved',(string)$id);return $id;
        });
    }
    public function page(array $input=[],?array $staff=null): array
    {
        if($staff){$this->staff($staff);}else{$this->a->settings->requireModule('bulletins');}
        $page=Input::integer($input['page']??1,1,100000);$size=Input::integer($input['size']??20,1,30);
        $period=Input::text($input['period']??'',40);$range=Archives::interval($period);
        $q=Input::text($input['q']??'',100);$status=$staff?Input::choice($input['status']??'',['','draft','published','archived']):'published';
        $where=['1=1'];$params=[];
        if($status!==''){$where[]='b.status=?';$params[]=$status;}
        if(!$staff){$where[]='b.publish_at<=?';$params[]=time();}
        if($range){$where[]='b.publish_at>=? AND b.publish_at<?';array_push($params,$range[0],$range[1]);}
        if($q!==''){$where[]="(b.title LIKE ? ESCAPE '!' OR b.body LIKE ? ESCAPE '!')";$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';array_push($params,$like,$like);}
        $from=' FROM cy_bulletins b WHERE '.implode(' AND ',$where);$total=(int)$this->a->db->value('SELECT COUNT(*)'.$from,$params);
        // Private idempotency keys and actor identities are never included in public feeds.
        $fields='b.id,b.title,b.body,b.source_name,b.source_url,b.publish_at,b.status,b.revision,b.updated_at,CASE WHEN EXISTS(SELECT 1 FROM cy_media m WHERE m.id=b.cover_id AND m.is_private=0) THEN b.cover_id ELSE 0 END AS cover_id';
        $items=$this->a->db->all('SELECT '.$fields.$from.' ORDER BY b.publish_at DESC,b.id DESC LIMIT ? OFFSET ?',array_merge($params,[$size,($page-1)*$size]));
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'q'=>$q,'period'=>$period,'status'=>$status];
    }
    public function get(int $id,?array $staff=null): array
    {
        if($staff){$this->staff($staff);}else{$this->a->settings->requireModule('bulletins');}
        $item=$this->a->db->one('SELECT * FROM cy_bulletins WHERE id=?',[$id]);
        if(!$item || (!$staff && ($item['status']!=='published' || (int)$item['publish_at']>time()))){throw new Problem('Bulletin not found.',404);}
        unset($item['request_key'],$item['fingerprint'],$item['actor_id']);
        if((int)$item['cover_id'] && !$this->a->db->one('SELECT id FROM cy_media WHERE id=? AND is_private=0',[(int)$item['cover_id']])){$item['cover_id']=0;}
        return $item;
    }
}
