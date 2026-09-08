<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Input, Problem};
final class Wallet
{
    public const CURRENCIES = ['balance', 'points', 'tokens', 'commission', 'earnings'];
    private Database $db; private Settings $settings; private Activity $activity;
    public function __construct(Database $db, Settings $settings, Activity $activity)
    { $this->db = $db; $this->settings = $settings; $this->activity = $activity; }

    /** Single ledger boundary: integer accounting, nonnegative funds, transactional idempotency. */
    public function adjust(int $user, string $currency, int $delta, string $key, string $reason): int
    {
        return $this->entry($user, $currency, $delta, $key, $reason, false);
    }

    public function reverseCommission(int $user, int $amount, int $order, string $number): int
    {
        if ($amount <= 0) { throw new Problem('Invalid commission reversal.'); }
        return $this->entry($user, 'commission', -$amount, 'order:' . $order . ':commission-reversal', 'Refund commission reversal #' . $number, true);
    }

    public static function isMoney(string $currency): bool
    { return in_array($currency, ['balance','commission','earnings'], true); }

    public function reverseEarnings(int $user, int $amount, int $order): int
    {
        if ($amount<=0) { throw new Problem('Invalid earnings reversal.'); }
        return $this->entry($user,'earnings',-$amount,'order:'.$order.':earnings-reversal','Creator refund reversal #'.$order,true);
    }

    private function entry(int $user, string $currency, int $delta, string $key, string $reason, bool $allowCommissionDebt): int
    {
        Input::choice($currency, self::CURRENCIES);
        $allowCommissionDebt = $allowCommissionDebt || (in_array($currency, ['commission','earnings'], true) && $delta > 0);
        if ($delta === 0 || abs($delta) > 10000000000 || strlen($key) > 191) { throw new Problem('Invalid ledger operation.'); }
        return $this->db->transaction(function () use ($user, $currency, $delta, $key, $reason, $allowCommissionDebt): int {
            if(self::isMoney($currency)){$this->settings->assertMoneyCurrency();}
            $account = $this->db->one('SELECT * FROM cy_users WHERE id=?' . $this->db->lock(), [$user]);
            if (!$account) { throw new Problem('Account not found.', 404); }
            $existing = $this->db->one('SELECT * FROM cy_ledger WHERE idempotency_key=?', [$key]);
            if ($existing) {
                if ((int)$existing['user_id'] !== $user || $existing['currency'] !== $currency || (int)$existing['delta'] !== $delta || $existing['reason'] !== $reason) {
                    throw new Problem('Idempotency key was reused with different data.', 409);
                }
                return (int)$existing['balance_after'];
            }
            $after = (int)$account[$currency] + $delta;
            if ($after < 0 && !$allowCommissionDebt) { throw new Problem('Insufficient funds.'); }
            if ($after > 9000000000000) { throw new Problem('Account balance limit reached.'); }
            $changed = $allowCommissionDebt
                ? $this->db->execute("UPDATE cy_users SET `$currency`=`$currency`+? WHERE id=?", [$delta, $user])
                : $this->db->execute("UPDATE cy_users SET `$currency`=`$currency`+? WHERE id=? AND `$currency`+?>=0", [$delta, $user, $delta]);
            if ($changed !== 1) { throw new Problem('Funds changed. Please retry.', 409); }
            $this->db->insert('cy_ledger', ['user_id' => $user, 'currency' => $currency, 'delta' => $delta,
                'balance_after' => $after, 'reason' => $reason, 'idempotency_key' => $key, 'created_at' => time()]);
            return $after;
        });
    }

    /** Caller owns a transaction; adjustments/reversals may credit suspended users,
     * but user-initiated spending must always revalidate the locked account. */
    public function lockActive(int $user): array
    {
        $row = $this->db->one('SELECT * FROM cy_users WHERE id=?' . $this->db->lock(), [$user]);
        if (!$row || $row['status'] !== 'active') { throw new Problem('Account unavailable.', 403); }
        return $row;
    }

    public static function intent(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $value)) { throw new Problem('Invalid request identifier.'); }
        return $value;
    }

    public function checkin(int $user): int
    {
        $this->settings->requireModule('checkin'); $this->settings->requireModule('points');
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $date = $today->format('Y-m-d'); $yesterday = $today->modify('-1 day')->format('Y-m-d');
        return $this->db->transaction(function () use ($user, $date, $yesterday): int {
            $account = $this->db->one('SELECT * FROM cy_users WHERE id=?' . $this->db->lock(), [$user]);
            if (!$account || $account['status'] !== 'active') { throw new Problem('Account unavailable.', 403); }
            if ($account['last_checkin'] === $date) { throw new Problem('You have already checked in today.', 409); }
            $streak = $account['last_checkin'] === $yesterday ? (int)$account['checkin_streak'] + 1 : 1;
            $reward = (int)$this->settings->get('checkin_points') + ($streak % 7 === 0 ? (int)$this->settings->get('streak_bonus') : 0);
            $this->db->execute('UPDATE cy_users SET last_checkin=?,checkin_streak=?,total_checkins=total_checkins+1,experience=experience+5 WHERE id=?', [$date, $streak, $user]);
            if ($reward > 0) { $this->adjust($user, 'points', $reward, 'checkin:' . $user . ':' . $date, 'Daily check-in'); }
            return $reward;
        });
    }

    public function transfer(int $sender, string $recipient, string $currency, int $amount, string $intent): void
    {
        $this->settings->requireModule('transfers');
        Input::choice($currency, ['balance', 'points', 'tokens']);
        $this->settings->requireModule($currency === 'balance' ? 'wallet' : $currency);
        if ($amount <= 0 || $amount > 100000000) { throw new Problem('Invalid transfer amount.'); }
        self::intent($intent);
        $this->db->transaction(function () use ($sender, $recipient, $currency, $amount, $intent): void {
            $target = $this->db->one('SELECT id,status FROM cy_users WHERE username=?', [$recipient]);
            if (!$target || $target['status'] !== 'active' || (int)$target['id'] === $sender) { throw new Problem('Invalid recipient.'); }
            $ids = [$sender, (int)$target['id']]; sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) { $this->lockActive($id); }
            $key = 'transfer:' . $sender . ':' . $intent;
            $reason = 'Transfer to @' . $recipient;
            $this->adjust($sender, $currency, -$amount, $key . ':out', $reason);
            $this->adjust((int)$target['id'], $currency, $amount, $key . ':in', 'Transfer from #' . $sender);
        });
    }
}
