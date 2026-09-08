<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Crypto,Input,Problem};
/** A guest pass authorizes only its own orders, never an authenticated site session. */
final class Guests
{
    private Database $db;private Settings $settings;private Crypto $crypto;
    public function __construct(Database $db,Settings $settings,Crypto $crypto){$this->db=$db;$this->settings=$settings;$this->crypto=$crypto;}
    public function create(string $email): array
    {
        $this->settings->requireModule('guest_checkout');$email=strtolower(Input::required($email,191));if(!filter_var($email,FILTER_VALIDATE_EMAIL)){throw new Problem('Enter a valid email address.');}
        return $this->db->transaction(function()use($email):array{
            $token=bin2hex(random_bytes(32));$name='guest_'.bin2hex(random_bytes(10));
            $uid=$this->db->insert('cy_users',['username'=>$name,'email'=>$name.'@guest.invalid','password_hash'=>'!guest-no-password','display_name'=>'Guest','role'=>'user','status'=>'active','bio'=>'','is_guest'=>1,'created_at'=>time()]);
            $id=$this->db->insert('cy_guest_passes',['user_id'=>$uid,'email_cipher'=>$this->crypto->seal($email),'token_hash'=>$this->crypto->digest('guest:'.$token),'created_at'=>time(),'expires_at'=>time()+(int)$this->settings->get('guest_expiry_days')*86400]);
            return ['id'=>$id,'user_id'=>$uid,'token'=>$token];
        });
    }
    public function authorize(string $token): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)){throw new Problem('Invalid or expired guest pass.',403);}
        $p=$this->db->one('SELECT * FROM cy_guest_passes WHERE token_hash=?',[$this->crypto->digest('guest:'.$token)]);
        if(!$p||(int)$p['expires_at']<=time()||(int)$p['claimed_by']!==0){throw new Problem('Invalid or expired guest pass.',403);}
        $u=$this->db->one('SELECT * FROM cy_users WHERE id=? AND is_guest=1 AND status=?',[(int)$p['user_id'],'active']);if(!$u){throw new Problem('Invalid or expired guest pass.',403);}return $u;
    }
    public function current(): ?array
    {
        $token=$_SESSION['guest_token']??'';if(!is_string($token)||$token===''){return null;}try{return $this->authorize($token);}catch(Problem $e){unset($_SESSION['guest_token']);return null;}
    }
    public function claim(int $user,string $token): int
    {
        return $this->db->transaction(function()use($user,$token):int{
            $target=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[$user]);if(!$target||$target['status']!=='active'||(int)$target['is_guest']){throw new Problem('Sign in to a regular account before claiming orders.',403);}
            $guest=$this->authorize($token);$gid=(int)$guest['id'];$this->db->one('SELECT id FROM cy_users WHERE id=?'.$this->db->lock(),[$gid]);$pass=$this->db->one('SELECT * FROM cy_guest_passes WHERE user_id=?'.$this->db->lock(),[$gid]);
            if((int)$pass['claimed_by']!==0){throw new Problem('This guest pass was already claimed.',409);}
            if((int)$this->db->value("SELECT COUNT(*) FROM cy_sales WHERE user_id=? AND status NOT IN ('paid','closed')",[$gid])){throw new Problem('Resolve pending payments before claiming these orders.',409);}
            $count=(int)$this->db->value('SELECT COUNT(*) FROM cy_orders WHERE user_id=?',[$gid]);
            foreach($this->db->all('SELECT * FROM cy_entitlements WHERE user_id=?',[$gid]) as $e){$old=$this->db->one('SELECT id FROM cy_entitlements WHERE user_id=? AND content_id=?',[$user,(int)$e['content_id']]);if($old){$this->db->execute('DELETE FROM cy_entitlements WHERE id=?',[(int)$e['id']]);}else{$this->db->update('cy_entitlements',(int)$e['id'],['user_id'=>$user]);}}
            foreach(['cy_orders','cy_sales','cy_aftersales'] as $t){$this->db->execute('UPDATE '.$t.' SET user_id=? WHERE user_id=?',[$user,$gid]);}
            $this->db->update('cy_guest_passes',(int)$pass['id'],['claimed_by'=>$user]);$this->db->update('cy_users',$gid,['status'=>'blocked']);return $count;
        });
    }
}
