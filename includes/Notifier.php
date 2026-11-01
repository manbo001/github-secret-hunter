<?php
declare(strict_types=1);

/**
 * 通知分发器 - 钉钉 / 企业微信 / 飞书 / 邮件
 * 配置统一以 JSON 存储并 AES 加密
 */
class Notifier
{
    /**
     * 默认消息模板（用户未配置自定义模板时使用）
     * 支持占位符: {title} {content} {query} {count} {time} {link}
     */
    public const DEFAULT_TEMPLATE = "### 🛡️ {title}\n\n**新增命中：{count} 条**\n\n**关键词：** `{query}`\n\n**时间：** {time}\n\n{content}\n{link}";

    /**
     * 仓库监控默认模板（渠道未配置 template_repo 时使用）
     */
    public const DEFAULT_TEMPLATE_REPO = "### 🔔 {title}\n\n**仓库：** {query}\n\n{content}\n\n**时间：** {time}\n{link}";

    /**
     * 渲染模板，替换占位符
     */
    public static function renderTemplate(?string $template, array $data): string {
        $tpl = $template ?? '';
        if (trim($tpl) === '') {
            $tpl = self::DEFAULT_TEMPLATE;
        }
        $replace = [
            '{title}'   => $data['title'] ?? '',
            '{content}' => $data['content'] ?? '',
            '{query}'   => $data['query'] ?? '',
            '{count}'   => (string)($data['count'] ?? 0),
            '{time}'    => $data['time'] ?? date('Y-m-d H:i:s'),
            '{link}'    => (string)($data['link'] ?? ''),
        ];
        return str_replace(array_keys($replace), array_values($replace), $tpl);
    }

