<?php

require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/ComplaintParty.php';
require_once __DIR__ . '/../models/Attachment.php';
require_once __DIR__ . '/../models/Location.php';
require_once __DIR__ . '/../models/ComplaintScannedDocument.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../middleware/rbac.php';
require_once __DIR__ . '/../services/PiiCryptoService.php';

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
        $this->complaint = new Complaint($this->conn);
        $this->complaintParty = new ComplaintParty();
        $this->attachment = new Attachment();
        $this->location = new Location();
        $this->scannedDoc = new ComplaintScannedDocument();
        $this->audit = new AuditService($this->conn);
    }

    public function index(): array
    {
        $rows = $this->complaint->getAll();
        if (isset($_SESSION['user_id'], $_SESSION['role_id'])) {
            $uid = agapSessionUserId();
            $roleName = agapGetRoleName($this->conn);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => agapCanAccessComplaint($this->conn, (int) $row['complaint_id'], $uid, $roleName, 'read')
            ));
        }
        return $rows;
    }

    public function show(int $id): array|false
    {
        if ($id <= 0) {
            return false;
        }
        $includeDeleted = false;
        if (isset($_SESSION['user_id'], $_SESSION['role_id'])) {
            $uid = agapSessionUserId();
            $roleName = agapGetRoleName($this->conn);
            if (!agapCanAccessComplaint($this->conn, $id, $uid, $roleName, 'read')) {
                return false;
            }
            $includeDeleted = ($roleName === 'Super Administrator');
        }
        $complaint = $this->complaint->getById($id, $includeDeleted);
        if (!$complaint) return false;
        $complaint['parties'] = $this->complaintParty->getByComplaint($id);
        $complaint['attachments'] = $this->attachment->getByComplaint($id);
        $complaint['location'] = $this->location->getByComplaint($id);
        $complaint['current_scan'] = $this->scannedDoc->getCurrentByComplaint($id);
        $complaint['scan_history'] = $this->scannedDoc->getHistoryByComplaint($id);
        require_once __DIR__ . '/../models/Paanyaya.php';
        $paaModel = new Paanyaya();
        $complaint['paanyaya'] = $paaModel->getByComplaintId($id);
        $complaint['paanyaya_workspace'] = $paaModel->getWorkspaceData($id);
        $complaint['paanyaya_stages'] = $paaModel->getTwoStagesData($id);
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
        return PiiCryptoService::decryptPayload($complaint);
    }

    public function store(array $data, array $files = []): array
    {
        $sourcePaanyaya = $data['escalated_from_paanyaya_id'] ?? $data['escalate_paanyaya_id'] ?? '';
        $actorId = (int) ($_SESSION['user_id'] ?? 0);
        $sourcePaanyayaId = null;
        if ($sourcePaanyaya !== '') {
            if ($actorId < 1 || ($_SESSION['role_name'] ?? '') === 'Resident' || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true)) {
                return ['success' => false, 'code' => 'PAANYAYA_HANDOFF_FORBIDDEN', 'message' => 'Only authorized staff may docket a Paanyaya.'];
            }
            $sourcePaanyayaId = filter_var($sourcePaanyaya, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($sourcePaanyayaId === false) {
                return ['success' => false, 'message' => 'Select a valid Paanyaya record.'];
            }
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

        $result = $sourcePaanyayaId === null
            ? $this->complaint->create($data)
            : $this->createFromPaanyaya($sourcePaanyayaId, $actorId, $data);

        if ($result['success']) {
            $complaintId = (int) ($result['complaint_id'] ?? 0);

            if ($sourcePaanyayaId === null) {
                $this->audit->log($actorId ?: 1, 'Created Complaint', 'Complaints', $complaintId ?: null);
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

            if (empty($result['redirect_url'])) {
                $result['redirect_url'] = '../../frontend/pages/complaints/complaint-details.php?id=' . $complaintId . '&tab=overview';
            }
        }

        return $result;
    }

    private function createFromPaanyaya(int $sourceId, int $actorId, array $data): array
    {
        require_once __DIR__ . '/../models/Paanyaya.php';
        $paanyaya = new Paanyaya($this->conn);
        $this->conn->beginTransaction();
        try {
            $source = $paanyaya->lockStandaloneHandoff($sourceId);
            if (empty($source['attendance_recorded_at'])) {
                throw new DomainException('Record the Paanyaya attendance before docketing.');
            }

            if (empty($data['category_id']) && !empty($source['preliminary_category_id'])) {
                $data['category_id'] = (int) $source['preliminary_category_id'];
            }
            if (empty($data['other_category_specification']) && !empty($source['preliminary_other_category_specification'])) {
                $data['other_category_specification'] = (string) $source['preliminary_other_category_specification'];
            }
            if (empty($data['case_type']) && !empty($source['preliminary_case_type'])) {
                $data['case_type'] = (string) $source['preliminary_case_type'];
            }
            if (empty($data['narrative']) && !empty($source['salaysay'])) {
                $data['narrative'] = (string) $source['salaysay'];
            }
            $result = $this->complaint->create($data, true);
            if (empty($result['success'])) {
                $this->conn->rollBack();
                return $result;
            }

            $complaintId = (int) $result['complaint_id'];
            $paanyaya->linkStandaloneHandoff($sourceId, (int) ($source['parent_paanyaya_id'] ?? $sourceId), $complaintId);
            if (!empty($source['tracking_reference'])) {
                $this->conn->prepare("UPDATE complaints SET intake_reference = COALESCE(NULLIF(intake_reference, ''), ?) WHERE complaint_id = ?")
                    ->execute([$source['tracking_reference'], $complaintId]);
            }
            $attemptNo = (int) ($source['attempt_number'] ?? 1);
            if ($attemptNo >= 2) {
                $this->conn->prepare("
                    UPDATE complaints
                    SET paanyaya_1_outcome = COALESCE(paanyaya_1_outcome, 'Impasse_Escalate'),
                        max_paanyaya_reached = 1
                    WHERE complaint_id = ?
                ")->execute([$complaintId]);
            } else {
                $this->conn->prepare("UPDATE complaints SET paanyaya_1_outcome = 'Impasse_Escalate' WHERE complaint_id = ?")
                    ->execute([$complaintId]);
            }
            if (!$this->audit->log($actorId, 'Created Complaint from Paanyaya', 'Complaints', $complaintId)) {
                throw new RuntimeException('Could not audit the Paanyaya handoff.');
            }
            $docket = $paanyaya->escalateToFormalDocketing($complaintId, $actorId, "Formal complaint created from Paanyaya #{$sourceId}.", $data);
            if (!empty($docket['success'])) {
                $docket['redirect_url'] = '../../frontend/pages/complaints/complaint-details.php?id=' . $complaintId . '&tab=mediation';
            }
            if (empty($docket['success']) || empty($docket['case_id'])) {
                if (!empty($docket['message'])) {
                    throw new DomainException($docket['message']);
                }
                throw new RuntimeException('Unable to create the formal Mediation docket.');
            }

            $this->conn->commit();
            return array_merge($result, $docket, [
                'escalated_from_paanyaya' => true,
                'paanyaya_id' => $sourceId,
                'status' => 'Mediation',
                'complaint_number' => $docket['case_number'],
            ]);
        } catch (DomainException $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Failed to docket Paanyaya: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to docket this Paanyaya. Please try again.'];
        }
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

        $result = $this->complaint->update($id, $data);

        if ($result['success']) {
            $complaintId = (int) $id;

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

            $this->audit->log(
                $_SESSION['user_id'] ?? 1,
                'Updated Complaint',
                'Complaints',
                $id
            );
        }

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
        $reviewerId = (int) ($_SESSION['user_id'] ?? 0);
        $isAssignedLisoReview = (int) ($_SESSION['role_id'] ?? 0) === 3;
        $requestMessage = is_string($data['request_message'] ?? null) ? trim($data['request_message']) : '';
        $classification = [];
        foreach ([
            'category_id', 'category_name', 'secondary_category_id', 'secondary_category_name',
            'other_category_specification', 'secondary_other_category_specification',
            'case_type', 'complaint_title', 'compact_view', 'jurisdiction_confirmed',
            'offense_imprisonment_max', 'has_private_offended_party',
            'accused_under_detention', 'habeas_corpus_needed', 'prescription_risk'
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $classification[$field] = $data[$field];
            }
        }
        return $this->complaint->review($id, $status, $notes !== '' ? $notes : null, $reviewerId, $isAssignedLisoReview, $requestMessage, $classification);
    }

    public function docket(int $id, int $userId): array
    {
        $result = $this->complaint->docket($id, $userId);
        if ($result['success']) {
            $this->audit->log($userId, 'Formally docketed screened complaint', 'Complaints', $id);
        }
        return $result;
    }

    public function destroy(int $id, string $reason = 'Operational soft deletion'): bool
    {
        if ($id <= 0) {
            return false;
        }
        if (isset($_SESSION['user_id'], $_SESSION['role_id']) && !agapIsSuperAdmin($this->conn)) {
            return false;
        }
        $actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        return $this->complaint->delete($id, $actorId, $reason);
    }

    public function restore(int $id, string $reason = 'Restored by Super Administrator'): bool
    {
        if ($id <= 0) {
            return false;
        }
        if (isset($_SESSION['user_id'], $_SESSION['role_id']) && !agapIsSuperAdmin($this->conn)) {
            return false;
        }
        $actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 1;
        return $this->complaint->restore($id, $actorId, $reason);
    }

    public function deletedList(): array
    {
        if (isset($_SESSION['user_id'], $_SESSION['role_id']) && !agapIsSuperAdmin($this->conn)) {
            return [];
        }
        return $this->complaint->getDeleted();
    }

    public function overrideRouting(int $id, string $newQueue, string $reason, ?string $newRoute = null): array
    {
        $actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        return $this->complaint->overrideRouting($id, $newQueue, $reason, $actorId, $newRoute);
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
