<?php
require_once 'Database.php';

// 检查配置文件是否存在
if (!file_exists('config.ini')) {
    throw new Exception("配置文件 config.ini 不存在");
}

// 解析配置文件
$config = parse_ini_file('config.ini', true);
if (!is_array($config)) {
    throw new Exception("无法解析配置文件 config.ini");
}

// 获取 GitHub 配置项
$githubToken = $config['GitHub']['githubToken'];
$githubRepoOwner = $config['GitHub']['repoOwner'];
$githubRepoName = $config['GitHub']['repoName'];
$githubBranch = $config['GitHub']['branch'];

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
        } elseif ($storage === 'github') {
            if (empty($githubToken) || empty($githubRepoOwner) || empty($githubRepoName) || empty($githubBranch)) {
                throw new Exception("GitHub 配置项不完整");
            }

            // 从路径中提取文件路径
            $filePath = ltrim(parse_url($path, PHP_URL_PATH), '/');

            // 获取文件的SHA值
            $url = "https://api.github.com/repos/{$githubRepoOwner}/{$githubRepoName}/contents/{$filePath}?ref={$githubBranch}";

            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: PHP\r\n" .
                                "Authorization: token {$githubToken}\r\n",
                    'method' => 'GET',
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $response = file_get_contents($url, false, $context);
            if ($response === FALSE) {
                throw new Exception('获取文件SHA失败: HTTP ' . $http_response_header[0]);
            }

            $fileInfo = json_decode($response, true);
            $fileSha = $fileInfo['sha'] ?? null;

            if (empty($fileSha)) {
                throw new Exception("无法获取文件的 SHA 值");
            }

            $deleteData = [
                'message' => '删除图片 - 来自PixPro图床程序',
                'sha' => $fileSha,
                'branch' => $githubBranch,
            ];

            $deleteContext = stream_context_create([
                'http' => [
                    'header' => "User-Agent: PHP\r\n" .
                                "Authorization: token {$githubToken}\r\n" .
                                "Content-Type: application/json\r\n",
                    'method' => 'DELETE',
                    'content' => json_encode($deleteData),
                ],
            ]);

            $deleteResponse = file_get_contents($url, false, $deleteContext);
            if ($deleteResponse === FALSE) {
                throw new Exception('删除文件失败: HTTP ' . $http_response_header[0]);
            }

            $httpCode = explode(' ', $http_response_header[0])[1];
            if ($httpCode != 200) {
                throw new Exception('删除文件失败: HTTP ' . $httpCode);
            }
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
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['result' => 'error', 'message' => '未知错误: ' . $e->getMessage()]);
        error_log("未知错误: " . $e->getMessage());
    }
} else {
    echo json_encode(['result' => 'error', 'message' => '仅允许 POST 请求。']);
}

$mysqli->close();
?>
