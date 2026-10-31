<?php

session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3, 4], true)) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';
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

$complaintId = filter_var($_GET['id'] ?? ($_GET['complaint_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    header('Location: complaint-list.php');
    exit;
}

$paanyayaModel = new Paanyaya();
$wsData = $paanyayaModel->getWorkspaceData($complaintId);

if (!$wsData) {
    header('Location: complaint-list.php');
    exit;
}

$complaint = $wsData['complaint'];
$complainants = $wsData['complainants'];
$respondents = $wsData['respondents'];
$sessions = $wsData['sessions'];
$latestSession = $wsData['latest_session'];
$attemptCount = $wsData['attempt_count'];
$maxAttempts = $wsData['max_attempts'];
$maxReached = $wsData['max_reached'];
$canScheduleSecond = $wsData['can_schedule_second'];
$isResolved = $wsData['is_resolved'];
$isEscalated = $wsData['is_escalated'];
$case = $wsData['case'];

$trackingNumber = $complaint['complaint_number'] ?: sprintf('CMP-%s-%05d', date('Y'), $complaintId);
$status = $complaint['status'];

// Flash message
$flash = $_SESSION['complaint_flash'] ?? null;
unset($_SESSION['complaint_flash']);

include '../../layouts/header.php';
?>
<script>
window.AGAP_SETTINGS = window.AGAP_SETTINGS || {};
window.AGAP_SETTINGS.mediation_days = <?= json_encode($allowedMedDays) ?>;
window.AGAP_SETTINGS.mediation_days_label = <?= json_encode($medDaysLabel) ?>;
window.AGAP_SETTINGS.mediation_day_numbers = <?= json_encode($allowedMedDayNumbers) ?>;
window.AGAP_SETTINGS.daily_capacity = <?= (int) $dailyHearingCapacity ?>;
window.AGAP_SETTINGS.mediation_hours_start = <?= json_encode($medHoursStart) ?>;
window.AGAP_SETTINGS.mediation_hours_end = <?= json_encode($medHoursEnd) ?>;
window.AGAP_SETTINGS.mediation_venue = <?= json_encode($medVenue) ?>;
</script>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/hearings.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/hearings.css'); ?>">

<style>
.paanyaya-ws-container {
    padding: 24px 30px;
    max-width: 1400px;
    margin: 0 auto;
}
.paanyaya-header-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #dcfce7;
    color: #15803d;
    font-size: 0.76rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 5px 12px;
    border-radius: 9999px;
    border: 1px solid #86efac;
}
.paanyaya-ws-header {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 22px 26px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.paanyaya-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
}
.paanyaya-main-title {
    font-size: 1.6rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.025em;
    margin: 4px 0 6px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.paanyaya-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}
.paanyaya-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 14px;
    margin-bottom: 16px;
    border-bottom: 1px solid #f1f5f9;
    flex-wrap: wrap;
    gap: 12px;
}
.paanyaya-card-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.session-card {
    background: #f8fafc;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    padding: 18px 20px;
    margin-bottom: 18px;
    transition: border-color 0.15s ease;
}
.session-card.is-active {
    background: #ffffff;
    border-color: #22c55e;
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.08);
}
.session-card.is-settled {
    background: #f0fdf4;
    border-color: #16a34a;
}
.session-card.is-failed {
    background: #fffbeb;
    border-color: #f59e0b;
}
.session-card.is-escalated {
    background: #eff6ff;
    border-color: #3b82f6;
}
.stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 700;
}
.outcome-option-label {
    display: block;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 10px;
    cursor: pointer;
    background: #ffffff;
    transition: all 0.15s ease;
}
.outcome-option-label:hover {
    border-color: #94a3b8;
    background: #f8fafc;
}
.outcome-option-label.is-selected {
    border-color: #16a34a;
    background: #f0fdf4;
}
.outcome-option-label.is-escalate.is-selected {
    border-color: #dc2626;
    background: #fef2f2;
}
.radio-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
    display: block;
}
.radio-desc {
    font-size: 0.8rem;
    color: #64748b;
    margin-top: 3px;
    line-height: 1.4;
    display: block;
}
.ltia-banner {
    background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
    border: 1.5px solid #86efac;
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.ltia-banner-icon {
    font-size: 1.7rem;
    line-height: 1;
}
</style>

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="paanyaya-ws-container">
            <!-- Navigation Back Bar -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                <a href="complaint-details.php?id=<?php echo $complaintId; ?>" class="back-link" style="margin-bottom: 0;">
                    <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    <span>Back to Complaint Details</span>
                </a>

                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="paanyaya-header-badge">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        LTIA Pre-Docketing Phase
                    </span>
                    <span style="font-size: 0.82rem; color: #64748b; font-weight: 600;">
                        Max 2 Attempts (Dalawang Beses Lang)
                    </span>
                </div>
            </div>

            <!-- Main Workspace Header -->
            <div class="paanyaya-ws-header">
                <div class="paanyaya-title-row">
                    <div>
                        <div style="font-size: 0.82rem; font-weight: 700; color: #166534; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">
                            Barangay Tumana Lupong Tagapamayapa · Informal Dialogue
                        </div>
                        <h1 class="paanyaya-main-title">
                            <span><?php echo htmlspecialchars($trackingNumber); ?></span>
                            <span style="color: #cbd5e1;">·</span>
                            <span style="color: #15803d;">PRE-DOCKETING (PAANYAYA <?php echo (int)$attemptCount; ?> OF <?php echo (int)$maxAttempts; ?>)</span>
                        </h1>
                        <p style="margin: 0; color: #64748b; font-size: 0.9rem;">
                            <strong><?php echo htmlspecialchars($complaint['complaint_title']); ?></strong>
                            <?php if (!empty($complaint['category_name'])): ?>
                                <span style="margin: 0 6px; color: #cbd5e1;">•</span>
                                <span><?php echo htmlspecialchars($complaint['category_name']); ?></span>
                            <?php endif; ?>
                            <span style="margin: 0 6px; color: #cbd5e1;">•</span>
                            <span>Filed: <?php echo date('M d, Y', strtotime($complaint['created_at'])); ?></span>
                        </p>
                    </div>

                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <?php if ($isResolved): ?>
                            <span class="stat-pill" style="background: #bbf7d0; color: #14532d; font-size: 0.88rem; padding: 6px 14px;">
                                Resolved at Paanyaya / Closed
                            </span>
                        <?php elseif ($isEscalated): ?>
                            <span class="stat-pill" style="background: #bfdbfe; color: #1e40af; font-size: 0.88rem; padding: 6px 14px;">
                                Formally Docketed (Case #<?php echo htmlspecialchars($case['case_number'] ?? ''); ?>)
                            </span>
                        <?php else: ?>
                            <span class="stat-pill" style="background: #dbeafe; color: #1e40af; font-size: 0.88rem; padding: 6px 14px;">
                                Paanyaya Attempt <?php echo (int)$attemptCount; ?> of 2
                            </span>
                        <?php endif; ?>

                        <button type="button" class="btn-sm btn-create" onclick="openPaanyayaPreview(<?php echo (int)$attemptCount; ?>)" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            <span>Print / Preview Paanyaya Letter</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- LTIA Guidance Banner -->
            <div class="ltia-banner">
                <div class="ltia-banner-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 10v11M9 10v11M15 10v11M19 10v11M12 2L2 7h20L12 2z"/></svg></div>
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 4px; font-size: 0.95rem; font-weight: 800; color: #14532d;">
                        LTIA Rule: Informal Pre-Docketing Keeps Contested Case Load Low
                    </h4>
                    <p style="margin: 0; font-size: 0.83rem; color: #166534; line-height: 1.45;">
                        Disputes resolved at the <strong>Paanyaya phase</strong> do not enter the formal court-annexed docket, preventing unnecessary statutory filings and boosting Barangay Tumana's <em>Lupon Tagapamayapa Incentives Awards (LTIA)</em> efficiency score. Disputes are permitted a strict maximum of <strong>two (2) informal invitation attempts</strong> before mandatory formal docketing.
                    </p>
                </div>
            </div>

            <!-- Grid: Dispute Details & Parties -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px;">
                <!-- Complainants Card -->
                <div class="paanyaya-card" style="margin-bottom: 0;">
                    <div class="paanyaya-card-header">
                        <h3>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle></svg>
                            Nagpaanyaya (Complainant / s)
                        </h3>
                        <span class="badge" style="background: #f0fdf4; color: #166534; font-weight: 700;"><?php echo count($complainants); ?> Party(ies)</span>
                    </div>
                    <?php foreach ($complainants as $c): ?>
                        <div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px dashed #e2e8f0;">
                            <div style="font-weight: 700; color: #0f172a; font-size: 0.95rem;"><?php echo htmlspecialchars($c['full_name']); ?></div>
                            <div style="font-size: 0.82rem; color: #64748b; margin-top: 2px;">
                                <?php echo htmlspecialchars($c['address'] ?: 'Barangay Tumana'); ?>
                                <?php if (!empty($c['contact_no'])): ?> • Tel: <?php echo htmlspecialchars($c['contact_no']); ?><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Respondents Card -->
                <div class="paanyaya-card" style="margin-bottom: 0;">
                    <div class="paanyaya-card-header">
                        <h3>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            Inanyayahan (Respondent / s)
                        </h3>
                        <span class="badge" style="background: #eff6ff; color: #1d4ed8; font-weight: 700;"><?php echo count($respondents); ?> Party(ies)</span>
                    </div>
                    <?php foreach ($respondents as $r): ?>
                        <div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px dashed #e2e8f0;">
                            <div style="font-weight: 700; color: #0f172a; font-size: 0.95rem;"><?php echo htmlspecialchars($r['full_name']); ?></div>
                            <div style="font-size: 0.82rem; color: #64748b; margin-top: 2px;">
                                <?php echo htmlspecialchars($r['address'] ?: 'Barangay Tumana'); ?>
                                <?php if (!empty($r['contact_no'])): ?> • Tel: <?php echo htmlspecialchars($r['contact_no']); ?><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Paanyaya Sessions Timeline (Dalawang Beses Lang) -->
            <div class="paanyaya-card">
                <div class="paanyaya-card-header">
                    <div>
                        <h3>
                            
                            Paanyaya Sessions History &amp; State Machine
                        </h3>
                        <p style="margin: 3px 0 0; font-size: 0.82rem; color: #64748b;">
                            Strictly limited to a maximum of two (2) attempts before mandatory escalation to formal mediation.
                        </p>
                    </div>

                    <div>
                        <?php if ($canScheduleSecond): ?>
                            <button type="button" class="btn-create" onclick="openScheduleSecondModal()" style="display: inline-flex; align-items: center; gap: 6px; background: #16a34a; border-color: #16a34a;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                <span>Schedule 2nd Paanyaya (Final Chance)</span>
                            </button>
                        <?php endif; ?>

                        <?php if (!$isResolved && !$isEscalated && ($maxReached || $attemptCount >= 2)): ?>
                            <button type="button" class="btn-create" onclick="triggerEscalation()" style="display: inline-flex; align-items: center; gap: 6px; background: #2563eb; border-color: #2563eb;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                <span>Escalate to Formal Case Docketing</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sessions List -->
                <?php if (empty($sessions)): ?>
                    <div style="text-align: center; padding: 30px; color: #64748b;">
                        <p>No Paanyaya session recorded yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($sessions as $sIndex => $sess): 
                        $attNum = (int)$sess['attempt_number'];
                        $isCurrent = ($attNum === $attemptCount);
                        $sessOutcome = $sess['session_outcome'];
                        $isSettled = ($sessOutcome === 'Settled');
                        $isFailed = in_array($sessOutcome, ['Absent_Retry', 'Impasse_Escalate', 'Max_Failed_Escalate'], true);
                        $isInProgress = ($sessOutcome === 'Continued_Dialogue');
                        $isAwaiting = empty($sessOutcome);
                    ?>
                        <div class="session-card <?php echo $isSettled ? 'is-settled' : ($isFailed ? 'is-failed' : ($isCurrent ? 'is-active' : '')); ?>">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <h4 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a;">
                                            Attempt #<?php echo $attNum; ?>: <?php echo ($attNum === 1) ? '1st Paanyaya (Unang Paanyaya)' : '2nd Paanyaya (Ikalawang Paanyaya - Huling Pagkakataon)'; ?>
                                        </h4>
                                        <?php if ($attNum === 2): ?>
                                            <span class="badge" style="background: #fed7aa; color: #9a3412; font-weight: 700; font-size: 0.72rem;">Final Attempt</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.84rem; color: #475569; margin-top: 4px;">
                                        <strong>Date:</strong> <?php echo date('F d, Y', strtotime($sess['scheduled_date'])); ?> (<?php echo date('l', strtotime($sess['scheduled_date'])); ?>)
                                        <span style="margin: 0 6px; color: #cbd5e1;">•</span>
                                        <strong>Time:</strong> <?php echo date('g:i A', strtotime($sess['scheduled_time'])); ?>
                                        <span style="margin: 0 6px; color: #cbd5e1;">•</span>
                                        <strong>Venue:</strong> <?php echo htmlspecialchars($sess['venue']); ?>
                                    </div>
                                </div>

                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <?php if ($isSettled): ?>
                                        <span class="stat-pill" style="background: #bbf7d0; color: #14532d;">
                                            Settled Amicably
                                        </span>
                                    <?php elseif ($sessOutcome === 'Absent_Retry'): ?>
                                        <span class="stat-pill" style="background: #fed7aa; color: #9a3412;">
                                            Party Absent (2nd Paanyaya Unlocked)
                                        </span>
                                    <?php elseif ($sessOutcome === 'Continued_Dialogue'): ?>
                                        <span class="stat-pill" style="background: #e0e7ff; color: #3730a3;">
                                            Continued Dialogue (2nd Session Agreed)
                                        </span>
                                    <?php elseif ($sessOutcome === 'Impasse_Escalate'): ?>
                                        <span class="stat-pill" style="background: #fecaca; color: #991b1b;">
                                            Impasse / Outright Refusal (Escalated)
                                        </span>
                                    <?php elseif ($sessOutcome === 'Max_Failed_Escalate'): ?>
                                        <span class="stat-pill" style="background: #fecaca; color: #991b1b;">
                                            2 Attempts Failed (Escalated to Docketing)
                                        </span>
                                    <?php else: ?>
                                        <span class="stat-pill" style="background: #fef08a; color: #854d0e;">
                                            Scheduled / Awaiting Dialogue
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($sess['pdf_path'])): ?>
                                        <a href="../../<?php echo htmlspecialchars($sess['pdf_path']); ?>" target="_blank" class="btn-sm btn-secondary" style="display: inline-flex; align-items: center; gap: 5px; text-decoration: none;">
                                            View Letter
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($isAwaiting && !$isResolved && !$isEscalated): ?>
                                        <button type="button" class="btn-sm btn-create" onclick="openRecordOutcomeModal(<?php echo (int)$sess['session_id']; ?>, <?php echo $attNum; ?>)" style="background: #16a34a; border-color: #16a34a;">
                                            Record Outcome
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Attendance & Notes if outcome recorded -->
                            <?php if (!empty($sessOutcome)): ?>
                                <div style="background: rgba(255,255,255,0.7); border-radius: 6px; padding: 10px 14px; margin-top: 10px; border: 1px solid #e2e8f0; font-size: 0.83rem; color: #334155;">
                                    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 4px;">
                                        <div><strong>Complainant Attendance:</strong> <span style="color: <?php echo ($sess['complainant_attended'] === 'Present') ? '#166534' : '#b91c1c'; ?>;"><?php echo htmlspecialchars($sess['complainant_attended']); ?></span></div>
                                        <div><strong>Respondent Attendance:</strong> <span style="color: <?php echo ($sess['respondent_attended'] === 'Present') ? '#166534' : '#b91c1c'; ?>;"><?php echo htmlspecialchars($sess['respondent_attended']); ?></span></div>
                                        <?php if (!empty($sess['resolved_at'])): ?>
                                            <div><strong>Resolved:</strong> <?php echo date('M d, Y h:i A', strtotime($sess['resolved_at'])); ?></div>
                                        <?php elseif (!empty($sess['escalated_at'])): ?>
                                            <div><strong>Escalated:</strong> <?php echo date('M d, Y h:i A', strtotime($sess['escalated_at'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($sess['outcome_notes'])): ?>
                                        <div style="margin-top: 6px; color: #475569;">
                                            <strong>Notes / Minutes:</strong> <?php echo nl2br(htmlspecialchars($sess['outcome_notes'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Escalated Details Banner (If case docketed) -->
            <?php if ($isEscalated && !empty($case)): ?>
                <div class="paanyaya-card" style="background: #f0f9ff; border: 1.5px solid #93c5fd;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                        <div>
                            <span class="badge" style="background: #bfdbfe; color: #1e40af; font-weight: 700; margin-bottom: 6px; display: inline-block;">
                                Formal Case Docketed
                            </span>
                            <h3 style="margin: 0; font-size: 1.2rem; color: #1e3a8a; font-weight: 800;">
                                Case #<?php echo htmlspecialchars($case['case_number']); ?> · Mediation Stage (1st Hearing)
                            </h3>
                            <p style="margin: 4px 0 0; font-size: 0.85rem; color: #3b82f6;">
                                Statutory 15-day Punong Barangay mediation timer is active. KP Form 7 (Complaint), KP Form 8 (Notice), and KP Form 9 (Summons) were generated.
                            </p>
                        </div>

                        <a href="../cases/mediation-workspace.php?case_id=<?php echo (int)$case['case_id']; ?>" class="btn-create" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none; background: #2563eb; border-color: #2563eb;">
                            <span>Open Mediation Workspace</span>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL 1: RECORD OUTCOME ACTION MODAL                            -->
<!-- ============================================================== -->
<div id="outcomeModal" class="modal" style="display: none;" role="dialog" aria-modal="true">
    <div class="modal-content" style="max-width: 620px; padding: 26px; border-radius: 12px; box-shadow: 0 25px 30px -5px rgba(0,0,0,0.2);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <h2 id="outcomeModalTitle" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">
                    Record Paanyaya Outcome
                </h2>
            </div>
            <button type="button" class="close-btn" onclick="closeRecordOutcomeModal()">&times;</button>
        </div>

        <form id="recordOutcomeForm" onsubmit="submitOutcome(event)">
            <input type="hidden" id="modalSessionId" name="session_id" value="">
            <input type="hidden" id="modalAttemptNumber" name="attempt_number" value="1">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">

            <!-- Attendance Selectors -->
            <div style="margin-bottom: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                <label style="font-size: 0.82rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 8px;">
                    Party Attendance at Informal Dialogue
                </label>
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="attComplainant" style="font-size: 0.84rem; font-weight: 600;">Complainant (Nagpaanyaya):</label>
                        <select id="attComplainant" name="complainant_attended" style="padding: 6px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                            <option value="Present" selected>Present (Dumating)</option>
                            <option value="Absent">Absent (Hindi Dumating)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="attRespondent" style="font-size: 0.84rem; font-weight: 600;">Respondent (Inanyayahan):</label>
                        <select id="attRespondent" name="respondent_attended" style="padding: 6px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                            <option value="Present" selected>Present (Dumating)</option>
                            <option value="Absent">Absent (Hindi Dumating)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Dynamic Radio Options (Attempt 1 vs Attempt 2) -->
            <label style="font-size: 0.82rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 8px;">
                Session Outcome Decision <span class="required-mark">*</span>
            </label>

            <!-- Attempt 1 Options Box -->
            <div id="attempt1Options">
                <!-- Radio 1: Settled -->
                <label class="outcome-option-label is-selected" id="optLabelSettled">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <input type="radio" name="outcome" value="Settled" checked onchange="handleOutcomeSelect(this)" style="margin-top: 3px;">
                        <div>
                            <span class="radio-title" style="color: #166534;">Resolved Amicably (Close Dispute - No Docketing)</span>
                            <span class="radio-desc">Both parties attended and agreed to amicable terms. The dispute is finalized and closed without ever starting the 15-day court clock (LTIA Pre-Docket Resolution).</span>
                        </div>
                    </div>
                </label>

                <!-- Radio 2: Party Absent -->
                <label class="outcome-option-label" id="optLabelAbsent">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <input type="radio" name="outcome" value="Absent_Retry" onchange="handleOutcomeSelect(this)" style="margin-top: 3px;">
                        <div>
                            <span class="radio-title" style="color: #9a3412;">Party Absent (Issue 2nd Paanyaya)</span>
                            <span class="radio-desc">One or both parties failed to appear. Extends the second and final informal chance before formal docketing.</span>
                        </div>
                    </div>
                </label>

                <!-- Radio 3: Continued Dialogue -->
                <label class="outcome-option-label" id="optLabelContinued">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <input type="radio" name="outcome" value="Continued_Dialogue" onchange="handleOutcomeSelect(this)" style="margin-top: 3px;">
                        <div>
                            <span class="radio-title" style="color: #3730a3;">Need 2nd Meeting to Finalize (Schedule 2nd Paanyaya)</span>
                            <span class="radio-desc">Both parties attended, made progress, and mutually agreed to a second informal session to finalize settlement terms.</span>
                        </div>
                    </div>
                </label>

                <!-- Radio 4: Impasse / Outright Refusal -->
                <label class="outcome-option-label is-escalate" id="optLabelImpasse">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <input type="radio" name="outcome" value="Impasse_Escalate" onchange="handleOutcomeSelect(this)" style="margin-top: 3px;">
                        <div>
                            <span class="radio-title" style="color: #dc2626;">Parties Refused to Settle (Escalate to Formal Docketing immediately)</span>
                            <span class="radio-desc">Hostility or clear refusal to settle. Do NOT waste time on a 2nd Paanyaya. Immediately assigns Case Number and generates KP Forms 7, 8, 9 for formal Mediation.</span>
                        </div>
                    </div>
                </label>
            </div>

            <!-- Attempt 2 Options Box (Dalawang Beses Lang) -->
            <div id="attempt2Options" style="display: none;">
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: 0.82rem; color: #92400e;">
                    <strong>Notice:</strong> This is the 2nd and final informal Paanyaya attempt (Dalawang Beses Lang). If not amicably resolved, the system mandates immediate formal docketing.
                </div>

                <label class="outcome-option-label is-selected" id="optLabelSettled2">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <input type="radio" name="outcome" value="Settled" onchange="handleOutcomeSelect(this)" style="margin-top: 3px;">
                        <div>
                            <span class="radio-title" style="color: #166534;">Resolved Amicably (Close Dispute - No Docketing)</span>
                            <span class="radio-desc">Parties resolved the dispute. Ends proceedings here as an LTIA Pre-Docket Resolution.</span>
                        </div>
                    </div>
                </label>

                <label class="outcome-option-label is-escalate" id="optLabelEscalate2">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <input type="radio" name="outcome" value="Max_Failed_Escalate" onchange="handleOutcomeSelect(this)" style="margin-top: 3px;">
                        <div>
                            <span class="radio-title" style="color: #dc2626;">Unsettled / Party Absent (Automatically Escalate to Formal Docketing)</span>
                            <span class="radio-desc">2-attempt maximum reached. The Paanyaya stage is closed. Formal Case Docket Number will be assigned, starting the 15-day Punong Barangay mediation timer.</span>
                        </div>
                    </div>
                </label>
            </div>

            <!-- Escalation 1st Mediation Hearing Schedule (Visible ONLY on Impasse / Max Failed) -->
            <div id="workspaceEscalationSection" style="display: none; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-top: 14px; margin-bottom: 6px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 6px;">
                    <h4 style="margin: 0; font-size: 0.92rem; color: #0f172a; font-weight: 700;">1st Formal Mediation Hearing Schedule</h4>
                    <span style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">
                        <?= htmlspecialchars($medDaysLabel) ?> · <?= htmlspecialchars($medVenue) ?> (Max <?= $dailyHearingCapacity ?>/Day)
                    </span>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="wsOutcomeMedDate" style="font-weight: 600; font-size: 0.82rem; margin: 0;">Mediation Date <span class="required-mark">*</span></label>
                            <span id="wsOutcomeCapacityBadge" class="badge-capacity" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                                Checking...
                            </span>
                        </div>
                        <input type="date" id="wsOutcomeMedDate" name="mediation_date" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    </div>
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="wsOutcomeMedTime" style="font-weight: 600; font-size: 0.82rem; margin: 0;">Target Call Time <span class="required-mark">*</span></label>
                            <span style="font-size: 0.72rem; color: #64748b;">1:00 PM - 4:30 PM</span>
                        </div>
                        <input type="time" id="wsOutcomeMedTime" name="mediation_time" min="<?= $medHoursStart ?>" max="<?= $medHoursEnd ?>" step="900" value="<?= $medHoursStart ?>" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px;">
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('wsOutcomeMedTime').value='13:00'; document.getElementById('wsOutcomeMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:00 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('wsOutcomeMedTime').value='13:15'; document.getElementById('wsOutcomeMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:15 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('wsOutcomeMedTime').value='13:30'; document.getElementById('wsOutcomeMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:30 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('wsOutcomeMedTime').value='13:45'; document.getElementById('wsOutcomeMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:45 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('wsOutcomeMedTime').value='14:00'; document.getElementById('wsOutcomeMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:00 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('wsOutcomeMedTime').value='14:30'; document.getElementById('wsOutcomeMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:30 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('wsOutcomeMedTime').value='15:00'; document.getElementById('wsOutcomeMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">3:00 PM</button>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="margin-top: 8px;">
                    <label style="font-weight: 600; font-size: 0.82rem;">Hearing Venue</label>
                    <input type="text" name="venue" value="<?= htmlspecialchars($medVenue) ?>" readonly style="padding: 8px 10px; font-size: 0.88rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>

                <!-- Live Day Docket Peek -->
                <div id="wsOutcomeDocketPeek" style="margin-top: 10px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                    <div style="font-size: 0.78rem; font-weight: 700; color: #334155; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                        <span>📋 Mediation Docket Call (<?= htmlspecialchars($medVenue) ?>)</span>
                        <span id="wsOutcomeDocketCount" style="font-size: 0.72rem; color: #64748b;">0 sessions</span>
                    </div>
                    <div id="wsOutcomeDocketList" style="font-size: 0.8rem; color: #64748b; line-height: 1.4;">
                        Select a mediation date to peek existing docket calls.
                    </div>
                </div>

                <!-- Inline Overlap Conflict Alert -->
                <div id="wsOutcomeConflictAlert" class="alert alert-danger" style="display: none; margin-top: 10px; padding: 8px 12px; font-size: 0.82rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;"></div>
            </div>

            <!-- Notes & Minutes -->
            <div class="form-group" style="margin-top: 14px;">
                <label for="outcomeNotes" style="font-size: 0.84rem; font-weight: 700; color: #334155;">Minutes &amp; Outcome Notes</label>
                <textarea id="outcomeNotes" name="outcome_notes" rows="3" placeholder="Enter session notes, agreed stipulations, or reasons for failure..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem;"></textarea>
            </div>

            <div id="outcomeErrorAlert" class="alert alert-danger" style="display: none; font-size: 0.84rem; margin-top: 10px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px;">
                <button type="button" class="btn-secondary" onclick="closeRecordOutcomeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitOutcome">Save Outcome Decision</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL 1B: DIRECT ESCALATION TO FORMAL MEDIATION MODAL          -->
<!-- ============================================================== -->
<div id="directEscalationModal" class="modal" style="display: none;" role="dialog" aria-modal="true">
    <div class="modal-content" style="max-width: 580px; padding: 24px; border-radius: 12px; box-shadow: 0 25px 30px -5px rgba(0,0,0,0.2);">
        <div class="modal-header" style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.3rem;">⚖️</span>
                <h2 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0;">
                    Escalate Dispute to Formal Case Docketing
                </h2>
            </div>
            <button type="button" class="close-btn" onclick="closeDirectEscalationModal()">&times;</button>
        </div>

        <form id="directEscalationForm" onsubmit="submitDirectEscalation(event)">
            <input type="hidden" name="complaint_id" value="<?php echo (int)$complaintId; ?>">
            <input type="hidden" name="outcome" value="Max_Failed_Escalate">

            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 0.85rem; color: #991b1b; line-height: 1.45;">
                <strong>Formal Mediation Docketing:</strong> The Paanyaya stage will be closed. An official Case Docket Number will be generated, starting the 15-day statutory Punong Barangay mediation timer, and KP Forms 7, 8, and 9 will be automatically prepared.
            </div>

            <!-- Escalation 1st Mediation Hearing Schedule -->
            <div style="background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 6px;">
                    <h4 style="margin: 0; font-size: 0.92rem; color: #0f172a; font-weight: 700;">1st Formal Mediation Hearing Schedule</h4>
                    <span style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">
                        <?= htmlspecialchars($medDaysLabel) ?> · <?= htmlspecialchars($medVenue) ?> (Max <?= $dailyHearingCapacity ?>/Day)
                    </span>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="directEscalationMedDate" style="font-weight: 700; font-size: 0.82rem; margin: 0;">Mediation Date <span class="required-mark">*</span></label>
                            <span id="directEscalationCapacityBadge" class="badge-capacity" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                                Checking...
                            </span>
                        </div>
                        <input type="date" id="directEscalationMedDate" name="mediation_date" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    </div>
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="directEscalationMedTime" style="font-weight: 700; font-size: 0.82rem; margin: 0;">Target Call Time <span class="required-mark">*</span></label>
                            <span style="font-size: 0.72rem; color: #64748b;">1:00 PM – 4:30 PM</span>
                        </div>
                        <input type="time" id="directEscalationMedTime" name="mediation_time" min="<?= $medHoursStart ?>" max="<?= $medHoursEnd ?>" step="900" value="<?= $medHoursStart ?>" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px;">
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('directEscalationMedTime').value='13:00'; document.getElementById('directEscalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:00 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('directEscalationMedTime').value='13:15'; document.getElementById('directEscalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:15 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('directEscalationMedTime').value='13:30'; document.getElementById('directEscalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:30 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('directEscalationMedTime').value='13:45'; document.getElementById('directEscalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:45 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('directEscalationMedTime').value='14:00'; document.getElementById('directEscalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:00 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('directEscalationMedTime').value='14:30'; document.getElementById('directEscalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:30 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('directEscalationMedTime').value='15:00'; document.getElementById('directEscalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">3:00 PM</button>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="margin-top: 8px;">
                    <label for="directEscalationVenue" style="font-weight: 700; font-size: 0.82rem;">Hearing Venue</label>
                    <input type="text" id="directEscalationVenue" name="venue" value="<?= htmlspecialchars($medVenue) ?>" readonly style="padding: 8px 10px; font-size: 0.88rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>

                <!-- Live Day Docket Peek -->
                <div id="directEscalationDocketPeek" style="margin-top: 10px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                    <div style="font-size: 0.78rem; font-weight: 700; color: #334155; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                        <span>📋 Mediation Docket Call (<?= htmlspecialchars($medVenue) ?>)</span>
                        <span id="directEscalationDocketCount" style="font-size: 0.72rem; color: #64748b;">0 sessions</span>
                    </div>
                    <div id="directEscalationDocketList" style="font-size: 0.8rem; color: #64748b; line-height: 1.4;">
                        Select a mediation date to peek existing docket calls.
                    </div>
                </div>

                <!-- Inline Overlap Conflict Alert -->
                <div id="directEscalationConflictAlert" class="alert alert-danger" style="display: none; margin-top: 10px; padding: 8px 12px; font-size: 0.82rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;"></div>
            </div>

            <div class="form-group" style="margin-top: 10px;">
                <label for="directEscalationNotes" style="font-size: 0.84rem; font-weight: 700; color: #334155;">Reasons for Escalation / Notes</label>
                <textarea id="directEscalationNotes" name="outcome_notes" rows="2" placeholder="Describe reasons for formal case escalation..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem;">Escalated to Formal Case Docketing from Paanyaya Pre-Docketing stage.</textarea>
            </div>

            <div id="directEscalationError" class="alert alert-danger" style="display: none; font-size: 0.84rem; margin-top: 10px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px;">
                <button type="button" class="btn-secondary" onclick="closeDirectEscalationModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitDirectEscalation" style="background: #dc2626; border-color: #dc2626;">Confirm Escalation &amp; Issue Summons</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL 2: SCHEDULE 2nd PAANYAYA MODAL                           -->
<!-- ============================================================== -->
<div id="scheduleSecondModal" class="modal" style="display: none;" role="dialog" aria-modal="true">
    <div class="modal-content" style="max-width: 560px; padding: 26px; border-radius: 12px; box-shadow: 0 25px 30px -5px rgba(0,0,0,0.2);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <h2 style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">
                    Schedule 2nd Paanyaya (Final Chance)
                </h2>
            </div>
            <button type="button" class="close-btn" onclick="closeScheduleSecondModal()">&times;</button>
        </div>

        <form id="scheduleSecondForm" onsubmit="submitScheduleSecond(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <input type="hidden" name="attempt_number" value="2">

            <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 0.84rem; color: #166534;">
                <strong>Second &amp; Final Informal Invitation:</strong> Re-schedule dialogue before the Punong Barangay / Lupong Tagapamayapa. Generates the official <em>Ikalawang Paanyaya</em> letter.
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="secondDate" style="font-weight: 700; font-size: 0.85rem;">2nd Invitation Date <span class="required-mark">*</span></label>
                    <input type="date" id="secondDate" name="invitation_date" value="<?php echo date('Y-m-d', strtotime('+3 weekdays')); ?>" required style="border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; width: 100%;">
                </div>
                <div class="form-group">
                    <label for="secondTime" style="font-weight: 700; font-size: 0.85rem;">2nd Invitation Time <span class="required-mark">*</span></label>
                    <input type="time" id="secondTime" name="invitation_time" value="14:00" required style="border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; width: 100%;">
                </div>
            </div>

            <div class="form-group">
                <label for="secondVenue" style="font-weight: 700; font-size: 0.85rem;">Venue</label>
                <input type="text" id="secondVenue" name="venue" value="Tanggapan ng Barangay Tumana" style="border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; width: 100%;">
            </div>

            <input type="hidden" name="has_e_signature" value="1">

            <div class="form-group" style="margin-top: 10px;">
                <label for="secondInvitee" style="font-weight: 700; font-size: 0.85rem;">Invitee Name (Person Being Invited)</label>
                <input type="text" id="secondInvitee" name="invitee_name" placeholder="Name of person being invited..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem;">
            </div>

            <div id="scheduleSecondError" class="alert alert-danger" style="display: none; font-size: 0.84rem; margin-top: 10px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px;">
                <button type="button" class="btn-secondary" onclick="closeScheduleSecondModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitScheduleSecond" style="background: #16a34a; border-color: #16a34a;">Issue 2nd Paanyaya</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL 3: PAANYAYA LETTER PREVIEW MODAL                         -->
<!-- ============================================================== -->
<div id="previewModal" class="modal" style="display: none;" role="dialog" aria-modal="true">
    <div class="modal-content" style="max-width: 900px; width: 95%; height: 90vh; padding: 0; display: flex; flex-direction: column; overflow: hidden; border-radius: 12px;">
        <div class="modal-header" style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; background: #ffffff; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 8px;">
                
                <h3 id="previewModalHeading" style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a;">
                    Paanyaya Letter Preview
                </h3>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <button type="button" class="btn-sm btn-secondary" onclick="printPreviewIframe()">
                    Print
                </button>
                <a id="btnDownloadPdf" href="#" target="_blank" class="btn-sm btn-create" style="text-decoration: none;">
                    ⬇ Download PDF
                </a>
                <button type="button" class="close-btn" onclick="closePreviewModal()">&times;</button>
            </div>
        </div>
        <div style="flex: 1 1 auto; background: #475569; position: relative;">
            <iframe id="previewIframe" src="about:blank" style="width: 100%; height: 100%; border: none;"></iframe>
        </div>
        <div style="padding: 10px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: flex-end;">
            <button type="button" class="btn-secondary" onclick="closePreviewModal()">Close Preview</button>
        </div>
    </div>
</div>

<script>
const COMPLAINT_ID = <?php echo (int)$complaintId; ?>;

function openPaanyayaPreview(attemptNum) {
    const heading = document.getElementById('previewModalHeading');
    if (heading) {
        heading.textContent = (attemptNum === 2) ? 'Ikalawang Paanyaya Letter Preview' : '1st Paanyaya Letter Preview';
    }
    const dlBtn = document.getElementById('btnDownloadPdf');
    if (dlBtn) {
        dlBtn.href = `../../../backend/api/paanyaya/preview.php?id=${COMPLAINT_ID}&attempt_number=${attemptNum}&download=1`;
    }
    const iframe = document.getElementById('previewIframe');
    if (iframe) {
        iframe.src = `../../../backend/api/paanyaya/preview.php?id=${COMPLAINT_ID}&attempt_number=${attemptNum}&format=html`;
    }
    const modal = document.getElementById('previewModal');
    if (modal) modal.style.display = 'flex';
}

function closePreviewModal() {
    const modal = document.getElementById('previewModal');
    if (modal) modal.style.display = 'none';
    const iframe = document.getElementById('previewIframe');
    if (iframe) iframe.src = 'about:blank';
}

function printPreviewIframe() {
    const iframe = document.getElementById('previewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}

function openRecordOutcomeModal(sessionId, attemptNum) {
    document.getElementById('modalSessionId').value = sessionId;
    document.getElementById('modalAttemptNumber').value = attemptNum;
    document.getElementById('outcomeModalTitle').textContent = `Record Outcome for Paanyaya Attempt #${attemptNum}`;
    
    const a1Box = document.getElementById('attempt1Options');
    const a2Box = document.getElementById('attempt2Options');
    const escSec = document.getElementById('workspaceEscalationSection');
    if (escSec) escSec.style.display = 'none';
    
    if (attemptNum >= 2) {
        if (a1Box) a1Box.style.display = 'none';
        if (a2Box) a2Box.style.display = 'block';
        const r = a2Box.querySelector('input[value="Settled"]');
        if (r) { r.checked = true; handleOutcomeSelect(r); }
    } else {
        if (a1Box) a1Box.style.display = 'block';
        if (a2Box) a2Box.style.display = 'none';
        const r = a1Box.querySelector('input[value="Settled"]');
        if (r) { r.checked = true; handleOutcomeSelect(r); }
    }
    
    const err = document.getElementById('outcomeErrorAlert');
    if (err) err.style.display = 'none';

    document.getElementById('outcomeModal').style.display = 'flex';
}

function closeRecordOutcomeModal() {
    document.getElementById('outcomeModal').style.display = 'none';
}

function handleOutcomeSelect(radio) {
    document.querySelectorAll('.outcome-option-label').forEach(lbl => lbl.classList.remove('is-selected'));
    const parentLabel = radio.closest('.outcome-option-label');
    if (parentLabel) parentLabel.classList.add('is-selected');

    const escSec = document.getElementById('workspaceEscalationSection');
    const submitBtn = document.getElementById('btnSubmitOutcome');
    if (radio.value === 'Impasse_Escalate' || radio.value === 'Max_Failed_Escalate') {
        if (escSec) escSec.style.display = 'block';
        if (submitBtn) {
            submitBtn.textContent = 'Escalate to Formal Docketing';
            submitBtn.style.background = '#dc2626';
            submitBtn.style.borderColor = '#dc2626';
        }
        if (window.initMediationDocketScheduler) {
            window.initMediationDocketScheduler({
                dateInput: '#wsOutcomeMedDate',
                timeInput: '#wsOutcomeMedTime',
                badgeEl: '#wsOutcomeCapacityBadge',
                docketEl: '#wsOutcomeDocketList',
                docketCountEl: '#wsOutcomeDocketCount',
                alertEl: '#wsOutcomeConflictAlert',
                submitBtn: '#btnSubmitOutcome',
                getComplaintId: () => COMPLAINT_ID
            });
        }
    } else {
        if (escSec) escSec.style.display = 'none';
        if (submitBtn) {
            submitBtn.textContent = 'Save Outcome Decision';
            submitBtn.style.background = '#16a34a';
            submitBtn.style.borderColor = '#16a34a';
            submitBtn.disabled = false;
        }
    }
}

async function submitOutcome(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitOutcome');
    const err = document.getElementById('outcomeErrorAlert');
    if (err) err.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Recording...';

    const form = document.getElementById('recordOutcomeForm');
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());

    try {
        const res = await fetch('../../../backend/api/paanyaya/record-outcome.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
            window.agapNotify?.(data.message, 'success', 'Paanyaya Workflow');
            setTimeout(() => {
                if (data.escalated && data.workspace_url) {
                    window.location.href = data.workspace_url;
                } else {
                    window.location.reload();
                }
            }, 1000);
        } else {
            if (err) {
                err.textContent = data.message || 'Failed to record outcome.';
                err.style.display = 'block';
            }
            btn.disabled = false;
            btn.textContent = 'Save Outcome Decision';
        }
    } catch (errExp) {
        if (err) {
            err.textContent = 'Network error: ' + errExp.message;
            err.style.display = 'block';
        }
        btn.disabled = false;
        btn.textContent = 'Save Outcome Decision';
    }
}

function openScheduleSecondModal() {
    document.getElementById('scheduleSecondError').style.display = 'none';
    document.getElementById('scheduleSecondModal').style.display = 'flex';
}

function closeScheduleSecondModal() {
    document.getElementById('scheduleSecondModal').style.display = 'none';
}

async function submitScheduleSecond(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitScheduleSecond');
    const err = document.getElementById('scheduleSecondError');
    if (err) err.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Issuing...';

    const form = document.getElementById('scheduleSecondForm');
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());

    try {
        const res = await fetch('../../../backend/api/paanyaya/schedule.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
            window.agapNotify?.(data.message, 'success', '2nd Paanyaya');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            if (err) {
                err.textContent = data.message || 'Failed to schedule 2nd Paanyaya.';
                err.style.display = 'block';
            }
            btn.disabled = false;
            btn.textContent = 'Issue 2nd Paanyaya';
        }
    } catch (errExp) {
        if (err) {
            err.textContent = 'Network error: ' + errExp.message;
            err.style.display = 'block';
        }
        btn.disabled = false;
        btn.textContent = 'Issue 2nd Paanyaya';
    }
}

function openDirectEscalationModal() {
    const err = document.getElementById('directEscalationError');
    if (err) err.style.display = 'none';
    const modal = document.getElementById('directEscalationModal');
    if (modal) {
        modal.style.display = 'flex';
        if (window.initMediationDocketScheduler) {
            window.initMediationDocketScheduler({
                dateInput: '#directEscalationMedDate',
                timeInput: '#directEscalationMedTime',
                badgeEl: '#directEscalationCapacityBadge',
                docketEl: '#directEscalationDocketList',
                docketCountEl: '#directEscalationDocketCount',
                alertEl: '#directEscalationConflictAlert',
                submitBtn: '#btnSubmitDirectEscalation',
                getComplaintId: () => COMPLAINT_ID
            });
        }
    }
}

function closeDirectEscalationModal() {
    const modal = document.getElementById('directEscalationModal');
    if (modal) modal.style.display = 'none';
}

function triggerEscalation() {
    openDirectEscalationModal();
}

async function submitDirectEscalation(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitDirectEscalation');
    const err = document.getElementById('directEscalationError');
    if (err) err.style.display = 'none';

    btn.disabled = true;
    btn.textContent = 'Escalating & Generating Forms...';

    const form = document.getElementById('directEscalationForm');
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());

    try {
        const res = await fetch('../../../backend/api/paanyaya/record-outcome.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
            window.agapNotify?.(data.message, 'success', 'Formal Docketing');
            closeDirectEscalationModal();
            setTimeout(() => {
                if (data.workspace_url) {
                    window.location.href = data.workspace_url;
                } else {
                    window.location.reload();
                }
            }, 1000);
        } else {
            if (err) {
                err.textContent = data.message || 'Failed to escalate dispute.';
                err.style.display = 'block';
            }
            btn.disabled = false;
            btn.textContent = 'Confirm Escalation & Issue Summons';
        }
    } catch (errExp) {
        if (err) {
            err.textContent = 'Network error: ' + errExp.message;
            err.style.display = 'block';
        }
        btn.disabled = false;
        btn.textContent = 'Confirm Escalation & Issue Summons';
    }
}
</script>

<?php include '../../layouts/footer.php'; ?>
