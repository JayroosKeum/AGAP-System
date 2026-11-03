<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class LuponDashboardController
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
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
                'pending_conciliations' => $pendingConciliations
            ],
            'duty_hearings' => $dutyHearings,
            'assigned_cases' => $assignedCases
        ];
    }

    /**
     * Get member's duty schedule from lupon_duty_schedules or fallback to Wednesday.
     */
    public function getMemberDutySchedule(int $userId): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, user_id, duty_day, start_time, end_time, notes, is_active
            FROM lupon_duty_schedules
            WHERE user_id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
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

        return $row;
    }

    /**
     * Retrieve all active cases where member is Chairman, Secretary, or Member.
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve hearings filtered by duty day and member's assigned cases.
     */
    public function getDutyHearings(int $userId, string $dutyDay): array
    {
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
              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
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
                   co.complaint_id, co.complaint_number, co.complaint_title, co.narrative, co.requested_resolution,
                   co.incident_date, co.incident_location, co.incident_purok,
                   cat.category_name,
                   pg.pangkat_id, pg.formation_date, pg.conciliation_deadline, pg.selection_method,
                   pm_user.position AS my_role
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

        return [
            'case' => $case,
            'parties' => $parties,
            'hearings' => $hearings,
            'settlement' => $settlement,
            'pangkat_members' => $pangkatMembers
        ];
    }
}
