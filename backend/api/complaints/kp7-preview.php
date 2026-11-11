<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../services/PDFService.php';
require_once __DIR__ . '/../../services/AuditService.php';

$db = (new Database())->connect();
$roleName = agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'Summons Server', 'BPSO', 'LISO', 'Resident'], $db);
$actorUserId = agapSessionUserId();

$complaintId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid complaint ID.']);
    exit;
}

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

if ($roleName === 'Resident') {
    if (!agapResidentOwnsComplaint($db, (int) $complaintId, $actorUserId)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are only permitted to view KP Form 7 for your own filed complaints.']);
        exit;
    }
}

$complaintStatus = trim((string) ($complaint['status'] ?? ''));
$isKp7PendingReview = ((int) ($complaint['is_draft'] ?? 0) === 1)
    || in_array($complaintStatus, ['Under Review', 'Needs Information', 'Rejected'], true)
    || (strcasecmp((string) ($complaint['complaint_source'] ?? ''), 'Online') === 0 && strcasecmp($complaintStatus, 'Filed') === 0 && empty($complaint['case_id']) && empty($complaint['generated_kp7_path']));

if ($isKp7PendingReview) {
    $isJsonReq = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (isset($_GET['format']) && $_GET['format'] === 'json');
    $msg = ($complaintStatus === 'Rejected')
        ? 'KP Form 7 was not generated because this complaint was declined during review.'
        : 'KP Form 7 is not generated yet while this complaint is still under review.';
    http_response_code(403);
    if ($isJsonReq) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'code' => 'KP7_PENDING_REVIEW', 'message' => $msg]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>KP Form 7 Pending Review</title>'
            . '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;color:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;}'
            . '.box{max-width:460px;background:#fff;border:1px solid #cbd5e1;border-radius:12px;padding:24px;box-shadow:0 8px 24px rgba(15,23,42,.06);}'
            . 'h1{margin:0 0 8px;font-size:1.1rem;color:#0f172a;}p{margin:0;font-size:.92rem;line-height:1.5;color:#475569;}</style></head>'
            . '<body><div class="box"><h1>KP Form 7 Not Yet Generated</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
    }
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
