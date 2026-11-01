<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$pageTitle = '推送通知';
$activePage = 'notifications';

$message = '';
$msgType = 'error';

$channelTypes = [
    'dingtalk' => '钉钉',
    'wechat'   => '企业微信',
    'feishu'   => '飞书',
    'email'    => '邮件',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'CSRF 校验失败。';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $type = $_POST['channel_type'] ?? '';
            $name = Validator::string($_POST['name'] ?? '', 64);
            if (!Validator::enum($type, array_keys($channelTypes))) {
                $message = '渠道类型不合法。';
            } elseif (!$name) {
                $message = '请输入渠道名称。';
            } else {
                // 按渠道类型收集配置
                $config = [];
                if (in_array($type, ['dingtalk', 'wechat', 'feishu'], true)) {
                    $webhook = post_string('webhook');
                    $secret = post_string('secret');
                    if (!Validator::url($webhook)) {
                        $message = 'Webhook URL 格式不合法。';
                    } elseif ($secret !== '' && strlen($secret) > 200) {
                        $message = '签名密钥过长。';
                    } else {
                        $config = ['webhook' => $webhook];
                        if ($secret !== '') {
                            $config['secret'] = $secret;
                        }
                    }
                } elseif ($type === 'email') {
                    // 清除嵌入的 CR/LF，防止 SMTP 头注入
                    $stripCrlf = fn($v) => str_replace(["\r", "\n"], '', (string)$v);
                    $config = [
                        'smtp_host' => $stripCrlf(Validator::string($_POST['smtp_host'] ?? '', 128) ?? ''),
                        'smtp_port' => (int)($_POST['smtp_port'] ?? 587),
                        'smtp_secure' => $_POST['smtp_secure'] ?? 'tls',
                        'smtp_user' => $stripCrlf(Validator::string($_POST['smtp_user'] ?? '', 128) ?? ''),
                        'smtp_pass' => post_raw('smtp_pass', 128),
                        'from_email' => $stripCrlf(Validator::string($_POST['from_email'] ?? '', 128) ?? ''),
                        'to_email' => $stripCrlf(Validator::string($_POST['to_email'] ?? '', 128) ?? ''),
                    ];
                    if (!$config['smtp_host'] || !$config['to_email']) {
                        $message = 'SMTP 主机和收件邮箱必填。';
                    } elseif (!filter_var($config['to_email'], FILTER_VALIDATE_EMAIL)) {
                        $message = '收件邮箱格式不合法。';
                    } elseif ($config['from_email'] !== '' && !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
                        $message = '发件邮箱格式不合法。';
                    } elseif ($config['smtp_port'] < 1 || $config['smtp_port'] > 65535) {
                        $message = 'SMTP 端口不合法（1-65535）。';
                    } elseif (!Validator::enum($config['smtp_secure'], ['tls', 'ssl', 'none'])) {
                        $message = '加密方式不合法。';
                    }
                }

                if (!$message) {
                    // 收集自定义模板（留空则使用默认模板）；搜索告警与仓库监控为两套独立模板
                    $template = post_string('template');
                    $templateRepo = post_string('template_repo');
                    if (strlen($template) > 2000 || strlen($templateRepo) > 2000) {
                        $message = '模板内容过长（最多 2000 字符）。';
                    } else {
                        if ($template !== '') {
                            $config['template'] = $template;
                        }
                        if ($templateRepo !== '') {
                            $config['template_repo'] = $templateRepo;
                        }
                        try {
                            $json = json_encode($config, JSON_UNESCAPED_UNICODE);
                            if ($json === false) {
                                throw new RuntimeException('配置序列化失败');
                            }
                            $encrypted = Crypto::encrypt($json);
                            db()->prepare('INSERT INTO notification_channels (user_id, channel_type, name, config_encrypted, enabled) VALUES (?, ?, ?, ?, 1)')
                                ->execute([$userId, $type, $name, $encrypted]);
                            audit_log('channel_created', ['type' => $type, 'name' => $name], $userId);
                            $message = '推送通知添加成功。';
                            $msgType = 'success';
                        } catch (Throwable $e) {
                            // 调试模式下显示具体错误，生产环境显示通用提示
                            $detail = ($GLOBALS['config']['app']['debug'] ?? false) ? '（' . $e->getMessage() . '）' : '';
                            $message = '配置加密存储失败' . $detail . '。请检查 APP_ENCRYPTION_KEY 是否正确配置。';
                        }
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('DELETE FROM notification_channels WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            audit_log('channel_deleted', ['id' => $id], $userId);
            $message = '渠道已删除。';
            $msgType = 'success';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('UPDATE notification_channels SET enabled = 1 - enabled WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            $message = '状态已切换。';
            $msgType = 'success';
        } elseif ($action === 'test') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM notification_channels WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);
            $ch = $stmt->fetch();
            if ($ch) {
                try {
                    $config = json_decode(Crypto::decrypt($ch['config_encrypted']), true);
                    if (!is_array($config)) {
                        throw new RuntimeException('配置解密失败');
                    }
                } catch (Throwable $e) {
                    $message = '渠道配置解密失败，请删除后重新创建。';
                    $msgType = 'error';
                    $config = null;
                }
                if ($config !== null) {
                    // 用当前用户真实最近的搜索命中构造测试消息（无结果时回退到样例数据）
                    $rstmt = db()->prepare(
                        'SELECT sr.repo_full_name, sr.file_path, st.query
                         FROM search_results sr
                         JOIN search_tasks st ON sr.task_id = st.id
                         WHERE st.user_id = ?
                         ORDER BY sr.found_at DESC, sr.id DESC
                         LIMIT 5'
                    );
                    $rstmt->execute([$userId]);
                    $recent = $rstmt->fetchAll();
                    if (!empty($recent)) {
                        $sampleContent = '';
                        foreach ($recent as $i => $r) {
                            $sampleContent .= ($i + 1) . '. ' . $r['repo_full_name'] . ':' . $r['file_path'] . "\n";
                        }
                        $sampleQuery = $recent[0]['query'];
                        $r2 = db()->prepare('SELECT COUNT(*) FROM search_results sr JOIN search_tasks st ON sr.task_id = st.id WHERE st.user_id = ?');
                        $r2->execute([$userId]);
                        $sampleCount = (int)$r2->fetchColumn();
                    } else {
                        $sampleContent = "1. example/repo:config/.env\n2. example/repo2:src/keys.pem\n";
                        $sampleQuery = 'AKIA password';
                        $sampleCount = 2;
                    }
                    $result = Notifier::send(
                        $ch['channel_type'],
                        $config,
                        'GitHub 敏感信息告警',
                        $sampleContent,
                        ['query' => $sampleQuery, 'count' => $sampleCount, 'time' => date('Y-m-d H:i:s')]
                    );
                    db()->prepare('INSERT INTO notification_logs (channel_id, status, response) VALUES (?, ?, ?)')
                        ->execute([$id, $result['success'] ? 'success' : 'failed', $result['response']]);
                    $message = $result['success'] ? '测试消息发送成功！' : '测试消息发送失败：' . $result['response'];
                    $msgType = $result['success'] ? 'success' : 'error';
                }
            }
        } elseif ($action === 'test_repo') {
            // 仓库监控模板测试：模拟一条新提交，验证 template_repo 渲染与推送链路
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM notification_channels WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);
            $ch = $stmt->fetch();
            if ($ch) {
                try {
                    $config = json_decode(Crypto::decrypt($ch['config_encrypted']), true);
                    if (!is_array($config)) {
                        throw new RuntimeException('配置解密失败');
                    }
                } catch (Throwable $e) {
                    $message = '渠道配置解密失败，请删除后重新创建。';
                    $msgType = 'error';
                    $config = null;
                }
                if ($config !== null) {
                    $sampleContent = "**最新提交：** `a1b2c3d4e5f6`\n**作者：** octocat\n**说明：** Update config parser";
                    $sampleRepo = 'octocat/Hello-World';
                    $baseUrl = (string)($GLOBALS['config']['app']['base_url'] ?? '');
                    $link = $baseUrl !== '' ? "\n---\n[查看监控详情]({$baseUrl}/repo_monitor.php)" : '';
                    $result = Notifier::send(
                        $ch['channel_type'],
                        $config,
                        '仓库更新提醒',
                        $sampleContent,
                        ['query' => $sampleRepo, 'count' => 1, 'time' => date('Y-m-d H:i:s'), 'link' => $link],
                        'repo'
                    );
                    db()->prepare('INSERT INTO notification_logs (channel_id, status, response) VALUES (?, ?, ?)')
                        ->execute([$id, $result['success'] ? 'success' : 'failed', $result['response']]);
                    $message = $result['success'] ? '仓库监控测试发送成功！（使用仓库监控模板）' : '仓库监控测试发送失败：' . $result['response'];
                    $msgType = $result['success'] ? 'success' : 'error';
                }
            }
        }
    }
}

