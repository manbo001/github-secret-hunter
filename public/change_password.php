<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// 完整登录校验（requireLogin 对 change_password.php 不做强制跳转）
Auth::requireLogin();

$userId = Auth::id();
$mustChange = Auth::mustChangePassword();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = '请求无效，请刷新页面重试。';
    } else {
        $oldPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword !== $confirmPassword) {
            $error = '两次输入的新密码不一致。';
        } elseif ($newPassword === $oldPassword) {
            $error = '新密码不能与原密码相同。';
        } else {
            // 首次强制改密时清除 must_change_password 标记
            $result = Auth::changePassword($userId, $oldPassword, $newPassword, $mustChange);
            if ($result['success']) {
                $success = '密码修改成功，正在跳转...';
                // 跳转仪表盘
                header('refresh: 2; url=dashboard.php');
            } else {
                $error = $result['message'];
            }
        }
    }
}

$csrf = Security::csrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('sm-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
    <title>修改密码 - 敏感信息监测聚合平台</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <h1>修改密码</h1>
        <?php if ($mustChange): ?>
            <p class="subtitle" style="color:#b91c1c;">这是您首次登录，系统要求必须修改默认密码后才能继续使用。</p>
        <?php else: ?>
            <p class="subtitle">请设置您的新密码</p>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= Security::e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= Security::e($success) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
            <div class="form-group">
                <label for="old_password">当前密码</label>
                <input type="password" id="old_password" name="old_password" required maxlength="128" autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="new_password">新密码</label>
                <input type="password" id="new_password" name="new_password" required maxlength="128" autocomplete="new-password">
                <small style="color:var(--text-2);font-size:12px;">至少 8 位，需包含大小写字母和数字</small>
            </div>
            <div class="form-group">
                <label for="confirm_password">确认新密码</label>
                <input type="password" id="confirm_password" name="confirm_password" required maxlength="128" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary btn-block">确认修改</button>
            <?php if (!$mustChange): ?>
                <div style="text-align:center;margin-top:14px;">
                    <a href="dashboard.php">返回仪表盘</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>
</body>
</html>
