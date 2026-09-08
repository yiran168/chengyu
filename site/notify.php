<?php
declare(strict_types=1);
define('CY_NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: text/plain; charset=UTF-8');
try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) { throw new \Chengyu\Core\Problem('Invalid method.', 405); }
    $gateway = \Chengyu\Core\Input::choice($_GET['gateway'] ?? '', ['epay', 'codepay']);
    $fields = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
    app()->payment->settle($gateway, $fields); echo 'success';
} catch (\Throwable $e) {
    // Do not expose signatures, secrets, SQL errors, or order existence to an unauthenticated caller.
    http_response_code(400); echo 'fail';
}
