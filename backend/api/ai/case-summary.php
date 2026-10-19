<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../controllers/ComplaintController.php';
require_once __DIR__ . '/../../controllers/AIController.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/AiRequestRateLimit.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

CsrfService::enforceForApiRequest();

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$rawInput = file_get_contents('php://input', false, null, 0, 65536);
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$payload = str_starts_with($contentType, 'application/json')
    ? json_decode($rawInput, true)
    : $_POST;
if (!is_array($payload)) {
    $payload = $_POST;
}

$complaintId = filter_var($payload['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid complaint ID is required.']);
    exit;
}

if (!in_array($payload['external_ai_consent'] ?? null, [true, 1, '1', 'true'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Confirm that case details may be processed for AI summarization.']);
    exit;
}

if (!AiRequestRateLimit::consume()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Please wait a moment before requesting another AI summary.']);
    exit;
}

try {
    $complaint = (new ComplaintController())->show((int) $complaintId);
    if (!$complaint) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case record not found.']);
        exit;
    }

    $parties = $complaint['parties'] ?? [];
    $complainants = [];
    $respondents = [];
    foreach ($parties as $p) {
        $fullName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
        if (($p['party_type'] ?? '') === 'Complainant') {
            $complainants[] = $fullName ?: 'Complainant';
        } else {
            $respondents[] = $fullName ?: 'Respondent';
        }
    }

    $caseWorkspace = $complaint['case_workspace'] ?? null;
    $caseItem = $caseWorkspace['case'] ?? [];
    $hearings = $caseWorkspace['hearings'] ?? [];

    $hearingsSummaryList = [];
    foreach ($hearings as $h) {
        $hDate = $h['hearing_date'] ? date('M j, Y g:i A', strtotime($h['hearing_date'])) : 'Date unrecorded';
        $hType = $h['hearing_type'] ?? 'Session';
        $hStatus = $h['status'] ?? 'Scheduled';
        $hearingsSummaryList[] = "{$hType} ({$hDate}) - Status: {$hStatus}";
    }
    $hearingsSummary = count($hearingsSummaryList) > 0 ? implode('; ', $hearingsSummaryList) : 'No hearings scheduled yet.';

    $caseSummaryPayload = [
        'case_number' => $caseItem['case_number'] ?? $complaint['complaint_number'],
        'complaint_title' => $complaint['complaint_title'] ?? 'Dispute Case',
        'case_type' => $caseItem['case_type'] ?? ($complaint['case_type'] ?? 'Civil'),
        'category_name' => $complaint['category_name'] ?? 'General Dispute',
        'case_status' => $caseItem['case_status'] ?? ($complaint['lifecycle_status'] ?? 'Active'),
        'complainants' => implode(', ', $complainants) ?: 'Complainant',
        'respondents' => implode(', ', $respondents) ?: 'Respondent',
        'incident_date' => $complaint['incident_date'] ?? 'N/A',
        'incident_location' => $complaint['incident_location'] ?? ($complaint['location']['address'] ?? 'Not specified'),
        'narrative' => $complaint['narrative'] ?? 'No narrative statement provided.',
        'additional_details' => $complaint['additional_details'] ?? '',
        'requested_resolution' => $complaint['requested_resolution'] ?? '',
        'hearings_summary' => $hearingsSummary
    ];

    $aiController = new AIController();
    $aiResponse = $aiController->caseSummary($caseSummaryPayload);

    if ($aiResponse['success']) {
        echo json_encode([
            'success' => true,
            'summary' => $aiResponse['summary'],
            'generated_at' => date('Y-m-d H:i:s'),
            'case_data' => $caseSummaryPayload
        ]);
        exit;
    }

    // Graceful fallback if Gemini service is offline or unconfigured
    $fallbackBrief = "1. EXECUTIVE BRIEFING\n"
        . "Case {$caseSummaryPayload['case_number']} involves a {$caseSummaryPayload['case_type']} dispute categorized under '{$caseSummaryPayload['category_name']}' between "
        . "{$caseSummaryPayload['complainants']} (Complainant) and {$caseSummaryPayload['respondents']} (Respondent). "
        . "The case is currently in the {$caseSummaryPayload['case_status']} stage of proceedings.\n\n"
        . "2. DISPUTED ISSUES & CLAIMS\n"
        . "• Incident Summary: " . ($complaint['narrative'] ? mb_substr($complaint['narrative'], 0, 300) . '...' : 'No narrative recorded.') . "\n"
        . "• Relief Demanded: " . ($complaint['requested_resolution'] ?: 'Amicable settlement and resolution.') . "\n\n"
        . "3. PROCEEDINGS & MEDIATION STATUS\n"
        . "• Sessions Conducted: " . count($hearings) . " session(s) on record.\n"
        . "• Hearing Details: {$hearingsSummary}\n\n"
        . "4. PROCEDURAL ASSESSMENT & RECOMMENDED NEXT STEPS\n"
        . "• Continue proceedings according to Katarungang Pambarangay rules (RA 7160).\n"
        . "• Enforce mediation appearance and explore amicable settlement terms between parties.";

    echo json_encode([
        'success' => true,
        'summary' => $fallbackBrief,
        'is_fallback' => true,
        'fallback_reason' => $aiResponse['message'] ?? 'AI service generated structured fallback.',
        'generated_at' => date('Y-m-d H:i:s'),
        'case_data' => $caseSummaryPayload
    ]);
} catch (Throwable $e) {
    error_log('Case summary API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while compiling the case summary.']);
}
