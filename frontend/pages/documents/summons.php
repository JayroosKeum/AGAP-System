<?php
session_start();
$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($caseId) {
    header("Location: ../gps/proof-service.php?case_id={$caseId}");
} else {
    header("Location: ../gps/proof-service.php");
}
exit;
