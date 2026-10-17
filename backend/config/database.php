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


    private $host = "localhost";
    private $dbname = "agap_db";
    private $username = "root";
    private $password = "";

    public function connect()
    {
        try {

            $pdo = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                $this->username,
                $this->password
            );

            $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

            return $pdo;

        } catch(PDOException $e) {

            die("Database Error: " . $e->getMessage());

        }
    }
}
