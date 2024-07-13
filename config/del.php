<?php
require_once '../vendor/autoload.php';
require_once 'Database.php';
use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

// 检查配置文件是否存在
if (!file_exists('config.ini')) {
    throw new Exception("配置文件 config.ini 不存在");
}

// 解析配置文件
$config = parse_ini_file('config.ini');
if (!is_array($config)) {
    throw new Exception("无法解析配置文件 config.ini");
}

// 获取配置项
$accessKeyId = $config['accessKeyId'];
$accessKeySecret = $config['accessKeySecret'];
$endpoint = $config['endpoint'];
$bucket = $config['bucket'];
$s3Region = $config['s3Region'];
$s3Bucket = $config['s3Bucket'];
$s3AccessKeyId = $config['s3AccessKeyId'];
$s3AccessKeySecret = $config['s3AccessKeySecret'];
$s3Endpoint = $config['s3Endpoint'];
$customUrlPrefix = $config['customUrlPrefix'];

// 获取数据库连接
$database = Database::getInstance();
$mysqli = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = $_POST['path'] ?? '';

    if (empty($path)) {
        echo json_encode(['result' => 'error', 'message' => '路径不能为空']);
        exit;
    }

    try {
        // 查询图片存储类型
        $stmt = $mysqli->prepare("SELECT storage FROM images WHERE path = ?");
        if (!$stmt) {
            throw new Exception("数据库错误: " . $mysqli->error);
        }
        $stmt->bind_param("s", $path);
        $stmt->execute();
        $stmt->bind_result($storage);
        $stmt->fetch();
        $stmt->close();

        if (empty($storage)) {
            throw new Exception("未找到相应的图片记录");
        }

        // 根据存储类型删除图片
        if ($storage === 'local') {
            $localFilePath = '../' . $path;
            if (file_exists($localFilePath)) {
                unlink($localFilePath);
            }
            // 即使文件不存在，也继续删除数据库记录
        } elseif ($storage === 'oss') {
            if (empty($accessKeyId) || empty($accessKeySecret) || empty($endpoint) || empty($bucket)) {
                throw new Exception("OSS 配置项不完整");
            }
            $ossKey = parse_url($path, PHP_URL_PATH);
            $ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint);
            $ossClient->deleteObject($bucket, $ossKey);
        } elseif ($storage === 's3') {
            if (empty($s3Region) || empty($s3Bucket) || empty($s3AccessKeyId) || empty($s3AccessKeySecret) || empty($s3Endpoint)) {
                throw new Exception("S3 配置项不完整");
            }
            if (!empty($customUrlPrefix)) {
                $s3Key = str_replace($customUrlPrefix . '/', '', $path);
            } else {
                $s3Key = $path;
            }
            $s3Client = new S3Client([
                'region' => $s3Region,
                'version' => 'latest',
                'endpoint' => $s3Endpoint,
                'credentials' => [
                    'key' => $s3AccessKeyId,
                    'secret' => $s3AccessKeySecret,
                ],
            ]);
            $s3Client->deleteObject([
                'Bucket' => $s3Bucket,
                'Key' => $s3Key,
            ]);
        } else {
            throw new Exception("无效的 storage 配置");
        }

        // 删除数据库记录
        $stmt = $mysqli->prepare("DELETE FROM images WHERE path = ?");
        if (!$stmt) {
            throw new Exception("数据库错误: " . $mysqli->error);
        }
        $stmt->bind_param("s", $path);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo json_encode(['result' => 'success', 'message' => '图片删除成功']);
        } else {
            echo json_encode(['result' => 'error', 'message' => '无法从数据库中删除']);
            error_log("Failed to delete image from database. Path: $path");
        }
        $stmt->close();
    } catch (OssException $e) {
        echo json_encode(['result' => 'error', 'message' => '从 oss 删除失败: ' . $e->getMessage()]);
        error_log("Failed to delete image from OSS: " . $e->getMessage());
    } catch (S3Exception $e) {
        echo json_encode(['result' => 'error', 'message' => '从 s3 删除失败: ' . $e->getMessage()]);
        error_log("Failed to delete image from S3: " . $e->getMessage());
    } catch (Exception $e) {
        echo json_encode(['result' => 'error', 'message' => '未知错误: ' . $e->getMessage()]);
        error_log("未知错误: " . $e->getMessage());
    }
} else {
    echo json_encode(['result' => 'error', 'message' => '仅允许 POST 请求。']);
}

$mysqli->close();
?>