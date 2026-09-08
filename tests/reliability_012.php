<?php
// Regression first: ordinary edits may not overwrite staff-owned commercial settings.
test('ordinary author edits preserve staff-owned content policy and prices', static function() use($a,$u,$admin):void {
    $id=$a->content->save(account($u),['kind'=>'article','title'=>'Author policy fixture','body'=>'Draft body','status'=>'draft','comment_enabled'=>'1'],false);
    $a->content->save(account($admin),['id'=>$id,'kind'=>'article','title'=>'Staff managed','body'=>'Public intro','protected_body'=>'Purchased material','access_level'=>'paid','price'=>'9.99','price_currency'=>'balance','access_days'=>15,'min_vip_tier'=>2,'pinned'=>'1','featured'=>'1','vip_free'=>'1','status'=>'published','comment_enabled'=>'1'],true);
    $a->content->save(account($u),['id'=>$id,'kind'=>'article','title'=>'Revised by author','body'=>'Edited intro','protected_body'=>'Edited paid material','access_level'=>'public','status'=>'pending','comment_enabled'=>'1'],false);
    $row=$a->db->one('SELECT * FROM cy_contents WHERE id=?',[$id]);
    same('pending',$row['status']);same('paid',$row['access_level']);same(999,(int)$row['price_amount']);same(15,(int)$row['access_days']);same(2,(int)$row['min_vip_tier']);same(1,(int)$row['pinned']);same(1,(int)$row['featured']);same(1,(int)$row['vip_free']);
});
