<?php
declare(strict_types=1);

/**
 * 网站图标输出端点（公开访问：登录页浏览器也会请求）
 * 图标数据由系统设置→站点外观上传，base64 存于 app_settings
 * MIME 白名单在上传时与服务时双重校验，杜绝 SVG（可携带脚本）等风险类型
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$allowed = [
    'image/png',
    'image/jpeg',
    'image/x-icon',
    'image/vnd.microsoft.icon',
    'image/webp',
];

$mime = Settings::get('site_favicon_mime', '');
$data = Settings::get('site_favicon_data', '');

if ($mime === '' || $data === '' || !in_array($mime, $allowed, true)) {
    // 图标已删除/不存在：404 且禁止缓存，让浏览器尽快回落到默认图标
    http_response_code(404);
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not Found');
}

$binary = base64_decode($data, true);
if ($binary === false || $binary === '') {
    http_response_code(404);
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not Found');
}

$etag = '"' . md5($binary) . '"';
header('Content-Type: ' . $mime);
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
// URL 已带内容版本号（favicon.php?v=hash），可安全长缓存；变更时 URL 变化自然失效
header('Cache-Control: max-age=86400');

// 命中协商缓存：图标未变化时返回 304，避免重复传输
if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

echo $binary;
