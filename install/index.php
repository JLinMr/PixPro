<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (file_exists('../.env')) {
    header('Location: /');
    exit;
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    try {
        $pdo = new PDO('sqlite:../database.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();
        
        createTableStructure($pdo);
        handleAdminUser($pdo);
        initializeConfigs($pdo);
        saveConfig();
        
        $pdo->commit();
        header('Location: /');
        exit;
    } catch (Exception $e) {
        if (isset($pdo)) $pdo->rollback();
        $error = '安装失败：' . $e->getMessage();
    }
}

function createTableStructure($pdo) {
    $tables = [
        "CREATE TABLE IF NOT EXISTS images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            url VARCHAR(255) NOT NULL,
            path VARCHAR(255) NOT NULL,
            storage VARCHAR(50) NOT NULL,
            size INTEGER NOT NULL,
            upload_ip VARCHAR(45) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            token VARCHAR(32) NOT NULL UNIQUE
        )",
        "CREATE TABLE IF NOT EXISTS configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            `key` VARCHAR(50) NOT NULL UNIQUE,
            value TEXT,
            description VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    ];
    
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
}

function initializeConfigs($pdo) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $siteUrl = $protocol . $_SERVER['HTTP_HOST'];
    
    $configs = [
        ['storage', 'local', '存储方式'],
        ['url_prefix', '', '图片代理'],
        ['per_page', '20', '每页显示数量'],
        ['login_restriction', 'false', '登录保护'],
        ['max_file_size', '5242880', '最大文件大小'],
        ['max_uploads_per_day', '50', '每日上传限制'],
        ['output_format', 'webp', '输出图片格式'],
        ['site_domain', $siteUrl, '网站域名']
    ];

    $stmt = $pdo->prepare("REPLACE INTO configs (`key`, value, description) VALUES (?, ?, ?)");
    foreach ($configs as $config) {
        $stmt->execute($config);
    }
}

function handleAdminUser($pdo) {
    $username = $_POST['adminUser'];
    $password = password_hash($_POST['adminPass'], PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);

    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE users SET password = ?, token = ? WHERE username = ?")
            ->execute([$password, $token, $username]);
    } else {
        $pdo->prepare("INSERT INTO users (username, password, token) VALUES (?, ?, ?)")
            ->execute([$username, $password, $token]);
    }
}

function saveConfig() {
    $content = <<<ENV
# 演示模式配置
# 开启后：首页和设置页面会显示演示站点提示、禁止保存设置修改、所有图片公开可见且可能被删除
DEMO_MODE = false

# 密码重置功能（默认关闭）
# 使用方法：
# 1. 将下方的 false 改为 true
# 2. 访问登录页面，点击「忘记密码」重置密码
# 3. 重置完成后立即改回 false
# 警告：开启后任何人都可以重置管理员密码！
ALLOW_PASSWORD_RESET = false
ENV;
    
    file_put_contents('../.env', $content);
    chmod('../.env', 0600);
}

function checkEnvironment() {
    $checks = [
        ['PHP 版本', '≥ 7.0', phpversion(), version_compare(phpversion(), '7.0.0', '>=')],
        ['SQLite', 'PDO SQLite扩展', null, 'pdo_sqlite'],
        ['IMAGICK', '必需', null, 'imagick'],
        ['EXIF', '可选', null, 'exif', true]
    ];
    
    $requirements = [];
    foreach ($checks as $check) {
        $isExtension = isset($check[3]) && is_string($check[3]);
        $loaded = $isExtension ? extension_loaded($check[3]) : $check[3];
        
        $requirements[$check[0]] = [
            'required' => $check[1],
            'current' => $check[2] ?? ($loaded ? '已安装' : '未安装'),
            'status' => $check[4] ?? $loaded
        ];
    }
    
    return $requirements;
}

if ($step === 0) {
    $requirements = checkEnvironment();
    $canProceed = !in_array(false, array_column($requirements, 'status'), true);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站安装</title>
    <link rel="shortcut icon" href="../static/favicon.svg">
    <link rel="stylesheet" href="install.css">
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
                <?php foreach ($requirements as $name => $req): ?>
                    <tr class="<?= $req['status'] ? 'success' : 'error' ?>">
                        <td><?= $name ?></td>
                        <td><?= $req['required'] ?></td>
                        <td><?= $req['current'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            
            <div class="form-group">
                <?php if ($canProceed): ?>
                    <input type="button" value="下一步" onclick="location.href='?step=1'">
                <?php else: ?>
                    <div class="error-message">
                        请解决上述问题后继续安装
                        <a href="?step=1" class="force-install">强制安装</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="info-message">
                    <p>使用 SQLite 数据库，无需配置数据库连接</p>
                    <p>数据库文件将保存在: database.db</p>
                </div>
                <?php 
                $fields = [
                    ['adminUser', '管理员账号', 'text'],
                    ['adminPass', '管理员密码', 'password']
                ];
                foreach ($fields as [$id, $label, $type]): ?>
                    <div class="form-group">
                        <label for="<?= $id ?>"><?= $label ?></label>
                        <input type="<?= $type ?>" id="<?= $id ?>" name="<?= $id ?>" required>
                    </div>
                <?php endforeach; ?>
                <div class="form-group">
                    <input type="submit" value="开始安装">
                </div>
            </form>
        <?php endif; ?>
    </div>
    
    <?php if ($error): ?>
    <script>
        const div = document.createElement('div');
        div.className = 'msg msg-red';
        div.textContent = <?= json_encode($error) ?>;
        document.body.appendChild(div);
        setTimeout(() => {
            div.classList.add('msg-right');
            setTimeout(() => div.remove(), 800);
        }, 1500);
    </script>
    <?php endif; ?>
</body>
</html>
