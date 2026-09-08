<?php
declare(strict_types=1);
namespace Chengyu\Http;
use Chengyu\App;
use Chengyu\Core\{Input, Problem};
final class SiteController
{
    private App $app;
    public function __construct(App $app) { $this->app = $app; }
    public function show(): void
    {
        \Chengyu\Core\Locale::context('site');$a = $this->app; $s = $a->settings; $me = $a->auth->user();
        $route = Input::text($_GET['r'] ?? 'home', 30); $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
        if ($s->get('maintenance') && (!$me || $me['role'] !== 'admin') && !in_array($route, ['login', 'forgot', 'reset'], true)) {
            http_response_code(503); render('error', ['title' => tr('A short pause'), 'message' => tr('The site is under maintenance.'), 'reference' => '', 'status' => 503]); return;
        }
        if((new PlatformPages($a))->show($route)){return;}
        if((new CommercePages($a))->show($route)){return;}
        if($route==='landing'){$slot=Input::choice($_GET['slot']??'page1',\Chengyu\Services\Layout::PAGES);render('landing',['title'=>tr('Custom page'),'route'=>'landing','slot'=>$slot]);return;}
        $protected = ['security','profile', 'wallet', 'orders', 'notifications', 'messages', 'favorites', 'write', 'cart', 'pay','tasks','creator','history','following'];
        if (in_array($route, $protected, true) && !$me) { flash(tr('Please sign in to continue.'), 'info'); redirect($a->url('login')); }
        $modules = ['articles' => 'articles', 'forum' => 'forum', 'shop' => 'shop', 'membership' => 'membership', 'wallet' => 'wallet', 'notifications' => null, 'messages' => 'messages', 'favorites' => 'favorites', 'search' => 'search', 'rankings' => 'rankings', 'cart' => 'cart','tasks'=>'tasks','collections'=>'collections','collection'=>'collections','links'=>'links','circles'=>'circles','circle'=>'circles','creator'=>'creator_income','history'=>'reading_history','following'=>'follows'];
        if (!empty($modules[$route])) { $s->requireModule($modules[$route]); }
        if (in_array($route,['circles','circle'],true)) {
            $s->requireModule('forum');
            if ($route==='circles') {render('circles',['title'=>tr('Find your circle'),'route'=>$route]);return;}
            $circle=$a->categories->get(Input::integer($_GET['id']??0,1));
            if (!(int)$circle['is_circle']) {throw new Problem('Circle not found.',404);}
            $allowed=$a->categories->allowed((int)$circle['id'],$me);
            $feed=$allowed?$a->content->feed('thread',$me,['category'=>(int)$circle['id'],'page'=>$page]):['items'=>[],'total'=>0,'page'=>1,'pages'=>1];
            render('circle',['title'=>$circle['name'],'route'=>$route,'circle'=>$circle,'allowed'=>$allowed,'feed'=>$feed]);return;
        }
        if($route==='connect') {if($me){redirect($a->url('security'));}render('connect',['title'=>tr('Finish connecting'),'route'=>$route,'connection'=>$a->oauth->pending()]);return;}
        if($route==='badges') {$s->requireModule('badges');render('badges',['title'=>tr('Community badges'),'route'=>$route]);return;}
        if($route==='security') {render('security',['title'=>tr('Security center'),'route'=>$route]);return;}
        if($route==='price_quote') {
            $a->activity->rate('price-quote',\Chengyu\Core\Security::ip(),120,60);
            $item=$a->content->get(Input::integer($_GET['id']??0,1),$me);
            if($item['kind']==='page' || ($item['kind']!=='product' && $item['access_level']!=='paid')) {throw new Problem('This content is not sold separately.',403);}
            $variant=Input::integer($_GET['variant_id']??0);$base=(int)$item['price_amount'];
            if($item['kind']==='product') {$selection=$a->inventory->selection($item,$variant);$base=$selection['price'];}
            elseif($variant) {throw new Problem('Invalid variant.');}
            $quote=$a->pricing->price($item,$me,$base);$coupon=Input::text($_GET['coupon']??'',64);
            if($coupon!=='') {if(!$me){throw new Problem('Please sign in to continue.',401);}$quote=$a->pricing->coupon($me,$item,'content',$quote,$coupon);}
            $quote['currency']=$item['price_currency'];$quote['formatted']=price($quote['amount'],$item['price_currency']);$quote['base_formatted']=price($quote['base_amount'],$item['price_currency']);
            $quote['label']=tr(['list'=>'List price','member'=>'Member price','promotion'=>'Limited-time price'][$quote['source']]);
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
            echo json_encode(['ok'=>true,'quote'=>$quote],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR);return;
        }
        if($route==='suggest') {
            $a->activity->rate('suggest',\Chengyu\Core\Security::ip(),120,60);
            $rows=$a->discovery->suggestions(Input::text($_GET['q']??'',100),$me);
            foreach($rows as &$row){$row['url']=$a->url($row['kind']==='product'?'product':'article',['id'=>$row['id']]);} unset($row);
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
            echo json_encode(['ok'=>true,'items'=>$rows],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR);return;
        }
        if($route==='creator') {
            render('creator',['route'=>$route,'title'=>tr('Creator studio'),'summary'=>$a->creator->summary((int)$me['id']),'income'=>$a->creator->page((int)$me['id'],$page)]);return;
        }
        if($route==='history') {
            render('history',['route'=>$route,'title'=>tr('Your reading journey'),'feed'=>$a->content->feed('all',$me,['history'=>true,'page'=>$page])]);return;
        }
        if ($route === 'home') {
            render('home', ['title' => (string)$s->get('site_name'), 'route' => $route]); return;
        }
        if (in_array($route, ['articles', 'forum', 'shop', 'search', 'favorites', 'following'], true)) {
            $kind = ['articles' => 'article', 'forum' => 'thread', 'shop' => 'product'][$route] ?? 'all';
            if($route==='search'){$kind=Input::choice($_GET['kind']??'all',['all','article','thread','product']);}
            $filters = ['page' => $page, 'category' => (int)($_GET['category'] ?? 0), 'q' => Input::text($_GET['q'] ?? '', 100), 'sort' => Input::text($_GET['sort'] ?? ($route==='search'?'relevance':'latest'), 30), 'following'=>$route==='following', 'favorites' => $route === 'favorites'];
            $filters['sort']=Input::choice($filters['sort'],\Chengyu\Services\CatalogFilters::SORTS);
            $filters=array_merge($filters,\Chengyu\Services\CatalogFilters::normalize($_GET));
            $titles = ['articles' => 'Stories worth your time', 'forum' => 'A conversation starts here', 'shop' => 'Good tools. Better possibilities.', 'search' => 'Find your inspiration', 'following'=>'From the people you follow', 'favorites' => 'Your saved collection'];
            $feed = $a->content->feed($kind, $me, $filters);
            render('listing', ['title' => tr($titles[$route]), 'route' => $route, 'kind' => $kind, 'feed' => $feed, 'filters' => $filters]); return;
        }
        if (in_array($route, ['article', 'product', 'page'], true)) {
            $item = $a->content->get(Input::integer($_GET['id'] ?? 0, 1), $me); $access = $a->content->access($item, $me);
            $key = 'view_' . $item['id'];
            if (empty($_SESSION[$key]) || (int)$_SESSION[$key] < time() - 3600) {
                $a->db->execute('UPDATE cy_contents SET view_count=view_count+1 WHERE id=?', [(int)$item['id']]); $_SESSION[$key] = time(); $item['view_count']++;
            }
            $thread=$a->threads->snapshot($item,$me,$access);
            $comments = $s->enabled('comments') && $a->content->commentsVisible($me) && ($access || $item['access_level'] === 'reply') ? $a->db->all('SELECT c.*,u.display_name,u.avatar_id,u.username FROM cy_comments c JOIN cy_users u ON u.id=c.user_id WHERE c.content_id=? AND c.status=? ORDER BY CASE WHEN c.id=? THEN 0 ELSE 1 END,c.pinned DESC,c.id DESC LIMIT 50', [(int)$item['id'], 'approved', (int)($thread['accepted_comment_id']??0)]) : [];
            render('article', ['thread'=>$thread, 'title' => $item['title'], 'route' => $route, 'item' => $item, 'access' => $access, 'comments' => $comments]); return;
        }
        if (in_array($route, ['login', 'register', 'forgot', 'reset'], true)) {
            if ($route === 'register') { $s->requireModule('registration'); }
            render('auth', ['title' => tr(['login' => 'Welcome back', 'register' => 'Make yourself at home', 'forgot' => 'Recover your account', 'reset' => 'Choose a new password'][$route]), 'route' => $route]); return;
        }
        if ($route === 'verify') {
            $s->requireModule('smtp'); render('verify',['title'=>tr('Verify your email'),'route'=>$route]); return;
        }
        if ($route === 'membership') {
            $plans = $a->db->all('SELECT * FROM cy_plans WHERE active=1 ORDER BY sort_order,id');
            render('membership', ['title' => tr('Make room for more inspiration'), 'route' => $route, 'plans' => $plans]); return;
        }
        if ($route === 'wallet') {
            $uid = (int)$me['id']; $total = (int)$a->db->value('SELECT COUNT(*) FROM cy_ledger WHERE user_id=?', [$uid]);
            $ledger = $a->db->all('SELECT * FROM cy_ledger WHERE user_id=? ORDER BY id DESC LIMIT 20 OFFSET ?', [$uid, ($page - 1) * 20]);
            $withdrawals = $a->db->all('SELECT id,amount,currency,status,note,created_at FROM cy_withdrawals WHERE user_id=? ORDER BY id DESC LIMIT 20', [$uid]);
            render('wallet', ['title' => tr('Your wallet'), 'route' => $route, 'ledger' => $ledger, 'withdrawals' => $withdrawals, 'pagination' => ['page' => $page, 'pages' => max(1, (int)ceil($total / 20))]]); return;
        }
        if ($route === 'orders') {
            $params = [(int)$me['id']]; $where = 'user_id=?';
            if (!empty($_GET['id'])) { $where .= ' AND id=?'; $params[] = Input::integer($_GET['id'], 1); }
            $total = (int)$a->db->value('SELECT COUNT(*) FROM cy_orders WHERE ' . $where, $params);
            $orders = $a->db->all('SELECT * FROM cy_orders WHERE ' . $where . ' ORDER BY id DESC LIMIT 20 OFFSET ?', array_merge($params, [($page - 1) * 20]));
            render('orders', ['title' => tr('Your orders'), 'route' => $route, 'orders' => $orders, 'pagination' => ['page' => $page, 'pages' => max(1, (int)ceil($total / 20))]]); return;
        }
        if ($route === 'pay') {
            $order = $a->db->one('SELECT * FROM cy_orders WHERE id=? AND user_id=?', [Input::integer($_GET['id'] ?? 0, 1), (int)$me['id']]);
            if (!$order) { throw new Problem('Order not found.', 404); }
            external_handoff($a->payment->checkout($order)); return;
        }
        if ($route === 'write') {
            if (!$a->auth->can('content')) { $s->requireModule('submissions'); }
            $item = !empty($_GET['id']) ? $a->db->one('SELECT * FROM cy_contents WHERE id=?', [Input::integer($_GET['id'], 1)]) : null;
            if ($item && (int)$item['author_id'] !== (int)$me['id'] && !$a->auth->can('content')) { throw new Problem('Access denied.', 403); }
            render('editor', ['title' => tr($item ? 'Edit your story' : 'Something worth sharing'), 'route' => $route, 'item' => $item, 'staff' => $a->auth->can('content'), 'isAdmin' => false]); return;
        }
        if ($route === 'profile') {
            render('profile', ['title' => tr('Your little corner'), 'route' => $route]); return;
        }
        if ($route === 'user') {
            $profile = $a->db->one('SELECT id,username,display_name,bio,avatar_id,verified,experience,vip_until,created_at FROM cy_users WHERE id=? AND status=?', [Input::integer($_GET['id'] ?? 0, 1), 'active']);
            if (!$profile) { throw new Problem('Account not found.', 404); }
            $feed = $a->content->feed('all', $me, ['author' => (int)$profile['id'], 'page' => $page]);
            render('user', ['title' => $profile['display_name'], 'route' => $route, 'profile' => $profile, 'feed' => $feed]); return;
        }
        if (in_array($route, ['notifications', 'messages', 'rankings', 'cart'], true)) {
            render($route, ['title' => tr(['notifications' => 'Your notifications', 'messages' => 'Your conversations', 'rankings' => 'People who make this place', 'cart' => 'Your collection bag'][$route]), 'route' => $route]); return;
        }
        if(in_array($route,['tasks','collections','links'],true)){render($route,['title'=>tr(['tasks'=>'Task center','collections'=>'Editorial collections','links'=>'Link directory'][$route]),'route'=>$route]);return;}
        if($route==='collection'){
            $collection=$a->db->one('SELECT * FROM cy_collections WHERE id=? AND active=1',[Input::integer($_GET['id']??0,1)]);
            if(!$collection){throw new Problem('Collection not found.',404);}
            $feed=$a->content->feed('all',$me,['collection'=>(int)$collection['id'],'page'=>$page]);
            render('collection',['title'=>$collection['name'],'route'=>$route,'collection'=>$collection,'feed'=>$feed]);return;
        }
        if ($route === 'terms') { render('terms', ['title' => tr('Site terms and privacy'), 'route' => $route]); return; }
        throw new Problem('Page not found.', 404);
    }
}
