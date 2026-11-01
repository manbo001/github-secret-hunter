<?php
declare(strict_types=1);

/**
 * 仓库监控后台执行器（请求触发式，无需系统 Cron）
 *
 * - bootstrap 每次请求调用 runIfDue()，每次最多检查一个到期的监控
 * - 首次检查只记录基线（当前最新提交），不推送，避免误报
 * - 之后每次检查：sha 变化 → 记入 repo_updates（唯一索引去重）→ 按配置推送通知
 * - 检查失败：重试至多 5 次后进入 error 状态，仍按周期继续尝试（不停止监控）
 */
class RepoMonitorWorker
{
    private const MAX_RETRIES = 5;

    public static function runIfDue(): void {
        try {
            $monitor = self::claimDueMonitor();
            if ($monitor !== null) {
                self::processMonitor($monitor);
            }
        } catch (Throwable $e) {
            error_log('RepoMonitorWorker::runIfDue failed: ' . $e->getMessage());
        }
    }

    /**
     * 原子认领：先把 next_check_at 推进占住，防止并发重复检查
     */
    private static function claimDueMonitor(): ?array {
        $stmt = db()->prepare(
            'SELECT * FROM repo_monitors
             WHERE paused = 0 AND next_check_at IS NOT NULL AND next_check_at <= NOW()
             ORDER BY next_check_at ASC LIMIT 1'
        );
        $stmt->execute();
        $monitor = $stmt->fetch();
        if (!$monitor) {
            return null;
        }
        $claim = db()->prepare(
            'UPDATE repo_monitors SET next_check_at = DATE_ADD(NOW(), INTERVAL 300 SECOND)
             WHERE id = ? AND paused = 0 AND next_check_at <= NOW()'
        );
        $claim->execute([$monitor['id']]);
        if ($claim->rowCount() !== 1) {
            return null;
        }
        return $monitor;
    }

