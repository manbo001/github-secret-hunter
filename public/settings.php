<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$pageTitle = '系统设置';
$activePage = 'settings';

$message = '';
$msgType = 'success';

// 会话不活动超时可选项（分钟 => 标签；0 = 不自动登出）
$sessionOptions = [
    30   => '30 分钟',
    60   => '1 小时',
    120  => '2 小时',
    240  => '4 小时',
    480  => '8 小时',
    1440 => '24 小时',
    0    => '不自动登出（不建议）',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'CSRF 校验失败。';
        $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_username') {
            // 修改用户名（需原密码确认；限流防暴力猜测原密码）
            if (!Security::rateLimit('rename_' . $userId, 5, 300)) {
                $message = '操作过于频繁，请 5 分钟后再试。';
                $msgType = 'error';
            } else {
                $newName = post_string('new_username', 32);
                $confirmPwd = $_POST['confirm_password'] ?? '';
                if (!is_string($confirmPwd)) {
                    $confirmPwd = '';
                }
                $res = Auth::renameUser($userId, $newName, $confirmPwd);
                $message = $res['message'];
                $msgType = $res['success'] ? 'success' : 'error';
            }
        } elseif ($action === 'save_site_identity') {
            // 站点外观：主标题必填（空则回退默认），副标题可留空
            $st = post_string('site_title', 50);
            $ss = post_string('site_subtitle', 100);
            // 多字节安全截断（浏览器标签页/侧边栏展示长度限制）
            $st = mb_substr($st, 0, 50, 'UTF-8');
            $ss = mb_substr($ss, 0, 100, 'UTF-8');
            if ($st === '') {
                $message = '站点主标题不能为空。';
                $msgType = 'error';
            } else {
                Settings::set('site_title', $st);
                Settings::set('site_subtitle', $ss);
                $message = '站点标题已更新：侧边栏与浏览器标签页将同步显示新标题。';
                $msgType = 'success';
                audit_log('site_identity_updated', ['title' => $st, 'subtitle' => $ss], $userId);
            }
        } elseif ($action === 'upload_favicon') {
            // 网站图标上传：≤512KB，MIME 白名单（不支持 SVG：可作为顶层文档执行脚本）
            $allowedFaviconMime = [
                'image/png', 'image/jpeg', 'image/x-icon',
                'image/vnd.microsoft.icon', 'image/webp',
            ];
            $file = $_FILES['favicon'] ?? null;
            if (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $message = '请选择要上传的图标文件。';
                $msgType = 'error';
            } elseif ((int)$file['size'] > 512 * 1024) {
                $message = '图标文件过大（最大 512KB）。';
                $msgType = 'error';
            } else {
                $content = (string)file_get_contents((string)$file['tmp_name']);
                $mime = '';
                if (function_exists('finfo_open')) {
                    $fi = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = (string)finfo_buffer($fi, $content);
                    finfo_close($fi);
                }
                if ($mime === '' || !in_array($mime, $allowedFaviconMime, true)) {
                    $message = '不支持的图标格式，仅允许 PNG / JPG / ICO / WebP。';
                    $msgType = 'error';
                } else {
                    Settings::set('site_favicon_mime', $mime);
                    Settings::set('site_favicon_data', base64_encode($content));
                    $message = '网站图标已更新，浏览器标签页将显示新图标（如未变化可强制刷新页面）。';
                    $msgType = 'success';
                    audit_log('site_favicon_uploaded', ['mime' => $mime, 'size' => strlen($content)], $userId);
                }
            }
        } elseif ($action === 'delete_favicon') {
            Settings::set('site_favicon_mime', '');
            Settings::set('site_favicon_data', '');
            $message = '网站图标已删除，浏览器标签页将恢复默认图标。';
            $msgType = 'success';
            audit_log('site_favicon_deleted', [], $userId);
        } elseif ($action === 'save_retention') {
            $days = (int)($_POST['retention_days'] ?? 90);
            $days = max(0, min(3650, $days));
            Cleaner::setRetentionDays($days);
            if ($days === 0) {
                $message = '已设置为永久保留，自动清理已关闭。';
            } else {
                $message = "已设置保留期 {$days} 天，自动清理已启用并即时生效。";
            }
            audit_log('retention_updated', ['days' => $days], $userId);
        } elseif ($action === 'clean_now') {
            $deleted = Cleaner::cleanExpired(true);
            $message = "清理完成，已删除 {$deleted} 个过期任务及关联结果。";
            audit_log('data_cleanup', ['deleted_tasks' => $deleted], $userId);
        } elseif ($action === 'save_text_match') {
            // GitHub 命中片段开关（写入 app_settings，优先级高于 .env）
            $tm = (($_POST['text_match'] ?? '0') === '1') ? '1' : '0';
            Settings::set('github_text_match', $tm);
            $message = $tm === '1'
                ? '已开启命中片段（text-match）：新抓取的结果将包含代码上下文，响应体较大，弱网时传输易中断。'
                : '已关闭命中片段（text-match）：响应体大幅缩小，弱网下传输成功率更高；新结果将不带代码片段。';
            $msgType = 'success';
            audit_log('text_match_updated', ['enabled' => $tm], $userId);
        } elseif ($action === 'save_session') {
            // 会话不活动超时（写入 app_settings，优先级高于 .env；0 = 不自动登出）
            $allowedSession = array_keys($sessionOptions);
            $mins = (int)($_POST['session_timeout_minutes'] ?? 480);
            if (!in_array($mins, $allowedSession, true)) {
                $mins = 480;
            }
            Settings::set('session_timeout_minutes', (string)$mins);
            $message = $mins === 0
                ? '已设置为不自动登出（不建议在公网环境使用）。'
                : '会话不活动超时已设置为 ' . $sessionOptions[$mins] . '，超过该时间无操作将需要重新登录。';
            $msgType = 'success';
            audit_log('session_timeout_updated', ['minutes' => $mins], $userId);
        } elseif ($action === 'save_proxy' || $action === 'test_proxy') {
            // GitHub 代理设置（JSON 存 app_settings，密码 AES 加密，仅作用于 GitHub API）
            $ptype = $_POST['proxy_type'] ?? 'none';
            if (!Validator::enum($ptype, ['none', 'http', 'https', 'socks5'])) {
                $ptype = 'none';
            }
            $phost = trim((string)Validator::string($_POST['proxy_host'] ?? '', 200));
            $pport = (int)($_POST['proxy_port'] ?? 0);
            $puser = trim((string)Validator::string($_POST['proxy_user'] ?? '', 100));
            $ppass = post_raw('proxy_pass', 128);

            if ($ptype !== 'none') {
                if ($phost === '') {
                    $message = '代理服务器地址不能为空。';
                    $msgType = 'error';
                } elseif ($pport < 1 || $pport > 65535) {
                    $message = '代理端口不合法（1-65535）。';
                    $msgType = 'error';
                }
            }

            if ($msgType !== 'error') {
                // 密码留空 = 保留原有密码
                $old = json_decode(Settings::get('github_proxy', ''), true) ?: [];
                $passEnc = $old['pass_enc'] ?? '';
                if ($ppass !== '') {
                    try {
                        $passEnc = Crypto::encrypt($ppass);
                    } catch (Throwable $e) {
                        $passEnc = '';
                    }
                }
                if ($ptype === 'none') {
                    Settings::set('github_proxy', '');
                    $message = '已关闭 GitHub 代理，API 将直连。';
                } else {
                    $cfg = json_encode([
                        'type'     => $ptype,
                        'host'     => $phost,
                        'port'     => $pport,
                        'user'     => $puser,
                        'pass_enc' => $passEnc,
                    ], JSON_UNESCAPED_UNICODE);
                    Settings::set('github_proxy', (string)$cfg);
                    $message = 'GitHub 代理设置已保存（' . strtoupper($ptype) . ' ' . $phost . ':' . $pport . '），对新发起的 API 请求立即生效。';
                }
                $msgType = 'success';
                audit_log('github_proxy_updated', ['type' => $ptype, 'host' => $phost, 'port' => $pport], $userId);

                // 测试连接：保存当前配置后立即自检
                if ($action === 'test_proxy' && $ptype !== 'none') {
                    $test = (new GitHubClient(''))->testConnection();
                    $message .= ' 测试结果：' . ($test['ok'] ? '通过 — ' : '失败 — ') . $test['message'];
                    if (!$test['ok']) {
                        $msgType = 'error';
                    }
                }
            }
        }
    }
}

