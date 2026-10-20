<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();
print_r($db->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC));
