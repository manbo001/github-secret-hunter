<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$pageTitle = '任务下发';
$activePage = 'search';

// 获取用户的 Token 列表（按平台分组展示）
$tokens = db()->prepare('SELECT id, platform, label, token_hint FROM github_tokens WHERE user_id = ? ORDER BY created_at DESC');
$tokens->execute([$userId]);
$tokens = $tokens->fetchAll();
$tokensByPlatform = [];
foreach ($tokens as $t) {
    $tokensByPlatform[$t['platform'] ?? 'github'][] = $t;
}

$presets = GitHubClient::presetQueries();
$csrf = Security::csrfToken();
include __DIR__ . '/partials/header.php';
?>
<div class="card">
    <h3>发起搜索</h3>
    <?php if (empty($tokens)): ?>
        <div class="alert alert-error">请先在 <a href="tokens.php">令牌设置</a> 页面添加至少一个 Token。</div>
    <?php else: ?>
        <form id="searchForm">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                <div class="form-group">
                    <label>选择 Token（平台随 Token 而定）</label>
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
                <div class="form-group">
                    <label>搜索关键词（GitHub 支持代码搜索语法；Gitee/GitCode 为仓库线索搜索，匹配仓库名与描述）</label>
                    <input type="text" name="query" id="query" required maxlength="512"
                           placeholder="例如：password filename:.env">
                    <small style="display:block;color:#b45309;font-size:12px;margin-top:6px;">
                        平台差异：GitHub 的 filename: / extension: 等限定符仅 GitHub 认识；
                        Gitee / GitCode 请使用普通关键词（如 password、secret），搜索的是仓库名与描述。
                        另：Gitee 搜索接口存在官方已知问题（返回空结果，Issue #ICCSIY），修复前该平台可能搜不到内容。
                    </small>
                </div>
            <div class="form-group">
                <label>快速选择预设规则</label>
                <div id="presetBtns" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                    <?php foreach ($presets as $name => $q): ?>
                        <button type="button" class="btn btn-secondary" data-q="<?= Security::e($q) ?>"><?= Security::e($name) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>每页数量（GitHub 最大 100；Gitee/GitCode 按平台上限自动调整）</label>
                    <input type="number" name="per_page" value="100" min="10" max="100">
                </div>
                <div class="form-group">
                    <label>命中后推送通知</label>
                    <select name="notify">
                        <option value="1">是</option>
                        <option value="0">否</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" id="searchBtn">开始搜索</button>
        </form>
    <?php endif; ?>
</div>

<div class="card" id="resultCard" style="display:none">
    <h3>任务进度</h3>
    <div id="resultInfo"></div>
</div>

<script>
// HTML 转义：所有来自 GitHub API 的字符串必须先转义再插入 DOM
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, function(c) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
}
// 仅允许 https 链接，防止 javascript: 等伪协议
function safeUrl(u) {
    return /^https:\/\//i.test(String(u || '')) ? u : '#';
}

// 预设规则填充
document.getElementById('presetBtns').addEventListener('click', function(e) {
    if (e.target.dataset.q) {
        document.getElementById('query').value = e.target.dataset.q;
    }
});

// 搜索提交（AJAX）：创建后台任务后轮询进度
let pollTimer = null;

document.getElementById('searchForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('searchBtn');
    btn.disabled = true;
    btn.textContent = '提交中...';

    const fd = new FormData(this);
    fetch('api/search.php', {
        method: 'POST',
        body: fd,
        headers: {'X-CSRF-Token': fd.get('csrf_token')}
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = '开始搜索';
        const card = document.getElementById('resultCard');
        const info = document.getElementById('resultInfo');
        card.style.display = 'block';

        if (!data.success) {
            info.innerHTML = '<div class="alert alert-error">' + escHtml(data.message || '提交失败') + '</div>';
            return;
        }
        if (pollTimer) clearInterval(pollTimer);
        info.innerHTML = '<p>任务 #' + data.task_id + ' 已创建，后台分页抓取中…（每页请求间隔执行，遵守 GitHub 速率限制）</p>';
        pollTimer = setInterval(() => pollTask(data.task_id, fd.get('csrf_token')), 4000);
        pollTask(data.task_id, fd.get('csrf_token'));
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = '开始搜索';
        document.getElementById('resultInfo').innerHTML = '<div class="alert alert-error">请求异常：' + escHtml(err.message) + '</div>';
    });
});

function renderTask(t) {
    const info = document.getElementById('resultInfo');
    if (t.status === 'running' || t.status === 'pending') {
        const pg = t.total_pages > 0 ? ('第 ' + t.current_page + ' / ' + t.total_pages + ' 页') : '准备中';
        info.innerHTML = '<p>任务 #' + escHtml(t.id) + ' 抓取中（' + pg + '），已命中 <strong>' +
            escHtml(t.total_count) + '</strong> 条，本轮新增 <strong>' + escHtml(t.last_new_count || 0) + '</strong> 条。' +
            (t.error_msg ? '<br><span style="color:#b45309;">' + escHtml(t.error_msg) + '</span>' : '') + '</p>';
    } else if (t.status === 'completed') {
        const recheck = (parseInt(t.recheck_minutes) > 0 && t.next_check_at)
            ? '将于 ' + escHtml(t.next_check_at) + ' 自动复查新增并推送。'
            : '';
        info.innerHTML = '<div class="alert alert-success">任务 #' + escHtml(t.id) + ' 本轮抓取完成：共命中 <strong>' +
            escHtml(t.total_count) + '</strong> 条，本轮新增 ' + escHtml(t.last_new_count || 0) +
            ' 条，已全部入库。' + recheck + '</div>' +
            '<p><a class="btn btn-primary" href="results.php?task_id=' + escHtml(t.id) + '">查看全部结果</a></p>';
        clearInterval(pollTimer);
        pollTimer = null;
    } else if (t.status === 'failed') {
        info.innerHTML = '<div class="alert alert-error">任务 #' + escHtml(t.id) + ' 失败：' + escHtml(t.error_msg || '未知错误') + '</div>';
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function pollTask(taskId, csrf) {
    fetch('api/task_status.php?id=' + encodeURIComponent(taskId), {
        headers: {'X-CSRF-Token': csrf}
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) renderTask(data.task);
    })
    .catch(() => {});
}
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
