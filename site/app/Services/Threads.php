<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Input, Problem};

/** Polls and question status share the content and comment authorization boundaries. */
final class Threads
{
    private Database $db; private Settings $settings; private Activity $activity;
    public function __construct(Database $db, Settings $settings, Activity $activity)
    { $this->db=$db; $this->settings=$settings; $this->activity=$activity; }
    public function definition(int $id): array
    {
        return $this->db->one('SELECT * FROM cy_threads WHERE content_id=?',[$id])
            ?? ['content_id'=>$id,'mode'=>'discussion','poll_question'=>'','closes_at'=>0,'results_rule'=>'after_vote','accepted_comment_id'=>0,'max_choices'=>1];
    }
    public function options(int $id): array
    { return $this->db->all('SELECT id,label,sort_order FROM cy_poll_options WHERE content_id=? ORDER BY sort_order,id',[$id]); }

    /** Called inside Content::save so malformed poll data cannot leave a half-saved post. */
    public function save(array $actor, int $id, array $input): void
    {
        $old=$this->definition($id);$mode=Input::choice($input['thread_mode']??$old['mode'],['discussion','question','poll']);
        $feature=['question'=>'questions','poll'=>'polls'][$mode]??'';
        if ($feature) { $this->settings->requireModule($feature); }
        if ($mode!==$old['mode'] && $this->db->one('SELECT id FROM cy_bounties WHERE content_id=?',[$id])) { throw new Problem('A question with bounty history cannot change type.',409); }
        $votes=(int)$this->db->value('SELECT COUNT(*) FROM cy_poll_votes WHERE content_id=?',[$id]);
        if ($mode!==$old['mode'] && ($votes>0 || (int)$old['accepted_comment_id']>0)) { throw new Problem('A thread with votes or an accepted answer cannot change type.',409); }
        $data=['mode'=>$mode,'poll_question'=>'','closes_at'=>0,'results_rule'=>'after_vote','max_choices'=>1];$labels=[];
        if ($mode==='poll') {
            $data['poll_question']=Input::required($input['poll_question']??$old['poll_question'],255);
            $data['results_rule']=Input::choice($input['poll_results']??$old['results_rule'],['always','after_vote','closed']);
            $options=$this->options($id);
            $text=Input::text($input['poll_options']??implode("\n",array_column($options,'label')),2200);
            foreach (preg_split('/\r\n|\r|\n/',$text) as $label) { if (trim($label)!=='') { $labels[]=Input::required($label,200); } }
            if (count($labels)<2 || count($labels)>10 || count(array_unique(array_map('strtolower',$labels)))!==count($labels)) { throw new Problem('Use 2-10 different poll options, one per line.'); }
            $data['max_choices']=Input::integer($input['poll_max_choices']??$old['max_choices']??1,1,count($labels));
            if ($votes>0 && $data['max_choices']!==(int)$old['max_choices']) { throw new Problem('The number of choices is locked after the first vote.',409); }
            $date=Input::text($input['poll_closes_at']??'',40);
            $expires=array_key_exists('poll_closes_at',$input)?($date===''?0:strtotime($date)):(int)$old['closes_at'];
            if ($expires===false || ($expires>0 && $expires>time()+366*86400)) { throw new Problem('Invalid poll closing time.'); }
            $data['closes_at']=$expires;
            if ($data['results_rule']==='closed' && $expires<=0) { throw new Problem('Choose a closing time for results visible after closing.'); }
            if ($votes>0 && ($labels!==array_column($options,'label') || $data['poll_question']!==$old['poll_question'] || $data['results_rule']!==$old['results_rule'])) { throw new Problem('Poll question, choices and result privacy are locked after the first vote.',409); }
            if ($votes>0 && $expires!==(int)$old['closes_at']) { throw new Problem('A poll deadline cannot change after voting starts.',409); }
        }
        if ($this->db->one('SELECT content_id FROM cy_threads WHERE content_id=?',[$id])) {
            $this->db->execute('UPDATE cy_threads SET mode=?,poll_question=?,closes_at=?,results_rule=?,max_choices=? WHERE content_id=?',[$data['mode'],$data['poll_question'],$data['closes_at'],$data['results_rule'],$data['max_choices'],$id]);
        } else { $this->db->insert('cy_threads',array_merge(['content_id'=>$id],$data)); }
        if (!$votes) {
            $this->db->execute('DELETE FROM cy_poll_options WHERE content_id=?',[$id]);
            foreach ($labels as $position=>$label) { $this->db->insert('cy_poll_options',['content_id'=>$id,'label'=>$label,'sort_order'=>$position]); }
        }
    }

