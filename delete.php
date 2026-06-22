<?php
ob_start();
session_start();
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/http.php';

if (empty($_SESSION['loggedin'])) {
    jsonExit(['status' => false, 'message' => '未登录，无权限执行删除操作', 'data' => []], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonExit(['status' => false, 'message' => '仅允许 POST 请求。', 'data' => []], 405);
}

validateCsrfToken();

jsonExit((new ImageDeleter(Database::getInstance()->getConnection()))->delete($_POST['path'] ?? ''));
