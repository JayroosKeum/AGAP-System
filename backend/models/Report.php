<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Paanyaya.php';
require_once __DIR__ . '/../services/DocketScheduleService.php';

class Report
{
    private $conn;
    private DocketScheduleService $docketScheduleService;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
        $this->docketScheduleService = new DocketScheduleService($this->conn);
    }

    private function enrichHearingRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        foreach ($rows as &$row) {
            $row['record_type'] = 'hearing';
            $elig = $this->docketScheduleService->evaluateItemEligibility($row, 'hearing');
            $row['is_locked'] = $elig['is_locked'];
            $row['lock_code'] = $elig['lock_code'];
            $row['lock_reason'] = $elig['lock_reason'];
        }
        unset($row);
        return $rows;
    }

    private function enrichPaanyayaRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        $paanyayaModel = new Paanyaya();
        $allWithWorkflow = $paanyayaModel->getAllPaanyayas();
        $workflowById = [];
        foreach ($allWithWorkflow as $w) {
            if (isset($w['paanyaya_id'])) {
                $workflowById[(int) $w['paanyaya_id']] = $w;
            }
        }
        foreach ($rows as &$row) {
            $pid = (int) ($row['paanyaya_id'] ?? 0);
            $row['raw_status'] = $row['status'] ?? 'Scheduled';
            if ($pid > 0 && isset($workflowById[$pid])) {
                $wf = $workflowById[$pid];
                $row['current_workflow_status'] = $wf['current_workflow_status'] ?? $row['status'];
                $row['status'] = $row['current_workflow_status'];
            }
            $row['record_type'] = 'paanyaya';
            $elig = $this->docketScheduleService->evaluateItemEligibility($row, 'paanyaya');
            $row['is_locked'] = $elig['is_locked'];
            $row['lock_code'] = $elig['lock_code'];
            $row['lock_reason'] = $elig['lock_reason'];
        }
        unset($row);
        return $rows;
    }

    private static function sortCalendarItems(array &$items): void
    {
        usort($items, static function (array $a, array $b): int {
            $dateA = substr((string) ($a['hearing_date'] ?? ''), 0, 10);
            $dateB = substr((string) ($b['hearing_date'] ?? ''), 0, 10);
            if ($dateA !== $dateB) {
                return strcmp($dateA, $dateB);
            }
            $orderA = (int) ($a['queue_order'] ?? 0);
            $orderB = (int) ($b['queue_order'] ?? 0);
            $normOrderA = $orderA > 0 ? $orderA : 9999;
            $normOrderB = $orderB > 0 ? $orderB : 9999;
            if ($normOrderA !== $normOrderB) {
                return $normOrderA <=> $normOrderB;
            }
            return strcmp((string) ($a['hearing_date'] ?? ''), (string) ($b['hearing_date'] ?? ''));
        });
    }

    public function dashboardStats(string $period = 'all', ?string $startDate = null, ?string $endDate = null): array
    {
        $today = date('Y-m-d');
        // A seven-calendar-day window includes today plus the following six
        // days. Using +7 made the "Next 7 calendar days" card count eight.
        $nextSevenDays = date('Y-m-d', strtotime('+6 days'));

        if ($period === 'all' || !$startDate || !$endDate) {
            $statusRows = $this->conn->query(
                'SELECT case_status AS status, COUNT(*) AS total
                 FROM cases
                 GROUP BY case_status
                 ORDER BY total DESC, case_status ASC'
            )->fetchAll(PDO::FETCH_ASSOC);

            $todayHearings = $this->conn->prepare(
                "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status, h.queue_status,
                        h.actual_start_time, h.actual_end_time, h.attendance_recorded_at, h.presiding_officer_id, h.updated_at,
                        h.complainant_attendance, h.respondent_attendance, h.attendance_notes, h.mediation_attempt, COALESCE(h.queue_order, 0) AS queue_order,
                        c.case_id, c.case_number, c.case_status, co.complaint_id, co.complaint_title,
                        TRIM(CONCAT_WS(' ', po.first_name, po.last_name)) AS presiding_officer_name,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                        ), '') AS complainant_names,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                        ), '') AS respondent_names
                 FROM hearings h
                 INNER JOIN cases c ON c.case_id = h.case_id
                 INNER JOIN complaints co ON co.complaint_id = c.complaint_id
                 LEFT JOIN users po ON po.user_id = h.presiding_officer_id
                 WHERE DATE(h.hearing_date) = ?
                   AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
                   AND h.status <> 'Completed'
                   AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
                 ORDER BY CASE WHEN COALESCE(h.queue_order, 0) > 0 THEN h.queue_order ELSE 9999 END ASC, h.hearing_date ASC"
            );
            $todayHearings->execute([$today]);
            $todayHearingItems = $this->enrichHearingRows($todayHearings->fetchAll(PDO::FETCH_ASSOC));

            // Paanyaya sessions (Stage 0 Informal Dialogues - prioritize today & scheduled, include all in All Time mode)
            $todayPaanyayas = $this->conn->prepare(
                "SELECT p.paanyaya_id, p.complaint_id, p.escalated_to_complaint_id, p.complainant_name, p.invitee_name,
                        p.scheduled_date, p.scheduled_time, p.status, p.venue, p.salaysay AS reason, COALESCE(p.queue_order, ps.queue_order, 0) AS queue_order,
                        p.attendance_recorded_at, p.updated_at,
                        co.complaint_number, co.complaint_title,
                        COALESCE(ps.session_id, 0) AS session_id,
                        COALESCE(ps.attempt_number, p.attempt_number, 1) AS attempt_number,
                        COALESCE(ps.complainant_attended, p.complainant_attended, 'Absent') AS complainant_attended,
                        COALESCE(ps.respondent_attended, p.invitee_attended, 'Absent') AS respondent_attended,
                        ps.actual_start_time, ps.actual_end_time, ps.presiding_officer_ids,
                        ps.session_outcome,
                        ps.queue_status AS session_queue_status
                 FROM paanyayas p
                 LEFT JOIN complaints co ON co.complaint_id = COALESCE(NULLIF(p.complaint_id, 0), p.escalated_to_complaint_id)
                 LEFT JOIN paanyaya_sessions ps ON ps.complaint_id = p.complaint_id AND ps.scheduled_date = p.scheduled_date
                 WHERE p.status NOT IN ('Completed', 'Cancelled', 'Resolved', 'Unresolved', 'Escalated')
                   AND (ps.session_id IS NULL OR COALESCE(ps.queue_status, 'Waiting') <> 'Concluded')
                 ORDER BY
                     CASE
                         WHEN p.scheduled_date = ? THEN 0
                         WHEN p.status IN ('Scheduled', 'Pending') THEN 1
                         ELSE 2
                     END,
                     CASE WHEN COALESCE(p.queue_order, 0) > 0 THEN p.queue_order ELSE 9999 END ASC,
                     p.scheduled_date DESC,
                     p.scheduled_time ASC,
                     p.paanyaya_id DESC
                "
            );
            $todayPaanyayas->execute([$today]);
            $paanyayaItems = $this->enrichPaanyayaRows($todayPaanyayas->fetchAll(PDO::FETCH_ASSOC));

            $deadlineCases = $this->conn->prepare(
                "SELECT case_id, case_number, case_status, mediation_deadline_date
                 FROM cases
                 WHERE case_status IN ('Mediation', 'Conciliation')
                   AND mediation_deadline_date IS NOT NULL
                   AND mediation_deadline_date <= ?
                 ORDER BY mediation_deadline_date ASC
                "
            );
            $deadlineCases->execute([$nextSevenDays]);
            $deadlineRows = $deadlineCases->fetchAll(PDO::FETCH_ASSOC);

            $deadlineCount = $this->conn->prepare(
                "SELECT COUNT(*) FROM cases
                 WHERE case_status IN ('Mediation', 'Conciliation')
                   AND mediation_deadline_date IS NOT NULL
                   AND mediation_deadline_date <= ?"
            );
            $deadlineCount->execute([$nextSevenDays]);

            $hearingCountToday = $this->conn->prepare(
                "SELECT COUNT(*) FROM hearings
                 WHERE DATE(hearing_date) = ?
                   AND status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
                   AND COALESCE(queue_status, 'Waiting') <> 'Concluded'"
            );
            $hearingCountToday->execute([$today]);

            $hearingCountWeek = $this->conn->prepare(
                "SELECT COUNT(*) FROM hearings
                 WHERE DATE(hearing_date) BETWEEN ? AND ?
                   AND status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
                   AND COALESCE(queue_status, 'Waiting') <> 'Concluded'"
            );
            $hearingCountWeek->execute([$today, $nextSevenDays]);

            $casesStmt = $this->conn->query(
                "SELECT c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date,
                        co.complaint_id, co.complaint_number, co.complaint_title,
                        cat.category_name,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                        ), '') AS complainant_names,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                        ), '') AS respondent_names
                 FROM cases c
                 INNER JOIN complaints co ON co.complaint_id = c.complaint_id
                 LEFT JOIN complaint_categories cat ON cat.category_id = co.category_id
                 ORDER BY c.docket_date DESC, c.case_id DESC"
            );
            $casesList = $casesStmt->fetchAll(PDO::FETCH_ASSOC);

            $calendarHearingsStmt = $this->conn->query(
                "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status, h.queue_status,
                        h.actual_start_time, h.actual_end_time, h.attendance_recorded_at, h.presiding_officer_id, h.updated_at,
                        h.complainant_attendance, h.respondent_attendance, h.attendance_notes, h.mediation_attempt, COALESCE(h.queue_order, 0) AS queue_order,
                        c.case_id, c.case_number, c.case_status, co.complaint_id, co.complaint_title,
                        TRIM(CONCAT_WS(' ', po.first_name, po.last_name)) AS presiding_officer_name,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                        ), '') AS complainant_names,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                        ), '') AS respondent_names
                 FROM hearings h
                 INNER JOIN cases c ON c.case_id = h.case_id
                 INNER JOIN complaints co ON co.complaint_id = c.complaint_id
                 LEFT JOIN users po ON po.user_id = h.presiding_officer_id
                 WHERE h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
                   AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
                 ORDER BY h.hearing_date ASC"
            );
            $calendarHearings = $this->enrichHearingRows($calendarHearingsStmt->fetchAll(PDO::FETCH_ASSOC));
            // Calendar markers represent active scheduled sessions: formal
            // hearings plus the current Paanyaya session for a dispute.
            // Completed/replaced Paanyaya attempts are filtered by the helper.
            $calendarHearings = array_merge($calendarHearings, $this->getCalendarPaanyayaItems());
            self::sortCalendarItems($calendarHearings);

            $walkinsTodayStmt = $this->conn->prepare(
                "SELECT (
                    (SELECT COUNT(*) FROM complaints WHERE DATE(created_at) = ?)
                    +
                    (SELECT COUNT(*) FROM paanyayas WHERE DATE(created_at) = ? AND (complaint_id IS NULL OR complaint_id = 0) AND (escalated_to_complaint_id IS NULL OR escalated_to_complaint_id = 0))
                )"
            );
            $walkinsTodayStmt->execute([$today, $today]);
            $walkinsToday = (int) $walkinsTodayStmt->fetchColumn();

            $totalPaanyaya = (int) $this->conn->query('
                SELECT (
                    (SELECT COUNT(*) FROM paanyayas)
                    +
                    (SELECT COUNT(*) FROM complaints WHERE (status LIKE "Pre-Docketed%" OR status LIKE "Paanyaya%") AND complaint_id NOT IN (
                        SELECT complaint_id FROM paanyayas WHERE complaint_id IS NOT NULL
                        UNION
                        SELECT escalated_to_complaint_id FROM paanyayas WHERE escalated_to_complaint_id IS NOT NULL
                    ))
                )
            ')->fetchColumn();

            $activePaanyayaOnly = (int) $this->conn->query('
                SELECT (
                    (SELECT COUNT(*) FROM paanyayas WHERE status IN ("Scheduled", "Pending"))
                    +
                    (SELECT COUNT(*) FROM complaints WHERE (status LIKE "Pre-Docketed%" OR status LIKE "Paanyaya%") AND complaint_id NOT IN (
                        SELECT complaint_id FROM paanyayas WHERE complaint_id IS NOT NULL
                        UNION
                        SELECT escalated_to_complaint_id FROM paanyayas WHERE escalated_to_complaint_id IS NOT NULL
                    ))
                )
            ')->fetchColumn();
            $activePaanyaya = $activePaanyayaOnly > 0 ? $activePaanyayaOnly : $totalPaanyaya;

            $pendingForms = (int) $this->conn->query("SELECT COUNT(DISTINCT c.case_id) FROM cases c LEFT JOIN summon_deliveries sd ON sd.case_id = c.case_id WHERE c.case_status IN ('Mediation', 'Conciliation') AND (sd.delivery_id IS NULL OR sd.delivery_status = 'Pending')")->fetchColumn();
            $mediationOnlyCases = (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status = 'Mediation'")->fetchColumn();
            $pangkatConciliations = (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status = 'Conciliation'")->fetchColumn();
            $activeMediationsTotal = (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status IN ('Mediation', 'Conciliation')")->fetchColumn();
            $activeUsers = (int) $this->conn->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn();

            $settledCasesTotal = (int) $this->conn->query(
                "SELECT COUNT(DISTINCT c.case_id) FROM cases c
                 LEFT JOIN settlements s ON s.case_id = c.case_id
                 WHERE c.case_status = 'Settled' OR s.settlement_date IS NOT NULL"
            )->fetchColumn();

            $cfaCasesTotal = (int) $this->conn->query(
                "SELECT COUNT(DISTINCT c.case_id) FROM cases c
                 LEFT JOIN cfa_records cf ON cf.case_id = c.case_id
                 WHERE c.case_status = 'CFA Issued' OR cf.issuance_date IS NOT NULL"
            )->fetchColumn();

            $archivedCasesTotal = (int) $this->conn->query(
                "SELECT COUNT(*) FROM cases
                 WHERE case_status = 'Archived' OR archived_date IS NOT NULL"
            )->fetchColumn();

            $auditStmt = $this->conn->query(
                "SELECT a.audit_id, a.action, a.module_name, a.audit_date,
                        COALESCE(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), u.username, 'System') AS user_name,
                        COALESCE(r.role_name, 'Staff') AS role_name
                 FROM audit_trails a
                 LEFT JOIN users u ON u.user_id = a.user_id
                 LEFT JOIN roles r ON r.role_id = u.role_id
                 ORDER BY a.audit_date DESC, a.audit_id DESC
                 LIMIT 10"
            );
            $auditFeed = $auditStmt ? $auditStmt->fetchAll(PDO::FETCH_ASSOC) : [];

            return [
                'total_cases' => (int) $this->conn->query('SELECT COUNT(*) FROM cases')->fetchColumn(),
                'settled_cases' => $settledCasesTotal,
                'cfa_cases' => $cfaCasesTotal,
                'archived_cases' => $archivedCasesTotal,
                'pending_review' => (int) $this->conn->query("SELECT COUNT(*) FROM complaints WHERE status IN ('Under Review', 'Needs Information')")->fetchColumn(),
                'paanyaya_complaints' => $totalPaanyaya,
                'draft_complaints' => 0,
                'hearings_today' => (int) $hearingCountToday->fetchColumn(),
                'hearings_this_week' => (int) $hearingCountWeek->fetchColumn(),
                'active_mediations' => $activeMediationsTotal,
                'mediation_cases' => $mediationOnlyCases,
                'pangkat_conciliations' => $pangkatConciliations,
                'active_users' => $activeUsers,
                'walkins_today' => $walkinsToday,
                'active_paanyaya' => $activePaanyaya,
                'pending_forms' => $pendingForms,
                'audit_feed' => $auditFeed,
                'deadlines_due' => (int) $deadlineCount->fetchColumn(),
                'today_hearings' => $todayHearingItems,
                'today_paanyayas' => $paanyayaItems,
                'calendar_hearings' => $calendarHearings,
                'mediation_deadlines' => $deadlineRows,
                'status_totals' => $statusRows,
                'cases' => $casesList,
                'analytics' => $this->getAnalyticsSummary(),
            ];
        }

        // Period-filtered queries
        $params = [$startDate, $endDate];

        $statusStmt = $this->conn->prepare(
            'SELECT case_status AS status, COUNT(*) AS total
             FROM cases
             WHERE docket_date BETWEEN ? AND ?
             GROUP BY case_status
             ORDER BY total DESC, case_status ASC'
        );
        $statusStmt->execute($params);
        $statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

        $hearingsStmt = $this->conn->prepare(
            "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status, h.queue_status,
                        h.actual_start_time, h.actual_end_time, h.attendance_recorded_at, h.presiding_officer_id, h.updated_at,
                        h.complainant_attendance, h.respondent_attendance, h.attendance_notes, h.mediation_attempt, COALESCE(h.queue_order, 0) AS queue_order,
                        c.case_id, c.case_number, c.case_status, co.complaint_id, co.complaint_title,
                        TRIM(CONCAT_WS(' ', po.first_name, po.last_name)) AS presiding_officer_name,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                        ), '') AS complainant_names,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                        ), '') AS respondent_names
             FROM hearings h
             INNER JOIN cases c ON c.case_id = h.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             LEFT JOIN users po ON po.user_id = h.presiding_officer_id
             WHERE DATE(h.hearing_date) BETWEEN ? AND ?
               AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
               AND h.status <> 'Completed'
               AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
             ORDER BY CASE WHEN COALESCE(h.queue_order, 0) > 0 THEN h.queue_order ELSE 9999 END ASC, h.hearing_date ASC"
        );
        $hearingsStmt->execute($params);
        $periodHearings = $this->enrichHearingRows($hearingsStmt->fetchAll(PDO::FETCH_ASSOC));

        // Period Paanyayas
        $paanyayasStmt = $this->conn->prepare(
            "SELECT p.paanyaya_id, p.complaint_id, p.escalated_to_complaint_id, p.complainant_name, p.invitee_name,
                    p.scheduled_date, p.scheduled_time, p.status, p.venue, p.salaysay AS reason, COALESCE(p.queue_order, ps.queue_order, 0) AS queue_order,
                    p.attendance_recorded_at, p.updated_at,
                    co.complaint_number, co.complaint_title,
                    COALESCE(ps.session_id, 0) AS session_id,
                    COALESCE(ps.attempt_number, p.attempt_number, 1) AS attempt_number,
                    COALESCE(ps.complainant_attended, p.complainant_attended, 'Absent') AS complainant_attended,
                    COALESCE(ps.respondent_attended, p.invitee_attended, 'Absent') AS respondent_attended,
                    ps.actual_start_time, ps.actual_end_time, ps.presiding_officer_ids,
                    ps.session_outcome,
                    ps.queue_status AS session_queue_status
             FROM paanyayas p
             LEFT JOIN complaints co ON co.complaint_id = COALESCE(NULLIF(p.complaint_id, 0), p.escalated_to_complaint_id)
             LEFT JOIN paanyaya_sessions ps ON ps.complaint_id = p.complaint_id AND ps.scheduled_date = p.scheduled_date
             WHERE (p.scheduled_date BETWEEN ? AND ? OR DATE(p.created_at) BETWEEN ? AND ?)
               AND p.status NOT IN ('Completed', 'Cancelled', 'Resolved', 'Unresolved', 'Escalated')
               AND (ps.session_id IS NULL OR COALESCE(ps.queue_status, 'Waiting') <> 'Concluded')
             ORDER BY CASE WHEN COALESCE(p.queue_order, 0) > 0 THEN p.queue_order ELSE 9999 END ASC, p.scheduled_date DESC, p.scheduled_time ASC, p.paanyaya_id DESC
            "
        );
        $paanyayasStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $periodPaanyayas = $this->enrichPaanyayaRows($paanyayasStmt->fetchAll(PDO::FETCH_ASSOC));

        $deadlineStmt = $this->conn->prepare(
            "SELECT case_id, case_number, case_status, mediation_deadline_date
             FROM cases
             WHERE case_status IN ('Mediation', 'Conciliation')
               AND mediation_deadline_date IS NOT NULL
               AND mediation_deadline_date BETWEEN ? AND ?
             ORDER BY mediation_deadline_date ASC"
        );
        $deadlineStmt->execute($params);
        $deadlineRows = $deadlineStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalCasesStmt = $this->conn->prepare("SELECT COUNT(*) FROM cases WHERE docket_date BETWEEN ? AND ?");
        $totalCasesStmt->execute($params);

        $settledStmt = $this->conn->prepare(
            "SELECT COUNT(DISTINCT c.case_id) FROM cases c
             LEFT JOIN settlements s ON s.case_id = c.case_id
             WHERE (c.case_status = 'Settled' OR s.settlement_date IS NOT NULL)
               AND (c.docket_date BETWEEN ? AND ? OR s.settlement_date BETWEEN ? AND ?)"
        );
        $settledStmt->execute([$startDate, $endDate, $startDate, $endDate]);

        $cfaStmt = $this->conn->prepare(
            "SELECT COUNT(DISTINCT c.case_id) FROM cases c
             LEFT JOIN cfa_records cf ON cf.case_id = c.case_id
             WHERE (c.case_status = 'CFA Issued' OR cf.issuance_date IS NOT NULL)
               AND (c.docket_date BETWEEN ? AND ? OR cf.issuance_date BETWEEN ? AND ?)"
        );
        $cfaStmt->execute([$startDate, $endDate, $startDate, $endDate]);

        $archivedStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM cases
             WHERE (case_status = 'Archived' OR archived_date IS NOT NULL)
               AND (docket_date BETWEEN ? AND ? OR (archived_date IS NOT NULL AND archived_date BETWEEN ? AND ?))"
        );
        $archivedStmt->execute([$startDate, $endDate, $startDate, $endDate]);

        $pendingReviewStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM complaints
             WHERE status IN ('Under Review', 'Needs Information')
               AND DATE(created_at) BETWEEN ? AND ?"
        );
        $pendingReviewStmt->execute($params);

        $paanyayaStmt = $this->conn->prepare('
            SELECT (
                (SELECT COUNT(*) FROM paanyayas WHERE (scheduled_date BETWEEN ? AND ? OR DATE(created_at) BETWEEN ? AND ?))
                +
                (SELECT COUNT(*) FROM complaints WHERE (status LIKE "Pre-Docketed%" OR status LIKE "Paanyaya%") AND DATE(created_at) BETWEEN ? AND ? AND complaint_id NOT IN (
                    SELECT complaint_id FROM paanyayas WHERE complaint_id IS NOT NULL
                    UNION
                    SELECT escalated_to_complaint_id FROM paanyayas WHERE escalated_to_complaint_id IS NOT NULL
                ))
            )
        ');
        $paanyayaStmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
        $totalPeriodPaanyaya = (int) $paanyayaStmt->fetchColumn();

        $activePaanyayaStmt = $this->conn->prepare('
            SELECT (
                (SELECT COUNT(*) FROM paanyayas WHERE status IN ("Scheduled", "Pending") AND (scheduled_date BETWEEN ? AND ? OR DATE(created_at) BETWEEN ? AND ?))
                +
                (SELECT COUNT(*) FROM complaints WHERE (status LIKE "Pre-Docketed%" OR status LIKE "Paanyaya%") AND DATE(created_at) BETWEEN ? AND ? AND complaint_id NOT IN (
                    SELECT complaint_id FROM paanyayas WHERE complaint_id IS NOT NULL
                    UNION
                    SELECT escalated_to_complaint_id FROM paanyayas WHERE escalated_to_complaint_id IS NOT NULL
                ))
            )
        ');
        $activePaanyayaStmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
        $activePaanyayaOnly = (int) $activePaanyayaStmt->fetchColumn();
        $activePaanyaya = $activePaanyayaOnly > 0 ? $activePaanyayaOnly : $totalPeriodPaanyaya;

        $hearingCountStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM hearings
             WHERE DATE(hearing_date) BETWEEN ? AND ?
               AND status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
               AND COALESCE(queue_status, 'Waiting') <> 'Concluded'"
        );
        $hearingCountStmt->execute($params);
        $periodHearingCount = (int) $hearingCountStmt->fetchColumn();

        $activeMediationStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM cases
             WHERE case_status IN ('Mediation', 'Conciliation')
               AND (docket_date BETWEEN ? AND ? OR (mediation_start_date IS NOT NULL AND mediation_start_date BETWEEN ? AND ?))"
        );
        $activeMediationStmt->execute([$startDate, $endDate, $startDate, $endDate]);

        $mediationOnlyStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM cases
             WHERE case_status = 'Mediation'
               AND (docket_date BETWEEN ? AND ? OR (mediation_start_date IS NOT NULL AND mediation_start_date BETWEEN ? AND ?))"
        );
        $mediationOnlyStmt->execute([$startDate, $endDate, $startDate, $endDate]);

        $deadlineCountStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM cases
             WHERE case_status IN ('Mediation', 'Conciliation')
               AND mediation_deadline_date IS NOT NULL
               AND mediation_deadline_date BETWEEN ? AND ?"
        );
        $deadlineCountStmt->execute($params);

        $casesStmt = $this->conn->prepare(
            "SELECT c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date,
                    co.complaint_id, co.complaint_number, co.complaint_title,
                    cat.category_name,
                    COALESCE((
                        SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                        FROM complaint_parties cp
                        INNER JOIN residents r ON r.resident_id = cp.resident_id
                        WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                    ), '') AS complainant_names,
                    COALESCE((
                        SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                        FROM complaint_parties cp
                        INNER JOIN residents r ON r.resident_id = cp.resident_id
                        WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                    ), '') AS respondent_names
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             LEFT JOIN complaint_categories cat ON cat.category_id = co.category_id
             WHERE c.docket_date BETWEEN ? AND ?
             ORDER BY c.docket_date DESC, c.case_id DESC"
        );
        $casesStmt->execute($params);
        $casesList = $casesStmt->fetchAll(PDO::FETCH_ASSOC);

        $calendarHearingsStmt = $this->conn->query(
            "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status, h.queue_status,
                        h.actual_start_time, h.actual_end_time, h.attendance_recorded_at, h.presiding_officer_id, h.updated_at,
                        h.complainant_attendance, h.respondent_attendance, h.attendance_notes, h.mediation_attempt, COALESCE(h.queue_order, 0) AS queue_order,
                        c.case_id, c.case_number, c.case_status, co.complaint_id, co.complaint_title,
                        TRIM(CONCAT_WS(' ', po.first_name, po.last_name)) AS presiding_officer_name,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                        ), '') AS complainant_names,
                        COALESCE((
                            SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.last_name)) SEPARATOR ', ')
                            FROM complaint_parties cp
                            INNER JOIN residents r ON r.resident_id = cp.resident_id
                            WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                        ), '') AS respondent_names
             FROM hearings h
             INNER JOIN cases c ON c.case_id = h.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             LEFT JOIN users po ON po.user_id = h.presiding_officer_id
             WHERE h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
               AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
             ORDER BY h.hearing_date ASC"
        );
        $calendarHearings = $this->enrichHearingRows($calendarHearingsStmt->fetchAll(PDO::FETCH_ASSOC));
        $calendarHearings = array_merge($calendarHearings, $this->getCalendarPaanyayaItems());
        self::sortCalendarItems($calendarHearings);

        $walkinsStmt = $this->conn->prepare(
            "SELECT (
                (SELECT COUNT(*) FROM complaints WHERE DATE(created_at) BETWEEN ? AND ?)
                +
                (SELECT COUNT(*) FROM paanyayas WHERE DATE(created_at) BETWEEN ? AND ? AND (complaint_id IS NULL OR complaint_id = 0) AND (escalated_to_complaint_id IS NULL OR escalated_to_complaint_id = 0))
            )"
        );
        $walkinsStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $walkinsToday = (int) $walkinsStmt->fetchColumn();

        $pangkatConciliationStmt = $this->conn->prepare("SELECT COUNT(*) FROM cases WHERE case_status = 'Conciliation' AND docket_date BETWEEN ? AND ?");
        $pangkatConciliationStmt->execute($params);
        $pangkatConciliations = (int) $pangkatConciliationStmt->fetchColumn();

        $activeUsers = (int) $this->conn->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn();
        $pendingForms = (int) $this->conn->query("SELECT COUNT(DISTINCT c.case_id) FROM cases c LEFT JOIN summon_deliveries sd ON sd.case_id = c.case_id WHERE c.case_status IN ('Mediation', 'Conciliation') AND (sd.delivery_id IS NULL OR sd.delivery_status = 'Pending')")->fetchColumn();

        $auditStmt = $this->conn->query(
            "SELECT a.audit_id, a.action, a.module_name, a.audit_date,
                    COALESCE(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), u.username, 'System') AS user_name,
                    COALESCE(r.role_name, 'Staff') AS role_name
             FROM audit_trails a
             LEFT JOIN users u ON u.user_id = a.user_id
             LEFT JOIN roles r ON r.role_id = u.role_id
             ORDER BY a.audit_date DESC, a.audit_id DESC
             LIMIT 10"
        );
        $auditFeed = $auditStmt ? $auditStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            'total_cases' => (int) $totalCasesStmt->fetchColumn(),
            'settled_cases' => (int) $settledStmt->fetchColumn(),
            'cfa_cases' => (int) $cfaStmt->fetchColumn(),
            'archived_cases' => (int) $archivedStmt->fetchColumn(),
            'pending_review' => (int) $pendingReviewStmt->fetchColumn(),
            'paanyaya_complaints' => $totalPeriodPaanyaya,
            'draft_complaints' => 0,
            'hearings_today' => $periodHearingCount,
            'hearings_this_week' => $periodHearingCount,
            'active_mediations' => (int) $activeMediationStmt->fetchColumn(),
            'mediation_cases' => (int) $mediationOnlyStmt->fetchColumn(),
            'pangkat_conciliations' => $pangkatConciliations,
            'active_users' => $activeUsers,
            'walkins_today' => $walkinsToday,
            'active_paanyaya' => $activePaanyaya,
            'pending_forms' => $pendingForms,
            'audit_feed' => $auditFeed,
            'deadlines_due' => (int) $deadlineCountStmt->fetchColumn(),
            'today_hearings' => $periodHearings,
            'today_paanyayas' => $periodPaanyayas,
            'calendar_hearings' => $calendarHearings,
            'mediation_deadlines' => $deadlineRows,
            'status_totals' => $statusRows,
            'cases' => $casesList,
            'analytics' => $this->getAnalyticsSummary($startDate, $endDate),
        ];
    }

    /** Calendar uses one event shape for active formal hearings and Paanyaya sessions. */
    private function getCalendarPaanyayaItems(): array
    {
        $stmt = $this->conn->query("
            SELECT CONCAT('paanyaya-', p.paanyaya_id) AS hearing_id,
                   p.paanyaya_id,
                   COALESCE(ps.session_id, 0) AS session_id,
                   'paanyaya' AS record_type,
                   c.case_id, 'Paanyaya' AS hearing_type,
                   CONCAT(p.scheduled_date, ' ', COALESCE(p.scheduled_time, ps.scheduled_time, '09:00:00')) AS hearing_date,
                   p.scheduled_date, COALESCE(p.scheduled_time, ps.scheduled_time, '09:00:00') AS scheduled_time,
                   COALESCE(p.venue, ps.venue, 'Tanggapan ng Barangay Tumana') AS venue,
                   COALESCE(p.status, 'Scheduled') AS status,
                   COALESCE(ps.queue_status, 'Waiting') AS queue_status,
                   ps.queue_status AS session_queue_status,
                   ps.actual_start_time, ps.actual_end_time, ps.session_outcome,
                   ps.presiding_officer_ids,
                   COALESCE(ps.complainant_attended, p.complainant_attended, 'Absent') AS complainant_attended,
                   COALESCE(ps.respondent_attended, p.invitee_attended, 'Absent') AS respondent_attended,
                   p.invitee_attended,
                   p.attendance_recorded_at, p.updated_at,
                   NULL AS complainant_attendance, NULL AS respondent_attendance,
                   ps.session_remarks AS attendance_notes,
                   COALESCE(ps.attempt_number, p.attempt_number, 1) AS mediation_attempt,
                   COALESCE(ps.attempt_number, p.attempt_number, 1) AS attempt_number,
                   COALESCE(p.queue_order, ps.queue_order, 0) AS queue_order,
                   COALESCE(NULLIF(p.complaint_id, 0), p.escalated_to_complaint_id) AS complaint_id,
                   COALESCE(c.case_number, co.complaint_number) AS case_number,
                   co.complaint_number,
                   co.complaint_title,
                   COALESCE(p.complainant_name, '') AS complainant_names,
                   COALESCE(p.complainant_name, '') AS complainant_name,
                   COALESCE(p.invitee_name, ps.invitee_name, '') AS respondent_names,
                   COALESCE(p.invitee_name, ps.invitee_name, '') AS invitee_name
            FROM paanyayas p
            LEFT JOIN complaints co ON co.complaint_id = COALESCE(NULLIF(p.complaint_id, 0), p.escalated_to_complaint_id)
            LEFT JOIN cases c ON c.complaint_id = co.complaint_id
            LEFT JOIN paanyaya_sessions ps ON ps.complaint_id = p.complaint_id
                AND ps.scheduled_date = p.scheduled_date
                AND ps.session_id = (
                    SELECT MAX(ps2.session_id)
                    FROM paanyaya_sessions ps2
                    WHERE ps2.complaint_id = p.complaint_id
                      AND ps2.scheduled_date = p.scheduled_date
                )
            WHERE COALESCE(p.status, 'Scheduled') IN ('Scheduled', 'Pending')
              AND (ps.session_id IS NULL OR (ps.session_outcome IS NULL AND COALESCE(ps.queue_status, 'Waiting') <> 'Concluded'))
            ORDER BY p.scheduled_date ASC, p.scheduled_time ASC
        ");
        $invitationItems = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // Also include any paanyaya_sessions row that does not have a matching row in paanyayas
        $orphanSessStmt = $this->conn->query("
            SELECT CONCAT('paanyaya-session-', ps.session_id) AS hearing_id,
                   0 AS paanyaya_id,
                   ps.session_id,
                   'paanyaya' AS record_type,
                   c.case_id, 'Paanyaya' AS hearing_type,
                   CONCAT(ps.scheduled_date, ' ', COALESCE(ps.scheduled_time, '09:00:00')) AS hearing_date,
                   ps.scheduled_date, COALESCE(ps.scheduled_time, '09:00:00') AS scheduled_time,
                   ps.venue, 'Scheduled' AS status,
                   COALESCE(ps.queue_status, 'Waiting') AS queue_status,
                   ps.queue_status AS session_queue_status,
                   ps.actual_start_time, ps.actual_end_time, ps.session_outcome,
                   ps.presiding_officer_ids,
                   COALESCE(ps.complainant_attended, 'Absent') AS complainant_attended,
                   COALESCE(ps.respondent_attended, 'Absent') AS respondent_attended,
                   COALESCE(ps.respondent_attended, 'Absent') AS invitee_attended,
                   NULL AS attendance_recorded_at, NULL AS resolved_at, NULL AS escalated_at, ps.updated_at,
                   NULL AS complainant_attendance, NULL AS respondent_attendance,
                   ps.session_remarks AS attendance_notes, ps.attempt_number AS mediation_attempt,
                   ps.attempt_number,
                   COALESCE(ps.queue_order, 0) AS queue_order,
                   co.complaint_id, COALESCE(c.case_number, co.complaint_number) AS case_number,
                   co.complaint_number,
                   co.complaint_title,
                   '' AS complainant_names,
                   '' AS complainant_name,
                   COALESCE(ps.invitee_name, '') AS respondent_names,
                   COALESCE(ps.invitee_name, '') AS invitee_name
            FROM paanyaya_sessions ps
            INNER JOIN complaints co ON co.complaint_id = ps.complaint_id
            LEFT JOIN cases c ON c.complaint_id = co.complaint_id
            WHERE ps.session_outcome IS NULL
              AND COALESCE(ps.queue_status, 'Waiting') <> 'Concluded'
              AND NOT EXISTS (
                  SELECT 1 FROM paanyayas p
                  WHERE p.complaint_id = ps.complaint_id
                    AND p.status IN ('Scheduled', 'Pending')
              )
            ORDER BY ps.scheduled_date ASC, ps.scheduled_time ASC
        ");
        $orphanSessions = $orphanSessStmt ? $orphanSessStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return $this->enrichPaanyayaRows(array_merge($invitationItems, $orphanSessions));
    }

    /**
     * Compute grievance categories, purok dispute hotspots, and demographic breakdowns
     */
    public function getAnalyticsSummary(?string $startDate = null, ?string $endDate = null): array
    {
        $hasPeriod = !empty($startDate) && !empty($endDate);
        $periodParams = $hasPeriod ? [$startDate, $endDate] : [];

        // 1. Top Complainant Issues (Dispute Categories)
        $catSql = "
            SELECT cat.category_name, COUNT(co.complaint_id) AS total
            FROM complaint_categories cat
            LEFT JOIN complaints co ON co.category_id = cat.category_id " .
            ($hasPeriod ? " AND DATE(co.created_at) BETWEEN ? AND ?" : "") . "
            GROUP BY cat.category_id, cat.category_name
            HAVING total > 0
            ORDER BY total DESC
            LIMIT 6
        ";
        $catStmt = $this->conn->prepare($catSql);
        $catStmt->execute($periodParams);
        $topCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback if no categorized complaints yet
        if (empty($topCategories)) {
            $fallbackSql = "
                SELECT cat.category_name, 0 AS total
                FROM complaint_categories cat
                ORDER BY cat.category_id ASC
                LIMIT 5
            ";
            $topCategories = $this->conn->query($fallbackSql)->fetchAll(PDO::FETCH_ASSOC);
        }

        // Clean up category display name (remove slash translations if too long)
        foreach ($topCategories as &$tc) {
            $parts = explode('/', $tc['category_name']);
            $tc['display_name'] = trim($parts[0]);
            $tc['total'] = (int) $tc['total'];
        }
        unset($tc);

        // 2. Purok Dispute Hotspots
        $purokSql = "
            SELECT COALESCE(NULLIF(TRIM(co.incident_purok), ''), 'Tumana (General)') AS purok_name,
                   COUNT(co.complaint_id) AS total
            FROM complaints co " .
            ($hasPeriod ? " WHERE DATE(co.created_at) BETWEEN ? AND ?" : "") . "
            GROUP BY purok_name
            ORDER BY total DESC
            LIMIT 7
        ";
        $purokStmt = $this->conn->prepare($purokSql);
        $purokStmt->execute($periodParams);
        $purokHotspots = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Demographics: Gender Breakdown
        $genderSql = "
            SELECT COALESCE(r.gender, 'Unspecified') AS gender, COUNT(*) as total
            FROM complaint_parties cp
            JOIN residents r ON r.resident_id = cp.resident_id
            JOIN complaints co ON co.complaint_id = cp.complaint_id " .
            ($hasPeriod ? " WHERE DATE(co.created_at) BETWEEN ? AND ?" : "") . "
            GROUP BY gender
        ";
        $genderStmt = $this->conn->prepare($genderSql);
        $genderStmt->execute($periodParams);
        $gendersRaw = $genderStmt->fetchAll(PDO::FETCH_ASSOC);
        $genderBreakdown = ['Male' => 0, 'Female' => 0, 'Other' => 0];
        foreach ($gendersRaw as $g) {
            $key = ucfirst(strtolower(trim($g['gender'])));
            if (isset($genderBreakdown[$key])) {
                $genderBreakdown[$key] += (int) $g['total'];
            } else {
                $genderBreakdown['Other'] += (int) $g['total'];
            }
        }

        // 4. Demographics: Age Group Distribution
        $ageSql = "
            SELECT 
                SUM(CASE WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) < 18 THEN 1 ELSE 0 END) AS minors,
                SUM(CASE WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 18 AND 29 THEN 1 ELSE 0 END) AS youth,
                SUM(CASE WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) BETWEEN 30 AND 59 THEN 1 ELSE 0 END) AS adults,
                SUM(CASE WHEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) >= 60 THEN 1 ELSE 0 END) AS seniors,
                COUNT(*) AS total_parties
            FROM complaint_parties cp
            JOIN residents r ON r.resident_id = cp.resident_id
            JOIN complaints co ON co.complaint_id = cp.complaint_id " .
            ($hasPeriod ? " WHERE DATE(co.created_at) BETWEEN ? AND ?" : "") . "
        ";
        $ageStmt = $this->conn->prepare($ageSql);
        $ageStmt->execute($periodParams);
        $ageRow = $ageStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'top_categories' => $topCategories,
            'purok_hotspots' => $purokHotspots,
            'gender_breakdown' => $genderBreakdown,
            'age_distribution' => [
                'minors' => (int) ($ageRow['minors'] ?? 0),
                'youth' => (int) ($ageRow['youth'] ?? 0),
                'adults' => (int) ($ageRow['adults'] ?? 0),
                'seniors' => (int) ($ageRow['seniors'] ?? 0),
                'total' => (int) ($ageRow['total_parties'] ?? 0)
            ]
        ];
    }

    public function getReport(?string $startDate = null, ?string $endDate = null): array
    {
        $whereCases = '';
        $whereFiled = '';
        $params = [];
        if (!empty($startDate) && !empty($endDate)) {
            $whereCases = 'WHERE c.docket_date BETWEEN ? AND ?';
            $whereFiled = 'WHERE DATE(created_at) BETWEEN ? AND ?';
            $params = [$startDate, $endDate];
        } elseif (!empty($startDate)) {
            $whereCases = 'WHERE c.docket_date >= ?';
            $whereFiled = 'WHERE DATE(created_at) >= ?';
            $params = [$startDate];
        } elseif (!empty($endDate)) {
            $whereCases = 'WHERE c.docket_date <= ?';
            $whereFiled = 'WHERE DATE(created_at) <= ?';
            $params = [$endDate];
        }

        $cases = $this->conn->prepare(
            "SELECT c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date,
                    c.archived_date, co.complaint_number, co.complaint_title, co.incident_date,
                    cat.category_name, s.settlement_date, s.compliance_status,
                    ar.award_date, cf.issuance_date AS cfa_issuance_date,
                    (SELECT COUNT(*) FROM hearings h WHERE h.case_id = c.case_id) AS hearing_count,
                    (SELECT MAX(h.hearing_date) FROM hearings h WHERE h.case_id = c.case_id) AS last_hearing_date
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             INNER JOIN complaint_categories cat ON cat.category_id = co.category_id
             LEFT JOIN settlements s ON s.case_id = c.case_id
             LEFT JOIN arbitration_records ar ON ar.case_id = c.case_id
             LEFT JOIN cfa_records cf ON cf.case_id = c.case_id
             $whereCases
             ORDER BY c.docket_date ASC, c.case_number ASC"
        );
        $cases->execute($params);
        $caseRows = $cases->fetchAll(PDO::FETCH_ASSOC);

        $filed = $this->conn->prepare(
            "SELECT COUNT(*) FROM complaints $whereFiled"
        );
        $filed->execute($params);

        $categories = $this->conn->prepare(
            "SELECT cat.category_name, COUNT(*) AS total
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             INNER JOIN complaint_categories cat ON cat.category_id = co.category_id
             $whereCases
             GROUP BY cat.category_id, cat.category_name
             ORDER BY total DESC, cat.category_name ASC"
        );
        $categories->execute($params);

        $totals = [
            'complaints_filed' => (int) $filed->fetchColumn(),
            'cases_docketed' => count($caseRows),
            'civil_cases' => 0,
            'criminal_cases' => 0,
            'settled_cases' => 0,
            'arbitration_cases' => 0,
            'cfa_issued_cases' => 0,
            'archived_cases' => 0,
            'hearings_scheduled' => 0,
        ];
        $statusTotals = [];
        foreach ($caseRows as $case) {
            $totals[$case['case_type'] === 'Criminal' ? 'criminal_cases' : 'civil_cases']++;
            $totals['hearings_scheduled'] += (int) $case['hearing_count'];
            if ($case['case_status'] === 'Settled' || $case['settlement_date'] !== null) {
                $totals['settled_cases']++;
            }
            if ($case['case_status'] === 'Arbitration' || $case['award_date'] !== null) {
                $totals['arbitration_cases']++;
            }
            if ($case['case_status'] === 'CFA Issued' || $case['cfa_issuance_date'] !== null) {
                $totals['cfa_issued_cases']++;
            }
            if ($case['case_status'] === 'Archived' || $case['archived_date'] !== null) {
                $totals['archived_cases']++;
            }
            $status = $case['case_status'];
            $statusTotals[$status] = ($statusTotals[$status] ?? 0) + 1;
        }

        return [
            'totals' => $totals,
            'status_totals' => $statusTotals,
            'categories' => $categories->fetchAll(PDO::FETCH_ASSOC),
            'cases' => $caseRows,
        ];
    }

    public function createGeneratedReport(string $type, int $userId, string $filePath): int
    {
        $statement = $this->conn->prepare(
            'INSERT INTO generated_reports (report_type, generated_by, file_path) VALUES (?, ?, ?)'
        );
        $statement->execute([$type, $userId, $filePath]);
        return (int) $this->conn->lastInsertId();
    }

    public function getGeneratedReport(int $reportId): ?array
    {
        $statement = $this->conn->prepare(
            'SELECT report_id, report_type, file_path FROM generated_reports WHERE report_id = ?'
        );
        $statement->execute([$reportId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * getCaseRegister()
     *
     * Returns a 1-row-per-case dataset for the Excel Case Register export.
     * Includes: docket info, parties, summons delivery date/server/result,
     * SLA timeliness (served within 1 business day of issuance), hearing count,
     * resolution/outcome, and case status.
     */
    public function getCaseRegister(?string $startDate = null, ?string $endDate = null): array
    {
        $where = '';
        $params = [];

        if ($startDate && $endDate) {
            $where = 'WHERE c.docket_date BETWEEN ? AND ?';
            $params = [$startDate, $endDate];
        } elseif ($startDate) {
            $where = 'WHERE c.docket_date >= ?';
            $params = [$startDate];
        } elseif ($endDate) {
            $where = 'WHERE c.docket_date <= ?';
            $params = [$endDate];
        }

        $sql = "
            SELECT
                c.case_id,
                c.case_number,
                c.case_type,
                c.case_status,
                c.docket_date,
                co.complaint_number,
                co.complaint_title,
                co.incident_date,
                cat.category_name,

                /* Complainant (first) */
                MAX(CASE WHEN cp.party_type = 'Complainant' THEN
                    TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name))
                END) AS complainant_name,

                /* Respondent (first) */
                MAX(CASE WHEN cp.party_type = 'Respondent' THEN
                    TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name))
                END) AS respondent_name,

                /* Summons — 1st attempt from summon_deliveries.
                   created_at ≈ issuance date; served_at = actual delivery datetime */
                MIN(DATE(sd.created_at))           AS summons_issued_date,
                MIN(DATE(sd.served_at))            AS summons_served_date,
                MAX(CONCAT_WS(' ', su.first_name, su.last_name))
                                                   AS serving_officer,
                MAX(sd.delivery_status)            AS delivery_status,
                /* SLA: served within 1 calendar day of issuance */
                CASE
                    WHEN MIN(sd.served_at) IS NOT NULL AND MIN(sd.created_at) IS NOT NULL
                         AND DATEDIFF(MIN(DATE(sd.served_at)), MIN(DATE(sd.created_at))) <= 1
                    THEN 'On Time'
                    WHEN MIN(sd.served_at) IS NOT NULL
                    THEN 'Delayed'
                    ELSE 'Not Served'
                END AS summons_timeliness,

                /* Hearing counts */
                COALESCE(SUM(CASE WHEN h.hearing_type IN ('Mediation','Initial Hearing')
                                       AND h.status != 'Cancelled' THEN 1 ELSE 0 END), 0) AS mediation_sessions,
                COALESCE(SUM(CASE WHEN h.hearing_type = 'Conciliation'
                                       AND h.status != 'Cancelled' THEN 1 ELSE 0 END), 0) AS conciliation_sessions,

                /* Settlement / CFA outcome */
                st.settlement_date,
                st.compliance_status,
                cfa.issuance_date              AS cfa_issuance_date,
                cfa.reason                     AS cfa_reason

            FROM cases c
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            LEFT JOIN complaint_categories cat ON cat.category_id = co.category_id
            LEFT JOIN complaint_parties cp ON cp.complaint_id = co.complaint_id
                AND cp.party_type IN ('Complainant','Respondent')
            LEFT JOIN residents r ON r.resident_id = cp.resident_id
            LEFT JOIN summon_deliveries sd ON sd.case_id = c.case_id
                AND sd.attempt_number = 1
                AND sd.form_type = 'Summon'
            LEFT JOIN users su ON su.user_id = sd.served_by
            LEFT JOIN hearings h ON h.case_id = c.case_id
            LEFT JOIN settlements st ON st.case_id = c.case_id
            LEFT JOIN cfa_records cfa ON cfa.case_id = c.case_id
            $where
            GROUP BY
                c.case_id, c.case_number, c.case_type, c.case_status,
                c.docket_date, co.complaint_number, co.complaint_title,
                co.incident_date, cat.category_name,
                st.settlement_date, st.compliance_status,
                cfa.issuance_date, cfa.reason
            ORDER BY c.docket_date ASC, c.case_id ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
