<?php
declare(strict_types=1);

/**
 * AES-256-GCM 认证加密（新数据）
 * 兼容解密旧版 AES-256-CBC 密文（格式无前缀），便于平滑升级
 * 密钥来自环境变量 APP_ENCRYPTION_KEY（32 字节）
 */
class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const LEGACY_CIPHER = 'AES-256-CBC';
    private const PREFIX = 'gcm:';

    private static function key(): string {
        // 优先从全局配置读取，回退到直接读环境变量（兼容 PHP-FPM 下全局变量异常的情况）
        $key = $GLOBALS['config']['app']['encryption_key'] ?? '';
        if ($key === '') {
            $key = getenv('APP_ENCRYPTION_KEY') ?: ($_ENV['APP_ENCRYPTION_KEY'] ?? '');
        }
        if (empty($key)) {
            throw new RuntimeException('APP_ENCRYPTION_KEY is not configured.');
        }
        // 确保 32 字节
        return substr(hash('sha256', $key, true), 0, 32);
    }

    /**
     * 加密 - 返回 gcm:base64(iv):base64(ciphertext):base64(tag)
     * GCM 自带完整性校验，密文被篡改时解密直接失败
     */
    public static function encrypt(string $plaintext): string {
        $iv = random_bytes(12); // GCM 推荐 12 字节 IV
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($iv) . ':' . base64_encode($ciphertext) . ':' . base64_encode($tag);
    }

    /**
     * 解密 - 自动识别新版 GCM（gcm: 前缀）与旧版 CBC（iv:ciphertext）格式
     */
    public static function decrypt(string $payload): string {
        if (str_starts_with($payload, self::PREFIX)) {
            return self::decryptGcm(substr($payload, strlen(self::PREFIX)));
        }
        return self::decryptLegacyCbc($payload);
    }

    private static function decryptGcm(string $payload): string {
        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid encrypted payload format.');
        }
        [$ivB64, $cipherB64, $tagB64] = $parts;
        $iv = base64_decode($ivB64, true);
        $ciphertext = base64_decode($cipherB64, true);
        $tag = base64_decode($tagB64, true);
        if ($iv === false || $ciphertext === false || $tag === false) {
            throw new RuntimeException('Invalid base64 in payload.');
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed.');
        }
        return $plaintext;
    }

    private static function decryptLegacyCbc(string $payload): string {
        $parts = explode(':', $payload, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid encrypted payload format.');
        }
        [$ivB64, $cipherB64] = $parts;
        $iv = base64_decode($ivB64, true);
        $ciphertext = base64_decode($cipherB64, true);
        if ($iv === false || $ciphertext === false) {
            throw new RuntimeException('Invalid base64 in payload.');
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::LEGACY_CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv
        );
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed.');
        }
        return $plaintext;
    }
}
