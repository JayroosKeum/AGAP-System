<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AuditService.php';

class ServerDashboardController
{
    private PDO $conn;
    private AuditService $audit;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
        $this->audit = new AuditService();
    }

    /**
     * Retrieve complete field dispatch payload for Process Server ("Kuya").
     */
    public function getDashboardData(array $params = []): array
    {
        $today = date('Y-m-d');
        $purokFilter = trim((string) ($params['purok'] ?? 'all'));
        $statusFilter = trim((string) ($params['status'] ?? 'pending'));

        $baseActiveWhere = "sd.delivery_status <> 'Superseded' AND (h.hearing_id IS NULL OR h.status NOT IN ('Cancelled', 'Office Cancelled'))";

        // 1. Dispatch Summary KPIs
        // a. Pending Delivery (All active pending deliveries)
        $pendingStmt = $this->conn->query("
            SELECT COUNT(*) 
            FROM summon_deliveries sd
            LEFT JOIN hearings h ON h.hearing_id = sd.hearing_id
            WHERE $baseActiveWhere
              AND sd.delivery_status = 'Pending'
        ");
        $pendingDelivery = (int) $pendingStmt->fetchColumn();

        // b. Served Today (count of today's served deliveries) + Total Served for filter tab
        $servedTodayStmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM summon_deliveries sd
            LEFT JOIN hearings h ON h.hearing_id = sd.hearing_id
            WHERE $baseActiveWhere
              AND sd.delivery_status LIKE 'Served%'
              AND (DATE(sd.served_at) = ? OR DATE(sd.updated_at) = ?)
        ");
        $servedTodayStmt->execute([$today, $today]);
        $servedToday = (int) $servedTodayStmt->fetchColumn();

        $totalServedSummons = (int) $this->conn->query("
            SELECT COUNT(*)
            FROM summon_deliveries sd
            LEFT JOIN hearings h ON h.hearing_id = sd.hearing_id
            WHERE $baseActiveWhere
              AND sd.delivery_status LIKE 'Served%'
        ")->fetchColumn();

        // c. Unserved / Need Follow-up (Party moved, address not found, refused, etc.)
        $unservedStmt = $this->conn->query("
            SELECT COUNT(*)
            FROM summon_deliveries sd
            LEFT JOIN hearings h ON h.hearing_id = sd.hearing_id
            WHERE $baseActiveWhere
              AND (sd.delivery_status IN ('Unserved', 'Attempted', 'Refused')
                   OR (sd.unserved_reason IS NOT NULL AND TRIM(sd.unserved_reason) != ''))
        ");
        $unservedFollowup = (int) $unservedStmt->fetchColumn();

        // 2. Distinct Purok List for Route Filter Dropdown
        $purokStmt = $this->conn->query("
            SELECT DISTINCT purok
            FROM residents
            WHERE purok IS NOT NULL AND TRIM(purok) != ''
            ORDER BY purok ASC
        ");
        $puroks = $purokStmt->fetchAll(PDO::FETCH_COLUMN);

        // 3. Dispatch Tasks Query with Purok & Status filters
        $where = [$baseActiveWhere];
        $queryParams = [];

        if ($statusFilter === 'served') {
            $where[] = "(sd.delivery_status LIKE 'Served%')";
        } elseif ($statusFilter === 'unserved') {
            $where[] = "(sd.delivery_status IN ('Unserved', 'Attempted', 'Refused') OR (sd.unserved_reason IS NOT NULL AND TRIM(sd.unserved_reason) != ''))";
        } elseif ($statusFilter === 'all') {
            // No status constraint
        } else {
            // Default: Pending deliveries
            $where[] = "(sd.delivery_status = 'Pending')";
        }

        if ($purokFilter !== 'all' && $purokFilter !== '') {
            $where[] = "r.purok = ?";
            $queryParams[] = $purokFilter;
        }

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT 'summon' AS task_kind,
                   0 AS paanyaya_attempt_id,
                   c.complaint_id,
                   0 AS invitation_number,
                   sd.delivery_id, sd.case_id, sd.hearing_id, sd.party_type, sd.delivery_status,
                   n.document_id AS replacement_document_id,
                   sd.attempt_number, sd.service_method, sd.served_at, sd.unserved_reason,
                   sd.failure_notes, sd.remarks, sd.proof_file_path, sd.form_type,
                   c.case_number, c.case_status,
                   COALESCE(h.hearing_type, sd.form_type, 'Notice') AS hearing_type,
                   COALESCE(h.hearing_date, sd.created_at) AS hearing_date,
                   COALESCE(h.venue, 'Tanggapan ng Lupon') AS venue,
                   r.resident_id,
                   COALESCE(
                       NULLIF(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)), ''),
                       NULLIF(TRIM(sd.recipient_name), ''),
                       'Recipient'
                   ) AS full_name,
                   r.contact_no,
                   COALESCE(NULLIF(TRIM(r.purok), ''), 'Tumana') AS purok,
                   r.house_street,
                   COALESCE(
                       NULLIF(TRIM(r.address), ''),
                       NULLIF(TRIM(sd.service_address), ''),
                       NULLIF(TRIM(r.purok), ''),
                       'Barangay Tumana'
                   ) AS address,
                   r.gender,
                   DATE(COALESCE(h.hearing_date, sd.created_at)) AS target_date,
                   DATEDIFF(DATE(COALESCE(h.hearing_date, sd.created_at)), CURDATE()) AS days_until_hearing
            FROM summon_deliveries sd
            JOIN cases c ON c.case_id = sd.case_id
            LEFT JOIN residents r ON r.resident_id = sd.resident_id
            LEFT JOIN hearings h ON h.hearing_id = sd.hearing_id
            LEFT JOIN hearing_reschedule_notices n ON n.document_id = sd.document_id
            WHERE $whereClause
            ORDER BY 
                CASE WHEN sd.delivery_status = 'Pending' THEN 0 ELSE 1 END,
                COALESCE(h.hearing_date, sd.created_at) ASC,
                r.purok ASC,
                sd.delivery_id DESC
            LIMIT 100
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($queryParams);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        require_once __DIR__ . '/../services/PiiCryptoService.php';
        foreach ($tasks as &$task) {
            $task['contact_no'] = PiiCryptoService::decryptField($task['contact_no'] ?? null);
            $task['house_street'] = PiiCryptoService::decryptField($task['house_street'] ?? null);
            $task['address'] = PiiCryptoService::decryptField($task['address'] ?? null);
            if ($task['address'] === null || trim((string) $task['address']) === '') {
                $task['address'] = $task['purok'] ?? 'Barangay Tumana';
            }
        }
        unset($task);

        $totalServedPaanyaya = 0;
        // Include Paanyaya service attempts (Stage 0) in KPIs & tasks (latest operative attempt per complaint & invitation)
        try {
            $paPending = (int) $this->conn->query("
                SELECT COUNT(*)
                FROM paanyaya_service_attempts a
                INNER JOIN (
                    SELECT MAX(attempt_id) AS latest_attempt_id
                    FROM paanyaya_service_attempts
                    GROUP BY complaint_id, invitation_number
                ) latest_pa ON latest_pa.latest_attempt_id = a.attempt_id
                JOIN complaints c ON c.complaint_id = a.complaint_id
                WHERE a.outcome IS NULL
                  AND c.screening_decision = 'Eligible'
                  AND c.status IN ('Accepted', 'Pre-Docketed (Paanyaya)')
            ")->fetchColumn();
            $pendingDelivery += $paPending;

            $paServedTodayStmt = $this->conn->prepare("
                SELECT COUNT(*)
                FROM paanyaya_service_attempts a
                INNER JOIN (
                    SELECT MAX(attempt_id) AS latest_attempt_id
                    FROM paanyaya_service_attempts
                    GROUP BY complaint_id, invitation_number
                ) latest_pa ON latest_pa.latest_attempt_id = a.attempt_id
                WHERE a.outcome = 'Delivered' AND DATE(a.recorded_at) = ?
            ");
            $paServedTodayStmt->execute([$today]);
            $paServedCount = (int) $paServedTodayStmt->fetchColumn();
            $servedToday += $paServedCount;

            $totalServedPaanyaya = (int) $this->conn->query("
                SELECT COUNT(*)
                FROM paanyaya_service_attempts a
                INNER JOIN (
                    SELECT MAX(attempt_id) AS latest_attempt_id
                    FROM paanyaya_service_attempts
                    GROUP BY complaint_id, invitation_number
                ) latest_pa ON latest_pa.latest_attempt_id = a.attempt_id
                WHERE a.outcome = 'Delivered'
            ")->fetchColumn();

            $paUnserved = (int) $this->conn->query("
                SELECT COUNT(*)
                FROM paanyaya_service_attempts a
                INNER JOIN (
                    SELECT MAX(attempt_id) AS latest_attempt_id
                    FROM paanyaya_service_attempts
                    GROUP BY complaint_id, invitation_number
                ) latest_pa ON latest_pa.latest_attempt_id = a.attempt_id
                WHERE a.outcome = 'Not Delivered'
            ")->fetchColumn();
            $unservedFollowup += $paUnserved;

            $paWhere = ["1=1"];
            $paParams = [];
            if ($statusFilter === 'served') {
                $paWhere[] = "a.outcome = 'Delivered'";
            } elseif ($statusFilter === 'unserved') {
                $paWhere[] = "a.outcome = 'Not Delivered'";
            } elseif ($statusFilter !== 'all') {
                $paWhere[] = "a.outcome IS NULL AND c.screening_decision = 'Eligible' AND c.status IN ('Accepted', 'Pre-Docketed (Paanyaya)')";
            }
            if ($purokFilter !== 'all' && $purokFilter !== '') {
                $paWhere[] = "resp.purok = ?";
                $paParams[] = $purokFilter;
            }

            $paSql = "
                SELECT a.attempt_id AS paanyaya_attempt_id,
                       0 AS delivery_id,
                       0 AS case_id,
                       0 AS hearing_id,
                       a.complaint_id,
                       a.invitation_number,
                       'paanyaya' AS task_kind,
                       'Respondent' AS party_type,
                       CASE
                           WHEN a.outcome = 'Delivered' THEN 'Served Personal'
                           WHEN a.outcome = 'Not Delivered' THEN 'Unserved'
                           ELSE 'Pending'
                       END AS delivery_status,
                       NULL AS replacement_document_id,
                       a.invitation_number AS attempt_number,
                       NULL AS service_method,
                       a.recorded_at AS served_at,
                       CASE WHEN a.outcome = 'Not Delivered' THEN a.notes ELSE NULL END AS unserved_reason,
                       NULL AS failure_notes,
                       a.notes AS remarks,
                       NULL AS proof_file_path,
                       CONCAT('Paanyaya ', a.invitation_number) AS form_type,
                       COALESCE(NULLIF(TRIM(c.intake_reference), ''), NULLIF(TRIM(c.complaint_number), ''), CONCAT('CMP-', c.complaint_id)) AS case_number,
                       c.status AS case_status,
                       CONCAT('Paanyaya ', a.invitation_number) AS hearing_type,
                       a.appointment_at AS hearing_date,
                       COALESCE(p.venue, 'Tanggapan ng Barangay Tumana') AS venue,
                       resp.resident_id,
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', resp.first_name, resp.middle_name, resp.last_name, resp.suffix)), ''), 'Inanyayahan') AS full_name,
                       resp.contact_no,
                       COALESCE(NULLIF(TRIM(resp.purok), ''), 'Tumana') AS purok,
                       resp.house_street,
                       COALESCE(NULLIF(TRIM(resp.address), ''), NULLIF(TRIM(resp.purok), ''), 'Barangay Tumana') AS address,
                       resp.gender,
                       DATE(a.appointment_at) AS target_date,
                       DATEDIFF(DATE(a.appointment_at), CURDATE()) AS days_until_hearing
                FROM paanyaya_service_attempts a
                INNER JOIN (
                    SELECT MAX(attempt_id) AS latest_attempt_id
                    FROM paanyaya_service_attempts
                    GROUP BY complaint_id, invitation_number
                ) latest_pa ON latest_pa.latest_attempt_id = a.attempt_id
                JOIN complaints c ON c.complaint_id = a.complaint_id
                LEFT JOIN paanyaya_preparations p ON p.complaint_id = a.complaint_id AND p.invitation_number = a.invitation_number
                LEFT JOIN (
                    SELECT cp.complaint_id, r.resident_id, r.first_name, r.middle_name, r.last_name, r.suffix, r.contact_no, r.purok, r.house_street, r.address, r.gender
                    FROM complaint_parties cp
                    JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.party_id = (
                        SELECT MIN(cp2.party_id)
                        FROM complaint_parties cp2
                        WHERE cp2.complaint_id = cp.complaint_id AND cp2.party_type = 'Respondent'
                    )
                ) resp ON resp.complaint_id = a.complaint_id
                WHERE " . implode(' AND ', $paWhere) . "
                ORDER BY (a.outcome IS NULL) DESC, a.appointment_at ASC, a.attempt_id DESC
                LIMIT 50
            ";
            $paStmt = $this->conn->prepare($paSql);
            $paStmt->execute($paParams);
            $paTasks = PiiCryptoService::decryptPayload($paStmt->fetchAll(PDO::FETCH_ASSOC));
            foreach ($paTasks as &$paTask) {
                if (empty(trim((string) ($paTask['address'] ?? '')))) {
                    $paTask['address'] = $paTask['purok'] ?? 'Barangay Tumana';
                }
            }
            unset($paTask);
            if (!empty($paTasks)) {
                $tasks = array_merge($paTasks, $tasks);
            }
        } catch (Throwable $e) {
            error_log('ServerDashboard Paanyaya query note: ' . $e->getMessage());
        }

        $totalServedAll = $totalServedSummons + $totalServedPaanyaya;
        return [
            'kpis' => [
                'pending_delivery' => $pendingDelivery,
                'served_today' => $servedToday,
                'unserved_followup' => $unservedFollowup
            ],
            'counts' => [
                'pending' => $pendingDelivery,
                'served' => $totalServedAll,
                'unserved' => $unservedFollowup,
                'all' => $pendingDelivery + $totalServedAll + $unservedFollowup,
            ],
            'puroks' => $puroks,
            'active_purok' => $purokFilter,
            'active_status' => $statusFilter,
            'tasks' => $tasks
        ];
    }

    /**
     * Service Logger supporting both summon_deliveries and Paanyaya service attempts,
     * with Officer's Return dropdown outcomes and photo proof upload.
     */
    public function logServiceAction(array $data, ?array $file = null): array
    {
        $paanyayaAttemptId = filter_var($data['paanyaya_attempt_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $returnOutcome = trim((string) ($data['return_outcome'] ?? ''));
        $recipientInput = trim((string) ($data['recipient_name'] ?? ''));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $userId = (int) ($data['user_id'] ?? ($_SESSION['user_id'] ?? 0));
        $roleId = (int) ($_SESSION['role_id'] ?? 0);

        $outcomeMap = [
            'served_personal' => ['action' => 'served', 'status' => 'Served Personal', 'method' => 'Personal Notice', 'label' => 'Served Personally (Personal na Naihatid)', 'reason' => null],
            'served_substituted' => ['action' => 'served', 'status' => 'Served Substituted', 'method' => 'Substituted Service', 'label' => 'Substituted Service (Iniwan sa Kasama sa Bahay)', 'reason' => null],
            'served_refused' => ['action' => 'refused', 'status' => 'Served Refused', 'method' => 'Tendered (Refused)', 'label' => 'Refused to Sign (Tumanggi Lumagda)', 'reason' => 'Refused to Sign'],
            'unserved_not_found' => ['action' => 'unserved', 'status' => 'Unserved', 'method' => 'Personal', 'label' => 'Address Not Found', 'reason' => 'Address Not Found'],
            'unserved_moved_out' => ['action' => 'unserved', 'status' => 'Unserved', 'method' => 'Personal', 'label' => 'Party Moved Out', 'reason' => 'Party Moved Out'],
            'unserved_no_one_home' => ['action' => 'unserved', 'status' => 'Unserved', 'method' => 'Personal', 'label' => 'No One Home', 'reason' => 'No One Home'],
            'unserved_out_of_town' => ['action' => 'unserved', 'status' => 'Unserved', 'method' => 'Personal', 'label' => 'Out of Town / Deceased', 'reason' => 'Out of Town / Country'],
        ];

        // 1. Handle Paanyaya invitation delivery return (Stage 0)
        if ($paanyayaAttemptId) {
            if ($roleId !== 4) {
                return ['success' => false, 'message' => 'Only the assigned Summons Server may record Paanyaya delivery returns.'];
            }
            require_once __DIR__ . '/../services/PaanyayaServiceDelivery.php';
            $mapped = $outcomeMap[$returnOutcome] ?? null;
            $legacyAction = strtolower(trim((string) ($data['action'] ?? '')));
            if ($mapped) {
                $paOutcome = $mapped['action'] === 'unserved' ? 'Not Delivered' : 'Delivered';
                $noteParts = [$mapped['label']];
                if ($recipientInput !== '') {
                    $noteParts[] = 'Recipient: ' . $recipientInput;
                }
                if ($remarks !== '') {
                    $noteParts[] = $remarks;
                }
                $paNotes = implode(' — ', $noteParts);
            } elseif (in_array($legacyAction, ['served', 'refused', 'unserved'], true)) {
                $paOutcome = $legacyAction === 'unserved' ? 'Not Delivered' : 'Delivered';
                $paNotes = $remarks !== '' ? $remarks : ($legacyAction === 'unserved' ? (string) ($data['unserved_reason'] ?? 'Not Delivered') : 'Delivered');
            } else {
                return ['success' => false, 'message' => "Select a valid Officer's Return option."];
            }

            try {
                $service = new PaanyayaServiceDelivery($this->conn);
                $proofUpload = ($file && !empty($file['tmp_name'])) ? $file : [];
                $service->record((int) $paanyayaAttemptId, $userId, ['outcome' => $paOutcome, 'notes' => $paNotes], $proofUpload);
                return [
                    'success' => true,
                    'message' => $paOutcome === 'Delivered'
                        ? 'Paanyaya delivery marked as Delivered!'
                        : 'Paanyaya delivery marked as Not Delivered (Follow-up required).',
                    'delivery_status' => $paOutcome === 'Delivered' ? 'Served Personal' : 'Unserved',
                ];
            } catch (DomainException $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        // 2. Handle Formal Summons / Notice Delivery (summon_deliveries)
        $deliveryId = filter_var($data['delivery_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$deliveryId) {
            return ['success' => false, 'message' => 'Invalid delivery ID.'];
        }

        $stmt = $this->conn->prepare("
            SELECT sd.*, c.case_number, h.hearing_date, r.first_name, r.last_name, n.document_id AS replacement_document_id
            FROM summon_deliveries sd
            JOIN cases c ON c.case_id = sd.case_id
            LEFT JOIN hearings h ON h.hearing_id = sd.hearing_id
            LEFT JOIN residents r ON r.resident_id = sd.resident_id
            LEFT JOIN hearing_reschedule_notices n ON n.document_id = sd.document_id
            WHERE sd.delivery_id = ?
        ");
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            return ['success' => false, 'message' => 'Delivery task record not found.'];
        }
        if ($delivery['delivery_status'] === 'Superseded') {
            return ['success' => false, 'message' => 'A superseded return is read-only.'];
        }
        $mapped = $outcomeMap[$returnOutcome] ?? null;
        if ($delivery['replacement_document_id'] !== null) {
            require_once __DIR__ . '/GPSController.php';
            $gpsInput = [
                'delivery_id' => $deliveryId,
                'case_id' => $delivery['case_id'],
                'hearing_id' => $delivery['hearing_id'],
                'document_id' => $delivery['document_id'],
                'party_type' => $delivery['party_type'],
                'resident_id' => $delivery['resident_id'],
                'recipient_name' => $recipientInput !== '' ? $recipientInput : ($delivery['recipient_name'] ?? 'Recipient'),
                'delivery_status' => $mapped ? $mapped['status'] : 'Served Personal',
                'service_method' => $mapped ? $mapped['method'] : 'Personal Notice',
                'unserved_reason' => $mapped['reason'] ?? ($data['unserved_reason'] ?? null),
                'remarks' => $remarks,
            ];
            return (new GPSController())->recordOfficerReturn($gpsInput, $file ?? [], $userId);
        }

        $action = $mapped ? $mapped['action'] : strtolower(trim((string) ($data['action'] ?? '')));
        $now = date('Y-m-d H:i:s');

        // Handle Photo Upload (Proof of Service: house gate, recipient photo)
        $proofFilePath = $delivery['proof_file_path'] ?? null;
        if ($file && !empty($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK) {
            $proofFilePath = $this->saveProofPhoto($deliveryId, $file);
        }

        $recipientName = $recipientInput;
        if ($recipientName === '') {
            $recipientName = trim(($delivery['first_name'] ?? '') . ' ' . ($delivery['last_name'] ?? ''));
        }
        if ($recipientName === '') {
            $recipientName = trim((string) ($delivery['recipient_name'] ?? 'Recipient'));
        }

        if ($action === 'served') {
            $targetStatus = $mapped ? $mapped['status'] : 'Served Personal';
            $serviceMethod = $mapped ? $mapped['method'] : trim((string) ($data['service_method'] ?? 'Personal Notice'));
            $upd = $this->conn->prepare("
                UPDATE summon_deliveries
                SET delivery_status = ?,
                    service_method = ?,
                    served_at = ?,
                    served_by = ?,
                    served_by_user_id = ?,
                    recipient_name = ?,
                    remarks = ?,
                    proof_file_path = COALESCE(?, proof_file_path),
                    unserved_reason = NULL,
                    updated_at = NOW()
                WHERE delivery_id = ? AND delivery_status <> 'Superseded'
            ");
            $upd->execute([
                $targetStatus,
                $serviceMethod,
                $now,
                $userId,
                $userId,
                $recipientName,
                $remarks ?: ($targetStatus === 'Served Substituted' ? 'Substituted service delivered to household/office representative.' : 'Personal service delivered and acknowledged.'),
                $proofFilePath,
                $deliveryId
            ]);

            if (!$upd->rowCount()) {
                return ['success' => false, 'message' => 'This delivery changed. Refresh the task and try again.'];
            }
            $this->audit->log(
                $userId,
                'SUMMON_SERVED',
                'Summons',
                "Summons delivery #{$deliveryId} marked as {$targetStatus} for Case {$delivery['case_number']}"
            );

            return [
                'success' => true,
                'message' => 'Matagumpay na naitala ang paghahatid (Served)!',
                'delivery_status' => $targetStatus,
                'proof_file_path' => $proofFilePath
            ];
        }

        if ($action === 'refused') {
            $upd = $this->conn->prepare("
                UPDATE summon_deliveries
                SET delivery_status = 'Served Refused',
                    service_method = 'Tendered (Refused)',
                    served_at = ?,
                    served_by = ?,
                    served_by_user_id = ?,
                    recipient_name = ?,
                    unserved_reason = 'Refused to Sign',
                    failure_notes = ?,
                    remarks = ?,
                    proof_file_path = COALESCE(?, proof_file_path),
                    updated_at = NOW()
                WHERE delivery_id = ? AND delivery_status <> 'Superseded'
            ");
            $upd->execute([
                $now,
                $userId,
                $userId,
                $recipientName,
                'Party refused to accept or sign the summons form in person.',
                $remarks ?: 'Tumanggi lumagda ang panig ngunit iniwan ang kopya.',
                $proofFilePath,
                $deliveryId
            ]);

            if (!$upd->rowCount()) {
                return ['success' => false, 'message' => 'This delivery changed. Refresh the task and try again.'];
            }
            $this->audit->log(
                $userId,
                'SUMMON_REFUSED',
                'Summons',
                "Summons delivery #{$deliveryId} recorded as REFUSED TO SIGN for Case {$delivery['case_number']}"
            );

            return [
                'success' => true,
                'message' => 'Naitala ang pagtanggi lumagda (Refused to Sign).',
                'delivery_status' => 'Served Refused',
                'proof_file_path' => $proofFilePath
            ];
        }

        if ($action === 'unserved') {
            $reason = $mapped && $mapped['reason'] ? $mapped['reason'] : trim((string) ($data['unserved_reason'] ?? 'Address Not Found'));
            $upd = $this->conn->prepare("
                UPDATE summon_deliveries
                SET delivery_status = 'Unserved',
                    unserved_reason = ?,
                    failure_notes = ?,
                    remarks = ?,
                    served_by = ?,
                    served_by_user_id = ?,
                    proof_file_path = COALESCE(?, proof_file_path),
                    updated_at = NOW()
                WHERE delivery_id = ? AND delivery_status <> 'Superseded'
            ");
            $upd->execute([
                $reason,
                $remarks ?: "Unserved attempt: {$reason}",
                $remarks,
                $userId,
                $userId,
                $proofFilePath,
                $deliveryId
            ]);

            if (!$upd->rowCount()) {
                return ['success' => false, 'message' => 'This delivery changed. Refresh the task and try again.'];
            }
            $this->audit->log(
                $userId,
                'SUMMON_UNSERVED',
                'Summons',
                "Summons delivery #{$deliveryId} marked as UNSERVED ({$reason}) for Case {$delivery['case_number']}"
            );

            return [
                'success' => true,
                'message' => 'Naitala bilang Unserved / Need Follow-up.',
                'delivery_status' => 'Unserved',
                'proof_file_path' => $proofFilePath
            ];
        }

        return ['success' => false, 'message' => "Hindi kilalang service action: {$action}"];
    }

    /**
     * Save uploaded proof photo to storage/proofs.
     */
    private function saveProofPhoto(int $deliveryId, array $file): ?string
    {
        $uploadDir = __DIR__ . '/../../storage/proofs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        $fileName = sprintf('proof_delivery_%d_%s_%s.%s', $deliveryId, date('Ymd_His'), bin2hex(random_bytes(4)), $ext);
        $targetPath = $uploadDir . '/' . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return 'storage/proofs/' . $fileName;
        }

        return null;
    }
}
