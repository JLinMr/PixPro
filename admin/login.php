<?php
session_status() === PHP_SESSION_NONE && session_start();
require_once '../includes/bootstrap.php';

$db = Database::getInstance();
$allowPasswordReset = ($_ENV['ALLOW_PASSWORD_RESET'] ?? 'false') === 'true';
$isDemoMode = ($_ENV['DEMO_MODE'] ?? 'false') === 'true';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/http.php';

    $action = $_POST['action'] ?? 'login';
    $pdo = $db->getConnection();
    $success = false;
    $message = '';
    $redirect = null;
    $uri = strtok($_SERVER['REQUEST_URI'], '?');

    $setLoggedIn = static function (array $user): void {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_id'] = $user['id'];
    };

    if ($action === 'login') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$_POST['username'] ?? '']);

        if ($user = $stmt->fetch(PDO::FETCH_ASSOC) and password_verify($_POST['password'] ?? '', $user['password'])) {
            $setLoggedIn($user);
            $success = true;
            $redirect = $uri;
        } else {
            $message = '用户名或密码无效';
        }
    } elseif ($action === 'demo_login' && $isDemoMode) {
        $stmt = $pdo->prepare('SELECT id, username FROM users ORDER BY id LIMIT 1');
        $stmt->execute();
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $setLoggedIn($user);
            $success = true;
            $redirect = $uri;
        } else {
            $message = '演示账号不可用';
        }
    } elseif ($action === 'reset' && $allowPasswordReset) {
        $username = $_POST['username'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!$username || !$newPassword || !$confirmPassword) {
            $message = '请填写所有字段';
        } elseif ($newPassword !== $confirmPassword) {
            $message = '两次输入的密码不一致';
        } elseif (strlen($newPassword) < 6) {
            $message = '密码长度至少为6位';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$username]);

            if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                if ($stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']])) {
                    $success = true;
                    $message = '密码重置成功，请使用新密码登录';
                } else {
                    $message = '重置失败';
                }
            } else {
                $message = '用户名不存在';
            }
        }
    } else {
        $message = '不支持的请求';
    }

    $payload = ['success' => $success, 'message' => $message];
    if ($redirect) $payload['redirect'] = $redirect;
    jsonExit($payload);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录</title>
    <link rel="shortcut icon" href="/static/favicon.svg">
    <link rel="stylesheet" href="/static/css/login.css">
</head>
<body>
    <div class="auth-card auth-card--narrow glass"<?= $allowPasswordReset ? ' data-allow-reset' : '' ?>>
        <div class="form-container active" id="login-form">
            <h2>登录</h2>
            <form method="post">
                <input type="hidden" name="action" value="<?= $isDemoMode ? 'demo_login' : 'login' ?>">
                <div class="form-group">
                    <label for="username">账号：</label>
                    <input type="text" class="glass-input" name="username" <?= $isDemoMode ? 'disabled placeholder="演示模式无需填写"' : 'required' ?>>
                </div>
                <div class="form-group">
                    <label for="password">密码：</label>
                    <div class="password-input-wrapper">
                        <input type="password" class="glass-input" name="password" <?= $isDemoMode ? 'disabled placeholder="演示模式无需填写"' : 'required' ?>>
                        <?php if (!$isDemoMode): ?>
                        <span class="toggle-password">
                            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-eye"></use></svg>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="glass-btn<?= $isDemoMode ? ' btn-demo' : '' ?>">
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
            <?php if (!$isDemoMode): ?>
            <div class="toggle-form">
                <a href="#" data-forgot-password>忘记密码？</a>
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
                    <input type="text" class="glass-input" name="username" required placeholder="请输入要重置密码的用户名">
                </div>
                <div class="form-group">
                    <label for="new_password">新密码：</label>
                    <div class="password-input-wrapper">
                        <input type="password" class="glass-input" name="new_password" required minlength="6" placeholder="至少6位字符">
                        <span class="toggle-password">
                            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-eye"></use></svg>
                        </span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">确认密码：</label>
                    <div class="password-input-wrapper">
                        <input type="password" class="glass-input" name="confirm_password" required minlength="6" placeholder="再次输入新密码">
                        <span class="toggle-password">
                            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-eye"></use></svg>
                        </span>
                    </div>
                </div>
                <button type="submit" class="glass-btn">重置密码</button>
            </form>
            <div class="toggle-form">
                <a href="#" data-toggle-form>返回登录</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <script type="module" src="/static/js/auth/login.js"></script>
    <script src="//at.alicdn.com/t/c/font_4623353_hb4c04qfi4u.js"></script>
</body>
</html>