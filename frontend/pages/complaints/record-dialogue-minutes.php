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
                        <h1 style="font-size: 1.6rem; font-weight: 800; color: #0f172a; margin: 0 0 4px 0; letter-spacing: -0.02em;">
                            Record Dialogue Minutes &amp; Result (<?= htmlspecialchars($stageType) ?>)
                        </h1>
                        <p style="margin: 0; color: #64748b; font-size: 0.92rem;">
                            Salaysay at Buod ng Usapan sa Harap ng Barangay &mdash; <strong>Case #<?= htmlspecialchars($caseNum) ?></strong>
                        </p>
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

                        <!-- 1. Dialogue Date & Time and Actual Start Time -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="minutesDialogueDate" style="font-weight: 600; font-size: 0.86rem; display: block; margin-bottom: 5px; color: #0f172a;">
                                    Petsa at Takdang Oras (Scheduled Date &amp; Time) <span class="required-mark" style="color: #dc2626;">*</span>
                                </label>
                                <input type="datetime-local" id="minutesDialogueDate" name="dialogue_date" required style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                    <label for="minutesActualStartTime" style="font-weight: 600; font-size: 0.86rem; margin: 0; color: #0f172a;">
                                        Aktwal na Oras ng Simula (Actual Start Time)
                                    </label>
                                    <button type="button" onclick="setMinutesActualStartNow()" style="background: none; border: none; color: #2563eb; font-size: 0.78rem; font-weight: 700; cursor: pointer; padding: 0;">
                                        Oras Ngayon (Now)
                                    </button>
                                </div>
                                <input type="time" id="minutesActualStartTime" name="actual_start_time" style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                            </div>
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
                                    Mga Namuno / Tagapamagitan sa Pag-uusap (Presiding Officer[s] / Lupon / LISO) <span class="required-mark" style="color: #dc2626;">*</span>
                                </label>
                                <span style="font-size: 0.74rem; color: #64748b;">Maaaring pumili ng higit sa isa (Co-presiders)</span>
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
                            <input type="hidden" id="minutesPresidingOfficerIds" name="presiding_officer_ids" value="[]" required>
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
                    </div>

                    <!-- SECTION 3: Salaysay at Buod ng Usapan -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 22px;">
                        <div class="rm-section-title">
                            <span style="font-size: 1.1rem;">📝</span>
                            <span>Seksyon 3: Salaysay at Buod ng Usapan (Discussion Summary &amp; Attachments)</span>
                            <span class="required-mark" style="color: #dc2626;">*</span>
                        </div>

                        <!-- Summary Textarea -->
                        <div class="form-group" style="margin-bottom: 18px;">
                            <label for="minutesDiscussionSummary" style="font-weight: 600; font-size: 0.86rem; color: #0f172a; display: block; margin-bottom: 5px;">
                                Salaysay at Buod ng Napag-usapan (Summary of Statements &amp; Discussion) <span class="required-mark" style="color: #dc2626;">*</span>
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
                                    <strong style="color: #991b1b; font-size: 0.94rem;">🚫 I-dismiss ang Reklamo / Dismiss Complaint (Complainant Absent)</strong>
                                    <p style="margin: 3px 0 0; font-size: 0.82rem; color: #b91c1c;">Hindi humarap o dumalo ang nagreklamo sa takdang oras ng pag-uusap. Isasara at ididismis ang reklamo alinsunod sa KP rules.</p>
                                </div>
                            </label>
                        </div>

                        <!-- Settled: Proposals / Agreed Terms Section -->
                        <div id="minutesAgreedTermsSection" style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px;">
                            <label for="minutesAgreedTerms" style="font-weight: 700; font-size: 0.88rem; color: #166534; display: block; margin-bottom: 6px;">
                                🤝 Mga Mungkahi o Napagkasunduang Kondisyon (Proposals / Agreed Terms)
                            </label>
                            <p style="margin: 0 0 8px 0; font-size: 0.78rem; color: #15803d;">
                                Itala rito ang naging pinal na kasunduan, kondisyon sa bayaran, takdang araw ng pagtupad, o anumang pangako ng magkabilang panig upang pormal na maipasara ang reklamo.
                            </p>
                            <textarea id="minutesAgreedTerms" name="agreed_terms" rows="3" placeholder="Kondisyon ng bayaran, pagsasaayos, o pangako ng magkabilang panig (kung mayroon)..." style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #86efac; border-radius: 7px; width: 100%; box-sizing: border-box; background: #ffffff;"></textarea>
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
                                                <input type="date" id="minutesP2Date" name="p2_scheduled_date" style="padding: 8px 10px; font-size: 0.86rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; box-sizing: border-box;">
                                            </div>
                                            <div class="form-group" style="margin-bottom: 0;">
                                                <label for="minutesP2Time" style="font-weight: 700; font-size: 0.82rem; display: block; margin-bottom: 4px; color: #1e293b;">
                                                    2nd Dialogue Time (08:00 - 17:00) <span class="required-mark" style="color: #dc2626;">*</span>
                                                </label>
                                                <input type="time" id="minutesP2Time" name="p2_scheduled_time" value="14:00" min="08:00" max="17:00" style="padding: 8px 10px; font-size: 0.86rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; box-sizing: border-box;">
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
                                    <span>⚖️</span> 1st Formal Mediation Hearing Schedule (Tumana Rules)
                                </h4>
                                <span style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 3px 10px; border-radius: 9999px; font-weight: 700;">
                                    Tuesdays ONLY · Lupon Court Room (Max 8/Day)
                                </span>
                            </div>

                            <!-- Date input and Visual Tuesday Picker -->
                            <div class="form-group" style="margin-bottom: 14px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <label for="minutesMedDate" style="margin-bottom: 0; font-weight: 700; font-size: 0.85rem; color: #1e293b;">
                                        Mediation Date (Tuesdays ONLY) <span class="required-mark" style="color: #dc2626;">*</span>
                                    </label>
                                    <span style="font-size: 0.74rem; color: #64748b;">Punong Barangay Mediation Day</span>
                                </div>
                                <input type="date" id="minutesMedDate" name="mediation_date" onchange="checkMinutesScheduleConflict()" required style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box; margin-bottom: 8px;">

                                <!-- Visual Tuesday Picker Container -->
                                <div id="minutesVisualDatePickerContainer" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.78rem; flex-wrap: wrap; gap: 6px;">
                                        <span style="font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em; font-size: 0.74rem;">
                                            Available Tuesday Slots (Max 8 Hearings/Day):
                                        </span>
                                        <div style="display: flex; gap: 10px; font-size: 0.72rem; flex-wrap: wrap;">
                                            <span style="display: inline-flex; align-items: center; gap: 4px; color: #166534;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e;"></span> Available</span>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; color: #991b1b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444;"></span> 8/8 Full</span>
                                        </div>
                                    </div>
                                    <div id="minutesVisualDayGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(88px, 1fr)); gap: 6px; padding: 4px 2px;">
                                        <div style="text-align: center; color: #64748b; font-size: 0.78rem; padding: 8px; grid-column: 1 / -1;">
                                            Loading Tuesday availability...
                                        </div>
                                    </div>
                                    <div id="minutesVisualDateStatusNotice" style="margin-top: 6px; font-size: 0.75rem; color: #64748b; line-height: 1.4;"></div>
                                </div>
                            </div>

                            <!-- Existing Scheduled Mediations on selected date -->
                            <div id="minutesDaySchedulesWrap" style="display: none; margin-bottom: 14px; padding: 10px 12px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.84rem;">
                                <div style="font-weight: 600; color: #334155; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                                    <span>Scheduled Mediations on this Date:</span>
                                    <span id="minutesDaySlotsCount" style="font-size: 0.72rem; background: #e2e8f0; color: #475569; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">0</span>
                                </div>
                                <div id="minutesDaySlotsList" style="color: #64748b; font-size: 0.82rem; line-height: 1.5;">
                                    No other mediations scheduled on this day.
                                </div>
                            </div>

                            <!-- Slot Selector & Venue Grid -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="minutesMedTime" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 5px; color: #1e293b;">
                                        Mediation Afternoon Slot (1:00 PM - 5:00 PM) <span class="required-mark" style="color: #dc2626;">*</span>
                                    </label>
                                    <select id="minutesMedTime" name="mediation_time" onchange="checkMinutesScheduleConflict()" style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                                        <option value="13:00">1:00 PM - 2:00 PM</option>
                                        <option value="14:00">2:00 PM - 3:00 PM</option>
                                        <option value="15:00">3:00 PM - 4:00 PM</option>
                                        <option value="16:00">4:00 PM - 5:00 PM</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="minutesMedVenue" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 5px; color: #1e293b;">Hearing Venue</label>
                                    <input type="text" id="minutesMedVenue" name="venue" value="Lupon Court Room" readonly style="padding: 9px 12px; font-size: 0.88rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box; cursor: not-allowed;">
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
// Multi-Select Presiders Management
let selectedPresiders = [];