$stats = Cleaner::getStats();
$csrf = Security::csrfToken();
// 当前登录用户名（管理员账号卡片展示）
$currentUser = Auth::user();
$currentUsername = (string)($currentUser['username'] ?? '');

// GitHub 命中片段当前生效值：后台设置优先，未设置时取 .env 默认
$dbTextMatch = Settings::get('github_text_match', '');
$textMatchEffective = $dbTextMatch !== '' ? ($dbTextMatch === '1') : (bool)($GLOBALS['config']['github']['text_match'] ?? true);
$textMatchSource = $dbTextMatch !== '' ? '后台设置' : '.env 默认值';

// GitHub 代理当前配置（密码只回显是否已设置）
$proxyCfg = json_decode(Settings::get('github_proxy', ''), true) ?: [];
$proxyType = in_array($proxyCfg['type'] ?? '', ['none', 'http', 'https', 'socks5'], true) ? $proxyCfg['type'] : 'none';
$proxyHost = (string)($proxyCfg['host'] ?? '');
$proxyPort = (string)($proxyCfg['port'] ?? '');
$proxyUser = (string)($proxyCfg['user'] ?? '');
$proxyHasPass = !empty($proxyCfg['pass_enc']);

// 会话不活动超时当前生效值：后台设置优先，未设置时取 .env 默认
$dbSession = Settings::get('session_timeout_minutes', '');
$sessionEffective = $dbSession !== '' ? (int)$dbSession : (int)(($GLOBALS['config']['security']['session_lifetime'] ?? 28800) / 60);
$sessionSource = $dbSession !== '' ? '后台设置' : '.env 默认值';

