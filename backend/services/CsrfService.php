<?php

/**
 * Session-bound CSRF protection for state-changing browser requests.
 */
final class CsrfService
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validateRequest(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        $expected = $_SESSION[self::SESSION_KEY] ?? '';

        return is_string($provided)
            && is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $provided);
    }

    /** Returns true for fetch/XHR/curl/JSON callers rather than browser document navigations or plain HTML forms. */
    public static function expectsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if ($xhr === 'xmlhttprequest') {
            return true;
        }
        if (str_contains($accept, 'application/json') || str_contains($contentType, 'application/json')) {
            return true;
        }
        if (($_GET['format'] ?? '') === 'json') {
            return true;
        }

        return !str_contains($accept, 'text/html');
    }

    public static function portalHomePath(string $frontendPagesPrefix = '../../../frontend/pages/'): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (($_SESSION['role_name'] ?? '') === 'Resident') {
            return $frontendPagesPrefix . 'resident/dashboard.php';
        }
        return match ((int) ($_SESSION['role_id'] ?? 0)) {
            1 => $frontendPagesPrefix . 'dashboard/admin-dashboard.php',
            2 => $frontendPagesPrefix . 'dashboard/clerk-dashboard.php',
            3 => $frontendPagesPrefix . 'dashboard/lupon-dashboard.php',
            4 => $frontendPagesPrefix . 'dashboard/server-dashboard.php',
            default => $frontendPagesPrefix . 'auth/login.php',
        };
    }

    public static function redirectBrowser(string $location, int $status = 303): never
    {
        header('Location: ' . $location, true, $status);
        exit;
    }

    /** Rejects unsafe API requests unless they carry the current session token. */
    public static function enforceForApiRequest(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        if (self::validateRequest()) {
            return;
        }

        if (!self::expectsJson()) {
            $fallback = self::portalHomePath();
            if (str_ends_with($fallback, 'auth/login.php')) {
                $fallback .= '?error=session_expired';
            }
            self::redirectBrowser($fallback);
        }

        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Invalid or missing request token.']);
        exit;
    }
}
