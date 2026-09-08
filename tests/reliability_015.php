<?php
// These regressions are intentionally executed against 0.14 before the patch.
function auth15(int $id): \Chengyu\Services\Auth {
    global $a;
    $_SESSION['user_id']=$id;$_SESSION['session_version']=(int)account($id)['session_version'];$_SESSION['last_active']=time();
    return new \Chengyu\Services\Auth($a->db,$a->settings,$a->crypto,$a->activity,$a->wallet,$a->secondFactor);
}
test('015: revoked cached session cannot change password and resurrect itself',static function()use($a){
    $saved=$_SESSION;$id=user14('revoke');$auth=auth15($id);$auth->user();
    try {
        $a->db->execute('UPDATE cy_users SET session_version=session_version+1 WHERE id=?',[$id]);
        reject(static function()use($auth){$auth->changePassword('TestPassword!2026','DifferentPassword!2026');},401);
        truth(password_verify('TestPassword!2026',account($id)['password_hash']));
    } finally {$_SESSION=$saved;}
});
test('015: password proof rejects overlong bcrypt input at service boundary',static function()use($a){
    $saved=$_SESSION;$pw=str_repeat('Ab123456',9);$id=user14('proof',$pw);$auth=auth15($id);
    try {reject(static function()use($auth,$pw){$auth->verifyPassword($pw.'different');});}
    finally {$_SESSION=$saved;}
});
test('015: profile edit rejects a concurrently suspended cached identity',static function()use($a){
    $saved=$_SESSION;$id=user14('profile');$auth=auth15($id);$auth->user();
    try {
        $a->db->update('cy_users',$id,['status'=>'suspended']);
        reject(static function()use($auth){$auth->profile(['display_name'=>'Changed after suspension']);},401);
        same('profile',account($id)['display_name']);
    } finally {$_SESSION=$saved;}
});
