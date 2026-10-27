<?php
date_default_timezone_set('Asia/Manila');

// All database-backed API mutations pass through this configuration file. Keep
// CSRF enforcement here so new routes do not depend on each controller author
// remembering to add a route-local check.
$agapScriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (PHP_SAPI !== 'cli' && str_contains($agapScriptPath, '/backend/api/')) {
    require_once __DIR__ . '/../services/CsrfService.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    CsrfService::enforceForApiRequest();
}

class Database {

    // Environment settings make the application portable between Laragon and
    // managed MySQL services such as Azure Database for MySQL. The defaults
    // preserve the existing local-development experience.
    private $host;
    private $port;
    private $dbname;
    private $username;
    private $password;
    private $sslCa;

    public function __construct()
    {
        $this->host = $this->environment('DB_HOST', 'localhost');
        $this->port = $this->environment('DB_PORT', '3306');
        $this->dbname = $this->environment('DB_NAME', 'agap_db');
        $this->username = $this->environment('DB_USER', 'root');
        $this->password = $this->environment('DB_PASS', '');
        $this->sslCa = $this->environment('DB_SSL_CA', '');
    }

    public function connect()
    {
        try {

            $port = filter_var($this->port, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]);
            if ($port === false) {
                throw new PDOException('Database port must be between 1 and 65535.');
            }

            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
            if ($this->sslCa !== '') {
                if (!is_readable($this->sslCa)) {
                    throw new PDOException('The configured database SSL CA file is not readable.');
                }
                if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $this->sslCa;
                }
            }

            $pdo = new PDO(
                "mysql:host={$this->host};port={$port};dbname={$this->dbname};charset=utf8mb4",
                $this->username,
                $this->password,
                $options
            );

            return $pdo;

        } catch(PDOException $e) {

            die("Database Error: " . $e->getMessage());

        }
    }

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
