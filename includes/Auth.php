<?php
declare(strict_types=1);

/**
 * 认证类 - 登录/登出、bcrypt、防爆破锁定、会话管理
 */
class Auth
{
    /**
     * 哈希格式合法的哑 bcrypt 串（cost=12），用于用户不存在时执行等价计算，
     * 拉平响应时间，防止通过耗时差异枚举有效用户名
     */
    private const DUMMY_HASH = '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZabcde';

    /**
     * 尝试登录
     * @return array{success:bool, message:string}
     */
    public static function login(string $username, string $password): array {
        // Rate limit: 同一 IP 每分钟最多 10 次登录尝试
        if (!Security::rateLimit('login_' . client_ip(), 10, 60)) {
            return ['success' => false, 'message' => '请求过于频繁，请稍后再试。'];
        }

        if (!Validator::username($username)) {
            return ['success' => false, 'message' => '用户名格式不正确。'];
        }

        // 查询用户
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // 锁定检查（即使密码正确，锁定期间也拒绝）
        if ($user && $user['lock_until'] !== null && strtotime($user['lock_until']) > time()) {
            $remaining = ceil((strtotime($user['lock_until']) - time()) / 60);
            audit_log('login_locked', ['username' => $username], (int)$user['id']);
            return ['success' => false, 'message' => sprintf('账号已锁定，请 %d 分钟后再试。', $remaining)];
        }
        // 锁定已过期：先清零失败计数，避免"每次解锁后仅剩一次尝试"的死循环
        if ($user && $user['lock_until'] !== null) {
            db()->prepare('UPDATE users SET failed_attempts = 0, lock_until = NULL WHERE id = ?')
                ->execute([$user['id']]);
            $user['failed_attempts'] = 0;
        }

        // 密码校验（用户不存在时也执行一次等价计算，防时间侧信道枚举）
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $passwordOk = password_verify($password, $hash);
        if (!$user || !$passwordOk) {
            $sec = $GLOBALS['config']['security'] ?? [];
            $maxAttempts = (int)($sec['login_max_attempts'] ?? (getenv('LOGIN_MAX_ATTEMPTS') ?: 5));
            $lockMinutes = (int)($sec['login_lock_minutes'] ?? (getenv('LOGIN_LOCK_MINUTES') ?: 15));

            if ($user) {
                $failed = (int)$user['failed_attempts'] + 1;
                if ($failed >= $maxAttempts) {
                    // 锁定
                    $lockUntil = date('Y-m-d H:i:s', time() + $lockMinutes * 60);
                    db()->prepare('UPDATE users SET failed_attempts = ?, lock_until = ? WHERE id = ?')
                        ->execute([$failed, $lockUntil, $user['id']]);
                    audit_log('login_failed_locked', ['username' => $username], (int)$user['id']);
                    return ['success' => false, 'message' => sprintf('登录失败次数过多，账号已锁定 %d 分钟。', $lockMinutes)];
                } else {
                    db()->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')
                        ->execute([$failed, $user['id']]);
                }
            }
            audit_log('login_failed', ['username' => $username]);
            $left = $user ? max(0, $maxAttempts - ((int)$user['failed_attempts'] + 1)) : 0;
            return ['success' => false, 'message' => $left > 0 ? sprintf('用户名或密码错误，剩余尝试次数 %d 次。', $left) : '用户名或密码错误。'];
        }

        // 登录成功 - 清除失败计数、更新登录信息、防会话固定
        db()->prepare('UPDATE users SET failed_attempts = 0, lock_until = NULL, last_login_ip = ?, last_login_at = NOW() WHERE id = ?')
            ->execute([client_ip(), $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['must_change_password'] = (int)$user['must_change_password'];
        $_SESSION['auth_stamp'] = substr($user['password_hash'], -10);
        $_SESSION['login_at'] = time();
        $_SESSION['last_activity'] = time();

        audit_log('login_success', ['username' => $username], (int)$user['id']);
        return ['success' => true, 'message' => '登录成功。'];
    }

    /**
     * 登出
     */
    public static function logout(): void {
        $userId = $_SESSION['user_id'] ?? null;
        audit_log('logout', [], $userId ? (int)$userId : null);

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * 会话不活动超时（秒）：后台设置（app_settings.session_timeout_minutes）优先，回退 .env 配置
     */
    private static function sessionLifetime(): int {
        $minutes = Settings::get('session_timeout_minutes', '');
        if ($minutes !== '' && (int)$minutes >= 0) {
            return (int)$minutes * 60;
        }
        return (int)($GLOBALS['config']['security']['session_lifetime'] ?? 28800);
    }

    /**
     * 检查是否已登录（含会话不活动超时校验，超时自动登出）
     */
    public static function check(): bool {
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        $lifetime = self::sessionLifetime();
        $lastActivity = $_SESSION['last_activity'] ?? $_SESSION['login_at'] ?? 0;
        if ($lifetime > 0 && $lastActivity > 0 && (time() - $lastActivity) > $lifetime) {
            self::logout();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * 获取当前用户 ID
     */
    public static function id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * 获取当前用户信息
     * 会话戳校验：密码修改后旧会话（含其他浏览器）自动失效
     */
    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }
        $stmt = db()->prepare('SELECT id, username, email, role, status, must_change_password, password_hash FROM users WHERE id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }
        // 密码已变更（改密前签发的会话）→ 登出并回登录页
        if (isset($_SESSION['auth_stamp']) && !hash_equals(substr($user['password_hash'], -10), (string)$_SESSION['auth_stamp'])) {
            self::logout();
            redirect('index.php');
        }
        unset($user['password_hash']);
        return $user;
    }

    /**
     * 是否需要强制修改密码
     */
    public static function mustChangePassword(): bool {
        return !empty($_SESSION['must_change_password']);
    }

    /**
     * 要求登录，否则重定向到登录页
     * 若用户标记为首次登录强制改密，则仅允许访问改密页和登出
     */
    public static function requireLogin(): void {
        if (!self::check()) {
            redirect('index.php');
        }
        $user = self::user();
        if (!$user || $user['status'] != 1) {
            self::logout();
            redirect('index.php?disabled=1');
        }
        // 强制改密：未在改密页/登出页则跳转
        if (self::mustChangePassword()) {
            $current = basename($_SERVER['PHP_SELF'] ?? '');
            if (!in_array($current, ['change_password.php', 'logout.php'], true)) {
                redirect('change_password.php');
            }
        }
    }

    /**
     * 部署期管理员用户名同步：
     * .env 配置了 ADMIN_USER 时，首次访问登录页自动把默认账号 admin 改名为该值。
     * 仅当库中还存在默认名 admin 的账号时生效——部署后在界面改过名则 .env 不再起作用。
     * 任何异常静默忽略，不影响登录页渲染。
     */
    public static function syncAdminUsernameFromEnv(): void {
        try {
            $envUser = getenv('ADMIN_USER');
            if ($envUser === false || $envUser === '' || !Validator::username($envUser)) {
                return;
            }
            $stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->execute(['admin']);
            $row = $stmt->fetch();
            if (!$row) {
                return; // 已改名（界面或 .env 已生效过），不再干预
            }
            $dup = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $dup->execute([$envUser, (int)$row['id']]);
            if ($dup->fetch()) {
                return; // 目标用户名已被占用
            }
            db()->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$envUser, (int)$row['id']]);
            audit_log('admin_username_synced', ['to' => $envUser]);
        } catch (Throwable $e) {
            // 忽略：用户名同步失败不应阻断登录
        }
    }

    /**
     * 修改用户名（系统设置 → 管理员账号）
     * 需原密码确认；改后当前会话的用户名即时更新
     */
    public static function renameUser(int $userId, string $newUsername, string $currentPassword): array {
        if (!Validator::username($newUsername)) {
            return ['success' => false, 'message' => '用户名格式不正确：3-32 位字母、数字或下划线。'];
        }
        $stmt = db()->prepare('SELECT username, password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => '原密码错误。'];
        }
        if ($newUsername === $user['username']) {
            return ['success' => false, 'message' => '新用户名与当前用户名相同。'];
        }
        $dup = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
        $dup->execute([$newUsername, $userId]);
        if ($dup->fetch()) {
            return ['success' => false, 'message' => '该用户名已被占用。'];
        }
        // 并发改到同一目标名时靠 users.username 的唯一索引兜底，转为友好提示而非 500
        try {
            db()->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$newUsername, $userId]);
        } catch (PDOException $e) {
            return ['success' => false, 'message' => '该用户名已被占用。'];
        }
        $_SESSION['username'] = $newUsername;
        audit_log('username_changed', ['new_username' => $newUsername], $userId);
        return ['success' => true, 'message' => '用户名已修改为 ' . $newUsername . '，请使用新用户名登录。'];
    }

    /**
     * 修改密码
     * @param bool $clearMustChange 是否清除强制改密标记（首次改密时传 true）
     */
    public static function changePassword(int $userId, string $oldPassword, string $newPassword, bool $clearMustChange = true): array {
        // Rate limit：原密码校验每用户每 5 分钟最多 5 次，防已登录会话暴力猜测原密码
        if (!Security::rateLimit('chgpwd_' . $userId, 5, 300)) {
            return ['success' => false, 'message' => '尝试过于频繁，请 5 分钟后再试。'];
        }
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => '原密码错误。'];
        }
        if (!Validator::password($newPassword)) {
            return ['success' => false, 'message' => '新密码强度不足：至少 8 位，包含大小写字母和数字。'];
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($clearMustChange) {
            db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([$hash, $userId]);
            $_SESSION['must_change_password'] = 0;
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
        }
        // 改密成功后换发会话 ID（防会话固定），并刷新会话戳使其他已登录会话失效
        session_regenerate_id(true);
        $_SESSION['auth_stamp'] = substr($hash, -10);
        audit_log('password_changed', [], $userId);
        return ['success' => true, 'message' => '密码修改成功。'];
    }
}
