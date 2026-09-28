<?php
$pendingPage=\Chengyu\Core\Input::integer($_GET['pending_page']??1,1,100000);
$pendingTotal=(int)$a->db->value("SELECT COUNT(*) FROM cy_orders WHERE status='refund_requested' AND sale_id=0");
$pendingOrders=$a->db->all("SELECT id,order_no,user_id,title,amount,currency,metadata FROM cy_orders WHERE status='refund_requested' AND sale_id=0 ORDER BY id DESC LIMIT 20 OFFSET ?",[($pendingPage-1)*20]);
?>
<section class="panel section" data-wallet-refund-requests>
<div class="section-heading"><h2><?= t('Site-asset refund requests') ?></h2><span class="pill"><?= $pendingTotal ?></span></div>
<p class="hint"><?= t('Review these wallet orders here or in order details. Approved refunds return the original site asset directly. Checkout service requests and external refund tasks are listed separately below.') ?></p>
<?php foreach($pendingOrders as $order): ?>
<article class="section" data-refund-order="<?= (int)$order['id'] ?>">
<div class="row between wrap"><strong>#<?= (int)$order['id'] ?> <?= e($order['title']) ?></strong><span><?= e(price((int)$order['amount'],$order['currency'])) ?></span></div>
<p><?= t('Account') ?> #<?= (int)$order['user_id'] ?> / <?= e($order['order_no']) ?></p><p><?= nl2br(e((json_decode($order['metadata'],true)??[])['refund_reason']??'')) ?></p>
<details><summary><?= t('Review this request') ?></summary><?php partial('refund_review',['order'=>$order]); ?></details>
</article>
<?php endforeach ?>
<?php if(!$pendingTotal): ?><p class="hint"><?= t('No site-asset refund requests are waiting for review.') ?></p><?php endif ?>
<?php partial('pagination',['pagination'=>['page'=>$pendingPage,'pages'=>max(1,(int)ceil($pendingTotal/20))],'page_key'=>'pending_page','tab'=>'service']); ?>
</section>
