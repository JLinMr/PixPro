<?php
session_start();
if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    require 'login.php';
    exit;
}

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('HTTP/1.1 403 Forbidden');
    exit('禁止直接访问');
}

require_once '../config/database.php';
$db = Database::getInstance();
$pdo = $db->getConnection();
$demoMode = ($_ENV['DEMO_MODE'] ?? 'false') === 'true';

// 处理AJAX请求
if (!empty($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        switch ($_POST['action']) {
            case 'test_storage':
                if (empty($_POST['storage_type'])) throw new Exception('未指定存储类型');
                
                require_once '../config/storage.php';
                $config = Database::getConfig($pdo);
                
                try {
                    StorageHelper::testConnection($_POST['storage_type'], $config);
                    echo json_encode(['success' => true, 'message' => '存储连接测试成功'], JSON_UNESCAPED_UNICODE);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false, 
                        'message' => '存储连接失败，请检查配置',
                        'error' => $e->getMessage()
                    ], JSON_UNESCAPED_UNICODE);
                }
                break;
                
            case 'check_update':
                $currentVersion = json_decode(file_get_contents('../package.json'), true)['version'] ?? '2.0';
                
                $context = stream_context_create([
                    'http' => [
                        'header' => "User-Agent: PHP\r\nAccept: application/vnd.github.v3+json",
                        'timeout' => 10
                    ]
                ]);
                
                $response = @file_get_contents('https://api.github.com/repos/JLinMr/PixPro/releases/latest', false, $context);
                if (!$response) throw new Exception('无法连接到 GitHub API');
                
                $latestVersion = ltrim(json_decode($response, true)['tag_name'] ?? '', 'v');
                $compareResult = version_compare($currentVersion, $latestVersion);
                
                $isDev = $compareResult > 0;
                $hasUpdate = $compareResult < 0;
                
                echo json_encode([
                    'success' => true,
                    'current' => $currentVersion,
                    'latest' => $latestVersion,
                    'hasUpdate' => $hasUpdate,
                    'isDev' => $isDev,
                    'url' => $isDev ? 'https://github.com/JLinMr/PixPro/tree/dev' : 'https://github.com/JLinMr/PixPro/releases/latest',
                    'message' => $isDev ? "您正在使用测试版本 V{$currentVersion}" 
                        : ($hasUpdate ? "发现新版本 V{$latestVersion}" : '已是最新版本')
                ], JSON_UNESCAPED_UNICODE);
                break;
                
            case 'optimize_db':
                $dbPath = dirname(__DIR__) . '/database.db';
                $sizeBefore = filesize($dbPath);
                
                $pdo->exec('VACUUM');
                $pdo->exec('ANALYZE');
                
                clearstatcache();
                $sizeAfter = filesize($dbPath);
                
                echo json_encode([
                    'success' => true,
                    'message' => '数据库优化完成',
                    'saved' => round(($sizeBefore - $sizeAfter) / 1024 / 1024, 2)
                ], JSON_UNESCAPED_UNICODE);
                break;
                
            default:
                throw new Exception('未知操作');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($demoMode) {
        exit(json_encode(['success' => false, 'message' => '演示模式下禁止修改设置']));
    }
    
    try {
        $pdo->beginTransaction();
        
        // 更新Token
        if (!empty($_POST['token'])) {
            $stmt = $pdo->prepare("UPDATE users SET token = ? WHERE id = ?");
            $stmt->execute([$_POST['token'], $_SESSION['user_id']]);
        }
        
        // 同步存储配置
        if (!empty($_POST['storage'])) {
            $storageConfig = Database::getStorageConfig($_POST['storage']);
            if ($storageConfig) {
                $existingKeys = array_column($pdo->query("SELECT `key` FROM configs")->fetchAll(PDO::FETCH_ASSOC), 'key');
                $stmt = $pdo->prepare("INSERT INTO configs (`key`, value, description) VALUES (?, ?, ?) ON CONFLICT(`key`) DO UPDATE SET description = excluded.description");
                foreach ($storageConfig['configs'] as $config) {
                    if (!in_array($config['key'], $existingKeys)) {
                        $stmt->execute([$config['key'], $config['default'], $config['description'] ?? '']);
                    } else {
                        $stmt = $pdo->prepare("UPDATE configs SET description = ? WHERE `key` = ?");
                        $stmt->execute([$config['description'] ?? '', $config['key']]);
                    }
                }
            }
        }
        
        // 更新配置
        $stmt = $pdo->prepare("UPDATE configs SET value = ? WHERE `key` = ?");
        foreach ($_POST as $key => $value) {
            if (!in_array($key, ['submit', 'token'])) {
                // 自动处理域名和endpoint
                if (!empty($value) && (strpos($key, '_cdn_domain') !== false || strpos($key, '_endpoint') !== false)) {
                    $value = rtrim($value, '/');
                    // 自动添加 https:// 协议头
                    if (!preg_match('/^https?:\/\//', $value)) {
                        $value = 'https://' . $value;
                    }
                }
                $stmt->execute([$value, $key]);
            }
        }
        
        $pdo->commit();
        exit(json_encode(['success' => true, 'message' => '设置已更新']));
    } catch (Exception $e) {
        $pdo->rollback();
        exit(json_encode(['success' => false, 'message' => '更新失败: ' . $e->getMessage()]));
    }
}

