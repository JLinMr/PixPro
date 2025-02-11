<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . $host;
if (file_exists('../.env')) {
    header('Location: /');
    exit();
}

$step = isset($_GET['step']) ? intval($_GET['step']) : 0;
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
        
        try {
            $mysqli = new mysqli($mysql['dbHost'], $mysql['dbUser'], $mysql['dbPass'], $mysql['dbName']);
            if ($mysqli->connect_error) {
                throw new Exception($mysqli->connect_error);
            }

            $mysqli->begin_transaction();
            try {
                // 创建表结构
                createOrUpdateTableStructure($mysqli);
                
                // 创建管理员账户
                handleAdminUser($mysqli);
                
                // 初始化系统配置
                initializeConfigs($mysqli);
                
                // 保存数据库配置
                saveConfig($mysql);
                
                $mysqli->commit();
                
                header('Location: /'); 
                exit;
            } catch (Exception $e) {
                $mysqli->rollback();
                throw $e;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            if (strpos($error, 'Access denied') !== false) {
                $error = '数据库连接失败：用户名或密码错误';
            } elseif (strpos($error, 'Unknown database') !== false) {
                $error = '数据库连接失败：数据库不存在';
            } elseif (strpos($error, 'Connection refused') !== false) {
                $error = '数据库连接失败：无法连接到数据库服务器，请检查主机地址是否正确';
            } elseif (strpos($error, 'Can\'t connect to MySQL server') !== false) {
                $error = '数据库连接失败：无法连接到MySQL服务器，请检查服务器是否启动';
            } else {
                $error = '数据库连接失败：' . $error;
            }
        }
    }
}

function createOrUpdateTableStructure($mysqli) {
    // 创建 images 表
    $createImagesTableSQL = "
        CREATE TABLE IF NOT EXISTS images (
            id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主键ID',
            user_id INT UNSIGNED NULL COMMENT '用户ID',
            url VARCHAR(255) NOT NULL COMMENT '图片URL',
            path VARCHAR(255) NOT NULL COMMENT '图片存储路径',
            storage VARCHAR(50) NOT NULL COMMENT '存储方式',
            size INT UNSIGNED NOT NULL COMMENT '图片大小(字节)',
            upload_ip VARCHAR(45) NOT NULL COMMENT '上传者IP地址',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片信息表';
    ";
    
    // 创建 users 表
    $createUsersTableSQL = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY COMMENT '用户 ID',
            username VARCHAR(255) NOT NULL UNIQUE COMMENT '用户名',
            password VARCHAR(255) NOT NULL COMMENT '密码',
            token VARCHAR(32) NOT NULL UNIQUE COMMENT 'API Token'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';
    ";
    
    // 创建 configs 表
    $createConfigsTableSQL = "
        CREATE TABLE IF NOT EXISTS configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(50) NOT NULL UNIQUE,
            value TEXT,
            description VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表';
    ";

    if ($mysqli->query($createImagesTableSQL) === FALSE) {
        throw new Exception('创建 images 表失败: ' . $mysqli->error);
    }
    if ($mysqli->query($createUsersTableSQL) === FALSE) {
        throw new Exception('创建 users 表失败: ' . $mysqli->error);
    }
    if ($mysqli->query($createConfigsTableSQL) === FALSE) {
        throw new Exception('创建 configs 表失败: ' . $mysqli->error);
    }
}

function initializeConfigs($mysqli) {
    global $protocol;
    
    // 获取当前网站域名
    $host = $_SERVER['HTTP_HOST'];
    $siteUrl = $protocol . $host;
    
    $defaultConfigs = [
        ['storage', 'local', '存储方式'],
        ['protocol', $protocol, 'URL协议'],
        ['per_page', '20', '每页显示数量'],
        ['login_restriction', 'false', '登录保护'],
        ['max_file_size', '5242880', '最大文件大小'],
        ['max_uploads_per_day', '50', '每日上传限制'],
        ['output_format', 'webp', '输出图片格式'],
        ['site_domain', $siteUrl, '网站域名']
    ];

    $stmt = $mysqli->prepare("REPLACE INTO configs (`key`, value, description) VALUES (?, ?, ?)");
    foreach ($defaultConfigs as $config) {
        $stmt->bind_param("sss", $config[0], $config[1], $config[2]);
        $stmt->execute();
    }
}

function generateRandomToken() {
    return bin2hex(random_bytes(16));
}

function handleAdminUser($mysqli) {
    global $adminUserExists, $adminUsername;

    $username = $_POST['mysql_adminUser'];
    $password = password_hash($_POST['mysql_adminPass'], PASSWORD_DEFAULT);
    $token = generateRandomToken();

    $checkUserSQL = "SELECT id, username FROM users WHERE username = ?";
    $stmt = $mysqli->prepare($checkUserSQL);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $adminUserExists = true;
        $adminUsername = $username;

        $updateAdminSQL = "UPDATE users SET password = ?, token = ? WHERE username = ?";
        $stmt = $mysqli->prepare($updateAdminSQL);
        $stmt->bind_param("sss", $password, $token, $username);
        if (!$stmt->execute()) {
            throw new Exception('更新管理员用户失败: ' . $stmt->error);
        }
    } else {
        $insertAdminSQL = "INSERT INTO users (username, password, token) VALUES (?, ?, ?)";
        $stmt = $mysqli->prepare($insertAdminSQL);
        $stmt->bind_param("sss", $username, $password, $token);
        if (!$stmt->execute()) {
            throw new Exception('插入管理员用户失败: ' . $stmt->error);
        }
    }
}

