<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$pageTitle = '令牌设置';
$activePage = 'tokens';

$message = '';
$msgType = 'error';

// 处理新增 Token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'CSRF 校验失败。';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $label = Validator::string($_POST['label'] ?? '', 64);
            $token = post_string('token', 300);
            $platform = $_POST['platform'] ?? 'github';
            $validPlatforms = array_keys(PlatformSearch::PLATFORMS);
            if (!in_array($platform, $validPlatforms, true)) {
                $platform = 'github';
            }
            // 平台感知的 Token 格式校验：github 用专用规则；gitee/gitcode 为 20-64 位字母数字/连字符
            if ($platform === 'github') {
                $tokenOk = Validator::githubToken($token);
            } else {
                $tokenOk = (bool)preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token);
            }
            if (!$label) {
                $message = '请输入 Token 备注名。';
            } elseif (!$tokenOk) {
                $message = 'Token 格式不正确。';
            } else {
                try {
                    $encrypted = Crypto::encrypt($token);
                    $hint = substr($token, -4);
                    db()->prepare('INSERT INTO github_tokens (user_id, platform, label, token_encrypted, token_hint) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$userId, $platform, $label, $encrypted, $hint]);
                    $newTokenId = (int)db()->lastInsertId();
                    audit_log('token_created', ['label' => $label, 'platform' => $platform], $userId);
                    $message = 'Token 添加成功。';
                    $msgType = 'success';
                } catch (Throwable $e) {
                    $message = 'Token 加密存储失败。';
                    $msgType = 'error';
                }
                if ($msgType === 'success') {
                    // 新令牌到位：自动恢复因令牌问题失败的搜索任务（仅未暂停的）。
                    // gitee/gitcode 的任务绑定具体令牌 ID，原令牌已删时必须同时换绑到新令牌，否则复活后立刻再失败。
                    // 错误匹配用精确短语，避免把 404 等其他失败误复活。
                    try {
                        $req = db()->prepare(
                            "UPDATE search_tasks
                             SET status = 'pending', next_run_at = NOW(), retries = 0, error_msg = NULL, token_id = ?
                             WHERE user_id = ? AND platform = ? AND status = 'failed' AND paused = 0
                               AND (error_msg LIKE '%令牌已被删除%'
                                 OR error_msg LIKE '%没有可用的令牌%'
                                 OR error_msg LIKE '%Token 无效%'
                                 OR error_msg LIKE '%Token 解密失败%')"
                        );
                        $req->execute([$newTokenId, $userId, $platform]);
                        $requeued = $req->rowCount();

                        // 仓库监控：绑定令牌已被删除的（令牌悬空）自动换绑到新令牌并立即重查；
                        // 仅 401 失效但令牌仍存在的，保留原绑定只安排重查（用户也可在监控页手动换绑）
                        $rqm1 = db()->prepare(
                            "UPDATE repo_monitors m
                             LEFT JOIN github_tokens t ON m.token_id = t.id
                             SET m.token_id = ?, m.next_check_at = NOW(), m.retries = 0, m.last_error = NULL, m.last_status = NULL
                             WHERE m.user_id = ? AND m.platform = ? AND t.id IS NULL
                               AND (m.last_error LIKE '%令牌已被删除%'
                                 OR m.last_error LIKE '%Token 无效%'
                                 OR m.last_error LIKE '%Token 解密失败%')"
                        );
                        $rqm1->execute([$newTokenId, $userId, $platform]);
                        $rqm2 = db()->prepare(
                            "UPDATE repo_monitors
                             SET next_check_at = NOW(), retries = 0
                             WHERE user_id = ? AND platform = ?
                               AND (last_error LIKE '%令牌已被删除%'
                                 OR last_error LIKE '%Token 无效%'
                                 OR last_error LIKE '%Token 解密失败%')"
                        );
                        $rqm2->execute([$userId, $platform]);
                        if ($requeued > 0) {
                            $message .= " 已自动恢复 {$requeued} 个因令牌失效而失败的任务（稍后自动继续抓取）。";
                        }
                    } catch (Throwable $e) {
                        // 恢复失败不影响令牌本身；任务可用「重试」按钮手动恢复
                        error_log('token revive failed: ' . $e->getMessage());
                        $message .= '（注意：相关失败任务的自动恢复出错，可在任务管理页手动重试。）';
                        $msgType = 'success';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            // IDOR 防护：带 user_id 条件
            db()->prepare('DELETE FROM github_tokens WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            audit_log('token_deleted', ['id' => $id], $userId);
            $message = 'Token 已删除。绑定它的仓库监控将无法检查（可在监控上换绑令牌），'
                . '相关搜索任务失败后重新添加令牌即可自动恢复。';
            $msgType = 'success';
        }
    }
}

// 列出用户的 Token（只显示后4位，不返回明文）
$tokens = db()->prepare('SELECT id, platform, label, token_hint, created_at, last_used_at FROM github_tokens WHERE user_id = ? ORDER BY created_at DESC');
$tokens->execute([$userId]);
$tokens = $tokens->fetchAll();

// 各令牌绑定的监控 + 关联搜索任务（供删除确认框提示影响面）
$monitorsByToken = [];
$mst = db()->prepare('SELECT id, token_id, repo FROM repo_monitors WHERE user_id = ?');
$mst->execute([$userId]);
foreach ($mst->fetchAll() as $m) {
    $monitorsByToken[(int)$m['token_id']][] = $m['repo'];
}
// 搜索任务按 token_id 归组：列出未失败（含已完成待复查）的任务关键词
$tasksByToken = [];
$tst = db()->prepare(
    "SELECT token_id, query, status FROM search_tasks
     WHERE user_id = ? AND (status IN ('pending','running') OR (status = 'completed' AND recheck_minutes > 0))"
);
$tst->execute([$userId]);
foreach ($tst->fetchAll() as $t) {
    $tasksByToken[(int)$t['token_id']][] = $t['query'] . '（' . $t['status'] . '）';
}

$csrf = Security::csrfToken();
include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= Security::e($message) ?></div>
<?php endif; ?>

<div class="card">
    <h3>添加令牌</h3>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <div class="form-row">
            <div class="form-group">
                <label>所属平台</label>
                <select name="platform">
                    <?php foreach (PlatformSearch::PLATFORMS as $pk => $pv): ?>
                        <option value="<?= Security::e($pk) ?>"><?= Security::e($pv[0]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>备注名</label>
                <input type="text" name="label" required maxlength="64" placeholder="如：个人主账号 Token">
            </div>
            <div class="form-group">
                <label>Token</label>
                <input type="password" name="token" required maxlength="300" placeholder="平台个人访问令牌">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">添加 Token</button>
    </form>
</div>

<div class="card">
    <h3>已配置的 Token</h3>
    <?php if (empty($tokens)): ?>
        <p style="color:var(--text-2);font-size:14px;">暂无 Token</p>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>平台</th><th>备注名</th><th>Token 后4位</th><th>创建时间</th><th>最后使用</th><th>操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td><span class="badge badge-info"><?= Security::e(PlatformSearch::label($t['platform'] ?? 'github')) ?></span></td>
                    <td><?= Security::e($t['label']) ?></td>
                    <td><code>••••<?= Security::e($t['token_hint']) ?></code></td>
                    <td style="white-space:nowrap;"><?= Security::e($t['created_at']) ?></td>
                    <td style="white-space:nowrap;"><?= Security::e($t['last_used_at'] ?? '—') ?></td>
                    <td>
                        <?php $bound = $monitorsByToken[(int)$t['id']] ?? [];
                               $boundTasks = $tasksByToken[(int)$t['id']] ?? []; ?>
                        <form method="post" style="display:inline"
                              data-bound-monitors="<?= Security::e(json_encode($bound, JSON_UNESCAPED_UNICODE)) ?>"
                              data-bound-tasks="<?= Security::e(json_encode($boundTasks, JSON_UNESCAPED_UNICODE)) ?>"
                              onsubmit="return tokenDeleteConfirm(this);">
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
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
function tokenDeleteConfirm(form) {
    var monitors = [], tasks = [];
    try { monitors = JSON.parse(form.dataset.boundMonitors || '[]'); } catch (e) {}
    try { tasks = JSON.parse(form.dataset.boundTasks || '[]'); } catch (e) {}
    var msg = '';
    if (monitors && monitors.length) {
        msg += '该令牌被以下仓库监控使用：\n· ' + monitors.join('\n· ') + '\n\n';
    }
    if (tasks && tasks.length) {
        msg += '以下搜索任务正在使用该令牌（删除后将无法继续抓取）：\n· ' + tasks.join('\n· ') + '\n\n';
    }
    if (msg !== '') {
        msg += '删除后相关监控将无法检查（需换绑令牌），搜索任务失败后重新添加令牌即可自动恢复。\n\n确认删除？';
        return confirm(msg);
    }
    return confirm('确认删除该 Token？');
}
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
