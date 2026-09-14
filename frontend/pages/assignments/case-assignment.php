<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    exit('Access Denied');
}

header('Location: ../cases/case-list.php#caseAssignments', true, 303);
exit;
