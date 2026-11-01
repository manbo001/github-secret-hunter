<?php
/**
 * 搜索 API - 创建后台搜索任务（分页抓取 + 定时增量复查由 TaskWorker 完成）
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

// 必须登录
if (!Auth::check()) {
    json_response(['success' => false, 'message' => '未登录'], 401);
}

// 强制改密期间禁止调用业务接口
if (Auth::mustChangePassword()) {
    json_response(['success' => false, 'message' => '请先修改默认密码。'], 403);
}

$userId = Auth::id();

// 仅 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => '方法不允许'], 405);
}

// CSRF 校验（同时支持 header 和表单字段）
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!Security::verifyCsrf($csrf)) {
    json_response(['success' => false, 'message' => 'CSRF 校验失败'], 403);
}

// Rate limit: 每用户每分钟最多 5 次任务创建
if (!Security::rateLimit('search_' . $userId, 5, 60)) {
    json_response(['success' => false, 'message' => '搜索过于频繁，请稍后再试。'], 429);
}

$tokenId = (int)($_POST['token_id'] ?? 0);
$query = Validator::string($_POST['query'] ?? '', 512);
$perPage = (int)($_POST['per_page'] ?? 100);
$notify = (int)($_POST['notify'] ?? 1);

if (!$tokenId || !$query) {
    json_response(['success' => false, 'message' => '参数不完整。']);
}
// GitHub 单页最大 100 条
$perPage = max(10, min(100, $perPage));
$notify = $notify ? 1 : 0;

// 获取用户 Token（IDOR 防护：带 user_id），并取其平台
$stmt = db()->prepare('SELECT id, platform FROM github_tokens WHERE id = ? AND user_id = ?');
$stmt->execute([$tokenId, $userId]);
$tokenRow = $stmt->fetch();
if (!$tokenRow) {
    json_response(['success' => false, 'message' => 'Token 不存在。']);
}
$platform = in_array($tokenRow['platform'] ?? 'github', array_keys(PlatformSearch::PLATFORMS), true)
    ? $tokenRow['platform'] : 'github';

$recheckMinutes = (int)($GLOBALS['config']['github']['recheck_minutes'] ?? 30);

// 创建后台搜索任务（TaskWorker 负责分页抓取与增量复查）
db()->prepare(
    "INSERT INTO search_tasks (user_id, platform, token_id, query, status, per_page, notify, recheck_minutes, current_page, next_run_at)
     VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, 1, NOW())"
)->execute([$userId, $platform, $tokenId, $query, $perPage, $notify, $recheckMinutes]);
$taskId = (int)db()->lastInsertId();

audit_log('search_task_created', ['task_id' => $taskId, 'query' => $query, 'per_page' => $perPage, 'notify' => $notify], $userId);

// 不做内联首页抓取：弱网下首次 API 调用可达数十秒，会让提交请求长时间挂起。
// 前端每 4 秒轮询任务进度，每次轮询都会触发 TaskWorker 推进一页，首页结果最多延迟一个轮询周期。

// 读取任务当前状态返回给前端
$stmt = db()->prepare('SELECT status, total_count, total_pages, current_page, last_new_count, error_msg FROM search_tasks WHERE id = ? AND user_id = ?');
$stmt->execute([$taskId, $userId]);
$task = $stmt->fetch() ?: [];

json_response([
    'success' => true,
    'task_id' => $taskId,
    'message' => '搜索任务已创建，后台将分页抓取全部命中结果。',
    'task' => $task,
]);