$channels = db()->prepare('SELECT id, channel_type, name, enabled, created_at FROM notification_channels WHERE user_id = ? ORDER BY created_at DESC');
$channels->execute([$userId]);
$channels = $channels->fetchAll();

$csrf = Security::csrfToken();
include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= Security::e($message) ?></div>
<?php endif; ?>

<div class="card">
    <h3>添加推送通知</h3>
    <form method="post" id="channelForm">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <div class="form-row">
            <div class="form-group">
                <label>渠道类型</label>
                <select name="channel_type" id="channelType" required>
                    <?php foreach ($channelTypes as $k => $v): ?>
                        <option value="<?= Security::e($k) ?>"><?= Security::e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>渠道名称</label>
                <input type="text" name="name" required maxlength="64" placeholder="如：运维告警群">
            </div>
        </div>

        <!-- Webhook 类渠道 -->
        <div id="webhookFields">
            <div class="form-group">
                <label>Webhook URL</label>
                <input type="text" name="webhook" maxlength="512" placeholder="https://oapi.dingtalk.com/robot/send?access_token=xxx">
            </div>
            <div class="form-group">
                <label>签名密钥（可选）</label>
                <input type="text" name="secret" maxlength="200" placeholder="钉钉/飞书加签密钥">
            </div>
        </div>

        <!-- 邮件渠道 -->
        <div id="emailFields" style="display:none">
            <div class="form-row">
                <div class="form-group">
                    <label>SMTP 主机</label>
                    <input type="text" name="smtp_host" maxlength="128" placeholder="smtp.qq.com">
                </div>
                <div class="form-group">
                    <label>SMTP 端口</label>
                    <input type="number" name="smtp_port" value="587">
                </div>
                <div class="form-group">
                    <label>加密方式</label>
                    <select name="smtp_secure">
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">无</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>SMTP 用户名</label>
                    <input type="text" name="smtp_user" maxlength="128">
                </div>
                <div class="form-group">
                    <label>SMTP 密码</label>
                    <input type="password" name="smtp_pass" maxlength="128">
                </div>
                <div class="form-group">
                    <label>发件邮箱</label>
                    <input type="email" name="from_email" maxlength="128">
                </div>
                <div class="form-group">
                    <label>收件邮箱</label>
                    <input type="email" name="to_email" maxlength="128">
                </div>
            </div>
        </div>

        <!-- 自定义消息模板：搜索告警与仓库监控两套独立模板 -->
        <div class="form-group">
            <label>搜索告警模板（可选，搜索发现新增命中时使用；留空使用默认模板）</label>
            <textarea name="template" rows="6" maxlength="2000" placeholder="支持占位符: {title} {content} {query} {count} {time}
