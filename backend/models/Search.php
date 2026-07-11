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

        $statuses = ['Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived'];
        if (in_array($filters['status'] ?? '', $statuses, true)) {
            $where[] = 'c.case_status = :status';
            $params[':status'] = $filters['status'];
        }

        if (in_array($filters['case_type'] ?? '', ['Civil', 'Criminal'], true)) {
            $where[] = 'c.case_type = :case_type';
            $params[':case_type'] = $filters['case_type'];
        }

        if ($this->isDate($filters['date_from'] ?? '')) {
            $where[] = 'co.incident_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if ($this->isDate($filters['date_to'] ?? '')) {
            $where[] = 'co.incident_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $sql = "SELECT c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date, co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, cc.category_name, 
        GROUP_CONCAT(DISTINCT CONCAT(cp.party_type, ': ', TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name))) ORDER BY cp.party_type SEPARATOR ' | ') AS parties,
        MAX(history.repeat_count) AS repeat_party_count FROM cases c INNER JOIN complaints co ON co.complaint_id = c.complaint_id LEFT JOIN complaint_categories cc ON cc.category_id = co.category_id LEFT JOIN complaint_parties cp ON cp.complaint_id = co.complaint_id LEFT JOIN residents r ON r.resident_id = cp.resident_id LEFT JOIN (SELECT cp2.complaint_id, cp2.resident_id, COUNT(DISTINCT cp3.complaint_id) AS repeat_count FROM complaint_parties cp2 INNER JOIN complaint_parties cp3 ON cp3.resident_id = cp2.resident_id GROUP BY cp2.complaint_id, cp2.resident_id) history ON history.complaint_id = co.complaint_id AND history.resident_id = cp.resident_id";

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' GROUP BY c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date, co.complaint_id, co.complaint_number, co.complaint_title, co.incident_date, cc.category_name ORDER BY co.incident_date DESC, c.case_id DESC LIMIT 200';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isDate($value): bool
    {
        return is_string($value) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) === 1;
    }
}
