<?php

session_start();
require_once '../../controllers/CaseController.php';

$controller = new CaseController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
        header('Location: ../../../frontend/pages/auth/login.php');
        exit;
    }
    if (!in_array($_POST['case_type'] ?? '', ['Civil', 'Criminal'], true)) {
        header('Location: ../../../frontend/pages/cases/case-list.php?error=save_failed');
        exit;
    }
    $complaintId = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($complaintId && PaanyayaPreparationService::managesDialogue((new Database())->connect(), $complaintId)) {
        header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . $complaintId . '#paanyaya-preparation', true, 303);
        exit;
    }
    $error = $controller->getDocketingError($complaintId);

    if ($error !== null) {
        header('Location: ../../../frontend/pages/cases/case-list.php?error=' . $error);
        exit;
    }

    if (!$controller->store($_POST)) {
        header('Location: ../../../frontend/pages/cases/case-list.php?error=save_failed');
        exit;
    }

    header(
        'Location: ../../../frontend/pages/cases/case-list.php'
    );

    exit;
}
