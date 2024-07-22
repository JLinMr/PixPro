<?php
session_start();

require_once 'image_processing.php';

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

function handleUploadedFile($file, $token, $referer) {
    global $accessKeyId, $accessKeySecret, $endpoint, $bucket, $cdndomain, $storage, $mysqli, $protocol, $s3Region, $s3Bucket, $s3Endpoint, $s3AccessKeyId, $s3AccessKeySecret, $customUrlPrefix, $frontendDomain;

    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : NULL;

    // 判断是否需要验证token
    if (!empty($referer) && strpos($referer, $frontendDomain) !== false) {
        // 前端上传，不验证token
    } else {
        // 第三方软件上传，验证token
        if (!isValidToken($token)) {
            respondAndExit(['result' => 'error', 'code' => 403, 'message' => 'Token错误']);
        }
    }

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

        $convertSuccess = true;

        if ($fileMimeType === 'image/png') {
            $convertSuccess = convertPngWithImagick($newFilePath, $newFilePathWithoutExt . '.webp', $quality);
            if ($convertSuccess) {
                $finalFilePath = $newFilePathWithoutExt . '.webp';
                unlink($newFilePath);
            }
        } elseif ($fileMimeType === 'image/gif') {
            $convertSuccess = convertGifToWebp($newFilePath, $newFilePathWithoutExt . '.webp', $quality);
            if ($convertSuccess) {
                $finalFilePath = $newFilePathWithoutExt . '.webp';
                unlink($newFilePath);
            }
        } elseif ($fileMimeType !== 'image/webp' && $fileMimeType !== 'image/svg+xml') {
            $convertSuccess = convertToWebp($newFilePath, $newFilePathWithoutExt . '.webp', $quality);
            if ($convertSuccess) {
                $finalFilePath = $newFilePathWithoutExt . '.webp';
                unlink($newFilePath);
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

        // 获取客户端IP地址
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

        $upload_ip = getClientIp();

        if ($storage === 'oss') {
            try {
                $ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint);
                $ossFilePath = $datePath . '/' . basename($finalFilePath);
                $ossClient->uploadFile($bucket, $ossFilePath, $finalFilePath);

                if (file_exists($finalFilePath)) {
                    unlink($finalFilePath);
                    if ($finalFilePath !== $newFilePath) {
                        unlink($newFilePath);
                    }
                    logMessage("本地文件已删除: {$finalFilePath}");
                } else {
                    logMessage("尝试删除不存在的文件: {$finalFilePath}");
                }

                logMessage("文件上传到OSS成功: $ossFilePath");
                $fileUrl = $protocol . $cdndomain . '/' . $ossFilePath;
                $stmt = $mysqli->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                $storageType = 'oss';
                $stmt->bind_param("sssdsd", $fileUrl, $ossFilePath, $storageType, $compressedSize, $upload_ip, $user_id);
                $stmt->execute();
                $stmt->close();

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
        } else if ($storage === 'local') {
            logMessage("文件存储在本地: $finalFilePath");
            $fileUrl = $protocol . $_SERVER['HTTP_HOST'] . '/' . $uploadDirWithDatePath . basename($finalFilePath);
            $stmt = $mysqli->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id) VALUES (?, ?, ?, ?, ?, ?)");
            $storageType = 'local';
            $stmt->bind_param("sssdsd", $fileUrl, $finalFilePath, $storageType, $compressedSize, $upload_ip, $user_id);
            $stmt->execute();
            $stmt->close();

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
        } else if ($storage === 's3') {
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

                if (file_exists($finalFilePath)) {
                    unlink($finalFilePath);
                    if ($finalFilePath !== $newFilePath) {
                        unlink($newFilePath);
                    }
                    logMessage("本地文件已删除: {$finalFilePath}");
                } else {
                    logMessage("尝试删除不存在的文件: {$finalFilePath}");
                }

                logMessage("文件上传到S3成功: $s3FilePath");

                // 检查 customUrlPrefix 是否有内容
                if (empty($customUrlPrefix)) {
                    $fileUrl = $result['ObjectURL'];
                } else {
                    $fileUrl = $customUrlPrefix . '/' . $s3FilePath;
                }

                $stmt = $mysqli->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                $storageType = 's3';
                $stmt->bind_param("sssdsd", $fileUrl, $s3FilePath, $storageType, $compressedSize, $upload_ip, $user_id);
                $stmt->execute();
                $stmt->close();

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
        } else {
            logMessage('文件上传失败: ' . $file['error']);
            respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件上传失败']);
        }
    }
}
?>