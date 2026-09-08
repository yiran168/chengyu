<?php
declare(strict_types=1);
namespace Chengyu\Http;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
use Chengyu\Services\Reconciliation;
/** Explicit allowlist: every new action is authorized here before touching its service. */
final class PlatformActions
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    public function run(string $action,array $in,array $response): ?array
    {
        $permissions=['resource_save'=>'content','resource_resolve'=>'content','backup_create'=>'manage','backup_download'=>'manage','backup_delete'=>'manage','search_remote_rebuild'=>'manage','search_remote_run'=>'manage','search_rebuild'=>'manage','admin_layout'=>'manage','layout_discard'=>'manage','layout_restore'=>'manage','course_save'=>'content','lesson_save'=>'content','tracking_attach'=>'manage','tracking_event'=>'manage','tracking_refresh'=>'manage','statement_import'=>'manage','statement_check'=>'manage','support_template'=>'manage'];
        $member=['resource_report','learning_progress','support_create','support_reply','support_status'];
        if(!isset($permissions[$action]) && !in_array($action,$member,true)){return null;}
        $a=$this->a;$user=isset($permissions[$action])?$a->auth->requirePermission($permissions[$action]):$a->auth->requireUser();$uid=(int)$user['id'];
        $a->activity->rate('platform:'.$action,(string)$uid,$action==='support_create'?20:240,3600);
        switch($action){
            case 'resource_save':$id=$a->resources->save($user,$in);$response['resource_id']=$id;$response['redirect']=$a->adminUrl('resources',['content_id'=>Input::integer($in['content_id'],1)]);break;
            case 'resource_report':$response['report_id']=$a->resources->report($user,$in);$response['message']=tr('Report received. You will be notified after review.');break;
            case 'resource_resolve':$a->resources->resolve($user,$in);$response['redirect']=$a->adminUrl('resources');break;
            case 'backup_create':
                if(empty($in['consent'])){throw new Problem('Confirm that this encrypted archive contains sensitive site data.');}
                $a->auth->prove(Input::passwordValue($in['current_password']??''),Input::text($in['factor_code']??'',100));
                $password=\Chengyu\Core\EncryptedArchive::password($in['backup_password']??'');
                if(!hash_equals($password,\Chengyu\Core\EncryptedArchive::password($in['backup_confirm']??''))){throw new Problem('Backup passwords do not match.');}
                $response['backup_id']=$a->backups->create($uid,$password,!empty($in['include_media']));$response['redirect']=$a->adminUrl('backups');break;
            case 'backup_download':
                $a->auth->prove(Input::passwordValue($in['current_password']??''),Input::text($in['factor_code']??'',100));$a->backups->download($uid,Input::integer($in['backup_id']??0,1));exit;
            case 'backup_delete':
                $a->auth->prove(Input::passwordValue($in['current_password']??''),Input::text($in['factor_code']??'',100));$a->backups->remove($uid,Input::integer($in['backup_id']??0,1));$response['redirect']=$a->adminUrl('backups');break;

            case 'search_remote_rebuild':$response['index']=$a->remoteSearch->rebuild(25,!empty($in['reset']));$response['redirect']=$a->adminUrl('search_index');break;
            case 'search_remote_run':
                $response['tasks']=[];foreach($a->remoteSearch->due(1) as $task){$response['tasks'][]=$a->remoteSearch->run((int)$task['id']);}
                $response['index']=$a->remoteSearch->status();$response['redirect']=$a->adminUrl('search_index');break;
            case 'search_rebuild':$response['index']=$a->search->rebuild(10,!empty($in['reset']));break;
            case 'admin_layout':
                $slot=Input::required($in['slot']??'',64);$json=Input::required($in['document']??'',100000);$revision=Input::integer($in['revision']??0);$draft=Input::integer($in['draft_revision']??0);
                $op=Input::choice($in['operation']??'publish',['publish','draft']);
                if($op==='draft'){$a->layout->draft($uid,$slot,$json,$revision,$draft);$response['message']=tr('Draft saved. The live page is unchanged.');}
                else{$a->layout->publish($uid,$slot,$json,$revision,$draft);}$response['redirect']=$a->adminUrl('builder',['slot'=>$slot]);break;
            case 'layout_discard':$slot=Input::required($in['slot']??'',64);$a->layout->discard($uid,$slot,Input::integer($in['draft_revision']??0,1));$response['redirect']=$a->adminUrl('builder',['slot'=>$slot]);break;
            case 'layout_restore':$slot=Input::required($in['slot']??'',64);$a->layout->restore($uid,$slot,Input::integer($in['history_revision']??0,1),Input::integer($in['revision']??0));$response['redirect']=$a->adminUrl('builder',['slot'=>$slot]);break;
            case 'course_save':$id=$a->learning->saveCourse($user,$in);$response['redirect']=$a->adminUrl('learning',['id'=>$id]);break;
            case 'lesson_save':$a->learning->saveLesson($user,$in);$response['redirect']=$a->adminUrl('learning',['id'=>Input::integer($in['course_id']??0,1)]);break;
            case 'learning_progress':$progress=$a->learning->saveProgress($user,$in);$response['progress']=$progress;$response['redirect']=$a->url('learn',['id'=>(int)$in['lesson_id']]);break;
            case 'tracking_attach':$parcel=$a->tracking->attach($uid,$in);$response['redirect']=$a->adminUrl('logistics',['order_id'=>Input::integer($in['order_id']??0,1),'parcel'=>$parcel]);break;
            case 'tracking_event':$a->tracking->addEvent($uid,Input::integer($in['shipment_id']??0,1),Input::integer($in['revision']??0,1),Reconciliation::timestamp(Input::required($in['event_at']??'',40)),Input::required($in['description']??'',1000));break;
            case 'tracking_refresh':if(empty($in['consent'])){throw new Problem('Confirm sharing the waybill and optional phone with the tracking provider.');}$a->tracking->refresh($uid,Input::integer($in['shipment_id']??0,1),Input::text($in['phone']??'',24));break;
            case 'statement_import':
                $csv=Input::text($in['csv']??'',2097152);$file=$_FILES['statement']??[];
                if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){if(($file['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']??'') || filesize($file['tmp_name'])>2097152){throw new Problem('Statement upload failed or exceeds 2 MiB.');}$csv=(string)file_get_contents($file['tmp_name']);}
                $id=$a->reconciliation->import($uid,Input::required($in['gateway']??'',30),$csv,Reconciliation::timestamp(Input::required($in['starts_at']??'',40)),Reconciliation::timestamp(Input::required($in['ends_at']??'',40)));
                $response['redirect']=$a->adminUrl('reconciliation',['id'=>$id]);break;
            case 'statement_check':$id=Input::integer($in['batch_id']??0,1);$a->reconciliation->check($uid,$id);$response['redirect']=$a->adminUrl('reconciliation',['id'=>$id]);break;
            case 'support_create':$id=$a->support->create($user,$in);$response['redirect']=$a->url('support',['id'=>$id]);break;
            case 'support_reply':$a->support->reply($user,$in);$response['redirect']=$user['role']==='admin'?$a->adminUrl('support',['id'=>Input::integer($in['ticket_id']??0,1)]):$a->url('support',['id'=>(int)$in['ticket_id']]);break;
            case 'support_status':$id=Input::integer($in['ticket_id']??0,1);$a->support->status($user,$id,Input::integer($in['revision']??0,1),Input::required($in['status']??'',20),Input::text($in['priority']??'normal',20));$response['redirect']=$user['role']==='admin'?$a->adminUrl('support',['id'=>$id]):$a->url('support',['id'=>$id]);break;
            case 'support_template':$a->support->template($user,$in);$response['redirect']=$a->adminUrl('support');break;
        }return $response;
    }
}
