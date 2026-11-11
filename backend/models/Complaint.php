<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ValidationService.php';
require_once __DIR__ . '/../services/ComplaintLifecycleService.php';

class Complaint
{
    private PDO $conn;
    private ?array $complaintColumnsCache = null;
    private ?array $intakeColumnsCache = null;

    public function __construct()
    {
        $database = new Database();

        $this->conn =
            $database->connect();
    }

    private function hasComplaintColumn(string $column): bool
    {
        if ($this->complaintColumnsCache === null) {
            try {
                $this->complaintColumnsCache = $this->conn->query('SHOW COLUMNS FROM complaints')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e) {
                $this->complaintColumnsCache = [];
            }
        }
        return in_array($column, $this->complaintColumnsCache, true);
    }

    private function hasIntakeScreeningColumn(string $column): bool
    {
        if ($this->intakeColumnsCache === null) {
            try {
                $this->intakeColumnsCache = $this->conn->query('SHOW COLUMNS FROM complaint_intake_screenings')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e) {
                $this->intakeColumnsCache = [];
            }
        }
        return in_array($column, $this->intakeColumnsCache, true);
    }

    public function getAll(?array $viewerScope = null)
    {
        try {
            $where = [];
            $params = [];
            if (!empty($viewerScope['role_name']) && $viewerScope['role_name'] === 'Resident' && !empty($viewerScope['user_id'])) {
                if ($this->hasComplaintColumn('created_by')) {
                    $where[] = '(c.created_by = ? OR c.encoded_by = ?)';
                    $params[] = (int) $viewerScope['user_id'];
                    $params[] = (int) $viewerScope['user_id'];
                } else {
                    $where[] = 'c.encoded_by = ?';
                    $params[] = (int) $viewerScope['user_id'];
                }
            } else {
                $where[] = 'COALESCE(c.is_draft, 0) = 0';
            }
            $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

            $stmt =
            $this->conn->prepare("
                SELECT
                    c.*,
                    cc.category_name,
                    cc2.category_name AS secondary_category_name,
                    cs.case_id,
                    cs.case_number,
                    cs.case_status,
                    (
                        SELECT GROUP_CONCAT(
                            TRIM(CONCAT_WS(' ', r_resp.first_name, NULLIF(r_resp.middle_name, ''), r_resp.last_name, r_resp.suffix))
                            ORDER BY cp_resp.party_id SEPARATOR ', '
                        )
                        FROM complaint_parties cp_resp
                        INNER JOIN residents r_resp ON r_resp.resident_id = cp_resp.resident_id
                        WHERE cp_resp.complaint_id = c.complaint_id
                          AND cp_resp.party_type = 'Respondent'
                    ) AS respondent_name
                FROM complaints c
                LEFT JOIN complaint_categories cc
                    ON c.category_id = cc.category_id
                LEFT JOIN complaint_categories cc2
                    ON c.secondary_category_id = cc2.category_id
                LEFT JOIN cases cs
                    ON cs.complaint_id = c.complaint_id
                {$whereSql}
                ORDER BY c.created_at DESC
            ");

            $stmt->execute($params);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $lifecycle = new ComplaintLifecycleService($this->conn);
            foreach ($rows as &$row) {
                $summary = $lifecycle->resolveSummaryForComplaint((int) $row['complaint_id']);
                $row['lifecycle_status'] = $summary['lifecycle_status'];
                $row['workflow_stage'] = $summary['workflow_stage'];
                if (!empty($row['other_category_specification']) && (stripos($row['category_name'] ?? '', 'Others') !== false || stripos($row['category_name'] ?? '', 'Iba pa') !== false)) {
                    $row['category_name_display'] = 'Others: ' . $row['other_category_specification'];
                } else {
                    $row['category_name_display'] = $row['category_name'] ?? '';
                }
                if (!empty($row['secondary_category_id']) || !empty($row['secondary_other_category_specification'])) {
                    $secDisplay = (!empty($row['secondary_other_category_specification']) && (stripos($row['secondary_category_name'] ?? '', 'Others') !== false || stripos($row['secondary_category_name'] ?? '', 'Iba pa') !== false))
                        ? 'Others: ' . $row['secondary_other_category_specification']
                        : ($row['secondary_category_name'] ?? $row['secondary_other_category_specification'] ?? '');
                    if (!empty($secDisplay)) {
                        $row['category_name_display'] .= ' & ' . $secDisplay;
                    }
                }
            }
            unset($row);
            return $rows;

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return [];
        }
    }

    public function getById(int $id): array|false
    {
        try {

            $stmt =
            $this->conn->prepare("
                SELECT c.*, cs.case_id, cs.case_number, cs.case_status, cat.category_name, cat2.category_name AS secondary_category_name
                FROM complaints c
                LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
                LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
                LEFT JOIN complaint_categories cat2 ON cat2.category_id = c.secondary_category_id
                WHERE c.complaint_id=?
            ");

            $stmt->execute([$id]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $summary = (new ComplaintLifecycleService($this->conn))->resolveSummaryForComplaint((int) $row['complaint_id']);
                $row['lifecycle_status'] = $summary['lifecycle_status'];
                $row['workflow_stage'] = $summary['workflow_stage'];
                $screening = $this->conn->prepare('SELECT * FROM complaint_intake_screenings WHERE complaint_id = ?');
                $screening->execute([(int) $row['complaint_id']]);
                $row['intake_screening'] = $screening->fetch(PDO::FETCH_ASSOC) ?: null;
                $row['ai_suggestion'] = null;
                if (!empty($row['intake_screening']['ai_suggestion_json'])) {
                    $decodedSug = json_decode((string) $row['intake_screening']['ai_suggestion_json'], true);
                    if (is_array($decodedSug)) {
                        $row['ai_suggestion'] = $decodedSug;
                    }
                }
                require_once __DIR__ . '/ComplaintScannedDocument.php';
                $scannedDocModel = new ComplaintScannedDocument($this->conn);
                $row['scanned_document'] = $scannedDocModel->getCurrentByComplaint((int) $row['complaint_id']);
                $row['scan_history'] = $scannedDocModel->getHistoryByComplaint((int) $row['complaint_id']);
                if (!empty($row['other_category_specification']) && (stripos($row['category_name'] ?? '', 'Others') !== false || stripos($row['category_name'] ?? '', 'Iba pa') !== false)) {
                    $row['category_name_display'] = 'Others: ' . $row['other_category_specification'];
                } else {
                    $row['category_name_display'] = $row['category_name'] ?? '';
                }
                if (!empty($row['secondary_category_id']) || !empty($row['secondary_other_category_specification'])) {
                    $secDisplay = (!empty($row['secondary_other_category_specification']) && (stripos($row['secondary_category_name'] ?? '', 'Others') !== false || stripos($row['secondary_category_name'] ?? '', 'Iba pa') !== false))
                        ? 'Others: ' . $row['secondary_other_category_specification']
                        : ($row['secondary_category_name'] ?? $row['secondary_other_category_specification'] ?? '');
                    if (!empty($secDisplay)) {
                        $row['category_name_display'] .= ' & ' . $secDisplay;
                    }
                }
            }
            return $row;

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }

    public function create(array $data): array
    {
        try {
            if (!empty($data['incident_datetime'])) {
                $rawDt = trim((string) $data['incident_datetime']);
                $dtObj = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d', $rawDt);
                if ($dtObj) {
                    $data['incident_date'] = $dtObj->format('Y-m-d');
                    $data['incident_time'] = (str_contains($rawDt, 'T') || str_contains($rawDt, ' ') || str_contains($rawDt, ':')) ? $dtObj->format('H:i') : null;
                }
            }

            if (empty($data['complaint_title']) && !empty($data['title'])) {
                $data['complaint_title'] = $data['title'];
            }
            if (empty($data['narrative']) && !empty($data['complaint_description'])) {
                $data['narrative'] = $data['complaint_description'];
            }
            if (empty($data['incident_street']) && !empty($data['incident_location'])) {
                $data['incident_street'] = $data['incident_location'];
            }

            $validation = $this->validateIncidentInput($data);
            if (!$validation['success']) {
                return $validation;
            }
            $mapValidation = $this->validateMapLocationInput($data, false);
            if (!$mapValidation['success']) {
                return $mapValidation;
            }
            $partiesValidation = $this->validatePartiesInput($data);
            if (!$partiesValidation['success']) {
                return $partiesValidation;
            }
            $rawSource = trim((string) ($data['complaint_source'] ?? 'Walk-in'));
            $complaintSource = strcasecmp($rawSource, 'Online') === 0 ? 'Online' : 'Walk-in';
            $data['complaint_source'] = $complaintSource;
            $isOnlineSubmission = ($complaintSource === 'Online');

            $jurisdictionValidation = $this->validateJurisdictionInput($data);
            if (!$jurisdictionValidation['success']) {
                return $jurisdictionValidation;
            }
            $isDraft = !empty($data['is_draft']) ? 1 : 0;
            if (!$isDraft) {
                $duplicates = $this->findPotentialDuplicates($data);
                if ($duplicates !== [] && empty($data['duplicate_acknowledged'])) {
                    if ($isOnlineSubmission) {
                        $dupRefs = array_values(array_filter(array_map(
                            static fn (array $d): string => trim((string) ($d['complaint_number'] ?? '')),
                            $duplicates
                        )));
                        $dupNote = 'Possible prior complaint(s) for LISO review' . ($dupRefs ? (': ' . implode(', ', $dupRefs)) : '.');
                        $mergedNotes = trim(implode("\n", array_filter([
                            trim((string) ($data['screening_notes'] ?? '')),
                            $dupNote,
                        ])));
                        $data['screening_notes'] = mb_substr($mergedNotes, 0, 2000);
                    } else {
                        return [
                            'success' => false,
                            'code' => 'POSSIBLE_DUPLICATE_REQUIRES_ACKNOWLEDGEMENT',
                            'message' => 'A possible duplicate complaint was found. Link the walk-in visit to the existing record or confirm before filing a separate record.',
                            'duplicates' => $duplicates,
                        ];
                    }
                }
            }

            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }

            $sessionUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $actorUserId = $sessionUserId ?: (PHP_SAPI === 'cli' ? ((int) ($data['created_by'] ?? $data['encoded_by'] ?? $data['clerk_id'] ?? 1)) : null);
            if (!$actorUserId) {
                return ['success' => false, 'message' => 'Authentication required to submit a complaint.'];
            }

            $linkedToId = filter_var($data['linked_to_complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $initialStatus = ($isOnlineSubmission && !$isDraft) ? 'Under Review' : 'Filed';

            $caseType = in_array(trim((string)($data['case_type'] ?? '')), ['Civil', 'Criminal'], true)
                ? trim((string)$data['case_type'])
                : 'Civil';

            $this->conn->beginTransaction();
            $cols = [
                'category_id', 'secondary_category_id', 'other_category_specification', 'secondary_other_category_specification',
                'case_type', 'complaint_title', 'incident_date', 'incident_time', 'is_time_approximate', 'is_time_unknown',
                'incident_location', 'incident_city', 'incident_barangay', 'incident_street', 'incident_purok',
                'incident_landmark', 'incident_location_details', 'narrative', 'additional_details', 'requested_resolution',
                'status', 'document_status', 'is_draft', 'encoded_by'
            ];
            $timeVal = !empty($data['is_time_unknown']) ? null : (trim((string) ($data['incident_time'] ?? '')) ?: null);
            $secCatId = filter_var($data['secondary_category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $secOtherSpec = trim((string)($data['secondary_other_category_specification'] ?? '')) ?: null;
            $vals = [
                $data['category_id'],
                $secCatId,
                trim((string) ($data['other_category_specification'] ?? '')) ?: null,
                $secOtherSpec,
                $caseType,
                trim((string) $data['complaint_title']),
                $data['incident_date'],
                $timeVal,
                !empty($data['is_time_approximate']) ? 1 : 0,
                !empty($data['is_time_unknown']) ? 1 : 0,
                $this->buildIncidentAddress($data),
                (stripos(trim((string)($data['incident_city'] ?? '')), 'Marikina') !== false ? 'Marikina City' : trim((string)($data['incident_city'] ?? 'Marikina City'))),
                trim((string) ($data['incident_barangay'] ?? 'Tumana')),
                trim((string) ($data['incident_street'] ?? '')) ?: null,
                trim((string) ($data['incident_purok'] ?? '')) ?: null,
                trim((string) ($data['incident_landmark'] ?? '')) ?: null,
                trim((string) ($data['incident_location_details'] ?? '')) ?: null,
                trim((string) $data['narrative']),
                trim((string) ($data['additional_details'] ?? '')) ?: null,
                trim((string) ($data['requested_resolution'] ?? '')) ?: null,
                $initialStatus,
                'No Scan Uploaded',
                $isDraft,
                ($complaintSource === 'Walk-in' || !$this->hasComplaintColumn('created_by')) ? $actorUserId : null
            ];

            if ($this->hasComplaintColumn('complaint_source')) {
                $cols[] = 'complaint_source';
                $vals[] = $complaintSource;
            }
            if ($this->hasComplaintColumn('linked_to_complaint_id')) {
                $cols[] = 'linked_to_complaint_id';
                $vals[] = $linkedToId;
            }
            if ($this->hasComplaintColumn('created_by')) {
                $cols[] = 'created_by';
                $vals[] = $actorUserId;
            }

            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $stmt = $this->conn->prepare('INSERT INTO complaints (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')');
            $stmt->execute($vals);

            $complaintId = (int) $this->conn->lastInsertId();
            $complaintNumber = sprintf('CMP-%s-%04d', date('Y'), $complaintId);
            $numberStatement = $this->conn->prepare(
                'UPDATE complaints SET complaint_number = ?, status = ?, paanyaya_attempt_count = 0, max_paanyaya_reached = 0, resolved_without_docketing = 0 WHERE complaint_id = ?'
            );
            $numberStatement->execute([$complaintNumber, $initialStatus, $complaintId]);
            $this->applyMapLocation($complaintId, $data);
            $this->applyParties($complaintId, $data);
            $this->saveIntakeScreening($complaintId, $data);

            if (!$isDraft) {
                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notifService = new NotificationService();
                    $actionUrl = '../complaints/complaint-details.php?id=' . (int) $complaintId;
                    $notifService->notifyRoles(
                        ['Administrator', 'Lupon Clerk', 'LISO'],
                        "New {$complaintSource} Complaint Filed",
                        "New {$complaintSource} complaint {$complaintNumber} (" . ($data['complaint_title'] ?? 'General Dispute') . ') submitted for preliminary LISO verification before mandatory Paanyaya.',
                        $actorUserId,
                        'info',
                        $actionUrl
                    );
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch new complaint notification: ' . $ne->getMessage());
                }
            }

            $this->conn->commit();
            return [
                'success' => true,
                'message' => $isDraft
                    ? "Draft saved under Reference ID {$complaintNumber}."
                    : ($isOnlineSubmission
                        ? "Your complaint ({$complaintNumber}) has been submitted and is now For Review by Barangay Tumana."
                        : "Complaint {$complaintNumber} ({$complaintSource}) filed and queued for LISO verification before mandatory Paanyaya."),
                'case_id' => null,
                'case_number' => null,
                'status' => $initialStatus,
                'complaint_source' => $complaintSource,
                'complaint_id' => $complaintId,
                'complaint_number' => $complaintNumber,
                'is_draft' => (bool) $isDraft
            ];

        }
        catch(Exception $e)
        {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            error_log(
                $e->getMessage()
            );

            return ['success' => false, 'message' => 'Unable to create the complaint. Please check the provided information and try again.'];
        }
    }

    public function update(mixed $id, array $data): array
    {
        try {

            $complaintId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$complaintId) {
                return ['success' => false, 'message' => 'Select a valid complaint to update.'];
            }

            if (!empty($data['incident_datetime'])) {
                $rawDt = trim((string) $data['incident_datetime']);
                $dtObj = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d', $rawDt);
                if ($dtObj) {
                    $data['incident_date'] = $dtObj->format('Y-m-d');
                    $data['incident_time'] = (str_contains($rawDt, 'T') || str_contains($rawDt, ' ') || str_contains($rawDt, ':')) ? $dtObj->format('H:i') : null;
                }
            }

            if (empty($data['complaint_title']) && !empty($data['title'])) {
                $data['complaint_title'] = $data['title'];
            }
            if (empty($data['narrative']) && !empty($data['complaint_description'])) {
                $data['narrative'] = $data['complaint_description'];
            }
            if (empty($data['incident_street']) && !empty($data['incident_location'])) {
                $data['incident_street'] = $data['incident_location'];
            }

            $hasSourceCol = $this->hasComplaintColumn('complaint_source');
            $sourceSelect = $hasSourceCol ? ', complaint_source' : '';
            $exists = $this->conn->prepare("SELECT complaint_id, complaint_number, status, is_draft{$sourceSelect} FROM complaints WHERE complaint_id = ?");
            $exists->execute([$complaintId]);
            $existingRow = $exists->fetch(PDO::FETCH_ASSOC);
            if (!$existingRow) {
                return ['success' => false, 'message' => 'The complaint could not be found.'];
            }

            $rawSource = trim((string) ($data['complaint_source'] ?? ($existingRow['complaint_source'] ?? 'Walk-in')));
            $complaintSource = strcasecmp($rawSource, 'Online') === 0 ? 'Online' : 'Walk-in';
            $data['complaint_source'] = $complaintSource;
            $isOnlineSubmission = ($complaintSource === 'Online');
            $isDraft = array_key_exists('is_draft', $data) ? (!empty($data['is_draft']) ? 1 : 0) : 0;
            $wasDraft = !empty($existingRow['is_draft']);
            $existingStatus = trim((string) ($existingRow['status'] ?? 'Filed'));

            $validation = $this->validateIncidentInput($data);
            if (!$validation['success']) {
                return $validation;
            }
            $mapValidation = $this->validateMapLocationInput($data, true);
            if (!$mapValidation['success']) {
                return $mapValidation;
            }
            $partiesValidation = $this->validatePartiesInput($data);
            if (!$partiesValidation['success']) {
                return $partiesValidation;
            }
            // Screening is required when filing. Older records can be
            // corrected without replacing their saved intake assessment.
            if (array_key_exists('jurisdiction_screened', $data) || $isOnlineSubmission) {
                $jurisdictionValidation = $this->validateJurisdictionInput($data);
                if (!$jurisdictionValidation['success']) {
                    return $jurisdictionValidation;
                }
            }
            if (!$isDraft) {
                $duplicates = $this->findPotentialDuplicates($data, $complaintId);
                if ($duplicates !== [] && empty($data['duplicate_acknowledged'])) {
                    if ($isOnlineSubmission) {
                        $dupRefs = array_values(array_filter(array_map(
                            static fn (array $d): string => trim((string) ($d['complaint_number'] ?? '')),
                            $duplicates
                        )));
                        $dupNote = 'Possible prior complaint(s) for LISO review' . ($dupRefs ? (': ' . implode(', ', $dupRefs)) : '.');
                        $mergedNotes = trim(implode("\n", array_filter([
                            trim((string) ($data['screening_notes'] ?? '')),
                            $dupNote,
                        ])));
                        $data['screening_notes'] = mb_substr($mergedNotes, 0, 2000);
                    } else {
                        return [
                            'success' => false,
                            'code' => 'POSSIBLE_DUPLICATE_REQUIRES_ACKNOWLEDGEMENT',
                            'message' => 'A possible duplicate complaint was found. Review it and confirm that this filing should proceed.',
                            'duplicates' => $duplicates,
                        ];
                    }
                }
            }

            $nextStatus = $existingStatus !== '' ? $existingStatus : 'Filed';
            if ($isDraft === 1) {
                $nextStatus = 'Filed';
            } elseif ($isOnlineSubmission && ($wasDraft || in_array($existingStatus, ['Filed', 'Needs Information'], true))) {
                $nextStatus = 'Under Review';
            } elseif (!$isOnlineSubmission && $wasDraft) {
                $nextStatus = 'Filed';
            }

            $caseType = in_array(trim((string)($data['case_type'] ?? '')), ['Civil', 'Criminal'], true)
                ? trim((string)$data['case_type'])
                : 'Civil';

            $this->conn->beginTransaction();
            $stmt =
            $this->conn->prepare("
                UPDATE complaints
                SET
                    category_id=?,
                    secondary_category_id=?,
                    other_category_specification=?,
                    secondary_other_category_specification=?,
                    case_type=?,
                    complaint_title=?,
                    incident_date=?,
                    incident_time=?,
                    is_time_approximate=?,
                    is_time_unknown=?,
                    incident_location=?,
                    incident_city=?,
                    incident_barangay=?,
                    incident_street=?,
                    incident_purok=?,
                    incident_landmark=?,
                    incident_location_details=?,
                    narrative=?,
                    additional_details=?,
                    requested_resolution=?,
                    status=?,
                    is_draft=?
                WHERE complaint_id=?
            ");

            $timeVal = !empty($data['is_time_unknown']) ? null : (trim((string) ($data['incident_time'] ?? '')) ?: null);
            $secCatId = filter_var($data['secondary_category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $secOtherSpec = trim((string)($data['secondary_other_category_specification'] ?? '')) ?: null;
            $stmt->execute([
                $data['category_id'],
                $secCatId,
                trim((string) ($data['other_category_specification'] ?? '')) ?: null,
                $secOtherSpec,
                $caseType,
                trim((string) $data['complaint_title']),
                $data['incident_date'],
                $timeVal,
                !empty($data['is_time_approximate']) ? 1 : 0,
                !empty($data['is_time_unknown']) ? 1 : 0,
                $this->buildIncidentAddress($data),
                (stripos(trim((string)($data['incident_city'] ?? '')), 'Marikina') !== false ? 'Marikina City' : trim((string)($data['incident_city'] ?? 'Marikina City'))),
                trim((string) ($data['incident_barangay'] ?? 'Tumana')),
                trim((string) ($data['incident_street'] ?? '')) ?: null,
                trim((string) ($data['incident_purok'] ?? '')) ?: null,
                trim((string) ($data['incident_landmark'] ?? '')) ?: null,
                trim((string) ($data['incident_location_details'] ?? '')) ?: null,
                trim((string) $data['narrative']),
                trim((string) ($data['additional_details'] ?? '')) ?: null,
                trim((string) ($data['requested_resolution'] ?? '')) ?: null,
                $nextStatus,
                $isDraft,
                $complaintId
            ]);

            $updateCase = $this->conn->prepare("
                UPDATE cases
                SET case_type = ?
                WHERE complaint_id = ? AND case_status IN ('Docketed', 'Mediation')
            ");
            $updateCase->execute([$caseType, $complaintId]);

            $this->applyMapLocation($complaintId, $data);

            if (isset($data['complainant_resident_id']) || isset($data['respondent_resident_id']) || isset($data['complainant_name']) || isset($data['respondent_name']) || isset($data['complainant_first_name']) || isset($data['respondent_first_name'])) {
                $delParties = $this->conn->prepare('DELETE FROM complaint_parties WHERE complaint_id = ?');
                $delParties->execute([$complaintId]);
                $this->applyParties($complaintId, $data);
            }
            if (array_key_exists('jurisdiction_screened', $data) || $isOnlineSubmission || isset($data['ai_suggestion_json'])) {
                $this->saveIntakeScreening($complaintId, $data);
            }
            if (array_key_exists('jurisdiction_screened', $data)) {
                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notifService = new NotificationService();
                    $cNum = (string) ($existingRow['complaint_number'] ?? '') ?: "CMP-#{$complaintId}";
                    $actionUrl = "../complaints/complaint-details.php?id=" . (int) $complaintId . "&tab=mediation";
                    $notifService->notifyRoles(
                        ['Administrator', 'Lupon Clerk'],
                        'New Complaint Filed',
                        "New complaint {$cNum} (" . ($data['complaint_title'] ?? 'General Dispute') . ") has been officially filed and placed into Mediation (Stage 1).",
                        $_SESSION['user_id'] ?? null,
                        'info',
                        $actionUrl
                    );
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch new complaint notification: ' . $ne->getMessage());
                }
            } elseif ($isOnlineSubmission && $wasDraft && !$isDraft) {
                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notifService = new NotificationService();
                    $cNum = (string) ($existingRow['complaint_number'] ?? '') ?: "CMP-#{$complaintId}";
                    $actionUrl = '../complaints/complaint-details.php?id=' . (int) $complaintId;
                    $notifService->notifyRoles(
                        ['Administrator', 'Lupon Clerk', 'LISO'],
                        'New Online Complaint Filed',
                        "New Online complaint {$cNum} (" . ($data['complaint_title'] ?? 'General Dispute') . ') submitted for preliminary LISO verification before mandatory Paanyaya.',
                        $_SESSION['user_id'] ?? null,
                        'info',
                        $actionUrl
                    );
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch online draft submission notification: ' . $ne->getMessage());
                }
            }

            $this->conn->commit();

            $cNum = (string) ($existingRow['complaint_number'] ?? '') ?: sprintf('CMP-%s-%04d', date('Y'), $complaintId);
            $successMsg = 'Complaint updated successfully.';
            if ($isDraft) {
                $successMsg = "Draft saved under Reference ID {$cNum}.";
            } elseif ($isOnlineSubmission && $wasDraft) {
                $successMsg = "Your complaint ({$cNum}) has been submitted and is now For Review by Barangay Tumana.";
            }

            return [
                'success' => true,
                'message' => $successMsg,
                'complaint_id' => $complaintId,
                'complaint_number' => $cNum,
                'status' => $nextStatus,
                'complaint_source' => $complaintSource,
                'is_draft' => (bool) $isDraft,
            ];

        }
        catch(Exception $e)
        {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log(
                $e->getMessage()
            );

            return ['success' => false, 'message' => 'Unable to update the complaint. Please try again.'];
        }
    }

    public function review(int $id, string $status, ?string $notes, array $classificationData = []): array
    {
        $allowed = ['Under Review', 'Needs Information', 'Accepted', 'Rejected'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'Select a valid review decision.'];
        }

        $aiUsed = in_array($classificationData['ai_classification_used'] ?? null, [true, 1, '1', 'true'], true);
        if ($aiUsed && !in_array($classificationData['ai_classification_verified'] ?? null, [true, 1, '1', 'true'], true)) {
            return [
                'success' => false,
                'code' => 'AI_CLASSIFICATION_VERIFICATION_REQUIRED',
                'message' => 'Human verification required: review the original narrative and check the mandatory confirmation box before saving AI-assisted classification.',
            ];
        }

        $lockedStatuses = [
            'Pre-Docketed (Paanyaya)', 'Paanyaya In Progress', '1st Paanyaya Failed (No-Show)',
            'Resolved at Paanyaya / Closed', 'Settled', 'Dismissed', 'Docketed', 'Mediation',
            'Conciliation', 'Arbitration', 'CFA Issued', 'Archived'
        ];
        $placeholders = implode(',', array_fill(0, count($lockedStatuses), '?'));
        $exists = $this->conn->prepare(
            "SELECT c.complaint_id, c.complaint_number, c.complaint_title FROM complaints c LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id WHERE c.complaint_id = ? AND cs.case_id IS NULL AND c.status NOT IN ({$placeholders}) LIMIT 1"
        );
        $exists->execute(array_merge([$id], $lockedStatuses));
        $row = $exists->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'message' => 'This complaint has already entered Paanyaya or formal proceedings and can no longer be changed during screening.'];
        }

        $setClauses = ['status = ?', 'review_notes = ?', 'is_draft = 0'];
        $params = [$status, $notes];

        if (!empty($classificationData['category_id'])) {
            $catId = filter_var($classificationData['category_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$catId) {
                return ['success' => false, 'message' => 'Select a valid complaint category.'];
            }
            $catStmt = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_id = ? AND COALESCE(is_active, 1) = 1 LIMIT 1');
            $catStmt->execute([$catId]);
            $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
            if (!$catRow) {
                return ['success' => false, 'message' => 'Selected complaint category was not found or is inactive.'];
            }
            $isOther = (stripos((string) $catRow['category_name'], 'Others') !== false || stripos((string) $catRow['category_name'], 'Iba pa') !== false);
            $otherSpec = trim((string) ($classificationData['other_category_specification'] ?? ''));
            if ($isOther) {
                if ($otherSpec === '' || mb_strlen($otherSpec) > 255 || !ValidationService::text($otherSpec)) {
                    return ['success' => false, 'message' => 'Specify valid custom category details under "Others (Please Specify)".'];
                }
            }
            $setClauses[] = 'category_id = ?';
            $params[] = (int) $catId;
            $setClauses[] = 'other_category_specification = ?';
            $params[] = $isOther ? $otherSpec : null;
        }

        if (array_key_exists('secondary_category_id', $classificationData)) {
            $rawSecCat = trim((string) ($classificationData['secondary_category_id'] ?? ''));
            if ($rawSecCat === '') {
                $setClauses[] = 'secondary_category_id = ?';
                $params[] = null;
                $setClauses[] = 'secondary_other_category_specification = ?';
                $params[] = null;
            } else {
                $secCatId = filter_var($rawSecCat, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$secCatId) {
                    return ['success' => false, 'message' => 'Select a valid 2nd complaint category.'];
                }
                $effectivePrimaryCatId = !empty($classificationData['category_id'])
                    ? (int) $classificationData['category_id']
                    : (int) ($this->conn->query("SELECT category_id FROM complaints WHERE complaint_id = {$id}")->fetchColumn() ?: 0);
                if ($secCatId === $effectivePrimaryCatId) {
                    return ['success' => false, 'message' => '2nd complaint category must be different from the primary category.'];
                }
                $secStmt = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_id = ? AND COALESCE(is_active, 1) = 1 LIMIT 1');
                $secStmt->execute([$secCatId]);
                $secRow = $secStmt->fetch(PDO::FETCH_ASSOC);
                if (!$secRow) {
                    return ['success' => false, 'message' => 'Selected 2nd complaint category was not found or is inactive.'];
                }
                $isSecOther = (stripos((string) $secRow['category_name'], 'Others') !== false || stripos((string) $secRow['category_name'], 'Iba pa') !== false);
                $secOtherSpec = trim((string) ($classificationData['secondary_other_category_specification'] ?? ''));
                if ($isSecOther && ($secOtherSpec === '' || mb_strlen($secOtherSpec) > 255 || !ValidationService::text($secOtherSpec))) {
                    return ['success' => false, 'message' => 'Specify valid custom details for the 2nd "Others (Please Specify)" category.'];
                }
                $setClauses[] = 'secondary_category_id = ?';
                $params[] = (int) $secCatId;
                $setClauses[] = 'secondary_other_category_specification = ?';
                $params[] = $isSecOther ? $secOtherSpec : null;
            }
        }

        if (!empty($classificationData['case_type'])) {
            $cType = trim((string) $classificationData['case_type']);
            if (!in_array($cType, ['Civil', 'Criminal'], true)) {
                return ['success' => false, 'message' => 'Case type must be Civil or Criminal.'];
            }
            $setClauses[] = 'case_type = ?';
            $params[] = $cType;
        }

        if (isset($classificationData['complaint_title']) && trim((string) $classificationData['complaint_title']) !== '') {
            $cTitle = trim((string) $classificationData['complaint_title']);
            if (mb_strlen($cTitle) > 255 || !ValidationService::text($cTitle)) {
                return ['success' => false, 'message' => 'Provide a valid complaint title of 255 characters or fewer.'];
            }
            $setClauses[] = 'complaint_title = ?';
            $params[] = $cTitle;
        }

        $params[] = $id;
        $stmt = $this->conn->prepare('UPDATE complaints SET ' . implode(', ', $setClauses) . ' WHERE complaint_id = ?');
        $stmt->execute($params);

        if ($status === 'Accepted') {
            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $cNum = $row['complaint_number'] ?: "CMP-#{$id}";
                (new NotificationService())->notifyRoles(
                    ['Administrator', 'Lupon Clerk', 'LISO'],
                    'Complaint Verified — Ready for Paanyaya',
                    "Complaint {$cNum} passed LISO preliminary verification and is ready for mandatory Paanyaya scheduling.",
                    $_SESSION['user_id'] ?? null,
                    'info',
                    '../paanyaya/stage.php?complaint_id=' . $id
                );
            } catch (Throwable $ne) {
                error_log('Failed to dispatch verification notification: ' . $ne->getMessage());
            }
        }

        return [
            'success' => true,
            'status' => $status,
            'message' => $status === 'Accepted'
                ? 'Complaint verified and endorsed for mandatory Paanyaya.'
                : 'Complaint review saved.'
        ];
    }

    public function delete(mixed $id): bool
    {
        try {

            $stmt =
            $this->conn->prepare("
                DELETE FROM complaints
                WHERE complaint_id=?
            ");

            return $stmt->execute([
                $id
            ]);

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }

    public function archive(mixed $id): bool
    {
        try {
            $stmt = $this->conn->prepare("
                UPDATE complaints
                SET status = 'Archived'
                WHERE complaint_id = ?
            ");
            $success = $stmt->execute([$id]);

            // Also synchronize any linked case status to Archived
            if ($success) {
                try {
                    $caseStmt = $this->conn->prepare("
                        UPDATE cases
                        SET case_status = 'Archived', archived_date = CURDATE()
                        WHERE complaint_id = ? AND case_status != 'Archived'
                    ");
                    $caseStmt->execute([$id]);
                } catch (Exception $e) {
                    error_log('Error syncing case archive: ' . $e->getMessage());
                }
            }

            return $success;
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    private function validateIncidentInput(array &$data): array
    {
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $categoryNameInput = trim((string)($data['category_name'] ?? ''));
        $isOnlineSubmission = strcasecmp(trim((string)($data['complaint_source'] ?? '')), 'Online') === 0;
        if (!$categoryId && $categoryNameInput === '' && $isOnlineSubmission) {
            $defCatStmt = $this->conn->query("
                SELECT category_id, category_name
                FROM complaint_categories
                WHERE COALESCE(is_active, 1) = 1
                ORDER BY CASE WHEN category_name LIKE 'Others%' OR category_name LIKE 'Iba pa%' THEN 0 ELSE 1 END ASC, category_id ASC
                LIMIT 1
            ");
            $defCat = $defCatStmt ? $defCatStmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($defCat) {
                $categoryId = (int)$defCat['category_id'];
                $data['category_id'] = $categoryId;
                if ((stripos($defCat['category_name'], 'Others') !== false || stripos($defCat['category_name'], 'Iba pa') !== false) && empty($data['other_category_specification'])) {
                    $data['other_category_specification'] = 'Pending Barangay LISO Classification';
                }
            }
        }
        if (!$categoryId && $categoryNameInput !== '') {
            $catStmt = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_name = ? OR category_name LIKE ? LIMIT 1');
            $catStmt->execute([$categoryNameInput, '%' . $categoryNameInput . '%']);
            $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
            if ($catRow) {
                $categoryId = (int)$catRow['category_id'];
                $data['category_id'] = $categoryId;
            } else {
                $categoryId = 51;
                $data['category_id'] = 51;
                if (empty($data['other_category_specification'])) {
                    $data['other_category_specification'] = $categoryNameInput;
                }
            }
        }

        $secCatId = filter_var($data['secondary_category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $secNameInput = trim((string)($data['secondary_category_name'] ?? ''));
        if (!$secCatId && $secNameInput !== '') {
            $catStmt2 = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_name = ? OR category_name LIKE ? LIMIT 1');
            $catStmt2->execute([$secNameInput, '%' . $secNameInput . '%']);
            $catRow2 = $catStmt2->fetch(PDO::FETCH_ASSOC);
            if ($catRow2) {
                $secCatId = (int)$catRow2['category_id'];
                $data['secondary_category_id'] = $secCatId;
            } else {
                $secCatId = 51;
                $data['secondary_category_id'] = 51;
                if (empty($data['secondary_other_category_specification'])) {
                    $data['secondary_other_category_specification'] = $secNameInput;
                }
            }
        }

        $title = trim((string) ($data['complaint_title'] ?? $data['title'] ?? ''));
        if ($title === '' && $categoryNameInput !== '') {
            $title = $categoryNameInput . ($secNameInput !== '' ? ' & ' . $secNameInput : '');
            $data['complaint_title'] = $title;
        }
        $date = trim((string) ($data['incident_date'] ?? ''));
        $time = trim((string) ($data['incident_time'] ?? ''));
        $narrative = trim((string) ($data['narrative'] ?? $data['complaint_description'] ?? ''));
        if ($title === '' && $isOnlineSubmission) {
            $narrSingle = preg_replace('/\s+/u', ' ', $narrative);
            if ($narrSingle !== '') {
                $title = mb_strlen($narrSingle, 'UTF-8') > 80
                    ? rtrim(mb_substr($narrSingle, 0, 77, 'UTF-8')) . '...'
                    : $narrSingle;
            } else {
                $title = 'Online Resident Complaint';
            }
            $data['complaint_title'] = $title;
        }

        if ($date === '' && !empty($data['incident_datetime'])) {
            $rawDt = trim((string) $data['incident_datetime']);
            $dtObj = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d', $rawDt);
            if ($dtObj) {
                $date = $dtObj->format('Y-m-d');
                $time = (str_contains($rawDt, 'T') || str_contains($rawDt, ' ') || str_contains($rawDt, ':')) ? $dtObj->format('H:i') : '';
            }
        }

        if (!$categoryId) return ['success' => false, 'message' => 'Select a valid complaint category.'];
        $catStmt = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_id = ?');
        $catStmt->execute([$categoryId]);
        $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
        if (!$catRow) return ['success' => false, 'message' => 'Select a valid complaint category.'];

        $isOtherCategory = (stripos($catRow['category_name'], 'Others') !== false || stripos($catRow['category_name'], 'Iba pa') !== false);
        $otherSpec = trim((string)($data['other_category_specification'] ?? ''));
        if ($isOtherCategory) {
            if ($otherSpec === '') {
                return ['success' => false, 'message' => 'Please specify the complaint category under "Others (Please Specify)".'];
            }
            if (mb_strlen($otherSpec) > 255) {
                return ['success' => false, 'message' => 'The custom category specification must be 255 characters or fewer.'];
            }
            if (!ValidationService::text($otherSpec)) {
                return ['success' => false, 'message' => 'Please remove unsupported control characters from the custom category specification.'];
            }
        }
        if ($secCatId !== null) {
            if ((int) $secCatId === (int) $categoryId) {
                return ['success' => false, 'message' => '2nd complaint category must be different from the primary category.'];
            }
            $secStmt = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_id = ?');
            $secStmt->execute([$secCatId]);
            $secRow = $secStmt->fetch(PDO::FETCH_ASSOC);
            if (!$secRow) {
                return ['success' => false, 'message' => 'Select a valid 2nd complaint category.'];
            }
            $isSecOther = (stripos($secRow['category_name'], 'Others') !== false || stripos($secRow['category_name'], 'Iba pa') !== false);
            $secOtherSpec = trim((string)($data['secondary_other_category_specification'] ?? ''));
            if ($isSecOther && ($secOtherSpec === '' || mb_strlen($secOtherSpec) > 255 || !ValidationService::text($secOtherSpec))) {
                return ['success' => false, 'message' => 'Specify valid custom details for the 2nd complaint category under "Others (Please Specify)".'];
            }
        }
        $aiClassificationUsed = in_array($data['ai_classification_used'] ?? null, [true, 1, '1', 'true'], true);
        if ($aiClassificationUsed) {
            $aiClassificationVerified = in_array($data['ai_classification_verified'] ?? null, [true, 1, '1', 'true'], true);
            if (!$aiClassificationVerified) {
                return [
                    'success' => false,
                    'code' => 'AI_CLASSIFICATION_VERIFICATION_REQUIRED',
                    'message' => 'Human verification required: please confirm that the selected complaint category, case type, and complaint title match the narrative before saving.',
                ];
            }
        }
        if ($title === '') return ['success' => false, 'message' => 'Complaint title is required.'];
        if (mb_strlen($title) > 255) return ['success' => false, 'message' => 'Complaint title must be 255 characters or fewer.'];
        if (!ValidationService::text($title)) return ['success' => false, 'message' => 'Please remove unsupported characters from the complaint title.'];
        if ($date === '') return ['success' => false, 'message' => 'Incident date is required.'];
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) return ['success' => false, 'message' => 'Incident date is invalid.'];
        if ($time !== '' && !preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $time)) return ['success' => false, 'message' => 'Incident time is invalid.'];

        // Time restrictions for incident date and time disabled for complaint intake
        // (Allows intake without strict clock-skew or future time rejections)
        $city = trim((string) ($data['incident_city'] ?? 'Marikina City')) ?: 'Marikina City';
        $barangay = trim((string) ($data['incident_barangay'] ?? 'Tumana')) ?: 'Tumana';
        $street = trim((string) ($data['incident_street'] ?? $data['incident_location'] ?? ''));
        if ($city === '' || stripos($city, 'Marikina') === false) return ['success' => false, 'message' => 'Incident city must be Marikina City.'];
        if ($barangay === '') return ['success' => false, 'message' => 'Incident barangay is required.'];
        if ($street === '') return ['success' => false, 'message' => 'Street or specific incident location is required.'];
        if (!ValidationService::address($street)) return ['success' => false, 'message' => 'Please remove unsupported control characters from the street address.'];
        if ($narrative === '') return ['success' => false, 'message' => 'Incident narrative is required.'];
        if (mb_strlen($narrative) > 15000) return ['success' => false, 'message' => 'Narrative is too long. Use 15,000 characters or fewer.'];
        if (!ValidationService::text($narrative, true)) return ['success' => false, 'message' => 'Please remove unsupported control characters from the incident narrative.'];
        if (isset($data['offense_fine_max']) && trim((string) $data['offense_fine_max']) !== '' && ValidationService::decimal($data['offense_fine_max'], 0.0, 999999999.99) === null) {
            return ['success' => false, 'message' => 'Estimated claim / fine amount must be a valid non-negative number.'];
        }
        if (isset($data['offense_imprisonment_max']) && trim((string) $data['offense_imprisonment_max']) !== '' && ValidationService::decimal($data['offense_imprisonment_max'], 0.0, 100.0) === null) {
            return ['success' => false, 'message' => 'Imprisonment years must be a valid non-negative number (0 to 100).'];
        }
        foreach (['incident_city' => 100, 'incident_barangay' => 100, 'incident_street' => 255, 'incident_purok' => 100, 'incident_landmark' => 255, 'blotter_ref_no' => 100, 'incident_location_details' => 1000, 'requested_resolution' => 5000, 'additional_details' => 5000] as $field => $maxLength) {
            if (mb_strlen(trim((string) ($data[$field] ?? ''))) > $maxLength) {
                return ['success' => false, 'message' => ucwords(str_replace('_', ' ', $field)) . " must be {$maxLength} characters or fewer."];
            }
            if (!ValidationService::text(trim((string) ($data[$field] ?? '')), in_array($field, ['additional_details', 'requested_resolution', 'incident_location_details'], true))) {
                return ['success' => false, 'message' => 'Please remove unsupported control characters from ' . str_replace('_', ' ', $field) . '.'];
            }
        }
        return ['success' => true];
    }

    private function validateMapLocationInput(array $data, bool $isUpdate): array
    {
        $defaultState = $isUpdate ? 'unchanged' : 'none';
        $state = trim((string) ($data['map_location_state'] ?? $defaultState));
        $latitude = trim((string) ($data['location_latitude'] ?? ''));
        $longitude = trim((string) ($data['location_longitude'] ?? ''));

        if (!in_array($state, ['none', 'unchanged', 'selected', 'clear'], true)) {
            return ['success' => false, 'message' => 'Map location state is invalid.'];
        }
        if ($state === 'selected') {
            $latitudeValue = filter_var($latitude, FILTER_VALIDATE_FLOAT);
            $longitudeValue = filter_var($longitude, FILTER_VALIDATE_FLOAT);
            if ($latitude === '' || $longitude === '' || $latitudeValue === false || $longitudeValue === false
                || $latitudeValue < -90 || $latitudeValue > 90 || $longitudeValue < -180 || $longitudeValue > 180) {
                return ['success' => false, 'message' => 'Select a valid point on the map.'];
            }
            if ($latitudeValue < 14.610 || $latitudeValue > 14.690 || $longitudeValue < 121.075 || $longitudeValue > 121.155) {
                return ['success' => false, 'message' => 'Incident location must be within Marikina City.'];
            }
        } elseif ($state === 'unchanged') {
            if ($latitude !== '' || $longitude !== '') {
                $latitudeValue = filter_var($latitude, FILTER_VALIDATE_FLOAT);
                $longitudeValue = filter_var($longitude, FILTER_VALIDATE_FLOAT);
                if ($latitudeValue === false || $longitudeValue === false
                    || $latitudeValue < -90 || $latitudeValue > 90 || $longitudeValue < -180 || $longitudeValue > 180) {
                    return ['success' => false, 'message' => 'Map point coordinates are invalid.'];
                }
            }
        } elseif ($latitude !== '' || $longitude !== '') {
            return ['success' => false, 'message' => 'Select a map point or clear the map location.'];
        }

        return ['success' => true];
    }

    private function applyMapLocation(int $complaintId, array $data): void
    {
        $state = trim((string) ($data['map_location_state'] ?? 'none'));
        if ($state === 'unchanged') {
            $address = $this->buildIncidentAddress($data);
            $updateAddress = $this->conn->prepare('UPDATE incident_locations SET address = ? WHERE complaint_id = ?');
            $updateAddress->execute([$address, $complaintId]);
            return;
        }
        if ($state === 'none') {
            return;
        }
        if ($state === 'clear') {
            $delete = $this->conn->prepare('DELETE FROM incident_locations WHERE complaint_id = ?');
            $delete->execute([$complaintId]);
            return;
        }

        $latitude = (float) $data['location_latitude'];
        $longitude = (float) $data['location_longitude'];
        $address = $this->buildIncidentAddress($data);
        $stmt = $this->conn->prepare(
            'INSERT INTO incident_locations (complaint_id, latitude, longitude, address) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), address = VALUES(address)'
        );
        $stmt->execute([$complaintId, $latitude, $longitude, $address]);
    }

    private function buildIncidentAddress(array $data): string
    {
        return implode(', ', array_filter([
            trim((string) ($data['incident_street'] ?? '')),
            trim((string) ($data['incident_purok'] ?? '')),
            trim((string) ($data['incident_barangay'] ?? 'Tumana')),
            trim((string) ($data['incident_city'] ?? 'Marikina City')),
        ]));
    }

    private function isWithinTumana(float $latitude, float $longitude): bool
    {
        // Barangay Tumana boundary (OpenStreetMap relation 1225795, retrieved 2026-09-25).
        $polygon = [[14.6554682,121.0857081],[14.6562612,121.0859908],[14.6557911,121.0865123],[14.6566853,121.0867891],[14.6573361,121.0874608],[14.6566672,121.0882081],[14.6596216,121.0912009],[14.6605249,121.0911456],[14.6609324,121.0914765],[14.6617729,121.0920319],[14.6634173,121.0935248],[14.6639892,121.0936321],[14.6643486,121.0936995],[14.6645004,121.0938826],[14.6646918,121.0941136],[14.6649347,121.0948585],[14.6652335,121.0951488],[14.6652695,121.0952371],[14.6652424,121.0956829],[14.6648805,121.0961861],[14.664908,121.0963356],[14.6648531,121.0964764],[14.6646002,121.096494],[14.6645363,121.0965238],[14.6645002,121.0966408],[14.6642299,121.0967374],[14.6637829,121.0977901],[14.6636408,121.0980795],[14.6629907,121.0988145],[14.6625832,121.0991486],[14.6625837,121.0993232],[14.6625136,121.0996819],[14.6625395,121.100022],[14.6625468,121.1001174],[14.6623861,121.1006225],[14.6621551,121.1005299],[14.6614757,121.1023379],[14.6605674,121.1020241],[14.6602368,121.1019108],[14.6595948,121.1016599],[14.6595375,121.1016838],[14.6593102,121.1017894],[14.6589154,121.1019614],[14.6587675,121.1020312],[14.6585513,121.1021193],[14.6581343,121.1022551],[14.6574788,121.102553],[14.6569962,121.1027094],[14.6566377,121.102703],[14.656158,121.1026733],[14.6559169,121.1026982],[14.6553478,121.1027577],[14.6550262,121.1027938],[14.6548318,121.1027249],[14.6541841,121.1025018],[14.6534713,121.1024013],[14.6532707,121.102373],[14.6510737,121.101336],[14.6508768,121.1007973],[14.6507211,121.0992879],[14.650753,121.0989571],[14.6509538,121.098811],[14.6514718,121.0983827],[14.6521496,121.0978851],[14.6527322,121.0972236],[14.6535364,121.0959443],[14.6539003,121.0955034],[14.654028,121.0953166],[14.6537619,121.0950108],[14.6533329,121.0942299],[14.6530062,121.0934184]];
        $inside = false;
        $j = count($polygon) - 1;
        for ($i = 0; $i < count($polygon); $j = $i++) {
            [$yi, $xi] = $polygon[$i];
            [$yj, $xj] = $polygon[$j];
            if ((($yi > $latitude) !== ($yj > $latitude)) && ($longitude < (($xj - $xi) * ($latitude - $yi) / ($yj - $yi) + $xi))) $inside = !$inside;
        }
        return $inside;
    }

    private function calculateAge(?string $birthDate): ?int
    {
        if (empty($birthDate)) {
            return null;
        }
        try {
            $dob = new DateTimeImmutable($birthDate);
            $today = new DateTimeImmutable('today');
            if ($dob > $today) {
                return null;
            }
            return (int) $dob->diff($today)->y;
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveOrRegisterResident(array $data, string $prefix): ?int
    {
        $isOnlineSource = strcasecmp(trim((string) ($data['complaint_source'] ?? '')), 'Online') === 0;
        $resId = filter_var($data[$prefix . '_resident_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($resId && $resId > 0) {
            $stmt = $this->conn->prepare('SELECT resident_id, residency_verified FROM residents WHERE resident_id = ?');
            $stmt->execute([$resId]);
            $existingRes = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existingRes) {
                if ($isOnlineSource && !empty($existingRes['residency_verified'])) {
                    return $resId;
                }
                $updFields = [];
                $updParams = [];

                $addressVal = trim((string)($data[$prefix . '_address'] ?? ''));
                $purokVal = trim((string)($data[$prefix . '_purok'] ?? ''));
                $isTenantVal = isset($data[$prefix . '_is_tenant']) && in_array((int)$data[$prefix . '_is_tenant'], [0, 1, 2], true)
                    ? (int)$data[$prefix . '_is_tenant']
                    : null;
                if ($isTenantVal === null && $purokVal === 'Non-Resident') {
                    $isTenantVal = 2;
                }

                if ($addressVal !== '') {
                    $updFields[] = "address = ?";
                    $updParams[] = $addressVal;
                    $updFields[] = "house_street = ?";
                    $updParams[] = $addressVal;

                    $isNonTumana = ($isTenantVal === 2 || strcasecmp($purokVal, 'Non-Resident') === 0 || (stripos($addressVal, 'tumana') === false && (stripos($addressVal, 'marikina') !== false || stripos($addressVal, 'quezon') !== false || stripos($addressVal, 'pasig') !== false || stripos($addressVal, 'cainta') !== false || stripos($addressVal, 'san mateo') !== false)));
                    $updFields[] = "barangay = ?";
                    $updParams[] = $isNonTumana ? 'Outside Tumana' : 'Tumana';
                    $updFields[] = "city_municipality = ?";
                    $updParams[] = 'Marikina City';
                }

                if ($purokVal !== '') {
                    $updFields[] = "purok = ?";
                    $updParams[] = $purokVal;
                }

                if ($isTenantVal !== null) {
                    $updFields[] = "is_tenant = ?";
                    $updParams[] = $isTenantVal;
                }

                $contactVal = trim((string)($data[$prefix . '_contact_no'] ?? $data[$prefix . '_contact'] ?? ''));
                if ($contactVal !== '') {
                    $updFields[] = "contact_no = ?";
                    $updParams[] = $contactVal;
                }

                $emailVal = trim((string)($data[$prefix . '_email'] ?? ''));
                if ($emailVal !== '') {
                    $updFields[] = "email = ?";
                    $updParams[] = $emailVal;
                }

                $civilStatusVal = in_array(trim((string)($data[$prefix . '_civil_status'] ?? '')), ['Single', 'Married', 'Widowed', 'Separated'], true)
                    ? trim((string)$data[$prefix . '_civil_status'])
                    : null;
                if ($civilStatusVal !== null) {
                    $updFields[] = "civil_status = ?";
                    $updParams[] = $civilStatusVal;
                }

                $occupationVal = trim((string)($data[$prefix . '_occupation'] ?? ''));
                if ($occupationVal !== '') {
                    $updFields[] = "occupation = ?";
                    $updParams[] = $occupationVal;
                }

                $homeLat = (isset($data[$prefix . '_latitude']) && $data[$prefix . '_latitude'] !== '')
                    ? (float)$data[$prefix . '_latitude']
                    : ((isset($data[$prefix . '_home_latitude']) && $data[$prefix . '_home_latitude'] !== '') ? (float)$data[$prefix . '_home_latitude'] : null);
                $homeLng = (isset($data[$prefix . '_longitude']) && $data[$prefix . '_longitude'] !== '')
                    ? (float)$data[$prefix . '_longitude']
                    : ((isset($data[$prefix . '_home_longitude']) && $data[$prefix . '_home_longitude'] !== '') ? (float)$data[$prefix . '_home_longitude'] : null);
                if ($homeLat !== null && $homeLng !== null) {
                    $updFields[] = "home_latitude = ?";
                    $updParams[] = $homeLat;
                    $updFields[] = "home_longitude = ?";
                    $updParams[] = $homeLng;
                }

                if (!empty($updFields)) {
                    $updParams[] = $resId;
                    $updStmt = $this->conn->prepare("UPDATE residents SET " . implode(', ', $updFields) . " WHERE resident_id = ?");
                    $updStmt->execute($updParams);
                }
                return $resId;
            }
        }

        $firstName = trim((string)($data[$prefix . '_first_name'] ?? ''));
        $middleName = trim((string)($data[$prefix . '_middle_name'] ?? '')) ?: null;
        $lastName = trim((string)($data[$prefix . '_last_name'] ?? ''));
        $suffix = trim((string)($data[$prefix . '_suffix'] ?? '')) ?: null;

        if ($firstName === '' && !empty($data[$prefix . '_name'])) {
            $parts = preg_split('/\s+/', trim((string)$data[$prefix . '_name']));
            if (!empty($parts)) {
                $firstName = array_shift($parts);
                $lastName = !empty($parts) ? implode(' ', $parts) : '-';
            }
        }

        if ($firstName === '' && $lastName === '') {
            return null;
        }
        if ($firstName === '') $firstName = 'Party';
        if ($lastName === '') $lastName = '-';

        $birthDate = trim((string)($data[$prefix . '_birthdate'] ?? $data[$prefix . '_birth_date'] ?? '')) ?: null;
        $gender = in_array(trim((string)($data[$prefix . '_gender'] ?? '')), ['Male', 'Female'], true) ? trim((string)$data[$prefix . '_gender']) : null;
        $civilStatus = in_array(trim((string)($data[$prefix . '_civil_status'] ?? '')), ['Single', 'Married', 'Widowed', 'Separated'], true) ? trim((string)$data[$prefix . '_civil_status']) : null;
        $occupation = trim((string)($data[$prefix . '_occupation'] ?? '')) ?: null;
        $contactNo = trim((string)($data[$prefix . '_contact_no'] ?? $data[$prefix . '_contact'] ?? '')) ?: null;
        $email = trim((string)($data[$prefix . '_email'] ?? '')) ?: null;
        $prefRaw = trim((string)($data[$prefix . '_preferred_contact'] ?? 'SMS'));
        $preferredContact = in_array($prefRaw, ['SMS', 'Call', 'Email', 'In-Person'], true) ? $prefRaw : 'SMS';
        $address = trim((string)($data[$prefix . '_address'] ?? '')) ?: null;
        $purok = trim((string)($data[$prefix . '_purok'] ?? '')) ?: null;

        $isTenant = 0;
        if (isset($data[$prefix . '_is_tenant']) && in_array((int)$data[$prefix . '_is_tenant'], [0, 1, 2], true)) {
            $isTenant = (int)$data[$prefix . '_is_tenant'];
        } elseif ($purok === 'Non-Resident') {
            $isTenant = 2;
        }

        $residencyVerified = $isOnlineSource
            ? 0
            : (isset($data[$prefix . '_residency_verified']) ? (!empty($data[$prefix . '_residency_verified']) ? 1 : 0) : 1);

        // Check if an existing profile matches this name
        $findStmt = $this->conn->prepare('SELECT resident_id, residency_verified FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1');
        $findStmt->execute([$firstName, $lastName]);
        $existingMatch = $findStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingMatch) {
            $existingId = (int)$existingMatch['resident_id'];
            if ($isOnlineSource && !empty($existingMatch['residency_verified'])) {
                return $existingId;
            }
            $updFields = [];
            $updParams = [];
            if ($address !== null && $address !== '') {
                $updFields[] = "address = ?";
                $updParams[] = $address;
                $updFields[] = "house_street = ?";
                $updParams[] = $address;
                $isNonTumana = ($isTenant === 2 || strcasecmp((string)$purok, 'Non-Resident') === 0 || (stripos($address, 'tumana') === false && (stripos($address, 'marikina') !== false || stripos($address, 'quezon') !== false)));
                $updFields[] = "barangay = ?";
                $updParams[] = $isNonTumana ? 'Outside Tumana' : 'Tumana';
                $updFields[] = "city_municipality = ?";
                $updParams[] = 'Marikina City';
            }
            if ($purok !== null && $purok !== '') {
                $updFields[] = "purok = ?";
                $updParams[] = $purok;
            }
            if ($isTenant !== null) {
                $updFields[] = "is_tenant = ?";
                $updParams[] = $isTenant;
            }
            if ($contactNo !== null && $contactNo !== '') {
                $updFields[] = "contact_no = ?";
                $updParams[] = $contactNo;
            }
            if ($email !== null && $email !== '') {
                $updFields[] = "email = ?";
                $updParams[] = $email;
            }
            if ($civilStatus !== null) {
                $updFields[] = "civil_status = ?";
                $updParams[] = $civilStatus;
            }
            if ($occupation !== null && $occupation !== '') {
                $updFields[] = "occupation = ?";
                $updParams[] = $occupation;
            }
            $homeLat = (isset($data[$prefix . '_latitude']) && $data[$prefix . '_latitude'] !== '')
                ? (float)$data[$prefix . '_latitude']
                : ((isset($data[$prefix . '_home_latitude']) && $data[$prefix . '_home_latitude'] !== '') ? (float)$data[$prefix . '_home_latitude'] : null);
            $homeLng = (isset($data[$prefix . '_longitude']) && $data[$prefix . '_longitude'] !== '')
                ? (float)$data[$prefix . '_longitude']
                : ((isset($data[$prefix . '_home_longitude']) && $data[$prefix . '_home_longitude'] !== '') ? (float)$data[$prefix . '_home_longitude'] : null);
            if ($homeLat !== null && $homeLng !== null) {
                $updFields[] = "home_latitude = ?";
                $updParams[] = $homeLat;
                $updFields[] = "home_longitude = ?";
                $updParams[] = $homeLng;
            }
            if (!empty($updFields)) {
                $updParams[] = $existingId;
                $updStmt = $this->conn->prepare("UPDATE residents SET " . implode(', ', $updFields) . " WHERE resident_id = ?");
                $updStmt->execute($updParams);
            }
            return $existingId;
        }

        // Insert new resident record automatically
        $isNonTumana = ($isTenant === 2 || strcasecmp((string)$purok, 'Non-Resident') === 0 || ($address && stripos($address, 'tumana') === false && stripos($address, 'marikina') !== false));
        $calcBarangay = $isNonTumana ? 'Outside Tumana' : 'Tumana';
        $homeLat = (isset($data[$prefix . '_latitude']) && $data[$prefix . '_latitude'] !== '')
            ? (float)$data[$prefix . '_latitude']
            : ((isset($data[$prefix . '_home_latitude']) && $data[$prefix . '_home_latitude'] !== '') ? (float)$data[$prefix . '_home_latitude'] : null);
        $homeLng = (isset($data[$prefix . '_longitude']) && $data[$prefix . '_longitude'] !== '')
            ? (float)$data[$prefix . '_longitude']
            : ((isset($data[$prefix . '_home_longitude']) && $data[$prefix . '_home_longitude'] !== '') ? (float)$data[$prefix . '_home_longitude'] : null);

        $ins = $this->conn->prepare("
            INSERT INTO residents (
                first_name, middle_name, last_name, suffix,
                birth_date, gender, civil_status, occupation,
                contact_no, email, preferred_contact,
                address, home_latitude, home_longitude, house_street, barangay, city_municipality,
                purok, is_tenant, residency_verified
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $firstName,
            $middleName,
            $lastName,
            $suffix,
            $birthDate,
            $gender,
            $civilStatus,
            $occupation,
            $contactNo,
            $email,
            $preferredContact,
            $address,
            $homeLat,
            $homeLng,
            $address,
            $calcBarangay,
            'Marikina City',
            $purok,
            $isTenant,
            $residencyVerified
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function applyParties(int $complaintId, array $data): void
    {
        $partyStmt = $this->conn->prepare("
            INSERT INTO complaint_parties (
                complaint_id, resident_id, party_type,
                is_minor, guardian_name, guardian_relationship, guardian_contact, guardian_address,
                home_latitude, home_longitude,
                preferred_contact, suffix, occupation, age, birth_date, gender, civil_status,
                contact_unknown, middle_name_unknown, birthdate_unknown, address_requires_verification, residency_verified
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_minor = VALUES(is_minor),
                guardian_name = VALUES(guardian_name),
                guardian_relationship = VALUES(guardian_relationship),
                guardian_contact = VALUES(guardian_contact),
                guardian_address = VALUES(guardian_address),
                home_latitude = VALUES(home_latitude),
                home_longitude = VALUES(home_longitude),
                preferred_contact = VALUES(preferred_contact),
                suffix = VALUES(suffix),
                occupation = VALUES(occupation),
                age = VALUES(age),
                birth_date = VALUES(birth_date),
                gender = VALUES(gender),
                civil_status = VALUES(civil_status),
                contact_unknown = VALUES(contact_unknown),
                middle_name_unknown = VALUES(middle_name_unknown),
                birthdate_unknown = VALUES(birthdate_unknown),
                address_requires_verification = VALUES(address_requires_verification),
                residency_verified = VALUES(residency_verified)
        ");

        $residentLookup = $this->conn->prepare('SELECT * FROM residents WHERE resident_id = ?');

        // 1. Complainant
        $compId = $this->resolveOrRegisterResident($data, 'complainant');
        if ($compId && $compId > 0) {
            $residentLookup->execute([$compId]);
            $compRes = $residentLookup->fetch(PDO::FETCH_ASSOC) ?: [];

            $compBirthDate = trim((string) ($data['complainant_birthdate'] ?? $data['complainant_birth_date'] ?? $compRes['birth_date'] ?? '')) ?: null;
            $compAge = $this->calculateAge($compBirthDate);
            if ($compAge === null && isset($data['complainant_age']) && $data['complainant_age'] !== '') {
                $compAge = filter_var($data['complainant_age'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 130]]) ?: null;
            }

            $compIsMinor = (!empty($data['complainant_is_minor']) || ($compAge !== null && $compAge < 18)) ? 1 : 0;
            $compPrefContact = in_array(trim((string)($data['complainant_preferred_contact'] ?? $compRes['preferred_contact'] ?? 'SMS')), ['SMS', 'Call', 'Email', 'In-Person'], true)
                ? trim((string)($data['complainant_preferred_contact'] ?? $compRes['preferred_contact'] ?? 'SMS'))
                : 'SMS';

            $compLat = (isset($data['complainant_latitude']) && $data['complainant_latitude'] !== '') ? (float)$data['complainant_latitude'] : ($compRes['home_latitude'] ?? null);
            $compLng = (isset($data['complainant_longitude']) && $data['complainant_longitude'] !== '') ? (float)$data['complainant_longitude'] : ($compRes['home_longitude'] ?? null);

            $partyStmt->execute([
                $complaintId,
                $compId,
                'Complainant',
                $compIsMinor,
                trim((string) ($data['complainant_guardian_name'] ?? '')) ?: null,
                trim((string) ($data['complainant_guardian_relationship'] ?? '')) ?: null,
                trim((string) ($data['complainant_guardian_contact'] ?? '')) ?: null,
                trim((string) ($data['complainant_guardian_address'] ?? '')) ?: null,
                $compLat,
                $compLng,
                $compPrefContact,
                trim((string) ($data['complainant_suffix'] ?? $compRes['suffix'] ?? '')) ?: null,
                trim((string) ($data['complainant_occupation'] ?? $compRes['occupation'] ?? '')) ?: null,
                $compAge,
                $compBirthDate,
                trim((string) ($data['complainant_gender'] ?? $compRes['gender'] ?? '')) ?: null,
                trim((string) ($data['complainant_civil_status'] ?? $compRes['civil_status'] ?? '')) ?: null,
                0,
                0,
                0,
                0,
                isset($data['complainant_residency_verified']) ? (!empty($data['complainant_residency_verified']) ? 1 : 0) : ((int) ($compRes['residency_verified'] ?? 1))
            ]);
        }

        // 2. Respondent (Person being complained against)
        $respId = $this->resolveOrRegisterResident($data, 'respondent');
        if ($respId && $respId > 0) {
            $residentLookup->execute([$respId]);
            $respRes = $residentLookup->fetch(PDO::FETCH_ASSOC) ?: [];

            $respBirthUnknown = !empty($data['respondent_birthdate_unknown']) ? 1 : 0;
            $respBirthDate = $respBirthUnknown ? null : (trim((string) ($data['respondent_birthdate'] ?? $data['respondent_birth_date'] ?? $respRes['birth_date'] ?? '')) ?: null);
            $respAge = $this->calculateAge($respBirthDate);
            if ($respAge === null && isset($data['respondent_age']) && $data['respondent_age'] !== '') {
                $respAge = filter_var($data['respondent_age'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 130]]) ?: null;
            }

            $respIsMinor = (!empty($data['respondent_is_minor']) || ($respAge !== null && $respAge < 18)) ? 1 : 0;

            $respLat = (isset($data['respondent_latitude']) && $data['respondent_latitude'] !== '') ? (float)$data['respondent_latitude'] : ($respRes['home_latitude'] ?? null);
            $respLng = (isset($data['respondent_longitude']) && $data['respondent_longitude'] !== '') ? (float)$data['respondent_longitude'] : ($respRes['home_longitude'] ?? null);

            $partyStmt->execute([
                $complaintId,
                $respId,
                'Respondent',
                $respIsMinor,
                trim((string) ($data['respondent_guardian_name'] ?? '')) ?: null,
                trim((string) ($data['respondent_guardian_relationship'] ?? '')) ?: null,
                trim((string) ($data['respondent_guardian_contact'] ?? '')) ?: null,
                trim((string) ($data['respondent_guardian_address'] ?? '')) ?: null,
                $respLat,
                $respLng,
                null,
                trim((string) ($data['respondent_suffix'] ?? $respRes['suffix'] ?? '')) ?: null,
                trim((string) ($data['respondent_occupation'] ?? $respRes['occupation'] ?? '')) ?: null,
                $respAge,
                $respBirthDate,
                trim((string) ($data['respondent_gender'] ?? $respRes['gender'] ?? '')) ?: null,
                trim((string) ($data['respondent_civil_status'] ?? $respRes['civil_status'] ?? '')) ?: null,
                !empty($data['respondent_contact_unknown']) ? 1 : 0,
                !empty($data['respondent_middle_name_unknown']) ? 1 : 0,
                $respBirthUnknown,
                !empty($data['respondent_address_requires_verification']) ? 1 : 0,
                (int) ($respRes['residency_verified'] ?? 1)
            ]);
        }

        // 3. Additional parties
        if (!empty($data['party_resident_ids']) && is_array($data['party_resident_ids'])) {
            $types = $data['party_types'] ?? [];
            foreach ($data['party_resident_ids'] as $index => $pIdRaw) {
                $pId = filter_var($pIdRaw, FILTER_VALIDATE_INT) ?: null;
                $pType = trim((string)($types[$index] ?? 'Witness'));
                if (!in_array($pType, ['Complainant', 'Respondent', 'Witness'], true)) {
                    $pType = 'Witness';
                }
                if (!$pId && (!empty($data['party_first_names'][$index]) || !empty($data['party_names'][$index]))) {
                    $pFirst = trim((string)($data['party_first_names'][$index] ?? ''));
                    $pLast  = trim((string)($data['party_last_names'][$index] ?? ''));
                    if ($pFirst === '' && !empty($data['party_names'][$index])) {
                        $parts = explode(' ', trim((string)$data['party_names'][$index]));
                        $pFirst = array_shift($parts);
                        $pLast = implode(' ', $parts) ?: '-';
                    }
                    if ($pFirst !== '') {
                        $pMiddle = trim((string)($data['party_middle_names'][$index] ?? ''));
                        $pSuffix = trim((string)($data['party_suffixes'][$index] ?? ''));
                        $pContact = trim((string)($data['party_contacts'][$index] ?? ''));
                        $pAddress = trim((string)($data['party_addresses'][$index] ?? ''));
                        $pPurok = trim((string)($data['party_puroks'][$index] ?? ''));

                        $pLat = (isset($data['party_latitudes'][$index]) && $data['party_latitudes'][$index] !== '') ? (float)$data['party_latitudes'][$index] : null;
                        $pLng = (isset($data['party_longitudes'][$index]) && $data['party_longitudes'][$index] !== '') ? (float)$data['party_longitudes'][$index] : null;

                        $findStmt = $this->conn->prepare('SELECT resident_id FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1');
                        $findStmt->execute([$pFirst, $pLast]);
                        $foundId = $findStmt->fetchColumn();
                        if ($foundId) {
                            $pId = (int)$foundId;
                        } else {
                            $pBarangay = ($pPurok === 'Non-Resident' || ($pAddress && stripos($pAddress, 'tumana') === false)) ? 'Outside Tumana' : 'Tumana';
                            $ins = $this->conn->prepare('INSERT INTO residents (first_name, middle_name, last_name, suffix, contact_no, address, home_latitude, home_longitude, house_street, purok, barangay, city_municipality, residency_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
                            $ins->execute([$pFirst, $pMiddle ?: null, $pLast, $pSuffix ?: null, $pContact ?: null, $pAddress ?: null, $pLat, $pLng, $pAddress ?: null, $pPurok ?: null, $pBarangay, 'Marikina City']);
                            $pId = (int)$this->conn->lastInsertId();
                        }
                    }
                }

                if ($pId && $pId > 0) {
                    $pAddressVal = trim((string)($data['party_addresses'][$index] ?? ''));
                    $pPurokVal   = trim((string)($data['party_puroks'][$index] ?? ''));
                    $pContactVal = trim((string)($data['party_contacts'][$index] ?? ''));
                    $pLatVal     = (isset($data['party_latitudes'][$index]) && $data['party_latitudes'][$index] !== '') ? (float)$data['party_latitudes'][$index] : null;
                    $pLngVal     = (isset($data['party_longitudes'][$index]) && $data['party_longitudes'][$index] !== '') ? (float)$data['party_longitudes'][$index] : null;
                    $pUpd = [];
                    $pParams = [];
                    if ($pAddressVal !== '') {
                        $pUpd[] = "address = ?";
                        $pParams[] = $pAddressVal;
                        $pUpd[] = "house_street = ?";
                        $pParams[] = $pAddressVal;
                        $isNonTum = ($pPurokVal === 'Non-Resident' || (stripos($pAddressVal, 'tumana') === false && (stripos($pAddressVal, 'marikina') !== false || stripos($pAddressVal, 'quezon') !== false)));
                        $pUpd[] = "barangay = ?";
                        $pParams[] = $isNonTum ? 'Outside Tumana' : 'Tumana';
                        $pUpd[] = "city_municipality = ?";
                        $pParams[] = 'Marikina City';
                    }
                    if ($pLatVal !== null && $pLngVal !== null) {
                        $pUpd[] = "home_latitude = ?";
                        $pParams[] = $pLatVal;
                        $pUpd[] = "home_longitude = ?";
                        $pParams[] = $pLngVal;
                    }
                    if ($pPurokVal !== '') {
                        $pUpd[] = "purok = ?";
                        $pParams[] = $pPurokVal;
                    }
                    if ($pContactVal !== '') {
                        $pUpd[] = "contact_no = ?";
                        $pParams[] = $pContactVal;
                    }
                    if (!empty($pUpd)) {
                        $pParams[] = $pId;
                        $this->conn->prepare("UPDATE residents SET " . implode(', ', $pUpd) . " WHERE resident_id = ?")->execute($pParams);
                    }

                    $residentLookup->execute([$pId]);
                    $pRes = $residentLookup->fetch(PDO::FETCH_ASSOC) ?: [];
                    $pBirth = trim((string)($pRes['birth_date'] ?? '')) ?: null;
                    $pAge = $this->calculateAge($pBirth);
                    $finalPLat = $pLatVal ?? ($pRes['home_latitude'] ?? null);
                    $finalPLng = $pLngVal ?? ($pRes['home_longitude'] ?? null);

                    $partyStmt->execute([
                        $complaintId,
                        $pId,
                        $pType,
                        ($pAge !== null && $pAge < 18) ? 1 : 0,
                        null,
                        null,
                        null,
                        null,
                        $finalPLat,
                        $finalPLng,
                        $pRes['preferred_contact'] ?? 'SMS',
                        $pRes['suffix'] ?? null,
                        $pRes['occupation'] ?? null,
                        $pAge,
                        $pBirth,
                        $pRes['gender'] ?? null,
                        $pRes['civil_status'] ?? null,
                        0,
                        0,
                        0,
                        0,
                        (int) ($pRes['residency_verified'] ?? 1)
                    ]);
                }
            }
        }
    }

    private function validatePartiesInput(array $data): array
    {
        // Complainant validation: either an existing resident profile OR direct first name & last name
        $compId = filter_var($data['complainant_resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $compFirst = trim((string)($data['complainant_first_name'] ?? ''));
        $compLast  = trim((string)($data['complainant_last_name'] ?? ''));
        $compRow   = null;

        if ($compId) {
            $checkComp = $this->conn->prepare('SELECT resident_id, birth_date, first_name, last_name FROM residents WHERE resident_id = ?');
            $checkComp->execute([$compId]);
            $compRow = $checkComp->fetch(PDO::FETCH_ASSOC);
            if (!$compRow && ($compFirst === '' || $compLast === '')) {
                return ['success' => false, 'message' => 'The selected complainant profile was not found in the directory.'];
            }
        } elseif ($compFirst === '' && empty($data['complainant_name'])) {
            return ['success' => false, 'message' => 'Please provide the complainant\'s first name and last name.'];
        }

        // Respondent validation: either an existing resident profile OR direct first name & last name
        $respId = filter_var($data['respondent_resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $respFirst = trim((string)($data['respondent_first_name'] ?? ''));
        $respLast  = trim((string)($data['respondent_last_name'] ?? ''));
        $respRow   = null;

        if ($respId) {
            $checkResp = $this->conn->prepare('SELECT resident_id, is_tenant, purok, first_name, last_name FROM residents WHERE resident_id = ?');
            $checkResp->execute([$respId]);
            $respRow = $checkResp->fetch(PDO::FETCH_ASSOC);
            if (!$respRow && ($respFirst === '' || $respLast === '')) {
                return ['success' => false, 'message' => 'The selected respondent profile was not found in the directory.'];
            }
        } elseif ($respFirst === '' && empty($data['respondent_name'])) {
            return ['success' => false, 'message' => 'Please provide the respondent\'s first name and last name.'];
        }

        // Distinctness check
        if ($compId && $respId && $compId === $respId) {
            return ['success' => false, 'message' => 'The complainant and respondent cannot be the same profile.'];
        }
        $fullCompName = strtolower(trim(($compRow ? ($compRow['first_name'] . ' ' . $compRow['last_name']) : ($compFirst . ' ' . $compLast))));
        $fullRespName = strtolower(trim(($respRow ? ($respRow['first_name'] . ' ' . $respRow['last_name']) : ($respFirst . ' ' . $respLast))));
        if ($fullCompName !== '' && $fullCompName === $fullRespName) {
            return ['success' => false, 'message' => 'The complainant and respondent cannot be the exact same person.'];
        }

        // Minor complainant validation: if minor, require legal guardian full name and relationship
        $compBirth = trim((string) ($data['complainant_birthdate'] ?? $data['complainant_birth_date'] ?? $compRow['birth_date'] ?? ''));
        $compAge = $this->calculateAge($compBirth);
        if ($compAge === null && isset($data['complainant_age']) && $data['complainant_age'] !== '') {
            $compAge = filter_var($data['complainant_age'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 130]]) ?: null;
        }
        $compIsMinor = !empty($data['complainant_is_minor']) || ($compAge !== null && $compAge < 18);
        if ($compIsMinor) {
            $gName = trim((string) ($data['complainant_guardian_name'] ?? ''));
            $gRel = trim((string) ($data['complainant_guardian_relationship'] ?? ''));
            if ($gName === '') {
                return ['success' => false, 'message' => 'Since the complainant is a minor, the legal guardian\'s full name is required.'];
            }
            if ($gRel === '') {
                return ['success' => false, 'message' => 'Please specify the legal guardian\'s relationship to the minor complainant.'];
            }
        }

        $capType = strtolower(trim((string) ($data['party_capacity_type'] ?? '')));
        $allowEntity = !empty($data['party_is_juridical']) || !empty($data['party_is_government']) || in_array($capType, ['juridical', 'government'], true);
        if (($pErr = ValidationService::validatePartyPrefix($data, 'complainant', $allowEntity)) !== null) {
            return ['success' => false, 'message' => $pErr];
        }
        if (($pErr = ValidationService::validatePartyPrefix($data, 'respondent', $allowEntity)) !== null) {
            return ['success' => false, 'message' => $pErr];
        }

        // Additional parties validation: all additional parties with resident ID must exist
        if (!empty($data['party_resident_ids']) && is_array($data['party_resident_ids'])) {
            $types = $data['party_types'] ?? [];
            foreach ($data['party_resident_ids'] as $idx => $pIdRaw) {
                if ($pIdRaw === '' || $pIdRaw === null) {
                    continue;
                }
                $pId = filter_var($pIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$pId) {
                    continue;
                }
                $pType = trim((string)($types[$idx] ?? 'Witness'));
                if (!in_array($pType, ['Complainant', 'Respondent', 'Witness'], true)) {
                    return ['success' => false, 'message' => 'Invalid party type specified for additional party.'];
                }

                $checkP = $this->conn->prepare('SELECT resident_id, is_tenant, purok FROM residents WHERE resident_id = ?');
                $checkP->execute([$pId]);
                $pRow = $checkP->fetch(PDO::FETCH_ASSOC);
                if (!$pRow) {
                    return ['success' => false, 'message' => 'An additional party resident profile was not found in the directory.'];
                }
            }
        }

        return ['success' => true];
    }

    /**
     * Evaluates dispute context against Katarungang Pambarangay statutory exclusions
     * under RA 7160 §§408, 409, 412 and relevant DILG guidelines.
     */
    public function validateJurisdictionInput(array &$data): array
    {
        require_once __DIR__ . '/../services/MediationDeadlineService.php';

        $isOnlineSubmission = strcasecmp(trim((string) ($data['complaint_source'] ?? '')), 'Online') === 0;
        $hasManualScreening = !empty($data['jurisdiction_screened']);
        if (!$hasManualScreening && !$isOnlineSubmission) {
            return [
                'success' => false,
                'message' => 'Complete the preliminary jurisdiction and eligibility screen before submitting the complaint.'
            ];
        }
        $onlineAdvisoryNotes = [];

        $disputeContext = [];

        // 1. Explicit statutory exclusion flags passed from UI / API
        $disputeContext['party_is_government']        = !empty($data['party_is_government']);
        $capacityType = strtolower(trim((string) ($data['party_capacity_type'] ?? '')));
        $disputeContext['party_is_juridical']         = !empty($data['party_is_juridical']) || $capacityType === 'juridical';
        if ($capacityType === 'government') {
            $disputeContext['party_is_government'] = true;
        }
        $disputeContext['party_is_public_officer']    = !empty($data['party_is_public_officer']) || !empty($data['involves_public_officer_official_capacity']) || $capacityType === 'public_officer';
        $disputeContext['offense_imprisonment_max']   = (float) ($data['offense_imprisonment_max'] ?? 0);
        $disputeContext['offense_fine_max']           = (float) ($data['offense_fine_max'] ?? 0);
        if (array_key_exists('has_private_offended_party', $data)) {
            $disputeContext['has_private_offended_party'] = (bool) $data['has_private_offended_party'];
        }
        $disputeContext['property_in_diff_lgu']       = !empty($data['property_in_diff_lgu']);
        $disputeContext['parties_in_diff_lgu']        = !empty($data['parties_in_diff_lgu']);
        $disputeContext['parties_same_barangay']      = !empty($data['parties_same_barangay']) || empty($data['parties_in_diff_lgu']);
        $disputeContext['accused_under_detention']    = !empty($data['accused_under_detention']);
        $disputeContext['habeas_corpus_needed']       = !empty($data['habeas_corpus_needed']);
        $disputeContext['provisional_remedy_needed']  = !empty($data['provisional_remedy_needed']);
        $disputeContext['prescription_risk']          = !empty($data['prescription_risk']);
        $disputeContext['indigenous_community']       = !empty($data['indigenous_community']);

        // Check government entity keywords on names
        $rawCompName = strtolower(trim(($data['complainant_first_name'] ?? '') . ' ' . ($data['complainant_last_name'] ?? '') . ' ' . ($data['complainant_name'] ?? '')));
        $rawRespName = strtolower(trim(($data['respondent_first_name'] ?? '') . ' ' . ($data['respondent_last_name'] ?? '') . ' ' . ($data['respondent_name'] ?? '')));
        $govKeywords = ['republic of the philippines', 'department of', 'city of marikina', 'barangay tumana', 'philippine national police', 'pnp', 'afp', 'bureau of'];
        foreach ($govKeywords as $kw) {
            if (($rawCompName !== '' && str_contains($rawCompName, $kw)) || ($rawRespName !== '' && str_contains($rawRespName, $kw))) {
                $disputeContext['party_is_government'] = true;
                break;
            }
        }

        // 2. Derive context from resident directory records
        $compId = filter_var($data['complainant_resident_id'] ?? null, FILTER_VALIDATE_INT);
        $respId = filter_var($data['respondent_resident_id'] ?? null, FILTER_VALIDATE_INT);
        if ($compId && $respId) {
            $stmt = $this->conn->prepare('SELECT resident_id, first_name, last_name, address, purok, is_tenant FROM residents WHERE resident_id IN (?, ?)');
            $stmt->execute([$compId, $respId]);
            $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $compRes = null;
            $respRes = null;
            foreach ($residents as $r) {
                if ((int)$r['resident_id'] === $compId) $compRes = $r;
                if ((int)$r['resident_id'] === $respId) $respRes = $r;
            }

            if ($compRes && $respRes) {
                $compName = strtolower(trim($compRes['first_name'] . ' ' . $compRes['last_name']));
                $respName = strtolower(trim($respRes['first_name'] . ' ' . $respRes['last_name']));
                foreach ($govKeywords as $kw) {
                    if (str_contains($compName, $kw) || str_contains($respName, $kw)) {
                        $disputeContext['party_is_government'] = true;
                        break;
                    }
                }

                // If complainant is Non-Resident with address outside Marikina and not in Tumana
                $compPurok   = trim((string)($compRes['purok'] ?? ''));
                $compAddress = strtolower(trim((string)($compRes['address'] ?? '')));
                if (((int)$compRes['is_tenant'] === 2 || strcasecmp($compPurok, 'Non-Resident') === 0)
                    && $compAddress !== ''
                    && !str_contains($compAddress, 'marikina')
                    && !str_contains($compAddress, 'tumana')) {
                    $disputeContext['parties_in_diff_lgu'] = true;
                    $disputeContext['parties_same_barangay'] = false;
                }
            }
        }

        // 2a. Walk-in / inline Non-Resident Respondent venue check (RA 7160 §409)
        $respIsNonResident = strcasecmp(trim((string) ($data['respondent_purok'] ?? '')), 'Non-Resident') === 0
            || (int) ($data['respondent_is_tenant'] ?? 0) === 2;
        if ($respIsNonResident && empty($data['respondent_kp_jurisdiction_exception'])) {
            if (!$isOnlineSubmission || $hasManualScreening) {
                return [
                    'success' => false,
                    'code'    => 'KP_VENUE_NON_RESIDENT_RESPONDENT',
                    'message' => 'RA 7160 §409: The respondent is a non-resident of Barangay Tumana. Confirm a §409(c/d) exception (dispute involves real property or workplace/school in Tumana) or advise filing in the respondent\'s barangay.',
                ];
            }
            $onlineAdvisoryNotes[] = 'Non-resident respondent selected — LISO to verify RA 7160 §409 venue exception during review.';
        }

        // 2b. Safe Spaces Act (RA 11313) Catcalling/Whistling mandatory 1st Offense verification
        $catIdCheck = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
        if ($catIdCheck) {
            $cStmt = $this->conn->prepare('SELECT category_name FROM complaint_categories WHERE category_id = ?');
            $cStmt->execute([$catIdCheck]);
            $catNameStr = strtolower((string)($cStmt->fetchColumn() ?: ''));
            if (str_contains($catNameStr, 'safe spaces') || str_contains($catNameStr, 'catcalling') || str_contains($catNameStr, '11313')) {
                if (!$isOnlineSubmission || $hasManualScreening) {
                    if (!empty($data['is_repeat_offense_vawc'])) {
                        return [
                            'success' => false,
                            'code'    => 'SAFE_SPACES_REPEAT_VAWC_PNP',
                            'message' => 'RA 11313 §11: 2nd and 3rd offenses under the Safe Spaces Act fall outside Katarungang Pambarangay conciliation and must be routed directly to the Barangay VAWC Desk or PNP WCPD.',
                        ];
                    }
                    if (empty($data['is_first_offense_catcalling'])) {
                        return [
                            'success' => false,
                            'code'    => 'SAFE_SPACES_FIRST_OFFENSE_REQUIRED',
                            'message' => 'Mandatory toggle required: Confirm "1st Offense Only (Catcalling/Whistling)" to proceed under Barangay jurisdiction (2nd/3rd offenses route to VAWC/PNP).',
                        ];
                    }
                } else {
                    $onlineAdvisoryNotes[] = 'Safe Spaces Act indicator detected — LISO to verify offense count / VAWC routing during review.';
                }
            }
        }

        // 3. Real property check: category 6 (Property and Rental Disputes)
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
        $isRealPropertyCategory = ($categoryId === 6)
            || (!empty($catNameStr) && (
                str_contains($catNameStr, 'lease')
                || str_contains($catNameStr, 'rental')
                || str_contains($catNameStr, 'boundary')
                || str_contains($catNameStr, 'easement')
                || str_contains($catNameStr, 'real property')
                || str_contains($catNameStr, 'usurpation')
            ));
        if ($isRealPropertyCategory) {
            $incidentCity = trim((string)($data['incident_city'] ?? ''));
            if ($incidentCity !== '' && stripos($incidentCity, 'Marikina') === false) {
                $disputeContext['property_in_diff_lgu'] = true;
            }
        }

        $eval = MediationDeadlineService::evaluateKpJurisdiction($disputeContext);
        if ($eval['excluded']) {
            // If the only statutory exclusion ground is fine/damages exceeding ₱5,000 (§408(c)),
            // and an official blotter reference number is provided with acknowledgment,
            // allow registering as a Blotter Reference Only entry without aborting.
            $isDamagesOnlyExclusion = count($eval['exclusion_grounds']) === 1 &&
                str_contains($eval['exclusion_grounds'][0], '§408(c)');
            $hasBlotterAck = !empty($data['blotter_ref_no']) && !empty($data['blotter_only_acknowledged']);

            if ($isDamagesOnlyExclusion && $hasBlotterAck) {
                // Permitted as Blotter Reference Only
            } elseif (!$isOnlineSubmission || $hasManualScreening) {
                return [
                    'success'           => false,
                    'code'              => 'KP_JURISDICTION_EXCLUDED',
                    'message'           => 'This dispute cannot be accepted under Katarungang Pambarangay: ' . implode(' ', $eval['exclusion_grounds']),
                    'exclusion_grounds' => $eval['exclusion_grounds'],
                    'evaluation'        => $eval,
                ];
            } else {
                $onlineAdvisoryNotes[] = 'Pending LISO KP jurisdiction review: ' . implode(' ', $eval['exclusion_grounds']);
            }
        }

        if ($isOnlineSubmission && !$hasManualScreening) {
            $reviewNote = 'Submitted online by resident — Pending LISO preliminary jurisdiction & eligibility review.';
            $mergedNotes = trim(implode("\n", array_filter(array_merge(
                [$reviewNote],
                $onlineAdvisoryNotes,
                [trim((string) ($data['screening_notes'] ?? ''))]
            ))));
            $data['screening_notes'] = mb_substr($mergedNotes, 0, 2000);
        }

        return [
            'success'    => true,
            'evaluation' => $eval,
        ];
    }

    private function saveIntakeScreening(int $complaintId, array $data): void
    {
        $bool = static fn (string $field): int => !empty($data[$field]) ? 1 : 0;
        $privateOffended = array_key_exists('has_private_offended_party', $data)
            ? $bool('has_private_offended_party')
            : 1;
        $notes = trim((string) ($data['screening_notes'] ?? '')) ?: null;
        if (!empty($data['is_first_offense_catcalling'])) {
            $ssaNote = 'Safe Spaces Act (RA 11313): Verified 1st Offense Only (Catcalling/Whistling)';
            $notes = $notes ? ($notes . "\n" . $ssaNote) : $ssaNote;
        }
        if (!empty($data['blotter_ref_no'])) {
            $blotterNote = 'Barangay Blotter Ref No: ' . trim((string)$data['blotter_ref_no']);
            if (!empty($data['blotter_only_acknowledged'])) {
                $blotterNote .= ' (Police / Court Jurisdiction — Issue Blotter Reference Only; Damages: ₱' . number_format((float)($data['offense_fine_max'] ?? 0), 2) . ')';
            }
            $notes = $notes ? ($notes . "\n" . $blotterNote) : $blotterNote;
        }
        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw new InvalidArgumentException('Screening notes must be 2,000 characters or fewer.');
        }

        $hasAiJsonCol = $this->hasIntakeScreeningColumn('ai_suggestion_json');
        $aiSuggestionJson = isset($data['ai_suggestion_json']) && trim((string) $data['ai_suggestion_json']) !== ''
            ? (string) $data['ai_suggestion_json']
            : null;

        $cols = 'complaint_id, party_is_government, party_is_public_officer,
                parties_in_diff_lgu, property_in_diff_lgu, accused_under_detention,
                habeas_corpus_needed, provisional_remedy_needed, prescription_risk,
                indigenous_community, offense_imprisonment_max, offense_fine_max,
                has_private_offended_party, duplicate_acknowledged, screening_notes, screened_by';
        $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
        $updates = 'party_is_government = VALUES(party_is_government),
                party_is_public_officer = VALUES(party_is_public_officer),
                parties_in_diff_lgu = VALUES(parties_in_diff_lgu),
                property_in_diff_lgu = VALUES(property_in_diff_lgu),
                accused_under_detention = VALUES(accused_under_detention),
                habeas_corpus_needed = VALUES(habeas_corpus_needed),
                provisional_remedy_needed = VALUES(provisional_remedy_needed),
                prescription_risk = VALUES(prescription_risk),
                indigenous_community = VALUES(indigenous_community),
                offense_imprisonment_max = VALUES(offense_imprisonment_max),
                offense_fine_max = VALUES(offense_fine_max),
                has_private_offended_party = VALUES(has_private_offended_party),
                duplicate_acknowledged = VALUES(duplicate_acknowledged),
                screening_notes = VALUES(screening_notes),
                screened_by = VALUES(screened_by),
                screened_at = CURRENT_TIMESTAMP';
        if ($hasAiJsonCol) {
            $cols .= ', ai_suggestion_json';
            $placeholders .= ', ?';
            $updates .= ', ai_suggestion_json = COALESCE(VALUES(ai_suggestion_json), ai_suggestion_json)';
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO complaint_intake_screenings ({$cols}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updates}"
        );
        $isOnlineUnscreened = strcasecmp(trim((string) ($data['complaint_source'] ?? '')), 'Online') === 0
            && empty($data['jurisdiction_screened']);
        $screenedByUserId = $isOnlineUnscreened ? null : ($_SESSION['user_id'] ?? null);

        $execParams = [
            $complaintId,
            $bool('party_is_government'),
            $bool('party_is_public_officer'),
            $bool('parties_in_diff_lgu'),
            $bool('property_in_diff_lgu'),
            $bool('accused_under_detention'),
            $bool('habeas_corpus_needed'),
            $bool('provisional_remedy_needed'),
            $bool('prescription_risk'),
            $bool('indigenous_community'),
            max(0, (float) ($data['offense_imprisonment_max'] ?? 0)),
            max(0, (float) ($data['offense_fine_max'] ?? 0)),
            $privateOffended,
            $bool('duplicate_acknowledged'),
            $notes,
            $screenedByUserId,
        ];
        if ($hasAiJsonCol) {
            $execParams[] = $aiSuggestionJson;
        }
        $stmt->execute($execParams);
    }

    public function findPotentialDuplicates(array $data, ?int $excludeComplaintId = null): array
    {
        $hasSourceCol = $this->hasComplaintColumn('complaint_source');
        $sourceSelect = $hasSourceCol ? 'c.complaint_source,' : "'Walk-in' AS complaint_source,";
        $refNumber = trim((string) ($data['reference_id'] ?? ($data['complaint_number'] ?? '')));
        if ($refNumber !== '') {
            $refSql = "SELECT c.complaint_id, c.complaint_number, c.complaint_title, c.status, c.incident_date, c.created_at, {$sourceSelect} cs.case_number, cs.case_status FROM complaints c LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id WHERE c.complaint_number = ? AND c.status NOT IN ('Archived', 'Rejected') LIMIT 5";
            $refStmt = $this->conn->prepare($refSql);
            $refStmt->execute([$refNumber]);
            $refRows = $refStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($refRows !== []) {
                return $refRows;
            }
        }

        $complainantId = filter_var($data['complainant_resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $respondentId = filter_var($data['respondent_resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$complainantId) {
            $complainantId = $this->findResidentIdByName(
                trim((string) ($data['complainant_first_name'] ?? '')),
                trim((string) ($data['complainant_last_name'] ?? ''))
            );
        }
        if (!$respondentId) {
            $respondentId = $this->findResidentIdByName(
                trim((string) ($data['respondent_first_name'] ?? '')),
                trim((string) ($data['respondent_last_name'] ?? ''))
            );
        }
        if (!$complainantId || !$respondentId) {
            return [];
        }

        $sql = "SELECT c.complaint_id, c.complaint_number, c.complaint_title, c.status, c.incident_date, c.created_at, {$sourceSelect} cs.case_number, cs.case_status
                FROM complaints c
                INNER JOIN complaint_parties complainant ON complainant.complaint_id = c.complaint_id
                    AND complainant.resident_id = ? AND complainant.party_type = 'Complainant'
                INNER JOIN complaint_parties respondent ON respondent.complaint_id = c.complaint_id
                    AND respondent.resident_id = ? AND respondent.party_type = 'Respondent'
                LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
                WHERE c.status NOT IN ('Archived', 'Dismissed', 'Rejected')";
        $params = [(int) $complainantId, (int) $respondentId];
        if ($excludeComplaintId !== null) {
            $sql .= ' AND c.complaint_id != ?';
            $params[] = $excludeComplaintId;
        }
        $sql .= ' ORDER BY c.created_at DESC LIMIT 5';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function linkWalkinToExisting(int $complaintId, int $staffUserId, array $data = []): array
    {
        if ($complaintId < 1 || $staffUserId < 1) {
            return ['success' => false, 'message' => 'A valid complaint and authenticated officer are required.'];
        }

        $walkinNotes = trim((string) ($data['walkin_notes'] ?? ($data['notes'] ?? '')));
        if (mb_strlen($walkinNotes) > 2000) {
            return ['success' => false, 'message' => 'Walk-in follow-up notes must be 2,000 characters or fewer.'];
        }

        $manageTx = !$this->conn->inTransaction();
        try {
            if ($manageTx) {
                $this->conn->beginTransaction();
            }

            $stmt = $this->conn->prepare("SELECT complaint_id, complaint_number, complaint_title, status, additional_details FROM complaints WHERE complaint_id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$complaintId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || in_array((string) $row['status'], ['Archived', 'Rejected'], true)) {
                if ($manageTx && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'That complaint cannot be linked in its current status.'];
            }

            $stamp = date('Y-m-d H:i');
            $noteEntry = "[Walk-in Follow-up Linked {$stamp}]" . ($walkinNotes !== '' ? ": {$walkinNotes}" : ' Complainant appeared in person for existing submission.');
            $mergedDetails = trim((string) ($row['additional_details'] ?? ''));
            $mergedDetails = $mergedDetails !== '' ? ($mergedDetails . "\n" . $noteEntry) : $noteEntry;

            $sets = ['additional_details = ?', 'updated_at = CURRENT_TIMESTAMP'];
            $params = [mb_substr($mergedDetails, 0, 10000)];
            if ($this->hasComplaintColumn('walkin_verified_at')) {
                $sets[] = 'walkin_verified_at = CURRENT_TIMESTAMP';
            }
            if ($this->hasComplaintColumn('walkin_verified_by')) {
                $sets[] = 'walkin_verified_by = ?';
                $params[] = $staffUserId;
            }
            if ($this->hasComplaintColumn('walkin_notes') && $walkinNotes !== '') {
                $sets[] = 'walkin_notes = ?';
                $params[] = $walkinNotes;
            }
            $params[] = $complaintId;

            $this->conn->prepare('UPDATE complaints SET ' . implode(', ', $sets) . ' WHERE complaint_id = ?')->execute($params);

            if ($manageTx) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'complaint_id' => $complaintId,
                'complaint_number' => $row['complaint_number'],
                'status' => $row['status'],
                'message' => 'Walk-in visit linked to existing complaint ' . ($row['complaint_number'] ?: "#{$complaintId}") . ' without creating a duplicate.',
                'redirect_url' => '../complaints/complaint-details.php?id=' . $complaintId
            ];
        } catch (Throwable $e) {
            if ($manageTx && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('linkWalkinToExisting error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to link the walk-in visit right now.'];
        }
    }

    private function findResidentIdByName(string $firstName, string $lastName): ?int
    {
        if ($firstName === '' || $lastName === '') {
            return null;
        }
        $stmt = $this->conn->prepare(
            'SELECT resident_id FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([$firstName, $lastName]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function resolveResidentId(string $name, ?int $residentId = null): ?int
    {
        if ($residentId && $residentId > 0) {
            $check = $this->conn->prepare('SELECT resident_id FROM residents WHERE resident_id = ?');
            $check->execute([$residentId]);
            if ($check->fetchColumn()) {
                return (int) $residentId;
            }
        }

        $name = trim($name);
        if ($name === '') {
            return null;
        }

        // Check existing resident by full name
        $lookup = $this->conn->prepare("
            SELECT resident_id FROM residents
            WHERE TRIM(CONCAT_WS(' ', first_name, last_name)) = ?
               OR TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) = ?
               OR TRIM(CONCAT(last_name, ', ', first_name)) = ?
            LIMIT 1
        ");
        $lookup->execute([$name, $name, $name]);
        $existingId = $lookup->fetchColumn();
        if ($existingId) {
            return (int) $existingId;
        }

        // Auto-create resident entry to ensure foreign key on complaint_parties is satisfied
        $parts = preg_split('/\\s+/', $name);
        if (count($parts) === 1) {
            $firstName = $parts[0];
            $lastName = '-';
            $middleName = null;
        } elseif (count($parts) === 2) {
            $firstName = $parts[0];
            $lastName = $parts[1];
            $middleName = null;
        } else {
            $firstName = $parts[0];
            $lastName = array_pop($parts);
            $middleName = implode(' ', array_slice($parts, 1));
        }

        $createStmt = $this->conn->prepare("
            INSERT INTO residents (first_name, middle_name, last_name)
            VALUES (?, ?, ?)
        ");
        $createStmt->execute([$firstName, $middleName ?: null, $lastName]);
        return (int) $this->conn->lastInsertId();
    }

    /**
     * Generates a Paanyaya case number in the format P-month-number-year
     * e.g. P-10-86-2026
     */
    public static function generatePaanyayaCaseNumber(int $complaintId, ?string $dateStr = null): string
    {
        $ts = $dateStr ? (strtotime($dateStr) ?: time()) : time();
        $month = (int) date('m', $ts);
        $year = (int) date('Y', $ts);
        return sprintf('P-%02d-%02d-%04d', $month, $complaintId, $year);
    }

}
