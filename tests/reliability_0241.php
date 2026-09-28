<?php
function recoveryUser241(string $role='user'): int {
    global $a;$id=user14('r241');$a->db->update('cy_users',$id,['role'=>$role]);return $id;
}
function recoveryFactor241(int $user): string {
    global $a;$secret=\Chengyu\Core\Totp::encode(random_bytes(20));
    $a->db->insert('cy_second_factors',['user_id'=>$user,'secret_cipher'=>$a->crypto->seal($secret),'last_step'=>-1,'recovery_hashes'=>'[]','enabled_at'=>time()]);return $secret;
}
function recover241(int $actor,int $user,array $changes=[]): void {
    global $a;$values=array_merge(['version'=>(int)account($actor)['session_version'],'password'=>'TestPassword!2026','code'=>'','target'=>(int)account($user)['session_version'],'username'=>account($user)['username'],'reason'=>'Owner verified in isolated test'],$changes);
    $a->secondFactor->recoverByAdmin($actor,$values['version'],$values['password'],$values['code'],$user,$values['target'],$values['username'],$values['reason']);
}
test('0241 invitation resume preserves usage and enforces expiry and quota',static function()use($a,$admin){
    $codes=$a->invitations->issue($admin,1,2,time()+3600,'Resume241');$id=(int)$a->db->value('SELECT id FROM cy_invitations WHERE label=?',['Resume241']);
    $a->db->transaction(static function()use($a,$codes){$a->invitations->consume($codes[0]);});
    $a->invitations->disable($admin,$id);reject(static function()use($a,$codes){$a->db->transaction(static function()use($a,$codes){$a->invitations->consume($codes[0]);});});
    $a->invitations->enable($admin,$id);same(1,(int)$a->db->value('SELECT uses FROM cy_invitations WHERE id=?',[$id]));
    $a->db->transaction(static function()use($a,$codes){$a->invitations->consume($codes[0]);});$a->invitations->disable($admin,$id);
    reject(static function()use($a,$admin,$id){$a->invitations->enable($admin,$id);},409);
    $a->db->update('cy_invitations',$id,['uses'=>0,'expires_at'=>time()-1]);reject(static function()use($a,$admin,$id){$a->invitations->enable($admin,$id);},409);
    reject(static function()use($a,$admin){$a->invitations->enable($admin,2147483647);},404);
});
test('0241 repeated invitation state changes have one audit event',static function()use($a,$admin){
    $a->invitations->issue($admin,1,1,time()+3600,'Replay241');$id=(int)$a->db->value('SELECT id FROM cy_invitations WHERE label=?',['Replay241']);
    $a->invitations->disable($admin,$id);$a->invitations->disable($admin,$id);$a->invitations->enable($admin,$id);$a->invitations->enable($admin,$id);
    foreach(['enabled','disabled'] as $state){same(1,(int)$a->db->value('SELECT COUNT(*) FROM cy_audit WHERE action=? AND target=?',['invitations.'.$state,(string)$id]));}
});
test('0241 wrong-type refunded orders cannot report successful upgrade rollback',static function()use($a,$admin){
    $u=recoveryUser241();$item=product();$a->wallet->adjust($u,'balance',100,'refund241:fund','fixture');$o=$a->commerce->buy($u,'content',$item,key32());
    $a->commerce->requestRefund($u,(int)$o['id'],'Fixture refund');$a->commerce->refund($admin,(int)$o['id'],true);$before=funds($u);
    reject(static function()use($a,$admin,$o){$a->upgrades->refund($admin,(int)$o['id']);});same($before,funds($u));same('refunded',$a->db->value('SELECT status FROM cy_orders WHERE id=?',[$o['id']]));
});
test('0241 administrator recovery revokes factors sessions resets and records its actor',static function()use($a){
    $actor=recoveryUser241('admin');$user=recoveryUser241();recoveryFactor241($user);resetFixture($user);$version=(int)account($user)['session_version'];
    recover241($actor,$user);same(false,$a->secondFactor->status($user)['enabled']);same($version+1,(int)account($user)['session_version']);same(0,(int)$a->db->value('SELECT COUNT(*) FROM cy_resets WHERE user_id=? AND used_at=0',[$user]));
    same($actor,(int)$a->db->value("SELECT actor_id FROM cy_audit WHERE action='account.factor_admin_recovery' AND target LIKE ?",[$user.' / %']));
    reject(static function()use($actor,$user,$version){recover241($actor,$user,['target'=>$version]);},409);
});
test('0241 recovery denies non-admin self stale-session and incorrect password proofs',static function()use($a){
    $actor=recoveryUser241('admin');$user=recoveryUser241();recoveryFactor241($user);$ordinary=recoveryUser241();
    reject(static function()use($ordinary,$user){recover241($ordinary,$user);},403);
    reject(static function()use($actor){recover241($actor,$actor);},403);
    reject(static function()use($actor,$user){recover241($actor,$user,['password'=>'wrong']);},403);
    reject(static function()use($actor,$user){recover241($actor,$user,['version'=>999]);},403);
    truth($a->secondFactor->status($user)['enabled']);
});
test('0241 recovery refuses wrong target username stale revision and inactive accounts',static function()use($a){
    $actor=recoveryUser241('admin');$user=recoveryUser241();recoveryFactor241($user);
    reject(static function()use($actor,$user){recover241($actor,$user,['username'=>'another-person']);},409);
    reject(static function()use($actor,$user){recover241($actor,$user,['target'=>999]);},409);
    $a->db->update('cy_users',$user,['status'=>'suspended']);reject(static function()use($actor,$user){recover241($actor,$user);},409);truth($a->secondFactor->status($user)['enabled']);
});
test('0241 enabled administrator factor is required for ordinary recovery',static function()use($a){
    $actor=recoveryUser241('admin');$user=recoveryUser241();recoveryFactor241($actor);recoveryFactor241($user);
    reject(static function()use($actor,$user){recover241($actor,$user);},401);truth($a->secondFactor->status($user)['enabled']);
});
test('0241 recovery of another administrator requires own enrolled second factor',static function()use($a){
    $actor=recoveryUser241('admin');$user=recoveryUser241('admin');recoveryFactor241($user);
    reject(static function()use($actor,$user){recover241($actor,$user);},403);
    $secret=recoveryFactor241($actor);$code=\Chengyu\Core\Totp::code($secret,time());recover241($actor,$user,['code'=>$code]);same(false,$a->secondFactor->status($user)['enabled']);
    $other=recoveryUser241();recoveryFactor241($other);reject(static function()use($actor,$other,$code){recover241($actor,$other,['code'=>$code]);},403);truth($a->secondFactor->status($other)['enabled']);
});
if($a->db->driver()==='mysql'){
    test('0241 documented no-SSH MySQL recovery checks identity and revokes sessions once',static function()use($a){
        $user=recoveryUser241('admin');recoveryFactor241($user);resetFixture($user);$version=(int)account($user)['session_version'];
        $text=file_get_contents(dirname(__DIR__).'/docs/ACCOUNT_RECOVERY.md');
        truth((bool)preg_match('/```sql\n(SET @cy_recovery_id.*?);\n```/s',$text,$match));
        $sql=str_replace(['SET @cy_recovery_id = 123;',"SET @cy_recovery_username = 'confirmed_admin_name';"],['SET @cy_recovery_id = '.$user.';',"SET @cy_recovery_username = '".account($user)['username']."';"],$match[1].';');
        $run=static function(string $script)use($a):void{foreach(explode(';',$script) as $statement){if(trim($statement)!==''){$a->db->execute(trim($statement));}}};
        $run(str_replace(account($user)['username'],'wrong_owner',$sql));truth($a->secondFactor->status($user)['enabled']);
        $run($sql);same(false,$a->secondFactor->status($user)['enabled']);same($version+1,(int)account($user)['session_version']);same(0,(int)$a->db->value('SELECT COUNT(*) FROM cy_resets WHERE user_id=? AND used_at=0',[$user]));
        $run($sql);same($version+1,(int)account($user)['session_version']);same(1,(int)$a->db->value("SELECT COUNT(*) FROM cy_audit WHERE action='account.factor_database_recovery' AND target=?",[(string)$user]));
    });
}
