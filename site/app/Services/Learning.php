<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
/** A course inherits the live content permission; progress is a learner report, not proof. */
final class Learning
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    private static function staff(?array $user): bool{return $user && in_array($user['role'],['admin','editor'],true);}
    public function saveCourse(array $actor,array $in): int
    {
        if(!self::staff($actor)){throw new Problem('Access denied.',403);}
        $id=Input::integer($in['id']??0);$revision=Input::integer($in['revision']??0);$content=Input::integer($in['content_id']??0,1);
        $data=['content_id'=>$content,'title'=>Input::required($in['title']??'',200),'description'=>Input::text($in['description']??'',4000),'active'=>empty($in['active'])?0:1,'updated_at'=>time()];
        return $this->a->db->transaction(function()use($actor,$id,$revision,$content,$data):int{
            $db=$this->a->db;$parent=$db->one('SELECT * FROM cy_contents WHERE id=?'.$db->lock(),[$content]);
            if(!$parent || !in_array($parent['kind'],['article','product'],true) || ($parent['kind']==='product' && $parent['product_type']!=='digital')){throw new Problem('Choose an article or a digital product as the course access source.');}
            $old=$id?$db->one('SELECT * FROM cy_courses WHERE id=?'.$db->lock(),[$id]):null;
            if(($id && !$old) || ($old?(int)$old['revision']:0)!==$revision){throw new Problem('Course changed. Reload before saving.',409);}
            if($old && (int)$old['content_id']!==$content){throw new Problem('A course keeps its original access source. Create a new course instead.');}
            $other=$db->one('SELECT id FROM cy_courses WHERE content_id=?',[$content]);if($other && (int)$other['id']!==$id){throw new Problem('This content already has a course.');}
            $data['revision']=$revision+1;if($old){$db->update('cy_courses',$id,$data);}else{$id=$db->insert('cy_courses',$data);}
            $this->a->activity->audit((int)$actor['id'],'course.saved',(string)$id);return $id;
        });
    }
    public function saveLesson(array $actor,array $in): int
    {
        if(!self::staff($actor)){throw new Problem('Access denied.',403);}
        $id=Input::integer($in['id']??0);$revision=Input::integer($in['revision']??0);$course=Input::integer($in['course_id']??0,1);$media=Input::integer($in['media_id']??0);
        $data=['course_id'=>$course,'title'=>Input::required($in['title']??'',200),'body'=>Input::text($in['body']??'',50000),'media_id'=>$media,'duration_seconds'=>Input::integer($in['duration_seconds']??0,0,604800),'sort_order'=>Input::integer($in['sort_order']??0,0,10000),'preview'=>empty($in['preview'])?0:1,'active'=>empty($in['active'])?0:1,'updated_at'=>time()];
        return $this->a->db->transaction(function()use($actor,$id,$revision,$course,$media,$data):int{
            $db=$this->a->db;$parent=$db->one('SELECT id FROM cy_courses WHERE id=?'.$db->lock(),[$course]);if(!$parent){throw new Problem('Course not found.',404);}
            $old=$id?$db->one('SELECT * FROM cy_lessons WHERE id=?'.$db->lock(),[$id]):null;
            if(($id && !$old) || ($old?(int)$old['revision']:0)!==$revision){throw new Problem('Lesson changed. Reload before saving.',409);}
            if($old && (int)$old['course_id']!==$course){throw new Problem('A lesson cannot move to another course.');}
            if($media){$file=$db->one('SELECT mime,is_private FROM cy_media WHERE id=?',[$media]);if(!$file || !in_array($file['mime'],['video/mp4','audio/mpeg','application/pdf','application/zip','text/plain'],true) || !(int)$file['is_private']){throw new Problem('Choose a private MP4, MP3, PDF, ZIP or text resource.');}}
            if(!$old && (int)$db->value('SELECT COUNT(*) FROM cy_lessons WHERE course_id=?',[$course])>=500){throw new Problem('A course supports at most 500 lessons.');}
            $data['revision']=$revision+1;if($old){$db->update('cy_lessons',$id,$data);}else{$id=$db->insert('cy_lessons',$data);}
            $this->a->activity->audit((int)$actor['id'],'lesson.saved',(string)$id);return $id;
        });
    }
    public function course(int $id,?array $user): array
    {
        $this->a->settings->requireModule('courses');$course=$this->a->db->one('SELECT * FROM cy_courses WHERE id=?',[$id]);
        if(!$course || (!(int)$course['active'] && !self::staff($user))){throw new Problem('Course not found.',404);}
        $content=$this->a->content->get((int)$course['content_id'],$user);$course['content']=$content;$course['allowed']=$this->a->content->access($content,$user);
        $course['lessons']=$this->a->db->all('SELECT id,title,media_id,duration_seconds,sort_order,preview,active FROM cy_lessons WHERE course_id=?'.(self::staff($user)?'':' AND active=1').' ORDER BY sort_order,id',[$id]);return $course;
    }
    public function lesson(int $id,?array $user): array
    {
        $row=$this->a->db->one('SELECT * FROM cy_lessons WHERE id=?',[$id]);if(!$row || (!(int)$row['active'] && !self::staff($user))){throw new Problem('Lesson not found.',404);}
        $course=$this->course((int)$row['course_id'],$user);if(!(int)$row['preview'] && !$course['allowed']){throw new Problem('This lesson requires access to the course.',403);}
        $row['course']=$course;return $row;
    }
    public function canReadMedia(int $media,?array $user): bool
    {
        if(!$this->a->settings->enabled('courses')){return false;}$cursor=0;
        do{$links=$this->a->db->all('SELECT l.id FROM cy_lessons l JOIN cy_courses c ON c.id=l.course_id WHERE l.media_id=? AND l.active=1 AND c.active=1 AND l.id>? ORDER BY l.id LIMIT 50',[$media,$cursor]);
            foreach($links as $link){$cursor=(int)$link['id'];try{$this->lesson($cursor,$user);return true;}catch(Problem $e){continue;}}
        }while(count($links)===50);return false;
    }
    public function progress(array $user,int $lesson): array
    {
        if(!empty($user['is_guest'])){throw new Problem('Sign in to save learning progress.',403);}$this->lesson($lesson,$user);
        return $this->a->db->one('SELECT * FROM cy_learning_progress WHERE user_id=? AND lesson_id=?',[(int)$user['id'],$lesson])??['position_seconds'=>0,'completed'=>0,'note'=>'','revision'=>0];
    }
    public function saveProgress(array $user,array $in): array
    {
        if(!empty($user['is_guest'])){throw new Problem('Sign in to save learning progress.',403);}
        $lesson=Input::integer($in['lesson_id']??0,1);$revision=Input::integer($in['revision']??0);$position=Input::integer($in['position_seconds']??0,0,604800);$note=Input::text($in['note']??'',10000);$completed=empty($in['completed'])?0:1;
        return $this->a->db->transaction(function()use($user,$lesson,$revision,$position,$note,$completed):array{
            $db=$this->a->db;$account=$db->one('SELECT * FROM cy_users WHERE id=?'.$db->lock(),[(int)$user['id']]);if(!$account || $account['status']!=='active'){throw new Problem('Account unavailable.',403);}
            $item=$this->lesson($lesson,$account);$old=$db->one('SELECT * FROM cy_learning_progress WHERE user_id=? AND lesson_id=?'.$db->lock(),[(int)$user['id'],$lesson]);
            if(($old?(int)$old['revision']:0)!==$revision){throw new Problem('Progress changed in another window. Reload before saving.',409);}
            $data=['user_id'=>(int)$user['id'],'lesson_id'=>$lesson,'position_seconds'=>(int)$item['duration_seconds']>0?min($position,(int)$item['duration_seconds']):$position,'completed'=>$completed,'note'=>$note,'revision'=>$revision+1,'updated_at'=>time()];
            if($old){$db->update('cy_learning_progress',(int)$old['id'],$data);}else{$db->insert('cy_learning_progress',$data);}return $data;
        });
    }
}
