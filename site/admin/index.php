<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
(new \Chengyu\Http\AdminController(app()))->show();