function saveConfig($mysql) {
    $envContent = "# 数据库配置\n";
    $envContent .= "DB_HOST={$mysql['dbHost']}\n";
    $envContent .= "DB_NAME={$mysql['dbName']}\n";
    $envContent .= "DB_USER={$mysql['dbUser']}\n";
    $envContent .= "DB_PASS={$mysql['dbPass']}";
    
    file_put_contents('../.env', $envContent);
    chmod('../.env', 0600);
}

function checkEnvironment() {
    $requirements = [];
    
    // 检查 PHP 版本
    $phpVersion = phpversion();
    $requirements['PHP 版本'] = [
        'required' => '≥ 7.0',
        'status' => version_compare($phpVersion, '7.0.0', '>='),
        'current' => $phpVersion
    ];
    
    // 检查 MySQL
    $mysqlInstalled = extension_loaded('mysqli');
    $requirements['MySQL'] = [
        'required' => '≥ 5.6',
        'status' => $mysqlInstalled,
        'current' => $mysqlInstalled ? '已安装' : '未安装'
    ];
    
    // 检查必需的扩展
    $requiredExtensions = [
        'fileinfo',
        'exif',
        'imagick',
        'pcntl'
    ];
    
    foreach ($requiredExtensions as $ext) {
        $isLoaded = extension_loaded($ext);
        $requirements[strtoupper($ext)] = [
            'required' => '必需',
            'status' => $isLoaded,
            'current' => $isLoaded ? '已安装' : '未安装'
        ];
    }
    
    // 检查 PCNTL 函数
    $pcntlFunctions = [
        'pcntl_signal',
        'pcntl_alarm'
    ];
    
    foreach ($pcntlFunctions as $func) {
        $isAvailable = function_exists($func);
        $requirements[$func] = [
            'required' => '必需',
            'status' => $isAvailable,
            'current' => $isAvailable ? '可用' : '不可用',
            'description' => '请确保此函数未被禁用'
        ];
    }
    
    return $requirements;
}

if ($step === 0) {
    $requirements = checkEnvironment();
    $canProceed = true;
    foreach ($requirements as $requirement) {
        if (!$requirement['status']) {
            $canProceed = false;
            break;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站安装</title>
    <link rel="shortcut icon" href="../static/favicon.svg">
    <link rel="stylesheet" type="text/css" href="../install/install.css">
    <script>
    function showNotification(message, className = 'msg-green') {
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
        
        <?php if ($step === 0): ?>
            <table class="check-table">
                <tr>
                    <th>检测项目</th>
                    <th>要求</th>
                    <th>当前状态</th>
                </tr>
                <?php foreach ($requirements as $name => $requirement): ?>
                    <tr class="<?= $requirement['status'] ? 'success' : 'error' ?>">
                        <td>
                            <?= $name ?>
                            <?php if (isset($requirement['description'])): ?>
                                <small style="color: #666; display: block;">
                                    <?= $requirement['description'] ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><?= $requirement['required'] ?></td>
                        <td><?= $requirement['current'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            
            <?php if ($canProceed): ?>
                <div class="form-group">
                    <input type="button" value="下一步" onclick="window.location.href='?step=1'">
                </div>
            <?php else: ?>
                <div class="error-message">
                    请解决上述问题后继续安装
                    <a href="?step=1" class="force-install">强制安装</a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <form method="POST">
                <?php if ($error): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            showNotification('<?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>', 'msg-red');
                        });
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
                    <input type="submit" value="开始安装">
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>