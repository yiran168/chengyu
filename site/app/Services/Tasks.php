<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Input,Problem};
final class Tasks
{
    public const METRICS=['checkin','comments','posts','profile'];
    private Database $db;private Settings $settings;private Wallet $wallet;private Activity $activity;
    public function __construct(Database $db,Settings $settings,Wallet $wallet,Activity $activity) {$this->db=$db;$this->settings=$settings;$this->wallet=$wallet;$this->activity=$activity;}
    public function save(int $actor,array $input):int
    {
        $id=Input::integer($input['id']??0);$metric=Input::choice($input['metric']??'',self::METRICS);$period=Input::choice($input['period']??'once',['once','daily']);
        if ($metric==='profile' && $period!=='once') {throw new Problem('Profile tasks may only be claimed once.');}
        $data=['name'=>Input::required($input['name']??'',100),'description'=>Input::text($input['description']??'',1000),'metric'=>$metric,'period'=>$period,'target_count'=>Input::integer($input['target_count']??1,1,in_array($metric,['profile','checkin'],true)?1:1000),'reward_points'=>Input::integer($input['reward_points']??0,0,100000),'reward_xp'=>Input::integer($input['reward_xp']??0,0,100000),'active'=>empty($input['active'])?0:1,'sort_order'=>Input::integer($input['sort_order']??0,0,10000)];
        return $this->db->transaction(function()use($actor,$id,$data):int {
            if ($id) {
                $old=$this->db->one('SELECT * FROM cy_tasks WHERE id=?'.$this->db->lock(),[$id]);if(!$old){throw new Problem('Task not found.',404);}
                if ($this->db->one('SELECT id FROM cy_task_claims WHERE task_id=? LIMIT 1',[$id])) {
                    foreach(['metric','period','target_count','reward_points','reward_xp'] as $field) {if((string)$old[$field]!== (string)$data[$field]){throw new Problem('Claimed tasks keep their rules. Disable this task and create another.');}}
                }
                $this->db->update('cy_tasks',$id,$data);
            } else {$id=$this->db->insert('cy_tasks',$data);}
            $this->activity->audit($actor,'task.saved',(string)$id);return $id;
        });
    }
    public function progress(array $task,array $user):array
    {
        $day=date('Y-m-d');$key=$task['period']==='daily'?$day:'once';$since=$task['period']==='daily'?(int)strtotime($day):0;$uid=(int)$user['id'];$count=0;
        switch($task['metric']) {
            case 'checkin':$count=$user['last_checkin']===$day?1:0;break;
            case 'profile':$count=trim($user['bio'])!=='' && trim($user['display_name'])!==''?1:0;break;
            case 'comments':$count=(int)$this->db->value("SELECT COUNT(*) FROM cy_comments WHERE user_id=? AND status='approved' AND created_at>=?",[$uid,$since]);break;
            case 'posts':$count=(int)$this->db->value("SELECT COUNT(*) FROM cy_contents WHERE author_id=? AND kind IN ('article','thread') AND status='published' AND publish_at<=? AND created_at>=?",[$uid,time(),$since]);break;
        }
        return ['count'=>min($count,(int)$task['target_count']),'key'=>$key,'claimed'=>(bool)$this->db->one('SELECT id FROM cy_task_claims WHERE user_id=? AND task_id=? AND period_key=?',[$uid,(int)$task['id'],$key])];
    }
    public function claim(int $user,int $task):void
    {
        $this->settings->requireModule('tasks');
        $this->db->transaction(function()use($user,$task):void {
            $account=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);
            $row=$this->db->one('SELECT * FROM cy_tasks WHERE id=? AND active=1'.$this->db->lock(),[$task]);
            if (!$account || $account['status']!=='active' || !$row) {throw new Problem('Task is unavailable.',404);}
            $progress=$this->progress($row,$account);if($progress['claimed']){return;}
            if ($progress['count']<(int)$row['target_count']) {throw new Problem('Task requirements are not complete.');}
            if((int)$row['reward_points'] && !$this->settings->enabled('points')){throw new Problem('Points are disabled; this reward cannot be claimed.');}
            $id=$this->db->insert('cy_task_claims',['user_id'=>$user,'task_id'=>$task,'period_key'=>$progress['key'],'points'=>(int)$row['reward_points'],'experience'=>(int)$row['reward_xp'],'created_at'=>time()]);
            if ((int)$row['reward_points']) {$this->wallet->adjust($user,'points',(int)$row['reward_points'],'task:'.$id,'Task reward: '.$row['name']);}
            $this->db->execute('UPDATE cy_users SET experience=experience+? WHERE id=?',[(int)$row['reward_xp'],$user]);$this->activity->audit($user,'task.claimed',(string)$task);
        });
    }
}
