<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/PDFService.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$complaintId = filter_var($_GET['id'] ?? ($_GET['complaint_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid complaint ID.']);
    exit;
}

$download = isset($_GET['download']) && in_array((string) $_GET['download'], ['1', 'true'], true);
$format = $_GET['format'] ?? 'html';

// Allow optional live parameter overrides from modal preview
$options = [];
if (!empty($_GET['invitation_date'])) {
    $options['invitation_date'] = trim((string) $_GET['invitation_date']);
}
if (!empty($_GET['invitation_time'])) {
    $options['invitation_time'] = trim((string) $_GET['invitation_time']);
}
if (!empty($_GET['venue'])) {
    $options['venue'] = trim((string) $_GET['venue']);
}
if (!empty($_GET['salaysay'])) {
    $options['salaysay'] = trim((string) $_GET['salaysay']);
}
if (isset($_GET['has_e_signature'])) {
    $options['has_e_signature'] = in_array((string)$_GET['has_e_signature'], ['1', 'true'], true);
}
if (!empty($_GET['attempt_number'])) {
    $options['attempt_number'] = (int) $_GET['attempt_number'];
}

try {
    $pdfService = new PDFService();

    if ($download || $format === 'pdf') {
        $pdf = $pdfService->generatePaanyaya($complaintId, $options);
        $filename = sprintf('Paanyaya-Complaint-%d.pdf', $complaintId);

        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdf;
        exit;
    }

    $html = $pdfService->renderPaanyayaHtml($complaintId, $options);
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Failed to render Paanyaya: ' . $e->getMessage()
    ]);
    exit;
}
