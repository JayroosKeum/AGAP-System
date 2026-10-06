<?php

require_once __DIR__ . '/../config/database.php';

class Assignment
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    /**
     * Retained for compatibility with older code.
     * The unified case-team workflow should normally use replaceCaseTeam().
     */
    public function assign(array $data): array
    {
        $caseId = filter_var(
            $data['case_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $memberId = filter_var(
            $data['member_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $role = trim((string) ($data['assignment_role'] ?? ''));
        $allowedRoles = ['Mediator', 'Head', 'Secretary', 'Member'];

        if (!$caseId || !$memberId || !in_array($role, $allowedRoles, true)) {
            return [
                'success' => false,
                'message' => 'Please provide a valid case, member, and assignment role.'
            ];
        }

        $case = $this->getCase((int) $caseId);
        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        if ($case['case_status'] === 'Archived') {
            return [
                'success' => false,
                'message' => 'Assignments for an archived case cannot be changed.'
            ];
        }

        if (in_array($case['case_status'], ['Docketed', 'Mediation'], true)) {
            return [
                'success' => false,
                'message' => 'Lupon assignment is automatic for Docketed and Mediation cases. The Barangay Captain is automatically assigned as Head.'
            ];
        }

        $isMediation = in_array($case['case_status'], ['Docketed', 'Mediation'], true);
        $isAutomaticMediationHead = $isMediation && $role === 'Head';

        if ($isAutomaticMediationHead) {
            $administrator = $this->getLuponHead();
            if (!$administrator) {
                return [
                    'success' => false,
                    'message' => 'The active Administrator or Barangay Captain account could not be found.'
                ];
            }
            $memberId = (int) $administrator['member_id'];
        }

        if ($isAutomaticMediationHead) {
            $eligible = $this->isEligibleMediationHead((int) $memberId);
        } else {
            $eligible = $this->isEligibleMember((int) $memberId);
        }

        if (!$eligible) {
            return [
                'success' => false,
                'message' => $isAutomaticMediationHead
                    ? 'The Mediation Head must be the active Administrator or Barangay Captain.'
                    : 'The selected user must be an active Lupon Member.'
            ];
        }

        $roleExists = $this->conn->prepare(
            'SELECT assignment_id FROM case_assignments WHERE case_id = ? AND assignment_role = ? LIMIT 1'
        );
        $roleExists->execute([$caseId, $role]);
        if ($roleExists->fetchColumn()) {
            return [
                'success' => false,
                'message' => 'This case already has a user assigned to the selected role.'
            ];
        }

        if (in_array($role, ['Head', 'Secretary', 'Member'], true)) {
            $memberExists = $this->conn->prepare(
                "SELECT assignment_id FROM case_assignments
                 WHERE case_id = ? AND member_id = ? AND assignment_role IN ('Head', 'Secretary', 'Member')
                 LIMIT 1"
            );
            $memberExists->execute([$caseId, $memberId]);
            if ($memberExists->fetchColumn()) {
                return [
                    'success' => false,
                    'message' => 'The selected user already has a team role for this case.'
                ];
            }
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date)
                 VALUES (?, ?, ?, CURDATE())'
            );
            $stmt->execute([$caseId, $memberId, $role]);

            return [
                'success' => true,
                'message' => $isAutomaticMediationHead
                    ? 'The Administrator or Barangay Captain was assigned as the Mediation Head.'
                    : 'Lupon member assigned successfully.'
            ];
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to save the assignment.'];
        }
    }

    /**
     * Replaces the complete Head, Secretary, and Member team.
     *
     * Docketed and Mediation use the automatic Administrator Head and reject
     * manual team saves. Conciliation accepts one initial team only; later
     * changes are made through the case edit operation.
     */
    public function replaceCaseTeam(int $caseId, array $members): array
    {
        if ($caseId < 1) {
            return ['success' => false, 'message' => 'A valid case is required.'];
        }

        $case = $this->getCase($caseId);
        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        if ($case['case_status'] === 'Archived') {
            return [
                'success' => false,
                'message' => 'The team of an archived case cannot be changed.'
            ];
        }

        if ($case['case_status'] === 'Docketed') {
            return [
                'success' => false,
                'message' => 'Lupon assignment is automatic for Docketed cases. The Barangay Captain is automatically assigned as Head.'
            ];
        }

        $isMediationOnly = false; // Admin is for Mediation only; Conciliation requires 3 Lupon Members

        $headId = filter_var(
            $members['head_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $secretaryId = filter_var(
            $members['secretary_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $memberId = filter_var(
            $members['member_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $quorumSize = (!empty($members['quorum_size']) && (int) $members['quorum_size'] === 2) ? 2 : 3;
        $quorumReason = trim((string) ($members['quorum_exception_reason'] ?? ''));
        $selectionMethod = in_array($members['selection_method'] ?? '', ['Party Agreement', 'PB Assignment', 'Raffle Draw'], true) 
            ? $members['selection_method'] 
            : 'Party Agreement';
        $selectionNotes = trim((string) ($members['selection_notes'] ?? ''));

        if ($quorumSize === 2) {
            if (!$headId || !$secretaryId) {
                return [
                    'success' => false,
                    'message' => 'For a 2-member quorum, both Head/Chairman and Secretary must be selected.'
                ];
            }
            if ($quorumReason === '') {
                return [
                    'success' => false,
                    'message' => 'A valid justification or party agreement is required when proceeding with a 2-member quorum.'
                ];
            }
            $selectedIds = array_filter([(int) $headId, (int) $secretaryId]);
            if ($memberId) {
                $selectedIds[] = (int) $memberId;
            }
            if (count(array_unique($selectedIds)) !== count($selectedIds)) {
                return ['success' => false, 'message' => 'Assigned Lupon members must be different users.'];
            }
        } else {
            if (!$headId || !$secretaryId || !$memberId) {
                return [
                    'success' => false,
                    'message' => 'Select the Head, Secretary, and Member from active Lupon Members.'
                ];
            }
            $selectedIds = [(int) $headId, (int) $secretaryId, (int) $memberId];
            if (count(array_unique($selectedIds)) !== 3) {
                return [
                    'success' => false,
                    'message' => 'Head, Secretary, and Member must be different users.'
                ];
            }
        }

        // The Administrator is for Mediation only. For Conciliation, all assigned must be active Lupon Members.
        if (!$this->isEligibleMember((int) $headId)) {
            return [
                'success' => false,
                'message' => 'The Administrator is for Mediation only. When a case goes to Conciliation, the Head must be an active Lupon Member.'
            ];
        }

        if (!$this->isEligibleMember((int) $secretaryId) || ($memberId && !$this->isEligibleMember((int) $memberId))) {
            return [
                'success' => false,
                'message' => 'Secretary and Member must be active Lupon Members.'
            ];
        }

        try {
            $this->conn->beginTransaction();

            $lockedCase = $this->conn->prepare('SELECT case_status FROM cases WHERE case_id = ? FOR UPDATE');
            $lockedCase->execute([$caseId]);
            $lockedStatus = $lockedCase->fetchColumn();
            if (!$lockedStatus || $lockedStatus !== $case['case_status']) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'The case status changed. Reload the case and try again.'];
            }

            if ($case['case_status'] === 'Conciliation') {
                $existingTeam = $this->conn->prepare(
                    "SELECT COUNT(DISTINCT ca.assignment_role)
                     FROM case_assignments ca
                     INNER JOIN users u ON u.user_id = ca.member_id
                     INNER JOIN roles r ON r.role_id = u.role_id
                     WHERE ca.case_id = ?
                       AND ca.assignment_role IN ('Head', 'Secretary', 'Member')
                       AND r.role_name = 'Lupon Member'
                       AND u.status = 'Active'"
                );
                $existingTeam->execute([$caseId]);
                if ((int) $existingTeam->fetchColumn() === 3) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'message' => 'This Conciliation case already has an assigned Lupon team. Use Edit to modify the assigned members.'
                    ];
                }
            }

            $delete = $this->conn->prepare(
                "DELETE FROM case_assignments
                 WHERE case_id = ?
                   AND assignment_role IN ('Head', 'Secretary', 'Member')"
            );
            $delete->execute([$caseId]);

            $insert = $this->conn->prepare(
                'INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date)
                 VALUES (?, ?, ?, CURDATE())'
            );

            $team = [
                'Head' => (int) $headId,
                'Secretary' => (int) $secretaryId
            ];
            if ($memberId) {
                $team['Member'] = (int) $memberId;
            }

            foreach ($team as $assignmentRole => $teamMemberId) {
                $insert->execute([$caseId, $teamMemberId, $assignmentRole]);
            }

            // Sync Pangkat record with selection method and quorum details
            $group = $this->conn->prepare(
                'INSERT INTO pangkat_groups (case_id, formation_date, selection_method, selection_notes, quorum_size, quorum_exception_reason) 
                 VALUES (?, CURDATE(), ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE 
                    formation_date = VALUES(formation_date),
                    selection_method = VALUES(selection_method),
                    selection_notes = VALUES(selection_notes),
                    quorum_size = VALUES(quorum_size),
                    quorum_exception_reason = VALUES(quorum_exception_reason),
                    pangkat_id = LAST_INSERT_ID(pangkat_id)'
            );
            $group->execute([$caseId, $selectionMethod, $selectionNotes ?: null, $quorumSize, $quorumReason ?: null]);
            $pangkatId = (int) $this->conn->lastInsertId();

            $this->conn->prepare('DELETE FROM pangkat_members WHERE pangkat_id = ?')->execute([$pangkatId]);
            $memberInsert = $this->conn->prepare('INSERT INTO pangkat_members (pangkat_id, member_id, position) VALUES (?, ?, ?)');
            $positions = [
                'Head' => 'Chairman',
                'Secretary' => 'Secretary',
                'Member' => 'Member'
            ];
            foreach ($team as $assignmentRole => $teamMemberId) {
                $memberInsert->execute([$pangkatId, $teamMemberId, $positions[$assignmentRole]]);
            }

            if ($case['case_status'] === 'Mediation') {
                $statusUpdate = $this->conn->prepare("UPDATE cases SET case_status = 'Conciliation' WHERE case_id = ?");
                $statusUpdate->execute([$caseId]);
                $complaintStatus = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Conciliation' WHERE c.case_id = ?");
                $complaintStatus->execute([$caseId]);
                $history = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Conciliation', ?, ?)");
                $history->execute([$caseId, "Case moved to conciliation with assigned {$quorumSize}-member Lupon team (Method: {$selectionMethod}).", $_SESSION['user_id'] ?? null]);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => $case['case_status'] === 'Mediation'
                    ? "{$quorumSize}-member Lupon case team saved. Case transitioned to Conciliation."
                    : "{$quorumSize}-member Lupon case team saved successfully."
            ];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log($exception->getMessage());
            return [
                'success' => false,
                'message' => 'Unable to save the case team.'
            ];
        }
    }

    /**
     * Returns every assignment for one case.
     */
    public function getByCase(int $caseId): array
    {
        if ($caseId < 1) {
            return [];
        }

        $stmt = $this->conn->prepare(
            "SELECT
                ca.assignment_id,
                ca.case_id,
                ca.member_id,
                ca.assignment_role,
                ca.assigned_date,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.username,
                r.role_name,
                latest_h.hearing_id,
                latest_h.substitute_presider_id,
                latest_h.substitute_reason,
                latest_h.parties_consent_to_substitute,
                sub_u.first_name AS substitute_first_name,
                sub_u.middle_name AS substitute_middle_name,
                sub_u.last_name AS substitute_last_name
             FROM case_assignments ca
             INNER JOIN users u ON u.user_id = ca.member_id
             INNER JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN (
                 SELECT h1.case_id, h1.hearing_id, h1.substitute_presider_id, h1.substitute_reason, h1.parties_consent_to_substitute
                 FROM hearings h1
                 WHERE h1.case_id = ?
                 ORDER BY (h1.substitute_presider_id IS NOT NULL) DESC, (h1.status = 'Scheduled') DESC, h1.hearing_date DESC, h1.hearing_id DESC
                 LIMIT 1
             ) latest_h ON latest_h.case_id = ca.case_id
             LEFT JOIN users sub_u ON sub_u.user_id = latest_h.substitute_presider_id
             WHERE ca.case_id = ?
             ORDER BY
                FIELD(ca.assignment_role, 'Head', 'Secretary', 'Member', 'Mediator'),
                ca.assigned_date,
                ca.assignment_id"
        );

        $stmt->execute([$caseId, $caseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alias for getByCase() to maintain compatibility with legacy callers.
     */
    public function getAssignments(int $caseId): array
    {
        return $this->getByCase($caseId);
    }

    /**
     * Returns active Lupon Member accounts eligible for the
     * normal Head, Secretary, and Member dropdowns.
     */
    public function getLuponMembers(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT
                u.user_id AS member_id,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.username,
                r.role_name
             FROM users u
             INNER JOIN roles r
                ON r.role_id = u.role_id
             WHERE u.status = 'Active'
               AND r.role_name = 'Lupon Member'
             ORDER BY
                u.last_name,
                u.first_name,
                u.middle_name,
                u.user_id"
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns the existing active Administrator.
     * In AGAP, the Administrator represents the Barangay Captain and Lupon Head.
     */
    public function getLuponHead(): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT
                u.user_id AS member_id,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.username,
                r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'Active'
               AND r.role_name = 'Administrator'
             ORDER BY u.user_id ASC
             LIMIT 1"
        );
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Ensures an existing Mediation case has the active Administrator assigned as Head.
     * This also repairs Mediation cases created before automatic Head assignment was implemented.
     */
    public function ensureMediationHead(int $caseId): array
    {
        if ($caseId < 1) {
            return ['success' => false, 'message' => 'A valid case is required.'];
        }

        $case = $this->getCase($caseId);
        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        if (!in_array($case['case_status'], ['Docketed', 'Mediation'], true)) {
            return [
                'success' => true,
                'message' => 'Automatic Mediation Head assignment is not required.'
            ];
        }

        $administrator = $this->getLuponHead();
        if (!$administrator) {
            return [
                'success' => false,
                'message' => 'The active Administrator or Barangay Captain account could not be found.'
            ];
        }

        $administratorId = (int) $administrator['member_id'];

        try {
            $this->conn->beginTransaction();

            // Remove any existing Head who is not the active Administrator.
            $deleteExistingHead = $this->conn->prepare(
                "DELETE FROM case_assignments
                 WHERE case_id = ?
                   AND assignment_role = 'Head'
                   AND member_id <> ?"
            );
            $deleteExistingHead->execute([$caseId, $administratorId]);

            $existing = $this->conn->prepare(
                "SELECT assignment_id
                 FROM case_assignments
                 WHERE case_id = ?
                   AND member_id = ?
                   AND assignment_role = 'Head'
                 LIMIT 1"
            );
            $existing->execute([$caseId, $administratorId]);

            if (!$existing->fetchColumn()) {
                $insert = $this->conn->prepare(
                    "INSERT INTO case_assignments (
                        case_id,
                        member_id,
                        assignment_role,
                        assigned_date
                     )
                     VALUES (?, ?, 'Head', CURDATE())"
                );
                $insert->execute([$caseId, $administratorId]);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'The Administrator or Barangay Captain is assigned as the Mediation Head.',
                'head' => $administrator
            ];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log($exception->getMessage());

            return [
                'success' => false,
                'message' => 'Unable to assign the automatic Mediation Head.'
            ];
        }
    }

    /**
     * Returns the case status needed for assignment rules.
     */
    public function getCase(int $caseId): array|false
    {
        $stmt = $this->conn->prepare(
            'SELECT case_id, case_status FROM cases WHERE case_id = ? LIMIT 1'
        );
        $stmt->execute([$caseId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function caseExists(int $caseId): bool
    {
        $stmt = $this->conn->prepare("SELECT 1 FROM cases WHERE case_id = ? AND case_status <> 'Archived'");
        $stmt->execute([$caseId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Checks eligibility for normal Lupon team roles.
     */
    public function isEligibleMember(int $memberId): bool
    {
        if ($memberId < 1) {
            return false;
        }

        $stmt = $this->conn->prepare(
            "SELECT 1 FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ? AND u.status = 'Active' AND r.role_name = 'Lupon Member'
             LIMIT 1"
        );
        $stmt->execute([$memberId]);

        return (bool) $stmt->fetchColumn();
    }

    public function isActiveLuponMember(int $memberId): bool
    {
        return $this->isEligibleMember($memberId);
    }

    /**
     * Checks whether the selected user is the active Administrator allowed to serve as Mediation Head.
     */
    public function isEligibleMediationHead(int $memberId): bool
    {
        if ($memberId < 1) {
            return false;
        }

        $stmt = $this->conn->prepare(
            "SELECT 1 FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ? AND u.status = 'Active' AND r.role_name = 'Administrator'
             LIMIT 1"
        );
        $stmt->execute([$memberId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Batch validation for multiple cases to check if each has a valid 3-member Lupon team for Conciliation.
     * Requirements:
     * 1. Exactly 3 distinct members for Head, Secretary, and Member.
     * 2. All 3 must be active users with role name "Lupon Member".
     * 3. The Administrator is for Mediation only and cannot be on the Conciliation team.
     *
     * @param array $caseIds
     * @return array [case_id => ['valid' => bool, 'message' => string, 'members' => array]]
     */
    public function validateConciliationTeams(array $caseIds): array
    {
        $caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds), fn($id) => $id > 0)));
        if (empty($caseIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = $this->conn->prepare(
            "SELECT
                ca.assignment_id,
                ca.case_id,
                ca.assignment_role,
                ca.member_id,
                u.status AS user_status,
                r.role_name,
                CONCAT_WS(' ', u.first_name, u.last_name) AS member_name
             FROM case_assignments ca
             INNER JOIN users u ON u.user_id = ca.member_id
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE ca.case_id IN ($placeholders)
               AND ca.assignment_role IN ('Head', 'Secretary', 'Member')"
        );
        $stmt->execute($caseIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pangkatStmt = $this->conn->prepare("SELECT case_id, quorum_size FROM pangkat_groups WHERE case_id IN ($placeholders)");
        $pangkatStmt->execute($caseIds);
        $pangkatQuorums = [];
        foreach ($pangkatStmt->fetchAll(PDO::FETCH_ASSOC) as $pRow) {
            $pangkatQuorums[(int) $pRow['case_id']] = (int) $pRow['quorum_size'];
        }

        $grouped = [];
        foreach ($caseIds as $cid) {
            $grouped[$cid] = [];
        }
        foreach ($rows as $row) {
            $grouped[(int) $row['case_id']][] = $row;
        }

        $results = [];
        foreach ($grouped as $cid => $assignments) {
            if (empty($assignments)) {
                $results[$cid] = [
                    'valid' => false,
                    'message' => 'No Lupon team has been chosen yet. Before you can book a conciliation hearing, you must choose your Lupon team on Case Team Assignment first.',
                    'members' => []
                ];
                continue;
            }

            $assignedRoles = [];
            $memberIds = [];
            $hasAdmin = false;
            $hasInactive = false;
            $nonLuponMember = false;

            foreach ($assignments as $a) {
                $role = $a['assignment_role'];
                $assignedRoles[$role] = $a;
                $memberIds[] = (int) $a['member_id'];

                if ($a['role_name'] === 'Administrator') {
                    $hasAdmin = true;
                } elseif ($a['role_name'] !== 'Lupon Member') {
                    $nonLuponMember = true;
                }

                if ($a['user_status'] !== 'Active') {
                    $hasInactive = true;
                }
            }

            if ($hasAdmin) {
                $results[$cid] = [
                    'valid' => false,
                    'message' => 'The Administrator is for Mediation only. When a complaint goes to Conciliation, you must choose a Lupon team (Head, Secretary, Member) of active Lupon Members on Case Team Assignment before booking a conciliation hearing.',
                    'members' => $assignments
                ];
                continue;
            }

            $isQuorumTwo = (isset($pangkatQuorums[$cid]) && $pangkatQuorums[$cid] === 2);
            $requiredRoles = $isQuorumTwo ? ['Head', 'Secretary'] : ['Head', 'Secretary', 'Member'];
            $expectedCount = $isQuorumTwo ? 2 : 3;

            $missingRoles = [];
            foreach ($requiredRoles as $reqRole) {
                if (!isset($assignedRoles[$reqRole])) {
                    $missingRoles[] = $reqRole;
                }
            }

            if (!empty($missingRoles)) {
                $results[$cid] = [
                    'valid' => false,
                    'message' => 'Incomplete case team: ' . implode(', ', $missingRoles) . " missing. A complete {$expectedCount}-member Lupon team must be chosen on Case Team Assignment before booking a conciliation hearing.",
                    'members' => $assignments
                ];
                continue;
            }

            if (count(array_unique($memberIds)) < $expectedCount) {
                $results[$cid] = [
                    'valid' => false,
                    'message' => 'Assigned Lupon team members must be different active Lupon Members.',
                    'members' => $assignments
                ];
                continue;
            }

            if ($nonLuponMember || $hasInactive) {
                $results[$cid] = [
                    'valid' => false,
                    'message' => 'All three assigned team members (Head, Secretary, Member) must be active Lupon Members.',
                    'members' => $assignments
                ];
                continue;
            }

            $results[$cid] = [
                'valid' => true,
                'message' => 'Valid Conciliation Lupon team assigned.',
                'members' => $assignments
            ];
        }

        return $results;
    }

    /**
     * Checks whether a single case has a valid 3-member Lupon team for Conciliation.
     *
     * @param int $caseId
     * @return array ['valid' => bool, 'message' => string, 'members' => array]
     */
    public function validateConciliationTeam(int $caseId): array
    {
        if ($caseId < 1) {
            return [
                'valid' => false,
                'message' => 'A valid case is required.',
                'members' => []
            ];
        }

        $results = $this->validateConciliationTeams([$caseId]);
        return $results[$caseId] ?? [
            'valid' => false,
            'message' => 'Case team not found.',
            'members' => []
        ];
    }
}