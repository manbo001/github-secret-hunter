<?php
declare(strict_types=1);

/**
 * GitHub Code Search API 客户端（适配新版 REST API）
 * API: https://api.github.com/search/code?q={query}
 */
class GitHubClient
{
    private string $token;
    private string $apiBase;
    private int $rateLimit;
    private int $timeout;
    private bool $textMatch;
    private int $remaining = -1;
    private int $resetAt = 0;

    public function __construct(string $token) {
        $this->token = $token;
        $cfg = $GLOBALS['config']['github'] ?? [];
        $this->apiBase = $cfg['api_base'] ?? (getenv('GITHUB_API_BASE') ?: 'https://api.github.com');
        $this->rateLimit = (int)($cfg['rate_limit'] ?? (getenv('GITHUB_RATE_LIMIT') ?: 30));
        // 总超时：带 text-match 片段的搜索响应可达数百 KB，慢链路下 30s 不够用
        $this->timeout = max(30, (int)($cfg['api_timeout'] ?? 90));
        // text-match 片段：响应体放大数倍，弱网环境可关闭（GITHUB_TEXT_MATCH=0）换取传输成功率
        // 优先级：后台设置（app_settings.github_text_match）> .env 默认值
        $textMatchSetting = Settings::get('github_text_match', '');
        if ($textMatchSetting === '1' || $textMatchSetting === '0') {
            $this->textMatch = $textMatchSetting === '1';
        } else {
            $this->textMatch = ((int)($cfg['text_match'] ?? 1)) === 1;
        }
    }

    /**
     * 执行代码搜索
     * @return array{total_count:int, items:array, error:?string, retry_after:int}
     *         retry_after > 0 表示被 GitHub 限流，应在这么多秒后重试
     */
    public function searchCode(string $query, int $perPage = 30, int $page = 1): array {
        $perPage = min($perPage, 100);
        $page = max(1, $page);
        $fail = fn(string $err, int $retryAfter = 0, bool $authFailed = false) => ['total_count' => 0, 'items' => [], 'error' => $err, 'retry_after' => $retryAfter, 'auth_failed' => $authFailed];

        $url = $this->apiBase . '/search/code?' . http_build_query([
            'q' => $query,
            'per_page' => $perPage,
            'page' => $page,
        ]);

        // SSRF 防护
        if (!Security::isAllowedHost($url)) {
            return $fail('目标域名不在白名单内。');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                // text-match 媒体类型：让响应携带 text_matches（命中片段）。
                // 会显著放大响应体，弱网环境可通过 GITHUB_TEXT_MATCH=0 关闭
                'Accept: ' . ($this->textMatch ? 'application/vnd.github.text-match+json' : 'application/vnd.github+json'),
                'Authorization: Bearer ' . $this->token,
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: SecretMonitor-Aggregator',
            ],
            CURLOPT_HEADER => true,            // 响应包含头，用于解析速率限制
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            // 智能超时：只要速度不低于 1KB/s 就允许继续传（大响应+慢链路），
            // 连续 30 秒低于该速度才判定为真卡死并中断
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 30,
            // 强制 HTTP/1.1：弱网链路上 HTTP/2 流重置（stream not closed cleanly）频发，1.1 更稳
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        foreach ($this->proxyCurlOptions() as $optKey => $optVal) {
            curl_setopt($ch, $optKey, $optVal);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $curlError = curl_error($ch);
            curl_close($ch);
            return $fail('GitHub API 请求失败: ' . $curlError);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // 解析速率限制头
        if (preg_match('/X-RateLimit-Remaining:\s*(\d+)/i', $headers, $m)) {
            $this->remaining = (int)$m[1];
        }
        if (preg_match('/X-RateLimit-Reset:\s*(\d+)/i', $headers, $m)) {
            $this->resetAt = (int)$m[1];
        }

        curl_close($ch);

        // 403：主速率限制耗尽或触发二级限制，均按建议时间退避重试
        if ($httpCode === 403) {
            $retryAfter = 0;
            if ($this->remaining === 0 && $this->resetAt > 0) {
                $retryAfter = max(1, $this->resetAt - time());
            } elseif (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                $retryAfter = max(1, (int)$m[1]);
            } else {
                $retryAfter = 60; // 二级速率限制默认 60 秒
            }
            return $fail("GitHub API 速率限制，请 {$retryAfter} 秒后再试。", $retryAfter);
        }

        // 429：请求过多
        if ($httpCode === 429) {
            $retryAfter = 60;
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                $retryAfter = max(1, (int)$m[1]);
            }
            return $fail("GitHub API 请求过于频繁，请 {$retryAfter} 秒后再试。", $retryAfter);
        }

        if ($httpCode === 401) {
            // auth_failed：Token 失效（触发失效告警推送）
            return $fail('Token 无效或无权限。', 0, true);
        }

        if ($httpCode !== 200) {
            $err = json_decode($body, true);
            return $fail($err['message'] ?? "GitHub API 请求失败 (HTTP {$httpCode})。");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $fail('GitHub API 响应格式错误。');
        }

        return [
            'total_count' => (int)($data['total_count'] ?? 0),
            'items' => $data['items'] ?? [],
            'error' => null,
            'retry_after' => 0,
        ];
    }

