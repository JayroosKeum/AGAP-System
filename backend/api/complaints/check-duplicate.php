<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';

header('Content-Type: application/json; charset=utf-8');

$db = (new Database())->connect();
$roleName = agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'BPSO', 'LISO', 'Resident'], $db);
$userId = agapSessionUserId();

$req = array_merge($_GET, $_POST);
$referenceId = trim((string) ($req['reference_id'] ?? ($req['complaint_number'] ?? ($req['q'] ?? ''))));
$compId = filter_var($req['complainant_resident_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$respId = filter_var($req['respondent_resident_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$contactQuery = trim((string) ($req['contact_no'] ?? ($req['complainant_contact_no'] ?? '')));
$incidentDate = trim((string) ($req['incident_date'] ?? ''));
$excludeId = filter_var($req['exclude_complaint_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

$complaintCols = [];
try {
    $complaintCols = $db->query('SHOW COLUMNS FROM complaints')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
}
$hasSourceCol = in_array('complaint_source', $complaintCols, true);
$hasCreatedByCol = in_array('created_by', $complaintCols, true);
$sourceSelect = $hasSourceCol ? 'c.complaint_source,' : "'Walk-in' AS complaint_source,";

// Privacy guard: Resident users can ONLY check duplicates among their own prior filings.
if ($roleName === 'Resident') {
    $ownerCol = $hasCreatedByCol ? '(c.created_by = ? OR c.encoded_by = ?)' : 'c.encoded_by = ?';
    $ownerParams = $hasCreatedByCol ? [$userId, $userId] : [$userId];
    $sql = "
        SELECT c.complaint_id, c.complaint_number, c.complaint_title, c.incident_date, c.status, c.created_at,
               {$sourceSelect} cs.case_number, cs.case_status
        FROM complaints c
        LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
        WHERE {$ownerCol}
          AND c.status NOT IN ('Archived', 'Rejected')
    ";
    $params = $ownerParams;
    if ($referenceId !== '') {
        $sql .= ' AND c.complaint_number = ?';
        $params[] = $referenceId;
    } elseif ($incidentDate !== '') {
        $sql .= ' AND c.incident_date = ?';
        $params[] = $incidentDate;
    } else {
        echo json_encode(['has_duplicate' => false, 'duplicates' => []]);
        exit;
    }
    $sql .= ' ORDER BY c.created_at DESC LIMIT 5';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $duplicates = array_map(static function (array $r): array {
        return [
            'complaint_id' => (int) $r['complaint_id'],
            'complaint_number' => $r['complaint_number'],
            'complaint_title' => $r['complaint_title'],
            'complaint_source' => $r['complaint_source'] ?? 'Online',
            'status' => $r['case_status'] ?: $r['status'],
            'incident_date' => $r['incident_date'],
            'created_at' => $r['created_at'],
            'match_reason' => 'Existing filing under your account',
        ];
    }, $rows);
    echo json_encode(['has_duplicate' => count($duplicates) > 0, 'duplicates' => $duplicates]);
    exit;
}

// Staff lookup (Administrator, Lupon Clerk, BPSO, LISO)
$rows = [];
$matchTag = 'Existing active record';

// 1. Direct lookup by Complaint Reference ID / Number (e.g., Online resident walks in with Reference ID)
if ($referenceId !== '') {
    $sql = "
        SELECT c.complaint_id, c.complaint_number, c.complaint_title, c.incident_date, c.status, c.created_at,
               {$sourceSelect} cs.case_number, cs.case_status,
               GROUP_CONCAT(DISTINCT CASE WHEN cp.party_type = 'Complainant' THEN TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) END SEPARATOR ', ') AS complainant_names,
               GROUP_CONCAT(DISTINCT CASE WHEN cp.party_type = 'Respondent' THEN TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) END SEPARATOR ', ') AS respondent_names
        FROM complaints c
        LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
        LEFT JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id
        LEFT JOIN residents r ON r.resident_id = cp.resident_id
        WHERE (c.complaint_number LIKE ? OR c.complaint_title LIKE ? OR CONCAT_WS(' ', r.first_name, r.last_name) LIKE ? OR r.contact_no LIKE ?)
          AND c.status NOT IN ('Archived', 'Rejected')
    ";
    $like = '%' . $referenceId . '%';
    $params = [$like, $like, $like, $like];
    if ($excludeId) {
        $sql .= ' AND c.complaint_id != ?';
        $params[] = $excludeId;
    }
    $sql .= ' GROUP BY c.complaint_id ORDER BY c.created_at DESC LIMIT 8';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $matchTag = 'Matched by Reference ID, party name, title, or contact';
} else {
    if (!$compId && !empty($req['complainant_first_name']) && !empty($req['complainant_last_name'])) {
        $s = $db->prepare('SELECT resident_id FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1');
        $s->execute([trim((string) $req['complainant_first_name']), trim((string) $req['complainant_last_name'])]);
        $compId = $s->fetchColumn() ?: null;
    }
    if (!$respId && !empty($req['respondent_first_name']) && !empty($req['respondent_last_name'])) {
        $s = $db->prepare('SELECT resident_id FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1');
        $s->execute([trim((string) $req['respondent_first_name']), trim((string) $req['respondent_last_name'])]);
        $respId = $s->fetchColumn() ?: null;
    }

    if ($compId && $respId) {
        $sql = "
            SELECT c.complaint_id, c.complaint_number, c.complaint_title, c.incident_date, c.status, c.created_at,
                   {$sourceSelect} cs.case_number, cs.case_status,
                   GROUP_CONCAT(DISTINCT CASE WHEN cp_all.party_type = 'Complainant' THEN TRIM(CONCAT_WS(' ', r_all.first_name, r_all.last_name)) END SEPARATOR ', ') AS complainant_names,
                   GROUP_CONCAT(DISTINCT CASE WHEN cp_all.party_type = 'Respondent' THEN TRIM(CONCAT_WS(' ', r_all.first_name, r_all.last_name)) END SEPARATOR ', ') AS respondent_names
            FROM complaints c
            INNER JOIN complaint_parties cp1 ON cp1.complaint_id = c.complaint_id AND cp1.resident_id = ? AND cp1.party_type = 'Complainant'
            INNER JOIN complaint_parties cp2 ON cp2.complaint_id = c.complaint_id AND cp2.resident_id = ? AND cp2.party_type = 'Respondent'
            LEFT JOIN complaint_parties cp_all ON cp_all.complaint_id = c.complaint_id
            LEFT JOIN residents r_all ON r_all.resident_id = cp_all.resident_id
            LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
            WHERE c.status NOT IN ('Archived', 'Dismissed', 'Rejected')
        ";
        $params = [$compId, $respId];
        if ($excludeId) {
            $sql .= ' AND c.complaint_id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' GROUP BY c.complaint_id ORDER BY c.created_at DESC LIMIT 5';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $matchTag = 'Existing active record involving the same complainant and respondent';
    } elseif ($contactQuery !== '' && strlen($contactQuery) >= 7) {
        $sql = "
            SELECT c.complaint_id, c.complaint_number, c.complaint_title, c.incident_date, c.status, c.created_at,
                   {$sourceSelect} cs.case_number, cs.case_status,
                   GROUP_CONCAT(DISTINCT CASE WHEN cp.party_type = 'Complainant' THEN TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) END SEPARATOR ', ') AS complainant_names,
                   GROUP_CONCAT(DISTINCT CASE WHEN cp.party_type = 'Respondent' THEN TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) END SEPARATOR ', ') AS respondent_names
            FROM complaints c
            INNER JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
            WHERE r.contact_no = ?
              AND c.status NOT IN ('Archived', 'Rejected')
        ";
        $params = [$contactQuery];
        if ($excludeId) {
            $sql .= ' AND c.complaint_id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' GROUP BY c.complaint_id ORDER BY c.created_at DESC LIMIT 5';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $matchTag = 'Existing complaint linked to the same phone number';
    }
}

$duplicates = [];
foreach ($rows as $r) {
    $reason = $matchTag;
    if (!empty($incidentDate) && ($r['incident_date'] ?? '') === $incidentDate) {
        $reason .= ' on the same incident date (' . $incidentDate . ')';
    }
    $duplicates[] = [
        'complaint_id' => (int) $r['complaint_id'],
        'complaint_number' => $r['complaint_number'],
        'case_number' => $r['case_number'] ?? null,
        'complaint_title' => $r['complaint_title'],
        'complaint_source' => $r['complaint_source'] ?? 'Walk-in',
        'complainant_names' => $r['complainant_names'] ?? '',
        'respondent_names' => $r['respondent_names'] ?? '',
        'status' => $r['case_status'] ?: $r['status'],
        'incident_date' => $r['incident_date'],
        'created_at' => $r['created_at'],
        'match_reason' => $reason,
    ];
}

echo json_encode([
    'has_duplicate' => count($duplicates) > 0,
    'duplicates' => $duplicates,
]);
exit;
