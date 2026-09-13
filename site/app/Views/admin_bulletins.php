<?php
use Chengyu\Core\{Input,Security};
$editId=Input::integer($_GET['edit']??0);$draft=$editId?$a->bulletins->get($editId,$me):[];$feed=$a->bulletins->page($_GET,$me);
?>
<div class="admin-title"><div><h1><?= t('News bulletins') ?></h1><p><?= t('Write a short public update. Future publication times take effect automatically.') ?></p></div><a class="btn secondary small" href="<?= e(url('bulletins')) ?>"><?= t('View site') ?><?= icon('arrow') ?></a></div>
<div class="bulletin-admin-grid"><section class="panel">
<div class="section-heading"><h2><?= t($editId?'Edit bulletin':'New bulletin') ?></h2><?php if($editId): ?><a href="<?= e(au('bulletins')) ?>" class="text-link"><?= t('New bulletin') ?></a><?php endif ?></div>
<?= form_start('bulletin_save','','bulletin-editor',false).hidden('id',$editId).hidden('revision',$draft['revision']??0).hidden('request_key',Security::token()) ?>
<?php field('title','Title',$draft['title']??'','text',['required'=>'required','maxlength'=>'160']);field('body','Bulletin text',$draft['body']??'','textarea',['required'=>'required','maxlength'=>'4000','rows'=>'9']); ?>
<p class="hint"><?= t('Published bulletin text and images are public. Do not include private material.') ?></p>
<?php field('source_name','Source name',$draft['source_name']??'','text',['maxlength'=>'120']);field('source_url','Source URL',$draft['source_url']??'','url',['maxlength'=>'1000']);upload_field('cover_id','Bulletin image',(int)($draft['cover_id']??0));select_field('status','Publication status',options(['draft','published','archived']),$draft['status']??'draft');field('publish_at','Publication time (site timezone)',date('Y-m-d\TH:i',(int)($draft['publish_at']??time())),'datetime-local',['required'=>'required','min'=>'1970-01-01T00:00','max'=>'2100-12-31T23:59']);button('Save bulletin','','check'); ?>
</form></section><section class="panel">
<form method="get" action="<?= e($a->basePath.'/admin/index.php') ?>" class="bulletin-filter"><?= hidden('tab','bulletins') ?><?php field('q','Search bulletins',$feed['q'],'search',['maxlength'=>'100']);select_field('status','Status',[''=>tr('All')]+options(['draft','published','archived']),$feed['status']);button('Filter','secondary','search'); ?></form>
<div class="bulletin-admin-list"><?php foreach($feed['items'] as $row): ?><article><div><a href="<?= e(au('bulletins',['edit'=>$row['id']])) ?>"><strong><?= e($row['title']) ?></strong></a><small><?= dt((int)$row['publish_at']) ?></small></div><span class="pill soft"><?= t($row['status']==='published' && (int)$row['publish_at']>time()?'Scheduled':$row['status']) ?></span></article><?php endforeach;if(!$feed['items']){empty_state('No bulletins found.','bell');} ?></div>
<?php partial('pagination',['feed'=>$feed,'tab'=>'bulletins']); ?></section></div>
