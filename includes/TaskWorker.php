<?php
declare(strict_types=1);

/**
 * 搜索任务后台执行器（请求触发式，无需系统 Cron）
 *
 * 工作方式：
 * - bootstrap 每次请求调用 runIfDue()，每次最多处理一个任务的"一页"搜索
 * - 翻页请求之间按 GITHUB_SEARCH_INTERVAL 秒间隔调度，遵守平台速率限制
 * - 被限流（403/429）时按平台返回的 Retry-After / Reset 时间自动退避
 * - GitHub 令牌池：同一用户有多个 GitHub Token 时按"最久未用"轮转（负载均衡，
 *   等效叠加每 Token 30 次/分的搜索配额，翻页更快）；限流配额也按 Token 隔离
 * - 大查询拆分：GitHub 代码搜索单查询只暴露前 1000 条（page*per_page>1000 返回 422），
 *   Gitee 同样存在 page*per_page<=1000 的隐性上限。命中数超过 1000 时自动按
 *   language 限定符拆分子查询（GitHub 额外用 NOT language 兜底未列出语言，
 *   超大的语言切片再按 size 桶二次拆分），结果合并去重入库；
 *   GitCode 无结果总数硬上限（page 最大 100），不拆分
 * - 任务完成后按 RECHECK_INTERVAL_MINUTES 间隔自动复查：只把新增结果入库并推送通知
 * - 去重依赖 search_results 的 (task_id, html_url) 唯一索引 + INSERT IGNORE
 */
class TaskWorker
{
    /** 单查询结果上限（github/gitee） */
    private const MAX_TOTAL = 1000;
    /** 单查询页数上限（github/gitee：10 页 × 100 = 1000） */
    private const MAX_PAGES = 10;
    /** GitCode 页数上限（官方文档：page 最大 100） */
    private const MAX_PAGES_GITCODE = 100;
    /** 连续失败多少次后标记任务失败（限流退避不计入）。弱网环境适当放宽 */
    private const MAX_RETRIES = 5;

    /**
     * 语言切片列表（拆分 >1000 命中的大查询）。
     * GitHub 代码搜索 q 上限 256 字符，语言数量须控制在 10 个左右，
     * 未列出的语言由 NOT language 兜底切片覆盖（GitHub）。
     */
    private const SPLIT_LANGUAGES = ['Java', 'Python', 'JavaScript', 'TypeScript', 'Go', 'C', 'C++', 'C#', 'PHP', 'Ruby', 'Shell'];

    /** 二次拆分用 size 桶（某个语言切片仍 >1000 时按代码文件大小切分） */
    private const SIZE_BUCKETS = ['size:0..2047', 'size:2048..16383', 'size:16384..131071', 'size:131072..1048575', 'size:>1048575'];

    /**
     * 到期自动处理（由 bootstrap 调用），异常全部吞掉避免影响页面渲染
     * @param int|null $preferredTaskId 优先处理的任务（如刚创建的任务），让首页结果立即可见
     */
    public static function runIfDue(?int $preferredTaskId = null): void {
        try {
            $task = self::claimDueTask($preferredTaskId);
            if ($task !== null) {
                self::processTask($task);
            }
        } catch (Throwable $e) {
            error_log('TaskWorker::runIfDue failed: ' . $e->getMessage());
        }
    }