// 站点外观当前值（空字符串 = 使用默认标题）
$siteTitleVal = Settings::get('site_title', '');
$siteSubtitleVal = Settings::get('site_subtitle', '');
$hasFavicon = site_has_favicon();
include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= Security::e($message) ?></div>
<?php endif; ?>

<div class="card">
    <h3>站点外观</h3>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
        <input type="hidden" name="action" value="save_site_identity">
        <div class="form-row">
            <div class="form-group">
                <label>主标题（必填）</label>
                <input type="text" name="site_title" maxlength="50" required
                       value="<?= Security::e($siteTitleVal !== '' ? $siteTitleVal : APP_NAME) ?>"
                       placeholder="<?= Security::e(APP_NAME) ?>">
            </div>
            <div class="form-group">
                <label>副标题（可留空）</label>
                <input type="text" name="site_subtitle" maxlength="100"
                       value="<?= Security::e($siteSubtitleVal) ?>" placeholder="例如：多平台代码资产监测">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">保存标题</button>
    </form>

    <hr style="border:none;border-top:1px solid var(--border);margin:20px 0;">
    <p style="color:var(--text-2);font-size:14px;margin-bottom:16px;">
        网站图标显示在浏览器标签页上，支持 PNG / JPG / ICO / WebP，最大 512KB。
        上传或删除后刷新页面即可生效；若标签页仍短暂显示旧图标，属浏览器缓存，关闭该标签页后重新打开即可。
    </p>
    <?php if ($hasFavicon): ?>
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
            <img src="favicon.php?_t=<?= substr(md5((string)Settings::get('site_favicon_data', '')), 0, 8) ?>"
                 alt="当前图标" width="32" height="32"
                 style="border-radius:6px;border:1px solid var(--border);background:var(--surface-2);">
            <span style="color:var(--text-2);font-size:13px;">当前图标（<?= Security::e(Settings::get('site_favicon_mime', '')) ?>）</span>
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
        <input type="hidden" name="action" value="upload_favicon">
        <input type="file" name="favicon" accept=".png,.jpg,.jpeg,.ico,.webp,image/png,image/jpeg,image/x-icon,image/webp" required>
        <button type="submit" class="btn btn-primary"><?= $hasFavicon ? '更换图标' : '上传图标' ?></button>
    </form>
    <?php if ($hasFavicon): ?>
        <form method="post" style="margin-top:12px;">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
            <input type="hidden" name="action" value="delete_favicon">
            <button type="submit" class="btn btn-danger" onclick="return confirm('确认删除网站图标？');">删除图标</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3>管理员账号</h3>
    <p style="color:var(--text-2);font-size:14px;margin-bottom:16px;">
        修改当前登录账号的用户名（当前：<strong><?= Security::e($currentUsername) ?></strong>）。
        需输入原密码确认；修改后请使用新用户名重新登录，浏览器标签页与侧边栏显示不受影响。
    </p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
        <input type="hidden" name="action" value="save_username">
        <div class="form-row">
            <div class="form-group">
                <label>新用户名（3-32 位字母、数字或下划线）</label>
                <input type="text" name="new_username" maxlength="32" required
                       value="<?= Security::e($currentUsername) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label>原密码（确认身份）</label>
                <input type="password" name="confirm_password" maxlength="128" required autocomplete="current-password">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">修改用户名</button>
    </form>
