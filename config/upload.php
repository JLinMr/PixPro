<?php
session_start();

require_once 'storage.php';

/**
 * 将JPEG图片转换为WebP格式
 */
function convertToWebp($source, $destination, $quality = 60) {
    $info = getimagesize($source);

    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/gif') {
        return false;
    } else {
        return false;
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $maxWidth = 2500;
    $maxHeight = 1600;
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

/**
 * 使用Imagick将PNG图片转换为WebP格式
 */
function convertPngWithImagick($source, $destination, $quality = 60) {
    try {
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
        $maxWidth = 2500;
        $maxHeight = 1600;

        if ($width > $maxWidth || $height > $maxHeight) {
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = round($width * $ratio);
            $newHeight = round($height * $ratio);
            $image->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);
        }

        $result = $image->writeImage($destination);
        $image->clear();
        $image->destroy();
        return $result;
    } catch (Exception $e) {
        logMessage('Imagick转换PNG失败: ' . $e->getMessage());
        return false;
    }
}

/**
 * 使用Imagick将GIF图片转换为WebP格式
 */
function convertGifToWebp($source, $destination, $quality = 60) {
    try {
        $image = new Imagick();
        $image->readImage($source);
        $image = $image->coalesceImages();
        foreach ($image as $frame) {
            $frame->setImageFormat('webp');
            $frame->setImageCompressionQuality($quality);
        }
        $image = $image->optimizeImageLayers();
        $result = $image->writeImages($destination, true);
        $image->clear();
        $image->destroy();
        return $result;
    } catch (Exception $e) {
        logMessage('GIF转换WebP失败: ' . $e->getMessage());
        return false;
    }
}

/**
 * 处理图片压缩
 */
function processImageCompression($fileMimeType, $newFilePath, $newFilePathWithoutExt, $quality) {
    global $mysqli;
    $convertSuccess = true;
    $finalFilePath = $newFilePath;
    $config = Database::getConfig($mysqli);
    $outputFormat = isset($config['output_format']) ? $config['output_format'] : 'webp';
    
    // 如果不是原始格式，需要进行格式转换
    if ($outputFormat !== 'original') {
        // 当quality不是100时，进行压缩转换
        if ($quality != 100) {
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
        }
        
        // 检查当前文件扩展名是否符合配置的输出格式
        $currentExt = strtolower(pathinfo($finalFilePath, PATHINFO_EXTENSION));
        if ($currentExt !== $outputFormat) {
            $newFinalFilePath = $newFilePathWithoutExt . '.' . $outputFormat;
            rename($finalFilePath, $newFinalFilePath);
            $finalFilePath = $newFinalFilePath;
        }
    }
    
    return [true, $finalFilePath];
}

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

    $ipList = explode(',', $ip);
    $ip = trim($ipList[0]);

    return $ip;
}

/**
 * 处理上传的文件
 */
function handleUploadedFile($file, $token, $referer) {
    global $mysqli;
    
    // 基础配置
    $config = Database::getConfig($mysqli);
    $storage = $config['storage'];
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : NULL;
    $quality = isset($_POST['quality']) ? intval($_POST['quality']) : 60;
    
    // 验证图片
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/octet-stream'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $fileMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($fileMimeType, $allowedTypes)) {
        respondAndExit(['result' => 'error', 'code' => 406, 'message' => '不支持的文件类型']);
    }

    // 验证图片有效性
    if ($fileMimeType !== 'image/svg+xml') {
        $isValidImage = ($fileMimeType === 'application/octet-stream') 
            ? imagecreatefromstring(file_get_contents($file['tmp_name'])) !== false
            : getimagesize($file['tmp_name']) !== false;
            
        if (!$isValidImage) {
            respondAndExit(['result' => 'error', 'code' => 406, 'message' => '文件不是有效的图片']);
        }
    }

    // 准备文件路径
    $datePath = date('Y/m/d');
    $uploadDir = 'i/' . $datePath . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '无法创建上传目录']);
    }

    $randomFileName = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'webp';
    $newFilePath = $uploadDir . $randomFileName . '.' . $extension;
    
    // 上传和处理文件
    if (move_uploaded_file($file['tmp_name'], $newFilePath)) {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        // 处理图片压缩和转换
        $finalFilePath = processImageCompression($fileMimeType, $newFilePath, $uploadDir . $randomFileName, $quality)[1];

        // 处理JPEG方向
        if ($fileMimeType === 'image/jpeg') {
            correctJpegOrientation($finalFilePath, $quality);
        }

        // 获取图片信息
        if ($fileMimeType === 'image/svg+xml') {
            $dimensions = ['width' => 100, 'height' => 100];
        } else {
            // 使用更可靠的方式获取图片尺寸
            try {
                if (class_exists('Imagick')) {
                    // 优先使用 Imagick
                    $image = new Imagick($finalFilePath);
                    $dimensions = [
                        'width' => $image->getImageWidth(),
                        'height' => $image->getImageHeight()
                    ];
                    $image->destroy();
                } else {
                    // 降级使用 GD
                    $dimensions = getimagesize($finalFilePath);
                    if ($dimensions) {
                        $dimensions = [
                            'width' => $dimensions[0],
                            'height' => $dimensions[1]
                        ];
                    }
                }

                if (!$dimensions) {
                    // 如果还是获取失败，尝试直接读取图片
                    $image = imagecreatefromstring(file_get_contents($finalFilePath));
                    if ($image) {
                        $dimensions = [
                            'width' => imagesx($image),
                            'height' => imagesy($image)
                        ];
                        imagedestroy($image);
                    }
                }
            } catch (Exception $e) {
                logMessage('获取图片尺寸失败: ' . $e->getMessage());
            }
        }

        // 即使获取失败也不中断上传
        if (empty($dimensions) || !isset($dimensions['width']) || !isset($dimensions['height'])) {
            $dimensions = ['width' => 0, 'height' => 0];
            logMessage("警告: 无法获取图片 {$finalFilePath} 的尺寸信息");
        }

        // 处理存储
        $upload_ip = getClientIp();
        $storageTypes = array_keys(Database::getStorageConfig());
        
        if ($storage === 'local') {
            handleLocalStorage($finalFilePath, $newFilePath, $uploadDir, filesize($finalFilePath), 
                $dimensions['width'], $dimensions['height'], $randomFileName, $user_id, $upload_ip);
        } else if (in_array($storage, $storageTypes)) {
            $handlerFunction = "handle" . strtoupper($storage) . "Upload";
            if (!function_exists($handlerFunction)) {
                respondAndExit(['result' => 'error', 'code' => 500, 'message' => '不支持的存储方式']);
            }
            $handlerFunction($finalFilePath, $newFilePath, $datePath, filesize($finalFilePath),
                $dimensions['width'], $dimensions['height'], $randomFileName, $user_id, $upload_ip);
        }
    } else {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件上传失败']);
    }
}

/**
 * 删除本地文件
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
 */
function insertImageRecord($fileUrl, $path, $storage, $size, $upload_ip, $user_id) {
    global $mysqli;

    $stmt = $mysqli->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssdsd", $fileUrl, $path, $storage, $size, $upload_ip, $user_id);
    $stmt->execute();
    $stmt->close();
}

// 新增辅助函数
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