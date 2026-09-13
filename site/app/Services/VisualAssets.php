<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Icons,Input,Problem};

/** Only source-controlled asset names and already validated public media can render. */
final class VisualAssets
{
    public const ART=[
        'island-anime'=>'Seaside studio','blue-hour'=>'Blue hour bookshop',
        'cover-space'=>'A room for ideas','cover-workshop'=>'Rainy workshop',
        'cover-weekend'=>'Coastal weekend','cover-glass'=>'Glass garden',
        'cover-collection'=>'Inspiration library','cover-launch'=>'Ready for takeoff',
    ];
    public const ICONS=['icon-news'=>'Anime news','icon-discover'=>'Anime discovery','icon-community'=>'Anime community','icon-shop'=>'Anime shop','icon-member'=>'Anime membership','icon-create'=>'Anime writing','icon-archive'=>'Anime archive'];
    public const SCENES=['home'=>'Home illustration','archives'=>'Archive illustration','auth'=>'Sign-in illustration','empty'=>'Empty-state decoration','footer'=>'Footer decoration','favicon'=>'Browser icon','touch'=>'Mobile bookmark icon'];
    public const DEFAULTS=['home'=>'island-anime','archives'=>'blue-hour'];
    public const IMAGE_TYPES=['image/png','image/jpeg','image/webp','image/gif'];
    private App $a;
    private ?array $cache=null;
    public function __construct(App $a){$this->a=$a;}
    public static function slots(): array
    {
        $slots=[];foreach(self::SCENES as $key=>$label){$slots['scene:'.$key]=$label;}
        foreach(Icons::NAMES as $key){$slots['icon:'.$key]='Icon: '.$key;}return $slots;
    }
    private function rows(): array
    {
        if($this->cache===null){
            $this->cache=[];
            // The slot allowlist is finite; one join serves every icon on this request.
            foreach($this->a->db->all('SELECT v.*,m.mime,m.is_private FROM cy_visual_assets v LEFT JOIN cy_media m ON m.id=v.media_id') as $row){$this->cache[$row['slot']]=$row;}
        }return $this->cache;
    }
    public function resetCache(): void {$this->cache=null;}
    public function current(string $slot): array
    {
        Input::choice($slot,array_keys(self::slots()));
        return $this->rows()[$slot]??['slot'=>$slot,'asset_key'=>'','media_id'=>0,'revision'=>0];
    }
    public function image(string $slot): string
    {
        $row=$this->current($slot);
        if(!empty($row['media_id']) && isset($row['is_private']) && !(int)$row['is_private'] && in_array($row['mime'],self::IMAGE_TYPES,true)){
            return $this->a->basePath.'/media.php?id='.(int)$row['media_id'];
        }
        $key=$row['asset_key'];
        return isset((self::ART+self::ICONS)[$key])?$this->art($key):$this->defaultImage($slot);
    }
    public function defaultImage(string $slot): string
    {
        Input::choice($slot,array_keys(self::slots()));
        $key=strpos($slot,'scene:')===0?(self::DEFAULTS[substr($slot,6)]??''):(strpos($slot,'icon:anime-')===0?'icon-'.substr($slot,11):'');
        return isset((self::ART+self::ICONS)[$key])?$this->art($key):'';
    }
    public function art(string $key): string
    {
        Input::choice($key,array_keys(self::ART+self::ICONS));
        return $this->a->basePath.'/assets/art/'.$key.'.webp?v='.App::VERSION;
    }
    public function save(array $actor,array $in): void
    {
        $slot=Input::choice($in['slot']??'',array_keys(self::slots()));
        $revision=Input::integer($in['revision']??0);$operation=Input::choice($in['operation']??'save',['save','reset']);
        $choices=strpos($slot,'icon:')===0?self::ICONS:self::ART+self::ICONS;
        $key=$operation==='reset'?'':Input::choice($in['asset_key']??'',array_merge([''],array_keys($choices)));
        $media=$operation==='reset'?0:Input::integer($in['media_id']??0);
        if($key!=='' && $media){throw new Problem('Choose one public image source.');}
        $this->a->db->transaction(function()use($actor,$slot,$revision,$key,$media):void{
            $db=$this->a->db;
            // Re-read and lock the account: a stale caller cannot retain admin privileges.
            $who=$db->one('SELECT role,status FROM cy_users WHERE id=?'.$db->lock(),[(int)$actor['id']]);
            if(!$who || $who['role']!=='admin' || $who['status']!=='active'){throw new Problem('Access denied.',403);}
            // Serialize creation too, including two administrators saving an unbound slot.
            $db->one('SELECT name FROM cy_settings WHERE name=?'.$db->lock(),['_schema_version']);
            $old=$db->one('SELECT * FROM cy_visual_assets WHERE slot=?'.$db->lock(),[$slot]);
            if(($old?(int)$old['revision']:0)!==$revision){throw new Problem('Visual asset changed. Reload before saving.',409);}
            if($media){$image=$db->one('SELECT mime,is_private FROM cy_media WHERE id=?',[$media]);
                if(!$image || (int)$image['is_private'] || !in_array($image['mime'],self::IMAGE_TYPES,true)){throw new Problem('Choose a public PNG, JPEG, WebP or GIF image.');}}
            $data=['slot'=>$slot,'asset_key'=>$key,'media_id'=>$media,'revision'=>$revision+1,'actor_id'=>(int)$actor['id'],'updated_at'=>time()];
            if($old){$db->update('cy_visual_assets',(int)$old['id'],$data);}else{$db->insert('cy_visual_assets',$data);}
            $this->a->activity->audit((int)$actor['id'],'visual.asset.saved',$slot);
        });
        $this->resetCache();
    }
}
