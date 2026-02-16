<?php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/storage.php';

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Upyun\Upyun;
use Upyun\Config;

// ============================================
// 工具函数
// ============================================

/**
 * 获取客户端IP地址
 */
function getClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'];
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    return trim(explode(',', $ip)[0]);
}

/**
 * 根据存储类型生成文件URL
 */
function generateFileUrl($storage, $config, $filePath, $s3Result = null) {
    $urlPrefix = !empty($config['url_prefix']) ? rtrim($config['url_prefix'], '/') : '';
    
    $addProtocol = function($domain, $defaultProtocol = 'https://') {
        return preg_match('/^https?:\/\//', $domain) ? $domain : $defaultProtocol . $domain;
    };
    
    switch ($storage) {
        case 'local':
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $domain = $config['local_cdn_domain'] ?: $_SERVER['HTTP_HOST'];
            $url = $addProtocol($domain, $protocol) . '/' . $filePath;
            break;
        case 'oss':
            $domain = $config['oss_cdn_domain'] ?: $config['oss_endpoint'];
            $url = $addProtocol($domain) . '/' . $filePath;
            break;
        case 's3':
            if ($config['s3_cdn_domain']) {
                $url = $addProtocol($config['s3_cdn_domain']) . '/' . $filePath;
            } else if ($s3Result && isset($s3Result['ObjectURL'])) {
                $url = $s3Result['ObjectURL'];
            } else {
                $url = $addProtocol($config['s3_endpoint']) . '/' . $filePath;
            }
            break;
        case 'upyun':
            if (empty($config['upyun_cdn_domain'])) {
                throw new Exception("又拍云必须配置CDN域名");
            }
            $url = $addProtocol($config['upyun_cdn_domain']) . '/' . $filePath;
            break;
        default:
            throw new Exception("未知的存储类型: {$storage}");
    }
    
    return $urlPrefix ? $urlPrefix . '/' . preg_replace('/^https?:\/\//', '', $url) : $url;
}

/**
 * 生成上传响应数据
 */
function generateUploadResponse($fileUrl, $filePath, $finalFilePath, $size, $width, $height, $message = '', $isError = false) {
    respondAndExit($isError ? [
        'result' => 'error',
        'code' => 500,
        'message' => $message
    ] : [
        'result' => 'success',
        'code' => 200,
        'status' => true,
        'name' => basename($finalFilePath),
        'data' => [
            'url' => $fileUrl,
            'name' => basename($finalFilePath),
            'width' => $width,
            'height' => $height,
            'size' => $size,
            'path' => $filePath
        ],
        'url' => $fileUrl
    ]);
}

// ============================================
// 图片处理函数
// ============================================

/**
 * 修正JPEG图片方向
 */
function correctJpegOrientation($filepath, $quality) {
    $exif = @exif_read_data($filepath);
    if (!$exif || !isset($exif['Orientation'])) return;

    $image = imagecreatefromjpeg($filepath);
    switch ($exif['Orientation']) {
        case 3: $image = imagerotate($image, 180, 0); break;
        case 6: $image = imagerotate($image, -90, 0); break;
        case 8: $image = imagerotate($image, 90, 0); break;
    }
    imagejpeg($image, $filepath, $quality);
    imagedestroy($image);
}

/**
 * 转换图片为WebP格式
 */
