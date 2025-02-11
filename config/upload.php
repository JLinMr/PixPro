<?php
session_start();

require_once 'storage.php';

/**
 * 统一的图片转换函数
 * @param string $source 源文件路径
 * @param string $destination 目标文件路径
 * @param int $quality 图片质量
 * @return bool 转换是否成功
 */
function convertImageToWebp($source, $destination, $quality = 60) {
    try {
        $maxWidth = 2500;
        $maxHeight = 1600;
        $info = getimagesize($source);
        $mimeType = $info['mime'];

        // 使用Imagick处理PNG
        if ($mimeType === 'image/png') {
            $image = new Imagick($source);
            
            if ($image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor(new ImagickPixel('transparent'));
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $image->setImageFormat('webp');
            $image->setImageCompressionQuality($quality);

            // 调整图片尺寸
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
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
        }
        // 使用GD处理JPEG和其他格式
        else if ($mimeType === 'image/jpeg') {
            $image = imagecreatefromjpeg($source);
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

        return false;
    } catch (Exception $e) {
        logMessage('图片转换失败: ' . $e->getMessage());
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
    
    // 当quality不是100且不是svg或webp时，进行压缩转换为webp
    if ($quality != 100 && $fileMimeType !== 'image/svg+xml' && $fileMimeType !== 'image/webp') {
        $convertSuccess = convertImageToWebp($newFilePath, $newFilePathWithoutExt . '.webp', $quality);
        if ($convertSuccess) {
            $finalFilePath = $newFilePathWithoutExt . '.webp';
            unlink($newFilePath);
        }
    }
    
    // 根据配置的输出格式修改文件后缀
    // 如果是quality=100或者是svg/webp，直接重命名为目标格式
    if ($outputFormat !== 'webp' || ($quality == 100 && $fileMimeType !== 'image/svg+xml')) {
        $currentExt = strtolower(pathinfo($finalFilePath, PATHINFO_EXTENSION));
        if ($currentExt !== $outputFormat) {
            if ($outputFormat === 'original') {
                // 如果是original，使用原始文件的扩展名
                $originalExt = strtolower(pathinfo($newFilePath, PATHINFO_EXTENSION));
                $newFinalFilePath = $newFilePathWithoutExt . '.' . $originalExt;
            } else {
                // 使用配置的输出格式作为扩展名
                $newFinalFilePath = $newFilePathWithoutExt . '.' . $outputFormat;
            }
            
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

    // 对于SVG文件特殊处理
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'svg') {
        // 如果文件扩展名是svg，则强制设置MIME类型
        $fileMimeType = 'image/svg+xml';
    }

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