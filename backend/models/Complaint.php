<?php
declare(strict_types=1);

final class Complaint
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data, int $actorId): int
    {
        $incidentDate = $data['incident_date'] ?? null;
        $incidentTime = $data['incident_time'] ?? null;
        if (!empty($data['incident_datetime'])) {
            $rawDt = trim((string) $data['incident_datetime']);
            $dtObj = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $rawDt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDt);
            if ($dtObj) {
                $incidentDate = $dtObj->format('Y-m-d');
                $incidentTime = $dtObj->format('H:i');
            }
        }
        $incidentAt = ($incidentDate && $incidentTime) ? "$incidentDate $incidentTime:00" : ($incidentDate ? "$incidentDate 00:00:00" : date('Y-m-d H:i:s'));

        $street = trim((string)($data['incident_street'] ?? ''));
        $purok = trim((string)($data['incident_purok'] ?? ''));
        $bgy = trim((string)($data['incident_barangay'] ?? 'Tumana'));
        $city = trim((string)($data['incident_city'] ?? 'Marikina City'));
        $location = trim(implode(', ', array_filter([$street, $purok, $bgy, $city])));

        $caseType = in_array(trim((string)($data['case_type'] ?? '')), ['Civil', 'Criminal'], true) ? trim((string)$data['case_type']) : 'Civil';
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

        $stmt = $this->pdo->prepare('
            INSERT INTO complaints (
                case_no, category_id, case_type, complaint_title, incident_date, incident_time, incident_at,
                incident_city, incident_barangay, incident_street, incident_purok, incident_landmark, incident_location,
                incident_latitude, incident_longitude, narrative, additional_details, proof_ocr_notes,
                complaint_type, relief_sought, process_stage, status, created_by, updated_by
            ) VALUES (
                :case_no, :category_id, :case_type, :complaint_title, :incident_date, :incident_time, :incident_at,
                :incident_city, :incident_barangay, :incident_street, :incident_purok, :incident_landmark, :incident_location,
                :incident_latitude, :incident_longitude, :narrative, :additional_details, :proof_ocr_notes,
                :complaint_type, :relief_sought, "Intake", "Filed", :created_by, :updated_by
            )
        ');

        $stmt->execute([
            'case_no' => 'TMP-' . bin2hex(random_bytes(8)),
            'category_id' => $categoryId,
            'case_type' => $caseType,
            'complaint_title' => trim((string)($data['complaint_title'] ?? 'Untitled Complaint')),
            'incident_date' => $incidentDate,
            'incident_time' => $incidentTime,
            'incident_at' => $incidentAt,
            'incident_city' => $city ?: 'Marikina City',
            'incident_barangay' => $bgy ?: 'Tumana',
            'incident_street' => $street ?: null,
            'incident_purok' => $purok ?: null,
            'incident_landmark' => trim((string)($data['incident_landmark'] ?? '')) ?: null,
            'incident_location' => $location ?: 'Barangay Tumana, Marikina City',
            'incident_latitude' => filter_var($data['location_latitude'] ?? $data['incident_latitude'] ?? null, FILTER_VALIDATE_FLOAT) ?: null,
            'incident_longitude' => filter_var($data['location_longitude'] ?? $data['incident_longitude'] ?? null, FILTER_VALIDATE_FLOAT) ?: null,
            'narrative' => trim((string)($data['narrative'] ?? '')),
            'additional_details' => trim((string)($data['additional_details'] ?? '')) ?: null,
            'proof_ocr_notes' => trim((string)($data['proof_ocr_notes'] ?? '')) ?: null,
            'complaint_type' => trim((string)($data['complaint_type'] ?? 'General Dispute')),
            'relief_sought' => trim((string)($data['relief_sought'] ?? 'Mediation Proceedings')),
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $caseNo = 'KP-' . date('Y') . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
        $update = $this->pdo->prepare('UPDATE complaints SET case_no = :case_no WHERE complaint_id = :id');
        $update->execute(['case_no' => $caseNo, 'id' => $id]);

        return $id;
    }

    public function addParty(int $complaintId, string $type, ?int $profileId, string $name, string $contact, string $address, string $purok): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO complaint_parties (
                complaint_id, party_type, profile_id, full_name, contact_no, address_line, purok_sitio, is_manual
            ) VALUES (
                :complaint_id, :party_type, :profile_id, :full_name, :contact_no, :address_line, :purok_sitio, :is_manual
            )
        ');
        $stmt->execute([
            'complaint_id' => $complaintId,
            'party_type' => $type,
            'profile_id' => $profileId,
            'full_name' => $name,
            'contact_no' => $contact,
            'address_line' => $address,
            'purok_sitio' => $purok,
            'is_manual' => $profileId === null ? 1 : 0
        ]);
    }

    public function addAttachment(int $complaintId, string $fileName, string $filePath, string $fileType, int $fileSize): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO complaint_attachments (
                complaint_id, file_name, file_path, file_type, file_size
            ) VALUES (
                :complaint_id, :file_name, :file_path, :file_type, :file_size
            )
        ');
        $stmt->execute([
            'complaint_id' => $complaintId,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_type' => $fileType,
            'file_size' => $fileSize
        ]);
    }

    public function addHistory(int $complaintId, int $actorId, array $details): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO complaint_history (complaint_id, action, details, acted_by)
            VALUES (:complaint_id, :action, :details, :actor)
        ');
        $stmt->execute([
            'complaint_id' => $complaintId,
            'action' => 'Complaint Filed',
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'actor' => $actorId
        ]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT c.*, cat.category_name, CONCAT(u.first_name, " ", u.last_name) AS creator_name
            FROM complaints c
            LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
            LEFT JOIN users u ON u.user_id = c.created_by
            WHERE c.complaint_id = :id
        ');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getParties(int $complaintId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT cp.*, p.profile_no, p.residency_status
            FROM complaint_parties cp
            LEFT JOIN profiles p ON p.profile_id = cp.profile_id
            WHERE cp.complaint_id = :id
            ORDER BY cp.complaint_party_id ASC
        ');
        $stmt->execute(['id' => $complaintId]);
        return $stmt->fetchAll();
    }

    public function getAttachments(int $complaintId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM complaint_attachments
            WHERE complaint_id = :id
            ORDER BY uploaded_at ASC
        ');
        $stmt->execute(['id' => $complaintId]);
        return $stmt->fetchAll();
    }

    public function getHistory(int $complaintId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT h.*, CONCAT(u.first_name, " ", u.last_name) AS actor_name
            FROM complaint_history h
            LEFT JOIN users u ON u.user_id = h.acted_by
            WHERE h.complaint_id = :id
            ORDER BY h.acted_at DESC
        ');
        $stmt->execute(['id' => $complaintId]);
        return $stmt->fetchAll();
    }

    public function all(?string $statusFilter = null, ?string $q = null, ?string $caseType = null): array
    {
        $where = ['1=1'];
        $params = [];

        if ($statusFilter !== null && $statusFilter !== '') {
            if ($statusFilter === 'Pending') {
                $where[] = "(c.status IN ('Filed', 'Pending Review', 'Under Review') OR c.process_stage = 'Intake')";
            } elseif ($statusFilter === 'Mediation') {
                $where[] = "(c.process_stage = 'Mediation' OR c.status = 'Mediation')";
            } elseif ($statusFilter === 'Conciliation') {
                $where[] = "(c.process_stage = 'Conciliation' OR c.status = 'Conciliation')";
            } elseif ($statusFilter === 'CFA') {
                $where[] = "(c.process_stage = 'CFA' OR c.status = 'CFA Issued')";
            } elseif ($statusFilter === 'Resolution / Closed') {
                $where[] = "(c.status IN ('Resolved', 'Settled', 'Dismissed', 'Archived', 'Closed') OR c.process_stage = 'Closed')";
            } else {
                $where[] = "c.status = :status";
                $params['status'] = $statusFilter;
            }
        }

        if ($caseType !== null && $caseType !== '') {
            $where[] = "c.case_type = :case_type";
            $params['case_type'] = $caseType;
        }

        if ($q !== null && trim($q) !== '') {
            $where[] = "(c.case_no LIKE :q OR c.complaint_title LIKE :q OR c.narrative LIKE :q OR cp1.full_name LIKE :q OR cp2.full_name LIKE :q)";
            $params['q'] = '%' . trim($q) . '%';
        }

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT c.*, cat.category_name,
                   (SELECT full_name FROM complaint_parties WHERE complaint_id = c.complaint_id AND party_type = 'Complainant' LIMIT 1) AS complainant_name,
                   (SELECT full_name FROM complaint_parties WHERE complaint_id = c.complaint_id AND party_type = 'Respondent' LIMIT 1) AS respondent_name,
                   (SELECT COUNT(*) FROM complaint_attachments WHERE complaint_id = c.complaint_id) AS attachment_count
            FROM complaints c
            LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
            WHERE $whereClause
            ORDER BY c.complaint_id DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
