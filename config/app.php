<?php
/**
 * 应用配置 - 从环境变量 / .env 加载，绝不硬编码密钥
 */

// 查找 .env 文件（项目根目录）
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // 跳过注释
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=') && !str_starts_with($line, 'export ')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // 去除首尾引号
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            // 始终以 .env 为准（避免 PHP-FPM worker 复用时旧值残留）
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

/**
 * 读取环境变量
 */
if (!function_exists('env')) {
function env(string $key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return $value;
}
}

return [
    'app' => [
        'env'            => env('APP_ENV', 'production'),
        'debug'          => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
        'encryption_key' => env('APP_ENCRYPTION_KEY', ''),
        // PHP 时区需与 MySQL 服务器时区一致，否则日期类统计（趋势图等）会因键错位而查空
        'timezone'       => env('APP_TIMEZONE', 'Asia/Shanghai'),
        // 对外访问地址（用于通知里的结果页链接），如 http://192.168.2.24 ；留空则通知不带链接
        'base_url'       => rtrim((string)env('APP_URL', ''), '/'),
    ],
    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 3306),
        'name' => env('DB_NAME', 'github_secret_hunter'),
        'user' => env('DB_USER', ''),
        'pass' => env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'login_lock_minutes' => (int) env('LOGIN_LOCK_MINUTES', 15),
        'session_lifetime'   => (int) env('SESSION_LIFETIME', 28800),
        // SSRF 白名单默认覆盖三个平台基址（.env 可扩展通知 webhook / SMTP 域名）
        'ssrf_allowed_hosts' => array_map('trim', explode(',', env('SSRF_ALLOWED_HOSTS', 'api.github.com,gitee.com,api.gitcode.com'))),
    ],
    'github' => [
        'rate_limit' => (int) env('GITHUB_RATE_LIMIT', 30),
        'api_base'   => 'https://api.github.com',
        // 单次 GitHub API 请求总超时（秒）。text-match 搜索响应较大，慢链路建议 ≥ 90
        'api_timeout' => max(30, (int) env('GITHUB_API_TIMEOUT', 90)),
        // 命中片段（text-match）：响应体放大数倍。弱网/链路不稳时设 0 关闭，换取传输成功率
        'text_match' => (int) env('GITHUB_TEXT_MATCH', 1) === 1,
        // 后台翻页请求的最小间隔（秒）。GitHub Search API 有限速，间隔过小会触发 403/429
        'search_interval' => max(2, (int) env('GITHUB_SEARCH_INTERVAL', 7)),
        // 任务完成后自动复查新增的间隔（分钟），0 = 关闭复查
        'recheck_minutes' => max(0, (int) env('RECHECK_INTERVAL_MINUTES', 30)),
    ],
];
