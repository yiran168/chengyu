<?php
declare(strict_types=1);
if (!defined('CY_BOOT')) { define('CY_BOOT', true); }
ini_set('display_errors', '0'); error_reporting(E_ALL); date_default_timezone_set('Asia/Shanghai');
require_once __DIR__ . '/autoload.php';
\Chengyu\Core\Security::headers();
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(static function (\Throwable $e): void {
    $status = $e instanceof \Chengyu\Core\Problem ? $e->status : 500;
    $id = bin2hex(random_bytes(6)); http_response_code($status);
    $log = dirname(__DIR__) . '/storage/errors.php';
    if (!is_file($log)) { @file_put_contents($log, "<?php http_response_code(404); exit; __halt_compiler();\n", LOCK_EX); }
    if (is_file($log) && @filesize($log) < 5000000) {
        @file_put_contents($log, gmdate('c') . ' ' . $id . ' ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine() . ' ' . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n", FILE_APPEND | LOCK_EX);
    }
    $message = $e instanceof \Chengyu\Core\Problem ? tr($e->getMessage()) : tr('Something went wrong. Please contact the administrator with this reference.');
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['ok' => false, 'message' => $message, 'reference' => $id], JSON_UNESCAPED_UNICODE); return;
    }
    if (isset($GLOBALS['chengyu'])) {
        render('error', ['title' => tr('Request could not be completed'), 'message' => $message, 'reference' => $id, 'status' => $status]);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>Chengyu</title><h1>' . e($message) . '</h1><p>' . e($id) . '</p>';
    }
});
if (!is_file(__DIR__ . '/config.php')) {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = \Chengyu\Core\WebPath::directory($script); if (substr($base, -6) === '/admin') { $base = substr($base, 0, -6); }
    header('Location: ' . $base . '/install/', true, 302); exit;
}
$config = require __DIR__ . '/config.php';
$GLOBALS['chengyu'] = new \Chengyu\App($config);
if(app()->settings->get('object_storage_enabled')){$host=parse_url(app()->settings->get('s3_endpoint'),PHP_URL_HOST);if(is_string($host)&&preg_match('/^[a-zA-Z0-9.-]+$/D',$host)){\Chengyu\Core\Security::headers('https://'.$host);}}
if (!defined('CY_NO_SESSION')) {
    \Chengyu\Core\Security::session(app()->basePath . '/', strpos($config['url'], 'https://') === 0);
}
return app();
