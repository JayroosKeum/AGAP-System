<?php
session_start();

if (
    !isset($_SESSION['role_id']) ||
    !in_array((int)$_SESSION['role_id'], [1, 2, 3, 4], true)
) {
    die('Access Denied');
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: complaint-list.php');
    exit;
}

$complaintId = (int)$_GET['id'];
$stageType = trim((string)($_GET['stage'] ?? 'Paanyaya 1'));
if (!in_array($stageType, ['Paanyaya 1', 'Paanyaya 2'], true)) {
    $stageType = 'Paanyaya 1';
}

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/controllers/ComplaintController.php';
require_once __DIR__ . '/../../../backend/models/Paanyaya.php';
require_once __DIR__ . '/../../../backend/models/Setting.php';

$sysSettings = Setting::all();
$rawMedDays = $sysSettings['mediation_day_of_week'] ?? 'Tue';
$allowedMedDays = [];
if (is_string($rawMedDays)) {
    $dec = json_decode($rawMedDays, true);
    $allowedMedDays = is_array($dec) ? $dec : array_filter(array_map('trim', explode(',', $rawMedDays)));
} elseif (is_array($rawMedDays)) {
    $allowedMedDays = $rawMedDays;
}
if (empty($allowedMedDays)) {
    $allowedMedDays = ['Tue'];
}

$pluralMap = [
    'Mon' => 'Mondays', 'Tue' => 'Tuesdays', 'Wed' => 'Wednesdays',
    'Thu' => 'Thursdays', 'Fri' => 'Fridays', 'Sat' => 'Saturdays', 'Sun' => 'Sundays'
];
$dayNumMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

$medDaysPlural = array_map(fn($d) => $pluralMap[$d] ?? $d, $allowedMedDays);
if (count($medDaysPlural) === 1) {
    $medDaysLabel = reset($medDaysPlural) . ' ONLY';
} elseif (count($medDaysPlural) === 2) {
    $medDaysLabel = implode(' & ', $medDaysPlural) . ' ONLY';
} else {
    $medDaysLabel = implode(', ', array_slice($medDaysPlural, 0, -1)) . ' & ' . end($medDaysPlural);
}

$allowedMedDayNumbers = array_values(array_unique(array_filter(array_map(fn($d) => $dayNumMap[$d] ?? null, $allowedMedDays), fn($n) => $n !== null)));
$dailyHearingCapacity = (int) ($sysSettings['daily_hearing_capacity'] ?? 8);
$medHoursStart = $sysSettings['mediation_hours_start'] ?? '13:00';
$medHoursEnd = $sysSettings['mediation_hours_end'] ?? '16:30';
$medVenue = $sysSettings['mediation_venue'] ?? 'Lupon Court Room';

$paanyayaModel = new Paanyaya();
$servingOfficers = $paanyayaModel->getServingOfficers();
$summonsServers = $paanyayaModel->getSummonsServers();
$eligiblePresiders = $paanyayaModel->getEligiblePresiders();

$complaintCtrl = new ComplaintController();
$complaint = $complaintCtrl->show($complaintId);

if (!$complaint) {
    die('Complaint not found.');
}

$complainants = [];
$respondents = [];
$complainantParties = [];
$respondentParties = [];
foreach ($complaint['parties'] ?? [] as $p) {
    $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    if (!$name) {
        $name = trim($p['resident_name'] ?? '');
    }
    if (!$name) {
        $name = ($p['party_type'] ?? '') === 'Complainant' ? 'Complainant' : 'Respondent';
    }
    $p['display_name'] = $name;

    if (($p['party_type'] ?? '') === 'Complainant') {
        $complainants[] = $name;
        $complainantParties[] = $p;
    } else {
        $respondents[] = $name;
        $respondentParties[] = $p;
    }
}
$compText = !empty($complainants) ? implode(', ', $complainants) : 'No complainant listed';
$respText = !empty($respondents) ? implode(', ', $respondents) : 'No respondent listed';
$caseNum = $complaint['complaint_number'] ?? ('#' . $complaintId);
$caseTitle = $complaint['complaint_title'] ?? 'Untitled Dispute';
$rawDate = $complaint['created_at'] ?? ($complaint['incident_date'] ?? null);
$filingDate = !empty($rawDate) ? date('M d, Y', strtotime($rawDate)) : 'N/A';

if (empty($complainantParties)) {
    $complainantParties[] = [
        'party_id' => 'comp_1',
        'display_name' => 'Nagpaanyaya (Complainant)',
        'party_type' => 'Complainant'
    ];
}
if (empty($respondentParties)) {
    $respondentParties[] = [
        'party_id' => 'resp_1',
        'display_name' => 'Inanyayahan (Respondent)',
        'party_type' => 'Respondent'
    ];
}

include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/case-details-modern.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/case-details-modern.css'); ?>">

