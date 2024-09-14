<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (file_exists('install.lock')) {
    $host = $_SERVER['HTTP_HOST'];
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http';
    $url = "$protocol://$host/";
    header("Location: $url");
    exit();
}

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$error = '';
$adminUserExists = false;
$adminUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePostRequest($step);
}

function handlePostRequest($step) {
    global $error, $adminUserExists, $adminUsername;

    if ($step === 1) {
        $mysql = [
            'dbHost' => $_POST['mysql_dbHost'],
            'dbName' => $_POST['mysql_dbName'],
            'dbUser' => $_POST['mysql_dbUser'],
            'dbPass' => $_POST['mysql_dbPass'],
        ];
        saveConfig($mysql);

        try {
            $mysqli = new mysqli($mysql['dbHost'], $mysql['dbUser'], $mysql['dbPass'], $mysql['dbName']);
            if ($mysqli->connect_error) {
                throw new Exception('数据库连接失败: ' . $mysqli->connect_error);
            }

            $mysqli->begin_transaction();
            try {
                createOrUpdateTableStructure($mysqli);
                handleAdminUser($mysqli);
                $mysqli->commit();
                header('Location: ?step=2');
                exit;
            } catch (Exception $e) {
                $mysqli->rollback();
                throw $e;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            if (strpos($error, 'Access denied') !== false) {
                $error = '数据库用户名或密码错误';
            } elseif (strpos($error, 'Unknown database') !== false) {
                $error = '数据库名错误';
            }
        }
    } elseif ($step === 2) {
        $storage = $_POST['storage'];
        $validToken = $_POST['validToken'];
        $protocol = $_POST['protocol'];

        $host = $_SERVER['HTTP_HOST'];
        $currentUrl = "$protocol$host/";

        $configContent = file_get_contents('../config/config.ini');
        $configContent .= "\n[Token]\nvalidToken = $validToken\n";
        $configContent .= "; // 为了站点安全，不建议你暴漏你的token\n";
        $configContent .= "\n[Other]\nstorage = $storage\nprotocol = $protocol\nper_page = 45\nlogin_restriction = false\nwhitelist = $currentUrl\n";
        $configContent .= "; // storage = local 本地存储  //  github 仓库存储\n; // protocol  配置图片URL协议头，如果你有证书建议使用https\n; // per_page  后台每页显示的图片数量，默认45  *** 其他设置查看 validate.php 文件\n; // login_restriction  true 开启 false 关闭 // 是否开启登录保护，默认false，开启后只有登录用户才能上传图片\n; // whitelist 设置后白名单站点使用API上传无需验证Token 例子: http://localhost/,http://127.0.0.1/\n";

        if ($storage === 'local') {
            addGitHubConfig($configContent);
        }

        file_put_contents('../config/config.ini', $configContent);
        chmod('../config/config.ini', 0600);

        if ($storage === 'local') {
            file_put_contents('install.lock', '安装锁');
            header('Location: ?step=5');
            exit;
        } elseif ($storage === 'github') {
            header('Location: ?step=3');
            exit;
        }
    } elseif ($step === 3) {
        $configType = 'GitHub';
        $configData = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, strtolower($configType)) === 0) {
                $configData[substr($key, strlen($configType) + 1)] = $value;
            }
        }
        saveConfigSection($configType, $configData);
        file_put_contents('install.lock', '安装锁');
        header('Location: ?step=5');
        exit;
    }
}

function saveConfig($mysql) {
    $configContent = "[MySQL]\n";
    foreach ($mysql as $key => $value) {
        $configContent .= "$key = $value\n";
    }
    file_put_contents('../config/config.ini', $configContent);
    chmod('../config/config.ini', 0777);
}

function saveConfigSection($section, $data) {
    $configContent = file_get_contents('../config/config.ini');
    $configContent .= "\n[$section]\n";
    foreach ($data as $key => $value) {
        $configContent .= "$key = $value\n";
    }
    file_put_contents('../config/config.ini', $configContent);
    chmod('../config/config.ini', 0600);
}

