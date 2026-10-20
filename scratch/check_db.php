<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();
echo "ROLES:\n";
print_r($db->query('SELECT role_id, role_name FROM roles')->fetchAll(PDO::FETCH_ASSOC));

echo "\nUSERS:\n";
print_r($db->query('SELECT user_id, username, role_id, first_name, last_name FROM users')->fetchAll(PDO::FETCH_ASSOC));

echo "\nPAANYAYA TABLES:\n";
$tables = $db->query("SHOW TABLES LIKE '%paanyaya%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);
foreach ($tables as $t) {
    echo "\nDESCRIBE $t:\n";
    print_r($db->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC));
}
