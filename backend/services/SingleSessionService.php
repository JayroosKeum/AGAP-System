<?php

require_once __DIR__ . '/../config/database.php';

class SingleSessionService
{
    public const IDLE_TIMEOUT_SECONDS = 900; // 15 minutes default
    private static bool $schemaChecked = false;
    private static ?int $cachedTimeoutSeconds = null;

    public static function getTimeoutSeconds(PDO $db): int
    {
        if (self::$cachedTimeoutSeconds !== null) {
            return self::$cachedTimeoutSeconds;
        }
        $seconds = self::IDLE_TIMEOUT_SECONDS;
        try {
            $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'session_inactivity_timeout_min' LIMIT 1");
            if ($stmt && $stmt->execute()) {
                $val = $stmt->fetchColumn();
                if ($val !== false && is_numeric($val)) {
                    $mins = max(5, min(240, (int) $val));
                    $seconds = $mins * 60;
                }
            }
        } catch (Throwable $e) {
            error_log('SingleSessionService::getTimeoutSeconds warning: ' . $e->getMessage());
        }
        self::$cachedTimeoutSeconds = $seconds;
        return $seconds;
    }

    public static function ensureSchema(PDO $db): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $cols = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!in_array('active_session_token', $cols, true)) {
                $db->exec('ALTER TABLE users ADD COLUMN active_session_token CHAR(64) NULL AFTER email_verification_expires_at');
            }
            if (!in_array('active_session_last_seen', $cols, true)) {
                $db->exec('ALTER TABLE users ADD COLUMN active_session_last_seen DATETIME NULL AFTER active_session_token');
            }
        } catch (Throwable $e) {
            error_log('SingleSessionService::ensureSchema warning: ' . $e->getMessage());
        }
    }

    /**
     * Atomically acquire the exclusive active session lock for a user (Option B).
     * Returns the raw session token on success, or null if another device holds an active session.
     */
    public static function acquireSessionLock(PDO $db, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        self::ensureSchema($db);

        $prevUserId = (int) ($_SESSION['user_id'] ?? 0);
        $prevRawToken = (string) ($_SESSION['session_token'] ?? '');
        $sameDeviceHash = ($prevUserId === $userId && $prevRawToken !== '')
            ? hash('sha256', $prevRawToken)
            : '__NONE__';

        $newRawToken = bin2hex(random_bytes(32));
        $newTokenHash = hash('sha256', $newRawToken);
        $timeoutSeconds = self::getTimeoutSeconds($db);

        $stmt = $db->prepare("
            UPDATE users
            SET active_session_token = ?, active_session_last_seen = NOW()
            WHERE user_id = ?
              AND status = 'Active'
              AND (
                   active_session_token IS NULL
                OR active_session_last_seen IS NULL
                OR active_session_last_seen < DATE_SUB(NOW(), INTERVAL {$timeoutSeconds} SECOND)
                OR active_session_token = ?
              )
        ");
        $stmt->execute([$newTokenHash, $userId, $sameDeviceHash]);

        if ($stmt->rowCount() !== 1) {
            return null;
        }

        // If the same browser switched directly to a different user, release the previous user's lock cleanly.
        if ($prevUserId > 0 && $prevUserId !== $userId && $prevRawToken !== '') {
            self::releaseToken($db, $prevUserId, hash('sha256', $prevRawToken));
        }

        $_SESSION['_agap_last_seen_touch'] = time();
        return $newRawToken;
    }

    public static function releaseCurrentSession(PDO $db): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $rawToken = (string) ($_SESSION['session_token'] ?? '');
        if ($userId <= 0 || $rawToken === '') {
            return;
        }

        self::ensureSchema($db);
        self::releaseToken($db, $userId, hash('sha256', $rawToken));
    }

    public static function clearUserSession(PDO $db, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        self::ensureSchema($db);
        try {
            $stmt = $db->prepare('UPDATE users SET active_session_token = NULL, active_session_last_seen = NULL WHERE user_id = ?');
            $stmt->execute([$userId]);
        } catch (Throwable $e) {
            error_log('SingleSessionService::clearUserSession warning: ' . $e->getMessage());
        }
    }

    public static function isCurrentSessionValid(PDO $db): bool
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return true;
        }

        $rawToken = (string) ($_SESSION['session_token'] ?? '');
        if ($rawToken === '') {
            return false;
        }

        self::ensureSchema($db);

        $tokenHash = hash('sha256', $rawToken);
        $timeoutSeconds = self::getTimeoutSeconds($db);
        $stmt = $db->prepare("
            SELECT status, active_session_token,
                   (active_session_last_seen IS NULL OR active_session_last_seen < DATE_SUB(NOW(), INTERVAL {$timeoutSeconds} SECOND)) AS is_expired
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || ($row['status'] ?? '') !== 'Active') {
            return false;
        }

        $storedHash = (string) ($row['active_session_token'] ?? '');
        if ($storedHash === '' || !hash_equals($storedHash, $tokenHash)) {
            return false;
        }

        if (!empty($row['is_expired'])) {
            self::releaseToken($db, $userId, $tokenHash);
            return false;
        }

        $lastTouch = (int) ($_SESSION['_agap_last_seen_touch'] ?? 0);
        if ((time() - $lastTouch) >= 30) {
            $touch = $db->prepare('UPDATE users SET active_session_last_seen = NOW() WHERE user_id = ? AND active_session_token = ?');
            $touch->execute([$userId, $tokenHash]);
            $_SESSION['_agap_last_seen_touch'] = time();
        }

        return true;
    }

    public static function enforceForApi(?PDO $db = null): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }

        $conn = $db ?? (new Database())->connect();
        $valid = self::isCurrentSessionValid($conn);
        if ($db === null) {
            $conn = null;
        }

        if ($valid) {
            return;
        }

        self::destroyPhpSession();
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Agap-Session: invalid');
        echo json_encode([
            'success' => false,
            'code' => 'SESSION_INVALID',
            'message' => 'Your session has expired or is no longer active. Please sign in again.',
        ]);
        exit;
    }

    public static function enforceForPage(?PDO $db = null): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (str_contains($script, '/frontend/pages/auth/') || str_contains($script, '/frontend/pages/landing/')) {
            return;
        }

        $conn = $db ?? (new Database())->connect();
        $valid = self::isCurrentSessionValid($conn);
        if ($db === null) {
            $conn = null;
        }

        if ($valid) {
            return;
        }

        $roleName = (string) ($_SESSION['role_name'] ?? '');
        $isResidentPortal = ($roleName === 'Resident') || str_ends_with($script, '/resident-portal.php');

        self::destroyPhpSession();

        $basePos = strpos($script, '/frontend/pages/');
        $target = $isResidentPortal
            ? '/frontend/pages/complaints/resident-portal.php?error=session_expired'
            : '/frontend/pages/auth/login.php?error=session_expired';
        $redirectUrl = ($basePos !== false)
            ? substr($script, 0, $basePos) . $target
            : ($isResidentPortal ? '../complaints/resident-portal.php?error=session_expired' : '../auth/login.php?error=session_expired');

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    public static function destroyPhpSession(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true)
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function releaseToken(PDO $db, int $userId, string $tokenHash): void
    {
        try {
            $stmt = $db->prepare('UPDATE users SET active_session_token = NULL, active_session_last_seen = NULL WHERE user_id = ? AND active_session_token = ?');
            $stmt->execute([$userId, $tokenHash]);
        } catch (Throwable $e) {
            error_log('SingleSessionService::releaseToken warning: ' . $e->getMessage());
        }
    }
}
