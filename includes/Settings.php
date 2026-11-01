<?php
declare(strict_types=1);

/**
 * 应用设置存取（app_settings 键值表）
 * 用于后台页面可动态修改的运行时配置（优先级高于 .env 默认值）
 */
class Settings
{
    /** 请求内memo缓存：favicon 等大字段一页内可能读取多次，避免重复传输 */
    private static array $cache = [];

    /**
     * 读取设置项；表不存在/DB 异常时返回默认值，保证调用方逻辑不受影响
     */
    public static function get(string $key, string $default = ''): string {
        if (array_key_exists($key, self::$cache)) {
            $val = self::$cache[$key];
            return $val === null ? $default : (string)$val;
        }
        try {
            $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            self::$cache[$key] = $val === false ? null : $val;
            return $val === false ? $default : (string)$val;
        } catch (Throwable $e) {
            // DB 异常按"缺失"处理，本请求内回退默认值
            self::$cache[$key] = null;
            return $default;
        }
    }

    /**
     * 写入设置项（不存在则插入），并同步刷新本请求缓存
     */
    public static function set(string $key, string $value): void {
        db()->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
        self::$cache[$key] = $value;
    }
}
