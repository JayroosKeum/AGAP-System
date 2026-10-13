<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/MediationDeadlineService.php';

class SummonDeliveryService
{
    private PDO $conn;
    private AuditService $audit;
    private MediationDeadlineService $deadlineService;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->audit = new AuditService();
        $this->deadlineService = new MediationDeadlineService($this->conn);
    }

    /**
     * Retrieves or auto-initializes summon delivery records for a hearing.
     * Supports multiple attempts for KP Form No. 9 and CFA unlocking evaluation.
     */
    public function getHearingDeliveries(int $hearingId): array
    {
        $hearingStmt = $this->conn->prepare("
            SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue, h.status AS hearing_status,
                   c.complaint_id, c.case_number, c.case_status, c.is_paused, c.pause_reason
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            WHERE h.hearing_id = ?
        ");
        $hearingStmt->execute([$hearingId]);
        $hearing = $hearingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }

        $caseId = (int) $hearing['case_id'];

        // Get parties for this complaint
        $partiesStmt = $this->conn->prepare("
            SELECT cp.resident_id, cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name,
                   r.address, r.purok, r.contact_no
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent'), r.last_name, r.first_name
        ");
        $partiesStmt->execute([$hearing['complaint_id']]);
        $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainantParty = null;
        $respondentParty = null;
        foreach ($parties as $p) {
            if ($p['party_type'] === 'Complainant' && $complainantParty === null) {
                $complainantParty = $p;
            } elseif ($p['party_type'] === 'Respondent' && $respondentParty === null) {
                $respondentParty = $p;
            }
        }

        // Determine default form types
        $complainantForm = ($hearing['hearing_type'] === 'Show Cause (Complainant)') ? 'KP Form 18' : 'Notice of Hearing';
        $respondentForm = ($hearing['hearing_type'] === 'Show Cause (Respondent)') ? 'KP Form 19' : 'Summon';

        // 1. Fetch or initialize Complainant delivery
        $compStmt = $this->conn->prepare("
            SELECT sd.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name
            FROM summon_deliveries sd
            LEFT JOIN users u ON u.user_id = COALESCE(sd.served_by_user_id, sd.served_by)
            WHERE sd.hearing_id = ? AND sd.party_type = 'Complainant'
            ORDER BY sd.delivery_id DESC
            LIMIT 1
        ");
        $compStmt->execute([$hearingId]);
        $complainantDeliv = $compStmt->fetch(PDO::FETCH_ASSOC);

        if ($complainantDeliv && ($complainantDeliv['delivery_status'] ?? '') !== 'Served' && strpos($complainantDeliv['delivery_status'] ?? '', 'Served') !== 0 && ($hearing['hearing_type'] ?? '') === 'Mediation') {
            $this->markKp8Printed($caseId, (int) ($complainantDeliv['document_id'] ?? 0), $hearingId, (int) ($_SESSION['user_id'] ?? 1));
            $compStmt->execute([$hearingId]);
            $complainantDeliv = $compStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$complainantDeliv) {
            $initialStatus = (($hearing['hearing_type'] ?? '') === 'Mediation') ? 'Served' : 'Pending';
            $insComp = $this->conn->prepare("
                INSERT INTO summon_deliveries (
                    hearing_id, case_id, party_type, resident_id, form_type, attempt_number,
                    delivery_status, recipient_name, served_at, service_method, remarks
                ) VALUES (?, ?, 'Complainant', ?, ?, 1, ?, ?, IF(? = 'Served', NOW(), NULL), IF(? = 'Served', 'Personal Notice', NULL), IF(? = 'Served', 'Personal notice served to complainant upon issuance of 1st mediation summons.', NULL))
            ");
            $insComp->execute([
                $hearingId,
                $caseId,
                $complainantParty ? (int) $complainantParty['resident_id'] : null,
                $complainantForm,
                $initialStatus,
                $complainantParty['resident_name'] ?? null,
                $initialStatus,
                $initialStatus,
                $initialStatus
            ]);
            $compDelivId = (int) $this->conn->lastInsertId();
            $complainantDeliv = [
                'delivery_id' => $compDelivId,
                'hearing_id' => $hearingId,
                'case_id' => $caseId,
                'party_type' => 'Complainant',
                'resident_id' => $complainantParty ? (int) $complainantParty['resident_id'] : null,
                'form_type' => $complainantForm,
                'attempt_number' => 1,
                'delivery_status' => $initialStatus,
                'served_at' => ($initialStatus === 'Served') ? date('Y-m-d H:i:s') : null,
                'served_by' => null,
                'served_by_name' => 'Lupon Office (Personal Notice)',
                'recipient_name' => $complainantParty['resident_name'] ?? null,
                'relationship' => null,
                'unserved_reason' => null,
                'failure_notes' => null,
                'remarks' => ($initialStatus === 'Served') ? 'Personal notice served to complainant upon issuance of 1st mediation summons.' : null,
            ];
        }
        if ($complainantParty) {
            $complainantDeliv['party_profile'] = $complainantParty;
        }

        // 2. Fetch all Respondent attempts
        $attempts = $this->getSummonsAttempts($caseId, $hearingId);

        // Attach resident profile to attempts
        foreach ($attempts as &$att) {
            if ($respondentParty) {
                $att['party_profile'] = $respondentParty;
            }
        }
        unset($att);

        // Latest respondent attempt for legacy card compatibility
        $latestRespAttempt = !empty($attempts) ? end($attempts) : null;

        // Calculate next attempt eligibility
        $attemptsCount = count($attempts);
        $anyServed = false;
        $failedCount = 0;
        $hasPending = false;

        foreach ($attempts as $a) {
            if ($a['is_served']) {
                $anyServed = true;
            } elseif ($a['is_pending']) {
                $hasPending = true;
            } elseif ($a['is_failed']) {
                $failedCount++;
            }
        }

        $canInitiateNextAttempt = false;
        $nextAttemptNumber = null;
        if (!$anyServed && !$hasPending && $failedCount < 3) {
            $canInitiateNextAttempt = true;
            $nextAttemptNumber = $failedCount + 1;
        }

        // 3. CFA Eligibility evaluation
        $cfaEligibility = $this->getCfaEligibility($caseId);

        // 4. Overall hearing attendance lock evaluation
        $serviceCheck = $this->checkServiceStatus($hearingId);

        return [
            'success' => true,
            'hearing' => $hearing,
            'deliveries' => [
                $complainantDeliv,
                $latestRespAttempt ?: []
            ],
            'complainant_delivery' => $complainantDeliv,
            'respondent_attempts' => $attempts,
            'latest_respondent_attempt' => $latestRespAttempt,
            'attempts_count' => $attemptsCount,
            'failed_attempts_count' => $failedCount,
            'can_initiate_next_attempt' => $canInitiateNextAttempt,
            'next_attempt_number' => $nextAttemptNumber,
            'cfa_eligibility' => $cfaEligibility,
            'attendance_unlocked' => $serviceCheck['allowed'],
            'service_notice' => $serviceCheck['message'],
        ];
    }

    /**
     * Checks if both Complainant and Respondent notices/summons are served.
     */
    public function checkServiceStatus(int $hearingId): array
    {
        $stmt = $this->conn->prepare("
            SELECT party_type, delivery_status, unserved_reason, failure_notes, attempt_number
            FROM summon_deliveries
            WHERE hearing_id = ? AND party_type IN ('Complainant', 'Respondent')
            ORDER BY attempt_number DESC, delivery_id DESC
        ");
        $stmt->execute([$hearingId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $complainantServed = false;
        $respondentServed = false;
        $unservedParties = [];
        $pendingParties = [];

        foreach ($rows as $r) {
            $pType = $r['party_type'];
            $st = $r['delivery_status'];
            $isServed = in_array($st, ['Served', 'Served Personal', 'Served Substituted', 'Served Refused'], true);

            if ($pType === 'Complainant') {
                if ($isServed) {
                    $complainantServed = true;
                } elseif ($st === 'Unserved') {
                    $unservedParties[] = "Complainant ({$r['unserved_reason']})";
                } elseif ($st === 'Pending') {
                    $pendingParties[] = 'Complainant';
                }
            } elseif ($pType === 'Respondent') {
                if ($isServed) {
                    $respondentServed = true;
                }
            }
        }

        // For Respondent, check latest attempt
        $respRows = array_values(array_filter($rows, fn($r) => $r['party_type'] === 'Respondent'));
        if (!empty($respRows) && !$respondentServed) {
            $latestResp = $respRows[0];
            if ($latestResp['delivery_status'] === 'Unserved') {
                $unservedParties[] = "Respondent (Attempt #{$latestResp['attempt_number']} - {$latestResp['unserved_reason']})";
            } elseif ($latestResp['delivery_status'] === 'Pending') {
                $pendingParties[] = "Respondent (Attempt #{$latestResp['attempt_number']})";
            }
        }

        if (count($unservedParties) > 0) {
            return [
                'allowed' => false,
                'status' => 'UNSERVED',
                'message' => 'Summon delivery failed for: ' . implode(', ', $unservedParties) . '. Hearing attendance and unexcused absences are locked until service is confirmed or address is corrected.',
            ];
        }

        if (count($pendingParties) > 0 || !$complainantServed || !$respondentServed) {
            return [
                'allowed' => false,
                'status' => 'PENDING',
                'message' => 'Summon/Notice delivery is still pending for: ' . implode(', ', $pendingParties) . '. Please record delivery status before recording hearing attendance.',
            ];
        }

        return [
            'allowed' => true,
            'status' => 'SERVED',
            'message' => 'All summons and notices successfully served. Hearing attendance tracking is unlocked.',
        ];
    }

    /**
     * Requirement A: Automatically mark KP Form No. 8 as served when printed for First Mediation.
     */
    public function markKp8Printed(int $caseId, ?int $documentId = null, ?int $hearingId = null, ?int $actorUserId = null): array
    {
        $actorUserId = $actorUserId ?: (int) ($_SESSION['user_id'] ?? 1);
        $startedTrans = false;
        if (!$this->conn->inTransaction()) {
            $this->conn->beginTransaction();
            $startedTrans = true;
        }

        try {
            // 1. Fetch case details
            $caseStmt = $this->conn->prepare("
                SELECT c.case_id, c.case_number, c.case_status, c.complaint_id
                FROM cases c
                WHERE c.case_id = ?
                FOR UPDATE
            ");
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'Case not found.'];
            }

            // 2. Resolve or verify hearing (must be Mediation)
            if (!$hearingId) {
                $hStmt = $this->conn->prepare("
                    SELECT hearing_id, hearing_type, hearing_date
                    FROM hearings
                    WHERE case_id = ? AND hearing_type = 'Mediation'
                    ORDER BY hearing_date ASC, hearing_id ASC
                    LIMIT 1
                ");
                $hStmt->execute([$caseId]);
                $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
                $hearingId = $hearing ? (int) $hearing['hearing_id'] : null;
            } else {
                $hStmt = $this->conn->prepare("
                    SELECT hearing_id, hearing_type, hearing_date
                    FROM hearings
                    WHERE hearing_id = ? AND case_id = ?
                ");
                $hStmt->execute([$hearingId, $caseId]);
                $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$hearing || ($hearing['hearing_type'] ?? '') !== 'Mediation') {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'KP Form No. 8 automatic service rule only applies to First Mediation hearings.'];
            }

            // 3. Verify document exists and is KP Form 8, or auto-resolve for this case
            $doc = null;
            if ($documentId) {
                $docStmt = $this->conn->prepare("
                    SELECT gd.document_id, gd.case_id, gd.service_status, dt.template_name
                    FROM generated_documents gd
                    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                    WHERE gd.document_id = ? AND gd.case_id = ?
                ");
                $docStmt->execute([$documentId, $caseId]);
                $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$doc || ($doc['template_name'] ?? '') !== 'KP Form 8') {
                $findKp8 = $this->conn->prepare("
                    SELECT gd.document_id, gd.case_id, gd.service_status, dt.template_name
                    FROM generated_documents gd
                    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                    WHERE gd.case_id = ? AND dt.template_name = 'KP Form 8'
                    ORDER BY gd.document_id DESC
                    LIMIT 1
                ");
                $findKp8->execute([$caseId]);
                $doc = $findKp8->fetch(PDO::FETCH_ASSOC);
                if ($doc) {
                    $documentId = (int) $doc['document_id'];
                }
            }

            if (!$doc || ($doc['template_name'] ?? '') !== 'KP Form 8') {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'No valid KP Form No. 8 document found for this case.'];
            }

            // 4. Identify complainant resident
            $compStmt = $this->conn->prepare("
                SELECT cp.resident_id, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name
                FROM complaint_parties cp
                INNER JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type = 'Complainant'
                ORDER BY cp.party_id ASC
                LIMIT 1
            ");
            $compStmt->execute([(int) $case['complaint_id']]);
            $complainant = $compStmt->fetch(PDO::FETCH_ASSOC);

            // 5. Check if already served (Idempotency safeguard)
            $checkDeliv = $this->conn->prepare("
                SELECT delivery_id, delivery_status
                FROM summon_deliveries
                WHERE hearing_id = ? AND party_type = 'Complainant'
                LIMIT 1
            ");
            $checkDeliv->execute([$hearingId]);
            $existingDeliv = $checkDeliv->fetch(PDO::FETCH_ASSOC);

            if ($existingDeliv && $existingDeliv['delivery_status'] === 'Served') {
                // Ensure document status is also Served
                $this->conn->prepare("UPDATE generated_documents SET service_status = 'Served' WHERE document_id = ?")->execute([$documentId]);
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->commit();
                return [
                    'success' => true,
                    'already_served' => true,
                    'message' => 'KP Form No. 8 (Complainant\'s Notice) is already marked as Served.',
                    'delivery_id' => (int) $existingDeliv['delivery_id'],
                    'service_status' => 'Served',
                ];
            }

            $remarks = 'Automatically marked as Served via KP Form No. 8 print workflow for First Mediation.';

            // 6. Update generated_documents
            $updDoc = $this->conn->prepare("UPDATE generated_documents SET service_status = 'Served' WHERE document_id = ?");
            $updDoc->execute([$documentId]);

            // 7. Upsert summon_deliveries for Complainant
            if ($existingDeliv) {
                $updDeliv = $this->conn->prepare("
                    UPDATE summon_deliveries
                    SET delivery_status = 'Served',
                        served_at = NOW(),
                        served_by = ?,
                        served_by_user_id = ?,
                        service_method = 'Printed Notice',
                        document_id = ?,
                        remarks = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE delivery_id = ?
                ");
                $updDeliv->execute([$actorUserId, $actorUserId, $documentId, $remarks, $existingDeliv['delivery_id']]);
                $delivId = (int) $existingDeliv['delivery_id'];
            } else {
                $insDeliv = $this->conn->prepare("
                    INSERT INTO summon_deliveries (
                        hearing_id, case_id, party_type, resident_id, form_type, attempt_number,
                        delivery_status, service_method, served_at, served_by, served_by_user_id,
                        recipient_name, remarks, document_id, created_by
                    ) VALUES (?, ?, 'Complainant', ?, 'Notice of Hearing', 1, 'Served', 'Printed Notice', NOW(), ?, ?, ?, ?, ?, ?)
                ");
                $insDeliv->execute([
                    $hearingId,
                    $caseId,
                    $complainant ? (int) $complainant['resident_id'] : null,
                    $actorUserId,
                    $actorUserId,
                    $complainant['resident_name'] ?? 'Complainant',
                    $remarks,
                    $documentId,
                    $actorUserId
                ]);
                $delivId = (int) $this->conn->lastInsertId();
            }

            // 8. Record in proof_of_service (idempotent: prevent duplicate records)
            $checkPos = $this->conn->prepare("
                SELECT proof_id FROM proof_of_service
                WHERE case_id = ? AND document_id = ? AND service_result = 'Served'
                LIMIT 1
            ");
            $checkPos->execute([$caseId, $documentId]);
            if (!$checkPos->fetchColumn()) {
                $insPos = $this->conn->prepare("
                    INSERT INTO proof_of_service (case_id, document_id, service_result, served_by, served_date, remarks)
                    VALUES (?, ?, 'Served', ?, NOW(), ?)
                ");
                $insPos->execute([$caseId, $documentId, $actorUserId, $remarks]);
            }

            // 9. Audit log entry
            $this->audit->log(
                $actorUserId,
                "KP Form No. 8 automatically marked Served via print workflow for Case #{$case['case_number']}",
                'Summons',
                $documentId
            );

            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'already_served' => false,
                'message' => 'KP Form No. 8 (Complainant\'s Notice) automatically marked as Served upon printing.',
                'delivery_id' => $delivId,
                'document_id' => $documentId,
                'service_status' => 'Served',
            ];
        } catch (Throwable $e) {
            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Error marking KP Form 8 served: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to record service for KP Form 8: ' . $e->getMessage()];
        }
    }

    /**
     * Requirement C: Retrieves all summons attempts for the respondent for a case/hearing.
     */
    public function getSummonsAttempts(int $caseId, ?int $hearingId = null): array
    {
        $params = [$caseId];
        $hSql = "";
        if ($hearingId) {
            $hSql = "AND sd.hearing_id = ?";
            $params[] = $hearingId;
        }

        $stmt = $this->conn->prepare("
            SELECT sd.*, 
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name,
                   h.hearing_type, h.hearing_date, h.venue
            FROM summon_deliveries sd
            LEFT JOIN users u ON u.user_id = COALESCE(sd.served_by_user_id, sd.served_by)
            LEFT JOIN hearings h ON h.hearing_id = sd.hearing_id
            WHERE sd.case_id = ? AND sd.party_type = 'Respondent' $hSql
            ORDER BY sd.attempt_number ASC, sd.delivery_id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        $ordinals = ['', '1st', '2nd', '3rd', '4th'];

        foreach ($rows as $row) {
            $num = (int) ($row['attempt_number'] ?: 1);
            $ordinal = $ordinals[$num] ?? $num . 'th';
            $status = $row['delivery_status'] ?: 'Pending';
            $isFailed = ($status === 'Unserved');
            $isServed = in_array($status, ['Served', 'Served Personal', 'Served Substituted', 'Served Refused'], true);
            $isPending = ($status === 'Pending');

            $formatted[] = [
                'delivery_id' => (int) $row['delivery_id'],
                'hearing_id' => (int) $row['hearing_id'],
                'document_id' => $row['document_id'] ? (int) $row['document_id'] : null,
                'case_id' => (int) $row['case_id'],
                'party_type' => $row['party_type'],
                'resident_id' => $row['resident_id'] ? (int) $row['resident_id'] : null,
                'form_type' => $row['form_type'],
                'attempt_number' => $num,
                'attempt_ordinal' => $ordinal,
                'delivery_status' => $status,
                'is_failed' => $isFailed,
                'is_served' => $isServed,
                'is_pending' => $isPending,
                'unserved_reason' => $row['unserved_reason'],
                'failure_notes' => $row['failure_notes'],
                'remarks' => $row['remarks'],
                'recipient_name' => $row['recipient_name'],
                'relationship' => $row['relationship'],
                'service_method' => $row['service_method'],
                'served_at' => $row['served_at'],
                'attempted_at' => $row['attempted_at'] ?: $row['served_at'] ?: $row['created_at'],
                'served_by' => $row['served_by'],
                'served_by_name' => $row['served_by_name'],
                'proof_file_path' => $row['proof_file_path'],
                'hearing_type' => $row['hearing_type'] ?? 'Mediation',
                'hearing_date' => $row['hearing_date'] ?? null,
            ];
        }

        return $formatted;
    }

    /**
     * Requirement C: Initiate the next summons delivery attempt for respondent (max 3 attempts).
     */
    public function initiateNextAttempt(int $caseId, int $actorUserId, ?int $hearingId = null): array
    {
        $startedTrans = false;
        if (!$this->conn->inTransaction()) {
            $this->conn->beginTransaction();
            $startedTrans = true;
        }

        try {
            // 1. Fetch case
            $cStmt = $this->conn->prepare("SELECT * FROM cases WHERE case_id = ? FOR UPDATE");
            $cStmt->execute([$caseId]);
            $case = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'Case not found.'];
            }

            if (in_array($case['case_status'], ['Settled', 'Dismissed', 'CFA Issued', 'Archived'], true)) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => "Cannot initiate summons attempt for a case that is {$case['case_status']}."];
            }

            // 2. Resolve hearing
            if (!$hearingId) {
                $hStmt = $this->conn->prepare("
                    SELECT hearing_id, hearing_type, hearing_date, venue
                    FROM hearings
                    WHERE case_id = ? AND hearing_type = 'Mediation'
                    ORDER BY hearing_date DESC, hearing_id DESC
                    LIMIT 1
                ");
                $hStmt->execute([$caseId]);
                $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
                $hearingId = $hearing ? (int) $hearing['hearing_id'] : null;
            } else {
                $hStmt = $this->conn->prepare("SELECT hearing_id, hearing_type, hearing_date, venue FROM hearings WHERE hearing_id = ? AND case_id = ?");
                $hStmt->execute([$hearingId, $caseId]);
                $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$hearing) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'No active hearing found for this case to associate summons attempt with.'];
            }

            // 3. Fetch existing attempts for Respondent
            $attemptsStmt = $this->conn->prepare("
                SELECT * FROM summon_deliveries
                WHERE hearing_id = ? AND party_type = 'Respondent'
                ORDER BY attempt_number ASC, delivery_id ASC
                FOR UPDATE
            ");
            $attemptsStmt->execute([$hearingId]);
            $existingAttempts = $attemptsStmt->fetchAll(PDO::FETCH_ASSOC);
            $count = count($existingAttempts);

            // 4. Check if any attempt is already successfully served
            foreach ($existingAttempts as $att) {
                if (in_array($att['delivery_status'], ['Served', 'Served Personal', 'Served Substituted', 'Served Refused'], true)) {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Respondent has already been successfully served. No further attempts are permitted.'];
                }
            }

            // 5. Evaluate current attempts progression
            if ($count === 0) {
                $targetAttempt = 1;
            } elseif ($count === 1) {
                $att1 = $existingAttempts[0];
                if ($att1['delivery_status'] === 'Pending') {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Attempt 1 is still pending. Record the Officer\'s Return for Attempt 1 before initiating the 2nd attempt.'];
                }
                if ($att1['delivery_status'] !== 'Unserved') {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Attempt 1 was not recorded as a qualifying delivery failure.'];
                }
                $targetAttempt = 2;
            } elseif ($count === 2) {
                $att1 = $existingAttempts[0];
                $att2 = $existingAttempts[1];
                if ($att2['delivery_status'] === 'Pending') {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Attempt 2 is still pending. Record the Officer\'s Return for Attempt 2 before initiating the 3rd attempt.'];
                }
                if ($att1['delivery_status'] !== 'Unserved' || $att2['delivery_status'] !== 'Unserved') {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Both previous attempts must be recorded as qualifying delivery failures before initiating Attempt 3.'];
                }
                $targetAttempt = 3;
            } else {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'Maximum of 3 summons delivery attempts allowed for this hearing. A 4th attempt cannot be created.'];
            }

            // 6. Find respondent resident_id
            $rStmt = $this->conn->prepare("
                SELECT cp.resident_id, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name
                FROM complaint_parties cp
                INNER JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type = 'Respondent'
                LIMIT 1
            ");
            $rStmt->execute([(int) $case['complaint_id']]);
            $respondent = $rStmt->fetch(PDO::FETCH_ASSOC);

            // 7. Generate or find KP Form 9 document for this attempt
            $docId = null;
            $tStmt = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 9' LIMIT 1");
            $tStmt->execute();
            $tplId = (int) $tStmt->fetchColumn() ?: 3;

            // Generate fresh PDF for this attempt
            require_once __DIR__ . '/PDFService.php';
            $pdfService = new PDFService();
            $root = dirname(__DIR__, 2);
            $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $case['case_number']);
            $fullDir = $root . '/' . $relativeDir;
            if (!is_dir($fullDir)) {
                @mkdir($fullDir, 0750, true);
            }
            $fileName = sprintf('KP-Form-9-Summons-%d-%s.pdf', $targetAttempt, date('Ymd-His'));
            $filePath = $relativeDir . '/' . $fileName;
            $fullPath = $fullDir . '/' . $fileName;

            // Fetch parties for PDF
            $partiesStmt = $this->conn->prepare("
                SELECT cp.party_type, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
                FROM complaint_parties cp
                INNER JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
            ");
            $partiesStmt->execute([(int) $case['complaint_id']]);
            $allParties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);
            $complainants = array_values(array_filter($allParties, fn($p) => $p['party_type'] === 'Complainant'));
            $respondents = array_values(array_filter($allParties, fn($p) => $p['party_type'] === 'Respondent'));

            $pdfData = [
                'case_number' => $case['case_number'],
                'complaint_title' => $case['case_number'],
                'complainants' => $complainants,
                'respondents' => $respondents,
                'target_party_name' => $respondent['resident_name'] ?? 'Respondent',
                'venue' => $hearing['venue'] ?? 'Barangay Hall',
                'hearing_date' => $hearing['hearing_date'] ?? date('Y-m-d 09:00:00'),
                'notice_date' => date('Y-m-d'),
            ];

            try {
                $pdfService->generateNotice('KP Form 9', $pdfData, $fullPath);
            } catch (Throwable $pe) {
                @file_put_contents($fullPath, '%PDF-1.4 Summons Attempt ' . $targetAttempt);
            }

            $insDoc = $this->conn->prepare("
                INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status, regeneration_reason)
                VALUES (?, ?, ?, ?, 'For Service', ?)
            ");
            $insDoc->execute([$caseId, $tplId, $actorUserId, $filePath, "Summons delivery attempt #{$targetAttempt} issued."]);
            $docId = (int) $this->conn->lastInsertId();

            // 8. Insert new delivery record in summon_deliveries for this attempt
            $insDeliv = $this->conn->prepare("
                INSERT INTO summon_deliveries (
                    hearing_id, case_id, party_type, resident_id, form_type, attempt_number,
                    delivery_status, recipient_name, document_id, created_by, created_at
                ) VALUES (?, ?, 'Respondent', ?, 'Summon', ?, 'Pending', ?, ?, ?, NOW())
            ");
            $insDeliv->execute([
                $hearingId,
                $caseId,
                $respondent ? (int) $respondent['resident_id'] : null,
                $targetAttempt,
                $respondent['resident_name'] ?? 'Respondent',
                $docId,
                $actorUserId
            ]);
            $deliveryId = (int) $this->conn->lastInsertId();

            // 9. Case history & Audit log
            $ordinals = ['', '1st', '2nd', '3rd'];
            $targetOrd = $ordinals[$targetAttempt] ?? $targetAttempt . 'th';
            $auditAction = "Initiated Summons {$targetOrd} Attempt for Respondent in Case #{$case['case_number']}";
            $this->audit->log($actorUserId, $auditAction, 'Summons', $docId);

            $histStmt = $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, ?, ?, ?)
            ");
            $histStmt->execute([
                $caseId,
                $case['case_status'],
                "Issued KP Form 9 Summons {$targetOrd} Attempt. Assigned to process server for service.",
                $actorUserId
            ]);

            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'message' => "Summons {$targetOrd} Attempt initiated successfully.",
                'attempt_number' => $targetAttempt,
                'delivery_id' => $deliveryId,
                'document_id' => $docId,
                'hearing_id' => $hearingId,
                'case_id' => $caseId,
            ];
        } catch (Throwable $e) {
            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Error initiating next summons attempt: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to initiate summons attempt: ' . $e->getMessage()];
        }
    }

    /**
     * Requirement D: Validate conditional CFA unlocking based on the three failed summons attempts rule.
     */
    public function getCfaEligibility(int $caseId): array
    {
        $cStmt = $this->conn->prepare("SELECT case_id, case_number, case_status FROM cases WHERE case_id = ?");
        $cStmt->execute([$caseId]);
        $case = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            return [
                'eligible' => false,
                'reason' => 'Case not found.',
                'code' => 'CASE_NOT_FOUND',
                'attempts_count' => 0,
                'failed_count' => 0,
                'can_issue' => false,
            ];
        }

        if ($case['case_status'] === 'Archived') {
            return [
                'eligible' => false,
                'reason' => 'Cannot issue CFA for an archived case.',
                'code' => 'CASE_ARCHIVED',
                'attempts_count' => 0,
                'failed_count' => 0,
                'can_issue' => false,
            ];
        }

        if ($case['case_status'] === 'Settled') {
            return [
                'eligible' => false,
                'reason' => 'Case has reached an Amicable Settlement.',
                'code' => 'CASE_SETTLED',
                'attempts_count' => 0,
                'failed_count' => 0,
                'can_issue' => false,
            ];
        }

        // Fetch all respondent summon delivery attempts for this case
        $attempts = $this->getSummonsAttempts($caseId);
        $totalAttempts = count($attempts);

        if ($totalAttempts === 0) {
            return [
                'eligible' => false,
                'reason' => 'CFA Locked — 0 of 3 summons attempts resulted in delivery failure.',
                'code' => 'NO_ATTEMPTS',
                'attempts_count' => 0,
                'failed_count' => 0,
                'can_issue' => false,
                'attempts' => $attempts,
            ];
        }

        $anyServed = false;
        $hasPending = false;
        $failedCount = 0;

        foreach ($attempts as $att) {
            if ($att['is_served']) {
                $anyServed = true;
            } elseif ($att['is_pending']) {
                $hasPending = true;
            } elseif ($att['is_failed']) {
                $failedCount++;
            }
        }

        if ($anyServed) {
            return [
                'eligible' => false,
                'reason' => 'CFA Locked — A successful service or an incomplete attempt prevents this three-failure condition from being met.',
                'code' => 'SERVICE_SUCCESSFUL',
                'attempts_count' => $totalAttempts,
                'failed_count' => $failedCount,
                'can_issue' => false,
                'attempts' => $attempts,
            ];
        }

        if ($hasPending) {
            return [
                'eligible' => false,
                'reason' => 'CFA Locked — An incomplete attempt prevents this three-failure condition from being met. Record delivery result first.',
                'code' => 'ATTEMPT_PENDING',
                'attempts_count' => $totalAttempts,
                'failed_count' => $failedCount,
                'can_issue' => false,
                'attempts' => $attempts,
            ];
        }

        if ($failedCount < 3) {
            return [
                'eligible' => false,
                'reason' => sprintf('CFA Locked — %d of 3 summons attempts resulted in delivery failure.', $failedCount),
                'code' => 'INSUFFICIENT_FAILURES',
                'attempts_count' => $totalAttempts,
                'failed_count' => $failedCount,
                'can_issue' => false,
                'attempts' => $attempts,
            ];
        }

        // All 3 attempts completed as qualifying delivery failures!
        return [
            'eligible' => true,
            'reason' => 'CFA Unlocked — 3 of 3 summons delivery attempts resulted in failure.',
            'code' => 'THREE_FAILURES_MET',
            'attempts_count' => $totalAttempts,
            'failed_count' => $failedCount,
            'can_issue' => true,
            'attempts' => $attempts,
        ];
    }

    /**
     * Records delivery result for a party's summon/notice.
     * Accurately associates each Officer's Return with its specific attempt number.
     */
    public function recordDelivery(int $hearingId, array $data, int $actorUserId): array
    {
        $partyType = trim($data['party_type'] ?? '');
        if (!in_array($partyType, ['Complainant', 'Respondent'], true)) {
            return ['success' => false, 'message' => 'Invalid party type. Must be Complainant or Respondent.'];
        }

        $deliveryStatus = trim($data['delivery_status'] ?? '');
        $allowedStatuses = ['Served Personal', 'Served Substituted', 'Served Refused', 'Unserved'];
        if (!in_array($deliveryStatus, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid delivery status.'];
        }

        // Retrieve hearing and case details
        $stmt = $this->conn->prepare("
            SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date,
                   c.complaint_id, c.case_number, c.case_status, c.is_paused, c.pause_reason
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            WHERE h.hearing_id = ?
        ");
        $stmt->execute([$hearingId]);
        $hearing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }

        $caseId = (int) $hearing['case_id'];
        $recipientName = trim($data['recipient_name'] ?? '');
        $relationship = trim($data['relationship'] ?? '');
        $servedAt = !empty($data['served_at']) ? date('Y-m-d H:i:s', strtotime($data['served_at'])) : date('Y-m-d H:i:s');
        $servedBy = !empty($data['served_by']) ? (int) $data['served_by'] : $actorUserId;
        $unservedReason = trim($data['unserved_reason'] ?? '');
        $failureNotes = trim($data['failure_notes'] ?? '');
        $remarks = trim($data['remarks'] ?? '');
        $attemptNumber = !empty($data['attempt_number']) ? max(1, (int) $data['attempt_number']) : 1;
        $deliveryId = !empty($data['delivery_id']) ? (int) $data['delivery_id'] : null;

        // Official summons delivery window validation (bypassed if development/testing)
        if ($deliveryStatus !== 'Unserved' && $servedAt !== null) {
            require_once __DIR__ . '/OfficeLogisticsService.php';
            $logistics = new OfficeLogisticsService($this->conn);
            $prevStmt = $this->conn->prepare("
                SELECT served_at FROM summon_deliveries 
                WHERE hearing_id = ? AND party_type = ? AND served_at IS NOT NULL 
                ORDER BY served_at DESC LIMIT 1
            ");
            $prevStmt->execute([$hearingId, $partyType]);
            $prevAttemptDate = $prevStmt->fetchColumn() ?: null;

            $attemptVal = $logistics->validateSummonsServiceAttempt($servedAt, $attemptNumber, $prevAttemptDate);
            if (!$attemptVal['valid']) {
                return ['success' => false, 'message' => $attemptVal['message']];
            }
        }

        // Validations per delivery scenario
        if ($deliveryStatus === 'Unserved') {
            $allowedReasons = ['Moved Out', 'Wrong Address', 'No One Home', 'Refused', 'Other'];
            if (!in_array($unservedReason, $allowedReasons, true)) {
                return ['success' => false, 'message' => 'A valid failure reason (e.g., Moved Out, Wrong Address, No One Home, Refused) is required when marking unserved.'];
            }
            if ($failureNotes === '') {
                return ['success' => false, 'message' => 'Please provide failure notes detailing the service attempt.'];
            }
            $servedAt = null;
        } elseif ($deliveryStatus === 'Served Substituted') {
            if ($recipientName === '') {
                return ['success' => false, 'message' => 'Recipient name is required for substituted service.'];
            }
            if ($relationship === '') {
                return ['success' => false, 'message' => 'Relationship to recipient is required for substituted service.'];
            }
            $unservedReason = null;
            $failureNotes = null;
        } elseif ($deliveryStatus === 'Served Personal') {
            if ($recipientName === '') {
                return ['success' => false, 'message' => 'Recipient name is required for personal service.'];
            }
            $unservedReason = null;
            $failureNotes = null;
        } elseif ($deliveryStatus === 'Served Refused') {
            // Treated as served under KP rules - reason recorded as Refused
            $unservedReason = 'Refused';
            $failureNotes = null;
            if ($remarks === '') {
                $remarks = 'Party refused to sign receipt (treated as valid service under KP rules).';
            }
        }

        // Determine resident_id
        $residentId = !empty($data['resident_id']) ? (int) $data['resident_id'] : null;
        if (!$residentId) {
            $rStmt = $this->conn->prepare("
                SELECT cp.resident_id
                FROM complaint_parties cp
                WHERE cp.complaint_id = ? AND cp.party_type = ?
                LIMIT 1
            ");
            $rStmt->execute([$hearing['complaint_id'], $partyType]);
            $residentId = (int) $rStmt->fetchColumn() ?: null;
        }

        $formType = ($partyType === 'Complainant')
            ? (($hearing['hearing_type'] === 'Show Cause (Complainant)') ? 'KP Form 18' : 'Notice of Hearing')
            : (($hearing['hearing_type'] === 'Show Cause (Respondent)') ? 'KP Form 19' : 'Summon');

        // Locate or update the target attempt row in summon_deliveries
        if ($deliveryId) {
            $upd = $this->conn->prepare("
                UPDATE summon_deliveries
                SET delivery_status = ?,
                    served_at = ?,
                    served_by = ?,
                    served_by_user_id = ?,
                    attempted_at = COALESCE(attempted_at, NOW()),
                    recipient_name = ?,
                    relationship = ?,
                    unserved_reason = ?,
                    failure_notes = ?,
                    remarks = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE delivery_id = ?
            ");
            $upd->execute([
                $deliveryStatus,
                $servedAt,
                $servedBy,
                $servedBy,
                $recipientName ?: null,
                $relationship ?: null,
                $unservedReason ?: null,
                $failureNotes ?: null,
                $remarks ?: null,
                $deliveryId
            ]);
        } else {
            // Check if a row with this hearing, party_type, attempt_number exists
            $find = $this->conn->prepare("
                SELECT delivery_id FROM summon_deliveries
                WHERE hearing_id = ? AND party_type = ? AND attempt_number = ?
                LIMIT 1
            ");
            $find->execute([$hearingId, $partyType, $attemptNumber]);
            $foundId = (int) $find->fetchColumn() ?: null;

            if ($foundId) {
                $deliveryId = $foundId;
                $upd = $this->conn->prepare("
                    UPDATE summon_deliveries
                    SET delivery_status = ?,
                        served_at = ?,
                        served_by = ?,
                        served_by_user_id = ?,
                        attempted_at = COALESCE(attempted_at, NOW()),
                        recipient_name = ?,
                        relationship = ?,
                        unserved_reason = ?,
                        failure_notes = ?,
                        remarks = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE delivery_id = ?
                ");
                $upd->execute([
                    $deliveryStatus,
                    $servedAt,
                    $servedBy,
                    $servedBy,
                    $recipientName ?: null,
                    $relationship ?: null,
                    $unservedReason ?: null,
                    $failureNotes ?: null,
                    $remarks ?: null,
                    $deliveryId
                ]);
            } else {
                $ins = $this->conn->prepare("
                    INSERT INTO summon_deliveries (
                        hearing_id, case_id, party_type, resident_id, form_type, attempt_number,
                        delivery_status, service_method, attempted_at, served_at, served_by, served_by_user_id,
                        recipient_name, relationship, unserved_reason, failure_notes, remarks
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Personal', NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $hearingId,
                    $caseId,
                    $partyType,
                    $residentId,
                    $formType,
                    $attemptNumber,
                    $deliveryStatus,
                    $servedAt,
                    $servedBy,
                    $servedBy,
                    $recipientName ?: null,
                    $relationship ?: null,
                    $unservedReason ?: null,
                    $failureNotes ?: null,
                    $remarks ?: null
                ]);
                $deliveryId = (int) $this->conn->lastInsertId();
            }
        }

        // Associate with corresponding generated document
        $docId = !empty($data['document_id']) ? (int) $data['document_id'] : null;
        if (!$docId) {
            $targetTpl = ($partyType === 'Respondent') ? 'KP Form 9' : 'KP Form 8';
            $likeTpl = ($partyType === 'Respondent') ? '%Summon%' : '%Notice%';
            $docStmt = $this->conn->prepare("
                SELECT gd.document_id
                FROM generated_documents gd
                INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                WHERE gd.case_id = ? AND (dt.template_name = ? OR dt.template_name LIKE ?)
                ORDER BY gd.document_id DESC
                LIMIT 1
            ");
            $docStmt->execute([$caseId, $targetTpl, $likeTpl]);
            $docId = (int) $docStmt->fetchColumn() ?: null;
        }

        // Link document_id to summon_deliveries if available
        if ($docId && $deliveryId) {
            $this->conn->prepare("UPDATE summon_deliveries SET document_id = COALESCE(document_id, ?) WHERE delivery_id = ?")->execute([$docId, $deliveryId]);
        }

        $ordinals = ['', '1st', '2nd', '3rd'];
        $attemptOrd = $ordinals[$attemptNumber] ?? $attemptNumber . 'th';

        if ($deliveryStatus === 'Unserved') {
            // SCENARIO B: Delivery Failed (Unserved)
            if ($hearing['case_status'] === 'Mediation' && empty($hearing['is_paused'])) {
                $pauseReason = "Summon Unserved ({$partyType} Attempt #{$attemptNumber}) - {$unservedReason}";
                $this->deadlineService->pauseMediation($caseId, $pauseReason, $failureNotes, $actorUserId);
            }

            // Record in proof_of_service with attempt number
            $posRemarks = "Summon {$attemptOrd} Attempt unserved for {$partyType}: {$unservedReason}. Notes: {$failureNotes}";
            $posStmt = $this->conn->prepare("
                INSERT INTO proof_of_service (case_id, document_id, service_result, served_by, served_date, remarks)
                VALUES (?, ?, 'Service Failed', ?, NOW(), ?)
            ");
            $posStmt->execute([$caseId, $docId, $servedBy, $posRemarks]);

            if ($docId) {
                $updDoc = $this->conn->prepare("UPDATE generated_documents SET service_status = 'Service Failed' WHERE document_id = ?");
                $updDoc->execute([$docId]);
            }

            $this->audit->log(
                $actorUserId,
                "Summon {$attemptOrd} Attempt marked Unserved for {$partyType} in Case #{$hearing['case_number']}. Reason: {$unservedReason}",
                'Hearings',
                $caseId
            );
        } else {
            // SCENARIO A: Served (Personal, Substituted, or Refused)
            $posRemarks = "{$partyType} ({$formType}) {$attemptOrd} Attempt {$deliveryStatus}. Recipient: {$recipientName}" . ($relationship ? " ({$relationship})" : "");
            $posStmt = $this->conn->prepare("
                INSERT INTO proof_of_service (case_id, document_id, service_result, served_by, served_date, remarks)
                VALUES (?, ?, 'Served', ?, ?, ?)
            ");
            $posStmt->execute([$caseId, $docId, $servedBy, $servedAt, $posRemarks]);

            if ($docId) {
                $updDoc = $this->conn->prepare("UPDATE generated_documents SET service_status = 'Served' WHERE document_id = ?");
                $updDoc->execute([$docId]);
            }

            // Check if BOTH are now served
            $serviceCheck = $this->checkServiceStatus($hearingId);
            if ($serviceCheck['allowed']) {
                if (!empty($hearing['is_paused']) && str_starts_with((string) $hearing['pause_reason'], 'Summon Unserved')) {
                    $this->deadlineService->resumeMediation($caseId, $actorUserId);
                }
            }

            $this->audit->log(
                $actorUserId,
                "Summon {$attemptOrd} Attempt delivery recorded as '{$deliveryStatus}' for {$partyType} in Case #{$hearing['case_number']}",
                'Hearings',
                $caseId
            );
        }

        $cfaElig = $this->getCfaEligibility($caseId);

        return [
            'success' => true,
            'message' => "Summon {$attemptOrd} Attempt for {$partyType} updated to {$deliveryStatus}.",
            'delivery_id' => $deliveryId,
            'attempt_number' => $attemptNumber,
            'cfa_eligibility' => $cfaElig,
            'service_status' => $this->checkServiceStatus($hearingId),
        ];
    }
}
