<?php
$file = __DIR__ . '/../backend/controllers/GPSController.php';
$content = file_get_contents($file);

$searchDeliveries = <<<'CODE'
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
CODE;

$replaceDeliveries = <<<'CODE'
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
CODE;

$searchReturn = <<<'CODE'
    public function recordOfficerReturn(array $input, array $file, int $userId): array
    {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();

        $hearingId = filter_var($input['hearing_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
CODE;

$replaceReturn = <<<'CODE'
    public function recordOfficerReturn(array $input, array $file, int $userId): array
    {
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../models/Paanyaya.php';
        $db = (new Database())->connect();

        $hearingId = filter_var($input['hearing_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

        // Check if this return belongs to a Paanyaya stage
        $isPaanyaya = !empty($input['is_paanyaya']);
        $paaStage = null;
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
            if ($paaStage) {
                // Ensure no scheduled formal hearings exist for this case
                $hCount = (int)$db->query("SELECT COUNT(*) FROM hearings WHERE case_id = " . (int)$caseId . " AND status = 'Scheduled'")->fetchColumn();
                if ($hCount === 0) $isPaanyaya = true;
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
CODE;

// Normalize line endings for replacement
$content = str_replace(str_replace("\r\n", "\n", $searchDeliveries), str_replace("\r\n", "\n", $replaceDeliveries), str_replace("\r\n", "\n", $content));
$content = str_replace(str_replace("\r\n", "\n", $searchReturn), str_replace("\r\n", "\n", $replaceReturn), str_replace("\r\n", "\n", $content));

file_put_contents($file, $content);
echo "GPSController.php patched successfully.\n";
