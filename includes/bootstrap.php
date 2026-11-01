<?php
/**
 * 应用启动文件 - session、安全响应头、自动加载、错误处理
 * 所有入口页面必须 require 本文件
 */

// 严格类型
declare(strict_types=1);

// 基础常量
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDE_PATH', ROOT_PATH . '/includes');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('APP_NAME', '敏感信息监测聚合平台');

// 加载应用配置
$GLOBALS['config'] = require CONFIG_PATH . '/app.php';

// ---- 时区（须与 MySQL 服务器时区一致，否则日期统计会错位）----
date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'Asia/Shanghai');

// ---- 错误处理 ----
$debug = $GLOBALS['config']['app']['debug'];
if ($debug) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// ---- 自动加载 ----
spl_autoload_register(function (string $class) {
    $file = INCLUDE_PATH . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---- 安全响应头 ----
// HTTPS 判定：直连 HTTPS，或经本机反代（SamWaf/nginx）终结 SSL 且转发 X-Forwarded-Proto: https
$isLocalProxy = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($isLocalProxy && strcasecmp((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 'https') === 0);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-XSS-Protection: 1; mode=block');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'");
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ---- Session 安全配置 ----
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
// PHP 官方 2026-10-23 安全公告建议：关闭 session 上传进度，消除相关 RCE 向量（CVE-2026-115560）
@ini_set('session.upload_progress.enabled', '0');
if ($isHttps) {
    ini_set('session.cookie_secure', '1');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- 全局函数 ----

/**
 * 站点主标题：后台设置优先（系统设置→站点外观），未设置时回退 APP_NAME
 * 侧边栏 logo 与浏览器标签页均使用，修改后全站同步
 */
function site_title(): string {
    $t = trim(Settings::get('site_title', ''));
    return $t !== '' ? $t : APP_NAME;
}

/**
 * 站点副标题：可为空（空时侧边栏不显示）
 */
function site_subtitle(): string {
    return trim(Settings::get('site_subtitle', ''));
}

/**
 * 是否已上传网站图标（base64 存于 app_settings，经 favicon.php 输出）
 */
function site_has_favicon(): bool {
    return Settings::get('site_favicon_data', '') !== ''
        && Settings::get('site_favicon_mime', '') !== '';
}

/**
 * 获取 PDO 实例
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = require CONFIG_PATH . '/database.php';
    }
    return $pdo;
}

/**
 * 获取客户端真实 IP（仅信任本机反代时才读取 X-Forwarded-For）
 * 取 XFF 最右侧条目：唯一可信代理（SamWaf/nginx）追加的真实 IP，
 * 即使前面有伪造值也以可信代理写入的最后一个为准，防止伪造绕过限流
 */
function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // 仅当请求来自本机回环（SamWaf/nginx 反代）时才信任代理头
    if (in_array($ip, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $parts = array_filter($parts, fn($v) => $v !== '');
        if (!empty($parts)) {
            $ip = end($parts);
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

/**
 * 安全读取 POST 字符串字段：非 string 输入（如数组型提交）返回空串而非 TypeError
 */
function post_string(string $key, int $max = 512): string {
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return mb_substr(trim($v), 0, $max, 'UTF-8');
}

/**
 * 同 post_string 但不去除首尾空白——密码类字段专用（密码可能合法含首尾空格）
 */
function post_raw(string $key, int $max = 512): string {
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return mb_substr($v, 0, $max, 'UTF-8');
}

/**
 * 重定向
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * JSON 响应
 */
function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 审计日志（敏感字段脱敏）
 */
function audit_log(string $action, array $details = [], ?int $userId = null): void {
    $sensitiveKeys = ['password', 'token', 'secret', 'webhook', 'smtp_pass', 'api_key'];
    foreach ($details as $k => &$v) {
        if (is_string($v) && in_array(strtolower($k), $sensitiveKeys, true)) {
            $v = '***';
        }
    }
    unset($v);
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_id, action, ip, user_agent, details) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            client_ip(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // 审计日志失败不影响主流程
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

// ---- 自动清理与后台任务（响应后执行，不阻塞页面）----
// 原实现在每个请求开头同步执行 Cleaner/TaskWorker/RepoMonitorWorker，
// 一旦有到期任务，页面会被一次最长 90 秒的平台 API 调用阻塞（表现为整站很慢）。
// 现改为：页面响应先完整推送给客户端（fastcgi_finish_request），
// 之后再在本次请求的收尾阶段执行后台任务；认领机制（next_run_at 占位）保证不重复处理。
if (PHP_SAPI === 'cli' || !function_exists('fastcgi_finish_request')) {
    // CLI / 非-FPM 环境：同步执行（测试脚本依赖此行为）
    Cleaner::runIfDue();
    TaskWorker::runIfDue();
    RepoMonitorWorker::runIfDue();
} else {
    register_shutdown_function(static function (): void {
        // 先把页面响应推给浏览器，再释放会话锁，最后执行后台任务
        try {
            fastcgi_finish_request();
        } catch (Throwable $e) {
            return;
        }
        try {
            session_write_close();
        } catch (Throwable $e) {
        }
        Cleaner::runIfDue();
        TaskWorker::runIfDue();
        RepoMonitorWorker::runIfDue();
    });
}
