<?php
session_start();

require_once 'image_processing.php';

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

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
$frontendDomain = $_SERVER['HTTP_HOST'];

/**
 * 验证令牌。
 *
 * @param string $token 令牌。
 * @param string $referer 来源URL。
 */
function validateToken($token, $referer) {
    global $frontendDomain, $validToken;

    if (!empty($referer) && strpos($referer, $frontendDomain) !== false) {
        // 前端上传，不验证token
    } else {
        // 第三方软件上传，验证token
        if ($token !== $validToken) {
            respondAndExit(['result' => 'error', 'code' => 403, 'message' => 'Token错误']);
        }
    }
}

/**
 * 获取客户端IP地址
 *
 * @return string 客户端IP地址
 */
function getClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'];

    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }

    // 如果 IP 地址包含多个，取第一个
    $ipList = explode(',', $ip);
    $ip = trim($ipList[0]);

    return $ip;
}

/**
 * 处理上传的文件
 *
 * @param array $file 上传的文件信息
 * @param string $token 令牌
 * @param string $referer 请求来源
 */
function handleUploadedFile($file, $token, $referer) {
    global $accessKeyId, $accessKeySecret, $endpoint, $bucket, $cdndomain, $storage, $mysqli, $protocol, $s3Region, $s3Bucket, $s3Endpoint, $s3AccessKeyId, $s3AccessKeySecret, $customUrlPrefix, $frontendDomain;

    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : NULL;
    validateToken($token, $referer);
    $uploadDir = 'i/';
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/octet-stream'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $fileMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $datePath = date('Y/m/d');
    $uploadDirWithDatePath = $uploadDir . $datePath . '/';
    if (!is_dir($uploadDirWithDatePath)) {
        if (!mkdir($uploadDirWithDatePath, 0777, true)) {
            logMessage('无法创建上传目录: ' . $uploadDirWithDatePath);
            respondAndExit(['result' => 'error', 'code' => 500, 'message' => '无法创建上传目录']);
        }
    }

    if (!in_array($fileMimeType, $allowedTypes)) {
        logMessage('不支持的文件类型: ' . $fileMimeType);
        respondAndExit(['result' => 'error', 'code' => 406, 'message' => '不支持的文件类型']);
    }

    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false && $fileMimeType !== 'image/svg+xml') {
        logMessage('文件不是有效的图片');
        respondAndExit(['result' => 'error', 'code' => 406, 'message' => '文件不是有效的图片']);
    }

    if ($fileMimeType === 'application/octet-stream') {
        $imageData = file_get_contents($file['tmp_name']);
        $image = imagecreatefromstring($imageData);
        if ($image === false) {
            logMessage('文件不是有效的图片');
            respondAndExit(['result' => 'error', 'code' => 406, 'message' => '文件不是有效的图片']);
        }
        imagedestroy($image);
    }

    $randomFileName = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $newFilePathWithoutExt = $uploadDirWithDatePath . $randomFileName;
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (empty($extension)) {
        $extension = 'webp';
    }
    $newFilePath = $newFilePathWithoutExt . '.' . $extension;
    $finalFilePath = $newFilePath;

    if (move_uploaded_file($file['tmp_name'], $newFilePath)) {
        logMessage("文件上传成功: $newFilePath");
        ini_set('memory_limit', '1024M');
        set_time_limit(300);
        $quality = isset($_POST['quality']) ? intval($_POST['quality']) : 60;

        // 如果质量设置为100，直接跳过压缩和转换步骤
        if ($quality == 100) {
            $convertSuccess = true;
        } else {
            list($convertSuccess, $finalFilePath) = processImageCompression($fileMimeType, $newFilePath, $newFilePathWithoutExt, $quality);
        }

        // 处理图片方向
        if ($fileMimeType === 'image/jpeg') {
            $exif = @exif_read_data($finalFilePath);
            if ($exif && isset($exif['Orientation'])) {
                $orientation = $exif['Orientation'];
                $image = imagecreatefromjpeg($finalFilePath);
                switch ($orientation) {
                    case 3:
                        $image = imagerotate($image, 180, 0);
                        break;
                    case 6:
                        $image = imagerotate($image, -90, 0);
                        break;
                    case 8:
                        $image = imagerotate($image, 90, 0);
                        break;
                }
                imagejpeg($image, $finalFilePath, $quality);
                imagedestroy($image);
            }
        }

        if ($fileMimeType !== 'image/svg+xml') {
            $compressedInfo = getimagesize($finalFilePath);
            if (!$compressedInfo) {
                logMessage('无法获取压缩后图片信息');
                respondAndExit(['result' => 'error', 'code' => 500, 'message' => '无法获取压缩后图片信息']);
            }
            $compressedWidth = $compressedInfo[0];
            $compressedHeight = $compressedInfo[1];
        } else {
            $compressedWidth = 100;
            $compressedHeight = 100;
        }
        $compressedSize = filesize($finalFilePath);

        $upload_ip = getClientIp();

        if ($storage === 'local') {
            handleLocalStorage($finalFilePath, $newFilePath, $uploadDirWithDatePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip);
        } else if ($storage === 'oss') {
            handleOSSUpload($finalFilePath, $newFilePath, $datePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip);
        } else if ($storage === 's3') {
            handleS3Upload($finalFilePath, $newFilePath, $datePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip);
        } else {
            logMessage('文件上传失败: ' . $file['error']);
            respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件上传失败']);
        }
    }
}

/**
 * 处理OSS上传
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 * @param string $datePath 日期路径
 * @param int $compressedSize 压缩后文件大小
 * @param int $compressedWidth 压缩后图片宽度
 * @param int $compressedHeight 压缩后图片高度
 * @param string $randomFileName 随机文件名
 * @param int $user_id 用户ID
 * @param string $upload_ip 上传IP地址
 */
