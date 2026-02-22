<?php
session_status() === PHP_SESSION_NONE && session_start();
require_once '../config/database.php';

$db = Database::getInstance();
$allowPasswordReset = ($_ENV['ALLOW_PASSWORD_RESET'] ?? 'false') === 'true';
$isDemoMode = ($_ENV['DEMO_MODE'] ?? 'false') === 'true';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? 'login';
    $pdo = $db->getConnection();
    
    if ($action === 'login') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$_POST['username'] ?? '']);
        
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC) and password_verify($_POST['password'] ?? '', $user['password'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_id'] = $user['id'];
            unset($_SESSION['demo_auto_login']);
        } else {
            $_SESSION['error'] = "用户名或密码无效";
        }
    } elseif ($action === 'demo_login' && $isDemoMode) {
        // 演示模式快速登录
        $stmt = $pdo->prepare("SELECT id, username FROM users ORDER BY id LIMIT 1");
        $stmt->execute();
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['demo_auto_login'] = true;
        }
    } elseif ($action === 'reset' && $allowPasswordReset) {
        $username = $_POST['username'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (!$username || !$newPassword || !$confirmPassword) {
            $_SESSION['error'] = "请填写所有字段";
        } elseif ($newPassword !== $confirmPassword) {
            $_SESSION['error'] = "两次输入的密码不一致";
        } elseif (strlen($newPassword) < 6) {
            $_SESSION['error'] = "密码长度至少为6位";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            
            if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']])) {
                    $_SESSION['success'] = "密码重置成功，请使用新密码登录";
                } else {
                    $_SESSION['error'] = "重置失败";
                }
            } else {
                $_SESSION['error'] = "用户名不存在";
            }
        }
    }
    
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录</title>
    <link rel="shortcut icon" href="/static/favicon.svg">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            cursor: url(../static/images/alternate.png) 16 16, auto;
        }
        
        a, button, input {
            cursor: url(../static/images/link.png) 16 16, pointer;
        }
        
        h2, label {
            cursor: url(../static/images/text.png) 16 16, text;
        }
        
        body {
            min-height: 100vh;
            color: #eee;
            display: flex;
            align-items: center;
            justify-content: center;
            background: url(../static/images/bg.webp) no-repeat 100% 100% / cover fixed;
        }
        
        .login-container {
            width: min(400px, 90vw);
            padding: 2rem;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        h2 {
            margin-bottom: 1.5rem;
            font-size: 1.75rem;
            font-weight: 600;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .password-input-wrapper {
            position: relative;
        }
        
        .password-input-wrapper input {
            padding-right: 2.5rem;
        }
        
        .toggle-password {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            opacity: 0.5;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        
        .toggle-password:hover {
            opacity: 1;
            transform: translateY(-50%) scale(1.1);
        }
        
        .toggle-password .icon {
            width: 1.125rem;
            height: 1.125rem;
        }
        
        input {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            outline: none;
            transition: all 0.3s ease;
        }
        
        input:focus {
            border-color: rgba(255, 255, 255, 0.4);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1);
        }
        
        input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }
        
        button {
            width: 100%;
            padding: 0.75rem;
            margin-top: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #fff;
            background: rgba(255, 255, 255, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        button:hover {
            border-color: rgba(255, 255, 255, 0.4);
            background: rgba(255, 255, 255, 0.35);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1);
        }
        
        .btn-demo {
            background: rgba(255, 193, 7, 0.25);
            border-color: rgba(255, 193, 7, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-demo:hover {
            background: rgba(255, 193, 7, 0.35);
            border-color: rgba(255, 193, 7, 0.5);
        }
        
        .toggle-form {
            margin-top: 1rem;
            text-align: center;
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .toggle-form a {
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            transition: opacity 0.3s ease;
        }
        
        .toggle-form a:hover {
            opacity: 0.8;
        }
        
        .form-container {
            display: none;
        }
        
        .form-container.active {
            display: block;
        }
        
        .security-warning {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        
        .security-warning strong {
            display: block;
            margin-bottom: 0.5rem;
            color: #fca5a5;
        }
        
        .msg {
            position: fixed;
            top: 20px;
            right: 15px;
            padding: 1rem 1.25rem;
            border-radius: 8px;
            color: #fff;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideIn 0.4s ease-out;
        }
        
        .msg-red {
            background: rgba(220, 38, 38, 0.9);
        }
        
        .msg-green {
            background: rgba(34, 197, 94, 0.9);
        }
        
        .msg-right {
            animation: slideOut 0.4s ease-in forwards;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes slideOut {
            to {
                opacity: 0;
                transform: translateX(100%);
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="form-container active" id="login-form">
            <h2>登录</h2>
            <form method="post">
                <input type="hidden" name="action" value="<?= $isDemoMode ? 'demo_login' : 'login' ?>">
                <div class="form-group">
                    <label for="username">账号：</label>
                    <input type="text" name="username" <?= $isDemoMode ? 'disabled placeholder="演示模式无需填写"' : 'required' ?>>
                </div>
                <div class="form-group">
                    <label for="password">密码：</label>
                    <div class="password-input-wrapper">
                        <input type="password" name="password" <?= $isDemoMode ? 'disabled placeholder="演示模式无需填写"' : 'required' ?>>
                        <?php if (!$isDemoMode): ?>
                        <span class="toggle-password">
                            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-eye"></use></svg>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="submit" <?= $isDemoMode ? 'class="btn-demo"' : '' ?>>
                    <?php if ($isDemoMode): ?>
                        <svg style="width: 18px; height: 18px; vertical-align: middle; margin-right: 6px;" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                        快速体验
                    <?php else: ?>
                        登录
                    <?php endif; ?>
                </button>
            </form>
            <?php if ($allowPasswordReset && !$isDemoMode): ?>
            <div class="toggle-form">
                <a href="#" onclick="toggleForm(event)">忘记密码？</a>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($allowPasswordReset): ?>
        <div class="form-container" id="reset-form">
            <h2>重置密码</h2>
            <div class="security-warning">
                <strong>⚠️ 安全提示</strong>
                密码重置功能已开启。为了系统安全，请在完成密码重置后，立即在 .env 文件中将 ALLOW_PASSWORD_RESET 设置为 false 以关闭此功能。
            </div>
            <form method="post">
                <input type="hidden" name="action" value="reset">
                <div class="form-group">
                    <label for="username">用户名：</label>
                    <input type="text" name="username" required placeholder="请输入要重置密码的用户名">
                </div>
                <div class="form-group">
                    <label for="new_password">新密码：</label>
                    <div class="password-input-wrapper">
                        <input type="password" name="new_password" required minlength="6" placeholder="至少6位字符">
                        <span class="toggle-password">
                            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-eye"></use></svg>
                        </span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">确认密码：</label>
                    <div class="password-input-wrapper">
                        <input type="password" name="confirm_password" required minlength="6" placeholder="再次输入新密码">
                        <span class="toggle-password">
                            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-eye"></use></svg>
                        </span>
                    </div>
                </div>
                <button type="submit">重置密码</button>
            </form>
            <div class="toggle-form">
                <a href="#" onclick="toggleForm(event)">返回登录</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($msg = $_SESSION['error'] ?? $_SESSION['success'] ?? null): ?>
    <script>
        const div = document.createElement('div');
        div.className = 'msg msg-<?= isset($_SESSION['error']) ? 'red' : 'green' ?>';
        div.textContent = <?= json_encode($msg) ?>;
        document.body.appendChild(div);
        setTimeout(() => {
            div.classList.add('msg-right');
            setTimeout(() => div.remove(), 800);
        }, 1500);
    </script>
    <?php unset($_SESSION['error'], $_SESSION['success']); endif; ?>
    <?php if ($allowPasswordReset): ?>
    <script>
        function toggleForm(e) {
            e.preventDefault();
            document.getElementById('login-form').classList.toggle('active');
            document.getElementById('reset-form').classList.toggle('active');
        }
    </script>
    <?php endif; ?>
    <script>
        // 密码显示/隐藏
        document.querySelectorAll('.toggle-password').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = btn.previousElementSibling;
                if (input.type === 'password') {
                    input.type = 'text';
                    btn.querySelector('use').setAttribute('xlink:href', '#icon-eye-close');
                } else {
                    input.type = 'password';
                    btn.querySelector('use').setAttribute('xlink:href', '#icon-eye');
                }
            });
        });
    </script>
    <script src="//at.alicdn.com/t/c/font_4623353_hb4c04qfi4u.js"></script>
</body>
</html>