    /**
     * 原子认领一个到期任务：先把 next_run_at 推进 90 秒占住，
     * 并发请求中只有一个能认领成功，避免同一页被重复抓取
     */
    private static function claimDueTask(?int $preferredTaskId = null): ?array {
        $stmt = db()->prepare(
            "SELECT * FROM search_tasks
             WHERE paused = 0 AND (
                   (status IN ('pending','running') AND (next_run_at IS NULL OR next_run_at <= NOW()))
                OR (status = 'completed' AND recheck_minutes > 0
                    AND next_check_at IS NOT NULL AND next_check_at <= NOW())
             )
             ORDER BY (id = ?) DESC, COALESCE(next_run_at, next_check_at) ASC
             LIMIT 1"
        );
        $stmt->execute([$preferredTaskId ?: 0]);
        $task = $stmt->fetch();
        if (!$task) {
            return null;
        }

        // 认领同时推进 next_run_at（pending/running 分支）与 next_check_at（复查分支），
        // 保证并发请求中只有一个能通过 WHERE 条件，防止同一页被重复抓取/重复通知
        $claim = db()->prepare(
            "UPDATE search_tasks
             SET next_run_at = DATE_ADD(NOW(), INTERVAL 90 SECOND),
                 next_check_at = IF(status = 'completed' AND next_check_at IS NOT NULL,
                                    DATE_ADD(NOW(), INTERVAL 90 SECOND), next_check_at)
             WHERE id = ? AND paused = 0 AND (
                   (status IN ('pending','running') AND (next_run_at IS NULL OR next_run_at <= NOW()))
                OR (status = 'completed' AND recheck_minutes > 0
                    AND next_check_at IS NOT NULL AND next_check_at <= NOW())
             )"
        );
        $claim->execute([$task['id']]);
        if ($claim->rowCount() !== 1) {
            return null; // 已被其他请求认领
        }

        // 若是复查轮次：清掉上一轮的子查询，重置分页进度重新探测（拆分与否由新一轮命中数决定）
        if ($task['status'] === 'completed') {
            db()->prepare('DELETE FROM search_subqueries WHERE task_id = ?')->execute([$task['id']]);
            db()->prepare("UPDATE search_tasks SET status = 'running', current_page = 1, total_pages = 0 WHERE id = ?")
                ->execute([$task['id']]);
            $task['_is_recheck'] = true;
            $task['status'] = 'running';
            $task['current_page'] = 1;
            $task['total_pages'] = 0;
        }
        return $task;
    }

