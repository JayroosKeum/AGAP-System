<?php
$path = __DIR__ . '/../backend/models/Paanyaya.php';
$content = file_get_contents($path);

$target = "        \$auditStmt = \$this->conn->prepare(\"
            SELECT al.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS user_name
            FROM audit_logs al
            LEFT JOIN users u ON u.user_id = al.user_id
            WHERE al.complaint_id = ? AND al.action LIKE ?
            ORDER BY al.created_at DESC
        \");";

$replacement = "        \$auditStmt = \$this->conn->prepare(\"
            SELECT at.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS user_name
            FROM audit_trails at
            LEFT JOIN users u ON u.user_id = at.user_id
            WHERE at.affected_record = ? AND at.action LIKE ?
            ORDER BY at.created_at DESC
        \");";

$content = str_replace($target, $replacement, $content);
file_put_contents($path, $content);
echo "Fixed audit table name in Paanyaya.php\n";
