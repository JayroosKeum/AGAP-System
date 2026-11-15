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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

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

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reason = trim((string) ($input['reason'] ?? ''));

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

    $conn->beginTransaction();

    // Fetch and lock the case row
    $caseStmt = $conn->prepare('SELECT case_id, case_status, case_number, complaint_id FROM cases WHERE case_id = ? FOR UPDATE');
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$case) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found.']);
        exit;
    }

    if ($case['case_status'] !== 'Settled') {
        $conn->rollBack();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => sprintf(
                'This case cannot be reopened for CFA. Only Settled cases are eligible for non-compliance CFA under RA 7160 Sec. 417. Current status: %s.',
                $case['case_status']
            )
        ]);
        exit;
    }

    // Require an operative Signed & Attested settlement OR Finalized arbitration award
    $settleStmt = $conn->prepare(
        'SELECT settlement_id, settlement_date, settlement_status, document_id,
                compliance_status, repudiation_status, repudiation_date
           FROM settlements
          WHERE case_id = ?
          ORDER BY settlement_id DESC LIMIT 1
          FOR UPDATE'
    );
    $settleStmt->execute([$caseId]);
    $settlement = $settleStmt->fetch(PDO::FETCH_ASSOC);

    $arbStmt = $conn->prepare(
        'SELECT arbitration_id, award_date, award_status, award_document_id, agreement_repudiation_status
           FROM arbitration_records
          WHERE case_id = ?
          ORDER BY arbitration_id DESC LIMIT 1
          FOR UPDATE'
    );
    $arbStmt->execute([$caseId]);
    $arbitration = $arbStmt->fetch(PDO::FETCH_ASSOC);

    $hasSignedSettlement = $settlement && ($settlement['settlement_status'] ?? '') === 'Signed and Attested';
    $hasFinalizedAward = $arbitration && ($arbitration['award_status'] ?? '') === 'Finalized';

    if (!$hasSignedSettlement && !$hasFinalizedAward) {
        $conn->rollBack();
        http_response_code(422);
        $msg = 'Cannot reopen for non-compliance CFA: no signed and attested Amicable Settlement or finalized Arbitration Award exists for this case.';
        if ($settlement && ($settlement['settlement_status'] ?? '') === 'Legacy Review Required') {
            $msg = 'Cannot reopen for non-compliance CFA: the legacy settlement record requires staff review to confirm signing and attestation first.';
        } elseif ($settlement && ($settlement['settlement_status'] ?? '') === 'Draft') {
            $msg = 'Cannot reopen for non-compliance CFA: the settlement is still a Draft and has not been signed and attested.';
        }
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    if ($hasSignedSettlement && (($settlement['repudiation_status'] ?? '') === 'Repudiated' || !empty($settlement['repudiation_date']))) {
        $conn->rollBack();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'This settlement is already formally repudiated. Issue the CFA on the repudiation ground directly.'
        ]);
        exit;
    }

    if ($hasFinalizedAward && ($arbitration['agreement_repudiation_status'] ?? '') === 'Repudiated') {
        $conn->rollBack();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'The underlying arbitration agreement was repudiated. Issue the CFA on the repudiation ground directly.'
        ]);
        exit;
    }

    require_once __DIR__ . '/../../services/MediationDeadlineService.php';
    $instrumentDate = $hasSignedSettlement
        ? substr((string) ($settlement['settlement_date'] ?? ''), 0, 10)
        : substr((string) ($arbitration['award_date'] ?? ''), 0, 10);
    if ($instrumentDate !== '') {
        $repudiationCutoff = MediationDeadlineService::calculateBusinessDays($instrumentDate, 10);
        if (date('Y-m-d') <= $repudiationCutoff) {
            $conn->rollBack();
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => sprintf(
                    'Cannot reopen for non-compliance CFA while within the 10-working-day repudiation window (until %s).',
                    $repudiationCutoff
                )
            ]);
            exit;
        }
    }

    // 1. Mark the operative instrument as Violated
    if ($hasSignedSettlement) {
        $updSettlement = $conn->prepare(
            "UPDATE settlements
                SET compliance_status = 'Violated',
                    updated_at = CURRENT_TIMESTAMP
              WHERE settlement_id = ?"
        );
        $updSettlement->execute([(int) $settlement['settlement_id']]);
    } else {
        $updArb = $conn->prepare(
            "UPDATE arbitration_records
                SET award_status = 'Violated'
              WHERE arbitration_id = ?"
        );
        $updArb->execute([(int) $arbitration['arbitration_id']]);
    }

    // Cancel any active court transmittal packet for the breached instrument so a CFA packet can be built
    $cancelTransStmt = $conn->prepare(
        "UPDATE case_transmittals
            SET status = 'Cancelled',
                superseded_at = COALESCE(superseded_at, CURRENT_TIMESTAMP),
                updated_at = CURRENT_TIMESTAMP
          WHERE case_id = ?
            AND superseded_at IS NULL
            AND status <> 'Cancelled'"
    );
    $cancelTransStmt->execute([$caseId]);

    // 2. Log in case_history
    $instrumentLabel = $hasSignedSettlement ? 'Amicable Settlement' : 'Arbitration Award';
    $fullReason = 'Case reopened for CFA issuance — Non-compliance with ' . $instrumentLabel . ' (RA 7160 Sec. 417): ' . $reason;
    $histStmt = $conn->prepare(
        "INSERT INTO case_history (case_id, status, remarks, updated_by)
         VALUES (?, 'Settled', ?, ?)"
    );
    $histStmt->execute([$caseId, $fullReason, $userId]);

    $conn->commit();

    // Build CFA page URL with pre-filled case and reason (no client workflow bypass)
    $cfaUrl = sprintf(
        '../documents/cfa.php?case_id=%d&reason=%s',
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
