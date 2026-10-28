<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ValidationService.php';
require_once __DIR__ . '/../services/ComplaintLifecycleService.php';

class Complaint
{
    private PDO $conn;

    public function __construct()
    {
        $database = new Database();

        $this->conn =
            $database->connect();
    }

    public function getAll()
    {
        try {

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
                LEFT JOIN cases cs
                    ON cs.complaint_id = c.complaint_id
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
                SELECT c.*, cs.case_id, cs.case_number, cs.case_status, cat.category_name
                FROM complaints c
                LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
                LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
                WHERE c.complaint_id=?
            ");

            $stmt->execute([$id]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
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

            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }

            $caseType = in_array(trim((string)($data['case_type'] ?? '')), ['Civil', 'Criminal'], true)
                ? trim((string)$data['case_type'])
                : 'Civil';

            $this->conn->beginTransaction();
            $stmt =
            $this->conn->prepare("
                INSERT INTO complaints
                (
                    category_id,
                    other_category_specification,
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
                    status,
                    document_status,
                    is_draft,
                    encoded_by
                )
                VALUES
                (
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
                )
            ");

            $timeVal = !empty($data['is_time_unknown']) ? null : (trim((string) ($data['incident_time'] ?? '')) ?: null);
            $stmt->execute([
                $data['category_id'],
                trim((string) ($data['other_category_specification'] ?? '')) ?: null,
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
                'Pre-Docketed (Paanyaya)',
                'No Scan Uploaded',
                0,
                $_SESSION['user_id'] ?? $data['clerk_id'] ?? $data['encoded_by'] ?? 1
            ]);

            $complaintId = (int) $this->conn->lastInsertId();
            $complaintNumber = self::generatePaanyayaCaseNumber($complaintId);
            $numberStatement = $this->conn->prepare(
                'UPDATE complaints SET complaint_number = ?, paanyaya_attempt_count = 1, max_paanyaya_reached = 0, resolved_without_docketing = 0 WHERE complaint_id = ?'
            );
            $numberStatement->execute([$complaintNumber, $complaintId]);
            $this->applyMapLocation($complaintId, $data);
            $this->applyParties($complaintId, $data);
            $this->saveIntakeScreening($complaintId, $data);
            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $notifService = new NotificationService();
                $cNum = $this->conn->query("SELECT complaint_number FROM complaints WHERE complaint_id = {$complaintId}")->fetchColumn() ?: "CMP-#{$complaintId}";
                $actionUrl = "../complaints/complaint-details.php?id=" . (int) $complaintId;
                $notifService->notifyRoles(
                    ['Administrator', 'Lupon Clerk'],
                    'New Complaint Filed',
                    "New complaint {$cNum} (" . ($data['complaint_title'] ?? 'General Dispute') . ") has been filed and initiated into Pre-Docketing (Paanyaya).",
                    $_SESSION['user_id'] ?? null,
                    'info',
                    $actionUrl
                );
            } catch (Throwable $ne) {
                error_log('Failed to dispatch new complaint notification: ' . $ne->getMessage());
            }

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Complaint submitted and placed into Pre-Docketing (Paanyaya) stage.',
                'complaint_id' => $complaintId,
                'complaint_number' => $complaintNumber,
                'is_draft' => false
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

            $caseType = in_array(trim((string)($data['case_type'] ?? '')), ['Civil', 'Criminal'], true)
                ? trim((string)$data['case_type'])
                : 'Civil';

            $this->conn->beginTransaction();
            $stmt =
            $this->conn->prepare("
                UPDATE complaints
                SET
                    category_id=?,
                    other_category_specification=?,
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
            $stmt->execute([
                $data['category_id'],
                trim((string) ($data['other_category_specification'] ?? '')) ?: null,
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
                    $actionUrl = "../complaints/complaint-details.php?id=" . (int) $complaintId;
                    $notifService->notifyRoles(
                        ['Administrator', 'Lupon Clerk'],
                        'New Complaint Filed',
                        "New complaint {$cNum} (" . ($data['complaint_title'] ?? 'General Dispute') . ") has been filed and initiated into Pre-Docketing (Paanyaya).",
                        $_SESSION['user_id'] ?? null,
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

    public function review(int $id, string $status, ?string $notes): array
    {
        $allowed = ['Under Review', 'Needs Information', 'Accepted', 'Rejected'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'Select a valid review decision.'];
        }
        $exists = $this->conn->prepare("SELECT complaint_id FROM complaints WHERE complaint_id = ? AND status NOT IN ('Docketed', 'Archived')");
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) return ['success' => false, 'message' => 'This complaint cannot be reviewed in its current status.'];
        $stmt = $this->conn->prepare('UPDATE complaints SET status = ?, review_notes = ? WHERE complaint_id = ?');
        $stmt->execute([$status, $notes, $id]);
        return ['success' => true, 'message' => 'Complaint review saved.'];
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

    private function validateIncidentInput(array $data): array
    {
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $title = trim((string) ($data['complaint_title'] ?? $data['title'] ?? ''));
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

        $residencyVerified = isset($data[$prefix . '_residency_verified']) ? (!empty($data[$prefix . '_residency_verified']) ? 1 : 0) : 1;

        // Check if an existing profile matches this name
        $findStmt = $this->conn->prepare('SELECT resident_id FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1');
        $findStmt->execute([$firstName, $lastName]);
        $existingId = $findStmt->fetchColumn();
        if ($existingId) {
            $existingId = (int)$existingId;
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

        $disputeContext = [];

        // 1. Explicit statutory exclusion flags passed from UI / API
        $disputeContext['party_is_government']        = !empty($data['party_is_government']);
        $disputeContext['party_is_public_officer']    = !empty($data['party_is_public_officer']) || !empty($data['involves_public_officer_official_capacity']);
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
            return [
                'success'           => false,
                'code'              => 'KP_JURISDICTION_EXCLUDED',
                'message'           => 'This dispute cannot be accepted under Katarungang Pambarangay: ' . implode(' ', $eval['exclusion_grounds']),
                'exclusion_grounds' => $eval['exclusion_grounds'],
                'evaluation'        => $eval,
            ];
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
