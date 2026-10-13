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
    $hearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($hearingId) {
        $result = $hearingAttendance->getHearingAttendance((int) $hearingId);
        if (!$result['success']) {
            http_response_code(404);
            echo json_encode($result);
            exit;
        }

        $serviceGate = (new SummonServiceGate())->forHearing((int) $hearingId);
        $payload = array_merge($result['hearing'], [
            'summons_count' => $result['summons_count'],
            'parties' => $result['parties'],
            'situation' => $result['situation'],
            'service_gate' => $serviceGate,
        ]);

        echo json_encode([
            'success' => true,
            'data' => $payload,
            'hearing' => $result['hearing'],
            'summons_count' => $result['summons_count'],
            'parties' => $result['parties'],
            'situation' => $result['situation'],
            'service_gate' => $serviceGate,
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

    if (isset($inputData['complainant_attendance'], $inputData['respondent_attendance'])) {
        require_once __DIR__ . '/../../services/ShowCauseService.php';
        $result = (new ShowCauseService())->recordAttendance((int) $hearingId, $inputData, (int) $_SESSION['user_id']);
        if (!empty($result['success'])) {
            $result['mediation_commencement'] = (new MediationCommencementService())->recordFromAttendance(
                (int) $hearingId,
                $inputData,
                (int) $_SESSION['user_id']
            );
        }
        http_response_code(!empty($result['success']) ? 200 : 422);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $parties = $inputData['parties'] ?? $inputData['records'] ?? [];
    if (!is_array($parties) || empty($parties)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Attendance party records or Complainant/Respondent attendance are required.']);
        exit;
    }

    if (!isset($parties[0])) {
        $normalized = [];
        foreach ($parties as $residentId => $partyData) {
            if (!is_array($partyData)) {
                continue;
            }
            if (!isset($partyData['resident_id'])) {
                $partyData['resident_id'] = $residentId;
            }
            $normalized[] = $partyData;
        }
        $parties = $normalized;
    }

    $result = $hearingAttendance->recordAttendance((int) $hearingId, $parties, (int) $_SESSION['user_id']);
    if (!empty($result['success'])) {
        $commencementInput = $inputData;
        $commencementInput['parties'] = $parties;
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
