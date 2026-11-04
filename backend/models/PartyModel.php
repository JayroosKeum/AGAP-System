<?php

require_once __DIR__ . '/../config/database.php';

/**
 * PartyModel - Manages resident profiles and relational party mappings across complaints and cases.
 * Module 5: Multi-Complaint Handling & Resident Linkage
 */
class PartyModel
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        if ($conn) {
            $this->conn = $conn;
        } else {
            $database = new Database();
            $this->conn = $database->connect();
        }
    }

    /**
     * Decouples resident profiles from individual case records.
     * Finds an existing resident profile or creates one, maintaining a single
     * profile for individuals involved in concurrent disputes.
     */
    public function findOrCreateResident(array $data): int
    {
        // 1. If explicit resident_id is provided and valid, verify and optionally update
        if (!empty($data['resident_id'])) {
            $existingId = (int)$data['resident_id'];
            $checkStmt = $this->conn->prepare('SELECT resident_id FROM residents WHERE resident_id = ?');
            $checkStmt->execute([$existingId]);
            if ($checkStmt->fetchColumn()) {
                $this->updateResidentProfile($existingId, $data);
                return $existingId;
            }
        }

        // 2. Extract and sanitize name fields
        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $middleName = trim((string)($data['middle_name'] ?? '')) ?: null;
        $suffix = trim((string)($data['suffix'] ?? '')) ?: null;

        // If single full_name string was provided
        if (empty($firstName) && !empty($data['name'])) {
            $parts = preg_split('/\s+/', trim((string)$data['name']));
            if (count($parts) === 1) {
                $firstName = $parts[0];
                $lastName = '-';
            } elseif (count($parts) === 2) {
                $firstName = $parts[0];
                $lastName = $parts[1];
            } else {
                $firstName = $parts[0];
                $lastName = array_pop($parts);
                $middleName = implode(' ', array_slice($parts, 1));
            }
        }

        if (empty($firstName) && empty($lastName)) {
            throw new InvalidArgumentException('Resident first name or last name is required.');
        }

        // 3. Match against existing residents to avoid duplicate profiles
        // We match by full name, or by contact_no + name
        $contactNo = trim((string)($data['contact_no'] ?? '')) ?: null;

        $matchSql = "
            SELECT resident_id FROM residents 
            WHERE (
                (LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?))
                OR (TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) = ?)
                OR (TRIM(CONCAT_WS(' ', first_name, last_name)) = ?)
            )
        ";
        $params = [$firstName, $lastName, trim("$firstName $middleName $lastName"), trim("$firstName $lastName")];

        if (!empty($contactNo)) {
            $matchSql .= " OR (contact_no = ? AND LOWER(TRIM(last_name)) = LOWER(?))";
            $params[] = $contactNo;
            $params[] = $lastName;
        }

        $matchSql .= " ORDER BY resident_id ASC LIMIT 1";

        $matchStmt = $this->conn->prepare($matchSql);
        $matchStmt->execute($params);
        $matchedId = $matchStmt->fetchColumn();

        if ($matchedId) {
            $residentId = (int)$matchedId;
            $this->updateResidentProfile($residentId, $data);
            return $residentId;
        }

        // 4. Create new resident record
        $insertStmt = $this->conn->prepare("
            INSERT INTO residents (
                first_name, middle_name, last_name, suffix,
                birth_date, gender, civil_status, occupation,
                contact_no, email, preferred_contact,
                address, house_street, purok, barangay, city_municipality,
                home_latitude, home_longitude, is_tenant, residency_verified
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?
            )
        ");

        $insertStmt->execute([
            $firstName,
            $middleName,
            $lastName,
            $suffix,
            !empty($data['birth_date']) ? $data['birth_date'] : null,
            trim((string)($data['gender'] ?? '')) ?: null,
            trim((string)($data['civil_status'] ?? '')) ?: null,
            trim((string)($data['occupation'] ?? '')) ?: null,
            $contactNo,
            trim((string)($data['email'] ?? '')) ?: null,
            in_array($data['preferred_contact'] ?? '', ['SMS', 'Call', 'Email', 'In-Person'], true) ? $data['preferred_contact'] : 'SMS',
            trim((string)($data['address'] ?? '')) ?: null,
            trim((string)($data['house_street'] ?? '')) ?: null,
            trim((string)($data['purok'] ?? '')) ?: null,
            trim((string)($data['barangay'] ?? 'Tumana')) ?: 'Tumana',
            trim((string)($data['city_municipality'] ?? 'Marikina City')) ?: 'Marikina City',
            isset($data['home_latitude']) && $data['home_latitude'] !== '' ? (float)$data['home_latitude'] : null,
            isset($data['home_longitude']) && $data['home_longitude'] !== '' ? (float)$data['home_longitude'] : null,
            !empty($data['is_tenant']) ? 1 : 0,
            isset($data['residency_verified']) ? (!empty($data['residency_verified']) ? 1 : 0) : 1
        ]);

        return (int)$this->conn->lastInsertId();
    }

    /**
     * Updates an existing resident's profile without duplicating the record.
     */
    public function updateResidentProfile(int $residentId, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowedUpdates = [
            'contact_no', 'email', 'preferred_contact', 'address', 'house_street',
            'purok', 'barangay', 'city_municipality', 'birth_date', 'gender',
            'civil_status', 'occupation', 'home_latitude', 'home_longitude',
            'is_tenant', 'residency_verified', 'suffix'
        ];

        foreach ($allowedUpdates as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $fields[] = "`$field` = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $residentId;
        $sql = "UPDATE residents SET " . implode(', ', $fields) . ", updated_at = CURRENT_TIMESTAMP WHERE resident_id = ?";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Links a resident profile to a complaint as Complainant, Respondent, or Witness.
     */
    public function linkPartyToComplaint(int $complaintId, int $residentId, string $partyType, array $meta = []): int
    {
        if (!in_array($partyType, ['Complainant', 'Respondent', 'Witness'], true)) {
            throw new InvalidArgumentException("Invalid party type: $partyType");
        }

        $stmt = $this->conn->prepare("
            INSERT INTO complaint_parties (
                complaint_id, resident_id, party_type,
                is_minor, guardian_name, guardian_relationship, guardian_contact, guardian_address,
                home_latitude, home_longitude, preferred_contact, suffix, occupation,
                age, birth_date, gender, civil_status,
                contact_unknown, middle_name_unknown, birthdate_unknown, address_requires_verification, residency_verified
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?
            )
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

        $stmt->execute([
            $complaintId,
            $residentId,
            $partyType,
            !empty($meta['is_minor']) ? 1 : 0,
            trim((string)($meta['guardian_name'] ?? '')) ?: null,
            trim((string)($meta['guardian_relationship'] ?? '')) ?: null,
            trim((string)($meta['guardian_contact'] ?? '')) ?: null,
            trim((string)($meta['guardian_address'] ?? '')) ?: null,
            isset($meta['home_latitude']) && $meta['home_latitude'] !== '' ? (float)$meta['home_latitude'] : null,
            isset($meta['home_longitude']) && $meta['home_longitude'] !== '' ? (float)$meta['home_longitude'] : null,
            $meta['preferred_contact'] ?? 'SMS',
            trim((string)($meta['suffix'] ?? '')) ?: null,
            trim((string)($meta['occupation'] ?? '')) ?: null,
            isset($meta['age']) && $meta['age'] !== '' ? (int)$meta['age'] : null,
            !empty($meta['birth_date']) ? $meta['birth_date'] : null,
            trim((string)($meta['gender'] ?? '')) ?: null,
            trim((string)($meta['civil_status'] ?? '')) ?: null,
            !empty($meta['contact_unknown']) ? 1 : 0,
            !empty($meta['middle_name_unknown']) ? 1 : 0,
            !empty($meta['birthdate_unknown']) ? 1 : 0,
            !empty($meta['address_requires_verification']) ? 1 : 0,
            isset($meta['residency_verified']) ? (!empty($meta['residency_verified']) ? 1 : 0) : 1
        ]);

        $partyId = (int)$this->conn->lastInsertId();
        if ($partyId === 0) {
            $lookup = $this->conn->prepare('SELECT party_id FROM complaint_parties WHERE complaint_id = ? AND resident_id = ? AND party_type = ?');
            $lookup->execute([$complaintId, $residentId, $partyType]);
            $partyId = (int)$lookup->fetchColumn();
        }

        return $partyId;
    }

    /**
     * Retrieves all relational parties for a complaint with resident profiles joined.
     */
    public function getPartiesForComplaint(int $complaintId): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                cp.*,
                r.first_name,
                r.middle_name,
                r.last_name,
                r.suffix AS resident_suffix,
                r.contact_no,
                r.email,
                r.address,
                r.house_street,
                r.purok,
                r.barangay,
                r.city_municipality,
                r.is_tenant,
                TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name
        ");
        $stmt->execute([$complaintId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves active concurrent disputes for a given resident across all complaints and docketed cases.
     */
    public function getConcurrentDisputesForResident(int $residentId, ?int $excludeComplaintId = null): array
    {
        $sql = "
            SELECT 
                cp.party_type AS resident_role,
                c.complaint_id,
                c.complaint_number,
                c.complaint_title,
                c.status AS complaint_status,
                c.created_at AS complaint_date,
                cs.case_id,
                cs.case_number,
                cs.case_status,
                cs.case_type,
                cs.docket_date,
                cs.created_at AS case_created_at
            FROM complaint_parties cp
            INNER JOIN complaints c ON c.complaint_id = cp.complaint_id
            LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
            WHERE cp.resident_id = ?
              AND c.status NOT IN ('Archived', 'Dismissed', 'Settled', 'Resolved')
              AND c.status NOT LIKE 'Dismissed%'
              AND c.status NOT LIKE 'Settled%'
              AND (cs.case_status IS NULL OR (cs.case_status NOT IN ('Archived', 'Dismissed', 'Settled', 'DISMISSED_BARRED') AND cs.case_status NOT LIKE 'Dismissed%' AND cs.case_status NOT LIKE 'Settled%'))
        ";
        $params = [$residentId];

        if ($excludeComplaintId !== null) {
            $sql .= " AND c.complaint_id != ?";
            $params[] = $excludeComplaintId;
        }

        $sql .= " ORDER BY COALESCE(cs.created_at, c.created_at) DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Checks for concurrent disputes for all parties involved in a complaint.
     * Used by the Cross-Link Alert Banner.
     */
    public function checkConcurrentDisputesForComplaint(int $complaintId): array
    {
        $parties = $this->getPartiesForComplaint($complaintId);
        $alerts = [];
        $hasConcurrent = false;

        foreach ($parties as $party) {
            $residentId = (int)$party['resident_id'];
            $disputes = $this->getConcurrentDisputesForResident($residentId, $complaintId);

            if (!empty($disputes)) {
                $hasConcurrent = true;
                foreach ($disputes as $d) {
                    $activeDisplayNumber = !empty($d['case_number']) ? $d['case_number'] : ($d['complaint_number'] ?: ('#' . $d['complaint_id']));
                    $activeStatus = !empty($d['case_status']) ? $d['case_status'] : $d['complaint_status'];

                    $alerts[] = [
                        'resident_id' => $residentId,
                        'resident_name' => $party['full_name'],
                        'current_role' => $party['party_type'],
                        'concurrent_complaint_id' => (int)$d['complaint_id'],
                        'concurrent_case_id' => !empty($d['case_id']) ? (int)$d['case_id'] : null,
                        'concurrent_display_number' => $activeDisplayNumber,
                        'concurrent_case_number' => $d['case_number'] ?? null,
                        'concurrent_role' => $d['resident_role'],
                        'concurrent_status' => $activeStatus,
                        'concurrent_title' => $d['complaint_title'],
                        'docket_date' => $d['docket_date'] ?? null,
                        'view_url' => 'complaint-details.php?id=' . (int)$d['complaint_id']
                    ];
                }
            }
        }

        return [
            'has_concurrent' => $hasConcurrent,
            'alert_count' => count($alerts),
            'alerts' => $alerts
        ];
    }

    /**
     * Assembles a cross-case chronological timeline across all complaints and cases for a resident.
     */
    public function getCrossCaseTimelineForResident(int $residentId): array
    {
        $events = [];

        // 1. Complaint Filing Events
        $stmtC = $this->conn->prepare("
            SELECT 
                c.complaint_id, c.complaint_number, c.complaint_title, c.status, c.created_at,
                cp.party_type
            FROM complaint_parties cp
            INNER JOIN complaints c ON c.complaint_id = cp.complaint_id
            WHERE cp.resident_id = ?
        ");
        $stmtC->execute([$residentId]);
        foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'type' => 'complaint_filed',
                'title' => 'Complaint Filed',
                'description' => "Complaint {$row['complaint_number']} filed as {$row['party_type']}: \"{$row['complaint_title']}\"",
                'date' => $row['created_at'],
                'complaint_id' => (int)$row['complaint_id'],
                'case_number' => $row['complaint_number'],
                'status' => $row['status'],
                'badge' => 'Intake'
            ];
        }

        // 2. Paanyaya Sessions
        $stmtP = $this->conn->prepare("
            SELECT 
                p.paanyaya_id, p.complaint_id, p.invitee_name, p.scheduled_date, p.scheduled_time, p.status,
                c.complaint_number
            FROM paanyayas p
            INNER JOIN complaints c ON c.complaint_id = p.complaint_id
            INNER JOIN complaint_parties cp ON cp.complaint_id = p.complaint_id
            WHERE cp.resident_id = ?
        ");
        $stmtP->execute([$residentId]);
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'type' => 'paanyaya_invitation',
                'title' => 'Paanyaya Invitation',
                'description' => "Stage 0 Invitation scheduled for " . date('M j, Y', strtotime($row['scheduled_date'])) . " at " . date('g:i A', strtotime($row['scheduled_time'])),
                'date' => $row['scheduled_date'] . ' ' . $row['scheduled_time'],
                'complaint_id' => (int)$row['complaint_id'],
                'case_number' => $row['complaint_number'],
                'status' => $row['status'],
                'badge' => 'Paanyaya'
            ];
        }

        // 3. Case Docketing
        $stmtCase = $this->conn->prepare("
            SELECT 
                cs.case_id, cs.case_number, cs.case_status, cs.docket_date, cs.created_at,
                c.complaint_id, c.complaint_title
            FROM complaint_parties cp
            INNER JOIN cases cs ON cs.complaint_id = cp.complaint_id
            INNER JOIN complaints c ON c.complaint_id = cs.complaint_id
            WHERE cp.resident_id = ?
        ");
        $stmtCase->execute([$residentId]);
        foreach ($stmtCase->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'type' => 'case_docketed',
                'title' => 'Formal Case Docketed',
                'description' => "Formally elevated & docketed as Case #{$row['case_number']}",
                'date' => $row['docket_date'] ? ($row['docket_date'] . ' 00:00:00') : $row['created_at'],
                'complaint_id' => (int)$row['complaint_id'],
                'case_id' => (int)$row['case_id'],
                'case_number' => $row['case_number'],
                'status' => $row['case_status'],
                'badge' => 'Docketed'
            ];
        }

        // 4. Summons & Field Deliveries
        $stmtSummon = $this->conn->prepare("
            SELECT 
                sd.delivery_id, sd.form_type, sd.delivery_status, sd.served_at, sd.created_at,
                cs.case_number, cs.case_id, cs.complaint_id
            FROM summon_deliveries sd
            INNER JOIN cases cs ON cs.case_id = sd.case_id
            WHERE sd.resident_id = ?
        ");
        $stmtSummon->execute([$residentId]);
        foreach ($stmtSummon->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'type' => 'summon_delivery',
                'title' => 'Summons / Notice: ' . $row['form_type'],
                'description' => "Status: {$row['delivery_status']}" . ($row['served_at'] ? " (Served: " . date('M j, Y g:i A', strtotime($row['served_at'])) . ")" : ""),
                'date' => $row['served_at'] ?: $row['created_at'],
                'complaint_id' => (int)$row['complaint_id'],
                'case_id' => (int)$row['case_id'],
                'case_number' => $row['case_number'],
                'status' => $row['delivery_status'],
                'badge' => 'Summons'
            ];
        }

        // 5. Hearings & Sessions
        $stmtHear = $this->conn->prepare("
            SELECT 
                h.hearing_id, h.hearing_type, h.hearing_date, h.status, h.complainant_attendance, h.respondent_attendance,
                cs.case_number, cs.case_id, cs.complaint_id, cp.party_type
            FROM complaint_parties cp
            INNER JOIN cases cs ON cs.complaint_id = cp.complaint_id
            INNER JOIN hearings h ON h.case_id = cs.case_id
            WHERE cp.resident_id = ?
        ");
        $stmtHear->execute([$residentId]);
        foreach ($stmtHear->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $myAtt = $row['party_type'] === 'Complainant' ? $row['complainant_attendance'] : $row['respondent_attendance'];
            $events[] = [
                'type' => 'hearing',
                'title' => $row['hearing_type'] . ' Hearing',
                'description' => "Status: {$row['status']}" . ($myAtt ? " | My Attendance: {$myAtt}" : ""),
                'date' => $row['hearing_date'],
                'complaint_id' => (int)$row['complaint_id'],
                'case_id' => (int)$row['case_id'],
                'case_number' => $row['case_number'],
                'status' => $row['status'],
                'badge' => $row['hearing_type']
            ];
        }

        // Sort chronologically descending
        usort($events, function ($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
        });

        return $events;
    }

    /**
     * Cross-Module Update Flow:
     * When complaint facts or parties are updated, synchronizes downstream child records
     * (summon_deliveries, paanyayas) using the relational resident_id.
     */
    public function propagatePartyUpdates(int $complaintId): void
    {
        $parties = $this->getPartiesForComplaint($complaintId);
        $caseIdStmt = $this->conn->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
        $caseIdStmt->execute([$complaintId]);
        $caseId = $caseIdStmt->fetchColumn();

        foreach ($parties as $party) {
            $residentId = (int)$party['resident_id'];
            $fullName = $party['full_name'];
            $fullAddress = trim(($party['house_street'] ?? '') . ' ' . ($party['address'] ?? '') . ' ' . ($party['purok'] ?? ''));

            // 1. Sync Paanyaya invitations
            if ($party['party_type'] === 'Respondent') {
                $updPaa = $this->conn->prepare("
                    UPDATE paanyayas 
                    SET invitee_name = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updPaa->execute([$fullName, $complaintId]);
            }

            // 2. Sync Summon Deliveries if case exists
            if ($caseId) {
                $updSum = $this->conn->prepare("
                    UPDATE summon_deliveries 
                    SET recipient_name = ?, 
                        service_address = COALESCE(NULLIF(?, ''), service_address),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE case_id = ? AND resident_id = ?
                ");
                $updSum->execute([$fullName, $fullAddress, $caseId, $residentId]);
            }
        }
    }
}
