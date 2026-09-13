<details class="editor-images"><summary><?= icon('anime-gallery') ?><?= t('Images and gallery') ?></summary>
<p class="hint"><?= t('Upload a public image, then insert its media ID into the body. Private images are never embedded.') ?></p>
<?php upload_field('inline_image','Image to insert',0);field('inline_image_alt','Image description','','text',['maxlength'=>'200']); ?>
<button type="button" class="btn secondary small" data-insert-image data-image-required="<?= t('Choose an image first.') ?>" data-image-inserted="<?= t('Image inserted. Save the article to publish your changes.') ?>"><?= icon('plus') ?><?= t('Insert into public body') ?></button><p class="hint" role="status" data-image-insert-message></p>
<p class="hint"><?= t('Without JavaScript, use this syntax in the public body: ![description](media:123). Replace 123 with your image ID.') ?></p>
<?php if($kind==='article'): ?>
<?php select_field('image_layout','Article image layout',['default'=>tr('Site default'),'single'=>tr('Single image'),'gallery'=>tr('Multiple images'),'text'=>tr('Text only')],$editing['image_layout']??'default');$gallery=\Chengyu\Services\ArticleImages::ids($editing['gallery_ids']??'[]');for($n=1;$n<=4;$n++){upload_field('gallery_image_'.$n,tr('Gallery image').' '.$n,(int)($gallery[$n-1]??0));} ?>
<p class="hint"><?= t('Choose two to four different public images. If all slots are empty, the list uses public-body images after saving. Protected-body images are excluded.') ?></p>
<?php endif ?></details>
