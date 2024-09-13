<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/Database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['reset_password'])) {
        $config = parse_ini_file('../config/config.ini', true);

        if (!isset($config['Token']['validToken'])) {
            $_SESSION['error'] = "配置文件中缺少 validToken 配置项";
        } else {
            $validToken = $config['Token']['validToken'];

            if ($_POST['validToken'] !== $validToken) {
                $_SESSION['error'] = "无效的 validToken";
                $_SESSION['stay_on_reset_form'] = true;
            } else {
                $db = Database::getInstance();
                $mysqli = $db->getConnection();

                $username = $_POST['username'];
                $new_password = $_POST['new_password'];
                $confirm_password = $_POST['confirm_password'];

                if ($new_password !== $confirm_password) {
                    $_SESSION['error'] = "两次输入的密码不一致";
                    $_SESSION['stay_on_reset_form'] = true;
                } else {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                    $query = "UPDATE users SET password = ? WHERE username = ?";
                    $stmt = $mysqli->prepare($query);
                    $stmt->bind_param("ss", $hashed_password, $username);
                    $stmt->execute();

                    if ($stmt->affected_rows > 0) {
                        $_SESSION['success'] = "密码重置成功";
                    } else {
                        $_SESSION['error'] = "密码重置失败，请重试";
                        $_SESSION['stay_on_reset_form'] = true;
                    }
                }
            }
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    } elseif (isset($_POST['login'])) {
        $db = Database::getInstance();
        $mysqli = $db->getConnection();

        $username = filter_input(INPUT_POST, 'username', FILTER_UNSAFE_RAW);
        $password = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);
        $query = "SELECT * FROM users WHERE username = ?";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['user_id'] = $user['id'];
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $_SESSION['error'] = "用户名或密码无效";
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录</title>
    <link rel="shortcut icon" href="/static/favicon.ico">
    <link rel="stylesheet" type="text/css" href="/static/css/login.css">
</head>
<body>
    <div class="login-container">
        <div id="login-form" class="form-container <?php echo (!isset($_SESSION['stay_on_reset_form'])) ? 'active' : ''; ?>">
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
            <div class="reset-password">
                <a id="reset-btn">重置密码</a>
            </div>
        </div>
        <div id="reset-form" class="form-container <?php echo (isset($_SESSION['stay_on_reset_form'])) ? 'active' : ''; ?>">
            <p>查看配置文件中的validToken</p>
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
                    <label for="validToken">Valid Token</label>
                    <input type="text" id="validToken" name="validToken" required>
                </div>
                <button type="submit" name="reset_password">重置密码</button>
            </form>
            <div class="reset-password">
                <a id="login-btn">返回登录</a>
            </div>
        </div>
        <?php if (isset($_SESSION['error'])) { ?>
            <div id="error-message" style="display: none;"><?php echo $_SESSION['error']; ?></div>
        <?php unset($_SESSION['error']); ?>
        <?php } ?>
        <?php if (isset($_SESSION['success'])) { ?>
            <div id="success-message" style="display: none;"><?php echo $_SESSION['success']; ?></div>
        <?php unset($_SESSION['success']); ?>
        <?php } ?>
        <?php if (isset($_SESSION['stay_on_reset_form'])) { ?>
            <?php unset($_SESSION['stay_on_reset_form']); ?>
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

        document.getElementById('login-btn').addEventListener('click', function() {
            document.getElementById('login-form').classList.add('active');
            document.getElementById('reset-form').classList.remove('active');
        });

        document.getElementById('reset-btn').addEventListener('click', function() {
            document.getElementById('reset-form').classList.add('active');
            document.getElementById('login-form').classList.remove('active');
        });
    </script>
</body>
</html>