<?php
require_once '../vendor/autoload.php';
require_once 'Database.php';

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class ImageDeleter {
    private $mysqli;
    private $config;

    public function __construct($mysqli, $config) {
        $this->mysqli = $mysqli;
        $this->config = $config;
    }

    public function deleteImage($path) {
        if (empty($path)) {
            return ['result' => 'error', 'message' => '路径不能为空'];
        }

        try {
            $storage = $this->getStorageType($path);
            if (empty($storage)) {
                throw new Exception("未找到相应的图片记录");
            }

            switch ($storage) {
                case 'local':
                    $this->deleteLocalImage($path);
                    break;
                case 'oss':
                    $this->deleteOssImage($path);
                    break;
                case 's3':
                    $this->deleteS3Image($path);
                    break;
                default:
                    throw new Exception("无效的 storage 配置");
            }

            $this->deleteDatabaseRecord($path);
            return ['result' => 'success', 'message' => '图片删除成功'];
        } catch (Exception $e) {
            return ['result' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function getStorageType($path) {
        $stmt = $this->mysqli->prepare("SELECT storage FROM images WHERE path = ?");
        $stmt->bind_param("s", $path);
        $stmt->execute();
        $stmt->bind_result($storage);
        $stmt->fetch();
        $stmt->close();
        return $storage;
    }

    private function deleteLocalImage($path) {
        $localFilePath = '../' . $path;
        if (file_exists($localFilePath)) {
            unlink($localFilePath);
        }
    }

    private function deleteOssImage($path) {
        $ossKey = parse_url($path, PHP_URL_PATH);
        $ossClient = new OssClient($this->config['accessKeyId'], $this->config['accessKeySecret'], $this->config['endpoint']);
        $ossClient->deleteObject($this->config['bucket'], $ossKey);
    }

    private function deleteS3Image($path) {
        $s3Key = !empty($this->config['customUrlPrefix']) ? str_replace($this->config['customUrlPrefix'] . '/', '', $path) : $path;
        $s3Client = new S3Client([
            'region' => $this->config['s3Region'],
            'version' => 'latest',
            'endpoint' => $this->config['s3Endpoint'],
            'credentials' => [
                'key' => $this->config['s3AccessKeyId'],
                'secret' => $this->config['s3AccessKeySecret'],
            ],
        ]);
        $s3Client->deleteObject([
            'Bucket' => $this->config['s3Bucket'],
            'Key' => $s3Key,
        ]);
    }

    private function deleteDatabaseRecord($path) {
        $stmt = $this->mysqli->prepare("DELETE FROM images WHERE path = ?");
        $stmt->bind_param("s", $path);
        $stmt->execute();
        if ($stmt->affected_rows <= 0) {
            throw new Exception("无法从数据库中删除");
        }
        $stmt->close();
    }
}

// 检查配置文件是否存在
if (!file_exists('config.ini')) {
    throw new Exception("配置文件 config.ini 不存在");
}

// 解析配置文件
$config = parse_ini_file('config.ini');
if (!is_array($config)) {
    throw new Exception("无法解析配置文件 config.ini");
}

// 获取数据库连接
$database = Database::getInstance();
$mysqli = $database->getConnection();

$imageDeleter = new ImageDeleter($mysqli, $config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = $_POST['path'] ?? '';
    $result = $imageDeleter->deleteImage($path);
    echo json_encode($result);
} else {
    echo json_encode(['result' => 'error', 'message' => '仅允许 POST 请求。']);
}

$mysqli->close();
?>