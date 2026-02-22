<?php
session_start();
header("Cache-Control: max-age=10800");

if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    require 'login.php';
    exit;
}

require_once '../config/database.php';
require 'pagination.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$demoMode = ($_ENV['DEMO_MODE'] ?? 'false') === 'true';
$isDemoAutoLogin = isset($_SESSION['demo_auto_login']) && $_SESSION['demo_auto_login'];

// 处理登出
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit();
}

// 获取配置和分页数据
$items_per_page = (int)($pdo->query("SELECT value FROM configs WHERE `key` = 'per_page'")->fetchColumn() ?: 20);
$total_rows = (int)($pdo->query("SELECT COUNT(id) FROM images")->fetchColumn() ?: 0);

$total_pages = max(1, ceil($total_rows / $items_per_page));
$current_page = min(max(1, $_GET['page'] ?? 1), $total_pages);

// 获取图片数据
$offset = ($current_page - 1) * $items_per_page;
$stmt = $pdo->prepare("SELECT * FROM images ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->execute([$items_per_page, $offset]);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 处理AJAX请求
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => renderImagesList($images),
        'pagination' => renderPagination($current_page, $total_pages),
        'currentPage' => $current_page,
        'totalPages' => $total_pages
    ]);
    exit;
}

// 渲染页面
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
<body>
    <div id="gallery" class="gallery"><?= $images_html ?></div>
    <div class="rightside">
        <a href="/" class="floating-link" title="返回主页">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-home"></use></svg>
        </a>
        <a class="select-link" title="多选模式">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-select"></use></svg>
        </a>
        <a href="#" class="settings-link" title="系统设置">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-Setting"></use></svg>
        </a>
        <a href="?logout=true" class="logout-link" title="退出登录">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-logout"></use></svg>
        </a>
        <a class="top-link" id="scroll-to-top" title="回到顶部">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-top"></use></svg>
        </a>
        <span id="current-total-pages"><?= $current_page ?>/<?= $total_pages ?></span>
    </div>
    <div id="pagination" class="pagination"><?= $pagination ?></div>
    <div id="settings-modal" class="modal">
        <div class="modal-content"></div>
    </div>
    <script src="//at.alicdn.com/t/c/font_4623353_hb4c04qfi4u.js"></script>
    <script src="/static/js/fancybox.umd.min.js"></script>
    <script src="/static/js/lazyload.min.js"></script>
    <script src="/static/js/admin.js"></script>
    <script src="/static/js/settings.js"></script>
</body>
</html>
<?php
// 辅助函数
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
        <div class="gallery-item" id="image-{$id}">
            <div class="image-wrapper">
                <div class="image-placeholder"><div class="spinner"></div></div>
                <img class="lazy" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-src="{$url}" data-fancybox="gallery">
            </div>
            <div class="action-buttons">
                <button class="copy-btn" data-url="{$url}">
                    <svg class="icon" aria-hidden="true"><use xlink:href="#icon-link"></use></svg>
                </button>
                <button class="delete-btn" data-id="{$id}" data-path="{$path}">
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