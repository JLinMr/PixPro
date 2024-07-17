<?php
ob_start();

require_once 'vendor/autoload.php';
require_once 'config/validate.php';
require_once 'config/Database.php';
require_once 'config/image_processing.php';
require_once 'config/Upload_processing.php';

// 读取配置文件
$config = parse_ini_file('config/config.ini');
$accessKeyId = $config['accessKeyId'];
$accessKeySecret = $config['accessKeySecret'];
$endpoint = $config['endpoint'];
$bucket = $config['bucket'];
$cdndomain = $config['cdndomain'];
$validToken = $config['validToken'];
$storage = $config['storage'];
$protocol = $config['protocol'];
$s3Region = $config['s3Region'];
$s3Bucket = $config['s3Bucket'];
$s3Endpoint = $config['s3Endpoint'];
$s3AccessKeyId = $config['s3AccessKeyId'];
$s3AccessKeySecret = $config['s3AccessKeySecret'];
$customUrlPrefix = $config['customUrlPrefix'];

// 获取当前请求的域名
$frontendDomain = $_SERVER['HTTP_HOST'];

// 获取数据库连接
$db = Database::getInstance();
$mysqli = $db->getConnection();

/**
 * 记录日志信息
 */
function logMessage($message) {
    $logFile = 'process_log.txt';
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

/**
 * 验证Token是否有效
 */
function isValidToken($token) {
    global $validToken;
    return $token === $validToken;
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