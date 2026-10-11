<?php

require_once __DIR__ . '/../config/database.php';

class CaseModel
{
    private PDO $conn;

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
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        require_once __DIR__ . '/../services/MediationDeadlineService.php';
        require_once __DIR__ . '/Assignment.php';
        $deadlineService = new MediationDeadlineService($this->conn);
        $assignmentModel = new Assignment();
        $caseIds = array_map(fn($c) => (int) $c['case_id'], $cases);
        $teamValidations = $assignmentModel->validateConciliationTeams($caseIds);

        $latestHearingsStmt = $this->conn->prepare("
            SELECT h.case_id, MAX(h.hearing_date) AS latest_hearing_date
            FROM hearings h
            WHERE h.status != 'Cancelled'
              AND NOT EXISTS (
                  SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id
              )
            GROUP BY h.case_id
        ");
        $latestHearingsStmt->execute();
        $latestHearingMap = $latestHearingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($cases as &$caseItem) {
            $caseItem['mediation_timer'] = $deadlineService->computeStatus($caseItem);
            $cid = (int) $caseItem['case_id'];
            $val = $teamValidations[$cid] ?? ['valid' => false, 'message' => ''];
            $caseItem['has_conciliation_team'] = $val['valid'];
            $caseItem['conciliation_team_message'] = $val['message'];
            $caseItem['latest_hearing_date'] = $latestHearingMap[$cid] ?? null;
        }
        unset($caseItem);

        return $cases;
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
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        require_once __DIR__ . '/../services/MediationDeadlineService.php';
        require_once __DIR__ . '/Assignment.php';
        $deadlineService = new MediationDeadlineService($this->conn);
        $assignmentModel = new Assignment();
        $caseIds = array_map(fn($c) => (int) $c['case_id'], $cases);
        $teamValidations = $assignmentModel->validateConciliationTeams($caseIds);

        $latestHearingsStmt = $this->conn->prepare("
            SELECT h.case_id, MAX(h.hearing_date) AS latest_hearing_date
            FROM hearings h
            WHERE h.status != 'Cancelled'
              AND NOT EXISTS (
                  SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id
              )
            GROUP BY h.case_id
        ");
        $latestHearingsStmt->execute();
        $latestHearingMap = $latestHearingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($cases as &$caseItem) {
            $caseItem['mediation_timer'] = $deadlineService->computeStatus($caseItem);
            $cid = (int) $caseItem['case_id'];
            $val = $teamValidations[$cid] ?? ['valid' => false, 'message' => ''];
            $caseItem['has_conciliation_team'] = $val['valid'];
            $caseItem['conciliation_team_message'] = $val['message'];
            $caseItem['latest_hearing_date'] = $latestHearingMap[$cid] ?? null;
        }
        unset($caseItem);

        $docketedStmt = $this->conn->prepare('SELECT complaint_id FROM cases');
        $docketedStmt->execute();

        return [
            'cases' => $cases,
            'docketed_complaint_ids' => array_map('strval', $docketedStmt->fetchAll(PDO::FETCH_COLUMN)),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    public function getById(int $id): array|false
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

    public function create(array $data): bool
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
            $docketDate = date('Y-m-d');
            $caseNumber = self::generateCaseNumber($this->conn, $docketDate, $caseId);
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
        require_once __DIR__ . '/../services/MediationDeadlineService.php';
        $deadlineService = new MediationDeadlineService($this->conn);
        $case['mediation_timer'] = $deadlineService->computeStatus($case);
        $assignments = $this->conn->prepare("SELECT ca.assignment_role, ca.assigned_date, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS member_name FROM case_assignments ca INNER JOIN users u ON u.user_id = ca.member_id WHERE ca.case_id = ? ORDER BY FIELD(ca.assignment_role, 'Head', 'Secretary', 'Member', 'Mediator'), ca.assigned_date");
        $assignments->execute([$id]);
        $hearings = $this->conn->prepare("
            SELECT 
                h.hearing_id, 
                h.hearing_type,
                h.hearing_date,
                h.venue,
                h.status AS hearing_status_code,
                CASE
                    WHEN h.status = 'Completed' THEN 'Completed'
                    WHEN h.status = 'Rescheduled' THEN 'Rescheduled'
                    WHEN h.status = 'Cancelled' THEN 'Cancelled'
                    WHEN h.hearing_date < NOW() THEN 'Completed'
                    ELSE 'Scheduled'
                END AS hearing_status,
                h.complainant_attendance,
                h.respondent_attendance,
                h.attendance_recorded_at,
                h.attendance_notes,
                h.rescheduled_from_id,
                h.reschedule_reason,
                (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id) AS attendance_count,
                (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id AND ha.attendance_status = 'Present') AS present_count,
                (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id AND ha.attendance_status = 'Absent' AND ha.is_justified = 0) AS unjustified_absent_count,
                (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id AND (ha.attendance_status = 'Excused' OR ha.is_justified = 1)) AS excused_count
            FROM hearings h 
            WHERE h.case_id = ? 
            ORDER BY h.hearing_date ASC
        ");
        $hearings->execute([$id]);
        $hearingsList = $hearings->fetchAll(PDO::FETCH_ASSOC);

        require_once __DIR__ . '/HearingAttendance.php';
        $attendanceModel = new HearingAttendance($this->conn);

        $partyAttendanceStmt = $this->conn->prepare("
            SELECT 
                cp.party_type,
                cp.resident_id,
                TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name,
                ha.attendance_status,
                ha.is_justified,
                ha.justification_reason,
                ha.remarks AS appearance_remarks,
                ha.recorded_at,
                (
                    (SELECT COUNT(*) FROM summon_deliveries sd WHERE sd.hearing_id = ? AND (sd.resident_id = cp.resident_id OR sd.party_type = cp.party_type) AND sd.delivery_status IN ('Served Personal', 'Served Substituted', 'Served Refused'))
                    +
                    (SELECT COUNT(*) FROM proof_of_service pos WHERE pos.case_id = ? AND pos.service_result = 'Served')
                ) AS service_confirmed_count
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            LEFT JOIN hearing_attendance ha ON ha.hearing_id = ? AND ha.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name
        ");

        $deliveriesStmt = $this->conn->prepare("
            SELECT sd.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name
            FROM summon_deliveries sd
            LEFT JOIN users u ON u.user_id = sd.served_by
            WHERE sd.hearing_id = ?
            ORDER BY FIELD(sd.party_type, 'Complainant', 'Respondent')
        ");

        $evaluationsStmt = $this->conn->prepare("
            SELECT sce.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS evaluated_by_name
            FROM show_cause_evaluations sce
            LEFT JOIN users u ON u.user_id = sce.evaluated_by
            WHERE sce.hearing_id = ?
            ORDER BY sce.created_at DESC
        ");

        $summonsCountStmt = $this->conn->prepare("
            SELECT COUNT(*) FROM generated_documents gd
            INNER JOIN document_templates dt ON dt.template_id = gd.template_id
            WHERE gd.case_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')
        ");
        $summonsCountStmt->execute([$id]);
        $summonsCount = (int) $summonsCountStmt->fetchColumn();

        foreach ($hearingsList as &$hItem) {
            $hId = (int) $hItem['hearing_id'];
            $partyAttendanceStmt->execute([$hId, $id, $hId, $case['complaint_id']]);
            $rawParties = $partyAttendanceStmt->fetchAll(PDO::FETCH_ASSOC);

            $parties = array_map(function ($p) {
                return [
                    'resident_id' => (int) $p['resident_id'],
                    'party_type' => $p['party_type'],
                    'full_name' => $p['full_name'],
                    'attendance_status' => $p['attendance_status'] ?: 'Pending',
                    'is_justified' => (int) ($p['is_justified'] ?? 0),
                    'justification_reason' => $p['justification_reason'] ?? '',
                    'remarks' => $p['appearance_remarks'] ?? '',
                    'service_confirmed' => (int) ($p['service_confirmed_count'] ?? 0) > 0,
                    'recorded_at' => $p['recorded_at'] ?? null,
                ];
            }, $rawParties);

            $hItem['parties'] = $parties;

            $deliveriesStmt->execute([$hId]);
            $hItem['deliveries'] = $deliveriesStmt->fetchAll(PDO::FETCH_ASSOC);

            $evaluationsStmt->execute([$hId]);
            $hItem['evaluations'] = $evaluationsStmt->fetchAll(PDO::FETCH_ASSOC);

            if ((int) $hItem['attendance_count'] > 0) {
                $hItem['situation'] = $attendanceModel->evaluateSituation(
                    $parties,
                    $summonsCount,
                    $hItem['hearing_type'],
                    $case['case_status']
                );
            } else {
                $hItem['situation'] = null;
            }
        }
        unset($hItem);

        $documents = $this->conn->prepare("SELECT gd.document_id, gd.generated_at, gd.service_status, gd.file_path, dt.template_name FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id WHERE gd.case_id = ? ORDER BY gd.generated_at DESC");
        $documents->execute([$id]);
        $proofs = $this->conn->prepare("SELECT ps.proof_id, ps.document_id, ps.served_date, ps.remarks, dt.template_name, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name FROM proof_of_service ps LEFT JOIN generated_documents gd ON gd.document_id = ps.document_id LEFT JOIN document_templates dt ON dt.template_id = gd.template_id LEFT JOIN users u ON u.user_id = ps.served_by WHERE ps.case_id = ? ORDER BY ps.served_date DESC");
        $proofs->execute([$id]);

        require_once __DIR__ . '/CaseStage.php';
        $stageModel = new CaseStage($this->conn);
        $stageOverview = $stageModel->getStageOverview($id);

        return [
            'case' => $case,
            'assignments' => $assignments->fetchAll(PDO::FETCH_ASSOC),
            'hearings' => $hearingsList,
            'documents' => $documents->fetchAll(PDO::FETCH_ASSOC),
            'proofs' => $proofs->fetchAll(PDO::FETCH_ASSOC),
            'stages' => $stageOverview ? [
                'mediation' => $stageOverview['mediation'],
                'conciliation' => $stageOverview['conciliation']
            ] : null
        ];
    }

    public function getDocketingError(mixed $complaintId): ?string
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

    public function update(mixed $id, array $data): array
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

            $updateComplaint = $this->conn->prepare('UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = ? WHERE c.case_id = ?');
            $updateComplaint->execute([$caseStatus, $caseId]);

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

    public function archive(mixed $id): bool
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

    /**
     * Generates a case number in the format MM-SS-YYYY
     * (e.g. 10-01-2026 for 1st case filed in October 2026,
     *  or 10-45-2026 for the 45th case filed in 2026).
     */
    public static function generateCaseNumber(PDO $conn, string $docketDate, int $caseId): string
    {
        $docketTimestamp = strtotime($docketDate) ?: time();
        $year = (int) date('Y', $docketTimestamp);
        $month = (int) date('m', $docketTimestamp);

        $stmt = $conn->prepare("
            SELECT case_number 
            FROM cases 
            WHERE YEAR(docket_date) = ? AND case_id != ? AND case_number IS NOT NULL
            FOR UPDATE
        ");
        $stmt->execute([$year, $caseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $maxSeries = 0;
        foreach ($rows as $cn) {
            if (preg_match('/^\d{2}-(\d+)-\d{4}$/', (string) $cn, $m)) {
                $seriesNum = (int) $m[1];
                if ($seriesNum > $maxSeries) {
                    $maxSeries = $seriesNum;
                }
            } elseif (preg_match('/^KP-\d{4}-(\d+)$/', (string) $cn, $m)) {
                $seriesNum = (int) $m[1];
                if ($seriesNum > $maxSeries) {
                    $maxSeries = $seriesNum;
                }
            }
        }

        $series = $maxSeries + 1;
        return sprintf('%02d-%02d-%04d', $month, $series, $year);
    }
}
