<?php

if (!class_exists('Composer\Autoload\ClassLoader')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use OSS\OssClient;
use Aws\S3\S3Client;
use Upyun\Upyun;
use Upyun\Config;

/**
 * 存储助手类 - 统一管理所有存储方式
 */
class StorageHelper {
    
    /**
     * 上传文件到指定存储
     */
    public static function upload($storage, $config, $localFilePath, $remotePath) {
        switch ($storage) {
            case 'local':
                // 本地存储不需要额外操作，文件已经在本地
                return true;
                
            case 'oss':
                $client = self::createOssClient($config);
                $client->uploadFile($config['oss_bucket'], $remotePath, $localFilePath);
                return true;
                
            case 's3':
                $client = self::createS3Client($config);
                $result = $client->putObject([
                    'Bucket' => $config['s3_bucket'],
                    'Key' => $remotePath,
                    'SourceFile' => $localFilePath,
                    'ACL' => 'public-read',
                ]);
                return $result;
                
            case 'upyun':
                $client = self::createUpyunClient($config);
                $client->writeFile($remotePath, fopen($localFilePath, 'r'), true);
                return true;
                
            default:
                throw new \Exception("不支持的存储方式: {$storage}");
        }
    }
    
    /**
     * 从指定存储删除文件
     */
    public static function delete($storage, $config, $path) {
        switch ($storage) {
            case 'local':
                if (file_exists('../' . $path)) {
                    return unlink('../' . $path);
                }
                return true;
                
            case 'oss':
                $client = self::createOssClient($config);
                $key = parse_url($path, PHP_URL_PATH);
                $client->deleteObject($config['oss_bucket'], $key);
                return true;
                
            case 's3':
                $client = self::createS3Client($config);
                $key = !empty($config['s3_cdn_domain']) 
                    ? str_replace($config['s3_cdn_domain'] . '/', '', $path) 
                    : $path;
                $client->deleteObject([
                    'Bucket' => $config['s3_bucket'],
                    'Key' => $key,
                ]);
                return true;
                
            case 'upyun':
                $client = self::createUpyunClient($config);
                $key = str_replace($config['upyun_cdn_domain'] . '/', '', $path);
                $client->delete($key);
                return true;
                
            default:
                throw new \Exception("不支持的存储方式: {$storage}");
        }
    }
    
    /**
     * 创建OSS客户端
     */
    private static function createOssClient($config) {
        return new OssClient(
            $config['oss_access_key_id'],
            $config['oss_access_key_secret'],
            $config['oss_endpoint']
        );
    }
    
    /**
     * 创建S3客户端
     */
    private static function createS3Client($config) {
        // S3 endpoint 需要包含协议头，默认使用 https
        $endpoint = $config['s3_endpoint'];
        if (!preg_match('/^https?:\/\//', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }
        
        return new S3Client([
            'region' => $config['s3_region'],
            'version' => 'latest',
            'endpoint' => $endpoint,
            'credentials' => [
                'key' => $config['s3_access_key_id'],
                'secret' => $config['s3_access_key_secret'],
            ],
            'suppress_php_deprecation_warning' => true,
            'http' => [
                'verify' => false  // 临时禁用SSL验证
            ]
        ]);
    }
    
    /**
     * 创建又拍云客户端
     */
    private static function createUpyunClient($config) {
        $serviceConfig = new Config(
            $config['upyun_bucket'],
            $config['upyun_operator'],
            $config['upyun_password']
        );
        
        return new Upyun($serviceConfig);
    }
}
