<?php
$file = __DIR__ . '/../backend/models/Paanyaya.php';
$content = file_get_contents($file);

$target = <<<'PHP'
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
        ];
    }
}
PHP;

$replacement = <<<'PHP'
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
            WHERE u.is_active = 1 AND u.role_id IN (1, 2, 4)
            ORDER BY u.role_id DESC, u.last_name ASC
        ");
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
                   TRIM(CONCAT_WS(' ', u_off.first_name, u_off.middle_name, u_off.last_name)) AS assigned_officer_name,
                   TRIM(CONCAT_WS(' ', u_rev.first_name, u_rev.middle_name, u_rev.last_name)) AS reviewed_by_name
            FROM paanyaya_stages ps
            LEFT JOIN users u_iss ON u_iss.user_id = ps.issued_by
            LEFT JOIN users u_off ON u_off.user_id = ps.assigned_officer_id
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
        $stmt = $this->conn->prepare("
            SELECT por.*,
                   TRIM(CONCAT_WS(' ', u_off.first_name, u_off.middle_name, u_off.last_name)) AS serving_officer_name,
                   TRIM(CONCAT_WS(' ', u_rec.first_name, u_rec.middle_name, u_rec.last_name)) AS recorded_by_name
            FROM paanyaya_officer_returns por
            LEFT JOIN users u_off ON u_off.user_id = por.serving_officer_id
            LEFT JOIN users u_rec ON u_rec.user_id = por.recorded_by
            WHERE por.paanyaya_stage_id = ?
            ORDER BY por.attempt_number ASC, por.return_id ASC
        ");
        $stmt->execute([$paanyayaStageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Checks if the required Paanyaya stages have been satisfied to unlock the formal summons workflow.
     */
    public function checkSummonsPrerequisite(int $complaintId): array
    {
        $stages = $this->conn->prepare("SELECT * FROM paanyaya_stages WHERE complaint_id = ?");
        $stages->execute([$complaintId]);
        $rows = $stages->fetchAll(PDO::FETCH_ASSOC);

        $stage1 = null;
        $stage2 = null;
        foreach ($rows as $r) {
            if ($r['stage_type'] === self::STAGE_1) $stage1 = $r;
            if ($r['stage_type'] === self::STAGE_2) $stage2 = $r;
        }

        // If Paanyaya 1 doesn't exist or is Not Started
        if (!$stage1 || $stage1['status'] === 'Not Started') {
            return [
                'allowed' => false,
                'reason' => 'paanyaya_1_not_started',
                'message' => 'Complete the required Paanyaya process before issuing the first formal summons.'
            ];
        }

        // Check if explicitly approved by authorized review
        if ($stage1['review_status'] === 'Approved for Summons' || ($stage2 && $stage2['review_status'] === 'Approved for Summons')) {
            return [
                'allowed' => true,
                'message' => 'Formal summons unlocked via authorized Lupon review approval.'
            ];
        }

        // Check attempts for Stage 1
        $countStmt = $this->conn->prepare("
            SELECT COUNT(*) AS total_count,
                   SUM(CASE WHEN delivery_outcome = 'Successfully delivered' THEN 1 ELSE 0 END) AS delivered_count
            FROM paanyaya_officer_returns
            WHERE paanyaya_stage_id = ?
        ");
        $countStmt->execute([(int)$stage1['id']]);
        $stats1 = $countStmt->fetch(PDO::FETCH_ASSOC);
        $attemptsCount1 = (int)($stats1['total_count'] ?? 0);
        $isDelivered1 = (int)($stats1['delivered_count'] ?? 0) > 0 || $stage1['status'] === 'Successfully Delivered';

        if ($stage1['status'] === 'In Progress' && !$isDelivered1) {
            return [
                'allowed' => false,
                'reason' => 'paanyaya_1_in_progress',
                'message' => 'Complete the required Paanyaya process before issuing the first formal summons.'
            ];
        }

        if ($stage1['status'] === 'Attempts Exhausted – For Review' && !$isDelivered1) {
            return [
                'allowed' => false,
                'reason' => 'paanyaya_1_exhausted_pending_review',
                'message' => 'All 6 Paanyaya 1 delivery attempts exhausted. Authorized review is required before advancing.'
            ];
        }

        // If Paanyaya 2 was initiated, check Paanyaya 2
        if ($stage2 && $stage2['status'] !== 'Not Started') {
            $countStmt->execute([(int)$stage2['id']]);
            $stats2 = $countStmt->fetch(PDO::FETCH_ASSOC);
            $attemptsCount2 = (int)($stats2['total_count'] ?? 0);
            $isDelivered2 = (int)($stats2['delivered_count'] ?? 0) > 0 || $stage2['status'] === 'Successfully Delivered';

            if ($stage2['review_status'] === 'Approved for Summons') {
                return [
                    'allowed' => true,
                    'message' => 'Formal summons unlocked via authorized Lupon review approval.'
                ];
            }

            if ($stage2['status'] === 'In Progress' && !$isDelivered2) {
                return [
                    'allowed' => false,
                    'reason' => 'paanyaya_2_in_progress',
                    'message' => 'Complete the required Paanyaya 2 process before issuing the first formal summons.'
                ];
            }

            if ($stage2['status'] === 'Attempts Exhausted – For Review' && !$isDelivered2) {
                return [
                    'allowed' => false,
                    'reason' => 'paanyaya_2_exhausted_pending_review',
                    'message' => 'All 6 Paanyaya 2 delivery attempts exhausted. Authorized review is required before advancing.'
                ];
            }

            if ($isDelivered2 || $stage2['status'] === 'Completed') {
                return [
                    'allowed' => true,
                    'message' => 'Paanyaya 2 successfully completed. Formal summons unlocked.'
                ];
            }
        }

        // If Paanyaya 1 was successfully delivered or completed
        if ($isDelivered1 || $stage1['status'] === 'Completed') {
            return [
                'allowed' => true,
                'message' => 'Paanyaya 1 successfully completed. Formal summons unlocked.'
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'paanyaya_incomplete',
            'message' => 'Complete the required Paanyaya process before issuing the first formal summons.'
        ];
    }

    /**
     * Gets complete Paanyaya workflow status for both stages.
     */
    public function getTwoStagesData(int $complaintId): array
    {
        $stage1 = $this->getOrCreateStage($complaintId, self::STAGE_1);
        $stage2 = $this->getOrCreateStage($complaintId, self::STAGE_2);

        $attempts1 = $this->getStageAttempts((int)$stage1['id']);
        $attempts2 = $this->getStageAttempts((int)$stage2['id']);

        $count1 = count($attempts1);
        $count2 = count($attempts2);

        $latest1 = !empty($attempts1) ? end($attempts1) : null;
        $latest2 = !empty($attempts2) ? end($attempts2) : null;

        $isDelivered1 = ($stage1['status'] === 'Successfully Delivered') || ($latest1 && $latest1['delivery_outcome'] === 'Successfully delivered');
        $isDelivered2 = ($stage2['status'] === 'Successfully Delivered') || ($latest2 && $latest2['delivery_outcome'] === 'Successfully delivered');

        $isExhausted1 = ($count1 >= self::MAX_STAGE_ATTEMPTS && !$isDelivered1);
        $isExhausted2 = ($count2 >= self::MAX_STAGE_ATTEMPTS && !$isDelivered2);

        $isCompleted1 = $isDelivered1 ||
                        $stage1['status'] === 'Completed' ||
                        in_array($stage1['review_status'], ['Approved for Paanyaya 2', 'Approved for Summons', 'Case Closed'], true);

        $isEligible2 = $isCompleted1;

        $canRecordAttempt1 = ($stage1['status'] === 'In Progress') && ($count1 < self::MAX_STAGE_ATTEMPTS) && !$isDelivered1;
        $canRecordAttempt2 = ($stage2['status'] === 'In Progress') && ($count2 < self::MAX_STAGE_ATTEMPTS) && !$isDelivered2;

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
                'needs_review' => ($stage1['status'] === 'Attempts Exhausted – For Review' || ($isExhausted1 && $stage1['review_status'] === 'None')),
            ],
            'stage_2' => [
                'details' => $stage2,
                'attempts' => $attempts2,
                'attempts_count' => $count2,
                'max_attempts' => self::MAX_STAGE_ATTEMPTS,
                'next_attempt_number' => min(self::MAX_STAGE_ATTEMPTS, $count2 + 1),
                'latest_attempt' => $latest2,
                'is_eligible' => $isEligible2,
                'is_delivered' => $isDelivered2,
                'is_exhausted' => $isExhausted2,
                'is_completed' => ($isDelivered2 || $stage2['status'] === 'Completed' || in_array($stage2['review_status'], ['Approved for Summons', 'Case Closed'], true)),
                'can_issue' => ($isEligible2 && $stage2['status'] === 'Not Started'),
                'can_record_attempt' => $canRecordAttempt2,
                'needs_review' => ($stage2['status'] === 'Attempts Exhausted – For Review' || ($isExhausted2 && $stage2['review_status'] === 'None')),
            ],
            'summons_prerequisite' => $prereq,
            'serving_officers' => $this->getServingOfficers()
        ];
    }

    /**
     * Issues Paanyaya 1 or Paanyaya 2 preliminary invitation.
     */
    public function issueStage(int $complaintId, string $stageType, array $data, ?int $userId = null): array
    {
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            return ['success' => false, 'message' => 'Invalid Paanyaya stage specified.'];
        }

        $cStmt = $this->conn->prepare('SELECT complaint_id, complaint_number, complaint_title, narrative, status FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        $stage = $this->getOrCreateStage($complaintId, $stageType);

        if ($stageType === self::STAGE_2) {
            $stage1 = $this->getOrCreateStage($complaintId, self::STAGE_1);
            $stage1Attempts = $this->getStageAttempts((int)$stage1['id']);
            $delivered1 = ($stage1['status'] === 'Successfully Delivered') ||
                          (!empty($stage1Attempts) && end($stage1Attempts)['delivery_outcome'] === 'Successfully delivered');
            $approved1 = in_array($stage1['review_status'], ['Approved for Paanyaya 2', 'Approved for Summons'], true);

            if (!$delivered1 && !$approved1 && $stage1['status'] !== 'Completed') {
                return [
                    'success' => false,
                    'message' => 'Paanyaya 2 cannot be issued until Paanyaya 1 is completed or authorized by review.'
                ];
            }
        }

        $invitationDate = trim((string) ($data['invitation_date'] ?? ''));
        $invitationTime = trim((string) ($data['invitation_time'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? 'Tanggapan ng Barangay Tumana'));
        $salaysay = trim((string) ($data['salaysay'] ?? ''));
        $hasESignature = isset($data['has_e_signature']) ? (int) ((bool) $data['has_e_signature']) : 1;
        $assignedOfficerId = !empty($data['assigned_officer_id']) ? (int) $data['assigned_officer_id'] : null;

        if ($invitationDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invitationDate)) {
            $invitationDate = date('Y-m-d', strtotime('+3 days'));
        }

        if ($invitationTime === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $invitationTime)) {
            $invitationTime = '14:00';
        }

        if (empty($salaysay)) {
            $salaysay = $complaint['narrative'] ?: 'naisin nilang magkausap at magkaliwanagan.';
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
                'invitation_date' => $invitationDate,
                'invitation_time' => $invitationTime,
                'venue' => $venue,
                'salaysay' => $salaysay,
                'has_e_signature' => $hasESignature,
            ]);

            $upd = $this->conn->prepare("
                UPDATE paanyaya_stages
                SET status = 'In Progress',
                    issued_at = CURRENT_TIMESTAMP,
                    issued_by = ?,
                    assigned_officer_id = ?,
                    invitation_date = ?,
                    invitation_time = ?,
                    venue = ?,
                    salaysay = ?,
                    has_e_signature = ?,
                    pdf_path = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $upd->execute([
                $userId,
                $assignedOfficerId,
                $invitationDate,
                $invitationTime,
                $venue,
                $salaysay,
                $hasESignature,
                $pdfRelativePath,
                (int)$stage['id']
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
                $this->audit->log($userId, "Issued {$stageType} (Preliminary Invitation)", 'Paanyaya', $complaintId);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'stage_type' => $stageType,
                'stage_id' => (int)$stage['id'],
                'pdf_path' => $pdfRelativePath,
                'message' => "{$stageType} successfully issued. You may now record delivery attempts via Officer's Return."
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
     * Records an Officer's Return attempt for Paanyaya 1 or Paanyaya 2.
     * Enforces up to 6 delivery attempts with independent attempt counter per stage.
     */
    public function recordOfficerReturn(int $complaintId, string $stageType, array $data, ?int $userId = null, ?array $proofFile = null): array
    {
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            return ['success' => false, 'message' => 'Invalid Paanyaya stage specified.'];
        }

        $stage = $this->getOrCreateStage($complaintId, $stageType);
        $stageId = (int) $stage['id'];

        if ($stage['status'] === 'Not Started') {
            return [
                'success' => false,
                'message' => "Cannot record an Officer's Return because {$stageType} has not been issued yet. Please issue the invitation first."
            ];
        }

        $countStmt = $this->conn->prepare("SELECT COUNT(*) FROM paanyaya_officer_returns WHERE paanyaya_stage_id = ?");
        $countStmt->execute([$stageId]);
        $existingCount = (int) $countStmt->fetchColumn();

        if ($existingCount >= self::MAX_STAGE_ATTEMPTS) {
            return [
                'success' => false,
                'message' => "Maximum 6 delivery attempts have already been recorded for {$stageType}. No further attempts may be added."
            ];
        }

        $attemptNumber = $existingCount + 1;

        $serviceDatetime = trim((string) ($data['service_datetime'] ?? ''));
        if ($serviceDatetime === '') {
            $serviceDatetime = date('Y-m-d H:i:s');
        } else {
            $ts = strtotime($serviceDatetime);
            if ($ts === false) {
                return ['success' => false, 'message' => 'Invalid date and time for service attempt.'];
            }
            $serviceDatetime = date('Y-m-d H:i:s', $ts);
        }

        $servingOfficerId = !empty($data['serving_officer_id']) ? (int) $data['serving_officer_id'] : ($stage['assigned_officer_id'] ?: $userId);
        if (!$servingOfficerId) {
            return ['success' => false, 'message' => 'Please select an assigned serving officer.'];
        }

        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        if ($recipientName === '') {
            return ['success' => false, 'message' => 'Respondent or intended recipient name is required.'];
        }

        $serviceAddress = trim((string) ($data['service_address'] ?? ''));
        if ($serviceAddress === '') {
            return ['success' => false, 'message' => 'Address visited is required.'];
        }

        $deliveryOutcome = trim((string) ($data['delivery_outcome'] ?? ''));
        if (!in_array($deliveryOutcome, self::VALID_DELIVERY_OUTCOMES, true)) {
            return [
                'success' => false,
                'message' => 'Invalid delivery outcome selected. Please choose a valid outcome.'
            ];
        }

        $unsuccessfulReason = trim((string) ($data['unsuccessful_reason'] ?? ''));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $latitude = !empty($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = !empty($data['longitude']) ? (float) $data['longitude'] : null;
        $serviceLocation = trim((string) ($data['service_location'] ?? ''));

        $proofFilePath = null;
        if (!empty($proofFile) && !empty($proofFile['tmp_name']) && is_uploaded_file($proofFile['tmp_name'])) {
            $ext = strtolower(pathinfo($proofFile['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
            if (!in_array($ext, $allowedExts, true)) {
                return ['success' => false, 'message' => 'Invalid proof file type. Allowed formats: JPG, PNG, WEBP, PDF.'];
            }
            if ($proofFile['size'] > 10 * 1024 * 1024) {
                return ['success' => false, 'message' => 'Proof file size exceeds 10MB limit.'];
            }

            $storageProofDir = __DIR__ . '/../../storage/paanyaya/proofs';
            if (!is_dir($storageProofDir)) {
                @mkdir($storageProofDir, 0777, true);
            }
            $stageSlug = ($stageType === self::STAGE_1) ? 'p1' : 'p2';
            $fileName = sprintf('proof_%d_%s_att%d_%s.%s', $complaintId, $stageSlug, $attemptNumber, date('Ymd_His'), $ext);
            $targetPath = $storageProofDir . '/' . $fileName;

            if (move_uploaded_file($proofFile['tmp_name'], $targetPath)) {
                $proofFilePath = 'storage/paanyaya/proofs/' . $fileName;
            }
        } elseif (!empty($data['proof_file_path'])) {
            $proofFilePath = trim((string)$data['proof_file_path']);
        }

        $isDelivered = ($deliveryOutcome === 'Successfully delivered');

        try {
            $this->conn->beginTransaction();

            $insStmt = $this->conn->prepare("
                INSERT INTO paanyaya_officer_returns (
                    paanyaya_stage_id, complaint_id, stage_type, attempt_number,
                    service_datetime, serving_officer_id, recipient_name, service_address,
                    delivery_outcome, unsuccessful_reason, remarks, proof_file_path,
                    latitude, longitude, service_location, recorded_by, submitted_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, CURRENT_TIMESTAMP
                )
            ");
            $insStmt->execute([
                $stageId,
                $complaintId,
                $stageType,
                $attemptNumber,
                $serviceDatetime,
                $servingOfficerId,
                $recipientName,
                $serviceAddress,
                $deliveryOutcome,
                $unsuccessfulReason ?: null,
                $remarks ?: null,
                $proofFilePath,
                $latitude,
                $longitude,
                $serviceLocation ?: null,
                $userId ?? 1
            ]);
            $returnId = (int) $this->conn->lastInsertId();

            if ($isDelivered) {
                $newStageStatus = 'Successfully Delivered';
            } elseif ($attemptNumber >= self::MAX_STAGE_ATTEMPTS) {
                $newStageStatus = 'Attempts Exhausted – For Review';
            } else {
                $newStageStatus = 'In Progress';
            }

            $updStage = $this->conn->prepare("
                UPDATE paanyaya_stages
                SET status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $updStage->execute([$newStageStatus, $stageId]);

            if ($userId) {
                $this->audit->log(
                    $userId,
                    "Recorded Officer's Return: {$stageType} Attempt {$attemptNumber}/6 — Outcome: {$deliveryOutcome}",
                    'Paanyaya',
                    $complaintId
                );
            }

            $this->conn->commit();

            return [
                'success' => true,
                'return_id' => $returnId,
                'stage_type' => $stageType,
                'attempt_number' => $attemptNumber,
                'delivery_outcome' => $deliveryOutcome,
                'stage_status' => $newStageStatus,
                'is_delivered' => $isDelivered,
                'is_exhausted' => ($attemptNumber >= self::MAX_STAGE_ATTEMPTS && !$isDelivered),
                'message' => $isDelivered
                    ? "Officer's Return recorded: {$stageType} successfully delivered!"
                    : ($attemptNumber >= self::MAX_STAGE_ATTEMPTS
                        ? "Officer's Return recorded. All 6 attempts for {$stageType} are exhausted. Case flagged for review."
                        : "Officer's Return recorded for Attempt {$attemptNumber} of 6.")
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Record Officer's Return error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error recording Officer's Return: " . $e->getMessage()];
        }
    }

    /**
     * Gets complete details of a single Officer's Return record.
     */
    public function getOfficerReturnDetails(int $returnId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT por.*,
                   c.complaint_number, c.complaint_title,
                   TRIM(CONCAT_WS(' ', u_off.first_name, u_off.middle_name, u_off.last_name)) AS serving_officer_name,
                   TRIM(CONCAT_WS(' ', u_rec.first_name, u_rec.middle_name, u_rec.last_name)) AS recorded_by_name
            FROM paanyaya_officer_returns por
            INNER JOIN complaints c ON c.complaint_id = por.complaint_id
            LEFT JOIN users u_off ON u_off.user_id = por.serving_officer_id
            LEFT JOIN users u_rec ON u_rec.user_id = por.recorded_by
            WHERE por.return_id = ?
            LIMIT 1
        ");
        $stmt->execute([$returnId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Updates permitted fields of an Officer's Return (correction workflow) and logs to audit.
     */
    public function updateOfficerReturn(int $returnId, array $data, ?int $userId = null): array
    {
        $existing = $this->getOfficerReturnDetails($returnId);
        if (!$existing) {
            return ['success' => false, 'message' => "Officer's Return record not found."];
        }

        $remarks = isset($data['remarks']) ? trim((string)$data['remarks']) : $existing['remarks'];
        $unsuccessfulReason = isset($data['unsuccessful_reason']) ? trim((string)$data['unsuccessful_reason']) : $existing['unsuccessful_reason'];
        $serviceAddress = isset($data['service_address']) ? trim((string)$data['service_address']) : $existing['service_address'];
        $correctionNotes = trim((string)($data['correction_notes'] ?? 'Administrative correction'));

        $upd = $this->conn->prepare("
            UPDATE paanyaya_officer_returns
            SET remarks = ?,
                unsuccessful_reason = ?,
                service_address = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE return_id = ?
        ");
        $upd->execute([$remarks, $unsuccessfulReason, $serviceAddress, $returnId]);

        if ($userId) {
            $this->audit->log(
                $userId,
                "Edited Officer's Return #{$returnId} ({$existing['stage_type']} Attempt {$existing['attempt_number']}): {$correctionNotes}",
                'Paanyaya',
                (int)$existing['complaint_id']
            );
        }

        return [
            'success' => true,
            'message' => "Officer's Return record updated successfully."
        ];
    }

    /**
     * Records an authorized Lupon review decision when attempts are exhausted or custom transition needed.
     */
    public function reviewStage(int $complaintId, string $stageType, array $data, ?int $userId = null): array
    {
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
                    status = IF(? = 'Case Closed', 'Completed', status),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $upd->execute([$decision, $notes, $userId, $decision, $stageId]);

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
}
PHP;

if (strpos($content, $target) !== false) {
    $content = str_replace($target, $replacement, $content);
    file_put_contents($file, $content);
    echo "Paanyaya model updated successfully!\n";
} else {
    echo "Target not found in Paanyaya.php\n";
}
