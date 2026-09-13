<?php if($kind==='article'): ?>
<details class="editor-attribution" <?= !empty($editing['source_name'])?'open':'' ?>><summary><?= icon('feather') ?><?= t('Source and copyright') ?></summary>
<?php field('source_name','Source name',$editing['source_name']??'','text',['maxlength'=>'120']);field('source_url','Source URL',$editing['source_url']??'','url',['maxlength'=>'1000']);select_field('copyright_mode','Copyright notice',['default'=>tr('Site default'),'original'=>tr('Original work'),'permission'=>tr('Published with permission'),'reprint'=>tr('Reprint'),'none'=>tr('No notice')],$editing['copyright_mode']??'default'); ?>
<p class="hint"><?= t('Choose a notice that matches the rights you hold. A notice does not grant permission.') ?></p>
<?php if($a->auth->can('manage')): ?><a href="<?= e(au('settings',['group'=>'editorial'])) ?>" class="text-link"><?= t('Edit copyright templates') ?></a><?php endif ?>
</details><?php endif ?>
