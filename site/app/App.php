<?php
declare(strict_types=1);
namespace Chengyu;
use Chengyu\Core\{Database, Settings, Crypto, Security, Problem};
use Chengyu\Services\{Activity, Wallet, Auth, Commerce, Payment, Content, Media, Mailer, Admin};
final class App
{
    public const VERSION = '0.19.0';
    public array $config; public Database $db; public Crypto $crypto; public Settings $settings;
    public Activity $activity; public Wallet $wallet; public Auth $auth; public Commerce $commerce;
    public Payment $payment; public Content $content; public Media $media; public Mailer $mailer; public Admin $admin;
    public \Chengyu\Services\Inventory $inventory; public \Chengyu\Services\Membership $membership;
    public \Chengyu\Services\Tasks $tasks; public \Chengyu\Services\Invitations $invitations;
    public \Chengyu\Services\Catalog $catalog; public \Chengyu\Services\Layout $layout;
    public \Chengyu\Services\Verification $verification; public \Chengyu\Services\Threads $threads;
    public \Chengyu\Services\Categories $categories; public \Chengyu\Services\Bounties $bounties; public \Chengyu\Services\Reviews $reviews;
    public \Chengyu\Services\Creator $creator;
    public \Chengyu\Services\Discovery $discovery;
    public \Chengyu\Services\SecondFactor $secondFactor;
    public \Chengyu\Services\Pricing $pricing;
    public \Chengyu\Services\OAuth $oauth;
    public \Chengyu\Services\Configuration $configuration;
    public \Chengyu\Services\Badges $badges;
    public \Chengyu\Services\Rulebooks $rulebooks;
    public \Chengyu\Services\Guests $guests;
    public \Chengyu\Services\DirectPayments $directPayments;
    public \Chengyu\Services\Checkout $checkout;
    public \Chengyu\Services\Aftersales $aftersales;
    public \Chengyu\Services\Refunds $refunds;
    public \Chengyu\Services\Payouts $payouts;
    public \Chengyu\Services\Jobs $jobs;
    public \Chengyu\Services\Upgrades $upgrades;
    public \Chengyu\Services\Uploads $uploads;
    public \Chengyu\Services\ObjectStorage $objects;
    public \Chengyu\Services\Translations $translations;
    public \Chengyu\Services\SearchIndex $search;
    public \Chengyu\Services\Learning $learning;
    public \Chengyu\Services\Tracking $tracking;
    public \Chengyu\Services\Reconciliation $reconciliation;
    public \Chengyu\Services\SupportDesk $support;
    public \Chengyu\Services\RemoteSearch $remoteSearch;
    public \Chengyu\Services\Backups $backups;
    public \Chengyu\Services\ResourceLibrary $resources;
    public string $basePath;
    public function __construct(array $config)
    {
        $this->config = $config; $this->basePath = rtrim((string)(parse_url($config['url'], PHP_URL_PATH) ?? ''), '/');
        $this->db = new Database($config['database']); \Chengyu\Core\Migrations::upgrade($this->db, $config['storage']); $this->crypto = new Crypto($config['secret']);
        $this->settings = new Settings($this->db, $this->crypto); $this->activity = new Activity($this->db, $this->crypto);
        $this->inventory = new \Chengyu\Services\Inventory($this->db,$this->crypto,$this->activity); $this->membership = new \Chengyu\Services\Membership($this->db);
        $this->verification = new \Chengyu\Services\Verification($this->db,$this->settings,$this->crypto,$this->activity);
        $this->threads = new \Chengyu\Services\Threads($this->db,$this->settings,$this->activity);
        $this->wallet = new Wallet($this->db, $this->settings, $this->activity);
        $this->creator = new \Chengyu\Services\Creator($this->db,$this->settings,$this->wallet,$this->activity);
        $this->categories = new \Chengyu\Services\Categories($this->db,$this->settings,$this->activity);
        $this->bounties = new \Chengyu\Services\Bounties($this->db,$this->settings,$this->wallet,$this->activity);
        $this->reviews = new \Chengyu\Services\Reviews($this->db,$this->settings,$this->activity);
        $this->tasks = new \Chengyu\Services\Tasks($this->db,$this->settings,$this->wallet,$this->activity);
        $this->invitations = new \Chengyu\Services\Invitations($this->db,$this->crypto,$this->activity);
        $this->catalog = new \Chengyu\Services\Catalog($this->db,$this->activity);
        $this->layout = new \Chengyu\Services\Layout($this->db,$this->activity);
        $this->secondFactor = new \Chengyu\Services\SecondFactor($this->db,$this->settings,$this->crypto,$this->activity,$this->wallet);
        $this->pricing = new \Chengyu\Services\Pricing($this->db,$this->settings,$this->membership,$this->activity);
        $this->auth = new Auth($this->db, $this->settings, $this->crypto, $this->activity, $this->wallet, $this->secondFactor);
        $this->oauth = new \Chengyu\Services\OAuth($this->db,$this->settings,$this->crypto,$this->activity,$this->wallet,$this->auth,$this->secondFactor,$config['url']);
        $this->configuration = new \Chengyu\Services\Configuration($this->db,$this->settings,$this->crypto,$this->activity);
        $this->badges = new \Chengyu\Services\Badges($this->db,$this->settings,$this->activity);
        $this->commerce = new Commerce($this->db, $this->settings, $this->crypto, $this->wallet, $this->activity, $this->pricing);
        $this->payment = new Payment($this->db, $this->settings, $this->crypto, $this->wallet, $this->activity, $config['url']);
        $this->content = new Content($this->db, $this->settings, $this->activity, $this->inventory);
        $this->discovery = new \Chengyu\Services\Discovery($this->db,$this->settings,$this->content,$this->wallet);
        $this->media = new Media($this->db, $this->settings, $this->content, $config['storage']);
        $this->rulebooks=new \Chengyu\Services\Rulebooks($this->db,$this->settings,$this->activity);
        $this->guests=new \Chengyu\Services\Guests($this->db,$this->settings,$this->crypto);
        $this->directPayments=new \Chengyu\Services\DirectPayments($this->settings);
        $this->checkout=new \Chengyu\Services\Checkout($this);
        $this->aftersales=new \Chengyu\Services\Aftersales($this);
        $this->refunds=new \Chengyu\Services\Refunds($this);
        $this->payouts=new \Chengyu\Services\Payouts($this);
        $this->jobs=new \Chengyu\Services\Jobs($this);
        $this->upgrades=new \Chengyu\Services\Upgrades($this);
        $this->objects=new \Chengyu\Services\ObjectStorage($this);
        $this->translations=new \Chengyu\Services\Translations($this);
        $this->tracking=new \Chengyu\Services\Tracking($this);
        $this->reconciliation=new \Chengyu\Services\Reconciliation($this);
        $this->support=new \Chengyu\Services\SupportDesk($this);
        $this->learning=new \Chengyu\Services\Learning($this);
        $this->media->learning($this->learning);
        $this->resources=new \Chengyu\Services\ResourceLibrary($this);
        $this->media->resources($this->resources);
        $this->search=new \Chengyu\Services\SearchIndex($this->db,$this->settings);
        $this->backups=new \Chengyu\Services\Backups($this);
        $this->remoteSearch=new \Chengyu\Services\RemoteSearch($this->db,$this->settings,$this->activity);
        $this->content->remoteSearch($this->remoteSearch);
        $this->uploads=new \Chengyu\Services\Uploads($this);
        $this->mailer = new Mailer($this->settings); $this->admin = new Admin($this->db, $this->settings, $this->activity);
    }
    public function url(string $route = 'home', array $params = []): string
    {
        if ($route === 'home' && !$params) { return $this->basePath . '/'; }
        return $this->basePath . '/index.php?' . http_build_query(array_merge(['r' => $route], $params), '', '&', PHP_QUERY_RFC3986);
    }
    public function adminUrl(string $tab = 'dashboard', array $params = []): string
    {
        return $this->basePath . '/admin/index.php?' . http_build_query(array_merge(['tab' => $tab], $params), '', '&', PHP_QUERY_RFC3986);
    }
    public function localTarget(string $target): string
    {
        $parts = parse_url($target);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || strpos($target, '//') === 0 || preg_match('/[\x00-\x20\\\\]/', $target)) { return $this->url(); }
        $paths = [$this->basePath . '/', $this->basePath . '/index.php', $this->basePath . '/admin/', $this->basePath . '/admin/index.php'];
        return in_array($parts['path'] ?? '', $paths, true) ? $target : $this->url();
    }
    /** Real community members exclude temporary checkout identities. */
    public function memberCount(): int
    { return (int)$this->db->value("SELECT COUNT(*) FROM cy_users WHERE status='active' AND is_guest=0"); }
    /** Stable public canonical URL; never reflects passwords, guest passes or arbitrary queries. */
    public function canonical(string $route,array $query=[]): string
    {
        $allowed=['article'=>['id'],'product'=>['id'],'page'=>['id'],'user'=>['id'],'circle'=>['id'],'collection'=>['id'],'course'=>['id'],
            'landing'=>['slot'],'articles'=>['category','page'],'forum'=>['category','page'],'shop'=>['category','page'],'courses'=>['page']];
        $params=[];foreach($allowed[$route]??[] as $key){$value=$query[$key]??null;
            if($key==='slot'){if(is_string($value) && in_array($value,\Chengyu\Services\Layout::PAGES,true)){$params[$key]=$value;}}
            elseif(is_scalar($value) && preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value)){if($key!=='page' || (int)$value>1){$params[$key]=(int)$value;}}
        }
        return rtrim($this->config['url'],'/').substr($this->url($route,$params),strlen($this->basePath));
    }
    public function navigation(): array
    {
        $user = $this->auth->user(); $items = [];
        $modules = \Chengyu\Services\Navigation::MODULES;
        foreach ($this->db->all('SELECT * FROM cy_navigation WHERE active=1 ORDER BY sort_order,id') as $item) {
            if (isset($modules[$item['route']]) && !$this->settings->enabled($modules[$item['route']])) { continue; }
            if ($item['visibility'] !== 'public' && !$user) { continue; }
            if ($item['visibility'] === 'vip' && (int)($user['vip_until'] ?? 0) <= time()) { continue; }
            if ($item['visibility'] === 'verified' && empty($user['verified'])) { continue; }
            $item['href'] = $item['route'] === 'external' ? $item['url'] : $this->url($item['route']); $items[] = $item;
        }
        return $items;
    }
}
