<?php
/** Printable official KP reports. Data is read directly from docketed records. */
declare(strict_types=1);
session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 5], true)) {
    http_response_code(403);
    exit('Access Denied');
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Setting.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/DocxTemplateService.php';

function value(string $key, string $default = ''): string { return trim((string) ($_GET[$key] ?? $default)); }
function h(?string $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dateLabel(?string $value): string { return $value ? date('M. j, Y', strtotime($value)) : ''; }
function cleanYear(): int { $year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT); return ($year && $year >= 2000 && $year <= 2100) ? $year : (int) date('Y'); }
function officialType(): string { $type = value('report_type'); return in_array($type, ['certificate', 'monitoring', 'form8', 'form6'], true) ? $type : 'certificate'; }

$type = officialType();
$year = cleanYear();
$month = filter_var($_GET['month'] ?? date('n'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
$months = [$month];
if ($type === 'monitoring') {
    $months = array_values(array_unique(array_filter(array_map('intval', explode(',', value('months'))), static fn(int $m): bool => $m >= 1 && $m <= 12)));
    if (!$months) { $quarter = max(1, min(4, (int) value('quarter', (string) ceil($month / 3)))); $months = range(($quarter - 1) * 3 + 1, ($quarter - 1) * 3 + 3); }
}
sort($months);
$startDate = sprintf('%04d-%02d-01', $year, min($months));
$endDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, max($months))));
$monthLabel = implode(', ', array_map(static fn(int $m): string => date('F', mktime(0, 0, 0, $m, 1)), $months));

$db = (new Database())->connect();
$settings = (new Setting($db))->getAllSettings();
$barangay = $settings['barangay_name'] ?? 'TUMANA';
$city = $settings['city_name'] ?? 'CITY OF MARIKINA';
$province = $settings['province_name'] ?? 'NCR, SECOND DISTRICT';
$region = $settings['region_name'] ?? 'NATIONAL CAPITAL REGION (NCR)';
$pb = $settings['captain_name'] ?? '';
$secretary = $settings['lupon_secretary_name'] ?? ($settings['barangay_secretary_name'] ?? '');

