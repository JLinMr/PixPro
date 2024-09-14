## 安装教程

首先下载源码ZIP，将文件上传到网站根目录，访问网址  ，填写相关信息，即可完成安装。

### Github存储获取Token

1. 登录Github账号，然后点击 https://github.com/settings/tokens/new

2. Expiration 设置有效时间

3. 勾选 repo 权限

4. 然后生成就行了

## 运行环境

推荐PHP 8.1 + MySQL >= 5.7

本程序依赖PHP的 Fileinfo 、 Imagick 、 exif拓展，需要自行安装。依赖 pcntl 扩展（宝塔PHP默认已安装）

要求 pcntl_signal 和 pcntl_alarm 函数可用（需主动解除禁用）

## 功能配置

如果需要更换存储策略，需安装后修改`config.ini`文件

### 安全配置

设置站点伪静态或修改nginx配置
```
location ~* /config\.ini$ {
    deny all;
}
```
### 登录上传

编辑 `config.ini` 文件

``` ini
login_restriction = false
; // login_restriction  true 开启 false 关闭 // 是否开启登录保护，默认false，开启后只有登录用户才能上传图片
```

### 上传限制

编辑 `config/validate.php` 文件头部。同步修改`static/js/script.js`的头部内容
```php
// validate.php
// 设置参数
$maxUploadsPerDay = 50; // 每天最多上传50次
$maxFileSize = 5 * 1024 * 1024; // 文件大小限制 5MB 修改这里同步修改 script.js
```
```js
// script.js
// 设置参数
const maxFileSize = 5 * 1024 * 1024;  // 文件大小限制 5MB
const maxFilesPerUpload = 5; // 最多上传5张图片
```
### 修改后台

直接修改 `admin` 目录名即可

## 资源加速

项目已经上传到NPM，所有静态资源均可以使用
###  使用npmmirror，@version需要改为版本号
https://cdn.npmmirror.com/packages/pixpro/@version/files/

例如: https://cdn.npmmirror.com/packages/pixpro/1.7.6/files/static/js/admin.js

### 使用 jsdelivr
https://cdn.jsdelivr.net/npm/pixpro@latest/

例如: https://cdn.jsdelivr.net/npm/pixpro@1.7.6/static/js/admin.js

### 使用 UNPKG的第三方镜像
https://cdn.cbd.int/pixpro@latest/

例如：https://cdn.cbd.int/pixpro@1.7.6/static/js/admin.js


## 拓展功能

本程序支持 Upgit 对接在Typora使用，对接方法如下

### 下载upgit

前往下载 [Upgit](https://alist.ruom.top/%E8%B5%84%E6%BA%90-%E5%88%86%E4%BA%AB/%E6%88%91%E7%9A%84%E9%A1%B9%E7%9B%AE/Upgit)

### 如何配置

修改目录下`config.toml`文件，内容如下

```toml
default_uploader = "easyimage"

[uploaders.easyimage]
request_url = "https://xxx.xxx.xxx/api.php"
token = "这里内容替换为你的Token"
```
### 接入 Typora

转到 Image 选自定义命令作为图像上传器，在命令文本框中输入 Upgit 程序位置，然后就可以使用了
![接入到Typora](https://cdn.dusays.com/2022/05/459-2.jpg)
