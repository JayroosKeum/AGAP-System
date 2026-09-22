<?php

require_once __DIR__ . '/../config/database.php';

class MediationSchedule
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function create(int $complaintId, string $hearingDate, string $venue, ?string $remarks): array
    {
        try {
            $this->conn->beginTransaction();

            $complaint = $this->conn->prepare(
                'SELECT complaint_id, status FROM complaints WHERE complaint_id = ? FOR UPDATE'
            );
            $complaint->execute([$complaintId]);
            $record = $complaint->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Complaint not found.'];
            }
            if ($record['status'] !== 'Under Review') {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Only complaints under review can proceed to 1st Mediation.'];
            }

            $existingCase = $this->conn->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
            $existingCase->execute([$complaintId]);
            if ($existingCase->fetchColumn()) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'This complaint already has a case record.'];
            }

            $case = $this->conn->prepare(
                "INSERT INTO cases (complaint_id, case_type, case_status, docket_date)
                 VALUES (?, 'Civil', 'Docketed', CURDATE())"
            );
            $case->execute([$complaintId]);
            $caseId = (int) $this->conn->lastInsertId();
            $caseNumber = sprintf('KP-%s-%05d', date('Y'), $caseId);
            $number = $this->conn->prepare('UPDATE cases SET case_number = ? WHERE case_id = ?');
            $number->execute([$caseNumber, $caseId]);

            $history = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks) VALUES (?, 'Docketed', ?)"
            );
            $history->execute([$caseId, 'Case docketed when the 1st Mediation was scheduled.']);

            $hearing = $this->conn->prepare(
                "INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, remarks)
                 VALUES (?, 'Mediation', ?, ?, ?)"
            );
            $hearing->execute([$caseId, $hearingDate, $venue, $remarks]);
            $hearingId = (int) $this->conn->lastInsertId();

            $deadline = $this->conn->prepare(
                "INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                 VALUES (?, 'Mediation Period', DATE_ADD(DATE(?), INTERVAL 15 DAY), 'Pending')"
            );
            $deadline->execute([$caseId, $hearingDate]);

            $status = $this->conn->prepare("UPDATE complaints SET status = 'Docketed' WHERE complaint_id = ?");
            $status->execute([$complaintId]);

            $this->conn->commit();
            return [
                'success' => true,
                'message' => '1st Mediation has been scheduled and the case is now docketed.',
                'case_id' => $caseId,
                'case_number' => $caseNumber,
                'hearing_id' => $hearingId,
            ];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to schedule mediation. Please try again.'];
        }
    }
}
