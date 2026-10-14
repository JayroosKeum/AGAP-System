<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/MediationDeadlineService.php';
require_once __DIR__ . '/NotificationService.php';

class PangkatService
{
    private PDO $conn;
    private AuditService $audit;
    private MediationDeadlineService $deadlines;
    private NotificationService $notifications;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->audit = new AuditService();
        $this->deadlines = new MediationDeadlineService($this->conn);
        $this->notifications = new NotificationService();
    }

    /**
     * Gets comprehensive Pangkat stage status for a case.
     */
    public function getPangkatOverview(int $caseId): array
    {
        $status = $this->deadlines->computeConciliationStatus($caseId);
        if (!$status['is_constituted']) {
            return $status;
        }

        $pangkatId = $status['pangkat_id'];

        // Fetch active members
        $memStmt = $this->conn->prepare("
            SELECT pm.pangkat_member_id, pm.member_id, pm.position, pm.status,
                   pm.disqualified_at, pm.disqualification_reason, pm.replacement_for_member_id,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS member_name,
                   u.email, u.contact_no AS contact_number
            FROM pangkat_members pm
            INNER JOIN users u ON u.user_id = pm.member_id
            WHERE pm.pangkat_id = ?
            ORDER BY FIELD(pm.position, 'Chairman', 'Secretary', 'Member'), pm.pangkat_member_id ASC
        ");
        $memStmt->execute([$pangkatId]);
        $allMembers = $memStmt->fetchAll(PDO::FETCH_ASSOC);

        $activeMembers = [];
        $disqualifiedMembers = [];
        $chairperson = null;
        $secretary = null;
        $regularMember = null;

        foreach ($allMembers as $m) {
            if ($m['status'] === 'Active' || $m['status'] === 'Challenged') {
                $activeMembers[] = $m;
                if ($m['position'] === 'Chairman' && !$chairperson) $chairperson = $m;
                elseif ($m['position'] === 'Secretary' && !$secretary) $secretary = $m;
                elseif (!$regularMember) $regularMember = $m;
            } else {
                $disqualifiedMembers[] = $m;
            }
        }

        // Fetch extensions
        $extStmt = $this->conn->prepare("
            SELECT pe.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS approver_name
            FROM pangkat_extensions pe
            INNER JOIN users u ON u.user_id = pe.approved_by
            WHERE pe.case_id = ?
            ORDER BY pe.extension_id DESC
        ");
        $extStmt->execute([$caseId]);
        $extensions = $extStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch challenges
        $chalStmt = $this->conn->prepare("
            SELECT pc.*,
                   TRIM(CONCAT_WS(' ', u_ch.first_name, u_ch.middle_name, u_ch.last_name)) AS challenged_name,
                   TRIM(CONCAT_WS(' ', u_rep.first_name, u_rep.middle_name, u_rep.last_name)) AS replacement_name,
                   TRIM(CONCAT_WS(' ', u_res.first_name, u_res.middle_name, u_res.last_name)) AS resolver_name
            FROM pangkat_challenges pc
            INNER JOIN users u_ch ON u_ch.user_id = pc.challenged_member_id
            LEFT JOIN users u_rep ON u_rep.user_id = pc.replacement_member_id
            LEFT JOIN users u_res ON u_res.user_id = pc.resolved_by_user_id
            WHERE pc.case_id = ?
            ORDER BY pc.challenge_id DESC
        ");
        $chalStmt->execute([$caseId]);
        $challenges = $chalStmt->fetchAll(PDO::FETCH_ASSOC);

        // Conciliation sessions count
        $sessStmt = $this->conn->prepare("
            SELECT COUNT(*) FROM hearings 
            WHERE case_id = ? AND hearing_type = 'Conciliation' AND status != 'Cancelled'
        ");
        $sessStmt->execute([$caseId]);
        $sessionCount = (int) $sessStmt->fetchColumn();

        return array_merge($status, [
            'active_members' => $activeMembers,
            'disqualified_members' => $disqualifiedMembers,
            'chairperson' => $chairperson,
            'secretary' => $secretary,
            'member' => $regularMember,
            'extensions' => $extensions,
            'challenges' => $challenges,
            'sessions_count' => $sessionCount
        ]);
    }

    /**
     * Records Pangkat selection process (Party choice, or failed agreement leading to PB lots/appointment).
     * LEGAL BASIS: RA 7160 Section 404(a)-(b)
     */
    public function recordSelectionProcess(int $caseId, array $data, int $userId): array
    {
        $this->conn->beginTransaction();
        try {
            $selectionStage = $data['selection_stage'] ?? 'Constituted';
            $selectionMethod = $data['selection_method'] ?? 'Party Agreement';
            $partyAgreementFailed = !empty($data['party_agreement_failed']) ? 1 : 0;
            $failureReason = trim((string) ($data['party_agreement_failure_reason'] ?? ''));
            $selectionNotes = trim((string) ($data['selection_notes'] ?? ''));
            $formationDate = !empty($data['formation_date']) ? date('Y-m-d', strtotime($data['formation_date'])) : date('Y-m-d');
            $conveningDeadline = MediationDeadlineService::calculateDeadline($formationDate, 3);

            $chairmanId = !empty($data['chairman_id']) ? (int) $data['chairman_id'] : null;
            $secretaryId = !empty($data['secretary_id']) ? (int) $data['secretary_id'] : null;
            $memberId = !empty($data['member_id']) ? (int) $data['member_id'] : null;

            if (!$chairmanId || !$secretaryId || !$memberId) {
                throw new InvalidArgumentException('Three (3) distinct Lupon members must be selected for the Pangkat panel.');
            }
            if ($chairmanId === $secretaryId || $chairmanId === $memberId || $secretaryId === $memberId) {
                throw new InvalidArgumentException('Pangkat panel members must all be distinct individuals.');
            }

            // Verify all 3 are active Lupon Members
            $checkStmt = $this->conn->prepare("
                SELECT u.user_id, r.role_name
                FROM users u
                INNER JOIN roles r ON r.role_id = u.role_id
                WHERE u.user_id IN (?, ?, ?) AND u.status = 'Active' AND r.role_name = 'Lupon Member'
            ");
            $checkStmt->execute([$chairmanId, $secretaryId, $memberId]);
            if (count($checkStmt->fetchAll()) < 3) {
                throw new InvalidArgumentException('All Pangkat members must be active users with the role "Lupon Member".');
            }

            // Upsert pangkat_groups
            $pgStmt = $this->conn->prepare("
                INSERT INTO pangkat_groups (
                    case_id, formation_date, convening_deadline, selection_method, selection_notes,
                    selection_stage, party_agreement_failed, party_agreement_failure_reason, quorum_size
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 3)
                ON DUPLICATE KEY UPDATE
                    formation_date = VALUES(formation_date),
                    convening_deadline = VALUES(convening_deadline),
                    selection_method = VALUES(selection_method),
                    selection_notes = VALUES(selection_notes),
                    selection_stage = VALUES(selection_stage),
                    party_agreement_failed = VALUES(party_agreement_failed),
                    party_agreement_failure_reason = VALUES(party_agreement_failure_reason),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $pgStmt->execute([
                $caseId,
                $formationDate,
                $conveningDeadline,
                $selectionMethod,
                $selectionNotes ?: null,
                $selectionStage,
                $partyAgreementFailed,
                $failureReason ?: null
            ]);

            $idStmt = $this->conn->prepare("SELECT pangkat_id FROM pangkat_groups WHERE case_id = ?");
            $idStmt->execute([$caseId]);
            $pangkatId = (int) $idStmt->fetchColumn();

            // Re-assign members
            $this->conn->prepare("DELETE FROM pangkat_members WHERE pangkat_id = ?")->execute([$pangkatId]);
            $insMem = $this->conn->prepare("
                INSERT INTO pangkat_members (pangkat_id, member_id, position, status)
                VALUES (?, ?, ?, 'Active')
            ");
            $insMem->execute([$pangkatId, $chairmanId, 'Chairman']);
            $insMem->execute([$pangkatId, $secretaryId, 'Secretary']);
            $insMem->execute([$pangkatId, $memberId, 'Member']);

            // Sync case_assignments
            $this->conn->prepare("DELETE FROM case_assignments WHERE case_id = ? AND assignment_role IN ('Head', 'Secretary', 'Member')")->execute([$caseId]);
            $insCa = $this->conn->prepare("INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date) VALUES (?, ?, ?, CURDATE())");
            $insCa->execute([$caseId, $chairmanId, 'Head']);
            $insCa->execute([$caseId, $secretaryId, 'Secretary']);
            $insCa->execute([$caseId, $memberId, 'Member']);

            // Upsert 3-day convening deadline
            $dlStmt = $this->conn->prepare("
                INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                VALUES (?, 'Pangkat Convening', ?, 'Pending')
                ON DUPLICATE KEY UPDATE
                    due_date = VALUES(due_date),
                    status = IF(status = 'Completed', 'Completed', 'Pending'),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $dlStmt->execute([$caseId, $conveningDeadline]);

            // History & Audit
            $histReason = $partyAgreementFailed
                ? "Pangkat constituted via {$selectionMethod} following failed party agreement (Reason: {$failureReason})."
                : "Pangkat constituted via {$selectionMethod}. 3-day convening deadline set to {$conveningDeadline}.";

            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Conciliation', ?, ?)");
            $hist->execute([$caseId, $histReason, $userId]);

            $this->audit->log($userId, "Constituted Pangkat via {$selectionMethod}", 'Pangkat', $pangkatId);
            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Pangkat panel successfully constituted. 3-day convening deadline is ' . date('F j, Y', strtotime($conveningDeadline)) . '.',
                'pangkat_id' => $pangkatId,
                'convening_deadline' => $conveningDeadline
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Grants a controlled extension for the conciliation period.
     * Discretionary: Up to 15 calendar days (cumulative limit 15 days).
     * Clearly Meritorious: Discretionary extension beyond 15 days with statutory justification.
     *
     * LEGAL BASIS: RA 7160 Section 410(c)
     */
    public function grantExtension(int $caseId, array $data, int $userId): array
    {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("SELECT * FROM pangkat_groups WHERE case_id = ? LIMIT 1");
            $stmt->execute([$caseId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$group) {
                throw new InvalidArgumentException('Pangkat has not been constituted for this case.');
            }

            $pangkatId = (int) $group['pangkat_id'];
            $extensionType = in_array($data['extension_type'] ?? '', ['Discretionary', 'Clearly Meritorious'], true)
                ? $data['extension_type']
                : 'Discretionary';

            $daysRequested = (int) ($data['days_extended'] ?? 15);
            if ($daysRequested < 1) {
                throw new InvalidArgumentException('Extension period must be at least 1 day.');
            }

            $currentExtendedDays = (int) ($group['extended_days'] ?? 0);

            if ($extensionType === 'Discretionary') {
                if ($daysRequested > 15) {
                    throw new InvalidArgumentException('Discretionary extension cannot exceed fifteen (15) calendar days under RA 7160 Sec. 410(c). For longer periods, a Clearly Meritorious exception is required.');
                }
                if ($currentExtendedDays + $daysRequested > 15) {
                    throw new InvalidArgumentException("Cumulative discretionary extension cannot exceed 15 days (already used {$currentExtendedDays} days). Submit a Clearly Meritorious exception workflow for additional extension.");
                }
            } else { // Clearly Meritorious
                $meritoriousBasis = trim((string) ($data['meritorious_basis'] ?? ''));
                if (mb_strlen($meritoriousBasis) < 20) {
                    throw new InvalidArgumentException('A detailed statutory explanation of at least 20 characters is required for a Clearly Meritorious extension under RA 7160 Sec. 410(c).');
                }
            }

            $justification = trim((string) ($data['justification'] ?? ''));
            if (mb_strlen($justification) < 10) {
                throw new InvalidArgumentException('Please provide a justification of at least 10 characters explaining why the Pangkat needs an extension.');
            }

            // Determine previous deadline
            $previousDeadline = $group['conciliation_deadline'];
            if (!$previousDeadline) {
                // If not convened yet, calculate based on formation date
                $baseDate = $group['convened_date'] ?: ($group['formation_date'] ?: date('Y-m-d'));
                $previousDeadline = MediationDeadlineService::calculateDeadline($baseDate, 15);
            }

            $newDeadline = MediationDeadlineService::calculateDeadline($previousDeadline, $daysRequested);

            // Record extension
            $insExt = $this->conn->prepare("
                INSERT INTO pangkat_extensions (
                    pangkat_id, case_id, extension_type, days_extended,
                    previous_deadline, new_deadline, justification, meritorious_basis,
                    approved_by, granted_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insExt->execute([
                $pangkatId,
                $caseId,
                $extensionType,
                $daysRequested,
                $previousDeadline,
                $newDeadline,
                $justification,
                $data['meritorious_basis'] ?? null,
                $userId
            ]);
            $extensionId = (int) $this->conn->lastInsertId();

            // Update pangkat_groups
            $updGroup = $this->conn->prepare("
                UPDATE pangkat_groups SET
                    is_extended = 1,
                    extended_days = extended_days + ?,
                    extension_type = ?,
                    conciliation_deadline = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE pangkat_id = ?
            ");
            $updGroup->execute([$daysRequested, $extensionType, $newDeadline, $pangkatId]);

            // Update case_deadlines
            $updDl = $this->conn->prepare("
                INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                VALUES (?, 'Conciliation Period', ?, 'Pending')
                ON DUPLICATE KEY UPDATE
                    due_date = VALUES(due_date),
                    status = 'Pending',
                    updated_at = CURRENT_TIMESTAMP
            ");
            $updDl->execute([$caseId, $newDeadline]);

            // Case history
            $extLabel = ($extensionType === 'Clearly Meritorious')
                ? "Clearly Meritorious Extension granted (+{$daysRequested} days to {$newDeadline}). Basis: " . ($data['meritorious_basis'] ?? '')
                : "Discretionary Pangkat extension granted (+{$daysRequested} days to {$newDeadline}). Justification: {$justification}";

            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Conciliation', ?, ?)");
            $hist->execute([$caseId, $extLabel, $userId]);

            $this->audit->log($userId, "Granted {$extensionType} conciliation extension (+{$daysRequested} days)", 'Pangkat', $pangkatId);
            $this->conn->commit();

            return [
                'success' => true,
                'message' => "Conciliation deadline extended by {$daysRequested} calendar day(s) until " . date('F j, Y', strtotime($newDeadline)) . " ({$extensionType}).",
                'extension_id' => $extensionId,
                'previous_deadline' => $previousDeadline,
                'new_deadline' => $newDeadline,
                'days_extended' => $daysRequested,
                'extension_type' => $extensionType
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Files a formal challenge against a Pangkat member.
     * LEGAL BASIS: RA 7160 Section 404(d)
     */
    public function fileChallenge(int $caseId, array $data, int $userId): array
    {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("SELECT pangkat_id FROM pangkat_groups WHERE case_id = ? LIMIT 1");
            $stmt->execute([$caseId]);
            $pangkatId = (int) $stmt->fetchColumn();

            if (!$pangkatId) {
                throw new InvalidArgumentException('Pangkat has not been constituted for this case.');
            }

            $challengedMemberId = filter_var($data['challenged_member_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$challengedMemberId) {
                throw new InvalidArgumentException('Please select the Pangkat member being challenged.');
            }

            // Verify member belongs to this Pangkat
            $memCheck = $this->conn->prepare("
                SELECT pangkat_member_id, status FROM pangkat_members 
                WHERE pangkat_id = ? AND member_id = ? AND status IN ('Active', 'Challenged')
            ");
            $memCheck->execute([$pangkatId, $challengedMemberId]);
            $existingMem = $memCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existingMem) {
                throw new InvalidArgumentException('The selected member is not an active member of this Pangkat panel.');
            }

            $challengerParty = in_array($data['challenger_party'] ?? '', ['Complainant', 'Respondent'], true)
                ? $data['challenger_party']
                : 'Complainant';

            $challengerName = trim((string) ($data['challenger_name'] ?? ''));

            $grounds = in_array($data['grounds'] ?? '', [
                'Relationship within 4th degree',
                'Financial or Personal Interest',
                'Bias or Partiality',
                'Other'
            ], true) ? $data['grounds'] : 'Bias or Partiality';

            $groundDetails = trim((string) ($data['ground_details'] ?? ''));
            if (mb_strlen($groundDetails) < 15) {
                throw new InvalidArgumentException('Detailed particulars of the challenge of at least 15 characters are required.');
            }

            $challengeDate = !empty($data['challenge_date']) ? date('Y-m-d', strtotime($data['challenge_date'])) : date('Y-m-d');

            $insChal = $this->conn->prepare("
                INSERT INTO pangkat_challenges (
                    pangkat_id, case_id, challenged_member_id, challenger_party,
                    challenger_name, grounds, ground_details, challenge_date, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $insChal->execute([
                $pangkatId,
                $caseId,
                $challengedMemberId,
                $challengerParty,
                $challengerName ?: null,
                $grounds,
                $groundDetails,
                $challengeDate
            ]);
            $challengeId = (int) $this->conn->lastInsertId();

            // Mark member as Challenged
            $updMem = $this->conn->prepare("UPDATE pangkat_members SET status = 'Challenged' WHERE pangkat_member_id = ?");
            $updMem->execute([(int) $existingMem['pangkat_member_id']]);

            // History
            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Conciliation', ?, ?)");
            $hist->execute([
                $caseId,
                "Formal challenge filed by {$challengerParty} against Pangkat member on grounds of {$grounds}. Awaiting majority resolution.",
                $userId
            ]);

            $this->audit->log($userId, "Filed challenge against Pangkat member #{$challengedMemberId}", 'Pangkat', $pangkatId);
            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Formal challenge has been recorded. Pursuant to RA 7160 Sec. 404(d), the challenge must be resolved by the majority of the Pangkat.',
                'challenge_id' => $challengeId
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Resolves a challenge by majority vote of the Pangkat.
     * If Sustained: Disqualifies member and immediately appoints/draws replacement.
     * If Dismissed: Restores member to Active status.
     *
     * LEGAL BASIS: RA 7160 Section 404(c)-(d)
     */
    public function resolveChallenge(int $challengeId, array $data, int $userId): array
    {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("
                SELECT pc.*, pg.case_id, pg.pangkat_id, pm.position AS member_position
                FROM pangkat_challenges pc
                INNER JOIN pangkat_groups pg ON pg.pangkat_id = pc.pangkat_id
                INNER JOIN pangkat_members pm ON pm.pangkat_id = pc.pangkat_id AND pm.member_id = pc.challenged_member_id
                WHERE pc.challenge_id = ?
                LIMIT 1
            ");
            $stmt->execute([$challengeId]);
            $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$challenge) {
                throw new InvalidArgumentException('Challenge record not found.');
            }

            if ($challenge['status'] !== 'Pending') {
                throw new InvalidArgumentException('This challenge has already been resolved.');
            }

            $decision = in_array($data['majority_decision'] ?? '', ['Disqualify and Replace', 'Dismiss Challenge'], true)
                ? $data['majority_decision']
                : 'Dismiss Challenge';

            $notes = trim((string) ($data['majority_decision_notes'] ?? ''));
            $votingRecord = trim((string) ($data['voting_record'] ?? 'Majority vote of panel'));
            $resolutionDate = !empty($data['resolution_date']) ? date('Y-m-d', strtotime($data['resolution_date'])) : date('Y-m-d');

            $pangkatId = (int) $challenge['pangkat_id'];
            $caseId = (int) $challenge['case_id'];
            $challengedMemberId = (int) $challenge['challenged_member_id'];
            $memberPosition = $challenge['member_position'];

            $replacementMemberId = null;
            $replacementMethod = null;

            if ($decision === 'Disqualify and Replace') {
                $replacementMemberId = filter_var($data['replacement_member_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$replacementMemberId) {
                    throw new InvalidArgumentException('A replacement Lupon member must be designated when a challenge is sustained.');
                }
                if ($replacementMemberId === $challengedMemberId) {
                    throw new InvalidArgumentException('Replacement member cannot be the disqualified member.');
                }

                // Verify replacement is active Lupon Member and not already in this Pangkat
                $checkRep = $this->conn->prepare("
                    SELECT u.user_id 
                    FROM users u 
                    INNER JOIN roles r ON r.role_id = u.role_id 
                    WHERE u.user_id = ? AND u.status = 'Active' AND r.role_name = 'Lupon Member'
                ");
                $checkRep->execute([$replacementMemberId]);
                if (!$checkRep->fetch()) {
                    throw new InvalidArgumentException('The replacement must be an active Lupon Member.');
                }

                $activeCheck = $this->conn->prepare("
                    SELECT pangkat_member_id FROM pangkat_members 
                    WHERE pangkat_id = ? AND member_id = ? AND status = 'Active'
                ");
                $activeCheck->execute([$pangkatId, $replacementMemberId]);
                if ($activeCheck->fetch()) {
                    throw new InvalidArgumentException('The replacement member is already an active member of this Pangkat.');
                }

                $replacementMethod = in_array($data['replacement_method'] ?? '', ['Party Agreement', 'Drawing of Lots', 'PB Appointment'], true)
                    ? $data['replacement_method']
                    : 'Drawing of Lots';

                // Disqualify old member
                $disq = $this->conn->prepare("
                    UPDATE pangkat_members SET
                        status = 'Disqualified',
                        disqualified_at = NOW(),
                        disqualification_reason = ?
                    WHERE pangkat_id = ? AND member_id = ?
                ");
                $disq->execute([$notes ?: "Disqualified via majority decision on challenge #{$challengeId}", $pangkatId, $challengedMemberId]);

                // Insert replacement member
                $insRep = $this->conn->prepare("
                    INSERT INTO pangkat_members (
                        pangkat_id, member_id, position, status, replacement_for_member_id
                    ) VALUES (?, ?, ?, 'Active', ?)
                ");
                $insRep->execute([$pangkatId, $replacementMemberId, $memberPosition, $challengedMemberId]);

                // Update case_assignments
                $roleName = $memberPosition === 'Chairman' ? 'Head' : $memberPosition;
                $updCa = $this->conn->prepare("
                    UPDATE case_assignments SET member_id = ?, assigned_date = CURDATE()
                    WHERE case_id = ? AND assignment_role = ?
                ");
                $updCa->execute([$replacementMemberId, $caseId, $roleName]);

                $chalStatus = 'Sustained';
                $histRemarks = "Challenge #{$challengeId} SUSTAINED by Pangkat majority. Member disqualified. Replacement member appointed via {$replacementMethod}. Notes: {$notes}";
            } else {
                // Restore member to Active
                $rest = $this->conn->prepare("
                    UPDATE pangkat_members SET status = 'Active'
                    WHERE pangkat_id = ? AND member_id = ?
                ");
                $rest->execute([$pangkatId, $challengedMemberId]);

                $chalStatus = 'Dismissed';
                $histRemarks = "Challenge #{$challengeId} DISMISSED by Pangkat majority. Member retained. Notes: {$notes}";
            }

            // Update pangkat_challenges
            $updChal = $this->conn->prepare("
                UPDATE pangkat_challenges SET
                    status = ?,
                    resolution_date = ?,
                    majority_decision = ?,
                    majority_decision_notes = ?,
                    voting_record = ?,
                    replacement_member_id = ?,
                    replacement_method = ?,
                    replacement_date = IF(? IS NOT NULL, CURDATE(), NULL),
                    resolved_by_user_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE challenge_id = ?
            ");
            $updChal->execute([
                $chalStatus,
                $resolutionDate,
                $decision,
                $notes ?: null,
                $votingRecord,
                $replacementMemberId,
                $replacementMethod,
                $replacementMemberId,
                $userId,
                $challengeId
            ]);

            // Case history
            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Conciliation', ?, ?)");
            $hist->execute([$caseId, $histRemarks, $userId]);

            $this->audit->log($userId, "Resolved challenge #{$challengeId}: {$chalStatus}", 'Pangkat', $pangkatId);
            $this->conn->commit();

            return [
                'success' => true,
                'message' => "Challenge has been resolved as {$chalStatus}. " . ($decision === 'Disqualify and Replace' ? 'Replacement member successfully seated.' : 'Member retained on panel.'),
                'challenge_id' => $challengeId,
                'status' => $chalStatus,
                'decision' => $decision
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
