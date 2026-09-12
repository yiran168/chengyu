<?php $portal=$s->get('home_presentation')==='portal'; ?>
<?php if($s->get('studio_enabled')){partial('composed',['slot'=>'home']);} ?>
<?php if(!$portal){partial('home_hero');} ?>
<?php if($portal): ?>
<div class="portal-heading"><div><h1><?= e($s->get('site_name')) ?></h1><span><?= e($s->get('site_tagline')) ?></span></div><?php if(\Chengyu\Services\Navigation::enabled($s,'archives')): ?><a class="text-link" href="<?= e(url('archives')) ?>"><?= icon('anime-archive') ?><?= t('Article archive') ?></a><?php endif ?></div>
<?php endif ?>
<nav class="quick-links <?= $portal?'portal-links':'' ?>" aria-label="<?= t('Browse sections') ?>"><?php foreach (['articles'=>['Articles','Read articles','book'],'forum'=>['Community','Recent discussions','message'],'shop'=>['Resources','Downloads and tools','box'],'membership'=>['Membership','View membership plans','crown']] as $r=>$v): if(!$s->enabled($r)){continue;} ?><a class="quick-link glass" href="<?= e(url($r)) ?>"><span class="quick-icon"><?= icon($v[2]) ?></span><span><strong><?= t($v[0]) ?></strong><small><?= t($v[1]) ?></small></span><?= icon('chevron') ?></a><?php endforeach ?></nav>
<div class="main-grid <?= !$s->get('show_sidebar')?'no-sidebar':'' ?> <?= $portal?'portal-main':'' ?>"><div class="main-column">
<?php foreach(explode(',',$s->get('home_sections')) as $section):
if($section==='featured' && $s->enabled('articles')):
    if($portal && ($homeFilters['category'] || $homeFilters['page']>1)){continue;}
    $entries=$a->content->feed('article',$me,['featured'=>true,'size'=>2])['items'];if(!$entries){continue;} ?>
<section class="section home-featured"><div class="section-heading"><h2><?= t('Featured articles') ?></h2></div><div class="featured-grid"><?php foreach($entries as $entry): ?><a class="featured-item panel glass reveal" href="<?= e(item_url($entry)) ?>"><img src="<?= e(cover($entry)) ?>" alt="" loading="lazy"><div><span class="pill soft"><?= e($entry['category_name']) ?></span><h3><?= e($entry['title']) ?></h3><?php if($s->get('show_dates')): ?><time datetime="<?= e(date('c',(int)$entry['created_at'])) ?>"><?= dt((int)$entry['created_at'],'Y-m-d') ?></time><?php endif ?></div></a><?php endforeach ?></div></section>
<?php elseif($section==='articles' && $s->enabled('articles')):
    $articleFeed=$a->content->feed('article',$me,$portal?$homeFilters:['size'=>6]); ?>
<section id="home-articles" class="section home-articles layout-<?= e($portal?'list':$s->get('card_layout')) ?>">
<?php if($portal): ?>
<div class="home-feed-tabs"><nav class="filters" aria-label="<?= t('Article categories') ?>"><a class="filter <?= !$homeFilters['category']?'active':'' ?>" href="<?= e(url('home',['sort'=>$homeFilters['sort']]).'#home-articles') ?>"><?= t('Latest articles') ?></a><?php foreach($a->categories->visible('article',$me) as $cat): ?><a class="filter <?= $homeFilters['category']===(int)$cat['id']?'active':'' ?>" href="<?= e(url('home',['category'=>$cat['id'],'sort'=>$homeFilters['sort']]).'#home-articles') ?>"><?= e($cat['name']) ?></a><?php endforeach ?></nav></div>
<div class="home-feed-toolbar"><span><?= (int)$articleFeed['total'] ?> <?= t('articles') ?></span><nav aria-label="<?= t('Sort articles') ?>"><?php foreach(['latest'=>'Latest','popular'=>'Most viewed'] as $sort=>$label): ?><a class="<?= $homeFilters['sort']===$sort?'active':'' ?>" href="<?= e(url('home',['category'=>$homeFilters['category'],'sort'=>$sort]).'#home-articles') ?>"><?= t($label) ?></a><?php endforeach ?></nav><a class="text-link" href="<?= e(url('articles')) ?>"><?= t('All articles') ?><?= icon('chevron') ?></a></div>
<?php else: ?><div class="section-heading"><h2><?= t('Latest articles') ?></h2><a class="text-link" href="<?= e(url('articles')) ?>"><?= t('All articles') ?><?= icon('arrow') ?></a></div><?php endif ?>
<div class="card-grid"><?php foreach($articleFeed['items'] as $entry){partial('card',['entry'=>$entry,'presentation'=>$portal?'row':'card']);} ?></div><?php if(!$articleFeed['items']){empty_state();} ?>
<?php if($portal){partial('pagination',['feed'=>$articleFeed,'route'=>'home','anchor'=>'home-articles']);} ?></section>
<?php elseif($section==='community' && $s->enabled('forum')): $entries=$a->content->feed('thread',$me,['size'=>4])['items']; ?>
<section class="section"><div class="section-heading"><h2><?= t('Recent discussions') ?></h2><a class="text-link" href="<?= e(url('forum')) ?>"><?= t('Community') ?><?= icon('arrow') ?></a></div><div class="thread-list panel glass"><?php foreach($entries as $entry){partial('thread',['entry'=>$entry]);}if(!$entries){empty_state();} ?></div></section>
<?php elseif($section==='products' && $s->enabled('shop')): $entries=$a->content->feed('product',$me,['size'=>6])['items']; ?>
<section class="section home-products"><div class="section-heading"><h2><?= t('Latest resources') ?></h2><a class="text-link" href="<?= e(url('shop')) ?>"><?= t('All resources') ?><?= icon('arrow') ?></a></div><div class="card-grid"><?php foreach($entries as $entry){partial('card',['entry'=>$entry]);} ?></div><?php if(!$entries){empty_state();} ?></section>
<?php endif;endforeach ?></div><?php if($s->get('show_sidebar')){partial('sidebar');} ?></div>