$caseSql = "SELECT c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date, c.created_at AS date_encoded,
    co.complaint_title, co.requested_resolution, cat.category_name,
    (SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ') FROM complaint_parties cp JOIN residents r ON r.resident_id=cp.resident_id WHERE cp.complaint_id=co.complaint_id AND cp.party_type='Complainant') AS complainant_name,
    (SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ') FROM complaint_parties cp JOIN residents r ON r.resident_id=cp.resident_id WHERE cp.complaint_id=co.complaint_id AND cp.party_type='Respondent') AS respondent_name,
    (SELECT MIN(DATE(h.hearing_date)) FROM hearings h WHERE h.case_id=c.case_id AND h.status NOT IN ('Cancelled','Office Cancelled','Rescheduled')) AS initial_confrontation_date,
    (SELECT COUNT(*) FROM hearings h WHERE h.case_id=c.case_id AND h.hearing_type='Conciliation' AND h.status='Completed') AS completed_conciliation,
    (SELECT COUNT(*) FROM hearings h WHERE h.case_id=c.case_id AND h.hearing_type IN ('Mediation','Initial Hearing') AND h.status='Completed') AS completed_mediation,
    s.settlement_date, s.agreement_details, s.compliance_status, s.compliance_date,
    ar.award_date, ar.award_details,
    (SELECT MAX(COALESCE(ep.notice_of_execution_date, DATE(ep.updated_at))) FROM execution_proceedings ep WHERE ep.case_id=c.case_id AND ep.execution_status='Enforced / Complied') AS execution_date,
    cfa.cfa_type, cfa.issuance_date AS cfa_date, cfa.reason AS cfa_reason, cfa.created_at AS cfa_encoded
FROM cases c JOIN complaints co ON co.complaint_id=c.complaint_id
LEFT JOIN complaint_categories cat ON cat.category_id=co.category_id
LEFT JOIN settlements s ON s.case_id=c.case_id
LEFT JOIN arbitration_records ar ON ar.case_id=c.case_id
LEFT JOIN cfa_records cfa ON cfa.case_id=c.case_id";

if ($type === 'certificate') {
    $stmt = $db->prepare($caseSql . ' WHERE DATE(cfa.created_at) BETWEEN ? AND ? ORDER BY cfa.created_at, c.case_id');
    $stmt->execute([$startDate, $endDate]);
} elseif ($type === 'monitoring') {
    $monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
    $stmt = $db->prepare($caseSql . " WHERE YEAR(c.docket_date) = ? AND MONTH(c.docket_date) IN ($monthPlaceholders) ORDER BY c.docket_date, c.case_id");
    $stmt->execute(array_merge([$year], $months));
} else {
    $stmt = $db->prepare($caseSql . ' WHERE c.docket_date BETWEEN ? AND ? ORDER BY c.docket_date, c.case_id');
    $stmt->execute([$startDate, $endDate]);
}
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = ['criminal' => 0, 'civil' => 0, 'others' => 0, 'mediation' => 0, 'conciliation' => 0, 'arbitration' => 0, 'repudiated' => 0, 'dropped' => 0, 'pending' => 0, 'dismissed' => 0, 'certified' => 0, 'referred' => 0, 'outside' => 0];
foreach ($cases as $case) {
    $nature = strtolower((string) $case['case_type']);
    $summary[$nature === 'criminal' ? 'criminal' : ($nature === 'civil' ? 'civil' : 'others')]++;
    $status = strtolower((string) $case['case_status']);
    if (!empty($case['award_date']) || $status === 'arbitration') $summary['arbitration']++;
    elseif (!empty($case['settlement_date']) || $status === 'settled') $summary[(int) $case['completed_conciliation'] > 0 ? 'conciliation' : 'mediation']++;
    elseif (str_contains($status, 'repudiat')) $summary['repudiated']++;
    elseif (str_contains($status, 'drop') || str_contains($status, 'withdraw')) $summary['dropped']++;
    elseif (str_contains($status, 'dismiss')) $summary['dismissed']++;
    elseif (!empty($case['cfa_date']) || str_contains($status, 'cfa')) $summary['certified']++;
    elseif (str_contains($status, 'refer')) $summary['referred']++;
    else $summary['pending']++;
}
$filed = count($cases); $settled = $summary['mediation'] + $summary['conciliation'] + $summary['arbitration'];
$unsettled = $summary['repudiated'] + $summary['dropped'] + $summary['pending'] + $summary['dismissed'] + $summary['certified'] + $summary['referred'];
$savingsRate = is_numeric($settings['kp_estimated_savings_rate'] ?? null) ? (float) $settings['kp_estimated_savings_rate'] : null;
$accomplished = value('date_accomplished', date('Y-m-d'));

try { (new AuditService())->log((int) $_SESSION['user_id'], 'Generated ' . strtoupper($type) . ' report for ' . $monthLabel . ' ' . $year, 'Reports'); } catch (Throwable $e) { error_log($e->getMessage()); }
$leftLogo = DocxTemplateService::getLogoDataUri('tumana_logo.jpg', 'tumana_logo.jpg') ?: '../../../frontend/assets/images/tumana_logo.jpg';
$rightLogo = DocxTemplateService::getLogoDataUri('marikina_logo.jpg', 'marikina_logo.jpg') ?: '../../../frontend/assets/images/marikina_logo.jpg';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= h(ucfirst($type)) ?> Report</title><style>
@page { size: landscape; margin: 11mm; } *{box-sizing:border-box} body{margin:0;color:#111;font:11px Arial,sans-serif;background:#eef2f1}.toolbar{position:sticky;top:0;padding:10px;text-align:right;background:#fff;border-bottom:1px solid #d1d5db;z-index:2}.toolbar button{background:#166534;color:#fff;border:0;border-radius:5px;padding:8px 14px;font-weight:bold;cursor:pointer}.toolbar a{margin-left:15px;color:#334155;text-decoration:none}.sheet{max-width:1400px;margin:16px auto;background:#fff;padding:18px;box-shadow:0 2px 12px #0002}.heading{position:relative;min-height:80px;text-align:center}.heading img{position:absolute;top:0;width:68px;height:68px;object-fit:contain}.heading img:first-child{left:0}.heading img:last-child{right:0}.heading h1{font-size:14px;letter-spacing:1px;margin:5px 80px}.heading h2{font-size:12px;margin:6px}.heading p{margin:3px}.meta{display:flex;justify-content:space-between;gap:25px;margin:8px 0 16px}.meta p{margin:3px 0}.formal-table{border-collapse:collapse;width:100%;table-layout:fixed}.formal-table th,.formal-table td{border:1px solid #222;padding:5px;vertical-align:top;overflow-wrap:anywhere}.formal-table th{text-align:center;font-size:9px}.gold th{background:#c99a36}.center{text-align:center}.wide{overflow-x:auto}.signatures{display:grid;grid-template-columns:repeat(3,1fr);gap:35px;margin-top:42px;text-align:center}.signatures .line{border-top:1px solid #111;margin:35px 15px 5px}.legend{margin-top:14px;font-size:9px}.small{font-size:9px}.monitor th{font-size:8px}.monitor td{text-align:center;font-size:10px}.form-info{display:grid;grid-template-columns:repeat(2,1fr);gap:2px 35px;margin:8px 0}.form-info p{margin:2px 0}@media print{body{background:#fff}.toolbar{display:none}.sheet{box-shadow:none;margin:0;padding:0}.formal-table thead{display:table-header-group}.formal-table tr{break-inside:avoid}.wide{overflow:visible}} </style></head><body>
<div class="toolbar"><button onclick="window.print()">Print</button><a href="#" onclick="window.close();return false">Close this Window</a></div><main class="sheet">
<?php if ($type === 'certificate'): ?><div class="heading"><img src="<?= h($leftLogo) ?>"><img src="<?= h($rightLogo) ?>"><h1>LUPONG TAGAPAMAYAPA</h1><h2>CERTIFICATE TO FILE ACTION CASES</h2><p>YEAR <?= $year ?></p></div><div class="wide"><table class="formal-table gold"><thead><tr><th>#</th><th>CASE NO.</th><th>FORM TYPE</th><th>RESPONDENT</th><th>COMPLAINANT</th><th>SUMMON REASON</th><th>LIABILITY</th><th>DATE ENCODED</th></tr></thead><tbody><?php foreach($cases as $i=>$c): ?><tr><td><?= $i+1 ?></td><td><?=h($c['case_number'])?></td><td><?=h($c['cfa_type'])?></td><td><?=h($c['respondent_name'])?></td><td><?=h($c['complainant_name'])?></td><td><?=h($c['cfa_reason'])?></td><td><?=h($c['requested_resolution'])?></td><td><?=h(dateLabel($c['cfa_encoded']))?></td></tr><?php endforeach; if(!$cases): ?><tr><td colspan="8" class="center">No Certificate to File Action records were encoded in this reporting period.</td></tr><?php endif;?></tbody></table></div>
<?php elseif ($type === 'monitoring'): ?><div class="meta"><div><p>REGION: <b><?=h($region)?></b></p><p>CITY/MUNICIPALITY: <b><?=h($city)?></b></p><p>PROVINCE: <b><?=h($province)?></b></p><p>BARANGAY: <b><?=h($barangay)?></b></p></div><div><p>REPORTING QUARTER: <b>Quarter <?=ceil($months[0]/3)?></b></p><p>CY: <b><?=$year?></b></p><p>DATE ACCOMPLISHED: <b><?=h(dateLabel($accomplished))?></b></p></div></div><div class="heading"><h1>LUPONG TAGAPAMAYAPA REPORT</h1><p>SUMMARY OF CASES FILED AND ACTIONS TAKEN</p><p>“<?=h($monthLabel)?>”</p></div><table class="formal-table monitor"><thead><tr><th rowspan="3">COVERING MONTH</th><th colspan="4">NUMBER OF CASES FILED</th><th colspan="11">ACTION TAKEN BY THE LUPONG TAGAPAMAYAPA</th><th rowspan="3">ESTIMATED GOVERNMENT SAVINGS</th></tr><tr><th colspan="3">Nature of Disputes</th><th rowspan="2">Total</th><th colspan="4">Settled Cases</th><th colspan="7">Unsettled Cases</th></tr><tr><th>Criminal</th><th>Civil</th><th>Others</th><th>Mediation</th><th>Conciliation</th><th>Arbitration</th><th>Total</th><th>Repudiated</th><th>Dropped / Withdrawn</th><th>Pending</th><th>Dismissed</th><th>Certified</th><th>Referred</th><th>Total</th></tr></thead><tbody><tr><td><?=h(implode(' / ',array_map(fn($m)=>date('M',mktime(0,0,0,$m,1)),$months)))?></td><td><?=$summary['criminal']?></td><td><?=$summary['civil']?></td><td><?=$summary['others']?></td><td><?=$filed?></td><td><?=$summary['mediation']?></td><td><?=$summary['conciliation']?></td><td><?=$summary['arbitration']?></td><td><?=$settled?></td><td><?=$summary['repudiated']?></td><td><?=$summary['dropped']?></td><td><?=$summary['pending']?></td><td><?=$summary['dismissed']?></td><td><?=$summary['certified']?></td><td><?=$summary['referred']?></td><td><?=$unsettled?></td><td><?= $savingsRate === null ? 'Not configured' : number_format($settled * $savingsRate,2) ?></td></tr></tbody></table><div class="signatures"><div><p>Prepared by:</p><div class="line"></div><b><?=h($secretary)?></b><br>Lupon Member / Secretary</div><div><p>Noted by:</p><div class="line"></div><b><?=h($pb)?></b><br>Punong Barangay</div><div><p>Attested by:</p><div class="line"></div><b><?=h($secretary)?></b><br>Barangay Secretary</div></div>
<?php elseif ($type === 'form8'): ?><div class="heading"><img src="<?= h($leftLogo) ?>"><img src="<?= h($rightLogo) ?>"><h1>CY <?=$year?> LUPONG TAGAPAMAYAPA INCENTIVES AWARDS</h1><h2>LTIA FORM 8. KP CASE RECORD AND SETTLEMENT LOG</h2></div><div class="form-info"><p>Category: Lupong Tagapamayapa</p><p>Punong Barangay: <?=h($pb)?></p><p>City/Municipality: <?=h($city)?></p><p>Province: <?=h($province)?></p><p>Region: <?=h($region)?></p><p>Barangay: <?=h($barangay)?></p></div><div class="wide"><table class="formal-table small"><thead><tr><th>No.</th><th>1<br>Case No.</th><th>2<br>Case Title</th><th>3<br>Nature of Case</th><th>4<br>Complaint Title</th><th>5<br>Date Filed</th><th>6<br>Date of Initial Confrontation</th><th>7<br>Action Taken</th><th>8<br>Date of Settlement or Award</th><th>9<br>Date of Execution</th><th>10<br>Main Point of Agreement</th><th>11<br>Status of Compliance</th><th>12<br>Remarks</th></tr></thead><tbody><?php foreach($cases as $i=>$c): $isAward=!empty($c['award_date']); $action=$isAward?'Arbitration':(!empty($c['settlement_date'])?((int)$c['completed_conciliation']?'Conciliation':'Mediation'):''); $statusMap=['Complied'=>'CC – Complete Compliance','Fully Paid'=>'CC – Complete Compliance','Partially Paid'=>'PC – Partial Compliance','Pending'=>'NC – Non-compliance','Overdue'=>'NC – Non-compliance','Violated'=>'NC – Non-compliance'];?><tr><td><?=$i+1?></td><td><?=h($c['case_number'])?></td><td><?=h(($c['complainant_name'] ?: '') . ' vs. ' . ($c['respondent_name'] ?: ''))?></td><td><?=h($c['case_type'])?></td><td><?=h($c['complaint_title'] ?: $c['category_name'])?></td><td><?=h(dateLabel($c['docket_date']))?></td><td><?=h(dateLabel($c['initial_confrontation_date']))?></td><td><?=h($action)?></td><td><?=h(dateLabel($isAward?$c['award_date']:$c['settlement_date']))?></td><td><?=h(dateLabel($c['execution_date']))?></td><td><?=h($isAward?$c['award_details']:$c['agreement_details'])?></td><td><?=h($statusMap[$c['compliance_status']] ?? '')?></td><td><?=h($c['case_status'])?></td></tr><?php endforeach; if(!$cases):?><tr><td colspan="13" class="center">No docketed cases were filed in this reporting period.</td></tr><?php endif;?></tbody></table></div><p class="legend"><b>Legend:</b> 1 Case Number; 2 Case Title; 3 Nature of Case; 4 Complaint Title; 5 Date Filed; 6 Date of Initial Confrontation; 7 Action Taken; 8 Date of Settlement or Award; 9 Date of Execution; 10 Main Point of Agreement; 11 Compliance (CC/PC/NC); 12 Remarks.</p>
<?php else: ?><div class="heading"><img src="<?= h($leftLogo) ?>"><img src="<?= h($rightLogo) ?>"><h1>CY <?=$year?> LUPONG TAGAPAMAYAPA INCENTIVES AWARDS</h1><h2>LTIA FORM 6. SUMMARY OF CASES</h2></div><div class="form-info"><p>Category: Lupong Tagapamayapa</p><p>Punong Barangay: <?=h($pb)?></p><p>City/Municipality: <?=h($city)?></p><p>Mayor: <?=h($settings['mayor_name'] ?? '')?></p><p>Province: <?=h($province)?></p><p>Region: <?=h($region)?></p><p>Population: <?=h($settings['barangay_population'] ?? '')?></p><p>Land Area: <?=h($settings['land_area'] ?? '')?></p><p>Total No. of Cases: <?=$filed?></p><p>Number of Lupons: <?=h($settings['number_of_lupons'] ?? '')?></p><p>Male: <?=h($settings['lupon_male_count'] ?? '')?></p><p>Female: <?=h($settings['lupon_female_count'] ?? '')?></p></div><table class="formal-table monitor"><thead><tr><th colspan="4">NATURE OF CASES</th><th colspan="4">SETTLED</th><th colspan="8">NOT SETTLED</th></tr><tr><th>Criminal</th><th>Civil</th><th>Others</th><th>Total</th><th>Mediation</th><th>Conciliation</th><th>Arbitration</th><th>Total</th><th>Repudiated</th><th>Dropped/Withdrawn</th><th>Pending</th><th>Dismissed</th><th>Certified to File Action</th><th>Referred to Court / Agency</th><th>Outside Jurisdiction</th><th>Total</th></tr></thead><tbody><tr><td><?=$summary['criminal']?></td><td><?=$summary['civil']?></td><td><?=$summary['others']?></td><td><?=$filed?></td><td><?=$summary['mediation']?></td><td><?=$summary['conciliation']?></td><td><?=$summary['arbitration']?></td><td><?=$settled?></td><td><?=$summary['repudiated']?></td><td><?=$summary['dropped']?></td><td><?=$summary['pending']?></td><td><?=$summary['dismissed']?></td><td><?=$summary['certified']?></td><td><?=$summary['referred']?></td><td><?=$summary['outside']?></td><td><?=$unsettled?></td></tr></tbody></table><p class="legend">Figures are generated from cases docketed from <?=h(dateLabel($startDate))?> to <?=h(dateLabel($endDate))?>. Cases are counted once by their recorded disposition. Blank profile fields indicate that the corresponding system setting has not yet been recorded.</p><?php endif; ?>
</main></body></html>
