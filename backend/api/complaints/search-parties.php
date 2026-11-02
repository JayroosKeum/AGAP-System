<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$query = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($query) < 2) {
    echo json_encode(['success' => true, 'parties' => []]);
    exit;
}

try {
    $db = (new Database())->connect();

    // Search existing parties from complaint_parties and residents
    $sql = "
        SELECT 
            r.resident_id,
            TRIM(CONCAT_WS(' ', r.first_name, NULLIF(r.middle_name, ''), r.last_name, NULLIF(r.suffix, ''))) AS full_name,
            r.first_name,
            r.middle_name,
            r.last_name,
            r.suffix,
            r.birth_date,
            r.gender,
            r.civil_status,
            r.occupation,
            r.contact_no,
            r.email,
            r.purok,
            r.address,
            r.home_latitude,
            r.home_longitude,
            COUNT(DISTINCT cp.complaint_id) AS total_past_cases,
            SUM(CASE WHEN cp.party_type = 'Complainant' THEN 1 ELSE 0 END) AS as_complainant_count,
            SUM(CASE WHEN cp.party_type = 'Respondent' THEN 1 ELSE 0 END) AS as_respondent_count
        FROM residents r
        LEFT JOIN complaint_parties cp ON cp.resident_id = r.resident_id
        WHERE 
            r.first_name LIKE :q1 
            OR r.last_name LIKE :q2
            OR CONCAT_WS(' ', r.first_name, r.last_name) LIKE :q3
            OR r.contact_no LIKE :q4
            OR r.address LIKE :q5
        GROUP BY r.resident_id
        ORDER BY total_past_cases DESC, r.last_name ASC, r.first_name ASC
        LIMIT 10
    ";

    $like = '%' . $query . '%';
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':q1' => $like,
        ':q2' => $like,
        ':q3' => $like,
        ':q4' => $like,
        ':q5' => $like
    ]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'parties' => $results
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to search parties: ' . $e->getMessage()
    ]);
}
