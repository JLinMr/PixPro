<?php
session_status() === PHP_SESSION_NONE && session_start();
if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    require 'login.php';
    exit;
}

require_once '../includes/bootstrap.php';

function getAllowedSettingKeys() {
    static $keys = null;
    if ($keys === null) {
        $keys = ['storage', 'login_restriction', 'url_prefix', 'per_page', 'max_uploads_per_day', 'max_file_size', 'output_format'];
        foreach (Database::getStorageTypes() as $storage) {
            foreach ($storage['configs'] as $config) {
                $keys[] = $config['key'];
            }
        }
        $keys = array_values(array_unique($keys));
    }
    return $keys;
}

function normalizeConfigValue(string $key, string $value): string {
    $value = trim($value);
    if ($value === '' || (strpos($key, '_cdn_domain') === false && strpos($key, '_endpoint') === false)) {
        return $value;
    }

    $value = rtrim($value, '/');
    return preg_match('/^https?:\/\//', $value) ? $value : 'https://' . $value;
}

function getStorageTestRequiredKeys(string $storage): array {
    static $keys = [
        'oss' => ['oss_endpoint', 'oss_bucket', 'oss_access_key_id', 'oss_access_key_secret'],
        's3' => ['s3_endpoint', 's3_region', 's3_bucket', 's3_access_key_id', 's3_access_key_secret'],
        'upyun' => ['upyun_bucket', 'upyun_operator', 'upyun_password'],
    ];

    return $keys[$storage] ?? [];
}

function buildConfigFromPost(PDO $pdo, array $post): array {
    $config = Database::getConfig($pdo);

    foreach (getAllowedSettingKeys() as $key) {
        if (!array_key_exists($key, $post)) {
            continue;
        }

        $value = normalizeConfigValue($key, (string)$post[$key]);
        $config[$key] = $value;
    }

    return $config;
}

function validateStorageTestConfig(string $storage, array $config): ?string {
    foreach (getStorageTestRequiredKeys($storage) as $key) {
        if (trim($config[$key] ?? '') === '') {
            return '请先填写完整的存储配置';
        }
    }

    return null;
}

