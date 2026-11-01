<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$pageTitle = '任务结果';
$activePage = 'results';
// 结果页禁用缓存：平台/任务筛选切换不允许命中浏览器缓存
header('Cache-Control: no-store, max-age=0');

// 分页
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedPerPage = [30, 50, 100, 200, 500];
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 50;
}
$offset = ($page - 1) * $perPage;

// 任务筛选
$taskId = (int)($_GET['task_id'] ?? 0);
$where = 'st.user_id = ?';
$params = [$userId];
if ($taskId > 0) {
    $where .= ' AND sr.task_id = ?';
    $params[] = $taskId;
}

// 平台筛选
$platformFilter = (string)($_GET['platform'] ?? '');
$hasPlatformFilter = in_array($platformFilter, array_keys(PlatformSearch::PLATFORMS), true);
if ($hasPlatformFilter) {
    $where .= ' AND sr.platform = ?';
    $params[] = $platformFilter;
}

// 各平台结果计数（筛选标签用）
try {
    $cntStmt = db()->prepare(
        "SELECT sr.platform, COUNT(*) AS cnt
         FROM search_results sr JOIN search_tasks st ON sr.task_id = st.id
         WHERE st.user_id = ?" . ($taskId > 0 ? ' AND sr.task_id = ?' : '') . "
         GROUP BY sr.platform"
    );
    $cntStmt->execute($taskId > 0 ? [$userId, $taskId] : [$userId]);
    $platformCounts = [];
    foreach ($cntStmt->fetchAll() as $row) {
        $platformCounts[$row['platform']] = (int)$row['cnt'];
    }
} catch (Throwable $e) {
    $platformCounts = [];
}

$error = '';
$total = 0;
$results = [];
$totalPages = 1;

