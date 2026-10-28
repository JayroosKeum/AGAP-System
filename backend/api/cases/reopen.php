<?php
/**
 * POST /backend/api/cases/reopen.php
 *
 * Reopens a Settled case for Certificate to File Action (CFA) issuance
 * when the respondent fails to comply with the Amicable Settlement terms
 * as authorised by RA 7160 Sec. 417.
 *
 * Required POST fields:
 *   case_id  (int)
 *   reason   (string) – specific non-compliance / breach description
 *
 * Returns JSON:
 *   { success, message, case_id?, cfa_url? }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

session_start();

header('Content-Type: application/json; charset=utf-8');

// Auth guard: Admin, Clerk, or Staff
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin or Clerk access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.']);
    exit;
}

$caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reason = trim((string) ($_POST['reason'] ?? ''));

if (!$caseId) {
    echo json_encode(['success' => false, 'message' => 'A valid case ID is required.']);
    exit;
}

if ($reason === '') {
    echo json_encode(['success' => false, 'message' => 'Please provide the reason for reopening this case (non-compliance description).']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

try {
    $db = new Database();
    $conn = $db->connect();

    // Fetch and lock the case row
    $caseStmt = $conn->prepare('SELECT case_id, case_status, case_number, complaint_id FROM cases WHERE case_id = ? FOR UPDATE');
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$case) {
        echo json_encode(['success' => false, 'message' => 'Case not found.']);
        exit;
    }

    if ($case['case_status'] !== 'Settled') {
        echo json_encode([
            'success' => false,
            'message' => sprintf(
                'This case cannot be reopened for CFA. Only Settled cases are eligible for non-compliance CFA under RA 7160 Sec. 417. Current status: %s.',
                $case['case_status']
            )
        ]);
        exit;
    }

    $conn->beginTransaction();

    // 1. Mark settlement as Violated
    $updSettlement = $conn->prepare(
        "UPDATE settlements
            SET compliance_status = 'Violated',
                updated_at = CURRENT_TIMESTAMP
         WHERE case_id = ?"
    );
    $updSettlement->execute([$caseId]);

    // 2. Update case status back to active (CFA Issued will be set upon actual CFA generation)
    //    We use a transient status marker so CFA.php can proceed for a Settled case.
    //    We set case_status = 'Conciliation' as the closest pre-CFA status recognised by CFA.php,
    //    OR we simply leave it as Settled and allow CFA.php to proceed (it only blocks Archived).
    //    Here we leave status as Settled — CFA.php will update it to 'CFA Issued' upon generation.

    // 3. Log in case_history
    $fullReason = 'Case reopened for CFA issuance — Non-compliance with Amicable Settlement (RA 7160 Sec. 417): ' . $reason;
    $histStmt = $conn->prepare(
        "INSERT INTO case_history (case_id, status, remarks, updated_by)
         VALUES (?, 'Settled', ?, ?)"
    );
    $histStmt->execute([$caseId, $fullReason, $userId]);

    $conn->commit();

    // Build CFA page URL with pre-filled case and reason
    $cfaUrl = sprintf(
        '../documents/cfa.php?case_id=%d&reason=%s&workflow=noncompliance_reopen',
        $caseId,
        urlencode('Non-compliance / breach of Amicable Settlement terms by respondent (RA 7160 Sec. 417): ' . $reason)
    );

    echo json_encode([
        'success'    => true,
        'message'    => 'Case reopened for CFA issuance. The settlement has been marked as Violated. You will now be redirected to issue the Certificate to File Action.',
        'case_id'    => $caseId,
        'case_number'=> $case['case_number'],
        'cfa_url'    => $cfaUrl
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('[reopen.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
}
