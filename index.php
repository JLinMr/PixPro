<?php
session_start();

require_once 'includes/bootstrap.php';
require_once 'includes/http.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $config = Database::getConfig($pdo);
    $maxFileSize = (int)($config['max_file_size'] ?? 0);
    $csrfToken = ensureCsrfToken();

    // 检查是否需要登录限制
    if (filter_var($config['login_restriction'] ?? 'false', FILTER_VALIDATE_BOOLEAN) && empty($_SESSION['loggedin'])) {
        header('Location: /admin');
        exit();
    }
} catch (Exception $e) {
    die($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>若梦图床</title>
    <meta name="keywords" content="图床程序,高效图片压缩,前端后台设计,图片上传,WEBP转换,阿里云OSS,本地存储,多格式支持,瀑布流管理,图片管理后台,自定义压缩率,尺寸限制">
    <meta name="description" content="一款专为个人需求设计的高效图床解决方案，集成了强大的图片压缩功能与优雅的前台后台界面。项目结构精简高效，提供自定义图片压缩率与尺寸设置，有效降低存储与带宽成本。支持JPEG, PNG, GIF转换为WEBP以及SVG、WEBP直接上传，搭载阿里云OSS存储（默认）及灵活的本地存储选项。特性包括点击、拖拽、粘贴及URL本地化上传方式，以及配备瀑布流布局的管理后台，实现图片轻松管理与预览。完全可自定制的体验，满足不同用户对图片管理和优化的高级需求。">
    <link rel="shortcut icon" href="static/favicon.svg">
    <link rel="stylesheet" type="text/css" href="static/css/styles.css">
</head>
<body>
    <header class="glass glass-in">
        <a href="https://www.bsgun.cn/" target="_blank" title="主页" class="header-link">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-home"></use></svg>
        </a>
        <a href="https://blog.bsgun.cn/" target="_blank" title="博客" class="header-link">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-Blog"></use></svg>
        </a>
        <a href="https://github.com/JLinMr/PixPro/" target="_blank" title="Github" class="header-link">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-Github"></use></svg>
        </a>
        <a href="/admin/" target="_blank" title="后台" class="header-link">
            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-Setting"></use></svg>
        </a>
    </header>
    <main>
        <div class="upload-container glass glass-in">
            <form id="uploadForm" enctype="multipart/form-data" method="post" action="#" onsubmit="return false;">
                <!-- 上传框区域 -->
                <div class="upload-section">
                    <button type="button" id="deleteImageButton" class="deleteImageButton glass-btn">
                        <svg class="icon" aria-hidden="true"><use xlink:href="#icon-xmark"></use></svg>
                    </button>
                    <div id="imageUploadBox" class="imageUploadBox glass-subtle" onclick="document.getElementById('imageInput').click();">
                        <svg class="icon upload-icon" aria-hidden="true"><use xlink:href="#icon-up"></use></svg>
                        <input type="file" id="imageInput" accept="image/png, image/jpeg, image/webp, image/gif" multiple>
                        <div id="imagePreviewContainer" class="imagePreviewContainer">
                            <button type="button" id="prevButton" class="nav-button prev-button glass-btn">
                                <svg class="icon" aria-hidden="true"><use xlink:href="#icon-Left-arrow"></use></svg>
                            </button>
                            <img id="imagePreview" class="imagePreview" src="" alt="">
                            <button type="button" id="nextButton" class="nav-button next-button glass-btn">
                                <svg class="icon" aria-hidden="true"><use xlink:href="#icon-Right-arrow"></use></svg>
                            </button>
                            <div id="imageCounter" class="image-counter glass-counter"></div>
                        </div>
                    </div>
                </div>

                <!-- 缩略图区域 -->
                <div id="thumbnailStrip" class="thumbnail-strip glass-panel">
                    <div id="thumbnailScrollContainer" class="thumbnail-scroll-container"></div>
                </div>

                <!-- 网络图片上传输入框 -->
                <div class="url-input-section">
                    <input type="text" id="pasteOrUrlInput" class="pasteOrUrlInput glass-input" placeholder="输入图片网络链接自动上传，或使用Ctrl+V粘贴图片" title="注意：部分网站设置了防盗链，可能无法直接下载">
                </div>

                <!-- 压缩比率调整 -->
                <div class="quality-section glass-panel">
                    <label for="qualityInput">图片清晰度 60-100<output id="qualityOutput" class="qualityOutput">60</output></label>
                    <input type="range" id="qualityInput" min="60" max="100" value="60" step="1">
                </div>

                <!-- 复制按钮区域 -->
                <div class="copy-section">
                    <div class="copy-tab-buttons">
                        <div class="copy-icons-column">
                            <button type="button" class="copy-tab-btn glass-panel" data-type="url" title="复制图片链接" disabled>
                                <svg class="icon" aria-hidden="true">
                                    <use xlink:href="#icon-imageUrl"></use>
                                </svg>
                            </button>
                            <button type="button" class="copy-tab-btn glass-panel" data-type="markdown" title="复制Markdown代码" disabled>
                                <svg class="icon" aria-hidden="true">
                                    <use xlink:href="#icon-markdownUrl"></use>
                                </svg>
                            </button>
                            <button type="button" class="copy-tab-btn glass-panel" data-type="html" title="复制HTML代码" disabled>
                                <svg class="icon" aria-hidden="true">
                                    <use xlink:href="#icon-htmlUrl"></use>
                                </svg>
                            </button>
                        </div>
                        <div class="copy-links-column">
                            <div class="copy-link-display glass-panel disabled" data-type="url">
                                <span class="copy-link-text" id="urlLinkText"></span>
                            </div>
                            <div class="copy-link-display glass-panel disabled" data-type="markdown">
                                <span class="copy-link-text" id="markdownLinkText"></span>
                            </div>
                            <div class="copy-link-display glass-panel disabled" data-type="html">
                                <span class="copy-link-text" id="htmlLinkText"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="progressContainer" class="progressContainer">
                    <div id="progressBar" class="progressBar"></div>
                </div>
            </form>
        </div>

        <!-- 图片信息展示 -->
        <div id="imageInfo" class="imageInfo glass glass-in">
            <div class="image-info-block">
                <div class="info-header">
                    <svg class="icon info-icon" aria-hidden="true">
                        <use xlink:href="#icon-imageUrl"></use>
                    </svg>
                    <h3>原始图片</h3>
                </div>
                <div class="info-grid">
                    <div class="info-item glass-panel">
                        <span class="info-label">尺寸</span>
                        <span class="info-value" id="originalWidth"></span>
                    </div>
                    <div class="info-item glass-panel">
                        <span class="info-label">大小</span>
                        <span class="info-value" id="originalSize"></span>
                    </div>
                </div>
            </div>
            <div class="image-info-block">
                <div class="info-header">
                    <svg class="icon info-icon" aria-hidden="true">
                        <use xlink:href="#icon-up"></use>
                    </svg>
                    <h3>压缩后</h3>
                </div>
                <div class="info-grid">
                    <div class="info-item glass-panel">
                        <span class="info-label">尺寸</span>
                        <span class="info-value" id="compressedWidth"></span>
                    </div>
                    <div class="info-item glass-panel">
                        <span class="info-label">大小</span>
                        <span class="info-value" id="compressedSize"></span>
                    </div>
                </div>
            </div>
            <div class="compression-stats">
                <div class="stat-badge glass-panel">
                    <span class="stat-label">压缩率</span>
                    <span class="stat-value" id="compressionRatio">-</span>
                </div>
                <div class="stat-badge glass-panel">
                    <span class="stat-label">节省空间</span>
                    <span class="stat-value" id="savedSpace">-</span>
                </div>
            </div>
        </div>
        <div class="keyboard-hints glass glass-in">
            <div class="hint-item">
                <div class="kbd-group">
                    <kbd>←</kbd><kbd>→</kbd>
                </div>
                <span>切换图片</span>
            </div>
            <div class="hint-item">
                <div class="kbd-group">
                    <kbd>Ctrl</kbd><span class="plus">+</span><kbd>V</kbd>
                </div>
                <span>粘贴上传</span>
            </div>
            <div class="hint-item">
                <div class="kbd-group">
                    <kbd>Ctrl</kbd><span class="plus">+</span><kbd>点击</kbd>
                </div>
                <span>批量复制</span>
            </div>
            <div class="hint-item">
                <div class="kbd-group">
                    <kbd>滚轮</kbd>
                </div>
                <span>切换图片</span>
            </div>
            <div class="hint-item">
                <div class="kbd-group">
                    <kbd>Esc</kbd>
                </div>
                <span>清除图片</span>
            </div>
        </div>
    </main>
    <footer>
        <?php if (($_ENV['DEMO_MODE'] ?? 'false') === 'true'): ?>
        <div class="demo-warning glass glass-in" style="padding: 10px;margin-bottom: 10px;border-radius: 10px;font-size: 15px;font-weight: bold;background: rgb(255 60 60 / 30%);">⚠️ 演示站点 - 所有图片公开可见且可能被删除</div>
        <?php endif; ?>
        <span>富强</span>
        <span>民主</span>
        <span>文明</span>
        <span>和谐</span>
        <span>自由</span>
        <span>平等</span>
        <span>公正</span>
        <span>法治</span>
        <span>爱国</span>
        <span>敬业</span>
        <span>诚信</span>
        <span>友善</span>
        <div class="icp">
            <span>© 2024</span><a href="https://bsgun.cn" target="_blank">梦爱吃鱼</a>
            <span>本站程序发布在</span><a href="https://github.com/JLinMr/PixPro/" target="_blank">Github</a>
            <button class="logo-btn">站点声明</button>
            <em class="logotitle glass glass-in">本站不保证内容，时效和稳定性，请勿上传包含危害国家安全和民族团结、侵犯他人权益、欺骗性质、色情或暴力的图片。严格遵守国家相关法律法规，尊重版权、著作权等权利；图片内容均由「网友」自行上传，所有图片作用、性质都与本站无关，本站对所有图片合法性概不负责，亦不承担任何法律责任；</em>
        </div>
    </footer>
    <script>window.PIXPRO_CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;</script>
    <script type="module" src="static/js/main.js" data-max-file-size="<?php echo $maxFileSize; ?>">
    </script>
    <script type="text/javascript" src="static/js/front/cursor.js" defer></script>
    <script src="//at.alicdn.com/t/c/font_4623353_hb4c04qfi4u.js"></script>
</body>
</html>