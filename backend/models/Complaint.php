<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ValidationService.php';

class Complaint
{
    private $conn;

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
                    cc.category_name
                FROM complaints c
                LEFT JOIN complaint_categories cc
                    ON c.category_id = cc.category_id
                ORDER BY created_at DESC
            ");

            $stmt->execute();

            return $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return [];
        }
    }

    public function getById($id)
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

            return $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }

    public function create($data): array
    {
        try {
            if (!empty($data['incident_datetime'])) {
                $rawDt = trim((string) $data['incident_datetime']);
                $dtObj = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $rawDt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDt);
                if ($dtObj) {
                    $data['incident_date'] = $dtObj->format('Y-m-d');
                    $data['incident_time'] = $dtObj->format('H:i');
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

            if(session_status() === PHP_SESSION_NONE)
            {
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
                    case_type,
                    complaint_title,
                    incident_date,
                    incident_time,
                    incident_location,
                    incident_landmark,
                    narrative,
                    additional_details,
                    status,
                    encoded_by
                )
                VALUES
                (
                    ?,?,?,?,?,?,?,?,?,?,?
                )
            ");

            $stmt->execute([
                $data['category_id'],
                $caseType,
                trim((string) $data['complaint_title']),
                $data['incident_date'],
                trim((string) ($data['incident_time'] ?? '')) ?: null,
                trim((string) ($data['incident_location'] ?? '')) ?: null,
                trim((string) ($data['incident_landmark'] ?? '')) ?: null,
                trim((string) $data['narrative']),
                trim((string) ($data['additional_details'] ?? '')) ?: null,
                'Under Review',
                $_SESSION['user_id']
            ]);

            $complaintId = (int) $this->conn->lastInsertId();
            $complaintNumber = sprintf('CMP-%s-%05d', date('Y'), $complaintId);
            $numberStatement = $this->conn->prepare(
                'UPDATE complaints SET complaint_number = ? WHERE complaint_id = ?'
            );
            $numberStatement->execute([$complaintNumber, $complaintId]);
            $this->applyMapLocation($complaintId, $data);
            $this->applyParties($complaintId, $data);

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Complaint submitted and placed under review.',
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

            return ['success' => false, 'message' => 'Unable to create the complaint. Please try again.'];
        }
    }

    public function update($id, $data): array
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
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDt);
                if ($dtObj) {
                    $data['incident_date'] = $dtObj->format('Y-m-d');
                    $data['incident_time'] = $dtObj->format('H:i');
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
                    case_type=?,
                    complaint_title=?,
                    incident_date=?,
                    incident_time=?,
                    incident_location=?,
                    incident_landmark=?,
                    narrative=?,
                    additional_details=?
                WHERE complaint_id=?
            ");

            $stmt->execute([
                $data['category_id'],
                $caseType,
                trim((string) $data['complaint_title']),
                $data['incident_date'],
                trim((string) ($data['incident_time'] ?? '')) ?: null,
                trim((string) ($data['incident_location'] ?? '')) ?: null,
                trim((string) ($data['incident_landmark'] ?? '')) ?: null,
                trim((string) $data['narrative']),
                trim((string) ($data['additional_details'] ?? '')) ?: null,
                $complaintId
            ]);

            $updateCase = $this->conn->prepare("
                UPDATE cases
                SET case_type = ?
                WHERE complaint_id = ? AND case_status IN ('Docketed', 'Mediation')
            ");
            $updateCase->execute([$caseType, $complaintId]);

            $this->applyMapLocation($complaintId, $data);

            if (isset($data['complainant_name']) || isset($data['respondent_name'])) {
                $delParties = $this->conn->prepare('DELETE FROM complaint_parties WHERE complaint_id = ?');
                $delParties->execute([$complaintId]);
                $this->applyParties($complaintId, $data);
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

    public function delete($id)
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

    private function validateIncidentInput(array $data): array
    {
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $title = trim((string) ($data['complaint_title'] ?? ''));
        $date = trim((string) ($data['incident_date'] ?? ''));
        $time = trim((string) ($data['incident_time'] ?? ''));
        $narrative = trim((string) ($data['narrative'] ?? ''));

        if ($date === '' && !empty($data['incident_datetime'])) {
            $rawDt = trim((string) $data['incident_datetime']);
            $dtObj = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDt);
            if ($dtObj) {
                $date = $dtObj->format('Y-m-d');
                $time = $dtObj->format('H:i');
            }
        }

        if (!$categoryId) return ['success' => false, 'message' => 'Select a valid complaint category.'];
        $category = $this->conn->prepare('SELECT category_id FROM complaint_categories WHERE category_id = ?');
        $category->execute([$categoryId]);
        if (!$category->fetchColumn()) return ['success' => false, 'message' => 'Select a valid complaint category.'];
        if ($title === '') return ['success' => false, 'message' => 'Complaint title is required.'];
        if (mb_strlen($title) > 255) return ['success' => false, 'message' => 'Complaint title must be 255 characters or fewer.'];
        if (!ValidationService::text($title)) return ['success' => false, 'message' => 'Please remove unsupported characters from the complaint title.'];
        if ($date === '') return ['success' => false, 'message' => 'Incident date is required.'];
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) return ['success' => false, 'message' => 'Incident date is invalid.'];
        if ($time !== '' && !preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $time)) return ['success' => false, 'message' => 'Incident time is invalid.'];
        $location = trim((string) ($data['incident_location'] ?? ''));
        if ($location === '') return ['success' => false, 'message' => 'Specific incident location is required.'];
        if (!ValidationService::address($location)) return ['success' => false, 'message' => 'Please remove unsupported control characters from the incident location.'];
        if ($narrative === '') return ['success' => false, 'message' => 'Incident narrative is required.'];
        if (mb_strlen($narrative) > 15000) return ['success' => false, 'message' => 'Narrative is too long. Use 15,000 characters or fewer.'];
        if (!ValidationService::text($narrative, true)) return ['success' => false, 'message' => 'Please remove unsupported control characters from the incident narrative.'];
        foreach (['incident_location' => 255, 'incident_landmark' => 255, 'additional_details' => 5000] as $field => $maxLength) {
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
        } elseif ($latitude !== '' || $longitude !== '') {
            return ['success' => false, 'message' => 'Select a map point or clear the map location.'];
        }

        return ['success' => true];
    }

    private function applyMapLocation(int $complaintId, array $data): void
    {
        $state = trim((string) ($data['map_location_state'] ?? 'none'));
        if ($state === 'unchanged') {
            $address = trim((string) $data['incident_location']);
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
        $address = trim((string) $data['incident_location']);
        $stmt = $this->conn->prepare(
            'INSERT INTO incident_locations (complaint_id, latitude, longitude, address) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), address = VALUES(address)'
        );
        $stmt->execute([$complaintId, $latitude, $longitude, $address]);
    }

    private function applyParties(int $complaintId, array $data): void
    {
        $partyStmt = $this->conn->prepare("
            INSERT IGNORE INTO complaint_parties (complaint_id, resident_id, party_type)
            VALUES (?, ?, ?)
        ");

        // 1. Complainant
        $compName = trim((string)($data['complainant_name'] ?? ''));
        $compId = filter_var($data['complainant_resident_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($compName !== '' || $compId) {
            $resId = $this->resolveResidentId($compName, $compId);
            if ($resId) {
                $partyStmt->execute([$complaintId, $resId, 'Complainant']);
            }
        }

        // 2. Respondent (Person being complained against)
        $respName = trim((string)($data['respondent_name'] ?? ''));
        $respId = filter_var($data['respondent_resident_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($respName !== '' || $respId) {
            $resId = $this->resolveResidentId($respName, $respId);
            if ($resId) {
                $partyStmt->execute([$complaintId, $resId, 'Respondent']);
            }
        }

        // 3. Additional parties
        if (!empty($data['party_names']) && is_array($data['party_names'])) {
            $types = $data['party_types'] ?? [];
            $ids = $data['party_resident_ids'] ?? [];
            foreach ($data['party_names'] as $index => $name) {
                $pName = trim((string)$name);
                $pType = trim((string)($types[$index] ?? 'Witness'));
                if (!in_array($pType, ['Complainant', 'Respondent', 'Witness'], true)) {
                    $pType = 'Witness';
                }
                $pId = filter_var($ids[$index] ?? null, FILTER_VALIDATE_INT) ?: null;
                if ($pName !== '' || $pId) {
                    $resId = $this->resolveResidentId($pName, $pId);
                    if ($resId) {
                        $partyStmt->execute([$complaintId, $resId, $pType]);
                    }
                }
            }
        }
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
}

