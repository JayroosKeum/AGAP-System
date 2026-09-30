<?php

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if (!in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

try {
    $db = (new Database())->connect();

    $queries = [
        'complaints' => "SELECT COUNT(*) AS row_count,
            COALESCE(MAX(updated_at), '1970-01-01 00:00:00') AS last_change,
            COALESCE(SUM(CRC32(CONCAT_WS('|', complaint_id, status, updated_at))), 0) AS checksum
            FROM complaints",

        'cases' => "SELECT COUNT(*) AS row_count,
            COALESCE(MAX(updated_at), '1970-01-01 00:00:00') AS last_change,
            COALESCE(SUM(CRC32(CONCAT_WS('|', case_id, case_status, updated_at))), 0) AS checksum
            FROM cases",

        'assignments' => "SELECT COUNT(*) AS row_count,
            COALESCE(MAX(created_at), '1970-01-01 00:00:00') AS last_change,
            COALESCE(SUM(CRC32(CONCAT_WS('|', assignment_id, case_id, member_id, assignment_role, assigned_date))), 0) AS checksum
            FROM case_assignments",

        'pangkat' => "SELECT
            (SELECT COUNT(*) FROM pangkat_groups) + (SELECT COUNT(*) FROM pangkat_members) AS row_count,
            GREATEST(
                COALESCE((SELECT MAX(created_at) FROM pangkat_groups), '1970-01-01 00:00:00'),
                COALESCE((SELECT MAX(created_at) FROM pangkat_members), '1970-01-01 00:00:00')
            ) AS last_change,
            COALESCE((SELECT SUM(CRC32(CONCAT_WS('|', pangkat_id, case_id, formation_date))) FROM pangkat_groups), 0)
            + COALESCE((SELECT SUM(CRC32(CONCAT_WS('|', pangkat_member_id, pangkat_id, member_id, position))) FROM pangkat_members), 0) AS checksum",

        'hearings' => "SELECT COUNT(*) AS row_count,
            COALESCE(MAX(updated_at), '1970-01-01 00:00:00') AS last_change,
            COALESCE(SUM(CRC32(CONCAT_WS('|', hearing_id, case_id, hearing_type, hearing_date, venue, status, updated_at))), 0) AS checksum
            FROM hearings",

        'deadlines' => "SELECT COUNT(*) AS row_count,
            COALESCE(MAX(updated_at), '1970-01-01 00:00:00') AS last_change,
            COALESCE(SUM(CRC32(CONCAT_WS('|', deadline_id, case_id, deadline_type, due_date, status, updated_at))), 0) AS checksum
            FROM case_deadlines",

        'history' => "SELECT COUNT(*) AS row_count,
            COALESCE(MAX(updated_at), '1970-01-01 00:00:00') AS last_change,
            COALESCE(SUM(CRC32(CONCAT_WS('|', history_id, case_id, status, updated_at))), 0) AS checksum
            FROM case_history"
    ];

    $modules = [];
    foreach ($queries as $module => $sql) {
        $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
        $modules[$module] = hash('sha256', json_encode([
            'count' => (int) ($row['row_count'] ?? 0),
            'last_change' => (string) ($row['last_change'] ?? ''),
            'checksum' => (string) ($row['checksum'] ?? '0')
        ], JSON_UNESCAPED_SLASHES));
    }

    echo json_encode([
        'success' => true,
        'revision' => hash('sha256', json_encode($modules, JSON_UNESCAPED_SLASHES)),
        'modules' => $modules,
        'server_time' => date(DATE_ATOM)
    ]);
} catch (Throwable $exception) {
    error_log('AGAP synchronization state failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to check for synchronized updates.']);
}
