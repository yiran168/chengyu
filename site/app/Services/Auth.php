<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Crypto, Input, Problem, Security};
final class Auth
{
    private Database $db; private Settings $settings; private Crypto $crypto; private Activity $activity; private Wallet $wallet;
    private ?array $cached = null;
    private SecondFactor $secondFactor;
    public function __construct(Database $db, Settings $settings, Crypto $crypto, Activity $activity, Wallet $wallet, ?SecondFactor $secondFactor=null)
    { $this->db = $db; $this->settings = $settings; $this->crypto = $crypto; $this->activity = $activity; $this->wallet = $wallet; $this->secondFactor=$secondFactor??new SecondFactor($db,$settings,$crypto,$activity,$wallet); }
    public function user(): ?array
    {
        if ($this->cached) { return $this->cached; }
        if (empty($_SESSION['user_id'])) { return null; }
        $user = $this->db->one('SELECT * FROM cy_users WHERE id=?', [(int)$_SESSION['user_id']]);
        if (!$user || $user['status'] !== 'active' || (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? 0)
            || time() - (int)($_SESSION['last_active'] ?? 0) > 43200) {
            $this->logout(); return null;
        }
        $_SESSION['last_active'] = time();
        return $this->cached = $user;
    }
    public function requireUser(): array
    {
        $user = $this->user();
        if (!$user) { throw new Problem('Please sign in to continue.', 401); }
        return $user;
    }
    public function can(string $permission): bool
    {
        $user = $this->user(); if (!$user) { return false; }
        if ($user['role'] === 'admin') { return true; }
        $roles = ['content' => ['editor'], 'moderate' => ['editor', 'moderator'], 'admin' => ['editor', 'moderator']];
        return in_array($user['role'], $roles[$permission] ?? [], true);
    }
    public function requirePermission(string $permission): array
    {
        $user = $this->requireUser(); if (!$this->can($permission)) { throw new Problem('Access denied.', 403); }
        return $user;
    }
    /** Fresh account/session validation is shared by every credential-sensitive write. */
    private function current(array $identity, bool $lock=false): array
    {
        $current=$this->db->one('SELECT * FROM cy_users WHERE id=?'.($lock?$this->db->lock():''),[(int)$identity['id']]);
        if (!$current || $current['status']!=='active' || (int)$current['session_version']!==(int)($_SESSION['session_version']??-1)) {
            $this->logout(); throw new Problem('Your session changed. Sign in again.',401);
        }
        return $current;
    }
    public function verifyPassword(string $password): void
    {
        $password=Input::passwordValue($password);
        $current=$this->current($this->requireUser());
        if (!password_verify($password,$current['password_hash'])) {throw new Problem('Current password is incorrect.',403);}
        $this->cached=$current;
    }
    public function register(array $input): int
    {
        $this->settings->requireModule('registration');
        $this->activity->rate('register', Security::ip(), (int)$this->settings->get('register_limit'), 3600);
        if (!empty($input['website'])) { throw new Problem('Registration could not be completed.'); }
        $username = strtolower(Input::required($input['username'] ?? '', 32));
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/D', $username)) { throw new Problem('Username must be 3-32 letters, digits or underscores.'); }
        $email = strtolower(Input::required($input['email'] ?? '', 191));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) { throw new Problem('Invalid email address.'); }
        $password = Input::password($input['password'] ?? '');
        $invitation = Input::text($input['invitation'] ?? '', 100);
        $display = Input::required($input['display_name'] ?? $username, 100);
        $referral = Input::text($input['referral'] ?? '', 32);
        $referrer = $referral !== '' ? $this->db->one('SELECT id FROM cy_users WHERE username=? AND status=?', [$referral, 'active']) : null;
        $verification = new Verification($this->db, $this->settings, $this->crypto, $this->activity);
        $proof = $this->settings->get('email_registration_required')
            ? $verification->validate($email, 'register', Input::text($input['email_code'] ?? '', 6), 0) : 0;
        try {
            return $this->db->transaction(function () use ($username, $email, $password, $display, $referrer, $invitation, $verification, $proof): int {
                if ($this->db->one('SELECT id FROM cy_users WHERE username=? OR email=?', [$username, $email])) { throw new Problem('Username or email is already registered.'); }
                if ($this->settings->get('registration_mode') === 'invite') { (new Invitations($this->db,$this->crypto,$this->activity))->consume($invitation); }
                if ($proof) { $verification->consume($proof); }
                $id = $this->db->insert('cy_users', ['email_verified_at' => $proof ? time() : 0, 'username' => $username, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'display_name' => $display, 'role' => 'user', 'status' => 'active', 'bio' => '', 'created_at' => time(), 'referred_by' => (int)($referrer['id'] ?? 0)]);
                $reward = $this->settings->enabled('points') ? (int)$this->settings->get('register_points') : 0;
                if ($reward > 0) { $this->wallet->adjust($id, 'points', $reward, 'register:' . $id, 'Registration reward'); }
                $this->activity->notify($id, 'Welcome aboard', 'Your community account is ready.', 'profile');
                $this->activity->audit($id, 'account.register', (string)$id);
                return $id;
            });
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') { throw new Problem('Username or email is already registered.'); }
            throw $e;
        }
    }
    public function login(string $identifier, string $password, string $code=''): void
    {
        $password = Input::passwordValue($password);
        $identifier = strtolower(Input::required($identifier, 191));
        $limit = (int)$this->settings->get('login_limit');
        $this->activity->rate('login-ip', Security::ip(), $limit * 2, 900);
        $this->activity->rate('login-account', $identifier, $limit, 900);
        $user = $this->db->one('SELECT * FROM cy_users WHERE username=? OR email=?', [$identifier, $identifier]);
        $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = password_verify($password, $user['password_hash'] ?? $dummy);
        if (!$user || !$valid || $user['status'] !== 'active') { throw new Problem('Incorrect credentials or account unavailable.', 401); }
        // Recheck under the same account lock used by password changes. A late bcrypt
        // rehash must never overwrite a password changed by a concurrent request.
        $user = $this->db->transaction(function () use ($user, $password, $code): array {
            $current=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[(int)$user['id']]);
            if (!$current || $current['status']!=='active' ||
                (!hash_equals($user['password_hash'],$current['password_hash']) && !password_verify($password,$current['password_hash']))) {
                throw new Problem('Incorrect credentials or account unavailable.',401);
            }
            $this->secondFactor->verifyLocked($current,$code);
            if (password_needs_rehash($current['password_hash'],PASSWORD_DEFAULT)) {
                $this->db->update('cy_users',(int)$current['id'],['password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);
            }
            $this->db->update('cy_users',(int)$current['id'],['last_login'=>time()]);
            $this->activity->audit((int)$current['id'],'account.login',(string)$current['id']);
            return $current;
        });
        $this->openSession($user);
    }
    private function openSession(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['oauth_flows'],$_SESSION['oauth_pending'],$_SESSION['factor_setup'],$_SESSION['factor_codes'],$_SESSION['cart'],$_SESSION['issued_codes'],$_SESSION['issued_invitations'],$_SESSION['content_unlock'],$_SESSION['verification_nonce']);
        $_SESSION['user_id']=(int)$user['id'];$_SESSION['session_version']=(int)$user['session_version'];$_SESSION['last_active']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));$this->cached=null;
    }
    /** Only internal identity adapters supply this callable; never deserialize it from HTTP. */
    public function loginVia(callable $proof): void
    {
        $user=$this->db->transaction($proof);$this->openSession($user);
    }
    public function prove(string $password,string $code=''): array
    {
        $password=Input::passwordValue($password);$user=$this->requireUser();
        $this->activity->rate('sensitive-proof',(string)$user['id'],12,900);
        return $this->db->transaction(function()use($user,$password,$code):array{
            $current=$this->current($user,true);if(!password_verify($password,$current['password_hash'])){throw new Problem('Current password is incorrect.',403);}
            $this->secondFactor->verifyLocked($current,$code);return $current;
        });
    }
    public function syncSession(int $version): void
    {
        $_SESSION['session_version']=$version;$_SESSION['csrf']=bin2hex(random_bytes(32));
        unset($_SESSION['factor_setup']);session_regenerate_id(true);$this->cached=null;
    }
    public function logout(): void
    {
        $this->cached = null; $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    }
    public function profile(array $input): void
    {
        $user=$this->requireUser();$display=Input::required($input['display_name']??'',100);$bio=Input::text($input['bio']??'',1000);$avatar=Input::integer($input['avatar_id']??0);
        $this->db->transaction(function()use($user,$display,$bio,$avatar):void {
            $current=$this->current($user,true);
            if ($avatar && !$this->db->one("SELECT id FROM cy_media WHERE id=? AND owner_id=? AND is_private=0 AND mime LIKE 'image/%'",[$avatar,(int)$current['id']])) {throw new Problem('Invalid avatar.');}
            $this->db->update('cy_users',(int)$current['id'],['display_name'=>$display,'bio'=>$bio,'avatar_id'=>$avatar]);
        });
        $this->cached=null;
    }
    public function changePassword(string $current, string $next): void
    {
        $current = Input::passwordValue($current); $next = Input::password($next); $user = $this->requireUser();
        $version = $this->db->transaction(function () use ($user, $current, $next): int {
            $locked = $this->current($user,true);
            if (!password_verify($current, $locked['password_hash'])) { throw new Problem('Current password is incorrect.', 403); }
            $version = (int)$locked['session_version'] + 1;
            $this->db->update('cy_users', (int)$user['id'], ['password_hash' => password_hash($next, PASSWORD_DEFAULT), 'session_version' => $version]);
            $this->db->execute('UPDATE cy_resets SET used_at=? WHERE user_id=? AND used_at=0', [time(), (int)$user['id']]);
            $this->activity->audit((int)$user['id'], 'account.password_changed', (string)$user['id']);
            return $version;
        });
        $_SESSION['session_version'] = $version; $_SESSION['csrf'] = bin2hex(random_bytes(32));
        session_regenerate_id(true); $this->cached = null;
    }

    public function changeEmail(string $email, string $code, string $password): void
    {
        $this->settings->requireModule('smtp'); $user=$this->requireUser(); $email=Verification::email($email); $password=Input::passwordValue($password);
        $verification=new Verification($this->db,$this->settings,$this->crypto,$this->activity);
        $this->verifyPassword($password);
        $proof=$verification->validate($email,'email',$code,(int)$user['id']);
        $version=$this->db->transaction(function()use($user,$email,$password,$verification,$proof):int {
            $locked=$this->current($user,true);
            if (!password_verify($password,$locked['password_hash'])) { throw new Problem('Current password is incorrect.',403); }
            if ($this->db->one('SELECT id FROM cy_users WHERE email=? AND id<>?',[$email,(int)$user['id']])) { throw new Problem('Email is already in use.'); }
            $verification->consume($proof);$version=(int)$locked['session_version']+1;
            $this->db->update('cy_users',(int)$user['id'],['email'=>$email,'email_verified_at'=>time(),'session_version'=>$version]);
            $this->db->execute('UPDATE cy_resets SET used_at=? WHERE user_id=? AND used_at=0',[time(),(int)$user['id']]);
            $this->activity->audit((int)$user['id'],'account.email_verified',(string)$user['id']);
            return $version;
        });
        $_SESSION['session_version']=$version;$_SESSION['csrf']=bin2hex(random_bytes(32));
        unset($_SESSION['verification_nonce']);session_regenerate_id(true);$this->cached=null;
    }

    public function recovery(string $email, Mailer $mailer, string $baseUrl): void
    {
        if (!$this->settings->enabled('smtp')) { throw new Problem('Email delivery is not configured. Contact the site administrator.'); }
        $email = strtolower(Input::required($email, 191));
        (new Verification($this->db,$this->settings,$this->crypto,$this->activity))->rateDelivery($email);
        $user = $this->db->one('SELECT id FROM cy_users WHERE email=? AND status=?', [$email, 'active']);
        if (!$user) { return; }
        $token = bin2hex(random_bytes(32));
        $this->db->insert('cy_resets', ['user_id' => (int)$user['id'], 'token_hash' => $this->crypto->digest($token), 'expires_at' => time() + 1800, 'created_at' => time()]);
        $url = $baseUrl . '/index.php?r=reset&token=' . $token;
        try { $mailer->send($email, 'Password recovery', "Reset your password using this link within 30 minutes:\n" . $url . "\nIf you did not request this, ignore this message."); }
        catch (\Throwable $e) { $this->activity->audit(0, 'email.delivery_failed', 'recovery'); }
    }
    public function reset(string $token, string $password): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new Problem('Invalid or expired recovery link.'); }
        $password = Input::password($password);
        $this->db->transaction(function () use ($token, $password): void {
            $row = $this->db->one('SELECT * FROM cy_resets WHERE token_hash=?' . $this->db->lock(), [$this->crypto->digest($token)]);
            if (!$row || (int)$row['used_at'] || (int)$row['expires_at'] < time()) { throw new Problem('Invalid or expired recovery link.'); }
            $this->db->execute('UPDATE cy_users SET password_hash=?,session_version=session_version+1 WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), (int)$row['user_id']]);
            $this->db->execute('UPDATE cy_resets SET used_at=? WHERE user_id=?', [time(), (int)$row['user_id']]);
            $this->activity->audit((int)$row['user_id'], 'account.password_reset', (string)$row['user_id']);
        });
    }
}
