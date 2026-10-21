<?php

require_once __DIR__ . '/../config/database.php';

class Report
{
    private $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function dashboardStats(string $period = 'all', ?string $startDate = null, ?string $endDate = null): array
    {
        $today = date('Y-m-d');
        $nextSevenDays = date('Y-m-d', strtotime('+7 days'));

        if ($period === 'all' || !$startDate || !$endDate) {
            $statusRows = $this->conn->query(
                'SELECT case_status AS status, COUNT(*) AS total
                 FROM cases
                 GROUP BY case_status
                 ORDER BY total DESC, case_status ASC'
            )->fetchAll(PDO::FETCH_ASSOC);

            $todayHearings = $this->conn->prepare(
                "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                        c.case_id, c.case_number, co.complaint_title
                 FROM hearings h
                 INNER JOIN cases c ON c.case_id = h.case_id
                 INNER JOIN complaints co ON co.complaint_id = c.complaint_id
                 WHERE DATE(h.hearing_date) = ?
                   AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
                 ORDER BY h.hearing_date ASC
                 LIMIT 8"
            );
            $todayHearings->execute([$today]);

            $deadlineCases = $this->conn->prepare(
                "SELECT case_id, case_number, case_status, mediation_deadline_date
                 FROM cases
                 WHERE case_status IN ('Mediation', 'Conciliation')
                   AND mediation_deadline_date IS NOT NULL
                   AND mediation_deadline_date <= ?
                 ORDER BY mediation_deadline_date ASC
                 LIMIT 8"
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
                   AND status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')"
            );
            $hearingCountToday->execute([$today]);

            $hearingCountWeek = $this->conn->prepare(
                "SELECT COUNT(*) FROM hearings
                 WHERE DATE(hearing_date) BETWEEN ? AND ?
                   AND status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')"
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
                 ORDER BY c.docket_date DESC, c.case_id DESC
                 LIMIT 50"
            );
            $casesList = $casesStmt->fetchAll(PDO::FETCH_ASSOC);

            $calendarHearingsStmt = $this->conn->query(
                "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                        c.case_id, c.case_number, co.complaint_title
                 FROM hearings h
                 INNER JOIN cases c ON c.case_id = h.case_id
                 INNER JOIN complaints co ON co.complaint_id = c.complaint_id
                 WHERE h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
                 ORDER BY h.hearing_date ASC"
            );
            $calendarHearings = $calendarHearingsStmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'total_cases' => (int) $this->conn->query('SELECT COUNT(*) FROM cases')->fetchColumn(),
                'settled_cases' => (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status = 'Settled'")->fetchColumn(),
                'cfa_cases' => (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status = 'CFA Issued'")->fetchColumn(),
                'archived_cases' => (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status = 'Archived'")->fetchColumn(),
                'pending_review' => (int) $this->conn->query("SELECT COUNT(*) FROM complaints WHERE status IN ('Under Review', 'Needs Information')")->fetchColumn(),
                'paanyaya_complaints' => (int) $this->conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Pre-Docketed (Paanyaya)'")->fetchColumn(),
                'draft_complaints' => 0,
                'hearings_today' => (int) $hearingCountToday->fetchColumn(),
                'hearings_this_week' => (int) $hearingCountWeek->fetchColumn(),
                'active_mediations' => (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status IN ('Mediation', 'Conciliation')")->fetchColumn(),
                'deadlines_due' => (int) $deadlineCount->fetchColumn(),
                'today_hearings' => $todayHearings->fetchAll(PDO::FETCH_ASSOC),
                'calendar_hearings' => $calendarHearings,
                'mediation_deadlines' => $deadlineRows,
                'status_totals' => $statusRows,
                'cases' => $casesList,
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
            "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                    c.case_id, c.case_number, co.complaint_title
             FROM hearings h
             INNER JOIN cases c ON c.case_id = h.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE DATE(h.hearing_date) BETWEEN ? AND ?
               AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
             ORDER BY h.hearing_date ASC
             LIMIT 15"
        );
        $hearingsStmt->execute($params);
        $periodHearings = $hearingsStmt->fetchAll(PDO::FETCH_ASSOC);

        $deadlineStmt = $this->conn->prepare(
            "SELECT case_id, case_number, case_status, mediation_deadline_date
             FROM cases
             WHERE case_status IN ('Mediation', 'Conciliation')
               AND mediation_deadline_date IS NOT NULL
               AND mediation_deadline_date BETWEEN ? AND ?
             ORDER BY mediation_deadline_date ASC
             LIMIT 15"
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

        $paanyayaStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM complaints
             WHERE status = 'Pre-Docketed (Paanyaya)'
               AND DATE(created_at) BETWEEN ? AND ?"
        );
        $paanyayaStmt->execute($params);

        $hearingCountStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM hearings
             WHERE DATE(hearing_date) BETWEEN ? AND ?
               AND status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')"
        );
        $hearingCountStmt->execute($params);

        $activeMediationStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM cases
             WHERE case_status IN ('Mediation', 'Conciliation')
               AND (docket_date BETWEEN ? AND ? OR (mediation_start_date IS NOT NULL AND mediation_start_date BETWEEN ? AND ?))"
        );
        $activeMediationStmt->execute([$startDate, $endDate, $startDate, $endDate]);

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
             ORDER BY c.docket_date DESC, c.case_id DESC
             LIMIT 100"
        );
        $casesStmt->execute($params);
        $casesList = $casesStmt->fetchAll(PDO::FETCH_ASSOC);

        $calendarHearingsStmt = $this->conn->query(
            "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                    c.case_id, c.case_number, co.complaint_title
             FROM hearings h
             INNER JOIN cases c ON c.case_id = h.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
             ORDER BY h.hearing_date ASC"
        );
        $calendarHearings = $calendarHearingsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_cases' => (int) $totalCasesStmt->fetchColumn(),
            'settled_cases' => (int) $settledStmt->fetchColumn(),
            'cfa_cases' => (int) $cfaStmt->fetchColumn(),
            'archived_cases' => (int) $archivedStmt->fetchColumn(),
            'pending_review' => (int) $pendingReviewStmt->fetchColumn(),
            'paanyaya_complaints' => (int) $paanyayaStmt->fetchColumn(),
            'draft_complaints' => 0,
            'hearings_today' => count($periodHearings),
            'hearings_this_week' => (int) $hearingCountStmt->fetchColumn(),
            'active_mediations' => (int) $activeMediationStmt->fetchColumn(),
            'deadlines_due' => (int) $deadlineCountStmt->fetchColumn(),
            'today_hearings' => $periodHearings,
            'calendar_hearings' => $calendarHearings,
            'mediation_deadlines' => $deadlineRows,
            'status_totals' => $statusRows,
            'cases' => $casesList,
        ];
    }

    public function getReport(string $startDate, string $endDate): array
    {
        $params = [$startDate, $endDate];
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
             WHERE c.docket_date BETWEEN ? AND ?
             ORDER BY c.docket_date ASC, c.case_number ASC"
        );
        $cases->execute($params);
        $caseRows = $cases->fetchAll(PDO::FETCH_ASSOC);

        $filed = $this->conn->prepare(
            'SELECT COUNT(*) FROM complaints WHERE DATE(created_at) BETWEEN ? AND ?'
        );
        $filed->execute($params);

        $categories = $this->conn->prepare(
            "SELECT cat.category_name, COUNT(*) AS total
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             INNER JOIN complaint_categories cat ON cat.category_id = co.category_id
             WHERE c.docket_date BETWEEN ? AND ?
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
            LEFT JOIN categories cat ON cat.category_id = co.category_id
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
