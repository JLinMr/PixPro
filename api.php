<?php
ob_start();

require_once 'vendor/autoload.php';
require_once 'config/database.php';
require_once 'config/upload.php';

// 获取数据库配置
$db = Database::getInstance();
$mysqli = $db->getConnection();

$config = Database::getConfig($mysqli);
$siteDomain = $config['site_domain'];

// 获取配置值
$maxUploadsPerDay = getConfigValue($mysqli, 'max_uploads_per_day');
$maxFileSize = getConfigValue($mysqli, 'max_file_size');

/**
 * 从数据库获取配置值
 * @param mysqli $mysqli 数据库连接
 * @param string $key 配置键名
 * @return int 配置值
 */
function getConfigValue($mysqli, $key) {
    $stmt = $mysqli->prepare("SELECT value FROM configs WHERE `key` = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    return (int)$result->fetch_assoc()['value'];
}

/**
 * 检查是否允许上传
 * @param int $maxUploadsPerDay 每日最大上传次数
 * @return bool|string 允许上传返回true，否则返回错误信息
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
 * 验证令牌和请求来源
 */
function validateToken() {
    global $mysqli, $config;
    
    // 获取 Authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    
    // 尝试从 Authorization header 获取 Bearer token
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
    } else {
        // 如果没有 Bearer token，则从 POST 参数获取
        $token = $_POST['token'] ?? '';
    }
    
    // 验证请求来源
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $siteDomains = explode(',', $config['site_domain']);
    $refererHost = parse_url($referer, PHP_URL_HOST);
    
    // 如果有合法的请求来源，直接通过
    foreach ($siteDomains as $domain) {
        $domain = trim($domain);
        if ($refererHost === parse_url($domain, PHP_URL_HOST)) {
            return;
        }
    }
    
    // 验证 token
    if (!empty($token)) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            return;
        }
    }
    
    respondAndExit([
        'result' => 'error', 
        'code' => 403, 
        'message' => 'Token验证失败 或 域名未授权'
    ]);
}

/**
 * 设置CORS响应头
 * @param array $allowedHosts 允许的域名列表
 */
function setCorsHeaders() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

/**
 * 记录日志信息
 * @param string $message 日志信息
 */
function logMessage($message) {
    $logFile = '上传日志.txt';
    $currentTime = date('Y-m-d H:i:s');
    $logMessage = "[$currentTime] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

/**
 * 返回响应并退出脚本
 * @param array $response 响应数据
 */
function respondAndExit($response) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ob_flush();
    flush();
    exit;
}

try {
    setCorsHeaders();

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
                respondAndExit([
                    'result' => 'error', 
                    'message' => "文件大小超过限制，最大允许 {$maxFileSizeMB}MB"
                ]);
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
            respondAndExit([
                'result' => 'error',
                'code' => 500,
                'message' => '文件处理错误: ' . $fileException->getMessage()
            ]);
        }
    } else {
        respondAndExit([
            'result' => 'error',
            'code' => 204,
            'message' => '无文件上传'
        ]);
    }
} catch (Exception $e) {
    logMessage('未知错误: ' . $e->getMessage());
    respondAndExit([
        'result' => 'error',
        'code' => 500,
        'message' => '发生未知错误: ' . $e->getMessage()
    ]);
}
?>