function convertImageToWebp($source, $destination, $quality = 60) {
    if (!file_exists($source)) {
        logMessage("错误: 源文件不存在: $source");
        return false;
    }
    
    $maxWidth = 2500;
    $maxHeight = 1600;
    $info = getimagesize($source);
    $mimeType = $info['mime'];

    try {
        if ($mimeType === 'image/png') {
            if (!class_exists('Imagick')) return 'imagick_not_installed';
            
            $image = new Imagick($source);
            if ($image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor(new ImagickPixel('transparent'));
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality($quality);

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ($width > $maxWidth || $height > $maxHeight) {
                $ratio = min($maxWidth / $width, $maxHeight / $height);
                $image->resizeImage(round($width * $ratio), round($height * $ratio), Imagick::FILTER_LANCZOS, 1);
            }

            $result = $image->writeImage($destination);
            $image->clear();
            $image->destroy();
            return $result;
        } else if ($mimeType === 'image/jpeg') {
            if (!extension_loaded('gd')) return 'gd_not_installed';
            
            $gdInfo = gd_info();
            if (!isset($gdInfo['WebP Support']) || !$gdInfo['WebP Support']) return 'gd_no_webp_support';
            
            $image = imagecreatefromjpeg($source);
            if (!$image) return 'gd_create_failed';
            
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > $maxWidth || $height > $maxHeight) {
                $ratio = min($maxWidth / $width, $maxHeight / $height);
                $newWidth = round($width * $ratio);
                $newHeight = round($height * $ratio);
                $newImage = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $newImage;
            }

            $result = imagewebp($image, $destination, $quality);
            imagedestroy($image);
            gc_collect_cycles();
            return $result;
        }
        return 'unsupported_mime_type';
    } catch (Exception $e) {
        logMessage('图片转换失败: ' . $e->getMessage());
        return false;
    }
}

/**
 * 处理图片压缩和格式转换
 */
function processImageCompression($fileMimeType, $newFilePath, $newFilePathWithoutExt, $quality, $outputFormat) {
    $finalFilePath = $newFilePath;
    
    if ($quality != 100 && !in_array($fileMimeType, ['image/svg+xml', 'image/webp'])) {
        $convertResult = convertImageToWebp($newFilePath, $newFilePathWithoutExt . '.webp', $quality);
        
        if ($convertResult === true) {
            $webpPath = $newFilePathWithoutExt . '.webp';
            if (file_exists($webpPath) && filesize($webpPath) > 0) {
                $finalFilePath = $webpPath;
                unlink($newFilePath);
            }
        } else if (is_string($convertResult)) {
            $errorMessages = [
                'imagick_not_installed' => 'Imagick扩展未安装，无法处理PNG图片',
                'gd_not_installed' => 'GD扩展未安装',
                'gd_no_webp_support' => 'GD扩展不支持webp格式',
                'gd_create_failed' => '无法创建图像资源',
                'unsupported_mime_type' => '不支持的图片格式'
            ];
            respondAndExit(['result' => 'error', 'code' => 500, 'message' => $errorMessages[$convertResult] ?? '图片转换失败']);
        }
    }
    
    if ($outputFormat !== 'webp' || ($quality == 100 && $fileMimeType !== 'image/svg+xml')) {
        $currentExt = strtolower(pathinfo($finalFilePath, PATHINFO_EXTENSION));
        if ($currentExt !== $outputFormat) {
            $newFinalFilePath = $outputFormat === 'original' 
                ? $newFilePathWithoutExt . '.' . strtolower(pathinfo($newFilePath, PATHINFO_EXTENSION))
                : $newFilePathWithoutExt . '.' . $outputFormat;
            rename($finalFilePath, $newFinalFilePath);
            $finalFilePath = $newFinalFilePath;
        }
    }
    
    return $finalFilePath;
}

/**
 * 获取图片尺寸信息
 */
function getImageDimensions($finalFilePath, $fileMimeType) {
    if ($fileMimeType === 'image/svg+xml') return ['width' => 100, 'height' => 100];
    
    try {
        if (class_exists('Imagick')) {
            $image = new Imagick($finalFilePath);
            $dimensions = ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
            $image->destroy();
            return $dimensions;
        }
        
        $dimensions = getimagesize($finalFilePath);
        if ($dimensions) return ['width' => $dimensions[0], 'height' => $dimensions[1]];
        
        $image = imagecreatefromstring(file_get_contents($finalFilePath));
        if ($image) {
            $dimensions = ['width' => imagesx($image), 'height' => imagesy($image)];
            imagedestroy($image);
            return $dimensions;
        }
    } catch (Exception $e) {
        logMessage('获取图片尺寸失败: ' . $e->getMessage());
    }
    
    return ['width' => 0, 'height' => 0];
}

