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
$mysqli = $db->getConnection();

// 处理登出
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit();
}

// 获取配置和分页数据
$items_per_page = (int)($mysqli->query("SELECT value FROM configs WHERE `key` = 'per_page'")->fetch_assoc()['value'] ?? 20);
$total_rows = (int)($mysqli->query("SELECT COUNT(id) as total FROM images")->fetch_assoc()['total'] ?? 0);
$total_pages = max(1, ceil($total_rows / $items_per_page));
$current_page = min(max(1, $_GET['page'] ?? 1), $total_pages);

// 获取图片数据
$offset = ($current_page - 1) * $items_per_page;
$stmt = $mysqli->prepare("SELECT * FROM images ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
    <!-- 引入Fancybox 和  vanilla-lazyload-->
    <link rel="stylesheet" href="/static/css/fancybox.min.css">
    <script src="/static/js/fancybox.umd.min.js" defer></script>
    <script src="/static/js/lazyload.min.js" defer></script>
    <!-- 你可以使用第三方CDN进行加速 Fancybox 版本 5.0.36 和 vanilla-lazyload 版本 19.1.3 -->
    <!-- <link rel="stylesheet" href="https://lib.baomitu.com/fancyapps-ui/5.0.36/fancybox/fancybox.min.css">
    <script src="https://lib.baomitu.com/fancyapps-ui/5.0.36/fancybox/fancybox.umd.min.js" defer></script>
    <script src="https://lib.baomitu.com/vanilla-lazyload/19.1.3/lazyload.min.js" defer></script> -->
</head>
<body>
    <div id="gallery" class="gallery"><?= $images_html ?></div>
    <div class="rightside">
        <a href="/" class="floating-link" title="返回主页"><img src="/static/images/svg/home.svg" alt="主页"></a>
        <a class="select-link" title="多选模式"><img src="/static/images/svg/select.svg" alt="多选"></a>
        <a href="settings.php" class="settings-link" title="系统设置"><img src="/static/images/svg/Setting.svg" alt="设置"></a>
        <a href="?logout=true" class="logout-link" title="退出登录"><img src="/static/images/svg/logout.svg" alt="退出"></a>
        <a class="top-link" id="scroll-to-top" title="回到顶部"><img src="/static/images/svg/top.svg" alt="顶部"></a>
        <span id="current-total-pages"><?= $current_page ?>/<?= $total_pages ?></span>
    </div>
    <div id="pagination" class="pagination"><?= $pagination ?></div>
    <div id="settings-modal" class="modal">
        <div class="modal-content"></div>
    </div>
    <script src="/static/js/admin.js" defer></script>
    <script src="/static/js/settings.js" defer></script>
</body>
</html>
<?php
// 辅助函数
function formatFileSize($sizeInBytes) {
    return number_format($sizeInBytes / 1024, 2) . ' KB';
}

function renderImagesList($images) {
    if (empty($images)) {
        return '<div class="empty-state">
            <div class="empty-icon"></div>
            <p>暂无图片</p>
        </div>';
    }
    
    ob_start();
    foreach ($images as $image): ?>
        <div class="gallery-item" id="image-<?= htmlspecialchars($image['id']) ?>">
            <div class="image-wrapper">
                <div class="image-placeholder"><div class="spinner"></div></div>
                <img class="lazy" data-src="<?= htmlspecialchars($image['url']) ?>" 
                     alt="Image" data-fancybox="gallery">
            </div>
            <div class="action-buttons">
                <button class="copy-btn" data-url="<?= htmlspecialchars($image['url']) ?>">
                    <img src="/static/images/svg/link.svg" alt="Copy" />
                </button>
                <button class="delete-btn" 
                        data-id="<?= htmlspecialchars($image['id']) ?>" 
                        data-path="<?= htmlspecialchars($image['path']) ?>">
                    <img src="/static/images/svg/xmark.svg" alt="X" />
                </button>
            </div>
            <div class="image-info">
                <p class="info-p">大小: <span><?= formatFileSize($image['size']) ?></span></p>
                <p class="info-p">IP: <span><?= htmlspecialchars($image['upload_ip']) ?></span></p>
                <p class="info-p">时间: <span><?= htmlspecialchars($image['created_at']) ?></span></p>
            </div>
        </div>
    <?php endforeach;
    return ob_get_clean();
}
?>