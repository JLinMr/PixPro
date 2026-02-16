<?php
/**
 * 检查程序版本 - 返回HTML片段
 */
session_status() === PHP_SESSION_NONE && session_start();

if (empty($_SESSION['loggedin'])) {
    http_response_code(403);
    exit('未授权');
}

// 读取版本信息
$packageJson = @json_decode(@file_get_contents(__DIR__ . '/../../package.json'), true);
$currentVersion = $packageJson['version'] ?? '2.0';

define('GITHUB_REPO', 'JLinMr/PixPro');

$latestVersion = $updateAvailable = $releaseInfo = $error = null;

try {
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: PHP\r\nAccept: application/vnd.github.v3+json",
            'timeout' => 10
        ]
    ]);
    
    $response = @file_get_contents('https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest', false, $context);
    if (!$response) throw new Exception('无法连接到 GitHub API');
    
    $releaseInfo = json_decode($response, true);
    if (isset($releaseInfo['tag_name'])) {
        $latestVersion = ltrim($releaseInfo['tag_name'], 'v');
        $updateAvailable = version_compare($latestVersion, $currentVersion, '>');
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// 检测是否为测试版（当前版本大于远程版本）
$isTestVersion = $latestVersion && version_compare($currentVersion, $latestVersion, '>');

// 样式常量
$boxStyle = 'background: rgba(255, 255, 255, 0.05); border-radius: 8px; padding: 20px; margin-bottom: 20px;';
$statusBoxStyle = 'padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px;';
$iconStyle = 'font-size: 48px; margin-bottom: 10px;';
$titleStyle = 'font-size: 18px; font-weight: bold; margin-bottom: 8px;';
$textStyle = 'font-size: 14px; color: rgba(255, 255, 255, 0.7);';

// 状态配置
$statuses = [
    'error' => ['bg' => 'rgba(244, 67, 54, 0.2)', 'border' => 'rgba(244, 67, 54, 0.3)', 'color' => '#f44336', 'icon' => '✗', 'title' => '检测失败', 'text' => htmlspecialchars($error)],
    'test' => ['bg' => 'rgba(156, 39, 176, 0.2)', 'border' => 'rgba(156, 39, 176, 0.3)', 'color' => '#9c27b0', 'icon' => '🧪', 'title' => '测试版本', 'text' => '当前版本高于最新发布版本'],
    'update' => ['bg' => 'rgba(255, 152, 0, 0.2)', 'border' => 'rgba(255, 152, 0, 0.3)', 'color' => '#ff9800', 'icon' => '⚠️', 'title' => '发现新版本', 'text' => '有可用的更新版本'],
    'latest' => ['bg' => 'rgba(76, 175, 80, 0.2)', 'border' => 'rgba(76, 175, 80, 0.3)', 'color' => '#4caf50', 'icon' => '✓', 'title' => '已是最新版本', 'text' => '当前程序无需更新']
];

$status = $error ? 'error' : ($isTestVersion ? 'test' : ($updateAvailable ? 'update' : 'latest'));
$s = $statuses[$status];
?>

<div style="padding: 20px;">
    <div style="<?= $boxStyle ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
            <span style="color: rgba(255, 255, 255, 0.7); font-size: 14px;">当前版本</span>
            <span style="color: #eee; font-size: 16px; font-weight: bold;"><?= $currentVersion ?></span>
        </div>
        <?php if ($latestVersion): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0;">
            <span style="color: rgba(255, 255, 255, 0.7); font-size: 14px;">最新版本</span>
            <span style="color: #eee; font-size: 16px; font-weight: bold;"><?= $latestVersion ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <div style="<?= $statusBoxStyle ?> background: <?= $s['bg'] ?>; border: 1px solid <?= $s['border'] ?>;">
        <div style="<?= $iconStyle ?> color: <?= $s['color'] ?>;"><?= $s['icon'] ?></div>
        <div style="<?= $titleStyle ?> color: <?= $s['color'] ?>;"><?= $s['title'] ?></div>
        <div style="<?= $textStyle ?>"><?= $s['text'] ?></div>
    </div>
    
    <?php if ($updateAvailable && isset($releaseInfo['body'])): ?>
    <div style="<?= $boxStyle ?> max-height: 300px; overflow-y: auto;">
        <h3 style="font-size: 16px; color: #eee; margin-bottom: 12px;">更新说明</h3>
        <p style="<?= $textStyle ?> line-height: 1.6; white-space: pre-wrap;"><?= htmlspecialchars($releaseInfo['body']) ?></p>
    </div>
    <a href="https://github.com/<?= GITHUB_REPO ?>/releases/latest" target="_blank" class="submit-btn" 
       style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; margin: 0;">
        前往下载
    </a>
    <?php endif; ?>
</div>
