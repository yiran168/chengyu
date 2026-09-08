<div class="field-divider"></div><span class="eyebrow">SPACE / ACCESS / COMMUNITY</span>
<?php $parents=[0=>tr('None')];foreach($a->db->all('SELECT id,name,kind FROM cy_categories ORDER BY sort_order,id') as $parent){if((int)$parent['id']!==(int)($v['id']??0)){$parents[$parent['id']]=$parent['name'].' / '.tr($parent['kind']);}}
select_field('parent_id','Parent category',$parents,$v['parent_id']??0);
select_field('post_level','Who may post',options(['login','vip','members','staff']),$v['post_level']??'login');
select_field('min_vip_tier','Required membership tier',[1=>'VIP 1',2=>'VIP 2',3=>'VIP 3'],$v['min_vip_tier']??1);
check_field('is_circle','Enable circle membership (forum categories only)',!empty($v['is_circle']));
select_field('join_currency','Circle joining asset',options(['balance','points','tokens']),$v['join_currency']??'points');
field('join_price','Circle joining price (0 free)',($v['join_currency']??'points')==='balance'?money((int)($v['join_amount']??0)):($v['join_amount']??0),'number',['step'=>'0.01','min'=>'0']);
field('join_days','Circle membership days (0 permanent)',$v['join_days']??30,'number',['min'=>'0','max'=>'3650']);
check_field('join_open','Accept new circle members',!isset($v['join_open'])||(bool)$v['join_open']); ?>
<p class="hint"><?= t('Read and post permissions are independent. Parent restrictions always apply. Active memberships do not need repurchasing.') ?></p>
