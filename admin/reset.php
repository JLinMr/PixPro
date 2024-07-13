<?php
session_start();
require_once '../config/Database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $config = parse_ini_file('../config/config.ini', true);

    if (!isset($config['Token']['reset_token'])) {
        $_SESSION['error'] = "配置文件中缺少 reset_token 配置项！";
    } else {
        $reset_token = $config['Token']['reset_token'];

        if ($_POST['token'] !== $reset_token) {
            $_SESSION['error'] = "无效的 token！";
        } else {
            $db = Database::getInstance();
            $mysqli = $db->getConnection();

            $username = $_POST['username'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if ($new_password !== $confirm_password) {
                $_SESSION['error'] = "两次输入的密码不一致！";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $query = "UPDATE users SET password = ? WHERE username = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ss", $hashed_password, $username);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $_SESSION['success'] = "密码重置成功！";
                } else {
                    $_SESSION['error'] = "密码重置失败，请重试！";
                }
            }
        }
    }
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重置密码</title>
    <link rel="shortcut icon" href="/static/favicon.ico">
    <link rel="stylesheet" type="text/css" href="/static/css/login.css">
</head>
<body>
    <div class="login-container">
        <p>查看config中的reset_token</p>
        <p style="color: #ff0000">为了站点安全，请勿告知他人 </p>
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="new_password">新密码</label>
                <input type="password" id="new_password" name="new_password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">确认新密码</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <div class="form-group">
                <label for="token">Token</label>
                <input type="text" id="token" name="token" required>
            </div>
            <button type="submit">重置密码</button>
            <div class="reset-password">
                <a href="<?php echo dirname($_SERVER['PHP_SELF']); ?>">返回登录</a>
            </div>
            <?php if (isset($_SESSION['error'])) { ?>
                <div id="error-message" style="display: none;"><?php echo $_SESSION['error']; ?></div>
            <?php unset($_SESSION['error']); ?>
            <?php } ?>
            <?php if (isset($_SESSION['success'])) { ?>
                <div id="success-message" style="display: none;"><?php echo $_SESSION['success']; ?></div>
            <?php unset($_SESSION['success']); ?>
            <?php } ?>
        </form>
    </div>
    <script>
        function showNotification(message, className = 'green-success') {
            const existingNotification = document.querySelector('.green-success, .red-success');
            if (existingNotification) {
                existingNotification.parentNode.removeChild(existingNotification);
            }
            const notification = document.createElement('div');
            notification.classList.add(className);
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.classList.add('success-right');
                setTimeout(() => notification.parentNode.removeChild(notification), 1000);
            }, 1500);
        }
        
        const errorMessage = document.getElementById('error-message');
        if (errorMessage && errorMessage.textContent) {
            showNotification(errorMessage.textContent, 'red-success');
        }
        
        const successMessage = document.getElementById('success-message');
        if (successMessage && successMessage.textContent) {
            showNotification(successMessage.textContent, 'green-success');
        }
    </script>
</body>
</html>