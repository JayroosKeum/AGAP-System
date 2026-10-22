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

        $stmt = $db->prepare("
            SELECT hearing_id, hearing_type, hearing_date, venue, status
            FROM hearings
            WHERE case_id = ?
            ORDER BY hearing_date DESC, hearing_id DESC
        ");
        $stmt->execute([$caseId]);
        $hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($hearings)) {
            // Check if this caseId corresponds to an active Paanyaya preliminary stage
            require_once __DIR__ . '/../models/Paanyaya.php';
            $compStmt = $db->prepare("
                SELECT co.complaint_id, co.complaint_number, co.complaint_title, co.status AS complaint_status,
                       c.case_id, c.case_number
                FROM complaints co
                LEFT JOIN cases c ON c.complaint_id = co.complaint_id
                WHERE co.complaint_id = ? OR c.case_id = ?
                LIMIT 1
            ");
            $compStmt->execute([$caseId, $caseId]);
            $compRow = $compStmt->fetch(PDO::FETCH_ASSOC);

            if ($compRow) {
                $complaintId = (int) $compRow['complaint_id'];
                $paaModel = new Paanyaya($db);
                $twoStages = $paaModel->getTwoStagesData($complaintId);
                
                $activeStageKey = ($twoStages['stage_2']['details']['status'] !== 'Not Started') ? 'stage_2' : 'stage_1';
                $activeStage = $twoStages[$activeStageKey];
                $stageDetails = $activeStage['details'];
                $stageAttempts = $activeStage['attempts'];

                // Get party details
                $partiesStmt = $db->prepare("
                    SELECT cp.party_type, cp.resident_id, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name,
                           r.address, r.purok, r.contact_no
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = ?
                ");
                $partiesStmt->execute([$complaintId]);
                $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

                $compName = 'Complainant';
                $respName = 'Respondent';
                $respResidentId = 0;
                $respAddress = '';
                foreach ($parties as $p) {
                    if ($p['party_type'] === 'Complainant' && $compName === 'Complainant') {
                        $compName = $p['full_name'];
                    } elseif ($p['party_type'] === 'Respondent' && $respName === 'Respondent') {
                        $respName = $p['full_name'];
                        $respResidentId = (int)$p['resident_id'];
                        $respAddress = $p['address'] ?: ($p['purok'] ?: 'Barangay Tumana');
                    }
                }

                $respondentAttempts = [];
                $failedCount = 0;
                foreach ($stageAttempts as $att) {
                    $isServed = ($att['delivery_outcome'] === 'Successfully delivered');
                    if (!$isServed) $failedCount++;
                    $respondentAttempts[] = [
                        'attempt_number' => (int)$att['attempt_number'],
                        'delivery_status' => $isServed ? 'Served' : 'Unserved',
                        'is_served' => $isServed,
                        'is_failed' => !$isServed,
                        'is_pending' => false,
                        'unserved_reason' => $att['unsuccessful_reason'] ?: ($isServed ? '' : ($att['delivery_outcome'] ?: 'Unserved')),
                        'attempted_at' => $att['service_datetime'] ?? $att['created_at'],
                        'served_at' => $att['service_datetime'],
                        'recipient_name' => $att['recipient_name'] ?: $respName,
                        'remarks' => $att['remarks'],
                        'delivery_id' => (int)($att['return_id'] ?? $att['id']),
                        'resident_id' => $respResidentId,
                        'party_profile' => [
                            'resident_id' => $respResidentId,
                            'resident_name' => $respName,
                            'address' => $respAddress
                        ]
                    ];
                }

                $anyServed = $activeStage['is_delivered'];
                $attemptsCount = count($respondentAttempts);
                if (!$anyServed && $attemptsCount < 6 && $stageDetails['status'] === 'In Progress') {
                    // Append next pending attempt so the officer can click to record return
                    $respondentAttempts[] = [
                        'attempt_number' => $attemptsCount + 1,
                        'delivery_status' => 'Pending Service',
                        'is_served' => false,
                        'is_failed' => false,
                        'is_pending' => true,
                        'unserved_reason' => 'Pending Service',
                        'attempted_at' => null,
                        'served_at' => null,
                        'recipient_name' => $respName,
                        'remarks' => '',
                        'delivery_id' => null,
                        'resident_id' => $respResidentId,
                        'party_profile' => [
                            'resident_id' => $respResidentId,
                            'resident_name' => $respName,
                            'address' => $respAddress
                        ]
                    ];
                }

                $invDate = $stageDetails['invitation_date'] ? ($stageDetails['invitation_date'] . ' ' . ($stageDetails['invitation_time'] ?: '14:00:00')) : date('Y-m-d H:i:s');

                $hearingsWithDeliveries = [
                    [
                        'hearing_id' => (int)$stageDetails['id'],
                        'hearing_type' => $stageDetails['stage_type'],
                        'hearing_date' => $invDate,
                        'venue' => $stageDetails['venue'] ?: 'Barangay Tumana Multi-Purpose Hall',
                        'status' => $stageDetails['status'],
                        'is_paanyaya' => true,
                        'stage_type' => $stageDetails['stage_type'],
                        'complaint_id' => $complaintId,
                        'can_initiate_next_attempt' => false,
                        'next_attempt_number' => min(6, $attemptsCount + 1),
                        'deliveries' => [
                            [
                                'party_type' => 'Complainant',
                                'recipient_name' => $compName,
                                'delivery_status' => 'Served',
                                'remarks' => 'Informal preliminary dialogue notice (Personal)'
                            ],
                            [
                                'party_type' => 'Respondent',
                                'resident_id' => $respResidentId,
                                'recipient_name' => $respName,
                                'delivery_status' => $anyServed ? 'Served' : ($attemptsCount > 0 ? 'Unserved' : 'Pending Service'),
                                'remarks' => $activeStage['latest_attempt']['remarks'] ?? ''
                            ]
                        ],
                        'respondent_attempts' => $respondentAttempts,
                        'cfa_eligibility' => [
                            'eligible' => false,
                            'failed_count' => $failedCount,
                            'attempts_count' => count($stageAttempts),
                            'reason' => 'Paanyaya preliminary stage (Summons unlocked after service or 6 attempts).'
                        ]
                    ]
                ];

                return [
                    'success' => true,
                    'data' => [
                        'hearings' => $hearingsWithDeliveries,
                        'has_hearings' => true,
                        'is_paanyaya' => true
                    ]
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'hearings' => [],
                    'has_hearings' => false,
                    'message' => 'No hearings scheduled yet for this case. Issue a summons to schedule 1st Mediation.'
                ]
            ];
        }

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
                    'deliveries' => $delivResult['deliveries'],
                    'respondent_attempts' => $delivResult['respondent_attempts'] ?? [],
                    'can_initiate_next_attempt' => $delivResult['can_initiate_next_attempt'] ?? false,
                    'next_attempt_number' => $delivResult['next_attempt_number'] ?? null,
                    'cfa_eligibility' => $delivResult['cfa_eligibility'] ?? null,
                    'attendance_unlocked' => $delivResult['attendance_unlocked'],
                    'service_notice' => $delivResult['service_notice'],
                ];
            }
        }

        $cfaEligibility = $deliveryService->getCfaEligibility($caseId);

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

        // Check whether this return belongs to a formal summons delivery (summon_deliveries) or formal hearing (hearings)
        $isFormal = false;
        if ($deliveryId) {
            $chkDeliv = $db->prepare("SELECT delivery_id, hearing_id, case_id FROM summon_deliveries WHERE delivery_id = ?");
            $chkDeliv->execute([$deliveryId]);
            $delivRow = $chkDeliv->fetch(PDO::FETCH_ASSOC);
            if ($delivRow) {
                $isFormal = true;
                if (!$hearingId && !empty($delivRow['hearing_id'])) {
                    $hearingId = (int)$delivRow['hearing_id'];
                }
                if (!$caseId && !empty($delivRow['case_id'])) {
                    $caseId = (int)$delivRow['case_id'];
                }
            }
        }

        if (!$isFormal && $hearingId) {
            $chkH = $db->prepare("SELECT hearing_id, case_id FROM hearings WHERE hearing_id = ?");
            $chkH->execute([$hearingId]);
            $hRow = $chkH->fetch(PDO::FETCH_ASSOC);
            if ($hRow) {
                $isFormal = true;
                if (!$caseId && !empty($hRow['case_id'])) {
                    $caseId = (int)$hRow['case_id'];
                }
            }
        }

        if (!$isFormal && $caseId) {
            $chkHC = $db->prepare("SELECT COUNT(*) FROM hearings WHERE case_id = ?");
            $chkHC->execute([$caseId]);
            if ((int)$chkHC->fetchColumn() > 0) {
                $isFormal = true;
            }
        }

        // Only evaluate as Paanyaya if this is strictly not a formal summons delivery or formal hearing
        $isPaanyaya = false;
        $paaStage = null;
        if (!$isFormal) {
            if (!empty($input['is_paanyaya'])) {
                $isPaanyaya = true;
            }
            if (!$isPaanyaya && $hearingId) {
                $chkStage = $db->prepare("SELECT * FROM paanyaya_stages WHERE id = ?");
                $chkStage->execute([$hearingId]);
                $paaStage = $chkStage->fetch(PDO::FETCH_ASSOC);
                if ($paaStage) $isPaanyaya = true;
            }
            if (!$isPaanyaya && $caseId) {
                $chkStage = $db->prepare("SELECT * FROM paanyaya_stages WHERE complaint_id = ? ORDER BY id DESC LIMIT 1");
                $chkStage->execute([$caseId]);
                $paaStage = $chkStage->fetch(PDO::FETCH_ASSOC);
                if ($paaStage) $isPaanyaya = true;
            }
        }

        if ($isPaanyaya) {
            $complaintId = (int)($paaStage['complaint_id'] ?? $input['complaint_id'] ?? $caseId);
            $stageType = $paaStage['stage_type'] ?? ($input['stage_type'] ?? 'Paanyaya 1');

            // Map delivery status to Paanyaya outcome
            $deliveryStatus = $input['delivery_status'] ?? 'Served Personal';
            if (in_array($deliveryStatus, ['Served Personal', 'Served Substituted', 'Served Refused'], true)) {
                $deliveryOutcome = 'Successfully delivered';
            } else {
                $unservedReason = $input['unserved_reason'] ?? '';
                if ($unservedReason === 'No One Home') {
                    $deliveryOutcome = 'Recipient unavailable';
                } elseif ($unservedReason === 'Refused') {
                    $deliveryOutcome = 'Recipient refused to receive';
                } elseif ($unservedReason === 'Moved Out') {
                    $deliveryOutcome = 'Respondent not found at the address';
                } elseif ($unservedReason === 'Wrong Address') {
                    $deliveryOutcome = 'Incorrect or incomplete address';
                } else {
                    $deliveryOutcome = 'Other reason';
                }
            }

            // Upload proof image if provided
            $imagePath = null;
            if (!empty($file['tmp_name'])) {
                $upload = $this->storeImage($file);
                if ($upload['success']) {
                    $imagePath = $upload['path'];
                }
            }

            // Fetch respondent address if not provided
            $serviceAddress = trim((string)($input['service_address'] ?? ''));
            if (empty($serviceAddress)) {
                $addrStmt = $db->prepare("
                    SELECT r.address, r.purok 
                    FROM complaint_parties cp 
                    INNER JOIN residents r ON r.resident_id = cp.resident_id 
                    WHERE cp.complaint_id = ? AND cp.party_type = 'Respondent' 
                    LIMIT 1
                ");
                $addrStmt->execute([$complaintId]);
                $resRow = $addrStmt->fetch(PDO::FETCH_ASSOC);
                $serviceAddress = $resRow['address'] ?: ($resRow['purok'] ?: 'Barangay Tumana');
            }

            $paaModel = new Paanyaya($db);
            $returnId = filter_var($input['delivery_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

            if ($returnId) {
                // Update existing Officer's Return (correction workflow)
                $updatePayload = [
                    'service_datetime' => !empty($input['served_at']) ? date('Y-m-d H:i:s', strtotime($input['served_at'])) : date('Y-m-d H:i:s'),
                    'recipient_name' => trim((string)($input['recipient_name'] ?? 'Respondent')),
                    'service_address' => $serviceAddress,
                    'delivery_outcome' => $deliveryOutcome,
                    'unsuccessful_reason' => trim((string)($input['failure_notes'] ?? ($input['unserved_reason'] ?? ''))),
                    'remarks' => trim((string)($input['remarks'] ?? '')),
                    'correction_notes' => 'Updated via Proof of Service'
                ];
                if ($imagePath) {
                    $updatePayload['proof_file_path'] = $imagePath;
                }
                $updRes = $paaModel->updateOfficerReturn($returnId, $updatePayload, $userId);
                if (!$updRes['success']) {
                    return $updRes;
                }

                $stagesData = $paaModel->getTwoStagesData($complaintId);
                $targetStage = ($stageType === 'Paanyaya 2') ? $stagesData['stage_2'] : $stagesData['stage_1'];
                $isExhausted = $targetStage['is_exhausted'] || ($targetStage['attempts_count'] >= 6 && !$targetStage['is_delivered']);

                return [
                    'success' => true,
                    'message' => "Officer's Return updated successfully for {$stageType}.",
                    'delivery_id' => $returnId,
                    'max_attempts_reached' => $isExhausted,
                    'escalation_prompt' => $isExhausted ? "6 Paanyaya delivery attempts completed without service. Issue Summon 1st Attempt is now unlocked." : null
                ];
            }

            $retResult = $paaModel->recordOfficerReturn($complaintId, $stageType, [
                'service_datetime' => !empty($input['served_at']) ? date('Y-m-d H:i:s', strtotime($input['served_at'])) : date('Y-m-d H:i:s'),
                'serving_officer_id' => $userId,
                'recipient_name' => trim((string)($input['recipient_name'] ?? 'Respondent')),
                'service_address' => $serviceAddress,
                'delivery_outcome' => $deliveryOutcome,
                'unsuccessful_reason' => trim((string)($input['failure_notes'] ?? ($input['unserved_reason'] ?? ''))),
                'remarks' => trim((string)($input['remarks'] ?? '')),
                'latitude' => $input['latitude'] ?? null,
                'longitude' => $input['longitude'] ?? null,
                'proof_file_path' => $imagePath
            ], $userId);

            if (!$retResult['success']) {
                return $retResult;
            }

            $stagesData = $paaModel->getTwoStagesData($complaintId);
            $targetStage = ($stageType === 'Paanyaya 2') ? $stagesData['stage_2'] : $stagesData['stage_1'];
            $isExhausted = $targetStage['is_exhausted'] || ($targetStage['attempts_count'] >= 6 && !$targetStage['is_delivered']);

            return [
                'success' => true,
                'message' => "Officer's Return recorded for {$stageType} (Attempt {$retResult['attempt_number']} of 6).",
                'delivery_id' => $retResult['return_id'] ?? null,
                'attempt_number' => $retResult['attempt_number'],
                'max_attempts_reached' => $isExhausted,
                'escalation_prompt' => $isExhausted ? "6 Paanyaya delivery attempts completed without service. Issue Summon 1st Attempt is now unlocked." : null
            ];
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

    public function initiateNextAttempt(int $caseId, int $userId, ?int $hearingId = null): array
    {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();
        $deliveryService = new SummonDeliveryService($db);
        return $deliveryService->initiateNextAttempt($caseId, $userId, $hearingId);
    }

    public function markKp8Served(int $caseId, int $documentId, ?int $hearingId, int $userId): array
    {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();
        $deliveryService = new SummonDeliveryService($db);
        return $deliveryService->markKp8Printed($caseId, $documentId, $hearingId, $userId);
    }

    public function cfaEligibility(int $caseId): array
    {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();
        $deliveryService = new SummonDeliveryService($db);
        return [
            'success' => true,
            'data' => $deliveryService->getCfaEligibility($caseId)
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
}
