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

    public function update($id, $data): array
    {
        $caseId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $caseType = trim((string) ($data['case_type'] ?? ''));
        $caseStatus = trim((string) ($data['case_status'] ?? ''));
        $allowedStatuses = ['Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived'];

        if (!$caseId) return ['success' => false, 'message' => 'Please select a valid case.'];
        if (!in_array($caseType, ['Civil', 'Criminal'], true)) return ['success' => false, 'message' => 'Please select a valid case type.'];
        if (!in_array($caseStatus, $allowedStatuses, true)) return ['success' => false, 'message' => 'Please select a valid case status.'];

        $teamFieldsProvided = array_key_exists('head_id', $data)
            || array_key_exists('secretary_id', $data)
            || array_key_exists('member_id', $data);
        $automaticHeadStage = in_array($caseStatus, ['Docketed', 'Mediation'], true);
        if ($automaticHeadStage && $teamFieldsProvided) {
            return ['success' => false, 'message' => 'Lupon assignment is automatic for Docketed and Mediation cases. The Barangay Captain is automatically assigned as Head.'];
        }

        $teamUpdated = false;
        try {
            $this->conn->beginTransaction();
            $caseQuery = $this->conn->prepare('SELECT case_id, case_status FROM cases WHERE case_id = ? FOR UPDATE');
            $caseQuery->execute([$caseId]);
            $currentCase = $caseQuery->fetch(PDO::FETCH_ASSOC);
            if (!$currentCase) throw new InvalidArgumentException('Case not found.');

            $updateCase = $this->conn->prepare('UPDATE cases SET case_type = ?, case_status = ? WHERE case_id = ?');
            $updateCase->execute([$caseType, $caseStatus, $caseId]);

            if ($automaticHeadStage) {
                $teamUpdated = true;
                $adminQuery = $this->conn->prepare("SELECT u.user_id FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE u.status = 'Active' AND r.role_name = 'Administrator' ORDER BY u.user_id ASC LIMIT 1");
                $adminQuery->execute();
                $headId = (int) $adminQuery->fetchColumn();
                if ($headId < 1) throw new InvalidArgumentException('The active Barangay Captain account could not be found.');

                $removeOtherRoles = $this->conn->prepare("DELETE FROM case_assignments WHERE case_id = ? AND assignment_role IN ('Secretary', 'Member')");
                $removeOtherRoles->execute([$caseId]);
                $removeWrongHead = $this->conn->prepare("DELETE FROM case_assignments WHERE case_id = ? AND assignment_role = 'Head' AND member_id <> ?");
                $removeWrongHead->execute([$caseId, $headId]);
                $headQuery = $this->conn->prepare("SELECT assignment_id FROM case_assignments WHERE case_id = ? AND assignment_role = 'Head' AND member_id = ? LIMIT 1");
                $headQuery->execute([$caseId, $headId]);
                $hasHead = (bool) $headQuery->fetchColumn();
                if (!$hasHead) {
                    $insertHead = $this->conn->prepare("INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date) VALUES (?, ?, 'Head', CURDATE())");
                    $insertHead->execute([$caseId, $headId]);
                }
            } elseif ($caseStatus === 'Conciliation') {
                $teamUpdated = true;
                $ids = [];
                foreach (['head_id', 'secretary_id', 'member_id'] as $field) {
                    $memberId = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if (!$memberId) throw new InvalidArgumentException('Select a Head, Secretary, and Member for Conciliation.');
                    $ids[$field] = (int) $memberId;
                }
                if (count(array_unique(array_values($ids))) !== 3) {
                    throw new InvalidArgumentException('Head, Secretary, and Member must be assigned to different active Lupon Members.');
                }
                $eligible = $this->conn->prepare("SELECT COUNT(DISTINCT u.user_id) FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE u.status = 'Active' AND r.role_name = 'Lupon Member' AND u.user_id IN (?, ?, ?)");
                $eligible->execute(array_values($ids));
                if ((int) $eligible->fetchColumn() !== 3) {
                    throw new InvalidArgumentException('Head, Secretary, and Member must be active Lupon Members.');
                }

                $existingQuery = $this->conn->prepare("SELECT assignment_id, assignment_role FROM case_assignments WHERE case_id = ? AND assignment_role IN ('Head', 'Secretary', 'Member') FOR UPDATE");
                $existingQuery->execute([$caseId]);
                $existing = [];
                foreach ($existingQuery->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
                    $existing[$assignment['assignment_role']] = (int) $assignment['assignment_id'];
                }
                $team = ['Head' => $ids['head_id'], 'Secretary' => $ids['secretary_id'], 'Member' => $ids['member_id']];
                foreach ($team as $role => $memberId) {
                    if (isset($existing[$role])) {
                        $save = $this->conn->prepare('UPDATE case_assignments SET member_id = ? WHERE assignment_id = ? AND case_id = ?');
                        $save->execute([$memberId, $existing[$role], $caseId]);
                    } else {
                        $save = $this->conn->prepare('INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date) VALUES (?, ?, ?, CURDATE())');
                        $save->execute([$caseId, $memberId, $role]);
                    }
                }
                $this->syncPangkatTeam((int) $caseId, $team);
            }

            $this->conn->commit();
            return [
                'success' => true,
                'message' => $teamUpdated ? 'Case and Lupon team updated successfully.' : 'Case updated successfully.'
            ];
        } catch (InvalidArgumentException $exception) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => $exception->getMessage()];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to update the case. Please try again.'];
        }
    }

    private function syncPangkatTeam(int $caseId, array $team): void
    {
        $group = $this->conn->prepare('INSERT INTO pangkat_groups (case_id, formation_date) VALUES (?, CURDATE()) ON DUPLICATE KEY UPDATE pangkat_id = LAST_INSERT_ID(pangkat_id)');
        $group->execute([$caseId]);
        $pangkatId = (int) $this->conn->lastInsertId();
        $this->conn->prepare('DELETE FROM pangkat_members WHERE pangkat_id = ?')->execute([$pangkatId]);
        $insert = $this->conn->prepare('INSERT INTO pangkat_members (pangkat_id, member_id, position) VALUES (?, ?, ?)');
        $positions = ['Head' => 'Chairman', 'Secretary' => 'Secretary', 'Member' => 'Member'];
        foreach ($team as $role => $memberId) $insert->execute([$pangkatId, $memberId, $positions[$role]]);
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
