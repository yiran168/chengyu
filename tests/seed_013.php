<?php
// Only disposable test sites use these real transaction fixtures. No production balances.
$a->settings->seed(['creator_income_enabled'=>true,'creator_hold_days'=>7,'creator_share_bps'=>8000,'creator_balance_conversion'=>true,'withdrawals_enabled'=>true]);
$db->update('cy_users',$admin,['creator_enabled'=>1]);
$buyer=$db->insert('cy_users',['username'=>'preview_reader_013','email'=>'reader013@example.test','password_hash'=>'not-a-login','display_name'=>"\u{793a}\u{4f8b}\u{8bfb}\u{8005}",'role'=>'user','status'=>'active','bio'=>'Disposable transaction fixture','created_at'=>time()]);
$a->wallet->adjust($buyer,'balance',100000,'visual013:funding','Disposable test transaction funding');
$labels=json_decode('["\u72ec\u7acb\u521b\u4f5c\u7684\u7b2c\u4e00\u672c\u7b14\u8bb0","\u4ece\u7075\u611f\u5230\u4f5c\u54c1\uff1a\u4e00\u4efd\u884c\u52a8\u6307\u5357","\u6253\u9020\u8212\u9002\u7684\u9605\u8bfb\u7a7a\u95f4","\u6bcf\u5468\u4e00\u4e2a\u5c0f\u4f5c\u54c1\uff1a\u521b\u4f5c\u6e05\u5355","\u8ba9\u5185\u5bb9\u66f4\u6e05\u6670\u7684\u7f16\u6392\u65b9\u6cd5","\u7b80\u5355\u7684\u65e5\u5e38\uff0c\u8ba4\u771f\u7684\u8bb0\u5f55"]',true);
foreach($labels as $i=>$label){
 $id=$a->content->save($db->one('SELECT * FROM cy_users WHERE id=?',[$admin]),['kind'=>'article','title'=>$label,'body'=>'## Original work\n\nThis is isolated preview data, not a real sale.','excerpt'=>"\u{628a}\u{597d}\u{60f3}\u{6cd5}\u{7559}\u{4e0b}\u{6765}\u{ff0c}\u{4e5f}\u{5206}\u{4eab}\u{7ed9}\u{9700}\u{8981}\u{7684}\u{4eba}\u{3002}",'protected_body'=>'Private preview content.','status'=>'published','price_currency'=>'balance','price'=>[39,69,29,49,79,19][$i].'.00','access_level'=>'paid','creator_pricing'=>1,'cover_id'=>0],true);
 $order=$a->commerce->buy($buyer,'content',$id,bin2hex(random_bytes(16)));$revenue=(int)$db->value('SELECT id FROM cy_creator_earnings WHERE order_id=?',[(int)$order['id']]);
 if($i<3){$db->update('cy_creator_earnings',$revenue,['release_at'=>time()-1]);$a->creator->settleOne($admin,$revenue);}
 if($i===5){$a->commerce->requestRefund($buyer,(int)$order['id'],'Disposable refund fixture');$a->commerce->refund($admin,(int)$order['id'],true);}
 $a->discovery->record($db->one('SELECT * FROM cy_users WHERE id=?',[$admin]),$id,[64,32,87,15,100,43][$i]);
}
foreach($db->all('SELECT id FROM cy_users WHERE id<>? LIMIT 3',[$admin]) as $u){$a->content->follow($admin,(int)$u['id']);}
