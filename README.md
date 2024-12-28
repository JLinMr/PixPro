## 前言

点一个 Star 再走吧~

一款专为个人需求设计的高效图床解决方案，集成了强大的图片压缩功能与优雅的前台后台管理界面。

项目结构精简高效，提供自定义图片压缩率与尺寸设置，有效降低存储与带宽成本。

支持本地储存，阿里云OSS储存，S3存储。可通过把储存桶挂载到本地的方式解锁更多储存方式。

简洁美观的前端，支持点击、拖拽、粘贴、URL、批量上传。

瀑布流管理后台，便捷查看图片信息，支持图片灯箱、AJAX无加载刷新。

## 演示站点

前端：https://dev.ruom.top/

后台：https://dev.ruom.top/admin/（用户名：admin，密码：123456）

## 安装教程

首先下载源码ZIP，将文件上传到网站根目录，访问网址  ，填写相关信息，即可完成安装。

## 运行环境

推荐PHP 8.1 + MySQL >= 5.6

本程序依赖PHP的 Fileinfo 、 Imagick 、 exif拓展，需要自行安装。依赖 pcntl 扩展（宝塔PHP默认已安装）

要求 pcntl_signal 和 pcntl_alarm 函数可用（需主动解除禁用）

## 资源加速

项目已经上传到NPM，所有静态资源均可以使用
###  使用npmmirror，@version需要改为版本号
https://cdn.npmmirror.com/packages/pixpro/@version/files/

例如: https://cdn.npmmirror.com/packages/pixpro/1.7.6/files/static/js/admin.js

### 使用 jsdelivr
https://cdn.jsdelivr.net/npm/pixpro@latest/

例如: https://cdn.jsdelivr.net/npm/pixpro@1.7.6/static/js/admin.js


## 拓展功能

本程序支持 Upgit 对接在Typora使用，对接方法如下

### 下载upgit

前往下载 [Upgit](https://www.ilanzou.com/s/dkc0YXrI)

### 如何配置

修改目录下`config.toml`文件，内容如下

```toml
default_uploader = "PixPro"

[uploaders.PixPro]
request_url = "https://xxx.xxx.xxx/api.php"
token = "这里内容替换为你的Token"
```
### 接入 Typora

转到 Image 选自定义命令作为图像上传器，在命令文本框中输入 Upgit 程序位置，然后就可以使用了
![接入到Typora](https://cdn.dusays.com/2022/05/459-2.jpg)
