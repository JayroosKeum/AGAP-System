<?php
/**
 * MODIFIED EXISTING FILE
 * Path: backend/api/hearings/attendance.php
 *
 * Retains attendance and show-cause behavior, enforces confirmed service for
 * Mediation, and records the first actual meeting only after attendance saves.
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../models/HearingAttendance.php';
require_once __DIR__ . '/../../services/SummonServiceGate.php';
require_once __DIR__ . '/../../services/MediationCommencementService.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$canManage = in_array((int) $_SESSION['role_id'], [1, 2], true);
$hearingAttendance = new HearingAttendance();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (isset($_GET['hearing_id']) ? (int) $_GET['hearing_id'] : null);
    if ($hearingId) {
        $result = $hearingAttendance->getHearingAttendance((int) $hearingId);
        if (!$result['success']) {
            http_response_code(404);
            echo json_encode($result);
            exit;
        }

        $serviceGate = (new SummonServiceGate())->forHearing((int) $hearingId);
        require_once __DIR__ . '/../../controllers/AssignmentController.php';
        $luponMembers = (new AssignmentController())->luponMembers();

        $payload = array_merge($result['hearing'], [
            'summons_count' => $result['summons_count'],
            'parties' => $result['parties'],
            'situation' => $result['situation'],
            'service_gate' => $serviceGate,
            'lupon_members' => is_array($luponMembers) ? $luponMembers : [],
        ]);

        echo json_encode([
            'success' => true,
            'data' => $payload,
            'hearing' => $result['hearing'],
            'summons_count' => $result['summons_count'],
            'parties' => $result['parties'],
            'situation' => $result['situation'],
            'service_gate' => $serviceGate,
            'lupon_members' => is_array($luponMembers) ? $luponMembers : [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $filters = [
        'stage' => trim((string) ($_GET['stage'] ?? '')),
        'situation' => trim((string) ($_GET['situation'] ?? '')),
        'date_from' => trim((string) ($_GET['date_from'] ?? '')),
        'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        'q' => trim((string) ($_GET['q'] ?? '')),
    ];
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(5, min(100, (int) ($_GET['per_page'] ?? 25)));
    $result = $hearingAttendance->getAttendanceMonitoringList($filters, $page, $perPage);

    echo json_encode([
        'success' => true,
        'data' => ['records' => $result['records'], 'kpis' => $result['kpi']],
        'records' => $result['records'],
        'kpi' => $result['kpi'],
        'kpis' => $result['kpi'],
        'pagination' => $result['pagination'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Administrators and Lupon Clerks can record attendance.']);
        exit;
    }

    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains(strtolower($contentType), 'application/json')) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        if (!is_array($decoded)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'The request body must contain valid JSON.']);
            exit;
        }
        $inputData = $decoded;
    } else {
        $inputData = $_POST;
    }

    $hearingId = filter_var($inputData['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$hearingId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid hearing ID is required.']);
        exit;
    }

    $serviceGate = (new SummonServiceGate())->forHearing((int) $hearingId);
    if (!$serviceGate['allowed']) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => $serviceGate['message'],
            'code' => $serviceGate['code'],
            'service_gate' => $serviceGate,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 1. Enforce Presider Attendance Validation
    if (isset($inputData['presider_attendance'])) {
        $presiderAtt = trim((string) $inputData['presider_attendance']);
        if ($presiderAtt === 'Absent') {
            $substituteId = filter_var($inputData['substitute_presider_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $substituteReason = trim((string) ($inputData['substitute_reason'] ?? ''));
            $consent = !empty($inputData['parties_consent_to_substitute']);

            if (!$substituteId || $substituteReason === '' || !$consent) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Presider attendance validation failed: When the presider is absent, selecting a designated substitute presider, providing the substitution reason, and confirming mutual party consent are required before the session can proceed.'
                ]);
                exit;
            }

            require_once __DIR__ . '/../../controllers/HearingController.php';
            $subResult = (new HearingController())->assignSubstitute([
                'hearing_id' => $hearingId,
                'substitute_presider_id' => $substituteId,
                'substitute_reason' => $substituteReason,
                'parties_consent_to_substitute' => 1
            ], (int) $_SESSION['user_id']);

            if (empty($subResult['success'])) {
                http_response_code(422);
                echo json_encode($subResult);
                exit;
            }
        }
    }

    // 2. Normalize party records from input
    $rawParties = $inputData['parties'] ?? $inputData['records'] ?? $inputData['attendance'] ?? [];
    $parties = [];
    if (is_array($rawParties)) {
        if (!isset($rawParties[0])) {
            foreach ($rawParties as $residentId => $partyData) {
                if (!is_array($partyData)) continue;
                if (!isset($partyData['resident_id'])) $partyData['resident_id'] = $residentId;
                $parties[] = $partyData;
            }
        } else {
            $parties = $rawParties;
        }
    }

    // 3. Extract Complainant & Respondent attendance if not directly keyed
    if ((!isset($inputData['complainant_attendance']) || !isset($inputData['respondent_attendance'])) && !empty($parties)) {
        $stmtCp = (new Database())->connect()->prepare("
            SELECT cp.resident_id, cp.party_type 
            FROM complaint_parties cp
            INNER JOIN cases c ON c.complaint_id = cp.complaint_id
            INNER JOIN hearings h ON h.case_id = c.case_id
            WHERE h.hearing_id = ?
        ");
        $stmtCp->execute([(int) $hearingId]);
        $typeMap = [];
        while ($row = $stmtCp->fetch(PDO::FETCH_ASSOC)) {
            $typeMap[(int) $row['resident_id']] = $row['party_type'];
        }

        foreach ($parties as $p) {
            $rId = (int) ($p['resident_id'] ?? 0);
            $pType = $p['party_type'] ?? ($typeMap[$rId] ?? '');
            $status = $p['attendance_status'] ?? 'Present';
            $reason = trim((string) ($p['justification_reason'] ?? $p['remarks'] ?? ''));

            if ($pType === 'Complainant' && !isset($inputData['complainant_attendance'])) {
                $inputData['complainant_attendance'] = in_array($status, ['Absent', 'Excused'], true) ? 'Absent' : 'Present';
                if ($reason !== '') $inputData['complainant_explanation'] = $reason;
            } elseif ($pType === 'Respondent' && !isset($inputData['respondent_attendance'])) {
                $inputData['respondent_attendance'] = in_array($status, ['Absent', 'Excused'], true) ? 'Absent' : 'Present';
                if ($reason !== '') $inputData['respondent_explanation'] = $reason;
            }
        }
    }

    // 4. Save via ShowCauseService
    require_once __DIR__ . '/../../services/ShowCauseService.php';
    if (!isset($inputData['complainant_attendance']) || !isset($inputData['respondent_attendance'])) {
        // Fallback default to Present if not explicitly marked
        $inputData['complainant_attendance'] = $inputData['complainant_attendance'] ?? 'Present';
        $inputData['respondent_attendance'] = $inputData['respondent_attendance'] ?? 'Present';
    }

    $result = (new ShowCauseService())->recordAttendance((int) $hearingId, $inputData, (int) $_SESSION['user_id']);

    if (!empty($result['success'])) {
        // Sync resident-level attendance records if provided
        if (!empty($parties)) {
            $hearingAttendance->recordAttendance((int) $hearingId, $parties, (int) $_SESSION['user_id']);
        }

        // Mediation Commencement Service
        $commencementInput = $inputData;
        if (!empty($parties)) {
            $commencementInput['parties'] = $parties;
        }
        $result['mediation_commencement'] = (new MediationCommencementService())->recordFromAttendance(
            (int) $hearingId,
            $commencementInput,
            (int) $_SESSION['user_id']
        );
    }

    http_response_code(!empty($result['success']) ? 200 : 422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
