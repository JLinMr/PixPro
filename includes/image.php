<?php

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/storage.php';

function correctJpegOrientation($filepath, $quality) {
    $exif = @exif_read_data($filepath);
    if (!$exif || !isset($exif['Orientation'])) {
        return;
    }

    $image = imagecreatefromjpeg($filepath);
    switch ($exif['Orientation']) {
        case 3: $image = imagerotate($image, 180, 0); break;
        case 6: $image = imagerotate($image, -90, 0); break;
        case 8: $image = imagerotate($image, 90, 0); break;
    }
    imagejpeg($image, $filepath, $quality);
    imagedestroy($image);
}

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
            if (!class_exists('Imagick')) {
                return 'imagick_not_installed';
            }

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
        }

        if ($mimeType === 'image/jpeg') {
            if (!extension_loaded('gd')) {
                return 'gd_not_installed';
            }

            $gdInfo = gd_info();
            if (!isset($gdInfo['WebP Support']) || !$gdInfo['WebP Support']) {
                return 'gd_no_webp_support';
            }

            $image = imagecreatefromjpeg($source);
            if (!$image) {
                return 'gd_create_failed';
            }

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

function processImageCompression($fileMimeType, $newFilePath, $newFilePathWithoutExt, $quality, $outputFormat) {
    $finalFilePath = $newFilePath;

    if ($fileMimeType !== 'image/webp' && $outputFormat === 'webp') {
        $convertResult = convertImageToWebp($newFilePath, $newFilePathWithoutExt . '.webp', $quality);

        if ($convertResult === true) {
            $webpPath = $newFilePathWithoutExt . '.webp';
            if (file_exists($webpPath) && filesize($webpPath) > 0) {
                $finalFilePath = $webpPath;
                unlink($newFilePath);
            }
        } elseif (is_string($convertResult)) {
            $errorMessages = [
                'imagick_not_installed' => 'Imagick扩展未安装，无法处理PNG图片',
                'gd_not_installed' => 'GD扩展未安装',
                'gd_no_webp_support' => 'GD扩展不支持webp格式',
                'gd_create_failed' => '无法创建图像资源',
                'unsupported_mime_type' => '不支持的图片格式',
            ];
            jsonExit(['result' => 'error', 'code' => 500, 'message' => $errorMessages[$convertResult] ?? '图片转换失败']);
        }
    }

    if ($outputFormat !== 'webp' && $outputFormat !== 'original') {
        $currentExt = strtolower(pathinfo($finalFilePath, PATHINFO_EXTENSION));
        if ($currentExt !== $outputFormat) {
            $newFinalFilePath = $newFilePathWithoutExt . '.' . $outputFormat;
            rename($finalFilePath, $newFinalFilePath);
            $finalFilePath = $newFinalFilePath;
        }
    } elseif ($outputFormat === 'original') {
        $currentExt = strtolower(pathinfo($finalFilePath, PATHINFO_EXTENSION));
        $originalExt = strtolower(pathinfo($newFilePath, PATHINFO_EXTENSION));
        if ($currentExt !== $originalExt) {
            $newFinalFilePath = $newFilePathWithoutExt . '.' . $originalExt;
            if (file_exists($finalFilePath)) {
                rename($finalFilePath, $newFinalFilePath);
                $finalFilePath = $newFinalFilePath;
            }
        }
    }

    return $finalFilePath;
}

function getImageDimensions($finalFilePath) {
    try {
        if (class_exists('Imagick')) {
            $image = new Imagick($finalFilePath);
            $dimensions = ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
            $image->destroy();
            return $dimensions;
        }

        $dimensions = getimagesize($finalFilePath);
        if ($dimensions) {
            return ['width' => $dimensions[0], 'height' => $dimensions[1]];
        }

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

function detectMimeType($file) {
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $extensionMimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    $allowedTypes = array_values($extensionMimeMap);
    $detectedMime = null;

    if (function_exists('finfo_open') && is_uploaded_file($file['tmp_name'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $rawMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if ($rawMime) {
                $detectedMime = strtolower(explode(';', $rawMime)[0]);
                if (in_array($detectedMime, ['image/jpg', 'image/pjpeg'], true)) {
                    $detectedMime = 'image/jpeg';
                }
            }
        }
    }

    if ($extension === 'svg' || $extension === 'svgz' || $detectedMime === 'image/svg+xml') {
        return ['image/svg+xml', $extension];
    }

    if ($detectedMime && in_array($detectedMime, $allowedTypes, true)) {
        $mimeType = $detectedMime;
    } elseif (isset($extensionMimeMap[$extension])) {
        $mimeType = $extensionMimeMap[$extension];
    } else {
        $mimeType = 'application/octet-stream';
    }

    return [$mimeType, $extension];
}

function validateFile($file) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/octet-stream'];
    [$mimeType, $extension] = detectMimeType($file);

    if ($mimeType === 'image/svg+xml' || in_array($extension, ['svg', 'svgz'], true)) {
        jsonExit(['result' => 'error', 'code' => 406, 'message' => '不支持 SVG 格式']);
    }

    if (!in_array($mimeType, $allowedTypes)) {
        jsonExit(['result' => 'error', 'code' => 406, 'message' => '不支持的文件类型']);
    }

    $isValidImage = ($mimeType === 'application/octet-stream')
        ? imagecreatefromstring(file_get_contents($file['tmp_name'])) !== false
        : getimagesize($file['tmp_name']) !== false;

    if (!$isValidImage) {
        jsonExit(['result' => 'error', 'code' => 406, 'message' => '文件不是有效的图片']);
    }

    return [$mimeType, $extension];
}

function handleUploadedFile($file) {
    global $pdo;

    $config = Database::getConfig($pdo);
    $storage = $config['storage'];
    $user_id = resolveUploadUserId($pdo);
    $quality = intval($_POST['quality'] ?? 60);

    [$mimeType, $extension] = validateFile($file);

    $datePath = 'i/' . date('Y/m/d');
    if (!is_dir($datePath) && !mkdir($datePath, 0755, true)) {
        jsonExit(['result' => 'error', 'code' => 500, 'message' => '无法创建上传目录']);
    }

    $randomFileName = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $ext = $extension ?: (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mimeType] ?? 'jpg');
    $newFilePath = $datePath . '/' . $randomFileName . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $newFilePath)) {
        jsonExit(['result' => 'error', 'code' => 500, 'message' => '文件上传失败']);
    }

    ini_set('memory_limit', '1024M');
    set_time_limit(300);

    if ($mimeType === 'image/jpeg') {
        correctJpegOrientation($newFilePath, $quality);
    }

    $finalFilePath = processImageCompression(
        $mimeType,
        $newFilePath,
        $datePath . '/' . $randomFileName,
        $quality,
        $config['output_format'] ?? 'webp'
    );

    $dimensions = getImageDimensions($finalFilePath);
    $fileSize = filesize($finalFilePath);
    $filePath = $datePath . '/' . basename($finalFilePath);

    try {
        $result = StorageHelper::upload($storage, $config, $finalFilePath, $filePath);

        if ($storage !== 'local') {
            if (file_exists($finalFilePath)) {
                unlink($finalFilePath);
            }
            if ($finalFilePath !== $newFilePath && file_exists($newFilePath)) {
                unlink($newFilePath);
            }
        }

        $fileUrl = generateFileUrl($storage, $config, $filePath, $result);
        $storagePath = ($storage === 'local') ? $finalFilePath : $filePath;

        $stmt = $pdo->prepare('INSERT INTO images (url, path, storage, size, upload_ip, user_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$fileUrl, $storagePath, $storage, $fileSize, getClientIp(), $user_id]);
        Database::adjustImageCount($pdo, 1);

        $clientIp = getClientIp();
        logMessage("上传成功 | IP: {$clientIp} | 存储: {$storage} | URL: {$fileUrl}");

        generateUploadResponse($fileUrl, $storagePath, $finalFilePath, $fileSize, $dimensions['width'], $dimensions['height']);
    } catch (Exception $e) {
        $clientIp = getClientIp();
        $errorMsg = $e->getMessage();
        logMessage("上传失败 | IP: {$clientIp} | 存储: {$storage} | 错误: {$errorMsg}");

        generateUploadResponse('', '', '', 0, 0, 0, "文件上传到{$storage}失败: " . $errorMsg, true);
    }
}
