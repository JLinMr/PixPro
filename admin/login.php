<?php
session_status() == PHP_SESSION_NONE && session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    $username = filter_input(INPUT_POST, 'username', FILTER_UNSAFE_RAW);
    $password = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);
    
    $stmt = $mysqli->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    
    if ($user = $stmt->get_result()->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['user_id'] = $user['id'];
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
    
    $_SESSION['error'] = "用户名或密码无效";
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录</title>
    <link rel="shortcut icon" href="/static/favicon.svg">
    <link rel="stylesheet" type="text/css" href="/static/css/login.css">
</head>
<body>
    <div class="login-container">
        <div id="login-form" class="form-container active">
            <h2>登录</h2>
            <form method="post" action="">
                <div class="form-group">
                    <label for="username">账号：</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">密码：</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="action-buttons">
                    <button type="submit" name="login">登录</button>
                </div>
            </form>
        </div>
        <?php if (isset($_SESSION['error'])) { ?>
            <div id="error-message" style="display: none;"><?php echo $_SESSION['error']; ?></div>
        <?php unset($_SESSION['error']); ?>
        <?php } ?>
        <?php if (isset($_SESSION['success'])) { ?>
            <div id="success-message" style="display: none;"><?php echo $_SESSION['success']; ?></div>
        <?php unset($_SESSION['success']); ?>
        <?php } ?>
    </div>
    <script>
        function showNotification(message, className = 'msg-green') {
            const notification = document.createElement('div');
            notification.className = `msg ${className}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.classList.add('msg-right');
                setTimeout(() => notification.remove(), 800);
            }, 1500);
        }

        const errorMessage = document.getElementById('error-message');
        if (errorMessage && errorMessage.textContent) {
            showNotification(errorMessage.textContent, 'msg-red');
        }

        const successMessage = document.getElementById('success-message');
        if (successMessage && successMessage.textContent) {
            showNotification(successMessage.textContent);
        }
    </script>
</body>
</html>