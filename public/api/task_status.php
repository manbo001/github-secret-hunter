<?php
/**
 * 任务进度查询 API（GET，只读，无状态变更）
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

if (!Auth::check()) {
    json_response(['success' => false, 'message' => '未登录'], 401);
}
if (Auth::mustChangePassword()) {
    json_response(['success' => false, 'message' => '请先修改默认密码。'], 403);
}

$taskId = (int)($_GET['id'] ?? 0);
if ($taskId <= 0) {
    json_response(['success' => false, 'message' => '参数不完整。']);
}

// IDOR 防护：只能查自己的任务
$stmt = db()->prepare(
    'SELECT id, query, status, total_count, total_pages, current_page, last_new_count,
            error_msg, next_check_at, recheck_minutes, paused, created_at, completed_at
     FROM search_tasks WHERE id = ? AND user_id = ?'
);
$stmt->execute([$taskId, Auth::id()]);
$task = $stmt->fetch();
if (!$task) {
    json_response(['success' => false, 'message' => '任务不存在。'], 404);
}

json_response(['success' => true, 'task' => $task]);
