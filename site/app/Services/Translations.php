<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Database,HttpClient,Input,Locale,Problem};
/** Published translations are revision-bound public text. Protected text is never sent upstream. */
final class Translations
{
    private App $a;private HttpClient $http;public function __construct(App $a,?HttpClient $http=null){$this->a=$a;$this->http=$http??new HttpClient();}
    public function save(int $actor,array $in): int
    {
        $id=Input::integer($in['content_id']??0,1);$locale=Input::choice($in['locale']??'',Locale::SUPPORTED);$source=Input::integer($in['source_version']??0,1);$revision=Input::integer($in['revision']??0);$data=['content_id'=>$id,'locale'=>$locale,'title'=>Input::required($in['title']??'',255),'excerpt'=>Input::text($in['excerpt']??'',1000),'body'=>Input::text($in['body']??'',100000),'source_version'=>$source,'status'=>Input::choice($in['status']??'draft',['draft','published']),'updated_at'=>time()];
        return $this->a->db->transaction(function()use($actor,$id,$locale,$source,$revision,$data):int{$db=$this->a->db;$item=$db->one('SELECT * FROM cy_contents WHERE id=?'.$db->lock(),[$id]);if(!$item||(int)$item['edit_version']!==$source){throw new Problem('Source content changed. Review the translation again.',409);}$old=$db->one('SELECT * FROM cy_translations WHERE content_id=? AND locale=?'.$db->lock(),[$id,$locale]);if(($old?(int)$old['revision']:0)!==$revision){throw new Problem('Translation changed in another window.',409);}$data['revision']=$revision+1;if($old){$tid=(int)$old['id'];$db->update('cy_translations',$tid,$data);}else{$tid=$db->insert('cy_translations',$data);}(new SearchIndex($db,$this->a->settings))->sync($id);$this->a->activity->audit($actor,'translation.saved',$id.':'.$locale);return $tid;});
    }
    public function generate(int $actor,int $id,string $source,string $target,int $revision): int
    {
        Input::choice($source,['zh','en']);Input::choice($target,Locale::SUPPORTED);if(substr($target,0,2)===$source){throw new Problem('Choose different source and target languages.');}$item=$this->a->db->one('SELECT * FROM cy_contents WHERE id=?',[$id]);if(!$item){throw new Problem('Content not found.',404);}$endpoint=$this->a->settings->get('translation_endpoint');if($endpoint===''){throw new Problem('Configure a translation endpoint first.',503);}HttpClient::endpoint($endpoint);$draft=['content_id'=>$id,'locale'=>$target,'source_version'=>(int)$item['edit_version'],'status'=>'draft','revision'=>$revision];
        foreach(['title'=>255,'excerpt'=>1000,'body'=>100000] as $field=>$max){if($item[$field]===''){$draft[$field]='';continue;}if(strlen($item[$field])>16000){throw new Problem('This text is too long for automatic translation. Translate it manually.');}$payload=['q'=>$item[$field],'source'=>$source,'target'=>substr($target,0,2),'format'=>'text'];if($this->a->settings->get('translation_key')!==''){$payload['api_key']=$this->a->settings->get('translation_key');}$result=$this->http->jsonBody('POST',$endpoint,$payload);if(!isset($result['translatedText'])||!is_string($result['translatedText'])){throw new Problem('Translation service returned invalid text.',502);}$draft[$field]=Input::text($result['translatedText'],$max);}
        return $this->save($actor,$draft);
    }
    public static function apply(Database $db,array $items,string $locale): array
    {
        if(!$items){return $items;}$ids=array_map('intval',array_column($items,'id'));$columns='content_id,locale,source_version,title,excerpt';foreach($items as $item){if(array_key_exists('body',$item)){$columns.=',body';break;}}$translations=$db->all("SELECT $columns FROM cy_translations WHERE locale=? AND status='published' AND content_id IN (".implode(',',array_fill(0,count($ids),'?')).')',array_merge([$locale],$ids));$map=[];foreach($translations as $t){$map[(int)$t['content_id']]=$t;}
        foreach($items as &$item){$t=$map[(int)$item['id']]??null;if(!$t||(int)$t['source_version']!==(int)($item['edit_version']??0)){continue;}foreach(['title','excerpt','body'] as $field){if(array_key_exists($field,$item)){$item[$field]=$t[$field];}}$item['translation_locale']=$locale;}unset($item);return $items;
    }
}
