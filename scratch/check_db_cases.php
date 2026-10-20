<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();
$rows = $db->query("
    SELECT co.complaint_id, co.complaint_number, co.status, cs.case_id, cs.case_number 
    FROM complaints co 
    LEFT JOIN cases cs ON cs.complaint_id = co.complaint_id 
    ORDER BY co.complaint_id DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
