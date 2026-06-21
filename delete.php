<?php
ob_start();
session_start();
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/http.php';

if (empty($_SESSION['loggedin'])) {
    jsonExit(['result' => 'error', 'message' => '未登录，无权限执行删除操作'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonExit(['result' => 'error', 'message' => '仅允许 POST 请求。'], 405);
}

jsonExit((new ImageDeleter(Database::getInstance()->getConnection()))->delete($_POST['path'] ?? ''));
