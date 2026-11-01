<?php
declare(strict_types=1);

/**
 * 多平台搜索聚合适配器
 *
 * - github  : 代码搜索（GitHubClient，text-match 片段）
 * - gitee   : Gitee OpenAPI v5 —— 无公开代码内容搜索，聚合为「仓库/线索搜索」
 *             （按关键词匹配仓库名/描述，命中疑似含敏感配置的仓库，供人工核查）
 * - gitcode : GitCode OpenAPI（v5 兼容风格），同 gitee
 *
 * 统一返回结构：{total_count:int, items:array, error:?string, retry_after:int}
 * items 统一归一化：repository.full_name / html_url / text_matches(片段) / score
 */
class PlatformSearch
{
    /** 平台定义：键 => [标签, API 基址] */
    public const PLATFORMS = [
        'github'  => ['GitHub',  'https://api.github.com'],
        'gitee'   => ['Gitee',   'https://gitee.com/api/v5'],
        // 注意：GitCode 的 API 基址是 api.gitcode.com；gitcode.com/api/v5 只是薄兼容层，
        // 仅实现了部分路由（搜索可用，commits 等 404）
        'gitcode' => ['GitCode', 'https://api.gitcode.com/api/v5'],
    ];

    /** 各平台搜索单页上限（实测：GitCode per_page>50 返回 400） */
    public const PER_PAGE_CAPS = [
        'github'  => 100,
        'gitee'   => 100,
        'gitcode' => 50,
    ];

    /**
     * 各平台搜索单页上限
     */
    public static function perPageCap(string $platform): int {
        return self::PER_PAGE_CAPS[$platform] ?? 100;
    }

    public static function label(string $platform): string {
        return self::PLATFORMS[$platform][0] ?? $platform;
    }

    /**
     * 统一搜索入口
     */
    public static function search(string $platform, string $token, string $query, int $perPage, int $page): array {
        return match ($platform) {
            'gitee', 'gitcode' => self::v5Search(self::PLATFORMS[$platform][1], $token, $query, $perPage, $page, $platform),
            default => (new GitHubClient($token))->searchCode($query, $perPage, $page),
        };
    }

    /**
     * 统一最近 N 条提交（新→旧，仓库监控用）
     * 返回：{commits:array, error:?string, retry_after:int}
     * commits 每项：{sha, message, author, committed_at, html_url}
     */
    public static function recentCommits(string $platform, string $token, string $repo, int $limit = 10): array {
        return match ($platform) {
            'gitee', 'gitcode' => self::v5RecentCommits(self::PLATFORMS[$platform][1], $token, $repo, $limit, $platform),
            default => (new GitHubClient($token))->getRecentCommits($repo, $limit),
        };
    }

