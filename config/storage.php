<?php

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Upyun\Upyun;
use Upyun\Config;

/**
 * 生成文件访问URL
 *
 * @param array $config 配置数组
 * @param string $cdnDomain CDN域名配置键名
 * @param string $defaultDomain 默认域名
 * @param string $filePath 文件路径
 * @return string 完整的文件URL
 */
function generateFileUrl($config, $cdnDomain, $defaultDomain, $filePath) {
    if (empty($config[$cdnDomain])) {
        return $config['protocol'] . $defaultDomain . '/' . $filePath;
    }
    return $config['protocol'] . $config[$cdnDomain] . '/' . $filePath;
}

/**
 * 生成并返回上传响应数据
 *
 * @param string $fileUrl 文件URL
 * @param string $filePath 文件路径
 * @param string $finalFilePath 最终文件名
 * @param int $compressedSize 压缩后大小
 * @param int $compressedWidth 压缩后宽度
 * @param int $compressedHeight 压缩后高度
 * @param string $message 错误信息(可选)
 * @param bool $isError 是否为错误响应
 */
function generateUploadResponse($fileUrl, $filePath, $finalFilePath, $compressedSize, $compressedWidth, $compressedHeight, $message = '', $isError = false) {
    if ($isError) {
        respondAndExit([
            'result' => 'error',
            'code' => 500,
            'message' => $message
        ]);
    }

    respondAndExit([
        'result' => 'success',
        'code' => 200,
        'status' => true,
        'name' => basename($finalFilePath),
        'data' => [
            'url' => $fileUrl,
            'name' => basename($finalFilePath),
            'width' => $compressedWidth,
            'height' => $compressedHeight,
            'size' => $compressedSize,           
            'path' => $filePath
        ],
        'url' => $fileUrl
    ]);
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
    global $mysqli;
    $config = Database::getConfig($mysqli);

    logMessage("文件存储在本地: $finalFilePath");
    $filePath = $uploadDirWithDatePath . basename($finalFilePath);
    $fileUrl = generateFileUrl($config, 'local_cdn_domain', $_SERVER['HTTP_HOST'], $filePath);
    
    insertImageRecord($fileUrl, $finalFilePath, 'local', $compressedSize, $upload_ip, $user_id);

    generateUploadResponse($fileUrl, $finalFilePath, $finalFilePath, $compressedSize, $compressedWidth, $compressedHeight);
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
    global $mysqli;
    $config = Database::getConfig($mysqli);

    try {
        $ossClient = new OssClient(
            $config['oss_access_key_id'],
            $config['oss_access_key_secret'],
            $config['oss_endpoint']
        );
        
        $ossFilePath = $datePath . '/' . basename($finalFilePath);
        $ossClient->uploadFile($config['oss_bucket'], $ossFilePath, $finalFilePath);

        deleteLocalFile($finalFilePath, $newFilePath);

        logMessage("文件上传到OSS成功: $ossFilePath");
        $fileUrl = generateFileUrl($config, 'oss_cdn_domain', $config['oss_endpoint'], $ossFilePath);
        insertImageRecord($fileUrl, $ossFilePath, 'oss', $compressedSize, $upload_ip, $user_id);

        generateUploadResponse($fileUrl, $ossFilePath, $finalFilePath, $compressedSize, $compressedWidth, $compressedHeight);
    } catch (OssException $e) {
        logMessage('文件上传到OSS失败: ' . $e->getMessage());
        generateUploadResponse('', '', '', 0, 0, 0, '文件上传到OSS失败: ' . $e->getMessage(), true);
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
    global $mysqli;
    $config = Database::getConfig($mysqli);

    try {
        $s3Client = new S3Client([
            'region' => $config['s3_region'],
            'version' => 'latest',
            'endpoint' => $config['protocol'] . $config['s3_endpoint'],
            'credentials' => [
                'key' => $config['s3_access_key_id'],
                'secret' => $config['s3_access_key_secret'],
            ],
            // 'http' => [
            //     'verify' => false
            // ],
            // 'suppress_php_deprecation_warning' => true
        ]);
        
        $s3FilePath = $datePath . '/' . basename($finalFilePath);
        $result = $s3Client->putObject([
            'Bucket' => $config['s3_bucket'],
            'Key' => $s3FilePath,
            'SourceFile' => $finalFilePath,
            'ACL' => 'public-read',
        ]);

        deleteLocalFile($finalFilePath, $newFilePath);

        logMessage("文件上传到S3成功: $s3FilePath");

        if (empty($config['s3_cdn_domain'])) {
            $fileUrl = $result['ObjectURL'];
        } else {
            $fileUrl = generateFileUrl($config, 's3_cdn_domain', '', $s3FilePath);
        }

        insertImageRecord($fileUrl, $s3FilePath, 's3', $compressedSize, $upload_ip, $user_id);

        generateUploadResponse($fileUrl, $s3FilePath, $finalFilePath, $compressedSize, $compressedWidth, $compressedHeight);
    } catch (S3Exception $e) {
        logMessage('文件上传到S3失败: ' . $e->getMessage());
        generateUploadResponse('', '', '', 0, 0, 0, '文件上传到S3失败: ' . $e->getMessage(), true);
    }
}

/**
 * 处理又拍云上传
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
function handleUpyunUpload($finalFilePath, $newFilePath, $datePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip) {
    global $mysqli;
    $config = Database::getConfig($mysqli);

    try {
        $serviceConfig = new \Upyun\Config(
            $config['upyun_bucket'],
            $config['upyun_operator'],
            $config['upyun_password']
        );
        $upyun = new \Upyun\Upyun($serviceConfig);
        
        $upyunFilePath = $datePath . '/' . basename($finalFilePath);
        $upyun->writeFile($upyunFilePath, fopen($finalFilePath, 'r'), true);

        deleteLocalFile($finalFilePath, $newFilePath);

        logMessage("文件上传到又拍云成功: $upyunFilePath");
        $fileUrl = generateFileUrl($config, 'upyun_cdn_domain', '', $upyunFilePath);
        insertImageRecord($fileUrl, $upyunFilePath, 'upyun', $compressedSize, $upload_ip, $user_id);

        generateUploadResponse($fileUrl, $upyunFilePath, $finalFilePath, $compressedSize, $compressedWidth, $compressedHeight);
    } catch (\Exception $e) {
        logMessage('文件上传到又拍云失败: ' . $e->getMessage());
        generateUploadResponse('', '', '', 0, 0, 0, '文件上传到又拍云失败: ' . $e->getMessage(), true);
    }
}