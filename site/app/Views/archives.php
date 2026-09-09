<section class="archive-hero panel glass"><div><span class="eyebrow"><?= t('Article archive') ?></span><h1><?= e($title) ?></h1><p><?= t('Follow the dates. Rediscover a thought worth keeping.') ?></p><span class="pill soft"><?= (int)$feed['total'] ?> <?= t('Stories in this selection') ?></span></div><img src="<?= e($a->visuals->image('scene:archives')) ?>" width="720" height="480" alt="" fetchpriority="high"></section>
<form class="archive-filter panel" action="<?= e($a->basePath.'/index.php') ?>" method="get">
    <?= hidden('r','archives') ?>
    <?php field('period','Year or month',$period,'text',['placeholder'=>'2026 / 2026-09','pattern'=>'[0-9]{4}(-[0-9]{2})?','maxlength'=>'7']);
    $categoryChoices=[0=>tr('All discoveries')];foreach($a->categories->visible('article',$me) as $cat){$categoryChoices[$cat['id']]=$cat['name'];}
    select_field('category','Category',$categoryChoices,$category);button('Browse archive','','clock'); ?>
    <a class="text-link" href="<?= e(url('archives')) ?>"><?= t('All dates') ?></a>
</form>
<div class="archive-journal">
<?php $archiveMonth='';foreach($feed['items'] as $entry):$entryMonth=date('Y-m',(int)$entry['created_at']);if($entryMonth!==$archiveMonth):if($archiveMonth!==''): ?></div></section><?php endif;$archiveMonth=$entryMonth; ?>
<section class="archive-month"><h2><a href="<?= e(url('archives',['period'=>$entryMonth,'category'=>$category])) ?>"><?= e($entryMonth) ?></a></h2><div class="archive-entries">
<?php endif ?>
<article class="archive-entry"><time datetime="<?= e(date('c',(int)$entry['created_at'])) ?>"><?= e(date('d',(int)$entry['created_at'])) ?></time><div><div class="archive-entry-meta"><span><?= e($entry['category_name']?:tr('Uncategorized')) ?></span><?php if($entry['access_level']!=='public'): ?><span><?= icon('lock') ?><?= t($entry['access_level']) ?></span><?php endif ?></div><h3><a href="<?= e(item_url($entry)) ?>"><?= e($entry['title']) ?></a></h3><?php if($entry['excerpt']!==''): ?><p><?= e(\Chengyu\Core\Input::clip($entry['excerpt'],140)) ?></p><?php endif ?></div><a class="archive-open icon-btn" href="<?= e(item_url($entry)) ?>" aria-label="<?= e(tr('Read more').' '.$entry['title']) ?>"><?= icon('arrow') ?></a></article>
<?php endforeach;if($archiveMonth!==''): ?></div></section><?php else: empty_state('No stories in this period yet. Try another date.','book');endif ?>
</div>
<?php partial('pagination',['feed'=>$feed,'route'=>'archives']); ?>
