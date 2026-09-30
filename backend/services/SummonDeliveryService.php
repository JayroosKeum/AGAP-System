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

        // Fetch existing deliveries for this hearing
        $delivStmt = $this->conn->prepare("
            SELECT sd.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name
            FROM summon_deliveries sd
            LEFT JOIN users u ON u.user_id = sd.served_by
            WHERE sd.hearing_id = ?
            ORDER BY FIELD(sd.party_type, 'Complainant', 'Respondent')
        ");
        $delivStmt->execute([$hearingId]);
        $existing = $delivStmt->fetchAll(PDO::FETCH_ASSOC);

        // Map existing by party_type
        $existingByParty = [];
        foreach ($existing as $ex) {
            $existingByParty[$ex['party_type']] = $ex;
        }

        // Determine appropriate default form_type based on hearing_type
        $complainantForm = ($hearing['hearing_type'] === 'Show Cause (Complainant)') ? 'KP Form 18' : 'Notice of Hearing';
        $respondentForm = ($hearing['hearing_type'] === 'Show Cause (Respondent)') ? 'KP Form 19' : 'Summon';

        $deliveries = [];
        foreach (['Complainant', 'Respondent'] as $pType) {
            if (isset($existingByParty[$pType])) {
                $rec = $existingByParty[$pType];
            } else {
                // Find primary resident for this party type
                $targetResident = null;
                foreach ($parties as $p) {
                    if ($p['party_type'] === $pType) {
                        $targetResident = $p;
                        break;
                    }
                }

                $formType = ($pType === 'Complainant') ? $complainantForm : $respondentForm;
                $ins = $this->conn->prepare("
                    INSERT INTO summon_deliveries (hearing_id, party_type, resident_id, form_type, delivery_status)
                    VALUES (?, ?, ?, ?, 'Pending')
                    ON DUPLICATE KEY UPDATE form_type = VALUES(form_type)
                ");
                $ins->execute([
                    $hearingId,
                    $pType,
                    $targetResident ? (int) $targetResident['resident_id'] : null,
                    $formType
                ]);

                $delivId = (int) $this->conn->lastInsertId();
                $rec = [
                    'delivery_id' => $delivId,
                    'hearing_id' => $hearingId,
                    'party_type' => $pType,
                    'resident_id' => $targetResident ? (int) $targetResident['resident_id'] : null,
                    'form_type' => $formType,
                    'delivery_status' => 'Pending',
                    'served_at' => null,
                    'served_by' => null,
                    'served_by_name' => null,
                    'recipient_name' => $targetResident ? $targetResident['resident_name'] : null,
                    'relationship' => null,
                    'unserved_reason' => null,
                    'failure_notes' => null,
                    'remarks' => null,
                ];
            }

            // Attach resident profile if available
            foreach ($parties as $p) {
                if ($p['party_type'] === $pType) {
                    $rec['party_profile'] = $p;
                    break;
                }
            }
            $deliveries[] = $rec;
        }

        // Check if attendance recording is currently unlocked
        $serviceCheck = $this->checkServiceStatus($hearingId);

        return [
            'success' => true,
            'hearing' => $hearing,
            'deliveries' => $deliveries,
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
            SELECT party_type, delivery_status, unserved_reason, failure_notes
            FROM summon_deliveries
            WHERE hearing_id = ? AND party_type IN ('Complainant', 'Respondent')
        ");
        $stmt->execute([$hearingId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $statuses = [];
        $unservedParties = [];
        $pendingParties = [];

        foreach ($rows as $r) {
            $statuses[$r['party_type']] = $r['delivery_status'];
            if ($r['delivery_status'] === 'Unserved') {
                $unservedParties[] = $r['party_type'] . " ({$r['unserved_reason']})";
            } elseif ($r['delivery_status'] === 'Pending') {
                $pendingParties[] = $r['party_type'];
            }
        }

        if (count($unservedParties) > 0) {
            return [
                'allowed' => false,
                'status' => 'UNSERVED',
                'message' => 'Summon delivery failed for: ' . implode(', ', $unservedParties) . '. Hearing attendance and unexcused absences are locked until service is confirmed or address is corrected.',
            ];
        }

        if (count($pendingParties) > 0 || count($rows) < 2) {
            return [
                'allowed' => false,
                'status' => 'PENDING',
                'message' => 'Summon/Notice delivery is still pending for: ' . implode(', ', $pendingParties) . '. Please record delivery status before recording hearing attendance.',
            ];
        }

        // Both are Served (Personal, Substituted, or Refused)
        return [
            'allowed' => true,
            'status' => 'SERVED',
            'message' => 'All summons and notices successfully served. Hearing attendance tracking is unlocked.',
        ];
    }

    /**
     * Records delivery result for a party's summon/notice.
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

        $recipientName = trim($data['recipient_name'] ?? '');
        $relationship = trim($data['relationship'] ?? '');
        $servedAt = !empty($data['served_at']) ? date('Y-m-d H:i:s', strtotime($data['served_at'])) : date('Y-m-d H:i:s');
        $servedBy = !empty($data['served_by']) ? (int) $data['served_by'] : $actorUserId;
        $unservedReason = trim($data['unserved_reason'] ?? '');
        $failureNotes = trim($data['failure_notes'] ?? '');
        $remarks = trim($data['remarks'] ?? '');

        // Validations per delivery scenario
        if ($deliveryStatus === 'Unserved') {
            $allowedReasons = ['Moved Out', 'Wrong Address', 'No One Home', 'Other'];
            if (!in_array($unservedReason, $allowedReasons, true)) {
                return ['success' => false, 'message' => 'A valid failure reason (e.g., Moved Out, Wrong Address, No One Home) is required when marking unserved.'];
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
        } elseif ($deliveryStatus === 'Served Personal') {
            if ($recipientName === '') {
                return ['success' => false, 'message' => 'Recipient name is required for personal service.'];
            }
        } elseif ($deliveryStatus === 'Served Refused') {
            // Treated as served under KP rules
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

        // Upsert summon_deliveries
        $upsert = $this->conn->prepare("
            INSERT INTO summon_deliveries (
                hearing_id, party_type, resident_id, form_type, delivery_status,
                served_at, served_by, recipient_name, relationship, unserved_reason, failure_notes, remarks
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                resident_id = VALUES(resident_id),
                form_type = VALUES(form_type),
                delivery_status = VALUES(delivery_status),
                served_at = VALUES(served_at),
                served_by = VALUES(served_by),
                recipient_name = VALUES(recipient_name),
                relationship = VALUES(relationship),
                unserved_reason = VALUES(unserved_reason),
                failure_notes = VALUES(failure_notes),
                remarks = VALUES(remarks),
                updated_at = CURRENT_TIMESTAMP
        ");

        $upsert->execute([
            $hearingId,
            $partyType,
            $residentId,
            $formType,
            $deliveryStatus,
            $servedAt,
            $servedBy,
            $recipientName ?: null,
            $relationship ?: null,
            $unservedReason ?: null,
            $failureNotes ?: null,
            $remarks ?: null,
        ]);

        $caseId = (int) $hearing['case_id'];

        // SCENARIO B: Delivery Failed (Unserved)
        // Automatically PAUSE mediation hearing & mediation clock
        if ($deliveryStatus === 'Unserved') {
            if ($hearing['case_status'] === 'Mediation' && empty($hearing['is_paused'])) {
                $pauseReason = "Summon Unserved ({$partyType}) - {$unservedReason}";
                $this->deadlineService->pauseMediation($caseId, $pauseReason, $failureNotes, $actorUserId);
            }

            // Also record in proof_of_service as Service Failed
            $posStmt = $this->conn->prepare("
                INSERT INTO proof_of_service (case_id, service_result, served_by, served_date, remarks)
                VALUES (?, 'Service Failed', ?, NOW(), ?)
            ");
            $posStmt->execute([
                $caseId,
                $servedBy,
                "Summon unserved for {$partyType}: {$unservedReason}. Notes: {$failureNotes}"
            ]);

            $this->audit->log(
                $actorUserId,
                "Summon marked Unserved for {$partyType} in Case #{$hearing['case_number']}. Reason: {$unservedReason}",
                'Hearings',
                $caseId
            );
        } else {
            // SCENARIO A: Served (Personal, Substituted, or Refused)
            // Record in proof_of_service
            $posStmt = $this->conn->prepare("
                INSERT INTO proof_of_service (case_id, service_result, served_by, served_date, remarks)
                VALUES (?, 'Served', ?, ?, ?)
            ");
            $posStmt->execute([
                $caseId,
                $servedBy,
                $servedAt,
                "{$partyType} ({$formType}) {$deliveryStatus}. Recipient: {$recipientName} " . ($relationship ? "({$relationship})" : "")
            ]);

            // Check if BOTH are now served
            $serviceCheck = $this->checkServiceStatus($hearingId);
            if ($serviceCheck['allowed']) {
                // If the case was paused because of Summon Unserved, automatically resume it!
                if (!empty($hearing['is_paused']) && str_starts_with((string) $hearing['pause_reason'], 'Summon Unserved')) {
                    $this->deadlineService->resumeMediation($caseId, $actorUserId);
                }
            }

            $this->audit->log(
                $actorUserId,
                "Summon delivery recorded as '{$deliveryStatus}' for {$partyType} in Case #{$hearing['case_number']}",
                'Hearings',
                $caseId
            );
        }

        return [
            'success' => true,
            'message' => "Summon delivery for {$partyType} updated to {$deliveryStatus}.",
            'service_status' => $this->checkServiceStatus($hearingId),
        ];
    }
}