try {
    // 总条数
    $countStmt = db()->prepare("SELECT COUNT(*) FROM search_results sr JOIN search_tasks st ON sr.task_id = st.id WHERE {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    // 查询结果
    $stmt = db()->prepare(
        "SELECT sr.*, st.query as task_query
         FROM search_results sr
         JOIN search_tasks st ON sr.task_id = st.id
         WHERE {$where}
         ORDER BY sr.found_at DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $results = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('results.php query failed: ' . $e->getMessage());
    $error = '数据查询失败：' . (($GLOBALS['config']['app']['debug'] ?? false) ? $e->getMessage() : '请检查数据库是否已正确导入 sql/schema.sql');
}

// 构建当前查询字符串（用于分页和导出链接保留筛选条件）
$queryString = http_build_query(array_filter([
    'task_id' => $taskId ?: null,
    'per_page' => $perPage,
    'platform' => $hasPlatformFilter ? $platformFilter : null,
]));

// 任务下拉清单：有平台筛选时只列出该平台的任务，保证与平台标签一致
$taskList = db()->prepare(
    'SELECT id, platform, query FROM search_tasks WHERE user_id = ?' . ($hasPlatformFilter ? ' AND platform = ?' : '') . ' ORDER BY id DESC LIMIT 100'
);
$taskList->execute($hasPlatformFilter ? [$userId, $platformFilter] : [$userId]);
$taskList = $taskList->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<script>
function setFilter(key, val) {
    const u = new URLSearchParams(location.search);
    if (val) { u.set(key, val); } else { u.delete(key); }
    u.delete('page');
    location.href = '?' + u.toString();
}
</script>
<div class="card">
    <!-- 平台分类筛选 -->
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
        <span style="font-size:13px;color:var(--text-2);">平台：</span>
        <a href="?<?= http_build_query(array_filter(['per_page' => $perPage])) ?>"
           class="badge <?= !$hasPlatformFilter ? 'badge-info' : 'badge-secondary' ?>" style="padding:4px 12px;">全部</a>
        <?php foreach (PlatformSearch::PLATFORMS as $pk => $pv): ?>
            <a href="?<?= http_build_query(array_filter(['per_page' => $perPage, 'platform' => $pk])) ?>"
               class="badge <?= ($hasPlatformFilter && $platformFilter === $pk) ? 'badge-info' : 'badge-secondary' ?>" style="padding:4px 12px;">
                <?= Security::e($pv[0]) ?>（<?= (int)($platformCounts[$pk] ?? 0) ?>）
            </a>
        <?php endforeach; ?>
        <span style="font-size:13px;color:var(--text-2);margin-left:12px;">任务：</span>
        <select style="max-width:340px;" onchange="setFilter('task_id', this.value)">
            <option value="">全部任务</option>
            <?php foreach ($taskList as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $taskId === (int)$t['id'] ? 'selected' : '' ?>>
                    #<?= (int)$t['id'] ?> · <?= Security::e(PlatformSearch::label($t['platform'] ?? 'github')) ?> · <?= Security::e(mb_substr($t['query'], 0, 40)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <h3 style="margin:0;">命中结果（共 <?= $total ?> 条）</h3>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <label style="font-size:14px;color:var(--text);">每页:</label>
            <select onchange="setFilter('per_page', this.value)">
                <?php foreach ($allowedPerPage as $n): ?>
                    <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
            <div class="btn-group">
                <a href="export.php?format=csv<?= $taskId ? '&task_id='.$taskId : '' ?><?= $hasPlatformFilter ? '&platform='.$platformFilter : '' ?>" class="btn btn-secondary">导出 CSV</a>
                <a href="export.php?format=excel<?= $taskId ? '&task_id='.$taskId : '' ?><?= $hasPlatformFilter ? '&platform='.$platformFilter : '' ?>" class="btn btn-secondary">导出 Excel</a>
                <a href="export.php?format=json<?= $taskId ? '&task_id='.$taskId : '' ?><?= $hasPlatformFilter ? '&platform='.$platformFilter : '' ?>" class="btn btn-secondary">导出 JSON</a>
            </div>
        </div>
    </div>

    <?php if ($taskId > 0 || $hasPlatformFilter): ?>
        <p style="font-size:13px;color:var(--text-2);margin-bottom:12px;">
            当前筛选：
            <?php if ($hasPlatformFilter): ?>
                <span class="badge badge-info">平台：<?= Security::e(PlatformSearch::label($platformFilter)) ?></span>
                <a href="javascript:setFilter('platform','')" style="color:#1a56a0;margin-right:10px;">清除平台</a>
            <?php endif; ?>
            <?php if ($taskId > 0): ?>
                <span class="badge badge-warning">任务：#<?= $taskId ?></span>
                <a href="javascript:setFilter('task_id','')" style="color:#1a56a0;">清除任务</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= Security::e($error) ?></div>
    <?php elseif (empty($results)): ?>
        <p style="color:var(--text-2);font-size:14px;">暂无任务结果</p>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>平台</th><th>ID</th><th>关键词</th><th>仓库</th><th>文件路径</th><th>命中时间</th><th>链接</th></tr>
            </thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <?php
                    // 只输出 http(s) 链接：平台返回的 html_url 理论上可信，仍防 javascript:/data: 伪协议
                    $safeLink = str_starts_with((string)$r['html_url'], 'https://') || str_starts_with((string)$r['html_url'], 'http://') ? (string)$r['html_url'] : '#';
                ?>
                <tr>
                    <td><span class="badge badge-info"><?= Security::e(PlatformSearch::label($r['platform'] ?? 'github')) ?></span></td>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= Security::e($r['task_query']) ?></td>
                    <td><?= Security::e($r['repo_full_name']) ?></td>
                    <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= Security::e($r['file_path']) ?>"><?= Security::e($r['file_path']) ?></td>
                    <td><?= Security::e($r['found_at']) ?></td>
                    <td><a href="<?= Security::e($safeLink) ?>" target="_blank" rel="noopener">查看</a></td>
                </tr>
                <?php if ($r['match_snippet']): ?>
                    <tr><td colspan="7"><pre class="snippet"><?= Security::e($r['match_snippet']) ?></pre></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
            <div style="margin-top:16px;display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;">
                <?php if ($page > 1): ?>
                    <a href="?page=1&per_page=<?= $perPage ?><?= $taskId ? '&task_id='.$taskId : '' ?><?= $hasPlatformFilter ? '&platform='.$platformFilter : '' ?>" class="btn btn-secondary">首页</a>
                    <a href="?page=<?= $page - 1 ?>&per_page=<?= $perPage ?><?= $taskId ? '&task_id='.$taskId : '' ?><?= $hasPlatformFilter ? '&platform='.$platformFilter : '' ?>" class="btn btn-secondary">上一页</a>
                <?php endif; ?>
                <span style="margin:0 8px;">第 <?= $page ?> / <?= $totalPages ?> 页</span>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&per_page=<?= $perPage ?><?= $taskId ? '&task_id='.$taskId : '' ?><?= $hasPlatformFilter ? '&platform='.$platformFilter : '' ?>" class="btn btn-secondary">下一页</a>
                    <a href="?page=<?= $totalPages ?>&per_page=<?= $perPage ?><?= $taskId ? '&task_id='.$taskId : '' ?><?= $hasPlatformFilter ? '&platform='.$platformFilter : '' ?>" class="btn btn-secondary">末页</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.table-scroll {
    max-height: 65vh;
    overflow-y: auto;
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 6px;
}
.table-scroll table {
    margin: 0;
    border: none;
}
.table-scroll::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}
.table-scroll::-webkit-scrollbar-track {
    background: var(--surface-2);
}
.table-scroll::-webkit-scrollbar-thumb {
    background: var(--border-strong);
    border-radius: 5px;
}
.table-scroll::-webkit-scrollbar-thumb:hover {
    background: var(--text-3);
}
.btn-group .btn {
    margin: 0;
    border-radius: 0;
}
.btn-group .btn:first-child { border-radius: 6px 0 0 6px; }
.btn-group .btn:last-child { border-radius: 0 6px 6px 0; }
</style>
<?php include __DIR__ . '/partials/footer.php'; ?>
