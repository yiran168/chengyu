<?php
declare(strict_types=1);
namespace Chengyu;
/** Original optional starter content. No third-party theme data is imported. */
final class Seed
{
    public static function run(App $app, int $admin, bool $examples, string $name): void
    {
        $data=json_decode(file_get_contents(__DIR__.'/seed.json'),true,512,JSON_THROW_ON_ERROR);
        $settings=$data['settings'];$settings['site_name']=$name;
        $app->settings->seed($settings);
        foreach ($data['navigation'] as $i=>$row) {$app->db->insert('cy_navigation',array_merge($row,['sort_order'=>$i*10,'active'=>1,'url'=>'','visibility'=>'public']));}
        $categories=[];
        foreach ($data['categories'] as $key=>$row) {$categories[$key]=$app->db->insert('cy_categories',array_merge($row,['sort_order'=>count($categories)*10,'access_level'=>'public']));}
        if (!$examples) {return;}
        $actor=$app->db->one('SELECT * FROM cy_users WHERE id=?',[$admin]);
        foreach ($data['contents'] as $i=>$row) {
            $row['category_id']=$categories[$row['category']]??0;unset($row['category']);
            $row=array_merge(['status'=>'published','comment_enabled'=>'1','access_level'=>'public','price_currency'=>'balance','price'=>'0','protected_body'=>'','product_type'=>'digital','inventory'=>-1],$row);
            $id=$app->content->save($actor,$row,true);
            $app->db->update('cy_contents',$id,['created_at'=>time()-($i+1)*86400,'updated_at'=>time()-($i+1)*86400]);
        }
        foreach ($data['plans'] as $i=>$row) {$app->db->insert('cy_plans',array_merge($row,['sort_order'=>$i*10,'active'=>1,'price_currency'=>'balance']));}
        $app->activity->audit($admin,'installation.sample_content','original-starter-content');
    }
}
