<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Crypto, Input, Problem, HttpClient};
/** Classic MD5 gateway compatibility adapters. No gateway hosts or credentials are bundled. */
final class Payment
{
    private Database $db; private Settings $settings; private Crypto $crypto; private Wallet $wallet; private Activity $activity; private string $baseUrl; private HttpClient $http;
    public function __construct(Database $db, Settings $settings, Crypto $crypto, Wallet $wallet, Activity $activity, string $baseUrl, ?HttpClient $http=null)
    { $this->db = $db; $this->settings = $settings; $this->crypto = $crypto; $this->wallet = $wallet; $this->activity = $activity; $this->baseUrl = $baseUrl; $this->http=$http??new HttpClient(); }
    public static function sign(array $fields, string $key): string
    {
        unset($fields['sign'], $fields['sign_type']); ksort($fields, SORT_STRING); $parts = [];
        foreach ($fields as $name => $value) {
            if (!is_scalar($value)) { throw new Problem('Invalid payment notification.'); }
            if ((string)$value !== '') { $parts[] = $name . '=' . (string)$value; }
        }
        return md5(implode('&', $parts) . $key);
    }
    public static function decimal(int $amount): string { $sign = $amount < 0 ? '-' : ''; $absolute = abs($amount); return $sign . intdiv($absolute, 100) . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT); }
    public function create(int $user, int $cents, string $gateway, string $method, string $intent): array
    {
        $this->settings->requireModule('wallet'); if($this->settings->get('store_currency')!=='CNY'){throw new Problem('Legacy yuan top-ups require the CNY store currency.');} Input::choice($gateway, ['epay', 'codepay']);
        $this->settings->requireModule($gateway); Wallet::intent($intent); Input::choice($method, ['alipay', 'wxpay', 'qqpay']);
        if (!$this->settings->get($gateway . '_' . $method)) { throw new Problem('This payment channel is disabled.'); }
        if (strpos($this->baseUrl, 'https://') !== 0) { throw new Problem('Configure an HTTPS site URL before enabling live payments.'); }
        if ($cents < (int)$this->settings->get('topup_min') || $cents > (int)$this->settings->get('topup_max')) { throw new Problem('Top-up amount is outside the allowed range.'); }
        $this->activity->rate('topup', (string)$user, 20, 3600);
        $snapshot = ['endpoint' => (string)$this->settings->get($gateway . '_endpoint'), 'merchant' => (string)$this->settings->get($gateway . '_merchant'), 'secret' => (string)$this->settings->get($gateway . '_secret')];
        $snapshot['query_endpoint']=$gateway==='epay'?(string)$this->settings->get('epay_query_endpoint'):'';
        if (strpos($snapshot['endpoint'], 'https://') !== 0 || $snapshot['merchant'] === '' || $snapshot['secret'] === '') { throw new Problem('Payment gateway is not configured.'); }
        return $this->db->transaction(function () use ($user, $cents, $gateway, $method, $intent, $snapshot): array {
            $this->settings->assertMoneyCurrency();
            $account = $this->db->one('SELECT status FROM cy_users WHERE id=?' . $this->db->lock(), [$user]);
            if (!$account || $account['status'] !== 'active') { throw new Problem('Account unavailable.', 403); }
            $existing = $this->db->one('SELECT * FROM cy_orders WHERE request_key=?', [$user . ':' . $intent]);
            if ($existing) {
                if ($existing['kind'] !== 'topup' || (int)$existing['amount'] !== $cents || $existing['gateway'] !== $gateway || $existing['gateway_type'] !== $method) { throw new Problem('Idempotency key was reused with different data.', 409); }
                return $existing;
            }
            $row = Commerce::row($user, 'topup', 0, 'Wallet top-up', $cents, 'balance', $intent);
            $row['gateway'] = $gateway; $row['gateway_type'] = $method;
            $row['expires_at'] = time() + (int)$this->settings->get('order_expiry') * 60;
            $snapshot['param'] = $this->crypto->digest('codepay:' . $row['order_no']);
            $row['metadata'] = json_encode(['gateway_config' => $this->crypto->seal(json_encode($snapshot, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
            $id = $this->db->insert('cy_orders', $row);
            return $this->db->one('SELECT * FROM cy_orders WHERE id=?', [$id]);
        });
    }
    private function config(array $order): array
    {
        $meta = json_decode($order['metadata'], true);
        return json_decode($this->crypto->open($meta['gateway_config']), true, 16, JSON_THROW_ON_ERROR);
    }
    public function checkout(array $order): string
    {
        if ($order['kind'] !== 'topup' || $order['status'] !== 'pending' || (int)$order['expires_at'] < time()) { throw new Problem('Payment order is no longer active.'); }
        $config = $this->config($order);
        $notify = $this->baseUrl . '/notify.php?gateway=' . $order['gateway'];
        // The gateway selector is routing metadata, not part of the signed callback payload.
        if ($order['gateway'] === 'epay') {
            $fields = ['pid' => $config['merchant'], 'type' => $order['gateway_type'], 'out_trade_no' => $order['order_no'],
                'notify_url' => $notify, 'return_url' => $this->baseUrl . '/index.php?r=orders', 'name' => 'Wallet top-up', 'money' => self::decimal((int)$order['amount']), 'sitename' => (string)$this->settings->get('site_name')];
        } else {
            $types = ['alipay' => '1', 'qqpay' => '2', 'wxpay' => '3'];
            $fields = ['id' => $config['merchant'], 'type' => $types[$order['gateway_type']], 'pay_id' => $order['order_no'], 'price' => self::decimal((int)$order['amount']),
                'param' => $config['param'], 'notify_url' => $notify, 'return_url' => $this->baseUrl . '/index.php?r=orders'];
        }
        $fields['sign'] = self::sign($fields, $config['secret']); $fields['sign_type'] = 'MD5';
        return $config['endpoint'] . '?' . http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
    public function settle(string $gateway, array $fields): void
    {
        if((string)$this->settings->get('store_currency')!=='CNY'){throw new Problem('Legacy CNY gateways cannot credit a non-CNY wallet. No implicit currency conversion is allowed.');}
        Input::choice($gateway, ['epay', 'codepay']);
        // This field is added by our notify URL, not by the gateway signature algorithm.
        unset($fields['gateway']);
        if (count($fields) > 40) { throw new Problem('Invalid payment notification.'); }
        foreach ($fields as $value) { if (!is_scalar($value) || strlen((string)$value) > 4000) { throw new Problem('Invalid payment notification.'); } }
        $orderNo = Input::required($fields[$gateway === 'epay' ? 'out_trade_no' : 'pay_id'] ?? '', 64);
        $order = $this->db->one('SELECT * FROM cy_orders WHERE order_no=? AND gateway=? AND kind=?', [$orderNo, $gateway, 'topup']);
        if (!$order) { throw new Problem('Unknown payment order.'); }
        $config = $this->config($order);
        $signature = (string)($fields['sign'] ?? '');
        if (($fields['sign_type'] ?? 'MD5') !== 'MD5' || !preg_match('/^[a-f0-9]{32}$/iD', $signature) || !hash_equals(self::sign($fields, $config['secret']), strtolower($signature))) { throw new Problem('Payment signature verification failed.', 403); }
        $expected = (int)$order['amount'];
        if ($gateway === 'epay') {
            if (($fields['pid'] ?? '') !== $config['merchant'] || ($fields['type'] ?? '') !== $order['gateway_type'] || ($fields['trade_status'] ?? '') !== 'TRADE_SUCCESS') { throw new Problem('Payment merchant, channel or status mismatch.'); }
            $trade = Input::required($fields['trade_no'] ?? '', 100);
            if (Input::cents($fields['money'] ?? '') !== $expected) { throw new Problem('Payment amount mismatch.'); }
        } else {
            $types = ['alipay' => '1', 'qqpay' => '2', 'wxpay' => '3'];
            if (($fields['type'] ?? '') !== $types[$order['gateway_type']] || !hash_equals($config['param'], (string)($fields['param'] ?? ''))) { throw new Problem('Payment channel or order binding mismatch.'); }
            if (isset($fields['id']) && (string)$fields['id'] !== $config['merchant']) { throw new Problem('Payment merchant mismatch.'); }
            $trade = Input::required($fields['pay_no'] ?? '', 100);
            // Floating-amount / rounding modes are intentionally not accepted.
            if (Input::cents($fields['money'] ?? '') !== $expected || (isset($fields['price']) && Input::cents($fields['price']) !== $expected)) { throw new Problem('Payment amount mismatch.'); }
        }
        $this->credit($orderNo,$gateway,$trade,$expected);
    }
    private function credit(string $orderNo,string $gateway,string $trade,int $expected): bool
    {
        return $this->db->transaction(function () use ($orderNo, $gateway, $trade, $expected): bool {
            $locked = $this->db->one('SELECT * FROM cy_orders WHERE order_no=?' . $this->db->lock(), [$orderNo]);
            if ($locked['status'] === 'paid') {
                if ($locked['gateway_trade_no'] !== $trade) { throw new Problem('Conflicting payment receipt.', 409); }
                return false;
            }
            if (!in_array($locked['status'], ['pending', 'expired'], true) || $locked['gateway'] !== $gateway || (int)$locked['amount'] !== $expected) { throw new Problem('Payment order state mismatch.'); }
            if ($this->db->one('SELECT id FROM cy_orders WHERE gateway=? AND gateway_trade_no=? AND id<>?', [$gateway, $trade, (int)$locked['id']])) { throw new Problem('Payment receipt has already been used.', 409); }
            // A valid late top-up is credited too: unlike stock purchases, it reserves no scarce goods.
            $this->wallet->adjust((int)$locked['user_id'], 'balance', $expected, 'order:' . $locked['id'] . ':topup', 'Top-up #' . $orderNo);
            $this->db->update('cy_orders', (int)$locked['id'], ['status' => 'paid', 'gateway_trade_no' => $trade, 'paid_at' => time(), 'fulfillment' => 'fulfilled']);
            $this->activity->notify((int)$locked['user_id'], 'Top-up received', self::decimal($expected), 'wallet');
            $this->activity->audit(0, 'payment.settled', $orderNo);return true;
        });
    }
    /** A query is evidence from the configured HTTPS merchant API, never from a browser. */
    public function reconcile(int $actor,int $id): array
    {
        $account=$this->db->one("SELECT * FROM cy_users WHERE id=? AND status='active'",[$actor]);
        $order=$this->db->one("SELECT * FROM cy_orders WHERE id=? AND kind='topup' AND gateway='epay'",[$id]);
        if(!$account || !$order || ((int)$order['user_id']!==$actor && $account['role']!=='admin')){throw new Problem('Order not found.',404);}
        $this->settings->requireModule('epay_query');
        if($order['status']==='paid'){return ['status'=>'paid','changed'=>false];}
        if(!in_array($order['status'],['pending','expired'],true)){throw new Problem('This order cannot be reconciled automatically.',409);}
        $this->activity->rate('payment-query',(string)$id,6,60);$this->activity->rate('payment-query-actor',(string)$actor,30,3600);
        $c=$this->config($order);$endpoint=(string)($c['query_endpoint']??'');if($endpoint===''){$endpoint=(string)$this->settings->get('epay_query_endpoint');}
        if($endpoint===''){throw new Problem('Configure a same-origin gateway order query endpoint first.');}
        $query=HttpClient::endpoint($endpoint);$checkout=HttpClient::endpoint($c['endpoint']);
        if($query['host']!==$checkout['host'] || strpos($endpoint,'?')!==false){throw new Problem('The query endpoint must use the original gateway HTTPS origin without a query.');}
        try {
            $r=$this->http->json('GET',$endpoint,['act'=>'order','pid'=>$c['merchant'],'key'=>$c['secret'],'out_trade_no'=>$order['order_no']]);
            foreach(['code','pid','out_trade_no','type','money','status'] as $field){if(!isset($r[$field]) || !is_scalar($r[$field])){throw new Problem('The gateway query response is incomplete.',502);}}
            if((string)$r['code']!=='1' || (string)$r['pid']!==$c['merchant'] || (string)$r['out_trade_no']!==$order['order_no'] || (string)$r['type']!==$order['gateway_type'] || Input::cents($r['money'])!==(int)$order['amount'] || !in_array((string)$r['status'],['0','1'],true)){throw new Problem('The gateway query did not match this order. Nothing was credited.',502);}
            $changed=false;if((string)$r['status']==='1'){$trade=Input::required($r['trade_no']??'',100);$changed=$this->credit($order['order_no'],'epay',$trade,(int)$order['amount']);}
            $now=(string)$this->db->value('SELECT status FROM cy_orders WHERE id=?',[$id]);
            $result=$now==='paid'?'paid':'pending';$this->db->insert('cy_payment_checks',['actor_id'=>$actor,'order_id'=>$id,'result'=>$result,'created_at'=>time()]);$this->activity->audit($actor,'payment.reconciled',(string)$id);
            return ['status'=>$result,'changed'=>$changed];
        }catch(\Throwable $error){
            $this->db->insert('cy_payment_checks',['actor_id'=>$actor,'order_id'=>$id,'result'=>'error','created_at'=>time()]);
            $this->activity->audit($actor,'payment.query_failed',(string)$id);throw $error;
        }
    }
}
