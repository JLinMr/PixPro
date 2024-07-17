## **前言**

点一个 Star 再走吧~

一款专为个人需求设计的高效图床解决方案，集成了强大的图片压缩功能与优雅的前台后台管理界面。

项目结构精简高效，提供自定义图片压缩率与尺寸设置，有效降低存储与带宽成本。

支持上传JPEG、PNG、GIF格式图片并转换为WEBP格式，支持上传SVG、WEBP图片。

支持本地储存，OSS储存，S3存储。可通过把储存桶挂载到本地的方式解锁更多储存方式。

简洁美观的前端，支持点击、拖拽、粘贴、URL、批量上传。

瀑布流管理后台，便捷管理图片，支持图片灯箱、AJAX无加载刷新。

支持自定义压缩率，默认60，可自定义修改。支持修改每日上传限制，单次上传限制

## **演示站点**

前端：https://dev.ruom.top/

后台：https://dev.ruom.top/admin/

## **项目简介**

本项目由几个简单的文件组成。采用简单高效的方式进行图片压缩，支持自定义压缩率和尺寸。

帮助大家减少图片储存、流量等方面的支出。

如果需要更换存储策略，需安装后修改`config.ini`文件

## **安装教程**
首先下载源码ZIP，将文件上传到网站根目录，访问网址  ，填写相关信息，即可完成安装。

## **运行环境**
推荐PHP 8.1 + MySQL >= 5.7

本程序依赖PHP的 Fileinfo 、 Imagick 拓展，需要自行安装。依赖 pcntl 扩展（宝塔PHP默认已安装）

要求 pcntl_signal 和 pcntl_alarm 函数可用（需主动解除禁用）。

### **配置信息安全**

设置如下 nginx 规则
```
location ~* /config\.ini$ {
    deny all;
}
```

### **上传限制**

编辑 `config/validate.php` 文件。同步修改`static/js/script.js`的头部内容
```
<?php
// 设置参数
$maxUploadsPerDay = 50; // 每天最多上传50次
$maxFileSize = 5 * 1024 * 1024; // 文件大小限制 5MB 修改这里同步修改 script.js

function isUploadAllowed($maxUploadsPerDay) {
    $cookieName = 'upload_count';
    $currentDate = date('Y-m-d');
    if (isset($_COOKIE[$cookieName])) {
        $uploadCounts = json_decode($_COOKIE[$cookieName], true);
        if ($uploadCounts['date'] === $currentDate) {
            if ($uploadCounts['count'] >= $maxUploadsPerDay) {
                return '上传次数过多，请明天再试';
            }
            $uploadCounts['count']++;
        } else {
            $uploadCounts = [
                'date' => $currentDate,
                'count' => 1
            ];
        }
    } else {
        $uploadCounts = [
            'date' => $currentDate,
            'count' => 1
        ];
    }
    // 设置 Cookie，过期时间为一天
    setcookie($cookieName, json_encode($uploadCounts), time() + 86400, "/");

    return true;
}

$uploadCheck = isUploadAllowed($maxUploadsPerDay);
if ($uploadCheck !== true) {
    echo json_encode(['error' => $uploadCheck]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $file = $_FILES['image'];

    if ($file['size'] > $maxFileSize) {
        $maxFileSizeMB = $maxFileSize / (1024 * 1024);
        echo json_encode(['error' => '文件大小超过限制，最大允许 ' . $maxFileSizeMB . 'MB']);
        exit();
    }

    echo json_encode(['success' => '文件上传成功']);
} else {
    echo json_encode(['error' => '无效的请求']);
}
?>
```
### **修改后台地址**

直接修改 `admin` 目录名即可

## **拓展功能**

本程序支持 UPGIT 对接在Typora使用，对接方法如下：

**UPGIT 配置信息**

在upgit.exe所在目录下新建`config.toml`文件。文件内容如下：
```
default_uploader = "easyimage"

[uploaders.easyimage]
request_url = "https://xxx.xxx.xxx/api.php"
token = "1c17b11693cb5ec63859b091c5b9c1b2"

```

创建一个 upgit.exe 的同级目录：**extensions**

然后到 **extensions** 目录下新建一个 **easyimage.jsonc** 文件，输入下面的内容并保存。
```
{
    "meta": {
        "id": "easyimage",
        "name": "EasyImage Uploader",
        "type": "simple-http-uploader",
        "version": "0.0.1",
        "repository": ""
    },
    "http": {
        "request": {
            // See https://www.kancloud.cn/easyimage/easyimage/2625228
            "url": "$(ext_config.request_url)",
            "method": "POST",
            "headers": {
                "Content-Type": "multipart/form-data",
                "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/98.0.4758.80 Safari/537.36"
            },
            "body": {
                "token": {
                    "type": "string",
                    "value": "$(ext_config.token)"
                },
                "image": {
                    "type": "file",
                    "value": "$(task.local_path)"
                }
            }
        }
    },
    "upload": {
        "rawUrl": {
            "from": "json_response",
            "path": "url"
        }
    }
}
```
### 接入到 Typora

**转到 Image 选自定义命令作为图像上传器，在命令文本框中输入 Upgit 程序位置，然后就可以使用了：**
![接入到Typora](https://cdn.dusays.com/2022/05/459-2.jpg)
