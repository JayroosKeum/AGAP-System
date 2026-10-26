<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../controllers/ReportController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$controller = new ReportController();
$model = new Report();

$startDate = !empty($_GET['start_date']) ? $_GET['start_date'] : null;
$endDate   = !empty($_GET['end_date'])   ? $_GET['end_date']   : null;
$year      = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT) ?: (int) date('Y');
$month     = filter_var($_GET['month'] ?? date('n'), FILTER_VALIDATE_INT) ?: (int) date('n');

if (!$startDate || !$endDate) {
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate   = date('Y-m-t', strtotime($startDate));
}

// Fetch 21-column case register
$cases = $model->getCaseRegister($startDate, $endDate);

// Calculate KP Monitoring Metrics
$civil = 0;
$criminal = 0;
$others = 0;

$mediation = 0;
$conciliation = 0;
$arbitration = 0;

$repudiated = 0;
$dropped = 0;
$pending = 0;
$dismissed = 0;
$certified = 0;
$referred = 0;

foreach ($cases as $c) {
    // Nature of dispute
    $type = strtolower((string)($c['case_type'] ?? ''));
    if ($type === 'civil') $civil++;
    elseif ($type === 'criminal') $criminal++;
    else $others++;

    // Actions taken
    $status = strtolower((string)($c['case_status'] ?? ''));
    $hasSettlement = !empty($c['settlement_date']);
    $hasCfa = !empty($c['cfa_issuance_date']);
    $medSessions = (int)($c['mediation_sessions'] ?? 0);
    $concilSessions = (int)($c['conciliation_sessions'] ?? 0);

    if ($status === 'settled' || $hasSettlement) {
        if ($concilSessions > 0) {
            $conciliation++;
        } else {
            $mediation++;
        }
    } elseif ($status === 'arbitration' || (!empty($c['award_date']))) {
        $arbitration++;
    } elseif (str_contains($status, 'repudiat')) {
        $repudiated++;
    } elseif (str_contains($status, 'drop') || str_contains($status, 'withdraw')) {
        $dropped++;
    } elseif (str_contains($status, 'dismiss')) {
        $dismissed++;
    } elseif ($status === 'cfa issued' || $hasCfa) {
        $certified++;
    } elseif (str_contains($status, 'refer')) {
        $referred++;
    } else {
        $pending++;
    }
}

$totalFiled = count($cases);
$totalSettled = $mediation + $conciliation + $arbitration;
$totalUnsettled = $repudiated + $dropped + $pending + $dismissed + $certified + $referred;
$savingsPerCase = 9500.00;
$estimatedSavings = $totalSettled * $savingsPerCase;

$monthTimestamp = strtotime($startDate);
$monthName = strtoupper(date('F Y', $monthTimestamp));
$quarterNum = (int) ceil(date('n', $monthTimestamp) / 3);

$response = [
    'success' => true,
    'meta' => [
        'region' => 'NCR',
        'city' => 'MARIKINA',
        'barangay' => 'TUMANA',
        'month_label' => $monthName,
        'quarter_label' => 'Quarter ' . $quarterNum,
        'cy' => date('Y', $monthTimestamp),
        'date_accomplished' => strtoupper(date('F j, Y')),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'prepared_by' => '',
        'noted_by' => 'HON. AKIKO LYNNE CENTENO',
        'attested_by' => 'PAMELA CONSULTA',
    ],
    'summary' => [
        'civil' => $civil,
        'criminal' => $criminal,
        'others' => $others,
        'total_filed' => $totalFiled,
        'mediation' => $mediation,
        'conciliation' => $conciliation,
        'arbitration' => $arbitration,
        'total_settled' => $totalSettled,
        'repudiated' => $repudiated,
        'dropped' => $dropped,
        'pending' => $pending,
        'dismissed' => $dismissed,
        'certified' => $certified,
        'referred' => $referred,
        'total_unsettled' => $totalUnsettled,
        'savings' => number_format($estimatedSavings, 2),
        'savings_raw' => $estimatedSavings,
    ],
    'cases' => $cases,
];

echo json_encode($response);
