<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../services/ResidentNameLookup.php';

header('Content-Type: application/json; charset=utf-8');

$db = (new Database())->connect();
$isResidentPortal = agapGetRoleName($db) === 'Resident';
$isStaffDuplicateAllowed = agapHasRole(['Super Administrator', 'Administrator', 'Lupon Clerk', 'BPSO'], $db);
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || (!$isStaffDuplicateAllowed && !$isResidentPortal)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

// A portal user must not be shown another resident's complaint details. The
// server-side intake handler still performs the duplicate check and routes it
// to a Lupon Member for an authorized screening decision.
if ($isResidentPortal) {
    echo json_encode(['has_duplicate' => false, 'duplicates' => []]);
    exit;
}

$compId = filter_var($_GET['complainant_resident_id'] ?? $_POST['complainant_resident_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$respId = filter_var($_GET['respondent_resident_id'] ?? $_POST['respondent_resident_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$title = trim((string) ($_GET['complaint_title'] ?? $_POST['complaint_title'] ?? ''));
$incidentDate = trim((string) ($_GET['incident_date'] ?? $_POST['incident_date'] ?? ''));
$excludeId = filter_var($_GET['exclude_complaint_id'] ?? $_POST['exclude_complaint_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

$db = (new Database())->connect();

if (!$compId && !empty($_GET['complainant_first_name']) && !empty($_GET['complainant_last_name'])) {
    $compId = ResidentNameLookup::find($db, trim((string) $_GET['complainant_first_name']), trim((string) $_GET['complainant_last_name']));
}
if (!$respId && !empty($_GET['respondent_first_name']) && !empty($_GET['respondent_last_name'])) {
    $respId = ResidentNameLookup::find($db, trim((string) $_GET['respondent_first_name']), trim((string) $_GET['respondent_last_name']));
}

if (!$compId || !$respId) {
    echo json_encode(['has_duplicate' => false, 'duplicates' => []]);
    exit;
}

// Find complaints where both complainant and respondent match
$sql = "
    SELECT c.complaint_id, c.complaint_number, c.complaint_title, c.incident_date, c.status, c.created_at,
           cs.case_number, cs.case_status
    FROM complaints c
    INNER JOIN complaint_parties cp1 ON cp1.complaint_id = c.complaint_id AND cp1.resident_id = ? AND cp1.party_type = 'Complainant'
    INNER JOIN complaint_parties cp2 ON cp2.complaint_id = c.complaint_id AND cp2.resident_id = ? AND cp2.party_type = 'Respondent'
    LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
    WHERE c.status NOT IN ('Archived', 'Dismissed')
";

$params = [$compId, $respId];
if ($excludeId) {
    $sql .= " AND c.complaint_id != ?";
    $params[] = $excludeId;
}
$sql .= " ORDER BY c.created_at DESC LIMIT 5";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$duplicates = [];
foreach ($rows as $r) {
    $reason = 'Existing active record involving the same complainant and respondent';
    if (!empty($incidentDate) && $r['incident_date'] === $incidentDate) {
        $reason .= ' on the same incident date (' . $incidentDate . ')';
    }
    $duplicates[] = [
        'complaint_id' => (int) $r['complaint_id'],
        'complaint_number' => $r['complaint_number'],
        'case_number' => $r['case_number'],
        'complaint_title' => $r['complaint_title'],
        'status' => $r['case_status'] ?: $r['status'],
        'incident_date' => $r['incident_date'],
        'created_at' => $r['created_at'],
        'match_reason' => $reason
    ];
}

echo json_encode([
    'has_duplicate' => count($duplicates) > 0,
    'duplicates' => $duplicates
]);
exit;
