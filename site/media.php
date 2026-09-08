<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$id = \Chengyu\Core\Input::integer($_GET['id'] ?? 0, 1);
$user=app()->auth->user();
app()->media->stream(app()->media->readable($id,$user),$user);
