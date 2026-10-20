<?php

$path = __DIR__ . '/../backend/models/Paanyaya.php';
$content = file_get_contents($path);

$target = "        \$stmt->execute([\$returnId]);
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        return \$row ?: null;";

$replacement = "        \$stmt->execute([\$returnId]);
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        if (!\$row) return null;

        // Fetch audit log corrections for this return
        \$auditStmt = \$this->conn->prepare(\"
            SELECT al.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS user_name
            FROM audit_logs al
            LEFT JOIN users u ON u.user_id = al.user_id
            WHERE al.complaint_id = ? AND al.action LIKE ?
            ORDER BY al.created_at DESC
        \");
        \$auditStmt->execute([(int)\$row['complaint_id'], \"%Edited Officer's Return #{\$returnId}%\"]);
        \$row['corrections'] = \$auditStmt->fetchAll(PDO::FETCH_ASSOC);

        return \$row;";

if (strpos($content, "%Edited Officer's Return #") === false) {
    $content = str_replace($target, $replacement, $content);
    file_put_contents($path, $content);
    echo "Added corrections audit to getOfficerReturnDetails.\n";
} else {
    echo "Already present.\n";
}
