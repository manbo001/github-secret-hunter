<?php
/**
 * 页面布局 - 顶部导航 + 侧边栏
 * 需传入 $pageTitle 和 $activePage
 */
if (!isset($activePage)) { $activePage = ''; }
if (!isset($pageTitle)) { $pageTitle = APP_NAME; }
$siteTitle = site_title();
$siteSubtitle = site_subtitle();
// 图标 URL 版本号：上传/删除后 URL 变化，强制浏览器刷新标签页图标（浏览器按 URL 缓存 favicon）
$faviconVer = site_has_favicon()
    ? substr(md5(Settings::get('site_favicon_data', '')), 0, 10)
    : 'none';
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('sm-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
    <title><?= Security::e($pageTitle) ?> - <?= Security::e($siteTitle) ?></title>
    <link rel="icon" href="favicon.php?v=<?= Security::e($faviconVer) ?>">
    <link rel="stylesheet" href="assets/css/style.css?v=20261112">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="logo"><?= Security::e($siteTitle) ?></div>
        <?php if ($siteSubtitle !== ''): ?>
        <div class="logo-sub"><?= Security::e($siteSubtitle) ?></div>
        <?php else: ?>
        <!-- 副标题留空时用占位符保持头部高度与分隔线，导航位置与有副标题时完全一致 -->
        <div class="logo-sub">&nbsp;</div>
        <?php endif; ?>
        <nav>
            <a href="dashboard.php" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>">总览</a>
            <a href="search.php" class="<?= $activePage === 'search' ? 'active' : '' ?>">任务下发</a>
            <a href="tasks.php" class="<?= $activePage === 'tasks' ? 'active' : '' ?>">任务管理</a>
            <a href="results.php" class="<?= $activePage === 'results' ? 'active' : '' ?>">任务结果</a>
            <a href="repo_monitor.php" class="<?= $activePage === 'repo_monitor' ? 'active' : '' ?>">仓库监控</a>
            <a href="tokens.php" class="<?= $activePage === 'tokens' ? 'active' : '' ?>">令牌设置</a>
            <a href="notifications.php" class="<?= $activePage === 'notifications' ? 'active' : '' ?>">推送通知</a>
            <a href="settings.php" class="<?= $activePage === 'settings' ? 'active' : '' ?>">系统设置</a>
        </nav>
    </aside>
    <div class="main">
        <div class="topbar">
            <h2><?= Security::e($pageTitle) ?></h2>
            <div class="user-info">
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="切换亮暗主题" title="切换亮暗主题">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.3 11.3 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
                <span><?= Security::e($user['username'] ?? '') ?> (<?= Security::e($user['role'] ?? '') ?>)</span>
                <a href="change_password.php">修改密码</a>
                <a href="logout.php?t=<?= Security::e(Security::csrfToken()) ?>">退出</a>
            </div>
        </div>
