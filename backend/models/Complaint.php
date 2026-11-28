<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ResidentNameLookup.php';
require_once __DIR__ . '/../services/ValidationService.php';
require_once __DIR__ . '/../services/ComplaintLifecycleService.php';
require_once __DIR__ . '/../services/ComplaintInformationService.php';
require_once __DIR__ . '/../services/ComplaintClassificationService.php';
require_once __DIR__ . '/../services/ComplaintRoutingService.php';
require_once __DIR__ . '/../services/PiiCryptoService.php';
require_once __DIR__ . '/../services/AuditService.php';

class Complaint
{
    private PDO $conn;
    private PiiCryptoService $crypto;

    public function __construct(?PDO $conn = null, ?PiiCryptoService $crypto = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->crypto = $crypto ?? new PiiCryptoService();
    }

    public function getAll(bool $includeDeleted = false)
    {
        try {
            $deletedWhere = $includeDeleted ? '1 = 1' : 'c.deleted_at IS NULL';
            $stmt =
            $this->conn->prepare("
                SELECT
                    c.*,
                    cc.category_name,
                    cs.case_id,
                    cs.case_number,
                    cs.case_status
                FROM complaints c
                LEFT JOIN complaint_categories cc
                    ON c.category_id = cc.category_id
                LEFT JOIN complaint_categories cc2
                    ON c.secondary_category_id = cc2.category_id
                LEFT JOIN cases cs
                    ON cs.complaint_id = c.complaint_id
                WHERE {$deletedWhere}
                ORDER BY c.created_at DESC
            ");

            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $lifecycle = new ComplaintLifecycleService($this->conn);
            foreach ($rows as &$row) {
                $row['lifecycle_status'] = $lifecycle->forComplaint((int) $row['complaint_id']);
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
            return PiiCryptoService::decryptPayload($rows);

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return [];
        }
    }

    public function getById(int $id, bool $includeDeleted = false): array|false
    {
        try {
            $deletedWhere = $includeDeleted ? '' : ' AND c.deleted_at IS NULL';
            $stmt =
            $this->conn->prepare("
                SELECT c.*, cs.case_id, cs.case_number, cs.case_status, cat.category_name, cat2.category_name AS secondary_category_name,
                       TRIM(CONCAT_WS(' ', bpso_u.first_name, bpso_u.last_name)) AS bpso_reviewer_name
                FROM complaints c
                LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
                LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
                LEFT JOIN complaint_categories cat2 ON cat2.category_id = c.secondary_category_id
                LEFT JOIN users bpso_u ON bpso_u.user_id = c.bpso_reviewed_by
                WHERE c.complaint_id=?{$deletedWhere}
            ");

            $stmt->execute([$id]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['kp7_available'] = ComplaintLifecycleService::canAccessKpForm7($row);
                $row['lifecycle_status'] = (new ComplaintLifecycleService($this->conn))->forComplaint((int) $row['complaint_id']);
                $screening = $this->conn->prepare('SELECT * FROM complaint_intake_screenings WHERE complaint_id = ?');
                $screening->execute([(int) $row['complaint_id']]);
                $row['intake_screening'] = $screening->fetch(PDO::FETCH_ASSOC) ?: null;
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
            return $row ? PiiCryptoService::decryptPayload($row) : false;

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }

    public function create(array $data, bool $paanyayaHandoff = false): array
    {
        $data = PiiCryptoService::unwrapSubmittedInputs($data);
        $ownsTransaction = !$this->conn->inTransaction();
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

            $actorId = (int) ($_SESSION['user_id'] ?? 0);
            $actorRoleName = (string) ($_SESSION['role_name'] ?? '');
            $isResidentPortal = $actorRoleName === 'Resident';
            $isBpsoActor = $actorRoleName === 'BPSO';
            $filingTrack = trim((string) ($data['filing_track'] ?? ''));
            $isBpsoToLiso = !$isResidentPortal && !$paanyayaHandoff && ($isBpsoActor || $filingTrack === 'bpso_to_liso');
            $data['intake_source'] = $isResidentPortal ? 'Online' : 'Walk-in';
            if ($isResidentPortal) {
                $profile = $this->conn->prepare('SELECT resident_id FROM resident_user_profiles WHERE user_id = ?');
                $profile->execute([$actorId]);
                $residentId = (int) $profile->fetchColumn();
                if ($residentId < 1) {
                    return ['success' => false, 'message' => 'Your Resident account is not linked to a resident profile. Please contact the barangay office.'];
                }
                $data['complainant_resident_id'] = $residentId;
                foreach (($data['party_types'] ?? []) as $index => $partyType) {
                    if ($partyType === 'Complainant') {
                        // The client renders primary parties again as hidden
                        // multi-party inputs. Bind every complainant entry to
                        // the signed-in profile rather than trusting those IDs.
                        $data['party_resident_ids'][$index] = $residentId;
                    }
                }

                // Online residents describe their concern in plain language; LISO
                // determines the formal KP category and Civil/Criminal type at screening.
                if (empty($data['category_id']) && trim((string)($data['category_name'] ?? '')) === '') {
                    $fallbackCatId = (int) ($this->conn->query(
                        "SELECT category_id FROM complaint_categories
                         WHERE category_name LIKE 'Others%' OR category_name LIKE 'Iba pa%'
                         ORDER BY category_id ASC LIMIT 1"
                    )->fetchColumn() ?: 0);
                    if ($fallbackCatId < 1) {
                        $fallbackCatId = (int) ($this->conn->query(
                            'SELECT category_id FROM complaint_categories ORDER BY category_id ASC LIMIT 1'
                        )->fetchColumn() ?: 0);
                    }
                    if ($fallbackCatId > 0) {
                        $data['category_id'] = $fallbackCatId;
                        $data['other_category_specification'] = 'Pending Barangay LISO Classification';
                    }
                }
                if (empty($data['case_type'])) {
                    $data['case_type'] = 'Civil';
                }
            }

            $assignedLisoId = null;
            $assignedDutySessionId = null;
            $isWalkInImmediateAccept = !$isResidentPortal && !$paanyayaHandoff && !$isBpsoToLiso;

            if ($isBpsoToLiso) {
                if (empty($data['bpso_identity_verified'])) {
                    return ['success' => false, 'message' => 'Confirm complainant identity verification before logging the walk-in blotter and endorsing to LISO.'];
                }
                if (empty($data['bpso_respondent_in_tumana'])) {
                    return ['success' => false, 'message' => 'Confirm that the respondent resides in Barangay Tumana before endorsing to LISO.'];
                }
                $requestedLiso = filter_var($data['liso_assigned_to'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
                if ($requestedLiso !== null) {
                    $dutyStmt = $this->conn->prepare(
                        "SELECT d.duty_session_id
                         FROM liso_duty_sessions d
                         INNER JOIN users u ON u.user_id = d.user_id
                         INNER JOIN roles r ON r.role_id = u.role_id
                         WHERE d.user_id = ? AND d.active_marker = 1 AND d.ended_at IS NULL
                           AND DATE(d.started_at) = CURDATE()
                           AND u.status = 'Active' AND r.role_name = 'Lupon Member'
                         ORDER BY d.started_at DESC LIMIT 1"
                    );
                    $dutyStmt->execute([$requestedLiso]);
                    $dutySessionId = $dutyStmt->fetchColumn();
                    if (!$dutySessionId) {
                        return ['success' => false, 'message' => 'Selected LISO officer is not currently on active LISO duty today.'];
                    }
                    $assignedLisoId = $requestedLiso;
                    $assignedDutySessionId = (int) $dutySessionId;
                }
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
            if (!$isResidentPortal) {
                $jurisdictionValidation = $this->validateJurisdictionInput($data);
                if (!$jurisdictionValidation['success']) {
                    return $jurisdictionValidation;
                }
                $duplicates = $this->findPotentialDuplicates($data);
                if ($duplicates !== [] && empty($data['duplicate_acknowledged'])) {
                    return [
                        'success' => false,
                        'code' => 'POSSIBLE_DUPLICATE_REQUIRES_ACKNOWLEDGEMENT',
                        'message' => 'A possible duplicate complaint was found. Review it and confirm that this filing should proceed.',
                        'duplicates' => $duplicates,
                    ];
                }
            }

            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }

            $requestedCaseType = trim((string)($data['case_type'] ?? ''));
            $caseType = ComplaintClassificationService::resolveEffectiveCaseType(
                $this->conn,
                !empty($data['category_id']) ? (int) $data['category_id'] : null,
                !empty($data['secondary_category_id']) ? (int) $data['secondary_category_id'] : null,
                in_array($requestedCaseType, ['Civil', 'Criminal'], true) ? $requestedCaseType : null
            );
            $data['case_type'] = $caseType;

            if ($ownsTransaction) {
                $this->conn->beginTransaction();
            }
            $stmt =
            $this->conn->prepare("
                INSERT INTO complaints
                (
                    category_id,
                    secondary_category_id,
                    other_category_specification,
                    secondary_other_category_specification,
                    case_type,
                    complaint_title,
                    incident_date,
                    incident_time,
                    is_time_approximate,
                    is_time_unknown,
                    incident_location,
                    incident_city,
                    incident_barangay,
                    incident_street,
                    incident_purok,
                    incident_landmark,
                    incident_location_details,
                    narrative,
                    additional_details,
                    requested_resolution,
                    intake_source,
                    bpso_status,
                    bpso_blotter_no,
                    bpso_respondent_in_tumana,
                    bpso_identity_verified,
                    bpso_private_notes,
                    bpso_reviewed_by,
                    bpso_reviewed_at,
                    submitted_by,
                    screening_decision,
                    status,
                    document_status,
                    is_draft,
                    encoded_by,
                    liso_assigned_to,
                    liso_assigned_duty_session_id,
                    liso_assigned_at,
                    liso_reviewed_by,
                    liso_reviewed_at
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $timeVal = !empty($data['is_time_unknown']) ? null : (trim((string) ($data['incident_time'] ?? '')) ?: null);
            $secCatId = filter_var($data['secondary_category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $secOtherSpec = trim((string)($data['secondary_other_category_specification'] ?? '')) ?: null;
            $walkInActor = $actorId ?: ($data['clerk_id'] ?? $data['encoded_by'] ?? 1);
            $bpsoStatus = $isResidentPortal ? 'Pending BPSO Review' : 'Endorsed to LISO';
            $rawBlotterNo = trim((string) ($data['bpso_blotter_no'] ?? $data['blotter_ref_no'] ?? ''));
            $bpsoBlotterNo = (!$isResidentPortal && $rawBlotterNo !== '') ? mb_substr($rawBlotterNo, 0, 64) : null;
            $bpsoPrivateNotes = (!$isResidentPortal && trim((string) ($data['bpso_private_notes'] ?? '')) !== '')
                ? mb_substr(trim((string) $data['bpso_private_notes']), 0, 2000)
                : null;
            $bpsoReviewedBy = $isResidentPortal ? null : $walkInActor;
            $bpsoReviewedAt = $isResidentPortal ? null : date('Y-m-d H:i:s');
            $initialStatus = $isWalkInImmediateAccept ? 'Accepted' : ($isBpsoToLiso ? 'Under Review' : 'Submitted');
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
                $data['intake_source'],
                $bpsoStatus,
                $bpsoBlotterNo,
                $isResidentPortal ? null : 1,
                $isResidentPortal ? 0 : 1,
                $bpsoPrivateNotes,
                $bpsoReviewedBy,
                $bpsoReviewedAt,
                $isResidentPortal ? $actorId : null,
                $isWalkInImmediateAccept ? 'Eligible' : 'Pending',
                $initialStatus,
                'No Scan Uploaded',
                0,
                $isResidentPortal ? null : $walkInActor,
                $assignedLisoId,
                $assignedDutySessionId,
                $assignedLisoId !== null ? date('Y-m-d H:i:s') : null,
                $isWalkInImmediateAccept ? $walkInActor : null,
                $isWalkInImmediateAccept ? date('Y-m-d H:i:s') : null
            ]);

            $complaintId = (int) $this->conn->lastInsertId();
            $intakeReference = sprintf('INT-%s-%06d', date('Y'), $complaintId);
            if ($isBpsoToLiso && $bpsoBlotterNo === null) {
                $bpsoBlotterNo = sprintf('BLT-%s-%06d', date('Y'), $complaintId);
            }

            // A1 / A2: Automated classification & routing evaluation persisted on intake
            $classificationSuggestion = ComplaintClassificationService::suggestFromNarrative(
                $this->conn,
                trim((string) $data['narrative']),
                trim((string) ($data['requested_resolution'] ?? ''))
            );
            $proposedCategoryId = !empty($classificationSuggestion['primary']['category_id'])
                ? (int) $classificationSuggestion['primary']['category_id']
                : (!empty($data['category_id']) ? (int) $data['category_id'] : null);
            $classifierConfidence = (string) ($classificationSuggestion['primary']['confidence'] ?? '');

            $routingResult = ComplaintRoutingService::evaluate([
                'complaint_title' => trim((string) $data['complaint_title']),
                'description' => trim((string) $data['narrative']),
                'category' => (string) ($classificationSuggestion['primary']['category_name'] ?? ($data['category_name'] ?? '')),
                'case_type' => $caseType,
                'claim_amount' => $data['claim_amount'] ?? null,
                'is_repeat_offense_vawc' => !empty($data['is_repeat_offense_vawc']),
                'has_minor_party' => !empty($data['has_minor_party']) || !empty($data['complainant_is_minor']) || !empty($data['respondent_is_minor']),
                'is_walk_in_accepted' => $isWalkInImmediateAccept,
                'classifier_confidence' => $classifierConfidence,
            ]);

            $privacyAcknowledged = !empty($data['privacy_notice_acknowledged']) ? 1 : 0;
            $privacyVersion = $privacyAcknowledged ? 'RA10173-NPC2023-06-v1' : null;
            $privacyAt = $privacyAcknowledged ? date('Y-m-d H:i:s') : null;
            $routedAt = date('Y-m-d H:i:s');

            $initialAssignedQueue = ($isResidentPortal && $routingResult['assigned_queue'] === 'LISO_SCREENING')
                ? 'BPSO_VALIDATION'
                : $routingResult['assigned_queue'];

            $this->conn->prepare(
                'UPDATE complaints
                 SET intake_reference = ?,
                     bpso_blotter_no = COALESCE(bpso_blotter_no, ?),
                     proposed_category_id = ?,
                     routing_route = ?,
                     assigned_queue = ?,
                     routing_rule_version = ?,
                     routing_reasons = ?,
                     routed_at = ?,
                     privacy_notice_acknowledged = ?,
                     privacy_notice_version = ?,
                     privacy_consented_at = ?
                 WHERE complaint_id = ?'
            )->execute([
                $intakeReference,
                $bpsoBlotterNo,
                $proposedCategoryId,
                $routingResult['route'],
                $initialAssignedQueue,
                $routingResult['rule_version'],
                json_encode($routingResult['reasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $routedAt,
                $privacyAcknowledged,
                $privacyVersion,
                $privacyAt,
                $complaintId,
            ]);

            $this->conn->prepare(
                "INSERT INTO complaint_routing_logs
                 (complaint_id, event_type, rule_version, proposed_category_id, routing_route, assigned_queue, reasons_json, matched_keywords_json, actor_user_id)
                 VALUES (?, 'Automated', ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $complaintId,
                $routingResult['rule_version'],
                $proposedCategoryId,
                $routingResult['route'],
                $routingResult['assigned_queue'],
                json_encode($routingResult['reasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($routingResult['matched_keywords'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $actorId > 0 ? $actorId : null,
            ]);

            $this->applyMapLocation($complaintId, $data);
            $this->applyParties($complaintId, $data);
            if (!$isResidentPortal) {
                $linkedUserStmt = $this->conn->prepare(
                    "SELECT rup.user_id
                     FROM complaint_parties cp
                     INNER JOIN resident_user_profiles rup ON rup.resident_id = cp.resident_id
                     INNER JOIN users u ON u.user_id = rup.user_id AND u.status = 'Active'
                     WHERE cp.complaint_id = ? AND cp.party_type = 'Complainant'
                     ORDER BY cp.party_id ASC
                     LIMIT 1"
                );
                $linkedUserStmt->execute([$complaintId]);
                $linkedResidentUserId = (int) ($linkedUserStmt->fetchColumn() ?: 0);
                if ($linkedResidentUserId > 0) {
                    $this->conn->prepare('UPDATE complaints SET submitted_by = ? WHERE complaint_id = ? AND submitted_by IS NULL')
                        ->execute([$linkedResidentUserId, $complaintId]);
                }
            }
            $this->saveIntakeScreening($complaintId, $data);
            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $notifService = new NotificationService($this->conn);
                $cNum = $intakeReference;
                $actionUrl = "../complaints/complaint-details.php?id=" . (int) $complaintId . ($isWalkInImmediateAccept ? '&tab=paanyaya' : '');
                if ($isWalkInImmediateAccept) {
                    $notifService->notifyRoles(
                        ['Administrator', 'Lupon Clerk'],
                        'Walk-in Complaint Ready for Paanyaya',
                        "Walk-in intake {$cNum} (" . ($data['complaint_title'] ?? 'General Dispute') . ') was accepted and is ready for Paanyaya preparation.',
                        $actorId ?: null,
                        'info',
                        $actionUrl
                    );
                } elseif ($isResidentPortal) {
                    $notifService->notifyRoles(
                        ['BPSO', 'Administrator'],
                        'New Online Complaint for BPSO Validation',
                        "Online intake {$cNum} (" . ($data['complaint_title'] ?? 'General Dispute') . ") requires BPSO desk validation and Tumana residency verification before LISO screening.",
                        $actorId ?: null,
                        'info',
                        "../complaints/complaint-details.php?id=" . (int) $complaintId . "#bpso-validation"
                    );
                } elseif ($isBpsoToLiso) {
                    $lisoUrl = "../complaints/complaint-details.php?id=" . (int) $complaintId . "#intake-screening";
                    if ($assignedLisoId !== null) {
                        $notifService->notifyUser(
                            $assignedLisoId,
                            'Walk-in Blotter Endorsed to LISO',
                            "BPSO logged walk-in {$cNum} (Blotter {$bpsoBlotterNo}) and assigned it to you for KP jurisdiction evaluation.",
                            $actorId ?: null,
                            'info',
                            $lisoUrl
                        );
                    } else {
                        $notifService->notifyRoles(
                            ['Lupon Member', 'Administrator'],
                            'Walk-in Blotter Endorsed to LISO',
                            "BPSO logged walk-in {$cNum} (Blotter {$bpsoBlotterNo}) and endorsed it for LISO jurisdiction screening.",
                            $actorId ?: null,
                            'info',
                            $lisoUrl
                        );
                    }
                } elseif (!$paanyayaHandoff) {
                    $notifService->notifyRoles(
                        ['Administrator', 'Lupon Member'],
                        'New Complaint Intake',
                        "New {$data['intake_source']} intake {$cNum} (" . ($data['complaint_title'] ?? 'General Dispute') . ") is ready for preliminary screening.",
                        $actorId ?: null,
                        'info',
                        $actionUrl
                    );
                }
            } catch (Throwable $ne) {
                error_log('Failed to dispatch new complaint notification: ' . $ne->getMessage());
            }

            if ($ownsTransaction) {
                $this->conn->commit();
            }
            return [
                'success' => true,
                'message' => $isWalkInImmediateAccept
                    ? 'Walk-in complaint accepted and ready for Paanyaya preparation.'
                    : ($isResidentPortal
                        ? 'Online complaint submitted for BPSO desk validation and Tumana residency verification.'
                        : "Walk-in blotter ({$bpsoBlotterNo}) recorded and endorsed to LISO for jurisdiction screening."),
                'case_id' => null,
                'case_number' => null,
                'status' => $initialStatus,
                'bpso_status' => $bpsoStatus,
                'bpso_blotter_no' => $bpsoBlotterNo,
                'complaint_id' => $complaintId,
                'intake_reference' => $intakeReference,
                'complaint_number' => null,
                'is_draft' => false,
                'routing' => $routingResult,
            ];

        }
        catch(Throwable $e)
        {
            if ($ownsTransaction && $this->conn->inTransaction()) {
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
            $data = PiiCryptoService::unwrapSubmittedInputs($data);

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
            if (array_key_exists('jurisdiction_screened', $data)) {
                $jurisdictionValidation = $this->validateJurisdictionInput($data);
                if (!$jurisdictionValidation['success']) {
                    return $jurisdictionValidation;
                }
            }
            $duplicates = $this->findPotentialDuplicates($data, $complaintId);
            if ($duplicates !== [] && empty($data['duplicate_acknowledged'])) {
                return [
                    'success' => false,
                    'code' => 'POSSIBLE_DUPLICATE_REQUIRES_ACKNOWLEDGEMENT',
                    'message' => 'A possible duplicate complaint was found. Review it and confirm that this filing should proceed.',
                    'duplicates' => $duplicates,
                ];
            }

            $exists = $this->conn->prepare('SELECT complaint_id FROM complaints WHERE complaint_id = ?');
            $exists->execute([$complaintId]);
            if (!$exists->fetchColumn()) {
                return ['success' => false, 'message' => 'The complaint could not be found.'];
            }

            $requestedCaseType = trim((string)($data['case_type'] ?? ''));
            $caseType = ComplaintClassificationService::resolveEffectiveCaseType(
                $this->conn,
                !empty($data['category_id']) ? (int) $data['category_id'] : null,
                !empty($data['secondary_category_id']) ? (int) $data['secondary_category_id'] : null,
                in_array($requestedCaseType, ['Civil', 'Criminal'], true) ? $requestedCaseType : null
            );
            $data['case_type'] = $caseType;

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
                    is_draft=0
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
                $complaintId
            ]);

            $updateCase = $this->conn->prepare("
                UPDATE cases
                SET case_type = ?
                WHERE complaint_id = ? AND case_status IN ('Docketed', 'Mediation')
            ");
            $updateCase->execute([$caseType, $complaintId]);

            $this->applyMapLocation($complaintId, $data);

            if (isset($data['complainant_resident_id']) || isset($data['respondent_resident_id']) || isset($data['complainant_name']) || isset($data['respondent_name'])) {
                $delParties = $this->conn->prepare('DELETE FROM complaint_parties WHERE complaint_id = ?');
                $delParties->execute([$complaintId]);
                $this->applyParties($complaintId, $data);
            }
            if (array_key_exists('jurisdiction_screened', $data)) {
                $this->saveIntakeScreening($complaintId, $data);
                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notifService = new NotificationService();
                    $cNum = $this->conn->query("SELECT complaint_number FROM complaints WHERE complaint_id = {$complaintId}")->fetchColumn() ?: "CMP-#{$complaintId}";
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
            } elseif ($caseType === 'Civil') {
                $this->conn->prepare(
                    'UPDATE complaint_intake_screenings
                     SET offense_imprisonment_max = 0,
                         has_private_offended_party = 1,
                         accused_under_detention = 0,
                         habeas_corpus_needed = 0,
                         prescription_risk = 0
                     WHERE complaint_id = ?'
                )->execute([$complaintId]);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Complaint updated successfully.',
                'complaint_id' => $complaintId
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

    public function review(int $id, string $status, ?string $notes, ?int $reviewerId = null, bool $mustBeAssignedLiso = false, string $requestMessage = '', array $classification = []): array
    {
        if (strcasecmp($status, 'Approved') === 0) {
            $status = 'Accepted';
        }

        $allowed = ['Under Review', 'Needs Information', 'Accepted', 'Referred', 'Rejected'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'Select a valid review decision.'];
        }
        $decision = match ($status) {
            'Needs Information' => 'Needs Information',
            'Accepted' => 'Eligible',
            'Referred' => 'Referred',
            'Rejected' => 'Rejected',
            default => 'Pending',
        };
        $reviewerId ??= (int) ($_SESSION['user_id'] ?? 0);
        if ($status === 'Rejected' && (trim((string) $notes) === '' || mb_strlen((string) $notes) > 2000 || !ValidationService::text((string) $notes))) {
            return ['success' => false, 'message' => 'Provide a rejection reason or jurisdictional basis (up to 2000 characters).'];
        }
        $resolvedAgency = null;
        $counselingNotes = trim((string) ($classification['counseling_notes'] ?? $notes ?? ''));
        if ($status === 'Referred') {
            $agencyInput = trim((string) ($classification['endorsement_agency'] ?? ''));
            $resolvedAgency = ComplaintRoutingService::resolveExternalAgency($agencyInput);
            if ($resolvedAgency === null) {
                return ['success' => false, 'message' => 'Select an approved government agency for external endorsement (PNP, Marikina Settlement Office, CENO, TMO, VAWC, BCPC, or MTC / Prosecutor).'];
            }
            if ($counselingNotes === '' || mb_strlen($counselingNotes) > 2000 || !ValidationService::text($counselingNotes, true)) {
                return ['success' => false, 'message' => 'Provide pre-filing counseling notes and jurisdictional basis (up to 2,000 characters).'];
            }
            $notes = $counselingNotes;
        }
        if ($status === 'Accepted' && !empty($classification['compact_view']) && empty($classification['jurisdiction_confirmed'])) {
            return ['success' => false, 'message' => 'Pakikumpirma muna ang checkbox ("Nabasa at nasuri ko ang salaysay, mga partido, at hurisdiksyon (R.A. 7160).") bago aprubahan.'];
        }
        $this->conn->beginTransaction();
        try {
            $lock = $this->conn->prepare(
                "SELECT c.submitted_by, c.liso_assigned_to, c.bpso_status, c.status, c.screening_decision, c.is_draft,
                        c.assigned_queue, c.category_id, c.secondary_category_id, c.other_category_specification,
                        c.secondary_other_category_specification, c.case_type, c.complaint_title,
                        cs.case_id, assigned.role_id AS assigned_role, assigned.status AS assigned_status
                 FROM complaints c
                 LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
                 LEFT JOIN users assigned ON assigned.user_id = c.liso_assigned_to
                 WHERE c.complaint_id = ? FOR UPDATE"
            );
            $lock->execute([$id]);
            $intake = $lock->fetch();
            $assignedId = (int) ($intake['liso_assigned_to'] ?? 0);
            if (!$intake || $intake['case_id'] || $intake['is_draft'] || $intake['screening_decision'] === 'Eligible'
                || !in_array($intake['status'], ['Submitted', 'Filed', 'Under Review', 'Needs Information', 'Rejected'], true)
                || (($mustBeAssignedLiso || $intake['submitted_by']) && $assignedId && ($assignedId !== $reviewerId
                    || (int) $intake['assigned_role'] !== 3 || $intake['assigned_status'] !== 'Active'))) {
                throw new DomainException('This intake has already been screened or can no longer be updated by your account.');
            }
            if (($intake['bpso_status'] ?? 'Endorsed to LISO') !== 'Endorsed to LISO') {
                throw new DomainException('This complaint must be validated and endorsed by BPSO before LISO screening.');
            }

            $hasClassificationInput = array_intersect(
                ['category_id', 'category_name', 'secondary_category_id', 'secondary_category_name', 'other_category_specification', 'secondary_other_category_specification', 'case_type', 'complaint_title'],
                array_keys($classification)
            ) !== [];

            $othersCatId = (int) ($this->conn->query(
                "SELECT category_id FROM complaint_categories
                 WHERE category_name LIKE 'Others%' OR category_name LIKE 'Iba pa%'
                 ORDER BY category_id ASC LIMIT 1"
            )->fetchColumn() ?: 51);

            $effectiveCategoryId = (array_key_exists('category_id', $classification) && trim((string) $classification['category_id']) !== '')
                ? (filter_var($classification['category_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0)
                : (array_key_exists('category_name', $classification) ? 0 : (int) ($intake['category_id'] ?? 0));
            $effectiveOtherSpec = array_key_exists('other_category_specification', $classification)
                ? (trim((string) $classification['other_category_specification']) ?: null)
                : ($intake['other_category_specification'] ?: null);

            $categoryNameInput = trim((string) ($classification['category_name'] ?? ''));
            if (!$effectiveCategoryId && $categoryNameInput !== '') {
                $catMatch = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_name = ? OR category_name LIKE ? LIMIT 1');
                $catMatch->execute([$categoryNameInput, '%' . $categoryNameInput . '%']);
                $matchedRow = $catMatch->fetch(PDO::FETCH_ASSOC);
                if ($matchedRow) {
                    $effectiveCategoryId = (int) $matchedRow['category_id'];
                } else {
                    $effectiveCategoryId = $othersCatId;
                    if ($effectiveOtherSpec === null || $effectiveOtherSpec === '' || strcasecmp($effectiveOtherSpec, 'Pending Barangay LISO Classification') === 0) {
                        $effectiveOtherSpec = $categoryNameInput;
                    }
                }
            }

            $effectiveSecCatId = array_key_exists('secondary_category_id', $classification)
                ? (filter_var($classification['secondary_category_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null)
                : (array_key_exists('secondary_category_name', $classification) ? null : (!empty($intake['secondary_category_id']) ? (int) $intake['secondary_category_id'] : null));
            $effectiveSecOtherSpec = array_key_exists('secondary_other_category_specification', $classification)
                ? (trim((string) $classification['secondary_other_category_specification']) ?: null)
                : ($intake['secondary_other_category_specification'] ?: null);

            $secCategoryNameInput = trim((string) ($classification['secondary_category_name'] ?? ''));
            if (!$effectiveSecCatId && $secCategoryNameInput !== '') {
                $catMatch2 = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_name = ? OR category_name LIKE ? LIMIT 1');
                $catMatch2->execute([$secCategoryNameInput, '%' . $secCategoryNameInput . '%']);
                $matchedRow2 = $catMatch2->fetch(PDO::FETCH_ASSOC);
                if ($matchedRow2) {
                    $effectiveSecCatId = (int) $matchedRow2['category_id'];
                } else {
                    $effectiveSecCatId = $othersCatId;
                    if ($effectiveSecOtherSpec === null || $effectiveSecOtherSpec === '') {
                        $effectiveSecOtherSpec = $secCategoryNameInput;
                    }
                }
            }
            $explicitCaseType = (array_key_exists('case_type', $classification) && in_array(trim((string) $classification['case_type']), ['Civil', 'Criminal'], true))
                ? trim((string) $classification['case_type'])
                : null;
            $effectiveCaseType = ($hasClassificationInput || $explicitCaseType !== null)
                ? ComplaintClassificationService::resolveEffectiveCaseType(
                    $this->conn,
                    $effectiveCategoryId ?: null,
                    $effectiveSecCatId,
                    $explicitCaseType
                )
                : (string) ($intake['case_type'] ?? 'Civil');
            $effectiveTitle = array_key_exists('complaint_title', $classification)
                ? trim((string) $classification['complaint_title'])
                : trim((string) ($intake['complaint_title'] ?? ''));

            if ($status === 'Accepted' || $hasClassificationInput) {
                $catStmt = $this->conn->prepare('SELECT category_id, category_name FROM complaint_categories WHERE category_id = ? LIMIT 1');
                $catStmt->execute([$effectiveCategoryId]);
                $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
                if (!$catRow) {
                    throw new DomainException('Select a valid complaint category before saving the screening decision.');
                }
                $isOtherCat = stripos($catRow['category_name'], 'Others') !== false || stripos($catRow['category_name'], 'Iba pa') !== false;
                if ($isOtherCat) {
                    if ($status === 'Accepted' && strcasecmp((string) $effectiveOtherSpec, 'Pending Barangay LISO Classification') === 0) {
                        throw new DomainException('Determine the official complaint category or specify the exact dispute type before marking this intake eligible.');
                    }
                    if ($status === 'Accepted' && ($effectiveOtherSpec === null || $effectiveOtherSpec === '')) {
                        throw new DomainException('Specify the custom complaint category under "Others" before marking this intake eligible.');
                    }
                    if ($effectiveOtherSpec !== null && (mb_strlen($effectiveOtherSpec) > 255 || !ValidationService::text($effectiveOtherSpec))) {
                        throw new DomainException('Provide a valid custom category specification of 255 characters or fewer.');
                    }
                } else {
                    $effectiveOtherSpec = null;
                }

                if ($effectiveSecCatId !== null) {
                    if ($effectiveSecCatId === $effectiveCategoryId && (!$isOtherCat || strcasecmp((string) $effectiveOtherSpec, (string) $effectiveSecOtherSpec) === 0)) {
                        throw new DomainException('Select a different 2nd complaint category or leave it blank.');
                    }
                    $catStmt->execute([$effectiveSecCatId]);
                    $secCatRow = $catStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$secCatRow) {
                        throw new DomainException('Select a valid 2nd complaint category.');
                    }
                    $isSecOther = stripos($secCatRow['category_name'], 'Others') !== false || stripos($secCatRow['category_name'], 'Iba pa') !== false;
                    if ($isSecOther) {
                        if ($effectiveSecOtherSpec === null || $effectiveSecOtherSpec === '') {
                            throw new DomainException('Specify the custom 2nd complaint category under "Others".');
                        }
                        if (mb_strlen($effectiveSecOtherSpec) > 255 || !ValidationService::text($effectiveSecOtherSpec)) {
                            throw new DomainException('Provide a valid 2nd category specification of 255 characters or fewer.');
                        }
                    } else {
                        $effectiveSecOtherSpec = null;
                    }
                } else {
                    $effectiveSecOtherSpec = null;
                }

                if (!in_array($effectiveCaseType, ['Civil', 'Criminal'], true)) {
                    throw new DomainException('Select a valid case type (Civil or Criminal).');
                }
                if ($effectiveTitle === '') {
                    $effectiveTitle = $isOtherCat ? (string) $effectiveOtherSpec : (string) $catRow['category_name'];
                }
                if ($effectiveTitle === '' || mb_strlen($effectiveTitle) > 255 || !ValidationService::text($effectiveTitle)) {
                    throw new DomainException('Provide a valid complaint title / specific nature of 255 characters or fewer.');
                }
            }

            $assignReviewer = false;
            if ($intake['submitted_by']) {
                if ($intake['screening_decision'] === 'Needs Information') {
                    $pending = $this->conn->prepare('SELECT responded_at FROM complaint_information_requests
                        WHERE complaint_id = ? ORDER BY request_id DESC LIMIT 1');
                    $pending->execute([$id]);
                    if ($pending->fetchColumn() === null) {
                        throw new DomainException('This complaint is waiting for the resident’s response.');
                    }
                }
                if ($decision === 'Needs Information') {
                    if ($requestMessage === '' || !ValidationService::maxLength($requestMessage, ComplaintInformationService::REQUEST_LIMIT)
                        || !ValidationService::text($requestMessage, true) || !mb_check_encoding($requestMessage, 'UTF-8')) {
                        throw new DomainException('Enter a message for the resident of up to 2,000 characters.');
                    }
                    if (!$assignedId && $mustBeAssignedLiso) {
                        $activeReviewer = $this->conn->prepare("SELECT 1 FROM users WHERE user_id = ?
                            AND role_id = 3 AND status = 'Active'");
                        $activeReviewer->execute([$reviewerId]);
                        $assignReviewer = (bool) $activeReviewer->fetchColumn();
                    }
                    if ((!$assignedId && !$assignReviewer)
                        || ($assignedId && ((int) $intake['assigned_role'] !== 3 || $intake['assigned_status'] !== 'Active'))) {
                        throw new DomainException('An active Lupon Member must handle re-screening for this intake.');
                    }
                    $this->conn->prepare('INSERT INTO complaint_information_requests (complaint_id, requested_by, request_message)
                        VALUES (?, ?, ?)')->execute([$id, $reviewerId, $requestMessage]);
                }
            }

            $this->conn->prepare(
                'UPDATE complaints
                 SET status = ?, screening_decision = ?, review_notes = ?,
                     liso_endorsement_agency = ?, liso_counseling_notes = ?,
                     liso_endorsed_at = CASE WHEN ? = \'Referred\' THEN NOW() ELSE liso_endorsed_at END,
                     assigned_queue = COALESCE(?, assigned_queue),
                     routing_route = COALESCE(?, routing_route),
                     category_id = ?, secondary_category_id = ?, other_category_specification = ?,
                     secondary_other_category_specification = ?, case_type = ?, complaint_title = ?,
                     liso_reviewed_by = ?, liso_reviewed_at = NOW()
                 WHERE complaint_id = ?'
            )->execute([
                $status,
                $decision,
                $notes,
                $resolvedAgency ? $resolvedAgency['full_name'] : null,
                $resolvedAgency ? $counselingNotes : null,
                $status,
                $resolvedAgency ? $resolvedAgency['queue'] : null,
                $resolvedAgency ? $resolvedAgency['route'] : null,
                $effectiveCategoryId,
                $effectiveSecCatId,
                $effectiveOtherSpec,
                $effectiveSecOtherSpec,
                $effectiveCaseType,
                $effectiveTitle,
                $reviewerId ?: null,
                $id
            ]);
            if ($resolvedAgency !== null) {
                $this->conn->prepare(
                    "INSERT INTO complaint_routing_logs
                     (complaint_id, event_type, rule_version, proposed_category_id, routing_route, assigned_queue, previous_queue, override_reason, reasons_json, matched_keywords_json, actor_user_id)
                     VALUES (?, 'Override', ?, ?, ?, ?, ?, ?, ?, '[]', ?)"
                )->execute([
                    $id,
                    ComplaintRoutingService::RULE_VERSION,
                    $effectiveCategoryId ?: null,
                    $resolvedAgency['route'],
                    $resolvedAgency['queue'],
                    $intake['assigned_queue'] ?? null,
                    mb_substr('LISO Endorsement to ' . $resolvedAgency['full_name'] . ': ' . $counselingNotes, 0, 500),
                    json_encode([$resolvedAgency['full_name'] . ': ' . $counselingNotes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $reviewerId ?: null,
                ]);
            }
            if ($assignReviewer) {
                $this->conn->prepare('UPDATE complaints SET liso_assigned_to = ?, liso_assigned_at = NOW() WHERE complaint_id = ?')
                    ->execute([$reviewerId, $id]);
            }
            $hasCriminalScreeningInput = array_intersect(
                ['offense_imprisonment_max', 'has_private_offended_party', 'accused_under_detention', 'habeas_corpus_needed', 'prescription_risk'],
                array_keys($classification)
            ) !== [];
            if ($effectiveCaseType === 'Civil') {
                $this->conn->prepare(
                    'UPDATE complaint_intake_screenings
                     SET offense_imprisonment_max = 0,
                         has_private_offended_party = 1,
                         accused_under_detention = 0,
                         habeas_corpus_needed = 0,
                         prescription_risk = 0,
                         screened_by = ?,
                         screened_at = NOW()
                     WHERE complaint_id = ?'
                )->execute([$reviewerId ?: null, $id]);
            } elseif ($hasCriminalScreeningInput) {
                $impMax = max(0, (float) ($classification['offense_imprisonment_max'] ?? 0));
                $hasPriv = array_key_exists('has_private_offended_party', $classification)
                    ? (!empty($classification['has_private_offended_party']) ? 1 : 0)
                    : 1;
                $accDet = !empty($classification['accused_under_detention']) ? 1 : 0;
                $habCorp = !empty($classification['habeas_corpus_needed']) ? 1 : 0;
                $presc = !empty($classification['prescription_risk']) ? 1 : 0;
                $this->conn->prepare(
                    'INSERT INTO complaint_intake_screenings
                        (complaint_id, offense_imprisonment_max, has_private_offended_party, accused_under_detention, habeas_corpus_needed, prescription_risk, screened_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        offense_imprisonment_max = VALUES(offense_imprisonment_max),
                        has_private_offended_party = VALUES(has_private_offended_party),
                        accused_under_detention = VALUES(accused_under_detention),
                        habeas_corpus_needed = VALUES(habeas_corpus_needed),
                        prescription_risk = VALUES(prescription_risk),
                        screened_by = VALUES(screened_by),
                        screened_at = CURRENT_TIMESTAMP'
                )->execute([$id, $impMax, $hasPriv, $accDet, $habCorp, $presc, $reviewerId ?: null]);
            } else {
                $this->conn->prepare('UPDATE complaint_intake_screenings SET screened_by = ?, screened_at = NOW() WHERE complaint_id = ?')
                    ->execute([$reviewerId ?: null, $id]);
            }
            if (!(new AuditService($this->conn))->log($reviewerId ?: null, 'Reviewed Complaint: ' . $status, 'Complaints', $id)) {
                throw new RuntimeException('Unable to audit the screening decision.');
            }
            $this->conn->commit();
            if ($resolvedAgency !== null) {
                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notifier = new NotificationService($this->conn);
                    if (!empty($intake['submitted_by'])) {
                        $notifier->notifyUser(
                            (int) $intake['submitted_by'],
                            'Complaint Endorsed to ' . $resolvedAgency['label'],
                            'Your complaint falls outside Katarungang Pambarangay jurisdiction and has been endorsed by LISO to ' . $resolvedAgency['full_name'] . '. Counseling note: ' . $counselingNotes,
                            $reviewerId ?: null,
                            'info',
                            'complaint.php?id=' . $id
                        );
                    }
                    $notifier->notifyRoles(
                        ['Administrator', 'Lupon Clerk'],
                        'LISO Endorsed Complaint Externally',
                        'Complaint #' . $id . ' was endorsed by LISO to ' . $resolvedAgency['full_name'] . '.',
                        $reviewerId ?: null,
                        'info',
                        '../complaints/complaint-details.php?id=' . $id
                    );
                } catch (Throwable $ne) {
                    error_log('LISO referral notification warning: ' . $ne->getMessage());
                }
            }
            return [
                'success' => true,
                'message' => $resolvedAgency !== null
                    ? ('Pre-filing counseling recorded and complaint endorsed to ' . $resolvedAgency['full_name'] . '.')
                    : 'Complaint review saved.',
                'endorsement_agency' => $resolvedAgency ? $resolvedAgency['full_name'] : null,
            ];
        } catch (Throwable $exception) {
            $this->conn->rollBack();
            if ($exception instanceof DomainException) {
                return ['success' => false, 'message' => $exception->getMessage()];
            }
            error_log('Complaint review: ' . $exception->getMessage());
            return ['success' => false, 'message' => 'Unable to save the screening decision. Please try again.'];
        }
    }

    public function docket(int $id, int $clerkId, array $feeData = []): array
    {
        require_once __DIR__ . '/CaseModel.php';
        require_once __DIR__ . '/../services/FilingFeeService.php';
        if (PaanyayaPreparationService::managesDialogue($this->conn, $id)) {
            require_once __DIR__ . '/Paanyaya.php';
            return (new Paanyaya($this->conn))->escalateToFormalDocketing($id, $clerkId, null, $feeData);
        }
        $this->conn->beginTransaction();
        try {
            $record = $this->conn->prepare('SELECT complaint_id, case_type, screening_decision FROM complaints WHERE complaint_id = ? FOR UPDATE');
            $record->execute([$id]);
            $complaint = $record->fetch(PDO::FETCH_ASSOC);
            if (!$complaint) throw new InvalidArgumentException('Complaint intake not found.');
            if (($complaint['screening_decision'] ?? '') !== 'Eligible') {
                throw new InvalidArgumentException('Only complaint intakes marked eligible by preliminary screening may be formally docketed.');
            }
            $caseModel = new CaseModel($this->conn);
            $docketingError = $caseModel->getDocketingError($id);
            if ($docketingError === 'duplicate_case') {
                $existingQuery = $this->conn->prepare('SELECT case_id, case_number FROM cases WHERE complaint_id = ? FOR UPDATE');
                $existingQuery->execute([$id]);
                $existingCase = $existingQuery->fetch(PDO::FETCH_ASSOC);
                if ($existingCase) {
                    $this->conn->commit();
                    return ['success' => true, 'message' => 'Complaint already formally docketed.', 'case_id' => (int) $existingCase['case_id'], 'case_number' => $existingCase['case_number']];
                }
            }
            if ($docketingError === 'paanyaya_preparation_required') {
                throw new InvalidArgumentException('Continue this walk-in from Paanyaya Preparation on its intake record.');
            }
            if ($docketingError !== null) {
                throw new InvalidArgumentException('This complaint is not yet authorized for formal docketing.');
            }
            (new FilingFeeService($this->conn))->ensureReadyForDocketing($id, $feeData, $clerkId);
            if (!$caseModel->create(['complaint_id' => $id, 'case_type' => $complaint['case_type']])) {
                throw new RuntimeException('Formal case docketing could not be completed.');
            }
            $caseQuery = $this->conn->prepare('SELECT case_id, case_number FROM cases WHERE complaint_id = ? LIMIT 1');
            $caseQuery->execute([$id]);
            $case = $caseQuery->fetch(PDO::FETCH_ASSOC);
            if (!$case) throw new RuntimeException('The formal docket was not created.');
            $this->conn->prepare('UPDATE complaints SET docketed_by = ?, docketed_at = NOW() WHERE complaint_id = ?')->execute([$clerkId, $id]);
            $this->conn->commit();
            return ['success' => true, 'message' => 'Complaint formally docketed.', 'case_id' => (int) $case['case_id'], 'case_number' => $case['case_number']];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * Soft-deletes a complaint and any linked formal case (A8).
     * Hard DELETE is intentionally prohibited to preserve records and auditability.
     */
    public function delete(mixed $id, ?int $deletedBy = null, string $reason = 'Operational soft deletion'): bool
    {
        $complaintId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$complaintId) {
            return false;
        }
        $cleanReason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : 'Operational soft deletion';
        $actorId = $deletedBy ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);

        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->conn->beginTransaction();
            }

            $beforeStmt = $this->conn->prepare('SELECT complaint_id, status, deleted_at, deleted_by, deletion_reason FROM complaints WHERE complaint_id = ? FOR UPDATE');
            $beforeStmt->execute([$complaintId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before || $before['deleted_at'] !== null) {
                if ($ownsTransaction && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return false;
            }

            $now = date('Y-m-d H:i:s');
            $stmt = $this->conn->prepare(
                'UPDATE complaints SET deleted_at = ?, deleted_by = ?, deletion_reason = ? WHERE complaint_id = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$now, $actorId, $cleanReason, $complaintId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Unable to soft-delete complaint.');
            }

            $this->conn->prepare(
                'UPDATE cases SET deleted_at = ?, deleted_by = ?, deletion_reason = ? WHERE complaint_id = ? AND deleted_at IS NULL'
            )->execute([$now, $actorId, $cleanReason, $complaintId]);

            (new AuditService($this->conn))->logStrict(
                $actorId,
                'Soft Deleted Complaint',
                'Complaints',
                (int) $complaintId,
                [
                    'before' => $before,
                    'after' => [
                        'complaint_id' => (int) $complaintId,
                        'status' => $before['status'],
                        'deleted_at' => $now,
                        'deleted_by' => $actorId,
                        'deletion_reason' => $cleanReason,
                    ],
                ]
            );

            if ($ownsTransaction) {
                $this->conn->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Complaint soft delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Restores a soft-deleted complaint and any linked soft-deleted case (A8 Super-Admin workflow).
     */
    public function restore(int $complaintId, int $restoredBy, string $reason = 'Restored by Super Administrator'): bool
    {
        if ($complaintId <= 0 || $restoredBy <= 0) {
            return false;
        }
        $cleanReason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : 'Restored by Super Administrator';
        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->conn->beginTransaction();
            }
            $beforeStmt = $this->conn->prepare('SELECT complaint_id, status, deleted_at, deleted_by, deletion_reason FROM complaints WHERE complaint_id = ? FOR UPDATE');
            $beforeStmt->execute([$complaintId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before || $before['deleted_at'] === null) {
                if ($ownsTransaction && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return false;
            }

            $this->conn->prepare('UPDATE complaints SET deleted_at = NULL, deleted_by = NULL, deletion_reason = NULL WHERE complaint_id = ?')
                ->execute([$complaintId]);
            $this->conn->prepare('UPDATE cases SET deleted_at = NULL, deleted_by = NULL, deletion_reason = NULL WHERE complaint_id = ?')
                ->execute([$complaintId]);

            (new AuditService($this->conn))->logStrict(
                $restoredBy,
                'Restored Soft-Deleted Complaint',
                'Complaints',
                $complaintId,
                [
                    'before' => $before,
                    'after' => [
                        'complaint_id' => $complaintId,
                        'deleted_at' => null,
                        'restoration_reason' => $cleanReason,
                    ],
                ]
            );

            if ($ownsTransaction) {
                $this->conn->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Complaint restore error: ' . $e->getMessage());
            return false;
        }
    }

    public function getDeleted(): array
    {
        $stmt = $this->conn->query(
            "SELECT c.complaint_id, c.intake_reference, c.complaint_number, c.complaint_title,
                    c.status, c.deleted_at, c.deleted_by, c.deletion_reason,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS deleted_by_name
             FROM complaints c
             LEFT JOIN users u ON u.user_id = c.deleted_by
             WHERE c.deleted_at IS NOT NULL
             ORDER BY c.deleted_at DESC"
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Records a human override of automated queue/routing assignment (A1).
     */
    public function overrideRouting(int $complaintId, string $newQueue, string $reason, int $actorId, ?string $newRoute = null): array
    {
        $cleanReason = trim($reason);
        if ($complaintId <= 0 || $actorId <= 0 || $cleanReason === '') {
            return ['success' => false, 'message' => 'A valid complaint ID and override reason are required.'];
        }
        if (!array_key_exists($newQueue, ComplaintRoutingService::APPROVED_QUEUES)) {
            return ['success' => false, 'message' => 'Select an approved Barangay Tumana receiving queue.'];
        }

        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->conn->beginTransaction();
            }
            $stmt = $this->conn->prepare('SELECT complaint_id, routing_route, assigned_queue, routing_rule_version FROM complaints WHERE complaint_id = ? AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([$complaintId]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                if ($ownsTransaction && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'Complaint not found.'];
            }

            $effectiveRoute = $newRoute !== null && trim($newRoute) !== '' ? trim($newRoute) : ($before['routing_route'] ?: 'KP_ELIGIBLE');
            $now = date('Y-m-d H:i:s');
            $this->conn->prepare(
                'UPDATE complaints
                 SET assigned_queue = ?, routing_route = ?, routing_overridden_by = ?, routing_overridden_at = ?, routing_override_reason = ?
                 WHERE complaint_id = ?'
            )->execute([$newQueue, $effectiveRoute, $actorId, $now, mb_substr($cleanReason, 0, 500), $complaintId]);

            $this->conn->prepare(
                "INSERT INTO complaint_routing_logs
                 (complaint_id, event_type, rule_version, routing_route, assigned_queue, previous_queue, override_reason, actor_user_id)
                 VALUES (?, 'Override', ?, ?, ?, ?, ?, ?)"
            )->execute([
                $complaintId,
                $before['routing_rule_version'] ?: ComplaintRoutingService::RULE_VERSION,
                $effectiveRoute,
                $newQueue,
                $before['assigned_queue'],
                mb_substr($cleanReason, 0, 500),
                $actorId,
            ]);

            (new AuditService($this->conn))->logStrict(
                $actorId,
                'Overrode Complaint Routing Queue',
                'Complaints',
                $complaintId,
                [
                    'before' => $before,
                    'after' => [
                        'assigned_queue' => $newQueue,
                        'routing_route' => $effectiveRoute,
                        'override_reason' => $cleanReason,
                    ],
                ]
            );

            if ($ownsTransaction) {
                $this->conn->commit();
            }
            return [
                'success' => true,
                'message' => 'Routing queue updated and override logged.',
                'assigned_queue' => $newQueue,
                'routing_route' => $effectiveRoute,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Routing override error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to override routing queue.'];
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
        if ($title === '') {
            $title = $isOtherCategory ? $otherSpec : (string) $catRow['category_name'];
            if ($secCatId) {
                $secStmt = $this->conn->prepare('SELECT category_name FROM complaint_categories WHERE category_id = ? LIMIT 1');
                $secStmt->execute([$secCatId]);
                $secRowName = (string) ($secStmt->fetchColumn() ?: '');
                $secLabel = (stripos($secRowName, 'Others') !== false || stripos($secRowName, 'Iba pa') !== false)
                    ? trim((string) ($data['secondary_other_category_specification'] ?? ''))
                    : $secRowName;
                if ($secLabel !== '') {
                    $title .= ' & ' . $secLabel;
                }
            }
            $title = mb_substr(trim($title), 0, 255);
            $data['complaint_title'] = $title;
        }
        if ($title === '') return ['success' => false, 'message' => 'Complaint title is required.'];
        if (mb_strlen($title) > 255) return ['success' => false, 'message' => 'Complaint title must be 255 characters or fewer.'];
        if (!ValidationService::text($title)) return ['success' => false, 'message' => 'Please remove unsupported characters from the complaint title.'];
        if ($date === '') return ['success' => false, 'message' => 'Incident date is required.'];
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) return ['success' => false, 'message' => 'Incident date is invalid.'];
        if ($time !== '' && !preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $time)) return ['success' => false, 'message' => 'Incident time is invalid.'];

        $today = new DateTimeImmutable('today');
        if ($parsedDate > $today) {
            return ['success' => false, 'message' => 'Incident date and time cannot be in the future.'];
        }
        if ($time !== '') {
            $parsedDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
            if ($parsedDateTime && $parsedDateTime > new DateTimeImmutable('now')) {
                return ['success' => false, 'message' => 'Incident date and time cannot be in the future.'];
            }
        }
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
        foreach (['incident_city' => 100, 'incident_barangay' => 100, 'incident_street' => 255, 'incident_purok' => 100, 'incident_landmark' => 255, 'additional_details' => 5000] as $field => $maxLength) {
            if (mb_strlen(trim((string) ($data[$field] ?? ''))) > $maxLength) {
                return ['success' => false, 'message' => ucwords(str_replace('_', ' ', $field)) . " must be {$maxLength} characters or fewer."];
            }
            if (!ValidationService::text(trim((string) ($data[$field] ?? '')), $field === 'additional_details')) {
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
        $resId = filter_var($data[$prefix . '_resident_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($resId && $resId > 0) {
            $stmt = $this->conn->prepare('SELECT resident_id FROM residents WHERE resident_id = ?');
            $stmt->execute([$resId]);
            if ($stmt->fetchColumn()) {
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
                    $updParams[] = $this->crypto->encrypt($addressVal);
                    $updFields[] = "house_street = ?";
                    $updParams[] = $this->crypto->encrypt($addressVal);

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
                    $updParams[] = $this->crypto->encrypt($contactVal);
                    $updFields[] = "contact_no_hash = ?";
                    $updParams[] = $this->crypto->contactBlindIndex($contactVal);
                    $updFields[] = "contact_last4_hash = ?";
                    $updParams[] = $this->crypto->contactLast4BlindIndex($contactVal);
                }

                $emailVal = trim((string)($data[$prefix . '_email'] ?? ''));
                if ($emailVal !== '') {
                    $updFields[] = "email = ?";
                    $updParams[] = $this->crypto->encrypt($emailVal);
                    $updFields[] = "email_hash = ?";
                    $updParams[] = $this->crypto->emailBlindIndex($emailVal);
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

        $residencyVerified = isset($data[$prefix . '_residency_verified']) ? (!empty($data[$prefix . '_residency_verified']) ? 1 : 0) : 1;

        // Check if an existing profile matches this name
        $existingId = ResidentNameLookup::find($this->conn, $firstName, $lastName) ?? 0;
        if ($existingId) {
            $existingId = (int)$existingId;
            $updFields = [];
            $updParams = [];
            if ($address !== null && $address !== '') {
                $updFields[] = "address = ?";
                $updParams[] = $this->crypto->encrypt($address);
                $updFields[] = "house_street = ?";
                $updParams[] = $this->crypto->encrypt($address);
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
                $updParams[] = $this->crypto->encrypt($contactNo);
                $updFields[] = "contact_no_hash = ?";
                $updParams[] = $this->crypto->contactBlindIndex($contactNo);
                $updFields[] = "contact_last4_hash = ?";
                $updParams[] = $this->crypto->contactLast4BlindIndex($contactNo);
            }
            if ($email !== null && $email !== '') {
                $updFields[] = "email = ?";
                $updParams[] = $this->crypto->encrypt($email);
                $updFields[] = "email_hash = ?";
                $updParams[] = $this->crypto->emailBlindIndex($email);
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
                contact_no, contact_no_hash, contact_last4_hash, email, email_hash, preferred_contact,
                address, home_latitude, home_longitude, house_street, barangay, city_municipality,
                purok, is_tenant, residency_verified
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            $this->crypto->encrypt($contactNo),
            $this->crypto->contactBlindIndex($contactNo),
            $this->crypto->contactLast4BlindIndex($contactNo),
            $this->crypto->encrypt($email),
            $this->crypto->emailBlindIndex($email),
            $preferredContact,
            $this->crypto->encrypt($address),
            $homeLat,
            $homeLng,
            $this->crypto->encrypt($address),
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
                $this->crypto->encrypt(trim((string) ($data['complainant_guardian_name'] ?? '')) ?: null),
                trim((string) ($data['complainant_guardian_relationship'] ?? '')) ?: null,
                $this->crypto->encrypt(trim((string) ($data['complainant_guardian_contact'] ?? '')) ?: null),
                $this->crypto->encrypt(trim((string) ($data['complainant_guardian_address'] ?? '')) ?: null),
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
                $this->crypto->encrypt(trim((string) ($data['respondent_guardian_name'] ?? '')) ?: null),
                trim((string) ($data['respondent_guardian_relationship'] ?? '')) ?: null,
                $this->crypto->encrypt(trim((string) ($data['respondent_guardian_contact'] ?? '')) ?: null),
                $this->crypto->encrypt(trim((string) ($data['respondent_guardian_address'] ?? '')) ?: null),
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
                        $pIsTenantRaw = $data['party_is_tenant'][$index] ?? null;
                        $pIsTenant = ($pIsTenantRaw !== null && $pIsTenantRaw !== '' && in_array((int)$pIsTenantRaw, [0, 1, 2], true))
                            ? (int)$pIsTenantRaw
                            : (strcasecmp($pPurok, 'Non-Resident') === 0 ? 2 : 0);
                        if ($pIsTenant === 2 && $pPurok === '') {
                            $pPurok = 'Non-Resident';
                        }

                        $pLat = (isset($data['party_latitudes'][$index]) && $data['party_latitudes'][$index] !== '') ? (float)$data['party_latitudes'][$index] : null;
                        $pLng = (isset($data['party_longitudes'][$index]) && $data['party_longitudes'][$index] !== '') ? (float)$data['party_longitudes'][$index] : null;

                        $foundId = ResidentNameLookup::find($this->conn, $pFirst, $pLast) ?? 0;
                        if ($foundId) {
                            $pId = (int)$foundId;
                        } else {
                            $pIsNonTumana = ($pIsTenant === 2 || strcasecmp($pPurok, 'Non-Resident') === 0 || ($pAddress !== '' && stripos($pAddress, 'tumana') === false));
                            $pBarangay = $pIsNonTumana ? 'Outside Tumana' : 'Tumana';
                            $ins = $this->conn->prepare('INSERT INTO residents (first_name, middle_name, last_name, suffix, contact_no, contact_no_hash, contact_last4_hash, address, home_latitude, home_longitude, house_street, purok, barangay, city_municipality, is_tenant, residency_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
                            $ins->execute([
                                $pFirst,
                                $pMiddle ?: null,
                                $pLast,
                                $pSuffix ?: null,
                                $this->crypto->encrypt($pContact ?: null),
                                $this->crypto->contactBlindIndex($pContact ?: null),
                                $this->crypto->contactLast4BlindIndex($pContact ?: null),
                                $this->crypto->encrypt($pAddress ?: null),
                                $pLat,
                                $pLng,
                                $this->crypto->encrypt($pAddress ?: null),
                                $pPurok ?: null,
                                $pBarangay,
                                'Marikina City',
                                $pIsTenant
                            ]);
                            $pId = (int)$this->conn->lastInsertId();
                        }
                    }
                }

                if ($pId && $pId > 0) {
                    $pAddressVal = trim((string)($data['party_addresses'][$index] ?? ''));
                    $pPurokVal   = trim((string)($data['party_puroks'][$index] ?? ''));
                    $pContactVal = trim((string)($data['party_contacts'][$index] ?? ''));
                    $pTenantPostRaw = $data['party_is_tenant'][$index] ?? null;
                    $pTenantVal = ($pTenantPostRaw !== null && $pTenantPostRaw !== '' && in_array((int)$pTenantPostRaw, [0, 1, 2], true))
                        ? (int)$pTenantPostRaw
                        : (strcasecmp($pPurokVal, 'Non-Resident') === 0 ? 2 : null);
                    $pLatVal     = (isset($data['party_latitudes'][$index]) && $data['party_latitudes'][$index] !== '') ? (float)$data['party_latitudes'][$index] : null;
                    $pLngVal     = (isset($data['party_longitudes'][$index]) && $data['party_longitudes'][$index] !== '') ? (float)$data['party_longitudes'][$index] : null;
                    $pUpd = [];
                    $pParams = [];
                    if ($pAddressVal !== '') {
                        $pUpd[] = "address = ?";
                        $pParams[] = $this->crypto->encrypt($pAddressVal);
                        $pUpd[] = "house_street = ?";
                        $pParams[] = $this->crypto->encrypt($pAddressVal);
                        $isNonTum = ($pTenantVal === 2 || strcasecmp($pPurokVal, 'Non-Resident') === 0 || (stripos($pAddressVal, 'tumana') === false && (stripos($pAddressVal, 'marikina') !== false || stripos($pAddressVal, 'quezon') !== false || stripos($pAddressVal, 'pasig') !== false || stripos($pAddressVal, 'cainta') !== false || stripos($pAddressVal, 'san mateo') !== false)));
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
                    if ($pTenantVal !== null) {
                        $pUpd[] = "is_tenant = ?";
                        $pParams[] = $pTenantVal;
                    }
                    if ($pContactVal !== '') {
                        $pUpd[] = "contact_no = ?";
                        $pParams[] = $this->crypto->encrypt($pContactVal);
                        $pUpd[] = "contact_no_hash = ?";
                        $pParams[] = $this->crypto->contactBlindIndex($pContactVal);
                        $pUpd[] = "contact_last4_hash = ?";
                        $pParams[] = $this->crypto->contactLast4BlindIndex($pContactVal);
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
    public function validateJurisdictionInput(array $data): array
    {
        require_once __DIR__ . '/../services/MediationDeadlineService.php';

        if (empty($data['jurisdiction_screened'])) {
            return [
                'success' => false,
                'message' => 'Complete the preliminary jurisdiction and eligibility screen before submitting the complaint.'
            ];
        }

        $effectiveCaseType = ComplaintClassificationService::resolveEffectiveCaseType(
            $this->conn,
            !empty($data['category_id']) ? (int) $data['category_id'] : null,
            !empty($data['secondary_category_id']) ? (int) $data['secondary_category_id'] : null,
            in_array(trim((string) ($data['case_type'] ?? '')), ['Civil', 'Criminal'], true) ? trim((string) $data['case_type']) : null
        );
        $isCriminalCase = ($effectiveCaseType === 'Criminal');

        $disputeContext = [];

        // 1. Explicit statutory exclusion flags passed from UI / API
        $disputeContext['party_is_government']        = !empty($data['party_is_government']);
        $disputeContext['party_is_public_officer']    = !empty($data['party_is_public_officer']) || !empty($data['involves_public_officer_official_capacity']);
        // Criminal jurisdiction warning/exclusion checks removed across intake & screening forms
        $disputeContext['offense_imprisonment_max']   = 0.0;
        $disputeContext['offense_fine_max']           = (float) ($data['offense_fine_max'] ?? 0);
        $disputeContext['has_private_offended_party'] = true;
        $disputeContext['property_in_diff_lgu']       = !empty($data['property_in_diff_lgu']);
        $disputeContext['parties_in_diff_lgu']        = !empty($data['parties_in_diff_lgu']);
        $disputeContext['parties_same_barangay']      = !empty($data['parties_same_barangay']) || empty($data['parties_in_diff_lgu']);
        $disputeContext['accused_under_detention']    = false;
        $disputeContext['habeas_corpus_needed']       = false;
        $disputeContext['provisional_remedy_needed']  = !empty($data['provisional_remedy_needed']);
        $disputeContext['prescription_risk']          = false;
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

        // 2b. Safe Spaces Act (RA 11313) Catcalling/Whistling mandatory 1st Offense verification
        $catIdCheck = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
        if ($catIdCheck) {
            $cStmt = $this->conn->prepare('SELECT category_name FROM complaint_categories WHERE category_id = ?');
            $cStmt->execute([$catIdCheck]);
            $catNameStr = strtolower((string)($cStmt->fetchColumn() ?: ''));
            if (str_contains($catNameStr, 'safe spaces') || str_contains($catNameStr, 'catcalling') || str_contains($catNameStr, '11313')) {
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
            }
        }

        // 3. Real property check: category 6 (Property and Rental Disputes)
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
        if ($categoryId === 6) {
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
            } else {
            return [
                'success'           => false,
                'code'              => 'KP_JURISDICTION_EXCLUDED',
                'message'           => 'This dispute cannot be accepted under Katarungang Pambarangay: ' . implode(' ', $eval['exclusion_grounds']),
                'exclusion_grounds' => $eval['exclusion_grounds'],
                'evaluation'        => $eval,
            ];
            }
        }

        return [
            'success'    => true,
            'evaluation' => $eval,
        ];
    }

    private function saveIntakeScreening(int $complaintId, array $data): void
    {
        $bool = static fn (string $field): int => !empty($data[$field]) ? 1 : 0;
        $isCriminalCase = (($data['case_type'] ?? 'Civil') === 'Criminal');
        $privateOffended = ($isCriminalCase && array_key_exists('has_private_offended_party', $data))
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

        $stmt = $this->conn->prepare(
            'INSERT INTO complaint_intake_screenings (
                complaint_id, party_is_government, party_is_public_officer,
                parties_in_diff_lgu, property_in_diff_lgu, accused_under_detention,
                habeas_corpus_needed, provisional_remedy_needed, prescription_risk,
                indigenous_community, offense_imprisonment_max, offense_fine_max,
                has_private_offended_party, duplicate_acknowledged, screening_notes, screened_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                party_is_government = VALUES(party_is_government),
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
                screened_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $complaintId,
            $bool('party_is_government'),
            $bool('party_is_public_officer'),
            $bool('parties_in_diff_lgu'),
            $bool('property_in_diff_lgu'),
            $isCriminalCase ? $bool('accused_under_detention') : 0,
            $isCriminalCase ? $bool('habeas_corpus_needed') : 0,
            $bool('provisional_remedy_needed'),
            $isCriminalCase ? $bool('prescription_risk') : 0,
            $bool('indigenous_community'),
            $isCriminalCase ? max(0, (float) ($data['offense_imprisonment_max'] ?? 0)) : 0.0,
            max(0, (float) ($data['offense_fine_max'] ?? 0)),
            $privateOffended,
            $bool('duplicate_acknowledged'),
            $notes,
            $_SESSION['user_id'] ?? null,
        ]);
    }

    private function findPotentialDuplicates(array $data, ?int $excludeComplaintId = null): array
    {
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

        $sql = "SELECT c.complaint_id, c.complaint_number, c.complaint_title, c.status, c.incident_date
                FROM complaints c
                INNER JOIN complaint_parties complainant ON complainant.complaint_id = c.complaint_id
                    AND complainant.resident_id = ? AND complainant.party_type = 'Complainant'
                INNER JOIN complaint_parties respondent ON respondent.complaint_id = c.complaint_id
                    AND respondent.resident_id = ? AND respondent.party_type = 'Respondent'
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

    private function findResidentIdByName(string $firstName, string $lastName): ?int
    {
        if ($firstName === '' || $lastName === '') {
            return null;
        }
        $id = ResidentNameLookup::find($this->conn, $firstName, $lastName);
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
        $existingId = ResidentNameLookup::find($this->conn, null, null, $name);

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
