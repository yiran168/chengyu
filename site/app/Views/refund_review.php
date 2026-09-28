<?= form_start('admin_refund','','',false).hidden('id',$order['id']) ?>
<?php select_field('decision','Decision',options(['approve','reject']));partial('factor_proof',['factorRequired'=>$a->secondFactor->status((int)$me['id'])['enabled']]);button('Review refund','secondary small','shield'); ?>
</form>
