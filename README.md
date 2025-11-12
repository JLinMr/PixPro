<div align="center">
    <img src="static/favicon.svg" width="100" height="100">
    <h1>PixPro</h1>
    <p>一个高效、简洁的图片上传系统，支持多种存储方式，包括本地存储、阿里云OSS、S3存储、又拍云存储，另可通过挂载扩展更多存储方式</p>
    <p align="center">🎮 在线演示：
      <a href="https://dev.bsgun.cn" target="_blank">
        https://dev.bsgun.cn
      </a>
      <p>演示站点更新较频繁，可能与实际效果存在差异</p>
    </p>
      <p style="color:rgb(247, 138, 138);">本站为公开演示环境，所有上传内容都对访客可见，且可能被他人恶意删除。请勿在此上传任何重要或私密文件，本站不保障文件安全与留存。</p>
</div>

## ✨ 特性

- 🚀 **高效压缩** - 集成强大的图片压缩功能，支持自定义压缩率，提升图片加载速度
- 🌐 **多种格式** - 支持多种图片格式，包括 JPEG、PNG、GIF、WebP、SVG 等，支持输出原格式、WebP、AVIF格式
- 💾 **多种存储** - 支持本地存储、阿里云OSS、S3存储、又拍云存储，另可通过挂载扩展更多存储方式
- 🎨 **优雅界面** - 简洁美观的前端界面，支持拖拽上传、粘贴上传等多种上传方式
- 📊 **便捷管理** - 瀑布流后台布局，支持图片灯箱预览和AJAX无感刷新

## 🚀 快速开始

1. 下载最新版本源码
2. 上传到网站根目录
3. 访问网站，根据向导完成安装

## 📋 环境要求

- PHP >= 8.1
- MySQL >= 5.6
- PHP扩展：
  - Fileinfo 
  - Imagick
  - exif
  - pcntl (需确保 pcntl_signal 和 pcntl_alarm 函数可用)

## 🔗 Twikoo 集成

> 兼容了Twikoo的兰空图床格式，所以可以直接使用兰空图床的配置

### 1. 伪静态配置

添加以下重写规则到你的 Nginx 配置或伪静态配置中：

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^/api/v1/upload$ /api.php last;
    }
}
```

### 2. TWikoo 后台配置

参考兰空图床的配置即可，在 TWikoo 管理面板中设置以下参数：

- `IMAGE_CDN`：设置为你的图床域名，例如 `https://your-domain.com/`
- `IMAGE_CDN_TOKEN`：设置为你的图床 Token

## 🔌 Typora 集成

本程序支持通过 Upgit 在 Typora 中使用，配置步骤如下：

1. 下载 [Upgit](https://coobl.lanzouq.com/i5ZZ82ohf8sf)

2. 修改 `config.toml` 文件：

```toml
default_uploader = "PixPro"

[uploaders.PixPro]
request_url = "https://your-domain.com/api.php"
token = "YOUR_TOKEN"
```

3. 在 Typora 偏好设置中：
   - 转到「图像」选项卡
   - 选择「自定义命令」作为图像上传器
   - 输入 Upgit 程序路径
   
![Typora配置示例](https://cdn.dusays.com/2022/05/459-2.jpg)

## CDN赞助

本项目 CDN 加速及安全防护由 Tencent EdgeOne 赞助：EdgeOne 提供长期有效的免费套餐，包含不限量的流量和请求，覆盖中国大陆节点，且无任何超额收费，感兴趣的朋友可以去 EdgeOne 官网获取

<a href="https://edgeone.ai/zh?from=github" target="_blank" >
    最佳亚洲 CDN、Edge 和安全解决方案 - 腾讯 EdgeOne
<img src="https://edgeone.ai/media/34fe3a45-492d-4ea4-ae5d-ea1087ca7b4b.png" width="500" height="100">
</a>

## 📝 许可证

[MIT License](LICENSE)