    /**
     * 处理一页搜索（主查询或子查询）
     */
    private static function processTask(array $task): void {
        $cfg = $GLOBALS['config']['github'] ?? [];
        $interval = max(2, (int)($cfg['search_interval'] ?? 7));
        $taskId = (int)$task['id'];
        $platform = (string)($task['platform'] ?? 'github');
        // 每页数量按平台实际上限收紧（gitcode 平台单页上限 50，超限页数推算会提前终止漏抓）
        $perPage = max(10, min(PlatformSearch::perPageCap($platform), (int)($task['per_page'] ?: 100)));

        // ---- 令牌选择：GitHub 多令牌 LRU 轮转（负载均衡）；其他平台用绑定令牌 ----
        $picked = self::pickToken($task, $platform);
        if ($picked === null) {
            db()->prepare("UPDATE search_tasks SET status = 'failed', error_msg = '没有可用的平台 Token（已被删除或解密失败）。', next_run_at = NULL WHERE id = ?")
                ->execute([$taskId]);
            return;
        }
        [$tokenId, $plainToken] = $picked;

        // ---- 当前工作项：优先未完成的子查询，否则主查询 ----
        $sub = self::nextPendingSubquery($taskId);
        $isSub = $sub !== null;
        $query = $isSub ? (string)$sub['query'] : (string)$task['query'];
        $page = $isSub ? max(1, (int)$sub['current_page']) : max(1, (int)$task['current_page']);

        // ---- 限流：每令牌独立配额（25 次/分，贴近 GitHub 单令牌搜索上限 30/分）+ 全局安全桶 ----
        if (!Security::rateLimit('platform_search_api_t' . $tokenId, 25, 60)
            || !Security::rateLimit('platform_search_api', 100, 60)) {
            self::scheduleNext($taskId, 5);
            return;
        }

        $result = PlatformSearch::search($platform, $plainToken, $query, $perPage, $page);

        // 无论成败都更新令牌使用时间：失败的令牌排到队尾，下一页自动轮换到其他令牌
        self::touchToken($tokenId);

        // ---- 失败处理：限流退避 vs 普通错误重试 ----
        if ($result['error']) {
            $retryAfter = (int)($result['retry_after'] ?? 0);
            if ($retryAfter > 0) {
                // 被平台限流：按官方建议时间退避，不计入失败次数，原地重试当前页
                db()->prepare('UPDATE search_tasks SET next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND), error_msg = ? WHERE id = ?')
                    ->execute([$retryAfter + $interval, mb_substr($result['error'], 0, 500), $taskId]);
                if ($isSub) {
                    db()->prepare('UPDATE search_subqueries SET status = ? WHERE id = ?')
                        ->execute([$page > 1 ? 'running' : 'pending', (int)$sub['id']]);
                }
                return;
            }
            if ($isSub) {
                $retries = (int)$sub['retries'] + 1;
                if ($retries >= self::MAX_RETRIES) {
                    // 单个子查询持续失败：跳过它继续其余切片，不让整个任务卡死
                    db()->prepare("UPDATE search_subqueries SET status = 'completed', total_count = 0, total_pages = 0, retries = ? WHERE id = ?")
                        ->execute([$retries, (int)$sub['id']]);
                    db()->prepare('UPDATE search_tasks SET error_msg = ? WHERE id = ?')
                        ->execute([mb_substr('子查询已跳过（连续失败）：' . $result['error'], 0, 500), $taskId]);
                    audit_log('search_sub_skipped', ['task_id' => $taskId, 'query' => $query, 'error' => $result['error']], (int)$task['user_id']);
                    self::advanceTaskProgress($task, $taskId, $interval, 0);
                } else {
                    db()->prepare('UPDATE search_subqueries SET retries = ? WHERE id = ?')->execute([$retries, (int)$sub['id']]);
                    self::scheduleNext($taskId, 120, mb_substr($result['error'], 0, 500));
                }
            } else {
                $retries = (int)$task['retries'] + 1;
                if ($retries >= self::MAX_RETRIES) {
                    db()->prepare("UPDATE search_tasks SET status = 'failed', retries = ?, error_msg = ?, next_run_at = NULL WHERE id = ?")
                        ->execute([$retries, mb_substr($result['error'], 0, 500), $taskId]);
                } else {
                    // 普通错误（网络抖动等）：2 分钟后重试
                    self::scheduleNext($taskId, 120, mb_substr($result['error'], 0, 500), $retries);
                }
            }
            return;
        }

        $items = $result['items'];
        $totalCount = (int)$result['total_count'];
        // 页数上限：gitcode 按 page 上限 100 翻满；github/gitee 单查询 10 页且 1000 条封顶
        if ($platform === 'gitcode') {
            $totalPages = ($totalCount > 0) ? (int)min(self::MAX_PAGES_GITCODE, (int)ceil($totalCount / $perPage)) : 0;
        } else {
            $totalPages = ($totalCount > 0) ? (int)min(self::MAX_PAGES, (int)ceil(min($totalCount, self::MAX_TOTAL) / $perPage)) : 0;
        }

        // ---- 入库（INSERT IGNORE + 唯一索引去重，只统计新增） ----
        $newCount = self::storeItems($task, $items);

        // ---- 子查询推进 ----
        if ($isSub) {
            // GitHub：切片本身仍 >1000 → 尝试按 size 桶再细分（无法细分时按 1000 封顶继续）
            if ($page === 1 && $platform === 'github' && $totalCount > self::MAX_TOTAL) {
                $buckets = self::sizeSplitQueries($query);
                if (!empty($buckets)) {
                    self::markSubCompleted((int)$sub['id'], $totalCount, $totalPages);
                    self::createSubqueries($taskId, $buckets);
                    audit_log('search_query_split', ['task_id' => $taskId, 'level' => 'size', 'slices' => count($buckets), 'parent_total' => $totalCount], (int)$task['user_id']);
                    self::advanceTaskProgress($task, $taskId, $interval, $newCount);
                    return;
                }
            }
            if ($page < $totalPages) {
                db()->prepare('UPDATE search_subqueries SET status = ?, total_count = ?, total_pages = ?, current_page = ? WHERE id = ?')
                    ->execute(['running', $totalCount, $totalPages, $page + 1, (int)$sub['id']]);
                // 累计通知计数并调度下一页
                db()->prepare('UPDATE search_tasks SET last_new_count = ?, pending_notify_count = pending_notify_count + ?, next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND), error_msg = NULL WHERE id = ?')
                    ->execute([$newCount, $newCount, $interval, $taskId]);
            } else {
                self::markSubCompleted((int)$sub['id'], $totalCount, $totalPages);
                self::advanceTaskProgress($task, $taskId, $interval, $newCount);
            }
            return;
        }

        // ---- 主查询命中数超上限：触发 language 拆分（gitcode 无结果上限，不拆） ----
        if ($page === 1 && $platform !== 'gitcode' && $totalCount > self::MAX_TOTAL) {
            $slices = self::languageSplitQueries($platform, $task['query']);
            // 切片为空（原查询过长，任何切片都超 GitHub 256 字符限制）→ 不拆分，
            // 落回正常的 1000 条封顶分页，否则任务会无限重新探测、永不完成
            if (!empty($slices)) {
                self::createSubqueries($taskId, $slices);
                $subCount = count($slices);
                // 任务保持 running：total_pages = 子查询数，current_page = 已完成子查询数（0）。
                // 页 1 已入库的新增也计入通知累计（这些 URL 在切片轮里会被唯一索引去重，不会再出现）
                db()->prepare(
                    "UPDATE search_tasks
                     SET status = 'running', total_count = ?, total_pages = ?, current_page = 0,
                         last_new_count = ?, pending_notify_count = pending_notify_count + ?,
                         error_msg = NULL, completed_at = NULL,
                         next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                     WHERE id = ?"
                )->execute([$totalCount, $subCount, $newCount, $newCount, $interval, $taskId]);
                audit_log('search_query_split', ['task_id' => $taskId, 'level' => 'language', 'slices' => $subCount, 'total' => $totalCount], (int)$task['user_id']);
                return;
            }
        }

        // ---- 正常分页推进 / 完成 ----
        if ($page < $totalPages) {
            db()->prepare(
                "UPDATE search_tasks
                 SET status = 'running', total_count = ?, total_pages = ?, current_page = ?,
                     last_new_count = ?, pending_notify_count = pending_notify_count + ?,
                     retries = 0, error_msg = NULL, completed_at = NULL,
                     next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id = ?"
            )->execute([$totalCount, $totalPages, $page + 1, $newCount, $newCount, $interval, $taskId]);
            return;
        }

        // 本轮（首次或复查）完成
        $pending = (int)$task['pending_notify_count'] + $newCount;
        if ((int)$task['notify'] === 1 && $pending > 0) {
            self::notifyNewHits($task, $pending, self::$lastNewItems);
        }
        db()->prepare(
            "UPDATE search_tasks
             SET status = 'completed', total_count = ?, total_pages = ?, current_page = ?,
                 last_new_count = ?, pending_notify_count = 0, retries = 0, error_msg = NULL,
                 completed_at = NOW(), next_run_at = NULL,
                 next_check_at = IF(? > 0, DATE_ADD(NOW(), INTERVAL ? MINUTE), NULL)
             WHERE id = ?"
        )->execute([$totalCount, $totalPages, $page, $newCount, (int)$task['recheck_minutes'], (int)$task['recheck_minutes'], $taskId]);

        audit_log('search_round_completed', [
            'task_id' => $taskId,
            'query' => $task['query'],
            'total' => $totalCount,
            'new' => $newCount,
            'is_recheck' => !empty($task['_is_recheck']),
        ], (int)$task['user_id']);
    }