    /**
     * 向用户所有启用渠道推送通知并记录日志
     * @param string $kind 模板类别：search=搜索命中告警（默认）；repo=仓库监控更新；raw=系统告警固定格式
     * @return int 成功送达的渠道数（调用方可据此决定是否写入冷却标记）
     */
    public static function pushToAllChannels(int $userId, string $title, string $message, array $data = [], string $kind = 'search'): int {
        $sent = 0;
        try {
            $stmt = db()->prepare('SELECT * FROM notification_channels WHERE user_id = ? AND enabled = 1');
            $stmt->execute([$userId]);
            $channels = $stmt->fetchAll();
            if (empty($channels)) {
                return 0;
            }
            foreach ($channels as $ch) {
                try {
                    $config = json_decode(Crypto::decrypt($ch['config_encrypted']), true);
                    $res = self::send(
                        $ch['channel_type'],
                        $config,
                        $title,
                        $message,
                        $data,
                        $kind
                    );
                    db()->prepare('INSERT INTO notification_logs (channel_id, result_id, status, response) VALUES (?, NULL, ?, ?)')
                        ->execute([$ch['id'], $res['success'] ? 'success' : 'failed', $res['response']]);
                    if ($res['success']) {
                        $sent++;
                    }
                } catch (Throwable $e) {
                    // 单个渠道失败不影响其他，但保留痕迹便于排查
                    error_log('Notifier channel ' . $ch['id'] . ' failed: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('Notifier::pushToAllChannels failed: ' . $e->getMessage());
        }
        return $sent;
    }

    /**
     * Token 失效告警（固定模板，不支持自定义；同一令牌 6 小时内只推送一次）
     * 由搜索任务 / 仓库监控在收到 401（auth_failed）时触发。
     * 冷却标记用 CAS 写入（WHERE 校验旧时间戳），并发 401 不会重复推送；
     * 标记在成功送达后写入，渠道全失败时下轮 401 仍会重试。
     */
    public static function notifyTokenInvalid(int $tokenId, int $userId, string $platform, string $context = ''): void {
        try {
            // 无任何启用渠道时直接返回：推送不可能成功，避免占用/释放冷却窗口的无谓循环
            $chk = db()->prepare('SELECT COUNT(*) FROM notification_channels WHERE user_id = ? AND enabled = 1');
            $chk->execute([$userId]);
            if ((int)$chk->fetchColumn() === 0) {
                return;
            }
            $st = db()->prepare('SELECT label, token_hint FROM github_tokens WHERE id = ?');
            $st->execute([$tokenId]);
            $row = $st->fetch();
            if (!$row) {
                return;
            }
            // 原子冷却认领：仅当 6 小时内未推送过时才能占用窗口（并发只有一个能成功）
            $claim = db()->prepare(
                'UPDATE github_tokens SET invalid_notified_at = NOW()
                 WHERE id = ? AND (invalid_notified_at IS NULL OR invalid_notified_at < DATE_SUB(NOW(), INTERVAL 6 HOUR))'
            );
            $claim->execute([$tokenId]);
            if ($claim->rowCount() !== 1) {
                return; // 冷却期内，已被其他请求推送
            }

            $title = PlatformSearch::label($platform) . ' Token 失效告警';
            $now = date('Y-m-d H:i:s');
            $message = "平台：{$title}\n"
                . "令牌：{$row['label']}（••••{$row['token_hint']}）\n"
                . "状态：Token 已失效或无权限（API 返回 401）\n"
                . "发现场景：{$context}\n"
                . "影响：使用该令牌的搜索任务与仓库监控将无法执行\n"
                . "处理：请到「令牌设置」页更换有效令牌；仓库监控可在监控列表中直接换绑\n"
                . "时间：{$now}";

            $sent = self::pushToAllChannels($userId, $title, $message, [
                'title' => $title,
                'time'  => $now,
            ], 'raw');
            if ($sent === 0) {
                // 全部渠道失败：释放冷却窗口，下一轮 401 可重试推送；失败本身留痕在 PHP 错误日志
                db()->prepare('UPDATE github_tokens SET invalid_notified_at = NULL WHERE id = ?')
                    ->execute([$tokenId]);
                return;
            }
            audit_log('token_invalid_notified', ['token_id' => $tokenId, 'platform' => $platform, 'context' => $context, 'sent' => $sent], $userId);
        } catch (Throwable $e) {
            error_log('Notifier::notifyTokenInvalid failed: ' . $e->getMessage());
        }
    }

    /**
     * 发送通知
     * @param string $kind 模板类别：search=搜索命中告警（默认）；repo=仓库监控更新
     *              repo 类别的模板回退顺序：渠道 template_repo → 渠道 template → DEFAULT_TEMPLATE_REPO
     * @param array $config 渠道配置（已解密的 JSON 数组，可包含 template / template_repo 字段）
     * @param string $title 标题
     * @param string $message 正文内容（将替换模板中的 {content}）
     * @param array $data 模板数据（query, count, time 等）
     * @return array{success:bool, response:?string}
     */
    public static function send(string $channelType, array $config, string $title, string $message, array $data = [], string $kind = 'search'): array {
        // 模板选择
        $searchTpl = trim((string)($config['template'] ?? ''));
        $template = $searchTpl;
        if ($kind === 'raw') {
            // 系统级告警（Token 失效 / 服务器资源告警）：固定格式，不使用用户自定义模板
            $rendered = "### {$title}\n\n{$message}";
        } else {
            if ($kind === 'repo') {
                $repoTpl = trim((string)($config['template_repo'] ?? ''));
                if ($repoTpl !== '') {
                    $template = $repoTpl;
                } elseif ($searchTpl === '') {
                    $template = self::DEFAULT_TEMPLATE_REPO;
                }
            }
            $renderData = array_merge([
                'title'   => $title,
                'content' => $message,
                'query'   => '',
                'count'   => 0,
                'time'    => date('Y-m-d H:i:s'),
            ], $data);
            $rendered = self::renderTemplate($template, $renderData);
        }

        return match ($channelType) {
            'dingtalk' => self::sendDingTalk($config, $title, $rendered),
            'wechat'   => self::sendWeChat($config, $title, $rendered),
            'feishu'   => self::sendFeishu($config, $title, $rendered),
            'email'    => self::sendEmail($config, $title, $rendered),
            default    => ['success' => false, 'response' => '未知渠道类型'],
        };
    }

    // ---------------- 钉钉 ----------------
    private static function sendDingTalk(array $config, string $title, string $message): array {
        $webhook = $config['webhook'] ?? '';
        $secret = $config['secret'] ?? '';
        if (!Validator::url($webhook) || !Security::isAllowedHost($webhook)) {
            return ['success' => false, 'response' => 'Webhook URL 不合法或不在白名单'];
        }
        $url = $webhook;
        if (!empty($secret)) {
            $timestamp = (int)(microtime(true) * 1000);
            $stringToSign = $timestamp . "\n" . $secret;
            $sign = urlencode(base64_encode(hash_hmac('sha256', $stringToSign, $secret, true)));
            $url .= (str_contains($url, '?') ? '&' : '?') . "timestamp={$timestamp}&sign={$sign}";
        }
        $payload = json_encode([
            'msgtype' => 'markdown',
            'markdown' => ['title' => $title, 'text' => $message],
        ], JSON_UNESCAPED_UNICODE);
        return self::httpPostJson($url, $payload);
    }

    // ---------------- 企业微信 ----------------
    private static function sendWeChat(array $config, string $title, string $message): array {
        $webhook = $config['webhook'] ?? '';
        if (!Validator::url($webhook) || !Security::isAllowedHost($webhook)) {
            return ['success' => false, 'response' => 'Webhook URL 不合法或不在白名单'];
        }
        $payload = json_encode([
            'msgtype' => 'markdown',
            'markdown' => ['content' => $message],
        ], JSON_UNESCAPED_UNICODE);
        return self::httpPostJson($webhook, $payload);
    }

    // ---------------- 飞书 ----------------
    private static function sendFeishu(array $config, string $title, string $message): array {
        $webhook = $config['webhook'] ?? '';
        $secret = $config['secret'] ?? '';
        if (!Validator::url($webhook) || !Security::isAllowedHost($webhook)) {
            return ['success' => false, 'response' => 'Webhook URL 不合法或不在白名单'];
        }
        $payloadData = [
            'msg_type' => 'text',
            'content' => ['text' => $message],
        ];
        if (!empty($secret)) {
            // 飞书签名算法：HMAC-SHA256，key = "时间戳\n密钥"，msg = 空字符串
            $timestamp = (int)time();
            $sign = base64_encode(hash_hmac('sha256', '', $timestamp . "\n" . $secret, true));
            $payloadData['timestamp'] = (string)$timestamp;
            $payloadData['sign'] = $sign;
        }
        return self::httpPostJson($webhook, json_encode($payloadData, JSON_UNESCAPED_UNICODE));
    }

    // ---------------- 邮件（内置 SMTP，无外部依赖）----------------
    private static function sendEmail(array $config, string $title, string $message): array {
        $host = str_replace(["\r", "\n"], '', (string)($config['smtp_host'] ?? ''));
        $port = (int)($config['smtp_port'] ?? 25);
        $user = str_replace(["\r", "\n"], '', (string)($config['smtp_user'] ?? ''));
        $pass = (string)($config['smtp_pass'] ?? '');
        $from = str_replace(["\r", "\n"], '', (string)($config['from_email'] ?? ''));
        $to = str_replace(["\r", "\n"], '', (string)($config['to_email'] ?? ''));
        $secure = (string)($config['smtp_secure'] ?? 'tls'); // tls / ssl / none

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'response' => '收件邮箱不合法'];
        }
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'response' => '发件邮箱不合法'];
        }
        if ($port < 1 || $port > 65535) {
            return ['success' => false, 'response' => 'SMTP 端口不合法'];
        }
        // SSRF 防护：主机必须在白名单内，且解析结果不得为内网地址
        if (!Security::isAllowedDomain($host)) {
            return ['success' => false, 'response' => 'SMTP 主机不在出站白名单内'];
        }
        $ip = gethostbyname($host);
        if (Security::isInternalIp($ip)) {
            return ['success' => false, 'response' => 'SMTP 主机解析到内网地址，已拒绝连接'];
        }
        if ($from === '' && $user !== '') {
            // 未配置发件邮箱时回退到 SMTP 用户名，但必须是合法邮箱
            if (!filter_var($user, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'response' => '发件邮箱不合法'];
            }
            $from = $user;
        }

