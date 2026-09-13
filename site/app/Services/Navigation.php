<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Input,Problem,Icons};
/** Shared tree validation, visibility and ordering for every navigation surface. */
final class Navigation
{
    public const MAX_ITEMS=500;
    private Database $db; private Settings $settings; private Activity $activity;
    public function __construct(Database $db,Settings $settings,Activity $activity){$this->db=$db;$this->settings=$settings;$this->activity=$activity;}
    public function save(int $actor,array $input): int
    {
        $id=Input::integer($input['id']??0);$key=Input::text($input['request_key']??'',64);
        if($key!=='' && !preg_match('/^[a-f0-9]{32,64}$/D',$key)){throw new Problem('Invalid request key.');}
        return $this->db->transaction(function()use($actor,$input,$id,$key):int{
            // One existing row serializes tree writes, including different administrators.
            $this->db->one('SELECT name FROM cy_settings WHERE name=?'.$this->db->lock(),['_schema_version']);
            $staff=$this->db->one('SELECT role,status FROM cy_users WHERE id=?'.$this->db->lock(),[$actor]);
            if(!$staff || $staff['role']!=='admin' || $staff['status']!=='active'){throw new Problem('Access denied.',403);}
            $rows=$this->db->all('SELECT * FROM cy_navigation ORDER BY sort_order,id');$map=[];
            foreach($rows as $row){$map[(int)$row['id']]=$row;}
            $old=$map[$id]??null;if($id && !$old){throw new Problem('Record not found.',404);}
            if($id && Input::integer($input['revision']??0)!==(int)$old['revision']){throw new Problem('This menu changed in another window. Reload before saving.',409);}
            $data=['label'=>Input::required($input['label']??'',100),'route'=>Input::choice($input['route']??'home',self::ROUTES),'icon'=>Input::choice($input['icon']??'',array_merge([''],Icons::NAMES)),
                'url'=>Input::url($input['url']??''),'visibility'=>Input::choice($input['visibility']??'public',['public','login','vip','verified']),
                'parent_id'=>Input::integer($input['parent_id']??0),'description'=>Input::text($input['description']??'',160),'image_id'=>Input::integer($input['image_id']??0),
                'panel_style'=>Input::choice($input['panel_style']??'dropdown',['dropdown','columns']),'sort_order'=>Input::integer($input['sort_order']??0,0,9999),'active'=>empty($input['active'])?0:1];
            if($data['route']==='external' && $data['url']===''){throw new Problem('External links require a URL.');}
            if($data['image_id']){$image=$this->db->one('SELECT mime,is_private FROM cy_media WHERE id=?'.$this->db->lock(),[$data['image_id']]);if(!$image || (int)$image['is_private'] || !in_array($image['mime'],VisualAssets::IMAGE_TYPES,true)){throw new Problem('Choose a public image.');}}
            $fingerprint=hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$request=$key!==''?$actor.':'.$key:null;
            if(!$id && $request!==null){$prior=$this->db->one('SELECT id,fingerprint FROM cy_navigation WHERE request_key=?',[$request]);if($prior){if(!hash_equals($prior['fingerprint'],$fingerprint)){throw new Problem('A request key cannot be reused with different content.',409);}return (int)$prior['id'];}}
            if(!$id && count($map)>=self::MAX_ITEMS){throw new Problem('At most 500 menu entries are supported.');}
            $node=$id?:-1;$map[$node]=$data;
            foreach($map as $start=>$row){
                $seen=[];$cursor=$start;
                while($cursor){
                    if(isset($seen[$cursor]) || count($seen)>=3){throw new Problem('Menus cannot contain cycles or exceed three levels.');}
                    if(!isset($map[$cursor])){throw new Problem('Choose an existing parent menu.');}
                    $seen[$cursor]=true;$cursor=(int)$map[$cursor]['parent_id'];
                }
            }
            if($id){$this->db->update('cy_navigation',$id,$data+['revision'=>(int)$old['revision']+1]);}
            else{$id=$this->db->insert('cy_navigation',$data+['request_key'=>$request,'fingerprint'=>$fingerprint]);}
            $this->activity->audit($actor,'navigation.saved',(string)$id);return $id;
        });
    }
    public function visible(?array $user,callable $href): array
    {
        if($user && (!empty($user['is_guest']) || ($user['status']??'active')!=='active')){$user=null;}
        $vip=$user?(new Membership($this->db))->tier($user)>0:false;$map=[];$items=[];
        foreach($this->db->all('SELECT * FROM cy_navigation ORDER BY sort_order,id LIMIT 500') as $row){$map[(int)$row['id']]=$row;}
        foreach($map as $id=>$row){
            $cursor=$id;$seen=[];$allowed=true;
            while($cursor){
                if(isset($seen[$cursor]) || count($seen)>=3 || !isset($map[$cursor])){$allowed=false;break;}
                $seen[$cursor]=true;$ancestor=$map[$cursor];$rule=$ancestor['visibility'];
                if(!(int)$ancestor['active'] || !self::enabled($this->settings,$ancestor['route']) || ($rule!=='public' && !$user) || ($rule==='vip' && !$vip) || ($rule==='verified' && empty($user['verified']))){$allowed=false;break;}
                $cursor=(int)$ancestor['parent_id'];
            }
            if(!$allowed){continue;}
            unset($row['request_key'],$row['fingerprint']);$row['href']=$href($row);$items[]=$row;
        }
        $imageIds=array_values(array_unique(array_filter(array_map(static function(array $r):int{return (int)$r['image_id'];},$items))));$public=[];
        if($imageIds){foreach($this->db->all('SELECT id,mime FROM cy_media WHERE is_private=0 AND id IN ('.implode(',',array_fill(0,count($imageIds),'?')).')',$imageIds) as $image){if(in_array($image['mime'],VisualAssets::IMAGE_TYPES,true)){$public[(int)$image['id']]=true;}}}
        foreach($items as &$item){if(!isset($public[(int)$item['image_id']])){$item['image_id']=0;}}unset($item);
        return $items;
    }
    public static function tree(array $rows): array
    {
        $children=[];foreach($rows as $row){$children[(int)($row['parent_id']??0)][]=$row;}
        $walk=static function(int $parent,int $depth)use(&$walk,$children):array{
            if($depth>=3){return [];}$out=[];
            foreach($children[$parent]??[] as $row){$row['children']=$walk((int)$row['id'],$depth+1);$out[]=$row;}
            return $out;
        };
        return $walk(0,0);
    }
    public static function enabled(\Chengyu\Core\Settings $settings,string $route): bool
    {
        $module=self::MODULES[$route]??null;
        return (!$module || $settings->enabled($module)) && ($route!=='archives' || $settings->enabled('articles'));
    }
    public const ROUTES=['bulletins','home','articles','archives','forum','shop','courses','learning','support','membership','wallet','rankings','favorites','collections','links','tasks','circles','creator','history','following','badges','external'];
    public const MODULES=['bulletins'=>'bulletins','courses'=>'courses','learning'=>'courses','support'=>'support','articles'=>'articles','archives'=>'archives','forum'=>'forum','shop'=>'shop','membership'=>'membership','wallet'=>'wallet','rankings'=>'rankings','favorites'=>'favorites','collections'=>'collections','links'=>'links','tasks'=>'tasks','circles'=>'circles','creator'=>'creator_income','history'=>'reading_history','following'=>'follows','badges'=>'badges'];
}
