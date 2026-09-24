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
        if (($filters['status'] ?? '') === 'in_progress') {
            $where[] = "(c.case_status IN ('Docketed', 'Mediation', 'Conciliation', 'Arbitration') OR (c.case_id IS NULL AND co.status IN ('Accepted', 'Mediation', 'Conciliation')))";
        } elseif (in_array($filters['status'] ?? '', $statuses, true)) {
            $where[] = '(c.case_status = :case_status OR (c.case_id IS NULL AND co.status = :complaint_status))';
            $params[':case_status'] = $filters['status'];
            $params[':complaint_status'] = $filters['status'];
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

        $sql = "SELECT c.case_id, c.case_number, COALESCE(c.case_type, co.case_type) AS case_type, c.case_status, COALESCE(c.case_status, co.status) AS record_status, c.docket_date, co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, cc.category_name,
        GROUP_CONCAT(DISTINCT CONCAT(cp.party_type, ': ', TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name))) ORDER BY cp.party_type SEPARATOR ' | ') AS parties,
        MAX(history.repeat_count) AS repeat_party_count FROM complaints co LEFT JOIN cases c ON c.complaint_id = co.complaint_id LEFT JOIN complaint_categories cc ON cc.category_id = co.category_id LEFT JOIN complaint_parties cp ON cp.complaint_id = co.complaint_id LEFT JOIN residents r ON r.resident_id = cp.resident_id LEFT JOIN (SELECT cp2.complaint_id, cp2.resident_id, COUNT(DISTINCT cp3.complaint_id) AS repeat_count FROM complaint_parties cp2 INNER JOIN complaint_parties cp3 ON cp3.resident_id = cp2.resident_id GROUP BY cp2.complaint_id, cp2.resident_id) history ON history.complaint_id = co.complaint_id AND history.resident_id = cp.resident_id";

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' GROUP BY c.case_id, c.case_number, c.case_type, co.case_type, c.case_status, co.status, c.docket_date, co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, cc.category_name ORDER BY co.incident_date DESC, c.case_id DESC, co.complaint_id DESC LIMIT 200';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isDate($value): bool
    {
        if (!is_string($value) || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) !== 1) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
