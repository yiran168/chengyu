<?php if($item['kind']==='article'):$notice=\Chengyu\Services\Editorial::copyright($s,$item,rtrim($a->config['url'],'/').'/index.php?r=article&id='.(int)$item['id']);if($notice!==''): ?>
<aside class="article-copyright"><strong><?= icon('shield') ?><?= t('Copyright notice') ?></strong><p><?= nl2br(e($notice)) ?></p></aside>
<?php endif;endif ?>
