<?php
declare(strict_types=1);

/**
 * 输入校验器 - 白名单 + 长度限制
 */
class Validator
{
    /**
     * 用户名校验：字母数字下划线，3-32 位
     */
    public static function username(string $value): bool {
        return (bool)preg_match('/^[a-zA-Z0-9_]{3,32}$/', $value);
    }

    /**
     * 密码强度：至少 8 位，包含大小写字母和数字
     */
    public static function password(string $value): bool {
        if (strlen($value) < 8 || strlen($value) > 128) {
            return false;
        }
        return preg_match('/[A-Z]/', $value)
            && preg_match('/[a-z]/', $value)
            && preg_match('/[0-9]/', $value);
    }

    /**
     * 邮箱格式
     */
    public static function email(string $value): bool {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 128;
    }

    /**
     * 通用字符串：限制长度，去除首尾空白
     * 非 string 输入（如数组型 POST）返回 null 而不是抛 TypeError
     */
    public static function string($value, int $maxLen = 255): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $maxLen, 'UTF-8');
    }

    /**
     * GitHub Token 格式校验（ghp_、github_pat_、gho_、ghu_、ghs_、ghr_ 前缀，或 40 位 hex classic token）
     */
    public static function githubToken(string $value): bool {
        $value = trim($value);
        if (strlen($value) < 10 || strlen($value) > 300) {
            return false;
        }
        // 新版 fine-grained token 前缀
        if (preg_match('/^(ghp_|github_pat_|gho_|ghu_|ghs_|ghr_)/', $value)) {
            return true;
        }
        // 旧版 classic token（40 位十六进制）
        if (preg_match('/^[a-f0-9]{40}$/i', $value)) {
            return true;
        }
        return false;
    }

    /**
     * URL 格式校验（http/https）
     */
    public static function url(string $value): bool {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower(parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * 平台仓库全名校验：防注入 URL 路径
     * - github/gitee：owner/name 两段
     * - gitcode：支持嵌套命名空间（最多 owner/子组/name 三段）
     * - 每段不能为 . 或 ..（防路径语义混淆）
     */
    public static function githubRepo(string $value, string $platform = 'github'): bool {
        $value = trim($value);
        if (strlen($value) < 3 || strlen($value) > 150) {
            return false;
        }
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9_.\-/]{0,149}$#', $value)) {
            return false;
        }
        $segs = explode('/', $value);
        $maxSegs = $platform === 'gitcode' ? 3 : 2;
        if (count($segs) < 2 || count($segs) > $maxSegs) {
            return false;
        }
        foreach ($segs as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return false;
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/', $seg)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 枚举值校验（非 string 输入一律 false，不抛 TypeError）
     */
    public static function enum($value, array $allowed): bool {
        return is_string($value) && in_array($value, $allowed, true);
    }

    /**
     * 整数校验
     */
    public static function int($value, int $min = 0, int $max = PHP_INT_MAX): bool {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) !== false;
    }
}
