<?php
date_default_timezone_set('Asia/Manila');

// All database-backed API mutations pass through this configuration file. Keep
// CSRF enforcement here so new routes do not depend on each controller author
// remembering to add a route-local check. Public authentication endpoints that
// use credentials or single-use cryptographic tokens are exempted.
$agapScriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (PHP_SAPI !== 'cli' && str_contains($agapScriptPath, '/backend/api/')) {
    $publicAuthEndpoints = [
        '/backend/api/auth/login.php',
        '/backend/api/auth/request-password-reset.php',
        '/backend/api/auth/reset-password.php',
        '/backend/api/auth/verify-email.php',
        '/backend/api/auth/activate.php',
    ];
    $isPublicAuth = false;
    foreach ($publicAuthEndpoints as $endpoint) {
        if (str_ends_with($agapScriptPath, $endpoint)) {
            $isPublicAuth = true;
            break;
        }
    }

    if (!$isPublicAuth) {
        require_once __DIR__ . '/../services/CsrfService.php';
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        CsrfService::enforceForApiRequest();
    }
}

class Database {

    private $host;
    private $port;
    private $dbname;
    private $username;
    private $password;
    private $sslCa;

    public function __construct()
    {
        $this->loadEnv();
        $this->host = $this->environment('DB_HOST', '127.0.0.1');
        $this->port = $this->environment('DB_PORT', '3306');
        $this->dbname = $this->environment('DB_NAME', 'agap_db');
        $this->username = $this->environment('DB_USER', 'root');
        $this->password = $this->environment('DB_PASS', '');
        $this->sslCa = $this->environment('DB_SSL_CA', '');
    }

    public function connect()
    {
        $port = filter_var($this->port, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            $port = 3306;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 4,
        ];
        if ($this->sslCa !== '' && is_readable($this->sslCa) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $this->sslCa;
        }

        try {
            return new PDO(
                "mysql:host={$this->host};port={$port};dbname={$this->dbname};charset=utf8mb4",
                $this->username,
                $this->password,
                $options
            );
        } catch(PDOException $e) {
            // If primary host fails and host was not 127.0.0.1/localhost, try local MySQL fallback
            if ($this->host !== '127.0.0.1' && $this->host !== 'localhost') {
                try {
                    return new PDO(
                        "mysql:host=127.0.0.1;port=3306;dbname=agap_db;charset=utf8mb4",
                        'root',
                        '',
                        $options
                    );
                } catch(PDOException $localEx) {
                    // Keep original exception
                }
            }

            die("Database Error: " . $e->getMessage());
        }
    }

    private static bool $envLoaded = false;
    private static array $envValues = [];

    private function loadEnv(): void
    {
        if (self::$envLoaded) return;
        self::$envLoaded = true;

        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($envFile)) return;

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if ($k !== '') {
                    self::$envValues[$k] = $v;

                    // Web-server configuration (for example Apache SetEnv) is
                    // deployment-specific and must take precedence over local
                    // .env defaults. Overwriting it here made HTTP requests use
                    // the local database even when the virtual host supplied the
                    // Azure database credentials.
                    $serverValue = $_SERVER[$k] ?? ($_ENV[$k] ?? getenv($k));
                    if (!is_string($serverValue) || $serverValue === '') {
                        putenv("$k=$v");
                        $_ENV[$k] = $v;
                        $_SERVER[$k] = $v;
                    }
                }
            }
        }
    }

    private function environment(string $name, string $default): string
    {
        $this->loadEnv();
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            $value = $_ENV[$name] ?? ($_SERVER[$name] ?? '');
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return self::$envValues[$name] ?? $default;
    }
}
