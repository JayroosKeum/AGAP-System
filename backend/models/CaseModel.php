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
