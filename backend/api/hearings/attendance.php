<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../models/HearingAttendance.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$canManage = in_array((int) $_SESSION['role_id'], [1, 2], true);
$hearingAttendance = new HearingAttendance();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 1. Single Hearing Attendance Details
    $hearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($hearingId) {
        $result = $hearingAttendance->getHearingAttendance((int) $hearingId);
        if (!$result['success']) {
            http_response_code(404);
            echo json_encode($result);
            exit;
        }

        $payload = array_merge($result['hearing'], [
            'summons_count' => $result['summons_count'],
            'parties' => $result['parties'],
            'situation' => $result['situation'],
        ]);

        echo json_encode([
            'success' => true,
            'data' => $payload,
            'hearing' => $result['hearing'],
            'summons_count' => $result['summons_count'],
            'parties' => $result['parties'],
            'situation' => $result['situation'],
        ]);
        exit;
    }

    // 2. Attendance Monitoring Overview & List
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
        'data' => [
            'records' => $result['records'],
            'kpis' => $result['kpi'],
        ],
        'records' => $result['records'],
        'kpi' => $result['kpi'],
        'kpis' => $result['kpi'],
        'pagination' => $result['pagination'],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Administrators and Lupon Clerks can record attendance.']);
        exit;
    }

    // Support both JSON body and standard Form POST
    $inputData = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $inputData = json_decode($raw, true) ?: [];
    } else {
        $inputData = $_POST;
    }

    $hearingId = filter_var($inputData['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$hearingId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid hearing ID is required.']);
        exit;
    }

    // Independent toggles for Complainant and Respondent (KP Form 18 & 19 workflow)
    if (isset($inputData['complainant_attendance'], $inputData['respondent_attendance'])) {
        require_once __DIR__ . '/../../services/ShowCauseService.php';
        $showCauseService = new ShowCauseService();
        $result = $showCauseService->recordAttendance((int) $hearingId, $inputData, (int) $_SESSION['user_id']);
        http_response_code($result['success'] ? 200 : 422);
        echo json_encode($result);
        exit;
    }

    $parties = $inputData['parties'] ?? $inputData['records'] ?? [];
    if (!is_array($parties) || empty($parties)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Attendance party records or Complainant/Respondent attendance are required.']);
        exit;
    }

    // Normalize if sent as associative array keyed by resident_id
    if (!isset($parties[0])) {
        $normalized = [];
        foreach ($parties as $resId => $pData) {
            if (is_array($pData)) {
                if (!isset($pData['resident_id'])) {
                    $pData['resident_id'] = $resId;
                }
                $normalized[] = $pData;
            }
        }
        $parties = $normalized;
    }

    $result = $hearingAttendance->recordAttendance((int) $hearingId, $parties, (int) $_SESSION['user_id']);
    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);