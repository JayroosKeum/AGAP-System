<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/NotificationService.php';

class Summons
{
    private PDO $conn;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
        $this->notifications = new NotificationService();
    }

    /**
     * Issues a summons for a complaint / case.
     * If the complaint is not yet docketed, this atomically creates and dockets
     * the case first, ensuring compliance with the required sequence:
     * Complaint -> Case/Docket -> Issue Summon -> Serve Summon -> Proof of Service.
     */
    public function issueFirstSummon(int $complaintId, int $userId): array
    {
        try {
            $this->conn->beginTransaction();

            $complaintStmt = $this->conn->prepare('SELECT complaint_id, complaint_number, complaint_title, status, case_type FROM complaints WHERE complaint_id = ? FOR UPDATE');
            $complaintStmt->execute([$complaintId]);
            $complaint = $complaintStmt->fetch(PDO::FETCH_ASSOC);

            if (!$complaint) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Complaint not found.'];
            }

            // Check if case exists or needs to be docketed
            $caseStmt = $this->conn->prepare('SELECT case_id, case_number, case_status FROM cases WHERE complaint_id = ? FOR UPDATE');
            $caseStmt->execute([$complaintId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                // Must locate active Administrator as automatic Head
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
                    return ['success' => false, 'message' => 'The active Administrator or Barangay Captain account could not be found.'];
                }

                $caseType = (!empty($complaint['case_type']) && in_array($complaint['case_type'], ['Civil', 'Criminal'], true))
                    ? $complaint['case_type']
                    : 'Civil';

                $insertCase = $this->conn->prepare("INSERT INTO cases (complaint_id, case_type, case_status, docket_date) VALUES (?, ?, 'Docketed', CURDATE())");
                $insertCase->execute([$complaintId, $caseType]);
                $caseId = (int) $this->conn->lastInsertId();
                $caseNumber = sprintf('KP-%s-%05d', date('Y'), $caseId);

                $updateCaseNum = $this->conn->prepare('UPDATE cases SET case_number = ? WHERE case_id = ?');
                $updateCaseNum->execute([$caseNumber, $caseId]);

                $headAssignment = $this->conn->prepare("INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date) VALUES (?, ?, 'Head', CURDATE())");
                $headAssignment->execute([$caseId, (int) $administrator['user_id']]);

                $updateComplaint = $this->conn->prepare("UPDATE complaints SET status = 'Docketed' WHERE complaint_id = ?");
                $updateComplaint->execute([$complaintId]);

                $caseHistory = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Docketed', 'Case docketed upon issuance of 1st Summons.', ?)");
                $caseHistory->execute([$caseId, $userId]);
            } else {
                $caseId = (int) $case['case_id'];
                $caseNumber = $case['case_number'];
            }

            // Template ID for KP Form 9 (Summons)
            $templateStmt = $this->conn->prepare(
                "INSERT INTO document_templates (template_name, description)
                 VALUES ('KP Form 9', 'Summons')
                 ON DUPLICATE KEY UPDATE description = VALUES(description), template_id = LAST_INSERT_ID(template_id)"
            );
            $templateStmt->execute();
            $templateId = (int) $this->conn->lastInsertId();
            if ($templateId === 0) {
                $getTemplate = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 9' LIMIT 1");
                $getTemplate->execute();
                $templateId = (int) $getTemplate->fetchColumn();
            }

            // Check existing summons documents
            $existingStmt = $this->conn->prepare(
                "SELECT gd.document_id, gd.service_status, gd.generated_at
                 FROM generated_documents gd
                 WHERE gd.case_id = ? AND gd.template_id = ?
                 ORDER BY gd.document_id ASC"
            );
            $existingStmt->execute([$caseId, $templateId]);
            $existingSummons = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
            $existingCount = count($existingSummons);

            // 1. Check if any summons was already successfully served
            $anyServedStmt = $this->conn->prepare("SELECT 1 FROM proof_of_service WHERE case_id = ? AND service_result = 'Served'");
            $anyServedStmt->execute([$caseId]);
            if ($anyServedStmt->fetchColumn()) {
                $this->conn->commit();
                $latest = !empty($existingSummons) ? end($existingSummons) : null;
                return [
                    'success' => true,
                    'already_issued' => true,
                    'case_id' => $caseId,
                    'case_number' => $caseNumber,
                    'document_id' => $latest ? (int) $latest['document_id'] : 0,
                    'summons_number' => $existingCount,
                    'message' => 'Summons has already been successfully served.',
                ];
            }

            // 2. Allow up to 2 summons attempts:
            // If already 2 summonses issued, do not issue a 3rd attempt
            if ($existingCount >= 2) {
                $this->conn->commit();
                $latest = end($existingSummons);
                return [
                    'success' => true,
                    'already_issued' => true,
                    'case_id' => $caseId,
                    'case_number' => $caseNumber,
                    'document_id' => (int) $latest['document_id'],
                    'summons_number' => 2,
                    'message' => 'Both 1st and 2nd summons attempts have already been issued.',
                ];
            }


            $summonsNum = $existingCount + 1;
            $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber);
            $fullDir = dirname(__DIR__, 2) . '/' . $relativeDir;
            if (!is_dir($fullDir)) {
                @mkdir($fullDir, 0750, true);
            }
            $fileName = sprintf('KP-Form-9-Summons-%d-%s.pdf', $summonsNum, date('Ymd-His'));
            $filePath = $relativeDir . '/' . $fileName;

            // Insert generated document
            $docStmt = $this->conn->prepare(
                "INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status)
                 VALUES (?, ?, ?, ?, 'For Service')"
            );
            $docStmt->execute([$caseId, $templateId, $userId, $filePath]);
            $documentId = (int) $this->conn->lastInsertId();

            // Record in case history
            $histRemarks = sprintf('Summons #%d issued. Assigned for service.', $summonsNum);
            $histStmt = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks, updated_by)
                 SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?"
            );
            $histStmt->execute([$histRemarks, $userId, $caseId]);


            $this->conn->commit();

            // Notify Summons Server users
            $this->notifySummonsServers($caseNumber, $summonsNum, $userId);

            return [
                'success' => true,
                'already_issued' => false,
                'case_id' => $caseId,
                'case_number' => $caseNumber,
                'document_id' => $documentId,
                'summons_number' => $summonsNum,
                'message' => sprintf('Summons #%d has been issued successfully.', $summonsNum),
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Error issuing summons: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to issue summons. Please try again.'];
        }


    }

    public function getSummonsByCase(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT gd.document_id, gd.case_id, gd.generated_at, gd.service_status, gd.file_path,
                    TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS issued_by_name,
                    dt.template_name
             FROM generated_documents gd
             INNER JOIN document_templates dt ON dt.template_id = gd.template_id
             LEFT JOIN users u ON u.user_id = gd.generated_by
             WHERE gd.case_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')
             ORDER BY gd.document_id ASC"
        );
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function notifySummonsServers(string $caseNumber, int $summonsNum, int $actorUserId): void
    {
        try {
            $stmt = $this->conn->prepare(
                "SELECT u.user_id
                 FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE u.status = 'Active' AND r.role_name = 'Summons Server'"
            );
            $stmt->execute();
            $servers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $title = sprintf('Summons #%d Issued', $summonsNum);
            $message = sprintf('Summons #%d for case %s has been issued and is ready for service.', $summonsNum, $caseNumber);

            foreach ($servers as $serverId) {
                if ((int) $serverId !== $actorUserId) {
                    $insert = $this->conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
                    $insert->execute([(int) $serverId, $title, $message]);
                }
            }
        } catch (Throwable $e) {
            error_log('Error notifying summons servers: ' . $e->getMessage());
        }
    }
}
