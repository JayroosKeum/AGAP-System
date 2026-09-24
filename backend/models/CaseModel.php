<?php

require_once __DIR__ . '/../config/database.php';

class CaseModel
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

        public function getAll(): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                c.*,
                co.complaint_number,
                co.complaint_title,

                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            DISTINCT TRIM(
                                CONCAT_WS(
                                    ' ',
                                    complainant.first_name,
                                    complainant.middle_name,
                                    complainant.last_name
                                )
                            )
                            ORDER BY
                                complainant.last_name,
                                complainant.first_name
                            SEPARATOR ', '
                        )
                        FROM complaint_parties complainant_party
                        INNER JOIN residents complainant
                            ON complainant.resident_id =
                            complainant_party.resident_id
                        WHERE complainant_party.complaint_id =
                            co.complaint_id
                        AND complainant_party.party_type =
                            'Complainant'
                    ),
                    ''
                ) AS complainant_names,

                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            DISTINCT TRIM(
                                CONCAT_WS(
                                    ' ',
                                    respondent.first_name,
                                    respondent.middle_name,
                                    respondent.last_name
                                )
                            )
                            ORDER BY
                                respondent.last_name,
                                respondent.first_name
                            SEPARATOR ', '
                        )
                        FROM complaint_parties respondent_party
                        INNER JOIN residents respondent
                            ON respondent.resident_id =
                            respondent_party.resident_id
                        WHERE respondent_party.complaint_id =
                            co.complaint_id
                        AND respondent_party.party_type =
                            'Respondent'
                    ),
                    ''
                ) AS respondent_names

            FROM cases c

            INNER JOIN complaints co
                ON co.complaint_id = c.complaint_id

            ORDER BY c.created_at DESC
        ");

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPage(int $page, int $perPage = 25): array
    {
        $totalStmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM cases c INNER JOIN complaints co ON co.complaint_id = c.complaint_id'
        );
        $totalStmt->execute();
        $total = (int) $totalStmt->fetchColumn();
        $totalPages = (int) ceil($total / $perPage);
        $page = max(1, min($page, max(1, $totalPages)));
        $offset = ($page - 1) * $perPage;

        $stmt = $this->conn->prepare("\n            SELECT\n                c.*,\n                co.complaint_number,\n                co.complaint_title,\n\n                COALESCE(\n                    (\n                        SELECT GROUP_CONCAT(\n                            DISTINCT TRIM(\n                                CONCAT_WS(\n                                    ' ',\n                                    complainant.first_name,\n                                    complainant.middle_name,\n                                    complainant.last_name\n                                )\n                            )\n                            ORDER BY\n                                complainant.last_name,\n                                complainant.first_name\n                            SEPARATOR ', '\n                        )\n                        FROM complaint_parties complainant_party\n                        INNER JOIN residents complainant\n                            ON complainant.resident_id =\n                            complainant_party.resident_id\n                        WHERE complainant_party.complaint_id =\n                            co.complaint_id\n                        AND complainant_party.party_type =\n                            'Complainant'\n                    ),\n                    ''\n                ) AS complainant_names,\n\n                COALESCE(\n                    (\n                        SELECT GROUP_CONCAT(\n                            DISTINCT TRIM(\n                                CONCAT_WS(\n                                    ' ',\n                                    respondent.first_name,\n                                    respondent.middle_name,\n                                    respondent.last_name\n                                )\n                            )\n                            ORDER BY\n                                respondent.last_name,\n                                respondent.first_name\n                            SEPARATOR ', '\n                        )\n                        FROM complaint_parties respondent_party\n                        INNER JOIN residents respondent\n                            ON respondent.resident_id =\n                            respondent_party.resident_id\n                        WHERE respondent_party.complaint_id =\n                            co.complaint_id\n                        AND respondent_party.party_type =\n                            'Respondent'\n                    ),\n                    ''\n                ) AS respondent_names\n\n            FROM cases c\n            INNER JOIN complaints co\n                ON co.complaint_id = c.complaint_id\n            ORDER BY c.created_at DESC\n            LIMIT :limit OFFSET :offset\n        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $docketedStmt = $this->conn->prepare('SELECT complaint_id FROM cases');
        $docketedStmt->execute();

        return [
            'cases' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'docketed_complaint_ids' => array_map('strval', $docketedStmt->fetchAll(PDO::FETCH_COLUMN)),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    public function getById($id)
    {
        $stmt = $this->conn->prepare("
            SELECT
                c.*,
                co.complaint_number,
                co.complaint_title,
                co.incident_date,
                co.narrative
            FROM cases c
            INNER JOIN complaints co
                ON co.complaint_id = c.complaint_id
            WHERE c.case_id=?
        ");

        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data)
    {
        if ($this->getDocketingError($data['complaint_id'] ?? null) !== null) {
            return false;
        }

        try {
            $this->conn->beginTransaction();
            $stmt = $this->conn->prepare("
            INSERT INTO cases
            (
                complaint_id,
                case_type,
                case_status,
                docket_date
            )
            VALUES
            (
                ?,?,?,?
            )
        ");

            $stmt->execute([
                $data['complaint_id'],
                $data['case_type'],
                'Docketed',
                date('Y-m-d')
            ]);

            $caseId = (int) $this->conn->lastInsertId();
            $caseNumber = sprintf('KP-%s-%05d', date('Y'), $caseId);
            $numberStatement = $this->conn->prepare(
                'UPDATE cases SET case_number = ? WHERE case_id = ?'
            );
            $numberStatement->execute([$caseNumber, $caseId]);
            $statusStatement = $this->conn->prepare("UPDATE complaints SET status = 'Docketed' WHERE complaint_id = ? AND status = 'Accepted'");
            $statusStatement->execute([$data['complaint_id']]);
            if ($statusStatement->rowCount() !== 1) {
                throw new RuntimeException('Complaint approval changed before docketing.');
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            error_log($e->getMessage());
            return false;
        }
    }

    public function getWorkspace(int $id): array|false
    {
        $case = $this->getById($id);
        if (!$case) return false;
        $assignments = $this->conn->prepare("SELECT ca.assignment_role, ca.assigned_date, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS member_name FROM case_assignments ca INNER JOIN users u ON u.user_id = ca.member_id WHERE ca.case_id = ? ORDER BY FIELD(ca.assignment_role, 'Head', 'Secretary', 'Member', 'Mediator'), ca.assigned_date");
        $assignments->execute([$id]);
        $hearings = $this->conn->prepare("SELECT hearing_id, hearing_type, hearing_date, venue, CASE WHEN hearing_date < NOW() THEN 'Completed' ELSE 'Scheduled' END AS hearing_status FROM hearings WHERE case_id = ? ORDER BY hearing_date ASC");
        $hearings->execute([$id]);
        $documents = $this->conn->prepare("SELECT gd.document_id, gd.generated_at, gd.service_status, dt.template_name FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id WHERE gd.case_id = ? ORDER BY gd.generated_at DESC");
        $documents->execute([$id]);
        $proofs = $this->conn->prepare("SELECT ps.proof_id, ps.document_id, ps.served_date, ps.remarks, dt.template_name, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name FROM proof_of_service ps LEFT JOIN generated_documents gd ON gd.document_id = ps.document_id LEFT JOIN document_templates dt ON dt.template_id = gd.template_id LEFT JOIN users u ON u.user_id = ps.served_by WHERE ps.case_id = ? ORDER BY ps.served_date DESC");
        $proofs->execute([$id]);
        return ['case' => $case, 'assignments' => $assignments->fetchAll(PDO::FETCH_ASSOC), 'hearings' => $hearings->fetchAll(PDO::FETCH_ASSOC), 'documents' => $documents->fetchAll(PDO::FETCH_ASSOC), 'proofs' => $proofs->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function getDocketingError($complaintId)
    {
        if (filter_var($complaintId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return 'invalid_complaint';
        }

        $complaint = $this->conn->prepare('SELECT complaint_id, status FROM complaints WHERE complaint_id = ?');
        $complaint->execute([$complaintId]);

        $complaintRecord = $complaint->fetch(PDO::FETCH_ASSOC);
        if (!$complaintRecord) {
            return 'invalid_complaint';
        }
        if ($complaintRecord['status'] !== 'Accepted') {
            return 'complaint_not_accepted';
        }

        $case = $this->conn->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
        $case->execute([$complaintId]);

        if ($case->fetch()) {
            return 'duplicate_case';
        }

        return null;
    }

    public function update($id,$data)
    {
        $stmt = $this->conn->prepare("
            UPDATE cases
            SET
                case_type=?,
                case_status=?
            WHERE case_id=?
        ");

        return $stmt->execute([
            $data['case_type'],
            $data['case_status'],
            $id
        ]);
    }

    public function archive($id)
    {
        $stmt = $this->conn->prepare("
            UPDATE cases
            SET
                case_status='Archived',
                archived_date=CURDATE()
            WHERE case_id=?
        ");

        return $stmt->execute([$id]);
    }
}
