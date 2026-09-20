<?php
use Chengyu\Core\Security;
$v=$edit??[];$byId=[];foreach($rows as $row){$byId[(int)$row['id']]=$row;}
usort($rows,static function(array $a,array $b):int{return [(int)$a['sort_order'],(int)$a['id']]<=>[(int)$b['sort_order'],(int)$b['id']];});
$parents=[0=>tr('Top level')];foreach($rows as $row){if((int)$row['id']!==(int)($v['id']??0)){$parents[(int)$row['id']]=$row['label'].' #'.$row['id'];}}
$categoryOptions=[0=>tr('All categories')];foreach($a->db->all('SELECT id,kind,name FROM cy_categories ORDER BY kind,sort_order,id') as $category){$categoryOptions[(int)$category['id']]=tr($category['kind']).' / '.$category['name'].' #'.$category['id'];}
?>
<div class="admin-title"><div><h1><?= t('Navigation') ?></h1><p><?= t('Organize up to three menu levels. Parent visibility applies to all children.') ?></p></div><a class="btn secondary" href="<?= e(au('navigation')) ?>"><?= icon('plus') ?><?= t('New menu entry') ?></a></div>
<div class="navigation-admin-grid"><section class="panel"><h2><?= t('Menu entries') ?></h2><div class="navigation-records">
<?php foreach($rows as $row):$parent=$byId[(int)$row['parent_id']]??null; ?>
<article class="navigation-record"><div><?= $row['icon']?icon($row['icon']):icon('menu') ?><strong><?= e($row['label']) ?></strong><span class="pill soft"><?= t($row['active']?'active':'disabled') ?></span></div><p><?php if($parent): ?><?= e($parent['label']) ?> / <?php endif ?><?= t($row['route']) ?> · <?= t($row['visibility']) ?> · <?= t('Sort order') ?> <?= (int)$row['sort_order'] ?></p><a class="text-link" href="<?= e(au('navigation',['edit'=>$row['id']])) ?>"><?= t('Edit') ?><?= icon('arrow') ?></a></article>
<?php endforeach;if(!$rows){empty_state();} ?></div><p class="hint"><?= t('Disable a parent to hide its whole branch. Records remain available for editing.') ?></p></section>
<section class="panel"><h2><?= t($edit?'Edit menu entry':'New menu entry') ?></h2>
<?= form_start('admin_entity','','navigation-editor',false).hidden('entity','navigation').hidden('id',$v['id']??0).hidden('revision',$v['revision']??0).hidden('request_key',Security::token()) ?>
<?php field('label','Menu label',$v['label']??'','text',['required'=>'required','maxlength'=>'100']);select_field('parent_id','Parent menu',$parents,$v['parent_id']??0);select_field('route','Destination',options(\Chengyu\Services\Navigation::ROUTES),$v['route']??'home');field('url','External URL',$v['url']??'','url',['maxlength'=>'1000']);select_field('icon','Icon',[''=>tr('No icon')]+\Chengyu\Core\Icons::labels(),$v['icon']??'');field('description','Menu description',$v['description']??'','textarea',['maxlength'=>'160']);upload_field('image_id','Menu image',(int)($v['image_id']??0));select_field('panel_style','Submenu layout',['dropdown'=>tr('Compact dropdown'),'columns'=>tr('Two columns')],$v['panel_style']??'dropdown');select_field('visibility','Who can see this link',options(['public','login','vip','verified']),$v['visibility']??'public');field('sort_order','Sort order',$v['sort_order']??0,'number',['min'=>'0','max'=>'9999']);check_field('active','Visible',!isset($v['active']) || (bool)$v['active']);?>
<fieldset class="navigation-content-fields"><legend><?= t('Content in this menu') ?></legend>
<?php select_field('category_id','Linked category',$categoryOptions,$v['category_id']??0);select_field('preview_count','Preview items',[0=>tr('Do not show previews'),2=>'2',3=>'3',4=>'4',5=>'5',6=>'6',1=>'1'],$v['preview_count']??0);select_field('preview_sort','Preview order',options(\Chengyu\Services\Navigation::PREVIEW_SORTS),$v['preview_sort']??'latest'); ?>
<p class="hint"><?= t('For articles, forum and shop links. A category links to its own listing; previews include published items in that category only. Restricted categories follow visitor permissions.') ?></p>
<p class="hint"><?= t('Enable up to eight content menus, with at most six previews each. Leave previews off for a simple category link.') ?></p></fieldset>
<?php button('Save menu entry','full','check'); ?>
<p class="hint"><?= t('Images and descriptions appear inside submenus and on mobile. Changes apply on the next page load.') ?></p></form></section></div>
