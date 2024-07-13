<?php
session_start();

// 设置参数
$maxUploadsPerDay = 50; // 每天最多上传50次
$maxFileSize = 5 * 1024 * 1024; // 文件大小限制 5MB 修改这里同步修改 script.js

function isUploadAllowed($maxUploadsPerDay) {
    // 获取当前用户的 IP 地址和 UA
    $ipAddress = $_SERVER['REMOTE_ADDR'];
    $userAgent = $_SERVER['HTTP_USER_AGENT'];

    // 构建唯一标识符
    $userIdentifier = $ipAddress . '_' . $userAgent;

    // 初始化上传次数和最后上传日期
    if (!isset($_SESSION[$userIdentifier])) {
        $_SESSION[$userIdentifier] = [
            'upload_count' => 0,
            'last_upload_date' => date('Y-m-d')
        ];
    }

    // 检查上传日期是否为今天
    if ($_SESSION[$userIdentifier]['last_upload_date'] !== date('Y-m-d')) {
        // 如果是新的一天，重置上传次数和日期
        $_SESSION[$userIdentifier]['upload_count'] = 0;
        $_SESSION[$userIdentifier]['last_upload_date'] = date('Y-m-d');
    }

    // 检查上传次数是否超过限制
    if ($_SESSION[$userIdentifier]['upload_count'] >= $maxUploadsPerDay) {
        return '上传次数过多，请明天再试';
    }

    // 更新上传次数
    $_SESSION[$userIdentifier]['upload_count']++;

    return true;
}

$uploadCheck = isUploadAllowed($maxUploadsPerDay);
if ($uploadCheck !== true) {
    echo json_encode(['error' => $uploadCheck]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $file = $_FILES['image'];

    if ($file['size'] > $maxFileSize) {
        // 获取 maxFileSize 的值并格式化为 MB
        $maxFileSizeMB = $maxFileSize / (1024 * 1024);
        echo json_encode(['error' => '文件大小超过限制，最大允许 ' . $maxFileSizeMB . 'MB']);
        exit();
    }

    echo json_encode(['success' => '文件上传成功']);
} else {
    echo json_encode(['error' => '无效的请求']);
}
?>