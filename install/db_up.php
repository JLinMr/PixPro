<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库表升级</title>
    <link rel="stylesheet" type="text/css" href="../static/css/install.css">
</head>
<body>
    <div class="message-box">
        <h1>数据库表升级</h1>
        <?php
        require_once '../config/Database.php';
        $db = Database::getInstance();
        $mysqli = $db->getConnection();

        $checkImagesTableSQL = "SHOW TABLES LIKE 'images'";
        $result = $mysqli->query($checkImagesTableSQL);

        if ($result && $result->num_rows > 0) {
            $checkUserIdColumnSQL = "SHOW COLUMNS FROM images LIKE 'user_id'";
            $userIdColumnResult = $mysqli->query($checkUserIdColumnSQL);

            if ($userIdColumnResult->num_rows == 0) {
                $alterUserIdColumnSQL = "
                    ALTER TABLE images
                    ADD COLUMN user_id INT UNSIGNED NULL COMMENT '用户ID' AFTER id;
                ";
                if ($mysqli->query($alterUserIdColumnSQL) === FALSE) {
                    echo '<p class="error">添加 user_id 列失败: ' . $mysqli->error . '</p>';
                } else {
                    echo '<p class="success">user_id 列添加成功！</p>';
                }
            } else {
                echo '<p class="info">images 表中已存在 user_id 列，跳过添加。</p>';
            }

            $checkSizeColumnSQL = "SHOW COLUMNS FROM images LIKE 'size'";
            $sizeColumnResult = $mysqli->query($checkSizeColumnSQL);

            if ($sizeColumnResult->num_rows == 0) {
                $alterSizeColumnSQL = "
                    ALTER TABLE images
                    ADD COLUMN size INT UNSIGNED NOT NULL COMMENT '图片大小(字节)' AFTER storage;
                ";
                if ($mysqli->query($alterSizeColumnSQL) === FALSE) {
                    echo '<p class="error">添加 size 列失败: ' . $mysqli->error . '</p>';
                } else {
                    echo '<p class="success">size 列添加成功！</p>';
                }
            } else {
                echo '<p class="info">images 表中已存在 size 列，跳过添加。</p>';
            }

            $checkUploadIpColumnSQL = "SHOW COLUMNS FROM images LIKE 'upload_ip'";
            $uploadIpColumnResult = $mysqli->query($checkUploadIpColumnSQL);

            if ($uploadIpColumnResult->num_rows == 0) {
                $alterUploadIpColumnSQL = "
                    ALTER TABLE images
                    ADD COLUMN upload_ip VARCHAR(45) NOT NULL COMMENT '上传者IP地址' AFTER size;
                ";
                if ($mysqli->query($alterUploadIpColumnSQL) === FALSE) {
                    echo '<p class="error">添加 upload_ip 列失败: ' . $mysqli->error . '</p>';
                } else {
                    echo '<p class="success">upload_ip 列添加成功！</p>';
                }
            } else {
                echo '<p class="info">images 表中已存在 upload_ip 列，跳过添加。</p>';
            }

            $alterStorageColumnSQL = "
                ALTER TABLE images
                MODIFY COLUMN storage ENUM('oss', 'local', 's3', 'other') NOT NULL COMMENT '存储方式';
            ";
            if ($mysqli->query($alterStorageColumnSQL) === FALSE) {
                echo '<p class="error">修改 storage 列失败: ' . $mysqli->error . '</p>';
            } else {
                echo '<p class="success">storage 列修改成功！</p>';
            }
        } else {
            echo '<p class="error">images 表不存在，无法升级。</p>';
        }

        $checkUsersTableSQL = "SHOW TABLES LIKE 'users'";
        $result = $mysqli->query($checkUsersTableSQL);

        if ($result && $result->num_rows > 0) {
            $alterUsersTableSQL = "
                ALTER TABLE users
                COMMENT='用户表';
            ";
            if ($mysqli->query($alterUsersTableSQL) === FALSE) {
                echo '<p class="error">修改 users 表失败: ' . $mysqli->error . '</p>';
            } else {
                echo '<p class="success">users 表升级成功！</p>';
            }
        } else {
            echo '<p class="error">users 表不存在，无法升级。</p>';
        }
        $mysqli->close();

        $configFilePath = '../config/config.ini';
        if (file_exists($configFilePath)) {
            $configContent = file_get_contents($configFilePath);
            $configContent = preg_replace_callback('/^\s*protocol\s*=\s*(\w+)\s*$/m', function($matches) {
                $protocol = $matches[1];
                if ($protocol === 'http') {
                    return "protocol = http://";
                } elseif ($protocol === 'https') {
                    return "protocol = https://";
                }
                return $matches[0];
            }, $configContent);

            if (file_put_contents($configFilePath, $configContent) !== FALSE) {
                echo '<p class="success">config.ini 文件中的 protocol 配置项已更新！</p>';
                chmod($configFilePath, 0600);
            } else {
                echo '<p class="error">无法写入 config.ini 文件。</p>';
            }
        } else {
            echo '<p class="error">config.ini 文件不存在。</p>';
        }

        $currentFilePath = __FILE__;
        if (unlink($currentFilePath)) {
            echo '<p class="success">升级完成，当前文件已删除。</p>';
        } else {
            echo '<p class="error">无法删除当前文件。</p>';
        }
        ?>
    <a href="/">回到首页</a>
    </div>
</body>
</html>