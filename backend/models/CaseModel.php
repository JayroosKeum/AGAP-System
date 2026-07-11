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

    public function generateCaseNumber()
{
    $year = date('Y');

    $stmt =
    $this->conn->query("
        SELECT COUNT(*)
        FROM cases
    ");

    $count =
    $stmt->fetchColumn() + 1;

    return sprintf(
        "KP-%s-%05d",
        $year,
        $count
    );
}

    public function getAll()
    {
        $stmt = $this->conn->prepare("
            SELECT
                c.*,
                co.complaint_number,
                co.complaint_title
            FROM cases c
            INNER JOIN complaints co
                ON co.complaint_id = c.complaint_id
            ORDER BY c.created_at DESC
        ");

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $caseNumber = $this->generateCaseNumber();

        $stmt = $this->conn->prepare("
            INSERT INTO cases
            (
                complaint_id,
                case_number,
                case_type,
                case_status,
                docket_date
            )
            VALUES
            (
                ?,?,?,?,?
            )
        ");

        return $stmt->execute([
            $data['complaint_id'],
            $caseNumber,
            $data['case_type'],
            'Docketed',
            date('Y-m-d')
        ]);
    }

    public function getDocketingError($complaintId)
    {
        if (filter_var($complaintId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return 'invalid_complaint';
        }

        $complaint = $this->conn->prepare('SELECT complaint_id FROM complaints WHERE complaint_id = ?');
        $complaint->execute([$complaintId]);

        if (!$complaint->fetch()) {
            return 'invalid_complaint';
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