<style>
.record-minutes-wrapper {
    max-width: 960px;
    margin: 0 auto;
    padding: 24px 20px 48px;
}
.record-minutes-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
    padding: 28px 32px;
}
.rm-section-title {
    font-size: 0.98rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 14px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.rm-party-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 18px 20px;
    margin-bottom: 22px;
}
.rm-delay-tag {
    font-size: 0.76rem;
    padding: 4px 10px;
    border-radius: 9999px;
    border: 1px solid #cbd5e1;
    background: #eff6ff;
    color: #1e40af;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.15s ease;
}
.rm-delay-tag:hover {
    background: #dbeafe;
    border-color: #93c5fd;
}
.rm-outcome-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border-radius: 8px;
    padding: 14px 16px;
    cursor: pointer;
    border: 1.5px solid transparent;
    transition: all 0.15s ease;
}
.rm-outcome-card.settled-card {
    background: #f0fdf4;
    border-color: #86efac;
}
.rm-outcome-card.settled-card:hover {
    border-color: #22c55e;
}
.rm-outcome-card.no-agree-card {
    background: #fef2f2;
    border-color: #fecaca;
}
.rm-outcome-card.no-agree-card:hover {
    border-color: #ef4444;
}
.rm-choice-box {
    background: #ffffff;
    border: 1px solid #fdba74;
    border-radius: 6px;
    padding: 10px 12px;
    cursor: pointer;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
</style>

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="record-minutes-wrapper">
            <!-- Breadcrumb Navigation -->
            <nav aria-label="Breadcrumb" style="margin-bottom: 12px; display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #64748b; font-weight: 500; flex-wrap: wrap;">
                <a href="complaint-list.php" style="color: #64748b; text-decoration: none;">Complaints</a>
                <span style="color: #94a3b8;">&gt;</span>
                <a href="complaint-details.php?id=<?= $complaintId ?>&tab=paanyaya" style="color: #64748b; text-decoration: none;">Complaint #<?= htmlspecialchars($caseNum) ?></a>
                <span style="color: #94a3b8;">&gt;</span>
                <span style="color: #0f172a; font-weight: 700;">Record <?= htmlspecialchars($stageType) ?> Minutes</span>
            </nav>

            <!-- Header Navigation & Title -->
            <div style="margin-bottom: 20px;">
                <a href="complaint-details.php?id=<?= $complaintId ?>&tab=paanyaya" class="back-link" style="display: inline-flex; align-items: center; gap: 6px; color: #475569; text-decoration: none; font-size: 0.88rem; font-weight: 600; margin-bottom: 12px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    <span>Back to Complaint Details</span>
                </a>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h1 style="font-size: 1.6rem; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em;">
                            Record Dialogue Minutes &amp; Result (<?= htmlspecialchars($stageType) ?>)
                        </h1>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="paanyaya-stage-tag" style="font-size: 0.82rem; padding: 4px 12px; border-radius: 9999px; background: #e0f2fe; color: #0369a1; font-weight: 700;">
                            <?= htmlspecialchars($stageType) ?>
                        </span>
                        <span id="paanyayaDraftIndicator" class="agap-draft-indicator state-idle">✓ Saved</span>
                    </div>
                </div>
            </div>

            <!-- Case & Parties Header Strip -->
            <div class="rm-party-box">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; font-size: 0.88rem;">
                    <div>
                        <span style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: #16a34a; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Nagpaanyaya (Complainant)</span>
                        <strong style="color: #0f172a; font-size: 0.95rem; display: block;"><?= htmlspecialchars($compText) ?></strong>
                    </div>
                    <div>
                        <span style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: #2563eb; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Inanyayahan (Respondent)</span>
                        <strong style="color: #0f172a; font-size: 0.95rem; display: block;"><?= htmlspecialchars($respText) ?></strong>
                    </div>
                    <div>
                        <span style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Case Title (Paksa ng Usapin)</span>
                        <strong style="color: #0f172a; font-size: 0.95rem; display: block;"><?= htmlspecialchars($caseTitle) ?></strong>
                    </div>
                    <div>
                        <span style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Case Number &amp; Filing Date</span>
                        <strong style="color: #0f172a; font-size: 0.95rem; display: block;">Case #<?= htmlspecialchars($caseNum) ?></strong>
                        <span style="display: block; font-size: 0.78rem; color: #64748b; margin-top: 2px;">Filed: <?= htmlspecialchars($filingDate) ?></span>
                    </div>
                </div>
            </div>

            <!-- Standalone Form Card -->
            <div class="record-minutes-card">
                <form id="recordDialogueMinutesForm" onsubmit="handleDialogueMinutesSubmit(event)" enctype="multipart/form-data">
                    <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                    <input type="hidden" id="stageTypeInput" name="stage_type" value="<?= htmlspecialchars($stageType) ?>">

                    <!-- SECTION 1: Date, Time & Multi-Select Presiding Officers (Lupon / LISO) -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 22px;">
                        <div class="rm-section-title">
                            <span style="font-size: 1.1rem;">🗓️</span>
                            <span>Seksyon 1: Petsa, Oras at Mga Namuno (Date, Time &amp; Presiding Officers)</span>
                            <span class="required-mark" style="color: #dc2626;">*</span>
                        </div>

                        <!-- 1. Dialogue Date & Time -->
                        <div class="form-group" style="margin-bottom: 18px;">
                            <label for="minutesDialogueDate" style="font-weight: 600; font-size: 0.86rem; display: block; margin-bottom: 5px; color: #0f172a;">
                                Petsa at Takdang Oras (Scheduled Date &amp; Time) <span class="required-mark" style="color: #dc2626;">*</span>
                            </label>
                            <input type="datetime-local" id="minutesDialogueDate" name="dialogue_date" required style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                        </div>

                        <!-- 2. Session Delay Remarks -->
                        <div class="form-group" style="margin-bottom: 18px;">
                            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 5px;">
                                <label for="minutesSessionRemarks" style="font-weight: 600; font-size: 0.86rem; margin: 0; color: #0f172a;">
                                    Puna sa Oras ng Simula / Dahilan ng Pagkaantala (Session Delay Remarks)
                                </label>
                                <span style="font-size: 0.76rem; color: #64748b;">(Kung sakaling late nag-umpisa ang session)</span>
                            </div>
                            <textarea id="minutesSessionRemarks" name="session_remarks" rows="2" placeholder="Halimbawa: Naantala ang simula dahil may naunang kaso sa Lupon Court Room. Present ang mga partido." style="padding: 9px 12px; font-size: 0.86rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;"></textarea>
                            <!-- Quick Tag Pills -->
                            <div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                                <span style="font-size: 0.76rem; color: #64748b; font-weight: 600;">I-click para ilagay:</span>
                                <button type="button" class="rm-delay-tag" onclick="applyDelayRemark('Naantala ang simula dahil may naunang kaso sa Lupon Court Room. Present ang mga partido.')">
                                    &bull; Naunang kaso sa Court Room
                                </button>
                                <button type="button" class="rm-delay-tag" style="background: #fff7ed; color: #c2410c;" onclick="applyDelayRemark('Naantala dahil nahuli sa pagdating ang isa o magkabilang panig.')">
                                    &bull; Nahuli ang panig
                                </button>
                                <button type="button" class="rm-delay-tag" style="background: #f0fdf4; color: #166534;" onclick="applyDelayRemark('Nagsimula sa takdang oras nang walang pagkaantala.')">
                                    &bull; Nasa oras
                                </button>
                            </div>
                        </div>

                        <!-- 3. Multi-Select Presiding Officers (Lupon / LISO) -->
                        <div class="form-group" style="margin-bottom: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label for="minutesPresidingSelect" style="font-weight: 700; font-size: 0.88rem; color: #0f172a; margin: 0;">
                                    Mga Namuno / Tagapamagitan sa Pag-uusap (Presiding Officer[s] / Lupon / LISO) <span id="presidersRequiredMark" class="required-mark" style="color: #dc2626;">*</span>
                                </label>
                                <span id="presidersOptionalNote" style="font-size: 0.74rem; color: #64748b;">Maaaring pumili ng higit sa isa (Co-presiders)</span>
                            </div>

                            <div class="presider-multiselect-container" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; background: #ffffff;">
                                <!-- Selected Badges / Chips Area -->
                                <div id="selectedPresidersList" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; min-height: 32px; align-items: center;">
                                    <span id="noPresiderPlaceholder" style="font-size: 0.8rem; color: #94a3b8;">Pumili ng isa o higit pang Lupon / LISO members mula sa dropdown sa ibaba...</span>
                                </div>

                                <!-- Dropdown Selector -->
                                <select id="minutesPresidingSelect" onchange="handleAddPresider(this.value)" style="width: 100%; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; font-size: 0.86rem; background: #f8fafc;">
                                    <option value="">+ Magdagdag ng Presiding Officer / Lupon Member...</option>
                                    <?php foreach ($eligiblePresiders ?? [] as $pres): ?>
                                        <option value="<?= (int)$pres['user_id'] ?>" data-name="<?= htmlspecialchars($pres['full_name']) ?>" data-role="<?= htmlspecialchars($pres['role_name']) ?>">
                                            <?= htmlspecialchars($pres['full_name']) ?> (<?= htmlspecialchars($pres['role_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" id="minutesPresidingOfficerIds" name="presiding_officer_ids" value="[]">
                        </div>
                    </div>

                    <!-- SECTION 2: Party Attendance -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 22px;">
                        <div class="rm-section-title">
                            <span style="font-size: 1.1rem;">👥</span>
                            <span>Seksyon 2: Pagdalo ng Magkabilang Panig (Party Attendance)</span>
                            <span class="required-mark" style="color: #dc2626;">*</span>
                        </div>

                        <input type="hidden" id="overallComplainantAttended" name="complainant_attended" value="Present">
                        <input type="hidden" id="overallRespondentAttended" name="respondent_attended" value="Present">

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <!-- Nagpaanyaya (Complainants) -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <span style="font-size: 0.82rem; color: #166534; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">
                                        Nagpaanyaya (Complainant<?= count($complainantParties) > 1 ? 's' : '' ?>)
                                    </span>
                                    <span style="font-size: 0.74rem; background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">
                                        <?= count($complainantParties) ?> <?= count($complainantParties) > 1 ? 'Parties' : 'Party' ?>
                                    </span>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 10px;">
                                    <?php foreach ($complainantParties as $p): 
                                        $pid = htmlspecialchars((string)$p['party_id']);
                                    ?>
                                        <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                                <strong style="color: #0f172a; font-size: 0.88rem;">
                                                    <?= htmlspecialchars($p['display_name']) ?>
                                                </strong>
                                                <div style="display: flex; gap: 8px; font-size: 0.82rem;">
                                                    <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; padding: 5px 10px; border-radius: 6px; background: #f0fdf4; border: 1px solid #bbf7d0;">
                                                        <input type="radio" class="comp-party-attendance" name="party_attendance[<?= $pid ?>]" value="Present" checked onchange="updateOverallAttendance()">
                                                        <span style="color: #166534; font-weight: 600;">🟢 Dumalo</span>
                                                    </label>
                                                    <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; padding: 5px 10px; border-radius: 6px; background: #fef2f2; border: 1px solid #fecaca;">
                                                        <input type="radio" class="comp-party-attendance" name="party_attendance[<?= $pid ?>]" value="Absent" onchange="updateOverallAttendance()">
                                                        <span style="color: #991b1b; font-weight: 600;">🔴 Absent</span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Inanyayahan (Respondents) -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <span style="font-size: 0.82rem; color: #2563eb; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">
                                        Inanyayahan (Respondent<?= count($respondentParties) > 1 ? 's' : '' ?>)
                                    </span>
                                    <span style="font-size: 0.74rem; background: #eff6ff; color: #1e40af; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">
                                        <?= count($respondentParties) ?> <?= count($respondentParties) > 1 ? 'Parties' : 'Party' ?>
                                    </span>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 10px;">
                                    <?php foreach ($respondentParties as $p): 
                                        $pid = htmlspecialchars((string)$p['party_id']);
                                    ?>
                                        <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                                <strong style="color: #0f172a; font-size: 0.88rem;">
                                                    <?= htmlspecialchars($p['display_name']) ?>
                                                </strong>
                                                <div style="display: flex; gap: 8px; font-size: 0.82rem;">
                                                    <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; padding: 5px 10px; border-radius: 6px; background: #f0fdf4; border: 1px solid #bbf7d0;">
                                                        <input type="radio" class="resp-party-attendance" name="party_attendance[<?= $pid ?>]" value="Present" checked onchange="updateOverallAttendance()">
                                                        <span style="color: #166534; font-weight: 600;">🟢 Dumalo</span>
                                                    </label>
                                                    <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; padding: 5px 10px; border-radius: 6px; background: #fef2f2; border: 1px solid #fecaca;">
                                                        <input type="radio" class="resp-party-attendance" name="party_attendance[<?= $pid ?>]" value="Absent" onchange="updateOverallAttendance()">
                                                        <span style="color: #991b1b; font-weight: 600;">🔴 Absent</span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div id="minutesAttendanceNotice" style="display: none; margin-top: 14px; padding: 10px 14px; border-radius: 8px; font-size: 0.84rem; line-height: 1.4;"></div>
                    </div>

                    <!-- SECTION 3: Salaysay at Buod ng Usapan -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 22px;">
                        <div class="rm-section-title">
                            <span style="font-size: 1.1rem;">📝</span>
                            <span>Seksyon 3: Salaysay at Buod ng Usapan</span>
                            <span class="required-mark sec3-required-mark" style="color: #dc2626;">*</span>
                        </div>

                        <!-- Summary Textarea -->
                        <div class="form-group" style="margin-bottom: 18px;">
                            <label for="minutesDiscussionSummary" style="font-weight: 600; font-size: 0.86rem; color: #0f172a; display: block; margin-bottom: 5px;">
                                Buod ng Usapan / Tala ng Pagharap <span class="required-mark sec3-required-mark" style="color: #dc2626;">*</span>
                            </label>
                            <textarea id="minutesDiscussionSummary" name="discussion_summary" rows="5" required placeholder="Ibuod ang mga pahayag, sagot, at mga puntong napag-usapan ng magkabilang panig habang isinasagawa ang dialogue..." style="padding: 10px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;"></textarea>
                        </div>

                        <!-- Scanned Minutes Upload -->
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minutesScannedFile" style="font-weight: 600; font-size: 0.86rem; color: #0f172a; display: block; margin-bottom: 5px;">
                                Kopya ng Isinulat-Kamay na Katitikan / Minutes (Optional Photo or Scan)
                            </label>
                            <input type="file" id="minutesScannedFile" name="scanned_minutes" accept=".jpg,.jpeg,.png,.pdf,.webp" style="padding: 8px 10px; font-size: 0.84rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                            <span style="font-size: 0.76rem; color: #64748b; margin-top: 4px; display: block;">Maaaring mag-attach ng litrato o scan ng sulat-kamay na katitikan na may lagda ng mga panig (JPG, PNG, PDF hanggang 10MB).</span>
                        </div>
                    </div>

                    <!-- SECTION 4: Procedural Outcome & Actions -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 22px;">
                        <div class="rm-section-title">
                            <span style="font-size: 1.1rem;">⚖️</span>
                            <span>Seksyon 4: Kinalabasan ng Pag-uusap at Susunod na Hakbang (Procedural Outcome &amp; Actions)</span>
                            <span class="required-mark" style="color: #dc2626;">*</span>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 18px;">
                            <label id="optMinutesSettledWrap" class="rm-outcome-card settled-card">
                                <input type="radio" name="outcome" value="Settled" checked onchange="toggleMinutesOutcomeViews()" style="margin-top: 4px;">
                                <div>
                                    <strong style="color: #166534; font-size: 0.94rem;">Nagkasundo / Amicably Settled (Informal Settlement)</strong>
                                    <p style="margin: 3px 0 0; font-size: 0.82rem; color: #166534;">Nalutas ang usapin sa impormal na pag-uusap. Isasara ang complaint nang hindi na kailangang i-docket sa pormal na korte (LTIA performance win).</p>
                                </div>
                            </label>

                            <label id="optMinutesNoAgreementWrap" class="rm-outcome-card no-agree-card">
                                <input type="radio" name="outcome" value="No_Agreement" onchange="toggleMinutesOutcomeViews()" style="margin-top: 4px;">
                                <div>
                                    <strong style="color: #991b1b; font-size: 0.94rem;">Walang Kasunduan / No Agreement Reached (or Respondent No-Show)</strong>
                                    <p style="margin: 3px 0 0; font-size: 0.82rem; color: #991b1b;">Hindi nagkasundo ang mga panig, tumanggi ang inanyayahan, o hindi sumipot. Piliin ang desisyon ng nagrereklamo sa ibaba.</p>
                                </div>
                            </label>

                            <label id="optMinutesDismissedWrap" class="rm-outcome-card dismissed-card" style="display: none; border: 1.5px solid #f87171; background: #fef2f2;">
                                <input type="radio" name="outcome" value="Dismissed" onchange="toggleMinutesOutcomeViews()" style="margin-top: 4px;">
                                <div>
                                    <strong style="color: #991b1b; font-size: 0.94rem;">🚫 I-dismiss ang Reklamo / Dismiss Complaint (Complainant or Both Parties Absent)</strong>
                                    <p style="margin: 3px 0 0; font-size: 0.82rem; color: #b91c1c;">Hindi humarap o dumalo ang nagreklamo sa takdang oras ng pag-uusap. Isasara at ididismis ang reklamo alinsunod sa KP rules.</p>
                                </div>
                            </label>
                        </div>

                        <!-- Settled: Proposals / Agreed Terms Section -->
                        <div id="minutesAgreedTermsSection" style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px;">
                            <label for="minutesAgreedTerms" style="font-weight: 700; font-size: 0.88rem; color: #166534; display: block; margin-bottom: 6px;">
                                🤝 Mga Mungkahi o Napagkasunduang Kondisyon (Proposals / Agreed Terms) <span class="required-mark" style="color: #dc2626;">*</span>
                            </label>
                            <p style="margin: 0 0 8px 0; font-size: 0.78rem; color: #15803d;">
                                Itala rito ang naging pinal na kasunduan, kondisyon sa bayaran, takdang araw ng pagtupad, o anumang pangako ng magkabilang panig upang pormal na maipasara ang reklamo.
                            </p>
                            <textarea id="minutesAgreedTerms" name="agreed_terms" rows="3" placeholder="Kondisyon ng bayaran, pagsasaayos, o pangako ng magkabilang panig..." style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #86efac; border-radius: 7px; width: 100%; box-sizing: border-box; background: #ffffff;"></textarea>
                            <small id="minutesAgreedTermsError" style="display: none; color: #dc2626; font-size: 0.75rem; font-weight: 600; margin-top: 4px; display: none;"></small>
                        </div>

                        <!-- No Agreement: Complainant Choice Section -->
                        <div id="minutesComplainantChoiceSection" style="display: none; background: #fff7ed; border: 1.5px solid #fed7aa; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px;">
                            <label style="font-weight: 700; font-size: 0.88rem; color: #9a3412; display: block; margin-bottom: 8px;">
                                Ano ang nais ng nagrereklamo? (Complainant's Decision) <span class="required-mark" style="color: #dc2626;">*</span>
                            </label>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <div id="optChoiceP2Group" style="display: flex; flex-direction: column;">
                                    <label id="optChoiceP2Wrap" class="rm-choice-box">
                                        <input type="radio" name="complainant_choice" value="Request_Paanyaya_2" checked onchange="toggleComplainantChoiceViews()" style="margin-top: 3px;">
                                        <div>
                                            <strong style="color: #c2410c; font-size: 0.86rem;">Choice A: Humiling ng Ikalawang Paanyaya (Give another chance / Request 2nd Paanyaya)</strong>
                                            <p style="margin: 2px 0 0; font-size: 0.78rem; color: #7c2d12;">I-unlock at sabay na itakda ang Paanyaya 2 habang naririto ang nagrereklamo.</p>
                                        </div>
                                    </label>

                                    <!-- 2nd Paanyaya Scheduling Container (Visible ONLY when Choice A is active) -->
                                    <div id="minutesPaanyaya2ScheduleSection" style="display: none; margin-top: 10px; background: #ffffff; border: 1.5px solid #fed7aa; border-radius: 8px; padding: 14px 16px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 6px;">
                                            <h5 style="margin: 0; font-size: 0.88rem; color: #9a3412; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                                                <span>📅</span> Iskedyul ng Ikalawang Paanyaya (2nd Dialogue Schedule)
                                            </h5>
                                            <span style="font-size: 0.72rem; background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">
                                                Kasamang Pagtatakda (Inline Scheduling)
                                            </span>
                                        </div>
                                        <p style="margin: 0 0 10px 0; font-size: 0.78rem; color: #7c2d12; line-height: 1.4;">
                                            Itakda agad ang petsa at oras ng Ikalawang Paanyaya habang naririto ang nagrereklamo upang mapagkasunduan ang susunod na pag-uusap.
                                        </p>
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                                            <div class="form-group" style="margin-bottom: 0;">
                                                <label for="minutesP2Date" style="font-weight: 700; font-size: 0.82rem; display: block; margin-bottom: 4px; color: #1e293b;">
                                                    2nd Dialogue Date <span class="required-mark" style="color: #dc2626;">*</span>
                                                </label>
                                                <input type="date" id="minutesP2Date" name="p2_scheduled_date" onchange="validateP2Schedule()" style="padding: 8px 10px; font-size: 0.86rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; box-sizing: border-box;">
                                                <small id="minutesP2DateError" style="display: none; color: #dc2626; font-size: 0.75rem; font-weight: 600; margin-top: 3px;"></small>
                                            </div>
                                            <div class="form-group" style="margin-bottom: 0;">
                                                <label for="minutesP2Time" style="font-weight: 700; font-size: 0.82rem; display: block; margin-bottom: 4px; color: #1e293b;">
                                                    2nd Dialogue Time (08:00 - 17:00) <span class="required-mark" style="color: #dc2626;">*</span>
                                                </label>
                                                <input type="time" id="minutesP2Time" name="p2_scheduled_time" value="14:00" min="08:00" max="17:00" onchange="validateP2Schedule()" style="padding: 8px 10px; font-size: 0.86rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; box-sizing: border-box;">
                                                <small id="minutesP2TimeError" style="display: none; color: #dc2626; font-size: 0.75rem; font-weight: 600; margin-top: 3px;"></small>
                                            </div>
                                        </div>
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                            <div class="form-group" style="margin-bottom: 0;">
                                                <label for="minutesP2Venue" style="font-weight: 700; font-size: 0.82rem; display: block; margin-bottom: 4px; color: #1e293b;">
                                                    Venue
                                                </label>
                                                <input type="text" id="minutesP2Venue" name="p2_venue" value="Lupon Courtroom" style="padding: 8px 10px; font-size: 0.86rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; box-sizing: border-box;">
                                            </div>
                                            <div class="form-group" style="margin-bottom: 0;">
                                                <label for="minutesP2Officer" style="font-weight: 700; font-size: 0.82rem; display: block; margin-bottom: 4px; color: #1e293b;">
                                                    The Serving Officer
                                                </label>
                                                <select id="minutesP2Officer" name="p2_assigned_officer_id" style="padding: 8px 10px; font-size: 0.86rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; box-sizing: border-box;">
                                                    <?php foreach ($summonsServers as $idx => $off): ?>
                                                        <option value="<?= (int)$off['user_id'] ?>" <?= $idx === 0 ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($off['full_name']) ?> (<?= htmlspecialchars($off['role_name']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <label id="optChoiceEscalateWrap" class="rm-choice-box">
                                    <input type="radio" name="complainant_choice" value="Escalate_Mediation" onchange="toggleComplainantChoiceViews()" style="margin-top: 3px;">
                                    <div>
                                        <strong style="color: #dc2626; font-size: 0.86rem;">Choice B: I-akyat na sa Pormal na Mediation / Punong Barangay (Escalate immediately)</strong>
                                        <p style="margin: 2px 0 0; font-size: 0.78rem; color: #991b1b;">Laktawan ang Paanyaya 2. Magtatalaga ng official Case Docket Number, KP Form 7 (Complaint), at KP Form 9 (Summons).</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Escalation 1st Mediation Hearing Schedule (Visible ONLY when Choice B is active) -->
                        <div id="minutesEscalationScheduleSection" style="display: none; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 18px; margin-bottom: 10px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                                <h4 style="margin: 0; font-size: 0.96rem; color: #0f172a; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                                    <span>⚖️</span> 1st Formal Mediation Hearing Schedule
                                </h4>
                                <span style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 3px 10px; border-radius: 9999px; font-weight: 700;">
                                    <?= htmlspecialchars($medDaysLabel) ?> · <?= htmlspecialchars($medVenue) ?> (Max <?= $dailyHearingCapacity ?>/Day)
                                </span>
                            </div>

                            <!-- Date input and Visual Day Picker -->
                            <div class="form-group" style="margin-bottom: 14px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <label for="minutesMedDate" style="margin-bottom: 0; font-weight: 700; font-size: 0.85rem; color: #1e293b;">
                                        Mediation Date (<?= htmlspecialchars($medDaysLabel) ?>) <span class="required-mark" style="color: #dc2626;">*</span>
                                    </label>
                                    <span id="minutesCapacityBadge" class="badge-capacity" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                                        Checking capacity...
                                    </span>
                                </div>
                                <input type="date" id="minutesMedDate" name="mediation_date" onchange="handleMinutesMedDateChange(this)" required style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box; margin-bottom: 4px;">
                                <small id="minutesMedDateError" style="display: none; color: #dc2626; font-size: 0.75rem; font-weight: 600; margin-bottom: 6px;"></small>

                                <!-- Visual Mediation Date Picker Container -->
                                <div id="minutesVisualDatePickerContainer" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.78rem; flex-wrap: wrap; gap: 6px;">
                                        <span style="font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em; font-size: 0.74rem;">
                                            Available Mediation Slots (Max <?= $dailyHearingCapacity ?> Hearings/Day):
                                        </span>
                                        <div style="display: flex; gap: 10px; font-size: 0.72rem; flex-wrap: wrap;">
                                            <span style="display: inline-flex; align-items: center; gap: 4px; color: #166534;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e;"></span> Available</span>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; color: #991b1b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444;"></span> <?= $dailyHearingCapacity ?>/<?= $dailyHearingCapacity ?> Full</span>
                                        </div>
                                    </div>
                                    <div id="minutesVisualDayGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(88px, 1fr)); gap: 6px; padding: 4px 2px;">
                                        <div style="text-align: center; color: #64748b; font-size: 0.78rem; padding: 8px; grid-column: 1 / -1;">
                                            Loading mediation availability...
                                        </div>
                                    </div>
                                    <div id="minutesVisualDateStatusNotice" style="margin-top: 6px; font-size: 0.75rem; color: #64748b; line-height: 1.4;"></div>
                                </div>
                            </div>

                            <!-- Slot Selector & Venue Grid (Elastic Target Call Time) -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                        <label for="minutesMedTime" style="font-weight: 700; font-size: 0.84rem; margin: 0; color: #1e293b;">
                                            Target Call Time (<?= date('g:i A', strtotime($medHoursStart)) ?> - <?= date('g:i A', strtotime($medHoursEnd)) ?>) <span class="required-mark" style="color: #dc2626;">*</span>
                                        </label>
                                        <span style="font-size: 0.72rem; color: #64748b;">15-min intervals</span>
                                    </div>
                                    <input type="time" id="minutesMedTime" name="mediation_time" min="<?= $medHoursStart ?>" max="<?= $medHoursEnd ?>" step="900" value="<?= $medHoursStart ?>" onchange="handleMinutesMedTimeChange(this)" style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                                    <small id="minutesMedTimeError" style="display: none; color: #dc2626; font-size: 0.75rem; font-weight: 600; margin-top: 3px;"></small>
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px;">
                                        <button type="button" class="btn-slot-chip" onclick="document.getElementById('minutesMedTime').value='13:00'; checkMinutesScheduleConflict();" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:00 PM</button>
                                        <button type="button" class="btn-slot-chip" onclick="document.getElementById('minutesMedTime').value='13:15'; checkMinutesScheduleConflict();" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:15 PM</button>
                                        <button type="button" class="btn-slot-chip" onclick="document.getElementById('minutesMedTime').value='13:30'; checkMinutesScheduleConflict();" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:30 PM</button>
                                        <button type="button" class="btn-slot-chip" onclick="document.getElementById('minutesMedTime').value='13:45'; checkMinutesScheduleConflict();" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:45 PM</button>
                                        <button type="button" class="btn-slot-chip" onclick="document.getElementById('minutesMedTime').value='14:00'; checkMinutesScheduleConflict();" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:00 PM</button>
                                        <button type="button" class="btn-slot-chip" onclick="document.getElementById('minutesMedTime').value='14:30'; checkMinutesScheduleConflict();" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:30 PM</button>
                                        <button type="button" class="btn-slot-chip" onclick="document.getElementById('minutesMedTime').value='15:00'; checkMinutesScheduleConflict();" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">3:00 PM</button>
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="minutesMedVenue" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 5px; color: #1e293b;">Hearing Venue</label>
                                    <input type="text" id="minutesMedVenue" name="venue" value="<?= htmlspecialchars($medVenue) ?>" readonly style="padding: 9px 12px; font-size: 0.88rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box; cursor: not-allowed;">
                                </div>
                            </div>

                            <!-- Live Day Docket Peek (Existing Scheduled Mediations on selected date) -->
                            <div id="minutesDaySchedulesWrap" style="display: none; margin-bottom: 12px; padding: 10px 12px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.84rem;">
                                <div style="font-weight: 700; color: #334155; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                                    <span>📋 Mediation Docket Call (<?= htmlspecialchars($medVenue) ?>):</span>
                                    <span id="minutesDaySlotsCount" style="font-size: 0.72rem; background: #e2e8f0; color: #475569; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">0</span>
                                </div>
                                <div id="minutesDaySlotsList" style="color: #64748b; font-size: 0.82rem; line-height: 1.5;">
                                    No other mediations scheduled on this day.
                                </div>
                            </div>

                            <!-- Real-Time Conflict Alert Banner -->
                            <div id="minutesScheduleConflictAlert" class="alert alert-danger" style="display: none; margin: 0; padding: 10px 12px; font-size: 0.84rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; line-height: 1.4;">
                            </div>
                        </div>
                    </div>

                    <div id="minutesErrorMessage" class="alert alert-danger" style="display: none; margin-bottom: 18px; padding: 12px 16px; border-radius: 8px; font-size: 0.88rem; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;"></div>

                    <!-- Action Buttons at the Bottom -->
                    <div style="display: flex; justify-content: flex-end; align-items: center; gap: 12px; margin-top: 24px; padding-top: 18px; border-top: 1px solid #f1f5f9;">
                        <a href="complaint-details.php?id=<?= $complaintId ?>&tab=paanyaya" class="btn-secondary" style="padding: 10px 20px; font-size: 0.9rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border-radius: 8px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            Cancel / Back to Complaint Details
                        </a>
                        <button type="submit" id="btnSubmitMinutes" class="btn-create" style="padding: 10px 24px; font-size: 0.92rem; background: #16a34a; border-color: #16a34a; color: #ffffff; border-radius: 8px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                            <span>💾</span> Save &amp; Finalize Dialogue Minutes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
window.AGAP_SETTINGS = window.AGAP_SETTINGS || {};
window.AGAP_SETTINGS.mediation_days = <?= json_encode($allowedMedDays) ?>;
window.AGAP_SETTINGS.mediation_days_label = <?= json_encode($medDaysLabel) ?>;
window.AGAP_SETTINGS.mediation_day_numbers = <?= json_encode($allowedMedDayNumbers) ?>;
window.AGAP_SETTINGS.daily_capacity = <?= (int) $dailyHearingCapacity ?>;
window.AGAP_SETTINGS.mediation_hours_start = <?= json_encode($medHoursStart) ?>;
window.AGAP_SETTINGS.mediation_hours_end = <?= json_encode($medHoursEnd) ?>;
window.AGAP_SETTINGS.mediation_venue = <?= json_encode($medVenue) ?>;

// Multi-Select Presiders Management (starts blank, no default pre-selected presider)
let selectedPresiders = [];

function handleAddPresider(val) {
    if (!val) return;
    const selectEl = document.getElementById("minutesPresidingSelect");
    if (!selectEl) return;
    const opt = selectEl.options[selectEl.selectedIndex];
    if (!opt || !opt.value) return;

    const id = parseInt(opt.value, 10);
    const name = opt.getAttribute("data-name") || opt.textContent.split("(")[0].trim();
    const role = opt.getAttribute("data-role") || "Lupon Facilitator";

    if (!selectedPresiders.some(p => p.id === id)) {
        selectedPresiders.push({ id, name, role });
        renderPresiderChips();
    }
    selectEl.value = "";
}

function handleRemovePresider(id) {
    selectedPresiders = selectedPresiders.filter(p => p.id !== id);
    renderPresiderChips();
}

function renderPresiderChips() {
    const listEl = document.getElementById("selectedPresidersList");
    const inputEl = document.getElementById("minutesPresidingOfficerIds");
    if (!listEl || !inputEl) return;

    inputEl.value = JSON.stringify(selectedPresiders.map(p => p.id));

    if (selectedPresiders.length === 0) {
        listEl.innerHTML = '<span id="noPresiderPlaceholder" style="font-size: 0.8rem; color: #94a3b8;">Pumili ng isa o higit pang Lupon / LISO members mula sa dropdown sa ibaba...</span>';
    } else {
        listEl.innerHTML = selectedPresiders.map(p => `
            <span class="presider-chip" style="display: inline-flex; align-items: center; gap: 6px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 9999px; padding: 4px 10px; font-size: 0.82rem; font-weight: 600;">
                <span>⚖️ ${escapeHtml(p.name)} <span style="font-size: 0.74rem; opacity: 0.85;">(${escapeHtml(p.role)})</span></span>
                <button type="button" onclick="handleRemovePresider(${p.id})" title="Remove" style="background: none; border: none; color: #1e40af; font-size: 1.05rem; line-height: 1; cursor: pointer; padding: 0 2px; font-weight: 700;">&times;</button>
            </span>
        `).join("");
    }
}
window.handleAddPresider = handleAddPresider;
window.handleRemovePresider = handleRemovePresider;
window.renderPresiderChips = renderPresiderChips;

const complaintId = <?= (int)$complaintId ?>;
const currentStage = <?= json_encode($stageType) ?>;

function setMinutesActualStartNow(e) {
    if (e && typeof e.preventDefault === 'function') {
        e.preventDefault();
    }
    const actualStart = document.getElementById('minutesActualStartTime');
    const btn = document.getElementById('btnActualStartNow') || (e && e.target);
    if (actualStart) {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        actualStart.value = `${hh}:${mm}`;
        actualStart.dispatchEvent(new Event('input', { bubbles: true }));
        actualStart.dispatchEvent(new Event('change', { bubbles: true }));
        actualStart.focus();

        actualStart.style.transition = 'border-color 0.2s, box-shadow 0.2s';
        actualStart.style.borderColor = '#16a34a';
        actualStart.style.boxShadow = '0 0 0 3px rgba(22, 163, 74, 0.2)';

        if (btn) {
            const originalText = btn.getAttribute('data-orig-text') || btn.innerHTML;
            btn.setAttribute('data-orig-text', originalText);
            btn.innerHTML = `✓ Naitala (${hh}:${mm})`;
            btn.style.color = '#16a34a';
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.style.color = '#2563eb';
                actualStart.style.borderColor = '#cbd5e1';
                actualStart.style.boxShadow = 'none';
            }, 1800);
        }
    }
}
window.setMinutesActualStartNow = setMinutesActualStartNow;

document.addEventListener('DOMContentLoaded', () => {
    // Render initial presider chips
    renderPresiderChips();

    // Set default dialogue date/time to now (local timezone)
    const dtInput = document.getElementById('minutesDialogueDate');
    if (dtInput) {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        dtInput.value = now.toISOString().slice(0, 16);
    }

    // Set default actual start time to now
    setMinutesActualStartNow();

    // Configure next mediation date
    const medDateInput = document.getElementById('minutesMedDate');
    if (medDateInput) {
        const allowedNums = window.AGAP_SETTINGS?.mediation_day_numbers || [2];
        const d = new Date();
        d.setDate(d.getDate() + 1);
        while (!allowedNums.includes(d.getDay())) {
            d.setDate(d.getDate() + 1);
        }
        medDateInput.value = d.toISOString().slice(0, 10);
        medDateInput.min = new Date().toISOString().slice(0, 10);
        checkMinutesScheduleConflict();
    }

    // For Paanyaya 2, Choice A is not applicable ("Dalawang Beses Lang" rule)
    const optChoiceP2Wrap = document.getElementById('optChoiceP2Wrap');
    if (optChoiceP2Wrap && currentStage === 'Paanyaya 2') {
        optChoiceP2Wrap.style.display = 'none';
        const escChoice = document.querySelector('input[name="complainant_choice"][value="Escalate_Mediation"]');
        if (escChoice) escChoice.checked = true;
    }

    updateOverallAttendance();
    initP2ScheduleDefaults();
    toggleMinutesOutcomeViews();

    if (window.AgapDraftManager) {
        window.minutesDraftManager = window.AgapDraftManager.init({
            formId: 'recordDialogueMinutesForm',
            storageKey: `agap_draft_paanyaya_${currentStage}_comp_${complaintId}`,
            indicatorId: 'paanyayaDraftIndicator',
            onRestore: () => {
                const actStart = document.getElementById('minutesActualStartTime');
                if (actStart && !actStart.value) {
                    setMinutesActualStartNow();
                }
                handleMinutesAttendanceChange();
                initP2ScheduleDefaults();
                toggleMinutesOutcomeViews();
            },
            onClear: () => {
                setMinutesActualStartNow();
                initP2ScheduleDefaults();
                toggleMinutesOutcomeViews();
            }
        });
    }
});

function applyDelayRemark(text) {
    const ta = document.getElementById('minutesSessionRemarks');
    if (ta) {
        ta.value = text;
        ta.focus();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
window.applyDelayRemark = applyDelayRemark;

function updateOverallAttendance() {
    const compRadios = document.querySelectorAll('.comp-party-attendance:checked');
    const respRadios = document.querySelectorAll('.resp-party-attendance:checked');

    let anyCompPresent = false;
    let allCompAbsent = compRadios.length > 0;
    compRadios.forEach(r => {
        if (r.value === 'Present') anyCompPresent = true;
        if (r.value !== 'Absent') allCompAbsent = false;
    });

    let anyRespPresent = false;
    let allRespAbsent = respRadios.length > 0;
    respRadios.forEach(r => {
        if (r.value === 'Present') anyRespPresent = true;
        if (r.value !== 'Absent') allRespAbsent = false;
    });

    const compOverallInput = document.getElementById('overallComplainantAttended');
    const respOverallInput = document.getElementById('overallRespondentAttended');

    if (compOverallInput) {
        compOverallInput.value = allCompAbsent ? 'Absent' : 'Present';
    }
    if (respOverallInput) {
        respOverallInput.value = allRespAbsent ? 'Absent' : 'Present';
    }

    handleMinutesAttendanceChange();
}
window.updateOverallAttendance = updateOverallAttendance;

function handleMinutesAttendanceChange() {
    const form = document.getElementById('recordDialogueMinutesForm');
    if (!form) return;
    const compAttended = document.getElementById('overallComplainantAttended')?.value || 'Present';
    const respAttended = document.getElementById('overallRespondentAttended')?.value || 'Present';
    const optSettledWrap = document.getElementById('optMinutesSettledWrap');
    const optNoAgreeWrap = document.getElementById('optMinutesNoAgreementWrap');
    const optDismissedWrap = document.getElementById('optMinutesDismissedWrap');
    const dismissRadio = form.querySelector('input[name="outcome"][value="Dismissed"]');
    const settledRadio = form.querySelector('input[name="outcome"][value="Settled"]');
    const noAgreeRadio = form.querySelector('input[name="outcome"][value="No_Agreement"]');
    const attendanceNotice = document.getElementById('minutesAttendanceNotice');
    const summaryTextarea = document.getElementById('minutesDiscussionSummary');
    const sec3Marks = document.querySelectorAll('.sec3-required-mark');

    // Handle Presiding Officers requirement based on attendance (Only required if both parties attended)
    const presidersReqMark = document.getElementById('presidersRequiredMark');
    const presidersNote = document.getElementById('presidersOptionalNote');
    const noPresiderPlaceholder = document.getElementById('noPresiderPlaceholder');
    const errBoxEl = document.getElementById('minutesErrorMessage');

    if (compAttended === 'Absent' || respAttended === 'Absent') {
        if (presidersReqMark) presidersReqMark.style.display = 'none';
        if (presidersNote) {
            presidersNote.innerHTML = '<span style="color: #64748b; font-style: italic;">(Opsyonal &bull; walang naganap na pag-uusap dahil hindi dumalo ang panig)</span>';
        }
        if (noPresiderPlaceholder) {
            noPresiderPlaceholder.textContent = 'Opsyonal: Maaaring iwanang bakante dahil hindi dumalo ang panig.';
        }
        if (errBoxEl && errBoxEl.textContent.includes('Presiding Officer')) {
            errBoxEl.textContent = '';
            errBoxEl.style.display = 'none';
        }
    } else {
        if (presidersReqMark) presidersReqMark.style.display = 'inline';
        if (presidersNote) {
            presidersNote.textContent = 'Maaaring pumili ng higit sa isa (Co-presiders)';
        }
        if (noPresiderPlaceholder) {
            noPresiderPlaceholder.textContent = 'Pumili ng isa o higit pang Lupon / LISO members mula sa dropdown sa ibaba...';
        }
    }

    // Handle Seksyon 3 requirement based on attendance
    if (compAttended === 'Absent' || respAttended === 'Absent') {
        if (summaryTextarea) {
            summaryTextarea.required = false;
            summaryTextarea.placeholder = 'Opsyonal: Hindi sumipot ang panig kaya walang pormal na pag-uusap na naisagawa.';
        }
        sec3Marks.forEach(el => { el.style.display = 'none'; });
    } else {
        if (summaryTextarea) {
            summaryTextarea.required = true;
            summaryTextarea.placeholder = 'Ibuod ang mga pahayag, sagot, at mga puntong napag-usapan ng magkabilang panig habang isinasagawa ang dialogue...';
        }
        sec3Marks.forEach(el => { el.style.display = 'inline'; });
    }

    const isComplainantOrBothAbsent = (compAttended === 'Absent') || (compAttended === 'Absent' && respAttended === 'Absent');

    if (isComplainantOrBothAbsent) {
        // Complainant is Absent OR Both Parties Absent -> Case must be Dismissed under KP law (Sec. 410b)
        if (optDismissedWrap) {
            optDismissedWrap.style.display = 'flex';
        }
        if (dismissRadio) {
            dismissRadio.checked = true;
        }
        // Lock out Settled and No Agreement
        if (optSettledWrap) {
            optSettledWrap.style.opacity = '0.35';
            optSettledWrap.style.pointerEvents = 'none';
        }
        if (optNoAgreeWrap) {
            optNoAgreeWrap.style.opacity = '0.35';
            optNoAgreeWrap.style.pointerEvents = 'none';
        }
        if (attendanceNotice) {
            attendanceNotice.style.display = 'block';
            attendanceNotice.style.background = '#fef2f2';
            attendanceNotice.style.border = '1px solid #fca5a5';
            attendanceNotice.style.color = '#991b1b';
            attendanceNotice.innerHTML = '<strong>⚠️ Batas Katarungang Pambarangay (Sec. 410b):</strong> Dahil hindi humarap ang nagrereklamo (o ang magkabilang panig), ang reklamo ay awtomatikong ididismis alinsunod sa KP Rules (Sec. 410b).';
        }
    } else {
        // Complainant is Present -> Dismissed is hidden
        if (optDismissedWrap) {
            optDismissedWrap.style.display = 'none';
        }
        if (dismissRadio && dismissRadio.checked) {
            if (respAttended === 'Absent') {
                if (noAgreeRadio) noAgreeRadio.checked = true;
            } else {
                if (settledRadio) settledRadio.checked = true;
            }
        }
        if (optNoAgreeWrap) {
            optNoAgreeWrap.style.opacity = '1';
            optNoAgreeWrap.style.pointerEvents = '';
        }

        if (respAttended === 'Absent') {
            // Respondent is Absent -> Cannot settle! Must be No Agreement / Respondent No-Show
            if (noAgreeRadio) {
                noAgreeRadio.checked = true;
            }
            if (optSettledWrap) {
                optSettledWrap.style.opacity = '0.35';
                optSettledWrap.style.pointerEvents = 'none';
            }
            if (attendanceNotice) {
                attendanceNotice.style.display = 'block';
                attendanceNotice.style.background = '#eff6ff';
                attendanceNotice.style.border = '1px solid #bfdbfe';
                attendanceNotice.style.color = '#1e40af';
                attendanceNotice.innerHTML = '<strong>ℹ️ Batas Katarungang Pambarangay:</strong> Hindi maaaring magkasundo kung walang respondent (Respondent No-Show). Awtomatikong pinili ang <em>Walang Kasunduan</em> sa Seksyon 4 upang makapili ng susunod na hakbang (Paanyaya 2 o Mediation).';
            }
        } else {
            // Both are Present -> Both Settled and No Agreement available
            if (optSettledWrap) {
                optSettledWrap.style.opacity = '1';
                optSettledWrap.style.pointerEvents = '';
            }
            if (attendanceNotice) {
                attendanceNotice.style.display = 'none';
                attendanceNotice.innerHTML = '';
            }
        }
    }
    initP2ScheduleDefaults();
    toggleMinutesOutcomeViews();
}
window.handleMinutesAttendanceChange = handleMinutesAttendanceChange;

function validateP2Schedule() {
    const dateInput = document.getElementById('minutesP2Date');
    const timeInput = document.getElementById('minutesP2Time');
    const dateErr = document.getElementById('minutesP2DateError');
    const timeErr = document.getElementById('minutesP2TimeError');
    let valid = true;

    if (dateInput && dateInput.value) {
        const val = dateInput.value;
        const today = new Date().toISOString().slice(0, 10);
        const parts = val.split('-');
        const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));

        if (val <= today) {
            if (dateErr) {
                dateErr.textContent = 'Ang petsa ng Ikalawang Paanyaya ay hindi maaaring lumipas na o ngayong araw.';
                dateErr.style.display = 'block';
            }
            valid = false;
        } else if (d.getDay() === 0 || d.getDay() === 6) {
            if (dateErr) {
                dateErr.textContent = 'Ang iskedyul ay dapat pumatak sa regular na araw ng trabaho (Lunes hanggang Biyernes).';
                dateErr.style.display = 'block';
            }
            valid = false;
        } else {
            if (dateErr) dateErr.style.display = 'none';
        }
    }

    if (timeInput && timeInput.value) {
        const tVal = timeInput.value;
        if (tVal < '08:00' || tVal > '17:00') {
            if (timeErr) {
                timeErr.textContent = 'Ang oras ay dapat nasa pagitan ng 8:00 AM at 5:00 PM (operating hours).';
                timeErr.style.display = 'block';
            }
            valid = false;
        } else {
            if (timeErr) timeErr.style.display = 'none';
        }
    }

    return valid;
}
window.validateP2Schedule = validateP2Schedule;

function handleMinutesMedDateChange(inputEl) {
    const errEl = document.getElementById('minutesMedDateError');
    if (!inputEl || !inputEl.value) {
        if (errEl) errEl.style.display = 'none';
        return;
    }
    const val = inputEl.value;
    const today = new Date().toISOString().slice(0, 10);
    const parts = val.split('-');
    const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    const allowedNums = window.AGAP_SETTINGS?.mediation_day_numbers || [2];
    const medLabel = window.AGAP_SETTINGS?.mediation_days_label || 'Mediation Day';

    if (val <= today) {
        if (errEl) {
            errEl.textContent = `Ang petsa ng Mediation ay dapat pumatak sa darating na araw (${medLabel}).`;
            errEl.style.display = 'block';
        }
    } else if (!allowedNums.includes(d.getDay())) {
        if (errEl) {
            errEl.textContent = `Alinsunod sa schedule rules, ang pormal na Mediation ay tuwing ${medLabel} lamang.`;
            errEl.style.display = 'block';
        }
        enforceAllowedMediationDate(inputEl);
    } else {
        if (errEl) errEl.style.display = 'none';
    }
    checkMinutesScheduleConflict();
}
window.handleMinutesMedDateChange = handleMinutesMedDateChange;

function handleMinutesMedTimeChange(inputEl) {
    const errEl = document.getElementById('minutesMedTimeError');
    if (!inputEl || !inputEl.value) {
        if (errEl) errEl.style.display = 'none';
        return;
    }
    const val = inputEl.value;
    const startH = window.AGAP_SETTINGS?.mediation_hours_start || '13:00';
    const endH = window.AGAP_SETTINGS?.mediation_hours_end || '16:30';
    if (val < startH || val > endH) {
        if (errEl) {
            errEl.textContent = `Ang Target Call Time ng Mediation ay dapat nasa pagitan ng ${startH} at ${endH}.`;
            errEl.style.display = 'block';
        }
    } else {
        if (errEl) errEl.style.display = 'none';
    }
    checkMinutesScheduleConflict();
}
window.handleMinutesMedTimeChange = handleMinutesMedTimeChange;

function toggleMinutesOutcomeViews() {
    const form = document.getElementById('recordDialogueMinutesForm');
    if (!form) return;
    const outcome = form.querySelector('input[name="outcome"]:checked')?.value || 'Settled';
    const choiceSection = document.getElementById('minutesComplainantChoiceSection');
    const agreedSection = document.getElementById('minutesAgreedTermsSection');
    const submitBtn = document.getElementById('btnSubmitMinutes');

    const p2Sec = document.getElementById('minutesPaanyaya2ScheduleSection');
    const escSec = document.getElementById('minutesEscalationScheduleSection');
    const p2Date = document.getElementById('minutesP2Date');
    const p2Time = document.getElementById('minutesP2Time');
    const medDate = document.getElementById('minutesMedDate');

    if (outcome === 'Settled') {
        if (agreedSection) agreedSection.style.display = 'block';
        if (choiceSection) choiceSection.style.display = 'none';
        if (p2Sec) p2Sec.style.display = 'none';
        if (escSec) escSec.style.display = 'none';
        if (p2Date) p2Date.required = false;
        if (p2Time) p2Time.required = false;
        if (medDate) medDate.required = false;
        if (submitBtn) {
            submitBtn.innerHTML = '<span>💾</span> Save &amp; Finalize Dialogue Minutes (Settled / Nagkasundo)';
            submitBtn.style.background = '#16a34a';
            submitBtn.style.borderColor = '#16a34a';
            submitBtn.disabled = false;
        }
    } else if (outcome === 'Dismissed') {
        if (agreedSection) agreedSection.style.display = 'none';
        if (choiceSection) choiceSection.style.display = 'none';
        if (p2Sec) p2Sec.style.display = 'none';
        if (escSec) escSec.style.display = 'none';
        if (p2Date) p2Date.required = false;
        if (p2Time) p2Time.required = false;
        if (medDate) medDate.required = false;
        if (submitBtn) {
            submitBtn.innerHTML = '<span>🚫</span> I-save at I-dismiss ang Reklamo (Dismiss Complaint)';
            submitBtn.style.background = '#991b1b';
            submitBtn.style.borderColor = '#7f1d1d';
            submitBtn.disabled = false;
        }
    } else {
        if (agreedSection) agreedSection.style.display = 'none';
        if (choiceSection) choiceSection.style.display = 'block';
        toggleComplainantChoiceViews();
    }
}

function initP2ScheduleDefaults() {
    const p2Date = document.getElementById('minutesP2Date');
    const p2Time = document.getElementById('minutesP2Time');
    if (p2Date) {
        const tom = new Date();
        tom.setDate(tom.getDate() + 1);
        p2Date.min = tom.toISOString().split('T')[0];

        if (!p2Date.value) {
            let d = new Date();
            let added = 0;
            while (added < 3) {
                d.setDate(d.getDate() + 1);
                if (d.getDay() !== 0 && d.getDay() !== 6) {
                    added++;
                }
            }
            p2Date.value = d.toISOString().split('T')[0];
        }
    }
    if (p2Time && !p2Time.value) {
        p2Time.value = '14:00';
    }
}

function toggleComplainantChoiceViews() {
    const form = document.getElementById('recordDialogueMinutesForm');
    if (!form) return;
    const outcome = form.querySelector('input[name="outcome"]:checked')?.value;
    const choice = form.querySelector('input[name="complainant_choice"]:checked')?.value || 'Request_Paanyaya_2';
    const p2Sec = document.getElementById('minutesPaanyaya2ScheduleSection');
    const escSec = document.getElementById('minutesEscalationScheduleSection');
    const submitBtn = document.getElementById('btnSubmitMinutes');
    const p2Date = document.getElementById('minutesP2Date');
    const p2Time = document.getElementById('minutesP2Time');
    const medDate = document.getElementById('minutesMedDate');

    if (outcome === 'No_Agreement' && choice === 'Escalate_Mediation') {
        if (p2Sec) p2Sec.style.display = 'none';
        if (escSec) escSec.style.display = 'block';
        if (p2Date) p2Date.required = false;
        if (p2Time) p2Time.required = false;
        if (medDate) medDate.required = true;
        if (submitBtn) {
            submitBtn.innerHTML = '<span>⚖️</span> Save &amp; Finalize Dialogue Minutes (Escalate to Mediation)';
            submitBtn.style.background = '#dc2626';
            submitBtn.style.borderColor = '#dc2626';
        }
        checkMinutesScheduleConflict();
    } else if (outcome !== 'Settled' && choice === 'Request_Paanyaya_2') {
        if (escSec) escSec.style.display = 'none';
        if (p2Sec) p2Sec.style.display = 'block';
        if (p2Date) p2Date.required = true;
        if (p2Time) p2Time.required = true;
        if (medDate) medDate.required = false;
        initP2ScheduleDefaults();
        if (submitBtn) {
            submitBtn.innerHTML = '<span>📅</span> Save &amp; Finalize Dialogue Minutes (Schedule 2nd Paanyaya)';
            submitBtn.style.background = '#2563eb';
            submitBtn.style.borderColor = '#2563eb';
            submitBtn.disabled = false;
        }
    } else {
        if (p2Sec) p2Sec.style.display = 'none';
        if (escSec) escSec.style.display = 'none';
        if (p2Date) p2Date.required = false;
        if (p2Time) p2Time.required = false;
        if (medDate) medDate.required = false;
    }
}

let conflictCheckDebounceTimer = null;
let currentMinutesWindowMeta = null;

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
}

function enforceAllowedMediationDate(inputEl) {
    if (!inputEl || !inputEl.value) return;
    const parts = inputEl.value.split('-');
    if (parts.length === 3) {
        const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        const allowedNums = window.AGAP_SETTINGS?.mediation_day_numbers || [2];
        if (!allowedNums.includes(d.getDay())) {
            while (!allowedNums.includes(d.getDay())) {
                d.setDate(d.getDate() + 1);
            }
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            inputEl.value = `${y}-${m}-${day}`;
        }
    }
}
window.enforceTuesdayDate = enforceAllowedMediationDate;
window.enforceAllowedMediationDate = enforceAllowedMediationDate;

function renderMinutesVisualDayPicker(windowMeta, selectedDate) {
    const gridEl = document.getElementById('minutesVisualDayGrid');
    const noticeEl = document.getElementById('minutesVisualDateStatusNotice');
    if (!gridEl || !windowMeta || !windowMeta.daily_slots) return;

    const allowedNums = window.AGAP_SETTINGS?.mediation_day_numbers || [2];
    const allowedDays = window.AGAP_SETTINGS?.mediation_days || ['Tue'];
    const medLabel = window.AGAP_SETTINGS?.mediation_days_label || 'Mediation';
    const maxCapacity = window.AGAP_SETTINGS?.daily_capacity || 8;

    const allDays = Object.values(windowMeta.daily_slots);
    const medDays = allDays.filter(d => {
        const parts = d.date.split('-');
        const dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return (allowedNums.includes(dt.getDay()) || allowedDays.includes(d.day_name)) && !d.is_past;
    });

    if (!medDays.length) {
        gridEl.innerHTML = `<div style="color: #64748b; font-size: 0.78rem; grid-column: 1 / -1; text-align: center;">No upcoming ${escapeHtml(medLabel)} dates found in window.</div>`;
        return;
    }

    gridEl.innerHTML = medDays.slice(0, 8).map(d => {
        const isSelected = (d.date === selectedDate);
        let badgeText = `${d.available} open`;
        let badgeBg = '#dcfce7';
        let badgeColor = '#15803d';
        let cardStyle = 'border: 1px solid #cbd5e1; background: #ffffff; color: #1e293b; cursor: pointer;';
        let disabledAttr = '';
        let tooltip = `${d.date}: ${d.available} of ${d.max || maxCapacity} slots available.`;

        if (d.is_fully_booked || (d.max > 0 && d.available <= 0)) {
            badgeText = `${d.max || maxCapacity}/${d.max || maxCapacity} FULL`;
            badgeBg = '#fee2e2';
            badgeColor = '#b91c1c';
            cardStyle = 'border: 1px solid #fecaca; background: #fff1f2; color: #881337; cursor: not-allowed; opacity: 0.65;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Fully booked! Maximum ${d.max || maxCapacity} mediation sessions reached for this date.`;
        } else if (d.is_non_working_day) {
            badgeText = 'Holiday';
            badgeBg = '#f1f5f9';
            badgeColor = '#64748b';
            cardStyle = 'border: 1px dashed #cbd5e1; background: #f8fafc; color: #64748b; cursor: pointer;';
            tooltip = `${d.date}: Official Holiday. Allowed for emergency scheduling, but standard working days preferred.`;
        }

        if (isSelected) {
            cardStyle = 'border: 2px solid #2563eb; background: #eff6ff; color: #1e3a8a; box-shadow: 0 0 0 2px rgba(37,99,235,0.25); font-weight: 700; cursor: pointer;';
            badgeBg = '#2563eb';
            badgeColor = '#ffffff';
        }

        return `
            <div class="minutes-tue-card ${isSelected ? 'active' : ''}"
                 onclick="${disabledAttr ? '' : `selectMinutesVisualDate('${d.date}')`}"
                 title="${escapeHtml(tooltip)}"
                 style="border-radius: 6px; padding: 8px 6px; text-align: center; transition: all 0.15s; user-select: none; ${cardStyle}">
                <div style="font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; color: #475569;">
                    ${escapeHtml(d.day_name || '')}
                </div>
                <div style="font-size: 0.88rem; font-weight: 800; margin: 3px 0;">
                    ${d.day_num} ${escapeHtml(d.month_name || '')}
                </div>
                <div style="font-size: 0.68rem; font-weight: 700; padding: 2px 5px; border-radius: 4px; background: ${badgeBg}; color: ${badgeColor}; display: inline-block;">
                    ${badgeText}
                </div>
            </div>
        `;
    }).join('');

    if (noticeEl) {
        if (selectedDate) {
            const selectedDayData = windowMeta.daily_slots[selectedDate];
            if (selectedDayData) {
                if (selectedDayData.is_fully_booked || (selectedDayData.max > 0 && selectedDayData.available <= 0)) {
                    noticeEl.innerHTML = `<span style="color: #dc2626; font-weight: 600;">Selected date is fully booked (${selectedDayData.max || maxCapacity} of ${selectedDayData.max || maxCapacity} daily mediation sessions).</span>`;
                } else {
                    noticeEl.innerHTML = `<span style="color: #15803d; font-weight: 600;">Date selected (${selectedDate}): ${selectedDayData.available} slots remaining.</span>`;
                }
            }
        } else {
            noticeEl.textContent = `Click any available mediation date above to select.`;
        }
    }
}

function selectMinutesVisualDate(dateStr) {
    const dateInput = document.getElementById('minutesMedDate');
    if (!dateInput) return;
    dateInput.value = dateStr;
    checkMinutesScheduleConflict();
}

async function checkMinutesScheduleConflict() {
    const dateInput = document.getElementById('minutesMedDate');
    const timeInput = document.getElementById('minutesMedTime');
    const conflictAlert = document.getElementById('minutesScheduleConflictAlert');
    const submitBtn = document.getElementById('btnSubmitMinutes');
    const schedulesWrap = document.getElementById('minutesDaySchedulesWrap');
    const slotsCount = document.getElementById('minutesDaySlotsCount');
    const slotsList = document.getElementById('minutesDaySlotsList');

    if (!dateInput) return;
    enforceAllowedMediationDate(dateInput);

    const dateVal = dateInput.value;
    const timeVal = timeInput ? timeInput.value : (window.AGAP_SETTINGS?.mediation_hours_start || '13:00');
    const venueVal = window.AGAP_SETTINGS?.mediation_venue || 'Lupon Court Room';

    if (!dateVal) return;

    try {
        const params = new URLSearchParams({
            date: dateVal,
            time: timeVal,
            venue: venueVal,
            complaint_id: String(complaintId),
            get_window: '1'
        });

        const res = await fetch(`../../../backend/api/summons/check-schedule.php?${params.toString()}`);
        const result = await res.json();

        if (result.window) {
            currentMinutesWindowMeta = result.window;
            renderMinutesVisualDayPicker(result.window, dateVal);
        }

        const bookedCount = result.total_booked ?? (result.scheduled_mediations ? result.scheduled_mediations.length : 0);
        const maxSlots = result.max_slots || 8;
        const availSlots = result.available_slots ?? Math.max(0, maxSlots - bookedCount);

        const capBadge = document.getElementById('minutesCapacityBadge');
        if (capBadge) {
            capBadge.textContent = result.capacity_badge_text || `${bookedCount} of 8 daily mediation slots booked`;
            if (result.is_fully_booked || bookedCount >= 8) {
                capBadge.style.background = '#fee2e2';
                capBadge.style.color = '#991b1b';
                capBadge.style.border = '1px solid #fca5a5';
            } else if (bookedCount >= 5) {
                capBadge.style.background = '#fef3c7';
                capBadge.style.color = '#92400e';
                capBadge.style.border = '1px solid #fcd34d';
            } else {
                capBadge.style.background = '#dcfce7';
                capBadge.style.color = '#166534';
                capBadge.style.border = '1px solid #86efac';
            }
        }

        if (schedulesWrap && slotsList && slotsCount) {
            schedulesWrap.style.display = 'block';
            slotsCount.textContent = `${availSlots} of ${maxSlots} slots available`;
            slotsCount.style.background = availSlots > 2 ? '#dcfce7' : (availSlots > 0 ? '#fef3c7' : '#fee2e2');
            slotsCount.style.color = availSlots > 2 ? '#15803d' : (availSlots > 0 ? '#92400e' : '#b91c1c');

            const scheduled = result.scheduled_mediations || [];
            if (scheduled.length === 0) {
                slotsList.innerHTML = `<span style="color: #166534; font-weight: 500;">✓ No other sessions scheduled on ${escapeHtml(dateVal)}. All 8 slots available.</span>`;
            } else {
                slotsList.innerHTML = `
                    <div style="margin-bottom: 6px; font-weight: 600; color: #475569;">Mediation Docket Call (${bookedCount} of ${maxSlots} booked):</div>
                ` + scheduled.map(s => {
                    const qStatus = s.queue_status || s.status || 'Waiting';
                    let pillBg = '#eff6ff', pillColor = '#1e40af', pillBorder = '#bfdbfe';
                    if (qStatus === 'In_Session') {
                        pillBg = '#ecfdf5'; pillColor = '#047857'; pillBorder = '#6ee7b7';
                    } else if (qStatus === 'Concluded' || qStatus === 'Completed') {
                        pillBg = '#f1f5f9'; pillColor = '#475569'; pillBorder = '#cbd5e1';
                    }
                    return `
                    <div style="margin-top: 4px; padding: 5px 8px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <div>
                            <strong style="color: #1e293b;">${escapeHtml(s.start_time || '')}</strong>
                            <span style="color: #64748b; font-size: 0.8rem; margin-left: 6px;">Case ${escapeHtml(s.case_number || 'N/A')}</span>
                        </div>
                        <span style="font-size: 0.72rem; color: ${pillColor}; background: ${pillBg}; border: 1px solid ${pillBorder}; padding: 1px 7px; border-radius: 9999px; font-weight: 700;">
                            ${escapeHtml(qStatus)}
                        </span>
                    </div>
                `;
                }).join('');
            }
        }

        if (result.is_fully_booked || bookedCount >= maxSlots) {
            if (conflictAlert) {
                conflictAlert.textContent = `Date Fully Booked: All ${maxSlots} daily mediation slots on this date are occupied. Please select another date.`;
                conflictAlert.style.display = 'block';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.title = 'Cannot submit: Maximum daily capacity of 8 mediation sessions reached for this date.';
            }
            return;
        }

        if (result.has_conflict && result.conflict) {
            const conflictMsg = result.conflict.message || 'The selected time overlaps with an existing scheduled mediation.';
            if (conflictAlert) {
                conflictAlert.textContent = conflictMsg.startsWith('⚠️') ? conflictMsg : `⚠️ Schedule Conflict: ${conflictMsg}`;
                conflictAlert.style.display = 'block';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.title = 'Cannot submit: Schedule conflict with an existing mediation on this day.';
            }
        } else {
            if (conflictAlert) {
                conflictAlert.textContent = '';
                conflictAlert.style.display = 'none';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.title = '';
            }
        }
    } catch (err) {
        console.warn('Failed to check schedule conflict:', err);
    }
}

async function handleDialogueMinutesSubmit(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitMinutes');
    const errBox = document.getElementById('minutesErrorMessage');

    // 1. Attendance & Outcome Consistency Evaluation
    const compAttended = document.getElementById('overallComplainantAttended')?.value || 'Present';
    const respAttended = document.getElementById('overallRespondentAttended')?.value || 'Present';
    const outcome = form.querySelector('input[name="outcome"]:checked')?.value || 'Settled';
    const choice = form.querySelector('input[name="complainant_choice"]:checked')?.value || 'Request_Paanyaya_2';
    const eitherPartyAbsent = (compAttended === 'Absent' || respAttended === 'Absent');

    // 2. Validate at least one Presiding Officer is selected ONLY IF both parties attended
    const presidersInput = document.getElementById('minutesPresidingOfficerIds');
    let presiderIds = [];
    try {
        presiderIds = JSON.parse(presidersInput?.value || '[]');
    } catch (e) {
        presiderIds = [];
    }
    if (!eitherPartyAbsent && (!Array.isArray(presiderIds) || presiderIds.length === 0)) {
        if (errBox) {
            errBox.textContent = 'Paki-pili ang kahit isang Presiding Officer o Lupon Member na namuno sa pag-uusap.';
            errBox.style.display = 'block';
        }
        document.getElementById('minutesPresidingSelect')?.focus();
        return;
    }

    if (compAttended === 'Absent' && outcome !== 'Dismissed') {
        if (errBox) {
            errBox.textContent = 'Dahil hindi humarap ang nagrereklamo (o ang magkabilang panig), ang reklamo ay dapat i-dismiss alinsunod sa KP Rules (Sec. 410b). Piliin ang "I-dismiss ang Reklamo".';
            errBox.style.display = 'block';
        }
        return;
    }

    if (respAttended === 'Absent' && outcome === 'Settled') {
        if (errBox) {
            errBox.textContent = 'Hindi maaaring magkasundo kung Absent ang inanyayahan (Respondent). Piliin ang "Walang Kasunduan" upang maitakda ang susunod na hakbang.';
            errBox.style.display = 'block';
        }
        return;
    }

    // 3. Discussion Summary Validation (Required only if both parties attended)
    const summaryInput = form.querySelector('textarea[name="discussion_summary"]');
    let discussionSummary = summaryInput?.value?.trim() || '';

    if (compAttended === 'Present' && respAttended === 'Present') {
        if (!discussionSummary) {
            if (errBox) {
                errBox.textContent = 'Paki-lagay ang Salaysay at Buod ng Napag-usapan (Summary of Statements & Discussion).';
                errBox.style.display = 'block';
            }
            document.getElementById('minutesDiscussionSummary')?.focus();
            return;
        }
    } else {
        if (!discussionSummary) {
            const absentPartyLabel = (compAttended === 'Absent' && respAttended === 'Absent')
                ? 'magkabilang panig.'
                : (compAttended === 'Absent' ? 'nagrereklamo (Complainant).' : 'inanyayahan (Respondent).');
            discussionSummary = 'Walang naganap na pag-uusap dahil hindi sumipot ang ' + absentPartyLabel;
            if (summaryInput) {
                summaryInput.value = discussionSummary;
            }
        }
    }

    // 4. Settled Outcome: Agreed Terms Validation
    if (outcome === 'Settled') {
        const termsInput = document.getElementById('minutesAgreedTerms');
        const termsVal = termsInput?.value?.trim() || '';
        if (!termsVal) {
            if (errBox) {
                errBox.textContent = 'Paki-lagay ang mga napagkasunduang kondisyon (Proposals / Agreed Terms) bago i-save ang kasunduan.';
                errBox.style.display = 'block';
            }
            termsInput?.focus();
            return;
        }
    } else {
        const termsInput = document.getElementById('minutesAgreedTerms');
        if (termsInput) termsInput.value = '';
    }

    // 5. Booking Schedule Validation (Seksyon 4)
    const todayStr = new Date().toISOString().slice(0, 10);
    const conflictAlert = document.getElementById('minutesScheduleConflictAlert');

    if (outcome === 'No_Agreement' && choice === 'Request_Paanyaya_2') {
        const p2Date = form.querySelector('input[name="p2_scheduled_date"]')?.value?.trim();
        const p2Time = form.querySelector('input[name="p2_scheduled_time"]')?.value?.trim();
        const p2Officer = form.querySelector('select[name="p2_assigned_officer_id"]')?.value?.trim();

        if (!p2Date || !p2Time) {
            if (errBox) {
                errBox.textContent = 'Paki-lagay ang petsa at oras para sa Ikalawang Paanyaya.';
                errBox.style.display = 'block';
            }
            return;
        }
        if (p2Date <= todayStr) {
            if (errBox) {
                errBox.textContent = 'Ang petsa ng Ikalawang Paanyaya ay hindi maaaring lumipas na o ngayong araw. Pumili ng darating na araw.';
                errBox.style.display = 'block';
            }
            return;
        }
        const p2Dt = new Date(p2Date + 'T00:00:00');
        if (p2Dt.getDay() === 0 || p2Dt.getDay() === 6) {
            if (errBox) {
                errBox.textContent = 'Ang iskedyul ng Ikalawang Paanyaya ay dapat pumatak sa regular na araw ng trabaho (Lunes hanggang Biyernes).';
                errBox.style.display = 'block';
            }
            return;
        }
        if (p2Time < '08:00' || p2Time > '17:00') {
            if (errBox) {
                errBox.textContent = 'Ang oras ng Ikalawang Paanyaya ay dapat nasa pagitan ng 8:00 AM at 5:00 PM (operating hours).';
                errBox.style.display = 'block';
            }
            return;
        }
        if (!p2Officer) {
            if (errBox) {
                errBox.textContent = 'Paki-pili ang The Serving Officer para sa Ikalawang Paanyaya.';
                errBox.style.display = 'block';
            }
            return;
        }
    }

    if (outcome === 'No_Agreement' && choice === 'Escalate_Mediation') {
        const medDate = form.querySelector('input[name="mediation_date"]')?.value?.trim();
        const medTime = form.querySelector('input[name="mediation_time"]')?.value?.trim();

        if (!medDate || !medTime) {
            if (errBox) {
                errBox.textContent = 'Paki-lagay ang petsa at Target Call Time para sa pormal na Mediation.';
                errBox.style.display = 'block';
            }
            return;
        }
        const allowedNums = window.AGAP_SETTINGS?.mediation_day_numbers || [2];
        const medLabel = window.AGAP_SETTINGS?.mediation_days_label || 'Mediation Day';
        const startH = window.AGAP_SETTINGS?.mediation_hours_start || '13:00';
        const endH = window.AGAP_SETTINGS?.mediation_hours_end || '16:30';

        if (medDate <= todayStr) {
            if (errBox) {
                errBox.textContent = `Ang petsa ng Mediation ay dapat pumatak sa darating na araw (${medLabel}).`;
                errBox.style.display = 'block';
            }
            return;
        }
        const medDt = new Date(medDate + 'T00:00:00');
        if (!allowedNums.includes(medDt.getDay())) {
            if (errBox) {
                errBox.textContent = `Alinsunod sa schedule rules, ang pormal na Mediation ay tuwing ${medLabel} lamang maaaring itakda.`;
                errBox.style.display = 'block';
            }
            return;
        }
        if (medTime < startH || medTime > endH) {
            if (errBox) {
                errBox.textContent = `Ang Target Call Time ng Mediation ay dapat nasa pagitan ng ${startH} at ${endH}.`;
                errBox.style.display = 'block';
            }
            return;
        }
        if (conflictAlert && conflictAlert.style.display !== 'none' && conflictAlert.textContent.trim() !== '') {
            if (errBox) {
                errBox.textContent = 'May schedule conflict o puno na ang mga slots sa napiling Mediation schedule. Paki-pili ang ibang petsa o oras bago magpatuloy.';
                errBox.style.display = 'block';
            }
            return;
        }
    }

    const formData = new FormData(form);

    try {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span>⏳</span> Saving dialogue minutes...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await fetch('../../../backend/api/paanyaya/record-minutes.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            if (window.AgapDraftManager) {
                window.AgapDraftManager.markSubmitted(`agap_draft_paanyaya_${currentStage}_comp_${complaintId}`);
                window.AgapDraftManager.setIndicator('paanyayaDraftIndicator', 'synced', '✓ All changes submitted');
            }
            window.minutesDraftManager?.markSubmitted();

            if (data.action === 'escalate_mediation') {
                window.pendingEscalationData = data;
                if (typeof openSummonsPreviewModal === 'function' && data.case_id) {
                    openSummonsPreviewModal(data.case_id, data.document_id || data.kp9_document_id, data.hearing_id, true);
                } else {
                    openPaanyayaPreviewModal(data.complaint_id || complaintId, data.stage_type || currentStage);
                }
            } else {
                let targetUrl = `complaint-details.php?id=${encodeURIComponent(complaintId)}&tab=paanyaya`;
                window.location.href = targetUrl;
            }
        } else {
            throw new Error(data.message || 'Failed to record dialogue minutes.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error recording dialogue minutes.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error recording dialogue minutes.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            initP2ScheduleDefaults();
            toggleMinutesOutcomeViews();
        }
    }
}

function openPaanyayaPreviewModal(compId, stType) {
    const modal = document.getElementById('paanyayaPreviewModal');
    const iframe = document.getElementById('paanyayaPreviewIframe');
    const dlBtn = document.getElementById('btnDownloadPaanyayaPdf');
    if (!modal) return;

    if (iframe) {
        iframe.src = `../../../backend/api/paanyaya/preview.php?id=${encodeURIComponent(compId)}&stage_type=${encodeURIComponent(stType)}&type=minutes&format=html&_t=${Date.now()}`;
    }
    if (dlBtn) {
        dlBtn.href = `../../../backend/api/paanyaya/preview.php?id=${encodeURIComponent(compId)}&stage_type=${encodeURIComponent(stType)}&type=minutes&download=1`;
    }

    modal.style.display = 'flex';
}

function closePaanyayaPreviewModal() {
    const modal = document.getElementById('paanyayaPreviewModal');
    if (modal) modal.style.display = 'none';
    window.location.href = `complaint-details.php?id=${encodeURIComponent(complaintId)}&tab=mediation`;
}

function proceedToSummonsDualPreview() {
    const modal = document.getElementById('paanyayaPreviewModal');
    if (modal) modal.style.display = 'none';
    window.summonsModalOpened = true;

    if (window.pendingEscalationData) {
        const d = window.pendingEscalationData;
        if (typeof openSummonsPreviewModal === 'function') {
            openSummonsPreviewModal(d.case_id, d.document_id, d.hearing_id, true);
        } else {
            window.location.href = `complaint-details.php?id=${encodeURIComponent(d.complaint_id)}&tab=mediation`;
        }
    }
}

function printPaanyayaIframe() {
    const iframe = document.getElementById('paanyayaPreviewIframe');
    if (iframe && iframe.contentWindow) {
        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch (e) {
            console.warn('Print iframe failed:', e);
        }
    }
}

window.onSummonsPreviewClosed = function() {
    if (window.pendingEscalationData?.complaint_id) {
        window.location.href = `complaint-details.php?id=${encodeURIComponent(window.pendingEscalationData.complaint_id)}&tab=mediation`;
    }
};
</script>

<!-- Step 1: Paanyaya Dialogue Minutes Preview Modal -->
<div id="paanyayaPreviewModal" class="modal" style="display:none; padding: 12px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9998;" role="dialog" aria-modal="true" aria-labelledby="paanyayaPreviewModalTitle">
    <div class="modal-content" style="max-width: 960px; width: 95%; height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); background: #fff;">
        <div class="modal-header" style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #ffffff; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.3rem;">📄</span>
                <div>
                    <h2 id="paanyayaPreviewModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Official Paanyaya Dialogue Minutes Preview</h2>
                    <p id="paanyayaPreviewModalSubtitle" style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b;">
                        Review recorded dialogue minutes before proceeding to KP Form 8 &amp; KP Form 9 summons.
                    </p>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" class="btn-secondary" onclick="printPaanyayaIframe()" style="padding: 6px 12px; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;" title="Print Dialogue Minutes">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Print Minutes
                </button>
                <a href="#" id="btnDownloadPaanyayaPdf" class="btn-secondary" style="padding: 6px 12px; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;" title="Download PDF">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download PDF
                </a>
                <button type="button" class="close-btn" onclick="closePaanyayaPreviewModal()">&times;</button>
            </div>
        </div>
        <div style="flex: 1 1 auto; background: #475569; position: relative; min-height: 0;">
            <iframe id="paanyayaPreviewIframe" src="about:blank" style="width: 100%; height: 100%; border: none; background: #ffffff;"></iframe>
        </div>
        <div class="modal-actions" style="padding: 12px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
            <button type="button" class="btn-secondary" onclick="closePaanyayaPreviewModal()" style="font-size: 0.84rem; padding: 7px 14px;">
                Close
            </button>
            <button type="button" class="btn-create" id="btnProceedToSummonsPreview" onclick="proceedToSummonsDualPreview()" style="background: #2563eb; border-color: #2563eb; color: #fff; padding: 8px 20px; font-weight: 700; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(37,99,235,0.25); cursor: pointer;">
                Proceed to KP Form 8 &amp; 9 (Summons) &rarr;
            </button>
        </div>
    </div>
</div>

<!-- Step 2: Dual KP Form 8 & 9 Preview Modal -->
<?php include '../../layouts/summons-preview-modal.php'; ?>
<script src="../../assets/js/summons-preview.js"></script>

<?php include '../../layouts/footer.php'; ?>
