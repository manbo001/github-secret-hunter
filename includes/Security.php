<?php
declare(strict_types=1);

/**
 * 安全工具类 - CSRF、XSS、RateLimit、SSRF 白名单
 */
class Security
{
    /**
     * 生成 CSRF Token（HMAC-SHA256，session 绑定）
     */
    public static function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * 校验 CSRF Token
     */
    public static function verifyCsrf(?string $token): bool {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * XSS 转义输出
     */
    public static function e(?string $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 获取 SSRF 白名单（兼容 $GLOBALS['config'] 异常的情况）
     */
    private static function getAllowedHosts(): array {
        $allowed = $GLOBALS['config']['security']['ssrf_allowed_hosts'] ?? null;
        if (!is_array($allowed) || empty($allowed)) {
            $raw = getenv('SSRF_ALLOWED_HOSTS') ?: ($_ENV['SSRF_ALLOWED_HOSTS'] ?? 'api.github.com');
            $allowed = array_map('trim', explode(',', $raw));
        }
        return $allowed;
    }

    /**
     * 校验 URL 是否允许出站访问 - 仅 http/https 且域名在白名单内
     */
    public static function isAllowedHost(string $url): bool {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === false || $host === null) {
            return false;
        }
        return self::isAllowedDomain(strtolower($host));
    }

    /**
     * 校验裸域名是否在白名单内（用于无 URL 形式的目标，如 SMTP 主机）
     * 所有解析到的 IP 都不得为内网地址
     */
    public static function isAllowedDomain(string $host): bool {
        $host = strtolower(trim($host));
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return false;
        }
        foreach (self::getAllowedHosts() as $allowedHost) {
            $allowedHost = strtolower(trim($allowedHost));
            if ($allowedHost !== '' && ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost))) {
                // 域名的全部解析结果都不得落入内网段
                $ips = gethostbynamel($host);
                if (!$ips) {
                    return false;
                }
                foreach ($ips as $ip) {
                    if (self::isInternalIp($ip)) {
                        return false;
                    }
                }
                return true;
            }
        }
        return false;
    }

    /**
     * 判断是否为内网 IP
     */
    public static function isInternalIp(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true; // 无法解析视为危险
        }
        $privateRanges = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '0.0.0.0/8',
            '100.64.0.0/10',   // 运营商级 NAT 段：覆盖阿里云元数据服务 100.100.100.200
            '::1/128',
            'fc00::/7',
            'fe80::/10',
        ];
        foreach ($privateRanges as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInRange(string $ip, string $range): bool {
        if (str_contains($range, '/')) {
            [$subnet, $mask] = explode('/', $range, 2);
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $maskLong = -1 << (32 - (int)$mask);
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }
        return $ip === $range;
    }

    /**
     * 简易 Rate Limiter（基于文件，适合单机）
     * 使用 flock 保证读-改-写原子性，防止并发请求绕过限流
     * 返回 true 表示允许请求，false 表示被限流
     */
    public static function rateLimit(string $key, int $maxRequests, int $windowSeconds): bool {
        $dir = sys_get_temp_dir() . '/gsh_rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . md5($key) . '.json';
        $now = time();
        $allowed = false;

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return true; // 限流文件不可用时放行，避免影响可用性
        }
        try {
            flock($fp, LOCK_EX);
            $data = json_decode((string)stream_get_contents($fp), true);
            if (!is_array($data)) {
                $data = [];
            }
            // 清理过期记录
            $data = array_values(array_filter($data, fn($t) => is_int($t) && ($now - $t) < $windowSeconds));
            if (count($data) < $maxRequests) {
                $data[] = $now;
                $allowed = true;
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
        } finally {
            @fclose($fp);
        }
        return $allowed;
    }
}