</div>

<div class="card">
    <h3>数据保留期设置</h3>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
        <input type="hidden" name="action" value="save_retention">
        <div class="form-group">
            <label>数据保留天数</label>
            <select name="retention_days">
                <?php foreach ([0 => '永久保留（关闭自动清理）', 7 => '7 天', 30 => '30 天', 90 => '90 天', 180 => '180 天', 365 => '1 年', 730 => '2 年'] as $days => $label): ?>
                    <option value="<?= $days ?>" <?= $stats['retention_days'] === $days ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">保存并立即生效</button>
    </form>
</div>

<div class="card">
    <h3>GitHub 搜索配置</h3>
    <p style="color:var(--text-2);font-size:14px;margin-bottom:16px;">
        命中片段（text-match）让搜索结果附带代码上下文片段，但会使单页响应体放大数倍。
        若任务频繁报「transfer closed / Operation timed out」等传输错误，请关闭此开关（保存后立即生效，无需重启）。
    </p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
        <input type="hidden" name="action" value="save_text_match">
        <div class="form-group">
            <label>命中片段（代码上下文）</label>
            <select name="text_match">
                <option value="1" <?= $textMatchEffective ? 'selected' : '' ?>>开启（结果带代码片段，响应体大）</option>
                <option value="0" <?= !$textMatchEffective ? 'selected' : '' ?>>关闭（弱网更稳定，结果不带片段）</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">保存</button>
        <small style="color:var(--text-3);font-size:12px;margin-left:12px;">当前生效值来自：<?= Security::e($textMatchSource) ?></small>
    </form>
</div>

