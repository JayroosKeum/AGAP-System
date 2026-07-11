<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 2, 3])) die('Access Denied');
require_once '../../../backend/controllers/CaseController.php';
$case = (new CaseController())->show($_GET['case_id'] ?? 0);
if (!$case) die('Case not found.');
?>
<!DOCTYPE html>
<html>
<head>
    <title>KP Form 9</title>

    <style>

        body{
            font-family: Arial;
            margin:40px;
        }

        .print-btn{
            margin-bottom:20px;
        }

        @media print{
            .print-btn{
                display:none;
            }
        }

    </style>

</head>
<body>

<button
    onclick="window.print()"
    class="print-btn">
    Print / Save PDF
</button>

<h2>KP FORM 9</h2>

<h3>SUMMONS</h3>

<p><strong>Case Number:</strong> <?= htmlspecialchars($case['case_number']) ?></p>
<p><strong>Complaint:</strong> <?= htmlspecialchars($case['complaint_title']) ?></p>
<p><strong>Date Issued:</strong> <?= date('F j, Y') ?></p>

<p>
You are hereby ordered to appear before the Lupong Tagapamayapa for mediation proceedings regarding the complaint stated above.
</p>

<br><br><p>_____________________________<br>Lupon Secretary / Authorized Officer</p>

</body>
</html>
