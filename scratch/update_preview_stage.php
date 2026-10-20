<?php
$previewFile = __DIR__ . '/../backend/api/paanyaya/preview.php';
$content = file_get_contents($previewFile);
if (strpos($content, "'stage_type'") === false) {
    $target = "if (!empty(\$_GET['attempt_number'])) {";
    $replacement = "if (!empty(\$_GET['stage_type'])) {\n    \$options['stage_type'] = trim((string) \$_GET['stage_type']);\n}\nif (!empty(\$_GET['attempt_number'])) {";
    $content = str_replace($target, $replacement, $content);
    file_put_contents($previewFile, $content);
    echo "Updated preview.php\n";
} else {
    echo "preview.php already has stage_type\n";
}

$pdfFile = __DIR__ . '/../backend/services/PDFService.php';
$pdfContent = file_get_contents($pdfFile);
if (strpos($pdfContent, "paanyaya_stages WHERE complaint_id") === false) {
    $target = "// Fetch latest paanyaya session record if exists";
    $replacement = "// Check if specific Paanyaya stage is requested\n        \$stageType = \$options['stage_type'] ?? null;\n        if (\$stageType) {\n            \$stStmt = \$this->conn->prepare('SELECT * FROM paanyaya_stages WHERE complaint_id = ? AND stage_type = ? LIMIT 1');\n            \$stStmt->execute([\$complaintId, \$stageType]);\n            \$stageData = \$stStmt->fetch(PDO::FETCH_ASSOC);\n            if (\$stageData) {\n                \$paanyaya = \$stageData;\n                if (empty(\$options['invitation_date']) && !empty(\$stageData['invitation_date'])) \$options['invitation_date'] = \$stageData['invitation_date'];\n                if (empty(\$options['invitation_time']) && !empty(\$stageData['invitation_time'])) \$options['invitation_time'] = \$stageData['invitation_time'];\n                if (empty(\$options['venue']) && !empty(\$stageData['venue'])) \$options['venue'] = \$stageData['venue'];\n                if (empty(\$options['salaysay']) && !empty(\$stageData['salaysay'])) \$options['salaysay'] = \$stageData['salaysay'];\n                if (!isset(\$options['has_e_signature']) && isset(\$stageData['has_e_signature'])) \$options['has_e_signature'] = (bool)\$stageData['has_e_signature'];\n                if (empty(\$options['attempt_number'])) \$options['attempt_number'] = (\$stageType === 'Paanyaya 2') ? 2 : 1;\n            }\n        }\n\n        // Fetch latest paanyaya session record if exists";
    $pdfContent = str_replace($target, $replacement, $pdfContent);
    file_put_contents($pdfFile, $pdfContent);
    echo "Updated PDFService.php\n";
} else {
    echo "PDFService.php already has paanyaya_stages\n";
}
