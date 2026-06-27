<?php
ob_start();

session_start();
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/http.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/image.php';

$pdo = Database::getInstance()->getConnection();

try {
    setCorsHeaders();

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        ob_end_clean();
        http_response_code(204);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES)) {
        uploadFailExit('无文件上传');
    }

    $uploadCheck = isUploadAllowed(
        $pdo,
        getConfigInt($pdo, 'max_uploads_per_day'),
        resolveUploadUserId($pdo),
        getClientIp()
    );
    if ($uploadCheck !== true) {
        uploadFailExit($uploadCheck);
    }

    $maxFileSize = getConfigInt($pdo, 'max_file_size');
    foreach ($_FILES as $file) {
        if ($file['size'] > $maxFileSize) {
            uploadFailExit('文件大小超过限制，最大允许 ' . ($maxFileSize / (1024 * 1024)) . 'MB');
        }
    }

    validateUploadAccess($pdo);

    foreach ($_FILES as $file) {
        handleUploadedFile($file);
    }
} catch (Exception $e) {
    uploadFailExit('请求失败，请稍后重试', 200, $e->getMessage());
}
