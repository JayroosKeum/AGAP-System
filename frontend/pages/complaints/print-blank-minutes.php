<?php
session_start();

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/models/HearingMinutes.php';
require_once __DIR__ . '/../../../backend/models/Paanyaya.php';

$db = (new Database())->connect();
if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 3, 4], true)) {
    header('Location: ../auth/login.php');
    exit;
}

$complaintId = filter_var($_GET['id'] ?? $_GET['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hearingId = filter_var($_GET['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requestedStage = trim((string) ($_GET['stage'] ?? ''));

$caseNumber = '____________________';
$caseFiled = '____________________';
$caseTitle = '________________________________________';
$complainants = '____________________';
$respondents = '____________________';
$scheduledDateTimeLabel = '____________________';
$stageHearingLabel = '____________________';
$luponOfficerLabel = '____________________';
$carryoverContext = null;

if ($hearingId) {
    $hStmt = $db->prepare("
        SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue, h.mediation_attempt,
               c.complaint_id, c.case_number, c.created_at,
               TRIM(CONCAT_WS(' ', po.first_name, po.middle_name, po.last_name)) AS presider_name,
               TRIM(CONCAT_WS(' ', sp.first_name, sp.middle_name, sp.last_name)) AS sub_name
        FROM hearings h
        INNER JOIN cases c ON c.case_id = h.case_id
        LEFT JOIN users po ON po.user_id = h.presiding_officer_id
        LEFT JOIN users sp ON sp.user_id = h.substitute_presider_id
        WHERE h.hearing_id = ?
        LIMIT 1
    ");
    $hStmt->execute([$hearingId]);
    $hRow = $hStmt->fetch(PDO::FETCH_ASSOC);
    if ($hRow) {
        $caseId = (int) $hRow['case_id'];
        $complaintId = (int) $hRow['complaint_id'];
        $caseNumber = $hRow['case_number'] ?: $caseNumber;
        $caseFiled = !empty($hRow['created_at']) ? date('F j, Y', strtotime($hRow['created_at'])) : $caseFiled;
        if (!empty($hRow['hearing_date'])) {
            $scheduledDateTimeLabel = date('F j, Y · g:i A', strtotime($hRow['hearing_date']));
        }
        $attemptNo = (int) ($hRow['mediation_attempt'] ?? 1);
        $hType = (string) ($hRow['hearing_type'] ?? 'Mediation');
        if ($hType === 'Conciliation') {
            $stageHearingLabel = 'Conciliation Session ' . max(1, $attemptNo);
        } elseif ($hType === 'Arbitration') {
            $stageHearingLabel = 'Arbitration Hearing ' . max(1, $attemptNo);
        } else {
            $ordinal = match (max(1, $attemptNo)) {
                1 => '1st',
                2 => '2nd',
                3 => '3rd',
                default => max(1, $attemptNo) . 'th',
            };
            $stageHearingLabel = "{$ordinal} Mediation Hearing";
        }

        // Resolve presiding officer or Pangkat members
        if ($hType === 'Conciliation') {
            $pgStmt = $db->prepare("
                SELECT TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS full_name, pm.role_in_pangkat
                FROM pangkat_members pm
                INNER JOIN pangkat_groups pg ON pg.pangkat_id = pm.pangkat_id
                INNER JOIN users u ON u.user_id = pm.user_id
                WHERE pg.case_id = ?
                ORDER BY FIELD(pm.role_in_pangkat, 'Chairperson', 'Secretary', 'Member')
            ");
            $pgStmt->execute([$caseId]);
            $pangkatRows = $pgStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($pangkatRows)) {
                $luponOfficerLabel = implode(', ', array_map(static fn($r) => $r['full_name'] . ' (' . $r['role_in_pangkat'] . ')', $pangkatRows));
            }
        }
        if ($luponOfficerLabel === '____________________') {
            $name = trim((string) ($hRow['sub_name'] ?: ($hRow['presider_name'] ?? '')));
            if ($name !== '') {
                $luponOfficerLabel = $name;
            }
        }

        $carryoverContext = (new HearingMinutes($db))->getCarryoverContext((int) $hearingId, (int) $caseId, $hType);
    }
}

if ($caseId && !$complaintId) {
    $cStmt = $db->prepare("SELECT complaint_id, case_number, created_at FROM cases WHERE case_id = ? LIMIT 1");
    $cStmt->execute([$caseId]);
    $caseRow = $cStmt->fetch(PDO::FETCH_ASSOC);
    if ($caseRow) {
        $complaintId = (int) $caseRow['complaint_id'];
        $caseNumber = $caseRow['case_number'] ?: $caseNumber;
        $caseFiled = !empty($caseRow['created_at']) ? date('F j, Y', strtotime($caseRow['created_at'])) : $caseFiled;
    }
}

if ($complaintId) {
    $stmt = $db->prepare("
        SELECT c.*,
               co.case_id, co.case_number, co.created_at AS case_created_at
        FROM complaints c
        LEFT JOIN cases co ON co.complaint_id = c.complaint_id
        WHERE c.complaint_id = ?
        LIMIT 1
    ");
    $stmt->execute([$complaintId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($c) {
        $caseNumber = $c['case_number'] ?: ($c['complaint_number'] ?: 'CMP-' . str_pad((string) $complaintId, 4, '0', STR_PAD_LEFT));
        $dateFiled = !empty($c['case_created_at']) ? $c['case_created_at'] : $c['created_at'];
        $caseFiled = $dateFiled ? date('F j, Y', strtotime($dateFiled)) : '____________________';
        $caseTitle = $c['complaint_title'] ?: 'Barangay Dispute Resolution';

        $pStmt = $db->prepare("
            SELECT cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, COALESCE(cp.suffix, r.suffix))) AS full_name
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY cp.party_type ASC, cp.party_id ASC
        ");
        $pStmt->execute([$complaintId]);
        $parties = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $cList = [];
        $rList = [];
        foreach ($parties as $p) {
            if ($p['party_type'] === 'Complainant') {
                $cList[] = $p['full_name'];
            } elseif ($p['party_type'] === 'Respondent') {
                $rList[] = $p['full_name'];
            }
        }
        if (!empty($cList)) $complainants = implode(', ', $cList);
        if (!empty($rList)) $respondents = implode(', ', $rList);

        // Paanyaya stage pre-fill if opened for Paanyaya 1 / Paanyaya 2
        if (!$hearingId && in_array($requestedStage, ['Paanyaya 1', 'Paanyaya 2'], true)) {
            $stageHearingLabel = $requestedStage . ' (Informal Dialogue)';
            $paanyayaModel = new Paanyaya($db);
            $psStmt = $db->prepare('SELECT invitation_date, invitation_time FROM paanyaya_stages WHERE complaint_id = ? AND stage_type = ? LIMIT 1');
            $psStmt->execute([$complaintId, $requestedStage]);
            $ps = $psStmt->fetch(PDO::FETCH_ASSOC);
            if ($ps && !empty($ps['invitation_date'])) {
                $dtStr = $ps['invitation_date'] . (!empty($ps['invitation_time']) ? ' ' . $ps['invitation_time'] : '');
                $scheduledDateTimeLabel = !empty($ps['invitation_time'])
                    ? date('F j, Y · g:i A', strtotime($dtStr))
                    : date('F j, Y', strtotime($dtStr));
            }
            $carryoverContext = $paanyayaModel->getCarryoverContext((int) $complaintId, $requestedStage);
        } elseif (!$hearingId && $carryoverContext === null) {
            $carryoverContext = (new Paanyaya($db))->getCarryoverContext((int) $complaintId, Paanyaya::STAGE_1);
        }
    }
}

$tumanaLogoPath = __DIR__ . '/../../assets/images/tumana_logo.jpg';
$bagongLogoPath = __DIR__ . '/../../assets/images/bagong_pilipinas_logo.png';
$hasTumanaLogo = file_exists($tumanaLogoPath);
$hasBagongLogo = file_exists($bagongLogoPath);

$priorSummaryText = trim((string) ($carryoverContext['prior_summary'] ?? ''));
$priorUnresolvedText = trim((string) ($carryoverContext['unresolved_issues'] ?? ''));
$priorProposalText = trim((string) ($carryoverContext['previous_proposal'] ?? ($carryoverContext['agreed_terms'] ?? '')));
$hasCarryoverBox = ($priorSummaryText !== '' || $priorUnresolvedText !== '' || $priorProposalText !== '');
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Katitikan ng Pagdinig (Smart Paper Sheet) - <?= htmlspecialchars($caseNumber) ?></title>
    <link rel="icon" href="../../assets/images/tumana_logo.jpg" type="image/jpeg">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            margin: 0;
            padding: 20px 0;
            line-height: 1.35;
        }
        .action-bar {
            max-width: 8.5in;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 18px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .action-bar .title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e3a8a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-bar .btn-group { display: flex; gap: 10px; }
        .btn-print {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 7px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-close {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }
        .sheet-container {
            width: 8.5in;
            min-height: 10.7in;
            margin: 0 auto;
            background: #ffffff;
            padding: 0.45in 0.6in 0.5in 0.6in;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-logo-left { width: 78px; text-align: left; vertical-align: middle; }
        .header-logo-right { width: 78px; text-align: right; vertical-align: middle; }
        .header-logo-img { width: 64px; height: 64px; object-fit: contain; }
        .header-center-text { text-align: center; vertical-align: middle; line-height: 1.25; }
        .rep-title { font-size: 9pt; color: #0f172a; }
        .brgy-title { font-size: 12.5pt; font-weight: 800; color: #0f172a; letter-spacing: 0.5px; }
        .city-title { font-size: 10pt; font-weight: 700; color: #1e293b; }
        .addr-title { font-size: 7.5pt; color: #475569; }
        .tel-title { font-size: 7.5pt; color: #475569; }
        .office-title { font-size: 10pt; font-weight: 800; color: #0f172a; margin-top: 3px; }
        .ooo { font-size: 8.5pt; color: #475569; margin-top: 1px; }
        .minutes-heading {
            font-size: 10.5pt;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin: 6px 0 8px 0;
            padding: 4px 0;
            border-top: 1.5px solid #0f172a;
            border-bottom: 1.5px solid #0f172a;
        }
        .particulars-table { width: 100%; border-collapse: collapse; font-size: 8.8pt; line-height: 1.45; margin-bottom: 8px; }
        .particulars-table td { padding: 2px 4px; vertical-align: top; color: #0f172a; }
        .carryover-box {
            border: 1px solid #94a3b8;
            background: #f8fafc;
            border-radius: 4px;
            padding: 6px 9px;
            margin-bottom: 8px;
            font-size: 8.2pt;
            line-height: 1.35;
        }
        .carryover-title {
            font-weight: 800;
            text-transform: uppercase;
            font-size: 7.8pt;
            letter-spacing: 0.4px;
            margin-bottom: 3px;
            color: #1e293b;
        }
        .attendance-strip {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            margin-bottom: 8px;
            font-size: 8.4pt;
        }
        .ruled-section { margin: 4px 0 10px 0; border-top: 1.2px solid #0f172a; }
        .ruled-line { height: 24px; border-bottom: 1px solid #94a3b8; width: 100%; }
        .outcome-box {
            border: 1.2px solid #0f172a;
            padding: 7px 10px;
            margin-bottom: 12px;
            font-size: 8.4pt;
        }
        .outcome-row { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 4px; }
        .sig-grid { width: 100%; border-collapse: collapse; font-size: 8.2pt; margin-top: 10px; }
        .sig-grid td { width: 33.3%; text-align: center; padding: 14px 8px 0; vertical-align: bottom; }
        .sig-line { border-top: 1px solid #0f172a; padding-top: 3px; font-weight: 700; }
        .sig-sub { font-size: 7.4pt; color: #475569; }
        @media print {
            body { background: #ffffff; padding: 0; color: #000000; }
            .action-bar { display: none !important; }
            .sheet-container { width: 100% !important; min-height: auto !important; margin: 0 !important; padding: 0 !important; border: none !important; box-shadow: none !important; }
            .carryover-box { background: #ffffff !important; border-color: #64748b !important; }
            @page { size: letter portrait; margin: 10mm 14mm 10mm 14mm; }
        }
    </style>
</head>
<body>

    <div class="action-bar">
        <div class="title">
            <span>Smart Paper Sheet (Pen-and-Paper Minutes) &bull; <?= htmlspecialchars($caseNumber) ?></span>
        </div>
        <div class="btn-group">
            <button type="button" class="btn-print" onclick="window.print()">Print Smart Minutes Sheet</button>
            <button type="button" class="btn-close" onclick="window.close()">Close</button>
        </div>
    </div>

    <div class="sheet-container">
        <table class="header-table">
            <tr>
                <td class="header-logo-left">
                    <?php if ($hasTumanaLogo): ?>
                        <img src="../../assets/images/tumana_logo.jpg" class="header-logo-img" alt="Barangay Tumana Logo">
                    <?php endif; ?>
                </td>
                <td class="header-center-text">
                    <div class="rep-title">Republika ng Pilipinas</div>
                    <div class="brgy-title">BARANGAY TUMANA</div>
                    <div class="city-title">CITY OF MARIKINA</div>
                    <div class="addr-title">ROAD 1 EXTENSION DOÑA PETRA, BARANGAY TUMANA, MARIKINA CITY</div>
                    <div class="tel-title">Tel. No. 02-8457-2818</div>
                    <div class="office-title">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
                    <div class="ooo">— oOo —</div>
                </td>
                <td class="header-logo-right">
                    <?php if ($hasBagongLogo): ?>
                        <img src="../../assets/images/bagong_pilipinas_logo.png" class="header-logo-img" alt="Bagong Pilipinas Logo">
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <div class="minutes-heading">
            MINUTES OF <?= htmlspecialchars($respondents) ?> vs. <?= htmlspecialchars($complainants) ?>
        </div>

        <table class="particulars-table">
            <tr>
                <td style="width: 50%;"><strong>CASE / REF NO:</strong> &nbsp;<?= htmlspecialchars($caseNumber) ?></td>
                <td style="width: 50%;"><strong>DATE FILED:</strong> &nbsp;<?= htmlspecialchars($caseFiled) ?></td>
            </tr>
            <tr>
                <td colspan="2"><strong>CASE TITLE:</strong> &nbsp;<?= htmlspecialchars($caseTitle) ?></td>
            </tr>
            <tr>
                <td><strong>DATE / TIME:</strong> &nbsp;<?= htmlspecialchars($scheduledDateTimeLabel) ?></td>
                <td><strong>STAGE / HEARING:</strong> &nbsp;<?= htmlspecialchars($stageHearingLabel) ?></td>
            </tr>
            <tr>
                <td><strong>LUPON / PRESIDER:</strong> &nbsp;<?= htmlspecialchars($luponOfficerLabel) ?></td>
                <td><strong>PAHINA:</strong> &nbsp;_____ ng _____</td>
            </tr>
        </table>

        <?php if ($hasCarryoverBox): ?>
            <div class="carryover-box">
                <div class="carryover-title">
                    Previous Session Context (<?= htmlspecialchars((string) ($carryoverContext['carried_from_stage'] ?? 'Prior Record')) ?>)
                </div>
                <?php if ($priorSummaryText !== ''): ?>
                    <div><strong>Summary / Salaysay:</strong> <?= htmlspecialchars(mb_strimwidth($priorSummaryText, 0, 260, '…')) ?></div>
                <?php endif; ?>
                <?php if ($priorProposalText !== ''): ?>
                    <div><strong>Previous Proposal / Terms:</strong> <?= htmlspecialchars(mb_strimwidth($priorProposalText, 0, 180, '…')) ?></div>
                <?php endif; ?>
                <?php if ($priorUnresolvedText !== ''): ?>
                    <div><strong>Unresolved Issues:</strong> <?= htmlspecialchars(mb_strimwidth($priorUnresolvedText, 0, 220, '…')) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="attendance-strip">
            <div><strong>Complainant Attendance:</strong> &nbsp;&#9744; Present &nbsp;&#9744; Late &nbsp;&#9744; Absent</div>
            <div><strong>Respondent Attendance:</strong> &nbsp;&#9744; Present &nbsp;&#9744; Late &nbsp;&#9744; Absent</div>
        </div>

        <div class="ruled-section">
            <?php for ($i = 1; $i <= 16; $i++): ?>
                <div class="ruled-line"></div>
            <?php endfor; ?>
        </div>

        <div class="outcome-box">
            <strong>Kinalabasan ng Sesyon (Mark &#10003; One Outcome):</strong>
            <div class="outcome-row">
                <span>&#9744; Nagkasundo / Settled</span>
                <span>&#9744; Susunod na Pagdinig / Continue Session</span>
                <span>&#9744; I-akyat (Mediation / Conciliation / CFA)</span>
                <span>&#9744; Party Absent / Dismissed</span>
            </div>
        </div>

        <table class="sig-grid">
            <tr>
                <td>
                    <div class="sig-line">Lagda ng Nagrereklamo</div>
                    <div class="sig-sub">Complainant Signature &amp; Date</div>
                </td>
                <td>
                    <div class="sig-line">Lagda ng Inirereklamo</div>
                    <div class="sig-sub">Respondent Signature &amp; Date</div>
                </td>
                <td>
                    <div class="sig-line">Lupon / Namuno sa Pagdinig</div>
                    <div class="sig-sub">Presiding Officer Signature</div>
                </td>
            </tr>
        </table>
    </div>

    <?php if (isset($_GET['autoprint']) && $_GET['autoprint'] == '1'): ?>
    <script>
        window.addEventListener('DOMContentLoaded', () => { window.print(); });
    </script>
    <?php endif; ?>
</body>
</html>
