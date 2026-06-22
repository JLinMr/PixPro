<?php

function jsonExit(array $data, int $code = 200): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureCsrfToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validateCsrfToken(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals(ensureCsrfToken(), $token)) {
        jsonExit(['success' => false, 'message' => 'CSRF token 无效'], 403);
    }
}

function logMessage($message) {
    file_put_contents(
        PIXPRO_ROOT . '/上传日志.txt',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function getConfigInt($pdo, $key, $default = 0) {
    $value = Database::getConfig($pdo, $key);
    return $value !== null ? (int)$value : $default;
}

function isUploadAllowed($pdo, $maxUploadsPerDay, $userId, $clientIp) {
    if ($maxUploadsPerDay <= 0) {
        return true;
    }

    $sql = $userId
        ? "SELECT COUNT(*) FROM images WHERE user_id = ? AND created_at >= datetime('now', 'start of day', 'localtime') AND created_at < datetime('now', 'start of day', '+1 day', 'localtime')"
        : "SELECT COUNT(*) FROM images WHERE upload_ip = ? AND user_id IS NULL AND created_at >= datetime('now', 'start of day', 'localtime') AND created_at < datetime('now', 'start of day', '+1 day', 'localtime')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId ?: $clientIp]);

    if ((int)$stmt->fetchColumn() >= $maxUploadsPerDay) {
        return "上传次数已达今日限制（{$maxUploadsPerDay}次），请明天再试";
    }

    return true;
}

function getRequestToken(): string {
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $matches)) {
        return trim($matches[1]);
    }

    return trim($_POST['token'] ?? '');
}

function getUserIdByToken($pdo, $token) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

function validateUploadAccess($pdo) {
    $config = Database::getConfig($pdo);
    $loginRestriction = filter_var($config['login_restriction'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $token = getRequestToken();

    if ($token !== '') {
        if (getUserIdByToken($pdo, $token)) {
            return;
        }
        jsonExit(['status' => false, 'message' => 'Token 验证失败', 'data' => []]);
    }

    if ($loginRestriction && empty($_SESSION['loggedin'])) {
        jsonExit(['status' => false, 'message' => '请先登录后再上传', 'data' => []]);
    }
}

function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

function getClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $trustedProxies = array_filter(array_map('trim', explode(',', $_ENV['TRUSTED_PROXIES'] ?? '')));

    if ($trustedProxies && in_array($ip, $trustedProxies, true)) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '';
        if ($forwarded !== '') {
            $ip = $forwarded;
        }
    }

    return trim(explode(',', $ip)[0]);
}

function resolveUploadUserId($pdo) {
    if (!empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }

    $token = getRequestToken();
    return $token !== '' ? getUserIdByToken($pdo, $token) : null;
}

function generateFileUrl($storage, $config, $filePath, $s3Result = null) {
    if ($storage === 'local') {
        $domain = $config['local_cdn_domain'] ?: ('https://' . $_SERVER['HTTP_HOST']);
    } elseif ($storage === 'oss') {
        $domain = $config['oss_cdn_domain'] ?: $config['oss_endpoint'];
    } elseif ($storage === 's3') {
        if ($config['s3_cdn_domain']) {
            $domain = $config['s3_cdn_domain'];
        } elseif (isset($s3Result['ObjectURL'])) {
            $domain = $s3Result['ObjectURL'];
        } else {
            $domain = $config['s3_endpoint'];
        }
    } elseif ($storage === 'upyun') {
        if (empty($config['upyun_cdn_domain'])) {
            throw new Exception('又拍云必须配置CDN域名');
        }
        $domain = $config['upyun_cdn_domain'];
    } else {
        throw new Exception("未知的存储类型: {$storage}");
    }

    $url = (isset($s3Result['ObjectURL']) && !$config['s3_cdn_domain'])
        ? $domain
        : $domain . '/' . $filePath;

    if (!empty($config['url_prefix'])) {
        return $config['url_prefix'] . '/' . preg_replace('/^https?:\/\//', '', $url);
    }

    return $url;
}

function generateUploadResponse($fileUrl, $filePath, $finalFilePath, $size, $width, $height, $message = '', $isError = false) {
    $fileName = basename($finalFilePath);
    jsonExit($isError ? [
        'status' => false,
        'message' => $message,
        'data' => [],
    ] : [
        'status' => true,
        'message' => '',
        'name' => $fileName,
        'data' => [
            'url' => $fileUrl,
            'name' => $fileName,
            'width' => $width,
            'height' => $height,
            'size' => $size,
            'path' => $filePath,
        ],
        'url' => $fileUrl,
    ]);
}
