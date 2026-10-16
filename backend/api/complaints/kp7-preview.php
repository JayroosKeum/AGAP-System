<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/PDFService.php';
require_once __DIR__ . '/../../services/AuditService.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$complaintId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid complaint ID.']);
    exit;
}

$db = (new Database())->connect();

$stmt = $db->prepare("
    SELECT c.*, cat.category_name, cs.case_id, cs.case_number, cs.case_status,
           TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS encoded_by_name
    FROM complaints c
    LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
    LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
    LEFT JOIN users u ON u.user_id = c.encoded_by
    WHERE c.complaint_id = ?
");
$stmt->execute([$complaintId]);
$complaint = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$complaint) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Complaint not found.']);
    exit;
}

// Fetch parties with details
$partyStmt = $db->prepare("
    SELECT cp.*,
           TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name,
           r.address, r.purok, r.contact_no, r.birth_date AS res_birth_date,
           r.gender AS res_gender, r.civil_status AS res_civil_status
    FROM complaint_parties cp
    INNER JOIN residents r ON r.resident_id = cp.resident_id
    WHERE cp.complaint_id = ?
    ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name
");
$partyStmt->execute([$complaintId]);
$parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);

$complainants = [];
$respondents = [];
foreach ($parties as $p) {
    $item = [
        'resident_id' => (int) $p['resident_id'],
        'party_type' => $p['party_type'],
        'full_name' => $p['full_name'],
        'address' => trim(implode(', ', array_filter([$p['address'] ?? '', $p['purok'] ?? '']))),
        'is_minor' => (int) ($p['is_minor'] ?? 0),
        'guardian_name' => $p['guardian_name'] ?? null,
        'guardian_relationship' => $p['guardian_relationship'] ?? null,
        'guardian_contact' => $p['guardian_contact'] ?? null,
        'guardian_address' => $p['guardian_address'] ?? null,
    ];
    if ($p['party_type'] === 'Complainant') {
        $complainants[] = $item;
    } elseif ($p['party_type'] === 'Respondent') {
        $respondents[] = $item;
    }
}

// Prepare data for template
$data = [
    'complaint_id' => (int) $complaint['complaint_id'],
    'complaint_number' => $complaint['case_number'] ?? $complaint['complaint_number'] ?? sprintf('%02d-%02d-%s', (int) date('m'), $complaintId, date('Y')),
    'case_number' => $complaint['case_number'] ?? '',
    'complaint_title' => $complaint['complaint_title'],
    'category_name' => $complaint['category_name'] ?? '',
    'narrative' => $complaint['narrative'],
    'additional_details' => $complaint['additional_details'] ?? '',
    'requested_resolution' => $complaint['requested_resolution'] ?? '',
    'incident_date' => $complaint['incident_date'],
    'incident_time' => $complaint['incident_time'],
    'is_time_approximate' => (int) ($complaint['is_time_approximate'] ?? 0),
    'is_time_unknown' => (int) ($complaint['is_time_unknown'] ?? 0),
    'incident_location' => $complaint['incident_location'] ?? trim(implode(', ', array_filter([$complaint['incident_street'] ?? '', $complaint['incident_purok'] ?? '', $complaint['incident_barangay'] ?? '', $complaint['incident_city'] ?? '']))),
    'incident_landmark' => $complaint['incident_landmark'] ?? '',
    'filed_date' => $complaint['created_at'],
    'created_at' => $complaint['created_at'],
    'official_name' => 'Tanggapan ng Lupong Tagapamayapa',
    'complainants' => $complainants,
    'respondents' => $respondents,
];

$pdfService = new PDFService();
$audit = new AuditService();

// Download PDF
if (isset($_GET['download']) && (string)$_GET['download'] === '1') {
    $audit->log((int) $_SESSION['user_id'], 'Downloaded KP Form 7 PDF', 'Complaints', $complaintId);

    $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $data['case_number'] ?: $data['complaint_number']);
    $tmpPdfPath = sys_get_temp_dir() . '/KP7_' . $safeName . '_' . time() . '.pdf';

    try {
        $pdfService->generateKp7($data, $tmpPdfPath);
        if (file_exists($tmpPdfPath)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="AGAP_KP_Form_7_' . $safeName . '.pdf"');
            header('Content-Length: ' . filesize($tmpPdfPath));
            readfile($tmpPdfPath);
            @unlink($tmpPdfPath);
            exit;
        }
    } catch (Throwable $e) {
        if (file_exists($tmpPdfPath)) @unlink($tmpPdfPath);
        http_response_code(500);
        echo 'Error generating PDF: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

$html = $pdfService->renderKp7Html($data);

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
    (isset($_GET['format']) && $_GET['format'] === 'json');

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'complaint_id' => $complaintId,
        'complaint_number' => $data['complaint_number'],
        'case_number' => $data['case_number'],
        'html' => $html,
        'download_url' => '../../../backend/api/complaints/kp7-preview.php?id=' . $complaintId . '&download=1'
    ]);
    exit;
}

// Direct HTML preview
header('Content-Type: text/html; charset=utf-8');
echo $html;
exit;