        $log = '';
        try {
            $fp = self::smtpConnect($host, $port, $secure);
            self::smtpCommand($fp, 'EHLO github-secret-hunter', $log);
            if ($secure === 'tls') {
                self::smtpCommand($fp, 'STARTTLS', $log);
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                self::smtpCommand($fp, 'EHLO github-secret-hunter', $log);
            }
            if (!empty($user)) {
                $authResp = self::smtpCommand($fp, 'AUTH LOGIN', $log);
                if (!str_starts_with(trim($authResp), '334')) {
                    throw new RuntimeException('SMTP 认证失败: ' . trim($authResp));
                }
                $userResp = self::smtpCommand($fp, base64_encode($user), $log, true);
                if (!str_starts_with(trim($userResp), '334')) {
                    throw new RuntimeException('SMTP 用户名校验失败: ' . trim($userResp));
                }
                $passResp = self::smtpCommand($fp, base64_encode($pass), $log, true);
                if (!str_starts_with(trim($passResp), '235')) {
                    throw new RuntimeException('SMTP 密码校验失败: ' . trim($passResp));
                }
            }
            self::smtpCommand($fp, "MAIL FROM:<{$from}>", $log);
            self::smtpCommand($fp, "RCPT TO:<{$to}>", $log);
            $dataResp = self::smtpCommand($fp, 'DATA', $log);
            if (!str_starts_with(trim($dataResp), '354')) {
                throw new RuntimeException('DATA 命令失败: ' . trim($dataResp));
            }

            $body = "Subject: =?UTF-8?B?" . base64_encode($title) . "?=\r\n";
            $body .= "From: {$from}\r\n";
            $body .= "To: {$to}\r\n";
            $body .= "MIME-Version: 1.0\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($message));

            // 写入邮件正文并确保刷新
            self::smtpWrite($fp, $body . "\r\n.\r\n");
            $resp = self::smtpRead($fp);
            $log .= "S: {$resp}";
            @self::smtpWrite($fp, "QUIT\r\n");
            fclose($fp);

            if (str_starts_with(trim($resp), '250')) {
                return ['success' => true, 'response' => '邮件发送成功'];
            }
            $detail = trim($resp);
            if ($detail === '') {
                $detail = '服务器无响应（可能网络超时或被防火墙拦截）';
            }
            return ['success' => false, 'response' => 'SMTP 响应异常: ' . $detail];
        } catch (Throwable $e) {
            $detail = $e->getMessage();
            if (!empty($log)) {
                $detail .= ' | 会话日志: ' . str_replace(["\r", "\n"], ' ', $log);
            }
            return ['success' => false, 'response' => '邮件发送失败: ' . $detail];
        }
    }

    // ---------------- SMTP 辅助 ----------------
    private static function smtpConnect(string $host, int $port, string $secure) {
        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15);
        if (!$fp) {
            throw new RuntimeException("SMTP 连接失败: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, 15);
        stream_set_blocking($fp, true);
        self::smtpRead($fp); // 读取欢迎信息
        return $fp;
    }

    /**
     * 确保全部写入并刷新缓冲区
     */
    private static function smtpWrite($fp, string $data): void {
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = fwrite($fp, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('SMTP 写入失败');
            }
            $written += $n;
        }
        fflush($fp);
    }

    private static function smtpCommand($fp, string $cmd, string &$log = '', bool $redact = false): string {
        self::smtpWrite($fp, $cmd . "\r\n");
        $resp = self::smtpRead($fp);
        // $redact = true 时命令内容是 base64 的用户名/密码，绝不写入日志（会随失败详情落库）
        $log .= $redact ? "C: [AUTH 凭据已隐藏]\nS: " . trim($resp) . "\n" : "C: {$cmd}\nS: " . trim($resp) . "\n";
        return $resp;
    }

    private static function smtpRead($fp): string {
        $response = '';
        $start = time();
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                if (time() - $start > 15) {
                    break;
                }
                continue;
            }
            $response .= $line;
            // SMTP 最终响应行格式: "250 " （状态码后接空格），续行用 "250-"
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    // ---------------- HTTP POST ----------------
    private static function httpPostJson(string $url, string $payload): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'response' => '请求失败: ' . $error];
        }
        // 各平台响应格式：钉钉/企微 {"errcode":0}；飞书新版 {"code":0}；飞书旧版 {"StatusCode":0}
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            if (array_key_exists('errcode', $decoded)) {
                return ['success' => (int)$decoded['errcode'] === 0, 'response' => $response];
            }
            if (array_key_exists('code', $decoded)) {
                return ['success' => (int)$decoded['code'] === 0, 'response' => $response];
            }
            if (array_key_exists('StatusCode', $decoded)) {
                return ['success' => (int)$decoded['StatusCode'] === 0, 'response' => $response];
            }
        }
        return ['success' => $httpCode >= 200 && $httpCode < 300, 'response' => $response];
    }
}
