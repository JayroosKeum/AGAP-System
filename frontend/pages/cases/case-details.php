<?php

session_start();

if (!isset($_SESSION['role_id']) || !in_array((int)$_SESSION['role_id'], [1, 2, 3], true)) {
    if ((int)($_SESSION['role_id'] ?? 0) === 4) {
        header('Location: ../gps/proof-service.php');
        exit;
    }
    http_response_code(403);
    die('Access Denied');
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$id) {
    header('Location: case-list.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';

try {
    $db = (new Database())->connect();

    // Check if the given ID is a case_id
    $stmt = $db->prepare("SELECT complaint_id FROM cases WHERE case_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $complaintId = $stmt->fetchColumn();

    if ($complaintId) {
        header('Location: ../complaints/complaint-details.php?id=' . (int)$complaintId);
        exit;
    }

    // Check if it's already a complaint_id
    $stmt2 = $db->prepare("SELECT complaint_id FROM complaints WHERE complaint_id = ? LIMIT 1");
    $stmt2->execute([$id]);
    $complaintId2 = $stmt2->fetchColumn();

    if ($complaintId2) {
        header('Location: ../complaints/complaint-details.php?id=' . (int)$complaintId2);
        exit;
    }

    header('Location: case-list.php');
    exit;
} catch (Throwable $e) {
    error_log('Case details redirect error: ' . $e->getMessage());
    header('Location: case-list.php');
    exit;
}
