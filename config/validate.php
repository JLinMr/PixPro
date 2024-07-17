<?php
// 设置参数
$maxUploadsPerDay = 50; // 每天最多上传50次
$maxFileSize = 5 * 1024 * 1024; // 文件大小限制 5MB 修改这里同步修改 script.js

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
    // 设置 Cookie，过期时间为一天
    setcookie($cookieName, json_encode($uploadCounts), time() + 86400, "/");

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
        $maxFileSizeMB = $maxFileSize / (1024 * 1024);
        echo json_encode(['error' => '文件大小超过限制，最大允许 ' . $maxFileSizeMB . 'MB']);
        exit();
    }

    echo json_encode(['success' => '文件上传成功']);
} else {
    echo json_encode(['error' => '无效的请求']);
}
?>