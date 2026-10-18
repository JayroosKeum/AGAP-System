<?php

final class AiRequestRateLimit
{
    public static function consume(int $limit = 10, int $windowSeconds = 300): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $windowStarted = (int) ($_SESSION['ai_request_window_started'] ?? 0);
        $requests = (int) ($_SESSION['ai_requests'] ?? 0);
        $now = time();

        if ($windowStarted < ($now - $windowSeconds)) {
            $windowStarted = $now;
            $requests = 0;
        }
        if ($requests >= $limit) {
            return false;
        }

        $_SESSION['ai_request_window_started'] = $windowStarted;
        $_SESSION['ai_requests'] = $requests + 1;
        return true;
    }
}
