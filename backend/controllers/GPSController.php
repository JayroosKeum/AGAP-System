<?php

require_once __DIR__ . '/../models/Location.php';
require_once __DIR__ . '/../models/ProofOfService.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/ValidationService.php';

class GPSController
{
    private Location $location;
    private ProofOfService $proof;
    private AuditService $audit;
    private Document $document;

    public function __construct()
    {
        $this->location = new Location();
        $this->proof = new ProofOfService();
        $this->audit = new AuditService();
        $this->document = new Document();
    }

    public function complaints(): array { return ['success' => true, 'data' => $this->location->getComplaints()]; }
    public function locations(): array { return ['success' => true, 'data' => $this->location->getAll()]; }
    public function cases(): array { return ['success' => true, 'data' => $this->proof->getAvailableCases()]; }
    public function documents(int $caseId): array { return ['success' => true, 'data' => $this->document->getGeneratedByCase($caseId)]; }
    public function caseSummary(int $caseId): array
    {
        $summary = $this->proof->getCaseSummary($caseId);
        if (!$summary) return ['success' => false, 'message' => 'Case not found.'];
        return ['success' => true, 'data' => $summary];
    }
    public function summonsServers(): array { return ['success' => true, 'data' => $this->proof->getSummonsServers()]; }
    public function defaultLuponClerk(): array
    {
        $clerk = $this->proof->getDefaultLuponClerk();
        return $clerk ? ['success' => true, 'data' => $clerk] : ['success' => false, 'message' => 'No active Lupon Clerk account is available.'];
    }
    public function summonsNotices(int $caseId): array { return ['success' => true, 'data' => $this->proof->getSummonsNotices($caseId)]; }
    public function reopenSummonsNotice(int $caseId, int $documentId, int $userId): array
    {
        if (!$caseId || !$documentId) return ['success' => false, 'message' => 'A valid case and summons notice are required.'];
        if (!$this->document->reopenForService($documentId, $caseId)) return ['success' => false, 'message' => 'Only a failed summons notice may be reopened for service.'];
        $this->audit->log($userId, 'Reopened failed summons notice for service', 'Summons', $documentId);
        return ['success' => true, 'message' => 'Summons notice reopened for a new service attempt.'];
    }

    public function caseDeliveries(int $caseId): array
    {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();
        $cfa = new CFA($db);

        $requestedStage = trim((string)($_GET['stage'] ?? $_GET['stage_type'] ?? ''));
        $requestedHearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $hearings = [];

        if (!$requestedStage) {
            $cStmt = $db->prepare("SELECT case_status FROM cases WHERE case_id = ? LIMIT 1");
            $cStmt->execute([$caseId]);
            $requestedStage = (string)$cStmt->fetchColumn();
        }

            $orderClauses = [];
            if ($requestedHearingId) {
                $orderClauses[] = "CASE WHEN hearing_id = " . (int)$requestedHearingId . " THEN 0 ELSE 1 END";
            }
            if ($requestedStage === 'Conciliation') {
                $orderClauses[] = "CASE WHEN hearing_type = 'Conciliation' THEN 0 ELSE 1 END";
            } elseif ($requestedStage === 'Mediation') {
                $orderClauses[] = "CASE WHEN hearing_type IN ('Initial Hearing', 'Mediation') THEN 0 ELSE 1 END";
            }
            $orderClauses[] = "CASE WHEN status IN ('Scheduled', 'In Progress', 'Active') THEN 0 ELSE 1 END";
            $orderClauses[] = "hearing_date DESC";
            $orderClauses[] = "hearing_id DESC";

            $hOrderSql = implode(", ", $orderClauses);

            $stmt = $db->prepare("
                SELECT hearing_id, hearing_type, hearing_date, venue, status
                FROM hearings
                WHERE case_id = ?
                ORDER BY $hOrderSql
            ");
            $stmt->execute([$caseId]);
            $hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($hearings)) {
            return [
                'success' => true,
                'data' => [
                    'hearings' => [],
                    'has_hearings' => false,
                    'cfa_eligibility' => $cfa->determineCfaEligibility($caseId),
                    'message' => 'No hearings scheduled yet for this case. Issue a summons to schedule 1st Mediation.'
                ]
            ];
        }


        require_once __DIR__ . '/../models/Setting.php';
        $maxSummons = (int) Setting::getSetting('summons_max_attempts', 6, $db);
        $deliveryService = new SummonDeliveryService($db);
        $hearingsWithDeliveries = [];
        foreach ($hearings as $h) {
            $hearingId = (int) $h['hearing_id'];
            $delivResult = $deliveryService->getHearingDeliveries($hearingId);
            if ($delivResult['success']) {
                $hearingsWithDeliveries[] = [
                    'hearing_id' => $hearingId,
                    'hearing_type' => $h['hearing_type'],
                    'hearing_date' => $h['hearing_date'],
                    'venue' => $h['venue'],
                    'status' => $h['status'],
                    'max_attempts' => $maxSummons,
                    'replacement_notices' => $deliveryService->replacementNotices($hearingId),
                    'deliveries' => $delivResult['deliveries'],
                    'complainant_delivery' => $delivResult['complainant_delivery'] ?? null,
                    'complainant_attempts' => $delivResult['complainant_attempts'] ?? [],
                    'latest_complainant_attempt' => $delivResult['latest_complainant_attempt'] ?? null,
                    'can_initiate_next_comp_attempt' => $delivResult['can_initiate_next_comp_attempt'] ?? false,
                    'next_comp_attempt_number' => $delivResult['next_comp_attempt_number'] ?? null,
                    'respondent_attempts' => $delivResult['respondent_attempts'] ?? [],
                    'can_initiate_next_attempt' => $delivResult['can_initiate_next_attempt'] ?? false,
                    'next_attempt_number' => $delivResult['next_attempt_number'] ?? null,
                    'delivery_eligibility' => $delivResult['cfa_eligibility'] ?? null,
                    'attendance_unlocked' => $delivResult['attendance_unlocked'],
                    'service_notice' => $delivResult['service_notice'],
                ];
            }
        }

        return [
            'success' => true,
            'data' => [
                'hearings' => $hearingsWithDeliveries,
                'has_hearings' => true,
                'cfa_eligibility' => $cfa->determineCfaEligibility($caseId),
            ]
        ];
    }

