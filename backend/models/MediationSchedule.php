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

            $caseStmt = $this->conn->prepare('SELECT case_id, case_number, case_status FROM cases WHERE complaint_id = ? FOR UPDATE');
            $caseStmt->execute([$complaintId]);
            $existingCase = $caseStmt->fetch(PDO::FETCH_ASSOC);

            // Prerequisite check: The first summons must be issued and the required service process must be recorded
            if (!$existingCase) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'The first summons must be issued and the required service process must be recorded before proceeding to the 1st Mediation stage.'
                ];
            }

            $caseId = (int) $existingCase['case_id'];
            $caseNumber = $existingCase['case_number'];

            // Check if summons was issued
            $summonsCheck = $this->conn->prepare(
                "SELECT 1 FROM generated_documents gd
                 INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                 WHERE gd.case_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')"
            );
            $summonsCheck->execute([$caseId]);
            if (!$summonsCheck->fetchColumn()) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'The first summons must be issued and the required service process must be recorded before proceeding to the 1st Mediation stage.'
                ];
            }

            // Check if service process was recorded in proof_of_service with Served status
            $proofCheck = $this->conn->prepare("SELECT 1 FROM proof_of_service WHERE case_id = ? AND service_result = 'Served'");
            $proofCheck->execute([$caseId]);

            if (!$proofCheck->fetchColumn()) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'The first summons must be issued and the required service process must be recorded before proceeding to the 1st Mediation stage.'
                ];
            }

            // Prevent duplicate 1st Mediation
            $existingMediation = $this->conn->prepare(
                "SELECT hearing_id FROM hearings WHERE case_id = ? AND hearing_type = 'Mediation' LIMIT 1"
            );
            $existingMediation->execute([$caseId]);
            if ($existingMediation->fetchColumn()) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => '1st Mediation has already been scheduled for this case.'
                ];
            }

            // Insert 1st Mediation hearing
            $hearing = $this->conn->prepare(
                "INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, remarks)
                 VALUES (?, 'Mediation', ?, ?, ?)"
            );
            $hearing->execute([$caseId, $hearingDate, $venue, $remarks]);
            $hearingId = (int) $this->conn->lastInsertId();

            // Transition case status to Mediation if currently Docketed
            if ($existingCase['case_status'] === 'Docketed') {
                $updateCase = $this->conn->prepare("UPDATE cases SET case_status = 'Mediation' WHERE case_id = ?");
                $updateCase->execute([$caseId]);

                $updateComplaint = $this->conn->prepare("UPDATE complaints SET status = 'Mediation' WHERE complaint_id = ?");
                $updateComplaint->execute([$complaintId]);
            }

            // Upsert Mediation Period deadline
            $deadline = $this->conn->prepare(
                "INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                 VALUES (?, 'Mediation Period', DATE_ADD(DATE(?), INTERVAL 15 DAY), 'Pending')
                 ON DUPLICATE KEY UPDATE due_date = VALUES(due_date)"
            );
            $deadline->execute([$caseId, $hearingDate]);

            // Record in case history
            $history = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks) VALUES (?, 'Mediation', ?)"
            );
            $history->execute([
                $caseId,
                sprintf('1st Mediation scheduled for %s at %s.', date('F j, Y g:i A', strtotime($hearingDate)), $venue)
            ]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => '1st Mediation has been scheduled successfully.',
                'case_id' => $caseId,
                'case_number' => $caseNumber,
                'hearing_id' => $hearingId,
            ];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            error_log($exception->getMessage());

            return [
                'success' => false,
                'message' => 'Unable to schedule mediation. Please try again.'
            ];
        }
    }
}