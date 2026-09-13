<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Input, Problem};
final class Admin
{
    private Database $db; private Settings $settings; private Activity $activity;
    public function __construct(Database $db, Settings $settings, Activity $activity)
    { $this->db = $db; $this->settings = $settings; $this->activity = $activity; }
    public function entity(int $actor, string $entity, array $input): int
    {
        Input::choice($entity, ['categories', 'plans', 'coupons', 'navigation']);
        $id = Input::integer($input['id'] ?? 0);
        $table = 'cy_' . $entity;
        if ($id && !$this->db->one("SELECT id FROM $table WHERE id=?", [$id])) { throw new Problem('Record not found.', 404); }
        if ($entity === 'categories') {
            return (new Categories($this->db,$this->settings,$this->activity))->save($actor,$input);
        } elseif ($entity === 'plans') {
            $currency = Input::choice($input['price_currency'] ?? 'balance', ['balance', 'points', 'tokens']);
            $data = ['tier'=>Input::integer($input['tier']??1,1,3), 'name' => Input::required($input['name'] ?? '', 100), 'days' => Input::integer($input['days'] ?? 30, 0, 3650),
                'price_amount' => $currency === 'balance' ? Input::cents($input['price'] ?? '') : Input::integer($input['price'] ?? 0, 1, 100000000),
                'price_currency' => $currency, 'description' => Input::text($input['description'] ?? '', 4000),
                'active' => empty($input['active']) ? 0 : 1, 'sort_order' => Input::integer($input['sort_order'] ?? 0, 0, 9999)];
        } elseif ($entity === 'coupons') {
            $code = strtoupper(Input::required($input['code'] ?? '', 64));
            if (!preg_match('/^[A-Z0-9_\-]{3,64}$/D', $code)) { throw new Problem('Invalid coupon code.'); }
            $mode = Input::choice($input['mode'] ?? 'flat', ['flat', 'percent']);
            $expires = strtotime(Input::required($input['expires_at'] ?? '', 40));
            if (!$expires || $expires <= time()) { throw new Problem('Expiry must be in the future.'); }
            $data = ['code' => $code, 'mode' => $mode, 'value_amount' => $mode === 'flat' ? Input::cents($input['value'] ?? '') : Input::integer($input['value'] ?? 0, 1, 10000),
                'min_amount' => Input::cents($input['minimum'] ?? '0', true), 'max_uses' => Input::integer($input['max_uses'] ?? 1, 1, 1000000),
                'expires_at' => $expires, 'active' => empty($input['active']) ? 0 : 1];
            $data['scope_kind']=Input::choice($input['scope_kind']??'all',['all','content','membership']);
            $data['content_id']=Input::integer($input['content_id']??0);
            $data['starts_at']=Pricing::date($input['starts_at']??'');
            $data['min_vip_tier']=Input::integer($input['min_vip_tier']??0,0,3);
            if ($data['starts_at']>=$expires) {throw new Problem('The coupon end must be later than its start.');}
            if ($data['content_id'] && ($data['scope_kind']!=='content' || !$this->db->one("SELECT id FROM cy_contents WHERE id=? AND kind<>'page'",[$data['content_id']]))) {throw new Problem('Choose a valid content coupon target.');}
            if ($this->db->one('SELECT id FROM cy_coupons WHERE code=? AND id<>?', [$code, $id])) { throw new Problem('Coupon code already exists.'); }
        } else {
            return (new Navigation($this->db,$this->settings,$this->activity))->save($actor,$input);
        }
        return $this->db->transaction(function () use ($actor, $id, $table, $data, $entity): int {
            if ($id) { if (!$this->db->one('SELECT id FROM '.$table.' WHERE id=?'.$this->db->lock(),[$id])) { throw new Problem('Record not found.',404); } $this->db->update($table, $id, $data); $saved = $id; }
            else { $saved = $this->db->insert($table, $data); }
            $this->activity->audit($actor, $entity . '.saved', (string)$saved); return $saved;
        });
    }
    public function user(int $actor, int $id, array $input): void
    {
        $role = Input::choice($input['role'] ?? '', ['user', 'editor', 'moderator', 'admin']);
        $status = Input::choice($input['status'] ?? '', ['active', 'suspended']);
        if ($id === $actor && ($role !== 'admin' || $status !== 'active')) { throw new Problem('You cannot remove your own administrator access.'); }
        $this->db->transaction(function () use ($actor, $id, $role, $status, $input): void {
            // Lock administrators together so two concurrent demotions cannot remove the last admin.
            $admins = $this->db->all("SELECT id FROM cy_users WHERE role='admin' AND status='active' ORDER BY id" . $this->db->lock());
            $user = $this->db->one('SELECT * FROM cy_users WHERE id=?' . $this->db->lock(), [$id]);
            if (!$user) { throw new Problem('Account not found.', 404); }
            if ($user['role'] === 'admin' && ($role !== 'admin' || $status !== 'active') && count($admins) <= 1) { throw new Problem('At least one active administrator is required.'); }
            $email = strtolower(Input::required($input['email'] ?? '', 191));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) { throw new Problem('Invalid email address.'); }
            if ($this->db->one('SELECT id FROM cy_users WHERE email=? AND id<>?', [$email, $id])) { throw new Problem('Email is already in use.'); }
            $until = !empty($input['vip_until']) ? strtotime(Input::text($input['vip_until'], 40)) : 0;
            if ($until === false) { throw new Problem('Invalid date.'); }
            $data = ['role' => $role, 'status' => $status, 'email' => $email, 'display_name' => Input::required($input['display_name'] ?? '', 100),
                'verified' => empty($input['verified']) ? 0 : 1, 'vip_until' => !empty($input['replace_membership']) ? $until : (int)$user['vip_until']];
            $data['creator_enabled']=!empty($input['creator_enabled'])?1:0;
            $data['creator_bps']=Input::integer($input['creator_bps']??$user['creator_bps'],-1,10000);
            if ($email !== $user['email']) { $data['email_verified_at'] = 0; }
            $invalidate = $role !== $user['role'] || $status !== $user['status'] || $email !== $user['email'];
            if (!empty($input['new_password'])) { $data['password_hash'] = password_hash(Input::password($input['new_password']), PASSWORD_DEFAULT); $invalidate = true; }
            if ($invalidate) {
                $data['session_version'] = (int)$user['session_version'] + 1;
                // A previously mailed recovery link must not undo an administrative credential change.
                $this->db->execute('UPDATE cy_resets SET used_at=? WHERE user_id=? AND used_at=0', [time(), $id]);
            }
            if (!empty($input['replace_membership'])) {
                $this->db->execute('DELETE FROM cy_memberships WHERE user_id=?',[$id]);
                if ($until>0) { $this->db->insert('cy_memberships',['user_id'=>$id,'tier'=>Input::integer($input['vip_tier']??1,1,3),'until_at'=>$until]); }
                $this->activity->audit($actor,'membership.replaced',(string)$id);
            }
            $this->db->update('cy_users', $id, $data); $this->activity->audit($actor, 'user.updated', (string)$id);
        });
    }
    public function pinComment(int $actor, int $id, bool $pinned): void
    {
        $this->db->transaction(function () use ($actor,$id,$pinned): void {
            if (!$this->db->one('SELECT id FROM cy_comments WHERE id=?'.$this->db->lock(),[$id])) { throw new Problem('Record not found.',404); }
            $this->db->update('cy_comments',$id,['pinned'=>$pinned?1:0]);
            $this->activity->audit($actor,'comment.pinned',(string)$id);
        });
    }
    public function moderation(int $actor, string $entity, int $id, string $status): void
    {
        Input::choice($entity, ['comments', 'reports']);
        $status = Input::choice($status, $entity === 'comments' ? ['approved', 'rejected'] : ['resolved', 'dismissed']);
        $table = 'cy_' . $entity;
        $this->db->transaction(function()use($actor,$entity,$table,$id,$status):void {
            // Lock the content first, matching answer acceptance, to avoid stale accepted state.
            if ($entity==='comments') {
                $comment=$this->db->one('SELECT content_id FROM cy_comments WHERE id=?',[$id]);
                if ($comment) { $this->db->one('SELECT id FROM cy_contents WHERE id=?'.$this->db->lock(),[(int)$comment['content_id']]); }
            }
            if (!$this->db->one("SELECT id FROM $table WHERE id=?".$this->db->lock(),[$id])) { throw new Problem('Record not found.',404); }
            $this->db->update($table,$id,['status'=>$status]);
            if ($entity==='comments' && $status==='rejected') { $this->db->execute('UPDATE cy_threads SET accepted_comment_id=0 WHERE accepted_comment_id=?',[$id]); }
            $this->activity->audit($actor,$entity.'.'.$status,(string)$id);
        });
    }
    public function cleanup(): array
    {
        $counts = [];
        $counts['email_challenges'] = $this->db->execute('DELETE FROM cy_email_challenges WHERE expires_at<?', [time() - 86400]);
        $counts['rates'] = $this->db->execute('DELETE FROM cy_rate_limits WHERE expires_at<?', [time() - 86400]);
        $counts['resets'] = $this->db->execute('DELETE FROM cy_resets WHERE expires_at<?', [time() - 86400]);
        $counts['audit'] = $this->db->execute('DELETE FROM cy_audit WHERE created_at<?', [time() - (int)$this->settings->get('audit_days') * 86400]);
        $counts['orders'] = $this->db->execute("UPDATE cy_orders SET status='expired' WHERE kind='topup' AND status='pending' AND expires_at<?", [time()]);
        return $counts;
    }
    public function export(string $entity): void
    {
        $queries = [
            'orders' => 'SELECT order_no,user_id,kind,item_id,title,amount,currency,status,gateway,gateway_trade_no,fulfillment,created_at,paid_at FROM cy_orders ORDER BY id',
            'ledger' => 'SELECT id,user_id,currency,delta,balance_after,reason,created_at FROM cy_ledger ORDER BY id',
            'users' => 'SELECT id,username,email,display_name,role,status,balance,points,tokens,commission,earnings,creator_enabled,creator_bps,vip_until,created_at FROM cy_users ORDER BY id',
        ];
        if (!isset($queries[$entity])) { throw new Problem('Unknown export.'); }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="chengyu-' . $entity . '-' . gmdate('Ymd') . '.csv"');
        $out = fopen('php://output', 'wb'); fwrite($out, "\xEF\xBB\xBF");
        $statement = $this->db->run($queries[$entity]); $first = true;
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            if ($first) { fputcsv($out, array_keys($row), ',', '"', ''); $first = false; }
            $cells = array_map(static function ($v) { $s = (string)$v; return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s; }, array_values($row));
            fputcsv($out, $cells, ',', '"', '');
        }
        fclose($out);
    }
}
