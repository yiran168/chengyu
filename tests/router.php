<?php
// Local test server only. php -d ffi.enable=1 -S 127.0.0.1:8765 tests/router.php
if (PHP_SAPI !== 'cli-server') { exit; }
$root = getenv('CY_TEST_ROOT');
if (!$root || !is_dir($root)) { throw new RuntimeException('CY_TEST_ROOT must point to a disposable site copy.'); }
if (!defined('CY_BOOT')) { define('CY_BOOT', true); }
require_once $root . '/app/autoload.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    require_once __DIR__ . '/Support/FfiSqlite.php';
    Chengyu\Core\Database::useTestFactory(static function (array $config) { return new FfiSqlite($config['path']); });
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~^/(app|storage|uploads)(/|$)~i', $path) || strpos($path, '..') !== false || strpos($path, "\0") !== false) { http_response_code(403); exit; }
$file = $root . $path;
if (is_dir($file)) { $file = rtrim($file, '/') . '/index.php'; }
if (!is_file($file)) { http_response_code(404); exit('Not found'); }
if (substr($file, -4) !== '.php') {
    $types = ['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','txt'=>'text/plain','webp'=>'image/webp','png'=>'image/png','jpg'=>'image/jpeg','html'=>'text/html; charset=utf-8'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream')); readfile($file); return true;
}
$_SERVER['SCRIPT_FILENAME'] = $file; $_SERVER['SCRIPT_NAME'] = substr($file, strlen($root));
require $file;
