<?php
require_once '../vendor/autoload.php';
require_once 'database.php';

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Upyun\Upyun;

class ImageDeleter {
    private $mysqli;
    private $config;
    private $storageHandlers;
    
    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
        $this->config = Database::getConfig($mysqli);
        $this->storageHandlers = [
            'local' => fn($path) => unlink('../' . $path),
            'oss' => function($path) {
                $ossKey = parse_url($path, PHP_URL_PATH);
                $ossClient = new OssClient(
                    $this->config['oss_access_key_id'],
                    $this->config['oss_access_key_secret'],
                    $this->config['oss_endpoint']
                );
                $ossClient->deleteObject($this->config['oss_bucket'], $ossKey);
            },
            's3' => function($path) {
                $s3Key = !empty($this->config['s3_custom_url_prefix']) 
                    ? str_replace($this->config['s3_custom_url_prefix'] . '/', '', $path) 
                    : $path;
                
                $s3Client = new S3Client([
                    'region' => $this->config['s3_region'],
                    'version' => 'latest',
                    'endpoint' => $this->config['protocol'] . $this->config['s3_endpoint'],
                    'credentials' => [
                        'key' => $this->config['s3_access_key_id'],
                        'secret' => $this->config['s3_access_key_secret'],
                    ],
                    // 'http' => [
                    //     'verify' => false
                    // ],
                    // 'suppress_php_deprecation_warning' => true
                ]);
                
                $s3Client->deleteObject([
                    'Bucket' => $this->config['s3_bucket'],
                    'Key' => $s3Key,
                ]);
            },
            'upyun' => function($path) {
                $serviceConfig = new \Upyun\Config(
                    $this->config['upyun_bucket'],
                    $this->config['upyun_operator'],
                    $this->config['upyun_password']
                );
                $upyun = new \Upyun\Upyun($serviceConfig);
                $upyunPath = str_replace($this->config['upyun_domain'] . '/', '', $path);
                $upyun->delete($upyunPath);
            }
        ];
    }
    
    public function delete($path) {
        if (empty($path)) {
            return ['result' => 'error', 'message' => '路径不能为空'];
        }

        try {
            $storage = $this->getStorageType($path);
            if (empty($storage)) {
                throw new Exception("未找到相应的图片记录");
            }

            if (!isset($this->storageHandlers[$storage])) {
                throw new Exception("无效的存储类型: {$storage}");
            }

            // 存储删除操作
            $handler = $this->storageHandlers[$storage];
            $handler($path);
            
            // 删除数据库记录
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
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result['storage'] ?? null;
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

// 处理请求
$database = Database::getInstance();
$mysqli = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = $_POST['path'] ?? '';
    $deleter = new ImageDeleter($mysqli);
    $result = $deleter->delete($path);
    echo json_encode($result);
} else {
    echo json_encode(['result' => 'error', 'message' => '仅允许 POST 请求。']);
}

$mysqli->close();
?>