<?php
/**
 * 服务器资源状态 API（GET，只读，总览每 5 秒轮询）
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

if (!Auth::check()) {
    json_response(['success' => false, 'message' => '未登录'], 401);
}
if (Auth::mustChangePassword()) {
    json_response(['success' => false, 'message' => '请先修改默认密码。'], 403);
}

json_response(['success' => true, 'server' => ServerMetrics::snapshot()]);
