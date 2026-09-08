<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$json = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
try {
    $response = (new \Chengyu\Http\Actions(app()))->run($_POST);
    if ($json) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    else {
        if (parse_url($response['redirect'], PHP_URL_SCHEME) !== null) { external_handoff($response['redirect']); }
        else { flash($response['message']); redirect($response['redirect']); }
    }
} catch (\Chengyu\Core\Problem $e) {
    if ($json) { http_response_code($e->status); header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['ok' => false, 'message' => tr($e->getMessage())], JSON_UNESCAPED_UNICODE); }
    else { flash(tr($e->getMessage()), 'error'); redirect(app()->localTarget(is_string($_POST['_back'] ?? null) ? $_POST['_back'] : url())); }
}
