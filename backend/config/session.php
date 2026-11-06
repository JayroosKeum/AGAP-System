<?php

/**
 * Unified Session Configuration & Initializer for AGAP
 * Ensures consistent session cookie parameters across environments,
 * especially behind Azure App Service reverse proxies with HTTPS.
 */

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['HTTP_X_ARR_SSL']));

    if (!headers_sent()) {
        ini_set('session.cookie_path', '/');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        if ($isSecure) {
            ini_set('session.cookie_secure', '1');
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    @session_start();
}
