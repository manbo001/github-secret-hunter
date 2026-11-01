<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// 已登录则跳转（需改密用户直接到改密页）
if (Auth::check()) {
    if (Auth::mustChangePassword()) {
        redirect('change_password.php');
    }
    redirect('dashboard.php');
}

// 部署期用户名同步：.env 配置 ADMIN_USER 时，首次访问自动重命名默认 admin 账号
Auth::syncAdminUsernameFromEnv();

$error = '';
$success = '';

// 处理登录提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 校验
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = '请求无效，请刷新页面重试。';
    } else {
        $username = post_string('username', 32);
        $password = post_raw('password', 128);
        $result = Auth::login($username, $password);
        if ($result['success']) {
            redirect('dashboard.php');
        } else {
            $error = $result['message'];
        }
    }
}

// 账号被禁用提示
if (isset($_GET['disabled'])) {
    $error = '账号已被禁用，请联系管理员。';
}
// 安全退出提示
if (isset($_GET['loggedout'])) {
    $success = '您已安全退出。';
}
$csrf = Security::csrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('sm-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
    <title>登录 - <?= Security::e(site_title()) ?></title>
    <link rel="icon" href="favicon.php?v=<?= site_has_favicon() ? Security::e(substr(md5(Settings::get('site_favicon_data', '')), 0, 10)) : 'none' ?>">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <h1><?= Security::e(site_title()) ?></h1>
        <p class="subtitle"><?= Security::e(site_subtitle() !== '' ? site_subtitle() : 'GitHub · Gitee · GitCode 多平台敏感信息监测') ?></p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= Security::e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= Security::e($success) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required maxlength="32"
                       value="<?= Security::e(post_string('username', 32)) ?>" autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required maxlength="128" autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary btn-block">登 录</button>
        </form>
    </div>
</div>
</body>
</html>
