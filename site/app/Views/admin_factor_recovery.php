<?php $targetFactor=$a->secondFactor->status((int)$person['id']); ?>
<section class="panel section" data-factor-recovery>
<h2><?= t('Authenticator recovery') ?></h2>
<p><?= t('Verify the account owner outside this form before resetting their authenticator. Recovery removes their factor and unused recovery codes, revokes all sessions and password-reset links, and records your reason in the audit log.') ?></p>
<?php if(!$targetFactor['enabled']): ?>
<p class="hint"><?= t('Two-factor authentication is not enabled.') ?></p>
<?php elseif((int)$person['id']===(int)$me['id']): ?>
<p class="hint"><?= t('Use your security center or the private operator recovery procedure for your own account.') ?></p>
<a class="btn secondary" href="<?= e(url('security')) ?>"><?= t('Security center') ?></a>
<?php else: ?>
<?= form_start('admin_factor_recover','','',false).hidden('user_id',$person['id']).hidden('target_version',$person['session_version']) ?>
<?php field('target_username','Type the target username to confirm','','text',['required'=>'required','maxlength'=>'32','autocomplete'=>'off']);field('reason','Identity verification and recovery reason','','textarea',['required'=>'required','maxlength'=>'180']);partial('factor_proof',['factorRequired'=>$a->secondFactor->status((int)$me['id'])['enabled']]);check_field('confirm_recovery','I verified the account owner and understand that all target sessions will be revoked.',false);button('Reset authenticator and revoke sessions','danger','shield'); ?>
</form>
<?php if($person['role']==='admin'): ?><p class="hint"><?= t('Enroll your own authenticator before recovering another administrator.') ?></p><?php endif ?>
<?php endif ?>
</section>
