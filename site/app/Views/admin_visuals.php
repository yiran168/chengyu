<?php
use Chengyu\Services\VisualAssets;
use Chengyu\Core\{Icons,Input};
$visualSlots=VisualAssets::slots();$selected=Input::choice($_GET['slot']??'scene:home',array_keys($visualSlots));
$binding=$a->visuals->current($selected);$isIcon=strpos($selected,'icon:')===0;
$visualChoices=$isIcon?VisualAssets::ICONS:VisualAssets::ART+VisualAssets::ICONS;
$preview=$a->visuals->image($selected);$assetUrls=[];foreach($visualChoices as $key=>$label){$assetUrls[$key]=$a->visuals->art($key);}
?>
<div class="admin-title"><div><span class="eyebrow"><?= t('Appearance management') ?></span><h1><?= t('Visual asset studio') ?></h1><p><?= t('Choose a distinct image for each place. Upload your own, preview it, or return to the original design.') ?></p></div><a class="btn secondary small" href="<?= e(au('settings',['group'=>'appearance'])) ?>"><?= icon('palette') ?><?= t('Site appearance') ?></a></div>
<div class="visual-workbench"><section class="panel visual-catalog"><div class="section-heading"><h2><?= t($isIcon?'Interface icons':'Page artwork') ?></h2><span class="pill"><?= count($isIcon?Icons::NAMES:VisualAssets::SCENES) ?></span></div>
<nav class="filters mb" aria-label="<?= t('Asset category') ?>"><a class="filter <?= !$isIcon?'active':'' ?>" href="<?= e(au('visuals',['slot'=>'scene:home'])) ?>"><?= t('Page artwork') ?></a><a class="filter <?= $isIcon?'active':'' ?>" href="<?= e(au('visuals',['slot'=>'icon:anime-discover'])) ?>"><?= t('Interface icons') ?></a><a class="filter" href="<?= e(au('media')) ?>"><?= t('My uploads') ?></a></nav>
<label class="field visual-search"><?= t('Find an icon or scene') ?><input type="search" data-visual-search placeholder="<?= t('Search by name') ?>"></label>
<div class="visual-grid <?= $isIcon?'symbol-grid':'' ?>"><?php foreach($visualSlots as $slot=>$label): if((strpos($slot,'icon:')===0)!==$isIcon){continue;}$source=$a->visuals->image($slot);$name=substr($slot,strpos($slot,':')+1); ?>
<a class="visual-tile <?= $selected===$slot?'selected':'' ?>" data-visual-name="<?= e(strtolower($name.' '.tr($label))) ?>" href="<?= e(au('visuals',['slot'=>$slot])) ?>" <?= $selected===$slot?'aria-current="true"':'' ?>><span class="visual-thumbnail"><?php if($isIcon): ?><?= icon($name) ?><?php elseif($source): ?><img src="<?= e($source) ?>" alt="" loading="lazy"><?php else: ?><?= Icons::svg('image') ?><?php endif ?></span><strong><?= t($label) ?></strong></a>
<?php endforeach ?></div></section>
<aside class="panel visual-editor"><span class="eyebrow"><?= t('Selected asset') ?></span><h2><?= t($visualSlots[$selected]) ?></h2>
<div class="visual-preview <?= $isIcon?'is-symbol':'' ?>" data-visual-preview data-default-image="<?= e($a->visuals->defaultImage($selected)) ?>"><img src="<?= e($preview?:asset('art/favicon.png')) ?>" alt="<?= t('Asset preview') ?>" <?= $preview?'':'hidden' ?>><span data-visual-fallback <?= $preview?'hidden':'' ?>><?= Icons::svg($isIcon?substr($selected,5):'image') ?></span></div>
<?= form_start('visual_save','','visual-form',false).hidden('slot',$selected).hidden('revision',$binding['revision']) ?>
<?php select_field('asset_key','Built-in artwork',[''=>'Default / uploaded image']+$visualChoices,$binding['asset_key'],['data-visual-assets'=>json_encode($assetUrls,JSON_THROW_ON_ERROR)]);upload_field('media_id','Custom public image ID',(int)$binding['media_id']); ?>
<p class="hint"><?= t('Choose a built-in asset or upload a public image. Set the other source to empty. PNG and WebP can preserve transparency.') ?></p>
<div class="row wrap"><?php button('Apply asset','','check'); ?><button class="btn secondary" type="submit" name="operation" value="reset" formnovalidate><?= icon('refresh') ?><?= t('Restore default') ?></button></div></form>
<p class="hint"><?= t('Changes apply across the site. An open older editor cannot overwrite a newer save.') ?></p>
<div class="visual-shortcuts"><a href="<?= e(au('navigation')) ?>"><?= t('Navigation') ?><?= icon('arrow') ?></a><a href="<?= e(au('categories')) ?>"><?= t('Categories') ?><?= icon('arrow') ?></a><a href="<?= e(au('builder')) ?>"><?= t('Layout studio') ?><?= icon('arrow') ?></a><a href="<?= e(au('contents')) ?>"><?= t('Content library') ?><?= icon('arrow') ?></a></div>
</aside></div>
<section class="panel section mt"><div class="section-heading"><h2><?= t('Original anime collection') ?></h2><span class="pill"><?= count(VisualAssets::ART)+count(VisualAssets::ICONS) ?></span></div><p class="hint"><?= t('Article covers are selected in the content editor. Change the site logo in appearance settings.') ?></p><div class="visual-art-gallery"><?php foreach(VisualAssets::ART+VisualAssets::ICONS as $key=>$label): ?><figure><img src="<?= e($a->visuals->art($key)) ?>" alt="<?= t($label) ?>" loading="lazy" width="240" height="160"><figcaption><?= t($label) ?></figcaption></figure><?php endforeach ?></div></section>
<script src="<?= e(asset('visual-studio.js')) ?>" defer></script>
