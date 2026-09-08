<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Crypto,Input,Problem,Totp};

/** Password and second-factor changes share the account -> factor lock order. */
final class SecondFactor
{
    private Database $db; private Settings $settings; private Crypto $crypto; private Activity $activity; private Wallet $wallet;
    public function __construct(Database $db,Settings $settings,Crypto $crypto,Activity $activity,Wallet $wallet)
    { $this->db=$db; $this->settings=$settings; $this->crypto=$crypto; $this->activity=$activity; $this->wallet=$wallet; }
    private function account(int $user,int $version,string $password): array
    {
        $password=Input::passwordValue($password); $row=$this->wallet->lockActive($user);
        if ((int)$row['session_version']!==$version) { throw new Problem('Your session changed. Sign in again.',401); }
        if (!password_verify($password,$row['password_hash'])) { throw new Problem('Current password is incorrect.',403); }
        return $row;
    }
    private function rate(int $user): void { $this->activity->rate('factor-management',(string)$user,12,900); }
    private function bump(array $account): int
    {
        $version=(int)$account['session_version']+1;
        $this->db->update('cy_users',(int)$account['id'],['session_version'=>$version]); return $version;
    }
    private function recovery(int $user): array
    {
        $plain=[];$hashes=[];
        for ($i=0;$i<8;$i++) {
            $code=strtoupper(bin2hex(random_bytes(10)));
            $plain[]=implode('-',str_split($code,5)); $hashes[]=$this->crypto->digest('factor:'.$user.':'.$code);
        }
        return [$plain,$hashes];
    }
    public function status(int $user): array
    {
        $row=$this->db->one('SELECT enabled_at,recovery_hashes FROM cy_second_factors WHERE user_id=?',[$user]);
        return ['enabled'=>(bool)$row,'enabled_at'=>(int)($row['enabled_at']??0),'recovery_remaining'=>$row?count(json_decode($row['recovery_hashes'],true,16,JSON_THROW_ON_ERROR)):0];
    }
    public function pending(int $user,int $version): ?array
    {
        $p=$_SESSION['factor_setup']??null;
        if (!is_array($p) || $p['user_id']!==$user || $p['version']!==$version || $p['expires_at']<=time()) { unset($_SESSION['factor_setup']);return null; }
        return $p;
    }
    public function begin(int $user,int $version,string $password): void
    {
        $this->settings->requireModule('two_factor'); $this->rate($user);
        $this->db->transaction(function()use($user,$version,$password):void {
            $this->account($user,$version,$password);
            if ($this->db->one('SELECT id FROM cy_second_factors WHERE user_id=?',[$user])) { throw new Problem('Two-factor authentication is already enabled.',409); }
        });
        $_SESSION['factor_setup']=['user_id'=>$user,'version'=>$version,'secret'=>Totp::encode(random_bytes(20)),'expires_at'=>time()+600];
    }
    public function activate(int $user,int $version,string $password,string $code): array
    {
        $this->settings->requireModule('two_factor'); $this->rate($user); $pending=$this->pending($user,$version);
        if (!$pending) { throw new Problem('Authenticator setup expired. Start again.',409); }
        $result=$this->db->transaction(function()use($user,$version,$password,$code,$pending):array {
            $account=$this->account($user,$version,$password);
            if ($this->db->one('SELECT id FROM cy_second_factors WHERE user_id=?',[$user])) { throw new Problem('Two-factor authentication is already enabled.',409); }
            $step=Totp::match($pending['secret'],$code,time());
            if ($step===null) { throw new Problem('Invalid or already used authenticator code.',403); }
            [$plain,$hashes]=$this->recovery($user);
            $this->db->insert('cy_second_factors',['user_id'=>$user,'secret_cipher'=>$this->crypto->seal($pending['secret']),'last_step'=>$step,'recovery_hashes'=>json_encode($hashes,JSON_THROW_ON_ERROR),'enabled_at'=>time()]);
            $this->activity->audit($user,'account.factor_enabled',(string)$user);
            return ['version'=>$this->bump($account),'codes'=>$plain];
        });
        unset($_SESSION['factor_setup']); return $result;
    }
    /** Must run inside a transaction after locking the current account. Existing
     * protection is enforced even when the enrollment switch is disabled. */
    public function verifyLocked(array $account,string $code): void
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Second-factor verification requires the account transaction'); }
        $user=(int)$account['id'];
        $row=$this->db->one('SELECT * FROM cy_second_factors WHERE user_id=?'.$this->db->lock(),[$user]);
        if (!$row) { return; }
        $code=Input::text($code,32);
        if ($code==='') { throw new Problem('Enter your authenticator code or a recovery code.',401); }
        $step=Totp::match($this->crypto->open($row['secret_cipher']),$code,time(),(int)$row['last_step']);
        if ($step!==null) { $this->db->update('cy_second_factors',(int)$row['id'],['last_step'=>$step]);return; }
        $normal=strtoupper(str_replace(['-',' '],'',$code));
        if (preg_match('/^[A-F0-9]{20}$/D',$normal)) {
            $hash=$this->crypto->digest('factor:'.$user.':'.$normal);
            $hashes=json_decode($row['recovery_hashes'],true,16,JSON_THROW_ON_ERROR);
            foreach ($hashes as $key=>$stored) {
                if (hash_equals($stored,$hash)) {
                    unset($hashes[$key]);
                    $this->db->update('cy_second_factors',(int)$row['id'],['recovery_hashes'=>json_encode(array_values($hashes),JSON_THROW_ON_ERROR)]);
                    $this->activity->audit($user,'account.recovery_code_used',(string)$user);return;
                }
            }
        }
        throw new Problem('Invalid or already used authenticator code.',403);
    }
    public function manage(int $user,int $version,string $password,string $code,string $operation): array
    {
        Input::choice($operation,['disable','rotate','revoke']); $this->rate($user);
        return $this->db->transaction(function()use($user,$version,$password,$code,$operation):array {
            $account=$this->account($user,$version,$password);
            $row=$this->db->one('SELECT id FROM cy_second_factors WHERE user_id=?',[$user]);
            if (!$row && $operation!=='revoke') { throw new Problem('Two-factor authentication is not enabled.',409); }
            $this->verifyLocked($account,$code);$plain=[];
            if ($operation==='disable') { $this->db->execute('DELETE FROM cy_second_factors WHERE user_id=?',[$user]); }
            if ($operation==='rotate') {
                [$plain,$hashes]=$this->recovery($user);
                $this->db->update('cy_second_factors',(int)$row['id'],['recovery_hashes'=>json_encode($hashes,JSON_THROW_ON_ERROR)]);
            }
            $this->activity->audit($user,'account.factor_'.$operation,(string)$user);
            return ['version'=>$this->bump($account),'codes'=>$plain];
        });
    }
    /** Local operator recovery only. No HTTP action exposes this method. */
    public function recoverOffline(int $user): void
    {
        if (PHP_SAPI!=='cli') { throw new \LogicException('Local operator recovery is CLI-only'); }
        $this->db->transaction(function()use($user):void {
            $account=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);
            if (!$account) { throw new Problem('Account not found.',404); }
            $this->db->execute('DELETE FROM cy_second_factors WHERE user_id=?',[$user]);$this->bump($account);
            $this->activity->audit(0,'account.factor_offline_recovery',(string)$user);
        });
    }
}