// 获取配置
$configs = array_column($pdo->query("SELECT * FROM configs ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), null, 'key');
$storageConfigs = json_decode(file_get_contents('../config/configs.json'), true);
$stmt = $pdo->prepare("SELECT token FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userToken = $stmt->fetch(PDO::FETCH_ASSOC)['token'] ?? '';

// 渲染字段
function renderFields($fields, $configs) {
    $halfWidthCount = 0;
    foreach($fields as $key => $field): 
        $isHalfWidth = $field['half_width'] ?? false;
        if ($isHalfWidth && $halfWidthCount % 2 === 0) echo '<div class="form-row">';
        
        $value = $key === 'max_file_size' ? ($configs[$key]['value'] / (1024 * 1024)) : ($configs[$key]['value'] ?? '');
        ?>
        <div class="<?= $isHalfWidth ? 'form-group form-group-half' : 'form-group' ?>">
            <label for="<?= $key ?>">
                <?= $field['name'] ?? $field['label'] ?>
                <?php if (!empty($field['description'])): ?>
                    <span class="label-description"><?= $field['description'] ?></span>
                <?php endif; ?>
            </label>
            <?php if ($field['type'] === 'radio'): ?>
                <div class="radio-group">
                    <?php foreach($field['options'] as $val => $label): ?>
                        <label>
                            <input type="radio" name="<?= $key ?>" value="<?= $val ?>" 
                                   <?= ($configs[$key]['value'] ?? '') === $val ? 'checked' : '' ?>>
                            <span><?= $label ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($field['type'] === 'password'): ?>
                <div class="password-wrapper">
                    <input type="password" name="<?= $key ?>" id="<?= $key ?>" value="<?= $value ?>"
                           placeholder="<?= $field['placeholder'] ?? '' ?>">
                    <span class="toggle-password">
                        <svg class="icon" aria-hidden="true"><use xlink:href="#icon-eye"></use></svg>
                    </span>
                </div>
            <?php else: ?>
                <input type="<?= $field['type'] ?>" name="<?= $key ?>" id="<?= $key ?>" value="<?= $value ?>"
                       <?= isset($field['min']) ? "min=\"{$field['min']}\"" : '' ?>
                       <?= isset($field['max']) ? "max=\"{$field['max']}\"" : '' ?>
                       placeholder="<?= $field['placeholder'] ?? '' ?>">
            <?php endif; ?>
        </div>
        <?php
        if ($isHalfWidth && ++$halfWidthCount % 2 === 0) echo '</div>';
    endforeach;
}

$basicSettings = [
    'url_prefix' => [
        'label' => '图片代理',
        'type' => 'text',
        'placeholder' => '例如：https://i1.wp.com/（留空则不使用）',
        'description' => '图片URL代理地址，可用于CDN加速或图片处理服务',
        'half_width' => true
    ],
    'per_page' => [
        'label' => '单页数量',
        'type' => 'number',
        'min' => 1,
        'max' => 100,
        'placeholder' => '建议设置为20',
        'description' => '后台单页显示图片数量',
        'half_width' => true
    ],
    'max_uploads_per_day' => [
        'label' => '每日限制',
        'type' => 'number',
        'min' => 1,
        'placeholder' => '建议设置为50',
        'description' => '每日上传次数限制',
        'half_width' => true
    ],
    'max_file_size' => [
        'label' => '文件大小',
        'type' => 'number',
        'min' => 1,
        'placeholder' => '建议设置为5',
        'description' => '单个文件大小限制(MB)',
        'half_width' => true
    ],
    'site_domain' => [
        'label' => '网站域名',
        'type' => 'text',
        'placeholder' => '例如：https://example.com,http://localhost',
        'description' => '用于验证上传，多个域名用英文逗号分隔，支持通配符 "*"'
    ],
    'output_format' => [
        'label' => '输出格式',
        'type' => 'radio',
        'options' => [
            'original' => '原格式',
            'webp' => 'WebP',
            'avif' => 'AVIF'
        ]
    ]
];
?>

<div class="settings-container">
    <form id="settings-form" method="POST">
        <?php if ($demoMode): ?>
            <div class="demo-mode-warning">
                <span>⚠️ <strong>演示模式</strong> - 当前处于演示模式，所有设置修改将被禁止</span>
            </div>
        <?php endif; ?>
        <div class="settings-group">
            <div class="settings-header">
                <h2>基本设置</h2>
                <button type="button" class="close-modal">
                    <svg class="icon" aria-hidden="true"><use xlink:href="#icon-xmark"></use></svg>
                </button>
            </div>
            <div class="form-group">
                <label>存储方式</label>
                <div class="radio-group">
                    <?php foreach($storageConfigs['storage_types'] as $value => $storage): ?>
                        <label>
                            <input type="radio" name="storage" value="<?= $value ?>" 
                                   <?= $configs['storage']['value'] === $value ? 'checked' : '' ?>>
                            <span><?= $storage['name'] ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <?php renderFields($basicSettings, $configs); ?>

            <div class="form-group">
                <label>开启登录保护</label>
                <div class="radio-group">
                    <?php foreach(['true' => '开启', 'false' => '关闭'] as $value => $label): ?>
                        <label>
                            <input type="radio" name="login_restriction" value="<?= $value ?>"
                                   <?= $configs['login_restriction']['value'] === $value ? 'checked' : '' ?>>
                            <span><?= $label ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>
                    API Token
                    <span class="label-description">用于API接口认证，请勿泄露，妥善保管</span>
                </label>
                <div class="token-input-group">
                    <input type="text" name="token" id="token-input" value="<?= $userToken ?>" readonly>
                    <button type="button" class="token-action-btn copy-token" title="复制">
                        <svg class="icon" aria-hidden="true"><use xlink:href="#icon-copy"></use></svg>
                    </button>
                    <button type="button" class="token-action-btn refresh-token" title="刷新">
                        <svg class="icon" aria-hidden="true"><use xlink:href="#icon-refresh"></use></svg>
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <label>系统维护</label>
                <div style="display: flex; gap: 10px;">
                    <button type="button" id="optimize-db-btn" class="update-btn">优化数据库</button>
                    <button type="button" id="check-update-btn" class="update-btn">检测更新</button>
                </div>
            </div>
        </div>
        
        <?php foreach ($storageConfigs['storage_types'] as $type => $storage): ?>
            <div class="settings-group" id="<?= $type ?>-settings" style="display: none;">
                <div class="settings-header">
                    <h2><?= $storage['name'] ?>设置</h2>
                    <?php if ($type !== 'local'): ?>
                        <button type="button" class="test-storage-btn" data-storage="<?= $type ?>">
                            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-link"></use></svg>
                            <span class="btn-text">测试</span>
                        </button>
                    <?php endif; ?>
                </div>
                <?php renderFields(array_column($storage['configs'], null, 'key'), $configs); ?>
            </div>
        <?php endforeach; ?>
        
        <button type="submit" name="submit" class="submit-btn submit-btn-float">保存设置</button>
    </form>
</div>
