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
    /** Recovery requires a fresh administrator proof and the exact target revision.
     * All account locks precede factor locks, in ID order, including cross-admin recovery. */
    public function recoverByAdmin(int $actor,int $version,string $password,string $code,int $user,int $targetVersion,string $username,string $reason): void
    {
        if($actor===$user){throw new Problem('Use your security center or the private operator recovery procedure for your own account.',403);}
        $password=Input::passwordValue($password);$username=Input::required($username,32);$reason=Input::required($reason,180);$this->rate($actor);
        $this->db->transaction(function()use($actor,$version,$password,$code,$user,$targetVersion,$username,$reason):void{
            $accounts=$this->db->all('SELECT * FROM cy_users WHERE id IN (?,?) ORDER BY id'.$this->db->lock(),[$actor,$user]);$rows=[];
            foreach($accounts as $row){$rows[(int)$row['id']]=$row;}
            $operator=$rows[$actor]??null;$target=$rows[$user]??null;
            if(!$operator || $operator['role']!=='admin' || $operator['status']!=='active' || (int)$operator['session_version']!==$version){throw new Problem('Access denied.',403);}
            if(!password_verify($password,$operator['password_hash'])){throw new Problem('Current password is incorrect.',403);}
            if(!$target){throw new Problem('Account not found.',404);}
            if($target['status']!=='active' || (int)$target['session_version']!==$targetVersion || !hash_equals($target['username'],$username)){throw new Problem('Target account changed. Reload and verify the username before recovery.',409);}
            if($target['role']==='admin' && !$this->db->one('SELECT id FROM cy_second_factors WHERE user_id=?',[$actor])){throw new Problem('Enroll your own authenticator before recovering another administrator.',403);}
            $this->verifyLocked($operator,$code);
            if(!$this->db->one('SELECT id FROM cy_second_factors WHERE user_id=?'.$this->db->lock(),[$user])){throw new Problem('Two-factor authentication is not enabled.',409);}
            $this->recoverAccount($target,$actor,'account.factor_admin_recovery',$reason);
            $this->activity->notify($user,'Authenticator reset by an administrator','Sign in and enroll a new authenticator. Contact the site operator if you did not request this recovery.','security');
        });
    }
    private function recoverAccount(array $account,int $actor,string $event,string $reason=''): void
    {
        $user=(int)$account['id'];$this->db->execute('DELETE FROM cy_second_factors WHERE user_id=?',[$user]);$this->bump($account);
        $this->db->execute('UPDATE cy_resets SET used_at=? WHERE user_id=? AND used_at=0',[time(),$user]);
        $this->activity->audit($actor,$event,(string)$user.($reason!==''?' / '.$reason:''));
    }
    /** Local operator recovery only. No HTTP action exposes this method. */
    public function recoverOffline(int $user): void
    {
        if (PHP_SAPI!=='cli') { throw new \LogicException('Local operator recovery is CLI-only'); }
        $this->db->transaction(function()use($user):void {
            $account=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);
            if (!$account) { throw new Problem('Account not found.',404); }
            $this->recoverAccount($account,0,'account.factor_offline_recovery');
        });
    }
}
