<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');
header('Cache-Control: private, no-store');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/PiiCryptoService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Lightweight session-based rate limit (max 20 lookups per 5 minutes).
$now = time();
$bucket = array_values(array_filter(
    (array) ($_SESSION['public_track_hits'] ?? []),
    static fn ($ts) => is_int($ts) && ($now - $ts) < 300
));
if (count($bucket) >= 20) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many lookup attempts. Please wait a few minutes and try again.']);
    exit;
}
$bucket[] = $now;
$_SESSION['public_track_hits'] = $bucket;

$raw = file_get_contents('php://input');
$input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
if (!is_array($input)) {
    $input = $_POST;
}

$reference = strtoupper(trim((string) ($input['reference'] ?? '')));
$verifier = trim((string) ($input['verifier'] ?? ''));

if ($reference === '' || $verifier === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Reference number and verification (last 4 digits of contact or complainant last name) are required.']);
    exit;
}

try {
    $db = (new Database())->connect();
    $stmt = $db->prepare("
        SELECT c.complaint_id,
               COALESCE(c.intake_reference, c.complaint_number) AS reference_no,
               cs.case_number,
               c.status,
               c.screening_decision,
               c.case_type,
               c.created_at,
               cs.case_status
        FROM complaints c
        LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
        WHERE c.deleted_at IS NULL
          AND (
              UPPER(COALESCE(c.intake_reference, '')) = ?
              OR UPPER(COALESCE(c.complaint_number, '')) = ?
              OR UPPER(COALESCE(cs.case_number, '')) = ?
          )
        LIMIT 1
    ");
    $stmt->execute([$reference, $reference, $reference]);
    $complaint = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$complaint) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No matching complaint record was found.']);
        exit;
    }

    // Verify against complainant's last 4 contact digits (via blind index or decrypted PII) or complainant's last name without returning PII.
    $partyStmt = $db->prepare("
        SELECT r.contact_no, r.contact_last4_hash, r.last_name
        FROM complaint_parties cp
        JOIN residents r ON r.resident_id = cp.resident_id
        WHERE cp.complaint_id = ? AND cp.party_type = 'Complainant'
    ");
    $partyStmt->execute([(int) $complaint['complaint_id']]);
    $parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);

    $crypto = new PiiCryptoService();
    $verifierLower = mb_strtolower($verifier, 'UTF-8');
    $verifierDigits = preg_replace('/\D+/', '', $verifier) ?? '';
    $verifierLast4Hash = (strlen($verifierDigits) === 4 && $crypto->isConfigured())
        ? $crypto->contactLast4BlindIndex($verifierDigits)
        : null;
    $verified = false;
    foreach ($parties as $party) {
        $lastName = mb_strtolower(trim((string) ($party['last_name'] ?? '')), 'UTF-8');
        if ($lastName !== '' && $verifierLower === $lastName) {
            $verified = true;
            break;
        }
        if ($verifierLast4Hash !== null && !empty($party['contact_last4_hash']) && hash_equals((string) $party['contact_last4_hash'], $verifierLast4Hash)) {
            $verified = true;
            break;
        }
        $decryptedContact = PiiCryptoService::decryptField($party['contact_no'] ?? null);
        $contactDigits = preg_replace('/\D+/', '', (string) $decryptedContact) ?? '';
        if (strlen($verifierDigits) === 4 && strlen($contactDigits) >= 4 && substr($contactDigits, -4) === $verifierDigits) {
            $verified = true;
            break;
        }
    }

    if (!$verified) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No matching complaint record was found.']);
        exit;
    }

    // Return sanitized status & milestones only (zero PII).
    echo json_encode([
        'success' => true,
        'record' => [
            'reference' => $complaint['reference_no'],
            'case_number' => $complaint['case_number'] ?: null,
            'case_type' => $complaint['case_type'],
            'screening_decision' => $complaint['screening_decision'],
            'status' => $complaint['status'],
            'case_status' => $complaint['case_status'] ?: null,
            'current_stage' => $complaint['case_status'] ?: ($complaint['status'] ?: 'Pre-Docket / Intake'),
            'filed_date' => substr((string) $complaint['created_at'], 0, 10),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Public complaint tracker error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to look up complaint status right now.']);
}