    /** lastNewItems：本轮最后一页的新增明细（通知摘要用），静态中转避免长参数链 */
    private static array $lastNewItems = [];

    /**
     * 令牌选择：GitHub 平台在用户多令牌时按"最久未用"轮转（负载均衡）；
     * 其他平台使用任务绑定令牌。返回 [tokenId, plainToken] 或 null（无可用令牌）
     */
    private static function pickToken(array $task, string $platform): ?array {
        if ($platform !== 'github') {
            $stmt = db()->prepare('SELECT id, token_encrypted FROM github_tokens WHERE id = ? AND user_id = ?');
            $stmt->execute([(int)$task['token_id'], (int)$task['user_id']]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            try {
                return [(int)$row['id'], Crypto::decrypt($row['token_encrypted'])];
            } catch (Throwable $e) {
                return null;
            }
        }
        // GitHub：所有令牌按 LRU 排序，最久未用的先用（NULL 视为最旧，优先启用闲置令牌）
        $stmt = db()->prepare(
            "SELECT id, token_encrypted FROM github_tokens
             WHERE user_id = ? AND platform = 'github'
             ORDER BY (last_used_at IS NULL) DESC, last_used_at ASC, id ASC"
        );
        $stmt->execute([(int)$task['user_id']]);
        foreach ($stmt->fetchAll() as $row) {
            try {
                return [(int)$row['id'], Crypto::decrypt($row['token_encrypted'])];
            } catch (Throwable $e) {
                continue; // 解密失败的令牌跳过
            }
        }
        return null;
    }

    private static function touchToken(int $tokenId): void {
        db()->prepare('UPDATE github_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$tokenId]);
    }

    private static function scheduleNext(int $taskId, int $seconds, string $errorMsg = '', int $retries = -1): void {
        if ($retries >= 0) {
            db()->prepare('UPDATE search_tasks SET retries = ?, next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND), error_msg = ? WHERE id = ?')
                ->execute([$retries, $seconds, $errorMsg, $taskId]);
        } else {
            db()->prepare('UPDATE search_tasks SET next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND), error_msg = ? WHERE id = ?')
                ->execute([$seconds, $errorMsg, $taskId]);
        }
    }

    private static function nextPendingSubquery(int $taskId): ?array {
        $stmt = db()->prepare(
            "SELECT * FROM search_subqueries WHERE task_id = ? AND status IN ('pending','running') ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$taskId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 生成拆分子查询（同一任务下相同子查询不重复创建。
     * query 列最长 800 字符无法建唯一索引，用查询比对去重；
     * 任务认领机制保证同一任务不会并发处理，无竞态窗口）
     */
    private static function createSubqueries(int $taskId, array $queries): void {
        $existing = db()->prepare('SELECT query FROM search_subqueries WHERE task_id = ?');
        $existing->execute([$taskId]);
        $have = array_fill_keys(array_map('strval', $existing->fetchAll(PDO::FETCH_COLUMN)), true);
        $ins = db()->prepare('INSERT INTO search_subqueries (task_id, query) VALUES (?, ?)');
        foreach ($queries as $q) {
            $q = trim((string)$q);
            if ($q !== '' && !isset($have[$q])) {
                $ins->execute([$taskId, mb_substr($q, 0, 800)]);
                $have[$q] = true;
            }
        }
    }

    private static function markSubCompleted(int $subId, int $totalCount, int $totalPages): void {
        db()->prepare("UPDATE search_subqueries SET status = 'completed', total_count = ?, total_pages = ? WHERE id = ?")
            ->execute([$totalCount, $totalPages, $subId]);
    }

    /**
     * 子查询全部完成时汇总收尾；否则推进任务进度（current_page = 已完成子查询数）
     */
    private static function advanceTaskProgress(array $task, int $taskId, int $interval, int $newCount): void {
        $st = db()->prepare(
            "SELECT COUNT(*) AS total_subs,
                    SUM(status = 'completed') AS done_subs
             FROM search_subqueries WHERE task_id = ?"
        );
        $st->execute([$taskId]);
        $agg = $st->fetch() ?: ['total_subs' => 0, 'done_subs' => 0];
        $totalSubs = (int)$agg['total_subs'];
        $doneSubs = (int)$agg['done_subs'];

        $remaining = $totalSubs - $doneSubs;
        if ($remaining > 0) {
            db()->prepare(
                "UPDATE search_tasks
                 SET status = 'running', total_pages = ?, current_page = ?, last_new_count = ?,
                     pending_notify_count = pending_notify_count + ?, next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id = ?"
            )->execute([$totalSubs, $doneSubs, $newCount, $newCount, $interval, $taskId]);
            return;
        }

        // 全部子查询完成：本轮收尾。
        // total_count 保持拆分前主查询探测到的真实总数（切片间有包含关系，求和会重复计数）
        $pending = (int)$task['pending_notify_count'] + $newCount;
        if ((int)$task['notify'] === 1 && $pending > 0) {
            self::notifyNewHits($task, $pending, self::$lastNewItems);
        }
        db()->prepare(
            "UPDATE search_tasks
             SET status = 'completed', total_pages = ?, current_page = ?,
                 last_new_count = ?, pending_notify_count = 0,
                 error_msg = NULL, completed_at = NOW(), next_run_at = NULL,
                 next_check_at = IF(? > 0, DATE_ADD(NOW(), INTERVAL ? MINUTE), NULL)
             WHERE id = ?"
        )->execute([$totalSubs, $doneSubs, $newCount, (int)$task['recheck_minutes'], (int)$task['recheck_minutes'], $taskId]);

        audit_log('search_round_completed', [
            'task_id' => $taskId,
            'query' => $task['query'],
            'total' => (int)$task['total_count'],
            'new' => $newCount,
            'split' => $totalSubs > 0,
            'is_recheck' => !empty($task['_is_recheck']),
        ], (int)$task['user_id']);
    }

    /**
     * 结果入库，返回新增条数（唯一索引去重）
     */
    private static function storeItems(array $task, array $items): int {
        $newItems = [];
        if (!empty($items)) {
            $ins = db()->prepare(
                'INSERT IGNORE INTO search_results (task_id, platform, repo_full_name, file_path, html_url, match_snippet, score)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $repoName = (string)($item['repository']['full_name'] ?? '');
                $filePath = (string)($item['path'] ?? '');
                $htmlUrl = (string)($item['html_url'] ?? '');
                $snippet = '';
                if (isset($item['text_matches']) && is_array($item['text_matches'])) {
                    foreach ($item['text_matches'] as $m) {
                        $snippet .= ($m['fragment'] ?? '') . "\n";
                    }
                }
                $ins->execute([$task['id'], (string)($task['platform'] ?? 'github'), $repoName, $filePath, $htmlUrl, $snippet, $item['score'] ?? null]);
                if ($ins->rowCount() === 1 && $htmlUrl !== '') {
                    $newItems[] = ['repo' => $repoName, 'path' => $filePath, 'url' => $htmlUrl];
                }
            }
        }
        self::$lastNewItems = $newItems;
        return count($newItems);
    }

    /**
     * language 一级拆分：原查询 + language:X 切片；
     * GitHub 额外用 NOT language 兜底未列出的语言（q 总长须 ≤250，超长时放弃兜底切片）
     */
    private static function languageSplitQueries(string $platform, string $query): array {
        $langs = self::SPLIT_LANGUAGES;
        $out = [];
        foreach ($langs as $l) {
            // GitHub 代码搜索 q 上限 256 字符：切片超长的必然 422，直接不创建
            if (strlen($query) + strlen(' language:' . $l) <= 250) {
                $out[] = $query . ' language:' . $l;
            }
        }
        if ($platform === 'github') {
            $nots = implode(' ', array_map(static fn(string $l): string => 'NOT language:' . $l, $langs));
            if (strlen($query) + strlen($nots) + 1 <= 250) {
                $out[] = $query . ' ' . $nots;
            }
        }
        return $out;
    }

    /**
     * size 切分：
     * - 无 size 限定 → 按顶层文件大小分桶
     * - 已有有界 size:A..B 且宽度 ≥2048 → 8 等分细分（宽度不足则视为已到底，无法再分）
     * - 无界限定（如 size:>1048575）→ 无法细分，返回空（调用方按 1000 封顶）
     */
    private static function sizeSplitQueries(string $query): array {
        if (preg_match('/size:(\d+)\.\.(\d+)/', $query, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            if ($b - $a + 1 < 2048) {
                return []; // 范围已足够细，避免无限递归
            }
            $step = (int)ceil(($b - $a + 1) / 8);
            $out = [];
            for ($s = $a; $s <= $b; $s += $step) {
                $e = min($s + $step - 1, $b);
                $out[] = str_replace('size:' . $m[1] . '..' . $m[2], 'size:' . $s . '..' . $e, $query);
            }
            return $out;
        }
        if (str_contains($query, 'size:')) {
            return []; // 无界 size 限定，无法细分
        }
        $out = [];
        foreach (self::SIZE_BUCKETS as $b) {
            $out[] = $query . ' ' . $b;
        }
        return $out;
    }

    /**
     * 新增命中推送通知（每轮完成汇总一次，只推新增部分）
     */
    private static function notifyNewHits(array $task, int $pendingCount, array $newItems): void {
        try {
            $stmt = db()->prepare('SELECT * FROM notification_channels WHERE user_id = ? AND enabled = 1');
            $stmt->execute([$task['user_id']]);
            $channels = $stmt->fetchAll();
            if (empty($channels)) {
                return;
            }

            $msg = "任务 #{$task['id']} 新增命中明细：\n";
            foreach (array_slice($newItems, 0, 10) as $i => $item) {
                $msg .= ($i + 1) . '. ' . $item['repo'] . ':' . $item['path'] . "\n";
            }
            if (count($newItems) > 10) {
                $msg .= '……（本轮共 ' . count($newItems) . ' 条新增，仅列出前 10 条）';
            }

            // 系统访问地址已配置时，附上结果页链接
            $baseUrl = (string)($GLOBALS['config']['app']['base_url'] ?? '');
            $link = '';
            if ($baseUrl !== '') {
                $link = "\n---\n[查看全部命中结果]({$baseUrl}/results.php?task_id={$task['id']})";
            }

            foreach ($channels as $ch) {
                try {
                    $config = json_decode(Crypto::decrypt($ch['config_encrypted']), true);
                    $res = Notifier::send(
                        $ch['channel_type'],
                        $config,
                        PlatformSearch::label((string)($task['platform'] ?? 'github')) . ' 敏感信息新增命中告警',
                        $msg,
                        [
                            'query' => $task['query'],
                            'count' => $pendingCount,
                            'time'  => date('Y-m-d H:i:s'),
                            'link'  => $link,
                        ]
                    );
                    db()->prepare('INSERT INTO notification_logs (channel_id, result_id, status, response) VALUES (?, NULL, ?, ?)')
                        ->execute([$ch['id'], $res['success'] ? 'success' : 'failed', $res['response']]);
                } catch (Throwable $e) {
                    // 单个渠道失败不影响其他
                }
            }
        } catch (Throwable $e) {
            error_log('TaskWorker::notifyNewHits failed: ' . $e->getMessage());
        }
    }
}
