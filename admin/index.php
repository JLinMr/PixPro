<?php
session_start();
header('Cache-Control: private, no-store');

if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    require 'login.php';
    exit;
}

require_once '../includes/bootstrap.php';
require_once '../includes/http.php';
require_once 'settings.php';
require 'pagination.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$demoMode = ($_ENV['DEMO_MODE'] ?? 'false') === 'true';
$csrfToken = ensureCsrfToken();

if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$items_per_page = (int)(Database::getConfig($pdo, 'per_page') ?: 20);
$total_rows = Database::getImageCount($pdo);
$total_pages = max(1, ceil($total_rows / $items_per_page));
$current_page = min(max(1, $_GET['page'] ?? 1), $total_pages);
$offset = ($current_page - 1) * $items_per_page;

$stmt = $pdo->prepare('SELECT id, url, path, size, upload_ip, created_at FROM images ORDER BY id DESC LIMIT ? OFFSET ?');
$stmt->execute([$items_per_page, $offset]);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($isAjax) {
    jsonExit([
        'success' => true,
        'html' => renderImagesList($images),
        'pagination' => renderPagination($current_page, $total_pages),
    ]);
}

$images_html = renderImagesList($images);
$pagination = renderPagination($current_page, $total_pages);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台</title>
    <link rel="shortcut icon" href="/static/favicon.svg">
    <link rel="stylesheet" href="/static/css/admin.css">
    <link rel="stylesheet" href="/static/css/fancybox.min.css">
</head>
<body class="page-admin">
    <div id="gallery" class="gallery glass"><?= $images_html ?></div>
    <div class="rightside">
        <a href="/" class="floating-link glass-btn" title="返回主页">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-home"></use></svg>
        </a>
        <a class="select-link glass-btn" title="多选模式">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-select"></use></svg>
        </a>
        <a href="#" class="settings-link glass-btn" title="系统设置">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-Setting"></use></svg>
        </a>
        <a href="?logout=true" class="logout-link glass-btn" title="退出登录">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-logout"></use></svg>
        </a>
        <a class="top-link glass-btn" id="scroll-to-top" title="回到顶部">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-top"></use></svg>
        </a>
    </div>
    <nav id="pagination" class="pagination-bar glass-panel" aria-label="分页"><?= $pagination ?></nav>
    <div id="settings-modal" class="settings-modal" role="dialog" aria-modal="true" aria-hidden="true">
        <?php renderSettingsForm($pdo, $demoMode); ?>
    </div>
    <script src="//at.alicdn.com/t/c/font_4623353_hb4c04qfi4u.js"></script>
    <script src="/static/js/fancybox.umd.min.js"></script>
    <script src="/static/js/lazyload.min.js"></script>
    <script>window.PIXPRO_CSRF_TOKEN = <?= json_encode($csrfToken) ?>;</script>
    <script type="module" src="/static/js/admin.js"></script>
</body>
</html>
<?php

function renderImagesList($images) {
    if (empty($images)) {
        return '<div class="empty-state"><div class="empty-icon"></div><p>暂无图片</p></div>';
    }

    $html = '';
    foreach ($images as $image) {
        $id = htmlspecialchars($image['id']);
        $url = htmlspecialchars($image['url']);
        $path = htmlspecialchars($image['path']);
        $size = number_format($image['size'] / 1024, 2);
        $ip = htmlspecialchars($image['upload_ip']);
        $time = htmlspecialchars($image['created_at']);

        $html .= <<<HTML
        <div class="gallery-item" id="image-{$id}" data-url="{$url}" data-path="{$path}">
            <div class="image-wrapper">
                <div class="image-placeholder"><div class="spinner"></div></div>
                <a href="{$url}" class="image-link" data-fancybox="gallery">
                    <img class="lazy" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-src="{$url}" alt="">
                </a>
            </div>
            <div class="action-buttons">
                <button type="button" class="copy-btn glass-btn" data-url="{$url}">
                    <svg class="icon" aria-hidden="true"><use xlink:href="#icon-link"></use></svg>
                </button>
                <button type="button" class="delete-btn glass-btn" data-path="{$path}">
                    <svg class="icon" aria-hidden="true"><use xlink:href="#icon-xmark"></use></svg>
                </button>
            </div>
            <div class="image-info">
                <p class="info-p">大小: <span>{$size} KB</span></p>
                <p class="info-p">IP: <span>{$ip}</span></p>
                <p class="info-p">时间: <span>{$time}</span></p>
            </div>
        </div>
HTML;
    }

    return $html;
}
?>
