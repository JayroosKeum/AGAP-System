<?php
declare(strict_types=1);
$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbName = 'remastered_db';
$dbUser = 'root';
$dbPass = '';
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $exception) {
    http_response_code(500);
    exit('Database connection failed. Check backend/config/database.php.');
}
