<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Input, Problem};
final class Content
{
    private Database $db; private Settings $settings; private Activity $activity; private ?Inventory $inventory;
    public function __construct(Database $db, Settings $settings, Activity $activity, ?Inventory $inventory=null)
    { $this->db = $db; $this->settings = $settings; $this->activity = $activity; $this->inventory=$inventory; }
    private ?RemoteSearch $remote=null;
    public function remoteSearch(RemoteSearch $remote): void { $this->remote=$remote; }
    public function categoryAllowed(int $category, ?array $user): bool
    {
        return (new Categories($this->db,$this->settings,$this->activity))->allowed($category,$user);
    }
    public function get(int $id, ?array $user = null): array
    {
        $item = $this->db->one('SELECT c.*,(SELECT MIN(v.price_amount) FROM cy_variants v WHERE v.content_id=c.id AND v.active=1 AND v.inventory<>0) AS variant_min_price,(SELECT COUNT(*) FROM cy_variants v WHERE v.content_id=c.id) AS variant_count,u.display_name,u.username,u.avatar_id,u.verified,cat.name AS category_name FROM cy_contents c JOIN cy_users u ON u.id=c.author_id LEFT JOIN cy_categories cat ON cat.id=c.category_id WHERE c.id=?', [$id]);
        if (!$item) { throw new Problem('Content not found.', 404); }
        $module = ['article' => 'articles', 'thread' => 'forum', 'product' => 'shop'][$item['kind']] ?? '';
        if ($module) { $this->settings->requireModule($module); }
        $owner = $user && ((int)$user['id'] === (int)$item['author_id'] || in_array($user['role'], ['admin', 'editor'], true));
        if (!$owner && ($item['status'] !== 'published' || (int)$item['publish_at'] > time())) { throw new Problem('Content not found.', 404); }
        if (!$this->categoryAllowed((int)$item['category_id'], $user)) { throw new Problem('This category requires sign-in or membership.', 403); }
        return Translations::apply($this->db,[$item],\Chengyu\Core\Locale::current())[0];
    }
    public function access(array $item, ?array $user): bool
    {
        if (!$this->categoryAllowed((int)$item['category_id'],$user)) { return false; }
        if ($user && ((int)$user['id'] === (int)$item['author_id'] || in_array($user['role'], ['admin', 'editor'], true))) { return true; }
        if ($item['access_level'] === 'public' && $item['kind'] !== 'product') { return true; }
        if ($item['access_level'] === 'password' && !empty($item['access_password']) && hash_equals(hash('sha256',$item['access_password']), (string)($_SESSION['content_unlock'][(int)$item['id']]??''))) { return true; }
        if (!$user) { return false; }
        if (($item['kind']==='product' || $item['access_level']==='paid') && $this->db->one("SELECT id FROM cy_orders WHERE user_id=? AND item_id=? AND kind='content' AND status IN ('paid','refund_requested') AND (access_until=0 OR access_until>?) LIMIT 1", [(int)$user['id'], (int)$item['id'],time()])) { return true; }
        if (!empty($user['is_guest'])) { return false; }
        if ((int)$item['vip_free'] && (new Membership($this->db))->tier($user) >= (int)$item['min_vip_tier']) { return true; }
        if ($item['access_level'] === 'login') { return true; }
        if ($item['access_level'] === 'vip') { return (new Membership($this->db))->tier($user) >= (int)$item['min_vip_tier']; }
        if ($item['access_level'] === 'reply') { return (bool)$this->db->one('SELECT id FROM cy_comments WHERE user_id=? AND content_id=? AND status=?', [(int)$user['id'], (int)$item['id'], 'approved']); }
        return false;
    }
    public function feed(string $kind, ?array $user, array $filters = []): array
    {
        Input::choice($kind, ['article', 'thread', 'product', 'all']);
        $where = ['c.status=?', 'c.publish_at<=?']; $params = ['published', time()];
        if ($kind !== 'all') { if (!$this->settings->enabled(['article'=>'articles','thread'=>'forum','product'=>'shop'][$kind])) { return ['items'=>[], 'total'=>0, 'page'=>1, 'pages'=>1]; } $where[] = 'c.kind=?'; $params[] = $kind; }
        else {
            $kinds = [];
            foreach (['article' => 'articles', 'thread' => 'forum', 'product' => 'shop'] as $k => $module) { if ($this->settings->enabled($module)) { $kinds[] = $k; } }
            if (!$kinds) { return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1]; }
            $where[] = 'c.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')'; $params = array_merge($params, $kinds);
        }
        if (!$user || !in_array($user['role'], ['admin', 'editor'], true)) {
            $ids=(new Categories($this->db,$this->settings,$this->activity))->visibleIds($user);
            $where[]='c.category_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
            $params=array_merge($params,$ids);
        }
        if (!empty($filters['category'])) { $where[] = 'c.category_id=?'; $params[] = Input::integer($filters['category']); }
        if (!empty($filters['collection'])) { $where[] = 'EXISTS(SELECT 1 FROM cy_collection_items ci WHERE ci.collection_id=? AND ci.content_id=c.id)'; $params[] = Input::integer($filters['collection'],1); }
        if (!empty($filters['author'])) { $where[] = 'c.author_id=?'; $params[] = Input::integer($filters['author']); }
        if (!empty($filters['courses'])) {$where[]="EXISTS(SELECT 1 FROM cy_courses lc WHERE lc.content_id=c.id AND lc.active=1)";}
        if (!empty($filters['featured'])) { $where[] = 'c.featured=1'; }
        CatalogFilters::apply($filters,$where,$params,$this->db->driver());
        if(isset($filters['archive_period'])){
            $range=Archives::interval(Input::text($filters['archive_period'],40));
            if($range){$where[]='c.created_at>=? AND c.created_at<?';array_push($params,$range[0],$range[1]);}
        }
        $searchTerms=[];$sortParams=[];$indexed=null;$searchBackend='local';
        $phrase=Input::text($filters['q']??'',100);
        if ($phrase!=='') {
            $searchTerms=preg_split('/\s+/u',trim($phrase),7,PREG_SPLIT_NO_EMPTY)?:[];
            if(count($searchTerms)>6){throw new Problem('Use at most six search terms.');}
            $engine=new SearchIndex($this->db,$this->settings);$localClauses=[];$localParams=[];
            if($engine->ready()){$indexed=$engine->query($phrase,\Chengyu\Core\Locale::current());$localClauses[]=$indexed['where'];$localParams=$indexed['params'];}
            else {
                foreach($searchTerms as $term){$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';
                    $localClauses[]="(c.title LIKE ? ESCAPE '!' OR c.excerpt LIKE ? ESCAPE '!' OR c.tags LIKE ? ESCAPE '!' OR EXISTS(SELECT 1 FROM cy_translations tt WHERE tt.content_id=c.id AND tt.locale=? AND tt.status='published' AND tt.source_version=c.edit_version AND (tt.title LIKE ? ESCAPE '!' OR tt.excerpt LIKE ? ESCAPE '!')))";
                    array_push($localParams,$like,$like,$like,\Chengyu\Core\Locale::current(),$like,$like);
                }
            }
            $searchWhere=$localClauses?'('.implode(' AND ',$localClauses).')':'1=0';
            if($this->remote && $this->settings->get('search_engine')==='meilisearch'){
                try{
                    $remote=$this->remote->query($phrase,\Chengyu\Core\Locale::current());
                    $searchWhere='('.$searchWhere.' OR '.$remote['where'].')';$localParams=array_merge($localParams,$remote['params']);
                    $indexed=$remote;$searchBackend='meilisearch+local';
                }catch(\Throwable $e){$searchBackend='local-fallback';}
            }
            $where[]=$searchWhere;$params=array_merge($params,$localParams);
        }
        if (!empty($filters['favorites']) && $user) {
            $where[] = "EXISTS(SELECT 1 FROM cy_reactions rr WHERE rr.content_id=c.id AND rr.user_id=? AND rr.kind='favorite')"; $params[] = (int)$user['id'];
        }
        if (!empty($filters['following'])) {
            if (!$user || !$this->settings->enabled('follows')) {throw new Problem('Access denied.',403);}
            $where[]='EXISTS(SELECT 1 FROM cy_follows ff WHERE ff.follower_id=? AND ff.followed_id=c.author_id)'; $params[]=(int)$user['id'];
        }
        if (!empty($filters['history'])) {
            if (!$user || !$this->settings->enabled('reading_history')) {throw new Problem('Access denied.',403);}
            $where[]='EXISTS(SELECT 1 FROM cy_reading_history hh WHERE hh.user_id=? AND hh.content_id=c.id)';$params[]=(int)$user['id'];
        }
        $from = ' FROM cy_contents c JOIN cy_users u ON u.id=c.author_id LEFT JOIN cy_categories cat ON cat.id=c.category_id WHERE ' . implode(' AND ', $where);
        $total = (int)$this->db->value('SELECT COUNT(*)' . $from, $params);
        $size = min(30, max(1, (int)($filters['size'] ?? $this->settings->get('page_size'))));
        $page = max(1, min(100000, (int)($filters['page'] ?? 1))); $offset = ($page - 1) * $size;
        $sort = ['latest' => 'c.pinned DESC,c.created_at DESC,c.id DESC', 'popular' => 'c.pinned DESC,c.view_count DESC,c.id DESC', 'price' => CatalogFilters::PRICE.' ASC,c.id DESC', 'price_desc'=>CatalogFilters::PRICE.' DESC,c.id DESC','sales'=>'c.sold_count DESC,c.id DESC','updated'=>'c.updated_at DESC,c.id DESC'][$filters['sort'] ?? 'latest'] ?? 'c.created_at DESC,c.id DESC';
        if($searchTerms && ($filters['sort']??'relevance')==='relevance'){$weight=[];foreach($searchTerms as $term){$escaped=str_replace(['!','%','_'],['!!','!%','!_'],$term);$weight[]="(CASE WHEN c.title=? THEN 100 WHEN c.title LIKE ? ESCAPE '!' THEN 40 WHEN c.title LIKE ? ESCAPE '!' THEN 20 WHEN c.tags LIKE ? ESCAPE '!' THEN 10 ELSE 1 END)";array_push($sortParams,$term,$escaped.'%','%'.$escaped.'%','%'.$escaped.'%');}$sort='('.implode('+',$weight).') DESC,c.pinned DESC,c.id DESC';}
        if($indexed && ($filters['sort']??'relevance')==='relevance'){$sort=$indexed['sort'];$sortParams=$indexed['sort_params'];}
        if (!empty($filters['collection'])) { $sortParams=[]; $sort='(SELECT ci.sort_order FROM cy_collection_items ci WHERE ci.collection_id='.Input::integer($filters['collection'],1).' AND ci.content_id=c.id) ASC,c.id DESC'; }
        $fields = '(SELECT MIN(v.price_amount) FROM cy_variants v WHERE v.content_id=c.id AND v.active=1 AND v.inventory<>0) AS variant_min_price,(SELECT COUNT(*) FROM cy_variants v WHERE v.content_id=c.id) AS variant_count,c.id,c.kind,c.title,c.excerpt,c.category_id,c.author_id,c.cover_id,c.cover_art,c.tags,c.price_amount,c.price_currency,c.access_level,c.pinned,c.featured,c.view_count,c.sold_count,c.created_at,c.updated_at,c.edit_version,c.publish_at,c.status,u.display_name,u.username,u.avatar_id,cat.name AS category_name';
        if (!empty($filters['history']) && $user) {
            $uid=(int)$user['id'];
            $fields.=',(SELECT hh.progress FROM cy_reading_history hh WHERE hh.user_id='.$uid.' AND hh.content_id=c.id) AS reading_progress';
            $sortParams=[];$sort='(SELECT hh.updated_at FROM cy_reading_history hh WHERE hh.user_id='.$uid.' AND hh.content_id=c.id) DESC,c.id DESC';
        }
        if(!empty($filters['archive_order'])){$sort='c.created_at DESC,c.id DESC';$sortParams=[];}
        $items = $this->db->all('SELECT ' . $fields . $from . ' ORDER BY ' . $sort . ' LIMIT ? OFFSET ?', array_merge($params,$sortParams,[$size,$offset]));
        $items=Translations::apply($this->db,$items,\Chengyu\Core\Locale::current());
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $size)), 'search_backend'=>$searchBackend];
    }
    public function save(array $user, array $input, bool $staff): int
    {
        $id = Input::integer($input['id'] ?? 0); $kind = Input::choice($input['kind'] ?? 'article', ['article', 'thread', 'product', 'page']);
        if (!$staff) {
            $this->settings->requireModule('submissions'); Input::choice($kind, ['article', 'thread']);
            $this->settings->requireModule($kind === 'thread' ? 'forum' : 'articles');
            $this->activity->rate('submit', (string)$user['id'], (int)$this->settings->get('post_limit'), 3600);
        }
        $old = $id ? $this->db->one('SELECT * FROM cy_contents WHERE id=?', [$id]) : null;
        if ($id && (!$old || (!$staff && (int)$old['author_id'] !== (int)$user['id']))) { throw new Problem('Access denied.', 403); }
        if ($old && $old['kind'] !== $kind) { throw new Problem('Content type cannot be changed after creation.'); }
        $title = Input::required($input['title'] ?? '', 255); $category = Input::integer($input['category_id'] ?? 0);
        if ($category && !$this->db->one('SELECT id FROM cy_categories WHERE id=? AND kind=?', [$category, $kind])) { throw new Problem('Choose a matching category.'); }
        if (!$this->categoryAllowed($category, $user) || (!$staff && !(new Categories($this->db,$this->settings,$this->activity))->allowed($category,$user,'post'))) { throw new Problem('Access denied.', 403); }
        $freshAuthor=$this->db->one('SELECT * FROM cy_users WHERE id=?',[(int)$user['id']]);
        $creator=!$staff && $freshAuthor && $freshAuthor['status']==='active' && (int)$freshAuthor['creator_enabled'] && $this->settings->enabled('creator_income') && (!$old || (int)$old['creator_pricing']);
        $access = Input::choice(!$staff && !$creator && $old && !in_array($old['access_level'],['public','login','reply'],true)?'public':($input['access_level'] ?? 'public'), $staff ? ['public', 'login', 'vip', 'paid', 'reply', 'password'] : ($creator?['public','login','reply','paid']:['public', 'login', 'reply']));
        // An author can revise a submission, not silently remove staff-set purchase gates.
        if (!$staff && !$creator && $old && !in_array($old['access_level'], ['public','login','reply'], true)) { $access=$old['access_level']; }
        if ($kind === 'product') { $access = 'paid'; }
        $currency = Input::choice($input['price_currency'] ?? 'balance', ['balance', 'points', 'tokens']);
        if ($creator) {$currency='balance';}
        $price = ($staff || $creator) ? ($currency === 'balance' ? Input::cents($input['price'] ?? '0', true) : Input::integer($input['price'] ?? 0, 0, 100000000)) : 0;
        $cover = Input::integer($input['cover_id'] ?? 0); $resource = Input::integer($input['resource_id'] ?? 0);
        foreach ([$cover, $resource] as $mediaId) {
            if (!$mediaId) { continue; }
            $media = $this->db->one('SELECT * FROM cy_media WHERE id=?', [$mediaId]);
            if (!$media || (!$staff && (int)$media['owner_id'] !== (int)$user['id'])) { throw new Problem('Invalid media attachment.'); }
            if ($mediaId === $cover && ((int)$media['is_private'] || strpos($media['mime'], 'image/') !== 0)) { throw new Problem('Cover must be a public image.'); }
            if ($mediaId === $resource && $access !== 'public' && !(int)$media['is_private']) { throw new Problem('Restricted resources must be uploaded as private files.'); }
        }
        $slug = strtolower(Input::text($input['slug'] ?? '', 100));
        if ($slug === '') { $slug = $old['slug'] ?? ('entry-' . bin2hex(random_bytes(8))); }
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,99}$/D', $slug)) { throw new Problem('Slug must use lowercase letters, numbers and hyphens.'); }
        if ($this->db->one('SELECT id FROM cy_contents WHERE slug=? AND id<>?', [$slug, $id])) { throw new Problem('This slug is already in use.'); }
        $status = $staff ? Input::choice($input['status'] ?? 'draft', ['draft', 'pending', 'published', 'archived']) : (($input['status'] ?? '') === 'draft' ? 'draft' : 'pending');
        $productType = $staff ? Input::choice($input['product_type'] ?? 'digital', ['digital', 'code', 'physical', 'invitation']) : 'digital';
        if ($old && (int)$old['sold_count'] > 0 && $old['product_type'] !== $productType) { throw new Problem('Delivery type cannot be changed after sales.'); }
        $publishAt = $staff && !empty($input['publish_at']) ? strtotime(Input::text($input['publish_at'], 40)) : 0;
        if ($publishAt === false) { throw new Problem('Invalid date.'); }
        $passwordHash=$old['access_password']??'';
        if ($staff && !empty($input['content_password'])) {
            $plain=Input::passwordValue($input['content_password']);
            if (strlen($plain)<6 || strlen($plain)>72 || strpos($plain, "\0")!==false) { throw new Problem('Content password must be 6-72 bytes.'); }
            $passwordHash=password_hash($plain,PASSWORD_DEFAULT);
        }
        if ($access==='password' && !$passwordHash) { throw new Problem('Set a content password first.'); }
        $data = ['access_days'=>$staff?Input::integer($input['access_days']??0,0,3650):0,
            'min_vip_tier'=>$staff?Input::integer($input['min_vip_tier']??1,1,3):1,
            'access_password'=>$passwordHash, 'kind' => $kind, 'title' => $title, 'slug' => $slug, 'excerpt' => Input::text($input['excerpt'] ?? '', 1000),
            'body' => Input::required($input['body'] ?? '', 50000), 'protected_body' => Input::text($input['protected_body'] ?? '', 50000),
            'status' => $status, 'category_id' => $category, 'cover_id' => $cover, 'cover_art'=>Input::choice($input['cover_art']??($old['cover_art']??''),array_merge([''],array_keys(VisualAssets::ART))), 'resource_id' => $resource,
            'tags' => Input::text($input['tags'] ?? '', 255), 'access_level' => $access, 'price_currency' => $currency, 'price_amount' => $price,
            'pinned' => $staff && !empty($input['pinned']) ? 1 : 0, 'featured' => $staff && !empty($input['featured']) ? 1 : 0,
            'product_type' => $productType, 'inventory' => $staff ? Input::integer($input['inventory'] ?? -1, -1, 1000000) : -1,
            'vip_free' => $staff && !empty($input['vip_free']) ? 1 : 0, 'comment_enabled' => !empty($input['comment_enabled']) ? 1 : 0,
            'delivery_text' => $staff ? Input::text($input['delivery_text'] ?? '', 10000) : '', 'updated_at' => time(), 'publish_at' => $publishAt];
        $data['creator_pricing']=$staff?(!empty($input['creator_pricing'])?1:0):($creator?1:0);
        $data['invite_uses']=$staff?Input::integer($input['invite_uses']??$old['invite_uses']??1,1,1000):1;
        $data['invite_days']=$staff?Input::integer($input['invite_days']??$old['invite_days']??30,1,3650):30;
        return $this->db->transaction(function () use ($data, $id, $user, $old, $input, $staff, $creator): int {
            if (!$staff) {
                $current=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[(int)$user['id']]);
                if (!$current || $current['status']!=='active') {throw new Problem('Account unavailable.',403);}
                if($creator && (!(int)$current['creator_enabled'] || !$this->settings->enabled('creator_income'))) {throw new Problem('Author publishing approval changed. Reload before saving.',409);}
            }
            $saved = $id;
            if ($id) {
                $locked=$this->db->one('SELECT * FROM cy_contents WHERE id=?'.$this->db->lock(),[$id]);
                if (!$locked || (!$staff && (int)$locked['author_id']!==(int)$user['id'])) { throw new Problem('Content not found.',404); }
                if (!$staff) {
                    if($creator && !(int)$locked['creator_pricing']) {throw new Problem('Author pricing permission changed. Reload before saving.',409);}
                    foreach (['creator_pricing','access_days','min_vip_tier','access_password','price_currency','price_amount','pinned','featured','product_type','inventory','vip_free','delivery_text','publish_at','resource_id','invite_uses','invite_days'] as $managed) { if($creator && in_array($managed,['price_amount','price_currency'],true)){continue;} $data[$managed]=$locked[$managed]; }
                    if (!$creator && !in_array($locked['access_level'],['public','login','reply'],true)) { $data['access_level']=$locked['access_level']; }
                }
                if ($data['price_currency']!==$locked['price_currency'] && $this->db->one('SELECT id FROM cy_variants WHERE content_id=? LIMIT 1',[$id])) { throw new Problem('Products with variants keep their pricing asset.'); }
                if (isset($input['edit_version']) && Input::integer($input['edit_version'],1)!==(int)$locked['edit_version']) { throw new Problem('This content changed in another window. Reload before saving.',409); }
                if ($data['product_type']!==$locked['product_type'] && ($this->db->one('SELECT id FROM cy_stock_codes WHERE content_id=?',[$id]) || $this->db->one('SELECT id FROM cy_variants WHERE content_id=?',[$id]) || (int)$locked['sold_count']>0)) { throw new Problem('Delivery type cannot change while inventory or sales exist.'); }
                $this->db->insert('cy_revisions',['content_id'=>$id,'actor_id'=>(int)$user['id'],'snapshot'=>json_encode($locked,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>time()]);
                $revisions=$this->db->all('SELECT id FROM cy_revisions WHERE content_id=? ORDER BY id DESC',[$id]);
                foreach (array_slice($revisions,20) as $revision) { $this->db->execute('DELETE FROM cy_revisions WHERE id=?',[(int)$revision['id']]); }
                $data['edit_version']=(int)$locked['edit_version']+1;
                $this->db->update('cy_contents',$id,$data);
            }
            else { $saved = $this->db->insert('cy_contents', array_merge($data, ['author_id' => (int)$user['id'], 'created_at' => time()])); }
            if ($data['product_type']==='code' || $this->db->one('SELECT id FROM cy_variants WHERE content_id=?',[$saved])) {
                // Keep inventory calculations in Inventory, not in editor form fields.
                if ($this->inventory) { $this->inventory->synchronize($saved); }
            }
            if ($data['kind']==='thread' && (isset($input['thread_mode']) || !$old)) { (new Threads($this->db,$this->settings,$this->activity))->save($user,$saved,$input); }
            (new SearchIndex($this->db,$this->settings))->sync($saved);
            $this->activity->audit((int)$user['id'], 'content.saved', (string)$saved);
            if ($old && $old['status'] !== 'published' && $data['status'] === 'published') { $this->activity->notify((int)$old['author_id'], 'Your submission was published', $data['title'], 'article&id=' . $saved); }
            return $saved;
        });
    }
    public function comment(array $user, int $content, string $body, int $parent = 0): void
    {
        $this->settings->requireModule('comments');
        if (!$this->commentsVisible($user)) { throw new Problem('Your account cannot view comments here.',403); }
        $item = $this->get($content, $user);
        if ($item['kind']==='thread' && !(new Categories($this->db,$this->settings,$this->activity))->allowed((int)$item['category_id'],$user,'post')) { throw new Problem('Your account cannot post in this circle.',403); }
        if (!(int)$item['comment_enabled']) { throw new Problem('Comments are closed.'); }
        $access = $this->settings->get('comment_access');
        if (($access === 'vip' && (int)$user['vip_until'] <= time()) || ($access === 'verified' && !(int)$user['verified'])) { throw new Problem('Your account cannot comment here.', 403); }
        if ($item['access_level'] !== 'reply' && !$this->access($item, $user)) { throw new Problem('Unlock the content before commenting.', 403); }
        $this->activity->rate('comment', (string)$user['id'], 30, 3600);
        if ($parent && !$this->db->one('SELECT id FROM cy_comments WHERE id=? AND content_id=? AND status=?', [$parent, $content, 'approved'])) { throw new Problem('Invalid reply target.'); }
        $body = Input::required($body, 4000);
        $review = $this->settings->get('comments_review') && !in_array($user['role'], ['admin', 'editor', 'moderator'], true);
        $this->db->insert('cy_comments', ['content_id' => $content, 'user_id' => (int)$user['id'], 'parent_id' => $parent, 'body' => $body, 'status' => $review ? 'pending' : 'approved', 'created_at' => time()]);
    }
    public function reaction(array $user, int $content, string $kind): bool
    {
        Input::choice($kind, ['like', 'favorite']); $this->settings->requireModule($kind === 'like' ? 'likes' : 'favorites');
        $this->get($content, $user);
        return $this->db->transaction(function () use ($user, $content, $kind): bool {
            $this->db->one('SELECT id FROM cy_users WHERE id=?' . $this->db->lock(), [(int)$user['id']]);
            $old = $this->db->one('SELECT id FROM cy_reactions WHERE user_id=? AND content_id=? AND kind=?', [(int)$user['id'], $content, $kind]);
            if ($old) { $this->db->execute('DELETE FROM cy_reactions WHERE id=?', [(int)$old['id']]); return false; }
            $this->db->insert('cy_reactions', ['user_id' => (int)$user['id'], 'content_id' => $content, 'kind' => $kind, 'created_at' => time()]); return true;
        });
    }
    public function follow(int $user, int $target): void
    {
        $this->settings->requireModule('follows');
        if ($user === $target || !$this->db->one('SELECT id FROM cy_users WHERE id=? AND status=?', [$target, 'active'])) { throw new Problem('Invalid account.'); }
        $this->db->transaction(function () use ($user, $target): void {
            $this->db->one('SELECT id FROM cy_users WHERE id=?' . $this->db->lock(), [$user]);
            $old = $this->db->one('SELECT id FROM cy_follows WHERE follower_id=? AND followed_id=?', [$user, $target]);
            if ($old) { $this->db->execute('DELETE FROM cy_follows WHERE id=?', [(int)$old['id']]); }
            else { $this->db->insert('cy_follows', ['follower_id' => $user, 'followed_id' => $target, 'created_at' => time()]); }
        });
    }
    public function message(int $user, string $recipient, string $body): void
    {
        $this->settings->requireModule('messages');
        $target = $this->db->one('SELECT id FROM cy_users WHERE username=? AND status=?', [Input::required($recipient, 32), 'active']);
        if (!$target || (int)$target['id'] === $user) { throw new Problem('Invalid recipient.'); }
        $this->activity->rate('message', (string)$user, (int)$this->settings->get('message_limit'), 3600);
        $this->db->insert('cy_messages', ['sender_id' => $user, 'recipient_id' => (int)$target['id'], 'body' => Input::required($body, 4000), 'created_at' => time()]);
    }
    public function report(array $user, int $content, string $reason): void
    {
        $this->settings->requireModule('reports'); $this->get($content, $user);
        $this->activity->rate('report', (string)$user['id'], 10, 3600);
        if ($this->db->one('SELECT id FROM cy_reports WHERE user_id=? AND content_id=? AND status=?', [(int)$user['id'], $content, 'pending'])) { throw new Problem('Your report is already pending.'); }
        $this->db->insert('cy_reports', ['user_id' => (int)$user['id'], 'content_id' => $content, 'reason' => Input::required($reason, 1000), 'status' => 'pending', 'created_at' => time()]);
    }
    public function commentPage(int $content,?array $user,array $input=[]): array
    {
        $page=Input::integer($input['comment_page']??1,1,100000);
        $sort=Input::choice($input['comment_sort']??'latest',['latest','oldest','author']);
        $empty=['items'=>[],'total'=>0,'page'=>1,'pages'=>1,'sort'=>$sort];
        $item=$this->get($content,$user);
        if(!$this->settings->enabled('comments') || !(int)$item['comment_enabled'] || !$this->commentsVisible($user) || (!$this->access($item,$user) && $item['access_level']!=='reply')){return $empty;}
        $where="c.content_id=? AND c.status='approved'";$params=[$content];
        if($sort==='author'){$where.=' AND c.user_id=?';$params[]=(int)$item['author_id'];}
        $from=' FROM cy_comments c JOIN cy_users u ON u.id=c.user_id WHERE '.$where;
        $total=(int)$this->db->value('SELECT COUNT(*)'.$from,$params);$pages=max(1,(int)ceil($total/50));$page=min($page,$pages);
        $accepted=(int)$this->db->value('SELECT accepted_comment_id FROM cy_threads WHERE content_id=? AND mode=?',[$content,'question']);
        $order='CASE WHEN c.id=? THEN 0 ELSE 1 END,c.pinned DESC,c.id '.($sort==='oldest'?'ASC':'DESC');
        // Page and list share the exact order. Pinned answers appear once, not again on every page.
        $items=$this->db->all('SELECT c.*,u.display_name,u.avatar_id,u.username'.$from.' ORDER BY '.$order.' LIMIT 50 OFFSET ?',array_merge($params,[$accepted,($page-1)*50]));
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>$pages,'sort'=>$sort];
    }
    public function commentsVisible(?array $user): bool
    {
        $rule=(string)$this->settings->get('comment_visibility','public');
        return $rule==='public' || ($user && (in_array($user['role'],['admin','editor','moderator'],true) || $rule==='login' || ($rule==='vip' && (int)$user['vip_until']>time()) || ($rule==='verified' && (int)$user['verified'])));
    }
    public function unlock(int $content, string $password, ?array $user): void
    {
        $item=$this->get($content,$user);
        $this->activity->rate('content-password',\Chengyu\Core\Security::ip().':'.$content,10,900);
        if ($item['access_level']!=='password' || !$item['access_password'] || !password_verify($password,$item['access_password'])) { throw new Problem('Incorrect content password.',403); }
        if (count($_SESSION['content_unlock']??[])>=100) { unset($_SESSION['content_unlock'][array_key_first($_SESSION['content_unlock'])]); }
        $_SESSION['content_unlock'][$content]=hash('sha256',$item['access_password']);
    }
    public function restore(array $actor, int $content, int $revision): void
    {
        if (!in_array($actor['role'],['admin','editor'],true)) { throw new Problem('Access denied.',403); }
        $this->db->transaction(function() use($actor,$content,$revision): void {
            $current=$this->db->one('SELECT * FROM cy_contents WHERE id=?'.$this->db->lock(),[$content]);
            $row=$this->db->one('SELECT * FROM cy_revisions WHERE id=? AND content_id=?',[$revision,$content]);
            if (!$current || !$row) { throw new Problem('Revision not found.',404); }
            $snapshot=json_decode($row['snapshot'],true,32,JSON_THROW_ON_ERROR);
            foreach (['title','excerpt','body','protected_body','tags'] as $field) { $current[$field]=$snapshot[$field]; }
            $current['price']=$current['price_currency']==='balance'?Payment::decimal((int)$current['price_amount']):(int)$current['price_amount'];
            $current['publish_at']=$current['publish_at']?date('Y-m-d H:i:s',(int)$current['publish_at']):'';
            $this->save($actor,$current,true);$this->activity->audit((int)$actor['id'],'content.restored',$content.':'.$revision);
        });
    }
}
