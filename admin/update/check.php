<?php
/**
 * 数据库修复 - 检测和执行
 */
session_status() === PHP_SESSION_NONE && session_start();

if (empty($_SESSION['loggedin'])) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => '未授权']));
}

require_once __DIR__ . '/../../config/database.php';

// 获取当前数据库配置
function getCurrentConfigs($mysqli) {
    $result = $mysqli->query("SELECT `key`, value, description FROM configs");
    $configs = [];
    while ($row = $result->fetch_assoc()) {
        $configs[$row['key']] = ['value' => $row['value'], 'description' => $row['description']];
    }
    return $configs;
}

// 获取标准配置
function getStandardConfigs() {
    $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    
    return [
        'storage' => ['value' => 'local', 'description' => '存储方式'],
        'url_prefix' => ['value' => '', 'description' => '图片代理'],
        'per_page' => ['value' => '20', 'description' => '每页显示数量'],
        'login_restriction' => ['value' => 'false', 'description' => '登录保护'],
        'max_file_size' => ['value' => '5242880', 'description' => '最大文件大小'],
        'max_uploads_per_day' => ['value' => '50', 'description' => '每日上传限制'],
        'output_format' => ['value' => 'webp', 'description' => '输出图片格式'],
        'site_domain' => ['value' => $siteUrl, 'description' => '网站域名']
    ];
}

// 获取存储配置
function getStorageConfigs() {
    $configsJson = json_decode(file_get_contents(__DIR__ . '/../../config/configs.json'), true);
    $storageConfigs = [];
    
    foreach ($configsJson['storage_types'] as $storageType => $storage) {
        foreach ($storage['configs'] as $config) {
            $storageConfigs[$config['key']] = [
                'value' => $config['default'],
                'description' => $config['name'] ?? ''
            ];
        }
    }
    
    return $storageConfigs;
}

