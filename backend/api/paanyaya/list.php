<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$status = isset($_GET['status']) ? trim((string) $_GET['status']) : null;
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : null;

try {
    $paanyayaModel = new Paanyaya();
    $paanyayas = $paanyayaModel->getAllPaanyayas($status, $search);
    $allRecords = ($status !== null || $search !== null) ? $paanyayaModel->getAllPaanyayas() : $paanyayas;
    $stats = [
        'all' => count($allRecords),
        'scheduled' => 0,
        'settled' => 0,
        'escalated' => 0,
        'cancelled' => 0
    ];
    foreach ($allRecords as $row) {
        $st = strtolower($row['status'] ?? '');
        if ($st === 'scheduled' || $st === 'pending' || $st === 'waiting') {
            $stats['scheduled']++;
        } elseif ($st === 'settled' || $st === 'resolved') {
            $stats['settled']++;
        } elseif ($st === 'escalated' || $st === 'docketed') {
            $stats['escalated']++;
        } elseif ($st === 'cancelled') {
            $stats['cancelled']++;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $paanyayas,
        'count' => count($paanyayas),
        'stats' => $stats
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
    exit;
}
