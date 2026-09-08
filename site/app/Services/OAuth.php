<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Crypto,Input,Problem,Security,HttpClient};

/** Authorization-code identities. Never identify an existing account by email. */
final class OAuth
{
    public const PROVIDERS=[
        'google'=>['name'=>'Google','authorize'=>'https://accounts.google.com/o/oauth2/v2/auth','token'=>'https://oauth2.googleapis.com/token','profile'=>'https://openidconnect.googleapis.com/v1/userinfo','scope'=>'openid profile'],
        'github'=>['name'=>'GitHub','authorize'=>'https://github.com/login/oauth/authorize','token'=>'https://github.com/login/oauth/access_token','profile'=>'https://api.github.com/user','scope'=>'read:user'],
        'microsoft'=>['name'=>'Microsoft','authorize'=>'https://login.microsoftonline.com/common/oauth2/v2.0/authorize','token'=>'https://login.microsoftonline.com/common/oauth2/v2.0/token','profile'=>'https://graph.microsoft.com/v1.0/me?$select=id,displayName','scope'=>'https://graph.microsoft.com/User.Read'],
    ];
    private Database $db;private Settings $settings;private Crypto $crypto;private Activity $activity;private Wallet $wallet;private Auth $auth;private SecondFactor $factor;private HttpClient $http;private string $base;
    public function __construct(Database $db,Settings $settings,Crypto $crypto,Activity $activity,Wallet $wallet,Auth $auth,SecondFactor $factor,string $base,?HttpClient $http=null)
    {$this->db=$db;$this->settings=$settings;$this->crypto=$crypto;$this->activity=$activity;$this->wallet=$wallet;$this->auth=$auth;$this->factor=$factor;$this->base=$base;$this->http=$http??new HttpClient();}
    public function available(): array
    {
        $out=[];foreach(self::PROVIDERS as $key=>$row){if($this->settings->enabled('social') && $this->settings->enabled('oauth_'.$key) && $this->settings->get('oauth_'.$key.'_id')!=='' && $this->settings->get('oauth_'.$key.'_secret')!==''){$out[$key]=$row['name'];}}return $out;
    }
    private function config(string $provider): array
    {
        Input::choice($provider,array_keys(self::PROVIDERS));$this->settings->requireModule('social');$this->settings->requireModule('oauth_'.$provider);
        if(strpos($this->base,'https://')!==0){throw new Problem('Configure an HTTPS site URL before enabling external sign-in.');}
        $id=(string)$this->settings->get('oauth_'.$provider.'_id');$secret=(string)$this->settings->get('oauth_'.$provider.'_secret');
        if($id==='' || $secret===''){throw new Problem('External sign-in is not configured.',503);}
        return array_merge(self::PROVIDERS[$provider],['id'=>$id,'secret'=>$secret,'app_hash'=>hash('sha256',$id),'fingerprint'=>$this->crypto->digest($provider.':'.$id.':'.$secret)]);
    }
    public function callbackUrl(string $provider): string
    {Input::choice($provider,array_keys(self::PROVIDERS));return $this->base.'/oauth.php?provider='.$provider;}
    public function begin(string $provider,string $purpose,string $password='',string $code=''): string
    {
        Input::choice($purpose,['login','link']);$c=$this->config($provider);$me=$this->auth->user();
        if($purpose==='login' && $me){throw new Problem('Sign out before switching accounts.',409);}
        $account=$purpose==='link'?$this->auth->prove($password,$code):null;
        $this->activity->rate('oauth-start',Security::ip(),20,900);
        $state=bin2hex(random_bytes(32));$verifier=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $flows=$_SESSION['oauth_flows']??[];foreach($flows as $k=>$v){if(($v['expires_at']??0)<=time()){unset($flows[$k]);}}
        if(count($flows)>=3){array_shift($flows);}
        $flows[$state]=['provider'=>$provider,'purpose'=>$purpose,'user_id'=>(int)($account['id']??0),'version'=>(int)($account['session_version']??0),'verifier'=>$verifier,'fingerprint'=>$c['fingerprint'],'expires_at'=>time()+600];$_SESSION['oauth_flows']=$flows;
        $q=['client_id'=>$c['id'],'redirect_uri'=>$this->callbackUrl($provider),'response_type'=>'code','scope'=>$c['scope'],'state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];
        return $c['authorize'].'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986);
    }
    public function callback(string $provider,array $input): string
    {
        $state=Input::required($input['state']??'',64);if(!preg_match('/^[0-9a-f]{64}$/D',$state)){throw new Problem('External sign-in state is invalid or expired.',403);}
        $flow=$_SESSION['oauth_flows'][$state]??null;unset($_SESSION['oauth_flows'][$state]);
        if(!$flow || $flow['provider']!==$provider || $flow['expires_at']<=time()){throw new Problem('External sign-in state is invalid or expired.',403);}
        $c=$this->config($provider);if(!hash_equals($c['fingerprint'],$flow['fingerprint'])){throw new Problem('External sign-in settings changed. Start again.',409);}
        if(isset($input['error'])){throw new Problem('External sign-in was cancelled or declined.');}
        $me=$this->auth->user();
        if(($flow['purpose']==='login' && $me) || ($flow['purpose']==='link' && (!$me || (int)$me['id']!==$flow['user_id'] || (int)$me['session_version']!==$flow['version']))){throw new Problem('The account session changed. Start sign-in again.',409);}
        $code=Input::required($input['code']??'',4096);
        $fields=['client_id'=>$c['id'],'client_secret'=>$c['secret'],'code'=>$code,'redirect_uri'=>$this->callbackUrl($provider),'grant_type'=>'authorization_code','code_verifier'=>$flow['verifier']];
        if($provider==='microsoft'){$fields['scope']=$c['scope'];}
        $token=$this->http->json('POST',$c['token'],$fields);
        if(strtolower((string)($token['token_type']??''))!=='bearer'){throw new Problem('The identity provider returned an unsupported token.',502);}
        $access=Input::required($token['access_token']??'',16000);
        $profile=$this->http->json('GET',$c['profile'],[],['Authorization'=>'Bearer '.$access]);unset($access,$token);
        $subject=Input::required($profile[$provider==='google'?'sub':'id']??'',255);
        $label=Input::clip(Input::required($profile[$provider==='microsoft'?'displayName':($provider==='github'?'login':'name')]??$c['name'],255),100);
        $identity=['provider'=>$provider,'app_hash'=>$c['app_hash'],'subject_hash'=>hash('sha256',$subject),'label'=>$label];unset($profile,$subject);
        if($flow['purpose']==='link'){
            $this->db->transaction(function()use($flow,$identity):void{
                $current=$this->wallet->lockActive($flow['user_id']);if((int)$current['session_version']!==$flow['version']){throw new Problem('Your session changed. Sign in again.',401);}
                $this->bind($flow['user_id'],$identity);
            });return 'security';
        }
        $known=$this->db->one('SELECT i.*,u.session_version,u.status FROM cy_identities i JOIN cy_users u ON u.id=i.user_id WHERE i.provider=? AND i.app_hash=? AND i.subject_hash=?',[$provider,$identity['app_hash'],$identity['subject_hash']]);
        if($known && $known['status']!=='active'){throw new Problem('Account unavailable.',403);}
        $_SESSION['oauth_pending']=array_merge($identity,['nonce'=>bin2hex(random_bytes(32)),'kind'=>$known?'login':'register','identity_id'=>(int)($known['id']??0),'user_id'=>(int)($known['user_id']??0),'version'=>(int)($known['session_version']??0),'fingerprint'=>$c['fingerprint'],'expires_at'=>time()+600]);
        return 'connect';
    }
    public function pending(?string $nonce=null): array
    {
        $p=$_SESSION['oauth_pending']??null;
        if(!$p || $p['expires_at']<=time() || ($nonce!==null && !hash_equals($p['nonce'],$nonce))){unset($_SESSION['oauth_pending']);throw new Problem('External sign-in proof expired. Start again.',403);}
        $c=$this->config($p['provider']);if(!hash_equals($c['fingerprint'],$p['fingerprint'])){unset($_SESSION['oauth_pending']);throw new Problem('External sign-in settings changed. Start again.',409);}
        return $p;
    }
    private function bind(int $user,array $identity): void
    {
        $old=$this->db->one('SELECT * FROM cy_identities WHERE provider=? AND app_hash=? AND subject_hash=?',[$identity['provider'],$identity['app_hash'],$identity['subject_hash']]);
        if($old && (int)$old['user_id']!==$user){throw new Problem('This external identity is already linked to another account.',409);}
        if($old){return;}
        if($this->db->one('SELECT id FROM cy_identities WHERE provider=? AND app_hash=? AND user_id=?',[$identity['provider'],$identity['app_hash'],$user])){throw new Problem('Unlink the old identity before linking another one.',409);}
        try{$this->db->insert('cy_identities',array_merge($identity,['user_id'=>$user,'created_at'=>time(),'last_used_at'=>0]));}catch(\PDOException $e){if((string)$e->getCode()==='23000'){throw new Problem('This external identity is already linked to another account.',409);}throw $e;}$this->activity->audit($user,'account.identity_linked',$identity['provider']);
    }
    public function login(string $nonce,string $factor): void
    {
        $p=$this->pending($nonce);if($p['kind']!=='login' || $this->auth->user()){throw new Problem('Invalid external sign-in step.',409);}
        $this->activity->rate('oauth-finish',Security::ip(),30,900);$this->activity->rate('oauth-factor',(string)$p['user_id'],10,900);
        $this->auth->loginVia(function()use($p,$factor):array{
            $current=$this->wallet->lockActive($p['user_id']);
            if((int)$current['session_version']!==$p['version']){throw new Problem('Your session changed. Sign in again.',401);}
            $link=$this->db->one('SELECT * FROM cy_identities WHERE id=? AND user_id=?',[$p['identity_id'],$p['user_id']]);
            if(!$link || !hash_equals($link['app_hash'],$p['app_hash']) || !hash_equals($link['subject_hash'],$p['subject_hash'])){throw new Problem('This identity link changed. Start again.',409);}
            $this->factor->verifyLocked($current,$factor);
            $this->db->update('cy_identities',(int)$link['id'],['last_used_at'=>time()]);$this->db->update('cy_users',(int)$current['id'],['last_login'=>time()]);$this->activity->audit((int)$current['id'],'account.oauth_login',$p['provider']);return $current;
        });
    }
    public function register(string $nonce,array $input): int
    {
        $p=$this->pending($nonce);if($p['kind']!=='register' || $this->auth->user()){throw new Problem('Invalid external registration step.',409);}
        if(empty($input['agree'])){throw new Problem('Please accept the site terms.');}
        $id=$this->db->transaction(function()use($p,$input):int{
            // Local registration still checks email proof, invitation, limits and uniqueness.
            $id=$this->auth->register($input);$this->bind($id,array_intersect_key($p,array_flip(['provider','app_hash','subject_hash','label'])));return $id;
        });unset($_SESSION['oauth_pending']);$this->auth->login((string)$input['username'],(string)$input['password']);return $id;
    }
    public function unlink(int $id,string $password,string $code): void
    {
        $me=$this->auth->prove($password,$code);
        $version=$this->db->transaction(function()use($id,$me):int{
            $current=$this->wallet->lockActive((int)$me['id']);if((int)$current['session_version']!==(int)$me['session_version']){throw new Problem('Your session changed. Sign in again.',401);}
            $row=$this->db->one('SELECT * FROM cy_identities WHERE id=? AND user_id=?',[$id,(int)$me['id']]);if(!$row){throw new Problem('Identity not found.',404);}
            $this->db->execute('DELETE FROM cy_identities WHERE id=?',[$id]);$v=(int)$current['session_version']+1;$this->db->update('cy_users',(int)$me['id'],['session_version'=>$v]);$this->activity->audit((int)$me['id'],'account.identity_unlinked',$row['provider']);return $v;
        });$this->auth->syncSession($version);unset($_SESSION['oauth_pending'],$_SESSION['oauth_flows']);
    }
    public function links(int $user): array
    {return $this->db->all('SELECT id,provider,label,created_at,last_used_at FROM cy_identities WHERE user_id=? ORDER BY id',[$user]);}
}
