<?php

require_once __DIR__ . '/../../../backend/config/session.php';

$roleId = (int) ($_SESSION['role_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId === 0 || $roleId === 0) {
    $returnUrl = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: ../auth/login.php' . ($returnUrl !== '' ? '?return_to=' . $returnUrl : ''));
    exit;
}

if (!in_array($roleId, [1, 2, 3, 4], true)) {
    http_response_code(403);
    die('Access Denied');
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$caseNumber = trim((string)($_GET['case_number'] ?? $_GET['case_no'] ?? $_GET['q'] ?? $_GET['search'] ?? ''));

if (!$id && $caseNumber === '') {
    header('Location: case-list.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';

try {
    $db = (new Database())->connect();

    if ($id) {
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
    }

    if ($caseNumber !== '') {
        // Check by case number
        $stmtCase = $db->prepare("SELECT complaint_id FROM cases WHERE case_number = ? LIMIT 1");
        $stmtCase->execute([$caseNumber]);
        $foundComplaintId = $stmtCase->fetchColumn();

        if ($foundComplaintId) {
            header('Location: ../complaints/complaint-details.php?id=' . (int)$foundComplaintId);
            exit;
        }

        // Check by complaint number
        $stmtComp = $db->prepare("SELECT complaint_id FROM complaints WHERE complaint_number = ? LIMIT 1");
        $stmtComp->execute([$caseNumber]);
        $compDirectId = $stmtComp->fetchColumn();

        if ($compDirectId) {
            header('Location: ../complaints/complaint-details.php?id=' . (int)$compDirectId);
            exit;
        }

        header('Location: case-list.php?search=' . urlencode($caseNumber));
        exit;
    }

    header('Location: case-list.php');
    exit;
} catch (Throwable $e) {
    error_log('Case details redirect error: ' . $e->getMessage());
    header('Location: case-list.php');
    exit;
}
