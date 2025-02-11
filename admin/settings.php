<?php
session_start();
if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    require 'login.php';
    exit;
}

require_once '../config/database.php';
$db = Database::getInstance();
$mysqli = $db->getConnection();

class ConfigManager {
    private $mysqli;
    
    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }
    
    public function syncConfigs($storageType) {
        $storageConfig = Database::getStorageConfig($storageType);
        if (!$storageConfig) return false;
        
        $existingConfigs = $this->getExistingConfigKeys();
        $newConfigs = array_filter(
            $storageConfig['configs'],
            fn($config) => !in_array($config['key'], $existingConfigs)
        );
        
        if ($newConfigs) {
            $this->insertNewConfigs($newConfigs);
        }
        return true;
    }
    
    private function getExistingConfigKeys() {
        return array_column(
            $this->mysqli->query("SELECT `key` FROM configs")->fetch_all(MYSQLI_ASSOC),
            'key'
        );
    }
    
    private function insertNewConfigs($configs) {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO configs (`key`, value, description) VALUES (?, ?, ?)"
        );
        foreach ($configs as $config) {
            $stmt->bind_param(
                "sss", 
                $config['key'], 
                $config['default'], 
                $config['description']
            );
            $stmt->execute();
        }
    }
    
    public function updateConfigs($data) {
        $stmt = $this->mysqli->prepare("UPDATE configs SET value = ? WHERE `key` = ?");
        foreach ($data as $key => $value) {
            if ($key !== 'submit') {
                $stmt->bind_param("ss", $value, $key);
                $stmt->execute();
            }
        }
    }
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $mysqli->begin_transaction();
        
        $configManager = new ConfigManager($mysqli);
        
        if (isset($_POST['token']) && !empty($_POST['token'])) {
            $newToken = $_POST['token'];
            $userId = $_SESSION['user_id'];
            $stmt = $mysqli->prepare("UPDATE users SET token = ? WHERE id = ?");
            $stmt->bind_param("si", $newToken, $userId);
            $stmt->execute();
        }
        
        if (!empty($_POST['storage']) && $_POST['storage'] !== 'local') {
            $configManager->syncConfigs($_POST['storage']);
        }
        
        $configManager->updateConfigs($_POST);
        
        $mysqli->commit();
        exit(json_encode(['success' => true, 'message' => '设置已更新']));
    } catch (Exception $e) {
        $mysqli->rollback();
        exit(json_encode(['success' => false, 'message' => '更新失败: ' . $e->getMessage()]));
    }
}

$configs = array_column(
    $mysqli->query("SELECT * FROM configs ORDER BY id")->fetch_all(MYSQLI_ASSOC),
    null,
    'key'
);

$storageConfigs = json_decode(file_get_contents('../config/configs.json'), true);

$userId = $_SESSION['user_id'];
$tokenResult = $mysqli->query("SELECT token FROM users WHERE id = $userId");
$userToken = $tokenResult->fetch_assoc()['token'];
?>

<div class="settings-container">
    <button class="close-modal"><img src="/static/images/svg/xmark.svg" alt="关闭"></button>
    <form id="settings-form" method="POST">
        <!-- 基本设置 -->
        <div class="settings-group">
            <h2>基本设置</h2>
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
            
            <div class="form-group">
                <label>URL协议</label>
                <div class="radio-group">
                    <?php 
                    $protocols = [
                        'http://' => 'HTTP',
                        'https://' => 'HTTPS',
                        'custom' => '自定义'
                    ];
                    foreach($protocols as $value => $label): 
                        $isChecked = $value === 'custom' 
                            ? !in_array($configs['protocol']['value'], ['http://', 'https://'])
                            : $configs['protocol']['value'] === $value;
                    ?>
                        <label>
                            <input type="radio" name="protocol" value="<?= $value ?>" 
                                   <?= $isChecked ? 'checked' : '' ?>>
                            <span><?= $label ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div id="custom-protocol-input" class="custom-input" style="display: none;">
                    <input type="text" name="custom_protocol" id="custom_protocol" 
                           value="<?= !in_array($configs['protocol']['value'], ['http://', 'https://']) ? $configs['protocol']['value'] : '' ?>"
                           placeholder="请输入自定义协议，例如：https://i1.wp.com/">
                </div>
            </div>
            
            <?php
            $basicSettings = [
                'per_page' => [
                    'label' => '后台单页显示数量',
                    'type' => 'number',
                    'min' => 1,
                    'max' => 100,
                    'placeholder' => '建议设置为20'
                ],
                'max_uploads_per_day' => [
                    'label' => '每日上传限制次数',
                    'type' => 'number',
                    'min' => 1,
                    'placeholder' => '建议设置为50'
                ],
                'max_file_size' => [
                    'label' => '单个文件大小限制(字节)',
                    'type' => 'number',
                    'min' => 1,
                    'placeholder' => '建议设置为5242880(5MB)'
                ],
                'site_domain' => [
                    'label' => '网站域名',
                    'type' => 'text',
                    'placeholder' => '例如：https://example.com'
                ],
                'output_format' => [
                    'label' => '输出图片格式',
                    'type' => 'radio',
                    'options' => [
                        'original' => '原格式',
                        'webp' => 'WebP',
                        'avif' => 'AVIF'
                    ]
                ]
            ];
            
            foreach($basicSettings as $key => $setting): ?>
                <div class="form-group">
                    <label for="<?= $key ?>"><?= $setting['label'] ?></label>
                    <?php if ($setting['type'] === 'radio'): ?>
                        <div class="radio-group">
                            <?php foreach($setting['options'] as $value => $label): ?>
                                <label>
                                    <input type="radio" 
                                           name="<?= $key ?>" 
                                           value="<?= $value ?>" 
                                           <?= $configs[$key]['value'] === $value ? 'checked' : '' ?>>
                                    <span><?= $label ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <input type="<?= $setting['type'] ?>" 
                               name="<?= $key ?>" 
                               id="<?= $key ?>"
                               value="<?= $configs[$key]['value'] ?>"
                               <?= isset($setting['min']) ? "min=\"{$setting['min']}\"" : '' ?>
                               <?= isset($setting['max']) ? "max=\"{$setting['max']}\"" : '' ?>
                               placeholder="<?= isset($setting['placeholder']) ? $setting['placeholder'] : '' ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

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
                <label>API Token</label>
                <input type="text" name="token" value="<?= $userToken ?>" placeholder="用于API接口认证的Token">
            </div>
        </div>
        
        <?php foreach ($storageConfigs['storage_types'] as $type => $storage):
            if ($type === 'local') continue; ?>
            <div class="settings-group" id="<?= $type ?>-settings" style="display: none;">
                <h2><?= $storage['name'] ?>设置</h2>
                <?php foreach ($storage['configs'] as $config): ?>
                    <div class="form-group">
                        <label for="<?= $config['key'] ?>"><?= $config['name'] ?></label>
                        <input type="<?= $config['type'] ?>" 
                               name="<?= $config['key'] ?>" 
                               id="<?= $config['key'] ?>"
                               value="<?= isset($configs[$config['key']]) ? $configs[$config['key']]['value'] : '' ?>"
                               placeholder="<?= isset($config['placeholder']) ? $config['placeholder'] : '' ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        
        <button type="submit" name="submit" class="submit-btn">保存设置</button>
    </form>
</div> 