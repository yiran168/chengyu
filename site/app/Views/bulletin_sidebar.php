<?php $updates=$a->bulletins->page(['size'=>4])['items'];if($updates): ?>
<section class="panel bulletin-widget"><div class="section-heading"><h3><?= icon('anime-news') ?><?= t('News bulletins') ?></h3><a href="<?= e(url('bulletins')) ?>" class="text-link"><?= t('More') ?><?= icon('chevron') ?></a></div><ol>
<?php foreach($updates as $update): ?><li><a href="<?= e(url('bulletin',['id'=>$update['id']])) ?>"><?= e($update['title']) ?></a><time datetime="<?= e(date('c',(int)$update['publish_at'])) ?>"><?= dt((int)$update['publish_at'],'m-d H:i') ?></time></li><?php endforeach ?></ol></section>
<?php endif ?>
