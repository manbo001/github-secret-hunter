<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$pageTitle = '平台 Token';
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
                    audit_log('token_created', ['label' => $label, 'platform' => $platform], $userId);
                    $message = 'Token 添加成功。';
                    $msgType = 'success';
                } catch (Throwable $e) {
                    $message = 'Token 加密存储失败。';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            // IDOR 防护：带 user_id 条件
            db()->prepare('DELETE FROM github_tokens WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            audit_log('token_deleted', ['id' => $id], $userId);
            $message = 'Token 已删除。';
            $msgType = 'success';
        }
    }
}

// 列出用户的 Token（只显示后4位，不返回明文）
$tokens = db()->prepare('SELECT id, platform, label, token_hint, created_at, last_used_at FROM github_tokens WHERE user_id = ? ORDER BY created_at DESC');
$tokens->execute([$userId]);
$tokens = $tokens->fetchAll();

// 各令牌绑定的监控（供删除确认框提示影响面）
$monitorsByToken = [];
$mst = db()->prepare('SELECT id, token_id, repo FROM repo_monitors WHERE user_id = ?');
$mst->execute([$userId]);
foreach ($mst->fetchAll() as $m) {
    $monitorsByToken[(int)$m['token_id']][] = $m['repo'];
}

$csrf = Security::csrfToken();
include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= Security::e($message) ?></div>
<?php endif; ?>

<div class="card">
    <h3>添加平台 Token</h3>
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
                <label>平台 Token</label>
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
                        <?php $bound = $monitorsByToken[(int)$t['id']] ?? []; ?>
                        <form method="post" style="display:inline" data-bound-monitors="<?= Security::e(json_encode($bound, JSON_UNESCAPED_UNICODE)) ?>" onsubmit="return tokenDeleteConfirm(this);">
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
    var bound = [];
    try { bound = JSON.parse(form.dataset.boundMonitors || '[]'); } catch (e) {}
    if (bound && bound.length) {
        return confirm('该令牌被以下仓库监控使用：\n· ' + bound.join('\n· ') +
            '\n\n删除后这些监控将无法检查（需在监控上换绑令牌）。\n\n确认删除？');
    }
    return confirm('确认删除该 Token？');
}
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
