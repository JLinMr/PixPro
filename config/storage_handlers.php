<?php

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

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
 * 处理S3上传
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
    global $s3Region, $s3Bucket, $s3Endpoint, $s3AccessKeyId, $s3AccessKeySecret, $customUrlPrefix, $protocol, $mysqli;

    try {

        $s3Client = new S3Client([
            'region' => $s3Region,
            'version' => 'latest',
            'endpoint' => $protocol . $s3Endpoint,
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

        if (empty($customUrlPrefix)) {
            $fileUrl = $result['ObjectURL'];
        } else {
            $fileUrl = $protocol . $customUrlPrefix . '/' . $s3FilePath;
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