<?php
session_start();
// 设置缓存控制头部
header("Cache-Control: max-age=10800");
// 检查用户是否已登录
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin']) {
    require_once '../config/Database.php';
    $config = parse_ini_file('../config/config.ini', true);

    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    // 处理用户登出请求
    if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
        session_unset();
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit();
    }

    require 'pagination.php';

    $items_per_page = $config['Other']['per_page']; // 每页显示的图片数量

    // 查询总图片数量
    $total_pages_query = "SELECT COUNT(id) as total FROM images";
    $total_pages_result = $mysqli->query($total_pages_query);
    $total_rows = $total_pages_result->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $items_per_page);

    // 获取当前页码，默认为第一页
    $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($current_page - 1) * $items_per_page;

    // 处理AJAX请求
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        $images = renderImages($mysqli, $items_per_page, $offset);
        $pagination = renderPagination($current_page, $total_pages);
        header('Content-Type: application/json');
        echo json_encode([
            'images' => $images,
            'pagination' => $pagination,
            'current_page' => $current_page,
            'total_pages' => $total_pages
        ]);
        exit();
    } else {
        $images = renderImages($mysqli, $items_per_page, $offset);
        $pagination = renderPagination($current_page, $total_pages);
    }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台</title>
    <link rel="shortcut icon" href="/static/favicon.ico">
    <link rel="stylesheet" type="text/css" href="/static/css/admin.css?v=1.7">
    <!-- 引入Fancybox 你可以使用第三方CDN进行加速 当前版本 Fancybox5.0.36 -->
    <link rel="stylesheet" href="/static/css/fancybox.min.css?v=5.0.36">
    <script src="/static/js/fancybox.umd.min.js?v=5.0.36" defer></script>
</head>
<body>
    <div id="gallery" class="gallery"></div>
    <div class="rightside">
        <a href="/" class="floating-link" title="返回主页"><img src="/static/images/svg/home.svg" alt="主页"></a>
        <a href="?logout=true" class="logout-link" title="退出登录"><img src="/static/images/svg/logout.svg" alt="退出"></a>
        <a class="top-link" id="scroll-to-top" title="回到顶部"><img src="/static/images/svg/top.svg" alt="顶部"></a>
        <span id="current-total-pages"><?php echo $current_page.'/'.$total_pages; ?></span>
    </div>
    <div id="pagination" class="pagination"></div>
    <div id="loading-indicator" class="loading-indicator">
        <div class="spinner"></div>
        <div class="loading-text">加载中...</div>
    </div>
    <script src="/static/js/admin.js?v=1.7" defer></script>
    <script src="/static/js/ajax.js?v=1.7" defer></script>
</body>
</html>
<?php
} else {
    require 'login.php';
}
?>