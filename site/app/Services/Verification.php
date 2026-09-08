<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Crypto, Input, Problem, Security};

/** Session-bound, single-use email proofs. Delivery is outside database transactions. */
final class Verification
{
    private Database $db;
    private Settings $settings;
    private Crypto $crypto;
    private Activity $activity;
    public function __construct(Database $db, Settings $settings, Crypto $crypto, Activity $activity)
    { $this->db=$db; $this->settings=$settings; $this->crypto=$crypto; $this->activity=$activity; }

    public static function email(string $email): string
    {
        $email = strtolower(Input::required($email, 191));
        if (strlen($email)>191 || !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new Problem('Invalid email address.'); }
        return $email;
    }
    private function binding(): string
    {
        if (empty($_SESSION['verification_nonce'])) { $_SESSION['verification_nonce']=bin2hex(random_bytes(32)); }
        return $this->crypto->digest('email-session:'.$_SESSION['verification_nonce']);
    }
    private function hash(string $email): string { return $this->crypto->digest('email-recipient:'.$email); }

    /** One quota shared by registration, email changes and password recovery. */
    public function rateDelivery(string $email): void
    {
        $email=self::email($email);$s=$this->settings;$ip=Security::ip();
        $this->activity->rateMany([
            ['email-ip-10m',$ip,(int)$s->get('verification_ip_10m'),600],
            ['email-ip-hour',$ip,(int)$s->get('verification_ip_hour'),3600],
            ['email-ip-day',$ip,(int)$s->get('verification_ip_day'),86400],
            ['email-recipient-10m',$email,(int)$s->get('verification_recipient_10m'),600],
            ['email-recipient-hour',$email,(int)$s->get('mail_limit'),3600],
            ['email-recipient-day',$email,(int)$s->get('verification_recipient_day'),86400],
            ['email-recipient-cooldown',$email,1,(int)$s->get('verification_cooldown'),true],
        ]);
    }

    public function request(string $email, string $purpose, int $user, callable $deliver): void
    {
        $this->settings->requireModule('smtp');
        $email=self::email($email); Input::choice($purpose,['register','email']);
        if ($purpose==='register') { $this->settings->requireModule('registration'); }
        if (($purpose==='register' && $user!==0) || ($purpose==='email' && $user<1)) { throw new Problem('Invalid verification purpose.'); }
        $this->rateDelivery($email);
        $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        $binding=$this->binding();$emailHash=$this->hash($email);
        $id=$this->db->transaction(function()use($emailHash,$purpose,$binding,$user,$code):int {
            $this->db->execute("UPDATE cy_email_challenges SET state='replaced' WHERE email_hash=? AND purpose=? AND session_hash=? AND used_at=0",[$emailHash,$purpose,$binding]);
            return $this->db->insert('cy_email_challenges',[
                'email_hash'=>$emailHash,'purpose'=>$purpose,'session_hash'=>$binding,'user_id'=>$user,
                'code_hash'=>$this->crypto->digest('email-code:'.$binding.':'.$emailHash.':'.$purpose.':'.$code),
                'state'=>'pending','expires_at'=>time()+600,'created_at'=>time(),
            ]);
        });
        try {
            $deliver($email,\tr('Your email verification code'),\tr('Your verification code is').': '.$code."\n".\tr('Valid for 10 minutes, in this browser only. Never share it.'));
            $this->db->execute("UPDATE cy_email_challenges SET state='sent' WHERE id=? AND state='pending'",[$id]);
        } catch (\Throwable $e) {
            $this->db->execute("UPDATE cy_email_challenges SET state='failed' WHERE id=?",[$id]);
            $this->activity->audit($user,'email.code_delivery_failed',(string)$id);
            throw new Problem('Verification email could not be sent. Check SMTP and try later.',503);
        }
    }

    /** Validation commits failed attempts before returning. Consume the proof in the business transaction. */
    public function validate(string $email, string $purpose, string $code, int $user): int
    {
        $email=self::email($email);Input::choice($purpose,['register','email']);
        $this->activity->rate('email-code-verify',Security::ip(),30,900);
        $emailHash=$this->hash($email);$binding=$this->binding();
        $id=$this->db->transaction(function()use($emailHash,$binding,$purpose,$code,$user):int {
            $row=$this->db->one('SELECT * FROM cy_email_challenges WHERE email_hash=? AND purpose=? AND session_hash=? AND user_id=? ORDER BY id DESC LIMIT 1'.$this->db->lock(),[$emailHash,$purpose,$binding,$user]);
            if (!$row || $row['state']!=='sent' || (int)$row['used_at'] || (int)$row['expires_at']<=time() || (int)$row['attempts']>=5) { return 0; }
            $hash=$this->crypto->digest('email-code:'.$binding.':'.$emailHash.':'.$purpose.':'.$code);
            if (!preg_match('/^[0-9]{6}$/D',$code) || !hash_equals($row['code_hash'],$hash)) {
                $this->db->execute('UPDATE cy_email_challenges SET attempts=attempts+1 WHERE id=?',[(int)$row['id']]);return 0;
            }
            return (int)$row['id'];
        });
        if (!$id) { throw new Problem('Invalid, expired or exhausted verification code.',403); }
        return $id;
    }

    public function consume(int $id): void
    {
        $changed=$this->db->execute("UPDATE cy_email_challenges SET used_at=?,state='used' WHERE id=? AND state='sent' AND used_at=0 AND expires_at>? AND attempts<5 AND session_hash=?",[time(),$id,time(),$this->binding()]);
        if ($changed!==1) { throw new Problem('Verification code was already used or expired.',409); }
    }
}
