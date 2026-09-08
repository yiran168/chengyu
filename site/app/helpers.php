<?php
declare(strict_types=1);
function app(): \Chengyu\App { return $GLOBALS['chengyu']; }
/** Public decoration never grants a private media entitlement. */
function public_image_url(int $id): string {
    if($id<1){return '';}
    $row=app()->db->one('SELECT id,mime FROM cy_media WHERE id=? AND is_private=0',[$id]);
    return $row && strpos($row['mime'],'image/')===0?media_url($id):'';
}
function brand_mark(): string {
    $s=app()->settings;$custom=public_image_url((int)$s->get('logo_media_id'));
    if($custom!=='' || $s->get('brand_art')==='anime'){
        return '<span class="brand-mark brand-mascot"><img src="'.e($custom?:asset('art/chengyu-mascot.webp')).'" width="52" height="52" alt="" decoding="async"></span>';
    }
    return '<span class="brand-mark">'.e($s->get('logo_text')).'</span>';
}
function e($value): string { return \Chengyu\Core\Markdown::escape($value); }
function tr(string $key): string { return \Chengyu\Core\Locale::translate($key); }

function t(string $key): string { return e(tr($key)); }
function url(string $route = 'home', array $params = []): string { return app()->url($route, $params); }
function au(string $tab = 'dashboard', array $params = []): string { return app()->adminUrl($tab, $params); }
function asset(string $file): string { return app()->basePath . '/assets/' . $file . '?v=' . \Chengyu\App::VERSION; }
function media_url(int $id): string { return app()->basePath . '/media.php?id=' . $id; }
function money(int $value): string { return \Chengyu\Services\Payment::decimal($value); }
function price(int $value, string $currency): string
{
    return \Chengyu\Services\Wallet::isMoney($currency) ? ((string)app()->settings->get('store_currency')==='CNY'?html_entity_decode('&#165; ', ENT_QUOTES, 'UTF-8'):(string)app()->settings->get('store_currency').' ') . money($value) : number_format($value) . ' ' . ($currency === 'tokens' ? (string)app()->settings->get('token_name') : tr('Points'));
}
function dt(int $timestamp, string $format = 'Y.m.d'): string { return $timestamp > 0 ? date($format, $timestamp) : '-'; }
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e(\Chengyu\Core\Security::csrf()) . '">'; }
function intent(): string { return '<input type="hidden" name="intent" value="' . e(\Chengyu\Core\Security::token()) . '">'; }
function form_start(string $action, string $back = '', string $class = '', bool $async = true): string
{
    $back = $back ?: (string)($_SERVER['REQUEST_URI'] ?? url());
    return '<form method="post" action="' . e(app()->basePath . '/action.php') . '" class="' . e($class) . '"' . ($async ? ' data-async' : '') . '>' . csrf() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="_back" value="' . e(app()->localTarget($back)) . '">';
}
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'][] = ['message' => $message, 'type' => $type]; }
function flashes(): array { $messages = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $messages; }
function redirect(string $target, int $status = 303): void { header('Location: ' . $target, true, $status); exit; }
function render(string $view, array $data = [], bool $admin = false): void
{
    if (!preg_match('/^[a-z_]+$/D', $view)) { throw new \LogicException('Invalid view'); }
    extract($data, EXTR_SKIP); $a = app(); $s = $a->settings; $me = $a->auth->user();
    ob_start(); require __DIR__ . '/Views/' . $view . '.php'; $content = ob_get_clean();
    require __DIR__ . '/Views/' . ($admin ? 'admin_layout' : 'layout') . '.php';
}
function partial(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP); $a = app(); $s = $a->settings; $me = $a->auth->user();
    require __DIR__ . '/Views/' . $view . '.php';
}
function icon(string $name, string $class = ''): string
{
    static $paths = [
        'arrow' => '<path d="M4 12h15m-6-6 6 6-6 6"/>',
        'chevron' => '<path d="m9 5 7 7-7 7"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
        'moon' => '<path d="M21 13A9 9 0 0 1 11 3 9 9 0 1 0 21 13Z"/>',
        'sparkles' => '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3ZM20 2v4m-2-2h4"/>',
        'book' => '<path d="M3 4h7l2 2 2-2h7v15h-7l-2 2-2-2H3V4Zm9 2v15"/>',
        'layers' => '<path d="m12 3 10 5-10 5L2 8l10-5Zm-9 10 9 5 9-5M3 18l9 5 9-5"/>',
        'message' => '<path d="M21 11a9 9 0 0 1-9 9H3l2-4a9 9 0 1 1 16-5Z"/><path d="M8 10h8m-8 4h5"/>',
        'box' => '<path d="m12 2 9 5v10l-9 5-9-5V7l9-5Zm0 10 9-5M3 7l9 5v10M7.5 4.5l9 5"/>',
        'crown' => '<path d="m2 7 5 4 5-8 5 8 5-4-3 13H5L2 7Zm4 9h12"/>',
        'wallet' => '<path d="M20 7H5a3 3 0 0 1 0-6h14v6M3 4v15a2 2 0 0 0 2 2h16V7m0 5h-6v5h6"/><circle cx="16" cy="14.5" r=".5"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'home' => '<path d="m3 10 9-8 9 8v11h-6v-7H9v7H3V10Z"/>',
        'code' => '<path d="m8 5-6 7 6 7m8-14 6 7-6 7m-3-17-2 20"/>',
        'leaf' => '<path d="M20 3C3 1 1 15 8 19c8 5 14-4 12-16ZM4 22 16 8"/>',
        'compass' => '<circle cx="12" cy="12" r="10"/><path d="m16 8-3 5-5 3 3-5 5-3Z"/>',
        'heart' => '<path d="M20 4a5 5 0 0 0-8 2 5 5 0 0 0-8-2c-5 5 2 11 8 16 6-5 13-11 8-16Z"/>',
        'bookmark' => '<path d="M5 3h14v19l-7-5-7 5V3Z"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 3"/>',
        'check' => '<path d="m4 12 5 5L20 5"/>',
        'lock' => '<rect x="4" y="10" width="16" height="12" rx="3"/><path d="M8 10V6a4 4 0 0 1 8 0v4m-4 5v3"/>',
        'bell' => '<path d="M4 17h16l-2-4V8a6 6 0 0 0-12 0v5l-2 4Zm5 3a3 3 0 0 0 6 0"/>',
        'user' => '<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>',
        'users' => '<circle cx="9" cy="7" r="4"/><path d="M1 22v-3a8 8 0 0 1 16 0v3m0-19a4 4 0 0 1 0 8m3 3a7 7 0 0 1 3 6"/>',
        'edit' => '<path d="m15 3 6 6-11 11-7 1 1-7L15 3Zm-1 1 6 6"/>',
        'settings' => '<path d="M4 5h16M4 12h16M4 19h16"/><circle cx="8" cy="5" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="19" r="2"/>',
        'logout' => '<path d="M9 3H3v18h6m-1-9h14m-5-5 5 5-5 5"/>',
        'download' => '<path d="M12 2v13m-5-5 5 5 5-5M3 16v6h18v-6"/>',
        'upload' => '<path d="M12 17V4m-5 5 5-5 5 5M3 16v6h18v-6"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'close' => '<path d="m5 5 14 14M5 19 19 5"/>',
        'plus' => '<path d="M12 3v18M3 12h18"/>',
        'shield' => '<path d="m12 2 9 4v7c0 6-9 10-9 10S3 19 3 13V6l9-4Z"/><path d="m8 12 3 3 6-6"/>',
        'chart' => '<path d="M3 3v18h18M7 16l4-5 4 2 6-8"/>',
        'gift' => '<rect x="3" y="8" width="18" height="5" rx="1"/><path d="M5 13v9h14v-9M12 8v14m0-14C3 8 4 1 8 2c3 0 4 6 4 6Zm0 0c9 0 8-7 4-6-3 0-4 6-4 6Z"/>',
        'refresh' => '<path d="M20 8a9 9 0 0 0-16 0m0 8a9 9 0 0 0 16 0M20 2v6h-6M4 22v-6h6"/>',
        'server' => '<rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 6h1m-1 11h1m8-11h4m-4 11h4"/>',
        'file' => '<path d="M14 2H4v20h16V8l-6-6Zm0 0v6h6M8 13h8m-8 4h6"/>',
        'palette' => '<path d="M12 2A10 10 0 1 0 12 22c5 0-3-7 3-7h3C24 15 21 2 12 2Z"/><circle cx="7" cy="8" r="1"/><circle cx="12" cy="6" r="1"/><circle cx="17" cy="9" r="1"/>',
    ];
    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['sparkles']) . '</svg>';
}
function avatar(array $user, string $class = ''): string
{
    if (!empty($user['avatar_id'])) { return '<img class="avatar ' . e($class) . '" src="' . e(media_url((int)$user['avatar_id'])) . '" alt="" loading="lazy">'; }
    $name = $user['display_name'] ?? $user['username'] ?? 'C'; preg_match('/^./us', $name, $m);
    return '<span class="avatar ' . e($class) . '" aria-hidden="true">' . e($m[0] ?? 'C') . '</span>';
}
function hidden(string $name, $value): string { return '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">'; }
function field(string $name, string $label, $value = '', string $type = 'text', array $attrs = []): void
{
    $id = 'f_' . $name . '_' . bin2hex(random_bytes(2)); $extra = '';
    foreach ($attrs as $key => $val) { if (preg_match('/^[a-z][a-z0-9\-]*$/D', $key)) { $extra .= ' ' . $key . '="' . e($val) . '"'; } }
    echo '<div class="field"><label for="' . $id . '">' . t($label) . '</label>';
    if ($type === 'textarea') { echo '<textarea id="' . $id . '" name="' . e($name) . '" rows="5"' . $extra . '>' . e($value) . '</textarea>'; }
    else { echo '<input id="' . $id . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' . $extra . '>'; }
    echo '</div>';
}
function select_field(string $name, string $label, array $options, $value = '', array $attrs = []): void
{
    $id = 's_' . $name . '_' . bin2hex(random_bytes(2)); $extra = '';
    foreach ($attrs as $key => $val) { if (preg_match('/^[a-z][a-z0-9\-]*$/D', $key)) { $extra .= ' ' . $key . '="' . e($val) . '"'; } }
    echo '<div class="field"><label for="' . $id . '">' . t($label) . '</label><select id="' . $id . '" name="' . e($name) . '"' . $extra . '>';
    foreach ($options as $k => $label) { if ($name === 'access_level' && $label === 'paid') { $label = 'Paid access'; } echo '<option value="' . e($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . t((string)$label) . '</option>'; }
    echo '</select></div>';
}
function check_field(string $name, string $label, bool $checked = false): void
{
    echo '<label class="checkbox"><input type="checkbox" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '><span>' . t($label) . '</span></label>';
}
function options(array $values): array { return array_combine($values, $values) ?: []; }
function cover(array $item): string { return !empty($item['cover_id']) ? media_url((int)$item['cover_id']) : asset(app()->settings->get('default_cover_style')==='abstract'?'cover-' . (((int)$item['id'] - 1) % 6 + 1) . '.svg':(((int)$item['id']%2)?'art/blue-hour.webp':'art/island-anime.webp')); }
function item_url(array $item): string { return url($item['kind'] === 'product' ? 'product' : ($item['kind'] === 'page' ? 'page' : 'article'), ['id' => (int)$item['id']]); }
function empty_state(string $message = 'Nothing here yet.', string $symbol = 'leaf'): void { echo '<div class="empty-state">' . icon($symbol) . '<h3>' . t($message) . '</h3><p>' . t('A little space for something good to come.') . '</p></div>'; }
function button(string $label = 'Save changes', string $class = '', string $symbol = 'check'): void { echo '<button class="btn ' . e($class) . '" type="submit">' . icon($symbol) . t($label) . '</button>'; }
function upload_field(string $name, string $label, int $value, bool $private = false): void
{
    echo '<div class="upload-field">'; field($name, $label, $value, 'number', ['min' => '0']);
    echo '<label class="upload-button">' . icon('upload') . t('Choose a file') . '<input type="file" data-upload-target="' . e($name) . '" data-private="' . ($private ? '1' : '0') . '"></label><small class="upload-status">' . t($private ? 'Private attachment; access is checked on download.' : 'Public image; use only assets you own.') . '</small></div>';
}
function address_select(int $uid): void
{
    $opts = [0 => tr('Choose a shipping address')];
    foreach (app()->db->all('SELECT * FROM cy_addresses WHERE user_id=? ORDER BY id DESC', [$uid]) as $row) { $opts[$row['id']] = $row['recipient'] . ' - ' . $row['address']; }
    select_field('address_id', 'Shipping address', $opts); echo '<p class="hint"><a href="' . e(url('profile')) . '">' . t('Manage your addresses') . '</a></p>';
}
function admin_nav(): array
{
    return ['backups'=>['Recovery vault','shield','manage'],'search_index'=>['Search index','compass','manage'],'learning'=>['Course studio','book','content'],'logistics'=>['Logistics desk','box','manage'],'reconciliation'=>['Statement reconciliation','chart','manage'],'support'=>['Support desk','message','manage'],'media_lab'=>['Media workbench','file','manage'],'translations'=>['Content translations','compass','content'],'commerce'=>['Commerce center','box','manage'],'shipping'=>['Shipping and promotions','gift','manage'],'service'=>['After-sales and payouts','shield','manage'],'integrations'=>['Connected services','compass','manage'],'configuration'=>['Configuration vault','layers','manage'],'badges'=>['Community badges','star','manage'],'dashboard'=>['Overview','grid','manage'],'pricing'=>['Pricing studio','gift','manage'],'creators'=>['Creator revenue','chart','manage'],'motion'=>['Motion studio','sparkles','manage'],'threads'=>['Questions and polls','message','content'],'circles'=>['Circle memberships','users','manage'],'bounties'=>['Question bounties','gift','manage'],'reviews'=>['Product reviews','star','moderate'],'builder'=>['Layout studio','layers','manage'],'collections'=>['Editorial collections','book','manage'],'links'=>['Link directory','compass','manage'],'announcements'=>['Scheduled announcements','bell','manage'],'tasks'=>['Task center','gift','manage'],'invitations'=>['Invitation codes','users','manage'],'variants'=>['Product variants','box','manage'],'resources'=>['Resource releases','layers','content'],'downloads'=>['Download records','file','manage'],'contents' => ['Content library', 'book', 'content'], 'categories' => ['Categories', 'layers', 'manage'], 'moderation' => ['Moderation', 'shield', 'moderate'], 'users' => ['Members', 'users', 'manage'], 'orders' => ['Orders', 'box', 'manage'], 'plans' => ['Membership plans', 'crown', 'manage'], 'stock' => ['Code inventory', 'code', 'manage'], 'coupons' => ['Coupons', 'gift', 'manage'], 'vouchers' => ['Redeem codes', 'gift', 'manage'], 'withdrawals' => ['Withdrawals', 'wallet', 'manage'], 'ledger' => ['Financial ledger', 'chart', 'manage'], 'media' => ['Media library', 'file', 'manage'], 'navigation' => ['Navigation', 'compass', 'manage'], 'settings' => ['Site settings', 'settings', 'manage'], 'logs' => ['Audit logs', 'shield', 'manage'], 'system' => ['System health', 'server', 'manage']];
}

function variant_select(array $item): void
{
    $a=app();$variants=$a->inventory->variants((int)$item['id']);
    if(!$variants && !(int)$a->db->value('SELECT COUNT(*) FROM cy_variants WHERE content_id=?',[(int)$item['id']])) {return;}
    $id='sku_'.bin2hex(random_bytes(4));echo '<div class="field variant-picker"><label for="'.e($id).'">'.t('Choose a variant').'</label><select name="variant_id" id="'.e($id).'" required data-variant-select><option value="">'.t('Choose a variant').'</option>';
    foreach($variants as $v){$q=$a->pricing->price($item,$a->auth->user(),(int)$v['price_amount']);$display=price($q['amount'],$item['price_currency']);echo '<option value="'.(int)$v['id'].'" data-price="'.e($display).'" data-amount="'.$q['amount'].'"'.((int)$v['inventory']===0?' disabled':'').'>'.e($v['name'].' / '.$display.' / '.tr('Stock').': '.((int)$v['inventory']<0?tr('Unlimited'):$v['inventory'])).'</option>';}
    echo '</select><output data-variant-price class="hint" aria-live="polite"></output></div>';
}

/** Feed cards and purchase panels share the same price presentation. */
function item_price(array $item): string
{
    if (!empty($item['variant_count'])) {
        return $item['variant_min_price'] === null ? tr('Out of stock.') : price(app()->pricing->price($item,app()->auth->user(),(int)$item['variant_min_price'])['amount'], $item['price_currency']).' '.tr('From');
    }
    return price(app()->pricing->price($item,app()->auth->user(),(int)$item['price_amount'])['amount'], $item['price_currency']);
}

function verification_fields(string $purpose): void
{
    field('email_code','Email verification code','','text',['inputmode'=>'numeric','pattern'=>'[0-9]{6}','maxlength'=>'6','autocomplete'=>'one-time-code','required'=>'required']);
    echo '<div class="row wrap verification-actions"><button type="button" class="btn secondary small" data-email-code="'.e($purpose).'">'.icon('message').t('Send verification code').'</button><span class="hint" data-code-status role="status"></span></div>';
    echo '<noscript><p><a href="'.e(url('verify',['purpose'=>$purpose])).'">'.t('Request a code without JavaScript').'</a></p></noscript>';
}

function membership_until(int $timestamp): string
{return $timestamp>=\Chengyu\Services\Membership::FOREVER?t('Lifetime'):dt($timestamp);}

/** Navigation is declared once; groups contain keys, not duplicated labels or permissions. */
function admin_groups(): array
{
    return [
        'Workspace'=>['dashboard','settings','integrations','configuration','backups','system','logs'],
        'Create and publish'=>['contents','learning','search_index','categories','collections','resources','downloads','media','media_lab','translations','moderation'],
        'Community and people'=>['users','support','threads','circles','bounties','reviews','badges','tasks','invitations'],
        'Commerce and finance'=>['commerce','shipping','logistics','reconciliation','service','orders','variants','stock','plans','pricing','coupons','vouchers','creators','withdrawals','ledger'],
        'Experience and growth'=>['motion','builder','navigation','announcements','links'],
    ];
}

/** End the same-origin form request with a document, not an external 303 chain.
 * Strict CSP form-action remains self. Only trusted service-generated URLs enter here.
 * JavaScript continues without a timer; a real anchor remains usable without scripts.
 */
function external_handoff(string $target): void
{
    $data = \Chengyu\Core\ExternalNavigation::target($target);
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    http_response_code(200);
    render('external_handoff', $data + ['title' => tr('Continue securely'), 'route' => 'external_handoff']);
}
