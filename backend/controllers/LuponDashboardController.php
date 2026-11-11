<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class LuponDashboardController
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
        $this->conn->exec("CREATE TABLE IF NOT EXISTS lupon_duty_schedules (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, duty_day VARCHAR(20) NOT NULL DEFAULT 'Wednesday', start_time TIME DEFAULT '08:00:00', end_time TIME DEFAULT '17:00:00', notes VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    }

    /**
     * Check if a user is an Admin (1) or Clerk (2) overseeing the Lupon dashboard.
     */
    private function isOversightRole(int $userId): bool
    {
        $stmt = $this->conn->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $roleId = (int) $stmt->fetchColumn();
        return in_array($roleId, [1, 2], true);
    }

    /**
     * Retrieve complete dashboard payload for a Lupon Member.
     */
    public function getDashboardData(int $userId): array
    {
        // 1. Get Member Duty Day & Schedule
        $dutySchedule = $this->getMemberDutySchedule($userId);
        $dutyDay = $dutySchedule['duty_day'] ?? 'Wednesday';

        // 2. Member's Assigned Pangkat Cases (Active)
        $assignedCases = $this->getAssignedPangkatCases($userId);

        // 3. Duty Day Hearings (defaults to member's duty day or active assigned pangkat sessions)
        $dutyHearings = $this->getDutyHearings($userId, $dutyDay);

        // 4. KPIs for Senior-Friendly Top Cards
        $totalAssigned = count($assignedCases);
        $chairpersonCount = count(array_filter($assignedCases, fn($c) => ($c['my_role'] ?? '') === 'Chairman'));
        $secretaryCount = count(array_filter($assignedCases, fn($c) => ($c['my_role'] ?? '') === 'Secretary'));
        $memberCount = count(array_filter($assignedCases, fn($c) => ($c['my_role'] ?? '') === 'Member'));

        $todayHearingsCount = count(array_filter($dutyHearings, function ($h) {
            $hearingDate = substr((string) ($h['hearing_date'] ?? ''), 0, 10);
            return $hearingDate === date('Y-m-d');
        }));

        $pendingConciliations = count(array_filter($assignedCases, fn($c) => ($c['case_status'] ?? '') === 'Conciliation'));
        $pendingVerification = $this->getPendingJurisdictionComplaints();

        return [
            'user_id' => $userId,
            'duty_schedule' => $dutySchedule,
            'duty_day' => $dutyDay,
            'kpis' => [
                'total_assigned' => $totalAssigned,
                'as_chairman' => $chairpersonCount,
                'as_secretary' => $secretaryCount,
                'as_member' => $memberCount,
                'today_hearings' => $todayHearingsCount,
                'pending_conciliations' => $pendingConciliations,
                'pending_verification' => count($pendingVerification)
            ],
            'duty_hearings' => $dutyHearings,
            'assigned_cases' => $assignedCases,
            'pending_verification_complaints' => $pendingVerification
        ];
    }

    /**
     * Retrieve submitted/pending complaints awaiting Lupon Member/Clerk jurisdiction check.
     */
    public function getPendingJurisdictionComplaints(): array
    {
        try {
            $stmt = $this->conn->query("
                SELECT co.complaint_id, co.complaint_number, co.complaint_title, co.case_type,
                       co.status, co.complaint_source, co.incident_date, co.incident_location,
                       co.incident_purok, co.narrative, co.additional_details, co.requested_resolution,
                       co.review_notes, co.created_at,
                       cat.category_name, cat.ltia_code,
                       COALESCE((
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                       ), '') AS complainant_names,
                       COALESCE((
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                       ), '') AS respondent_names
                FROM complaints co
                LEFT JOIN cases c ON c.complaint_id = co.complaint_id
                LEFT JOIN complaint_categories cat ON cat.category_id = co.category_id
                WHERE c.case_id IS NULL
                  AND COALESCE(co.is_draft, 0) = 0
                  AND co.status IN ('Filed', 'Under Review', 'Needs Information', 'Submitted', 'Pending Review')
                ORDER BY CASE WHEN co.complaint_source = 'Online' THEN 0 ELSE 1 END ASC, co.created_at DESC
                LIMIT 25
            ");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Get member's duty schedule from lupon_duty_schedules or fallback to Wednesday.
     */
    public function getMemberDutySchedule(int $userId): array
    {
        $schedule = $this->getMemberDutyScheduleRow($userId);
        return array_merge($schedule, $this->getMemberDutyGroup($userId));
    }

    /**
     * Standing Pangkat duty group the member is stationed in (Settings → Pangkat Duty Groups).
     */
    private function getMemberDutyGroup(int $userId): array
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT g.duty_group_id, g.group_name, g.station, gm.position AS group_position
                FROM pangkat_duty_group_members gm
                INNER JOIN pangkat_duty_groups g ON g.duty_group_id = gm.duty_group_id
                WHERE gm.member_id = ? AND g.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (PDOException $e) {
            // Duty group tables are created on first use of the Settings panel.
        }
        return ['duty_group_id' => null, 'group_name' => null, 'station' => null, 'group_position' => null];
    }

    private function getMemberDutyScheduleRow(int $userId): array
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT id, user_id, duty_day, start_time, end_time, notes, is_active
                FROM lupon_duty_schedules
                WHERE user_id = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return $row;
            }
        } catch (PDOException $e) {
            // Table lupon_duty_schedules may not exist yet; use default schedule below
        }

        // Provide sensible defaults: Wednesday Team
        return [
            'id' => 0,
            'user_id' => $userId,
            'duty_day' => 'Wednesday',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'notes' => 'Wednesday Conciliation Team (Regular Duty)',
            'is_active' => 1
        ];
    }

    /**
     * Retrieve all active cases where member is Chairman, Secretary, or Member.
     * For Admin/Clerk oversight accounts without direct member rows, returns all active Pangkat cases.
     */
    public function getAssignedPangkatCases(int $userId): array
    {
        $stmt = $this->conn->prepare("
            SELECT c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date,
                   co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, co.incident_location,
                   cat.category_name,
                   pg.pangkat_id, pg.formation_date, pg.conciliation_deadline,
                   pm.position AS my_role, pm.status AS member_status,
                   COALESCE((
                       SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                       FROM complaint_parties cp
                       JOIN residents r ON r.resident_id = cp.resident_id
                       WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                   ), '') AS complainant_names,
                   COALESCE((
                       SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                       FROM complaint_parties cp
                       JOIN residents r ON r.resident_id = cp.resident_id
                       WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                   ), '') AS respondent_names,
                   (
                       SELECT COUNT(*)
                       FROM hearings h
                       WHERE h.case_id = c.case_id AND h.status NOT IN ('Cancelled', 'Office Cancelled')
                   ) AS hearing_count,
                   (
                       SELECT MAX(h.hearing_date)
                       FROM hearings h
                       WHERE h.case_id = c.case_id AND h.status = 'Completed'
                   ) AS last_hearing_date,
                   (
                       SELECT s.compliance_status
                       FROM settlements s
                       WHERE s.case_id = c.case_id
                       LIMIT 1
                   ) AS settlement_status
            FROM pangkat_members pm
            JOIN pangkat_groups pg ON pg.pangkat_id = pm.pangkat_id
            JOIN cases c ON c.case_id = pg.case_id
            JOIN complaints co ON co.complaint_id = c.complaint_id
            LEFT JOIN complaint_categories cat ON cat.category_id = co.category_id
            WHERE pm.member_id = ? AND pm.status = 'Active'
            ORDER BY c.case_id DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows) && $this->isOversightRole($userId)) {
            $oversightStmt = $this->conn->query("
                SELECT c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date,
                       co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, co.incident_location,
                       cat.category_name,
                       pg.pangkat_id, pg.formation_date, pg.conciliation_deadline,
                       COALESCE((
                           SELECT pm_role.position
                           FROM pangkat_members pm_role
                           WHERE pm_role.pangkat_id = pg.pangkat_id AND pm_role.status = 'Active'
                           ORDER BY FIELD(pm_role.position, 'Chairman', 'Secretary', 'Member')
                           LIMIT 1
                       ), 'Chairman') AS my_role,
                       'Active' AS member_status,
                       COALESCE((
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                       ), '') AS complainant_names,
                       COALESCE((
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                       ), '') AS respondent_names,
                       (
                           SELECT COUNT(*)
                           FROM hearings h
                           WHERE h.case_id = c.case_id AND h.status NOT IN ('Cancelled', 'Office Cancelled')
                       ) AS hearing_count,
                       (
                           SELECT MAX(h.hearing_date)
                           FROM hearings h
                           WHERE h.case_id = c.case_id AND h.status = 'Completed'
                       ) AS last_hearing_date,
                       (
                           SELECT s.compliance_status
                           FROM settlements s
                           WHERE s.case_id = c.case_id
                           LIMIT 1
                       ) AS settlement_status
                FROM pangkat_groups pg
                JOIN cases c ON c.case_id = pg.case_id
                JOIN complaints co ON co.complaint_id = c.complaint_id
                LEFT JOIN complaint_categories cat ON cat.category_id = co.category_id
                WHERE EXISTS (
                    SELECT 1 FROM pangkat_members pm_chk
                    WHERE pm_chk.pangkat_id = pg.pangkat_id AND pm_chk.status = 'Active'
                )
                ORDER BY c.case_id DESC
            ");
            $rows = $oversightStmt ? $oversightStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        return $rows;
    }

    /**
     * Retrieve hearings filtered by duty day and member's assigned cases.
     */
    public function getDutyHearings(int $userId, string $dutyDay): array
    {
        $isOversight = $this->isOversightRole($userId);

        if ($isOversight) {
            $stmt = $this->conn->prepare("
                SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.end_time, h.venue, h.status, h.mediation_attempt,
                       h.complainant_attendance, h.respondent_attendance, h.attendance_notes,
                       DAYNAME(h.hearing_date) AS day_of_week,
                       c.case_id, c.case_number, c.case_status,
                       co.complaint_id, co.complaint_title,
                       pg.pangkat_id,
                       COALESCE(pm.position, (
                           SELECT pm_role.position
                           FROM pangkat_members pm_role
                           WHERE pm_role.pangkat_id = pg.pangkat_id AND pm_role.status = 'Active'
                           ORDER BY FIELD(pm_role.position, 'Chairman', 'Secretary', 'Member')
                           LIMIT 1
                       )) AS my_role,
                       COALESCE((
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                       ), '') AS complainant_names,
                       COALESCE((
                           SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                           FROM complaint_parties cp
                           JOIN residents r ON r.resident_id = cp.resident_id
                           WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                       ), '') AS respondent_names
                FROM hearings h
                JOIN cases c ON c.case_id = h.case_id
                JOIN complaints co ON co.complaint_id = c.complaint_id
                LEFT JOIN pangkat_groups pg ON pg.case_id = c.case_id
                LEFT JOIN pangkat_members pm ON pm.pangkat_id = pg.pangkat_id AND pm.member_id = ?
                WHERE (DAYNAME(h.hearing_date) = ? OR pg.pangkat_id IS NOT NULL)
                  AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Completed', 'Rescheduled')
                  AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
                ORDER BY h.hearing_date ASC
                LIMIT 40
            ");
            $stmt->execute([$userId, $dutyDay]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->conn->prepare("
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.end_time, h.venue, h.status, h.mediation_attempt,
                   h.complainant_attendance, h.respondent_attendance, h.attendance_notes,
                   DAYNAME(h.hearing_date) AS day_of_week,
                   c.case_id, c.case_number, c.case_status,
                   co.complaint_id, co.complaint_title,
                   pg.pangkat_id,
                   pm.position AS my_role,
                   COALESCE((
                       SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                       FROM complaint_parties cp
                       JOIN residents r ON r.resident_id = cp.resident_id
                       WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                   ), '') AS complainant_names,
                   COALESCE((
                       SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                       FROM complaint_parties cp
                       JOIN residents r ON r.resident_id = cp.resident_id
                       WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                   ), '') AS respondent_names
            FROM hearings h
            JOIN cases c ON c.case_id = h.case_id
            JOIN complaints co ON co.complaint_id = c.complaint_id
            LEFT JOIN pangkat_groups pg ON pg.case_id = c.case_id
            LEFT JOIN pangkat_members pm ON pm.pangkat_id = pg.pangkat_id AND pm.member_id = ?
            WHERE (DAYNAME(h.hearing_date) = ? OR pm.member_id IS NOT NULL)
              AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Completed', 'Rescheduled')
              AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
            ORDER BY h.hearing_date ASC
            LIMIT 40
        ");
        $stmt->execute([$userId, $dutyDay]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve Digital Case Dossier for a specific case:
     * Details, parties with contact numbers, hearings history, and agreed terms.
     */
    public function getCaseDossier(int $caseId, int $userId): array
    {
        // 1. Case and Complaint details
        $caseStmt = $this->conn->prepare("
            SELECT c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date,
                   co.complaint_id, co.complaint_number, co.complaint_title, co.narrative, co.additional_details, co.requested_resolution,
                   co.incident_date, co.incident_location, co.incident_purok, co.complaint_source, co.review_notes, co.generated_kp7_path,
                   cat.category_name,
                   pg.pangkat_id, pg.formation_date, pg.conciliation_deadline, pg.selection_method,
                   COALESCE(pm_user.position, (
                       SELECT pm_role.position
                       FROM pangkat_members pm_role
                       WHERE pm_role.pangkat_id = pg.pangkat_id AND pm_role.status = 'Active'
                       ORDER BY FIELD(pm_role.position, 'Chairman', 'Secretary', 'Member')
                       LIMIT 1
                   ), 'Member') AS my_role
            FROM cases c
            JOIN complaints co ON co.complaint_id = c.complaint_id
            LEFT JOIN complaint_categories cat ON cat.category_id = co.category_id
            LEFT JOIN pangkat_groups pg ON pg.case_id = c.case_id
            LEFT JOIN pangkat_members pm_user ON pm_user.pangkat_id = pg.pangkat_id AND pm_user.member_id = ?
            WHERE c.case_id = ?
        ");
        $caseStmt->execute([$userId, $caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            throw new RuntimeException("Case dossier not found.");
        }

        $complaintId = (int) $case['complaint_id'];
        $pangkatId = (int) ($case['pangkat_id'] ?? 0);

        // 2. Complaint Parties with contact numbers and addresses
        $partiesStmt = $this->conn->prepare("
            SELECT cp.party_id, cp.party_type,
                   r.resident_id, r.first_name, r.middle_name, r.last_name, r.suffix,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name,
                   r.contact_no, r.email, r.purok, r.house_street, r.address, r.gender, r.civil_status,
                   r.preferred_contact
            FROM complaint_parties cp
            JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), cp.party_id ASC
        ");
        $partiesStmt->execute([$complaintId]);
        $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Hearing history & minutes
        $hearingsStmt = $this->conn->prepare("
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                   h.complainant_attendance, h.respondent_attendance, h.attendance_notes,
                   hm.minute_id, hm.session_type, hm.session_outcome, hm.agreements_action_items,
                   hm.settlement_discussion_notes, hm.unresolved_issues, hm.complainant_statement, hm.respondent_statement
            FROM hearings h
            LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
            WHERE h.case_id = ?
            ORDER BY h.hearing_date ASC
        ");
        $hearingsStmt->execute([$caseId]);
        $hearings = $hearingsStmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Agreed Terms / Settlement (if exists)
        $settlementStmt = $this->conn->prepare("
            SELECT s.settlement_id, s.settlement_date, s.agreement_details, s.compliance_status,
                   s.compliance_due_date, s.total_amount, s.repudiation_status, s.responsible_party
            FROM settlements s
            WHERE s.case_id = ?
            LIMIT 1
        ");
        $settlementStmt->execute([$caseId]);
        $settlement = $settlementStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // 5. Pangkat Panel Members
        $pangkatMembers = [];
        if ($pangkatId > 0) {
            $pmStmt = $this->conn->prepare("
                SELECT pm.pangkat_member_id, pm.position, pm.status,
                       u.user_id, u.first_name, u.last_name, u.contact_no,
                       TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS full_name
                FROM pangkat_members pm
                JOIN users u ON u.user_id = pm.member_id
                WHERE pm.pangkat_id = ?
                ORDER BY FIELD(pm.position, 'Chairman', 'Secretary', 'Member'), pm.pangkat_member_id ASC
            ");
            $pmStmt->execute([$pangkatId]);
            $pangkatMembers = $pmStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 6. Created KP Forms & Documents bound to this case/complaint (Read-only preview)
        $documents = [];
        try {
            $docStmt = $this->conn->prepare("
                SELECT gd.document_id, gd.template_id, dt.template_name, dt.description AS template_description,
                       gd.file_path, gd.service_status, gd.regeneration_reason, gd.generated_at
                FROM generated_documents gd
                INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                WHERE gd.case_id = ?
                ORDER BY gd.generated_at DESC, gd.document_id DESC
            ");
            $docStmt->execute([$caseId]);
            foreach ($docStmt->fetchAll(PDO::FETCH_ASSOC) as $docRow) {
                $docId = (int) $docRow['document_id'];
                $docRow['preview_url'] = "../../../backend/api/documents/kp-form.php?document_id={$docId}&preview=1";
                $documents[] = $docRow;
            }
        } catch (Throwable $docErr) {
            // keep non-fatal if template join unavailable
        }

        if (!empty($case['generated_kp7_path']) || $complaintId > 0) {
            $hasKp7 = false;
            foreach ($documents as $existingDoc) {
                if (stripos((string) ($existingDoc['template_name'] ?? ''), 'Form 7') !== false) {
                    $hasKp7 = true;
                    break;
                }
            }
            if (!$hasKp7) {
                $documents[] = [
                    'document_id' => null,
                    'template_name' => 'KP Form 7',
                    'template_description' => "Sumbong (Complainant's Complaint)",
                    'file_path' => $case['generated_kp7_path'] ?? '',
                    'service_status' => 'Generated',
                    'generated_at' => $case['docket_date'] ?? null,
                    'preview_url' => "../../../backend/api/documents/kp-form.php?complaint_id={$complaintId}&form_code=KP%20Form%207&preview=1"
                ];
            }
        }

        return [
            'case' => $case,
            'parties' => $parties,
            'hearings' => $hearings,
            'settlement' => $settlement,
            'pangkat_members' => $pangkatMembers,
            'documents' => $documents
        ];
    }
}
