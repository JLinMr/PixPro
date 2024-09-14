<?php
ob_start();

require_once 'config/validate.php';
require_once 'config/Database.php';
require_once 'config/Upload_processing.php';

/**
 * 获取数据库连接
 */
$db = Database::getInstance();
$mysqli = $db->getConnection();


/**
 * 记录日志信息
 */
function logMessage($message) {
    $logFile = '上传日志.txt';
    $currentTime = date('Y-m-d H:i:s');
    $logMessage = "[$currentTime] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

/**
 * 返回响应并退出脚本
 */
function respondAndExit($response) {
    ob_end_clean();
    echo json_encode($response);
    ob_flush();
    flush();
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
        $file = $_FILES['image'];
        $token = isset($_POST['token']) ? $_POST['token'] : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        handleUploadedFile($file, $token, $referer);
    } else {
        respondAndExit(['result' => 'error', 'code' => 204, 'message' => '无文件上传']);
    }
} catch (Exception $e) {
    logMessage('未知错误: ' . $e->getMessage());
    respondAndExit(['result' => 'error', 'code' => 500, 'message' => '发生未知错误: ' . $e->getMessage()]);
}
?>