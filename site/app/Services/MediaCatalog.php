<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Input,Problem};

/** Shared, bounded public-image selection for every editor upload field. */
final class MediaCatalog
{
    public const PAGE_SIZE=12;
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    private function actor(int $id): array
    {
        $user=$this->db->one('SELECT id,role,status,is_guest FROM cy_users WHERE id=?',[$id]);
        if(!$user || $user['status']!=='active' || (int)$user['is_guest']){throw new Problem('Access denied.',403);}
        return $user;
    }
    private static function staff(array $user): bool{return in_array($user['role'],['admin','editor'],true);}
    private static function item(array $row): array
    {
        return ['id'=>(int)$row['id'],'name'=>$row['name'],'bytes'=>(int)$row['bytes'],'mime'=>$row['mime']];
    }
    public function listing(int $actor,array $input): array
    {
        $user=$this->actor($actor);$staff=self::staff($user);$scope=Input::choice($input['scope']??'mine',['mine','all']);
        if($scope==='all' && !$staff){throw new Problem('Access denied.',403);}
        $q=Input::text($input['q']??'',100);$before=Input::integer($input['before']??0);
        $where='is_private=0 AND mime IN (?,?,?,?)';$args=VisualAssets::IMAGE_TYPES;
        if($scope==='mine'){$where.=' AND owner_id=?';$args[]=$actor;}
        if($before){$where.=' AND id<?';$args[]=$before;}
        if($q!==''){$where.=" AND name LIKE ? ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';}
        $rows=$this->db->all('SELECT id,name,bytes,mime FROM cy_media WHERE '.$where.' ORDER BY id DESC LIMIT '.(self::PAGE_SIZE+1),$args);
        $more=count($rows)>self::PAGE_SIZE;$rows=array_slice($rows,0,self::PAGE_SIZE);$items=array_map([self::class,'item'],$rows);
        return ['items'=>$items,'has_more'=>$more,'next_before'=>$more?(int)$rows[count($rows)-1]['id']:0,'can_browse_all'=>$staff,'scope'=>$scope];
    }
    public function select(int $actor,int $id): array
    {
        $user=$this->actor($actor);$row=$this->db->one('SELECT id,name,bytes,mime,is_private,owner_id FROM cy_media WHERE id=?',[$id]);
        if(!$row || (int)$row['is_private'] || !in_array($row['mime'],VisualAssets::IMAGE_TYPES,true) || (!self::staff($user) && (int)$row['owner_id']!==$actor)){throw new Problem('Image is no longer available. Choose another image.',404);}
        return self::item($row);
    }
}