// ============================================
// 文件验证函数
// ============================================

/**
 * 检测并修正文件MIME类型
 */
function detectMimeType($file) {
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // 根据扩展名判断MIME类型
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml'
    ];
    
    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    return [$mimeType, $extension];
}

/**
 * 验证文件类型和有效性
 */
function validateFile($file) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/octet-stream'];
    list($mimeType, $extension) = detectMimeType($file);
    
    if (!in_array($mimeType, $allowedTypes)) {
        respondAndExit(['result' => 'error', 'code' => 406, 'message' => '不支持的文件类型']);
    }
    
    if ($mimeType !== 'image/svg+xml') {
        $isValidImage = ($mimeType === 'application/octet-stream') 
            ? imagecreatefromstring(file_get_contents($file['tmp_name'])) !== false
            : getimagesize($file['tmp_name']) !== false;
            
        if (!$isValidImage) {
            respondAndExit(['result' => 'error', 'code' => 406, 'message' => '文件不是有效的图片']);
        }
    }
    
    return [$mimeType, $extension];
}

// ============================================
// 主处理函数
// ============================================

/**
 * 处理上传的文件
 */
function handleUploadedFile($file, $token, $referer) {
    global $mysqli;
    
    $config = Database::getConfig($mysqli);
    $storage = $config['storage'];
    $user_id = $_SESSION['user_id'] ?? NULL;
    $quality = intval($_POST['quality'] ?? 60);
    
    list($mimeType, $extension) = validateFile($file);
    
    $datePath = 'i/' . date('Y/m/d');
    if (!is_dir($datePath) && !mkdir($datePath, 0777, true)) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '无法创建上传目录']);
    }
    
    $randomFileName = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $ext = $extension ?: (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'][$mimeType] ?? 'jpg');
    $newFilePath = $datePath . '/' . $randomFileName . '.' . $ext;
    
    if (!move_uploaded_file($file['tmp_name'], $newFilePath)) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件上传失败']);
    }
    
    ini_set('memory_limit', '1024M');
    set_time_limit(300);
    
    $finalFilePath = processImageCompression($mimeType, $newFilePath, $datePath . '/' . $randomFileName, $quality, $config['output_format'] ?? 'webp');
    if ($mimeType === 'image/jpeg') correctJpegOrientation($finalFilePath, $quality);
    
    $dimensions = getImageDimensions($finalFilePath, $mimeType);
    $fileSize = filesize($finalFilePath);
    $filePath = $datePath . '/' . basename($finalFilePath);
    
    try {
        $result = StorageHelper::upload($storage, $config, $finalFilePath, $filePath);
        
        if ($storage !== 'local') {
            if (file_exists($finalFilePath)) unlink($finalFilePath);
            if ($finalFilePath !== $newFilePath && file_exists($newFilePath)) unlink($newFilePath);
        }
        
        $fileUrl = generateFileUrl($storage, $config, $filePath, $result);
        $storagePath = ($storage === 'local') ? $finalFilePath : $filePath;
        
        $stmt = $mysqli->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssdsd", $fileUrl, $storagePath, $storage, $fileSize, getClientIp(), $user_id);
        $stmt->execute();
        
        // 记录上传成功日志
        $clientIp = getClientIp();
        logMessage("上传成功 | IP: {$clientIp} | 存储: {$storage} | URL: {$fileUrl}");
        
        generateUploadResponse($fileUrl, $storagePath, $finalFilePath, $fileSize, $dimensions['width'], $dimensions['height']);
    } catch (Exception $e) {
        // 记录上传失败日志
        $clientIp = getClientIp();
        $errorMsg = $e->getMessage();
        logMessage("上传失败 | IP: {$clientIp} | 存储: {$storage} | 错误: {$errorMsg}");
        
        generateUploadResponse('', '', '', 0, 0, 0, "文件上传到{$storage}失败: " . $errorMsg, true);
    }
}
