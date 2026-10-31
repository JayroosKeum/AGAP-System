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

        $requestedStage = trim((string)($_GET['stage'] ?? $_GET['stage_type'] ?? ''));
        $requestedHearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $hearings = [];

        if (!$requestedStage) {
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
                    'deliveries' => $delivResult['deliveries'],
                    'complainant_delivery' => $delivResult['complainant_delivery'] ?? null,
                    'complainant_attempts' => $delivResult['complainant_attempts'] ?? [],
                    'latest_complainant_attempt' => $delivResult['latest_complainant_attempt'] ?? null,
                    'can_initiate_next_comp_attempt' => $delivResult['can_initiate_next_comp_attempt'] ?? false,
                    'next_comp_attempt_number' => $delivResult['next_comp_attempt_number'] ?? null,
                    'respondent_attempts' => $delivResult['respondent_attempts'] ?? [],
                    'can_initiate_next_attempt' => $delivResult['can_initiate_next_attempt'] ?? false,
                    'next_attempt_number' => $delivResult['next_attempt_number'] ?? null,
                    'cfa_eligibility' => $delivResult['cfa_eligibility'] ?? null,
                    'attendance_unlocked' => $delivResult['attendance_unlocked'],
                    'service_notice' => $delivResult['service_notice'],
                ];
            }
        }

        $primaryHearingId = !empty($hearingsWithDeliveries[0]['hearing_id']) ? (int) $hearingsWithDeliveries[0]['hearing_id'] : null;
        $cfaEligibility = $deliveryService->getCfaEligibility($caseId, $primaryHearingId);

        return [
            'success' => true,
            'data' => [
                'hearings' => $hearingsWithDeliveries,
                'has_hearings' => true,
                'cfa_eligibility' => $cfaEligibility,
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

        require_once __DIR__ . '/../models/Setting.php';
        $maxSummons = (int) Setting::getSetting('summons_max_attempts', 6, $db);
        $deliveryService = new SummonDeliveryService($db);
        $result = $deliveryService->recordDelivery($hearingId, $input, $userId);

        if ($result['success'] && $imagePath) {
            $targetDeliveryId = !empty($result['delivery_id']) ? (int) $result['delivery_id'] : $deliveryId;
            if ($targetDeliveryId) {
                $updDeliv = $db->prepare("UPDATE summon_deliveries SET proof_file_path = ? WHERE delivery_id = ?");
                $updDeliv->execute([$imagePath, $targetDeliveryId]);
            }
            if ($caseId) {
                $upd = $db->prepare("UPDATE proof_of_service SET image_path = ? WHERE case_id = ? ORDER BY proof_id DESC LIMIT 1");
                $upd->execute([$imagePath, $caseId]);
            }
        }

        return $result;
    }

    public function initiateNextAttempt(int $caseId, int $userId, ?int $hearingId = null, string $partyType = 'Respondent'): array
    {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();
        require_once __DIR__ . '/../models/Setting.php';
        $maxSummons = (int) Setting::getSetting('summons_max_attempts', 6, $db);
        $deliveryService = new SummonDeliveryService($db);
        return $deliveryService->initiateNextAttempt($caseId, $userId, $hearingId, $partyType);
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
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();
        require_once __DIR__ . '/../models/Setting.php';
        $maxSummons = (int) Setting::getSetting('summons_max_attempts', 6, $db);
        $deliveryService = new SummonDeliveryService($db);
        return [
            'success' => true,
            'data' => $deliveryService->getCfaEligibility($caseId, filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT) ?: null)
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

        // Build 3 stages
        $stages = [
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
