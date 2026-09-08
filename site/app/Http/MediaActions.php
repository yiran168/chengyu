<?php
declare(strict_types=1);
namespace Chengyu\Http;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
final class MediaActions
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    public function run(string $action,array $in,array $response): ?array
    {
        $a=$this->a;if(in_array($action,['upload_start','upload_status','upload_chunk','upload_finish','upload_cancel','object_prepare','object_finish'],true)){
            $u=$a->auth->requirePermission('manage');$a->activity->rate('media-session',(string)$u['id'],20000,3600);$key=Input::text($in['upload_key']??'',48);
            switch($action){
                case 'upload_start':$response['upload']=$a->uploads->start($u,Input::required($in['name']??'',255),Input::integer($in['bytes']??0,1,4294967296),Input::text($in['sha256']??'',64),!empty($in['private']));break;
                case 'upload_status':$response['upload']=$a->uploads->status($u,$key);break;
                case 'upload_chunk':$file=$_FILES['file']??[];if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')){throw new Problem('Chunk upload failed.');}$response['upload']=$a->uploads->put($u,$key,Input::integer($in['part']??0),$file['tmp_name']);break;
                case 'upload_finish':$response['media_id']=$a->uploads->finish($u,$key);break;
                case 'upload_cancel':$a->uploads->cancel($u,$key);break;
                case 'object_prepare':$response['upload']=$a->objects->prepare($u,Input::required($in['name']??'',255),Input::integer($in['bytes']??0,1,4294967296));break;
                case 'object_finish':$response['media_id']=$a->objects->finish($u,$key);break;
            }return $response;
        }
        if(in_array($action,['translation_save','translation_generate'],true)){$u=$a->auth->requirePermission('content');$a->activity->rate('translation-edit',(string)$u['id'],60,3600);if($action==='translation_save'){$a->translations->save((int)$u['id'],$in);}else{if(empty($in['consent'])){throw new Problem('Confirm that public text may be sent to the configured translation service.');}$a->activity->rate('translation-api',(string)$u['id'],10,3600);$a->translations->generate((int)$u['id'],Input::integer($in['content_id']??0,1),Input::choice($in['source']??'',['zh','en']),Input::choice($in['locale']??'',\Chengyu\Core\Locale::SUPPORTED),Input::integer($in['revision']??0));}return $response;}return null;
    }
}
