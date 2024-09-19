<?php
session_start();
header("Cache-Control: max-age=10800");

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin']) {
    require_once '../config/Database.php';
    $config = parse_ini_file('../config/config.ini', true);
    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
        session_unset();
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit();
    }

    require 'pagination.php';
    $items_per_page = $config['Other']['per_page'];

    function renderImages($mysqli, $items_per_page, $offset) {
        $query = "SELECT * FROM images ORDER BY id DESC LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("ii", $items_per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $images = [];
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $images[] = $row;
            }
        }
        return $images;
    }

    $total_rows = $mysqli->query("SELECT COUNT(id) as total FROM images")->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $items_per_page);
    $current_page = max(1, min($total_pages, isset($_GET['page']) ? (int)$_GET['page'] : 1));
    $offset = ($current_page - 1) * $items_per_page;

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'images' => renderImages($mysqli, $items_per_page, $offset),
            'pagination' => renderPagination($current_page, $total_pages),
            'current_page' => $current_page,
            'total_pages' => $total_pages
        ]);
        exit();
    }

    $images = renderImages($mysqli, $items_per_page, $offset);
    $pagination = renderPagination($current_page, $total_pages);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台</title>
    <link rel="shortcut icon" href="/static/favicon.ico">
    <link rel="stylesheet" href="/static/css/admin.css">
    <!-- 引入Fancybox 当前版本 Fancybox5.0.36 -->
    <link rel="stylesheet" href="/static/css/fancybox.min.css">
    <script src="/static/js/fancybox.umd.min.js" defer></script>
    <!-- 你可以使用第三方CDN进行加速 当前版本 Fancybox5.0.36 -->
    <!-- <link rel="stylesheet" href="https://cdn.npmmirror.com/packages/pixpro/1.7.5/files/static/css/fancybox.min.css"> -->
    <!-- <script src="https://cdn.npmmirror.com/packages/pixpro/1.7.5/files/static/js/fancybox.umd.min.js" defer></script> -->
</head>
<body>
    <div id="gallery" class="gallery"></div>
    <div class="rightside">
        <a href="/" class="floating-link" title="返回主页"><img src="/static/images/svg/home.svg" alt="主页"></a>
        <a href="?logout=true" class="logout-link" title="退出登录"><img src="/static/images/svg/logout.svg" alt="退出"></a>
        <a class="top-link" id="scroll-to-top" title="回到顶部"><img src="/static/images/svg/top.svg" alt="顶部"></a>
        <span id="current-total-pages"><?= "$current_page/$total_pages"; ?></span>
    </div>
    <div id="pagination" class="pagination"><?= $pagination; ?></div>
    <div id="loading-indicator" class="loading-indicator">
        <div class="spinner"></div>
        <div class="loading-text">加载中...</div>
    </div>
    <script src="/static/js/admin.js" defer></script>
    <script src="/static/js/ajax.js" defer></script>
</body>
</html>
<?php
} else {
    require 'login.php';
}
?>