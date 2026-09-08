<?php
declare(strict_types=1);
namespace Chengyu\Http;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
final class PlatformPages
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    public function show(string $route): bool
    {
        if(!in_array($route,['resource','courses','course','learn','learning','support','tracking'],true)){return false;}
        $a=$this->a;$user=$a->auth->user();$data=['route'=>$route];
        if(in_array($route,['courses','course','learn','learning'],true)){$a->settings->requireModule('courses');}
        if($route==='resource'){
            header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
            $owner=$user??$a->guests->current();$delivery=$a->resources->destination(Input::integer($_GET['id']??0,1),$owner,($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD');
            if(!$delivery['external']){$a->media->stream($a->media->readable((int)$delivery['file']['media_id'],$owner),$owner);return true;}
            render('resource',['route'=>'resource','title'=>$delivery['file']['label'],'delivery'=>$delivery]);return true;
        }
        if($route==='courses'){
            $data['title']=tr('Learning library');$data['feed']=$a->content->feed('all',$user,['courses'=>true,'page'=>Input::integer($_GET['page']??1,1,100000)]);
            $data['courseMap']=[];$ids=array_map('intval',array_column($data['feed']['items'],'id'));if($ids){foreach($a->db->all('SELECT * FROM cy_courses WHERE active=1 AND content_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids) as $course){$data['courseMap'][(int)$course['content_id']]=$course;}}
        }elseif($route==='course'){$data['course']=$a->learning->course(Input::integer($_GET['id']??0,1),$user);$data['title']=$data['course']['title'];}
        elseif($route==='learn'){$data['lesson']=$a->learning->lesson(Input::integer($_GET['id']??0,1),$user);$data['title']=$data['lesson']['title'];$data['progress']=$user?$a->learning->progress($user,(int)$data['lesson']['id']):null;}
        elseif($route==='learning'){
            if(!$user){redirect($a->url('login'));}$data['title']=tr('My learning');$data['items']=[];
            foreach($a->db->all('SELECT p.*,l.title,l.course_id FROM cy_learning_progress p JOIN cy_lessons l ON l.id=p.lesson_id WHERE p.user_id=? ORDER BY p.updated_at DESC LIMIT 100',[(int)$user['id']]) as $row){try{$a->learning->lesson((int)$row['lesson_id'],$user);$data['items'][]=$row;}catch(Problem $e){continue;}}
        }elseif($route==='support'){
            if(!$user){redirect($a->url('login'));}$a->settings->requireModule('support');$data['title']=tr('Support desk');$id=Input::integer($_GET['id']??0);$data['ticket']=$id?$a->support->ticket($user,$id):null;$data['supportPage']=Input::integer($_GET['page']??1,1,100000);
            if(!$id){$data['tickets']=$a->support->listing($user,$data['supportPage']);}
        }else{
            $order=Input::integer($_GET['id']??0,1);$owner=(new CommerceActions($a))->owner($order,0);$data['shipment']=$a->tracking->view($order,$owner,Input::integer($_GET['parcel']??0));$data['parcels']=$a->tracking->parcels($order,$owner);$data['title']=tr('Shipment timeline');
        }
        render($route,$data);return true;
    }
}