    public function recordOfficerReturn(array $input, array $file, int $userId): array
    {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../models/Paanyaya.php';
        $db = (new Database())->connect();

        $hearingId = filter_var($input['hearing_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $deliveryId = filter_var($input['delivery_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

        if ($deliveryId) {
            $delivStmt = $db->prepare("SELECT delivery_id, hearing_id, case_id FROM summon_deliveries WHERE delivery_id = ?");
            $delivStmt->execute([$deliveryId]);
            $delivRow = $delivStmt->fetch(PDO::FETCH_ASSOC);
            if ($delivRow) {
                if (!$hearingId && !empty($delivRow['hearing_id'])) {
                    $hearingId = (int)$delivRow['hearing_id'];
                }
                if (!$caseId && !empty($delivRow['case_id'])) {
                    $caseId = (int)$delivRow['case_id'];
                }
            }
        }

        if (!$hearingId && $caseId) {
            $stmt = $db->prepare("SELECT hearing_id FROM hearings WHERE case_id = ? ORDER BY hearing_date DESC, hearing_id DESC LIMIT 1");
            $stmt->execute([$caseId]);
            $hearingId = (int) $stmt->fetchColumn() ?: null;
        }

        if (!$hearingId) {
            return ['success' => false, 'message' => 'No active hearing found for this case to record summon delivery against.'];
        }

        // Upload proof image if provided
        $imagePath = null;
        if (!empty($file['tmp_name'])) {
            $upload = $this->storeImage($file);
            if (!$upload['success']) {
                return $upload;
            }
            $imagePath = $upload['path'];
        }

        $deliveryService = new SummonDeliveryService($db);
        try {
            $db->beginTransaction();
            $result = $deliveryService->recordDelivery($hearingId, $input, $userId);
            if ($result['success']) {
                if ($imagePath) {
                    $db->prepare("UPDATE summon_deliveries SET proof_file_path = ? WHERE delivery_id = ?")
                        ->execute([$imagePath, $result['delivery_id']]);
                    $db->prepare("UPDATE proof_of_service SET image_path = ? WHERE proof_id = ?")
                        ->execute([$imagePath, $result['proof_id']]);
                }
                $db->commit();
                return $result;
            }
            return $result;
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
                if ($imagePath) {
                    @unlink(dirname(__DIR__, 2) . '/' . $imagePath);
                }
            }
        }
    }

    public function initiateNextAttempt(
        int $caseId,
        int $userId,
        ?int $hearingId = null,
        string $partyType = 'Respondent',
        ?int $deliveryId = null,
        ?int $residentId = null
    ): array {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();
        require_once __DIR__ . '/../models/Setting.php';
        $maxSummons = (int) Setting::getSetting('summons_max_attempts', 6, $db);
        $deliveryService = new SummonDeliveryService($db);
        return $deliveryService->initiateNextAttempt($caseId, $userId, $hearingId, $partyType, $deliveryId, $residentId);
    }

    public function markKp8Served(int $caseId, int $documentId, ?int $hearingId, int $userId): array
    {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();
        require_once __DIR__ . '/../models/Setting.php';
        $maxSummons = (int) Setting::getSetting('summons_max_attempts', 6, $db);
        $deliveryService = new SummonDeliveryService($db);
        return $deliveryService->markKp8Printed($caseId, $documentId, $hearingId, $userId);
    }

    public function cfaEligibility(int $caseId): array
    {
        $db = (new Database())->connect();
        return [
            'success' => true,
            'data' => (new CFA($db))->determineCfaEligibility($caseId)
        ];
    }

    public function location(int $complaintId): array
    {
        if (!$this->location->complaintExists($complaintId)) return ['success' => false, 'message' => 'Complaint not found.'];
        return ['success' => true, 'data' => $this->location->getByComplaint($complaintId)];
    }

    public function saveLocation(array $data, int $userId): array
    {
        $complaintId = filter_var($data['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $latitude = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $address = trim((string) ($data['address'] ?? ''));
        if (!$complaintId || $latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) return ['success' => false, 'message' => 'Select a complaint and a valid point on the map.'];
        if (mb_strlen($address) > 2000) return ['success' => false, 'message' => 'Address must not exceed 2,000 characters.'];
        $result = $this->location->save((int) $complaintId, (float) $latitude, (float) $longitude, $address !== '' ? $address : null);
        if ($result['success']) $this->audit->log($userId, 'Saved incident location', 'GPS', (int) $complaintId);
        return $result + ['message' => $result['success'] ? 'Incident location saved.' : null];
    }

    public function proofs(int $caseId): array
    {
        if ($caseId < 1) return ['success' => false, 'message' => 'A valid case is required.'];
        return ['success' => true, 'data' => $this->proof->getByCase($caseId)];
    }

    public function saveProof(array $data, array $file, int $userId): array
    {
        date_default_timezone_set('Asia/Manila');
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $documentId = filter_var($data['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $servedDate = trim((string) ($data['served_date'] ?? ''));
        $date = null;
        if ($servedDate !== '') {
            $cleanDate = str_replace('T', ' ', $servedDate);
            try {
                $date = new DateTimeImmutable($cleanDate, new DateTimeZone('Asia/Manila'));
            } catch (Throwable $e) {
                try {
                    $date = DateTimeImmutable::createFromFormat('m/d/Y h:i A', $servedDate, new DateTimeZone('Asia/Manila'));
                } catch (Throwable $e2) {
                    $ts = strtotime($servedDate);
                    if ($ts !== false) {
                        $date = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Manila'));
                    }
                }
            }
        }
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $serviceResult = trim((string) ($data['service_result'] ?? 'Served'));
        $allowedResults = ['Served', 'Not Served', 'Refused', 'Respondent Not Found', 'Address Problem', 'Other'];
        if (!in_array($serviceResult, $allowedResults, true)) {
            $serviceResult = 'Served';
        }
        $clerk = $this->proof->getDefaultLuponClerk();
        if (!$clerk) return ['success' => false, 'message' => 'An active Lupon Clerk account is required before recording service.'];
        $servedBy = (int) $clerk['user_id'];

        // Allow up to 24 hours in the future to account for clock skew, timezones, and planned service entries
        $maxAllowedDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+24 hours');
        if (!$caseId || !$documentId || !$date || $date > $maxAllowedDate) {
            return ['success' => false, 'message' => 'Select an active case, generated document, and a valid service date that is not in the future.'];
        }
        if (mb_strlen($remarks) > 2000) return ['success' => false, 'message' => 'Verification details must not exceed 2,000 characters.'];
        $upload = $this->storeImage($file);
        if (!$upload['success']) return $upload;
        $result = $this->proof->create((int) $caseId, (int) $documentId, (int) $servedBy, $date->format('Y-m-d H:i:s'), $remarks !== '' ? $remarks : null, $upload['path'], $serviceResult);
        if (!$result['success'] && $upload['path']) @unlink(dirname(__DIR__, 2) . '/' . $upload['path']);
        if ($result['success']) {
            $this->audit->log($userId, 'Recorded proof of service (' . $serviceResult . ')', 'GPS', (int) $result['proof_id']);
        }
        return $result + ['message' => $result['success'] ? 'Proof of service recorded.' : null];
    }


    public function proofImage(int $proofId): array|false { return $this->proof->getById($proofId); }

    private function storeImage(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['success' => true, 'path' => null];
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $actualSize = $temporaryPath !== '' && is_uploaded_file($temporaryPath) ? filesize($temporaryPath) : false;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $actualSize === false || $actualSize < 1
            || $actualSize > 5 * 1024 * 1024 || (int) ($file['size'] ?? -1) !== $actualSize) {
            return ['success' => false, 'message' => 'Upload a valid JPG, PNG, or WebP image no larger than 5 MB.'];
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset($extensions[$mime]) || !in_array($extension, $extensions[$mime], true) || @getimagesize($temporaryPath) === false) {
            return ['success' => false, 'message' => 'Proof image must be a valid JPG, PNG, or WebP file.'];
        }
        $relativeDirectory = 'storage/uploads/proof-of-service'; $directory = dirname(__DIR__, 2) . '/' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) return ['success' => false, 'message' => 'Proof-image storage is unavailable.'];
        $storedExtension = $mime === 'image/jpeg' ? 'jpg' : $extension;
        $relativePath = $relativeDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $storedExtension;
        if (!move_uploaded_file($temporaryPath, dirname(__DIR__, 2) . '/' . $relativePath)) return ['success' => false, 'message' => 'Unable to save the proof image.'];
        return ['success' => true, 'path' => $relativePath];
    }

    public function getCaseServiceTimeline(int $caseId): array
    {
        if ($caseId < 1) {
            return ['success' => false, 'message' => 'A valid case ID is required.'];
        }

        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();

        $cStmt = $db->prepare("
            SELECT c.case_id, c.case_number, c.case_status, c.complaint_id,
                   co.complaint_number, co.complaint_title, co.status AS complaint_status
            FROM cases c
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE c.case_id = ?
        ");
        $cStmt->execute([$caseId]);
        $case = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        $complaintId = (int) $case['complaint_id'];

        // --- STAGE 1: MEDIATION & STAGE 2: CONCILIATION DELIVERIES ---
        $mediationItems = [];
        $conciliationItems = [];

        try {
            $delivStmt = $db->prepare("
                SELECT sd.*, 
                       h.hearing_type, h.hearing_date, h.venue,
                       gd.file_path, gd.template_id, dt.template_name,
                       TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name
                FROM summon_deliveries sd
                LEFT JOIN hearings h ON h.hearing_id = sd.hearing_id
                LEFT JOIN generated_documents gd ON gd.document_id = sd.document_id
                LEFT JOIN document_templates dt ON dt.template_id = gd.template_id
                LEFT JOIN users u ON u.user_id = COALESCE(sd.served_by_user_id, sd.served_by)
                WHERE sd.case_id = ?
                ORDER BY sd.delivery_id ASC
            ");
            $delivStmt->execute([$caseId]);
            $allDeliveries = $delivStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allDeliveries as $deliv) {
                $fType = $deliv['form_type'];
                $hType = $deliv['hearing_type'] ?? '';
                $isConc = ($hType === 'Conciliation' || str_contains($fType, '10') || str_contains($fType, '11') || str_contains($fType, '12') || str_contains((string)$deliv['remarks'], 'KP Form 12') || str_contains((string)$deliv['remarks'], 'Pangkat'));

                $rawStatus = $deliv['delivery_status'] ?: 'Pending';
                $statusPill = 'pending';
                $statusLabel = 'Pending Service';
                if ($rawStatus === 'Served Personal' || $rawStatus === 'Served') {
                    $statusPill = 'served-personal';
                    $statusLabel = 'Served - Personal';
                } elseif ($rawStatus === 'Served Substituted') {
                    $statusPill = 'served-substituted';
                    $statusLabel = 'Served - Substituted';
                } elseif ($rawStatus === 'Served Refused') {
                    $statusPill = 'refused';
                    $statusLabel = 'Refused to Receive';
                } elseif ($rawStatus === 'Unserved') {
                    $statusPill = 'unserved';
                    $statusLabel = 'Unserved';
                } elseif ($rawStatus === 'Superseded') {
                    $statusPill = 'pending';
                    $statusLabel = 'Superseded';
                }

                $attemptNum = (int) ($deliv['attempt_number'] ?: 1);

                $docCode = $fType;
                if (str_contains($fType, 'Summon') || $fType === 'Summon') {
                    $docCode = 'KP Form 9';
                } elseif ($fType === 'Notice of Hearing' && !$isConc) {
                    $docCode = 'KP Form 8';
                }

                $previewUrl = null;
                if (!empty($deliv['document_id'])) {
                    $previewUrl = "../../../backend/api/documents/download.php?id=" . (int)$deliv['document_id'];
                } else {
                    $previewUrl = "../../../backend/api/documents/kp-form.php?case_id={$caseId}&form_code=" . urlencode($docCode) . "&preview=1";
                }

                $item = [
                    'delivery_id' => (int) $deliv['delivery_id'],
                    'hearing_id' => $deliv['hearing_id'] ? (int) $deliv['hearing_id'] : null,
                    'document_id' => $deliv['document_id'] ? (int) $deliv['document_id'] : null,
                    'stage' => $isConc ? 'Conciliation' : 'Mediation',
                    'stage_index' => $isConc ? 2 : 1,
                    'document_code' => $docCode,
                    'document_title' => $deliv['remarks'] ?: "{$docCode} Notice",
                    'party_type' => $deliv['party_type'],
                    'recipient_name' => $deliv['recipient_name'] ?: $deliv['party_type'],
                    'attempt_number' => $attemptNum,
                    'attempt_label' => "Attempt #{$attemptNum}",
                    'delivery_status' => $statusLabel,
                    'status_pill' => $statusPill,
                    'unserved_reason' => $deliv['unserved_reason'] ?: null,
                    'served_by_name' => $deliv['served_by_name'] ?: ($deliv['service_method'] ?: 'Process Server'),
                    'service_datetime' => $deliv['served_at'] ?: ($deliv['attempted_at'] ?: $deliv['created_at']),
                    'service_method' => $deliv['service_method'] ?: null,
                    'proof_file_path' => $deliv['proof_file_path'] ?: null,
                    'remarks' => $deliv['remarks'] ?: '',
                    'preview_url' => $previewUrl
                ];

                if ($isConc) {
                    $conciliationItems[] = $item;
                } else {
                    $mediationItems[] = $item;
                }
            }
        } catch (Throwable $e) {
            error_log('Timeline Deliveries Error: ' . $e->getMessage());
        }

        $paanyayaItems = [];
        try {
            if ($complaintId > 0) {
                $respStmt = $db->prepare("
                    SELECT TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = ? AND cp.party_type = 'Respondent'
                    ORDER BY cp.party_id ASC
                ");
                $respStmt->execute([$complaintId]);
                $respNamesList = array_values(array_filter(array_map('trim', $respStmt->fetchAll(PDO::FETCH_COLUMN))));
                $defaultInviteeName = !empty($respNamesList)
                    ? implode(', ', $respNamesList)
                    : ($case['respondent_names'] ?? 'Respondent');

                $minStmt = $db->prepare("
                    SELECT minutes_id, stage_type, dialogue_date, complainant_attended, respondent_attended,
                           discussion_summary, outcome, complainant_choice, scanned_minutes_path
                    FROM paanyaya_minutes
                    WHERE complaint_id = ?
                    ORDER BY FIELD(stage_type, 'Paanyaya 1', 'Paanyaya 2'), minutes_id ASC
                ");
                $minStmt->execute([$complaintId]);
                $minutesByStage = [];
                foreach ($minStmt->fetchAll(PDO::FETCH_ASSOC) as $minRow) {
                    $minutesByStage[$minRow['stage_type']] = $minRow;
                }

                $attStmt = $db->prepare("
                    SELECT a.attempt_id, a.invitation_number, a.appointment_at AS attempt_appointment_at,
                           a.document_version, a.released_at, a.outcome, a.notes, a.recorded_at, a.proof_mime,
                           v.appointment_at AS version_appointment_at, v.venue AS version_venue,
                           (v.document_pdf IS NOT NULL) AS has_version_pdf,
                           p.appointment_at AS prep_appointment_at, p.venue AS prep_venue,
                           (p.document_pdf IS NOT NULL) AS has_prep_pdf,
                           TRIM(CONCAT_WS(' ', srv.first_name, srv.last_name)) AS served_by_name
                    FROM paanyaya_service_attempts a
                    INNER JOIN paanyaya_preparations p
                        ON p.complaint_id = a.complaint_id AND p.invitation_number = a.invitation_number
                    LEFT JOIN paanyaya_invitation_versions v
                        ON v.complaint_id = a.complaint_id
                       AND v.invitation_number = a.invitation_number
                       AND v.document_version = a.document_version
                    LEFT JOIN users srv ON srv.user_id = a.assigned_to
                    WHERE a.complaint_id = ?
                    ORDER BY a.invitation_number ASC, a.attempt_id ASC
                ");
                $attStmt->execute([$complaintId]);
                $attempts = $attStmt->fetchAll(PDO::FETCH_ASSOC);

                $prepStmt = $db->prepare("
                    SELECT invitation_number, appointment_at, venue, prepared_at, ready_at, document_version
                    FROM paanyaya_preparations
                    WHERE complaint_id = ?
                    ORDER BY invitation_number ASC
                ");
                $prepStmt->execute([$complaintId]);
                $preparations = $prepStmt->fetchAll(PDO::FETCH_ASSOC);

                $attemptsPerInvitation = [];
                foreach ($attempts as $att) {
                    $invNum = (int) ($att['invitation_number'] ?: 1);
                    $attemptsPerInvitation[$invNum] = ($attemptsPerInvitation[$invNum] ?? 0) + 1;
                    $seq = $attemptsPerInvitation[$invNum];
                    $origAppt = $att['version_appointment_at'] ?: ($att['attempt_appointment_at'] ?: $att['prep_appointment_at']);
                    $venue = $att['version_venue'] ?: ($att['prep_venue'] ?: 'Barangay Hall');
                    $outcome = $att['outcome'] ?: 'Pending';
                    [$statusPill, $statusLabel] = match ($outcome) {
                        'Delivered' => ['served-personal', 'Delivered'],
                        'Not Delivered' => ['unserved', 'Not Delivered'],
                        default => ['pending', 'Pending Service'],
                    };
                    $hasPdf = !empty($att['has_version_pdf']) || !empty($att['has_prep_pdf']);
                    $previewUrl = $hasPdf
                        ? ('../../../backend/api/paanyaya/service.php?view=invitation&attempt_id=' . (int) $att['attempt_id'])
                        : null;
                    $proofUrl = !empty($att['proof_mime'])
                        ? ('../../../backend/api/paanyaya/service.php?view=proof&attempt_id=' . (int) $att['attempt_id'])
                        : null;
                    $remarksParts = [];
                    if ($origAppt) {
                        $remarksParts[] = 'Appointment: ' . $origAppt . ($venue !== '' ? " ({$venue})" : '');
                    }
                    if (!empty($att['notes'])) {
                        $remarksParts[] = trim((string) $att['notes']);
                    }

                    $paanyayaItems[] = [
                        'delivery_id' => (int) $att['attempt_id'],
                        'hearing_id' => null,
                        'document_id' => null,
                        'stage' => 'Paanyaya',
                        'stage_index' => 0,
                        'document_code' => 'Paanyaya #' . $invNum,
                        'document_title' => 'Paanyaya Invitation #' . $invNum,
                        'party_type' => 'Respondent',
                        'recipient_name' => $defaultInviteeName,
                        'attempt_number' => $seq,
                        'invitation_number' => $invNum,
                        'attempt_label' => 'Invitation #' . $invNum . ' · Service Attempt #' . $seq,
                        'delivery_status' => $statusLabel,
                        'status_pill' => $statusPill,
                        'unserved_reason' => $outcome === 'Not Delivered' ? ($att['notes'] ?: 'Not Delivered') : null,
                        'served_by_name' => trim((string) ($att['served_by_name'] ?? '')) ?: 'Summons Server',
                        'appointment_at' => $origAppt,
                        'service_datetime' => $att['recorded_at'] ?: ($origAppt ?: $att['released_at']),
                        'service_method' => 'Paanyaya Invitation Delivery',
                        'proof_file_path' => $proofUrl,
                        'remarks' => implode(' — ', $remarksParts) ?: 'Paanyaya Invitation Delivery',
                        'preview_url' => $previewUrl,
                    ];
                }

                // Include prepared invitations without service attempts yet
                foreach ($preparations as $prep) {
                    $invNum = (int) ($prep['invitation_number'] ?: 1);
                    if (!isset($attemptsPerInvitation[$invNum])) {
                        $paanyayaItems[] = [
                            'delivery_id' => $invNum,
                            'hearing_id' => null,
                            'document_id' => null,
                            'stage' => 'Paanyaya',
                            'stage_index' => 0,
                            'document_code' => 'Paanyaya #' . $invNum,
                            'document_title' => 'Paanyaya Invitation #' . $invNum,
                            'party_type' => 'Respondent',
                            'recipient_name' => $defaultInviteeName,
                            'attempt_number' => 1,
                            'invitation_number' => $invNum,
                            'attempt_label' => 'Invitation #' . $invNum . ' · Prepared',
                            'delivery_status' => !empty($prep['ready_at']) ? 'Ready for Release' : 'Prepared',
                            'status_pill' => 'pending',
                            'unserved_reason' => null,
                            'served_by_name' => 'Barangay Office',
                            'appointment_at' => $prep['appointment_at'],
                            'service_datetime' => $prep['appointment_at'] ?: $prep['prepared_at'],
                            'service_method' => 'Paanyaya Preparation',
                            'proof_file_path' => null,
                            'remarks' => 'Appointment: ' . ($prep['appointment_at'] ?: 'TBD') . (!empty($prep['venue']) ? ' (' . $prep['venue'] . ')' : ''),
                            'preview_url' => '../../../backend/api/paanyaya/preparation.php?complaint_id=' . $complaintId . '&invitation_number=' . $invNum,
                        ];
                    }
                }

                // Append recorded Stage 0 dialogue entries
                foreach ($minutesByStage as $stageType => $minRow) {
                    $invNum = $stageType === 'Paanyaya 2' ? 2 : 1;
                    $mOutcome = (string) ($minRow['outcome'] ?? 'No_Agreement');
                    $mChoice = (string) ($minRow['complainant_choice'] ?? '');
                    [$statusPill, $statusLabel] = match (true) {
                        $mOutcome === 'Settled' => ['served-personal', 'Settled at Paanyaya'],
                        $mChoice === 'Escalate_Mediation' => ['served-personal', 'Escalated to Mediation'],
                        $mOutcome === 'Dismissed' => ['unserved', 'Dismissed'],
                        default => ['pending', $mOutcome],
                    };

                    $paanyayaItems[] = [
                        'delivery_id' => (int) $minRow['minutes_id'],
                        'hearing_id' => null,
                        'document_id' => null,
                        'stage' => 'Paanyaya',
                        'stage_index' => 0,
                        'document_code' => 'Paanyaya Dialogue #' . $invNum,
                        'document_title' => $stageType . ' Informal Dialogue',
                        'party_type' => 'Parties',
                        'recipient_name' => $defaultInviteeName,
                        'attempt_number' => $invNum,
                        'invitation_number' => $invNum,
                        'attempt_label' => 'Dialogue #' . $invNum . ' · Outcome',
                        'delivery_status' => $statusLabel,
                        'status_pill' => $statusPill,
                        'unserved_reason' => null,
                        'served_by_name' => 'Lupon Clerk',
                        'service_datetime' => $minRow['dialogue_date'],
                        'service_method' => 'Informal Dialogue',
                        'proof_file_path' => !empty($minRow['scanned_minutes_path']) ? ('../../../' . ltrim((string) $minRow['scanned_minutes_path'], '/')) : null,
                        'remarks' => $minRow['discussion_summary'] ?: 'Paanyaya Dialogue Recorded',
                        'preview_url' => null,
                    ];
                }

                // Legacy fallback when no prepared invitations/attempts/minutes exist
                if (empty($paanyayaItems)) {
                    $pStmt = $db->prepare("
                        SELECT ps.*
                        FROM paanyaya_sessions ps
                        WHERE ps.complaint_id = ?
                        ORDER BY ps.attempt_number ASC, ps.session_id ASC
                    ");
                    $pStmt->execute([$complaintId]);
                    $paaSessions = $pStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($paaSessions as $pSess) {
                        $pStatus = $pSess['session_outcome'] ?: 'Scheduled';
                        $statusPill = 'pending';
                        $statusLabel = 'Scheduled';
                        if (in_array($pStatus, ['Settled', 'Resolved'], true)) {
                            $statusPill = 'served-personal';
                            $statusLabel = 'Settled';
                        } elseif (in_array($pStatus, ['Escalated', 'Impasse_Escalate'], true)) {
                            $statusPill = 'refused';
                            $statusLabel = 'Escalated';
                        } elseif ($pStatus === 'Dismissed') {
                            $statusPill = 'unserved';
                            $statusLabel = 'Dismissed';
                        }

                        $previewUrl = !empty($pSess['pdf_path']) ? ('../../../' . ltrim($pSess['pdf_path'], '/')) : null;

                        $paanyayaItems[] = [
                            'delivery_id' => (int) $pSess['session_id'],
                            'hearing_id' => null,
                            'document_id' => null,
                            'stage' => 'Paanyaya',
                            'stage_index' => 0,
                            'document_code' => 'Paanyaya',
                            'document_title' => 'Paanyaya Invitation Letter',
                            'party_type' => 'Invitee',
                            'recipient_name' => $pSess['invitee_name'] ?: $defaultInviteeName,
                            'attempt_number' => (int) ($pSess['attempt_number'] ?: 1),
                            'attempt_label' => 'Dialogue #' . ((int) ($pSess['attempt_number'] ?: 1)),
                            'delivery_status' => $statusLabel,
                            'status_pill' => $statusPill,
                            'unserved_reason' => null,
                            'served_by_name' => 'Barangay Office',
                            'service_datetime' => $pSess['scheduled_date'] . ' ' . $pSess['scheduled_time'],
                            'service_method' => 'Invitation Delivery',
                            'proof_file_path' => null,
                            'remarks' => $pSess['outcome_notes'] ?: ($pSess['session_remarks'] ?: 'Paanyaya Informal Dialogue Invitation'),
                            'preview_url' => $previewUrl
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Timeline Paanyaya Error: ' . $e->getMessage());
        }

        // Build 3 stages
        $stages = [
            [
                'stage_id' => 0,
                'stage_key' => 'paanyaya',
                'stage_name' => 'Stage 0: Paanyaya',
                'stage_title' => 'Pre-Docketed Informal Dialogue (Paanyaya)',
                'badge' => (!empty($paanyayaItems) || in_array($case['case_status'], ['Mediation', 'Conciliation', 'Settled', 'Arbitration', 'CFA Issued'], true)) ? 'Concluded' : 'Pending',
                'items' => $paanyayaItems
            ],
            [
                'stage_id' => 1,
                'stage_key' => 'mediation',
                'stage_name' => 'Stage 1: Mediation',
                'stage_title' => 'Punong Barangay Mediation (KP Form 8 & 9)',
                'badge' => in_array($case['case_status'], ['Conciliation', 'Settled', 'Arbitration', 'Dismissed'], true) ? 'Concluded' : 'In Progress',
                'items' => $mediationItems
            ],
            [
                'stage_id' => 2,
                'stage_key' => 'conciliation',
                'stage_name' => 'Stage 2: Conciliation',
                'stage_title' => 'Pangkat ng Tagapagkasundo Conciliation (KP Form 10, 11 & 12)',
                'badge' => ($case['case_status'] === 'Conciliation') ? 'In Progress' : (in_array($case['case_status'], ['Settled', 'Arbitration', 'CFA Issued'], true) ? 'Completed' : 'Pending'),
                'items' => $conciliationItems
            ]
        ];

        return [
            'success' => true,
            'case_id' => $caseId,
            'case_number' => $case['case_number'],
            'case_status' => $case['case_status'],
            'stages' => $stages
        ];
    }
}
