<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Input,Settings};

/** Plain-text attribution and shared feed filters; never executes a stored template. */
final class Editorial
{
    public const MODES=['default','original','permission','reprint','none'];
    public static function fields(array $input,array $old=[]): array
    {
        return ['source_name'=>Input::text($input['source_name']??$old['source_name']??'',120),
            'source_url'=>Input::url($input['source_url']??$old['source_url']??''),
            'copyright_mode'=>Input::choice($input['copyright_mode']??$old['copyright_mode']??'default',self::MODES)];
    }
    public static function copyright(Settings $settings,array $item,string $url): string
    {
        $mode=$item['copyright_mode']??'default';
        if($mode==='none' || !in_array($mode,self::MODES,true)){return '';}
        $template=(string)$settings->get('copyright_'.$mode,'');
        return strtr($template,['{author}'=>(string)$item['display_name'],'{title}'=>(string)$item['title'],'{site}'=>(string)$settings->get('site_name'),'{url}'=>$url]);
    }
    public static function reading(Content $content,Settings $settings,array $item,?array $user): array
    {
        $out=['previous'=>null,'next'=>null,'related'=>[]];
        if($item['kind']!=='article' || $item['status']!=='published' || (int)$item['publish_at']>time()){return $out;}
        $filters=['exclude'=>(int)$item['id'],'category'=>(int)$item['category_id'],'category_exact'=>true,'size'=>1];
        if($settings->get('article_neighbors')){
            foreach(['previous','next'] as $direction){$rows=$content->feed('article',$user,$filters+['adjacent'=>['direction'=>$direction,'at'=>(int)$item['created_at'],'id'=>(int)$item['id']]])['items'];$out[$direction]=$rows[0]??null;}
        }
        if($settings->get('article_related')){$out['related']=$content->feed('article',$user,['category'=>(int)$item['category_id'],'category_exact'=>true,'exclude'=>(int)$item['id'],'sort'=>'popular','size'=>4])['items'];}
        return $out;
    }
}
