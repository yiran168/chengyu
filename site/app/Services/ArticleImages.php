<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Input,Problem,Markdown};

final class ArticleImages
{
    public const MODES=['default','single','gallery','text'];
    public static function ids(string $json): array
    {
        $value=json_decode($json,true);if(!is_array($value)){return [];}$ids=[];
        foreach(array_slice($value,0,4) as $id){if(is_int($id) && $id>0){$ids[$id]=$id;}}
        return array_values($ids);
    }
    public static function publicMap(Database $db,array $ids,bool $lock=false): array
    {
        $ids=array_values(array_unique(array_filter($ids)));$out=[];if(!$ids){return $out;}
        foreach(array_chunk($ids,400) as $batch){
            foreach($db->all('SELECT id,owner_id,mime FROM cy_media WHERE is_private=0 AND id IN ('.implode(',',array_fill(0,count($batch),'?')).')'.($lock?$db->lock():''),$batch) as $row){
                if(in_array($row['mime'],VisualAssets::IMAGE_TYPES,true)){$out[(int)$row['id']]=$row;}
            }
        }
        return $out;
    }
    /** Invoked inside the existing content transaction and revision check. */
    public static function fields(Database $db,array $input,array $old,string $body,int $actor,bool $staff): array
    {
        $layout=Input::choice($input['image_layout']??$old['image_layout']??'default',self::MODES);$explicit=[];$changed=false;
        for($i=1;$i<=4;$i++){if(array_key_exists('gallery_image_'.$i,$input)){$changed=true;}$id=Input::integer($input['gallery_image_'.$i]??0);if($id){$explicit[$id]=$id;}}
        if(!$changed){$explicit=self::ids($old['gallery_ids']??'[]');}else{$explicit=array_values($explicit);}
        $media=self::publicMap($db,$explicit,true);
        foreach($explicit as $id){if(!isset($media[$id]) || (!$staff && (int)$media[$id]['owner_id']!==$actor)){throw new Problem('Gallery images must be public images that you can manage.');}}
        $derived=Markdown::imageIds($body);$public=self::publicMap($db,$derived,true);
        $derived=array_slice(array_values(array_filter($derived,static function(int $id)use($public):bool{return isset($public[$id]);})),0,4);
        return ['image_layout'=>$layout,'gallery_ids'=>json_encode(array_values($explicit)),'body_image_ids'=>json_encode($derived)];
    }
    /** One bounded batch for a feed; protected bodies are never selected or parsed. */
    public static function hydrate(Database $db,array $items): array
    {
        $ids=[];foreach($items as $item){$ids=array_merge($ids,self::ids($item['gallery_ids']??'[]'),self::ids($item['body_image_ids']??'[]'));}
        $public=self::publicMap($db,$ids);
        foreach($items as &$item){$chosen=self::ids($item['gallery_ids']??'[]');if(!$chosen){$chosen=self::ids($item['body_image_ids']??'[]');}$item['public_images']=array_values(array_filter($chosen,static function(int $id)use($public):bool{return isset($public[$id]);}));}unset($item);
        return $items;
    }
    public static function mode(array $item,Settings $settings): string
    {
        if($item['kind']!=='article'){return 'single';}$mode=$item['image_layout']??'default';
        if($mode==='text' || ($mode==='default' && $settings->get('article_thumbnail')==='text')){return 'text';}
        if(($mode==='gallery' || ($mode==='default' && $settings->get('article_multimage'))) && count($item['public_images']??[])>=2){return 'gallery';}
        return 'single';
    }
    public static function render(Database $db,string $body,string $basePath,bool $captions=true): string
    {
        $public=self::publicMap($db,Markdown::imageIds($body));
        return Markdown::render($body,static function(int $id,string $alt)use($public,$basePath,$captions):string{
            if(!isset($public[$id])){return '<span class="image-unavailable">'.Markdown::escape($alt?:'Image unavailable').'</span>';}
            $label=Markdown::escape($alt);$src=Markdown::escape($basePath.'/media.php?id='.$id);
            return '<span class="inline-picture"><img loading="lazy" decoding="async" src="'.$src.'" alt="'.$label.'">'.($captions && $alt!==''?'<span class="image-caption">'.$label.'</span>':'').'</span>';
        });
    }
}
