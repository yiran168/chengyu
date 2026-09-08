<?php
$filterOptions=[
    'access'=>['Access type',[''=>'All access','public'=>'Public','login'=>'Sign in','vip'=>'VIP','paid'=>'Paid access','reply'=>'Reply to unlock','password'=>'Password']],
    'currency'=>['Price asset',[''=>'All assets','balance'=>'Balance','points'=>'Points','tokens'=>'Tokens']],
    'period'=>['Published within',[''=>'Any time','week'=>'Last 7 days','month'=>'Last 30 days','year'=>'Last 365 days']],
];
if($kind==='product'){$filterOptions['product_type']=['Delivery type',[''=>'All delivery types','digital'=>'Digital files','code'=>'License codes','physical'=>'Physical goods','invitation'=>'Invitation']];}
$advanced=false;foreach(\Chengyu\Services\CatalogFilters::KEYS as $filterKey){if(($filters[$filterKey]??'')!==''){$advanced=true;}}
?>
<details class="catalog-filter-panel panel" <?= $advanced?'open':'' ?>>
<summary><span class="row"><?= icon('settings') ?><strong><?= t('Refine your discovery') ?></strong><?php if($advanced): ?><span class="pill soft"><?= t('Filters applied') ?></span><?php endif ?></span><?= icon('chevron') ?></summary>
<form method="get" action="<?= e($a->basePath.'/index.php') ?>" class="catalog-filter-form">
<?= hidden('r',$route).hidden('kind',$kind).hidden('category',$filters['category']).hidden('sort',$filters['sort']).hidden('q',$filters['q']) ?>
<div class="catalog-filter-grid"><?php foreach($filterOptions as $name=>[$label,$choices]){select_field($name,$label,array_map('tr',$choices),$filters[$name]??'');} ?>
<?php field('tag','Exact tag',$filters['tag']??'','text',['maxlength'=>'50']);field('min_price','Minimum list price',$filters['min_price']??'','text',['inputmode'=>'decimal','maxlength'=>'20']);field('max_price','Maximum list price',$filters['max_price']??'','text',['inputmode'=>'decimal','maxlength'=>'20']); ?></div>
<div class="row between wrap"><div><?php if($kind==='product'): ?><label class="checkbox"><input type="checkbox" name="stock" value="available" <?= ($filters['stock']??'')==='available'?'checked':'' ?>><span><?= t('In stock only') ?></span></label><?php endif ?><p class="hint"><?= t('Price filters use the lowest available SKU list price. Member discounts and coupons are calculated at checkout.') ?></p></div><div class="row"><?php button('Apply filters','small','search'); ?><a class="btn secondary small" href="<?= e(url($route,['kind'=>$kind,'q'=>$filters['q']])) ?>"><?= t('Reset filters') ?></a></div></div>
</form></details>