// 如果是 POST 请求，执行更新
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $db = Database::getInstance();
        $mysqli = $db->getConnection();
        
        $steps = [];
        $errors = [];
        
        $mysqli->begin_transaction();
        
        try {
            $currentConfigs = getCurrentConfigs($mysqli);
            $standardConfigs = array_merge(getStandardConfigs(), getStorageConfigs());
            
            // 定义配置迁移规则
            $migrations = [
                's3_custom_url_prefix' => 's3_cdn_domain',
                'upyun_domain' => 'upyun_cdn_domain'
            ];
            $deprecated = ['protocol']; // 纯废弃项，直接删除
            
            // 1. 添加缺失的基础配置项
            $addedCount = 0;
            foreach (getStandardConfigs() as $key => $config) {
                if (!isset($currentConfigs[$key])) {
                    $stmt = $mysqli->prepare("INSERT INTO configs (`key`, value, description) VALUES (?, ?, ?)");
                    $stmt->bind_param("sss", $key, $config['value'], $config['description']);
                    if ($stmt->execute()) {
                        $steps[] = "✓ 已添加配置项: {$key}";
                        $addedCount++;
                    } else {
                        $errors[] = "✗ 添加配置项 {$key} 失败";
                    }
                }
            }
            if ($addedCount === 0) $steps[] = "○ 所有基础配置项已存在";
            
            // 2. 重命名旧配置（只处理存在的）
            $renamedCount = 0;
            foreach ($migrations as $oldKey => $newKey) {
                if (isset($currentConfigs[$oldKey])) {
                    $newDesc = $standardConfigs[$newKey]['description'] ?? '';
                    $stmt = $mysqli->prepare("UPDATE configs SET `key` = ?, description = ? WHERE `key` = ?");
                    $stmt->bind_param("sss", $newKey, $newDesc, $oldKey);
                    if ($stmt->execute() && $mysqli->affected_rows > 0) {
                        $steps[] = "✓ 已重命名: {$oldKey} → {$newKey}";
                        $renamedCount++;
                    }
                }
            }
            if ($renamedCount === 0) $steps[] = "○ 无需重命名的配置项";
            
            // 3. 删除废弃配置项
            $deletedCount = 0;
            foreach ($deprecated as $key) {
                if (isset($currentConfigs[$key])) {
                    $stmt = $mysqli->prepare("DELETE FROM configs WHERE `key` = ?");
                    $stmt->bind_param("s", $key);
                    if ($stmt->execute() && $mysqli->affected_rows > 0) {
                        $steps[] = "✓ 已删除: {$key}";
                        $deletedCount++;
                    }
                }
            }
            if ($deletedCount === 0) $steps[] = "○ 无废弃配置项";
            
            $mysqli->commit();
            
            // 返回结果
            $updateSuccess = empty($errors);
            
            echo json_encode([
                'success' => $updateSuccess,
                'steps' => $steps,
                'errors' => $errors,
                'message' => $updateSuccess ? '数据库修复完成！' : '修复过程中出现错误'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            $mysqli->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => '修复失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// GET 请求 - 显示检测界面
try {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    $currentConfigs = getCurrentConfigs($mysqli);
    $baseConfigs = getStandardConfigs();
    $storageConfigs = getStorageConfigs();
    
    // 检查需要修复的项目
    $needUpdate = false;
    $issues = [];
    
    // 1. 检查缺失的基础配置项
    $missingConfigs = [];
    foreach ($baseConfigs as $key => $config) {
        if (!isset($currentConfigs[$key])) {
            $missingConfigs[] = $key;
        }
    }
    
    if (!empty($missingConfigs)) {
        $needUpdate = true;
        $issues[] = '缺少 ' . count($missingConfigs) . ' 个基础配置项: ' . implode(', ', $missingConfigs);
    }
    
    // 2. 检查需要重命名的配置
    $migrations = [
        's3_custom_url_prefix' => 's3_cdn_domain',
        'upyun_domain' => 'upyun_cdn_domain'
    ];
    $needRename = [];
    foreach ($migrations as $oldKey => $newKey) {
        if (isset($currentConfigs[$oldKey])) {
            $needRename[] = "{$oldKey} → {$newKey}";
        }
    }
    
    if (!empty($needRename)) {
        $needUpdate = true;
        $issues[] = '检测到 ' . count($needRename) . ' 个需要重命名的配置项: ' . implode(', ', $needRename);
    }
    
    // 3. 检查废弃的配置项
    $deprecated = ['protocol'];
    $deprecatedFound = [];
    foreach ($deprecated as $key) {
        if (isset($currentConfigs[$key])) {
            $deprecatedFound[] = $key;
        }
    }
    
    if (!empty($deprecatedFound)) {
        $needUpdate = true;
        $issues[] = '检测到 ' . count($deprecatedFound) . ' 个废弃配置项: ' . implode(', ', $deprecatedFound);
    }
    
    // 样式常量
    $boxStyle = 'background: rgba(255, 255, 255, 0.05); border-radius: 8px; padding: 20px; margin-bottom: 20px;';
    $statusBoxStyle = 'padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px;';
    $iconStyle = 'font-size: 48px; margin-bottom: 10px;';
    $titleStyle = 'font-size: 18px; font-weight: bold; margin-bottom: 8px;';
    $textStyle = 'font-size: 14px; color: rgba(255, 255, 255, 0.7);';
    
    // 返回HTML片段
    if ($needUpdate) {
        ?>
<div style="padding: 20px;">
    <div style="<?= $statusBoxStyle ?> background: rgba(255, 152, 0, 0.2); border: 1px solid rgba(255, 152, 0, 0.3);">
        <div style="<?= $iconStyle ?> color: #ff9800;">⚠️</div>
        <div style="<?= $titleStyle ?> color: #ff9800;">检测到数据库需要修复</div>
        <div style="<?= $textStyle ?> line-height: 1.6;">
            <?= implode('<br>', $issues) ?>
        </div>
    </div>
    
    <button type="button" class="submit-btn" id="run-update-btn" style="margin: 0;">
        立即修复
    </button>
    
    <div id="update-progress" style="display: none; margin-top: 20px;"></div>
</div>

<script>
setTimeout(() => {
    const runBtn = document.getElementById('run-update-btn');
    const updateProgress = document.getElementById('update-progress');
    if (!runBtn || !updateProgress) return;
    
    runBtn.onclick = async (e) => {
        e.preventDefault();
        e.stopPropagation();
        runBtn.disabled = true;
        runBtn.innerHTML = '修复中...';
        updateProgress.style.display = 'block';
        updateProgress.innerHTML = '<div style="padding: 20px; border-radius: 8px; background: rgba(33, 150, 243, 0.2); border: 1px solid rgba(33, 150, 243, 0.3);"><div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; color: #2196f3;"><div class="spinner"></div><span>正在修复数据库...</span></div><div id="update-steps" style="font-size: 13px; line-height: 1.8; color: rgba(255, 255, 255, 0.9);"></div></div>';
        
        try {
            const data = await fetch('update/check.php', { method: 'POST' }).then(r => r.json());
            const stepsDiv = document.getElementById('update-steps');
            if (!stepsDiv) return;
            
            stepsDiv.innerHTML = '';
            [...(data.steps || []), ...(data.errors || [])].forEach(msg => {
                const div = document.createElement('div');
                div.style.cssText = 'padding: 4px 0; color: ' + (msg.startsWith('✓') ? '#4caf50' : msg.startsWith('✗') ? '#f44336' : 'rgba(255, 255, 255, 0.7)');
                div.textContent = msg;
                stepsDiv.appendChild(div);
            });
            
            const resultDiv = document.createElement('div');
            resultDiv.style.cssText = 'margin-top: 15px; padding: 12px; border-radius: 8px; font-weight: bold; background: ' + (data.success ? 'rgba(76, 175, 80, 0.3)' : 'rgba(244, 67, 54, 0.3)') + '; color: ' + (data.success ? '#4caf50' : '#f44336');
            resultDiv.textContent = (data.success ? '✓ ' : '✗ ') + data.message;
            stepsDiv.appendChild(resultDiv);
            
            if (typeof UI !== 'undefined') UI.showNotification(data.success ? '数据库修复完成' : '数据库修复失败', data.success ? 'success' : 'error');
            runBtn.innerHTML = data.success ? '修复完成' : '重试';
            runBtn.disabled = data.success;
            
            const spinner = updateProgress.querySelector('.spinner');
            if (spinner?.parentElement) spinner.parentElement.style.display = 'none';
        } catch (error) {
            console.error('修复失败:', error);
            updateProgress.innerHTML = '<div style="padding: 20px; border-radius: 8px; background: rgba(244, 67, 54, 0.2); border: 1px solid rgba(244, 67, 54, 0.3); color: #f44336; text-align: center;">✗ 修复失败: ' + error.message + '</div>';
            if (typeof UI !== 'undefined') UI.showNotification('数据库修复失败', 'error');
            runBtn.disabled = false;
            runBtn.innerHTML = '重试';
        }
    };
}, 0);
</script>
        <?php
    } else {
        ?>
<div style="padding: 20px;">
    <div style="<?= $statusBoxStyle ?> background: rgba(76, 175, 80, 0.2); border: 1px solid rgba(76, 175, 80, 0.3);">
        <div style="<?= $iconStyle ?> color: #4caf50;">✓</div>
        <div style="<?= $titleStyle ?> color: #4caf50;">数据库配置正常</div>
        <div style="<?= $textStyle ?>">无需修复</div>
    </div>
</div>
        <?php
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo '✗ 检测失败: ' . htmlspecialchars($e->getMessage());
}
