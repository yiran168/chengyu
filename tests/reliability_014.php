<?php
// Service-boundary regressions: these fail against the unmodified 0.13 release.
function user14(string $label='member', string $password='TestPassword!2026'): int {
    global $a;
    $name=$label.substr(key32(),0,12);
    return $a->db->insert('cy_users',['username'=>$name,'email'=>$name.'@example.test','password_hash'=>password_hash($password,PASSWORD_DEFAULT),'display_name'=>$label,'role'=>'user','status'=>'active','bio'=>'','created_at'=>time()]);
}
test('014: first tier-one purchase does not extend from a higher-tier expiry',static function()use($a){
    $id=user14();$a->membership->grant($id,3,365);$before=time();
    $until=$a->membership->grant($id,1,30);
    truth($until>=$before+30*86400 && $until<=time()+30*86400);
    same(3,$a->membership->tier(account($id)));
});
test('014: suspended account cannot consume a voucher at the service boundary',static function()use($a,$admin){
    $id=user14();$codes=$a->commerce->vouchers($admin,'points',7,1,time()+86400,'suspended check');
    $a->db->update('cy_users',$id,['status'=>'suspended']);
    reject(static function()use($a,$id,$codes){$a->commerce->redeem($id,$codes[0]);},403);
    same(0,funds($id,'points'));
    $hash=$a->crypto->digest(str_replace('-','',$codes[0]));
    same(0,(int)$a->db->value('SELECT redeemed_by FROM cy_vouchers WHERE code_hash=?',[$hash]));
});
test('014: login service rejects bcrypt-truncated passwords longer than 72 bytes',static function()use($a){
    $password=str_repeat('Ab123456',9);$id=user14('longpass',$password);$session=$_SESSION;
    try {reject(static function()use($a,$id,$password){$a->auth->login(account($id)['username'],$password.'X');});}
    finally {$a->auth->logout();$_SESSION=$session;}
});
