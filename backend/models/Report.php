<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Paanyaya.php';

class Report
{
    private $conn;
    private array $schemaCache = [];

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    private function tableExists(string $table): bool
    {
        $key = 'table:' . $table;
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }
        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute([$table]);
            return $this->schemaCache[$key] = ((int) $stmt->fetchColumn() > 0);
        } catch (Throwable) {
            return $this->schemaCache[$key] = false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = 'col:' . $table . '.' . $column;
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }
        if (!$this->tableExists($table)) {
            return $this->schemaCache[$key] = false;
        }
        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([$table, $column]);
            return $this->schemaCache[$key] = ((int) $stmt->fetchColumn() > 0);
        } catch (Throwable) {
            return $this->schemaCache[$key] = false;
        }
    }

    private function safeScalar(string $sql, array $params = [], int $default = 0): int
    {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return $default;
        }
    }

    private function safeFetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
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
        }
        unset($row);
        return $rows;
    }

    public function dashboardStats(string $period = 'all', ?string $startDate = null, ?string $endDate = null): array
    {
        $today = date('Y-m-d');
        // A seven-calendar-day window includes today plus the following six
        // days. Using +7 made the "Next 7 calendar days" card count eight.
        $nextSevenDays = date('Y-m-d', strtotime('+6 days'));
        $hQueueCol = $this->columnExists('hearings', 'queue_order') ? 'COALESCE(h.queue_order, 0)' : '0';
        $pQueueCol = $this->columnExists('paanyayas', 'queue_order') ? 'p.queue_order' : '0';
        $psQueueCol = $this->columnExists('paanyaya_sessions', 'queue_order') ? 'ps.queue_order' : '0';
        $paanyayaOrderCol = $this->columnExists('paanyayas', 'queue_order')
            ? 'CASE WHEN COALESCE(p.queue_order, 0) > 0 THEN p.queue_order ELSE 9999 END ASC,'
            : '';

        if ($period === 'all' || !$startDate || !$endDate) {
            $statusRows = $this->conn->query(
                'SELECT case_status AS status, COUNT(*) AS total
                 FROM cases
                 GROUP BY case_status
                 ORDER BY total DESC, case_status ASC'
            )->fetchAll(PDO::FETCH_ASSOC);

            $todayHearings = $this->conn->prepare(
                "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status, h.queue_status,
                        h.actual_start_time, h.complainant_attendance, h.respondent_attendance, h.attendance_notes, h.mediation_attempt, {$hQueueCol} AS queue_order,
                        c.case_id, c.case_number, co.complaint_id, co.complaint_title,
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
                 WHERE DATE(h.hearing_date) = ?
                   AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
                   AND h.status <> 'Completed'
                   AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
                 ORDER BY CASE WHEN {$hQueueCol} > 0 THEN {$hQueueCol} ELSE 9999 END ASC, h.hearing_date ASC"
            );
            $todayHearings->execute([$today]);

            // Paanyaya sessions (Stage 0 Informal Dialogues - prioritize today & scheduled, include all in All Time mode)
            $todayPaanyayas = $this->conn->prepare(
                "SELECT p.paanyaya_id, p.complaint_id, p.escalated_to_complaint_id, p.complainant_name, p.invitee_name,
                        p.scheduled_date, p.scheduled_time, p.status, p.venue, p.salaysay AS reason, COALESCE({$pQueueCol}, {$psQueueCol}, 0) AS queue_order,
                        co.complaint_number, co.complaint_title,
                        COALESCE(ps.session_id, 0) AS session_id,
                        COALESCE(ps.attempt_number, p.attempt_number, 1) AS attempt_number,
                        COALESCE(ps.complainant_attended, p.complainant_attended, 'Absent') AS complainant_attended,
                        COALESCE(ps.respondent_attended, p.invitee_attended, 'Absent') AS respondent_attended,
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
                     {$paanyayaOrderCol}
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
                        c.mediation_deadline_date, c.is_paused,
                        co.complaint_id, co.complaint_number, co.complaint_title, co.incident_purok,
                        cat.category_name,
                        s.settlement_date, s.pb_attested, s.compliance_status,
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
                 LEFT JOIN settlements s ON s.case_id = c.case_id
                 ORDER BY c.docket_date DESC, c.case_id DESC"
            );
            $casesList = $casesStmt->fetchAll(PDO::FETCH_ASSOC);

            $calendarHearingsStmt = $this->conn->query(
                "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status, h.queue_status,
                        h.actual_start_time, h.complainant_attendance, h.respondent_attendance, h.attendance_notes, h.mediation_attempt, {$hQueueCol} AS queue_order,
                        c.case_id, c.case_number, co.complaint_id, co.complaint_title,
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
                 WHERE h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
                   AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
                 ORDER BY h.hearing_date ASC"
            );
            $calendarHearings = $calendarHearingsStmt->fetchAll(PDO::FETCH_ASSOC);
            // Calendar markers represent active scheduled sessions: formal
            // hearings plus the current Paanyaya session for a dispute.
            // Completed/replaced Paanyaya attempts are filtered by the helper.
            $calendarHearings = array_merge($calendarHearings, $this->getCalendarPaanyayaItems());
            usort($calendarHearings, static fn(array $a, array $b): int => strcmp((string) $a['hearing_date'], (string) $b['hearing_date']));

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

            $totalCasesCount = (int) $this->conn->query('SELECT COUNT(*) FROM cases')->fetchColumn();
            $pendingReviewCount = (int) $this->conn->query("SELECT COUNT(*) FROM complaints WHERE status IN ('Filed', 'Under Review', 'Needs Information', 'Pending Review') AND COALESCE(is_draft, 0) = 0")->fetchColumn();
            $hearingsTodayCount = (int) $hearingCountToday->fetchColumn();
            $hearingsWeekCount = (int) $hearingCountWeek->fetchColumn();
            $deadlinesDueCount = (int) $deadlineCount->fetchColumn();

            $basePayload = [
                'total_cases' => $totalCasesCount,
                'settled_cases' => $settledCasesTotal,
                'cfa_cases' => $cfaCasesTotal,
                'archived_cases' => $archivedCasesTotal,
                'pending_review' => $pendingReviewCount,
                'paanyaya_complaints' => $totalPaanyaya,
                'draft_complaints' => 0,
                'hearings_today' => $hearingsTodayCount,
                'hearings_this_week' => $hearingsWeekCount,
                'active_mediations' => $activeMediationsTotal,
                'mediation_cases' => $mediationOnlyCases,
                'pangkat_conciliations' => $pangkatConciliations,
                'active_users' => $activeUsers,
                'walkins_today' => $walkinsToday,
                'active_paanyaya' => $activePaanyaya,
                'pending_forms' => $pendingForms,
                'audit_feed' => $auditFeed,
                'deadlines_due' => $deadlinesDueCount,
                'today_hearings' => $todayHearings->fetchAll(PDO::FETCH_ASSOC),
                'today_paanyayas' => $paanyayaItems,
                'calendar_hearings' => $calendarHearings,
                'mediation_deadlines' => $deadlineRows,
                'status_totals' => $statusRows,
                'cases' => $casesList,
                'analytics' => $this->getAnalyticsSummary(),
            ];

            return array_merge($basePayload, $this->getCaptainExecutiveInsights('all', null, null, $basePayload));
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
                        h.actual_start_time, h.complainant_attendance, h.respondent_attendance, h.attendance_notes, h.mediation_attempt, {$hQueueCol} AS queue_order,
                        c.case_id, c.case_number, co.complaint_id, co.complaint_title,
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
             WHERE DATE(h.hearing_date) BETWEEN ? AND ?
               AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
               AND h.status <> 'Completed'
               AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
             ORDER BY h.hearing_date ASC"
        );
        $hearingsStmt->execute($params);
        $periodHearings = $hearingsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Period Paanyayas
        $paanyayasStmt = $this->conn->prepare(
            "SELECT p.paanyaya_id, p.complaint_id, p.escalated_to_complaint_id, p.complainant_name, p.invitee_name,
                    p.scheduled_date, p.scheduled_time, p.status, p.venue, p.salaysay AS reason, COALESCE({$pQueueCol}, {$psQueueCol}, 0) AS queue_order,
                    co.complaint_number, co.complaint_title,
                    COALESCE(ps.session_id, 0) AS session_id,
                    COALESCE(ps.attempt_number, p.attempt_number, 1) AS attempt_number,
                    COALESCE(ps.complainant_attended, p.complainant_attended, 'Absent') AS complainant_attended,
                    COALESCE(ps.respondent_attended, p.invitee_attended, 'Absent') AS respondent_attended,
                    ps.session_outcome,
                    ps.queue_status AS session_queue_status
             FROM paanyayas p
             LEFT JOIN complaints co ON co.complaint_id = COALESCE(NULLIF(p.complaint_id, 0), p.escalated_to_complaint_id)
             LEFT JOIN paanyaya_sessions ps ON ps.complaint_id = p.complaint_id AND ps.scheduled_date = p.scheduled_date
             WHERE (p.scheduled_date BETWEEN ? AND ? OR DATE(p.created_at) BETWEEN ? AND ?)
               AND p.status NOT IN ('Completed', 'Cancelled', 'Resolved', 'Unresolved', 'Escalated')
               AND (ps.session_id IS NULL OR COALESCE(ps.queue_status, 'Waiting') <> 'Concluded')
             ORDER BY p.scheduled_date DESC, p.scheduled_time ASC, p.paanyaya_id DESC
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
             WHERE status IN ('Filed', 'Under Review', 'Needs Information', 'Pending Review')
               AND COALESCE(is_draft, 0) = 0
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
                    c.mediation_deadline_date, c.is_paused,
                    co.complaint_id, co.complaint_number, co.complaint_title, co.incident_purok,
                    cat.category_name,
                    s.settlement_date, s.pb_attested, s.compliance_status,
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
             LEFT JOIN settlements s ON s.case_id = c.case_id
             WHERE c.docket_date BETWEEN ? AND ?
             ORDER BY c.docket_date DESC, c.case_id DESC"
        );
        $casesStmt->execute($params);
        $casesList = $casesStmt->fetchAll(PDO::FETCH_ASSOC);

        $calendarHearingsStmt = $this->conn->query(
            "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status, h.queue_status,
                        h.actual_start_time, h.complainant_attendance, h.respondent_attendance, h.attendance_notes, h.mediation_attempt, {$hQueueCol} AS queue_order,
                        c.case_id, c.case_number, co.complaint_id, co.complaint_title,
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
             WHERE h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
               AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
             ORDER BY h.hearing_date ASC"
        );
        $calendarHearings = $calendarHearingsStmt->fetchAll(PDO::FETCH_ASSOC);
        $calendarHearings = array_merge($calendarHearings, $this->getCalendarPaanyayaItems());
        usort($calendarHearings, static fn(array $a, array $b): int => strcmp((string) $a['hearing_date'], (string) $b['hearing_date']));

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

        $basePayload = [
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

        return array_merge($basePayload, $this->getCaptainExecutiveInsights($period, $startDate, $endDate, $basePayload));
    }

    /** Calendar uses one event shape for active formal hearings and Paanyaya sessions. */
    private function getCalendarPaanyayaItems(): array
    {
        $psQueueCol = $this->columnExists('paanyaya_sessions', 'queue_order') ? 'COALESCE(ps.queue_order, 0)' : '0';
        $stmt = $this->conn->query("\n            SELECT CONCAT('paanyaya-', ps.session_id) AS hearing_id,
                   c.case_id, 'Paanyaya' AS hearing_type,
                   CONCAT(ps.scheduled_date, ' ', COALESCE(ps.scheduled_time, '09:00:00')) AS hearing_date,
                   ps.venue, COALESCE(p.status, 'Scheduled') AS status,
                   COALESCE(ps.queue_status, 'Waiting') AS queue_status,
                   ps.actual_start_time, NULL AS complainant_attendance, NULL AS respondent_attendance,
                   ps.session_remarks AS attendance_notes, ps.attempt_number AS mediation_attempt,
                   {$psQueueCol} AS queue_order,
                   co.complaint_id, COALESCE(c.case_number, co.complaint_number) AS case_number,
                   co.complaint_title,
                   COALESCE(p.complainant_name, '') AS complainant_names,
                   COALESCE(ps.invitee_name, p.invitee_name, '') AS respondent_names
            FROM paanyaya_sessions ps
            INNER JOIN complaints co ON co.complaint_id = ps.complaint_id
            LEFT JOIN cases c ON c.complaint_id = co.complaint_id
            LEFT JOIN paanyayas p ON p.complaint_id = co.complaint_id
                AND p.paanyaya_id = (SELECT MAX(p2.paanyaya_id) FROM paanyayas p2 WHERE p2.complaint_id = co.complaint_id)
            WHERE COALESCE(p.status, 'Scheduled') IN ('Scheduled', 'Pending')
              AND ps.session_outcome IS NULL
              AND COALESCE(ps.queue_status, 'Waiting') <> 'Concluded'
              AND NOT EXISTS (
                  SELECT 1 FROM paanyaya_sessions later_ps
                  WHERE later_ps.complaint_id = ps.complaint_id
                    AND later_ps.attempt_number > ps.attempt_number
                    AND later_ps.session_outcome IS NULL
                    AND COALESCE(later_ps.queue_status, 'Waiting') <> 'Concluded'
              )
            ORDER BY ps.scheduled_date ASC, ps.scheduled_time ASC\n        ");
        $sessionItems = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        // Stand-alone invitations (not yet mirrored to paanyaya_sessions)
        // must also appear on the dashboard calendar.
        $inviteStmt = $this->conn->query("\n            SELECT CONCAT('paanyaya-invitation-', p.paanyaya_id) AS hearing_id,
                   c.case_id, 'Paanyaya' AS hearing_type,
                   CONCAT(p.scheduled_date, ' ', COALESCE(p.scheduled_time, '09:00:00')) AS hearing_date,
                   p.venue, COALESCE(p.status, 'Scheduled') AS status,
                   'Waiting' AS queue_status, NULL AS actual_start_time,
                   NULL AS complainant_attendance, NULL AS respondent_attendance,
                   NULL AS attendance_notes, 1 AS mediation_attempt, 0 AS queue_order,
                   co.complaint_id, COALESCE(c.case_number, co.complaint_number) AS case_number,
                   co.complaint_title, COALESCE(p.complainant_name, '') AS complainant_names,
                   COALESCE(p.invitee_name, '') AS respondent_names
            FROM paanyayas p
            LEFT JOIN complaints co ON co.complaint_id = p.complaint_id
            LEFT JOIN cases c ON c.complaint_id = co.complaint_id
            WHERE COALESCE(p.status, 'Scheduled') IN ('Scheduled', 'Pending')
              AND NOT EXISTS (SELECT 1 FROM paanyaya_sessions ps WHERE ps.complaint_id = p.complaint_id)
            ORDER BY p.scheduled_date ASC, p.scheduled_time ASC\n        ");
        $invitationItems = $inviteStmt ? $inviteStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return array_merge($sessionItems, $invitationItems);
    }

    /**
     * Executive intelligence payload tailored for the Punong Barangay (Barangay Captain).
     */
    private function getCaptainExecutiveInsights(string $period, ?string $startDate, ?string $endDate, array $base): array
    {
        $today = date('Y-m-d');
        $nextSeven = date('Y-m-d', strtotime('+7 days'));
        $hasPeriod = !empty($startDate) && !empty($endDate);

        // 1. Official Barangay / Captain settings
        $settingsRows = $this->safeFetchAll(
            "SELECT setting_key, setting_value FROM system_settings
             WHERE setting_key IN ('captain_name', 'lupon_secretary_name', 'barangay_name', 'city_name')"
        );
        $settingsMap = [];
        foreach ($settingsRows as $sr) {
            $settingsMap[(string) $sr['setting_key']] = (string) $sr['setting_value'];
        }
        $captainInfo = [
            'captain_name' => $settingsMap['captain_name'] ?? 'HON. AKIKO LYNNE CENTENO',
            'lupon_secretary_name' => $settingsMap['lupon_secretary_name'] ?? 'Barangay Secretary',
            'barangay_name' => $settingsMap['barangay_name'] ?? 'Barangay Tumana',
            'city_name' => $settingsMap['city_name'] ?? 'Marikina City',
        ];

        // 2. Complaint volume & previous period comparison
        $totalDisputes = $hasPeriod
            ? $this->safeScalar(
                "SELECT COUNT(*) FROM complaints WHERE COALESCE(is_draft, 0) = 0 AND DATE(created_at) BETWEEN ? AND ?",
                [$startDate, $endDate]
            )
            : $this->safeScalar("SELECT COUNT(*) FROM complaints WHERE COALESCE(is_draft, 0) = 0");

        if ($hasPeriod) {
            $startTs = strtotime($startDate);
            $endTs = strtotime($endDate);
            $spanDays = max(1, (int) round(($endTs - $startTs) / 86400) + 1);
            $prevEnd = date('Y-m-d', strtotime('-1 day', $startTs));
            $prevStart = date('Y-m-d', strtotime('-' . ($spanDays - 1) . ' days', strtotime($prevEnd)));
            $currentCompare = $totalDisputes;
            $previousCompare = $this->safeScalar(
                "SELECT COUNT(*) FROM complaints WHERE COALESCE(is_draft, 0) = 0 AND DATE(created_at) BETWEEN ? AND ?",
                [$prevStart, $prevEnd]
            );
        } else {
            // In All Time mode, compare last 30 days vs prior 30 days for meaningful trend delta
            $currStart = date('Y-m-d', strtotime('-29 days'));
            $prevEnd = date('Y-m-d', strtotime('-30 days'));
            $prevStart = date('Y-m-d', strtotime('-59 days'));
            $currentCompare = $this->safeScalar(
                "SELECT COUNT(*) FROM complaints WHERE COALESCE(is_draft, 0) = 0 AND DATE(created_at) BETWEEN ? AND ?",
                [$currStart, $today]
            );
            $previousCompare = $this->safeScalar(
                "SELECT COUNT(*) FROM complaints WHERE COALESCE(is_draft, 0) = 0 AND DATE(created_at) BETWEEN ? AND ?",
                [$prevStart, $prevEnd]
            );
        }
        $disputesDeltaPct = null;
        if ($previousCompare > 0) {
            $disputesDeltaPct = round((($currentCompare - $previousCompare) / $previousCompare) * 100, 1);
        } elseif ($currentCompare > 0) {
            $disputesDeltaPct = 100.0;
        }

        // 3. Statutory mediation clocks & PB oversight metrics
        $pbMediationDueSoon = $this->safeScalar(
            "SELECT COUNT(*) FROM cases
             WHERE case_status IN ('Mediation', 'Conciliation')
               AND COALESCE(is_paused, 0) = 0
               AND mediation_deadline_date IS NOT NULL
               AND mediation_deadline_date BETWEEN ? AND ?",
            [$today, $nextSeven]
        );
        $pbMediationOverdue = $this->safeScalar(
            "SELECT COUNT(*) FROM cases
             WHERE case_status IN ('Mediation', 'Conciliation')
               AND COALESCE(is_paused, 0) = 0
               AND mediation_deadline_date IS NOT NULL
               AND mediation_deadline_date < ?",
            [$today]
        );
        $pbMediationPaused = $this->safeScalar(
            "SELECT COUNT(*) FROM cases WHERE case_status IN ('Mediation', 'Conciliation') AND COALESCE(is_paused, 0) = 1"
        );

        $pendingScreening = $this->safeScalar(
            "SELECT COUNT(*) FROM complaints
             WHERE status IN ('Filed', 'Under Review', 'Needs Information', 'Pending Review')
               AND COALESCE(is_draft, 0) = 0"
        );

        // Resident verification queue (mirrors backend/api/users/resident-verification.php)
        $pendingVerificationsList = $this->getPendingResidentVerifications();
        $pendingResidentVerifications = count($pendingVerificationsList);

        $pendingPbAttestations = $this->tableExists('settlements')
            ? $this->safeScalar("SELECT COUNT(*) FROM settlements WHERE COALESCE(pb_attested, 0) = 0")
            : 0;

        $pendingTransmittals = 0;
        if ($this->tableExists('settlements') && $this->tableExists('case_transmittals')) {
            $pendingTransmittals = $this->safeScalar(
                "SELECT COUNT(DISTINCT c.case_id)
                 FROM cases c
                 LEFT JOIN settlements s ON s.case_id = c.case_id
                 LEFT JOIN case_transmittals ct ON ct.case_id = c.case_id
                 WHERE (c.case_status = 'Settled' OR s.settlement_id IS NOT NULL)
                   AND (ct.transmittal_id IS NULL OR ct.status IN ('Pending', 'Generated'))"
            );
        }

        $pendingPangkatChallenges = $this->tableExists('pangkat_challenges')
            ? $this->safeScalar("SELECT COUNT(*) FROM pangkat_challenges WHERE status = 'Pending'")
            : 0;

        $pangkatConveningOverdue = $this->tableExists('pangkat_groups')
            ? $this->safeScalar(
                "SELECT COUNT(*) FROM pangkat_groups
                 WHERE convened_date IS NULL
                   AND convening_deadline IS NOT NULL
                   AND convening_deadline < ?",
                [$today]
            )
            : 0;

        $breachedCount = $this->tableExists('settlements')
            ? $this->safeScalar("SELECT COUNT(*) FROM settlements WHERE compliance_status IN ('Overdue', 'Breached', 'Violated')")
            : 0;
        if ($this->tableExists('settlement_executions')) {
            $breachedCount += $this->safeScalar(
                "SELECT COUNT(*) FROM settlement_executions WHERE execution_status NOT IN ('Complied Under Execution', 'Endorsed to Court')"
            );
        }
        if ($this->tableExists('execution_proceedings')) {
            $breachedCount += $this->safeScalar(
                "SELECT COUNT(*) FROM execution_proceedings WHERE execution_status NOT IN ('Enforced / Complied', 'Lapsed to MTC')"
            );
        }

        $pendingLegalActions = $this->tableExists('hearing_legal_actions')
            ? $this->safeScalar("SELECT COUNT(*) FROM hearing_legal_actions WHERE status = 'Pending Review'")
            : 0;

        $unservedSummons = $this->tableExists('summon_deliveries')
            ? $this->safeScalar(
                "SELECT COUNT(DISTINCT sd.delivery_id)
                 FROM summon_deliveries sd
                 INNER JOIN cases c ON c.case_id = sd.case_id
                 WHERE c.case_status IN ('Mediation', 'Conciliation')
                   AND sd.delivery_status IN ('Pending', 'Unserved')"
            )
            : 0;

        $slaRows = $this->tableExists('summon_deliveries')
            ? $this->safeFetchAll(
                "SELECT
                    SUM(CASE WHEN served_at IS NOT NULL AND created_at IS NOT NULL AND DATEDIFF(DATE(served_at), DATE(created_at)) <= 1 THEN 1 ELSE 0 END) AS on_time,
                    SUM(CASE WHEN served_at IS NOT NULL THEN 1 ELSE 0 END) AS served_total
                 FROM summon_deliveries
                 WHERE attempt_number = 1"
            )
            : [];
        $slaOnTime = (int) ($slaRows[0]['on_time'] ?? 0);
        $slaTotal = (int) ($slaRows[0]['served_total'] ?? 0);
        $slaPct = $slaTotal > 0 ? round(($slaOnTime / $slaTotal) * 100) : 100;

        $activeLuponMembers = $this->safeScalar(
            "SELECT COUNT(*) FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_name = 'Lupon Member' AND u.status = 'Active'"
        );

        $paanyayaResolved = $hasPeriod
            ? $this->safeScalar(
                "SELECT COUNT(*) FROM complaints
                 WHERE (status LIKE 'Resolved%' OR paanyaya_1_outcome = 'Settled' OR COALESCE(resolved_without_docketing, 0) = 1)
                   AND DATE(created_at) BETWEEN ? AND ?",
                [$startDate, $endDate]
            )
            : $this->safeScalar(
                "SELECT COUNT(*) FROM complaints
                 WHERE status LIKE 'Resolved%' OR paanyaya_1_outcome = 'Settled' OR COALESCE(resolved_without_docketing, 0) = 1"
            );

        $settledFormal = (int) ($base['settled_cases'] ?? 0);
        $totalCases = (int) ($base['total_cases'] ?? 0);
        $totalResolvedAll = $settledFormal + $paanyayaResolved;
        $settlementRatePct = $totalCases > 0 ? (int) round(($settledFormal / $totalCases) * 100) : 0;
        $denomDisputes = max($totalDisputes, $totalCases + $paanyayaResolved);
        $overallResolutionPct = $denomDisputes > 0 ? (int) round(($totalResolvedAll / $denomDisputes) * 100) : $settlementRatePct;
        $ltiaSavings = $totalResolvedAll * 9500;

        $actionRequiredTotal = $pendingScreening
            + $pendingResidentVerifications
            + $pendingPbAttestations
            + $pendingTransmittals
            + $pendingPangkatChallenges
            + $pangkatConveningOverdue
            + $pbMediationOverdue
            + $breachedCount;

        // 4. Priority Action Queue items for Barangay Captain
        $priorityActions = [];

        foreach ($this->safeFetchAll(
            "SELECT c.case_id, c.case_number, c.case_status, c.mediation_deadline_date, co.complaint_title, co.incident_purok
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE c.case_status IN ('Mediation', 'Conciliation')
               AND COALESCE(c.is_paused, 0) = 0
               AND c.mediation_deadline_date IS NOT NULL
               AND c.mediation_deadline_date <= ?
             ORDER BY c.mediation_deadline_date ASC
             LIMIT 6",
            [$nextSeven]
        ) as $row) {
            $isOverdue = ($row['mediation_deadline_date'] < $today);
            $priorityActions[] = [
                'type' => 'deadline',
                'urgency' => $isOverdue ? 'critical' : 'warning',
                'badge' => $isOverdue ? 'Overdue Clock' : 'Due Soon',
                'title' => 'Case #' . ($row['case_number'] ?: $row['case_id']) . ' · ' . ($row['complaint_title'] ?: 'Mediation Case'),
                'subtitle' => $row['case_status'] . (!empty($row['incident_purok']) ? ' · ' . $row['incident_purok'] : ''),
                'meta' => 'Deadline: ' . $row['mediation_deadline_date'],
                'link_url' => '../cases/case-details.php?id=' . (int) $row['case_id'],
                'action_label' => 'Open Case',
            ];
        }

        foreach ($this->safeFetchAll(
            "SELECT s.settlement_id, s.case_id, s.settlement_date, s.repudiation_deadline, s.pb_attested,
                    c.case_number, co.complaint_title, ct.status AS transmittal_status
             FROM settlements s
             INNER JOIN cases c ON c.case_id = s.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             LEFT JOIN case_transmittals ct ON ct.case_id = c.case_id
             WHERE COALESCE(s.pb_attested, 0) = 0
                OR ct.transmittal_id IS NULL
                OR ct.status IN ('Pending', 'Generated')
             ORDER BY s.settlement_date ASC
             LIMIT 6"
        ) as $row) {
            $needsAttest = ((int) ($row['pb_attested'] ?? 0) === 0);
            $priorityActions[] = [
                'type' => 'settlement',
                'urgency' => $needsAttest ? 'warning' : 'info',
                'badge' => $needsAttest ? 'Needs PB Attestation' : 'MTC/DILG Transmittal',
                'title' => 'Case #' . ($row['case_number'] ?: $row['case_id']) . ' · ' . ($row['complaint_title'] ?: 'Amicable Settlement'),
                'subtitle' => $needsAttest
                    ? 'Amicable Settlement awaiting Punong Barangay attestation'
                    : '10-working-day transmittal status: ' . ($row['transmittal_status'] ?: 'Pending'),
                'meta' => !empty($row['repudiation_deadline']) ? 'Repudiation ends ' . $row['repudiation_deadline'] : 'Settled ' . $row['settlement_date'],
                'link_url' => '../cases/case-details.php?id=' . (int) $row['case_id'],
                'action_label' => $needsAttest ? 'Attest' : 'Transmit',
            ];
        }

        foreach ($this->safeFetchAll(
            "SELECT p.pangkat_id, p.case_id, p.convening_deadline, c.case_number, co.complaint_title
             FROM pangkat_groups p
             INNER JOIN cases c ON c.case_id = p.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE p.convened_date IS NULL
               AND p.convening_deadline IS NOT NULL
               AND p.convening_deadline < ?
             ORDER BY p.convening_deadline ASC
             LIMIT 4",
            [$today]
        ) as $row) {
            $priorityActions[] = [
                'type' => 'pangkat',
                'urgency' => 'critical',
                'badge' => 'Pangkat Overdue',
                'title' => 'Case #' . ($row['case_number'] ?: $row['case_id']) . ' · ' . ($row['complaint_title'] ?: 'Pangkat Convening'),
                'subtitle' => 'Pangkat panel constituted but not yet convened',
                'meta' => 'Due: ' . $row['convening_deadline'],
                'link_url' => '../cases/case-details.php?id=' . (int) $row['case_id'],
                'action_label' => 'Review Pangkat',
            ];
        }

        $pendingComplaintsList = $this->safeFetchAll(
            "SELECT co.complaint_id, co.complaint_number, co.complaint_title, co.status, co.complaint_source,
                    co.incident_purok, DATE(co.created_at) AS filed_date
             FROM complaints co
             WHERE co.status IN ('Filed', 'Under Review', 'Needs Information', 'Pending Review')
               AND COALESCE(co.is_draft, 0) = 0
             ORDER BY co.created_at DESC
             LIMIT 15"
        );
        foreach (array_slice($pendingComplaintsList, 0, 4) as $row) {
            $priorityActions[] = [
                'type' => 'complaint',
                'urgency' => 'info',
                'badge' => $row['status'] ?: 'Needs Review',
                'title' => ($row['complaint_number'] ? '#' . $row['complaint_number'] . ' · ' : '') . ($row['complaint_title'] ?: 'New Complaint'),
                'subtitle' => ($row['complaint_source'] ?: 'Walk-in') . ' intake' . (!empty($row['incident_purok']) ? ' · ' . $row['incident_purok'] : ''),
                'meta' => 'Filed: ' . $row['filed_date'],
                'link_url' => '../complaints/complaint-details.php?id=' . (int) $row['complaint_id'],
                'action_label' => 'Screen',
            ];
        }

        foreach (array_slice($pendingVerificationsList, 0, 3) as $rv) {
            $priorityActions[] = [
                'type' => 'resident',
                'urgency' => $rv['profile_complete'] ? 'warning' : 'info',
                'badge' => $rv['profile_complete'] ? 'Ready to Verify' : 'Incomplete Profile',
                'title' => ($rv['full_name'] ?: $rv['username']) . ' (@' . $rv['username'] . ')',
                'subtitle' => !empty($rv['purok']) ? $rv['purok'] . ($rv['address'] ? ' — ' . $rv['address'] : '') : ($rv['email'] ?: 'Resident Portal registration'),
                'meta' => 'Registered ' . substr((string) $rv['created_at'], 0, 10),
                'link_url' => '../users/resident-verification.php',
                'action_label' => 'Verify',
            ];
        }

        $transmittalsDueList = $this->tableExists('settlements')
            ? $this->safeFetchAll(
                "SELECT s.settlement_id, s.case_id, s.settlement_date, s.pb_attested, s.compliance_status,
                        s.repudiation_deadline, c.case_number, co.complaint_title,
                        ct.transmittal_number, COALESCE(ct.status, 'Pending') AS transmittal_status,
                        ct.ten_day_expiry_date
                 FROM settlements s
                 INNER JOIN cases c ON c.case_id = s.case_id
                 INNER JOIN complaints co ON co.complaint_id = c.complaint_id
                 LEFT JOIN case_transmittals ct ON ct.case_id = c.case_id
                 ORDER BY s.settlement_date DESC
                 LIMIT 15"
            )
            : [];

        return [
            'captain_info' => $captainInfo,
            'captain_summary' => [
                'total_disputes' => $totalDisputes,
                'previous_disputes' => $previousCompare,
                'disputes_delta_pct' => $disputesDeltaPct,
                'pb_mediation_active' => (int) ($base['mediation_cases'] ?? 0),
                'pb_mediation_due_soon' => $pbMediationDueSoon,
                'pb_mediation_overdue' => $pbMediationOverdue,
                'pb_mediation_paused' => $pbMediationPaused,
                'pending_screening' => $pendingScreening,
                'pending_resident_verifications' => $pendingResidentVerifications,
                'pending_pb_attestations' => $pendingPbAttestations,
                'pending_transmittals' => $pendingTransmittals,
                'pending_pangkat_challenges' => $pendingPangkatChallenges,
                'pangkat_convening_overdue' => $pangkatConveningOverdue,
                'breached_or_execution' => $breachedCount,
                'pending_legal_actions' => $pendingLegalActions,
                'unserved_summons' => $unservedSummons,
                'paanyaya_resolved' => $paanyayaResolved,
                'total_resolved_all' => $totalResolvedAll,
                'settlement_rate_pct' => $settlementRatePct,
                'overall_resolution_rate_pct' => $overallResolutionPct,
                'ltia_estimated_savings' => $ltiaSavings,
                'summons_sla_on_time' => $slaOnTime,
                'summons_sla_total' => $slaTotal,
                'summons_sla_pct' => $slaPct,
                'active_lupon_members' => $activeLuponMembers,
                'action_required_total' => $actionRequiredTotal,
            ],
            'trend_series' => $this->buildTrendSeries($period, $startDate, $endDate),
            'weekday_activity' => $this->buildWeekdayActivity($startDate, $endDate),
            'priority_actions' => array_slice($priorityActions, 0, 15),
            'pending_verifications' => array_slice($pendingVerificationsList, 0, 15),
            'pending_complaints' => $pendingComplaintsList,
            'transmittals_due' => $transmittalsDueList,
        ];
    }

    private function getPendingResidentVerifications(): array
    {
        $users = $this->safeFetchAll(
            "SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.username,
                    u.email, u.contact_no, u.status, u.created_at
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_name = 'Resident'
             ORDER BY u.created_at DESC, u.user_id DESC"
        );
        if (empty($users)) {
            return [];
        }
        $pending = [];
        foreach ($users as $u) {
            $res = null;
            $email = trim((string) ($u['email'] ?? ''));
            if ($email !== '') {
                $rows = $this->safeFetchAll('SELECT resident_id, email, contact_no, purok, address, residency_verified FROM residents WHERE LOWER(email) = LOWER(?) LIMIT 1', [$email]);
                $res = $rows[0] ?? null;
            }
            if (!$res) {
                $first = trim((string) ($u['first_name'] ?? ''));
                $last = trim((string) ($u['last_name'] ?? ''));
                if ($first !== '' && $last !== '') {
                    $rows = $this->safeFetchAll('SELECT resident_id, email, contact_no, purok, address, residency_verified FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1', [$first, $last]);
                    $res = $rows[0] ?? null;
                }
            }
            if (!empty($res['residency_verified'])) {
                continue;
            }
            $rEmail = trim((string) ($res['email'] ?? $u['email'] ?? ''));
            $rContact = trim((string) ($res['contact_no'] ?? $u['contact_no'] ?? ''));
            $rPurok = trim((string) ($res['purok'] ?? ''));
            $rAddress = trim((string) ($res['address'] ?? ''));
            $pending[] = [
                'user_id' => (int) $u['user_id'],
                'full_name' => trim(implode(' ', array_filter([$u['first_name'] ?? '', $u['middle_name'] ?? '', $u['last_name'] ?? '']))),
                'username' => (string) ($u['username'] ?? ''),
                'email' => $rEmail,
                'contact_no' => $rContact,
                'purok' => $rPurok,
                'address' => $rAddress,
                'profile_complete' => ($rEmail !== '' && $rContact !== '' && ($rAddress !== '' || $rPurok !== '')),
                'created_at' => (string) ($u['created_at'] ?? ''),
            ];
        }
        return $pending;
    }

    private function buildTrendSeries(string $period, ?string $startDate, ?string $endDate): array
    {
        $buckets = [];
        $today = date('Y-m-d');

        if ($period === 'weekly' && $startDate && $endDate) {
            $cursor = strtotime($startDate);
            $endTs = strtotime($endDate);
            while ($cursor <= $endTs) {
                $d = date('Y-m-d', $cursor);
                $prevD = date('Y-m-d', strtotime('-7 days', $cursor));
                $buckets[] = ['label' => date('D j', $cursor), 'start' => $d, 'end' => $d, 'prev_start' => $prevD, 'prev_end' => $prevD];
                $cursor = strtotime('+1 day', $cursor);
            }
        } elseif ($period === 'monthly' && $startDate && $endDate) {
            // Split month into ~6 intervals of 5 days each
            $startTs = strtotime($startDate);
            $endTs = strtotime($endDate);
            $cursor = $startTs;
            while ($cursor <= $endTs) {
                $chunkEnd = min($endTs, strtotime('+4 days', $cursor));
                $s = date('Y-m-d', $cursor);
                $e = date('Y-m-d', $chunkEnd);
                $ps = date('Y-m-d', strtotime('-1 month', $cursor));
                $pe = date('Y-m-d', strtotime('-1 month', $chunkEnd));
                $buckets[] = ['label' => date('M j', $cursor), 'start' => $s, 'end' => $e, 'prev_start' => $ps, 'prev_end' => $pe];
                $cursor = strtotime('+1 day', $chunkEnd);
            }
        } elseif ($period === 'quarterly' && $startDate && $endDate) {
            $cursor = strtotime($startDate);
            $endTs = strtotime($endDate);
            while ($cursor <= $endTs) {
                $chunkEnd = min($endTs, strtotime('+13 days', $cursor));
                $s = date('Y-m-d', $cursor);
                $e = date('Y-m-d', $chunkEnd);
                $ps = date('Y-m-d', strtotime('-3 months', $cursor));
                $pe = date('Y-m-d', strtotime('-3 months', $chunkEnd));
                $buckets[] = ['label' => date('M j', $cursor), 'start' => $s, 'end' => $e, 'prev_start' => $ps, 'prev_end' => $pe];
                $cursor = strtotime('+1 day', $chunkEnd);
            }
        } elseif ($period === 'annual' && $startDate) {
            $year = (int) date('Y', strtotime($startDate));
            for ($m = 1; $m <= 12; $m++) {
                $s = sprintf('%04d-%02d-01', $year, $m);
                $e = date('Y-m-t', strtotime($s));
                $ps = sprintf('%04d-%02d-01', $year - 1, $m);
                $pe = date('Y-m-t', strtotime($ps));
                $buckets[] = ['label' => date('M', strtotime($s)), 'start' => $s, 'end' => $e, 'prev_start' => $ps, 'prev_end' => $pe];
            }
        } else {
            // All Time: determine range from earliest complaint/case up to today
            $minDateRow = $this->safeFetchAll(
                "SELECT LEAST(
                    COALESCE((SELECT MIN(DATE(created_at)) FROM complaints), ?),
                    COALESCE((SELECT MIN(docket_date) FROM cases), ?)
                ) AS min_d",
                [$today, $today]
            );
            $minDate = $minDateRow[0]['min_d'] ?? $today;
            $spanDays = max(1, (int) round((strtotime($today) - strtotime($minDate)) / 86400) + 1);
            if ($spanDays <= 18) {
                $winStart = strtotime('-' . max(6, min(13, $spanDays - 1)) . ' days', strtotime($today));
                for ($c = $winStart; $c <= strtotime($today); $c = strtotime('+1 day', $c)) {
                    $d = date('Y-m-d', $c);
                    $pd = date('Y-m-d', strtotime('-14 days', $c));
                    $buckets[] = ['label' => date('M j', $c), 'start' => $d, 'end' => $d, 'prev_start' => $pd, 'prev_end' => $pd];
                }
            } elseif ($spanDays <= 120) {
                $winStart = strtotime('-' . min(83, $spanDays - 1) . ' days', strtotime($today));
                for ($c = $winStart; $c <= strtotime($today); $c = strtotime('+7 days', $c)) {
                    $chunkEnd = min(strtotime($today), strtotime('+6 days', $c));
                    $s = date('Y-m-d', $c);
                    $e = date('Y-m-d', $chunkEnd);
                    $ps = date('Y-m-d', strtotime('-7 days', $c));
                    $pe = date('Y-m-d', strtotime('-7 days', $chunkEnd));
                    $buckets[] = ['label' => date('M j', $c), 'start' => $s, 'end' => $e, 'prev_start' => $ps, 'prev_end' => $pe];
                }
            } else {
                for ($i = 11; $i >= 0; $i--) {
                    $mTs = strtotime("-$i months", strtotime(date('Y-m-01')));
                    $s = date('Y-m-01', $mTs);
                    $e = date('Y-m-t', $mTs);
                    $ps = date('Y-m-01', strtotime('-1 year', $mTs));
                    $pe = date('Y-m-t', strtotime('-1 year', $mTs));
                    $buckets[] = ['label' => date('M y', $mTs), 'start' => $s, 'end' => $e, 'prev_start' => $ps, 'prev_end' => $pe];
                }
            }
        }

        if (empty($buckets)) {
            return [];
        }

        $rangeMin = $buckets[0]['prev_start'];
        $rangeMax = $buckets[count($buckets) - 1]['end'];

        $filedRows = $this->safeFetchAll(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM complaints
             WHERE COALESCE(is_draft, 0) = 0 AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)",
            [$rangeMin, $rangeMax]
        );
        $filedByDay = [];
        foreach ($filedRows as $r) {
            $filedByDay[(string) $r['d']] = (int) $r['c'];
        }

        $resolvedRows = $this->safeFetchAll(
            "SELECT d, COUNT(*) AS c FROM (
                SELECT COALESCE(s.settlement_date, c.docket_date) AS d
                FROM cases c
                LEFT JOIN settlements s ON s.case_id = c.case_id
                WHERE (c.case_status = 'Settled' OR s.settlement_date IS NOT NULL)
                  AND COALESCE(s.settlement_date, c.docket_date) BETWEEN ? AND ?
                UNION ALL
                SELECT DATE(created_at) AS d
                FROM complaints
                WHERE (status LIKE 'Resolved%' OR paanyaya_1_outcome = 'Settled' OR COALESCE(resolved_without_docketing, 0) = 1)
                  AND DATE(created_at) BETWEEN ? AND ?
            ) t GROUP BY d",
            [$rangeMin, $rangeMax, $rangeMin, $rangeMax]
        );
        $resolvedByDay = [];
        foreach ($resolvedRows as $r) {
            $resolvedByDay[(string) $r['d']] = (int) $r['c'];
        }

        $sumBetween = static function (array $map, string $s, string $e): int {
            $sum = 0;
            foreach ($map as $d => $val) {
                if ($d >= $s && $d <= $e) {
                    $sum += $val;
                }
            }
            return $sum;
        };

        $series = [];
        foreach ($buckets as $b) {
            $series[] = [
                'label' => $b['label'],
                'filed' => $sumBetween($filedByDay, $b['start'], $b['end']),
                'resolved' => $sumBetween($resolvedByDay, $b['start'], $b['end']),
                'previous_filed' => $sumBetween($filedByDay, $b['prev_start'], $b['prev_end']),
            ];
        }
        return $series;
    }

    private function buildWeekdayActivity(?string $startDate, ?string $endDate): array
    {
        $hasPeriod = !empty($startDate) && !empty($endDate);
        $params = $hasPeriod ? [$startDate, $endDate] : [];

        $hRows = $this->safeFetchAll(
            "SELECT DAYOFWEEK(hearing_date) AS dow, COUNT(*) AS cnt
             FROM hearings
             WHERE status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')" .
             ($hasPeriod ? " AND DATE(hearing_date) BETWEEN ? AND ?" : "") . "
             GROUP BY DAYOFWEEK(hearing_date)",
            $params
        );
        $cRows = $this->safeFetchAll(
            "SELECT DAYOFWEEK(created_at) AS dow, COUNT(*) AS cnt
             FROM complaints
             WHERE COALESCE(is_draft, 0) = 0" .
             ($hasPeriod ? " AND DATE(created_at) BETWEEN ? AND ?" : "") . "
             GROUP BY DAYOFWEEK(created_at)",
            $params
        );

        $hMap = [];
        foreach ($hRows as $r) {
            $hMap[(int) $r['dow']] = (int) $r['cnt'];
        }
        $cMap = [];
        foreach ($cRows as $r) {
            $cMap[(int) $r['dow']] = (int) $r['cnt'];
        }

        $days = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
        $items = [];
        $maxTotal = 0;
        $peakDow = 3; // Default Tuesday (Mediation Day) if all 0

        foreach ($days as $dow => $label) {
            $h = $hMap[$dow] ?? 0;
            $c = $cMap[$dow] ?? 0;
            $tot = $h + $c;
            if ($tot > $maxTotal) {
                $maxTotal = $tot;
                $peakDow = $dow;
            }
            $items[] = [
                'dow' => $dow,
                'label' => $label,
                'hearings_count' => $h,
                'complaints_count' => $c,
                'total_count' => $tot,
                'is_peak' => false,
            ];
        }
        foreach ($items as &$item) {
            if ($item['dow'] === $peakDow && ($maxTotal > 0 || $item['dow'] === 3)) {
                $item['is_peak'] = true;
            }
        }
        unset($item);

        return $items;
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
