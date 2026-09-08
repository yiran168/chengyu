<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Input, Problem};

/** The same policy protects feeds, single pages, files, purchases and submissions. */
final class Categories
{
    private Database $db; private Settings $settings; private Activity $activity;
    public function __construct(Database $db, Settings $settings, Activity $activity)
    { $this->db=$db; $this->settings=$settings; $this->activity=$activity; }
    public function get(int $id): array
    {
        $row=$this->db->one('SELECT * FROM cy_categories WHERE id=?',[$id]);
        if (!$row) { throw new Problem('Category not found.',404); } return $row;
    }
    private function context(?array $user): array
    {
        $rows=[]; foreach ($this->db->all('SELECT * FROM cy_categories') as $row) { $rows[(int)$row['id']]=$row; }
        $members=[];$blocked=[];
        if ($user) {
            foreach ($this->db->all("SELECT item_id FROM cy_orders WHERE user_id=? AND kind='circle' AND status IN ('paid','refund_requested') AND (access_until=0 OR access_until>?)",[(int)$user['id'],time()]) as $row) { $members[(int)$row['item_id']]=true; }
            foreach ($this->db->all('SELECT * FROM cy_circle_members WHERE user_id=?',[(int)$user['id']]) as $row) {
                $blocked[(int)$row['category_id']]=(bool)$row['blocked'];
                if ((int)$row['granted_until']>time()) { $members[(int)$row['category_id']]=true; }
            }
        }
        return ['rows'=>$rows,'members'=>$members,'blocked'=>$blocked,'tier'=>$user?(new Membership($this->db))->tier($user):0];
    }
    private function evaluate(int $id, ?array $user, string $action, array $context, array $seen=[]): bool
    {
        if (!$id) { return $action==='read' || $user!==null; }
        if (isset($seen[$id]) || count($seen)>=8 || !isset($context['rows'][$id])) { return false; }
        $seen[$id]=true;$row=$context['rows'][$id];
        if (!empty($context['blocked'][$id])) { return false; }
        $rule=$action==='post'?$row['post_level']:$row['access_level'];
        $allowed=$rule==='public' || ($rule==='login' && $user!==null) || ($rule==='vip' && $context['tier']>=(int)$row['min_vip_tier']) || ($rule==='members' && !empty($context['members'][$id]));
        if (!$allowed) { return false; }
        // A permissive child must never make a restricted ancestor public.
        return $this->evaluate((int)$row['parent_id'],$user,$action,$context,$seen);
    }
    public function allowed(int $id, ?array $user, string $action='read'): bool
    {
        Input::choice($action,['read','post']);
        if($user && !empty($user['is_guest'])){$user=null;}
        if ($user && in_array($user['role'],['admin','editor'],true)) { return true; }
        return $this->evaluate($id,$user,$action,$this->context($user));
    }
    public function visibleIds(?array $user): array
    {
        if($user && !empty($user['is_guest'])){$user=null;}
        $context=$this->context($user);$ids=[0];
        foreach ($context['rows'] as $id=>$row) { if ($this->evaluate($id,$user,'read',$context)) { $ids[]=$id; } }
        return $ids;
    }
    public function member(int $id, int $user): bool
    {
        $special=$this->db->one('SELECT * FROM cy_circle_members WHERE category_id=? AND user_id=?',[$id,$user]);
        if ($special && (int)$special['blocked']) { return false; }
        if ($special && (int)$special['granted_until']>time()) { return true; }
        return (bool)$this->db->one("SELECT id FROM cy_orders WHERE kind='circle' AND item_id=? AND user_id=? AND status IN ('paid','refund_requested') AND (access_until=0 OR access_until>?) LIMIT 1",[$id,$user,time()]);
    }
    public function save(int $actor, array $input): int
    {
        $id=Input::integer($input['id']??0);$old=$id?$this->get($id):[];
        $kind=Input::choice($input['kind']??$old['kind']??'thread',['article','thread','product']);
        $circle=!empty($input['is_circle']);
        if ($circle && $kind!=='thread') { throw new Problem('Only forum categories can be circles.'); }
        $level=Input::choice($input['access_level']??'public',['public','login','vip','members']);
        $post=Input::choice($input['post_level']??'login',['login','vip','members','staff']);
        if (!$circle && ($level==='members'||$post==='members')) { throw new Problem('Member-only access requires a circle.'); }
        $currency=Input::choice($input['join_currency']??'points',['balance','points','tokens']);
        $data=['name'=>Input::required($input['name']??'',100),'kind'=>$kind,'description'=>Input::text($input['description']??'',2000),
            'sort_order'=>Input::integer($input['sort_order']??0,0,99999),'icon'=>Input::choice($input['icon']??'layers',['layers','book','code','sparkles','message','box','leaf','compass','star','heart','shield']),
            'access_level'=>$level,'post_level'=>$post,'parent_id'=>Input::integer($input['parent_id']??0),'min_vip_tier'=>Input::integer($input['min_vip_tier']??1,1,3),
            'is_circle'=>$circle?1:0,'join_currency'=>$currency,'join_amount'=>$currency==='balance'?Input::cents($input['join_price']??'0',true):Input::integer($input['join_price']??0,0,100000000),
            'join_days'=>Input::integer($input['join_days']??30,0,3650),'join_open'=>!empty($input['join_open'])?1:0];
        return $this->db->transaction(function()use($id,$actor,$old,$data):int {
            // Serialize tree writes, including two concurrent parents being made each other's child.
            $this->db->all('SELECT id FROM cy_categories ORDER BY id'.$this->db->lock());
            $current=$id?$this->get($id):null;
            if ($current && $current['kind']!==$data['kind'] && ($this->db->one('SELECT id FROM cy_contents WHERE category_id=?',[$id]) || $this->db->one('SELECT id FROM cy_categories WHERE parent_id=?',[$id]))) { throw new Problem('A category containing content or child categories cannot change type.'); }
            if ($current && $current['is_circle'] && !$data['is_circle'] && ($this->db->one("SELECT id FROM cy_orders WHERE kind='circle' AND item_id=?",[$id]) || $this->db->one('SELECT id FROM cy_circle_members WHERE category_id=?',[$id]))) { throw new Problem('A circle with membership history cannot be converted.'); }
            $seen=$id?[$id=>true]:[];$parent=(int)$data['parent_id'];$depth=1;
            while ($parent) {
                if (isset($seen[$parent]) || ++$depth>8) { throw new Problem('Category nesting cannot contain cycles or exceed eight levels.'); }
                $seen[$parent]=true;$row=$this->get($parent);
                if ($row['kind']!==$data['kind']) { throw new Problem('Parent and child categories must have the same type.'); }
                $parent=(int)$row['parent_id'];
            }
            // Also verify the depth of existing descendants, not just the moved parent chain.
            $all=$this->db->all('SELECT id,parent_id FROM cy_categories');$parents=[];
            foreach ($all as $row) { $parents[(int)$row['id']]=(int)$row['parent_id']; }
            if ($id) { $parents[$id]=(int)$data['parent_id']; }
            foreach ($parents as $start=>$unused) { $seen=[];$at=$start;while($at){ if(isset($seen[$at])||count($seen)>=8){throw new Problem('Category nesting cannot contain cycles or exceed eight levels.');}$seen[$at]=true;$at=$parents[$at]??0; } }
            if ($id) { $this->db->update('cy_categories',$id,$data);$saved=$id; }
            else { $saved=$this->db->insert('cy_categories',$data); }
            RemoteSearch::enqueueAll($this->db);
            $this->activity->audit($actor,'category.policy_saved',(string)$saved);return $saved;
        });
    }
    public function manageMember(int $actor, int $category, int $user, bool $blocked, int $until): void
    {
        if ($until<0 || $until>4102444800) { throw new Problem('Invalid membership expiry.'); }
        $this->db->transaction(function()use($actor,$category,$user,$blocked,$until):void {
            $account=$this->db->one('SELECT id FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);
            $circle=$this->get($category);
            if (!$account || !(int)$circle['is_circle']) { throw new Problem('Choose a circle and an existing account.'); }
            $old=$this->db->one('SELECT * FROM cy_circle_members WHERE category_id=? AND user_id=?',[$category,$user]);
            $data=['blocked'=>$blocked?1:0,'granted_until'=>$until,'updated_at'=>time()];
            if ($old) { $this->db->update('cy_circle_members',(int)$old['id'],$data); }
            else { $this->db->insert('cy_circle_members',array_merge($data,['category_id'=>$category,'user_id'=>$user])); }
            $this->activity->audit($actor,'circle.member_updated',$category.':'.$user);
        });
    }
}
