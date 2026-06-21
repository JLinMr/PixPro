<?php
/**
 * MySQL 到 SQLite 数据迁移脚本
 * 
 * 使用方法：
 * 1. 确保 .env 文件中包含 MySQL 配置
 * 2. 访问: http://your-domain/migrate.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(0);

$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
$error = '';

require_once __DIR__ . '/includes/database.php';

function createPixProTables(PDO $pdo) {
    $pdo->exec(
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            token VARCHAR(32) NOT NULL UNIQUE
        )'
    );
    $pdo->exec(
        'CREATE TABLE configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            `key` VARCHAR(50) NOT NULL UNIQUE,
            value TEXT,
            description VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
            url VARCHAR(255) NOT NULL,
            path VARCHAR(255) NOT NULL,
            storage VARCHAR(50) NOT NULL,
            size INTEGER NOT NULL,
            upload_ip VARCHAR(45) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )'
    );
    foreach ([
        'CREATE INDEX idx_images_user_created ON images(user_id, created_at)',
        'CREATE INDEX idx_images_ip_created ON images(upload_ip, created_at)',
        'CREATE INDEX idx_images_path ON images(path)',
        'CREATE INDEX idx_images_id_desc ON images(id DESC)',
    ] as $sql) {
        $pdo->exec($sql);
    }
}

function finalizeMigration(PDO $pdo) {
    $existing = array_column(
        $pdo->query('SELECT `key` FROM configs')->fetchAll(PDO::FETCH_ASSOC),
        'key'
    );
    $fixDetails = ['renamed' => [], 'deleted' => [], 'added' => []];

    foreach ([
        's3_custom_url_prefix' => 's3_cdn_domain',
        'upyun_domain' => 'upyun_cdn_domain',
    ] as $old => $new) {
        if (in_array($old, $existing, true)) {
            $pdo->prepare('UPDATE configs SET `key` = ? WHERE `key` = ?')->execute([$new, $old]);
            $fixDetails['renamed'][] = "$old → $new";
            $key = array_search($old, $existing, true);
            if ($key !== false) {
                $existing[$key] = $new;
            }
        }
    }

    foreach (['protocol', 'site_domain'] as $field) {
        if (in_array($field, $existing, true)) {
            $pdo->prepare('DELETE FROM configs WHERE `key` = ?')->execute([$field]);
            $fixDetails['deleted'][] = $field;
        }
    }

    try {
        $pdo->exec('ALTER TABLE configs DROP COLUMN updated_at');
        $fixDetails['deleted'][] = 'updated_at (表字段)';
    } catch (Exception $e) {
    }

    $imageCount = (int)$pdo->query('SELECT COUNT(id) FROM images')->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO configs (`key`, value, description) VALUES (?, ?, ?)');
    foreach ([
        'url_prefix' => ['', '图片代理'],
        'local_cdn_domain' => ['', '本地CDN域名'],
        'output_format' => ['webp', '输出图片格式'],
        'image_count' => [(string)$imageCount, '图片总数缓存'],
    ] as $field => $data) {
        if (!in_array($field, $existing, true)) {
            $stmt->execute([$field, $data[0], $data[1]]);
            $fixDetails['added'][] = $field;
        }
    }

    $pdo->prepare("UPDATE configs SET value = ? WHERE `key` = 'image_count'")->execute([(string)$imageCount]);

    return $fixDetails;
}

// 检查环境
function checkEnvironment() {
    $envFile = PIXPRO_ROOT . '/.env';
    $envExists = file_exists($envFile);
    $checks = [
        ['.env 文件', '必需', $envExists ? '存在' : '不存在', $envExists],
        ['SQLite', 'PDO SQLite扩展', extension_loaded('pdo_sqlite') ? '已安装' : '未安装', extension_loaded('pdo_sqlite')],
        ['MySQL', 'PDO MySQL扩展', extension_loaded('pdo_mysql') ? '已安装' : '未安装', extension_loaded('pdo_mysql')]
    ];

    if ($envExists) {
        $env = loadEnv($envFile);
        $hasMysql = isset($env['DB_HOST'], $env['DB_NAME']);
        $checks[] = ['MySQL 配置', '必需', $hasMysql ? '已配置' : '未配置', $hasMysql];
    }

    return $checks;
}

session_start();

$envFile = PIXPRO_ROOT . '/.env';
$env = file_exists($envFile) ? loadEnv($envFile) : [];
$hasMysqlConfig = !empty($env['DB_HOST']) && !empty($env['DB_NAME']);

if (!$hasMysqlConfig) {
    http_response_code(403);
    die('迁移不可用：未检测到 MySQL 配置，系统可能已完成迁移。');
}

if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    header('Location: /admin/');
    exit;
}

// 执行迁移
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    try {
        $envFile = PIXPRO_ROOT . '/.env';
        if (!file_exists($envFile)) throw new Exception('.env 文件不存在');
        
        $env = loadEnv($envFile);
        if (!isset($env['DB_HOST'])) throw new Exception('未检测到 MySQL 配置');
        
        // 连接 MySQL
        $mysqli = new mysqli($env['DB_HOST'], $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME']);
        if ($mysqli->connect_error) throw new Exception('MySQL 连接失败: ' . $mysqli->connect_error);
        $mysqli->set_charset('utf8mb4');
        
        // 创建 SQLite
        $sqliteDbPath = PIXPRO_ROOT . '/database.db';
        if (file_exists($sqliteDbPath)) {
            $backup = $sqliteDbPath . '.backup.' . date('YmdHis');
            copy($sqliteDbPath, $backup);
            unlink($sqliteDbPath);
        }
        
        $pdo = new PDO('sqlite:' . $sqliteDbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        createPixProTables($pdo);
        
        // 迁移数据
        $stats = ['users' => 0, 'configs' => 0, 'images' => 0];
        
        // 迁移 users
        $result = $mysqli->query("SELECT * FROM users");
        $stmt = $pdo->prepare("INSERT INTO users (id, username, password, token) VALUES (?, ?, ?, ?)");
        while ($row = $result->fetch_assoc()) {
            $stmt->execute([$row['id'], $row['username'], $row['password'], $row['token']]);
            $stats['users']++;
        }
        
        // 迁移 configs
        $result = $mysqli->query("SELECT * FROM configs");
        $stmt = $pdo->prepare("INSERT INTO configs (id, `key`, value, description, created_at) VALUES (?, ?, ?, ?, ?)");
        while ($row = $result->fetch_assoc()) {
            $stmt->execute([
                $row['id'],
                $row['key'],
                $row['value'],
                $row['description'],
                $row['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $stats['configs']++;
        }
        
        // 迁移 images（分批）
        $result = $mysqli->query("SELECT COUNT(*) as total FROM images");
        $total = $result->fetch_assoc()['total'];
        
        $stmt = $pdo->prepare("INSERT INTO images (id, user_id, url, path, storage, size, upload_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $batchSize = 1000;
        
        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            $result = $mysqli->query("SELECT * FROM images ORDER BY id LIMIT $batchSize OFFSET $offset");
            $pdo->beginTransaction();
            while ($row = $result->fetch_assoc()) {
                $stmt->execute([$row['id'], $row['user_id'], $row['url'], $row['path'], $row['storage'], $row['size'], $row['upload_ip'], $row['created_at']]);
                $stats['images']++;
            }
            $pdo->commit();
        }
        
        // 更新 .env
        $envBackup = $envFile . '.mysql.backup.' . date('YmdHis');
        copy($envFile, $envBackup);
        
        $envContent = <<<ENV
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
        
        file_put_contents($envFile, $envContent);
        chmod($envFile, 0600);
        
        $fixDetails = finalizeMigration($pdo);
        
        $mysqli->close();
        
        // 保存到 session
        $_SESSION['migration_stats'] = $stats;
        $_SESSION['migration_fix'] = $fixDetails;
        
        header('Location: ?step=2');
        exit;
        
    } catch (Exception $e) {
        $error = '迁移失败：' . $e->getMessage();
    }
}

if ($step === 0) {
    $checks = checkEnvironment();
    $canProceed = !in_array(false, array_column($checks, 3), true);
} elseif ($step === 2) {
    $stats = $_SESSION['migration_stats'] ?? null;
    $fixDetails = $_SESSION['migration_fix'] ?? null;
    
    // 如果没有数据，重定向回步骤0
    if (!$stats) {
        header('Location: ?step=0');
        exit;
    }
    
    // 清除 session
    unset($_SESSION['migration_stats'], $_SESSION['migration_fix']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库迁移</title>
    <link rel="shortcut icon" href="static/favicon.svg">
    <link rel="stylesheet" href="/static/css/auth/install.css">
</head>
<body>
    <div class="auth-card glass">
        <h2>MySQL 到 SQLite 数据迁移</h2>
        
        <?php if ($step === 0): ?>
            <div class="info-message">
                <p>此工具将帮助您从 MySQL 迁移到 SQLite 数据库</p>
                <p>迁移过程将自动备份现有数据</p>
            </div>
            
            <table class="check-table">
                <tr>
                    <th>检测项目</th>
                    <th>要求</th>
                    <th>当前状态</th>
                </tr>
                <?php foreach ($checks as $check): ?>
                    <tr class="<?= $check[3] ? 'success' : 'error' ?>">
                        <td><?= $check[0] ?></td>
                        <td><?= $check[1] ?></td>
                        <td><?= $check[2] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            
            <div class="form-group">
                <?php if ($canProceed): ?>
                    <input type="button" value="下一步" onclick="location.href='?step=1'">
                <?php else: ?>
                    <div class="error-message">
                        请解决上述问题后继续迁移
                    </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($step === 1): ?>
            <form method="POST">
                <div class="info-message">
                    <h3>⚠️ 重要提示</h3>
                    <p>此操作将：</p>
                    <ul style="margin: 10px 0; padding-left: 25px; line-height: 1.8;">
                        <li>从 MySQL 迁移所有数据到 SQLite</li>
                        <li>备份现有 SQLite 数据库（如果存在）</li>
                        <li>备份当前 .env 配置文件</li>
                        <li>更新 .env 为 SQLite 配置</li>
                    </ul>
                    <p><strong>请确保已备份重要数据！</strong></p>
                </div>
                
                <div class="form-group" style="display: flex; gap: 10px;">
                    <input type="button" value="取消" onclick="location.href='/'" style="flex: 1;">
                    <input type="submit" value="确认迁移" style="flex: 1;">
                </div>
            </form>
            
        <?php else: ?>
            <div class="info-message">
                <h3>✅ 迁移完成</h3>
                <div style="margin: 20px 0;">
                    <p style="margin: 10px 0;"><strong>数据统计：</strong></p>
                    <ul style="margin: 10px 0; padding-left: 25px; line-height: 1.8;">
                        <li>用户数据：<?= $stats['users'] ?> 条</li>
                        <li>配置数据：<?= $stats['configs'] ?> 条</li>
                        <li>图片数据：<?= $stats['images'] ?> 条</li>
                    </ul>
                    
                    <?php if ($fixDetails && (count($fixDetails['renamed']) > 0 || count($fixDetails['deleted']) > 0 || count($fixDetails['added']) > 0)): ?>
                        <p style="margin: 15px 0 10px 0;"><strong>字段修复：</strong></p>
                        <ul style="margin: 10px 0; padding-left: 25px; line-height: 1.8;">
                            <?php if (count($fixDetails['renamed']) > 0): ?>
                                <li>重命名字段：<?= implode('、', $fixDetails['renamed']) ?></li>
                            <?php endif; ?>
                            <?php if (count($fixDetails['deleted']) > 0): ?>
                                <li>删除字段：<?= implode('、', $fixDetails['deleted']) ?></li>
                            <?php endif; ?>
                            <?php if (count($fixDetails['added']) > 0): ?>
                                <li>新增字段：<?= implode('、', $fixDetails['added']) ?></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 152, 0, 0.1); border-radius: 8px; border-left: 4px solid #ff9800;">
                    <p style="margin: 0 0 10px 0;"><strong>⚠️ 重要提示：</strong></p>
                    <ol style="margin: 0; padding-left: 25px; line-height: 1.8;">
                        <li>删除此迁移脚本文件：migrate.php</li>
                        <li>检查 .env 配置文件是否正确</li>
                        <li>测试网站功能是否正常</li>
                        <li>确认图片上传和访问正常</li>
                        <li>备份文件已保存，可在需要时恢复</li>
                    </ol>
                </div>
            </div>
            
            <div class="form-group">
                <input type="button" value="返回首页" onclick="location.href='/'">
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-message" style="margin-top: 20px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>