<?php

require_once __DIR__ . '/../config/database.php';

class LoginThrottleService
{
    public const DEFAULT_MAX_ATTEMPTS = 5;
    public const DEFAULT_LOCKOUT_MINUTES = 15;
    private static bool $schemaChecked = false;

    public static function ensureSchema(PDO $db): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    attempt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    scope_type ENUM('username', 'ip') NOT NULL,
                    scope_key VARCHAR(150) NOT NULL,
                    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                    first_failed_at DATETIME NOT NULL,
                    locked_until DATETIME NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_login_attempt_scope (scope_type, scope_key),
                    KEY idx_login_attempts_locked_until (locked_until)
                ) ENGINE=InnoDB
            ");
        } catch (Throwable $e) {
            error_log('LoginThrottleService::ensureSchema warning: ' . $e->getMessage());
        }
    }

    public static function getPolicy(PDO $db): array
    {
        $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;
        $lockoutMinutes = self::DEFAULT_LOCKOUT_MINUTES;

        try {
            $stmt = $db->query("
                SELECT setting_key, setting_value
                FROM system_settings
                WHERE setting_key IN ('max_failed_login_attempts', 'lockout_duration_min')
            ");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['setting_key'] === 'max_failed_login_attempts' && is_numeric($row['setting_value'])) {
                        $maxAttempts = max(3, min(10, (int) $row['setting_value']));
                    } elseif ($row['setting_key'] === 'lockout_duration_min' && is_numeric($row['setting_value'])) {
                        $lockoutMinutes = max(5, min(120, (int) $row['setting_value']));
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('LoginThrottleService::getPolicy warning: ' . $e->getMessage());
        }

        return [
            'max_attempts' => $maxAttempts,
            'lockout_minutes' => $lockoutMinutes,
        ];
    }

    public static function clientIp(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }
        return PHP_SAPI === 'cli' ? '127.0.0.1' : 'unknown';
    }

    public static function checkBlocked(PDO $db, string $username): ?array
    {
        self::ensureSchema($db);
        $userKey = self::normalizeUsernameKey($username);
        $ipKey = self::clientIp();

        $stmt = $db->prepare("
            SELECT scope_type, TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS remaining_seconds
            FROM login_attempts
            WHERE ((scope_type = 'username' AND scope_key = ?) OR (scope_type = 'ip' AND scope_key = ?))
              AND locked_until IS NOT NULL
              AND locked_until > NOW()
            ORDER BY remaining_seconds DESC
            LIMIT 1
        ");
        $stmt->execute([$userKey, $ipKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $seconds = max(1, (int) ($row['remaining_seconds'] ?? 60));
        return [
            'blocked' => true,
            'scope' => (string) ($row['scope_type'] ?? 'username'),
            'retry_after_seconds' => $seconds,
            'retry_after_minutes' => (int) ceil($seconds / 60),
        ];
    }

    public static function recordFailure(PDO $db, string $username): ?array
    {
        self::ensureSchema($db);
        $policy = self::getPolicy($db);
        $maxUser = (int) $policy['max_attempts'];
        $maxIp = $maxUser * 3;
        $lockoutMin = (int) $policy['lockout_minutes'];

        $userKey = self::normalizeUsernameKey($username);
        if ($userKey !== '') {
            self::incrementScope($db, 'username', $userKey, $maxUser, $lockoutMin);
        }
        $ipKey = self::clientIp();
        if ($ipKey !== '') {
            self::incrementScope($db, 'ip', $ipKey, $maxIp, $lockoutMin);
        }

        return self::checkBlocked($db, $username);
    }

    public static function clearFailures(PDO $db, string $username): void
    {
        self::ensureSchema($db);
        $userKey = self::normalizeUsernameKey($username);
        if ($userKey === '') {
            return;
        }
        try {
            $stmt = $db->prepare("DELETE FROM login_attempts WHERE scope_type = 'username' AND scope_key = ?");
            $stmt->execute([$userKey]);
        } catch (Throwable $e) {
            error_log('LoginThrottleService::clearFailures warning: ' . $e->getMessage());
        }
    }

    private static function incrementScope(PDO $db, string $scopeType, string $scopeKey, int $threshold, int $lockoutMin): void
    {
        $threshold = max(1, $threshold);
        $lockoutMin = max(1, $lockoutMin);

        $sql = "
            INSERT INTO login_attempts (scope_type, scope_key, failed_attempts, first_failed_at, locked_until)
            VALUES (?, ?, 1, NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                failed_attempts = CASE
                    WHEN (locked_until IS NOT NULL AND locked_until <= NOW())
                      OR first_failed_at < DATE_SUB(NOW(), INTERVAL {$lockoutMin} MINUTE)
                    THEN 1
                    ELSE failed_attempts + 1
                END,
                first_failed_at = CASE
                    WHEN (locked_until IS NOT NULL AND locked_until <= NOW())
                      OR first_failed_at < DATE_SUB(NOW(), INTERVAL {$lockoutMin} MINUTE)
                    THEN NOW()
                    ELSE first_failed_at
                END,
                locked_until = CASE
                    WHEN (
                        CASE
                            WHEN (locked_until IS NOT NULL AND locked_until <= NOW())
                              OR first_failed_at < DATE_SUB(NOW(), INTERVAL {$lockoutMin} MINUTE)
                            THEN 1
                            ELSE failed_attempts + 1
                        END
                    ) >= {$threshold}
                    THEN DATE_ADD(NOW(), INTERVAL {$lockoutMin} MINUTE)
                    ELSE NULL
                END
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$scopeType, $scopeKey]);
    }

    private static function normalizeUsernameKey(string $username): string
    {
        return mb_substr(mb_strtolower(trim($username), 'UTF-8'), 0, 150, 'UTF-8');
    }
}
