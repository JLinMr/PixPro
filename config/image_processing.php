<?php
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
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($quality);
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
        $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
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
    $convertSuccess = true;
    $finalFilePath = $newFilePath;

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

    return [$convertSuccess, $finalFilePath];
}
?>