    private static function processMonitor(array $monitor): void {
        $monitorId = (int)$monitor['id'];

        // 取回绑定的 Token
        $stmt = db()->prepare('SELECT token_encrypted FROM github_tokens WHERE id = ? AND user_id = ?');
        $stmt->execute([$monitor['token_id'], $monitor['user_id']]);
        $tokenRow = $stmt->fetch();
        if (!$tokenRow) {
            db()->prepare("UPDATE repo_monitors SET last_status = 'error', last_error = '绑定的 GitHub Token 已被删除。', next_check_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?")
                ->execute([(int)$monitor['check_interval_minutes'], $monitorId]);
            return;
        }
        try {
            $plainToken = Crypto::decrypt($tokenRow['token_encrypted']);
        } catch (Throwable $e) {
            db()->prepare("UPDATE repo_monitors SET last_status = 'error', last_error = 'Token 解密失败。', next_check_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?")
                ->execute([(int)$monitor['check_interval_minutes'], $monitorId]);
            return;
        }

        // 平台 API 速率保护：按令牌独立配额（与搜索任务同规格），超限本轮顺延
        if (!Security::rateLimit('platform_search_api_t' . (int)$monitor['token_id'], 25, 60)) {
            db()->prepare('UPDATE repo_monitors SET next_check_at = DATE_ADD(NOW(), INTERVAL 5 SECOND) WHERE id = ?')
                ->execute([$monitorId]);
            return;
        }

        $result = PlatformSearch::recentCommits((string)($monitor['platform'] ?? 'github'), $plainToken, (string)$monitor['repo'], 10);

        // ---- 失败处理 ----
        if ($result['error']) {
            $retryAfter = (int)($result['retry_after'] ?? 0);
            if ($retryAfter > 0) {
                $wait = max($retryAfter, 60);
                db()->prepare("UPDATE repo_monitors SET last_status = 'error', last_error = ?, next_check_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
                    ->execute([mb_substr($result['error'], 0, 500), $wait, $monitorId]);
            } else {
                $retries = (int)$monitor['retries'] + 1;
                // 瞬时故障 60 秒后重试；达到重试上限则等一个检查周期再试
                $nextDelaySec = $retries >= self::MAX_RETRIES ? (int)$monitor['check_interval_minutes'] * 60 : 60;
                db()->prepare("UPDATE repo_monitors SET retries = ?, last_status = 'error', last_error = ?, next_check_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
                    ->execute([$retries, mb_substr($result['error'], 0, 500), $nextDelaySec, $monitorId]);
            }
            return;
        }

        // ---- 成功：基线或增量判定（本次拉取的全部提交逐条入库去重，可捕获两次检查间的多次提交） ----
        $commits = $result['commits'];
        $newest = $commits[0] ?? null;

        $isBaseline = ($monitor['last_sha'] === null);
        $newItems = [];
        foreach ($commits as $c) {
            $ins = db()->prepare(
                'INSERT IGNORE INTO repo_updates (monitor_id, sha, author, message, html_url, committed_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $monitorId, $c['sha'], $c['author'], $c['message'],
                $c['html_url'], self::toDatetime($c['committed_at']),
            ]);
            if ($ins->rowCount() === 1) {
                $newItems[] = $c;
            }
        }
        $newCount = count($newItems);
        $newestSha = $newest !== null ? $newest['sha'] : null;

        // 增量有新提交时推送（基线不推送）
        if (!$isBaseline && $newCount > 0 && (int)$monitor['notify'] === 1) {
            self::notifyUpdate($monitor, $newItems);
        }

        audit_log('repo_monitor_checked', [
            'monitor_id' => $monitorId,
            'repo' => $monitor['repo'],
            'baseline' => $isBaseline,
            'new' => $newCount,
        ], (int)$monitor['user_id']);

        db()->prepare(
            "UPDATE repo_monitors
             SET last_sha = ?, last_commit_message = ?, last_commit_author = ?, last_commit_time = ?,
                 last_checked_at = NOW(), last_status = 'ok', last_error = NULL, retries = 0,
                 next_check_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
             WHERE id = ?"
        )->execute([
            $newestSha, (string)($newest['message'] ?? ''), (string)($newest['author'] ?? ''),
            self::toDatetime((string)($newest['committed_at'] ?? '')),
            (int)$monitor['check_interval_minutes'], $monitorId,
        ]);

        db()->prepare('UPDATE github_tokens SET last_used_at = NOW() WHERE id = ?')
            ->execute([$monitor['token_id']]);
    }

    private static function notifyUpdate(array $monitor, array $newItems): void {
        try {
            $stmt = db()->prepare('SELECT * FROM notification_channels WHERE user_id = ? AND enabled = 1');
            $stmt->execute([$monitor['user_id']]);
            $channels = $stmt->fetchAll();
            if (empty($channels)) {
                return;
            }

            $latest = $newItems[0];
            $msg = "本次共 **" . count($newItems) . "** 条新提交，最新：\n"
                . '**最新提交：** `' . substr((string)$latest['sha'], 0, 12) . "`\n"
                . '**作者：** ' . (string)$latest['author'] . "\n"
                . '**说明：** ' . (string)$latest['message'];
            if (count($newItems) > 3) {
                $msg .= "\n\n其余 " . (count($newItems) - 3) . " 条见监控详情页";
            }

            $baseUrl = (string)($GLOBALS['config']['app']['base_url'] ?? '');
            $link = '';
            if ($baseUrl !== '') {
                $link = "\n---\n[查看监控详情]({$baseUrl}/repo_monitor.php)";
            }

            foreach ($channels as $ch) {
                try {
                    $config = json_decode(Crypto::decrypt($ch['config_encrypted']), true);
                    $res = Notifier::send(
                        $ch['channel_type'],
                        $config,
                        PlatformSearch::label((string)($monitor['platform'] ?? 'github')) . ' 仓库更新提醒',
                        $msg,
                        ['query' => $monitor['repo'], 'count' => 1, 'time' => date('Y-m-d H:i:s'), 'link' => $link],
                        'repo'
                    );
                    db()->prepare('INSERT INTO notification_logs (channel_id, result_id, status, response) VALUES (?, NULL, ?, ?)')
                        ->execute([$ch['id'], $res['success'] ? 'success' : 'failed', $res['response']]);
                } catch (Throwable $e) {
                    // 单个渠道失败不影响其他
                }
            }
        } catch (Throwable $e) {
            error_log('RepoMonitorWorker::notifyUpdate failed: ' . $e->getMessage());
        }
    }

    /**
     * GitHub ISO8601 时间转 DATETIME，解析失败返回 null
     */
    private static function toDatetime(string $iso): ?string {
        if ($iso === '') {
            return null;
        }
        $ts = strtotime($iso);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
