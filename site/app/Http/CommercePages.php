<?php
declare(strict_types=1);
namespace Chengyu\Http;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
use Chengyu\Services\Checkout;
final class CommercePages
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    public function show(string $route): bool
    {
        if(!in_array($route,['checkout','checkout_review','receipt','guest_orders','guest_content','upgrade'],true)){return false;}
        $a=$this->a;header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
        $data=['route'=>$route,'title'=>tr(['checkout'=>'Secure checkout','checkout_review'=>'Review your order','receipt'=>'Order receipt','guest_orders'=>'Guest order desk','guest_content'=>'Your purchased content','upgrade'=>'Membership upgrade'][$route])];
        if($route==='checkout'){
            $a->settings->requireModule('checkout');$owner=$a->auth->user()??$a->guests->current();$lines=[];$items=[];$physical=false;
            if(!empty($_GET['cart'])){$a->auth->requireUser();foreach(\Chengyu\Services\Cart::lines() as $line){$line['kind']='content';$lines[]=$line;}}
            else{$lines[]=['kind'=>Input::choice($_GET['kind']??'content',['content','membership']),'item_id'=>Input::integer($_GET['item_id']??0,1),'variant_id'=>Input::integer($_GET['variant_id']??0),'quantity'=>Input::integer($_GET['quantity']??1,1,10)];}
            $lines=Checkout::lines($lines);foreach($lines as $line){
                if($line['kind']==='content'){$p=$a->content->get($line['item_id'],$owner);$physical=$physical||($p['kind']==='product'&&$p['product_type']==='physical');$items[]=$p;}
                else{$a->auth->requireUser();$p=$a->db->one('SELECT * FROM cy_plans WHERE id=? AND active=1',[$line['item_id']]);if(!$p){throw new Problem('Membership plan is unavailable.',404);}$items[]=$p;}
            }
            $data+=['lines'=>$lines,'items'=>$items,'physical'=>$physical,'owner'=>$owner];
        }elseif($route==='checkout_review'){
            $draft=$_SESSION['checkout_draft']??null;if(!is_array($draft)||$draft['expires_at']<time()){throw new Problem('The checkout draft expired. Review your items again.',409);}$data['draft']=$draft;
        }elseif($route==='receipt'){
            $id=Input::integer($_GET['id']??0,1);try{$owner=(new CommerceActions($a))->owner(0,$id);}catch(Problem $e){if($e->status===404&&!$a->auth->user()&&!$a->guests->current()){redirect($a->url('guest_orders'));}throw $e;}
            $data['owner']=$owner;$data['sale']=$a->checkout->owned($id,(int)$owner['id']);$data['orders']=$a->db->all('SELECT * FROM cy_orders WHERE sale_id=? ORDER BY id',[$id]);
        }elseif($route==='guest_orders'){
            $owner=$a->guests->current();$data['owner']=$owner;$data['sales']=$owner?$a->db->all('SELECT id,sale_no,amount,currency,status,created_at FROM cy_sales WHERE user_id=? ORDER BY id DESC LIMIT 100',[(int)$owner['id']]):[];
        }elseif($route==='guest_content'){
            $id=Input::integer($_GET['id']??0,1);$owner=(new CommerceActions($a))->owner($id);$o=$a->db->one('SELECT * FROM cy_orders WHERE id=?',[$id]);
            if($o['kind']!=='content'||!in_array($o['status'],['paid','refund_requested'],true)||((int)$o['access_until']>0&&(int)$o['access_until']<=time())){throw new Problem('This purchase does not grant active access.',403);}
            $item=$a->content->get((int)$o['item_id'],$owner);if(!$a->content->access($item,$owner)){throw new Problem('Content access is unavailable.',403);}$data+=['item'=>$item,'order'=>$o,'owner'=>$owner];
        }else{$a->auth->requireUser();$data['plan_id']=Input::integer($_GET['plan_id']??0,1);$data['quote']=$_SESSION['upgrade_quote']??null;if($data['quote']&&($data['quote']['plan']!==$data['plan_id']||$data['quote']['user']!==(int)$a->auth->user()['id'])){$data['quote']=null;}}
        render($route,$data);return true;
    }
}
