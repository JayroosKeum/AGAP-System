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
                    incident_city,
                    incident_barangay,
                    incident_street,
                    incident_purok,
                    incident_landmark,
                    narrative,
                    additional_details,
                    status,
                    encoded_by
                )
                VALUES
                (
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
                )
            ");

            $stmt->execute([
                $data['category_id'],
                $caseType,
                trim((string) $data['complaint_title']),
                $data['incident_date'],
                trim((string) ($data['incident_time'] ?? '')) ?: null,
                $this->buildIncidentAddress($data),
                trim((string) ($data['incident_city'] ?? 'Marikina City')),
                trim((string) ($data['incident_barangay'] ?? 'Tumana')),
                trim((string) ($data['incident_street'] ?? '')) ?: null,
                trim((string) ($data['incident_purok'] ?? '')) ?: null,
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
                    incident_city=?,
                    incident_barangay=?,
                    incident_street=?,
                    incident_purok=?,
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
                $this->buildIncidentAddress($data),
                trim((string) ($data['incident_city'] ?? 'Marikina City')),
                trim((string) ($data['incident_barangay'] ?? 'Tumana')),
                trim((string) ($data['incident_street'] ?? '')) ?: null,
                trim((string) ($data['incident_purok'] ?? '')) ?: null,
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
        $city = trim((string) ($data['incident_city'] ?? ''));
        $barangay = trim((string) ($data['incident_barangay'] ?? ''));
        $street = trim((string) ($data['incident_street'] ?? ''));
        if ($city !== 'Marikina City') return ['success' => false, 'message' => 'Incident city must be Marikina City.'];
        if ($barangay !== 'Tumana') return ['success' => false, 'message' => 'Incident barangay must be Tumana.'];
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
            if (!$this->isWithinTumana((float) $latitudeValue, (float) $longitudeValue)) {
                return ['success' => false, 'message' => 'Map pins must be located within Barangay Tumana.'];
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