const currentUserId = <?= (int)($_SESSION['user_id'] ?? 0) ?>;
const eligiblePresidersList = <?= json_encode(array_map(fn($p) => [
    'id' => (int)$p['user_id'],
    'name' => $p['full_name'],
    'role' => $p['role_name']
], $eligiblePresiders ?? [])) ?>;

// Pre-select current user if they are an eligible presider
const matchedCurrentUser = eligiblePresidersList.find(p => p.id === currentUserId);
if (matchedCurrentUser) {
    selectedPresiders.push(matchedCurrentUser);
}

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

    // Configure next Tuesday date for mediation
    const medDateInput = document.getElementById('minutesMedDate');
    if (medDateInput) {
        const d = new Date();
        d.setDate(d.getDate() + 3);
        while (d.getDay() !== 2) {
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
                handleMinutesAttendanceChange();
                initP2ScheduleDefaults();
                toggleMinutesOutcomeViews();
            },
            onClear: () => {
                initP2ScheduleDefaults();
                toggleMinutesOutcomeViews();
            }
        });
    }
});

function setMinutesActualStartNow() {
    const actualStart = document.getElementById('minutesActualStartTime');
    if (actualStart) {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        actualStart.value = `${hh}:${mm}`;
    }
}

function applyDelayRemark(text) {
    const ta = document.getElementById('minutesSessionRemarks');
    if (ta) {
        ta.value = text;
        ta.focus();
    }
}

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
    const compAttended = document.getElementById('overallComplainantAttended')?.value ||
        form.querySelector('input[name="complainant_attended"]:checked')?.value || 'Present';
    const respAttended = document.getElementById('overallRespondentAttended')?.value ||
        form.querySelector('input[name="respondent_attended"]:checked')?.value || 'Present';
    const optSettledWrap = document.getElementById('optMinutesSettledWrap');
    const optDismissedWrap = document.getElementById('optMinutesDismissedWrap');
    const dismissRadio = form.querySelector('input[name="outcome"][value="Dismissed"]');
    const settledRadio = form.querySelector('input[name="outcome"][value="Settled"]');
    const noAgreeRadio = form.querySelector('input[name="outcome"][value="No_Agreement"]');

    if (compAttended === 'Absent') {
        if (optDismissedWrap) {
            optDismissedWrap.style.display = 'flex';
        }
        if (dismissRadio) {
            dismissRadio.checked = true;
        }
        if (optSettledWrap) {
            optSettledWrap.style.opacity = '0.5';
            optSettledWrap.style.pointerEvents = 'none';
        }
    } else {
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
        if (respAttended === 'Absent') {
            if (noAgreeRadio && (!dismissRadio || !dismissRadio.checked)) {
                noAgreeRadio.checked = true;
            }
            if (optSettledWrap) {
                optSettledWrap.style.opacity = '0.5';
                optSettledWrap.style.pointerEvents = 'none';
            }
        } else {
            if (optSettledWrap) {
                optSettledWrap.style.opacity = '1';
                optSettledWrap.style.pointerEvents = '';
            }
        }
    }
    initP2ScheduleDefaults();
    toggleMinutesOutcomeViews();
}

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

