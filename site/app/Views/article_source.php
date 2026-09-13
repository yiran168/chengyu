<?php if(!empty($item['source_name']) || !empty($item['source_url'])): ?>
<span class="article-source"><?= t('Source') ?>: <?php if(!empty($item['source_url'])): ?><a href="<?= e($item['source_url']) ?>" target="_blank" rel="nofollow noopener noreferrer"><?= e($item['source_name']?:tr('Original link')) ?><?= icon('link') ?></a><?php else: ?><?= e($item['source_name']) ?><?php endif ?></span>
<?php endif ?>
