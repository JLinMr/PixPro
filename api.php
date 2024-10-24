<?php
ob_start();

require_once 'vendor/autoload.php';
require_once 'config/validate.php';
require_once 'config/Database.php';
require_once 'config/Upload_processing.php';

/**
 * 获取数据库连接
 */
$db = Database::getInstance();
$mysqli = $db->getConnection();

/**
 * 读取配置文件
 * 缓存白名单主机名
 */
$config = parse_ini_file('config/config.ini', true);
$validToken = $config['Token']['validToken'];
$whitelist = explode(',', $config['Other']['whitelist']);
$allowedHosts = array_map(function($url) {
    return parse_url($url, PHP_URL_HOST);
}, $whitelist);

/**
 * 验证令牌和来源域名。
 *
 * @param string $token 令牌。
 * @param string $referer 来源URL。
 */
function validateToken($token, $referer, $allowedHosts) {
    global $validToken;

    $refererHost = parse_url($referer, PHP_URL_HOST) ?: '';

    if (in_array($refererHost, $allowedHosts) || $token === $validToken) {
        return;
    }

    respondAndExit(['result' => 'error', 'code' => 403, 'message' => '域名未在白名单或Token验证失败']);
}

/**
 * 设置响应头
 */
function setCorsHeaders($allowedHosts) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $originHost = !empty($origin) ? parse_url($origin, PHP_URL_HOST) : '';

    if (in_array($originHost, $allowedHosts)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type");
        return;
    }

    // 如果来源不允许，临时允许所有来源并返回错误响应
    header("Access-Control-Allow-Origin: *");
    http_response_code(403);
    respondAndExit(['result' => 'error', 'code' => 403, 'message' => '你的域名未在白名单内']);
}

setCorsHeaders($allowedHosts);

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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES)) {
        $token = $_POST['token'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        validateToken($token, $referer, $allowedHosts);

        try {
            foreach ($_FILES as $file) {
                handleUploadedFile($file, $token, $referer);
            }
        } catch (Exception $fileException) {
            logMessage('文件处理错误', ['error' => $fileException->getMessage()]);
            respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件处理错误: ' . $fileException->getMessage()]);
        }

    } else {
        respondAndExit(['result' => 'error', 'code' => 204, 'message' => '无文件上传']);
    }
} catch (Exception $e) {
    logMessage('未知错误', ['error' => $e->getMessage()]);
    respondAndExit(['result' => 'error', 'code' => 500, 'message' => '发生未知错误: ' . $e->getMessage()]);
}
?>