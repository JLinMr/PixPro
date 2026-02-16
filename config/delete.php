<?php
// 清理输出缓冲，防止警告信息混入 JSON 响应
ob_start();

// 抑制所有错误输出
error_reporting(0);
ini_set('display_errors', '0');

require_once '../vendor/autoload.php';
require_once 'database.php';
require_once 'storage.php';

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Upyun\Upyun;

class ImageDeleter {
    private $mysqli;
    private $config;
    
    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
        $this->config = Database::getConfig($mysqli);
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

            // 从存储删除文件
            StorageHelper::delete($storage, $this->config, $path);
            
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

// 清理之前的输出
ob_end_clean();

// 设置 JSON 响应头
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = $_POST['path'] ?? '';
    $deleter = new ImageDeleter($mysqli);
    $result = $deleter->delete($path);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['result' => 'error', 'message' => '仅允许 POST 请求。'], JSON_UNESCAPED_UNICODE);
}

$mysqli->close();
?>