    /**
     * 获取仓库最近 N 条提交（新→旧）
     * @return array{commits:array, error:?string, retry_after:int}
     * commits 每项：{sha, message, author, committed_at, html_url}
     */
    public function getRecentCommits(string $repo, int $limit = 10): array {
        $limit = max(1, min(100, $limit));
        $fail = fn(string $err, int $retryAfter = 0, bool $authFailed = false) => ['commits' => [], 'error' => $err, 'retry_after' => $retryAfter, 'auth_failed' => $authFailed];

        $url = $this->apiBase . '/repos/' . $repo . '/commits?per_page=' . $limit;

        if (!Security::isAllowedHost($url)) {
            return $fail('目标域名不在白名单内。');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $this->token,
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: SecretMonitor-Aggregator',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 30,
            // 与 searchCode 一致：弱网强制 HTTP/1.1，避免监控被 h2 流重置反复打断
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        foreach ($this->proxyCurlOptions() as $optKey => $optVal) {
            curl_setopt($ch, $optKey, $optVal);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $curlError = curl_error($ch);
            curl_close($ch);
            return $fail('GitHub API 请求失败: ' . $curlError);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 403 || $httpCode === 429) {
            return $fail("GitHub API 速率限制，请稍后再试。(HTTP {$httpCode})", 60);
        }
        if ($httpCode === 404) {
            return $fail('仓库不存在或 Token 无权限访问。');
        }
        if ($httpCode === 401) {
            // auth_failed：Token 失效（触发失效告警推送）
            return $fail('Token 无效或无权限。', 0, true);
        }
        if ($httpCode !== 200) {
            return $fail("GitHub API 请求失败 (HTTP {$httpCode})。");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return $fail('GitHub API 响应格式错误。');
        }

        $commits = [];
        foreach ($data as $c) {
            if (!is_array($c) || !isset($c['sha'])) { continue; }
            $commits[] = [
                'sha'          => (string)$c['sha'],
                'message'      => mb_substr((string)($c['commit']['message'] ?? ''), 0, 500),
                'author'       => mb_substr((string)($c['commit']['author']['name'] ?? ($c['author']['login'] ?? '')), 0, 100),
                'committed_at' => (string)($c['commit']['author']['date'] ?? ''),
                'html_url'     => (string)($c['html_url'] ?? ''),
            ];
        }
        return ['commits' => $commits, 'error' => null, 'retry_after' => 0];
    }

    /**
     * 读取 GitHub 代理配置（app_settings.github_proxy，JSON）
     * 仅对 GitHub API 生效；Gitee/GitCode 不走代理
     */
    private function proxyCurlOptions(): array {
        $opts = [];
        try {
            $cfg = Settings::get('github_proxy', '');
        } catch (Throwable $e) {
            return $opts;
        }
        if ($cfg === '') {
            return $opts;
        }
        $p = json_decode($cfg, true);
        if (!is_array($p) || ($p['type'] ?? 'none') === 'none' || empty($p['host']) || empty($p['port'])) {
            return $opts;
        }
        $scheme = match ($p['type']) {
            'socks5' => 'socks5h://',   // 域名解析也走代理，避免本地 DNS 污染
            'https'  => 'https://',
            default  => 'http://',
        };
        $opts[CURLOPT_PROXY] = $scheme . $p['host'] . ':' . (int)$p['port'];
        if (!empty($p['user'])) {
            $opts[CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
            $pass = '';
            if (!empty($p['pass_enc'])) {
                try {
                    $pass = Crypto::decrypt($p['pass_enc']);
                } catch (Throwable $e) {
                    $pass = '';
                }
            }
            $opts[CURLOPT_PROXYUSERPWD] = $p['user'] . ':' . $pass;
        }
        return $opts;
    }

    /**
     * 代理连通性自检：无鉴权访问 rate_limit 端点
     * @return array{ok:bool, message:string}
     */
    public function testConnection(): array {
        $url = $this->apiBase . '/rate_limit';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => max(30, $this->timeout),
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SecretMonitor-Aggregator',
        ]);
        foreach ($this->proxyCurlOptions() as $k => $v) {
            curl_setopt($ch, $k, $v);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'message' => '连接失败: ' . $err];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($httpCode === 200) {
            return ['ok' => true, 'message' => '代理链路连通，GitHub API 可达（HTTP 200）'];
        }
        return ['ok' => false, 'message' => "GitHub API 可达但返回 HTTP {$httpCode}（代理链路本身已通）"];
    }

    /**
     * 获取剩余请求次数
     */
    public function getRemaining(): int {
        return $this->remaining;
    }

    /**
     * 预设的搜索规则
     */
    public static function presetQueries(): array {
        return [
            'github_token'    => 'ghp_ extension:env',
            'github_pat'      => 'github_pat_ extension:env',
            'aws_key'         => 'AKIA extension:env',
            'env_password'    => 'password filename:.env',
            'env_secret'      => 'secret filename:.env',
            'private_key'     => '"PRIVATE KEY" extension:pem',
            'api_key_php'     => 'api_key extension:php',
            'config_password' => 'password = filename:config',
            'jwt_secret'      => 'jwt_secret extension:env',
            'db_password'     => 'DB_PASS filename:.env',
        ];
    }
}
