<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$pageTitle = '搜索任务';
$activePage = 'tasks';

$message = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'CSRF 校验失败。';
        $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'pause' && $id > 0) {
            db()->prepare('UPDATE search_tasks SET paused = 1 WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            $message = '任务已暂停。';
            audit_log('task_paused', ['task_id' => $id], $userId);
        } elseif ($action === 'resume' && $id > 0) {
            db()->prepare('UPDATE search_tasks SET paused = 0 WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            $message = '任务已恢复。';
            audit_log('task_resumed', ['task_id' => $id], $userId);
        } elseif ($action === 'recheck_now' && $id > 0) {
            // 立即复查：把 completed 任务的复查时间提前到当前请求后由 TaskWorker 拾取
            db()->prepare(
                "UPDATE search_tasks SET next_check_at = NOW()
                 WHERE id = ? AND user_id = ? AND status = 'completed' AND recheck_minutes > 0"
            )->execute([$id, $userId]);
            $message = '已安排立即复查。';
            audit_log('task_recheck_requested', ['task_id' => $id], $userId);
        } elseif ($action === 'delete' && $id > 0) {
            db()->prepare('DELETE FROM search_tasks WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            $message = '任务及结果已删除。';
            $msgType = 'success';
            audit_log('task_deleted', ['task_id' => $id], $userId);
        } elseif ($action === 'batch_delete') {
            $ids = $_POST['ids'] ?? [];
            if (!empty($ids) && is_array($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_map('intval', $ids);
                $params[] = $userId;
                $del = db()->prepare("DELETE FROM search_tasks WHERE id IN ($placeholders) AND user_id = ?");
                $del->execute($params);
                $message = '已删除 ' . $del->rowCount() . ' 个任务。';
                audit_log('task_batch_deleted', ['count' => $del->rowCount()], $userId);
            }
        }
    }
}

// 分页
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare('SELECT COUNT(*) FROM search_tasks WHERE user_id = ?');
$countStmt->execute([$userId]);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = db()->prepare(
    'SELECT * FROM search_tasks WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?'
);
$stmt->execute([$userId, $perPage, $offset]);
$tasks = $stmt->fetchAll();

$csrf = Security::csrfToken();
include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= Security::e($message) ?></div>
<?php endif; ?>

<div class="card">
    <h3>搜索任务（共 <?= $total ?> 个）</h3>
    <?php if (empty($tasks)): ?>
        <p style="color:var(--text-2);font-size:14px;">暂无搜索任务</p>
    <?php else: ?>
        <form method="post" id="batchForm">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
            <input type="hidden" name="action" value="batch_delete">
            <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll"></th>
                    <th>ID</th>
                    <th>平台</th>
                    <th>搜索关键词</th>
                    <th>状态</th>
                    <th>进度</th>
                    <th>命中数</th>
                    <th>本轮新增</th>
                    <th>下次复查</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>"></td>
                        <td><?= (int)$t['id'] ?></td>
                        <td><span class="badge badge-info"><?= Security::e(PlatformSearch::label($t['platform'] ?? 'github')) ?></span></td>
                        <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= Security::e($t['query']) ?>"><?= Security::e($t['query']) ?></td>
                        <td>
                            <?php
                            $statusMap = ['pending' => ['等待', 'badge-secondary'], 'running' => ['运行中', 'badge-primary'], 'completed' => ['完成', 'badge-success'], 'failed' => ['失败', 'badge-danger']];
                            [$label, $cls] = $statusMap[$t['status']] ?? [$t['status'], 'badge-secondary'];
                            ?>
                            <span class="badge <?= $cls ?>"><?= Security::e((string)$label) ?></span>
                            <?php if ($t['paused']): ?>
                                <span class="badge badge-warning">已暂停</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($t['status'] === 'running' && (int)$t['total_pages'] > 10): ?>
                                拆分子查询 <?= (int)$t['current_page'] ?>/<?= (int)$t['total_pages'] ?>
                            <?php elseif ($t['status'] === 'running' && (int)$t['total_pages'] > 0): ?>
                                第 <?= (int)$t['current_page'] ?>/<?= (int)$t['total_pages'] ?> 页
                            <?php elseif ($t['status'] === 'pending'): ?>
                                排队中
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$t['total_count'] ?></td>
                        <td><?= (int)$t['last_new_count'] ?></td>
                        <td style="white-space:nowrap;">
                            <?= $t['next_check_at'] ? Security::e($t['next_check_at']) : '—' ?>
                        </td>
                        <td style="white-space:nowrap;"><?= Security::e($t['created_at']) ?></td>
                        <td style="white-space:nowrap;">
                            <?php if (!$t['paused']): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                                    <input type="hidden" name="action" value="pause">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="btn btn-secondary">暂停</button>
                                </form>
                            <?php else: ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                                    <input type="hidden" name="action" value="resume">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="btn btn-success">恢复</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($t['status'] === 'completed' && (int)$t['recheck_minutes'] > 0): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                                    <input type="hidden" name="action" value="recheck_now">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="btn btn-success">立即复查</button>
                                </form>
                            <?php endif; ?>
                            <a href="results.php?task_id=<?= (int)$t['id'] ?>" class="btn btn-secondary">查看结果</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('确认删除此任务及其所有结果？');">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn-danger">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php if ($t['error_msg']): ?>
                        <tr><td colspan="11" style="color:#dc2626;font-size:13px;">错误: <?= Security::e($t['error_msg']) ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div style="margin-top:12px;">
                <button type="submit" class="btn btn-danger" onclick="return confirm('确认删除选中的任务？');">批量删除</button>
            </div>
        </form>

        <?php if ($totalPages > 1): ?>
            <div style="margin-top:16px;text-align:center;">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="btn btn-secondary">上一页</a>
                <?php endif; ?>
                <span style="margin:0 12px;">第 <?= $page ?> / <?= $totalPages ?> 页</span>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="btn btn-secondary">下一页</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = this.checked);
});
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
