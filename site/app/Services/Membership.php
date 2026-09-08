<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Input, Problem};
final class Membership
{
    /** A documented permanent entitlement sentinel, not an arbitrary 10-year plan. */
    public const FOREVER=253402214400;
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    public function tier(?array $user): int
    {
        if (!$user) { return 0; }
        $tier=(int)$this->db->value('SELECT COALESCE(MAX(tier),0) FROM cy_memberships WHERE user_id=? AND until_at>?',[(int)$user['id'],time()]);
        return max($tier,(int)$user['vip_until']>time()?1:0);
    }
    public function lifetimeTier(int $user): int
    {return (int)$this->db->value('SELECT COALESCE(MAX(tier),0) FROM cy_memberships WHERE user_id=? AND until_at>=?',[$user,self::FOREVER]);}
    public function grant(int $user, int $tier, int $days): int
    {
        Input::integer($tier,1,3);Input::integer($days,0,3650);
        return $this->db->transaction(function() use($user,$tier,$days): int {
            $account=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);
            if (!$account) { throw new Problem('Account not found.',404); }
            $row=$this->db->one('SELECT * FROM cy_memberships WHERE user_id=? AND tier=?',[$user,$tier]);
            $base=(int)($row['until_at']??0);
            // vip_until is an aggregate across all tiers. Only import it for a
            // genuinely legacy account with no canonical membership records.
            if ($tier===1 && !$row && !(int)$this->db->value('SELECT COUNT(*) FROM cy_memberships WHERE user_id=?',[$user])) { $base=(int)$account['vip_until']; }
            $until=$days===0 || $base>=self::FOREVER?self::FOREVER:min(self::FOREVER,max(time(),$base)+$days*86400);
            if ($row) { $this->db->update('cy_memberships',(int)$row['id'],['until_at'=>$until]); }
            else { $this->db->insert('cy_memberships',['user_id'=>$user,'tier'=>$tier,'until_at'=>$until]); }
            $max=(int)$this->db->value('SELECT MAX(until_at) FROM cy_memberships WHERE user_id=?',[$user]);
            $this->db->update('cy_users',$user,['vip_until'=>$max]);return $until;
        });
    }

    /** Record actual paid basis; free/manual grants cannot manufacture upgrade credit. */
    public function purchase(array $order,int $tier,int $days): void
    {
        $this->db->transaction(function()use($order,$tier,$days):void{
            if($this->db->one('SELECT id FROM cy_membership_lots WHERE order_id=?',[(int)$order['id']])){return;}
            $user=(int)$order['user_id'];$this->db->one('SELECT id FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);
            $current=(int)$this->db->value('SELECT until_at FROM cy_memberships WHERE user_id=? AND tier=?',[$user,$tier]);
            if($tier===1 && !(int)$this->db->value('SELECT COUNT(*) FROM cy_memberships WHERE user_id=?',[$user])){$current=(int)$this->db->value('SELECT vip_until FROM cy_users WHERE id=?',[$user]);}
            $start=max(time(),$current);
            $until=$this->grant($user,$tier,$days);if($days===0){$start=time();}
            $this->db->insert('cy_membership_lots',['order_id'=>(int)$order['id'],'user_id'=>$user,'tier'=>$tier,'starts_at'=>$start,'until_at'=>$until,'basis_amount'=>(int)$order['amount'],'currency'=>$order['currency'],'status'=>'active']);
        });
    }
}