function enforceTuesdayDate(inputEl) {
    if (!inputEl || !inputEl.value) return;
    const parts = inputEl.value.split('-');
    if (parts.length === 3) {
        const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        if (d.getDay() !== 2) {
            while (d.getDay() !== 2) {
                d.setDate(d.getDate() + 1);
            }
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            inputEl.value = `${y}-${m}-${day}`;
        }
    }
}

function renderMinutesVisualDayPicker(windowMeta, selectedDate) {
    const gridEl = document.getElementById('minutesVisualDayGrid');
    const noticeEl = document.getElementById('minutesVisualDateStatusNotice');
    if (!gridEl || !windowMeta || !windowMeta.daily_slots) return;

    const allDays = Object.values(windowMeta.daily_slots);
    const tuesdayDays = allDays.filter(d => {
        const parts = d.date.split('-');
        const dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return dt.getDay() === 2 && !d.is_past;
    });

    if (!tuesdayDays.length) {
        gridEl.innerHTML = '<div style="color: #64748b; font-size: 0.78rem; grid-column: 1 / -1; text-align: center;">No upcoming Tuesday dates found in window.</div>';
        return;
    }

    gridEl.innerHTML = tuesdayDays.slice(0, 6).map(d => {
        const isSelected = (d.date === selectedDate);
        let badgeText = `${d.available} open`;
        let badgeBg = '#dcfce7';
        let badgeColor = '#15803d';
        let cardStyle = 'border: 1px solid #cbd5e1; background: #ffffff; color: #1e293b; cursor: pointer;';
        let disabledAttr = '';
        let tooltip = `${d.date}: ${d.available} of ${d.max} slots available.`;

        if (d.is_fully_booked) {
            badgeText = '8/8 FULL';
            badgeBg = '#fee2e2';
            badgeColor = '#b91c1c';
            cardStyle = 'border: 1px solid #fecaca; background: #fff1f2; color: #881337; cursor: not-allowed; opacity: 0.65;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Fully booked! Maximum 8 mediation sessions reached for this date.`;
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
                    ${escapeHtml(d.day_name || 'Tue')}
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
                if (selectedDayData.is_fully_booked) {
                    noticeEl.innerHTML = `<span style="color: #dc2626; font-weight: 600;">Selected Tuesday is fully booked (8 of 8 daily mediation sessions).</span>`;
                } else {
                    noticeEl.innerHTML = `<span style="color: #15803d; font-weight: 600;">Tuesday selected (${selectedDate}): ${selectedDayData.available} slots remaining.</span>`;
                }
            }
        } else {
            noticeEl.textContent = `Click any available Tuesday above to select.`;
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
    enforceTuesdayDate(dateInput);

    const dateVal = dateInput.value;
    const timeVal = timeInput ? timeInput.value : '13:00';
    const venueVal = 'Lupon Court Room';

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

        if (schedulesWrap && slotsList && slotsCount) {
            schedulesWrap.style.display = 'block';
            slotsCount.textContent = `${availSlots} of ${maxSlots} slots available`;
            slotsCount.style.background = availSlots > 2 ? '#dcfce7' : (availSlots > 0 ? '#fef3c7' : '#fee2e2');
            slotsCount.style.color = availSlots > 2 ? '#15803d' : (availSlots > 0 ? '#92400e' : '#b91c1c');

            const scheduled = result.scheduled_mediations || [];
            if (scheduled.length === 0) {
                slotsList.innerHTML = `<span style="color: #166534; font-weight: 500;">All 8 session slots available on this date (${dateVal}).</span>`;
            } else {
                slotsList.innerHTML = `
                    <div style="margin-bottom: 6px; font-weight: 600; color: #475569;">${availSlots} of ${maxSlots} slots available for this date:</div>
                ` + scheduled.map(s => `
                    <div style="margin-top: 4px; padding: 4px 8px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <div>
                            <strong style="color: #1e293b;">${escapeHtml(s.start_time || '')} – ${escapeHtml(s.end_time || '')}</strong>
                            <span style="color: #64748b; font-size: 0.8rem; margin-left: 6px;">Case ${escapeHtml(s.case_number || 'N/A')}</span>
                        </div>
                        <span style="font-size: 0.72rem; color: #475569; background: #f1f5f9; padding: 1px 6px; border-radius: 4px;">${escapeHtml(s.venue || 'Lupon Court Room')}</span>
                    </div>
                `).join('');
            }
        }

        if (result.is_fully_booked || bookedCount >= maxSlots) {
            if (conflictAlert) {
                conflictAlert.textContent = `Date Fully Booked: All ${maxSlots} daily mediation slots on this date are occupied. Please select another Tuesday.`;
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
                conflictAlert.textContent = `Schedule Conflict: ${conflictMsg}`;
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

    // Validate at least one Presiding Officer is selected
    const presidersInput = document.getElementById('minutesPresidingOfficerIds');
    let presiderIds = [];
    try {
        presiderIds = JSON.parse(presidersInput?.value || '[]');
    } catch (e) {
        presiderIds = [];
    }
    if (!Array.isArray(presiderIds) || presiderIds.length === 0) {
        if (errBox) {
            errBox.textContent = 'Paki-pili ang kahit isang Presiding Officer o Lupon Member na namuno sa pag-uusap.';
            errBox.style.display = 'block';
        }
        document.getElementById('minutesPresidingSelect')?.focus();
        return;
    }

    const conflictAlert = document.getElementById('minutesScheduleConflictAlert');
    const outcome = form.querySelector('input[name="outcome"]:checked')?.value;
    const choice = form.querySelector('input[name="complainant_choice"]:checked')?.value;

    if (outcome !== 'Settled') {
        const termsInput = document.getElementById('minutesAgreedTerms');
        if (termsInput) termsInput.value = '';
    }

    if (outcome === 'No_Agreement' && choice === 'Request_Paanyaya_2') {
        const p2Date = form.querySelector('input[name="p2_scheduled_date"]')?.value?.trim();
        const p2Time = form.querySelector('input[name="p2_scheduled_time"]')?.value?.trim();
        if (!p2Date || !p2Time) {
            if (errBox) {
                errBox.textContent = 'Please specify the scheduled date and time for the 2nd Paanyaya dialogue.';
                errBox.style.display = 'block';
            }
            return;
        }
    }

    if (outcome === 'No_Agreement' && choice === 'Escalate_Mediation') {
        if (conflictAlert && conflictAlert.style.display !== 'none' && conflictAlert.textContent.trim() !== '') {
            if (errBox) {
                errBox.textContent = 'Please resolve the mediation schedule conflict before proceeding.';
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
                openPaanyayaPreviewModal(data.complaint_id || complaintId, data.stage_type || currentStage);
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