示例:
### {title}
命中 {count} 条 | 关键词: {query}
{content}"></textarea>
        </div>
        <div class="form-group">
            <label>仓库监控模板（可选，监控的仓库有新提交时使用；留空则回退：搜索告警模板 → 仓库默认模板）</label>
            <textarea name="template_repo" rows="6" maxlength="2000" placeholder="支持占位符: {title} {content} {query} {count} {time}
示例:
### {title}
仓库 {query} 有更新
{content}"></textarea>
            <small style="color:var(--text-2);font-size:12px;">
                占位符说明：{title}=标题，{content}=内容明细，{query}=仓库名（仓库模板）/ 搜索词（搜索模板），{count}=数量，{time}=时间，{link}=详情页链接（需在 .env 配置 APP_URL）<br>
                钉钉支持 Markdown 语法（**加粗**、[链接](url)、&gt; 引用）；测试推送使用真实数据作为内容。
            </small>
        </div>

        <button type="submit" class="btn btn-primary">添加渠道</button>
    </form>
</div>

<div class="card">
    <h3>已配置渠道</h3>
    <?php if (empty($channels)): ?>
        <p style="color:var(--text-2);font-size:14px;">暂无推送通知</p>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>名称</th><th>类型</th><th>状态</th><th>创建时间</th><th>操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($channels as $c): ?>
                <tr>
                    <td><?= Security::e($c['name']) ?></td>
                    <td><?= Security::e($channelTypes[$c['channel_type']] ?? $c['channel_type']) ?></td>
                    <td>
                        <span class="badge <?= $c['enabled'] ? 'badge-success' : 'badge-danger' ?>">
                            <?= $c['enabled'] ? '启用' : '禁用' ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;"><?= Security::e($c['created_at']) ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                            <input type="hidden" name="action" value="test">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn btn-success">发送测试</button>
                        </form>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                            <input type="hidden" name="action" value="test_repo">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn btn-success">仓库测试</button>
                        </form>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn btn-secondary"><?= $c['enabled'] ? '禁用' : '启用' ?></button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('确认删除？');">
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn btn-danger">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('channelType').addEventListener('change', function() {
    const isEmail = this.value === 'email';
    document.getElementById('webhookFields').style.display = isEmail ? 'none' : 'block';
    document.getElementById('emailFields').style.display = isEmail ? 'block' : 'none';
});
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
