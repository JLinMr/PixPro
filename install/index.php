<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (file_exists('install.lock')) {
    echo '
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>系统已安装</title>
        <link rel="shortcut icon" href="../static/favicon.ico">
        <link rel="stylesheet" type="text/css" href="style.css?v=1.7.5">
    </head>
    <body>
        <div class="message-box">
            <h1>系统已经安装成功</h1>
            <p>如需重新安装，请删除install/install.lock文件</p>
            <a href="/">回到首页</a>
        </div>
    </body>
    </html>
    ';
    die();
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

        $configContent = file_get_contents('../config/config.ini');
        $configContent .= "\n[Token]\n";
        $configContent .= "validToken = $validToken\n";
        $configContent .= "; // 为了站点安全，不建议你暴漏你的token\n";

        $configContent .= "\n[Other]\n";
        $configContent .= "storage = $storage\n";
        $configContent .= "protocol = $protocol\n";
        $configContent .= "per_page = 45\n";
        $configContent .= "; // storage = local 本地存储  //  oss  阿里云对象存储  //  s3  AWS S3 兼容三方\n";
        $configContent .= "; // protocol  配置图片URL协议头，如果你有证书建议使用https  S3 无需配置\n";
        $configContent .= "; // per_page  后台每页显示的图片数量，默认45  *** 其他设置查看 validate.php 文件\n";
        $configContent .= "; // 请不要删除OSS和S3配置项，否则会发生一些小意外\n";

        if ($storage === 'local') {
            addOSSConfig($configContent);
            addS3Config($configContent);
        } elseif ($storage === 'oss') {
            addS3Config($configContent);
        } elseif ($storage === 's3') {
            addOSSConfig($configContent);
        }

        file_put_contents('../config/config.ini', $configContent);
        chmod('../config/config.ini', 0600);

        if ($storage === 'local') {
            file_put_contents('install.lock', '安装锁');
            header('Location: ?step=5');
            exit;
        } elseif ($storage === 'oss') {
            header('Location: ?step=3');
            exit;
        } elseif ($storage === 's3') {
            header('Location: ?step=4');
            exit;
        }
    } elseif ($step === 3) {
        handleOSSConfig();
    } elseif ($step === 4) {
        handleS3Config();
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

function createOrUpdateTableStructure($mysqli) {
    $createImagesTableSQL = "
        CREATE TABLE IF NOT EXISTS images (
            id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主键ID',
            user_id INT UNSIGNED NULL COMMENT '用户ID',
            url VARCHAR(255) NOT NULL COMMENT '图片URL',
            path VARCHAR(255) NOT NULL COMMENT '图片存储路径',
            storage ENUM('oss', 'local', 's3', 'other') NOT NULL COMMENT '存储方式',
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

function addOSSConfig(&$configContent) {
    $configContent .= "\n[OSS]\n";
    $configContent .= "accessKeyId = \n";
    $configContent .= "accessKeySecret = \n";
    $configContent .= "endpoint = \n";
    $configContent .= "bucket = \n";
    $configContent .= "cdndomain = \n";
}

function addS3Config(&$configContent) {
    $configContent .= "\n[S3]\n";
    $configContent .= "s3Region = \n";
    $configContent .= "s3Bucket = \n";
    $configContent .= "s3Endpoint = \n";
    $configContent .= "s3AccessKeyId = \n";
    $configContent .= "s3AccessKeySecret = \n";
    $configContent .= "customUrlPrefix = \n";
}

function handleOSSConfig() {
    $oss = [
        'accessKeyId' => $_POST['oss_accessKeyId'],
        'accessKeySecret' => $_POST['oss_accessKeySecret'],
        'endpoint' => $_POST['oss_endpoint'],
        'bucket' => $_POST['oss_bucket'],
        'cdndomain' => $_POST['oss_cdndomain'],
    ];

    $configContent = file_get_contents('../config/config.ini');
    $configContent .= "\n[OSS]\n";
    foreach ($oss as $key => $value) {
        $configContent .= "$key = $value\n";
    }

    file_put_contents('../config/config.ini', $configContent);
    chmod('../config/config.ini', 0600);

    file_put_contents('install.lock', '安装锁');
    header('Location: ?step=5');
    exit;
}

function handleS3Config() {
    $s3 = [
        's3Region' => $_POST['s3_region'],
        's3Bucket' => $_POST['s3_bucket'],
        's3Endpoint' => $_POST['s3_endpoint'],
        's3AccessKeyId' => $_POST['s3_accessKeyId'],
        's3AccessKeySecret' => $_POST['s3_accessKeySecret'],
        'customUrlPrefix' => $_POST['s3_customUrlPrefix'],
    ];

    $configContent = file_get_contents('../config/config.ini');
    $configContent .= "\n[S3]\n";
    foreach ($s3 as $key => $value) {
        $configContent .= "$key = $value\n";
    }

    file_put_contents('../config/config.ini', $configContent);
    chmod('../config/config.ini', 0600);

    file_put_contents('install.lock', '安装锁');
    header('Location: ?step=5');
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站安装</title>
    <link rel="shortcut icon" href="../static/favicon.ico">
    <link rel="stylesheet" type="text/css" href="style.css?v=1.7.5">
    <script type="text/javascript" src="script.js?v=1.7.5" defer></script>
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
                            <input type="radio" id="storage_oss" name="storage" value="oss" required>
                            <span>OSS</span>
                        </label>
                        <label>
                            <input type="radio" id="storage_s3" name="storage" value="s3" required>
                            <span>S3</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="protocol">协议头<span style="margin-left: 15px;color: #ff0000;">URL协议头，本地存储和OSS需要配置</span></label>
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
                    <label for="validToken">API接口Token<botton id="generateToken" style="margin-left: 15px;color: #ff0000;cursor: pointer;">点我生成</botton></label>
                    <input type="text" id="validToken" name="validToken" required>
                </div>
                <div class="form-group">
                    <input type="submit" value="下一步">
                </div>
            </form>
        <?php elseif ($step === 3): ?>
            <form method="POST">
                <div class="form-group">
                    <label for="oss_accessKeyId">OSS Access Key ID</label>
                    <input type="text" id="oss_accessKeyId" name="oss_accessKeyId" required>
                </div>
                <div class="form-group">
                    <label for="oss_accessKeySecret">OSS Access Key Secret</label>
                    <input type="text" id="oss_accessKeySecret" name="oss_accessKeySecret" required>
                </div>
                <div class="form-group">
                    <label for="oss_endpoint">OSS Endpoint</label>
                    <input type="text" id="oss_endpoint" name="oss_endpoint" required>
                </div>
                <div class="form-group">
                    <label for="oss_bucket">OSS Bucket</label>
                    <input type="text" id="oss_bucket" name="oss_bucket" required>
                </div>
                <div class="form-group">
                    <label for="oss_cdndomain">OSS CDN 域名</label>
                    <input type="text" id="oss_cdndomain" name="oss_cdndomain" value="oss-cdn.your-domain.com" required>
                </div>
                <div class="form-group">
                    <input type="submit" value="完成安装">
                </div>
            </form>
        <?php elseif ($step === 4): ?>
            <form method="POST">
                <div class="form-group">
                    <label for="s3_region">S3 Region</label>
                    <input type="text" id="s3_region" name="s3_region" required>
                </div>
                <div class="form-group">
                    <label for="s3_bucket">S3 Bucket</label>
                    <input type="text" id="s3_bucket" name="s3_bucket" required>
                </div>
                <div class="form-group">
                    <label for="s3_endpoint">S3 Endpoint<span style="margin-left: 15px;color: #ccc;">举个例子: https://s3.ap-northeast-2.amazonaws.com</span></label>
                    <input type="text" id="s3_endpoint" name="s3_endpoint" required>
                </div>
                <div class="form-group">
                    <label for="s3_accessKeyId">S3 Access Key ID</label>
                    <input type="text" id="s3_accessKeyId" name="s3_accessKeyId" required>
                </div>
                <div class="form-group">
                    <label for="s3_accessKeySecret">S3 Access Key Secret</label>
                    <input type="text" id="s3_accessKeySecret" name="s3_accessKeySecret" required>
                </div>
                <div class="form-group">
                    <label for="s3_customUrlPrefix">S3 自定义前缀<span style="margin-left: 15px;color: #ccc;">兼容第三方添加的配置</span></label>
                    <input type="text" id="s3_customUrlPrefix" name="s3_customUrlPrefix" placeholder="非必填">
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
    <script>
    // 生成随机 Token
    document.getElementById('generateToken').addEventListener('click', e => {
        e.preventDefault();
        const token = Array.from({length: 32}, () => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
        [Math.random() * 62 | 0]).join('');
        document.getElementById('validToken').value = token;
    });
    </script>
</body>
</html>