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
$mysqli = $db->getConnection();

// 检查是否为演示模式
$demoMode = ($_ENV['DEMO_MODE'] ?? 'false') === 'true';

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 演示模式禁止保存
    if ($demoMode) {
        exit(json_encode(['success' => false, 'message' => '演示模式下禁止修改设置']));
    }
    
    try {
        $mysqli->begin_transaction();
        
        // 更新Token
        if (!empty($_POST['token'])) {
            $stmt = $mysqli->prepare("UPDATE users SET token = ? WHERE id = ?");
            $stmt->bind_param("si", $_POST['token'], $_SESSION['user_id']);
            $stmt->execute();
        }
        
        // 同步存储配置
        if (!empty($_POST['storage'])) {
            $storageConfig = Database::getStorageConfig($_POST['storage']);
            if ($storageConfig) {
                $result = $mysqli->query("SELECT `key` FROM configs")->fetch_all(MYSQLI_ASSOC);
                $existingKeys = array_column($result, 'key');
                $stmt = $mysqli->prepare("INSERT INTO configs (`key`, value, description) VALUES (?, ?, ?)");
                foreach ($storageConfig['configs'] as $config) {
                    if (!in_array($config['key'], $existingKeys)) {
                        $description = $config['description'] ?? '';
                        $stmt->bind_param("sss", $config['key'], $config['default'], $description);
                        $stmt->execute();
                    }
                }
            }
        }
        
        // 更新配置
        $stmt = $mysqli->prepare("UPDATE configs SET value = ? WHERE `key` = ?");
        foreach ($_POST as $key => $value) {
            if (!in_array($key, ['submit', 'token'])) {
                $stmt->bind_param("ss", $value, $key);
                $stmt->execute();
            }
        }
        
        $mysqli->commit();
        exit(json_encode(['success' => true, 'message' => '设置已更新']));
    } catch (Exception $e) {
        $mysqli->rollback();
        exit(json_encode(['success' => false, 'message' => '更新失败: ' . $e->getMessage()]));
    }
}

// 获取配置
$result = $mysqli->query("SELECT * FROM configs ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$configs = array_column($result, null, 'key');
$storageConfigs = json_decode(file_get_contents('../config/configs.json'), true);
$userToken = $mysqli->query("SELECT token FROM users WHERE id = {$_SESSION['user_id']}")->fetch_assoc()['token'];

// 渲染字段
function renderFields($fields, $configs) {
    $halfWidthCount = 0;
    foreach($fields as $key => $field): 
        $isHalfWidth = $field['half_width'] ?? false;
        if ($isHalfWidth && $halfWidthCount % 2 === 0) echo '<div class="form-row">';
        
        $value = $key === 'max_file_size' ? ($configs[$key]['value'] / (1024 * 1024)) : ($configs[$key]['value'] ?? '');
        $groupClass = $isHalfWidth ? 'form-group form-group-half' : 'form-group';
        ?>
        <div class="<?= $groupClass ?>">
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
            <?php else: ?>
                <input type="<?= $field['type'] ?>" name="<?= $key ?>" id="<?= $key ?>" value="<?= $value ?>"
                       <?= isset($field['min']) ? "min=\"{$field['min']}\"" : '' ?>
                       <?= isset($field['max']) ? "max=\"{$field['max']}\"" : '' ?>
                       placeholder="<?= $field['placeholder'] ?? '' ?>">
            <?php endif; ?>
        </div>
        <?php
        if ($isHalfWidth) {
            $halfWidthCount++;
            if ($halfWidthCount % 2 === 0) echo '</div>';
        }
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
                <label>系统更新</label>
                <div style="display: flex; gap: 10px;">
                    <button type="button" id="check-db-update-btn" class="update-btn">
                        数据库检测
                    </button>
                    <button type="button" id="check-version-update-btn" class="update-btn">
                        程序更新
                    </button>
                </div>
            </div>
        </div>
        
        <?php foreach ($storageConfigs['storage_types'] as $type => $storage): ?>
            <div class="settings-group" id="<?= $type ?>-settings" style="display: none;">
                <h2><?= $storage['name'] ?>设置</h2>
                <?php renderFields(array_column($storage['configs'], null, 'key'), $configs); ?>
            </div>
        <?php endforeach; ?>
        
        <button type="submit" name="submit" class="submit-btn submit-btn-float">
            保存设置
        </button>
    </form>
</div>
