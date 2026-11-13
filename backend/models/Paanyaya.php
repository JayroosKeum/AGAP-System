<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PDFService.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/PaanyayaPreparationService.php';

class Paanyaya
{
    private PDO $conn;
    private AuditService $audit;
    private PDFService $pdf;

    public const MAX_ATTEMPTS = 2;
    public const STAGE_1 = 'Paanyaya 1';
    public const STAGE_2 = 'Paanyaya 2';
    public const MAX_STAGE_ATTEMPTS = 6;

    public function getMaxStageAttempts(): int
    {
        require_once __DIR__ . '/Setting.php';
        return (int) Setting::getSetting('summons_max_attempts', (int) Setting::getSetting('paanyaya_max_attempts', 6, $this->conn), $this->conn);
    }

    public const VALID_DELIVERY_OUTCOMES = [
        'Successfully delivered',
        'Recipient unavailable',
        'Recipient refused to receive',
        'Respondent not found at the address',
        'Incorrect or incomplete address',
        'Other reason'
    ];

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?: (new Database())->connect();
        $this->audit = new AuditService();
        $this->pdf = new PDFService($this->conn);
        $this->ensurePartyColumns();
    }

    private function ensurePartyColumns(): void
    {
        try {
            $cols = $this->conn->query('DESCRIBE paanyayas')->fetchAll(PDO::FETCH_COLUMN);
            $needed = [
                'complainant_resident_id' => 'INT NULL',
                'complainant_address' => 'VARCHAR(255) NULL',
                'complainant_purok' => 'VARCHAR(100) NULL',
                'complainant_contact_no' => 'VARCHAR(50) NULL',
                'complainant_email' => 'VARCHAR(150) NULL',
                'invitee_resident_id' => 'INT NULL',
                'invitee_address' => 'VARCHAR(255) NULL',
                'invitee_purok' => 'VARCHAR(100) NULL',
                'invitee_contact_no' => 'VARCHAR(50) NULL',
                'invitee_email' => 'VARCHAR(150) NULL',
                'complainant_latitude' => 'DECIMAL(10,7) NULL',
                'complainant_longitude' => 'DECIMAL(10,7) NULL',
                'invitee_latitude' => 'DECIMAL(10,7) NULL',
                'invitee_longitude' => 'DECIMAL(10,7) NULL',
                'location_latitude' => 'DECIMAL(10,7) NULL',
                'location_longitude' => 'DECIMAL(10,7) NULL',
            ];
            foreach ($needed as $col => $def) {
                if (!in_array($col, $cols, true)) {
                    $this->conn->exec("ALTER TABLE paanyayas ADD COLUMN {$col} {$def}");
                }
            }
        } catch (Throwable $e) {
            error_log('Paanyaya::ensurePartyColumns warning: ' . $e->getMessage());
        }
    }

    /**
     * Creates a directory profile for a manually entered invitation party.
     * A matching resident is reused, so saving a Paanyaya never creates a
     * duplicate profile merely because the picker was not used.
     */
    private function persistManualResident(?int $residentId, string $fullName, string $firstName, string $middleName, string $lastName, string $address, string $purok, string $contact, string $email, array $data, string $prefix): ?int
    {
        if ($residentId) {
            return $residentId;
        }
        $fullName = trim($fullName);
        if ($fullName === '' || $fullName === 'Inanyayahang Residente') {
            return null;
        }
        if ($firstName === '' || $lastName === '') {
            $parts = preg_split('/\s+/', $fullName) ?: [];
            $firstName = $firstName ?: (string) array_shift($parts);
            $lastName = $lastName ?: (string) array_pop($parts);
            $middleName = $middleName ?: trim(implode(' ', $parts));
        }
        if ($firstName === '' || $lastName === '') {
            return null;
        }

        require_once __DIR__ . '/Resident.php';
        $profile = [
            'first_name' => $firstName,
            'middle_name' => $middleName ?: null,
            'last_name' => $lastName,
            'contact_no' => $contact ?: null,
            'email' => $email ?: null,
            'address' => $address ?: null,
            'purok' => $purok ?: null,
            'home_latitude' => isset($data[$prefix . '_latitude']) && is_numeric($data[$prefix . '_latitude']) ? (float) $data[$prefix . '_latitude'] : null,
            'home_longitude' => isset($data[$prefix . '_longitude']) && is_numeric($data[$prefix . '_longitude']) ? (float) $data[$prefix . '_longitude'] : null,
        ];
        $residents = new Resident($this->conn);
        $existing = $residents->findDuplicate($profile);
        return $existing ? (int) $existing['resident_id'] : $residents->create($profile);
    }

    /**
     * Schedules a Paanyaya dialogue session (Attempt #1 or Attempt #2).
     * Enforces the "Dalawang Beses Lang" (Max 2 Attempts) rule.
     */
    /**
     * Schedules a Paanyaya dialogue invitation.
     * Contains ONLY essential info: Invitee Name, Scheduled Date, Scheduled Time, and status.
     * References Mediation record when provided without duplicating complaint details.
     */
    public function schedule(array $data, ?int $userId = null): array
    {
        $complaintId = (int) ($data['complaint_id'] ?? 0);
        $complaint = null;

        if ($complaintId > 0) {
            if (PaanyayaPreparationService::requiresPreparation($this->conn, $complaintId)
                || PaanyayaPreparationService::managesDialogue($this->conn, $complaintId)) {
                return ['success' => false, 'message' => 'Prepare this walk-in invitation from the original intake record.'];
            }
            $cStmt = $this->conn->prepare('SELECT complaint_id, complaint_number, complaint_title, status, paanyaya_attempt_count, max_paanyaya_reached, resolved_without_docketing FROM complaints WHERE complaint_id = ? LIMIT 1');
            $cStmt->execute([$complaintId]);
            $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$complaint) {
                return ['success' => false, 'message' => 'Referenced complaint not found.'];
            }

            if (!empty($complaint['resolved_without_docketing']) || $complaint['status'] === 'Settled' || $complaint['status'] === 'Resolved at Paanyaya / Closed') {
                return ['success' => false, 'message' => 'This dispute has already been resolved and is closed.'];
            }
        }

        // 1. Name of the person being invited
        $inviteeFirst = trim((string) ($data['invitee_first_name'] ?? ''));
        $inviteeMiddle = trim((string) ($data['invitee_middle_name'] ?? ''));
        $inviteeLast = trim((string) ($data['invitee_last_name'] ?? ''));
        if ($inviteeFirst !== '' || $inviteeLast !== '') {
            $inviteeName = trim(preg_replace('/\s+/', ' ', "{$inviteeFirst} {$inviteeMiddle} {$inviteeLast}"));
        } else {
            $inviteeName = trim((string) ($data['invitee_name'] ?? ($data['name'] ?? '')));
        }
        if ($inviteeName === '' && $complaintId > 0) {
            // Reference respondent from mediation record without duplicating complaint narrative
            $rStmt = $this->conn->prepare("
                SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                FROM complaint_parties cp
                JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type = 'Respondent'
            ");
            $rStmt->execute([$complaintId]);
            $inviteeName = (string) $rStmt->fetchColumn();
        }
        if ($inviteeName === '') {
            $inviteeName = 'Inanyayahang Residente';
        }

        // Complainant Name (Nagpaanyaya)
        $compFirst = trim((string) ($data['complainant_first_name'] ?? ''));
        $compMiddle = trim((string) ($data['complainant_middle_name'] ?? ''));
        $compLast = trim((string) ($data['complainant_last_name'] ?? ''));
        if ($compFirst !== '' || $compLast !== '') {
            $complainantName = trim(preg_replace('/\s+/', ' ', "{$compFirst} {$compMiddle} {$compLast}"));
        } else {
            $complainantName = trim((string) ($data['complainant_name'] ?? ''));
        }
        if ($complainantName === '' && $complaintId > 0) {
            $cStmt = $this->conn->prepare("
                SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                FROM complaint_parties cp
                JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type = 'Complainant'
            ");
            $cStmt->execute([$complaintId]);
            $complainantName = (string) $cStmt->fetchColumn();
        }

        // 2. Scheduled Date
        $scheduledDate = trim((string) ($data['scheduled_date'] ?? ($data['invitation_date'] ?? ($data['date'] ?? ''))));
        if ($scheduledDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
            return ['success' => false, 'message' => 'Please provide a valid invitation date (YYYY-MM-DD).'];
        }

        // 3. Scheduled Time
        $scheduledTime = trim((string) ($data['scheduled_time'] ?? ($data['invitation_time'] ?? ($data['time'] ?? ''))));
        if ($scheduledTime === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $scheduledTime)) {
            return ['success' => false, 'message' => 'Please provide a valid invitation time (HH:MM).'];
        }

        // Validate that scheduled date & time are strictly in the future
        $now = new DateTime();
        $scheduledDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledDate . ' ' . (strlen($scheduledTime) === 5 ? $scheduledTime . ':00' : $scheduledTime));
        if (!$scheduledDateTime) {
            $scheduledDateTime = DateTime::createFromFormat('Y-m-d H:i', $scheduledDate . ' ' . substr($scheduledTime, 0, 5));
        }

        if ($scheduledDateTime) {
            $today = new DateTime($now->format('Y-m-d'));
            $schedDay = new DateTime($scheduledDateTime->format('Y-m-d'));

            if ($schedDay < $today) {
                return [
                    'success' => false,
                    'message' => 'The scheduled date cannot be in the past. Please select today or a future date.'
                ];
            }

            if ($scheduledDateTime <= $now) {
                return [
                    'success' => false,
                    'message' => 'The scheduled date and time must be in the future.'
                ];
            }
        }

        $venue = trim((string) ($data['venue'] ?? 'Tanggapan ng Barangay Tumana'));
        if ($venue === '') {
            $venue = 'Tanggapan ng Barangay Tumana';
        }

        $hasESignature = isset($data['has_e_signature']) ? (int) ((bool) $data['has_e_signature']) : 1;

        $compResidentId = filter_var($data['complainant_resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $compAddress = trim((string) ($data['complainant_address'] ?? ''));
        $compPurok = trim((string) ($data['complainant_purok'] ?? ''));
        $compContact = trim((string) ($data['complainant_contact_no'] ?? ''));
        $compEmail = trim((string) ($data['complainant_email'] ?? ''));

        $invResidentId = filter_var($data['invitee_resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $invAddress = trim((string) ($data['invitee_address'] ?? ''));
        $invPurok = trim((string) ($data['invitee_purok'] ?? ''));
        $invContact = trim((string) ($data['invitee_contact_no'] ?? ''));
        $invEmail = trim((string) ($data['invitee_email'] ?? ''));
        $salaysayCheck = trim((string) ($data['salaysay'] ?? ''));

        if ($complaintId <= 0 && !empty($data['require_party_details'])) {
            if ($complainantName === '') {
                return ['success' => false, 'message' => 'Please enter the full name of the Complainant (Nagpaanyaya).'];
            }
            if ($compAddress === '' && $compPurok === '') {
                return ['success' => false, 'message' => 'Please provide the street address or Purok of the Complainant (Nagpaanyaya).'];
            }
            if ($inviteeName === '' || $inviteeName === 'Inanyayahang Residente') {
                return ['success' => false, 'message' => 'Please enter the full name of the Person Being Invited (Inanyayahan).'];
            }
            if ($invAddress === '' && $invPurok === '') {
                return ['success' => false, 'message' => 'Please provide the street address or Purok of the Person Being Invited (Inanyayahan) for invitation delivery.'];
            }
            if ($salaysayCheck === '') {
                return ['success' => false, 'message' => 'Please provide the Salaysay (Statement / Dispute Narrative) for the Paanyaya invitation.'];
            }
        }

        try {
            $this->conn->beginTransaction();

            $compResidentId = $this->persistManualResident(
                $compResidentId, $complainantName, $compFirst, $compMiddle, $compLast,
                $compAddress, $compPurok, $compContact, $compEmail, $data, 'complainant'
            );
            $invResidentId = $this->persistManualResident(
                $invResidentId, $inviteeName, $inviteeFirst, $inviteeMiddle, $inviteeLast,
                $invAddress, $invPurok, $invContact, $invEmail, $data, 'invitee'
            );

            // Salaysay (statement narrative)
            $salaysay = trim((string) ($data['salaysay'] ?? ''));

            // Insert into independent paanyayas entity
            $insPaanyaya = $this->conn->prepare("
                INSERT INTO paanyayas (
                    complaint_id, complainant_name, invitee_name, scheduled_date, scheduled_time,
                    status, venue, salaysay, scheduled_by
                ) VALUES (?, ?, ?, ?, ?, 'Scheduled', ?, ?, ?)
            ");
            $insPaanyaya->execute([
                $complaintId > 0 ? $complaintId : null,
                $complainantName !== '' ? $complainantName : null,
                $inviteeName,
                $scheduledDate,
                $scheduledTime,
                $venue,
                $salaysay !== '' ? $salaysay : null,
                $userId
            ]);
            $paanyayaId = (int) $this->conn->lastInsertId();
            try {
                $updDetails = $this->conn->prepare("
                    UPDATE paanyayas
                    SET complainant_resident_id = ?,
                        complainant_address = ?,
                        complainant_purok = ?,
                        complainant_contact_no = ?,
                        complainant_email = ?,
                        invitee_resident_id = ?,
                        invitee_address = ?,
                        invitee_purok = ?,
                        invitee_contact_no = ?,
                        invitee_email = ?,
                        complainant_latitude = ?,
                        complainant_longitude = ?,
                        invitee_latitude = ?,
                        invitee_longitude = ?,
                        location_latitude = ?,
                        location_longitude = ?
                    WHERE paanyaya_id = ?
                ");
                $updDetails->execute([
                    $compResidentId,
                    $compAddress !== '' ? $compAddress : null,
                    $compPurok !== '' ? $compPurok : null,
                    $compContact !== '' ? $compContact : null,
                    $compEmail !== '' ? $compEmail : null,
                    $invResidentId,
                    $invAddress !== '' ? $invAddress : null,
                    $invPurok !== '' ? $invPurok : null,
                    $invContact !== '' ? $invContact : null,
                    $invEmail !== '' ? $invEmail : null,
                    (isset($data['complainant_latitude']) && $data['complainant_latitude'] !== '' && is_numeric($data['complainant_latitude'])) ? (float) $data['complainant_latitude'] : null,
                    (isset($data['complainant_longitude']) && $data['complainant_longitude'] !== '' && is_numeric($data['complainant_longitude'])) ? (float) $data['complainant_longitude'] : null,
                    (isset($data['invitee_latitude']) && $data['invitee_latitude'] !== '' && is_numeric($data['invitee_latitude'])) ? (float) $data['invitee_latitude'] : null,
                    (isset($data['invitee_longitude']) && $data['invitee_longitude'] !== '' && is_numeric($data['invitee_longitude'])) ? (float) $data['invitee_longitude'] : null,
                    (isset($data['location_latitude']) && $data['location_latitude'] !== '' && is_numeric($data['location_latitude'])) ? (float) $data['location_latitude'] : null,
                    (isset($data['location_longitude']) && $data['location_longitude'] !== '' && is_numeric($data['location_longitude'])) ? (float) $data['location_longitude'] : null,
                    $paanyayaId
                ]);
            } catch (Throwable $detErr) {
                error_log('Could not update extra party fields on paanyayas: ' . $detErr->getMessage());
            }

            $sessionId = null;
            $attemptNumber = 1;

            if ($complaintId > 0) {
                // Count previous sessions for attempt counter
                $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM paanyaya_sessions WHERE complaint_id = ?');
                $countStmt->execute([$complaintId]);
                $existingSessionCount = (int) $countStmt->fetchColumn();
                $attemptNumber = isset($data['attempt_number']) ? (int) $data['attempt_number'] : ($existingSessionCount + 1);
                if ($attemptNumber < 1) $attemptNumber = 1;

                // Sync with paanyaya_sessions
                $insSession = $this->conn->prepare("
                    INSERT INTO paanyaya_sessions (
                        complaint_id, invitee_name, attempt_number, scheduled_date, scheduled_time,
                        venue, salaysay, has_e_signature, scheduled_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insSession->execute([
                    $complaintId,
                    $inviteeName,
                    $attemptNumber,
                    $scheduledDate,
                    $scheduledTime,
                    $venue,
                    $salaysay !== '' ? $salaysay : null,
                    $hasESignature,
                    $userId
                ]);
                $sessionId = (int) $this->conn->lastInsertId();

                // Update complaints state
                $statusToSet = ($attemptNumber === 1) ? 'Pre-Docketed (Paanyaya)' : 'Paanyaya In Progress';
                $updComplaint = $this->conn->prepare("
                    UPDATE complaints
                    SET status = ?,
                        paanyaya_attempt_count = ?,
                        max_paanyaya_reached = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComplaint->execute([
                    $statusToSet,
                    $attemptNumber,
                    ($attemptNumber >= self::MAX_ATTEMPTS ? 1 : 0),
                    $complaintId
                ]);

                // Sync with legacy complaint_paanyaya
                $legacyIns = $this->conn->prepare("
                    INSERT INTO complaint_paanyaya (
                        complaint_id, invitee_name, invitation_date, invitation_time, venue,
                        status, has_e_signature, scheduled_by
                    ) VALUES (?, ?, ?, ?, ?, 'Scheduled', ?, ?)
                ");
                $legacyIns->execute([
                    $complaintId,
                    $inviteeName,
                    $scheduledDate,
                    $scheduledTime,
                    $venue,
                    $hasESignature,
                    $userId
                ]);
            }

            // Generate official Paanyaya PDF invitation letter
            $storageDir = __DIR__ . '/../../storage/paanyaya';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0777, true);
            }

            $pdfFileName = sprintf('paanyaya_%d_%s.pdf', $paanyayaId, date('Ymd_His'));
            $pdfFullPath = $storageDir . '/' . $pdfFileName;
            $pdfRelativePath = 'storage/paanyaya/' . $pdfFileName;

            try {
                $this->pdf->generatePaanyaya($complaintId > 0 ? $complaintId : 0, [
                    'save_path' => $pdfFullPath,
                    'paanyaya_id' => $paanyayaId,
                    'invitee_name' => $inviteeName,
                    'complainant_name' => $complainantName,
                    'complainant_address' => $compAddress,
                    'complainant_purok' => $compPurok,
                    'invitee_address' => $invAddress,
                    'invitee_purok' => $invPurok,
                    'invitation_date' => $scheduledDate,
                    'invitation_time' => $scheduledTime,
                    'venue' => $venue,
                    'salaysay' => $salaysay,
                    'has_e_signature' => (bool)$hasESignature,
                ]);

                // Save PDF path to paanyayas
                $this->conn->prepare('UPDATE paanyayas SET pdf_path = ? WHERE paanyaya_id = ?')->execute([$pdfRelativePath, $paanyayaId]);
            } catch (Throwable $pdfEx) {
                error_log("Paanyaya PDF pre-generation skipped: " . $pdfEx->getMessage());
            }

            if ($sessionId) {
                $this->conn->prepare('UPDATE paanyaya_sessions SET pdf_path = ? WHERE session_id = ?')->execute([$pdfRelativePath, $sessionId]);
            }

            if ($userId) {
                $this->audit->log($userId, "Scheduled Paanyaya for {$inviteeName}", 'Paanyaya', $complaintId ?: null);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'paanyaya_id' => $paanyayaId,
                'session_id' => $sessionId,
                'attempt_number' => $attemptNumber,
                'invitee_name' => $inviteeName,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $scheduledTime,
                'pdf_path' => $pdfRelativePath,
                'message' => "Paanyaya invitation for {$inviteeName} successfully scheduled."
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Paanyaya scheduling error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error scheduling Paanyaya: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Lists all independent Paanyaya records with optional filters.
     */
    public function getAllPaanyayas(?string $status = null, ?string $search = null): array
    {
        $sql = "
            SELECT p.*,
                   c.complaint_number,
                   c.complaint_title,
                   c.status AS complaint_workflow_status,
                   cs.case_id AS linked_case_id,
                   cs.case_status AS linked_case_status,
                   COALESCE(
                       NULLIF(TRIM(p.complainant_name), ''),
                       (
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           INNER JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = p.complaint_id AND cp.party_type = 'Complainant'
                       ),
                       'Nagpaanyaya'
                   ) AS complainant_name,
                   COALESCE(
                       NULLIF(TRIM(p.invitee_name), ''),
                       (
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           INNER JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = p.complaint_id AND cp.party_type = 'Respondent'
                       ),
                       'Inanyayahan'
                   ) AS invitee_name,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM paanyayas p
            LEFT JOIN complaints c ON c.complaint_id = COALESCE(NULLIF(p.complaint_id, 0), p.escalated_to_complaint_id)
            LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
            LEFT JOIN users u ON u.user_id = p.scheduled_by
            WHERE 1=1
        ";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (p.invitee_name LIKE ? OR c.complaint_number LIKE ? OR c.complaint_title LIKE ?)";
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY p.scheduled_date DESC, p.scheduled_time DESC, p.paanyaya_id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $records = array_map(
            fn(array $record): array => $this->withCurrentWorkflow($record),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        if ($status === null || $status === '' || $status === 'all') {
            return $records;
        }

        return array_values(array_filter($records, static fn (array $record): bool =>
            self::matchesWorkflowStatus($record['current_workflow_status'], $status)
        ));
    }

    private static function matchesWorkflowStatus(string $currentStatus, ?string $status): bool
    {
        return match ($status) {
            null, '', 'all' => true,
            'Resolved', 'Completed', 'Settled' => in_array($currentStatus, ['Resolved', 'Completed', 'Settled', 'Dismissed', 'Archived'], true),
            'Cancelled', 'Failed', 'Escalated' => in_array($currentStatus, ['Cancelled', 'Failed', 'Escalated', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Repudiated'], true),
            'Scheduled', 'Pending' => in_array($currentStatus, ['Scheduled', 'Pending', 'Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'CFA Issued'], true),
            default => $currentStatus === $status,
        };
    }

    public function getGroupedPaanyayaCases(?string $status = null, ?string $search = null): array
    {
        $groupKey = static function (array $record): string {
            $complaintId = (int) ($record['complaint_id'] ?: ($record['escalated_to_complaint_id'] ?? 0));
            return $complaintId > 0 ? 'complaint:' . $complaintId : 'single:' . (int) $record['paanyaya_id'];
        };

        $groups = [];
        foreach ($this->getAllPaanyayas() as $record) {
            $groups[$groupKey($record)][(int) $record['paanyaya_id']] = $record;
        }

        if ($search !== null) {
            $matchedGroups = [];
            foreach ($this->getAllPaanyayas(null, $search) as $record) {
                $matchedGroups[$groupKey($record)] = true;
            }
            $groups = array_intersect_key($groups, $matchedGroups);
        }

        $cases = [];
        foreach ($groups as $key => $items) {
            usort($items, static fn (array $left, array $right): int =>
                [(int) $left['attempt_number'], $left['scheduled_date'], $left['scheduled_time'], (int) $left['paanyaya_id']]
                <=> [(int) $right['attempt_number'], $right['scheduled_date'], $right['scheduled_time'], (int) $right['paanyaya_id']]
            );
            $lastIndex = count($items) - 1;
            foreach ($items as $index => &$item) {
                $item['is_latest'] = $index === $lastIndex;
                $item['step_display_status'] = $item['status'] === 'Completed' ? 'Done' : $item['status'];
            }
            unset($item);
            $latest = $items[$lastIndex];
            $workflowStatus = $latest['current_workflow_status'];
            if (!self::matchesWorkflowStatus($workflowStatus, $status)) {
                continue;
            }
            $cases[] = [
                'group_key' => $key,
                'latest' => $latest,
                'items' => $items,
                'has_second_paanyaya' => (int) $latest['attempt_number'] >= 2,
                'display_status' => $workflowStatus === 'Completed' ? 'Done' : $workflowStatus,
                'workflow_status' => $workflowStatus,
                'workflow_stage' => $latest['current_workflow_stage'],
            ];
        }
        return $cases;
    }

    /**
     * Gets a single Paanyaya record by ID.
     */
    public function getPaanyayaById(int $paanyayaId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT p.*,
                   c.complaint_number,
                   c.complaint_title,
                   c.status AS complaint_workflow_status,
                   cs.case_id AS linked_case_id,
                   cs.case_status AS linked_case_status,
                   COALESCE(
                       NULLIF(TRIM(p.complainant_name), ''),
                       (
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           INNER JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = p.complaint_id AND cp.party_type = 'Complainant'
                       ),
                       'Nagpaanyaya'
                   ) AS complainant_name,
                   COALESCE(
                       NULLIF(TRIM(p.invitee_name), ''),
                       (
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           INNER JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = p.complaint_id AND cp.party_type = 'Respondent'
                       ),
                       'Inanyayahan'
                   ) AS invitee_name,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM paanyayas p
            LEFT JOIN complaints c ON c.complaint_id = COALESCE(NULLIF(p.complaint_id, 0), p.escalated_to_complaint_id)
            LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
            LEFT JOIN users u ON u.user_id = p.scheduled_by
            WHERE p.paanyaya_id = ?
            LIMIT 1
        ");
        $stmt->execute([$paanyayaId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $record ? $this->withCurrentWorkflow($record) : null;
    }

    /**
     * Retains the Paanyaya's own status as history while exposing the live
     * stage/status of the formal case created from it, when one exists.
     */
    private function withCurrentWorkflow(array $record): array
    {
        $historicalStatus = trim((string)($record['status'] ?? 'Scheduled'));
        $linkedStatus = trim((string)($record['linked_case_status'] ?? ''));

        // A complaint can exist before it becomes a formal case. Only use a
        // complaint value as a fallback when it is a recognised case workflow state.
        if ($linkedStatus === '') {
            $complaintStatus = trim((string)($record['complaint_workflow_status'] ?? ''));
            $caseStates = ['Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Repudiated'];
            if (in_array($complaintStatus, $caseStates, true)) {
                $linkedStatus = $complaintStatus;
            }
        }

        $currentStatus = $linkedStatus !== '' ? $linkedStatus : $historicalStatus;
        $stageLabels = [
            'Docketed' => 'Stage 1 · Ready for Mediation',
            'Mediation' => 'Stage 1 · Punong Barangay Mediation',
            'Conciliation' => 'Stage 2 · Pangkat Conciliation',
            'Arbitration' => 'Stage 3 · Voluntary Arbitration',
            'Settled' => 'Completed · Amicable Settlement',
            'Dismissed' => 'Closed · Dismissed',
            'CFA Issued' => 'Completed · Certificate to File Action Issued',
            'Archived' => 'Closed · Archived',
            'DISMISSED_BARRED' => 'Closed · Dismissed / Barred',
            'RESPONDENT_DEFAULT' => 'Completed · Respondent Default',
            'Repudiated' => 'Closed · Repudiated',
            'Escalated' => 'Escalated to Formal Case Docketing',
        ];

        $record['current_workflow_status'] = $currentStatus;
        $record['current_workflow_stage'] = $stageLabels[$currentStatus] ?? 'Stage 0 · Informal Dialogue';
        $record['has_linked_case_workflow'] = $linkedStatus !== '';

        return $record;
    }

    /**
     * Updates status of an independent Paanyaya entity.
     */
    public function updatePaanyayaStatus(int $paanyayaId, string $status, ?int $userId = null): array
    {
        $validStatuses = ['Scheduled', 'Completed', 'Cancelled', 'Resolved', 'Unresolved'];
        if (!in_array($status, $validStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid status specified.'];
        }

        $stmt = $this->conn->prepare("UPDATE paanyayas SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE paanyaya_id = ?");
        $stmt->execute([$status, $paanyayaId]);

        if ($userId) {
            $this->audit->log($userId, "Updated Paanyaya #{$paanyayaId} status to {$status}", 'Paanyaya', null);
        }

        return ['success' => true, 'message' => "Paanyaya status updated to {$status}."];
    }

    /**
     * Gets all Paanyaya sessions for a complaint in chronological order.
     */
    public function getSessions(int $complaintId): array
    {
        $stmt = $this->conn->prepare("
            SELECT ps.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM paanyaya_sessions ps
            LEFT JOIN users u ON u.user_id = ps.scheduled_by
            WHERE ps.complaint_id = ?
            ORDER BY ps.attempt_number ASC, ps.session_id ASC
        ");
        $stmt->execute([$complaintId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gets a single session by session_id.
     */
    public function getSessionById(int $sessionId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT ps.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM paanyaya_sessions ps
            LEFT JOIN users u ON u.user_id = ps.scheduled_by
            WHERE ps.session_id = ?
            LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Gets latest session for a complaint.
     */
    public function getByComplaintId(int $complaintId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT ps.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM paanyaya_sessions ps
            LEFT JOIN users u ON u.user_id = ps.scheduled_by
            WHERE ps.complaint_id = ?
            ORDER BY ps.attempt_number DESC, ps.session_id DESC
            LIMIT 1
        ");
        $stmt->execute([$complaintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['invitation_date'] = $row['scheduled_date'] ?? null;
            $row['invitation_time'] = $row['scheduled_time'] ?? null;
            $row['paanyaya_id'] = $row['session_id'];
            if (!empty($row['scheduled_date'])) {
                $ts = strtotime($row['scheduled_date']);
                $dow = (int)date('w', $ts);
                $days = ['Linggo', 'Lunes', 'Martes', 'Miyerkules', 'Huwebes', 'Biyernes', 'Sabado'];
                $row['hearing_day'] = $days[$dow] ?? date('l', $ts);
            }
            if (empty($row['status'])) {
                $row['status'] = (!empty($row['session_outcome'])) ? $row['session_outcome'] : 'Scheduled';
            }
            return $row;
        }

        // Fallback to complaint_paanyaya if paanyaya_sessions is empty
        $legStmt = $this->conn->prepare("
            SELECT cp.*,
                   cp.paanyaya_id AS session_id,
                   1 AS attempt_number,
                   cp.invitation_date AS scheduled_date,
                   cp.invitation_time AS scheduled_time,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM complaint_paanyaya cp
            LEFT JOIN users u ON u.user_id = cp.scheduled_by
            WHERE cp.complaint_id = ?
            ORDER BY cp.paanyaya_id DESC
            LIMIT 1
        ");
        $legStmt->execute([$complaintId]);
        $legRow = $legStmt->fetch(PDO::FETCH_ASSOC);
        if ($legRow) {
            $legRow['scheduled_date'] = $legRow['invitation_date'] ?? null;
            $legRow['scheduled_time'] = $legRow['invitation_time'] ?? null;
            if (!empty($legRow['invitation_date'])) {
                $ts = strtotime($legRow['invitation_date']);
                $dow = (int)date('w', $ts);
                $days = ['Linggo', 'Lunes', 'Martes', 'Miyerkules', 'Huwebes', 'Biyernes', 'Sabado'];
                $legRow['hearing_day'] = $days[$dow] ?? date('l', $ts);
            }
            return $legRow;
        }
        return null;
    }

    /**
     * Records the outcome of a Paanyaya dialogue session.
     * Implements the "Max 2 Attempts" (`Dalawang Beses Lang`) State Machine:
     *
     * Session 1 Outcomes:
     * - 'Settled': Status -> 'Resolved at Paanyaya / Closed'. LTIA Pre-Docket Resolution. No 2nd Paanyaya.
     * - 'Absent_Retry': Status -> '1st Paanyaya Failed (No-Show)'. Unlocks 2nd Paanyaya.
     * - 'Continued_Dialogue': Status -> 'Paanyaya In Progress'. Unlocks 2nd Paanyaya.
     * - 'Impasse_Escalate': Status -> 'Escalate to Formal Docketing'. Triggers immediate formal docketing.
     *
     * Session 2 Outcomes:
     * - 'Settled': Status -> 'Resolved at Paanyaya / Closed'. LTIA win.
     * - Any failure / unsettled / absent -> Max 2 attempts reached. Triggers auto escalation to Formal Docketing.
     */
    public function recordOutcome(int $sessionId, array|string $data, mixed $userIdOrNotes = null, ?int $maybeUserId = null): array
    {
        if (is_string($data)) {
            $outcomeStr = $data;
            $data = ['outcome' => $outcomeStr];
            if (is_string($userIdOrNotes)) {
                $data['notes'] = $userIdOrNotes;
                $userId = $maybeUserId;
            } else {
                $userId = (int) $userIdOrNotes;
            }
        } else {
            $userId = is_int($userIdOrNotes) ? $userIdOrNotes : $maybeUserId;
        }

        $session = $this->getSessionById($sessionId);
        if (!$session) {
            return ['success' => false, 'message' => 'Paanyaya session record not found.'];
        }

        $complaintId = (int) $session['complaint_id'];
        $attemptNumber = (int) $session['attempt_number'];
        if (PaanyayaPreparationService::managesDialogue($this->conn, $complaintId)) {
            return ['success' => false, 'message' => 'Record dialogue minutes on the original complaint.'];
        }

        $outcome = trim((string) ($data['outcome'] ?? ''));
        $notes = trim((string) ($data['outcome_notes'] ?? ($data['notes'] ?? '')));
        $compAttended = in_array(trim((string)($data['complainant_attended'] ?? '')), ['Present', 'Late', 'Absent'], true)
            ? trim((string)$data['complainant_attended']) : 'Present';
        $respAttended = in_array(trim((string)($data['respondent_attended'] ?? '')), ['Present', 'Late', 'Absent'], true)
            ? trim((string)$data['respondent_attended']) : 'Present';

        // Check if individual party attendance is supplied
        $partyAttendance = $data['party_attendance'] ?? [];
        if (is_array($partyAttendance) && !empty($partyAttendance)) {
            require_once __DIR__ . '/ComplaintParty.php';
            $complaintParties = (new ComplaintParty())->getByComplaint($complaintId);
            $compHasPresent = false;
            $compCount = 0;
            $respHasPresent = false;
            $respCount = 0;

            foreach ($complaintParties as $cp) {
                $pid = (string)$cp['party_id'];
                if (isset($partyAttendance[$pid])) {
                    $att = $partyAttendance[$pid] === 'Absent' ? 'Absent' : 'Present';
                    if ($cp['party_type'] === 'Complainant') {
                        $compCount++;
                        if ($att === 'Present') $compHasPresent = true;
                    } elseif ($cp['party_type'] === 'Respondent') {
                        $respCount++;
                        if ($att === 'Present') $respHasPresent = true;
                    }
                }
            }

            if ($compCount > 0) {
                $compAttended = $compHasPresent ? 'Present' : 'Absent';
            }
            if ($respCount > 0) {
                $respAttended = $respHasPresent ? 'Present' : 'Absent';
            }
        }

        $allowedOutcomes = ['Settled', 'Absent_Retry', 'Continued_Dialogue', 'Impasse_Escalate', 'Max_Failed_Escalate', 'Dismissed'];
        if (!in_array($outcome, $allowedOutcomes, true)) {
            // Map common aliases
            if (stripos($outcome, 'dismiss') !== false) {
                $outcome = 'Dismissed';
            } elseif (stripos($outcome, 'settle') !== false || stripos($outcome, 'resolve') !== false) {
                $outcome = 'Settled';
            } elseif (stripos($outcome, 'absent') !== false || stripos($outcome, 'no-show') !== false) {
                $outcome = 'Absent_Retry';
            } elseif (stripos($outcome, 'continue') !== false || stripos($outcome, 'progress') !== false) {
                $outcome = 'Continued_Dialogue';
            } else {
                $outcome = ($attemptNumber >= self::MAX_ATTEMPTS) ? 'Max_Failed_Escalate' : 'Impasse_Escalate';
            }
        }

        $stageType = ($attemptNumber >= 2) ? self::STAGE_2 : self::STAGE_1;
        try {
            $this->conn->beginTransaction();

            // Outcome 1 / Settle (Applicable to both Attempt 1 and Attempt 2)
            if ($outcome === 'Dismissed') {
                $updSession = $this->conn->prepare("
                    UPDATE paanyaya_sessions
                    SET session_outcome = 'Dismissed',
                        outcome_notes = ?,
                        complainant_attended = ?,
                        respondent_attended = ?,
                        resolved_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $updSession->execute([$notes, $compAttended, $respAttended, $sessionId]);

                $updComplaint = $this->conn->prepare("
                    UPDATE complaints
                    SET status = 'Dismissed',
                        resolved_without_docketing = 1,
                        ltia_category = 'Dismissed at Paanyaya',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComplaint->execute([$complaintId]);

                $updStages = $this->conn->prepare("
                    UPDATE paanyaya_stages
                    SET status = 'Completed',
                        review_status = 'Case Closed',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ? AND stage_type = ?
                ");
                $updStages->execute([$complaintId, $stageType]);

                if ($userId) {
                    $this->audit->log($userId, 'Complaint Dismissed at Paanyaya (Complainant Absent)', 'Complaints', $complaintId);
                }

                // Sync independent paanyayas entity
                $this->conn->prepare("UPDATE paanyayas SET status = 'Cancelled', updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?")->execute([$complaintId]);

                $this->conn->commit();

                return [
                    'success' => true,
                    'outcome' => 'Dismissed',
                    'status' => 'Dismissed',
                    'resolved_without_docketing' => 1,
                    'ltia_category' => 'Dismissed at Paanyaya',
                    'message' => 'Reklamo ay na-dismiss dahil sa hindi pagdalo ng nagreklamo.'
                ];
            }

            if ($outcome === 'Settled') {
                $updSession = $this->conn->prepare("
                    UPDATE paanyaya_sessions
                    SET session_outcome = 'Settled',
                        outcome_notes = ?,
                        complainant_attended = ?,
                        respondent_attended = ?,
                        resolved_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $updSession->execute([$notes, $compAttended, $respAttended, $sessionId]);

                $updComplaint = $this->conn->prepare("
                    UPDATE complaints
                    SET status = 'Settled',
                        resolved_without_docketing = 1,
                        ltia_category = 'Pre-Docket Resolution',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComplaint->execute([$complaintId]);

                $updStages = $this->conn->prepare("
                    UPDATE paanyaya_stages
                    SET status = 'Completed',
                        review_status = 'Case Closed',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ? AND stage_type = ?
                ");
                $updStages->execute([$complaintId, $stageType]);

                // Sync independent paanyayas entity
                $this->conn->prepare("UPDATE paanyayas SET status = 'Resolved', updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?")->execute([$complaintId]);

                if ($stageType === self::STAGE_1) {
                    $this->conn->prepare("
                        UPDATE paanyaya_stages
                        SET status = 'Not Started',
                            review_status = 'None',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE complaint_id = ? AND stage_type = ? AND issued_at IS NULL
                    ")->execute([$complaintId, self::STAGE_2]);
                }

                if ($userId) {
                    $this->audit->log($userId, 'Informally Resolved at Paanyaya / Closed (Pre-Docket Resolution)', 'Complaints', $complaintId);
                }

                $this->conn->commit();

                return [
                    'success' => true,
                    'outcome' => 'Settled',
                    'status' => 'Settled',
                    'resolved_without_docketing' => 1,
                    'ltia_category' => 'Pre-Docket Resolution',
                    'message' => 'Dispute successfully settled during Paanyaya informal dialogue! Resolved without formal court docketing (LTIA performance win).'
                ];
            }

            // ATTEMPT 1 Specific Non-Settled Outcomes:
            if ($attemptNumber === 1) {
                if ($outcome === 'Absent_Retry') {
                    $updSession = $this->conn->prepare("
                        UPDATE paanyaya_sessions
                        SET session_outcome = 'Absent_Retry',
                            outcome_notes = ?,
                            complainant_attended = ?,
                            respondent_attended = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE session_id = ?
                    ");
                    $updSession->execute([$notes, $compAttended, $respAttended, $sessionId]);

                    $updComplaint = $this->conn->prepare("
                        UPDATE complaints
                        SET status = '1st Paanyaya Failed (No-Show)',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE complaint_id = ?
                    ");
                    $updComplaint->execute([$complaintId]);

                    if ($userId) {
                        $this->audit->log($userId, '1st Paanyaya Failed (No-Show) - 2nd Paanyaya Unlocked', 'Complaints', $complaintId);
                    }

                    $this->conn->commit();

                    return [
                        'success' => true,
                        'outcome' => 'Absent_Retry',
                        'status' => '1st Paanyaya Failed (No-Show)',
                        'can_issue_second' => true,
                        'message' => '1st Paanyaya session recorded as failed due to party absence. You may now schedule and issue the 2nd Paanyaya (final informal chance).'
                    ];
                }

                if ($outcome === 'Continued_Dialogue') {
                    $updSession = $this->conn->prepare("
                        UPDATE paanyaya_sessions
                        SET session_outcome = 'Continued_Dialogue',
                            outcome_notes = ?,
                            complainant_attended = ?,
                            respondent_attended = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE session_id = ?
                    ");
                    $updSession->execute([$notes, $compAttended, $respAttended, $sessionId]);

                    $updComplaint = $this->conn->prepare("
                        UPDATE complaints
                        SET status = 'Paanyaya In Progress',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE complaint_id = ?
                    ");
                    $updComplaint->execute([$complaintId]);

                    if ($userId) {
                        $this->audit->log($userId, 'Paanyaya Dialogue In Progress - 2nd Session Requested', 'Complaints', $complaintId);
                    }

                    $this->conn->commit();

                    return [
                        'success' => true,
                        'outcome' => 'Continued_Dialogue',
                        'status' => 'Paanyaya In Progress',
                        'can_issue_second' => true,
                        'message' => 'Parties requested a follow-up meeting. You may now schedule the 2nd Paanyaya session.'
                    ];
                }

                if ($outcome === 'Impasse_Escalate') {
                    // Outright refusal / total impasse -> Do NOT waste time on 2nd Paanyaya.
                    $updSession = $this->conn->prepare("
                        UPDATE paanyaya_sessions
                        SET session_outcome = 'Impasse_Escalate',
                            outcome_notes = ?,
                            complainant_attended = ?,
                            respondent_attended = ?,
                            escalated_at = CURRENT_TIMESTAMP,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE session_id = ?
                    ");
                    $updSession->execute([$notes, $compAttended, $respAttended, $sessionId]);

                    $this->conn->commit();

                    // Trigger immediate formal docketing & 1st Mediation
                    return $this->escalateToFormalDocketing($complaintId, $userId, $notes, $data);
                }
            }

            // ATTEMPT 2: The Final Informal Chance
            if ($attemptNumber >= self::MAX_ATTEMPTS) {
                // Any unsettled outcome at attempt 2 triggers mandatory escalation
                $finalOutcome = 'Max_Failed_Escalate';
                $updSession = $this->conn->prepare("
                    UPDATE paanyaya_sessions
                    SET session_outcome = ?,
                        outcome_notes = ?,
                        complainant_attended = ?,
                        respondent_attended = ?,
                        escalated_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $updSession->execute([$finalOutcome, $notes, $compAttended, $respAttended, $sessionId]);

                $updComp = $this->conn->prepare("
                    UPDATE complaints
                    SET max_paanyaya_reached = 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComp->execute([$complaintId]);

                $this->conn->commit();

                // Trigger formal docketing
                return $this->escalateToFormalDocketing($complaintId, $userId, $notes ?: '2nd Paanyaya failed to reach settlement. Maximum informal attempts reached (Dalawang Beses Lang).', $data);
            }

            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Unhandled Paanyaya outcome scenario.'];

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Paanyaya outcome recording error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording outcome: ' . $e->getMessage()];
        }
    }

    /**
     * Escalates an unsettled Paanyaya dispute to Formal Case Docketing & 1st Mediation.
     * 1. Assigns Formal Case Docket Number (e.g. 10-12-2026).
     * 2. Sets Case Stage to Mediation (1st Hearing).
     * 3. Starts statutory 15-day Punong Barangay mediation timer.
     * 4. Auto-generates KP Form 7 (Complaint), KP Form 8 (Notice), KP Form 9 (Summons).
     */
    public function escalateToFormalDocketing(int $complaintId, ?int $userId = null, ?string $notes = null, array $escalationData = []): array
    {
        if (PaanyayaPreparationService::managesDialogue($this->conn, $complaintId)) {
            return $this->docketRecordedDialogue($complaintId, $userId ?? 0);
        }
        try {
            require_once __DIR__ . '/CaseModel.php';
            require_once __DIR__ . '/Summons.php';

            $caseModel = new CaseModel();
            $summonsModel = new Summons();

            // 1. Check if case already docketed
            $checkCase = $this->conn->prepare('SELECT case_id, case_number, case_status FROM cases WHERE complaint_id = ? LIMIT 1');
            $checkCase->execute([$complaintId]);
            $existingCase = $checkCase->fetch(PDO::FETCH_ASSOC);

            $caseId = 0;
            $caseNumber = '';

            if ($existingCase) {
                $caseId = (int) $existingCase['case_id'];
                $caseNumber = $existingCase['case_number'];
                $this->conn->prepare("UPDATE cases SET case_status = 'Mediation' WHERE case_id = ? AND case_status IN ('Docketed', 'Pre-Docketed (Paanyaya)')")->execute([$caseId]);
            } else {
                // Fetch complaint details to determine case_type
                $cStmt = $this->conn->prepare('SELECT case_type FROM complaints WHERE complaint_id = ? LIMIT 1');
                $cStmt->execute([$complaintId]);
                $cType = $cStmt->fetchColumn() ?: 'Civil';

                // Allow docketing transition from Pre-Docketed
                $this->conn->prepare("UPDATE complaints SET status = 'Accepted' WHERE complaint_id = ?")->execute([$complaintId]);

                $docketSuccess = $caseModel->create([
                    'complaint_id' => $complaintId,
                    'case_type' => $cType
                ]);

                if (!$docketSuccess) {
                    return ['success' => false, 'message' => 'Failed to initialize formal case docketing in database.'];
                }

                // Retrieve assigned case number
                $fetchCase = $this->conn->prepare('SELECT case_id, case_number FROM cases WHERE complaint_id = ? ORDER BY case_id DESC LIMIT 1');
                $fetchCase->execute([$complaintId]);
                $newCase = $fetchCase->fetch(PDO::FETCH_ASSOC);
                $caseId = (int) ($newCase['case_id'] ?? 0);
                $caseNumber = $newCase['case_number'] ?? '';
                if ($caseId > 0) {
                    $this->conn->prepare("UPDATE cases SET case_status = 'Mediation' WHERE case_id = ?")->execute([$caseId]);
                    try {
                        require_once __DIR__ . '/CaseStage.php';
                        (new CaseStage($this->conn))->startStage($caseId, 'Mediation');
                    } catch (Throwable $se) {
                        error_log('Could not start Mediation stage on escalation: ' . $se->getMessage());
                    }
                }
            }

            // 2. Determine 1st Mediation Hearing parameters dynamically based on Office Hours & Session Duration Settings
            require_once __DIR__ . '/../services/MediationDeadlineService.php';
            require_once __DIR__ . '/Setting.php';

            $rawMedDays = Setting::getSetting('mediation_day_of_week', 'Tue');
            $allowedMedDays = [];
            if (is_string($rawMedDays)) {
                $dec = json_decode($rawMedDays, true);
                $allowedMedDays = is_array($dec) ? $dec : array_filter(array_map('trim', explode(',', $rawMedDays)));
            } elseif (is_array($rawMedDays)) {
                $allowedMedDays = $rawMedDays;
            }
            if (empty($allowedMedDays)) {
                $allowedMedDays = ['Tue'];
            }
            $dayNumMap = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];
            $pluralMap = ['Mon' => 'Mondays', 'Tue' => 'Tuesdays', 'Wed' => 'Wednesdays', 'Thu' => 'Thursdays', 'Fri' => 'Fridays', 'Sat' => 'Saturdays', 'Sun' => 'Sundays'];
            $allowedDayNumbers = array_values(array_unique(array_filter(array_map(fn($d) => $dayNumMap[$d] ?? null, $allowedMedDays))));
            $medDaysPlural = array_map(fn($d) => $pluralMap[$d] ?? $d, $allowedMedDays);
            $medDaysLabel = implode(', ', $medDaysPlural);

            $dailyCap = (int) Setting::getSetting('daily_hearing_capacity', 8);
            $medHoursStart = Setting::getSetting('mediation_hours_start', '08:00');
            $medHoursEnd = Setting::getSetting('mediation_hours_end', '16:30');
            $venueSetting = Setting::getSetting('mediation_venue', 'Lupon Court Room');

            $medDate = !empty($escalationData['mediation_date']) ? trim((string)$escalationData['mediation_date']) : '';
            $medTime = !empty($escalationData['mediation_time']) ? trim((string)$escalationData['mediation_time']) : '';
            $venue = !empty($escalationData['venue']) ? trim((string)$escalationData['venue']) : $venueSetting;
            $summonsResult = [];
            $hasExplicitSchedule = ($medDate !== '' && $medTime !== '');
            if ($hasExplicitSchedule) {

            if (!empty($medDate)) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $medDate)) {
                    return ['success' => false, 'message' => 'Please provide a valid 1st Mediation date (YYYY-MM-DD).'];
                }
                $todayStr = date('Y-m-d');
                if ($medDate <= $todayStr) {
                    return ['success' => false, 'message' => "Mediation date must be a future scheduled mediation day ({$medDaysLabel})."];
                }
                $dateObj = new DateTimeImmutable($medDate);
                $dayN = (int) $dateObj->format('N');
                if (!in_array($dayN, $allowedDayNumbers, true)) {
                    return ['success' => false, 'message' => "Mediation hearings can only be scheduled on {$medDaysLabel} according to Office Hours Settings."];
                }
                if (MediationDeadlineService::isNonWorkingDay($dateObj)) {
                    return ['success' => false, 'message' => 'The selected date is a declared holiday or non-working day. Please select another date.'];
                }
            }

            if (!empty($medTime)) {
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $medTime)) {
                    return ['success' => false, 'message' => 'Please provide a valid target call time (HH:MM).'];
                }
                $startMin = (int) date('H', strtotime($medHoursStart)) * 60 + (int) date('i', strtotime($medHoursStart));
                $endMin = (int) date('H', strtotime($medHoursEnd)) * 60 + (int) date('i', strtotime($medHoursEnd));
                $timeMin = (int) date('H', strtotime($medTime)) * 60 + (int) date('i', strtotime($medTime));
                if ($timeMin < $startMin || $timeMin > $endMin) {
                    return ['success' => false, 'message' => "Mediation sessions must have target call times between {$medHoursStart} and {$medHoursEnd}."];
                }
            }

            // If date or time is missing, auto-find next open mediation date and available slot
            if (empty($medDate) || empty($medTime)) {
                $allowedSlots = ['13:00', '13:15', '13:30', '13:45', '14:00', '14:15', '14:30', '14:45', '15:00', '15:30', '16:00', '16:30'];
                $checkDay = new DateTime('+1 day');
                while (!in_array((int)$checkDay->format('N'), $allowedDayNumbers, true)) {
                    $checkDay->modify('+1 day');
                }

                $foundDate = null;
                $foundTime = null;

                for ($step = 0; $step < 30; $step++) {
                    $dateCandidate = $checkDay->format('Y-m-d');
                    if (in_array((int)$checkDay->format('N'), $allowedDayNumbers, true)) {
                        $candStmt = $this->conn->prepare("
                            SELECT COUNT(*) FROM hearings h
                            INNER JOIN cases c ON c.case_id = h.case_id
                            WHERE DATE(h.hearing_date) = ?
                              AND h.hearing_type = 'Mediation'
                              AND c.case_status NOT IN ('Dismissed', 'Settled')
                              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
                        ");
                        $candStmt->execute([$dateCandidate]);
                        $candCnt = (int) $candStmt->fetchColumn();

                        if ($candCnt < $dailyCap && !MediationDeadlineService::isNonWorkingDay(new DateTimeImmutable($dateCandidate))) {
                            foreach ($allowedSlots as $slot) {
                                $candDateTime = $dateCandidate . ' ' . $slot . ':00';
                                $conflict = $summonsModel->findMediationConflict($candDateTime, $venue, $caseId);
                                if (!$conflict) {
                                    $foundDate = $dateCandidate;
                                    $foundTime = $slot;
                                    break 2;
                                }
                            }
                        }
                    }
                    $checkDay->modify('+1 day');
                }

                $medDate = $foundDate ?: $checkDay->format('Y-m-d');
                $medTime = $foundTime ?: $medHoursStart;
            }

            // Enforce daily capacity ceiling via SQL
            $capStmt = $this->conn->prepare("
                SELECT COUNT(*) FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                WHERE DATE(h.hearing_date) = ?
                  AND h.hearing_type = 'Mediation'
                  AND c.case_status NOT IN ('Dismissed', 'Settled')
                  AND h.status NOT IN ('Cancelled', 'Office Cancelled')
            ");
            $capStmt->execute([$medDate]);
            $bookedCeiling = (int) $capStmt->fetchColumn();
            if ($bookedCeiling >= $dailyCap) {
                return [
                    'success' => false,
                    'message' => "Daily capacity limit reached: All {$dailyCap} mediation slots are fully booked for this date. Please select another date."
                ];
            }

            // Room conflict check using Elastic Duration
            $hearingDateTime = substr($medDate, 0, 10) . ' ' . substr($medTime, 0, 5) . ':00';
            $conflict = $summonsModel->findMediationConflict($hearingDateTime, $venue, $caseId);
            if ($conflict) {
                return [
                    'success' => false,
                    'conflict' => true,
                    'message' => $conflict['message'] ?? 'Schedule conflict: Another hearing is already scheduled during this call window in the Lupon Court Room.'
                ];
            }

            $hearingPayload = [
                'mediation_date' => $medDate,
                'mediation_time' => $medTime,
                'venue' => $venue,
                'remarks' => $notes ?: 'Escalated from Paanyaya (Informal Invitation) pre-docketing stage.',
                'override_paanyaya' => true
            ];

            // 3. Issue First Summons (Auto-generates KP Form 8 and KP Form 9)
            $summonsResult = $summonsModel->issueFirstSummon($complaintId, $userId ?? 1, $hearingPayload);
            } // end if ($hasExplicitSchedule)

            if ($hasExplicitSchedule && empty($summonsResult['success'])) {
                return [
                    'success' => false,
                    'message' => $summonsResult['message'] ?? 'Failed to issue 1st Mediation summons.'
                ];
            }

            // 4. Auto-generate KP Form 7 (Formal Complaint)
            $kp7Path = null;
            try {
                $kp7Dir = dirname(__DIR__, 2) . '/storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber);
                if (!is_dir($kp7Dir)) {
                    @mkdir($kp7Dir, 0750, true);
                }

                $kp7Relative = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber) . '/KP-Form-7-Complaint-' . date('Ymd-His') . '.pdf';
                $kp7Absolute = dirname(__DIR__, 2) . '/' . $kp7Relative;

                // Load complaint data for KP Form 7
                $cDetailsStmt = $this->conn->prepare("
                    SELECT c.*, cat.category_name 
                    FROM complaints c 
                    LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id 
                    WHERE c.complaint_id = ? LIMIT 1
                ");
                $cDetailsStmt->execute([$complaintId]);
                $cDetails = $cDetailsStmt->fetch(PDO::FETCH_ASSOC);

                $partyStmt = $this->conn->prepare("
                    SELECT cp.*, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name,
                           r.address, r.purok
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = ?
                    ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name
                ");
                $partyStmt->execute([$complaintId]);
                $parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);

                $complainants = [];
                $respondents = [];
                foreach ($parties as $p) {
                    $item = [
                        'full_name' => $p['full_name'],
                        'address' => trim(implode(', ', array_filter([$p['address'] ?? '', $p['purok'] ?? '']))),
                    ];
                    if ($p['party_type'] === 'Complainant') $complainants[] = $item;
                    if ($p['party_type'] === 'Respondent') $respondents[] = $item;
                }

                $kp7Data = [
                    'complaint_id' => $complaintId,
                    'case_number' => $caseNumber,
                    'complaint_number' => $caseNumber,
                    'complaint_title' => $cDetails['complaint_title'] ?? '',
                    'category_name' => $cDetails['category_name'] ?? '',
                    'narrative' => $cDetails['narrative'] ?? '',
                    'requested_resolution' => $cDetails['requested_resolution'] ?? '',
                    'incident_date' => $cDetails['incident_date'] ?? date('Y-m-d'),
                    'incident_time' => $cDetails['incident_time'] ?? null,
                    'incident_location' => $cDetails['incident_location'] ?? 'Barangay Tumana',
                    'filed_date' => date('Y-m-d'),
                    'complainants' => $complainants,
                    'respondents' => $respondents,
                ];

                $this->pdf->generateKp7($kp7Data, $kp7Absolute);

                if (file_exists($kp7Absolute)) {
                    $kp7Path = $kp7Relative;
                    // Register KP Form 7 in generated_documents
                    $tplStmt = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 7' LIMIT 1");
                    $tplStmt->execute();
                    $kp7TplId = (int) $tplStmt->fetchColumn() ?: 1;

                    $insDoc = $this->conn->prepare("
                        INSERT INTO generated_documents (
                            case_id, template_id, file_path, generated_by, service_status
                        ) VALUES (?, ?, ?, ?, 'Served')
                    ");
                    $insDoc->execute([$caseId, $kp7TplId, $kp7Relative, $userId ?? 1]);
                }
            } catch (Throwable $docErr) {
                error_log('Failed to generate KP Form 7 on escalation: ' . $docErr->getMessage());
            }

            // 5. Update complaints status to Mediation
            $this->conn->prepare("UPDATE complaints SET status = 'Mediation', complaint_number = ?, updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?")->execute([$caseNumber, $complaintId]);
            $this->conn->prepare("UPDATE paanyaya_stages SET status = 'Completed', review_status = 'Approved for Summons', updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?")->execute([$complaintId]);

            if ($userId) {
                $this->audit->log($userId, "Escalated to Formal Case Docketing: Case #{$caseNumber} assigned and routed to Mediation (Stage 1)", 'Complaints', $complaintId);
            }

            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $notifService = new NotificationService();
                $notifService->notifyRoles(
                    ['Administrator', 'Lupon Clerk'],
                    "Dispute Escalated to Formal Mediation",
                    "Dispute was escalated to Formal Mediation (Stage 1). Case #{$caseNumber} has been officially docketed.",
                    $userId
                );
            } catch (Throwable $ne) {
                error_log('Failed to dispatch escalation notification: ' . $ne->getMessage());
            }


            return [
                'success' => true,
                'escalated' => true,
                'case_id' => $caseId,
                'action' => 'escalate_mediation',
                'case_number' => $caseNumber,

                'hearing_id' => (int) ($summonsResult['hearing_id'] ?? 0),
                'document_id' => $summonsResult['document_id'] ?? null,
                'kp8_document_id' => $summonsResult['kp8_document_id'] ?? null,
                'kp9_document_id' => $summonsResult['kp9_document_id'] ?? ($summonsResult['document_id'] ?? null),
                'complaint_id' => $complaintId,
                'hearing_date' => $medDate,
                'hearing_time' => $medTime,
                'venue' => 'Lupon Court Room',
                'kp7_generated' => ($kp7Path !== null),
                'kp8_generated' => ($summonsResult['kp8_document_id'] ?? null) !== null,
                'kp9_generated' => ($summonsResult['document_id'] ?? null) !== null,
                'workspace_url' => "../complaints/complaint-details.php?id={$complaintId}&tab=mediation",
                'message' => "Formal Docketing complete! Official Case #{$caseNumber} assigned and routed to Mediation (Stage 1)."
            ];

        } catch (Throwable $e) {
            error_log('Escalation error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Escalation failed: ' . $e->getMessage()];
        }
    }

    private function docketRecordedDialogue(int $complaintId, int $userId): array
    {
        require_once __DIR__ . '/CaseModel.php';
        if ($userId < 1) {
            return ['success' => false, 'message' => 'A staff account is required.'];
        }
        $this->conn->beginTransaction();
        try {
            $lock = $this->conn->prepare('SELECT case_type, status FROM complaints WHERE complaint_id = ? FOR UPDATE');
            $lock->execute([$complaintId]);
            $complaint = $lock->fetch(PDO::FETCH_ASSOC);
            $lookup = $this->conn->prepare('SELECT case_id, case_number FROM cases WHERE complaint_id = ?');
            $lookup->execute([$complaintId]);
            $case = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$case) {
                $minutes = $this->conn->prepare('SELECT complainant_choice FROM paanyaya_minutes WHERE complaint_id = ?
                    ORDER BY FIELD(stage_type, \'Paanyaya 2\', \'Paanyaya 1\'), minutes_id DESC LIMIT 1');
                $minutes->execute([$complaintId]);
                if (($complaint['status'] ?? '') !== 'Pre-Docketed (Paanyaya)' || $minutes->fetchColumn() !== 'Escalate_Mediation') {
                    throw new DomainException('Record dialogue minutes with a mediation outcome before docketing this complaint.');
                }
                if (!(new CaseModel($this->conn))->create(['complaint_id' => $complaintId, 'case_type' => $complaint['case_type']])) {
                    throw new RuntimeException('Unable to create the formal case.');
                }
                $lookup->execute([$complaintId]);
                $case = $lookup->fetch(PDO::FETCH_ASSOC);
                $this->conn->prepare("UPDATE cases SET case_status = 'Mediation' WHERE case_id = ?")->execute([$case['case_id']]);
                $this->conn->prepare("INSERT INTO case_stages (case_id, stage_type, stage_status)
                    VALUES (?, 'Mediation', 'Not Started') ON DUPLICATE KEY UPDATE stage_type = VALUES(stage_type)")
                    ->execute([$case['case_id']]);
                $this->conn->prepare('UPDATE complaints SET docketed_by = ?, docketed_at = NOW() WHERE complaint_id = ?')
                    ->execute([$userId, $complaintId]);
                $this->conn->prepare("UPDATE paanyayas SET status = 'Escalated', escalated_to_complaint_id = ?
                    WHERE complaint_id = ? AND attempt_number = (SELECT paanyaya_attempt_count FROM complaints WHERE complaint_id = ?)")
                    ->execute([$complaintId, $complaintId, $complaintId]);
                if (!(new AuditService($this->conn))->log($userId, 'Docketed Paanyaya for formal mediation', 'Complaints', $complaintId)) {
                    throw new RuntimeException('Unable to save the docketing audit entry.');
                }
            }
            $this->conn->commit();
            return ['success' => true, 'case_id' => (int) $case['case_id'], 'case_number' => $case['case_number'],
                'message' => 'Formal case ' . $case['case_number'] . ' is ready for mediation scheduling.'];
        } catch (Throwable $exception) {
            $this->conn->rollBack();
            if ($exception instanceof DomainException) {
                return ['success' => false, 'message' => $exception->getMessage()];
            }
            error_log('Paanyaya docketing: ' . $exception->getMessage());
            return ['success' => false, 'message' => 'Unable to docket this complaint. The saved minutes are available for retry.'];
        }
    }

    /**
     * Gets full workspace state for the Paanyaya Pre-Docketing interface.
     */
    public function getWorkspaceData(int $complaintId): ?array
    {
        $cStmt = $this->conn->prepare("
            SELECT c.*, cat.category_name,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS encoder_name
            FROM complaints c
            LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
            LEFT JOIN users u ON u.user_id = c.encoded_by
            WHERE c.complaint_id = ?
            LIMIT 1
        ");
        $cStmt->execute([$complaintId]);
        $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            return null;
        }

        // Fetch parties
        $pStmt = $this->conn->prepare("
            SELECT cp.*, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name,
                   r.address, r.purok, r.contact_no, r.email, r.gender, r.civil_status
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name
        ");
        $pStmt->execute([$complaintId]);
        $parties = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainants = [];
        $respondents = [];
        foreach ($parties as $p) {
            if ($p['party_type'] === 'Complainant') $complainants[] = $p;
            if ($p['party_type'] === 'Respondent') $respondents[] = $p;
        }

        // Fetch all sessions
        $sessions = $this->getSessions($complaintId);
        $latestSession = !empty($sessions) ? end($sessions) : null;
        $attemptCount = (int) ($complaint['paanyaya_attempt_count'] ?? count($sessions) ?: 1);

        // Check if formally docketed
        $caseStmt = $this->conn->prepare('SELECT case_id, case_number, case_status, created_at FROM cases WHERE complaint_id = ? LIMIT 1');
        $caseStmt->execute([$complaintId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $isResolved = ($complaint['status'] === 'Settled' && empty($case)) || ($complaint['status'] === 'Resolved at Paanyaya / Closed') || !empty($complaint['resolved_without_docketing']);
        $isEscalated = ($case !== null) || in_array($complaint['status'], ['Docketed', 'Mediation', 'Conciliation', 'Arbitration'], true) || ($complaint['status'] === 'Settled' && $case !== null);
        $maxReached = !empty($complaint['max_paanyaya_reached']) || (count($sessions) >= self::MAX_ATTEMPTS);
        $canScheduleSecond = !$isResolved && !$isEscalated && (count($sessions) === 1) && in_array($latestSession['session_outcome'] ?? '', ['Absent_Retry', 'Continued_Dialogue'], true);

        $twoStages = $this->getTwoStagesData($complaintId);

        return [
            'complaint' => $complaint,
            'complainants' => $complainants,
            'respondents' => $respondents,
            'sessions' => $sessions,
            'latest_session' => $latestSession,
            'attempt_count' => $attemptCount,
            'max_attempts' => self::MAX_ATTEMPTS,
            'max_reached' => $maxReached,
            'can_schedule_second' => $canScheduleSecond,
            'is_resolved' => $isResolved,
            'is_escalated' => $isEscalated,
            'case' => $case,
            'two_stages' => $twoStages,
            'can_issue_summons' => $twoStages['summons_prerequisite']['allowed'] ?? false,
            'summons_block_reason' => $twoStages['summons_prerequisite']['message'] ?? '',
        ];
    }

    /**
     * Gets all serving officers (Summons Servers, Clerks, Admins).
     */
    public function getServingOfficers(): array
    {
        $stmt = $this->conn->query("
            SELECT u.user_id, u.username, u.role_id, r.role_name,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.status = 'Active' AND u.role_id IN (1, 2, 4)
            ORDER BY u.role_id DESC, u.last_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gets serving officers specifically with the Summons Server role.
     */
    public function getSummonsServers(): array
    {
        $stmt = $this->conn->query("
            SELECT u.user_id, u.username, u.role_id, r.role_name,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.status = 'Active' AND (u.role_id = 4 OR r.role_name = 'Summons Server')
            ORDER BY u.last_name ASC
        ");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return !empty($results) ? $results : $this->getServingOfficers();
    }

    /**
     * Gets eligible presiding officers / Lupon facilitators (Lupon Member, Lupon Clerk, Administrator).
     */
    public function getEligiblePresiders(): array
    {
        $stmt = $this->conn->query("
            SELECT u.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name, r.role_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.status = 'Active' AND r.role_name IN ('Lupon Member', 'Lupon Clerk', 'Administrator', 'Punong Barangay')
            ORDER BY r.role_id ASC, u.last_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Resolves an array or JSON string of presiding officer IDs to an array of presider details.
     */
    public function resolvePresidingOfficers(mixed $presiderIds): array
    {
        if (is_string($presiderIds)) {
            $presiderIds = json_decode($presiderIds, true);
        }
        if (!is_array($presiderIds) || empty($presiderIds)) {
            return [];
        }
        $cleanIds = array_values(array_filter(array_map('intval', $presiderIds), fn($id) => $id > 0));
        if (empty($cleanIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $stmt = $this->conn->prepare("
            SELECT u.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name, r.role_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.user_id IN ({$placeholders})
            ORDER BY FIELD(u.user_id, {$placeholders})
        ");
        $stmt->execute(array_merge($cleanIds, $cleanIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gets or initializes a Paanyaya stage record ('Paanyaya 1' or 'Paanyaya 2') for a complaint.
     */
    public function getOrCreateStage(int $complaintId, string $stageType): array
    {
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            throw new InvalidArgumentException("Invalid stage type: {$stageType}");
        }

        $stmt = $this->conn->prepare("
            SELECT ps.*,
                   TRIM(CONCAT_WS(' ', u_iss.first_name, u_iss.middle_name, u_iss.last_name)) AS issued_by_name,
                   TRIM(CONCAT_WS(' ', u_rev.first_name, u_rev.middle_name, u_rev.last_name)) AS reviewed_by_name
            FROM paanyaya_stages ps
            LEFT JOIN users u_iss ON u_iss.user_id = ps.issued_by
            LEFT JOIN users u_rev ON u_rev.user_id = ps.reviewed_by
            WHERE ps.complaint_id = ? AND ps.stage_type = ?
            LIMIT 1
        ");
        $stmt->execute([$complaintId, $stageType]);
        $stage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stage) {
            $ins = $this->conn->prepare("
                INSERT INTO paanyaya_stages (complaint_id, stage_type, status)
                VALUES (?, ?, 'Not Started')
            ");
            $ins->execute([$complaintId, $stageType]);

            $stmt->execute([$complaintId, $stageType]);
            $stage = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $stage;
    }

    /**
     * Gets all delivery attempts (Officer's Returns) for a stage in chronological order.
     */
    public function getStageAttempts(int $paanyayaStageId): array
    {
        // Officer's Return is completely removed from Paanyaya workflow
        return [];
    }

    /**
     * Checks if the required Paanyaya stages have been satisfied to unlock the formal summons workflow.
     */
    /**
     * Checks if mediation summons is allowed. Paanyaya and Mediation operate as separate workflows.
     * Officer's Return is removed from Paanyaya, so summons is never gated by paanyaya delivery attempts.
     */
    public function checkSummonsPrerequisite(int $complaintId): array
    {
        $cStmt = $this->conn->prepare('SELECT status FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $compRow = $cStmt->fetch(PDO::FETCH_ASSOC);

        if ($compRow && in_array($compRow['status'], ['Dismissed', 'Settled', 'Closed', 'Resolved at Paanyaya / Closed'], true)) {
            return [
                'allowed' => false,
                'reason' => 'case_closed',
                'message' => 'Ang reklamo ay ' . ($compRow['status'] === 'Dismissed' ? 'na-dismiss' : 'nalutas na') . '. Hindi na maaaring maglabas ng pormal na summons.'
            ];
        }

        return [
            'allowed' => true,
            'message' => 'Mediation summons unlocked.'
        ];
    }
    public function getTwoStagesData(int $complaintId): array
    {
        $stage1 = $this->getOrCreateStage($complaintId, self::STAGE_1);
        $stage2 = $this->getOrCreateStage($complaintId, self::STAGE_2);

        // Officer's Return removed from Paanyaya — no attempt tracking
        $attempts1 = [];
        $attempts2 = [];
        $count1 = 0;
        $count2 = 0;
        $latest1 = null;
        $latest2 = null;

        // Delivery no longer tracked for Paanyaya — invitation is considered delivered once issued
        $isDelivered1 = ($stage1['status'] === 'Completed') || ($stage1['issued_at'] !== null);
        $isDelivered2 = ($stage2['status'] === 'Completed') || ($stage2['issued_at'] !== null);

        // Exhaustion not applicable without officer returns
        $isExhausted1 = false;
        $isExhausted2 = false;

        $minutes1 = $this->getStageMinutes($complaintId, self::STAGE_1);
        $minutes2 = $this->getStageMinutes($complaintId, self::STAGE_2);

        $cStmt = $this->conn->prepare('SELECT status, paanyaya_1_outcome FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $compRow = $cStmt->fetch(PDO::FETCH_ASSOC);

        $isP1Solved = (($compRow['status'] ?? '') === 'Settled') ||
                      (($compRow['status'] ?? '') === 'Resolved at Paanyaya / Closed') ||
                      (($compRow['status'] ?? '') === 'Dismissed') ||
                      (($compRow['paanyaya_1_outcome'] ?? '') === 'Settled') ||
                      (($compRow['paanyaya_1_outcome'] ?? '') === 'Dismissed') ||
                      ($minutes1 && in_array($minutes1['outcome'], ['Settled', 'Dismissed'], true));

        $p2WasIssued = ($stage2['issued_at'] !== null); // count2 always 0 (no officer returns)

        $canUnlockP2FromMinutes = !$isP1Solved && ($minutes1 !== null) &&
            in_array($minutes1['outcome'], ['No_Agreement', 'Party_Absent'], true) &&
            ($minutes1['complainant_choice'] === 'Request_Paanyaya_2');

        $isEligible2 = !$isP1Solved && ($p2WasIssued || $canUnlockP2FromMinutes || ($stage1['review_status'] === 'Approved for Paanyaya 2'));

        $isCompleted1 = ($minutes1 !== null) ||
                        ($stage1['status'] === 'Completed') ||
                        in_array($stage1['review_status'], ['Approved for Paanyaya 2', 'Approved for Summons', 'Case Closed'], true);

        // Officer's Return removed — attempt recording is no longer applicable
        $canRecordAttempt1 = false;
        $canRecordAttempt2 = false;

        $prereq = $this->checkSummonsPrerequisite($complaintId);

        return [
            'stage_1' => [
                'details' => $stage1,
                'attempts' => $attempts1,
                'attempts_count' => $count1,
                'max_attempts' => self::MAX_STAGE_ATTEMPTS,
                'next_attempt_number' => min(self::MAX_STAGE_ATTEMPTS, $count1 + 1),
                'latest_attempt' => $latest1,
                'is_delivered' => $isDelivered1,
                'is_exhausted' => $isExhausted1,
                'is_completed' => $isCompleted1,
                'can_issue' => ($stage1['status'] === 'Not Started'),
                'can_record_attempt' => $canRecordAttempt1,
                'needs_review' => false, // Officer's Return removed — review triggered by minutes outcome
                'minutes_recorded' => ($minutes1 !== null),
                'minutes' => $minutes1,
                'outcome' => $minutes1['outcome'] ?? null,
                'complainant_choice' => $minutes1['complainant_choice'] ?? null,
            ],
            'stage_2' => [
                'details' => $stage2,
                'attempts' => $attempts2,
                'attempts_count' => $count2,
                'max_attempts' => self::MAX_STAGE_ATTEMPTS,
                'next_attempt_number' => min(self::MAX_STAGE_ATTEMPTS, $count2 + 1),
                'latest_attempt' => $latest2,
                'is_eligible' => $isEligible2,
                'can_unlock_p2' => $canUnlockP2FromMinutes,
                'is_delivered' => $isDelivered2,
                'is_exhausted' => $isExhausted2,
                'is_completed' => ($p2WasIssued && ($minutes2 !== null || $stage2['status'] === 'Completed' || in_array($stage2['review_status'], ['Approved for Summons', 'Case Closed'], true))),
                'can_issue' => ($isEligible2 && $stage2['status'] === 'Not Started'),
                'can_record_attempt' => ($isEligible2 && $canRecordAttempt2),
                'needs_review' => false, // Officer's Return removed — no exhaustion-based review
                'minutes_recorded' => ($minutes2 !== null),
                'minutes' => $minutes2,
                'outcome' => $minutes2['outcome'] ?? null,
                'complainant_choice' => $minutes2['complainant_choice'] ?? null,
                'bypassed' => ($isP1Solved && !$p2WasIssued),
            ],
            'summons_prerequisite' => $prereq,
            'serving_officers' => $this->getServingOfficers(),
            'eligible_presiders' => $this->getEligiblePresiders()
        ];
    }

    /**
     * Issues Paanyaya 1 or Paanyaya 2 preliminary invitation.
     */
    /**
     * Issues Paanyaya 1 or Paanyaya 2 preliminary invitation.
     * Stores only Invitee Name, Scheduled Date, Scheduled Time, and status.
     * Officer's Return is excluded from this workflow.
     */
    public function issueStage(int $complaintId, string $stageType, array $data, ?int $userId = null): array
    {
        if (PaanyayaPreparationService::requiresPreparation($this->conn, $complaintId)) {
            return ['success' => false, 'message' => 'Prepare this walk-in invitation from the original intake record.'];
        }
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            return ['success' => false, 'message' => 'Invalid Paanyaya stage specified.'];
        }

        if (PaanyayaPreparationService::managesDialogue($this->conn, $complaintId)) {
            return ['success' => false, 'message' => 'Prepare this invitation on the original complaint.'];
        }

        $cStmt = $this->conn->prepare('SELECT complaint_id, complaint_number, complaint_title, status FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        $stage = $this->getOrCreateStage($complaintId, $stageType);

        if ($stageType === self::STAGE_2) {
            $stage1 = $this->getOrCreateStage($complaintId, self::STAGE_1);
            $stage1Minutes = $this->getStageMinutes($complaintId, self::STAGE_1);
            $approved1 = in_array($stage1['review_status'], ['Approved for Paanyaya 2', 'Approved for Summons'], true);
            $canIssueP2 = ($stage1Minutes && in_array($stage1Minutes['outcome'], ['No_Agreement', 'Party_Absent'], true) && $stage1Minutes['complainant_choice'] === 'Request_Paanyaya_2');

            if (!$approved1 && !$canIssueP2 && $stage['status'] === 'Not Started') {
                return [
                    'success' => false,
                    'message' => 'Paanyaya 2 is locked. It unlocks ONLY if Paanyaya 1 concludes with No Agreement and the complainant requests a second invitation.'
                ];
            }
        }

        // Invitee Name
        $inviteeName = trim((string)($data['invitee_name'] ?? ($data['name'] ?? '')));
        if ($inviteeName === '') {
            $rStmt = $this->conn->prepare("
                SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                FROM complaint_parties cp
                JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type = 'Respondent'
            ");
            $rStmt->execute([$complaintId]);
            $inviteeName = (string) $rStmt->fetchColumn();
        }
        if ($inviteeName === '') {
            $inviteeName = 'Inanyayahang Residente';
        }

        $invitationDate = trim((string) ($data['invitation_date'] ?? ''));
        $invitationTime = trim((string) ($data['invitation_time'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? 'Tanggapan ng Barangay Tumana'));
        $hasESignature = isset($data['has_e_signature']) ? (int) ((bool) $data['has_e_signature']) : 1;

        $compResidentId = filter_var($data['complainant_resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $compAddress = trim((string) ($data['complainant_address'] ?? ''));
        $compPurok = trim((string) ($data['complainant_purok'] ?? ''));
        $compContact = trim((string) ($data['complainant_contact_no'] ?? ''));
        $compEmail = trim((string) ($data['complainant_email'] ?? ''));

        $invResidentId = filter_var($data['invitee_resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $invAddress = trim((string) ($data['invitee_address'] ?? ''));
        $invPurok = trim((string) ($data['invitee_purok'] ?? ''));
        $invContact = trim((string) ($data['invitee_contact_no'] ?? ''));
        $invEmail = trim((string) ($data['invitee_email'] ?? ''));
        $salaysayCheck = trim((string) ($data['salaysay'] ?? ''));

        if ($complaintId <= 0 && !empty($data['require_party_details'])) {
            if ($complainantName === '') {
                return ['success' => false, 'message' => 'Please enter the full name of the Complainant (Nagpaanyaya).'];
            }
            if ($compAddress === '' && $compPurok === '') {
                return ['success' => false, 'message' => 'Please provide the street address or Purok of the Complainant (Nagpaanyaya).'];
            }
            if ($inviteeName === '' || $inviteeName === 'Inanyayahang Residente') {
                return ['success' => false, 'message' => 'Please enter the full name of the Person Being Invited (Inanyayahan).'];
            }
            if ($invAddress === '' && $invPurok === '') {
                return ['success' => false, 'message' => 'Please provide the street address or Purok of the Person Being Invited (Inanyayahan) for invitation delivery.'];
            }
            if ($salaysayCheck === '') {
                return ['success' => false, 'message' => 'Please provide the Salaysay (Statement / Dispute Narrative) for the Paanyaya invitation.'];
            }
        }

        if ($invitationDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invitationDate)) {
            $invitationDate = date('Y-m-d', strtotime('+3 days'));
        }

        if ($invitationTime === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $invitationTime)) {
            $invitationTime = '14:00';
        }

        try {
            $this->conn->beginTransaction();

            $storageDir = __DIR__ . '/../../storage/paanyaya';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0777, true);
            }

            $stageSlug = ($stageType === self::STAGE_1) ? 'paanyaya1' : 'paanyaya2';
            $attemptNum = ($stageType === self::STAGE_1) ? 1 : 2;
            $pdfFileName = sprintf('paanyaya_%d_%s_%s.pdf', $complaintId, $stageSlug, date('Ymd_His'));
            $pdfFullPath = $storageDir . '/' . $pdfFileName;
            $pdfRelativePath = 'storage/paanyaya/' . $pdfFileName;

            $this->pdf->generatePaanyaya($complaintId, [
                'save_path' => $pdfFullPath,
                'attempt_number' => $attemptNum,
                'stage_type' => $stageType,
                'invitee_name' => $inviteeName,
                'invitation_date' => $invitationDate,
                'invitation_time' => $invitationTime,
                'venue' => $venue,
                'has_e_signature' => $hasESignature,
            ]);

            $upd = $this->conn->prepare("
                UPDATE paanyaya_stages
                SET status = 'In Progress',
                    issued_at = CURRENT_TIMESTAMP,
                    issued_by = ?,
                    invitee_name = ?,
                    invitation_date = ?,
                    invitation_time = ?,
                    venue = ?,
                    has_e_signature = ?,
                    pdf_path = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $upd->execute([
                $userId,
                $inviteeName,
                $invitationDate,
                $invitationTime,
                $venue,
                $hasESignature,
                $pdfRelativePath,
                (int)$stage['id']
            ]);

            // Sync with independent paanyayas table
            $insP = $this->conn->prepare("
                INSERT INTO paanyayas (
                    complaint_id, invitee_name, scheduled_date, scheduled_time,
                    status, venue, pdf_path, scheduled_by
                ) VALUES (?, ?, ?, ?, 'Scheduled', ?, ?, ?)
            ");
            $insP->execute([
                $complaintId,
                $inviteeName,
                $invitationDate,
                $invitationTime,
                $venue,
                $pdfRelativePath,
                $userId
            ]);

            $updComp = $this->conn->prepare("
                UPDATE complaints
                SET status = 'Pre-Docketed (Paanyaya)',
                    paanyaya_attempt_count = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE complaint_id = ?
            ");
            $updComp->execute([$attemptNum, $complaintId]);

            if ($userId) {
                $this->audit->log($userId, "Issued {$stageType} for {$inviteeName}", 'Paanyaya', $complaintId);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'stage_type' => $stageType,
                'stage_id' => (int)$stage['id'],
                'invitee_name' => $inviteeName,
                'invitation_date' => $invitationDate,
                'invitation_time' => $invitationTime,
                'pdf_path' => $pdfRelativePath,
                'message' => "{$stageType} successfully issued for {$inviteeName}."
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Paanyaya stage issue error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error issuing ' . $stageType . ': ' . $e->getMessage()];
        }
    }

    /**
     * Officer's Return is not applicable to Paanyaya invitations.
     */
    public function recordOfficerReturn(int $complaintId, string $stageType, array $data, ?int $userId = null, ?array $proofFile = null): array
    {
        return [
            'success' => false,
            'message' => "Officer's Return is not associated with Paanyaya invitations. Summons and Officer's Returns belong to the Mediation workflow."
        ];
    }

    public function getOfficerReturnDetails(int $returnId): ?array
    {
        return null;
    }

    public function updateOfficerReturn(int $returnId, array $data, ?int $userId = null): array
    {
        return [
            'success' => false,
            'message' => "Officer's Return is not associated with Paanyaya invitations."
        ];
    }

    public function reviewStage(int $complaintId, string $stageType, array $data, ?int $userId = null): array
    {
        if (PaanyayaPreparationService::managesDialogue($this->conn, $complaintId)) {
            return ['success' => false, 'message' => 'Record dialogue minutes on the original complaint.'];
        }
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            return ['success' => false, 'message' => 'Invalid Paanyaya stage specified.'];
        }

        $decision = trim((string) ($data['decision'] ?? ''));
        $allowedDecisions = ['Approved for Paanyaya 2', 'Approved for Summons', 'Case Closed'];
        if (!in_array($decision, $allowedDecisions, true)) {
            return ['success' => false, 'message' => 'Invalid review decision. Please select an approved action.'];
        }

        $notes = trim((string) ($data['review_notes'] ?? ''));
        if ($notes === '') {
            return ['success' => false, 'message' => 'Review notes / justification are required for this decision.'];
        }

        $stage = $this->getOrCreateStage($complaintId, $stageType);
        $stageId = (int) $stage['id'];

        try {
            $this->conn->beginTransaction();

            $upd = $this->conn->prepare("
                UPDATE paanyaya_stages
                SET review_status = ?,
                    review_notes = ?,
                    reviewed_by = ?,
                    reviewed_at = CURRENT_TIMESTAMP,
                    status = 'Completed',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $upd->execute([$decision, $notes, $userId, $stageId]);

            if ($decision === 'Approved for Paanyaya 2') {
                $this->getOrCreateStage($complaintId, self::STAGE_2);
            }

            if ($userId) {
                $this->audit->log(
                    $userId,
                    "Lupon Review Decision for {$stageType}: {$decision}. Justification: {$notes}",
                    'Paanyaya',
                    $complaintId
                );
            }

            $this->conn->commit();

            return [
                'success' => true,
                'decision' => $decision,
                'message' => "Review decision ({$decision}) recorded successfully."
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Paanyaya review error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording review: ' . $e->getMessage()];
        }
    }
    /**
     * Gets dialogue minutes for a specific Paanyaya stage.
     */
    public function getStageMinutes(int $complaintId, string $stageType): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT pm.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS recorded_by_name
            FROM paanyaya_minutes pm
            LEFT JOIN users u ON u.user_id = pm.recorded_by
            WHERE pm.complaint_id = ? AND pm.stage_type = ?
            ORDER BY pm.minutes_id DESC
            LIMIT 1
        ");
        $stmt->execute([$complaintId, $stageType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['presiding_officers'] = $this->resolvePresidingOfficers($row['presiding_officer_ids'] ?? null);
            $names = array_map(fn($p) => $p['full_name'], $row['presiding_officers']);
            $row['presiding_officers_text'] = !empty($names) ? implode(' & ', $names) : '';
        }
        return $row ?: null;
    }

    /**
     * Gets all recorded dialogue minutes for a complaint.
     */
    public function getAllMinutes(int $complaintId): array
    {
        $stmt = $this->conn->prepare("
            SELECT pm.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS recorded_by_name
            FROM paanyaya_minutes pm
            LEFT JOIN users u ON u.user_id = pm.recorded_by
            WHERE pm.complaint_id = ?
            ORDER BY pm.minutes_id ASC
        ");
        $stmt->execute([$complaintId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['presiding_officers'] = $this->resolvePresidingOfficers($r['presiding_officer_ids'] ?? null);
            $names = array_map(fn($p) => $p['full_name'], $r['presiding_officers']);
            $r['presiding_officers_text'] = !empty($names) ? implode(' & ', $names) : '';
        }
        return $rows;
    }

    /**
     * Records the Temporary Minutes of the Meeting and Complainant Decision.
     */
    /**
     * Ensures actual_start_time and session_remarks columns exist in paanyaya_minutes and paanyaya_sessions.
     */
    public function ensureMinutesColumnsExist(): void
    {
        try {
            $cols = array_column($this->conn->query("DESCRIBE paanyaya_minutes")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (!in_array('actual_start_time', $cols, true)) {
                $this->conn->exec("ALTER TABLE paanyaya_minutes ADD COLUMN actual_start_time TIME NULL AFTER dialogue_date");
            }
            if (!in_array('session_remarks', $cols, true)) {
                $this->conn->exec("ALTER TABLE paanyaya_minutes ADD COLUMN session_remarks TEXT NULL AFTER agreed_terms");
            }
            $sessCols = array_column($this->conn->query("DESCRIBE paanyaya_sessions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (!in_array('session_remarks', $sessCols, true)) {
                $this->conn->exec("ALTER TABLE paanyaya_sessions ADD COLUMN session_remarks TEXT NULL AFTER outcome_notes");
            }
        } catch (Throwable $e) {
            error_log('Paanyaya ensureMinutesColumnsExist warning: ' . $e->getMessage());
        }
    }

    public function recordMinutes(int $complaintId, string $stageType, array $data, int $userId, ?array $proofFile = null): array
    {
        $this->ensureMinutesColumnsExist();

        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            return ['success' => false, 'message' => 'Invalid Paanyaya stage specified.'];
        }

        $cStmt = $this->conn->prepare('SELECT complaint_id, status FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        $managed = PaanyayaPreparationService::managesDialogue($this->conn, $complaintId);
        if (!$managed && PaanyayaPreparationService::requiresPreparation($this->conn, $complaintId)) {
            return ['success' => false, 'message' => 'Confirm delivery of the prepared invitation before recording dialogue minutes.'];
        }

        if ($managed) {
            foreach (['complainant_attended', 'respondent_attended'] as $field) {
                if (!in_array($data[$field] ?? '', ['Present', 'Absent'], true)) {
                    return ['success' => false, 'message' => 'Record attendance for both parties.'];
                }
            }
            $date = is_string($data['dialogue_date'] ?? null) ? $data['dialogue_date'] : '';
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $date);
            if (!$parsed || $parsed->format('Y-m-d\TH:i') !== $date || $parsed > new DateTimeImmutable()) {
                return ['success' => false, 'message' => 'Enter the actual dialogue date and time, up to the current time.'];
            }
            $outcomeInput = $data['outcome'] ?? '';
            if (!in_array($outcomeInput, ['Settled', 'Request_Paanyaya_2', 'Escalate_Mediation', 'Dismissed'], true)
                || ($outcomeInput === 'Request_Paanyaya_2' && $stageType !== self::STAGE_1)
                || (($outcomeInput === 'Dismissed') !== ($data['complainant_attended'] === 'Absent'))) {
                return ['success' => false, 'message' => 'Choose an outcome that matches the recorded attendance and dialogue.'];
            }
            foreach (['discussion_summary', 'agreed_terms'] as $field) {
                if (!is_string($data[$field] ?? '') || mb_strlen($data[$field] ?? '') > 10000) {
                    return ['success' => false, 'message' => 'Minutes and agreed terms can contain up to 10,000 characters each.'];
                }
            }
            if (trim($data['discussion_summary'] ?? '') === '') {
                return ['success' => false, 'message' => 'Enter the minutes or attendance notes.'];
            }
            $data = [
                'dialogue_date' => $date,
                'actual_start_time' => $parsed->format('H:i'),
                'complainant_attended' => $data['complainant_attended'],
                'respondent_attended' => $data['respondent_attended'],
                'outcome' => $outcomeInput,
                'complainant_choice' => $outcomeInput,
                'discussion_summary' => $data['discussion_summary'],
                'agreed_terms' => $data['agreed_terms'] ?? '',
            ];
            $proofFile = null;
        }

        $dialogueDate = !empty($data['dialogue_date']) ? date('Y-m-d H:i:s', strtotime($data['dialogue_date'])) : date('Y-m-d H:i:s');
        $compAttended = in_array(trim((string)($data['complainant_attended'] ?? '')), ['Present', 'Late', 'Absent'], true)
            ? trim((string)$data['complainant_attended']) : 'Present';
        $respAttended = in_array(trim((string)($data['respondent_attended'] ?? '')), ['Present', 'Late', 'Absent'], true)
            ? trim((string)$data['respondent_attended']) : 'Present';

        // Check if individual party attendance is supplied
        $partyAttendance = $data['party_attendance'] ?? [];
        if (is_array($partyAttendance) && !empty($partyAttendance)) {
            require_once __DIR__ . '/ComplaintParty.php';
            $complaintParties = (new ComplaintParty())->getByComplaint($complaintId);
            $compHasPresent = false;
            $compCount = 0;
            $respHasPresent = false;
            $respCount = 0;

            foreach ($complaintParties as $cp) {
                $pid = (string)$cp['party_id'];
                if (isset($partyAttendance[$pid])) {
                    $att = $partyAttendance[$pid] === 'Absent' ? 'Absent' : 'Present';
                    if ($cp['party_type'] === 'Complainant') {
                        $compCount++;
                        if ($att === 'Present') $compHasPresent = true;
                    } elseif ($cp['party_type'] === 'Respondent') {
                        $respCount++;
                        if ($att === 'Present') $respHasPresent = true;
                    }
                }
            }

            if ($compCount > 0) {
                $compAttended = $compHasPresent ? 'Present' : 'Absent';
            }
            if ($respCount > 0) {
                $respAttended = $respHasPresent ? 'Present' : 'Absent';
            }
        }

        $discussionSummary = trim((string)($data['discussion_summary'] ?? ($data['summary'] ?? '')));
        $sessionRemarks = trim((string)($data['session_remarks'] ?? ($data['delay_remarks'] ?? ($data['remarks'] ?? ''))));
        $actualStartTime = !empty($data['actual_start_time']) ? trim((string)$data['actual_start_time']) : null;
        if ($actualStartTime) {
            $parsedTime = strtotime($actualStartTime);
            if ($parsedTime !== false) {
                $actualStartTime = date('H:i:s', $parsedTime);
            }
        }
        $agreedTerms = trim((string)($data['agreed_terms'] ?? ($data['terms'] ?? '')));

        if ($discussionSummary === '') {
            if ($compAttended === 'Absent' || $respAttended === 'Absent') {
                $discussionSummary = 'Walang naganap na pag-uusap dahil hindi sumipot ang partido.';
            } else {
                $discussionSummary = 'Informal dialogue conducted. Summary of discussion recorded.';
            }
        }

        // Determine outcome with statutory attendance enforcement
        $rawOutcome = trim((string)($data['outcome'] ?? ''));
        if ($compAttended === 'Absent') {
            // Under RA 7160 Sec. 410(b), complainant absence requires dismissal
            $outcome = 'Dismissed';
        } elseif ($rawOutcome === 'Dismissed' || stripos($rawOutcome, 'dismiss') !== false) {
            $outcome = 'Dismissed';
        } elseif (stripos($rawOutcome, 'settle') !== false || stripos($rawOutcome, 'nagkasundo') !== false) {
            if ($respAttended === 'Absent') {
                return ['success' => false, 'message' => 'Hindi maaaring magkasundo (Settled) kung Absent ang inanyayahan (Respondent). Piliin ang Walang Kasunduan.'];
            }
            $outcome = 'Settled';
        } elseif ($respAttended === 'Absent') {
            $outcome = 'Party_Absent';
        } else {
            $outcome = 'No_Agreement';
        }

        // Validate agreed terms for settled outcome
        if ($outcome === 'Settled' && $agreedTerms === '') {
            return ['success' => false, 'message' => 'Paki-lagay ang mga napagkasunduang kondisyon (Proposals / Agreed Terms) bago i-save ang kasunduan.'];
        }

        // Complainant choice & agreed terms enforcement
        if ($outcome === 'Settled' || $outcome === 'Dismissed') {
            $complainantChoice = ($outcome === 'Dismissed') ? 'Dismissed' : 'None';
            $agreedTerms = ($outcome === 'Dismissed') ? '' : $agreedTerms;
        } else {
            $agreedTerms = ''; // Agreed terms only apply if amicably settled
            $rawChoice = trim((string)($data['complainant_choice'] ?? ''));
            if ($rawChoice === 'Escalate_Mediation' || stripos($rawChoice, 'escalate') !== false || stripos($rawChoice, 'akyat') !== false) {
                $complainantChoice = 'Escalate_Mediation';
            } else {
                $complainantChoice = ($stageType === self::STAGE_2) ? 'Escalate_Mediation' : 'Request_Paanyaya_2';
            }
        }

        // Validate booking schedule for 2nd Paanyaya
        if ($stageType === self::STAGE_1 && $complainantChoice === 'Request_Paanyaya_2') {
            $p2DateCheck = trim((string)($data['p2_scheduled_date'] ?? ''));
            if ($p2DateCheck !== '') {
                if (strtotime($p2DateCheck) <= strtotime('today')) {
                    return ['success' => false, 'message' => 'Ang petsa ng Ikalawang Paanyaya ay hindi maaaring lumipas na o ngayong araw. Pumili ng darating na araw.'];
                }
                $dayOfWeek = (int)date('N', strtotime($p2DateCheck));
                if ($dayOfWeek === 6 || $dayOfWeek === 7) {
                    return ['success' => false, 'message' => 'Ang iskedyul ng Ikalawang Paanyaya ay dapat pumatak sa regular na araw ng trabaho (Lunes hanggang Biyernes).'];
                }
            }
        }

        // File upload handling for scanned minutes
        $scannedMinutesPath = null;
        if (!empty($proofFile) && !empty($proofFile['tmp_name']) && is_uploaded_file($proofFile['tmp_name'])) {
            $ext = strtolower(pathinfo($proofFile['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
            if (!in_array($ext, $allowedExts, true)) {
                return ['success' => false, 'message' => 'Invalid file format. Allowed formats: JPG, PNG, WEBP, PDF.'];
            }
            if ($proofFile['size'] > 10 * 1024 * 1024) {
                return ['success' => false, 'message' => 'File size exceeds 10MB limit.'];
            }

            $storageDir = __DIR__ . '/../../storage/paanyaya/minutes';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0777, true);
            }
            $stageSlug = ($stageType === self::STAGE_1) ? 'p1' : 'p2';
            $fileName = sprintf('minutes_%d_%s_%s.%s', $complaintId, $stageSlug, date('Ymd_His'), $ext);
            $targetPath = $storageDir . '/' . $fileName;

            if (move_uploaded_file($proofFile['tmp_name'], $targetPath)) {
                $scannedMinutesPath = 'storage/paanyaya/minutes/' . $fileName;
            }
        } elseif (!empty($data['scanned_minutes_path'])) {
            $scannedMinutesPath = trim((string)$data['scanned_minutes_path']);
        }

        try {
            $this->conn->beginTransaction();

            if ($managed) {
                $number = $stageType === self::STAGE_2 ? 2 : 1;
                $lock = $this->conn->prepare('SELECT status FROM complaints WHERE complaint_id = ? FOR UPDATE');
                $lock->execute([$complaintId]);
                $status = $lock->fetchColumn();
                $delivery = $this->conn->prepare("SELECT MAX(recorded_at) FROM paanyaya_service_attempts
                    WHERE complaint_id = ? AND invitation_number = ? AND outcome = 'Delivered'");
                $delivery->execute([$complaintId, $number]);
                $deliveredAt = $delivery->fetchColumn();
                if ($status !== 'Pre-Docketed (Paanyaya)' || !$deliveredAt) {
                    throw new DomainException('Confirm delivery of this invitation before recording dialogue minutes.');
                }
                if (strtotime($dialogueDate) < strtotime(date('Y-m-d H:i:00', strtotime($deliveredAt)))) {
                    throw new DomainException('The dialogue cannot be recorded as occurring before delivery.');
                }
                $existing = $this->getStageMinutes($complaintId, $stageType);
                if ($existing) {
                    throw new DomainException('Minutes have already been recorded for this dialogue.');
                }
            }

            // Extract and sanitize presiding_officer_ids
            $rawPresiders = $data['presiding_officer_ids'] ?? [];
            if (is_string($rawPresiders)) {
                $decoded = json_decode($rawPresiders, true);
                $rawPresiders = is_array($decoded) ? $decoded : [];
            }
            $presiderIds = array_values(array_filter(array_map('intval', (array)$rawPresiders), fn($id) => $id > 0));
            $presidersJson = !empty($presiderIds) ? json_encode($presiderIds) : json_encode([]);

            $insStmt = $this->conn->prepare("
                INSERT INTO paanyaya_minutes (
                    complaint_id, stage_type, dialogue_date, actual_start_time,
                    complainant_attended, respondent_attended,
                    discussion_summary, agreed_terms, session_remarks,
                    outcome, complainant_choice,
                    scanned_minutes_path, recorded_by, presiding_officer_ids, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, CURRENT_TIMESTAMP
                )
            ");
            $insStmt->execute([
                $complaintId,
                $stageType,
                $dialogueDate,
                $actualStartTime,
                $compAttended,
                $respAttended,
                $discussionSummary,
                $agreedTerms ?: null,
                $sessionRemarks ?: null,
                $outcome,
                $complainantChoice,
                $scannedMinutesPath,
                $userId,
                $presidersJson
            ]);
            $minutesId = (int) $this->conn->lastInsertId();

            if ($stageType === self::STAGE_1) {
                $updCompCols = $this->conn->prepare("
                    UPDATE complaints
                    SET paanyaya_1_outcome = ?,
                        paanyaya_complainant_choice = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updCompCols->execute([$outcome, $complainantChoice, $complaintId]);
            }

            // Also keep session updated if any exists
            $sess = $this->getByComplaintId($complaintId);
            if ($sess && !empty($sess['session_id'])) {
                $sessOutcome = ($outcome === 'Settled') ? 'Settled' : (($outcome === 'Dismissed') ? 'Dismissed' : (($complainantChoice === 'Request_Paanyaya_2') ? 'Continued_Dialogue' : 'Impasse_Escalate'));
                $updSess = $this->conn->prepare("
                    UPDATE paanyaya_sessions
                    SET session_outcome = ?,
                        outcome_notes = ?,
                        session_remarks = COALESCE(?, session_remarks),
                        actual_start_time = COALESCE(?, actual_start_time),
                        complainant_attended = ?,
                        respondent_attended = ?,
                        presiding_officer_ids = ?,
                        queue_status = IF(? = 1, 'Concluded', queue_status),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $fullActualStart = $actualStartTime ? (date('Y-m-d', strtotime($dialogueDate)) . ' ' . $actualStartTime) : null;
                $updSess->execute([$sessOutcome, $discussionSummary, $sessionRemarks ?: null, $fullActualStart, $compAttended, $respAttended, $presidersJson, (int) $managed, (int)$sess['session_id']]);
            }
            $attemptNumber = ($stageType === self::STAGE_2) ? 2 : 1;
            $this->conn->prepare("UPDATE paanyaya_sessions SET presiding_officer_ids = ? WHERE complaint_id = ? AND attempt_number = ?")
                       ->execute([$presidersJson, $complaintId, $attemptNumber]);
            if ($managed) {
                $registerStatus = match (true) {
                    $outcome === 'Settled' => 'Resolved',
                    $outcome === 'Dismissed' => 'Cancelled',
                    default => 'Completed',
                };
                $this->conn->prepare('UPDATE paanyayas SET status = ?, attendance_recorded_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ? AND attempt_number = ?')->execute([$registerStatus, $complaintId, $attemptNumber]);
            }

            // Case 0: Dismissed
            if ($outcome === 'Dismissed') {
                $updComp = $this->conn->prepare("
                    UPDATE complaints
                    SET status = 'Dismissed',
                        resolved_without_docketing = 1,
                        ltia_category = 'Dismissed at Paanyaya',
                        paanyaya_1_outcome = IF(? = 'Paanyaya 1', 'Dismissed', COALESCE(paanyaya_1_outcome, 'Dismissed')),
                        paanyaya_complainant_choice = IF(? = 'Paanyaya 1', 'Dismissed', COALESCE(paanyaya_complainant_choice, 'Dismissed')),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComp->execute([$stageType, $stageType, $complaintId]);

                $updStages = $this->conn->prepare("
                    UPDATE paanyaya_stages
                    SET status = 'Completed',
                        review_status = 'Case Closed',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updStages->execute([$complaintId]);

                if ($stageType === self::STAGE_1) {
                    $this->conn->prepare("
                        UPDATE paanyaya_stages
                        SET status = 'Not Started',
                            review_status = 'Case Closed',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE complaint_id = ? AND stage_type = ? AND issued_at IS NULL
                    ")->execute([$complaintId, self::STAGE_2]);
                }

                $this->audit->log($userId, "Recorded Minutes for {$stageType}: Dismissed (Complainant Absent)", 'Paanyaya', $complaintId);

                $this->conn->commit();

                return [
                    'success' => true,
                    'minutes_id' => $minutesId,
                    'action' => 'dismissed',
                    'outcome' => 'Dismissed',
                    'complainant_choice' => 'Dismissed',
                    'message' => 'Ang reklamo ay opisyal nang na-dismiss dahil sa hindi pagdalo ng nagreklamo (Complainant Absent).'
                ];
            }

            // Case 1: Settled
            if ($outcome === 'Settled') {
                $updComp = $this->conn->prepare("
                    UPDATE complaints
                    SET status = 'Settled',
                        resolved_without_docketing = 1,
                        ltia_category = 'Pre-Docket Resolution',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComp->execute([$complaintId]);

                $updStages = $this->conn->prepare("
                    UPDATE paanyaya_stages
                    SET status = 'Completed',
                        review_status = 'Case Closed',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updStages->execute([$complaintId]);

                $this->audit->log($userId, "Recorded Minutes for {$stageType}: Amicably Settled (Pre-Docket Resolution)", 'Paanyaya', $complaintId);

                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notifService = new NotificationService();
                    $cNum = $complaint['complaint_number'] ?? "CMP-#{$complaintId}";
                    $notifService->notifyPaanyayaOutcome($complaintId, $cNum, 'Settled', $userId);
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch Paanyaya settled notification: ' . $ne->getMessage());
                }

                $this->conn->commit();

                return [
                    'success' => true,
                    'minutes_id' => $minutesId,
                    'action' => 'settled',
                    'outcome' => 'Settled',
                    'complainant_choice' => 'None',
                    'message' => 'Dispute amicably settled during informal Paanyaya dialogue! Closed without formal court docketing (LTIA performance win).'
                ];
            }

            // Case 2: No Agreement - Choice A: Request 2nd Paanyaya
            if ($stageType === self::STAGE_1 && $complainantChoice === 'Request_Paanyaya_2') {
                $stage1 = $this->getOrCreateStage($complaintId, self::STAGE_1);
                $this->conn->prepare("UPDATE paanyaya_stages SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$stage1['id']]);

                $this->audit->log($userId, "Recorded Minutes for {$stageType}: Complainant requested 2nd Paanyaya.", 'Paanyaya', $complaintId);

                $this->conn->commit();

                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notifService = new NotificationService();
                    $cNum = $complaint['complaint_number'] ?? "CMP-#{$complaintId}";
                    $notifService->notifyPaanyayaOutcome($complaintId, $cNum, $outcome, $userId);
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch Paanyaya notification: ' . $ne->getMessage());
                }

                $p2Date = trim((string)($data['p2_scheduled_date'] ?? ''));
                $p2Time = trim((string)($data['p2_scheduled_time'] ?? ''));
                $p2Venue = trim((string)($data['p2_venue'] ?? 'Lupon Courtroom'));
                if ($p2Venue === '') {
                    $p2Venue = 'Lupon Courtroom';
                }
                $scheduledP2 = false;
                $msg = $managed ? 'Minutes recorded. Prepare the second invitation on this complaint.'
                    : 'Dialogue minutes recorded: No agreement reached. Complainant requested 2nd Paanyaya. Paanyaya 2 card is now unlocked!';

                if ($p2Date !== '' && $p2Time !== '') {
                    $issueData = [
                        'invitation_date' => $p2Date,
                        'invitation_time' => $p2Time,
                        'venue' => $p2Venue,
                            'has_e_signature' => 1
                    ];
                    $issueRes = $this->issueStage($complaintId, self::STAGE_2, $issueData, $userId);

                    if (!empty($issueRes['success'])) {
                        $scheduledP2 = true;
                        $pdfPath = $issueRes['pdf_path'] ?? null;

                        // Insert or update row in paanyaya_sessions with attempt_number = 2 and queue_status = 'Waiting'
                        $chkSess = $this->conn->prepare("SELECT session_id FROM paanyaya_sessions WHERE complaint_id = ? AND attempt_number = 2 LIMIT 1");
                        $chkSess->execute([$complaintId]);
                        $existingP2SessionId = $chkSess->fetchColumn();

                        if ($existingP2SessionId) {
                            $updSess = $this->conn->prepare("
                                UPDATE paanyaya_sessions
                                SET scheduled_date = ?,
                                    scheduled_time = ?,
                                    venue = ?,
                                    pdf_path = COALESCE(?, pdf_path),
                                    scheduled_by = ?,
                                    queue_status = 'Waiting',
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE session_id = ?
                            ");
                            $updSess->execute([
                                $p2Date,
                                $p2Time,
                                $p2Venue,
                                $pdfPath,
                                $userId,
                                (int)$existingP2SessionId
                            ]);
                        } else {
                            $insSession = $this->conn->prepare("
                                INSERT INTO paanyaya_sessions (
                                    complaint_id, attempt_number, scheduled_date, scheduled_time,
                                    venue, has_e_signature, salaysay, scheduled_by, pdf_path, queue_status
                                ) VALUES (?, 2, ?, ?, ?, 1, ?, ?, ?, 'Waiting')
                            ");
                            $insSession->execute([
                                $complaintId,
                                $p2Date,
                                $p2Time,
                                $p2Venue,
                                $complaint['narrative'] ?? 'Ikalawang Paanyaya (Second Preliminary Dialogue)',
                                $userId,
                                $pdfPath
                            ]);
                        }

                        $formattedDate = date('M d, Y', strtotime($p2Date));
                        $formattedTime = date('g:i A', strtotime($p2Time));
                        $msg = "1st Dialogue Minutes recorded and 2nd Paanyaya scheduled successfully for {$formattedDate} at {$formattedTime}!";
                    } else {
                        error_log("Failed to auto-issue Stage 2 during Choice A minutes: " . ($issueRes['message'] ?? 'Unknown error'));
                    }
                }

                return [
                    'success' => true,
                    'minutes_id' => $minutesId,
                    'action' => 'request_paanyaya_2',
                    'outcome' => $outcome,
                    'complainant_choice' => 'Request_Paanyaya_2',
                    'scheduled_p2' => $scheduledP2,
                    'p2_date' => $p2Date,
                    'p2_time' => $p2Time,
                    'message' => $msg
                ];
            }

            // Case 3: No Agreement - Choice B: Escalate to Formal Mediation (or Stage 2 failure)
            $stage1 = $this->getOrCreateStage($complaintId, $stageType);
            $this->conn->prepare("UPDATE paanyaya_stages SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$stage1['id']]);

            $this->audit->log($userId, "Recorded Minutes for {$stageType}: Escalating to Formal Mediation per complainant choice.", 'Paanyaya', $complaintId);

            $this->conn->commit();

            if ($managed) {
                return [
                    'success' => true, 'minutes_id' => $minutesId, 'action' => 'mediation_pending',
                    'message' => 'Minutes recorded. Continue to formal mediation from the complaint.',
                ];
            }

            // Trigger formal docketing
            $escResult = $this->escalateToFormalDocketing($complaintId, $userId, $discussionSummary, $data);
            if (empty($escResult['success'])) {
                return $escResult;
            }
            $escResult['minutes_id'] = $minutesId;
            $escResult['action'] = 'escalate_mediation';
            $escResult['stage_type'] = $stageType;
            $escResult['outcome'] = $outcome;
            $escResult['complainant_choice'] = 'Escalate_Mediation';
            $escResult['message'] = 'Dialogue minutes recorded and dispute escalated to Mediation.';

            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $notifService = new NotificationService();
                $cNum = $complaint['complaint_number'] ?? "CMP-#{$complaintId}";
                $notifService->notifyPaanyayaOutcome($complaintId, $cNum, 'Escalate_Mediation', $userId);
            } catch (Throwable $ne) {
                error_log('Failed to dispatch Paanyaya escalated notification: ' . $ne->getMessage());
            }

            return $escResult;

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            if ($e instanceof DomainException) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
            error_log("Paanyaya recordMinutes error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording dialogue minutes: ' . $e->getMessage()];
        }
    }




    /**
     * Records attendance, dialogue statement (Salaysay), notes, and executes resolution or escalation.
     */
    public function recordAttendanceAndAction(int $paanyayaId, ?int $userId, array $data): array
    {
        $paanyaya = $this->getPaanyayaById($paanyayaId);
        if (!$paanyaya) {
            return ['success' => false, 'message' => 'Hindi natagpuan ang tala ng Paanyaya.'];
        }
        if (!empty($paanyaya['complaint_id']) && PaanyayaPreparationService::managesDialogue($this->conn, (int) $paanyaya['complaint_id'])) {
            return ['success' => false, 'message' => 'Record dialogue minutes on the original complaint.'];
        }

        $compAttended = in_array($data['complainant_attended'] ?? '', ['Present', 'Absent'], true)
            ? $data['complainant_attended'] : 'Present';
        $invAttended = in_array($data['invitee_attended'] ?? '', ['Present', 'Absent'], true)
            ? $data['invitee_attended'] : 'Present';
        $salaysay = trim((string)($data['salaysay'] ?? ($paanyaya['salaysay'] ?? '')));
        $dialogueNotes = trim((string)($data['dialogue_notes'] ?? ($paanyaya['dialogue_notes'] ?? '')));
        $action = trim((string)($data['action'] ?? 'save_attendance'));

        $currentStatus = (string)($paanyaya['status'] ?? '');
        if (in_array($currentStatus, ['Resolved', 'Settled', 'Completed'], true) && in_array($action, ['schedule_second_paanyaya', 'escalate'], true)) {
            return [
                'success' => false,
                'message' => 'Naka-lock ang Paanyaya dahil naresolba na ito sa Kasunduan (Settled).'
            ];
        }

        if ($currentStatus === 'Escalated' && in_array($action, ['schedule_second_paanyaya', 'resolve', 'escalate'], true)) {
            return [
                'success' => false,
                'message' => 'Naka-lock ang Paanyaya dahil opisyal na itong nai-akyat sa Mediation.'
            ];
        }

        // Handle Schedule 2nd Paanyaya
        if ($action === 'schedule_second_paanyaya') {
            return $this->scheduleSecondPaanyaya($paanyayaId, $userId, $data);
        }

        // Handle Escalation to Formal Mediation
        if ($action === 'escalate') {
            $complaintId = (int)($paanyaya['complaint_id'] ?? 0);
            if ($complaintId <= 0) {
                // A Stage 0-only record does not carry the formal complaint
                // classification and incident fields. Save its attendance, then
                // require the clerk to complete the formal intake before docketing.
                $upd = $this->conn->prepare("
                    UPDATE paanyayas
                    SET complainant_attended = ?,
                        invitee_attended = ?,
                        salaysay = ?,
                        dialogue_notes = ?,
                        attendance_recorded_at = NOW(),
                        updated_at = NOW()
                    WHERE paanyaya_id = ?
                ");
                $upd->execute([$compAttended, $invAttended, $salaysay, $dialogueNotes, $paanyayaId]);

                return [
                    'success' => true,
                    'requires_formal_complaint_details' => true,
                    'paanyaya_id' => $paanyayaId,
                    'formal_complaint_url' => '../complaints/complaint-create.php?escalate_paanyaya_id=' . $paanyayaId,
                    'message' => 'Paanyaya attendance was saved. Complete the required formal complaint details before this dispute can be docketed.'
                ];
            } else {
                // Complaint already exists: update attendance on Paanyaya and execute escalation docketing
                $upd = $this->conn->prepare("
                    UPDATE paanyayas
                    SET complainant_attended = ?,
                        invitee_attended = ?,
                        salaysay = ?,
                        dialogue_notes = ?,
                        attendance_recorded_at = NOW(),
                        status = 'Escalated',
                        updated_at = NOW()
                    WHERE paanyaya_id = ?
                ");
                $upd->execute([$compAttended, $invAttended, $salaysay, $dialogueNotes, $paanyayaId]);

                // Also update complaint narrative if complaint narrative was empty
                if ($salaysay !== '') {
                    $this->conn->prepare("
                        UPDATE complaints
                        SET narrative = COALESCE(NULLIF(TRIM(narrative), ''), ?)
                        WHERE complaint_id = ?
                    ")->execute([$salaysay, $complaintId]);
                }

                $notes = "Inilipat mula sa Stage 0 (Paanyaya #{$paanyayaId}). Pagdalo: Nagpaanyaya={$compAttended}, Inanyayahan={$invAttended}. " . $dialogueNotes;
                $this->conn->prepare("
                    UPDATE paanyaya_sessions
                    SET session_outcome = 'Impasse_Escalate',
                        outcome_notes = COALESCE(NULLIF(outcome_notes, ''), ?),
                        escalated_at = COALESCE(escalated_at, NOW()),
                        queue_status = 'Concluded',
                        updated_at = NOW()
                    WHERE complaint_id = ?
                ")->execute([$notes, $complaintId]);
                $this->conn->prepare("
                    UPDATE complaints
                    SET paanyaya_1_outcome = COALESCE(NULLIF(paanyaya_1_outcome, ''), 'Impasse_Escalate'),
                        updated_at = NOW()
                    WHERE complaint_id = ?
                ")->execute([$complaintId]);
                $docketResult = $this->escalateToFormalDocketing($complaintId, $userId, $notes);

                return [
                    'success' => true,
                    'complaint_id' => $complaintId,
                    'case_id' => $docketResult['case_id'] ?? null,
                    'case_number' => $docketResult['case_number'] ?? null,
                    'message' => 'Matagumpay na nai-akyat ang Paanyaya sa Pormal na Mediation (Stage 1). Nailipat ang Salaysay at rekord ng Pagdalo!'
                ];
            }
        }

        // Handle Resolution (Kasunduan)
        if ($action === 'resolve') {
            $upd = $this->conn->prepare("
                UPDATE paanyayas
                SET complainant_attended = ?,
                    invitee_attended = ?,
                    salaysay = ?,
                    dialogue_notes = ?,
                    status = 'Resolved',
                    attendance_recorded_at = NOW(),
                    updated_at = NOW()
                WHERE paanyaya_id = ?
            ");
            $upd->execute([$compAttended, $invAttended, $salaysay, $dialogueNotes, $paanyayaId]);

            $complaintId = (int)($paanyaya['complaint_id'] ?? 0);
            if ($complaintId > 0) {
                $this->conn->prepare("
                    UPDATE complaints
                    SET status = 'Resolved at Paanyaya / Closed',
                        resolved_without_docketing = 1,
                        updated_at = NOW()
                    WHERE complaint_id = ?
                ")->execute([$complaintId]);
            }

            return [
                'success' => true,
                'message' => 'Matagumpay na naitala ang kasunduan at naging "Resolved" ang Paanyaya.'
            ];
        }

        // Default: Save Attendance Only
        $upd = $this->conn->prepare("
            UPDATE paanyayas
            SET complainant_attended = ?,
                invitee_attended = ?,
                salaysay = ?,
                dialogue_notes = ?,
                attendance_recorded_at = NOW(),
                updated_at = NOW()
            WHERE paanyaya_id = ?
        ");
        $upd->execute([$compAttended, $invAttended, $salaysay, $dialogueNotes, $paanyayaId]);

        return [
            'success' => true,
            'message' => 'Matagumpay na naitala ang Pagdalo (Attendance) at mga Tala ng Paanyaya.'
        ];
    }

    /**
     * Seamlessly escalates a pre-docketed Paanyaya into a formal complaint and Mediation case.
     * Transfers Complainant, Invitee/Respondent, Salaysay (narrative), and Attendance records.
     */
    public function escalatePreDocketedPaanyaya(int $paanyayaId, ?int $userId = null, array $attendanceData = []): array
    {
        try {
            $this->conn->beginTransaction();

            $pStmt = $this->conn->prepare('SELECT * FROM paanyayas WHERE paanyaya_id = ? LIMIT 1 FOR UPDATE');
            $pStmt->execute([$paanyayaId]);
            $paanyaya = $pStmt->fetch(PDO::FETCH_ASSOC);

            if (!$paanyaya) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Hindi natagpuan ang tala ng Paanyaya.'];
            }
            if (!empty($paanyaya['complaint_id']) && PaanyayaPreparationService::managesDialogue($this->conn, (int) $paanyaya['complaint_id'])) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Continue to formal mediation from the original complaint.'];
            }

            // Check if already escalated
            if (!empty($paanyaya['complaint_id']) && $paanyaya['status'] === 'Escalated') {
                $this->conn->rollBack();
                return [
                    'success' => true,
                    'already_escalated' => true,
                    'complaint_id' => (int)$paanyaya['complaint_id'],
                    'message' => 'Ang Paanyaya na ito ay nai-akyat na sa Pormal na Mediation.'
                ];
            }

            $compAttended = $attendanceData['complainant_attended'] ?? ($paanyaya['complainant_attended'] ?? 'Present');
            $invAttended = $attendanceData['invitee_attended'] ?? ($paanyaya['invitee_attended'] ?? 'Absent');
            $salaysay = trim((string)($attendanceData['salaysay'] ?? ($paanyaya['salaysay'] ?? '')));
            $dialogueNotes = trim((string)($attendanceData['dialogue_notes'] ?? ($paanyaya['dialogue_notes'] ?? '')));

            $complainantName = trim((string)($paanyaya['complainant_name'] ?: 'Nagpaanyaya'));
            $inviteeName = trim((string)($paanyaya['invitee_name'] ?: 'Inanyayahan'));

            // 1. Helper to find or create resident
            $findOrCreateResident = function(string $fullName) use ($userId): int {
                $clean = trim(preg_replace('/\s+/', ' ', $fullName));
                if ($clean === '' || $clean === 'Nagpaanyaya' || $clean === 'Inanyayahan') return 1;

                $stmt = $this->conn->prepare("
                    SELECT resident_id FROM residents
                    WHERE TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) = ?
                       OR TRIM(CONCAT_WS(' ', first_name, last_name)) = ?
                    LIMIT 1
                ");
                $stmt->execute([$clean, $clean]);
                $rId = (int)$stmt->fetchColumn();
                if ($rId > 0) return $rId;

                $parts = explode(' ', $clean);
                $first = $parts[0] ?? 'Residente';
                $last = (count($parts) > 1) ? end($parts) : 'Barangay';
                $middle = (count($parts) > 2) ? implode(' ', array_slice($parts, 1, -1)) : null;

                $ins = $this->conn->prepare("
                    INSERT INTO residents (first_name, middle_name, last_name, birth_date, gender, civil_status, address, barangay, city_municipality)
                    VALUES (?, ?, ?, '1990-01-01', 'Prefer not to say', 'Single', 'Barangay Tumana, Marikina City', 'Tumana', 'Marikina City')
                ");
                $ins->execute([$first, $middle, $last]);
                return (int)$this->conn->lastInsertId();
            };

            $compResidentId = !empty($paanyaya['complainant_resident_id']) ? (int)$paanyaya['complainant_resident_id'] : $findOrCreateResident($complainantName);
            $respResidentId = !empty($paanyaya['invitee_resident_id']) ? (int)$paanyaya['invitee_resident_id'] : $findOrCreateResident($inviteeName);
            try {
                if ($compResidentId > 1 && (!empty($paanyaya['complainant_address']) || !empty($paanyaya['complainant_purok']) || !empty($paanyaya['complainant_contact_no']))) {
                    $this->conn->prepare("
                        UPDATE residents
                        SET address = COALESCE(NULLIF(?, ''), address),
                            purok = COALESCE(NULLIF(?, ''), purok),
                            contact_no = COALESCE(NULLIF(?, ''), contact_no),
                            email = COALESCE(NULLIF(?, ''), email)
                        WHERE resident_id = ?
                    ")->execute([
                        trim((string)($paanyaya['complainant_address'] ?? '')),
                        trim((string)($paanyaya['complainant_purok'] ?? '')),
                        trim((string)($paanyaya['complainant_contact_no'] ?? '')),
                        trim((string)($paanyaya['complainant_email'] ?? '')),
                        $compResidentId
                    ]);
                }
                if ($respResidentId > 1 && (!empty($paanyaya['invitee_address']) || !empty($paanyaya['invitee_purok']) || !empty($paanyaya['invitee_contact_no']))) {
                    $this->conn->prepare("
                        UPDATE residents
                        SET address = COALESCE(NULLIF(?, ''), address),
                            purok = COALESCE(NULLIF(?, ''), purok),
                            contact_no = COALESCE(NULLIF(?, ''), contact_no),
                            email = COALESCE(NULLIF(?, ''), email)
                        WHERE resident_id = ?
                    ")->execute([
                        trim((string)($paanyaya['invitee_address'] ?? '')),
                        trim((string)($paanyaya['invitee_purok'] ?? '')),
                        trim((string)($paanyaya['invitee_contact_no'] ?? '')),
                        trim((string)($paanyaya['invitee_email'] ?? '')),
                        $respResidentId
                    ]);
                }
            } catch (Throwable $ruErr) {
                error_log('Resident sync on escalation warning: ' . $ruErr->getMessage());
            }
            // respResidentId resolved above

            // 2. Format detailed narrative with attendance history
            $complaintNarrative = $salaysay !== '' ? $salaysay : "Inilipat mula sa Stage 0 (Paanyaya) para sa Pormal na Mediation.";
            $compDetails = sprintf(
                "Nagmula sa Stage 0 (Paanyaya #%d).\nTala ng Pagdalo (Attendance):\n- Nagpaanyaya (%s): %s\n- Inanyayahan (%s): %s\nPetsa ng Paanyaya: %s %s\nTala ng Pag-uusap: %s",
                $paanyayaId,
                $complainantName,
                $compAttended,
                $inviteeName,
                $invAttended,
                $paanyaya['scheduled_date'],
                $paanyaya['scheduled_time'],
                $dialogueNotes !== '' ? $dialogueNotes : 'Walang naitalang kasunduan sa informal dialogue.'
            );

            // 3. Create formal complaint
            $year = date('Y');
            $seqStmt = $this->conn->query("SELECT MAX(complaint_id) FROM complaints");
            $nextSeq = ((int)$seqStmt->fetchColumn()) + 1;
            $complaintNo = sprintf("%s-%04d", $year, $nextSeq);

            $insComplaint = $this->conn->prepare("
                INSERT INTO complaints (
                    complaint_number, complaint_title, narrative, additional_details,
                    incident_date, incident_location, incident_city, incident_barangay,
                    status, case_type, category_id,
                    encoded_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'Barangay Tumana, Marikina City', 'Marikina City', 'Tumana', 'Accepted', 'Civil', 1, ?, NOW(), NOW())
            ");
            $title = "Usapin: {$complainantName} laban kay {$inviteeName}";
            $insComplaint->execute([
                $complaintNo,
                $title,
                $complaintNarrative,
                $compDetails,
                $paanyaya['scheduled_date'],
                $userId
            ]);
            $complaintId = (int)$this->conn->lastInsertId();

            // 4. Link Complaint Parties
            $insParty = $this->conn->prepare("
                INSERT INTO complaint_parties (complaint_id, resident_id, party_type)
                VALUES (?, ?, ?)
            ");
            $insParty->execute([$complaintId, $compResidentId, 'Complainant']);
            $insParty->execute([$complaintId, $respResidentId, 'Respondent']);

            if (!empty($paanyaya['location_latitude']) && !empty($paanyaya['location_longitude'])) {
                try {
                    $locAddr = trim(implode(', ', array_filter([$paanyaya['invitee_address'] ?? '', $paanyaya['invitee_purok'] ?? '', 'Barangay Tumana, Marikina City'])));
                    $this->conn->prepare("
                        INSERT INTO complaint_locations (complaint_id, latitude, longitude, address)
                        VALUES (?, ?, ?, ?)
                    ")->execute([
                        $complaintId,
                        (float) $paanyaya['location_latitude'],
                        (float) $paanyaya['location_longitude'],
                        $locAddr !== '' ? $locAddr : 'Barangay Tumana, Marikina City'
                    ]);
                } catch (Throwable $locErr) {
                    error_log('Could not copy Paanyaya pinned location on escalation: ' . $locErr->getMessage());
                }
            }

            // 5. Escalation formally concludes the Paanyaya as no agreement.
            // Preserve that result before linking the new official complaint.
            $escalationNotes = $dialogueNotes !== ''
                ? $dialogueNotes
                : 'No agreement at Paanyaya; escalated to formal Mediation.';
            $updPaanyaya = $this->conn->prepare("
                UPDATE paanyayas
                SET status = 'Escalated',
                    complaint_id = ?,
                    escalated_to_complaint_id = ?,
                    complainant_attended = ?,
                    invitee_attended = ?,
                    salaysay = ?,
                    dialogue_notes = ?,
                    attendance_recorded_at = NOW(),
                    updated_at = NOW()
                WHERE paanyaya_id = ?
            ");
            $updPaanyaya->execute([
                $complaintId,
                $complaintId,
                $compAttended,
                $invAttended,
                $salaysay,
                $escalationNotes,
                $paanyayaId
            ]);

            $this->conn->prepare("
                UPDATE complaints
                SET paanyaya_1_outcome = 'Impasse_Escalate',
                    updated_at = NOW()
                WHERE complaint_id = ?
            ")->execute([$complaintId]);

            $this->conn->commit();

            // 6. Perform formal docketing using existing docketing pipeline
            $docketResult = $this->escalateToFormalDocketing(
                $complaintId,
                $userId,
                "Inilipat mula sa Stage 0 (Paanyaya #{$paanyayaId}) patungong Formal Mediation. " . $compDetails
            );

            return [
                'success' => true,
                'complaint_id' => $complaintId,
                'case_id' => $docketResult['case_id'] ?? null,
                'case_number' => $docketResult['case_number'] ?? $complaintNo,
                'hearing' => $docketResult['hearing'] ?? null,
                'message' => 'Matagumpay na nai-akyat ang Paanyaya sa Pormal na Mediation (Stage 1). Nailipat ang Salaysay at rekord ng Pagdalo!'
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error in escalatePreDocketedPaanyaya: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to escalate Paanyaya to formal Mediation: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Schedules a 2nd Paanyaya (Attempt #2) for a dispute dialogue.
     * Completes Attempt #1 with recorded attendance/salaysay, and schedules Attempt #2.
     */
    public function scheduleSecondPaanyaya(int $paanyayaId, ?int $userId, array $data): array
    {
        $paanyaya = $this->getPaanyayaById($paanyayaId);
        if (!$paanyaya) {
            return ['success' => false, 'message' => 'Hindi natagpuan ang tala ng Paanyaya.'];
        }
        if (!empty($paanyaya['complaint_id']) && PaanyayaPreparationService::managesDialogue($this->conn, (int) $paanyaya['complaint_id'])) {
            return ['success' => false, 'message' => 'Prepare the second invitation on the original complaint.'];
        }

        if ((int)($paanyaya['attempt_number'] ?? 1) >= 2) {
            return [
                'success' => false,
                'message' => 'Ang usaping ito ay nasa Ikalawang Paanyaya (Attempt 2) na. Alinsunod sa proseso ng Katarungang Pambarangay, dalawang beses lamang ang Paanyaya bago umakyat sa Mediation.'
            ];
        }

        $p2Date = trim((string)($data['p2_date'] ?? ($data['scheduled_date'] ?? '')));
        $p2Time = trim((string)($data['p2_time'] ?? ($data['scheduled_time'] ?? '14:00')));
        if ($p2Date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p2Date)) {
            return ['success' => false, 'message' => 'Mangyaring maglagay ng wastong petsa para sa 2nd Paanyaya (YYYY-MM-DD).'];
        }
        if ($p2Time === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $p2Time)) {
            $p2Time = '14:00:00';
        }

        $compAttended = in_array($data['complainant_attended'] ?? '', ['Present', 'Absent'], true)
            ? $data['complainant_attended'] : 'Present';
        $invAttended = in_array($data['invitee_attended'] ?? '', ['Present', 'Absent'], true)
            ? $data['invitee_attended'] : 'Absent';
        $salaysay = trim((string)($data['salaysay'] ?? ($paanyaya['salaysay'] ?? '')));

        try {
            $this->conn->beginTransaction();

            // 1. Mark 1st Paanyaya as Completed
            $updFirst = $this->conn->prepare("
                UPDATE paanyayas
                SET complainant_attended = ?,
                    invitee_attended = ?,
                    salaysay = ?,
                    status = 'Completed',
                    attendance_recorded_at = NOW(),
                    updated_at = NOW()
                WHERE paanyaya_id = ?
            ");
            $updFirst->execute([$compAttended, $invAttended, $salaysay, $paanyayaId]);

            // 2. Insert 2nd Paanyaya with attempt_number = 2
            $venue = !empty($paanyaya['venue']) ? $paanyaya['venue'] : 'Tanggapan ng Barangay Tumana';
            $complaintId = !empty($paanyaya['complaint_id']) ? (int)$paanyaya['complaint_id'] : null;

            $insSecond = $this->conn->prepare("
                INSERT INTO paanyayas (
                    complaint_id, complainant_name, invitee_name, scheduled_date, scheduled_time,
                    status, attempt_number, venue, salaysay, scheduled_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'Scheduled', 2, ?, ?, ?, NOW(), NOW())
            ");
            $insSecond->execute([
                $complaintId,
                $paanyaya['complainant_name'],
                $paanyaya['invitee_name'],
                $p2Date,
                $p2Time,
                $venue,
                $salaysay !== '' ? $salaysay : null,
                $userId
            ]);
            $newPaanyayaId = (int)$this->conn->lastInsertId();

            // 3. Pre-generate 2nd Paanyaya PDF letter
            $pdfRelPath = null;
            try {
                $pdfDir = __DIR__ . '/../../storage/paanyaya';
                if (!is_dir($pdfDir)) {
                    @mkdir($pdfDir, 0777, true);
                }
                $pdfFilename = "paanyaya_{$newPaanyayaId}_" . date('Ymd_His') . ".pdf";
                $pdfAbsPath = $pdfDir . '/' . $pdfFilename;
                $pdfRelPath = 'storage/paanyaya/' . $pdfFilename;

                $pdfOptions = [
                    'save_path' => $pdfAbsPath,
                    'paanyaya_id' => $newPaanyayaId,
                    'invitee_name' => $paanyaya['invitee_name'],
                    'complainant_name' => $paanyaya['complainant_name'],
                    'invitation_date' => $p2Date,
                    'invitation_time' => $p2Time,
                    'venue' => $venue,
                    'salaysay' => $salaysay,
                    'attempt_number' => 2,
                ];
                $this->pdf->generatePaanyaya($complaintId > 0 ? $complaintId : 0, $pdfOptions);

                $this->conn->prepare("UPDATE paanyayas SET pdf_path = ? WHERE paanyaya_id = ?")
                    ->execute([$pdfRelPath, $newPaanyayaId]);
            } catch (Throwable $pe) {
                error_log("Failed to pre-generate 2nd Paanyaya PDF: " . $pe->getMessage());
            }

            // 4. Audit Log
            $this->audit->log($userId, "Scheduled 2nd Paanyaya #{$newPaanyayaId} for invitee '{$paanyaya['invitee_name']}' on {$p2Date} {$p2Time}.", 'Paanyaya', $complaintId);

            $this->conn->commit();

            return [
                'success' => true,
                'new_paanyaya_id' => $newPaanyayaId,
                'message' => 'Matagumpay na nai-iskedyul ang Ikalawang Paanyaya (2nd Paanyaya)!'
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error in scheduleSecondPaanyaya: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Nabigo sa pag-iskedyul ng Ikalawang Paanyaya: ' . $e->getMessage()
            ];
        }
    }

}
