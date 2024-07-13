<?php
session_start(); // 启动会话

// 检查用户是否已登录
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin']) {
    require_once '../config/Database.php'; // 引入数据库配置文件
    $db = Database::getInstance(); // 获取数据库实例
    $mysqli = $db->getConnection(); // 获取数据库连接

    // 处理用户登出请求
    if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
        session_unset(); // 清除会话变量
        session_destroy(); // 销毁会话
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); // 重定向到当前页面，移除所有查询参数
        exit(); // 终止脚本执行
    }

    require 'pagination.php'; // 引入分页处理文件

    $items_per_page = 20; // 每页显示的图片数量

    // 查询总图片数量
    $total_pages_query = "SELECT COUNT(id) as total FROM images";
    $total_pages_result = $mysqli->query($total_pages_query);
    $total_rows = $total_pages_result->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $items_per_page); // 计算总页数

    // 获取当前页码，默认为第一页
    $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($current_page - 1) * $items_per_page; // 计算偏移量

    // 处理AJAX请求
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        $images = renderImages($mysqli, $items_per_page, $offset); // 渲染图片
        $pagination = renderPagination($current_page, $total_pages); // 渲染分页
        header('Content-Type: application/json'); // 设置响应头为JSON
        echo json_encode([
            'images' => $images,
            'pagination' => $pagination,
            'current_page' => $current_page,
            'total_pages' => $total_pages
        ]); // 返回JSON数据
        exit(); // 终止脚本执行
    } else {
        $images = renderImages($mysqli, $items_per_page, $offset); // 渲染图片
        $pagination = renderPagination($current_page, $total_pages); // 渲染分页
    }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台</title>
    <link rel="shortcut icon" href="/static/favicon.ico">
    <link rel="stylesheet" type="text/css" href="/static/css/admin.css">
    <link rel="stylesheet" type="text/css" href="/static/css/zoom.css">
</head>
<body>
    <div id="gallery" class="gallery"></div>
    <div class="rightside">
        <a href="/" class="floating-link" title="返回主页"><img src="/static/svg/home.svg" alt="主页"></a>
        <a href="?logout=true" class="logout-link" title="退出登录"><img src="/static/svg/logout.svg" alt="退出"></a>
        <a class="top-link" id="scroll-to-top" title="回到顶部"><img src="/static/svg/top.svg" alt="顶部"></a>
        <span id="current-total-pages"><?php echo $current_page.'/'.$total_pages; ?></span>
    </div>
    <div id="pagination" class="pagination"></div>
    <div id="loading-indicator" class="loading-indicator">
        <div class="spinner"></div>
        <div class="loading-text">加载中...</div>
    </div>
    <div id="img-zoom" class="img-zoom">
        <button id="close-btn" class="close-btn" onclick="closeZoom(event)"><img src="/static/svg/xmark.svg" alt="X" /></button>
        <button id="prev-img" class="nav-btn" onclick="prevImage(event)">&#10094;</button>
        <img class="zoom-img" id="zoomed-img">
        <button id="next-img" class="nav-btn" onclick="nextImage(event)">&#10095;</button>
    </div>
    <script src="/static/js/zoom.js" defer></script>
    <script src="/static/js/admin.js" defer></script>
    <script src="/static/js/ajax.js" defer></script>
</body>
</html>
<?php
} else {
    require 'login.php'; // 如果用户未登录，引入登录页面
}
?>