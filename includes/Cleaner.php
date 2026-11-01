<?php
declare(strict_types=1);

/**
 * 数据清理 - 按保留期删除过期任务和结果；附带服务器资源告警检查
 *
 * 自动清理机制（无需系统 Cron）：
 * - 每次请求 bootstrap 会调用 Cleaner::runIfDue()
 * - 若距上次清理超过 24 小时且保留期 > 0，则自动执行清理
 * - 执行结果（成功/失败）均写入 app_settings 的 last_cleanup_at / last_cleanup_result
 * - 若保留期为 0（永久保留），则不执行清理
 *
 * 服务器资源告警（同在 runIfDue 中随请求触发）：
 * - 每 5 分钟检查一次 CPU / 内存 / 磁盘使用率（阈值在系统设置配置，0 = 不告警）
 * - 达到阈值即向启用渠道推送固定模板告警，同一指标 30 分钟冷却
 */
class Cleaner
{
    /** 两次自动清理的最小间隔（秒）：24 小时 */
    private const MIN_INTERVAL = 86400;

    /**
     * 读取设置项
     */
    private static function get(string $key, string $default = ''): string {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : (string)$val;
    }

    /**
     * 写入设置项（不存在则插入）
     */
    private static function set(string $key, string $value): void {
        $stmt = db()->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);
    }

    /**
     * 获取数据保留天数（0 = 永久保留）
     */
    public static function getRetentionDays(): int {
        $val = self::get('data_retention_days', '90');
        return max(0, (int)$val);
    }

    /**
     * 设置保留天数
     * 设置后若保留期缩短，会立即触发一次清理
     */
    public static function setRetentionDays(int $days): void {
        $days = max(0, $days);
        self::set('data_retention_days', (string)$days);
        // 缩短保留期时立即清理一次，让新设置即时生效
        if ($days > 0) {
            self::cleanExpired(true);
        }
    }

    /**
     * 获取上次清理时间（Y-m-d H:i:s）
     */
    public static function getLastCleanupAt(): string {
        return self::get('last_cleanup_at', '从未清理');
    }

    /**
     * 获取上次清理结果描述
     */
    public static function getLastCleanupResult(): string {
        return self::get('last_cleanup_result', '-');
    }

    /**
     * 执行清理：删除超过保留期的任务及其结果
     *
     * @param bool $force 是否强制执行（忽略时间间隔与保留期=0限制，用于手动清理）
     * @return int 删除的任务数
     */
    public static function cleanExpired(bool $force = false): int {
        $days = self::getRetentionDays();
        if ($days <= 0) {
            if ($force) {
                self::set('last_cleanup_at', date('Y-m-d H:i:s'));
                self::set('last_cleanup_result', '保留期为永久，未删除任何数据');
            }
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $stmt = db()->prepare('DELETE FROM search_tasks WHERE created_at < ?');
        $stmt->execute([$cutoff]);
        $deleted = $stmt->rowCount();

        self::set('last_cleanup_at', date('Y-m-d H:i:s'));
        self::set('last_cleanup_result', "已删除 {$deleted} 个过期任务及关联结果");
        return $deleted;
    }

    /**
     * 到期自动清理（请求触发式，由 bootstrap 调用）
     * 仅当距上次清理超过 MIN_INTERVAL 时执行，且要求保留期 > 0
     * 异常被吞掉，避免影响页面渲染
     */
    public static function runIfDue(): void {
        try {
            self::checkServerAlerts();
        } catch (Throwable $e) {
            error_log('Cleaner::checkServerAlerts failed: ' . $e->getMessage());
        }
        try {
            $days = self::getRetentionDays();
            if ($days <= 0) {
                return; // 永久保留，不清理
            }
            $last = self::get('last_cleanup_at', '');
            if ($last !== '' && $last !== '从未清理') {
                $lastTs = strtotime($last);
                if ($lastTs !== false && (time() - $lastTs) < self::MIN_INTERVAL) {
                    return; // 24 小时内已清理过
                }
            }
            self::cleanExpired(true);
        } catch (Throwable $e) {
            error_log('Cleaner::runIfDue failed: ' . $e->getMessage());
        }
    }

    /**
     * 服务器资源告警（系统设置 → 服务器告警）：
     * 每 5 分钟随请求触发一次检查；CPU / 内存 / 磁盘任一达到阈值即向启用渠道推送
     * （固定模板，不支持自定义）；同一指标触发后 30 分钟内不重复推送
     */
    public static function checkServerAlerts(): void {
        // 原子检查认领：Security::rateLimit 基于 flock 的原子计数，5 分钟窗口内
        // 并发请求只有一个能通过，避免多个请求同时检查、重复推送
        if (!Security::rateLimit('server_alert_check', 1, 300)) {
            return;
        }

        $cpuThreshold = (int)Settings::get('alert_cpu', '0');
        $memThreshold = (int)Settings::get('alert_mem', '0');
        $diskThreshold = (int)Settings::get('alert_disk', '0');
        if ($cpuThreshold <= 0 && $memThreshold <= 0 && $diskThreshold <= 0) {
            return; // 全部未启用
        }
        // 找一个已启用推送通知的用户作为告警接收方（单用户系统即管理员）
        $st = db()->prepare('SELECT user_id FROM notification_channels WHERE enabled = 1 GROUP BY user_id LIMIT 1');
        $st->execute();
        $row = $st->fetch();
        if (!$row) {
            return; // 未配置任何推送通知
        }
        $userId = (int)$row['user_id'];

        $snapshot = ServerMetrics::snapshot();
        if (empty($snapshot['available'])) {
            return;
        }

        $alerts = [];

        // CPU
        if ($cpuThreshold > 0 && $snapshot['cpu']['percent'] !== null && $snapshot['cpu']['percent'] >= $cpuThreshold) {
            $alerts[] = ['cpu', $snapshot['cpu']['percent'], $cpuThreshold,
                "CPU 使用率 {$snapshot['cpu']['percent']}%（阈值 {$cpuThreshold}%）\n"
                . "型号：{$snapshot['cpu']['model']}（{$snapshot['cpu']['cores']} 核）\n"
                . "负载：{$snapshot['cpu']['load'][0]} / {$snapshot['cpu']['load'][1]} / {$snapshot['cpu']['load'][2]}"];
        }
        // 内存
        $mem = $snapshot['mem'];
        if ($memThreshold > 0 && $mem['percent'] >= $memThreshold) {
            $alerts[] = ['mem', $mem['percent'], $memThreshold,
                "内存使用率 {$mem['percent']}%（阈值 {$memThreshold}%）\n"
                . self::fmtBytes($mem['used']) . ' / ' . self::fmtBytes($mem['total'])];
        }
        // 磁盘：监控范围可选（全部分区或单一指定分区）
        if ($diskThreshold > 0) {
            $disks = $snapshot['disks'];
            $diskMount = trim(self::get('alert_disk_mount', ''));
            if ($diskMount !== '') {
                $disks = array_values(array_filter($disks, static fn(array $d): bool => $d['mount'] === $diskMount));
            }
            $hit = [];
            foreach ($disks as $d) {
                if ($d['percent'] >= $diskThreshold) {
                    $hit[] = "{$d['mount']}（{$d['device']}）使用率 {$d['percent']}%，已用 "
                        . self::fmtBytes($d['total'] - $d['free']) . ' / ' . self::fmtBytes($d['total']);
                }
            }
            if (!empty($hit)) {
                $scope = $diskMount !== '' ? "监控范围：{$diskMount}" : '监控范围：全部分区';
                $alerts[] = ['disk', max(array_column($disks, 'percent')), $diskThreshold,
                    "{$scope}\n磁盘使用率超过 {$diskThreshold}%：\n" . implode("\n", $hit)];
            }
        }

        // 逐项推送（各指标独立 30 分钟冷却；冷却标记在成功送达后写入，
        // 渠道全失败时 5 分钟后的下一轮检查可重试）
        $now = date('Y-m-d H:i:s');
        foreach ($alerts as [$metric, $value, $threshold, $detail]) {
            $sentKey = 'server_alert_sent_' . $metric;
            $lastSent = self::get($sentKey, '');
            if ($lastSent !== '' && (time() - strtotime($lastSent)) < 1800) {
                continue;
            }
            $title = '服务器资源告警';
            $message = $detail . "\n时间：{$now}";
            $sent = Notifier::pushToAllChannels($userId, $title, $message, ['title' => $title, 'time' => $now], 'raw');
            if ($sent > 0) {
                self::set($sentKey, $now);
                audit_log('server_alert_pushed', ['metric' => $metric, 'value' => $value, 'threshold' => $threshold, 'sent' => $sent], $userId);
            }
        }
    }

    /** 字节格式化（告警消息用） */
    public static function fmtBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float)$bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return round($v, $i > 1 ? 1 : 0) . ' ' . $units[$i];
    }

    /**
     * 获取清理统计信息
     */
    public static function getStats(): array {
        $days = self::getRetentionDays();
        $cutoff = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);

        $expiredTasks = 0;
        if ($days > 0) {
            $stmt = db()->prepare('SELECT COUNT(*) FROM search_tasks WHERE created_at < ?');
            $stmt->execute([$cutoff]);
            $expiredTasks = (int)$stmt->fetchColumn();
        }

        $totalTasks = (int)db()->query('SELECT COUNT(*) FROM search_tasks')->fetchColumn();
        $totalResults = (int)db()->query('SELECT COUNT(*) FROM search_results')->fetchColumn();

        return [
            'retention_days'      => $days,
            'expired_tasks'       => $expiredTasks,
            'total_tasks'         => $totalTasks,
            'total_results'       => $totalResults,
            'last_cleanup_at'     => self::getLastCleanupAt(),
            'last_cleanup_result' => self::getLastCleanupResult(),
            'auto_cleanup'        => $days > 0 ? '已启用' : '已禁用（永久保留）',
        ];
    }
}
