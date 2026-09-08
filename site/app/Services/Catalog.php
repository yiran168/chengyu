<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Input,Problem};
/** Small editorial catalogs share lifecycle and validation, not arbitrary table access. */
final class Catalog
{
    public const ENTITIES=['collections','links','announcements'];
    public const ROUTES=['courses','learning','support','landing','home','articles','forum','shop','membership','collections','links','tasks','terms'];
    private Database $db;private Activity $activity;
    public function __construct(Database $db,Activity $activity) {$this->db=$db;$this->activity=$activity;}
    public function save(int $actor,string $entity,array $input):int
    {
        Input::choice($entity,self::ENTITIES);$id=Input::integer($input['id']??0);
        $data=['name'=>Input::required($input['name']??'',100),'description'=>Input::text($input['description']??'',3000),'active'=>empty($input['active'])?0:1,'sort_order'=>Input::integer($input['sort_order']??0,0,10000)];$ids=[];
        if($entity==='links') {$data['url']=Input::url(Input::required($input['url']??'',1000));$data['group_name']=Input::text($input['group_name']??'',100);}
        if($entity==='collections') {
            $raw=Input::text($input['content_ids']??'',2000);
            foreach(preg_split('/[\s,]+/',$raw,-1,PREG_SPLIT_NO_EMPTY) as $value) {$ids[]=Input::integer($value,1);}
            $ids=array_values(array_unique($ids));if(count($ids)>200){throw new Problem('A collection can contain up to 200 items.');}
        }
        if($entity==='announcements') {
            $data['route']=Input::choice($input['route']??'home',self::ROUTES);$data['audience']=Input::choice($input['audience']??'public',['public','login','vip','verified']);
            foreach(['starts_at','ends_at'] as $field){$raw=Input::text($input[$field]??'',40);$data[$field]=$raw!==''?strtotime($raw):0;if($data[$field]===false || $data[$field]<0){throw new Problem('Invalid date.');}}
            if($data['ends_at'] && $data['ends_at']<=max(time(),$data['starts_at'])){throw new Problem('Announcement end must follow its start.');}
        }
        return $this->db->transaction(function()use($actor,$entity,$id,$data,$ids):int {
            if($id){if(!$this->db->one('SELECT id FROM cy_'.$entity.' WHERE id=?'.$this->db->lock(),[$id])){throw new Problem('Record not found.',404);}$this->db->update('cy_'.$entity,$id,$data);}
            else{$id=$this->db->insert('cy_'.$entity,$data);}
            if($entity==='collections') {
                $this->db->execute('DELETE FROM cy_collection_items WHERE collection_id=?',[$id]);
                foreach($ids as $sort=>$content){if(!$this->db->one("SELECT id FROM cy_contents WHERE id=? AND kind IN ('article','thread','product')",[$content])){throw new Problem('Collection contains an unknown content ID.');}$this->db->insert('cy_collection_items',['collection_id'=>$id,'content_id'=>$content,'sort_order'=>$sort]);}
            }
            $this->activity->audit($actor,$entity.'.saved',(string)$id);return $id;
        });
    }
    public function visibleAnnouncements(?array $user,int $limit=3):array
    {
        $levels=['public'];if($user){$levels[]='login';if((int)$user['vip_until']>time()){$levels[]='vip';}if((int)$user['verified']){$levels[]='verified';}}
        return $this->db->all('SELECT * FROM cy_announcements WHERE active=1 AND starts_at<=? AND (ends_at=0 OR ends_at>?) AND audience IN ('.implode(',',array_fill(0,count($levels),'?')).') ORDER BY sort_order,id DESC LIMIT ?',array_merge([time(),time()],$levels,[max(1,min($limit,10))]));
    }
}
