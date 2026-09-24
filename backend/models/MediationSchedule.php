<?php

require_once __DIR__ . '/../config/database.php';

class MediationSchedule
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    /**
     * Creates a Mediation case from a complaint.
     *
     * The existing active Administrator represents the
     * Barangay Captain and Lupon Head. The Administrator is
     * automatically assigned as the Head of the Mediation case.
     *
     * This operation does not create or modify user accounts.
     */
    public function create(
        int $complaintId,
        string $hearingDate,
        string $venue,
        ?string $remarks
    ): array {
        try {
            $this->conn->beginTransaction();

            $complaint = $this->conn->prepare('SELECT complaint_id, status, case_type FROM complaints WHERE complaint_id = ? FOR UPDATE');
            $complaint->execute([$complaintId]);
            $record = $complaint->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                $this->conn->rollBack();

                return [
                    'success' => false,
                    'message' => 'Complaint not found.'
                ];
            }
            if (!in_array($record['status'], ['Filed', 'Under Review', 'Needs Information', 'Accepted'], true)) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'This complaint cannot proceed to 1st Mediation in its current status.'];
            }

            $existingCase = $this->conn->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
            $existingCase->execute([$complaintId]);
            if ($existingCase->fetchColumn()) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'This complaint already has a case record.'];
            }

            /*
             * Locate the existing active Administrator.
             *
             * In AGAP, the Administrator represents the
             * Barangay Captain and Lupon Head.
             */
            $adminStmt = $this->conn->prepare(
                "SELECT u.user_id
                 FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE u.status = 'Active' AND r.role_name = 'Administrator'
                 ORDER BY u.user_id ASC
                 LIMIT 1"
            );
            $adminStmt->execute();
            $administrator = $adminStmt->fetch(PDO::FETCH_ASSOC);

            if (!$administrator) {
                $this->conn->rollBack();

                return [
                    'success' => false,
                    'message' =>
                        'The active Administrator or Barangay ' .
                        'Captain account could not be found.'
                ];
            }

            $caseType = (!empty($record['case_type']) && in_array($record['case_type'], ['Civil', 'Criminal'], true))
                ? $record['case_type']
                : 'Civil';

            $case = $this->conn->prepare(
                "INSERT INTO cases (complaint_id, case_type, case_status, docket_date)
                 VALUES (?, ?, 'Docketed', CURDATE())"
            );
            $case->execute([$complaintId, $caseType]);
            $caseId = (int) $this->conn->lastInsertId();
            $caseNumber = sprintf('KP-%s-%05d', date('Y'), $caseId);
            $number = $this->conn->prepare('UPDATE cases SET case_number = ? WHERE case_id = ?');
            $number->execute([$caseNumber, $caseId]);

            $headAssignment = $this->conn->prepare(
                "INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date)
                 VALUES (?, ?, 'Head', CURDATE())"
            );
            $headAssignment->execute([$caseId, (int) $administrator['user_id']]);

            $history = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks) VALUES (?, 'Docketed', ?)"
            );
            $history->execute([
                $caseId,
                'Case docketed when the 1st Mediation was scheduled. The Administrator or Barangay Captain was automatically assigned as Head.'
            ]);

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
                'head_member_id' => (int) $administrator['user_id'],
            ];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            error_log($exception->getMessage());

            return [
                'success' => false,
                'message' =>
                    'Unable to schedule mediation. ' .
                    'Please try again.'
            ];
        }
    }
}