<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Input,Problem};
/** Recognition is cosmetic; a badge never silently grants financial or admin privileges. */
final class Badges
{
    public const SYMBOLS=['sparkles','crown','shield','heart','check','compass','bookmark'];
    public const TONES=['tide','dusk','paper','graphite','citrus'];
    private Database $db;private Settings $settings;private Activity $activity;
    public function __construct(Database $db,Settings $settings,Activity $activity){$this->db=$db;$this->settings=$settings;$this->activity=$activity;}
    private function admin(int $id): void
    {if(!$this->db->one("SELECT id FROM cy_users WHERE id=? AND role='admin' AND status='active'",[$id])){throw new Problem('Access denied.',403);}}
    public function save(int $actor,array $input): int
    {
        $this->admin($actor);$id=Input::integer($input['id']??0);$row=['name'=>Input::required($input['name']??'',100),'description'=>Input::text($input['description']??'',1000),'symbol'=>Input::choice($input['symbol']??'sparkles',self::SYMBOLS),'tone'=>Input::choice($input['tone']??'tide',self::TONES),'active'=>empty($input['active'])?0:1,'sort_order'=>Input::integer($input['sort_order']??0,0,9999)];
        return $this->db->transaction(function()use($actor,$id,$row):int{
            if($id){if(!$this->db->one('SELECT id FROM cy_badges WHERE id=?',[$id])){throw new Problem('Badge not found.',404);}$this->db->update('cy_badges',$id,$row);}
            else{if((int)$this->db->value('SELECT COUNT(*) FROM cy_badges')>=200){throw new Problem('Badge limit reached.');}$id=$this->db->insert('cy_badges',$row);}
            $this->activity->audit($actor,'badge.saved',(string)$id);return $id;
        });
    }
    public function grant(int $actor,int $user,int $badge,int $expires=0): void
    {
        $this->admin($actor);Input::integer($expires,0,253402214400);if($expires && $expires<=time()){throw new Problem('Expiry must be in the future.');}
        $this->db->transaction(function()use($actor,$user,$badge,$expires):void{
            if(!$this->db->one("SELECT id FROM cy_users WHERE id=? AND status='active'".$this->db->lock(),[$user])){throw new Problem('Account unavailable.',403);}
            if(!$this->db->one('SELECT id FROM cy_badges WHERE id=? AND active=1',[$badge])){throw new Problem('Badge not found.',404);}
            $old=$this->db->one('SELECT * FROM cy_user_badges WHERE user_id=? AND badge_id=?',[$user,$badge]);
            if($old && (int)$old['expires_at']===$expires){return;}
            $row=['user_id'=>$user,'badge_id'=>$badge,'granted_by'=>$actor,'granted_at'=>time(),'expires_at'=>$expires];
            if($old){$this->db->update('cy_user_badges',(int)$old['id'],$row);}else{$this->db->insert('cy_user_badges',$row);}
            $this->activity->audit($actor,'badge.granted',$user.':'.$badge);$this->activity->notify($user,'A new badge for your collection','Visit your profile to see your community recognition.','profile');
        });
    }
    public function revoke(int $actor,int $user,int $badge): void
    {
        $this->admin($actor);$this->db->transaction(function()use($actor,$user,$badge):void{$this->db->one('SELECT id FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);$this->db->execute('DELETE FROM cy_user_badges WHERE user_id=? AND badge_id=?',[$user,$badge]);$this->activity->audit($actor,'badge.revoked',$user.':'.$badge);});
    }
    public function forUser(int $user): array
    {
        if(!$this->settings->enabled('badges')){return [];}
        return $this->db->all("SELECT b.*,g.granted_at,g.expires_at FROM cy_user_badges g JOIN cy_badges b ON b.id=g.badge_id JOIN cy_users u ON u.id=g.user_id WHERE g.user_id=? AND b.active=1 AND u.status='active' AND (g.expires_at=0 OR g.expires_at>?) ORDER BY b.sort_order,b.id LIMIT 200",[$user,time()]);
    }
    public function catalog(): array
    {return $this->db->all('SELECT b.*,(SELECT COUNT(*) FROM cy_user_badges g JOIN cy_users u ON u.id=g.user_id WHERE g.badge_id=b.id AND u.status=? AND (g.expires_at=0 OR g.expires_at>?)) AS holders FROM cy_badges b WHERE b.active=1 ORDER BY b.sort_order,b.id LIMIT 200',['active',time()]);}
}
