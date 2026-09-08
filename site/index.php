<?php
declare(strict_types=1);
if (version_compare(PHP_VERSION, '7.4.0', '<') || PHP_INT_SIZE < 8) { http_response_code(503); exit('Chengyu requires 64-bit PHP 7.4 or newer. Live payments require HTTPS.'); }
require __DIR__ . '/app/bootstrap.php';
(new \Chengyu\Http\SiteController(app()))->show();
