<?php

require_once __DIR__ . '/../config/database.php';

class Search
{
    private $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function records(array $filters): array
    {
        $where = [];
        $params = [];
        $term = trim($filters['q'] ?? '');

        if ($term !== '') {
            $where[] = "(c.case_number LIKE :term OR co.complaint_number LIKE :term OR co.complaint_title LIKE :term OR co.narrative LIKE :term OR EXISTS (SELECT 1 FROM complaint_parties cp_search INNER JOIN residents r_search ON r_search.resident_id = cp_search.resident_id WHERE cp_search.complaint_id = co.complaint_id AND CONCAT_WS(' ', r_search.first_name, r_search.middle_name, r_search.last_name) LIKE :term))";
            $params[':term'] = '%' . $term . '%';
        }

        $statuses = ['Filed', 'Under Review', 'Needs Information', 'Accepted', 'Rejected', 'Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived'];
        $filterStatus = $filters['status'] ?? '';

        if ($filterStatus === 'Under Review') {
            $where[] = "(c.case_id IS NULL AND co.status IN ('Filed', 'Under Review', 'Needs Information', 'Accepted') AND co.status NOT IN ('Dismissed', 'Rejected', 'Settled'))";
        } elseif ($filterStatus === 'Docketed') {
            $where[] = "(c.case_id IS NOT NULL OR c.case_status = 'Docketed' OR co.status = 'Docketed')";
        } elseif ($filterStatus === 'Mediation') {
            $where[] = "(EXISTS (SELECT 1 FROM hearings h_m WHERE h_m.case_id = c.case_id AND h_m.hearing_type = 'Mediation') OR c.case_status = 'Mediation' OR co.status = 'Mediation')";
        } elseif ($filterStatus === 'Conciliation') {
            $where[] = "(EXISTS (SELECT 1 FROM hearings h_c WHERE h_c.case_id = c.case_id AND h_c.hearing_type = 'Conciliation') OR c.case_status = 'Conciliation' OR co.status = 'Conciliation')";
        } elseif ($filterStatus === 'Arbitration') {
            $where[] = "(EXISTS (SELECT 1 FROM arbitration_records ar WHERE ar.case_id = c.case_id) OR EXISTS (SELECT 1 FROM hearings h_a WHERE h_a.case_id = c.case_id AND h_a.hearing_type = 'Arbitration') OR c.case_status = 'Arbitration' OR co.status = 'Arbitration')";
        } elseif ($filterStatus === 'Settled') {
            $where[] = "(EXISTS (SELECT 1 FROM settlements s WHERE s.case_id = c.case_id) OR c.case_status = 'Settled' OR co.status = 'Settled')";
        } elseif ($filterStatus === 'Dismissed') {
            $where[] = "(c.case_status = 'Dismissed' OR co.status IN ('Dismissed', 'Rejected'))";
        } elseif (in_array($filterStatus, ['CFA', 'CFA Issued'], true)) {
            $where[] = "(EXISTS (SELECT 1 FROM cfa_records cfa WHERE cfa.case_id = c.case_id) OR c.case_status = 'CFA Issued' OR co.status = 'CFA Issued')";
        } elseif ($filterStatus === 'in_progress') {
            $where[] = "(c.case_status IN ('Docketed', 'Mediation', 'Conciliation', 'Arbitration') OR EXISTS (SELECT 1 FROM hearings h_prog WHERE h_prog.case_id = c.case_id) OR (c.case_id IS NULL AND co.status IN ('Accepted', 'Mediation', 'Conciliation')))";
        } elseif (in_array($filterStatus, $statuses, true)) {
            $where[] = '(c.case_status = :case_status OR (c.case_id IS NULL AND co.status = :complaint_status))';
            $params[':case_status'] = $filterStatus;
            $params[':complaint_status'] = $filterStatus;
        }

        $intakeFilter = trim((string)($filters['intake_status'] ?? ''));
        if ($intakeFilter === 'Under Review') {
            $where[] = "(c.case_id IS NULL AND co.status IN ('Filed', 'Under Review', 'Needs Information', 'Accepted') AND NOT EXISTS (SELECT 1 FROM hearings h_rev WHERE h_rev.case_id = c.case_id))";
        } elseif ($intakeFilter === 'Docketed') {
            $where[] = "(c.case_id IS NOT NULL OR c.case_status = 'Docketed' OR co.status = 'Docketed' OR EXISTS (SELECT 1 FROM hearings h_doc WHERE h_doc.case_id = c.case_id))";
        }

        $stageFilter = trim((string)($filters['current_stage'] ?? ''));
        if ($stageFilter === 'Mediation') {
            $where[] = "(EXISTS (SELECT 1 FROM hearings h_m WHERE h_m.case_id = c.case_id AND h_m.hearing_type = 'Mediation') OR c.case_status = 'Mediation' OR co.status = 'Mediation')";
        } elseif ($stageFilter === 'Conciliation') {
            $where[] = "(EXISTS (SELECT 1 FROM hearings h_c WHERE h_c.case_id = c.case_id AND h_c.hearing_type = 'Conciliation') OR c.case_status = 'Conciliation' OR co.status = 'Conciliation')";
        } elseif ($stageFilter === 'Arbitration') {
            $where[] = "(EXISTS (SELECT 1 FROM arbitration_records ar WHERE ar.case_id = c.case_id) OR EXISTS (SELECT 1 FROM hearings h_a WHERE h_a.case_id = c.case_id AND h_a.hearing_type = 'Arbitration') OR c.case_status = 'Arbitration' OR co.status = 'Arbitration')";
        } elseif ($stageFilter === 'None') {
            $where[] = "(c.case_id IS NULL AND NOT EXISTS (SELECT 1 FROM hearings h_none WHERE h_none.case_id = c.case_id))";
        }

        $dispFilter = trim((string)($filters['final_disposition'] ?? ''));
        if ($dispFilter === 'Amicable Settlement' || $dispFilter === 'Settled') {
            $where[] = "(EXISTS (SELECT 1 FROM settlements s WHERE s.case_id = c.case_id) OR c.case_status = 'Settled' OR co.status = 'Settled')";
        } elseif ($dispFilter === 'Arbitration Award') {
            $where[] = "(EXISTS (SELECT 1 FROM arbitration_records ar WHERE ar.case_id = c.case_id))";
        } elseif (in_array($dispFilter, ['Certificate to File Action (CFA)', 'CFA', 'CFA Issued'], true)) {
            $where[] = "(EXISTS (SELECT 1 FROM cfa_records cfa WHERE cfa.case_id = c.case_id) OR c.case_status = 'CFA Issued' OR co.status = 'CFA Issued')";
        } elseif (in_array($dispFilter, ['Dismissed / Dropped', 'Dismissed'], true)) {
            $where[] = "(c.case_status = 'Dismissed' OR co.status IN ('Dismissed', 'Rejected'))";
        } elseif ($dispFilter === 'Pending') {
            $where[] = "(NOT EXISTS (SELECT 1 FROM settlements s_pend WHERE s_pend.case_id = c.case_id) AND NOT EXISTS (SELECT 1 FROM cfa_records cfa_pend WHERE cfa_pend.case_id = c.case_id) AND NOT EXISTS (SELECT 1 FROM arbitration_records ar_pend WHERE ar_pend.case_id = c.case_id) AND c.case_status NOT IN ('Settled', 'Dismissed', 'CFA Issued') AND co.status NOT IN ('Settled', 'Dismissed', 'Rejected', 'CFA Issued'))";
        }

        if (in_array($filters['case_type'] ?? '', ['Civil', 'Criminal'], true)) {
            $where[] = '(c.case_type = :case_type OR (c.case_id IS NULL AND co.case_type = :case_type))';
            $params[':case_type'] = $filters['case_type'];
        }

        $categoryId = filter_var($filters['category_id'] ?? null, FILTER_VALIDATE_INT);
        if ($categoryId && $categoryId > 0) {
            $where[] = 'co.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }

        if ($this->isDate($filters['date_from'] ?? '')) {
            $where[] = 'co.incident_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if ($this->isDate($filters['date_to'] ?? '')) {
            $where[] = 'co.incident_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $sql = "SELECT c.case_id, c.case_number, COALESCE(c.case_type, co.case_type) AS case_type, c.case_status, co.status AS complaint_status, COALESCE(c.case_status, co.status) AS raw_record_status, c.docket_date, co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, cc.category_name,
        GROUP_CONCAT(DISTINCT CONCAT(cp.party_type, ': ', TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name))) ORDER BY cp.party_type SEPARATOR ' | ') AS parties,
        MAX(history.repeat_count) AS repeat_party_count,
        (SELECT COUNT(*) FROM hearings h_med WHERE h_med.case_id = c.case_id AND h_med.hearing_type = 'Mediation') AS mediation_count,
        (SELECT COUNT(*) FROM hearings h_con WHERE h_con.case_id = c.case_id AND h_con.hearing_type = 'Conciliation') AS conciliation_count,
        (SELECT COUNT(*) FROM hearings h_arb WHERE h_arb.case_id = c.case_id AND h_arb.hearing_type = 'Arbitration') AS arbitration_hearing_count,
        (SELECT COUNT(*) FROM settlements s WHERE s.case_id = c.case_id) AS settlement_count,
        (SELECT COUNT(*) FROM arbitration_records ar WHERE ar.case_id = c.case_id) AS arbitration_record_count,
        (SELECT COUNT(*) FROM cfa_records cfa WHERE cfa.case_id = c.case_id) AS cfa_count
        FROM complaints co
        LEFT JOIN cases c ON c.complaint_id = co.complaint_id
        LEFT JOIN complaint_categories cc ON cc.category_id = co.category_id
        LEFT JOIN complaint_parties cp ON cp.complaint_id = co.complaint_id
        LEFT JOIN residents r ON r.resident_id = cp.resident_id
        LEFT JOIN (
            SELECT cp2.complaint_id, cp2.resident_id, COUNT(DISTINCT cp3.complaint_id) AS repeat_count
            FROM complaint_parties cp2
            INNER JOIN complaint_parties cp3 ON cp3.resident_id = cp2.resident_id
            GROUP BY cp2.complaint_id, cp2.resident_id
        ) history ON history.complaint_id = co.complaint_id AND history.resident_id = cp.resident_id";

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // Sorting mapping
        $sortBy = strtolower(trim((string)($filters['sort_by'] ?? 'incident_date')));
        $sortOrder = strtoupper(trim((string)($filters['sort_order'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';

        // Check for _asc or _desc suffixes
        if (str_ends_with($sortBy, '_asc')) {
            $sortBy = substr($sortBy, 0, -4);
            $sortOrder = 'ASC';
        } elseif (str_ends_with($sortBy, '_desc')) {
            $sortBy = substr($sortBy, 0, -5);
            $sortOrder = 'DESC';
        }

        // Column aliases mapping
        $aliasMap = [
            'complaint' => 'complaint_number',
            'case_no' => 'case_number',
            'category' => 'category_name',
            'date' => 'incident_date',
            'lifecycle' => 'raw_record_status',
            'status' => 'raw_record_status'
        ];
        if (isset($aliasMap[$sortBy])) {
            $sortBy = $aliasMap[$sortBy];
        }

        $sortColumns = [
            'incident_date' => "co.incident_date {$sortOrder}, co.complaint_id {$sortOrder}",
            'complaint_number' => "co.complaint_number {$sortOrder}",
            'case_number' => "c.case_number {$sortOrder}, co.complaint_id {$sortOrder}",
            'category_name' => "cc.category_name {$sortOrder}",
            'complaint_title' => "co.complaint_title {$sortOrder}",
            'parties' => "parties {$sortOrder}",
            'raw_record_status' => "COALESCE(c.case_status, co.status) {$sortOrder}",
            'intake_status' => "CASE WHEN c.case_id IS NOT NULL OR c.case_status = 'Docketed' THEN 2 ELSE 1 END {$sortOrder}",
            'current_stage' => "CASE WHEN (SELECT COUNT(*) FROM arbitration_records ar WHERE ar.case_id = c.case_id) > 0 THEN 3 WHEN (SELECT COUNT(*) FROM hearings h_c WHERE h_c.case_id = c.case_id AND h_c.hearing_type = 'Conciliation') > 0 THEN 2 WHEN (SELECT COUNT(*) FROM hearings h_m WHERE h_m.case_id = c.case_id AND h_m.hearing_type = 'Mediation') > 0 THEN 1 ELSE 0 END {$sortOrder}",
            'final_disposition' => "CASE WHEN (SELECT COUNT(*) FROM settlements s WHERE s.case_id = c.case_id) > 0 THEN 2 WHEN (SELECT COUNT(*) FROM cfa_records cfa WHERE cfa.case_id = c.case_id) > 0 THEN 3 ELSE 1 END {$sortOrder}"
        ];

        $orderClause = $sortColumns[$sortBy] ?? "co.incident_date DESC, c.case_id DESC, co.complaint_id DESC";

        $sql .= " GROUP BY c.case_id, c.case_number, c.case_type, co.case_type, c.case_status, co.status, c.docket_date, co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, cc.category_name ORDER BY {$orderClause} LIMIT 250";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $lifecycle = $this->resolveLifecycle($row);
            $row['intake_status'] = $lifecycle['intake_status'];
            $row['current_stage'] = $lifecycle['current_stage'];
            $row['final_disposition'] = $lifecycle['final_disposition'];
            $row['is_stage_exhausted'] = $lifecycle['is_stage_exhausted'];
            $row['status_labels'] = $lifecycle['status_labels'];
            $row['record_status'] = implode(', ', $lifecycle['status_labels']);
        }
        unset($row);

        return $rows;
    }

    public function resolveLifecycle(array $row): array
    {
        // 1. Final Disposition (How the case was resolved or terminated)
        $disposition = 'Pending';
        if ((int)($row['settlement_count'] ?? 0) > 0 || ($row['case_status'] ?? '') === 'Settled' || ($row['complaint_status'] ?? '') === 'Settled') {
            $disposition = 'Amicable Settlement';
        } elseif ((int)($row['cfa_count'] ?? 0) > 0 || ($row['case_status'] ?? '') === 'CFA Issued' || ($row['complaint_status'] ?? '') === 'CFA Issued') {
            $disposition = 'Certificate to File Action (CFA)';
        } elseif ((int)($row['arbitration_record_count'] ?? 0) > 0) {
            $disposition = 'Arbitration Award';
        } elseif (($row['case_status'] ?? '') === 'Dismissed' || in_array($row['complaint_status'] ?? '', ['Dismissed', 'Rejected'], true)) {
            $disposition = 'Dismissed / Dropped';
        }

        // 2. Progression / Dispute Stage (Active procedural phase)
        $stage = 'None';
        $medCount = (int)($row['mediation_count'] ?? 0);
        $conCount = (int)($row['conciliation_count'] ?? 0);
        $arbCount = (int)($row['arbitration_hearing_count'] ?? 0) + (int)($row['arbitration_record_count'] ?? 0);

        // After 3 mediations and 3 conciliations, the dispute stage is completed/exhausted and gone from Status
        $isStageExhausted = ($conCount >= 3) || ($medCount >= 3 && $conCount >= 3);

        if (!$isStageExhausted) {
            if ($arbCount > 0 || ($row['case_status'] ?? '') === 'Arbitration' || ($row['complaint_status'] ?? '') === 'Arbitration') {
                $stage = 'Arbitration';
            } elseif ($conCount > 0 || ($row['case_status'] ?? '') === 'Conciliation' || ($row['complaint_status'] ?? '') === 'Conciliation') {
                $stage = 'Conciliation';
            } elseif ($medCount > 0 || ($row['case_status'] ?? '') === 'Mediation' || ($row['complaint_status'] ?? '') === 'Mediation') {
                $stage = 'Mediation';
            }
        }

        // 3. Intake / Administrative Status (Current administrative state)
        $intake = 'Under Review';
        if (!empty($row['case_id']) || ($row['case_status'] ?? '') === 'Docketed' || ($row['complaint_status'] ?? '') === 'Docketed' || $stage !== 'None' || $isStageExhausted) {
            $intake = 'Docketed';
        }

        // Aggregate labels for tag/pill rendering
        $labels = [$intake];
        if ($stage !== 'None') {
            $labels[] = $stage;
        }
        $dispLabel = ($disposition === 'Certificate to File Action (CFA)') ? 'CFA' : ($disposition === 'Dismissed / Dropped' ? 'Dismissed' : $disposition);
        $labels[] = $dispLabel;

        return [
            'intake_status' => $intake,
            'current_stage' => $stage,
            'final_disposition' => $disposition,
            'is_stage_exhausted' => $isStageExhausted,
            'status_labels' => array_values(array_unique($labels))
        ];
    }

    public function resolveStatusLabels(array $row): array
    {
        return $this->resolveLifecycle($row)['status_labels'];
    }

    private function isDate($value): bool
    {
        if (!is_string($value) || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) !== 1) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
