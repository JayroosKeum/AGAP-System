<?php

require_once __DIR__ . '/../config/database.php';

class Report
{
    private $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function dashboardStats(): array
    {
        $today = date('Y-m-d');
        $nextSevenDays = date('Y-m-d', strtotime('+7 days'));
        $statusRows = $this->conn->query(
            'SELECT case_status AS status, COUNT(*) AS total
             FROM cases
             GROUP BY case_status
             ORDER BY total DESC, case_status ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $todayHearings = $this->conn->prepare(
            "SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                    c.case_number, co.complaint_title
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

        return [
            'total_cases' => (int) $this->conn->query('SELECT COUNT(*) FROM cases')->fetchColumn(),
            'settled_cases' => (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status = 'Settled'")->fetchColumn(),
            'cfa_cases' => (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status = 'CFA Issued'")->fetchColumn(),
            'archived_cases' => (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status = 'Archived'")->fetchColumn(),
            'pending_review' => (int) $this->conn->query("SELECT COUNT(*) FROM complaints WHERE status IN ('Under Review', 'Needs Information')")->fetchColumn(),
            'draft_complaints' => (int) $this->conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Draft' OR is_draft = 1")->fetchColumn(),
            'hearings_today' => (int) $hearingCountToday->fetchColumn(),
            'hearings_this_week' => (int) $hearingCountWeek->fetchColumn(),
            'active_mediations' => (int) $this->conn->query("SELECT COUNT(*) FROM cases WHERE case_status IN ('Mediation', 'Conciliation')")->fetchColumn(),
            'deadlines_due' => (int) $deadlineCount->fetchColumn(),
            'today_hearings' => $todayHearings->fetchAll(PDO::FETCH_ASSOC),
            'mediation_deadlines' => $deadlineRows,
            'status_totals' => $statusRows,
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
}
