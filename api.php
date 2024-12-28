<?php
ob_start();

require_once 'vendor/autoload.php';
require_once 'config/database.php';
require_once 'config/upload.php';

/**
 * 获取数据库连接并读取配置
 */
$db = Database::getInstance();
$mysqli = $db->getConnection();

$config = Database::getConfig($mysqli);
$validToken = $config['valid_token'];
$whitelist = explode(',', $config['whitelist']);
$allowedHosts = array_map(function($url) {
    return parse_url($url, PHP_URL_HOST);
}, $whitelist);

// 获取每日上传限制和文件大小限制
$stmt = $mysqli->prepare("SELECT value FROM configs WHERE `key` = ?");
$key = 'max_uploads_per_day';
$stmt->bind_param("s", $key);
$stmt->execute();
$result = $stmt->get_result();
$maxUploadsPerDay = (int)$result->fetch_assoc()['value'];

$key = 'max_file_size';
$stmt->bind_param("s", $key);
$stmt->execute();
$result = $stmt->get_result();
$maxFileSize = (int)$result->fetch_assoc()['value'];

/**
 * 检查是否允许上传
 */
function isUploadAllowed($maxUploadsPerDay) {
    $cookieName = 'upload_count';
    $currentDate = date('Y-m-d');
    
    if (isset($_COOKIE[$cookieName])) {
        $uploadCounts = json_decode($_COOKIE[$cookieName], true);
        if ($uploadCounts['date'] === $currentDate) {
            if ($uploadCounts['count'] >= $maxUploadsPerDay) {
                return '上传次数过多，请明天再试';
            }
            $uploadCounts['count']++;
        } else {
            $uploadCounts = [
                'date' => $currentDate,
                'count' => 1
            ];
        }
    } else {
        $uploadCounts = [
            'date' => $currentDate,
            'count' => 1
        ];
    }
    setcookie($cookieName, json_encode($uploadCounts), time() + 86400, "/");
    return true;
}

/**
 * 验证令牌和来源域名
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
 * 设置CORS响应头
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

    header("Access-Control-Allow-Origin: *");
    http_response_code(403);
    respondAndExit(['result' => 'error', 'code' => 403, 'message' => '你的域名未在白名单内']);
}

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
    setCorsHeaders($allowedHosts);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES)) {
        // 验证上传次数限制
        $uploadCheck = isUploadAllowed($maxUploadsPerDay);
        if ($uploadCheck !== true) {
            respondAndExit(['result' => 'error', 'message' => $uploadCheck]);
        }

        // 验证文件大小
        foreach ($_FILES as $file) {
            if ($file['size'] > $maxFileSize) {
                $maxFileSizeMB = $maxFileSize / (1024 * 1024);
                respondAndExit(['result' => 'error', 'message' => '文件大小超过限制，最大允许 ' . $maxFileSizeMB . 'MB']);
            }
        }

        $token = $_POST['token'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        validateToken($token, $referer, $allowedHosts);

        try {
            foreach ($_FILES as $file) {
                handleUploadedFile($file, $token, $referer);
            }
        } catch (Exception $fileException) {
            logMessage('文件处理错误: ' . $fileException->getMessage());
            respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件处理错误: ' . $fileException->getMessage()]);
        }
    } else {
        respondAndExit(['result' => 'error', 'code' => 204, 'message' => '无文件上传']);
    }
} catch (Exception $e) {
    logMessage('未知错误: ' . $e->getMessage());
    respondAndExit(['result' => 'error', 'code' => 500, 'message' => '发生未知错误: ' . $e->getMessage()]);
}
?>