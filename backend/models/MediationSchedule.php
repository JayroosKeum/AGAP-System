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

            /*
             * Lock the complaint to prevent concurrent requests
             * from creating multiple cases for the same complaint.
             */
            $complaintStatement = $this->conn->prepare(
                'SELECT
                    complaint_id,
                    status
                 FROM complaints
                 WHERE complaint_id = ?
                 FOR UPDATE'
            );

            $complaintStatement->execute([
                $complaintId
            ]);

            $complaint = $complaintStatement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$complaint) {
                $this->conn->rollBack();

                return [
                    'success' => false,
                    'message' => 'Complaint not found.'
                ];
            }

            if ($complaint['status'] !== 'Under Review') {
                $this->conn->rollBack();

                return [
                    'success' => false,
                    'message' =>
                        'Only complaints under review can ' .
                        'proceed to mediation.'
                ];
            }

            /*
             * Locate the existing active Administrator.
             *
             * In AGAP, the Administrator represents the
             * Barangay Captain and Lupon Head.
             */
            $administratorStatement = $this->conn->prepare(
                "SELECT
                    u.user_id,
                    u.first_name,
                    u.middle_name,
                    u.last_name,
                    u.username
                 FROM users u
                 INNER JOIN roles r
                    ON r.role_id = u.role_id
                 WHERE u.status = 'Active'
                   AND r.role_name = 'Administrator'
                 ORDER BY u.user_id ASC
                 LIMIT 1"
            );

            $administratorStatement->execute();

            $administrator = $administratorStatement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$administrator) {
                $this->conn->rollBack();

                return [
                    'success' => false,
                    'message' =>
                        'The active Administrator or Barangay ' .
                        'Captain account could not be found.'
                ];
            }

            /*
             * Confirm that the complaint does not already have
             * an existing case record.
             */
            $existingCaseStatement = $this->conn->prepare(
                'SELECT case_id
                 FROM cases
                 WHERE complaint_id = ?
                 LIMIT 1'
            );

            $existingCaseStatement->execute([
                $complaintId
            ]);

            if ($existingCaseStatement->fetchColumn()) {
                $this->conn->rollBack();

                return [
                    'success' => false,
                    'message' =>
                        'This complaint already has a case record.'
                ];
            }

            /*
             * Create the Mediation case.
             *
             * This retains the existing behavior where a case
             * created through Mediation is classified as Civil.
             */
            $caseStatement = $this->conn->prepare(
                "INSERT INTO cases (
                    complaint_id,
                    case_type,
                    case_status,
                    docket_date
                 )
                 VALUES (
                    ?,
                    'Civil',
                    'Mediation',
                    CURDATE()
                 )"
            );

            $caseStatement->execute([
                $complaintId
            ]);

            $caseId = (int) $this->conn
                ->lastInsertId();

            /*
             * Generate the official case number using the
             * database-generated case ID.
             */
            $caseNumber = sprintf(
                'KP-%s-%05d',
                date('Y'),
                $caseId
            );

            $caseNumberStatement = $this->conn->prepare(
                'UPDATE cases
                 SET case_number = ?
                 WHERE case_id = ?'
            );

            $caseNumberStatement->execute([
                $caseNumber,
                $caseId
            ]);

            /*
             * Automatically assign the existing Administrator
             * as the Head of the Mediation case.
             */
            $headAssignmentStatement = $this->conn->prepare(
                "INSERT INTO case_assignments (
                    case_id,
                    member_id,
                    assignment_role,
                    assigned_date
                 )
                 VALUES (
                    ?,
                    ?,
                    'Head',
                    CURDATE()
                 )"
            );

            $headAssignmentStatement->execute([
                $caseId,
                (int) $administrator['user_id']
            ]);

            /*
             * Record the initial Mediation status and automatic
             * Head assignment in the case history.
             */
            $historyStatement = $this->conn->prepare(
                "INSERT INTO case_history (
                    case_id,
                    status,
                    remarks
                 )
                 VALUES (
                    ?,
                    'Mediation',
                    ?
                 )"
            );

            $historyStatement->execute([
                $caseId,
                'Case opened when mediation was scheduled. ' .
                'The Administrator or Barangay Captain was ' .
                'automatically assigned as Head.'
            ]);

            /*
             * Create the Mediation hearing.
             */
            $hearingStatement = $this->conn->prepare(
                "INSERT INTO hearings (
                    case_id,
                    hearing_type,
                    hearing_date,
                    venue,
                    remarks
                 )
                 VALUES (
                    ?,
                    'Mediation',
                    ?,
                    ?,
                    ?
                 )"
            );

            $hearingStatement->execute([
                $caseId,
                $hearingDate,
                $venue,
                $remarks
            ]);

            $hearingId = (int) $this->conn
                ->lastInsertId();

            /*
             * Create the 15-day Mediation Period deadline.
             */
            $deadlineStatement = $this->conn->prepare(
                "INSERT INTO case_deadlines (
                    case_id,
                    deadline_type,
                    due_date,
                    status
                 )
                 VALUES (
                    ?,
                    'Mediation Period',
                    DATE_ADD(
                        DATE(?),
                        INTERVAL 15 DAY
                    ),
                    'Pending'
                 )"
            );

            $deadlineStatement->execute([
                $caseId,
                $hearingDate
            ]);

            /*
             * Synchronize the complaint status with the case.
             *
             * The status condition prevents the request from
             * overriding another workflow update.
             */
            $statusStatement = $this->conn->prepare(
                "UPDATE complaints
                 SET status = 'Mediation'
                 WHERE complaint_id = ?
                   AND status = 'Under Review'"
            );

            $statusStatement->execute([
                $complaintId
            ]);

            if ($statusStatement->rowCount() !== 1) {
                throw new RuntimeException(
                    'Complaint status changed before ' .
                    'mediation could be scheduled.'
                );
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' =>
                    'Mediation has been scheduled. ' .
                    'The Administrator or Barangay Captain ' .
                    'was automatically assigned as Head.',
                'case_id' => $caseId,
                'case_number' => $caseNumber,
                'hearing_id' => $hearingId,
                'head_member_id' =>
                    (int) $administrator['user_id']
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