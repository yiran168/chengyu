<?php
/** Run on a trusted PHP host. Secrets are read from the environment, never the URL. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../site/app/autoload.php';
use Chengyu\Core\{HttpClient, Input};
try {
    $origin = rtrim((string)getenv('CY_SITE_URL'), '/');
    $secret = (string)getenv('CY_JOBS_SECRET');
    if ($origin === '' || strlen($secret) < 32) {
        fwrite(STDERR, "Set CY_SITE_URL and CY_JOBS_SECRET, then run: php tools/trigger_jobs.php [1-10]\n");
        exit(2);
    }
    HttpClient::endpoint($origin);
    if (parse_url($origin, PHP_URL_QUERY) || parse_url($origin, PHP_URL_FRAGMENT)) {
        throw new InvalidArgumentException('Site URL must not contain a query or fragment.');
    }
    $body = json_encode(['limit' => Input::integer($argv[1] ?? 1, 1, 10)], JSON_THROW_ON_ERROR);
    $time = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256', $time . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
    $result = (new HttpClient())->request('POST', $origin . '/jobs.php', $body, [
        'Content-Type' => 'application/json', 'X-CY-Time' => $time,
        'X-CY-Nonce' => $nonce, 'X-CY-Signature' => $signature,
    ]);
    $decoded = json_decode($result['body'], true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || empty($decoded['ok'])) { throw new RuntimeException('Job result was not confirmed.'); }
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Job trigger failed. Verify HTTPS, clock, signing secret and the private server log.\n");
    exit(1);
}