<div class="card">
    <h3>GitHub 代理设置</h3>
    <p style="color:var(--text-2);font-size:14px;margin-bottom:16px;">
        仅对 GitHub API 生效（搜索 / 复查 / 仓库监控）；Gitee 与 GitCode 始终直连。
        代理认证为可选项，留空密码则保留原有密码。保存后对新发起的请求立即生效。
    </p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
        <div class="form-row">
            <div class="form-group">
                <label>代理类型</label>
                <select name="proxy_type">
                    <option value="none" <?= $proxyType === 'none' ? 'selected' : '' ?>>不使用代理（直连）</option>
                    <option value="http" <?= $proxyType === 'http' ? 'selected' : '' ?>>HTTP</option>
                    <option value="https" <?= $proxyType === 'https' ? 'selected' : '' ?>>HTTPS</option>
                    <option value="socks5" <?= $proxyType === 'socks5' ? 'selected' : '' ?>>SOCKS5</option>
                </select>
            </div>
            <div class="form-group">
                <label>代理服务器</label>
                <input type="text" name="proxy_host" maxlength="200" value="<?= Security::e($proxyHost) ?>" placeholder="例如 192.168.2.1">
            </div>
            <div class="form-group">
                <label>端口</label>
                <input type="number" name="proxy_port" min="1" max="65535" value="<?= Security::e($proxyPort) ?>" placeholder="例如 7890">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>用户名（可选）</label>
                <input type="text" name="proxy_user" maxlength="100" value="<?= Security::e($proxyUser) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label>密码（可选<?= $proxyHasPass ? '，已设置' : '' ?>）</label>
                <input type="password" name="proxy_pass" maxlength="128" autocomplete="new-password" placeholder="<?= $proxyHasPass ? '留空保持不变' : '' ?>">
            </div>
        </div>
        <button type="submit" name="action" value="save_proxy" class="btn btn-primary">保存设置</button>
        <button type="submit" name="action" value="test_proxy" class="btn btn-secondary">测试连接</button>
    </form>
</div>

<div class="card">
    <h3>会话安全</h3>
    <p style="color:var(--text-2);font-size:14px;margin-bottom:16px;">
        登录后若连续无操作超过设定时长，将自动登出并要求重新登录。保存后立即生效。
    </p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
        <input type="hidden" name="action" value="save_session">
        <div class="form-group">
            <label>无操作自动登出时长</label>
            <select name="session_timeout_minutes">
                <?php foreach ($sessionOptions as $min => $label): ?>
                    <option value="<?= $min ?>" <?= $sessionEffective === $min ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">保存</button>
        <small style="color:var(--text-3);font-size:12px;margin-left:12px;">当前生效值来自：<?= Security::e($sessionSource) ?></small>
    </form>
</div>

<div class="card">
    <h3>自动清理状态</h3>
    <table>
        <tr><td>自动清理</td><td><span class="badge <?= $stats['retention_days'] > 0 ? 'badge-success' : 'badge-secondary' ?>"><?= Security::e($stats['auto_cleanup']) ?></span></td></tr>
        <?php if ($stats['retention_days'] > 0): ?>
            <tr><td>保留期</td><td><?= (int)$stats['retention_days'] ?> 天</td></tr>
        <?php endif; ?>
        <tr><td>上次清理时间</td><td><?= Security::e($stats['last_cleanup_at']) ?></td></tr>
        <tr><td>上次清理结果</td><td><?= Security::e($stats['last_cleanup_result']) ?></td></tr>
        <tr><td>下次自动清理</td><td><?= $stats['retention_days'] > 0 ? '24 小时内（下次请求时触发）' : '—' ?></td></tr>
    </table>

    <?php if ($stats['expired_tasks'] > 0): ?>
        <form method="post" style="margin-top:16px;" onsubmit="return confirm('确认立即清理所有过期数据？此操作不可恢复。');">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
            <input type="hidden" name="action" value="clean_now">
            <button type="submit" class="btn btn-danger">立即清理过期数据（<?= (int)$stats['expired_tasks'] ?> 个任务）</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3>数据统计</h3>
    <table>
        <tr><td>搜索任务总数</td><td><?= (int)$stats['total_tasks'] ?> 个</td></tr>
        <tr><td>搜索结果总数</td><td><?= (int)$stats['total_results'] ?> 条</td></tr>
        <tr><td>已过期待清理任务</td><td><?= (int)$stats['expired_tasks'] ?> 个</td></tr>
    </table>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