function createOrUpdateTableStructure($mysqli) {
    $createImagesTableSQL = "
        CREATE TABLE IF NOT EXISTS images (
            id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主键ID',
            user_id INT UNSIGNED NULL COMMENT '用户ID',
            url VARCHAR(255) NOT NULL COMMENT '图片URL',
            path VARCHAR(255) NOT NULL COMMENT '图片存储路径',
            storage ENUM('github', 'local', 'other') NOT NULL COMMENT '存储方式',
            size INT UNSIGNED NOT NULL COMMENT '图片大小(字节)',
            upload_ip VARCHAR(45) NOT NULL COMMENT '上传者IP地址',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片信息表';
    ";
    if ($mysqli->query($createImagesTableSQL) === FALSE) {
        throw new Exception('创建 images 表失败: ' . $mysqli->error);
    }

    $createUsersTableSQL = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY COMMENT '用户 ID',
            username VARCHAR(255) NOT NULL UNIQUE COMMENT '用户名',
            password VARCHAR(255) NOT NULL COMMENT '密码'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';
    ";
    if ($mysqli->query($createUsersTableSQL) === FALSE) {
        throw new Exception('创建 users 表失败: ' . $mysqli->error);
    }
}

function handleAdminUser($mysqli) {
    global $adminUserExists, $adminUsername;

    $username = $_POST['mysql_adminUser'];
    $password = password_hash($_POST['mysql_adminPass'], PASSWORD_DEFAULT);

    $checkUserSQL = "SELECT id, username FROM users WHERE username = ?";
    $stmt = $mysqli->prepare($checkUserSQL);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $adminUserExists = true;
        $adminUsername = $username;

        $updateAdminSQL = "UPDATE users SET password = ? WHERE username = ?";
        $stmt = $mysqli->prepare($updateAdminSQL);
        $stmt->bind_param("ss", $password, $username);
        if (!$stmt->execute()) {
            throw new Exception('更新管理员用户失败: ' . $stmt->error);
        }
    } else {
        $insertAdminSQL = "INSERT INTO users (username, password) VALUES (?, ?)";
        $stmt = $mysqli->prepare($insertAdminSQL);
        $stmt->bind_param("ss", $username, $password);
        if (!$stmt->execute()) {
            throw new Exception('插入管理员用户失败: ' . $stmt->error);
        }
    }
}
function addGitHubConfig(&$configContent) {
    $configContent .= "\n[GitHub]\nrepoOwner = \nrepoName = \nbranch = \ngithubToken = \n";
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站安装</title>
    <link rel="shortcut icon" href="../static/favicon.ico">
    <link rel="stylesheet" type="text/css" href="../static/css/install.css">
    <script>
        function showNotification(message, className = 'msg-red') {
            const notification = document.createElement('div');
            notification.className = `msg ${className}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.classList.add('msg-right');
                setTimeout(() => notification.remove(), 800);
            }, 1500);
        }

        function generateToken() {
            const token = Array.from({length: 32}, () => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
            [Math.random() * 62 | 0]).join('');
            document.getElementById('validToken').value = token;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const generateTokenButton = document.getElementById('generateToken');
            if (generateTokenButton) {
                generateTokenButton.addEventListener('click', e => {
                    e.preventDefault();
                    generateToken();
                });
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <h2>网站安装向导</h2>
        <?php if ($step === 1): ?>
            <form method="POST">
                <?php if ($error): ?>
                    <script>
                        showNotification('<?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>', 'msg-red');
                    </script>
                <?php endif; ?>
                <div class="form-group">
                    <label for="mysql_dbHost">主机地址</label>
                    <input type="text" id="mysql_dbHost" name="mysql_dbHost" value="127.0.0.1" required>
                </div>
                <div class="form-group">
                    <label for="mysql_dbName">数据库名</label>
                    <input type="text" id="mysql_dbName" name="mysql_dbName" required>
                </div>
                <div class="form-group">
                    <label for="mysql_dbUser">用户名</label>
                    <input type="text" id="mysql_dbUser" name="mysql_dbUser" required>
                </div>
                <div class="form-group">
                    <label for="mysql_dbPass">密码</label>
                    <input type="password" id="mysql_dbPass" name="mysql_dbPass" required>
                </div>
                <div class="form-group">
                    <label for="mysql_adminUser">管理员账号</label>
                    <input type="text" id="mysql_adminUser" name="mysql_adminUser" value="<?= $adminUsername ?>" required>
                </div>
                <div class="form-group">
                    <label for="mysql_adminPass">管理员密码</label>
                    <input type="password" id="mysql_adminPass" name="mysql_adminPass" required>
                </div>
                <div class="form-group">
                    <input type="submit" value="下一步">
                </div>
            </form>
        <?php elseif ($step === 2): ?>
            <form method="POST">
                <div class="form-group">
                    <label for="storage">选择存储方式</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" id="storage_local" name="storage" value="local" required>
                            <span>Local</span>
                        </label>
                        <label>
                            <input type="radio" id="storage_github" name="storage" value="github" required>
                            <span>GitHub</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="protocol">协议头<span class="example-hint">URL协议头</span></label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" id="protocol_https" name="protocol" value="https://" required>
                            <span>HTTPS</span>
                        </label>
                        <label>
                            <input type="radio" id="protocol_http" name="protocol" value="http://" required>
                            <span>HTTP</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="validToken">API接口Token<button id="generateToken" class="generateToken">点我生成</button></label>
                    <input type="text" id="validToken" name="validToken" required>
                </div>
                <div class="form-group">
                    <input type="submit" value="下一步">
                </div>
            </form>
        <?php elseif ($step === 3): ?>
            <form method="POST">
                <div class="form-group">
                    <label for="github_repoOwner">GitHub 用户名</label>
                    <input type="text" id="github_repoOwner" name="github_repoOwner" required>
                </div>
                <div class="form-group">
                    <label for="github_repoName">GitHub 仓库名</label>
                    <input type="text" id="github_repoName" name="github_repoName" required>
                </div>
                <div class="form-group">
                    <label for="github_branch">GitHub 分支</label>
                    <input type="text" id="github_branch" name="github_branch" value="main" required>
                </div>
                <div class="form-group">
                    <label for="github_githubToken">GitHub Token</label>
                    <input type="text" id="github_githubToken" name="github_githubToken" required>
                </div>
                <div class="form-group">
                    <input type="submit" value="完成安装">
                </div>
            </form>
        <?php elseif ($step === 5): ?>
            <div>
                <p>安装成功</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>