function renderFields($fields, $configs) {
    $halfWidthCount = 0;
    foreach($fields as $key => $field): 
        $isHalfWidth = $field['half_width'] ?? false;
        if ($isHalfWidth && $halfWidthCount % 2 === 0) echo '<div class="form-row">';
        
        $value = $key === 'max_file_size' ? ($configs[$key]['value'] / (1024 * 1024)) : ($configs[$key]['value'] ?? '');
        $esc = static function ($text) {
            return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        };
        ?>
        <div class="<?= $isHalfWidth ? 'form-group form-group-half' : 'form-group' ?>">
            <label for="<?= $esc($key) ?>">
                <?= $esc($field['name'] ?? $field['label']) ?>
                <?php if (!empty($field['description'])): ?>
                    <span class="label-description"><?= $esc($field['description']) ?></span>
                <?php endif; ?>
            </label>
            <?php if ($field['type'] === 'radio'): ?>
                <div class="radio-group">
                    <?php foreach($field['options'] as $val => $label): ?>
                        <label>
                            <input type="radio" name="<?= $esc($key) ?>" value="<?= $esc($val) ?>" 
                                   <?= ($configs[$key]['value'] ?? '') === $val ? 'checked' : '' ?>>
                            <span><?= $esc($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($field['type'] === 'password'): ?>
                <div class="password-wrapper">
                    <input type="password" class="glass-input" name="<?= $esc($key) ?>" id="<?= $esc($key) ?>" value="<?= $esc($value) ?>"
                           autocomplete="new-password"
                           placeholder="<?= $esc($field['placeholder'] ?? '') ?>">
                    <span class="toggle-password">
                        <svg class="icon" aria-hidden="true"><use xlink:href="#icon-eye"></use></svg>
                    </span>
                </div>
            <?php else: ?>
                <input type="<?= $esc($field['type']) ?>" class="glass-input" name="<?= $esc($key) ?>" id="<?= $esc($key) ?>" value="<?= $esc($value) ?>"
                       autocomplete="off"
                       <?= isset($field['min']) ? 'min="' . $esc($field['min']) . '"' : '' ?>
                       <?= isset($field['max']) ? 'max="' . $esc($field['max']) . '"' : '' ?>
                       placeholder="<?= $esc($field['placeholder'] ?? '') ?>">
            <?php endif; ?>
        </div>
        <?php
        if ($isHalfWidth && ++$halfWidthCount % 2 === 0) echo '</div>';
    endforeach;
}

function renderSettingsForm(PDO $pdo, bool $demoMode): void {
    $configs = array_column($pdo->query("SELECT * FROM configs ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), null, 'key');
    $storageTypes = Database::getStorageTypes();
    $stmt = $pdo->prepare("SELECT token FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userToken = $stmt->fetch(PDO::FETCH_ASSOC)['token'] ?? '';

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
        'label' => '上传限制',
        'type' => 'number',
        'min' => 0,
        'placeholder' => '建议设置为50',
        'description' => '每日上传次数，0为不限制',
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
    <form id="settings-form" method="POST" autocomplete="off">
        <div class="autofill-guard" aria-hidden="true" tabindex="-1">
            <input type="text" tabindex="-1" autocomplete="username">
            <input type="password" tabindex="-1" autocomplete="current-password">
        </div>
        <?php if ($demoMode): ?>
            <div class="demo-mode-warning">
                <span>⚠️ <strong>演示模式</strong> - 当前处于演示模式，所有设置修改将被禁止</span>
            </div>
        <?php endif; ?>
        <div class="settings-group glass">
            <div class="settings-header">
                <h2>基本设置</h2>
                <button type="button" class="close-modal glass-btn">
                    <svg class="icon" aria-hidden="true"><use xlink:href="#icon-xmark"></use></svg>
                </button>
            </div>
            <div class="form-group">
                <label>存储方式</label>
                <div class="radio-group">
                    <?php foreach($storageTypes as $value => $storage): ?>
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
                    <input type="text" class="glass-input" name="token" id="token-input" value="<?= htmlspecialchars($userToken, ENT_QUOTES, 'UTF-8') ?>" readonly autocomplete="off">
                    <button type="button" class="token-action-btn glass-btn copy-token" title="复制">
                        <svg class="icon" aria-hidden="true"><use xlink:href="#icon-copy"></use></svg>
                    </button>
                    <button type="button" class="token-action-btn glass-btn refresh-token" title="刷新">
                        <svg class="icon" aria-hidden="true"><use xlink:href="#icon-refresh"></use></svg>
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <label>系统维护</label>
                <div style="display: flex; gap: 10px;">
                    <button type="button" id="optimize-db-btn" class="update-btn glass-btn">优化数据库</button>
                    <button type="button" id="check-update-btn" class="update-btn glass-btn">检测更新</button>
                </div>
            </div>
        </div>
        
        <?php foreach ($storageTypes as $type => $storage): ?>
            <div class="settings-group glass" id="<?= $type ?>-settings" style="display: none;">
                <div class="settings-header">
                    <h2><?= $storage['name'] ?>设置</h2>
                    <?php if ($type !== 'local'): ?>
                        <button type="button" class="test-storage-btn glass-btn" data-storage="<?= $type ?>" disabled>测试连接</button>
                    <?php endif; ?>
                </div>
                <?php renderFields(array_column($storage['configs'], null, 'key'), $configs); ?>
            </div>
        <?php endforeach; ?>
        
        <button type="submit" name="submit" class="submit-btn glass-btn submit-btn-float">保存设置</button>
    </form>
</div>
<?php
}

if (basename($_SERVER['SCRIPT_FILENAME']) !== 'settings.php') {
    return;
}

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('HTTP/1.1 403 Forbidden');
    exit('禁止直接访问');
}

require_once '../includes/http.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$demoMode = ($_ENV['DEMO_MODE'] ?? 'false') === 'true';

if (!empty($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'test_storage':
                if (empty($_POST['storage_type'])) {
                    throw new Exception('未指定存储类型');
                }

                require_once '../includes/storage.php';
                $storageType = $_POST['storage_type'];
                $config = buildConfigFromPost($pdo, $_POST);

                if ($message = validateStorageTestConfig($storageType, $config)) {
                    jsonExit(['success' => false, 'message' => $message]);
                }

                try {
                    StorageHelper::testConnection($storageType, $config);
                    jsonExit(['success' => true, 'message' => '存储连接测试成功']);
                } catch (Exception $e) {
                    jsonExit([
                        'success' => false,
                        'message' => '存储连接失败，请检查配置',
                        'error' => $e->getMessage()
                    ]);
                }
                break;

            case 'check_update':
                $currentVersion = json_decode(file_get_contents(__DIR__ . '/../package.json'), true)['version'] ?? '2.0';

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

                jsonExit([
                    'success' => true,
                    'current' => $currentVersion,
                    'latest' => $latestVersion,
                    'hasUpdate' => $hasUpdate,
                    'isDev' => $isDev,
                    'url' => $isDev ? 'https://github.com/JLinMr/PixPro/tree/dev' : 'https://github.com/JLinMr/PixPro/releases/latest',
                    'message' => $isDev ? "您正在使用测试版本 V{$currentVersion}"
                        : ($hasUpdate ? "发现新版本 V{$latestVersion}" : '已是最新版本')
                ]);

            case 'optimize_db':
                $result = Database::optimize($pdo);

                jsonExit([
                    'success' => true,
                    'message' => '数据库优化完成，图片计数已同步',
                    'saved' => $result['saved'],
                    'image_count' => $result['image_count'],
                ]);

            default:
                throw new Exception('未知操作');
        }
    } catch (Exception $e) {
        jsonExit(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($demoMode) {
        jsonExit(['success' => false, 'message' => '演示模式下禁止修改设置']);
    }

    try {
        $pdo->beginTransaction();

        if (!empty($_POST['token'])) {
            $stmt = $pdo->prepare("UPDATE users SET token = ? WHERE id = ?");
            $stmt->execute([$_POST['token'], $_SESSION['user_id']]);
        }

        if (!empty($_POST['storage'])) {
            $storageConfig = Database::getStorageConfig($_POST['storage']);
            if ($storageConfig) {
                $existingKeys = array_column($pdo->query("SELECT `key` FROM configs")->fetchAll(PDO::FETCH_ASSOC), 'key');
                $insertStmt = $pdo->prepare("INSERT INTO configs (`key`, value, description) VALUES (?, ?, ?)");
                $updateDescStmt = $pdo->prepare("UPDATE configs SET description = ? WHERE `key` = ?");
                foreach ($storageConfig['configs'] as $config) {
                    if (!in_array($config['key'], $existingKeys)) {
                        $insertStmt->execute([$config['key'], $config['default'], $config['description'] ?? '']);
                    } else {
                        $updateDescStmt->execute([$config['description'] ?? '', $config['key']]);
                    }
                }
            }
        }

        $allowedKeys = getAllowedSettingKeys();
        $stmt = $pdo->prepare("UPDATE configs SET value = ? WHERE `key` = ?");
        foreach ($_POST as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                continue;
            }
            $stmt->execute([normalizeConfigValue($key, (string)$value), $key]);
        }

        $pdo->commit();
        Database::clearConfigCache();
        jsonExit(['success' => true, 'message' => '设置已更新']);
    } catch (Exception $e) {
        $pdo->rollback();
        jsonExit(['success' => false, 'message' => '更新失败: ' . $e->getMessage()]);
    }
}

jsonExit(['success' => false, 'message' => '不支持的请求'], 405);
