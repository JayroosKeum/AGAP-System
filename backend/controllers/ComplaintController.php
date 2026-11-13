<?php

require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/ComplaintParty.php';
require_once __DIR__ . '/../models/Attachment.php';
require_once __DIR__ . '/../models/Location.php';
require_once __DIR__ . '/../models/ComplaintScannedDocument.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class ComplaintController
{
    private PDO $conn;
    private Complaint $complaint;
    private ComplaintParty $complaintParty;
    private Attachment $attachment;
    private Location $location;
    private ComplaintScannedDocument $scannedDoc;
    private AuditService $audit;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
        $this->complaint = new Complaint();
        $this->complaintParty = new ComplaintParty();
        $this->attachment = new Attachment();
        $this->location = new Location();
        $this->scannedDoc = new ComplaintScannedDocument();
        $this->audit = new AuditService($this->conn);
    }

    public function index(?array $viewerScope = null): array
    {
        return $this->complaint->getAll($viewerScope);
    }

    public function show(int $id): array|false
    {
        if ($id <= 0) {
            return false;
        }
        $complaint = $this->complaint->getById($id);
        if (!$complaint) return false;
        $complaint['parties'] = $this->complaintParty->getByComplaint($id);
        $complaint['attachments'] = $this->attachment->getByComplaint($id);
        $complaint['location'] = $this->location->getByComplaint($id);
        $complaint['current_scan'] = $this->scannedDoc->getCurrentByComplaint($id);
        $complaint['scan_history'] = $this->scannedDoc->getHistoryByComplaint($id);
        require_once __DIR__ . '/../models/Paanyaya.php';
        $paaModel = new Paanyaya();
        $complaint['paanyaya'] = $paaModel->getByComplaintId($id);
        $complaint['paanyaya_sessions'] = $paaModel->getSessions($id);
        $complaint['paanyaya_workspace'] = $paaModel->getWorkspaceData($id);
        $complaint['paanyaya_stages'] = $paaModel->getTwoStagesData($id);
        $cfaRecord = null;
        if (!empty($complaint['case_id'])) {
            require_once __DIR__ . '/../models/CFA.php';
            $cfaRecord = (new CFA($this->conn))->getByCase((int) $complaint['case_id']) ?: null;
        }
        $complaint['cfa'] = $cfaRecord;
        require_once __DIR__ . '/../models/PartyModel.php';
        $partyModel = new PartyModel();
        $complaint['concurrent_disputes'] = $partyModel->checkConcurrentDisputesForComplaint($id);
        if (!empty($complaint['case_id'])) {
            // The case workspace is supplementary to the complaint record.  A
            // workspace/schema problem must never prevent the complaint itself,
            // its parties, or its attachments from being viewed.
            try {
                require_once __DIR__ . '/../models/CaseModel.php';
                $complaint['case_workspace'] = (new CaseModel())->getWorkspace((int) $complaint['case_id']);
                if (is_array($complaint['case_workspace'])) {
                    $complaint['case_workspace']['cfa'] = $cfaRecord;
                }
            } catch (Throwable $exception) {
                error_log(sprintf(
                    'Unable to load case workspace for complaint %d (case %d): %s',
                    $id,
                    (int) $complaint['case_id'],
                    $exception->getMessage()
                ));
                $complaint['case_workspace'] = null;
            }
        } elseif (($complaint['status'] ?? '') === 'Settled' || !empty($complaint['resolved_without_docketing'])) {
            try {
                require_once __DIR__ . '/../services/MediationDeadlineService.php';
                require_once __DIR__ . '/../models/CaseTransmittal.php';

                $db = (new Database())->connect();
                $pMinStmt = $db->prepare("
                    SELECT minutes_id, agreed_terms, dialogue_date, created_at
                    FROM paanyaya_minutes
                    WHERE complaint_id = ? AND outcome = 'Settled'
                    ORDER BY minutes_id DESC LIMIT 1
                ");
                $pMinStmt->execute([$id]);
                $pMin = $pMinStmt->fetch(PDO::FETCH_ASSOC);

                $settlementDate = !empty($pMin['dialogue_date'])
                    ? substr($pMin['dialogue_date'], 0, 10)
                    : (!empty($pMin['created_at']) ? substr($pMin['created_at'], 0, 10) : substr($complaint['updated_at'] ?? date('Y-m-d'), 0, 10));

                $expiryDate = MediationDeadlineService::calculateBusinessDays($settlementDate, 10);
                $transModel = new CaseTransmittal($db);
                $workingDays = $transModel->calculateWorkingDaysRemaining($expiryDate);
                $isLocked = ($workingDays > 0);

                $complaint['case_workspace'] = [
                    'case' => [
                        'case_id' => 0,
                        'complaint_id' => $id,
                        'case_number' => $complaint['complaint_number'],
                        'case_type' => $complaint['case_type'] ?? 'Civil',
                        'case_status' => 'Settled',
                        'docket_date' => substr($complaint['created_at'], 0, 10),
                    ],
                    'settlement' => [
                        'settlement_id' => !empty($pMin['minutes_id']) ? (int) $pMin['minutes_id'] : 0,
                        'case_id' => 0,
                        'complaint_id' => $id,
                        'settlement_date' => $settlementDate,
                        'agreement_details' => $pMin['agreed_terms'] ?? '',
                        'repudiation_deadline' => $expiryDate,
                        'working_days_remaining' => $workingDays,
                        'repudiation_status' => 'Within Repudiation Period',
                        'compliance_status' => 'Pending'
                    ],
                    'transmittal' => [
                        'transmittal_id' => 0,
                        'case_id' => 0,
                        'complaint_id' => $id,
                        'transmittal_number' => sprintf('TR-%s-%04d', date('Y'), $id),
                        'settlement_date' => $settlementDate,
                        'ten_day_expiry_date' => $expiryDate,
                        'repudiation_deadline' => $expiryDate,
                        'working_days_remaining' => $workingDays,
                        'is_locked' => $isLocked,
                        'is_repudiated' => false,
                        'target_agency' => 'Both',
                        'status' => 'Pending'
                    ],
                    'stages' => null,
                    'hearings' => [],
                    'documents' => [],
                    'proofs' => [],
                    'assignments' => []
                ];
            } catch (Throwable $exception) {
                error_log('Unable to build pre-docket settlement workspace for complaint ' . $id . ': ' . $exception->getMessage());
                $complaint['case_workspace'] = null;
            }
        } else {
            $complaint['case_workspace'] = null;
        }
        return $complaint;
    }

    public function store(array $data, array $files = []): array
    {
        // Time restrictions for intaking complaints are disabled (24/7 intake allowed)
        // Lupon office hours check is bypassed for complaint intake.

        $evidenceFiles = [];
        if (!empty($files['evidence'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['evidence']);
        } elseif (!empty($files['attachments'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['attachments']);
        }

        foreach ($evidenceFiles as $file) {
            $validationError = $this->validateAttachmentFile($file);
            if ($validationError !== null) {
                return ['success' => false, 'message' => $validationError];
            }
        }

        // Check for scanned handwritten KP Form 7 upload
        $scannedDocFile = null;
        foreach (['scanned_kp7', 'scan_file', 'scanned_document'] as $scanKey) {
            if (!empty($files[$scanKey]) && !empty($files[$scanKey]['tmp_name']) && ($files[$scanKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $scannedDocFile = $files[$scanKey];
                break;
            }
            if (!empty($_FILES[$scanKey]) && !empty($_FILES[$scanKey]['tmp_name']) && ($_FILES[$scanKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $scannedDocFile = $_FILES[$scanKey];
                break;
            }
        }

        if ($scannedDocFile !== null) {
            $scanError = $this->scannedDoc->validateScanFile($scannedDocFile);
            if ($scanError !== null) {
                return ['success' => false, 'message' => $scanError];
            }
        }

        $this->prepareAiSuggestionForIntake($data);
        $result = $this->complaint->create($data);

        if ($result['success']) {
            $complaintId = (int) ($result['complaint_id'] ?? 0);

            $actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : (PHP_SAPI === 'cli' ? 1 : 0);
            if ($actorId > 0) {
                $this->audit->log(
                    $actorId,
                    !empty($data['is_draft']) ? 'Saved Complaint Draft' : 'Created Complaint (' . ($result['complaint_source'] ?? 'Walk-in') . ')',
                    'Complaints',
                    $complaintId ?: null
                );
            }

            if ($complaintId > 0) {
                $this->logAiClassificationVerification($complaintId, $data);
                if (empty($data['is_draft']) && ($result['status'] ?? '') !== 'Under Review') {
                    try {
                        require_once __DIR__ . '/../services/KpFormService.php';
                        $kpRes = (new KpFormService($this->conn))->autoGenerateForEvent('complaint_filed', $complaintId, $actorId ?: 1);
                        if (!empty($kpRes['generated'][0]['file_path'])) {
                            $result['kp7_file_path'] = $kpRes['generated'][0]['file_path'];
                        }
                        $result['kp7_preview_url'] = '../../backend/api/complaints/kp7-preview.php?id=' . $complaintId;
                        $result['kp7_download_url'] = '../../backend/api/complaints/kp7-preview.php?id=' . $complaintId . '&download=1';
                    } catch (Throwable $kpErr) {
                        error_log('Pre-docket KP Form 7 generation warning: ' . $kpErr->getMessage());
                    }
                }
            }

            // Handle evidence attachments
            if (!empty($evidenceFiles) && $complaintId > 0) {
                $uploadedCount = 0;
                foreach ($evidenceFiles as $file) {
                    $attachResult = $this->addAttachment($complaintId, $file);
                    if ($attachResult['success']) {
                        $uploadedCount++;
                    }
                }
                if ($uploadedCount > 0) {
                    $result['message'] .= " ($uploadedCount attachment(s) uploaded.)";
                }
            }

            // Handle scanned KP Form 7 upload
            if ($scannedDocFile !== null && $complaintId > 0) {
                $scanUpload = $this->scannedDoc->upload(
                    $complaintId,
                    $scannedDocFile,
                    (int) ($_SESSION['user_id'] ?? 1),
                    'Uploaded during complaint intake'
                );
                if ($scanUpload['success']) {
                    $result['message'] .= ' (Handwritten KP Form 7 scan uploaded.)';
                } else {
                    $result['message'] .= ' (Warning: Scanned document could not be saved: ' . ($scanUpload['message'] ?? 'Upload error') . ')';
                }
            }


            // A complaint created from Paanyaya must immediately become a formal docket.
            // Linking the Paanyaya alone left the newly-created complaint in its old
            // Paanyaya status and made the workflow appear stuck at Stage 0.
            $escalatedPaanyayaId = (int)($data['escalated_from_paanyaya_id'] ?? ($data['escalate_paanyaya_id'] ?? 0));
            if ($complaintId > 0 && $escalatedPaanyayaId > 0) {
                try {
                    require_once __DIR__ . '/../models/Paanyaya.php';
                    $paanyayaModel = new Paanyaya();
                    $docketResult = $paanyayaModel->escalateToFormalDocketing(
                        $complaintId,
                        (int) ($_SESSION['user_id'] ?? 1),
                        "Formal complaint created from Paanyaya #{$escalatedPaanyayaId}."
                    );

                    if (empty($docketResult['success'])) {
                        throw new RuntimeException($docketResult['message'] ?? 'Unable to create the formal Mediation docket.');
                    }

                    $updP = $this->conn->prepare("UPDATE paanyayas SET status = 'Escalated', complaint_id = ?, escalated_to_complaint_id = ?, updated_at = NOW() WHERE paanyaya_id = ?");
                    $updP->execute([(int)$complaintId, (int)$complaintId, $escalatedPaanyayaId]);

                    // Escalation is the formal conclusion of the informal dialogue:
                    // there was no agreement at Paanyaya, so preserve that outcome
                    // for the Paanyaya details and audit trail.
                    $noAgreementNotes = 'No agreement at Paanyaya; escalated to formal Mediation.';
                    $this->conn->prepare("\n                        UPDATE paanyaya_sessions\n                        SET session_outcome = 'Impasse_Escalate',\n                            outcome_notes = COALESCE(NULLIF(outcome_notes, ''), ?),\n                            session_remarks = COALESCE(NULLIF(session_remarks, ''), ?),\n                            escalated_at = COALESCE(escalated_at, NOW()),\n                            queue_status = 'Concluded',\n                            updated_at = NOW()\n                        WHERE complaint_id = ?\n                    ")->execute([$noAgreementNotes, $noAgreementNotes, (int)$complaintId]);

                    $this->conn->prepare("\n                        UPDATE complaints\n                        SET paanyaya_1_outcome = COALESCE(NULLIF(paanyaya_1_outcome, ''), 'Impasse_Escalate'),\n                            updated_at = NOW()\n                        WHERE complaint_id = ?\n                    ")->execute([(int)$complaintId]);

                    $result = array_merge($result, $docketResult, [
                        'escalated_from_paanyaya' => true,
                        'paanyaya_id' => $escalatedPaanyayaId,
                        'message' => ($result['message'] ?? 'Complaint created.') . ' Formal docket created and moved to Mediation.'
                    ]);
                } catch (Throwable $pe) {
                    error_log('Failed to docket escalated paanyaya in ComplaintController: ' . $pe->getMessage());
                    return [
                        'success' => false,
                        'complaint_id' => $complaintId,
                        'message' => 'The official complaint was saved, but it could not be docketed into Mediation: ' . $pe->getMessage()
                    ];
                }
            }

            if (empty($result['redirect_url'])) {
                $result['redirect_url'] = '../../frontend/pages/complaints/complaint-details.php?id=' . $complaintId . '&tab=overview';
            }
        }

        return $result;
    }

    public function update(int $id, array $data, array $files = []): array
    {
        $evidenceFiles = [];
        if (!empty($files['evidence'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['evidence']);
        } elseif (!empty($files['attachments'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['attachments']);
        } elseif (!empty($_FILES['evidence'])) {
            $evidenceFiles = $this->normalizeFilesArray($_FILES['evidence']);
        }

        foreach ($evidenceFiles as $file) {
            $validationError = $this->validateAttachmentFile($file);
            if ($validationError !== null) {
                return ['success' => false, 'message' => $validationError];
            }
        }

        // Check for scanned handwritten KP Form 7 upload
        $scannedDocFile = null;
        foreach (['scanned_kp7', 'scan_file', 'scanned_document'] as $scanKey) {
            if (!empty($files[$scanKey]) && !empty($files[$scanKey]['tmp_name']) && ($files[$scanKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $scannedDocFile = $files[$scanKey];
                break;
            }
            if (!empty($_FILES[$scanKey]) && !empty($_FILES[$scanKey]['tmp_name']) && ($_FILES[$scanKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $scannedDocFile = $_FILES[$scanKey];
                break;
            }
        }

        if ($scannedDocFile !== null) {
            $scanError = $this->scannedDoc->validateScanFile($scannedDocFile);
            if ($scanError !== null) {
                return ['success' => false, 'message' => $scanError];
            }
        }

        $this->prepareAiSuggestionForIntake($data);
        $result = $this->complaint->update($id, $data);

        if ($result['success']) {
            $complaintId = (int) $id;
            if ($complaintId > 0) {
                $this->logAiClassificationVerification($complaintId, $data);
            }

            if (!empty($evidenceFiles) && $complaintId > 0) {
                $uploadedCount = 0;
                foreach ($evidenceFiles as $file) {
                    $attachResult = $this->addAttachment($complaintId, $file);
                    if ($attachResult['success']) {
                        $uploadedCount++;
                    }
                }
                if ($uploadedCount > 0) {
                    $result['message'] .= " ($uploadedCount attachment(s) uploaded.)";
                }
            }

            // Handle scanned KP Form 7 upload
            if ($scannedDocFile !== null && $complaintId > 0) {
                $reason = trim((string) ($data['replacement_reason'] ?? 'Updated scanned KP Form 7 document'));
                $scanUpload = $this->scannedDoc->upload(
                    $complaintId,
                    $scannedDocFile,
                    (int) ($_SESSION['user_id'] ?? 1),
                    $reason
                );
                if ($scanUpload['success']) {
                    $result['message'] .= ' (Handwritten KP Form 7 scan uploaded.)';
                } else {
                    $result['message'] .= ' (Warning: Scanned document could not be saved: ' . ($scanUpload['message'] ?? 'Upload error') . ')';
                }
            }

            $auditAction = !empty($result['is_draft'])
                ? 'Saved Complaint Draft'
                : ((($result['complaint_source'] ?? '') === 'Online' && ($result['status'] ?? '') === 'Under Review')
                    ? 'Submitted Online Complaint'
                    : 'Updated Complaint');
            $this->audit->log(
                $_SESSION['user_id'] ?? 1,
                $auditAction,
                'Complaints',
                $id
            );
        }

        return $result;
    }

    private function prepareAiSuggestionForIntake(array &$data): void
    {
        if (!empty($data['is_draft']) && empty($data['ai_suggestion_payload'])) {
            return;
        }
        $narrative = trim((string) ($data['narrative'] ?? $data['complaint_description'] ?? ''));
        if (mb_strlen($narrative) < 10) {
            return;
        }
        $requestedResolution = trim((string) ($data['requested_resolution'] ?? ''));

        $payloadRaw = trim((string) ($data['ai_suggestion_payload'] ?? ''));
        if ($payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            if (is_array($decoded) && !empty($decoded['primary']['category_id'])) {
                $catId = filter_var($decoded['primary']['category_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($catId) {
                    try {
                        $catStmt = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_id = ? AND COALESCE(is_active, 1) = 1 LIMIT 1');
                        $catStmt->execute([$catId]);
                        $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
                        if ($catRow) {
                            $decoded['primary'] = [
                                'category_id' => (int) $catRow['category_id'],
                                'category_name' => (string) $catRow['category_name'],
                                'confidence' => mb_substr(trim((string) ($decoded['primary']['confidence'] ?? 'Medium')), 0, 32),
                                'reason' => mb_substr(trim((string) ($decoded['primary']['reason'] ?? '')), 0, 500),
                            ];
                            $secClean = null;
                            if (!empty($decoded['secondary']) && is_array($decoded['secondary']) && !empty($decoded['secondary']['category_id'])) {
                                $secCatId = filter_var($decoded['secondary']['category_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                                if ($secCatId && $secCatId !== (int) $catRow['category_id']) {
                                    $catStmt->execute([$secCatId]);
                                    $secCatRow = $catStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($secCatRow) {
                                        $secType = trim((string) ($decoded['secondary']['case_type'] ?? ''));
                                        $secClean = [
                                            'category_id' => (int) $secCatRow['category_id'],
                                            'category_name' => (string) $secCatRow['category_name'],
                                            'confidence' => mb_substr(trim((string) ($decoded['secondary']['confidence'] ?? 'Medium')), 0, 32),
                                            'case_type' => in_array($secType, ['Civil', 'Criminal'], true) ? $secType : null,
                                            'reason' => mb_substr(trim((string) ($decoded['secondary']['reason'] ?? '')), 0, 500),
                                        ];
                                    }
                                }
                            }
                            $decoded['secondary'] = $secClean;
                            $caseType = trim((string) ($decoded['case_type'] ?? 'Civil'));
                            $decoded['case_type'] = in_array($caseType, ['Civil', 'Criminal'], true) ? $caseType : 'Civil';
                            $decoded['suggested_title'] = mb_substr(trim((string) ($decoded['suggested_title'] ?? '')), 0, 255);
                            $decoded['jurisdiction_advisory'] = mb_substr(trim((string) ($decoded['jurisdiction_advisory'] ?? '')), 0, 600);
                            $decoded['source'] = trim((string) ($decoded['source'] ?? 'keyword_fallback'));
                            $decoded['resident_viewed'] = true;
                            $decoded['auto_generated_on_submit'] = false;
                            $decoded['generated_at'] = date('Y-m-d H:i:s');
                            $data['ai_suggestion_json'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                            return;
                        }
                    } catch (Throwable $e) {
                        error_log('Resident AI suggestion validation notice: ' . $e->getMessage());
                    }
                }
            }
        }

        try {
            require_once __DIR__ . '/AIController.php';
            $catStmt = $this->conn->query("
                SELECT category_id, category_name, tagalog_name, description
                FROM complaint_categories
                WHERE COALESCE(is_active, 1) = 1
                ORDER BY CASE WHEN category_name LIKE 'Others%' OR category_name LIKE 'Iba pa%' THEN 1 ELSE 0 END ASC, category_name ASC
            ");
            $categories = $catStmt ? $catStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($categories === []) {
                return;
            }
            $res = (new AIController())->suggestComplaintClassification($narrative, $categories, $requestedResolution, false);
            if (!empty($res['success']) && !empty($res['suggestion']['primary']['category_id'])) {
                $sug = $res['suggestion'];
                $sug['source'] = (string) ($res['source'] ?? 'keyword_fallback');
                $sug['resident_viewed'] = false;
                $sug['auto_generated_on_submit'] = true;
                $sug['generated_at'] = date('Y-m-d H:i:s');
                $data['ai_suggestion_json'] = json_encode($sug, JSON_UNESCAPED_UNICODE);
            }
        } catch (Throwable $e) {
            error_log('Intake auto AI suggestion warning: ' . $e->getMessage());
        }
    }

    private function logAiClassificationVerification(int $complaintId, array $data): void
    {
        if (!in_array($data['ai_classification_used'] ?? null, [true, 1, '1', 'true'], true)) {
            return;
        }
        if (!in_array($data['ai_classification_verified'] ?? null, [true, 1, '1', 'true'], true)) {
            return;
        }

        $suggestedCatId = filter_var($data['ai_suggested_category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $finalCatId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($finalCatId <= 0) {
            try {
                $stmt = $this->conn->prepare('SELECT category_id FROM complaints WHERE complaint_id = ? LIMIT 1');
                $stmt->execute([$complaintId]);
                $finalCatId = (int) ($stmt->fetchColumn() ?: 0);
            } catch (Throwable) {
                $finalCatId = 0;
            }
        }

        if ($suggestedCatId > 0 && $finalCatId > 0) {
            $action = ($suggestedCatId === $finalCatId)
                ? "Verified AI complaint classification (accepted category #{$finalCatId})"
                : "Verified AI complaint classification (overrode #{$suggestedCatId} to #{$finalCatId})";
        } else {
            $action = 'Verified AI complaint classification';
        }

        $this->audit->log(
            $_SESSION['user_id'] ?? 1,
            $action,
            'Complaints',
            $complaintId
        );
    }

    public function linkWalkin(int $complaintId, array $data, array $files = []): array
    {
        $staffUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($staffUserId < 1) {
            return ['success' => false, 'message' => 'Authentication required.'];
        }

        $evidenceFiles = [];
        if (!empty($files['evidence'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['evidence']);
        } elseif (!empty($files['attachments'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['attachments']);
        }
        foreach ($evidenceFiles as $file) {
            $validationError = $this->validateAttachmentFile($file);
            if ($validationError !== null) {
                return ['success' => false, 'message' => $validationError];
            }
        }

        $result = $this->complaint->linkWalkinToExisting($complaintId, $staffUserId, $data);
        if (empty($result['success'])) {
            return $result;
        }

        $uploadedCount = 0;
        foreach ($evidenceFiles as $file) {
            $attachRes = $this->addAttachment($complaintId, $file);
            if (!empty($attachRes['success'])) {
                $uploadedCount++;
            }
        }
        if ($uploadedCount > 0) {
            $result['message'] .= " ({$uploadedCount} walk-in attachment(s) added.)";
        }

        $this->audit->log($staffUserId, 'Linked Walk-in Visit to Existing Complaint', 'Complaints', $complaintId);
        return $result;
    }

    public function review(int $id, array $data): array
    {
        $status = trim((string) ($data['status'] ?? ''));
        if (strcasecmp($status, 'Approved') === 0) {
            $status = 'Accepted';
        }
        $notes = trim((string) ($data['review_notes'] ?? ''));
        if ($id < 1 || mb_strlen($notes) > 2000) {
            return ['success' => false, 'message' => 'Provide valid review notes of 2,000 characters or fewer.'];
        }
        $result = $this->complaint->review($id, $status, $notes !== '' ? $notes : null, $data);
        if ($result['success']) {
            $actorId = (int) ($_SESSION['user_id'] ?? 1);
            $effectiveStatus = $result['status'] ?? $status;
            $this->audit->log($actorId, 'Reviewed Complaint: ' . $effectiveStatus, 'Complaints', $id);
            $this->logAiClassificationVerification($id, $data);
            if ($effectiveStatus === 'Accepted') {
                try {
                    require_once __DIR__ . '/../services/KpFormService.php';
                    (new KpFormService($this->conn))->autoGenerateForEvent('complaint_filed', $id, $actorId);
                } catch (Throwable $kpErr) {
                    error_log('Review KP Form 7 generation warning: ' . $kpErr->getMessage());
                }
            }
        }
        return $result;
    }

    public function destroy(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $result = $this->complaint->delete($id);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'] ?? 1,
                'Deleted Complaint',
                'Complaints',
                $id
            );
        }

        return (bool) $result;
    }

    public function archive(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $result = $this->complaint->archive($id);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'] ?? 1,
                'Archived Complaint',
                'Complaints',
                $id
            );
        }

        return (bool) $result;
    }

    public function addParty(int $complaintId, int $residentId, string $partyType): array
    {
        $result = $this->complaintParty->create($complaintId, $residentId, $partyType);
        if ($result['success']) {
            $this->audit->log((int) $_SESSION['user_id'], 'Added Party to Complaint', 'Complaints', $complaintId);
        }
        return $result;
    }
    public function deleteParty(int $partyId): array
    {
        $party = $this->complaintParty->getById($partyId);
        if (!$party) {
            return ['success' => false, 'message' => 'Complaint party not found.'];
        }
        if (!$this->complaintParty->delete($partyId)) {
            return ['success' => false, 'message' => 'Unable to remove complaint party.'];
        }
        $this->audit->log((int) $_SESSION['user_id'], 'Removed Party from Complaint', 'Complaints', (int) $party['complaint_id']);
        return ['success' => true, 'message' => 'Complaint party removed successfully.'];
    }

    public function getParties(int $complaintId): array
    {
        return $this->complaintParty->getByComplaint($complaintId);
    }

    public function normalizeFilesArray(array $files): array
    {
        $normalized = [];
        if (!isset($files['name'])) {
            return $normalized;
        }

        if (is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($error === UPLOAD_ERR_NO_FILE || empty($files['name'][$i])) {
                    continue;
                }
                $normalized[] = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $error,
                    'size' => (int) ($files['size'][$i] ?? 0)
                ];
            }
        } else {
            $error = (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_NO_FILE && !empty($files['name'])) {
                $normalized[] = $files;
            }
        }

        return $normalized;
    }

    public function validateAttachmentFile(array $file): ?string
    {
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return 'A valid file upload is required.';
        }
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $isUploaded = $temporaryPath !== '' && (is_uploaded_file($temporaryPath) || (PHP_SAPI === 'cli' && is_file($temporaryPath)));
        if (!$isUploaded) {
            return 'A valid file upload is required.';
        }
        $actualSize = filesize($temporaryPath);
        if ($actualSize === false || $actualSize < 1 || $actualSize > 25 * 1024 * 1024
            || (int) ($file['size'] ?? -1) !== $actualSize) {
            $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
            return "File '{$fileName}' must not exceed 25 MB.";
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporaryPath);
        $allowed = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
            'video/mp4' => ['mp4'],
            'video/webm' => ['webm'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/zip' => ['docx'],
            'application/x-zip' => ['docx'],
            'application/x-zip-compressed' => ['docx'],
            'application/octet-stream' => ['doc', 'docx']
        ];
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset($allowed[$mime]) || !in_array($extension, $allowed[$mime], true)) {
            $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
            return "File '{$fileName}' has an invalid format. Allowed formats: JPG, PNG, GIF, WebP, MP4, WebM, PDF, DOC, DOCX.";
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($temporaryPath) === false) {
            $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
            return "The uploaded image '{$fileName}' is invalid or corrupted.";
        }
        if ($mime === 'application/pdf' && file_get_contents($temporaryPath, false, null, 0, 5) !== '%PDF-') {
            $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
            return "The uploaded PDF '{$fileName}' is invalid or corrupted.";
        }
        if ($extension === 'docx') {
            if (file_get_contents($temporaryPath, false, null, 0, 2) !== 'PK') {
                $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
                return "The uploaded DOCX file '{$fileName}' is invalid or corrupted.";
            }
        }
        if ($extension === 'doc') {
            if (substr(file_get_contents($temporaryPath, false, null, 0, 4), 0, 4) !== "\xD0\xCF\x11\xE0") {
                $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
                return "The uploaded DOC file '{$fileName}' is invalid or corrupted.";
            }
        }

        return null;
    }

    public function addAttachment(int $complaintId, array $file): array
    {
        if (!$this->attachment->complaintExists($complaintId)) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        $error = $this->validateAttachmentFile($file);
        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporaryPath);
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        $originalName = trim(basename((string) ($file['name'] ?? 'attachment')));
        $originalName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $originalName) ?: 'attachment.' . $extension;
        $storedExtension = $mime === 'image/jpeg' ? 'jpg' : $extension;
        $storedName = bin2hex(random_bytes(16)) . '.' . $storedExtension;
        $relativePath = 'storage/uploads/complaint-evidence/' . $storedName;
        $targetDirectory = dirname(__DIR__, 2) . '/storage/uploads/complaint-evidence';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0750, true) && !is_dir($targetDirectory)) {
            return ['success' => false, 'message' => 'Upload storage is unavailable.'];
        }
        $targetPath = $targetDirectory . '/' . $storedName;
        $moved = PHP_SAPI === 'cli'
            ? @copy($file['tmp_name'], $targetPath)
            : @move_uploaded_file($file['tmp_name'], $targetPath);
        if (!$moved) {
            return ['success' => false, 'message' => 'Unable to store the uploaded file.'];
        }

        try {
            $result = $this->attachment->create($complaintId, $originalName, $relativePath, $mime);
            if (!$result['success']) {
                @unlink($targetPath);
                return $result;
            }
        } catch (Throwable $exception) {
            @unlink($targetPath);
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to save the attachment record.'];
        }

        $this->audit->log((int) $_SESSION['user_id'], 'Added Attachment to Complaint', 'Complaints', $complaintId);
        return $result;
    }
    public function getAttachments(int $complaintId): array
    {
        return $this->attachment->getByComplaint($complaintId);
    }

    public function getAttachment(int $attachmentId): array|false
    {
        return $this->attachment->getById($attachmentId);
    }

    public function deleteAttachment(int $attachmentId): array
    {
        $attachment = $this->attachment->getById($attachmentId);
        if (!$attachment) {
            return ['success' => false, 'message' => 'Attachment not found.'];
        }
        if (!$this->attachment->delete($attachmentId)) {
            return ['success' => false, 'message' => 'Unable to delete the attachment.'];
        }

        $base1 = realpath(dirname(__DIR__, 2) . '/storage/uploads/complaint-evidence');
        $base2 = realpath(dirname(__DIR__, 2) . '/storage/uploads/evidence');
        $path = realpath(dirname(__DIR__, 2) . '/' . $attachment['file_path']);
        if ($path !== false && is_file($path)) {
            if (($base1 !== false && str_starts_with($path, $base1 . DIRECTORY_SEPARATOR)) ||
                ($base2 !== false && str_starts_with($path, $base2 . DIRECTORY_SEPARATOR))) {
                @unlink($path);
            }
        }
        $this->audit->log((int) $_SESSION['user_id'], 'Deleted Attachment from Complaint', 'Complaints', (int) $attachment['complaint_id']);
        return ['success' => true, 'message' => 'Attachment deleted successfully.'];
    }
}
