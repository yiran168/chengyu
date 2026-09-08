<?php
declare(strict_types=1);
namespace Chengyu\Http;
use Chengyu\App;
use Chengyu\Core\{Input, Problem};
final class AdminController
{
    private App $app;
    public function __construct(App $app) { $this->app=$app; }
    public function show(): void
    {
        \Chengyu\Core\Locale::context('admin');$a=$this->app;
        if (!$a->auth->user()) { render('auth',['title'=>tr('Sign in to your dashboard'),'route'=>'login','adminLogin'=>true]); return; }
        $a->auth->requirePermission('admin');
        $tab=Input::text($_GET['tab']??'dashboard',40); $navigation=admin_nav();
        $aliases=['edit_content'=>'contents','edit_user'=>'users']; $base=$aliases[$tab]??$tab;
        if (!isset($navigation[$base])) {throw new Problem('Page not found.',404);}
        if ($base==='dashboard' && !$a->auth->can('manage')) {redirect($a->adminUrl($a->auth->can('content')?'contents':'moderation'));}
        $a->auth->requirePermission($navigation[$base][2]);
        $data=['title'=>tr($navigation[$base][0]),'tab'=>$tab,'baseTab'=>$base];
        if (in_array($tab,['resources','backups','search_index','learning','logistics','reconciliation','support','media_lab','translations','commerce','shipping','service','motion','threads','circles','bounties','reviews','creators','pricing','integrations','configuration','badges'],true)) { render('admin_'.$tab,$data,true);return; }
        if ($tab==='edit_content') {
            $item=!empty($_GET['id'])?$a->db->one('SELECT * FROM cy_contents WHERE id=?',[Input::integer($_GET['id'],1)]):null;
            if (!empty($_GET['id']) && !$item) {throw new Problem('Content not found.',404);}
            render('editor',array_merge($data,['title'=>tr($item?'Edit content':'Create content'),'item'=>$item,'staff'=>true,'isAdmin'=>true]),true);return;
        }
        if ($tab==='edit_user') {
            $person=$a->db->one('SELECT * FROM cy_users WHERE id=?',[Input::integer($_GET['id']??0,1)]);
            if (!$person) {throw new Problem('Account not found.',404);}
            render('admin_user',array_merge($data,['person'=>$person]),true);return;
        }
        if (in_array($tab,['collections','links','announcements','tasks'],true)) {
            $data['rows']=$a->db->all('SELECT * FROM cy_'.$tab.' ORDER BY sort_order,id DESC LIMIT 300');
            $data['edit']=!empty($_GET['edit'])?$a->db->one('SELECT * FROM cy_'.$tab.' WHERE id=?',[Input::integer($_GET['edit'],1)]):null;
            render('admin_catalog',$data,true);return;
        }
        if(in_array($tab,['variants','builder','invitations','downloads'],true)){render('admin_'.$tab,$data,true);return;}
        if (in_array($tab,['categories','plans','coupons','navigation'],true)) {
            $data['rows']=$a->db->all('SELECT * FROM cy_'.$tab.' ORDER BY id DESC LIMIT 500');
            $data['edit']=!empty($_GET['edit'])?$a->db->one('SELECT * FROM cy_'.$tab.' WHERE id=?',[Input::integer($_GET['edit'],1)]):null;
            render('admin_entities',$data,true);return;
        }
        if ($tab==='settings') {
            $group=Input::text($_GET['group']??'site',40);$groups=array_unique(array_column($a->settings->schema(),0));
            if (!in_array($group,$groups,true)) {throw new Problem('Unknown settings section.');}
            render('admin_settings',array_merge($data,['group'=>$group,'groups'=>$groups]),true);return;
        }
        if (in_array($tab,['dashboard','vouchers','stock','system','moderation'],true)) {render('admin_'.$tab,$data,true);return;}
        $page=max(1,min(100000,(int)($_GET['page']??1)));$q=Input::text($_GET['q']??'',100);$status=Input::text($_GET['status']??'',40);$where='1=1';$params=[];
        $tables=['contents'=>'cy_contents','users'=>'cy_users','orders'=>'cy_orders','ledger'=>'cy_ledger','media'=>'cy_media','logs'=>'cy_audit','withdrawals'=>'cy_withdrawals'];
        $searchFields=['contents'=>['title','tags'],'users'=>['username','display_name','email'],'orders'=>['order_no','title'],'ledger'=>['reason'],'media'=>['name'],'logs'=>['action','target'],'withdrawals'=>['note']];
        if ($q!=='') {$clauses=[];foreach ($searchFields[$tab] as $field) {$clauses[]=$field." LIKE ? ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';}$where.=' AND ('.implode(' OR ',$clauses).')';}
        if ($status!=='' && in_array($tab,['contents','users','orders','withdrawals'],true)) {$where.=' AND status=?';$params[]=$status;}
        if($tab==='orders'&&!empty($_GET['sale_id'])){$where.=' AND sale_id=?';$params[]=Input::integer($_GET['sale_id'],1);}
        if ($tab==='contents' && !empty($_GET['kind'])) {$where.=' AND kind=?';$params[]=Input::choice($_GET['kind'],['article','thread','product','page']);}
        $total=(int)$a->db->value('SELECT COUNT(*) FROM '.$tables[$tab].' WHERE '.$where,$params);
        $rows=$a->db->all('SELECT * FROM '.$tables[$tab].' WHERE '.$where.' ORDER BY id DESC LIMIT 30 OFFSET ?',array_merge($params,[($page-1)*30]));
        render('admin_list',array_merge($data,['rows'=>$rows,'q'=>$q,'status'=>$status,'pagination'=>['page'=>$page,'pages'=>max(1,(int)ceil($total/30))],'total'=>$total]),true);
    }
}
