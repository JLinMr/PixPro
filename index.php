<?php
if (!file_exists('install/install.lock')) {
    header('Location: /install');
    exit;
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
    <link rel="shortcut icon" href="static/favicon.ico">
    <link rel="stylesheet" type="text/css" href="static/css/styles.css">
    <!-- 弹窗公告 -->
    <link rel="stylesheet" type="text/css" href="static/css/notification.css">
    <script type="text/javascript" src="static/js/notification.js" defer></script>
    <!-- 不需要的直接注释这两行 -->
</head>
<body>
    <nav>
        <a href="https://www.bsgun.cn/" target="_blank" title="主页"><img src="static/images/svg/home.svg" alt="主页"/></a>
        <a href="https://blog.bsgun.cn/" target="_blank" title="博客"><img src="static/images/svg/Blog.svg" alt="博客"/></a>
        <a href="https://github.com/JLinMr/PixPro/" target="_blank" title="Github"><img src="static/images/svg/Github.svg" alt="Github"/></a>
        <a href="/admin/" target="_blank" title="后台" ><img src="static/images/svg/Setting.svg" alt="后台"/></a>
    </nav>
    <div class="uploadForm">
        <button id="deleteImageButton"><img src="static/images/svg/xmark.svg" alt="x"></button>
        <form id="uploadForm" enctype="multipart/form-data">
            <div id="imageUploadBox" onclick="document.getElementById('imageInput').click();">
                <input type="file" id="imageInput" name="image[]" accept="image/*" multiple required style="display: none;">
                <img id="imagePreview" src="static/images/svg/up.svg" alt="预览图片">
            </div>
            <div id="pasteOrUrlInputBox">
                <input type="text" id="pasteOrUrlInput" placeholder="此处可粘贴图像URL或使用Ctrl+V粘贴图片">
            </div>
            <div id="parameters">
                <label for="qualityInput">图片清晰度 60-100<output id="qualityOutput">60</output></label>
                <input type="range" id="qualityInput" name="quality" min="60" max="100" value="60" step="5">
            </div>
            <div id="progressContainer">
                <div id="progressBar"></div>
            </div>
        </form>
    </div>
    <div class="urlOutput" id="urlOutput">
        <div class="tab-buttons">
            <button class="tab-button active" data-target="tab1" title="图片链接"><img src="static/images/svg/imageUrl.svg" alt="图片链接"/></button>
            <button class="tab-button" data-target="tab2" title="Markdown代码"><img src="static/images/svg/markdownUrl.svg" alt="Markdown代码"/></button>
            <button class="tab-button" data-target="tab3" title="Markdown链接"><img src="static/images/svg/markdownLinkUrl.svg" alt="Markdown链接"/></button>
            <button class="tab-button" data-target="tab4" title="HTML代码"><img src="static/images/svg/htmlUrl.svg" alt="HTML代码"/></button>
        </div>
        <div class="tab-content">
            <div class="tab-pane active" id="tab1">
                <div class="input-container" id="imageUrlContainer"></div>
            </div>
            <div class="tab-pane" id="tab2">
                <div class="input-container" id="markdownUrlContainer"></div>
            </div>
            <div class="tab-pane" id="tab3">
                <div class="input-container" id="markdownLinkUrlContainer"></div>
            </div>
            <div class="tab-pane" id="tab4">
                <div class="input-container" id="htmlUrlContainer"></div>
            </div>
        </div>
    </div>
    <div id="imageInfo" class="double-column-layout">
        <div>
            <h2>压缩前</h2>
            <div style="text-align:center;">
                <p>宽度：<span id="originalWidth"></span> px</p>
                <p>高度：<span id="originalHeight"></span> px</p>
                <p>大小：<span id="originalSize"></span> KB</p>
            </div>
        </div>
        <div>
            <h2>压缩后</h2>
            <div style="text-align:center;">
                <p>宽度：<span id="compressedWidth"></span> px</p>
                <p>高度：<span id="compressedHeight"></span> px</p>
                <p>大小：<span id="compressedSize"></span> KB</p>
            </div>
        </div>
    </div>
    <footer class="footer-nav">
        <a>富强</a>
        <a>民主</a>
        <a>文明</a>
        <a>和谐</a>
        <a>自由</a>
        <a>平等</a>
        <a>公正</a>
        <a>法制</a>
        <a>爱国</a>
        <a>敬业</a>
        <a>诚实</a>
        <a>友善</a>
        <div class="icp">
            <span>© 2024</span><a href="https://bsgun.cn" target="_blank">梦爱吃鱼</a>
            <span>本站程序发布在</span><a href="https://github.com/JLinMr/PixPro/" target="_blank">Github</a>
            <button class="logo-btn">站点声明</button>
            <em class="logotitle">本站不保证内容，时效和稳定性，请勿上传包含危害国家安全和民族团结、侵犯他人权益、欺骗性质、色情或暴力的图片。严格遵守国家相关法律法规，尊重版权、著作权等权利；图片内容均由「网友」自行上传，所有图片作用、性质都与本站无关，本站对所有图片合法性概不负责，亦不承担任何法律责任；</em>
        </div>
    </footer>
    <script type="text/javascript" src="static/js/script.js" defer></script>
    <!-- 引入鼠标指针跟随特效 -->
    <script type="text/javascript" src="static/js/cursor.js" defer></script>
</body>
</html>