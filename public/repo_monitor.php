<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$pageTitle = '仓库监控';
$activePage = 'repo_monitor';

$message = '';
$msgType = 'error';

$intervalOptions = [
    10   => '每 10 分钟',
    30   => '每 30 分钟',
    60   => '每 1 小时',
    180  => '每 3 小时',
    360  => '每 6 小时',
    720  => '每 12 小时',
    1440 => '每天',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'CSRF 校验失败。';
    } else {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'create') {
            // Rate limit: 每用户每分钟最多 5 次创建
            if (!Security::rateLimit('repo_monitor_' . $userId, 5, 60)) {
                $message = '操作过于频繁，请稍后再试。';
            } else {
                $repo = post_string('repo', 150);
                $tokenId = (int)($_POST['token_id'] ?? 0);
                $interval = (int)($_POST['check_interval_minutes'] ?? 60);
                $notify = (int)($_POST['notify'] ?? 1);
                $platform = $_POST['platform'] ?? 'github';
                if (!in_array($platform, array_keys(PlatformSearch::PLATFORMS), true)) {
                    $platform = 'github';
                }

                if (!Validator::githubRepo($repo, $platform)) {
                    $message = '仓库格式不正确，应为 owner/name（GitCode 支持最多三层命名空间）。';
                } elseif ($tokenId <= 0) {
                    $message = '请选择用于查询的平台 Token。';
                } else {
                    $allowedIntervals = array_keys($intervalOptions);
                    if (!in_array($interval, $allowedIntervals, true)) {
                        $interval = 60;
                    }
                    // IDOR 防护：Token 必须属于当前用户，且平台必须与监控一致
                    $chk = db()->prepare('SELECT id FROM github_tokens WHERE id = ? AND user_id = ? AND platform = ?');
                    $chk->execute([$tokenId, $userId, $platform]);
                    if (!$chk->fetch()) {
                        $message = 'Token 不存在或平台不匹配。';
                    } else {
                        // 同一平台下同一仓库同一用户只允许一条监控
                        $dup = db()->prepare('SELECT id FROM repo_monitors WHERE user_id = ? AND platform = ? AND repo = ?');
                        $dup->execute([$userId, $platform, $repo]);
                        if ($dup->fetch()) {
                            $message = '该仓库已在监控列表中。';
                        } else {
                            db()->prepare(
                                'INSERT INTO repo_monitors (user_id, platform, token_id, repo, check_interval_minutes, notify, next_check_at)
                                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
                            )->execute([$userId, $platform, $tokenId, $repo, $interval, $notify ? 1 : 0]);
                            $message = "已添加对 " . PlatformSearch::label($platform) . " {$repo} 的监控，首次检查将记录基线提交（不推送）。";
                            $msgType = 'success';
                            audit_log('repo_monitor_created', ['platform' => $platform, 'repo' => $repo, 'interval' => $interval], $userId);
                            // 首次检查由 bootstrap 的响应后 worker 自动执行（本请求结束时），
                            // 不在此处内联调用，避免弱网下阻塞创建请求
                        }
                    }
                }
            }
        } elseif ($action === 'check_now' && $id > 0) {
            db()->prepare('UPDATE repo_monitors SET next_check_at = NOW() WHERE id = ? AND user_id = ?')
                ->execute([$id, $userId]);
            $message = '已安排立即检查。';
            $msgType = 'success';
        } elseif ($action === 'pause' && $id > 0) {
            db()->prepare('UPDATE repo_monitors SET paused = 1 WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            $message = '监控已暂停。';
            $msgType = 'success';
            audit_log('repo_monitor_paused', ['monitor_id' => $id], $userId);
        } elseif ($action === 'resume' && $id > 0) {
            db()->prepare('UPDATE repo_monitors SET paused = 0, next_check_at = NOW() WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            $message = '监控已恢复。';
            $msgType = 'success';
            audit_log('repo_monitor_resumed', ['monitor_id' => $id], $userId);
        } elseif ($action === 'rebind' && $id > 0) {
            // 换绑令牌：监控绑定到同平台的新令牌，立即重新检查
            $newTokenId = (int)($_POST['new_token_id'] ?? 0);
            $mst = db()->prepare('SELECT platform FROM repo_monitors WHERE id = ? AND user_id = ?');
            $mst->execute([$id, $userId]);
            $mon = $mst->fetch();
            if (!$mon) {
                $message = '监控不存在。';
            } elseif ($newTokenId <= 0) {
                $message = '请选择要换绑的令牌。';
            } else {
                $tst = db()->prepare('SELECT id FROM github_tokens WHERE id = ? AND user_id = ? AND platform = ?');
                $tst->execute([$newTokenId, $userId, $mon['platform']]);
                if (!$tst->fetch()) {
                    $message = '新令牌不存在或平台不匹配。';
                } else {
                    db()->prepare('UPDATE repo_monitors SET token_id = ?, last_error = NULL, retries = 0, next_check_at = NOW() WHERE id = ? AND user_id = ?')
                        ->execute([$newTokenId, $id, $userId]);
                    $message = '监控已换绑到新令牌，将立即重新检查。';
                    $msgType = 'success';
                    audit_log('repo_monitor_rebound', ['monitor_id' => $id, 'token_id' => $newTokenId], $userId);
                }
            }
        } elseif ($action === 'delete' && $id > 0) {
            db()->prepare('DELETE FROM repo_monitors WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            $message = '监控已删除。';
            $msgType = 'success';
            audit_log('repo_monitor_deleted', ['monitor_id' => $id], $userId);
        }
    }
}

// 监控列表（IDOR：user_id 隔离）
$monitors = db()->prepare(
    'SELECT m.*, t.token_hint FROM repo_monitors m
     LEFT JOIN github_tokens t ON m.token_id = t.id
     WHERE m.user_id = ? ORDER BY m.created_at DESC'
);
$monitors->execute([$userId]);
$monitors = $monitors->fetchAll();

// 最近检测到的更新（本用户所有监控），页面按仓库分组展示
$updates = db()->prepare(
    'SELECT u.*, m.repo, m.platform FROM repo_updates u
     JOIN repo_monitors m ON u.monitor_id = m.id
     WHERE m.user_id = ? ORDER BY u.found_at DESC, u.id DESC LIMIT 100'
);
$updates->execute([$userId]);
$updates = $updates->fetchAll();

// 按仓库分组：保持最近更新在前的仓库顺序，组内沿用查询顺序
$updatesByRepo = [];
foreach ($updates as $u) {
    $key = ($u['platform'] ?? 'github') . '|' . $u['repo'];
    if (!isset($updatesByRepo[$key])) {
        $updatesByRepo[$key] = [
            'platform' => $u['platform'] ?? 'github',
            'repo'     => $u['repo'],
            'items'    => [],
        ];
    }
    $updatesByRepo[$key]['items'][] = $u;
}

// 可选 Token
$tokens = db()->prepare('SELECT id, platform, label, token_hint FROM github_tokens WHERE user_id = ? ORDER BY created_at DESC');
$tokens->execute([$userId]);
$tokens = $tokens->fetchAll();
$tokensByPlatform = [];
foreach ($tokens as $t) {
    $tokensByPlatform[$t['platform'] ?? 'github'][] = $t;
}

$csrf = Security::csrfToken();
include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= Security::e($message) ?></div>
<?php endif; ?>

<div class="card">
    <h3>添加仓库监控</h3>
    <?php if (empty($tokens)): ?>
        <div class="alert alert-error">请先在 <a href="tokens.php">GitHub Token</a> 页面添加至少一个 Token。</div>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <div class="form-group">
                    <label>平台</label>
                    <select name="platform">
                        <?php foreach (PlatformSearch::PLATFORMS as $pk => $pv): ?>
                            <option value="<?= Security::e($pk) ?>"><?= Security::e($pv[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>仓库（owner/name）</label>
                    <input type="text" name="repo" required maxlength="150" placeholder="例如 octocat/Hello-World">
                </div>
                <div class="form-group">
                    <label>查询用 Token（须与所选平台一致）</label>
                    <select name="token_id" required>
                        <?php foreach (PlatformSearch::PLATFORMS as $pk => $pv): ?>
                            <?php if (!empty($tokensByPlatform[$pk])): ?>
                                <optgroup label="<?= Security::e($pv[0]) ?>">
                                    <?php foreach ($tokensByPlatform[$pk] as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>"><?= Security::e($t['label']) ?> (••••<?= Security::e($t['token_hint']) ?>)</option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>检查间隔</label>
                    <select name="check_interval_minutes">
                        <?php foreach ($intervalOptions as $min => $label): ?>
                            <option value="<?= $min ?>"><?= Security::e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>发现更新后推送通知</label>
                    <select name="notify">
                        <option value="1">是</option>
                        <option value="0">否</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">添加监控</button>
            <small style="display:block;color:var(--text-2);font-size:12px;margin-top:10px;">
                首次检查记录基线（不推送），之后发现新提交即记录并按需推送通知。
            </small>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3>监控列表（共 <?= count($monitors) ?> 个）</h3>
    <?php if (empty($monitors)): ?>
        <p style="color:var(--text-2);font-size:14px;">暂无监控</p>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>平台</th><th>仓库</th><th>状态</th><th>最新提交</th><th>最近检查</th><th>检查间隔</th><th>下次检查</th><th>Token</th><th>操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($monitors as $m): ?>
                <tr>
                    <td><span class="badge badge-info"><?= Security::e(PlatformSearch::label($m['platform'] ?? 'github')) ?></span></td>
                    <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= Security::e($m['repo']) ?>"><?= Security::e($m['repo']) ?></td>
                    <td>
                        <?php if ($m['paused']): ?>
                            <span class="badge badge-warning">已暂停</span>
                        <?php elseif ($m['last_status'] === 'error'): ?>
                            <span class="badge badge-danger" title="<?= Security::e($m['last_error'] ?? '') ?>">检查失败</span>
                        <?php elseif ($m['last_status'] === 'ok'): ?>
                            <span class="badge badge-success">正常</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">待首次检查</span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= Security::e($m['last_commit_message'] ?? '') ?>">
                        <?= $m['last_sha'] ? '<code>' . Security::e(substr($m['last_sha'], 0, 8)) . '</code> ' . Security::e($m['last_commit_author'] ?? '') : '—' ?>
                    </td>
                    <td style="white-space:nowrap;"><?= Security::e($m['last_checked_at'] ?? '—') ?></td>
                    <td><?= Security::e($intervalOptions[(int)$m['check_interval_minutes']] ?? ((int)$m['check_interval_minutes'] . ' 分钟')) ?></td>
                    <td style="white-space:nowrap;"><?= $m['paused'] ? '—' : Security::e($m['next_check_at'] ?? '—') ?></td>
                    <td><code>••••<?= Security::e($m['token_hint'] ?? '已删除') ?></code></td>
                    <td style="white-space:nowrap;">
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                            <input type="hidden" name="action" value="check_now">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="btn btn-secondary">立即检查</button>
                        </form>
                        <?php if ($m['paused']): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                                <input type="hidden" name="action" value="resume">
                                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                <button type="submit" class="btn btn-success">恢复</button>
                            </form>
                        <?php else: ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                                <input type="hidden" name="action" value="pause">
                                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                <button type="submit" class="btn btn-secondary">暂停</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('确认删除该监控及其更新记录？');">
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="btn btn-danger">删除</button>
                        </form>
                    </td>
                </tr>
                <?php if ($m['last_status'] === 'error' && $m['last_error']): ?>
                    <tr><td colspan="9" style="color:#dc2626;font-size:13px;">错误: <?= Security::e($m['last_error']) ?>
                        <?php if (str_contains((string)$m['last_error'], 'Token 已被删除') && !empty($tokensByPlatform[$m['platform'] ?? ''])): ?>
                            — 可在下方直接换绑同平台的可用令牌：
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                                <input type="hidden" name="action" value="rebind">
                                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                <select name="new_token_id" required>
                                    <?php foreach ($tokensByPlatform[$m['platform']] as $tk): ?>
                                        <option value="<?= (int)$tk['id'] ?>"><?= Security::e($tk['label']) ?> (••••<?= Security::e($tk['token_hint']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary" style="font-size:12px;">换绑令牌</button>
                            </form>
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
        <h3 style="margin:0;">最近检测到的更新</h3>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-secondary" id="updExpandAll" style="font-size:12px;padding:4px 12px;">全部展开</button>
            <button type="button" class="btn btn-secondary" id="updCollapseAll" style="font-size:12px;padding:4px 12px;">全部折叠</button>
        </div>
    </div>
    <?php if (empty($updatesByRepo)): ?>
        <p style="color:var(--text-2);font-size:14px;">暂无更新记录</p>
    <?php else: ?>
        <div class="upd-groups">
            <?php $firstKey = array_key_first($updatesByRepo); ?>
            <?php foreach ($updatesByRepo as $key => $group): ?>
                <?php
                    $latest = $group['items'][0];
                    // 默认仅展开最近有更新的第一个仓库，其余折叠（用户手动展开后记忆在 localStorage）
                    $defaultOpen = ($key === $firstKey);
                ?>
                <div class="upd-group<?= $defaultOpen ? ' open' : '' ?>" data-key="<?= Security::e($key) ?>">
                    <button type="button" class="upd-head" aria-expanded="<?= $defaultOpen ? 'true' : 'false' ?>">
                        <svg class="upd-chevron" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                        <span class="upd-repo" title="<?= Security::e($group['repo']) ?>"><?= Security::e($group['repo']) ?></span>
                        <span class="badge badge-info"><?= Security::e(PlatformSearch::label($group['platform'])) ?></span>
                        <span class="upd-meta">
                            <span class="badge badge-secondary"><?= count($group['items']) ?> 条</span>
                            <span>最近发现 <?= Security::e(substr($latest['found_at'], 0, 16)) ?></span>
                        </span>
                    </button>
                    <div class="upd-body">
                        <table>
                            <thead>
                            <tr><th>提交</th><th>作者</th><th>说明</th><th>提交时间</th><th>发现时间</th><th>链接</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['items'] as $u): ?>
                                <?php $safeLink = str_starts_with((string)$u['html_url'], 'https://') || str_starts_with((string)$u['html_url'], 'http://') ? (string)$u['html_url'] : '#'; ?>
                                <tr>
                                    <td><code><?= Security::e(substr($u['sha'], 0, 8)) ?></code></td>
                                    <td><?= Security::e($u['author']) ?></td>
                                    <td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= Security::e($u['message']) ?>"><?= Security::e(mb_substr($u['message'], 0, 60)) ?></td>
                                    <td style="white-space:nowrap;"><?= Security::e($u['committed_at'] ?? '—') ?></td>
                                    <td style="white-space:nowrap;"><?= Security::e($u['found_at']) ?></td>
                                    <td><a href="<?= Security::e($safeLink) ?>" target="_blank" rel="noopener">查看</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var KEY = 'sm-upd-expanded';
    var store = {};
    try { store = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { store = {}; }
    var groups = Array.prototype.slice.call(document.querySelectorAll('.upd-group'));

    function apply(g, open, persist) {
        g.classList.toggle('open', open);
        g.querySelector('.upd-head').setAttribute('aria-expanded', open ? 'true' : 'false');
        if (persist) { store[g.getAttribute('data-key')] = open; }
    }
    function save() { try { localStorage.setItem(KEY, JSON.stringify(store)); } catch (e) {} }

    groups.forEach(function (g) {
        var key = g.getAttribute('data-key');
        // 用户有历史操作记录时以记录为准，否则用服务端默认（仅第一个展开）
        var open = Object.prototype.hasOwnProperty.call(store, key) ? !!store[key] : g.classList.contains('open');
        apply(g, open, false);
        g.querySelector('.upd-head').addEventListener('click', function () {
            apply(g, !g.classList.contains('open'), true);
            save();
        });
    });
    document.getElementById('updExpandAll').addEventListener('click', function () {
        groups.forEach(function (g) { apply(g, true, true); }); save();
    });
    document.getElementById('updCollapseAll').addEventListener('click', function () {
        groups.forEach(function (g) { apply(g, false, true); }); save();
    });
})();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
