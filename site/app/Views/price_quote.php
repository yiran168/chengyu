<?php
$hasVariants=(int)($item['variant_count']??0)>0 || ($item['kind']==='product' && (int)$a->db->value('SELECT COUNT(*) FROM cy_variants WHERE content_id=?',[(int)$item['id']])>0);
$quote=$a->pricing->price($item,$me,(int)$item['price_amount']);
?>
<div class="price-quote" data-price-quote data-endpoint="<?= e(url('price_quote',['id'=>$item['id']])) ?>">
<div class="quote-head"><span class="pill soft" data-quote-label><?= t(['list'=>'List price','member'=>'Member price','promotion'=>'Limited-time price'][$quote['source']]) ?></span><span class="hint"><?= t('Checked again at checkout') ?></span></div>
<div class="quote-value"><strong data-quote-amount><?= $hasVariants?t('Choose a variant'):e(price($quote['amount'],$item['price_currency'])) ?></strong><del data-quote-base <?= $quote['amount']===$quote['base_amount'] || $hasVariants?'hidden':'' ?>><?= e(price($quote['base_amount'],$item['price_currency'])) ?></del></div>
<?= hidden('price_currency',$item['price_currency']).hidden('price_ceiling',$hasVariants?'':$quote['amount']) ?>
<button type="button" class="btn secondary small quote-refresh" data-quote-refresh hidden><?= icon('refresh') ?><?= t('Preview price and coupon') ?></button><p class="hint" role="status" data-quote-status><?= t('A quote is not a stock reservation. Checkout uses the current server price; a higher price than the displayed quote requires reconfirmation.') ?></p>
</div>