    /**
     * v5 风格最近 N 条提交（gitee/gitcode 通用）
     * GitCode 特殊性：owner 命名空间可含斜杠（如 gh_mirrors/na/xxx 三段路径），
     * 需将 owner 部分 URL 编码并以 PATH_AS_IS 发送，否则路由 404
     */
    private static function v5RecentCommits(string $base, string $token, string $repo, int $limit, string $platform): array {
        $fail = fn(string $err, int $retryAfter = 0) => ['commits' => [], 'error' => $err, 'retry_after' => $retryAfter];

        $repoPath = $repo;
        if ($platform === 'gitcode' && str_contains($repo, '/')) {
            $pos = strrpos($repo, '/');
            $ownerPath = substr($repo, 0, $pos);
            $repoName = substr($repo, $pos + 1);
            $repoPath = rawurlencode($ownerPath) . '/' . rawurlencode($repoName);
        }

        $url = $base . '/repos/' . $repoPath . '/commits?per_page=' . max(1, min(100, $limit));
        $extraHeaders = [];
        if ($token !== '') {
            if ($platform === 'gitcode') {
                $extraHeaders = ['PRIVATE-TOKEN: ' . $token];
            } else {
                $url .= '&access_token=' . urlencode($token);
            }
        }

        if (!Security::isAllowedHost($url)) {
            return $fail('目标域名不在白名单内。');
        }

        $resp = self::httpGet($url, $base, $extraHeaders, true);
        if ($resp['error'] !== null) {
            return $fail($resp['error'], $resp['retry_after']);
        }

        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            return $fail('平台 API 响应格式错误。');
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
     * v5 风格仓库搜索（gitee/gitcode 通用）
     * 鉴权差异：gitee 用 access_token 查询参数；gitcode 用 PRIVATE-TOKEN 请求头
     */
    private static function v5Search(string $base, string $token, string $query, int $perPage, int $page, string $platform): array {
        $fail = fn(string $err, int $retryAfter = 0) => ['total_count' => 0, 'items' => [], 'error' => $err, 'retry_after' => $retryAfter];

        $url = $base . '/search/repositories?' . http_build_query([
            'q'        => $query,
            'per_page' => max(1, min(self::perPageCap($platform), $perPage)),
            'page'     => max(1, $page),
        ]);
        $extraHeaders = [];
        if ($token !== '') {
            if ($platform === 'gitcode') {
                $extraHeaders[] = 'PRIVATE-TOKEN: ' . $token;
            } else {
                $url .= '&access_token=' . urlencode($token);
            }
        }

        if (!Security::isAllowedHost($url)) {
            return $fail('目标域名不在白名单内。');
        }

        $resp = self::httpGet($url, $base, $extraHeaders);
        if ($resp['error'] !== null) {
            return $fail($resp['error'], $resp['retry_after']);
        }

        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            return $fail('平台 API 响应格式错误。');
        }

        // v5 搜索响应：数组（总数在响应头 Total-Count，兼容 items 直接为数组或 {data:[]} 包装）
        $list = $data;
        if (isset($data['data']) && is_array($data['data'])) {
            $list = $data['data'];
        }
        $totalCount = (int)($resp['headers']['total-count'] ?? count($list));

        $items = [];
        foreach ($list as $r) {
            if (!is_array($r)) {
                continue;
            }
            $fullName = (string)($r['full_name'] ?? ($r['path_with_namespace'] ?? ''));
            if ($fullName === '') {
                continue;
            }
            $items[] = [
                'repository'   => ['full_name' => $fullName],
                'path'         => '',
                'html_url'     => (string)($r['html_url'] ?? $r['web_url'] ?? ($r['http_url_to_repo'] ?? '')),
                'text_matches' => [['fragment' => (string)($r['description'] ?? '')]],
                'score'        => null,
            ];
        }

        return ['total_count' => $totalCount, 'items' => $items, 'error' => null, 'retry_after' => 0];
    }

    /**
     * 通用 GET（弱网加固与 GitHubClient 一致：90s 总超时 + 低速判定 + HTTP/1.1）
     */
    private static function httpGet(string $url, string $base, array $extraHeaders = [], bool $pathAsIs = false): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => max(30, (int)($GLOBALS['config']['github']['api_timeout'] ?? 90)),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SecretMonitor-Aggregator',
        ]);
        // GitCode 的 owner 命名空间含 %2F 编码斜杠，需禁止 curl 做 URL 规范化
        if ($pathAsIs) {
            curl_setopt($ch, CURLOPT_PATH_AS_IS, true);
        }
        if ($extraHeaders) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['error' => '平台 API 请求失败: ' . $err, 'retry_after' => 0, 'body' => '', 'headers' => []];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr((string)$response, 0, $headerSize);
        $body = substr((string)$response, $headerSize);
        curl_close($ch);

        // 解析响应头为小写键数组
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        if ($httpCode === 401) {
            return ['error' => 'Token 无效或无权限。', 'retry_after' => 0, 'body' => $body, 'headers' => $headers];
        }
        if ($httpCode === 404) {
            return ['error' => '仓库不存在或无权限访问。', 'retry_after' => 0, 'body' => $body, 'headers' => $headers];
        }
        if ($httpCode === 403 || $httpCode === 429) {
            return ['error' => "平台 API 速率限制 (HTTP {$httpCode})，请稍后再试。", 'retry_after' => 60, 'body' => $body, 'headers' => $headers];
        }
        if ($httpCode !== 200) {
            // 平台 400 类错误通常在响应体里带 error_message，透出便于排错
            $detail = '';
            $errData = json_decode($body, true);
            if (is_array($errData)) {
                $detail = (string)($errData['error_message'] ?? ($errData['messages'][0] ?? ''));
                $detail = mb_substr($detail, 0, 120);
            }
            return ['error' => "平台 API 请求失败 (HTTP {$httpCode})" . ($detail !== '' ? ": {$detail}" : '。'), 'retry_after' => 0, 'body' => $body, 'headers' => $headers];
        }

        return ['error' => null, 'retry_after' => 0, 'body' => $body, 'headers' => $headers];
    }
}
