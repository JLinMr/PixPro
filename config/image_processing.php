<?php
/**
 * 将图片转换为WebP格式
 */
function ToWebp($source, $destination, $quality) {
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
            $image->resizeImage($newWidth, $newHeight, Imagick::FILTER_MITCHELL, 1);
        }
        $result = $image->writeImage($destination);
        $image->clear();
        $image->destroy();
        gc_collect_cycles();
        return $result;
    } catch (Exception $e) {
        logMessage('Imagick转换失败: ' . $e->getMessage());
        return false;
    }
}

function GifToWebp($source, $destination, $quality) {
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
?>
