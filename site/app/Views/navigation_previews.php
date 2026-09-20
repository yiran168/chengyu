<div class="nav-content-list">
<?php foreach($entries as $entry):$hasImage=!empty($entry['cover_id']) || ($s->get('default_cover_style')==='anime' && isset(\Chengyu\Services\VisualAssets::ART[$entry['cover_art']??''])); ?>
<a class="nav-content-item" href="<?= e(item_url($entry)) ?>" data-preview-id="<?= (int)$entry['id'] ?>">
<?php if($hasImage): ?><img src="<?= e(cover($entry)) ?>" alt="" width="88" height="60" loading="lazy" decoding="async"><?php endif ?>
<span class="nav-content-copy"><span class="nav-content-title"><?= e($entry['title']) ?></span><span class="nav-content-meta">
<?php if($entry['access_level']!=='public'): ?><span><?= icon('lock') ?><?= t($entry['access_level']==='paid'?'Paid access':$entry['access_level']) ?></span><?php endif ?>
<?php if($s->get('show_dates')): ?><time datetime="<?= e(date('c',(int)$entry['created_at'])) ?>"><?= dt((int)$entry['created_at'],'Y-m-d') ?></time><?php endif ?>
</span></span></a>
<?php endforeach ?>
</div>
