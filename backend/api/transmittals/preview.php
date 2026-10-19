<?php

session_start();

if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 3, 4], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid or missing case ID.']);
    exit;
}

require_once __DIR__ . '/../../services/PDFService.php';

$returnHtml = isset($_GET['html']) && in_array((string) $_GET['html'], ['1', 'true'], true);
$download = isset($_GET['download']) && in_array((string) $_GET['download'], ['1', 'true'], true);

try {
    $pdfService = new PDFService();
    $output = $pdfService->generateTransmittalLetter($caseId, [
        'return_html' => $returnHtml
    ]);

    if ($returnHtml) {
        header('Content-Type: text/html; charset=UTF-8');
        echo $output;
        exit;
    }

    $caseStmt = (new Database())->connect()->prepare("SELECT case_number FROM cases WHERE case_id = ?");
    $caseStmt->execute([$caseId]);
    $caseNumber = $caseStmt->fetchColumn() ?: "Case-{$caseId}";
    $filename = sprintf('Transmittal-Letter-MTC-DILG-%s.pdf', preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber));

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $output;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Failed to generate transmittal letter: ' . $e->getMessage()
    ]);
    exit;
}
