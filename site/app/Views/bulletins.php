<div class="section-heading bulletin-heading"><div><h1><?= icon('anime-news') ?><?= t('News bulletins') ?></h1><p><?= t('Short updates, in chronological order.') ?></p></div><?php if($a->auth->can('content')): ?><a class="btn secondary small" href="<?= e(au('bulletins')) ?>"><?= icon('edit') ?><?= t('Publish an update') ?></a><?php endif ?></div>
<div class="main-grid <?= !$s->get('show_sidebar')?'no-sidebar':'' ?>"><section class="panel bulletin-panel">
<form class="bulletin-filter" method="get" action="<?= e($a->basePath.'/index.php') ?>"><?= hidden('r','bulletins') ?>
<?php field('q','Search bulletins',$feed['q'],'search',['maxlength'=>'100']);field('period','Year or month',$feed['period'],'text',['placeholder'=>'2026-09','pattern'=>'[0-9]{4}(-[0-9]{2})?','maxlength'=>'7']);button('Filter','secondary','search'); ?><a href="<?= e(url('bulletins')) ?>" class="text-link"><?= t('All dates') ?></a></form>
<div class="bulletin-timeline"><?php $day='';foreach($feed['items'] as $entry):$entryDay=date('Y-m-d',(int)$entry['publish_at']);if($day!==$entryDay):$day=$entryDay; ?><h2 class="bulletin-day"><time datetime="<?= e($day) ?>"><?= e($day) ?></time></h2><?php endif ?>
<?php partial('bulletin_entry',['entry'=>$entry,'standalone'=>false]); ?>
<?php endforeach;if(!$feed['items']){empty_state('No bulletins found.','bell');} ?></div>
<?php partial('pagination',['feed'=>$feed,'route'=>'bulletins']); ?></section><?php if($s->get('show_sidebar')){partial('sidebar');} ?></div>
