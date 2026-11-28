<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ResidentNameLookup.php';
require_once __DIR__ . '/../services/PiiCryptoService.php';
require_once __DIR__ . '/../middleware/rbac.php';

class Search
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function records(array $filters): array
    {
        $where = ['co.deleted_at IS NULL'];
        $params = [];

        if (isset($_SESSION['user_id'], $_SESSION['role_id'])) {
            [$visSql, $visParams] = agapBuildComplaintVisibilitySql('co', $this->conn);
            $where[] = $visSql;
            foreach ($visParams as $idx => $val) {
                $k = ':vis_' . $idx;
                $pos = strpos($where[count($where) - 1], '?');
                if ($pos !== false) {
                    $where[count($where) - 1] = substr_replace($where[count($where) - 1], $k, $pos, 1);
                    $params[$k] = $val;
                }
            }
        }

        $term = trim($filters['q'] ?? '');

        if ($term !== '') {
            $nameIds = ResidentNameLookup::matchingIds($this->conn, $filters['q']);
            $nameMatch = $nameIds === [] ? '0 = 1' : 'cp_search.resident_id IN (' . implode(',', $nameIds) . ')';
            $where[] = "(c.case_number LIKE :term OR co.complaint_number LIKE :term OR co.complaint_title LIKE :term OR co.narrative LIKE :term OR EXISTS (SELECT 1 FROM complaint_parties cp_search WHERE cp_search.complaint_id = co.complaint_id AND {$nameMatch}))";
            $params[':term'] = '%' . $term . '%';
        }

        $statuses = ['Filed', 'Under Review', 'Needs Information', 'Accepted', 'Rejected', 'Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived', 'Pre-Docketed (Paanyaya)', 'Resolved at Paanyaya / Closed'];
        $filterStatus = $filters['status'] ?? '';

        if ($filterStatus === 'Archived') {
            $where[] = "(c.case_status = 'Archived' OR co.status = 'Archived')";
        } else {
            $where[] = "(co.status != 'Archived' AND (c.case_status IS NULL OR c.case_status != 'Archived'))";

            if ($filterStatus === 'Paanyaya' || $filterStatus === 'Pre-Docketed (Paanyaya)' || $filterStatus === 'Pending') {
                $where[] = "(c.case_id IS NULL AND (co.status = 'Pre-Docketed (Paanyaya)' OR co.status = 'Resolved at Paanyaya / Closed' OR co.status LIKE '%Paanyaya%' OR co.status NOT IN ('Mediation','Conciliation','CFA Issued','Settled','Dismissed','Archived','Arbitration','Rejected')))";
            } elseif ($filterStatus === 'Resolution / Closed') {
                $where[] = "(EXISTS (SELECT 1 FROM settlements s_closed WHERE s_closed.case_id = c.case_id)
                    OR EXISTS (SELECT 1 FROM arbitration_records ar_closed WHERE ar_closed.case_id = c.case_id)
                    OR c.case_status IN ('Closed','Settled','Dismissed','Arbitration','DISMISSED_BARRED','RESPONDENT_DEFAULT')
                    OR co.status IN ('Resolution / Closed','Closed','Settled','Dismissed','Arbitration','Rejected','DISMISSED_BARRED','RESPONDENT_DEFAULT','Resolved at Paanyaya / Closed'))";
            } elseif ($filterStatus === 'BPSO Validation') {
                $where[] = "(c.case_id IS NULL AND co.bpso_status IN ('Pending BPSO Review', 'Needs Clarification'))";
            } elseif ($filterStatus === 'Under Review') {
                $where[] = "(c.case_id IS NULL AND co.status IN ('Filed', 'Under Review', 'Needs Information', 'Accepted') AND co.status NOT IN ('Dismissed', 'Rejected', 'Settled'))";
            } elseif ($filterStatus === 'Docketed') {
                $where[] = "(c.case_id IS NOT NULL OR c.case_status = 'Docketed' OR co.status = 'Docketed')";
            } elseif ($filterStatus === 'Mediation') {
                $where[] = "(EXISTS (SELECT 1 FROM hearings h_m WHERE h_m.case_id = c.case_id AND h_m.hearing_type = 'Mediation') OR c.case_status IN ('Mediation', 'Docketed') OR co.status IN ('Mediation', 'Docketed', 'Accepted', 'Filed'))";
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
        }

        $intakeFilter = trim((string)($filters['intake_status'] ?? ''));
        if (in_array($filterStatus, ['Paanyaya', 'Mediation', 'Conciliation', 'in_progress'], true)) {
            $where[] = "co.status NOT IN ('Resolution / Closed', 'Closed', 'Pending Close') AND COALESCE(c.case_status, '') NOT IN ('Closed', 'Pending Close')";
        }
        if ($intakeFilter === 'Under Review') {
            $where[] = "(c.case_id IS NULL AND co.status IN ('Filed', 'Under Review', 'Needs Information', 'Accepted') AND NOT EXISTS (SELECT 1 FROM hearings h_rev WHERE h_rev.case_id = c.case_id))";
        } elseif ($intakeFilter === 'Docketed') {
            $where[] = "(c.case_id IS NOT NULL OR c.case_status = 'Docketed' OR co.status = 'Docketed' OR EXISTS (SELECT 1 FROM hearings h_doc WHERE h_doc.case_id = c.case_id))";
        }

        $stageFilter = trim((string)($filters['current_stage'] ?? ''));
        if ($stageFilter === 'Mediation') {
            $where[] = "(EXISTS (SELECT 1 FROM hearings h_m WHERE h_m.case_id = c.case_id AND h_m.hearing_type = 'Mediation') OR c.case_status IN ('Mediation', 'Docketed') OR co.status IN ('Mediation', 'Docketed', 'Accepted', 'Filed'))";
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

        $queueFilter = trim((string) ($filters['assigned_queue'] ?? ''));
        if ($queueFilter !== '') {
            $where[] = 'co.assigned_queue = :assigned_queue';
            $params[':assigned_queue'] = $queueFilter;
        }

        if ($this->isDate($filters['date_from'] ?? '')) {
            $where[] = 'co.incident_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if ($this->isDate($filters['date_to'] ?? '')) {
            $where[] = 'co.incident_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $sql = "SELECT c.case_id, c.case_number, COALESCE(c.case_type, co.case_type) AS case_type, c.case_status, co.status, co.status AS complaint_status, co.bpso_status, co.bpso_blotter_no, co.intake_source, co.resolution_type, COALESCE(c.case_status, co.status) AS raw_record_status, c.docket_date, co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, co.assigned_queue, co.routing_route, co.liso_endorsement_agency, cc.category_name, co.document_status, co.is_draft,
        GROUP_CONCAT(DISTINCT CONCAT(cp.party_type, ': ', TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name))) ORDER BY cp.party_type SEPARATOR ' | ') AS parties,
        MAX(history.repeat_count) AS repeat_party_count,
        (SELECT COALESCE(MAX(ps.attempt_number), 1) FROM paanyaya_sessions ps WHERE ps.complaint_id = co.complaint_id) AS paanyaya_attempt,
        (SELECT COUNT(*) FROM hearings h_med WHERE h_med.case_id = c.case_id AND h_med.hearing_type = 'Mediation') AS mediation_count,
        (SELECT COUNT(*) FROM hearings h_con WHERE h_con.case_id = c.case_id AND h_con.hearing_type = 'Conciliation') AS conciliation_count,
        (SELECT COUNT(*) FROM hearings h_arb WHERE h_arb.case_id = c.case_id AND h_arb.hearing_type = 'Arbitration') AS arbitration_hearing_count,
        (SELECT COUNT(*) FROM settlements s WHERE s.case_id = c.case_id AND s.settlement_status = 'Signed and Attested') AS settlement_count,
        (SELECT COUNT(*) FROM arbitration_records ar WHERE ar.case_id = c.case_id) AS arbitration_record_count,
        (SELECT COUNT(*) FROM arbitration_records ar WHERE ar.case_id = c.case_id AND (ar.award_status = 'Finalized' OR ar.award_date IS NOT NULL)) AS arbitration_finalized_count,
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
            'complaint' => 'complaint_title',
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
            'case_number' => "COALESCE(c.case_number, co.complaint_number, co.complaint_id) {$sortOrder}, co.complaint_id {$sortOrder}",
            'category_name' => "cc.category_name {$sortOrder}",
            'complaint_title' => "co.complaint_title {$sortOrder}, co.complaint_id {$sortOrder}",
            'parties' => "parties {$sortOrder}",
            'raw_record_status' => "COALESCE(c.case_status, co.status) {$sortOrder}",
            'intake_status' => "CASE WHEN c.case_id IS NOT NULL OR c.case_status = 'Docketed' THEN 2 ELSE 1 END {$sortOrder}",
            'current_stage' => "CASE WHEN (SELECT COUNT(*) FROM arbitration_records ar WHERE ar.case_id = c.case_id) > 0 THEN 3 WHEN (SELECT COUNT(*) FROM hearings h_c WHERE h_c.case_id = c.case_id AND h_c.hearing_type = 'Conciliation') > 0 THEN 2 WHEN (SELECT COUNT(*) FROM hearings h_m WHERE h_m.case_id = c.case_id AND h_m.hearing_type = 'Mediation') > 0 THEN 1 ELSE 0 END {$sortOrder}",
            'final_disposition' => "CASE WHEN (SELECT COUNT(*) FROM settlements s WHERE s.case_id = c.case_id) > 0 THEN 2 WHEN (SELECT COUNT(*) FROM cfa_records cfa WHERE cfa.case_id = c.case_id) > 0 THEN 3 ELSE 1 END {$sortOrder}"
        ];

        $orderClause = $sortColumns[$sortBy] ?? "co.incident_date DESC, c.case_id DESC, co.complaint_id DESC";

        $sql .= " GROUP BY c.case_id, c.case_number, c.case_type, co.case_type, c.case_status, co.status, co.bpso_status, co.bpso_blotter_no, co.intake_source, c.docket_date, co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, co.assigned_queue, co.routing_route, co.liso_endorsement_agency, cc.category_name, co.document_status, co.is_draft ORDER BY {$orderClause} LIMIT 250";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            if (empty($row['case_number']) && !empty($row['complaint_number'])) {
                $row['case_number'] = $row['complaint_number'];
            }
            $lifecycle = $this->resolveLifecycle($row);
            $row['intake_status'] = $lifecycle['intake_status'];
            $row['current_stage'] = $lifecycle['current_stage'];
            $row['final_disposition'] = $lifecycle['final_disposition'];
            $row['is_stage_exhausted'] = $lifecycle['is_stage_exhausted'];
            $row['status_labels'] = $lifecycle['status_labels'];
            $row['record_status'] = implode(', ', $lifecycle['status_labels']);
            $row['lifecycle_status'] = $this->resolveStandardStatus($row);
        }
        unset($row);

        return PiiCryptoService::decryptPayload($rows);
    }

    public function resolveLifecycle(array $row): array
    {
        // 1. Final Disposition (How the case was resolved or terminated)
        $disposition = 'Pending';
        $cs = (string) ($row['case_status'] ?? '');
        $cos = (string) ($row['complaint_status'] ?? '');
        $hasCfa = (int) ($row['cfa_count'] ?? 0) > 0 || $cs === 'CFA Issued' || $cos === 'CFA Issued';
        $hasArbRec = (int) ($row['arbitration_record_count'] ?? 0) > 0;
        $hasArbFinalized = (int) ($row['arbitration_finalized_count'] ?? 0) > 0;
        $hasSettleRec = (int) ($row['settlement_count'] ?? 0) > 0;

        $verifiedClosed = $cs === 'Closed' || in_array($cos, ['Closed', 'Resolution / Closed'], true);
        $pendingClose = $cs === 'Pending Close' || $cos === 'Pending Close';
        if ($verifiedClosed) {
            $disposition = trim((string) ($row['resolution_type'] ?? '')) ?: 'Closed';
        } elseif (in_array($cs, ['Dismissed', 'DISMISSED_BARRED'], true) || in_array($cos, ['Dismissed', 'DISMISSED_BARRED', 'Rejected'], true)) {
            $disposition = 'Dismissed / Dropped';
        } elseif ($cs === 'Repudiated' || $cos === 'Repudiated') {
            $disposition = 'Repudiated';
        } elseif ($hasCfa) {
            $disposition = 'Certificate to File Action (CFA)';
        } elseif ($cs === 'Arbitration Award' || $hasArbFinalized || ($hasArbRec && ($cs === 'Settled' || $cos === 'Settled') && !$hasSettleRec)) {
            $disposition = 'Arbitration Award';
        } elseif ($cs === 'Arbitration' || $cos === 'Arbitration') {
            $disposition = 'Pending';
        } elseif ($hasSettleRec || $cs === 'Settled' || $cos === 'Settled' || $cos === 'Resolved at Paanyaya / Closed') {
            $disposition = 'Amicable Settlement';
        }

        // 2. Progression / Dispute Stage (Procedural phase)
        $stage = 'None';
        $medCount = (int)($row['mediation_count'] ?? 0);
        $conCount = (int)($row['conciliation_count'] ?? 0);
        $arbCount = (int)($row['arbitration_hearing_count'] ?? 0) + (int)($row['arbitration_record_count'] ?? 0);
        $paanyayaAttempt = (int)($row['paanyaya_attempt'] ?? 1);
        $caseStatus = (string)($row['case_status'] ?? '');
        $complaintStatus = (string)($row['complaint_status'] ?? '');
        $hasCase = !empty($row['case_id']);

        $isDisposed = $verifiedClosed || in_array($caseStatus, ['Settled', 'CFA Issued', 'Dismissed', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Repudiated', 'Arbitration Award', 'Archived'], true)
            || in_array($complaintStatus, ['Settled', 'Dismissed', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Repudiated', 'Rejected', 'Resolved at Paanyaya / Closed'], true)
            || ($hasSettleRec && $caseStatus !== 'Arbitration')
            || $hasCfa;
        $isStageExhausted = $isDisposed;

        if ($arbCount > 0 || $caseStatus === 'Arbitration' || $complaintStatus === 'Arbitration') {
            $stage = 'Arbitration';
        } elseif ($conCount > 0 || $caseStatus === 'Conciliation' || $complaintStatus === 'Conciliation') {
            $stage = 'Conciliation';
        } elseif ($medCount > 0 || $caseStatus === 'Mediation' || $complaintStatus === 'Mediation' || ($hasCase && !$isDisposed)) {
            if ($medCount >= 3) {
                $stage = '3rd Mediation';
            } elseif ($medCount === 2) {
                $stage = '2nd Mediation';
            } else {
                $stage = '1st Mediation';
            }
        } elseif ($hasCase) {
            // Case is docketed and disposed, default procedural entry point is 1st Mediation
            $stage = '1st Mediation';
        } elseif ($isDisposed) {
            $stage = 'None';
        } elseif (str_contains($complaintStatus, 'Paanyaya') || str_contains($caseStatus, 'Paanyaya')) {
            // Pre-docketed Paanyaya stage (no formal case_id yet)
            $isSecond = ($paanyayaAttempt >= 2) || str_contains($complaintStatus, '2nd') || str_contains($caseStatus, '2nd') || str_contains($complaintStatus, 'Paanyaya 2');
            $stage = $isSecond ? '2nd Paanyaya' : '1st Paanyaya';
        } elseif ($complaintStatus === 'Docketed' || $caseStatus === 'Docketed' || (!str_contains($complaintStatus, 'Pre-Docketed') && str_contains($complaintStatus, 'Docketed'))) {
            $stage = '1st Mediation';
        } else {
            $stage = $complaintStatus !== '' ? $complaintStatus : 'Under Review';
        }

        // 3. Intake / Administrative Status (Current administrative state)
        $intake = $complaintStatus !== '' ? $complaintStatus : 'Under Review';
        if (!$hasCase && (str_contains($complaintStatus, 'Paanyaya') || str_contains($caseStatus, 'Paanyaya') || str_contains($complaintStatus, 'Pre-Docketed'))) {
            $intake = 'Pre-Docketed (Paanyaya)';
        } elseif ($hasCase || $caseStatus === 'Docketed' || $complaintStatus === 'Docketed' || (!str_contains($complaintStatus, 'Pre-Docketed') && str_contains($complaintStatus, 'Docketed'))) {
            $intake = 'Docketed';
        }

        // Aggregate labels for tag/pill rendering
        $labels = [];
        if ($pendingClose) {
            $labels[] = 'Pending Close';
        } elseif ($verifiedClosed) {
            $labels[] = 'Closed';
            if ($disposition !== 'Closed') $labels[] = $disposition;
        } elseif ($caseStatus === 'Archived' || $complaintStatus === 'Archived') {
            $labels[] = 'Archived';
        } elseif ($disposition !== 'Pending') {
            if ($disposition === 'Amicable Settlement') {
                $labels[] = 'Amicable Settlement';
            } elseif ($disposition === 'Certificate to File Action (CFA)') {
                $labels[] = 'CFA';
            } elseif ($disposition === 'Repudiated') {
                $labels[] = 'Repudiated';
            } elseif ($disposition === 'Arbitration Award') {
                $labels[] = 'Arbitration Award';
            } elseif ($disposition === 'Dismissed / Dropped') {
                $labels[] = ($caseStatus === 'DISMISSED_BARRED' || $complaintStatus === 'DISMISSED_BARRED') ? 'Dismissed (Barred)' : 'Dismissed';
            }
        } else {
            if ($stage !== 'None') {
                $labels[] = $stage;
            }
        }

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

    private function resolveStandardStatus(array $row): string
    {
        $caseStatus = (string) ($row['case_status'] ?? '');
        $complaintStatus = (string) ($row['complaint_status'] ?? '');

        if ($caseStatus === 'Closed' || in_array($complaintStatus, ['Closed', 'Resolution / Closed'], true)) return 'Resolution / Closed';
        if ($caseStatus === 'Pending Close' || $complaintStatus === 'Pending Close') return 'Pending Close';

        $dismissedStatuses = ['Dismissed', 'DISMISSED_BARRED', 'Archived', 'Rejected'];
        if (in_array($caseStatus, $dismissedStatuses, true) || in_array($complaintStatus, $dismissedStatuses, true)) {
            return 'Resolution / Closed';
        }
        if ((int) ($row['cfa_count'] ?? 0) > 0 || $caseStatus === 'CFA Issued' || $complaintStatus === 'CFA Issued' || $caseStatus === 'Repudiated' || $complaintStatus === 'Repudiated') {
            return 'CFA';
        }

        $closedStatuses = ['Settled', 'Dismissed', 'Archived', 'Rejected', 'Arbitration', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Resolved at Paanyaya / Closed'];

        if ((int) ($row['settlement_count'] ?? 0) > 0
            || (int) ($row['arbitration_record_count'] ?? 0) > 0
            || in_array($caseStatus, $closedStatuses, true)
            || in_array($complaintStatus, $closedStatuses, true)) {
            return 'Resolution / Closed';
        }
        if ((int) ($row['conciliation_count'] ?? 0) > 0 || $caseStatus === 'Conciliation' || $complaintStatus === 'Conciliation') {
            return 'Conciliation';
        }
        if ((int) ($row['mediation_count'] ?? 0) > 0 || $caseStatus === 'Mediation' || $complaintStatus === 'Mediation') {
            return 'Mediation';
        }
        if (str_contains($complaintStatus, 'Paanyaya') || $complaintStatus === 'Accepted' || str_contains($caseStatus, 'Paanyaya')) {
            return 'Paanyaya';
        }
        if (empty($row['case_id'])) {
            if (in_array($complaintStatus, ['Pending', 'Under Review', 'Submitted', 'For Verification', 'Filed'], true) || $complaintStatus === '') {
                return 'Verification';
            }
            if (str_contains($complaintStatus, 'Mediation') || str_contains($complaintStatus, 'Docketed')) {
                return 'Mediation';
            }
            return 'Verification';
        }
        return 'Mediation';
    }


    private function isDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) !== 1) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
