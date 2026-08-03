<?php

require_once __DIR__ . '/../config/database.php';

class Document
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function getAvailableCases(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT c.case_id, c.case_number, c.case_status, co.complaint_title
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE c.case_status <> 'Archived'
             ORDER BY c.created_at DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getKp12Data(int $caseId): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT c.case_id, c.case_number, c.case_status, c.complaint_id,
                    co.complaint_number, co.complaint_title,
                    h.hearing_id, h.hearing_date, h.venue,
                    TRIM(CONCAT_WS(' ', chair.first_name, chair.middle_name, chair.last_name)) AS chairman_name
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             LEFT JOIN hearings h ON h.hearing_id = (
                 SELECT h2.hearing_id
                 FROM hearings h2
                 WHERE h2.case_id = c.case_id
                   AND h2.hearing_type = 'Conciliation'
                 ORDER BY h2.hearing_date ASC
                 LIMIT 1
             )
             LEFT JOIN pangkat_groups pg ON pg.case_id = c.case_id
             LEFT JOIN pangkat_members pm ON pm.pangkat_id = pg.pangkat_id AND pm.position = 'Chairman'
             LEFT JOIN users chair ON chair.user_id = pm.member_id
             WHERE c.case_id = ?"
        );
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return false;
        }

        $parties = $this->conn->prepare(
            "SELECT cp.party_type,
                    TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name,
                    r.address
             FROM complaint_parties cp
             INNER JOIN residents r ON r.resident_id = cp.resident_id
             WHERE cp.complaint_id = ?
               AND cp.party_type IN ('Complainant', 'Respondent')
             ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent'), r.last_name, r.first_name"
        );
        $parties->execute([(int) $case['complaint_id']]);

        $case['complainants'] = [];
        $case['respondents'] = [];
        foreach ($parties->fetchAll(PDO::FETCH_ASSOC) as $party) {
            if ($party['party_type'] === 'Complainant') {
                $case['complainants'][] = $party;
            } else {
                $case['respondents'][] = $party;
            }
        }

        return $case;
    }

    public function getOrCreateTemplate(string $name, string $description): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO document_templates (template_name, description)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), template_id = LAST_INSERT_ID(template_id)'
        );
        $stmt->execute([$name, $description]);
        return (int) $this->conn->lastInsertId();
    }

    public function createGeneratedDocument(int $caseId, int $templateId, int $userId, string $filePath): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO generated_documents (case_id, template_id, generated_by, file_path)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$caseId, $templateId, $userId, $filePath]);
        return (int) $this->conn->lastInsertId();
    }

    public function getAllGenerated(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT gd.document_id, gd.case_id, gd.file_path, gd.generated_at,
                    dt.template_name, c.case_number, co.complaint_title,
                    TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS generated_by_name
             FROM generated_documents gd
             INNER JOIN document_templates dt ON dt.template_id = gd.template_id
             INNER JOIN cases c ON c.case_id = gd.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             LEFT JOIN users u ON u.user_id = gd.generated_by
             ORDER BY gd.generated_at DESC, gd.document_id DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getGeneratedById(int $documentId): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT gd.*, dt.template_name, c.case_number
             FROM generated_documents gd
             INNER JOIN document_templates dt ON dt.template_id = gd.template_id
             INNER JOIN cases c ON c.case_id = gd.case_id
             WHERE gd.document_id = ?"
        );
        $stmt->execute([$documentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function deleteGenerated(int $documentId): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM generated_documents WHERE document_id = ?');
        $stmt->execute([$documentId]);
        return $stmt->rowCount() === 1;
    }
}