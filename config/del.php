<?php
require_once '../vendor/autoload.php';
require_once 'Database.php';

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

function deleteImage($mysqli, $config, $path) {
    if (empty($path)) {
        return ['result' => 'error', 'message' => '路径不能为空'];
    }

    try {
        $storage = getStorageType($mysqli, $path);
        if (empty($storage)) {
            throw new Exception("未找到相应的图片记录");
        }

        switch ($storage) {
            case 'local':
                deleteLocalImage($path);
                break;
            case 'oss':
                deleteOssImage($config, $path);
                break;
            case 's3':
                deleteS3Image($config, $path);
                break;
            default:
                throw new Exception("无效的 storage 配置");
        }

        deleteDatabaseRecord($mysqli, $path);
        return ['result' => 'success', 'message' => '图片删除成功'];
    } catch (Exception $e) {
        return ['result' => 'error', 'message' => $e->getMessage()];
    }
}

function getStorageType($mysqli, $path) {
    $stmt = $mysqli->prepare("SELECT storage FROM images WHERE path = ?");
    $stmt->bind_param("s", $path);
    $stmt->execute();
    $stmt->bind_result($storage);
    $stmt->fetch();
    $stmt->close();
    return $storage;
}

function deleteLocalImage($path) {
    $localFilePath = '../' . $path;
    if (file_exists($localFilePath)) {
        unlink($localFilePath);
    }
}

function deleteOssImage($config, $path) {
    $ossKey = parse_url($path, PHP_URL_PATH);
    $ossClient = new OssClient($config['accessKeyId'], $config['accessKeySecret'], $config['endpoint']);
    $ossClient->deleteObject($config['bucket'], $ossKey);
}

function deleteS3Image($config, $path) {
    $s3Key = !empty($config['customUrlPrefix']) ? str_replace($config['customUrlPrefix'] . '/', '', $path) : $path;
    $s3Client = new S3Client([
        'region' => $config['s3Region'],
        'version' => 'latest',
        'endpoint' => $config['protocol'] . $config['s3Endpoint'],
        'credentials' => [
            'key' => $config['s3AccessKeyId'],
            'secret' => $config['s3AccessKeySecret'],
        ],
    ]);
    $s3Client->deleteObject([
        'Bucket' => $config['s3Bucket'],
        'Key' => $s3Key,
    ]);
}

function deleteDatabaseRecord($mysqli, $path) {
    $stmt = $mysqli->prepare("DELETE FROM images WHERE path = ?");
    $stmt->bind_param("s", $path);
    $stmt->execute();
    if ($stmt->affected_rows <= 0) {
        throw new Exception("无法从数据库中删除");
    }
    $stmt->close();
}

if (!file_exists('config.ini')) {
    throw new Exception("配置文件 config.ini 不存在");
}

$config = parse_ini_file('config.ini');
if (!is_array($config)) {
    throw new Exception("无法解析配置文件 config.ini");
}

$database = Database::getInstance();
$mysqli = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = $_POST['path'] ?? '';
    $result = deleteImage($mysqli, $config, $path);
    echo json_encode($result);
} else {
    echo json_encode(['result' => 'error', 'message' => '仅允许 POST 请求。']);
}

$mysqli->close();
?>