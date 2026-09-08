<?php if($s->get('locale_switcher')): ?>
<?= form_start('locale_select','','locale-form',false).hidden('scope',\Chengyu\Core\Locale::scope()) ?>
<input type="hidden" name="locale" value="<?= \Chengyu\Core\Locale::current()==='zh-CN'?'en-US':'zh-CN' ?>">
<button class="locale-button" type="submit" title="<?= t('Switch interface language') ?>" aria-label="<?= t('Switch interface language') ?>"><?= \Chengyu\Core\Locale::current()==='zh-CN'?'EN':'&#20013;&#25991;' ?></button></form>
<?php endif ?>