    public function snapshot(array $item, ?array $user, bool $access): ?array
    {
        if ($item['kind']!=='thread' || !$access) { return null; }
        $row=$this->definition((int)$item['id']);
        if ($row['mode']==='question') {
            if (!$this->settings->enabled('questions')) { return null; }
            if ((int)$row['accepted_comment_id'] && !$this->db->one("SELECT id FROM cy_comments WHERE id=? AND content_id=? AND status='approved'",[(int)$row['accepted_comment_id'],(int)$item['id']])) { $row['accepted_comment_id']=0; }
            $row['bounty']=$this->db->one('SELECT * FROM cy_bounties WHERE content_id=?',[(int)$item['id']]);
            return $row;
        }
        if ($row['mode']!=='poll' || !$this->settings->enabled('polls')) { return null; }
        $row['options']=$this->options((int)$item['id']);
        $vote=$user?$this->db->one('SELECT id,option_id FROM cy_poll_votes WHERE content_id=? AND user_id=?',[(int)$item['id'],(int)$user['id']]):null;
        $row['choices']=$vote?array_map('intval',array_column($this->db->all('SELECT option_id FROM cy_poll_choices WHERE vote_id=? ORDER BY option_id',[(int)$vote['id']]),'option_id')):[];
        $row['choice']=(int)($vote['option_id']??0);$row['closed']=(int)$row['closes_at']>0 && (int)$row['closes_at']<=time();
        $row['can_see_results']=$row['results_rule']==='always' || $row['closed'] || ($row['results_rule']==='after_vote' && $row['choice']>0);
        $row['total']=null;
        if ($row['can_see_results']) {
            $counts=$this->db->all('SELECT c.option_id,COUNT(*) AS votes FROM cy_poll_choices c JOIN cy_poll_votes v ON v.id=c.vote_id WHERE v.content_id=? GROUP BY c.option_id',[(int)$item['id']]);
            $byId=[];$total=0;foreach ($counts as $count) { $byId[(int)$count['option_id']]=(int)$count['votes'];$total+=(int)$count['votes']; }
            $total=(int)$this->db->value('SELECT COUNT(*) FROM cy_poll_votes WHERE content_id=?',[(int)$item['id']]);
            $row['total']=$total;
            foreach ($row['options'] as &$option) { $option['votes']=$byId[(int)$option['id']]??0; $option['percent']=$total?round(100*$option['votes']/$total,1):0; }unset($option);
        }
        return $row;
    }

    public function vote(array $user,int $id,int $option,Content $content): void
    { $this->voteMany($user,$id,[$option],$content); }
    public function voteMany(array $user,int $id,array $options,Content $content): void
    {
        $this->settings->requireModule('polls');$this->activity->rate('poll-vote',(string)$user['id'],30,3600);
        if (!$options || count($options)>10) { throw new Problem('Choose between one and the allowed number of options.'); }
        $normalized=[];foreach($options as $option){$normalized[]=Input::integer($option,1);}
        if (count(array_unique($normalized))!==count($normalized)) {throw new Problem('Duplicate poll option.');}
        sort($normalized,SORT_NUMERIC);
        $this->db->transaction(function()use($user,$id,$normalized,$content):void {
            $account=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[(int)$user['id']]);
            if (!$account || $account['status']!=='active') {throw new Problem('Account unavailable.',403);}
            $this->db->one('SELECT id FROM cy_contents WHERE id=?'.$this->db->lock(),[$id]);
            $item=$content->get($id,$account);$row=$this->definition($id);
            if ($item['kind']!=='thread' || $item['status']!=='published' || (int)$item['publish_at']>time() || !$content->access($item,$account)) {throw new Problem('Unlock the content before voting.',403);}
            if ($row['mode']!=='poll' || ((int)$row['closes_at']>0 && (int)$row['closes_at']<=time())) {throw new Problem('This poll is closed.');}
            if (count($normalized)>(int)$row['max_choices']) {throw new Problem('Too many poll choices.');}
            $valid=array_map('intval',array_column($this->options($id),'id'));
            foreach($normalized as $option){if(!in_array($option,$valid,true)){throw new Problem('Invalid poll option.');}}
            $old=$this->db->one('SELECT id FROM cy_poll_votes WHERE content_id=? AND user_id=?',[$id,(int)$user['id']]);
            if ($old) {
                $choices=array_map('intval',array_column($this->db->all('SELECT option_id FROM cy_poll_choices WHERE vote_id=? ORDER BY option_id',[(int)$old['id']]),'option_id'));
                if ($choices===$normalized) {return;}throw new Problem('Your vote has already been recorded.',409);
            }
            $ballot=$this->db->insert('cy_poll_votes',['content_id'=>$id,'user_id'=>(int)$user['id'],'option_id'=>$normalized[0],'created_at'=>time()]);
            foreach ($normalized as $option) {$this->db->insert('cy_poll_choices',['vote_id'=>$ballot,'option_id'=>$option]);}
            $this->activity->audit((int)$user['id'],'poll.voted',(string)$id);
        });
    }

    public function accept(array $actor, int $id, int $comment, Content $content): void
    {
        $this->settings->requireModule('questions');
        $this->db->transaction(function()use($actor,$id,$comment,$content):void {
            $this->db->one('SELECT id FROM cy_contents WHERE id=?'.$this->db->lock(),[$id]);
            $item=$content->get($id,$actor);$row=$this->definition($id);
            if ($item['kind']!=='thread' || $row['mode']!=='question' || ((int)$item['author_id']!==(int)$actor['id'] && !in_array($actor['role'],['admin','editor'],true))) { throw new Problem('Only the author or an editor can accept an answer.',403); }
            $answer=$this->db->one("SELECT * FROM cy_comments WHERE id=? AND content_id=? AND status='approved' AND parent_id=0".$this->db->lock(),[$comment,$id]);
            if (!$answer) { throw new Problem('Choose an approved top-level answer.'); }
            if ((int)$row['accepted_comment_id']===$comment) { return; }
            (new Bounties($this->db,$this->settings,new Wallet($this->db,$this->settings,$this->activity),$this->activity))->award($id,$answer);
            $this->db->execute('UPDATE cy_threads SET accepted_comment_id=? WHERE content_id=?',[$comment,$id]);
            $this->activity->notify((int)$answer['user_id'],'Your answer was accepted',$item['title'],'article&id='.$id);
            $this->activity->audit((int)$actor['id'],'question.accepted',$id.':'.$comment);
        });
    }
}
