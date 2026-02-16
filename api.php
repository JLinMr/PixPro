<?php
ob_start();

require_once 'vendor/autoload.php';
require_once 'config/database.php';
require_once 'config/upload.php';

// 初始化
$db = Database::getInstance();
$mysqli = $db->getConnection();
$config = Database::getConfig($mysqli);

// ============================================
// 工具函数
// ============================================

/**
 * 从数据库获取配置值
 */
function getConfigValue($mysqli, $key) {
    $stmt = $mysqli->prepare("SELECT value FROM configs WHERE `key` = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['value'];
}

/**
 * 检查域名是否被允许
 */
function isDomainAllowed($host) {
    global $config;
    
    if (empty($host)) return false;
    
    $siteDomains = array_map('trim', explode(',', $config['site_domain']));
    
    // 通配符允许所有域名
    if (in_array('*', $siteDomains)) return true;
    
    // 检查域名是否匹配
    foreach ($siteDomains as $domain) {
        if ($host === parse_url($domain, PHP_URL_HOST)) {
            return true;
        }
    }
    
    return false;
}

/**
 * 记录日志
 */
function logMessage($message) {
    file_put_contents('上传日志.txt', "[" . date('Y-m-d H:i:s') . "] $message" . PHP_EOL, FILE_APPEND);
}

/**
 * 返回JSON响应并退出
 */
function respondAndExit($response) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================
// 上传限制
// ============================================

/**
 * 检查上传次数限制
 */
function isUploadAllowed($maxUploadsPerDay) {
    if ($maxUploadsPerDay <= 0) return true;
    
    $uploadDir = 'i/.upload_limits/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $clientIp = getClientIp();
    $currentDate = date('Y-m-d');
    $limitFile = $uploadDir . md5($clientIp) . '.json';
    
    // 读取或初始化记录
    $uploadData = file_exists($limitFile) 
        ? json_decode(file_get_contents($limitFile), true) ?: []
        : [];
    
    // 新的一天重置计数
    if (!isset($uploadData['date']) || $uploadData['date'] !== $currentDate) {
        $uploadData = ['date' => $currentDate, 'count' => 0, 'ip' => $clientIp];
    }
    
    // 检查限制
    if ($uploadData['count'] >= $maxUploadsPerDay) {
        return "上传次数已达今日限制（{$maxUploadsPerDay}次），请明天再试";
    }
    
    // 更新计数
    $uploadData['count']++;
    $uploadData['last_upload'] = date('Y-m-d H:i:s');
    file_put_contents($limitFile, json_encode($uploadData, JSON_PRETTY_PRINT));
    
    // 10%概率清理过期文件
    if (rand(1, 10) === 1) {
        foreach (glob($uploadDir . '*.json') as $file) {
            $data = json_decode(@file_get_contents($file), true);
            if (isset($data['date']) && $data['date'] !== $currentDate) {
                @unlink($file);
            }
        }
    }
    
    return true;
}

// ============================================
// 验证函数
// ============================================

/**
 * 验证Token和请求来源
 */
function validateToken() {
    global $mysqli;
    
    // 获取Token
    $token = '';
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $matches)) {
        $token = $matches[1];
    } else {
        $token = $_POST['token'] ?? '';
    }
    
    // 验证域名
    $refererHost = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
    if (isDomainAllowed($refererHost)) return;
    
    // 验证Token
    if (!empty($token)) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) return;
    }
    
    respondAndExit(['result' => 'error', 'code' => 403, 'message' => 'Token验证失败 或 域名未授权']);
}

/**
 * 设置CORS响应头
 */
function setCorsHeaders() {
    global $config;
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $siteDomains = array_map('trim', explode(',', $config['site_domain']));
    
    if (in_array('*', $siteDomains)) {
        header("Access-Control-Allow-Origin: *");
    } else if (!empty($origin) && isDomainAllowed(parse_url($origin, PHP_URL_HOST))) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
    } else {
        header("Access-Control-Allow-Origin: null");
    }
    
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// ============================================
// 主流程
// ============================================

try {
    setCorsHeaders();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES)) {
        respondAndExit(['result' => 'error', 'code' => 204, 'message' => '无文件上传']);
    }
    
    // 验证上传次数
    $maxUploadsPerDay = getConfigValue($mysqli, 'max_uploads_per_day');
    $uploadCheck = isUploadAllowed($maxUploadsPerDay);
    if ($uploadCheck !== true) {
        respondAndExit(['result' => 'error', 'message' => $uploadCheck]);
    }
    
    // 验证文件大小
    $maxFileSize = getConfigValue($mysqli, 'max_file_size');
    foreach ($_FILES as $file) {
        if ($file['size'] > $maxFileSize) {
            $maxFileSizeMB = $maxFileSize / (1024 * 1024);
            respondAndExit(['result' => 'error', 'message' => "文件大小超过限制，最大允许 {$maxFileSizeMB}MB"]);
        }
    }
    
    // 验证权限
    validateToken();
    
    // 处理上传
    foreach ($_FILES as $file) {
        handleUploadedFile($file, $_POST['token'] ?? '', $_SERVER['HTTP_REFERER'] ?? '');
    }
    
} catch (Exception $e) {
    logMessage('错误: ' . $e->getMessage());
    respondAndExit(['result' => 'error', 'code' => 500, 'message' => '发生错误: ' . $e->getMessage()]);
}
?>
