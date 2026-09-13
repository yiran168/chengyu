<?php $mobile=$mobile??false;$depth=$depth??0;foreach($nodes as $node):$children=$node['children']??[];$active=($route??'')===$node['route']; ?>
<?php if($children): ?><details class="site-nav-branch <?= $mobile?'nav-mobile':'' ?> <?= $node['panel_style']==='columns'?'nav-columns':'' ?>" data-nav-id="<?= (int)$node['id'] ?>">
<summary><?php partial('navigation_label',['node'=>$node,'showImage'=>$depth>0 || $mobile]); ?><?= icon('chevron') ?></summary><div class="site-nav-panel">
<a class="nav-parent-link" href="<?= e($node['href']) ?>"<?= $node['route']==='external'?' target="_blank" rel="noopener noreferrer"':'' ?>><?= t('Open section') ?><?= icon('arrow') ?></a>
<div class="nav-children"><?php partial('navigation_nodes',['route'=>$route??'','nodes'=>$children,'depth'=>$depth+1,'mobile'=>$mobile]); ?></div></div></details>
<?php else: ?><a class="site-nav-link <?= $active?'active':'' ?>" href="<?= e($node['href']) ?>"<?= $active?' aria-current="page"':'' ?><?= $node['route']==='external'?' target="_blank" rel="noopener noreferrer"':'' ?> data-nav-id="<?= (int)$node['id'] ?>"><?php partial('navigation_label',['node'=>$node,'showImage'=>$depth>0 || $mobile]); ?></a><?php endif ?>
<?php endforeach ?>
