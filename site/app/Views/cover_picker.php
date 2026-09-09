<?php $coverOptions=\Chengyu\Services\VisualAssets::ART;$coverUrls=[];foreach($coverOptions as $key=>$label){$coverUrls[$key]=$a->visuals->art($key);} ?>
<?php select_field('cover_art','Built-in cover',[''=>'Unique editorial placeholder']+$coverOptions,$editing['cover_art']??'',['data-cover-assets'=>json_encode($coverUrls,JSON_THROW_ON_ERROR)]); ?>
<p class="hint"><?= t('An uploaded cover takes priority. Remove its media ID to use the selected artwork.') ?></p>
<details class="cover-collection"><summary><?= t('Browse original covers') ?></summary><div class="visual-art-gallery"><?php foreach($coverOptions as $key=>$label): ?><label class="cover-choice"><input type="radio" name="cover_pick" value="<?= e($key) ?>" data-cover-pick><img src="<?= e($coverUrls[$key]) ?>" alt="" loading="lazy" width="180" height="120"><span><?= t($label) ?></span></label><?php endforeach ?></div></details>
<script src="<?= e(asset('visual-studio.js')) ?>" defer></script>
