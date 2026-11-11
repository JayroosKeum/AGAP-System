<?php

final class AiRequestRateLimit
{
    public static function consume(int $limit = 10, int $windowSeconds = 300): bool
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $scopeKey = $userId > 0 ? ('session_user_' . $userId) : ('session_' . session_id());
        $res = self::consumeScoped($scopeKey, $limit, $windowSeconds);
        return !empty($res['allowed']);
    }

    /**
     * Enforces an atomic per-user/per-scope rate limit backed by both session and temp lock storage
     * so clearing cookies cannot reset Resident Gemini quotas.
     */
    public static function consumeScoped(string $scopeKey, int $limit, int $windowSeconds): array
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $now = time();
        $safeHash = hash('sha256', $scopeKey);
        $sessBucketKey = 'ai_rl_' . substr($safeHash, 0, 16);

        // Session layer fast check
        $sessState = $_SESSION[$sessBucketKey] ?? ['start' => $now, 'count' => 0];
        if (!is_array($sessState) || (int) ($sessState['start'] ?? 0) <= ($now - $windowSeconds)) {
            $sessState = ['start' => $now, 'count' => 0];
        }

        // File lock layer keyed by scopeKey (persists across sessions/cookies for the same account)
        $rlDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agap_ai_rate_limits';
        if (!is_dir($rlDir)) {
            @mkdir($rlDir, 0775, true);
        }
        $rlFile = $rlDir . DIRECTORY_SEPARATOR . $safeHash . '.json';

        $fileState = $sessState;
        $fh = @fopen($rlFile, 'c+');
        if ($fh !== false && @flock($fh, LOCK_EX)) {
            $raw = stream_get_contents($fh);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded) && (int) ($decoded['start'] ?? 0) > ($now - $windowSeconds)) {
                $fileState = [
                    'start' => (int) $decoded['start'],
                    'count' => max((int) ($decoded['count'] ?? 0), (int) $sessState['count']),
                ];
            } else {
                $fileState = ['start' => $now, 'count' => 0];
            }

            if ($fileState['count'] >= $limit) {
                $retryAfter = max(1, ($fileState['start'] + $windowSeconds) - $now);
                @flock($fh, LOCK_UN);
                @fclose($fh);
                $_SESSION[$sessBucketKey] = $fileState;
                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'limit' => $limit,
                    'retry_after' => $retryAfter,
                ];
            }

            $fileState['count']++;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($fileState));
            fflush($fh);
            @flock($fh, LOCK_UN);
            @fclose($fh);
            $_SESSION[$sessBucketKey] = $fileState;

            return [
                'allowed' => true,
                'remaining' => max(0, $limit - $fileState['count']),
                'limit' => $limit,
                'retry_after' => 0,
            ];
        }
        if ($fh !== false) {
            @fclose($fh);
        }

        // Fallback to session-only bucket if temp filesystem is locked down
        if ($sessState['count'] >= $limit) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'limit' => $limit,
                'retry_after' => max(1, ($sessState['start'] + $windowSeconds) - $now),
            ];
        }
        $sessState['count']++;
        $_SESSION[$sessBucketKey] = $sessState;

        return [
            'allowed' => true,
            'remaining' => max(0, $limit - $sessState['count']),
            'limit' => $limit,
            'retry_after' => 0,
        ];
    }
}