function handleOSSUpload($finalFilePath, $newFilePath, $datePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip) {
    global $accessKeyId, $accessKeySecret, $endpoint, $bucket, $cdndomain, $protocol, $mysqli;

    try {
        $ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint);
        $ossFilePath = $datePath . '/' . basename($finalFilePath);
        $ossClient->uploadFile($bucket, $ossFilePath, $finalFilePath);

        deleteLocalFile($finalFilePath, $newFilePath);

        logMessage("文件上传到OSS成功: $ossFilePath");
        $fileUrl = $protocol . $cdndomain . '/' . $ossFilePath;
        insertImageRecord($fileUrl, $ossFilePath, 'oss', $compressedSize, $upload_ip, $user_id);

        respondAndExit([
            'result' => 'success',
            'code' => 200,
            'url' => $fileUrl,
            'srcName' => $randomFileName,
            'width' => $compressedWidth,
            'height' => $compressedHeight,
            'size' => $compressedSize,
            'thumb' => $fileUrl,
            'path' => $ossFilePath
        ]);
    } catch (OssException $e) {
        logMessage('文件上传到OSS失败: ' . $e->getMessage());
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件上传到OSS失败: ' . $e->getMessage()]);
    }
}

/**
 * 处理本地存储
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 * @param string $uploadDirWithDatePath 带日期路径的上传目录
 * @param int $compressedSize 压缩后文件大小
 * @param int $compressedWidth 压缩后图片宽度
 * @param int $compressedHeight 压缩后图片高度
 * @param string $randomFileName 随机文件名
 * @param int $user_id 用户ID
 * @param string $upload_ip 上传IP地址
 */
function handleLocalStorage($finalFilePath, $newFilePath, $uploadDirWithDatePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip) {
    global $protocol, $mysqli;

    logMessage("文件存储在本地: $finalFilePath");
    $fileUrl = $protocol . $_SERVER['HTTP_HOST'] . '/' . $uploadDirWithDatePath . basename($finalFilePath);
    insertImageRecord($fileUrl, $finalFilePath, 'local', $compressedSize, $upload_ip, $user_id);

    respondAndExit([
        'result' => 'success',
        'code' => 200,
        'url' => $fileUrl,
        'srcName' => $randomFileName,
        'width' => $compressedWidth,
        'height' => $compressedHeight,
        'size' => $compressedSize,
        'thumb' => $fileUrl,
        'path' => $finalFilePath
    ]);
}

/**
 * 处理S3上传并获取文件URL
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 * @param string $datePath 日期路径
 * @param int $compressedSize 压缩后文件大小
 * @param int $compressedWidth 压缩后图片宽度
 * @param int $compressedHeight 压缩后图片高度
 * @param string $randomFileName 随机文件名
 * @param int $user_id 用户ID
 * @param string $upload_ip 上传IP地址
 */
function handleS3Upload($finalFilePath, $newFilePath, $datePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip) {
    global $s3Region, $s3Bucket, $s3Endpoint, $s3AccessKeyId, $s3AccessKeySecret, $customUrlPrefix, $mysqli;

    try {
        $s3Client = new S3Client([
            'region' => $s3Region,
            'version' => 'latest',
            'endpoint' => $s3Endpoint,
            'credentials' => [
                'key' => $s3AccessKeyId,
                'secret' => $s3AccessKeySecret,
            ],
        ]);
        $s3FilePath = $datePath . '/' .  basename($finalFilePath);
        $result = $s3Client->putObject([
            'Bucket' => $s3Bucket,
            'Key' => $s3FilePath,
            'SourceFile' => $finalFilePath,
            'ACL' => 'public-read',
        ]);

        deleteLocalFile($finalFilePath, $newFilePath);

        logMessage("文件上传到S3成功: $s3FilePath");

        // 检查 customUrlPrefix 是否有内容
        if (empty($customUrlPrefix)) {
            $fileUrl = $result['ObjectURL'];
        } else {
            $fileUrl = $customUrlPrefix . '/' . $s3FilePath;
        }

        insertImageRecord($fileUrl, $s3FilePath, 's3', $compressedSize, $upload_ip, $user_id);

        respondAndExit([
            'result' => 'success',
            'code' => 200,
            'url' => $fileUrl,
            'srcName' => $randomFileName,
            'width' => $compressedWidth,
            'height' => $compressedHeight,
            'size' => $compressedSize,
            'thumb' => $fileUrl,
            'path' => $s3FilePath
        ]);
    } catch (S3Exception $e) {
        logMessage('文件上传到S3失败: ' . $e->getMessage());
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件上传到S3失败: ' . $e->getMessage()]);
    }
}

/**
 * 删除本地文件
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 */
function deleteLocalFile($finalFilePath, $newFilePath) {
    if (file_exists($finalFilePath)) {
        unlink($finalFilePath);
        if ($finalFilePath !== $newFilePath) {
            unlink($newFilePath);
        }
        logMessage("本地文件已删除: {$finalFilePath}");
    } else {
        logMessage("尝试删除不存在的文件: {$finalFilePath}");
    }
}

/**
 * 插入图片记录到数据库
 *
 * @param string $fileUrl 文件URL
 * @param string $path 文件路径
 * @param string $storage 存储类型
 * @param int $size 文件大小
 * @param string $upload_ip 上传IP地址
 * @param int $user_id 用户ID
 */
function insertImageRecord($fileUrl, $path, $storage, $size, $upload_ip, $user_id) {
    global $mysqli;

    $stmt = $mysqli->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssdsd", $fileUrl, $path, $storage, $size, $upload_ip, $user_id);
    $stmt->execute();
    $stmt->close();
}