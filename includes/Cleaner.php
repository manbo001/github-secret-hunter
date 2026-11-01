<?php
declare(strict_types=1);

/**
 * 数据清理 - 按保留期删除过期搜索任务和结果
 *
 * 自动清理机制（无需系统 Cron）：
 * - 每次请求 bootstrap 会调用 Cleaner::runIfDue()
 * - 若距上次清理超过 24 小时且保留期 > 0，则自动执行清理
 * - 执行结果（成功/失败）均写入 app_settings 的 last_cleanup_at / last_cleanup_result
 * - 若保留期为 0（永久保留），则不执行清理
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
     * 执行清理：删除超过保留期的搜索任务及其结果
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
