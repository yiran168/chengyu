<?php
declare(strict_types=1);
require_once __DIR__ . '/Core/Support.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Chengyu\\';
    if (strpos($class, $prefix) !== 0) { return; }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative)) { return; }
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) { require_once $file; }
});
require_once __DIR__ . '/helpers.php';
