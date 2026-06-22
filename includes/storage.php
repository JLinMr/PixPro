<?php

use OSS\OssClient;
use Aws\S3\S3Client;
use Upyun\Upyun;
use Upyun\Config;

class StorageHelper {
    private static function ensureVendor(): void {
        static $loaded = false;
        if (!$loaded) {
            require_once PIXPRO_ROOT . '/vendor/autoload.php';
            $loaded = true;
        }
    }

    public static function upload($storage, $config, $localFilePath, $remotePath) {
        switch ($storage) {
            case 'local':
                return true;

            case 'oss':
                self::ensureVendor();
                $client = self::createOssClient($config);
                $client->uploadFile($config['oss_bucket'], $remotePath, $localFilePath);
                return true;

            case 's3':
                self::ensureVendor();
                $client = self::createS3Client($config);
                return $client->putObject([
                    'Bucket' => $config['s3_bucket'],
                    'Key' => $remotePath,
                    'SourceFile' => $localFilePath,
                    'ACL' => 'public-read',
                ]);

            case 'upyun':
                self::ensureVendor();
                $client = self::createUpyunClient($config);
                $client->write($remotePath, file_get_contents($localFilePath));
                return true;

            default:
                throw new Exception("不支持的存储方式: {$storage}");
        }
    }

    public static function delete($storage, $config, $path) {
        try {
            switch ($storage) {
                case 'local':
                    $fullPath = PIXPRO_ROOT . '/' . $path;
                    if (file_exists($fullPath)) {
                        return unlink($fullPath);
                    }
                    return true;

                case 'oss':
                    self::ensureVendor();
                    $client = self::createOssClient($config);
                    $key = parse_url($path, PHP_URL_PATH);
                    $client->deleteObject($config['oss_bucket'], $key);
                    return true;

                case 's3':
                    self::ensureVendor();
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
                    self::ensureVendor();
                    $client = self::createUpyunClient($config);
                    $key = str_replace($config['upyun_cdn_domain'] . '/', '', $path);
                    $client->delete($key);
                    return true;

                default:
                    throw new Exception("不支持的存储方式: {$storage}");
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, '404') !== false
                || strpos($errorMessage, 'NoSuchKey') !== false
                || strpos($errorMessage, 'not exist') !== false
                || strpos($errorMessage, '不存在') !== false) {
                return true;
            }
            throw $e;
        }
    }

    private static function createOssClient($config) {
        return new OssClient(
            $config['oss_access_key_id'],
            $config['oss_access_key_secret'],
            $config['oss_endpoint']
        );
    }

    private static function createS3Client($config) {
        return new S3Client([
            'region' => $config['s3_region'],
            'version' => 'latest',
            'endpoint' => $config['s3_endpoint'],
            'credentials' => [
                'key' => $config['s3_access_key_id'],
                'secret' => $config['s3_access_key_secret'],
            ],
            'suppress_php_deprecation_warning' => true,
            'http' => ['verify' => true],
        ]);
    }

    private static function createUpyunClient($config) {
        $serviceConfig = new Config(
            $config['upyun_bucket'],
            $config['upyun_operator'],
            $config['upyun_password']
        );

        return new Upyun($serviceConfig);
    }

    public static function testConnection($storage, $config) {
        switch ($storage) {
            case 'local':
                return true;

            case 'oss':
                self::ensureVendor();
                $client = self::createOssClient($config);
                $client->doesBucketExist($config['oss_bucket']);
                return true;

            case 's3':
                self::ensureVendor();
                $client = self::createS3Client($config);
                $client->headBucket(['Bucket' => $config['s3_bucket']]);
                return true;

            case 'upyun':
                self::ensureVendor();
                $client = self::createUpyunClient($config);
                $client->read('/', ['list' => true]);
                return true;

            default:
                throw new Exception('不支持的存储方式');
        }
    }
}

class ImageDeleter {
    private $pdo;
    private $config;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->config = Database::getConfig($pdo);
    }

    public function delete($path) {
        if (empty($path)) {
            return ['result' => 'error', 'message' => '路径不能为空'];
        }

        try {
            $storage = $this->getStorageType($path);
            if (!$storage) {
                throw new Exception('未找到相应的图片记录');
            }

            StorageHelper::delete($storage, $this->config, $path);
            $this->deleteDatabaseRecord($path);

            return ['result' => 'success', 'message' => '图片删除成功'];
        } catch (Exception $e) {
            return ['result' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function getStorageType($path) {
        $stmt = $this->pdo->prepare('SELECT storage FROM images WHERE path = ?');
        $stmt->execute([$path]);
        return $stmt->fetchColumn() ?: null;
    }

    private function deleteDatabaseRecord($path) {
        $stmt = $this->pdo->prepare('DELETE FROM images WHERE path = ?');
        $stmt->execute([$path]);
        if ($stmt->rowCount() <= 0) {
            throw new Exception('无法从数据库中删除');
        }

        Database::adjustImageCount($this->pdo, -1);
    }
}
