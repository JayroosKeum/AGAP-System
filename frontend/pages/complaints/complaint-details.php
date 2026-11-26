<?php

session_start();

// A direct workspace URL may be opened after a session expires. Redirect to
// sign-in instead of rendering a bare denial page. Case detail access itself
// remains limited to the roles that handle the case workflow.
if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}

$roleId = (int) $_SESSION['role_id'];
if ($roleId === 4) {
    // Summons Servers work from Proof of Service, not the full complaint
    // workspace. This keeps case data scoped to their assigned field role.
    header('Location: ../gps/proof-service.php');
    exit;
}
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/middleware/rbac.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';
require_once __DIR__ . '/../../../backend/services/ComplaintRoutingService.php';
require_once __DIR__ . '/../../../backend/services/ClosureEvidenceService.php';
if (!agapHasRole(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member'])) {
    http_response_code(403);
    exit('Access denied for this role.');
}

$isLuponReadOnly = $roleId === 3;
$canScreenClassification = in_array($roleId, [1, 2, 3], true);
$canAuthorizePastDate = $roleId === 1;

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: complaint-list.php');
    exit;
}

$complaintId = (int)$_GET['id'];
$preparation = null;
$paanyayaInvitations = [];
$preparationFlash = $_SESSION['paanyaya_preparation_flash'] ?? null;
if (($preparationFlash['complaint_id'] ?? null) === $complaintId) {
    unset($_SESSION['paanyaya_preparation_flash']);
} else {
    $preparationFlash = null;
}

require_once __DIR__ . '/../../../backend/services/PaanyayaPreparationService.php';
$preparationDb = (new Database())->connect();
agapRequireComplaintAccess($preparationDb, $complaintId, 'read', false);
$usesPaanyayaPreparation = PaanyayaPreparationService::requiresPreparation($preparationDb, $complaintId);
if (in_array($roleId, [1, 2, 3], true)) {
    try {
        $preparationService = new PaanyayaPreparationService($preparationDb);
        $preparation = $preparationService->intake($complaintId);
        if ($preparation) {
            $paanyayaInvitations[1] = $preparation;
            $secondInvitation = $preparationService->intake($complaintId, 2);
            if ($secondInvitation) {
                $paanyayaInvitations[2] = $secondInvitation;
            }
        }
    } catch (PDOException $exception) {
        error_log('Paanyaya preparation workspace: ' . $exception->getMessage());
        $preparationFlash ??= ['type' => 'error', 'message' => 'Paanyaya preparation is currently unavailable.'];
    }
}
require_once __DIR__ . '/../../../backend/models/CaseModel.php';
$canScreenSelectedIntake = false;
$assignedLisoId = 0;
$intakeScreeningMeta = null;
$informationRequests = [];
require_once __DIR__ . '/../../../backend/services/ComplaintInformationService.php';
try {
    $assignmentStatement = $preparationDb->prepare(
        'SELECT c.liso_assigned_to, c.screening_decision, c.status, c.submitted_by, c.is_draft,
                c.category_id, c.secondary_category_id, c.other_category_specification,
                c.secondary_other_category_specification, c.case_type, c.complaint_title, c.intake_source,
                c.narrative, cat.category_name, cs.case_id
         FROM complaints c
         LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
         LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
         WHERE c.complaint_id = ? LIMIT 1'
    );
    $assignmentStatement->execute([$complaintId]);
    $intakeScreeningMeta = $assignmentStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!empty($intakeScreeningMeta['submitted_by'])
        && ($roleId !== 3 || (int) $intakeScreeningMeta['liso_assigned_to'] === (int) $_SESSION['user_id'])) {
        $informationRequests = (new ComplaintInformationService($preparationDb))->history($complaintId);
    }
} catch (Throwable $e) {
    $intakeScreeningMeta = null;
}
$isUndocketedIntake = empty($intakeScreeningMeta['case_id']);
$isIntakeAlreadyEligible = ($intakeScreeningMeta['screening_decision'] ?? '') === 'Eligible'
    || !$isUndocketedIntake
    || in_array($intakeScreeningMeta['status'] ?? '', [
        'Accepted',
        'Pre-Docketed (Paanyaya)',
        'Mediation',
        'Conciliation',
        'Arbitration',
        'Settled',
        'Dismissed',
        'CFA Issued',
        'Archived',
        'Rejected',
        'Resolved at Paanyaya / Closed'
    ], true);
if ($roleId === 3 && $intakeScreeningMeta) {
    $assignedLisoId = (int) ($intakeScreeningMeta['liso_assigned_to'] ?? 0);
    $canScreenSelectedIntake = !$isIntakeAlreadyEligible && !$intakeScreeningMeta['is_draft'] && (
        $assignedLisoId < 1 || $assignedLisoId === (int) $_SESSION['user_id']
    );
}
$hasResidentAccount = !empty($intakeScreeningMeta['submitted_by']);
$awaitingResidentReply = ($intakeScreeningMeta['screening_decision'] ?? '') === 'Needs Information'
    && $informationRequests && $informationRequests[0]['responded_at'] === null;
$canFormallyDocket = false;
if (in_array($roleId, [1, 2], true) && !$usesPaanyayaPreparation && !$preparation) {
    try {
        $canFormallyDocket = (new CaseModel($preparationDb))->getDocketingError($complaintId) === null;
    } catch (Throwable $e) {
        $canFormallyDocket = false;
    }
}
$rawTab = trim((string)($_GET['tab'] ?? ''));
if ($rawTab === '' && !empty($preparationFlash)) {
    $rawTab = 'tab-paanyaya';
}
if ($rawTab === '') {
    $rawTab = 'tab-overview';
}
if (!str_starts_with($rawTab, 'tab-')) {
    $rawTab = 'tab-' . $rawTab;
}
$validTabs = ['tab-overview', 'tab-paanyaya', 'tab-mediation', 'tab-conciliation', 'tab-arbitration'];
$activeTab = in_array($rawTab, $validTabs, true) ? $rawTab : 'tab-overview';
$complaintFlash = $_SESSION['complaint_flash'] ?? null;
unset($_SESSION['complaint_flash']);

include '../../layouts/header.php';
require_once __DIR__ . '/../../../backend/models/Setting.php';
require_once __DIR__ . '/../../../backend/models/Paanyaya.php';
require_once __DIR__ . '/../../../backend/models/PartyModel.php';
$partyModel = new PartyModel();
$concurrentDisputes = $partyModel->checkConcurrentDisputesForComplaint($complaintId);
$paanyayaModel = new Paanyaya();
$servingOfficers = $paanyayaModel->getServingOfficers();
$eligiblePresiders = $paanyayaModel->getEligiblePresiders();
$sysSettings = Setting::all();
$maxPaanyayaSetting = (int) ($sysSettings['paanyaya_max_attempts'] ?? 3);

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
$workHoursStart = $sysSettings['working_hours_start'] ?? '08:00';
$workHoursEnd = $sysSettings['working_hours_end'] ?? '17:00';
$medDefaultDuration = (int) ($sysSettings['mediation_default_duration_min'] ?? 45) ?: 45;
$medMaxDuration = (int) ($sysSettings['mediation_max_duration_min'] ?? 60) ?: 60;
$concilDefaultDuration = (int) ($sysSettings['conciliation_default_duration_min'] ?? 60) ?: 60;
$concilMaxDuration = (int) ($sysSettings['conciliation_max_duration_min'] ?? 90) ?: 90;
$medStartFmt = date('g:i A', strtotime($medHoursStart));
$medEndFmt = date('g:i A', strtotime($medHoursEnd));
$workStartFmt = date('g:i A', strtotime($workHoursStart));
$workEndFmt = date('g:i A', strtotime($workHoursEnd));
?>
<script>
window.AGAP_SETTINGS = window.AGAP_SETTINGS || {};
window.AGAP_SETTINGS.paanyaya_max_attempts = <?php echo $maxPaanyayaSetting; ?>;
window.AGAP_SETTINGS.mediation_days = <?= json_encode($allowedMedDays) ?>;
window.AGAP_SETTINGS.mediation_days_label = <?= json_encode($medDaysLabel) ?>;
window.AGAP_SETTINGS.mediation_day_numbers = <?= json_encode($allowedMedDayNumbers) ?>;
window.AGAP_SETTINGS.daily_capacity = <?= (int) $dailyHearingCapacity ?>;
window.AGAP_SETTINGS.mediation_hours_start = <?= json_encode($medHoursStart) ?>;
window.AGAP_SETTINGS.mediation_hours_end = <?= json_encode($medHoursEnd) ?>;
window.AGAP_SETTINGS.mediation_venue = <?= json_encode($medVenue) ?>;
window.AGAP_SETTINGS.working_hours_start = <?= json_encode($workHoursStart) ?>;
window.AGAP_SETTINGS.working_hours_end = <?= json_encode($workHoursEnd) ?>;
window.AGAP_SETTINGS.mediation_default_duration_min = <?= (int) $medDefaultDuration ?>;
window.AGAP_SETTINGS.mediation_max_duration_min = <?= (int) $medMaxDuration ?>;
window.AGAP_SETTINGS.conciliation_default_duration_min = <?= (int) $concilDefaultDuration ?>;
window.AGAP_SETTINGS.conciliation_max_duration_min = <?= (int) $concilMaxDuration ?>;
</script>
<?php

?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<link rel="stylesheet" href="../../assets/css/case-details-modern.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/case-details-modern.css'); ?>">
<link rel="stylesheet" href="../../assets/css/hearings.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/hearings.css'); ?>">
<link rel="stylesheet" href="../../assets/css/paanyaya-preparation.css?v=<?= filemtime(__DIR__ . '/../../assets/css/paanyaya-preparation.css') ?>">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content case-details-main">

        <?php include '../../layouts/navbar.php'; ?>

        <!-- Modern Action Bar / Navigation Header (Picture 1) -->
        <div class="case-details-action-bar">
            <div class="case-bar-left">
                <a href="complaint-list.php" class="back-link">
                    <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    <span>Back to Complaints</span>
                </a>
                <div class="case-title-badge-group">
                    <h1 id="complaintNumber" class="case-main-heading">Case #—</h1>
                    <span id="complaintStatusBadge" class="case-stage-pill status-mediation">Loading...</span>
                </div>
            </div>

            <div class="case-bar-right">
                <?php if ($roleId === 3 && $assignedLisoId > 0 && $assignedLisoId !== (int) $_SESSION['user_id']): ?>
                    <span style="color:#64748b; font-size:.82rem; font-weight:700;">Assigned to another LISO</span>
                <?php endif; ?>
                <?php if ($canFormallyDocket): ?>
                    <button type="button" class="btn-case-primary" onclick="docketEligibleIntake(<?php echo $complaintId; ?>)">
                        <span>Formal Docket</span>
                    </button>
                <?php endif; ?>
                <?php if ($isLuponReadOnly): ?>
                    <span class="btn-case-status" id="statusTrackerButton" style="cursor:default; pointer-events:none;" title="Current complaint progression status">
                        <span id="statusDotIndicator" class="status-dot-indicator"></span>
                        <span id="statusTrackerButtonLabel">Status: Under Review</span>
                    </span>
                <?php else: ?>
                    <button type="button" class="btn-case-status" id="statusTrackerButton" onclick="toggleStatusTracker()" title="Toggle case progression tracking">
                        <span id="statusDotIndicator" class="status-dot-indicator"></span>
                        <span id="statusTrackerButtonLabel">Status: In Progress</span>
                        <span id="statusToggleArrow" class="dropdown-arrow">▼</span>
                    </button>
                <?php endif; ?>
                <?php if (!$isLuponReadOnly): ?>
                    <a id="editComplaintBtn" href="complaint-edit.php?id=<?php echo $complaintId; ?>" class="btn-case-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        <span>Edit Case</span>
                    </a>
                    <button type="button" id="openRoutingOverrideBtn" class="btn-case-status" onclick="openRoutingOverrideModal()" title="Review or override automated receiving queue">
                        <span>Queue / Routing</span>
                    </button>
                    <button type="button" id="openPendingCloseBtn" class="btn-case-status" onclick="openPendingCloseModal()" title="Request complainant-verified Pending Close">
                        <span>Request Pending Close</span>
                    </button>
                <?php else: ?>
                    <span class="btn-case-status" style="background:#eef6f2; border-color:#cde2d6; color:#102b2d; cursor:default; pointer-events:none;">
                        Read-Only Screening View
                    </span>
                <?php endif; ?>
                <script>
                    window.__agapLuponReadOnly = <?php echo $isLuponReadOnly ? 'true' : 'false'; ?>;
                    window.__agapCanScreenClassification = <?php echo $canScreenClassification ? 'true' : 'false'; ?>;
                </script>
            </div>
        </div>

        <?php if ($complaintFlash && !empty($complaintFlash['message'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    window.agapNotify && window.agapNotify(
                        <?php echo json_encode($complaintFlash['message']); ?>,
                        <?php echo json_encode(($complaintFlash['type'] ?? '') === 'success' ? 'success' : 'error'); ?>
                    );
                });
            </script>
        <?php endif; ?>

        <script>
        function resolveLisoCategoryInput(inputEl, selectEl, otherWrapEl, otherInpEl) {
            if (!selectEl) return;
            const map = {};
            let othersId = '';
            for (let i = 0; i < selectEl.options.length; i++) {
                const opt = selectEl.options[i];
                if (!opt.value) continue;
                const text = opt.text.trim();
                map[text.toLowerCase()] = { id: opt.value, text };
                if (!othersId && /others|iba pa/i.test(text)) {
                    othersId = opt.value;
                }
            }

            if (inputEl) {
                const typed = inputEl.value.trim();
                const lower = typed.toLowerCase();
                if (typed === '') {
                    selectEl.value = '';
                    if (otherWrapEl) otherWrapEl.style.display = 'none';
                    if (otherInpEl) otherInpEl.value = '';
                    return;
                }
                if (lower in map) {
                    const matched = map[lower];
                    selectEl.value = matched.id;
                    const isExplicitOther = /others|iba pa/i.test(matched.text);
                    if (otherWrapEl) otherWrapEl.style.display = isExplicitOther ? 'block' : 'none';
                    if (otherInpEl) {
                        if (!isExplicitOther || otherInpEl.value.trim().toLowerCase() === 'pending barangay liso classification') {
                            otherInpEl.value = '';
                        }
                    }
                    return;
                }
                // Custom typed category name: map to Others and store typed text as specification
                selectEl.value = othersId || '51';
                if (otherWrapEl) otherWrapEl.style.display = 'none';
                if (otherInpEl) otherInpEl.value = typed;
                return;
            }

            if (otherWrapEl) {
                const selectedText = selectEl.options[selectEl.selectedIndex]?.text || '';
                const isOther = selectEl.value !== '' && /others|iba pa/i.test(selectedText);
                otherWrapEl.style.display = isOther ? 'block' : 'none';
                if (isOther && otherInpEl && otherInpEl.value.trim().toLowerCase() === 'pending barangay liso classification') {
                    otherInpEl.value = '';
                }
            }
        }

        function syncLisoCriminalFields() {
            // Criminal jurisdiction warnings removed across all intake & screening forms.
        }

        function autoMapLisoScreenCaseType() {
            const pSel = document.getElementById('lisoScreenCategoryId');
            const sSel = document.getElementById('lisoScreenSecondaryCategoryId');
            const caseTypeEl = document.getElementById('lisoScreenCaseType');
            const hintEl = document.getElementById('lisoScreenCaseTypeHint');
            if (!pSel || !caseTypeEl) return;

            const pOpt = pSel.options[pSel.selectedIndex];
            const sOpt = sSel ? sSel.options[sSel.selectedIndex] : null;
            let resolved = null;
            if (pOpt && pOpt.value && pOpt.dataset.isOther !== '1') {
                resolved = pOpt.dataset.caseType || 'Civil';
            } else if (pOpt && pOpt.value && pOpt.dataset.isOther === '1') {
                const typed = (document.getElementById('lisoScreenCategoryInput')?.value || '').trim();
                if (typed) {
                    resolved = window.AgapCategoryClassification?.inferCaseTypeFromCategoryText(typed) || 'Civil';
                }
            }
            if (sOpt && sOpt.value && sOpt.dataset.isOther !== '1' && sOpt.dataset.caseType === 'Criminal') {
                resolved = 'Criminal';
            }
            if (resolved === 'Civil' || resolved === 'Criminal') {
                caseTypeEl.value = resolved;
                if (hintEl) hintEl.textContent = `Auto-mapped to ${resolved} (editable if needed).`;
            }
            syncLisoCriminalFields();
        }

        function syncLisoScreenOtherInputs() {
            resolveLisoCategoryInput(
                document.getElementById('lisoScreenCategoryInput'),
                document.getElementById('lisoScreenCategoryId'),
                document.getElementById('lisoScreenOtherWrap'),
                document.getElementById('lisoScreenOtherSpec')
            );
            resolveLisoCategoryInput(
                document.getElementById('lisoScreenSecondaryCategoryInput'),
                document.getElementById('lisoScreenSecondaryCategoryId'),
                document.getElementById('lisoScreenSecondaryOtherWrap'),
                document.getElementById('lisoScreenSecondaryOtherSpec')
            );
            autoMapLisoScreenCaseType();
        }

        async function runLisoAutoClassify(complaintId) {
            const btn = document.getElementById('btnLisoAutoClassify');
            const narText = String(window.complaintData?.narrative || document.getElementById('complaintNarrative')?.textContent || '').trim();
            if (narText.length < 10) {
                window.agapNotify?.('Narrative must contain at least 10 characters to auto-classify.', 'warning', 'Auto-Classify');
                return;
            }
            const origText = btn ? btn.textContent : '';
            if (btn) { btn.disabled = true; btn.textContent = 'Classifying...'; }
            try {
                const resp = await fetch('../../../backend/api/ai/classify-complaint.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': window.agapCsrfToken || '' },
                    body: JSON.stringify({
                        complaint_id: complaintId,
                        narrative: narText,
                        requested_resolution: String(window.complaintData?.requested_resolution || ''),
                        processing_mode: 'local_only',
                        csrf_token: window.agapCsrfToken || ''
                    })
                });
                const json = await resp.json();
                const sug = json?.suggestion;
                if (resp.ok && json.success && sug?.primary?.category_name) {
                    const catInput = document.getElementById('lisoScreenCategoryInput');
                    const catSelect = document.getElementById('lisoScreenCategoryId');
                    const caseTypeEl = document.getElementById('lisoScreenCaseType');
                    const titleEl = document.getElementById('lisoScreenComplaintTitle');
                    if (catInput) catInput.value = sug.primary.category_name;
                    if (catSelect) catSelect.value = String(sug.primary.category_id || '');
                    syncLisoScreenOtherInputs();
                    if (caseTypeEl && sug.case_type) {
                        caseTypeEl.value = sug.case_type;
                        syncLisoCriminalFields();
                    }
                    if (titleEl && sug.suggested_title && !titleEl.value.trim()) {
                        titleEl.value = sug.suggested_title;
                    }
                    window.agapNotify?.(`Suggested "${sug.primary.category_name}" (${sug.case_type}). Verify before marking eligible.`, 'success', 'LISO Classification');
                } else {
                    window.agapNotify?.(json?.message || 'Unable to suggest category from narrative.', 'warning', 'Auto-Classify');
                }
            } catch (_) {
                window.agapNotify?.('Unable to connect to classification helper.', 'error', 'Auto-Classify');
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = origText; }
            }
        }

        async function reviewIntake(complaintId, status) {
            if (window.__agapReviewIntakeSubmitting) return;

            syncLisoScreenOtherInputs();

            const catInputEl = document.getElementById('lisoScreenCategoryInput');
            const catEl = document.getElementById('lisoScreenCategoryId');
            const otherEl = document.getElementById('lisoScreenOtherSpec');
            const secCatInputEl = document.getElementById('lisoScreenSecondaryCategoryInput');
            const secCatEl = document.getElementById('lisoScreenSecondaryCategoryId');
            const secOtherEl = document.getElementById('lisoScreenSecondaryOtherSpec');
            const caseTypeEl = document.getElementById('lisoScreenCaseType');
            const titleEl = document.getElementById('lisoScreenComplaintTitle');
            const notesEl = document.getElementById('lisoScreeningDecisionNotes');
            const requestEl = document.getElementById('lisoResidentRequest');
            const btnNeedsInfo = document.getElementById('btnIntakeNeedsInfo');
            const btnEligible = document.getElementById('btnMarkIntakeEligible');
            const btnRejected = document.getElementById('btnMarkIntakeRejected');
            if (status === 'Rejected' && !(notesEl?.value || '').trim()) {
                window.agapNotify?.('Enter the reason or jurisdictional basis before marking this intake ineligible.', 'error');
                notesEl?.focus();
                return;
            }

            if (status === 'Accepted') {
                const typedPrimary = (catInputEl?.value || '').trim();
                if ((catInputEl && !typedPrimary) || (catEl && !catEl.value && !typedPrimary)) {
                    window.agapNotify?.('Select or type the official Complaint Category before marking this intake eligible.', 'error');
                    (catInputEl || catEl)?.focus();
                    return;
                }
                const catText = catEl?.options[catEl.selectedIndex]?.text || '';
                const isOther = /others|iba pa/i.test(catText);
                const otherVal = (otherEl?.value || '').trim();
                if (isOther && (!otherVal || otherVal.toLowerCase() === 'pending barangay liso classification')) {
                    window.agapNotify?.('Specify the exact dispute category or type a complaint category before marking this intake eligible.', 'error');
                    (otherEl?.offsetParent ? otherEl : (catInputEl || otherEl))?.focus();
                    return;
                }
                if (caseTypeEl && !['Civil', 'Criminal'].includes(caseTypeEl.value)) {
                    window.agapNotify?.('Select whether this case is Civil or Criminal.', 'error');
                    caseTypeEl.focus();
                    return;
                }
            }

            window.__agapReviewIntakeSubmitting = true;
            const activeBtn = status === 'Accepted' ? btnEligible : (status === 'Rejected' ? btnRejected : btnNeedsInfo);
            const originalLabel = activeBtn ? activeBtn.textContent : '';
            const allControls = [btnNeedsInfo, btnEligible, btnRejected, notesEl, requestEl, catInputEl, catEl, otherEl, secCatInputEl, secCatEl, secOtherEl, caseTypeEl, titleEl];

            allControls.forEach((el) => {
                if (el) {
                    el.disabled = true;
                    el.style.pointerEvents = 'none';
                    el.style.opacity = '0.65';
                }
            });
            if (activeBtn) {
                activeBtn.textContent = 'Saving...';
            }

            const reviewNotes = (notesEl?.value || '').trim();
            const form = new FormData();
            form.append('complaint_id', complaintId);
            form.append('status', status);
            form.append('review_notes', reviewNotes);
            if (status !== 'Rejected') {
            if (catEl) form.append('category_id', catEl.value);
            if (catInputEl) form.append('category_name', catInputEl.value.trim());
            if (otherEl) form.append('other_category_specification', otherEl.value.trim());
            if (secCatEl) form.append('secondary_category_id', secCatEl.value);
            if (secCatInputEl) form.append('secondary_category_name', secCatInputEl.value.trim());
            if (secOtherEl) form.append('secondary_other_category_specification', secOtherEl.value.trim());
            if (caseTypeEl) form.append('case_type', caseTypeEl.value);
            if (titleEl) form.append('complaint_title', titleEl.value.trim());
            }
            if (status === 'Needs Information' && requestEl) {
                form.append('request_message', requestEl.value.trim());
            }
            try {
                const response = await fetch('../../../backend/api/complaints/review.php', { method: 'POST', body: form });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to save the screening decision.');
                }
                const screeningCard = document.getElementById('intake-screening');
                if (screeningCard) {
                    screeningCard.style.display = 'none';
                }
                window.agapNotify?.(result.message, 'success');
                window.location.reload();
            } catch (error) {
                window.__agapReviewIntakeSubmitting = false;
                allControls.forEach((el) => {
                    if (el) {
                        el.disabled = false;
                        el.style.pointerEvents = '';
                        el.style.opacity = '';
                    }
                });
                if (activeBtn && originalLabel) {
                    activeBtn.textContent = originalLabel;
                }
                window.agapNotify?.(error.message, 'error');
            }
        }

        async function docketEligibleIntake(complaintId) {
            if (window.__agapDocketIntakeSubmitting) return;
            if (!window.confirm('Create the formal KP case docket for this eligible intake?')) return;
            window.__agapDocketIntakeSubmitting = true;
            const activeBtn = window.event?.target?.closest?.('button') || document.querySelector('[onclick*="docketEligibleIntake"]');
            const originalText = activeBtn ? activeBtn.textContent : '';
            if (activeBtn) {
                activeBtn.disabled = true;
                activeBtn.textContent = 'Docketing...';
            }
            try {
                const form = new FormData();
                form.append('complaint_id', complaintId);
                const response = await fetch('../../../backend/api/complaints/docket.php', { method: 'POST', headers: { 'X-CSRF-Token': window.agapCsrfToken || '' }, body: form });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to docket this intake.');
                }
                window.agapNotify?.(`Formal case ${result.case_number} created.`, 'success');
                window.location.reload();
            } catch (error) {
                window.__agapDocketIntakeSubmitting = false;
                if (activeBtn) {
                    activeBtn.disabled = false;
                    if (originalText) activeBtn.textContent = originalText;
                }
                window.agapNotify?.(error.message || 'Unable to docket this intake.', 'error');
            }
        }
        </script>

        <!-- Cross-Link Alert Banner (Module 5: Multi-Complaint Handling & Resident Linkage) -->
        <?php $hasConcurrent = !empty($concurrentDisputes['has_concurrent']); ?>
        <div id="crossLinkAlertBanner" class="cross-link-alert-banner" style="<?php echo $hasConcurrent ? 'display: flex;' : 'display: none;'; ?> align-items: center; justify-content: space-between; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 16px; margin-bottom: 14px; gap: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="color: #b45309; display: flex; align-items: center;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                </span>
                <div id="crossLinkBannerContent" style="font-size: 0.88rem; color: #92400e; line-height: 1.4;">
                    <?php if ($hasConcurrent): ?>
                        <?php 
                            $alerts = $concurrentDisputes['alerts'] ?? [];
                            if (count($alerts) === 1):
                                $a = $alerts[0];
                        ?>
                            <span><strong>Active Dispute Notice:</strong> <strong><?php echo htmlspecialchars($a['resident_name']); ?></strong> (<?php echo htmlspecialchars($a['current_role']); ?>) is currently an active party in <a href="<?php echo htmlspecialchars($a['view_url']); ?>" style="color: #92400e; font-weight: 700; text-decoration: underline;">Case #<?php echo htmlspecialchars($a['concurrent_display_number']); ?></a> (<?php echo htmlspecialchars($a['concurrent_status']); ?>).</span>
                        <?php else: ?>
                            <?php $pNames = implode(', ', array_unique(array_column($alerts, 'resident_name'))); ?>
                            <?php $pCount = count(array_unique(array_column($alerts, 'resident_name'))); $aCount = count($alerts); ?>
                            <span><strong>Concurrent Disputes Notice:</strong> Active <?php echo $pCount === 1 ? 'party' : 'parties'; ?> (<strong><?php echo htmlspecialchars($pNames); ?></strong>) <?php echo $pCount === 1 ? 'is' : 'are'; ?> involved in <strong><?php echo $aCount; ?> other active <?php echo $aCount === 1 ? 'case' : 'cases'; ?></strong>.</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="case-timeline.php?id=<?php echo $complaintId; ?>" class="cross-link-action-link" style="font-size: 0.84rem; font-weight: 700; color: #b45309; text-decoration: none; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px; background: #fef3c7; border-radius: 6px; border: 1px solid #fde68a;">
                <span>Cross-Case Timeline &rarr;</span>
            </a>
        </div>

        <!-- Horizontal Tabs Navigation Bar -->
        <nav class="case-tabs-nav" aria-label="Case Sections" <?php echo ($isLuponReadOnly && $isUndocketedIntake && !$preparation) ? 'style="display: none;"' : ''; ?>>
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-overview' ? 'active' : ''; ?>" data-tab="tab-overview" onclick="switchCaseTab('tab-overview')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                <span>Case Folder</span>
            </button>
            <?php if ($preparation || $preparationFlash || $usesPaanyayaPreparation || !$isLuponReadOnly): ?>
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-paanyaya' ? 'active' : ''; ?>" data-tab="tab-paanyaya" id="navTabPaanyayaLink" onclick="switchCaseTab('tab-paanyaya')">
                <span>🤝 Paanyaya</span>
            </button>
            <?php endif; ?>
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-mediation' ? 'active' : ''; ?>" data-tab="tab-mediation" onclick="switchCaseTab('tab-mediation')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <span>Mediation</span>
                <span class="case-tab-lock-icon" id="tabLockMediation" style="display: none;" title="Locked stage">🔒</span>
            </button>
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-conciliation' ? 'active' : ''; ?>" data-tab="tab-conciliation" onclick="switchCaseTab('tab-conciliation')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v1"></path><path d="M18 8h4a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-4"></path><circle cx="8" cy="12" r="2"></circle><circle cx="18" cy="13" r="1.5"></circle></svg>
                <span>Conciliation</span>
                <span class="case-tab-lock-icon" id="tabLockConciliation" style="display: none;" title="Locked stage">🔒</span>
            </button>
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-arbitration' ? 'active' : ''; ?>" data-tab="tab-arbitration" id="caseTabItemOutcomes" onclick="switchCaseTab('tab-arbitration')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"></path><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                <span id="tabOutcomesLabel">Outcomes</span>
                <span class="case-tab-lock-icon" id="tabLockArbitration" style="display: none;" title="Locked stage">🔒</span>
            </button>
        </nav>

        <!-- Collapsible Case Status / Progression Section -->
        <div id="statusTrackerSection" class="status-tracker-section" style="display: none; margin: 0 30px 24px;">
            <div class="status-tracker-card">
                <div class="status-tracker-card-header">
                    <div class="status-tracker-header-left">
                        <div class="status-tracker-title-wrap">
                            <span class="status-tracker-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            </span>
                            <h2>Complaint Status &amp; Progression</h2>
                        </div>
                        <span id="trackerCurrentStageBadge" class="status-pill status-filed">Loading...</span>
                    </div>
                    <button type="button" class="btn-collapse-toggle" onclick="toggleStatusTracker()" title="Collapse case status section" aria-label="Collapse case status section">
                        <span id="trackerCollapseIcon">▲</span>
                    </button>
                </div>

                <div class="status-tracker-body">
                    <!-- Horizontal Progress Tracker Stepper -->
                    <div class="status-stepper-scroll">
                        <div id="statusStepperTrack" class="status-stepper-track">
                            <div class="stepper-loading">Loading case progression…</div>
                        </div>
                    </div>

                    <!-- Current Stage Context Banner -->
                    <div id="statusStageHighlights" class="status-stage-highlights" style="display:none;">
                        <div class="highlight-info-box">
                            <div class="highlight-icon" id="highlightIcon">ℹ</div>
                            <div class="highlight-text">
                                <strong id="highlightStageName">Current Stage: —</strong>
                                <p id="highlightStageDetails" style="margin: 2px 0 0; color: #475569; font-size: 0.88rem;">—</p>
                            </div>
                        </div>
                    </div>

                    <!-- Expandable Case Event History -->
                    <div class="status-history-drawer">
                        <button type="button" class="btn-history-toggle" onclick="toggleCaseHistoryDrawer()">
                            <span id="historyDrawerIcon">▶</span>
                            <span>View Recorded Events &amp; Case History (<span id="historyEventCount">0</span>)</span>
                        </button>
                        <div id="caseHistoryContent" class="case-history-content" style="display: none;">
                            <div id="caseHistoryTimeline" class="case-history-timeline">
                                <p style="color:#64748b; font-size:0.85rem; margin:0;">Loading case events…</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================================
             TAB PANE 1: CASE FOLDER (Default Active Tab)
             =================================================================== -->
        <div id="tab-overview" class="case-tab-pane <?php echo $activeTab === 'tab-overview' ? 'active' : ''; ?>">

            <!-- Celebratory Statutory Countdown Completion Alert Banner -->
            <div id="statutoryTransmittalAlertBanner" style="display: none; margin-bottom: 16px; background: #ecfdf5; border: 1px solid #6ee7b7; border-left: 5px solid #10b981; border-radius: 8px; padding: 14px 18px; color: #065f46; font-size: 0.9rem; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.4rem; line-height: 1;">ℹ️</span>
                    <div>
                        <strong style="display: block; font-size: 0.95rem; color: #064e3b; margin-bottom: 2px;">
                            10-Working-Day Statutory Period Completed: Court Transmittal Letter is now ready to print.
                        </strong>
                        <span style="font-size: 0.82rem; color: #047857;">
                            The mandatory repudiation window under RA 7160 Sec. 416 has elapsed. You may now print and transmit the official endorsement to the Metropolitan Trial Court (MTCC Marikina City).
                        </span>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; flex-shrink: 0;">
                    <button type="button" class="btn-create" onclick="openPrintTransmittalLetter()" style="background: #059669; font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; color: #fff;">
                        🖨️ Print Transmittal Letter
                    </button>
                </div>
            </div>

            <?php if ($informationRequests): ?>
                <section id="intake-request-history" class="sophos-card paanyaya-preparation" aria-labelledby="intake-request-history-title">
                    <h2 id="intake-request-history-title">Information request history</h2>
                    <ol class="paanyaya-prep-list">
                        <?php foreach ($informationRequests as $informationRequest): ?>
                            <li>
                                <div>
                                    <strong>Request sent</strong> · <?= htmlspecialchars($informationRequest['requested_at']) ?>
                                    <p><?= nl2br(htmlspecialchars($informationRequest['request_message'])) ?></p>
                                    <?php if ($informationRequest['responded_at']): ?>
                                        <strong>Resident replied</strong> · <?= htmlspecialchars($informationRequest['responded_at']) ?>
                                        <p><?= nl2br(htmlspecialchars($informationRequest['response_message'])) ?></p>
                                    <?php else: ?>
                                        <p>Awaiting resident reply</p>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>

            <?php if ($roleId === 3 && $canScreenSelectedIntake && !$awaitingResidentReply): ?>
                <?php
                    require_once __DIR__ . '/../../../backend/services/ComplaintClassificationService.php';
                    $screeningCategories = ComplaintClassificationService::getCategories($preparationDb);
                    $screeningRow = [];
                    try {
                        $scrStmt = $preparationDb->prepare('SELECT * FROM complaint_intake_screenings WHERE complaint_id = ? LIMIT 1');
                        $scrStmt->execute([$complaintId]);
                        $screeningRow = $scrStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    } catch (Throwable) {
                        $screeningRow = [];
                    }
                    $currentCatId = (int) ($intakeScreeningMeta['category_id'] ?? 0);
                    $currentSecCatId = (int) ($intakeScreeningMeta['secondary_category_id'] ?? 0);
                    $currentOtherSpec = (string) ($intakeScreeningMeta['other_category_specification'] ?? '');
                    $currentSecOtherSpec = (string) ($intakeScreeningMeta['secondary_other_category_specification'] ?? '');
                    $currentCaseType = (string) ($intakeScreeningMeta['case_type'] ?? 'Civil');
                    $currentTitle = (string) ($intakeScreeningMeta['complaint_title'] ?? '');
                    $needsLisoClassification = strcasecmp(trim($currentOtherSpec), 'Pending Barangay LISO Classification') === 0;
                    $isCurrentOther = false;
                    $isCurrentSecOther = false;
                    $currentCatDisplayName = '';
                    $currentSecCatDisplayName = '';
                    foreach ($screeningCategories as $sc) {
                        $sname = (string) ($sc['category_name'] ?? '');
                        if ((int) $sc['category_id'] === $currentCatId) {
                            if (stripos($sname, 'Others') !== false || stripos($sname, 'Iba pa') !== false) {
                                $isCurrentOther = true;
                                $currentCatDisplayName = (!$needsLisoClassification && $currentOtherSpec !== '') ? $currentOtherSpec : '';
                            } elseif (!$needsLisoClassification) {
                                $currentCatDisplayName = $sname;
                            }
                        }
                        if ($currentSecCatId > 0 && (int) $sc['category_id'] === $currentSecCatId) {
                            if (stripos($sname, 'Others') !== false || stripos($sname, 'Iba pa') !== false) {
                                $isCurrentSecOther = true;
                                $currentSecCatDisplayName = $currentSecOtherSpec !== '' ? $currentSecOtherSpec : $sname;
                            } else {
                                $currentSecCatDisplayName = $sname;
                            }
                        }
                    }
                    $lisoImpMax = (float) ($screeningRow['offense_imprisonment_max'] ?? 0);
                    $lisoSelectedImp = $lisoImpMax > 1.0 ? '2' : ($lisoImpMax > 0.5 ? '1' : ($lisoImpMax > 0.0 ? '0.5' : '0'));
                    $lisoNoPriv = array_key_exists('has_private_offended_party', $screeningRow) && empty($screeningRow['has_private_offended_party']);
                    $lisoDetained = !empty($screeningRow['accused_under_detention']);
                    $lisoHabeas = !empty($screeningRow['habeas_corpus_needed']);
                    $lisoPresc = !empty($screeningRow['prescription_risk']);
                ?>
                <section id="intake-screening" class="sophos-card" aria-labelledby="intake-screening-title" style="margin-bottom: 18px; border: 1.5px solid #cde2d6; background: linear-gradient(135deg, #ffffff 0%, #f7fbf9 100%);">
                    <div class="sophos-card-header" style="border-bottom: 1px solid #e2ece7; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h2 id="intake-screening-title" style="margin: 0; font-size: 1rem; font-weight: 800; color: #102b2d;">LISO Preliminary Screening &amp; Legal Classification</h2>
                            <p style="margin: 2px 0 0; font-size: 0.82rem; color: #475569;">Determine the official KP category, Civil/Criminal case type, and specific nature before marking this intake eligible.</p>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <button type="button" id="btnLisoAutoClassify" class="btn-outline-sm" onclick="runLisoAutoClassify(<?php echo $complaintId; ?>)" style="font-size: 0.76rem; padding: 5px 11px;">Auto-Classify from Salaysay</button>
                            <span style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; border-radius: 999px; padding: 4px 12px; font-size: 0.74rem; font-weight: 700;">Action Required</span>
                        </div>
                    </div>
                    <div class="sophos-card-body" style="padding: 16px 20px;">
                        <?php if ($needsLisoClassification): ?>
                            <div style="margin-bottom: 14px; padding: 10px 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; font-size: 0.82rem; color: #1e3a8a;">
                                <strong>Resident Online Filing:</strong> Legal classification is pending Lupon determination. Select or type the official category and Civil/Criminal type below before marking Eligible.
                            </div>
                        <?php endif; ?>

                        <datalist id="lisoScreeningCategoriesList">
                            <?php foreach ($screeningCategories as $sc): ?>
                                <option value="<?= htmlspecialchars((string) $sc['category_name']) ?>"
                                        data-category-id="<?= (int) $sc['category_id'] ?>"
                                        data-case-type="<?= htmlspecialchars((string) ($sc['default_case_type'] ?? 'Civil')) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 14px;">
                            <div class="form-group" style="margin: 0;">
                                <label for="lisoScreenCategoryInput" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 6px;">Complaint Category <span style="color:#dc2626;">*</span></label>
                                <div class="category-picker-wrap">
                                    <input type="text"
                                           id="lisoScreenCategoryInput"
                                           list="lisoScreeningCategoriesList"
                                           value="<?= htmlspecialchars($currentCatDisplayName) ?>"
                                           placeholder="Search KP category or type Other / Custom..."
                                           autocomplete="off"
                                           oninput="syncLisoScreenOtherInputs()"
                                           onchange="syncLisoScreenOtherInputs()"
                                           style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 12px; font-size: 0.88rem; color: #0f172a; background: #ffffff;">
                                </div>
                                <select id="lisoScreenCategoryId" onchange="syncLisoScreenOtherInputs()" style="display: none;">
                                    <option value="">Select official category...</option>
                                    <?php foreach ($screeningCategories as $sc): ?>
                                        <option value="<?= (int) $sc['category_id'] ?>"
                                                data-case-type="<?= htmlspecialchars((string) ($sc['default_case_type'] ?? 'Civil')) ?>"
                                                data-is-other="<?= !empty($sc['is_other']) ? '1' : '0' ?>"
                                                <?= ((int) $sc['category_id'] === $currentCatId && !$needsLisoClassification) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) $sc['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label for="lisoScreenCaseType" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 6px;">Case Type (Auto-Mapped) <span style="color:#dc2626;">*</span></label>
                                <select id="lisoScreenCaseType" onchange="syncLisoCriminalFields()" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 12px; font-size: 0.88rem; color: #0f172a; background: #ffffff;">
                                    <option value="Civil" <?= $currentCaseType === 'Civil' ? 'selected' : '' ?>>Civil</option>
                                    <option value="Criminal" <?= $currentCaseType === 'Criminal' ? 'selected' : '' ?>>Criminal</option>
                                </select>
                                <small id="lisoScreenCaseTypeHint" style="display: block; font-size: 0.72rem; color: #64748b; margin-top: 4px;">Auto-mapped from category (editable).</small>
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label for="lisoScreenSecondaryCategoryInput" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 6px;">2nd Complaint Category <span style="font-weight:500; color:#64748b;">(Optional)</span></label>
                                <div class="category-picker-wrap">
                                    <input type="text"
                                           id="lisoScreenSecondaryCategoryInput"
                                           list="lisoScreeningCategoriesList"
                                           value="<?= htmlspecialchars($currentSecCatDisplayName) ?>"
                                           placeholder="Search or type 2nd category (optional)..."
                                           autocomplete="off"
                                           oninput="syncLisoScreenOtherInputs()"
                                           onchange="syncLisoScreenOtherInputs()"
                                           style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 12px; font-size: 0.88rem; color: #0f172a; background: #ffffff;">
                                </div>
                                <select id="lisoScreenSecondaryCategoryId" onchange="syncLisoScreenOtherInputs()" style="display: none;">
                                    <option value="">None</option>
                                    <?php foreach ($screeningCategories as $sc): ?>
                                        <option value="<?= (int) $sc['category_id'] ?>"
                                                data-case-type="<?= htmlspecialchars((string) ($sc['default_case_type'] ?? 'Civil')) ?>"
                                                data-is-other="<?= !empty($sc['is_other']) ? '1' : '0' ?>"
                                                <?= ((int) $sc['category_id'] === $currentSecCatId) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) $sc['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div id="lisoScreenOtherWrap" class="form-group" style="margin-bottom: 14px; display: none;">
                            <label for="lisoScreenOtherSpec" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 6px;">Specify Category (Others) <span style="color:#dc2626;">*</span></label>
                            <input type="text" id="lisoScreenOtherSpec" maxlength="255" value="<?= htmlspecialchars($needsLisoClassification ? '' : $currentOtherSpec) ?>" placeholder="Specify the dispute category..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 12px; font-size: 0.88rem; color: #0f172a; background: #ffffff;">
                        </div>

                        <div id="lisoScreenSecondaryOtherWrap" class="form-group" style="margin-bottom: 14px; display: none;">
                            <label for="lisoScreenSecondaryOtherSpec" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 6px;">Specify 2nd Category (Others) <span style="color:#dc2626;">*</span></label>
                            <input type="text" id="lisoScreenSecondaryOtherSpec" maxlength="255" value="<?= htmlspecialchars($currentSecOtherSpec) ?>" placeholder="Specify the 2nd dispute category..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 12px; font-size: 0.88rem; color: #0f172a; background: #ffffff;">
                        </div>

                        <div class="form-group" style="margin-bottom: 14px;">
                            <label for="lisoScreeningDecisionNotes" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 6px;">Internal screening notes / rejection basis (required for rejection)</label>
                            <textarea id="lisoScreeningDecisionNotes" rows="3" maxlength="2000" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-size: 0.88rem; color: #0f172a; background: #ffffff; resize: vertical;"></textarea>
                        </div>
                        <?php if ($hasResidentAccount): ?>
                            <div class="form-group paanyaya-prep-fields">
                                <label for="lisoResidentRequest">Message to resident (required when requesting information)
                                    <textarea id="lisoResidentRequest" rows="3" maxlength="<?= ComplaintInformationService::REQUEST_LIMIT ?>"></textarea>
                                </label>
                            </div>
                        <?php endif; ?>
                        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <button type="button" id="btnMarkIntakeRejected" class="btn-case-status" onclick="reviewIntake(<?php echo $complaintId; ?>, 'Rejected')">Mark Ineligible / Reject</button>
                            <button type="button" id="btnIntakeNeedsInfo" class="btn-case-status" onclick="reviewIntake(<?php echo $complaintId; ?>, 'Needs Information')">Needs Information</button>
                            <button type="button" id="btnMarkIntakeEligible" class="btn-case-primary" onclick="reviewIntake(<?php echo $complaintId; ?>, 'Accepted')">Mark Intake Eligible</button>
                        </div>
                    </div>
                </section>
            <?php elseif ($roleId === 3 && ($intakeScreeningMeta['screening_decision'] ?? '') === 'Eligible'): ?>
                <div class="sophos-card" style="margin-bottom: 18px; border: 1.5px solid #bbf7d0; background: #f0fdf4; padding: 12px 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="display: inline-flex; width: 24px; height: 24px; border-radius: 50%; background: #16a34a; color: #ffffff; align-items: center; justify-content: center; font-weight: 800; font-size: 0.82rem;">✓</span>
                        <div>
                            <strong style="font-size: 0.9rem; color: #14532d; display: block;">Intake Marked Eligible</strong>
                            <span style="font-size: 0.8rem; color: #166534;">Preliminary screening is complete. This complaint is now routed to the Paanyaya stage.</span>
                        </div>
                    </div>
                    <span style="background: #dcfce7; color: #166534; border: 1px solid #86efac; border-radius: 999px; padding: 4px 12px; font-size: 0.74rem; font-weight: 700;">Screening Completed</span>
                </div>
            <?php endif; ?>

            <!-- Sophos-Style Hero Section (Light Mode) -->
            <div class="sophos-hero-card">
                <div class="sophos-hero-left">
                    <div class="sophos-hero-pills-row">
                        <span id="sophosHeroCaseNum" class="sophos-ref-pill">#CMP-2026-0000</span>
                        <span id="heroCaseTypeBadge" class="sophos-tag-select sophos-tag-type">
                            <span id="sophosCaseTypeText">CIVIL COMPLAINT</span>
                        </span>
                        <span id="heroComplaintStatusBadge" class="sophos-tag-select sophos-tag-status">
                            <span id="sophosStatusText">Under Review</span>
                        </span>
                        <!-- Hidden hook for legacy scripts if needed -->
                        <span id="heroDaysRemainingBadge" style="display: none;"><span id="heroDaysRemainingText"></span></span>
                    </div>
                    <h2 id="complaintTitle" class="sophos-hero-title">Loading case title...</h2>
                </div>
                <div class="sophos-hero-kpis">
                    <div class="sophos-kpi-col">
                        <span class="sophos-kpi-label">Assigned Officer</span>
                        <span id="sophosHeroOwner" class="sophos-kpi-val">Punong Barangay</span>
                    </div>
                    <div class="sophos-kpi-col">
                        <span class="sophos-kpi-label">Date Filed</span>
                        <span id="infoDateFiled" class="sophos-kpi-val">—</span>
                    </div>
                    <div class="sophos-kpi-col">
                        <span class="sophos-kpi-label">Hearing Date</span>
                        <span id="sophosHeroHearingDate" class="sophos-kpi-val">N/A</span>
                    </div>
                    <div class="sophos-kpi-col">
                        <span class="sophos-kpi-label">Last Updated</span>
                        <span id="sophosHeroLastUpdate" class="sophos-kpi-val">—</span>
                    </div>
                </div>
            </div>

            <!-- Sophos-Inspired 2-Column Asymmetric Layout Grid -->
            <div class="sophos-layout-grid">
                <!-- ================= LEFT COLUMN (~36% / 360px) ================= -->
                <div class="sophos-grid-left">
                    <!-- Left Card 1: Information -->
                    <div class="sophos-card">
                        <div class="sophos-card-header" style="display:flex; justify-content:space-between; align-items:center;">
                            <h3 class="sophos-card-title">Information</h3>
                            <span style="font-size:0.68rem; font-weight:700; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:2px 7px; border-radius:9999px;" title="Structured classification is isolated from dynamic hearing minutes">Metadata Protected</span>
                        </div>
                        <div class="sophos-card-body">
                            <div class="sophos-info-list">
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Tracking Ref</span>
                                    <span id="infoTrackingRef" class="sophos-info-val">—</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Case type</span>
                                    <span id="infoCaseType" class="sophos-info-val">Civil Complaint</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Category</span>
                                    <span id="infoCategory" class="sophos-info-val">—</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Origin / Intake</span>
                                    <span id="sophosInfoOrigin" class="sophos-info-val">Resident Portal (Online)</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Jurisdiction</span>
                                    <span class="sophos-info-val">Barangay Tumana</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Total Parties</span>
                                    <span class="sophos-info-val"><span id="partiesCount">0</span> involved</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Admin Status</span>
                                    <span id="infoStatus" class="sophos-info-val" style="color: #2563eb;">Loading...</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Left Card 2: Applicable KP Rules & Legal Basis (Mitre Tactics Style) -->
                    <div class="sophos-card">
                        <div class="sophos-card-header">
                            <h3 class="sophos-card-title">Applicable KP Rules &amp; Legal Basis</h3>
                        </div>
                        <div class="sophos-card-body">
                            <div class="sophos-legal-list">
                                <a href="https://www.officialgazette.gov.ph/1991/10/10/republic-act-no-7160/" target="_blank" rel="noopener noreferrer" class="sophos-legal-item" title="Subject matter jurisdiction of Katarungang Pambarangay">
                                    <div class="sophos-legal-left">
                                        <span class="sophos-legal-chevron">&rsaquo;</span>
                                        <span class="sophos-legal-code">Sec. 408</span>
                                        <span class="sophos-legal-desc">Subject Matter Jurisdiction</span>
                                    </div>
                                    <span class="sophos-legal-icon">&#8599;</span>
                                </a>
                                <a href="https://www.officialgazette.gov.ph/1991/10/10/republic-act-no-7160/" target="_blank" rel="noopener noreferrer" class="sophos-legal-item" title="Barangay venue and territorial residency requirements">
                                    <div class="sophos-legal-left">
                                        <span class="sophos-legal-chevron">&rsaquo;</span>
                                        <span class="sophos-legal-code">Sec. 409</span>
                                        <span class="sophos-legal-desc">Barangay Venue &amp; Residency</span>
                                    </div>
                                    <span class="sophos-legal-icon">&#8599;</span>
                                </a>
                                <a href="javascript:void(0)" onclick="switchCaseTab('tab-overview')" class="sophos-legal-item" title="Formal verified complaint requirement">
                                    <div class="sophos-legal-left">
                                        <span class="sophos-legal-chevron">&rsaquo;</span>
                                        <span class="sophos-legal-code">KP Form 7</span>
                                        <span class="sophos-legal-desc">Verified Formal Complaint</span>
                                    </div>
                                    <span class="sophos-legal-icon">&#8599;</span>
                                </a>
                                <a href="javascript:void(0)" onclick="switchCaseTab('tab-mediation')" class="sophos-legal-item" title="Punong Barangay 15-day mediation mandate">
                                    <div class="sophos-legal-left">
                                        <span class="sophos-legal-chevron">&rsaquo;</span>
                                        <span class="sophos-legal-code">Sec. 410</span>
                                        <span class="sophos-legal-desc">15-Day Mediation Procedure</span>
                                    </div>
                                    <span class="sophos-legal-icon">&#8599;</span>
                                </a>
                                <a href="javascript:void(0)" class="sophos-legal-item" title="Tolling of prescriptive period for criminal &amp; civil actions up to 60 days">
                                    <div class="sophos-legal-left">
                                        <span class="sophos-legal-chevron">&rsaquo;</span>
                                        <span class="sophos-legal-code">60-Day Tolling</span>
                                        <span class="sophos-legal-desc">Prescription Suspension</span>
                                    </div>
                                    <span class="sophos-legal-icon">&#8599;</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Left Card 3: Intake & Incident Location (Initial Detection Style) -->
                    <div class="sophos-card">
                        <div class="sophos-card-header">
                            <h3 class="sophos-card-title">Intake &amp; Incident Location</h3>
                        </div>
                        <div class="sophos-card-body">
                            <div class="sophos-loc-meta">
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Specific Location</span>
                                    <span id="locationAddressText" class="sophos-info-val">—</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Severity / Priority</span>
                                    <span class="sophos-badge-severity">Medium Priority</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Landmark</span>
                                    <span id="locationLandmarkText" class="sophos-info-val">None</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Source</span>
                                    <span class="sophos-info-val">Barangay Tumana LISO</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Incident Date &amp; Time</span>
                                    <span id="infoIncidentDate" class="sophos-info-val">—</span>
                                </div>
                                <div class="sophos-info-row">
                                    <span class="sophos-info-label">Coordinates</span>
                                    <span id="locationCoordinatesText" class="sophos-info-val" style="font-family: monospace; font-size: 0.8rem;">—</span>
                                </div>
                                <div class="sophos-info-row" id="locationDetailsRow" style="display:none;">
                                    <span class="sophos-info-label">Location Details</span>
                                    <span id="locationDetailsText" class="sophos-info-val">—</span>
                                </div>
                            </div>

                            <div id="complaintDetailMap" class="sophos-map-frame" aria-label="Incident Location Map"></div>
                            <div id="emptyMapPlaceholder" class="empty-map-state-frame" style="display:none; height: 160px;">
                                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <p style="margin: 6px 0 2px; font-weight: 600; font-size: 0.84rem; color: #64748b;">No GPS coordinates pinned</p>
                                <?php if (!$isLuponReadOnly): ?>
                                <span style="font-size: 0.76rem; color: #94a3b8;">Can be updated anytime via Edit Case</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= RIGHT COLUMN (~64%) ================= -->
                <div class="sophos-grid-right">
                    <!-- Right Card 1: Summary -->
                    <div class="sophos-card">
                        <div class="sophos-card-header">
                            <h3 class="sophos-card-title">Summary</h3>
                            <div class="sophos-header-actions">
                                <button type="button" class="sophos-btn-action" onclick="copyComplaintNarrative()" title="Copy narrative statement to clipboard">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                    <span>Copy Narrative</span>
                                </button>
                                <button type="button" class="sophos-btn-action" onclick="document.getElementById('aiBriefBoxWrap').scrollIntoView({behavior: 'smooth'})" title="AI Case Brief Section" style="color: #6d28d9; border-color: #c4b5fd; background: #faf5ff;">
                                    <span>✨ AI Brief</span>
                                </button>
                            </div>
                        </div>
                        <div class="sophos-card-body">
                            <span style="font-size: 0.74rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 6px;">Statement of Complaint</span>
                            <div id="complaintNarrative" class="sophos-narrative-box">Loading narrative statement...</div>

                            <div id="complaintAdditionalDetailsWrap" style="display:none; margin-top: 12px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
                                <strong style="font-size: 0.78rem; color: #64748b; text-transform: uppercase;">Additional Facts / Prior Attempts:</strong>
                                <p id="complaintAdditionalDetails" style="margin: 4px 0 0; font-size: 0.88rem; color: #1e293b;"></p>
                            </div>

                            <div id="complaintRequestedResolutionWrap" style="display:none; margin-top: 12px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
                                <strong style="font-size: 0.78rem; color: #64748b; text-transform: uppercase;">Requested Resolution / Relief Sought:</strong>
                                <p id="complaintRequestedResolution" style="margin: 4px 0 0; font-size: 0.88rem; color: #1e293b;"></p>
                            </div>

                            <div id="complaintAiSuggestionCallout" style="display:none; margin-top: 12px; padding: 10px 12px; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe;"></div>

                            <!-- Integrated AI Brief Panel -->
                            <div id="aiBriefBoxWrap" style="margin-top: 14px; padding: 12px 14px; border-radius: 8px; background: linear-gradient(135deg, #fcfbff 0%, #f8f7ff 100%); border: 1px solid #ddd6fe;">
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                    <div>
                                        <strong style="font-size: 0.82rem; color: #4c1d95;">✨ AI-Assisted Factual Case Brief</strong>
                                        <p style="margin: 1px 0 0; font-size: 0.76rem; color: #64748b;">Advisory summary generated on request; decisions remain with Lupon officers.</p>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <label style="font-size: 0.74rem; color: #475569; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                            <input type="checkbox" id="aiBriefConsentCheck" style="accent-color: #7c3aed;"> Consent for Gemini
                                        </label>
                                        <button type="button" id="btnGenerateAiBrief" class="sophos-btn-action" onclick="generateAiCaseBrief()" style="color: #6d28d9; border-color: #a78bfa; background: #ffffff; font-weight: 700;">
                                            <span id="aiBriefBtnSpinner" style="display: none;">⏳</span>
                                            <span id="aiBriefBtnText">Generate Brief</span>
                                        </button>
                                    </div>
                                </div>
                                <div id="aiBriefOutputContainer" style="margin-top: 8px;">
                                    <p style="margin: 0; font-size: 0.8rem; color: #64748b;">Click 'Generate Brief' when reviewing case narrative and relief sought.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Card 2: Incident Particulars & Execution Specs (Command Line Style) -->
                    <div class="sophos-card">
                        <div class="sophos-card-header">
                            <h3 class="sophos-card-title">Incident Particulars &amp; Execution Specs</h3>
                            <button type="button" class="sophos-btn-action" onclick="copyExecutionSpecs()" title="Copy execution parameters">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                <span>Copy Specs</span>
                            </button>
                        </div>
                        <div class="sophos-card-body" style="padding: 12px 16px;">
                            <div id="sophosCommandLineBox" class="sophos-cmd-box">
                                <span class="sophos-cmd-param">--case-number</span>=<span class="sophos-cmd-val">"CMP-2026-0000"</span> \<br>
                                <span class="sophos-cmd-param">--jurisdiction</span>=<span class="sophos-cmd-val">"Barangay Tumana, District II, Marikina City (RA 7160 Sec. 408)"</span> \<br>
                                <span class="sophos-cmd-param">--incident-location</span>=<span class="sophos-cmd-val">"Sitio Olandes, Purok 3"</span> \<br>
                                <span class="sophos-cmd-param">--relief-sought</span>=<span class="sophos-cmd-val">"Amicable settlement and boundary restitution"</span> \<br>
                                <span class="sophos-cmd-param">--prescriptive-window</span>=<span class="sophos-cmd-val">"60 calendar days statutory tolling (Sec. 410 R.A. 7160)"</span> \<br>
                                <span class="sophos-cmd-param">--statutory-condition</span>=<span class="sophos-cmd-val">"Condition precedent to court filing (Sec. 412 R.A. 7160)"</span> \<br>
                                <span class="sophos-cmd-param">--presiding-officer</span>=<span class="sophos-cmd-val">"Punong Barangay / Lupon Chairman"</span>
                            </div>
                        </div>
                    </div>

                    <!-- Right Card 3: Involved Parties (Impacted Entities Style) -->
                    <div class="sophos-card">
                        <div class="sophos-card-header">
                            <h3 class="sophos-card-title">Involved Parties (<span id="partiesCountHead">0</span>)</h3>
                            <div class="sophos-header-actions">
                                <span id="partiesCountBadge" class="sophos-ref-pill" style="font-size: 0.74rem;">0 Parties</span>
                                <button type="button" class="sophos-btn-action" onclick="togglePartiesFilter()" title="Filter by party role">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                                    <span>Filter</span>
                                </button>
                            </div>
                        </div>
                        <div class="sophos-card-body" style="padding: 0;">
                            <div class="sophos-table-wrap">
                                <table class="sophos-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 44px; text-align: center;">N°</th>
                                            <th style="width: 140px;">ROLE / TYPE</th>
                                            <th>NAME</th>
                                            <th>RESIDENCY &amp; CONTACT</th>
                                            <th style="width: 110px;">STATUS</th>
                                        </tr>
                                    </thead>
                                    <tbody id="partiesTable">
                                        <tr>
                                            <td colspan="5" style="text-align: center; padding: 20px; color: #64748b;">
                                                Loading involved parties...
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Hidden backwards compatibility container -->
                            <div id="partiesListContainer" style="display: none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Operational Modules: Case Team & Hearings Schedule Grid -->
            <div class="operational-two-col" id="operationalModulesGrid" <?php echo ($isLuponReadOnly && $isUndocketedIntake) ? 'style="display: none;"' : ''; ?>>
                <!-- Card: Case Team Assignment -->
                <div class="operational-card">
                    <div class="operational-card-header">
                        <div>
                            <h3>Case Team Assignment</h3>
                            <span class="card-subtitle">Assigned Lupon officers for this case</span>
                        </div>
                        <?php if (!$isLuponReadOnly): ?>
                        <a href="../cases/case-list.php#caseAssignments" id="cwManageTeamLink" class="btn-small-link">
                            Manage Team &rarr;
                        </a>
                        <?php endif; ?>
                    </div>
                    <div id="cwTeamContainer" class="parties-list-wrap">
                        <div class="empty-detail-state">Loading assigned case team...</div>
                    </div>
                </div>

                <!-- Card: Hearings & Deadlines Schedule -->
                <div class="operational-card">
                    <div class="operational-card-header">
                        <div>
                            <h3>Hearings &amp; Deadlines Schedule</h3>
                            <span class="card-subtitle">Mediation, conciliation, and session records</span>
                        </div>
                        <?php if (!$isLuponReadOnly): ?>
                        <a id="cwOpenHearingsLink" href="#" class="btn-small-link" style="display: none;">
                            Open Schedule &rarr;
                        </a>
                        <?php endif; ?>
                    </div>
                    <div id="cwHearingsContainer">
                        <div class="empty-detail-state">Loading scheduled hearings...</div>
                    </div>
                </div>
            </div>

            <!-- OFFICIAL KP FORMS Card -->
            <div class="kp-forms-card-box" id="officialKpFormsCardBox" <?php echo ($isLuponReadOnly && $isUndocketedIntake) ? 'style="display: none;"' : ''; ?>>
                <div class="kp-forms-header">
                    <div>
                        <h3 style="margin: 0;">OFFICIAL KP FORMS</h3>
                        <span class="card-subtitle" style="font-size: 0.82rem; color: #64748b; margin-top: 2px; display: block;">Issued Katarungang Pambarangay forms and legal notices</span>
                    </div>
                    <span id="heroKpDocCountBadge" class="badge-issued-chip">0 Issued</span>
                </div>
                <div id="heroKpFormsList" class="kp-forms-list-container">
                    <div class="empty-detail-state">Loading issued KP forms...</div>
                </div>
            </div>

        </div>
        <!-- ===================================================================
             TAB PANE 1B: PAANYAYA (Stage 0 Pre-Docketing & Dialogue)
             =================================================================== -->
        <div id="tab-paanyaya" class="case-tab-pane <?php echo $activeTab === 'tab-paanyaya' ? 'active' : ''; ?>">
            <?php if ($preparation || $preparationFlash): ?>
                <div id="paanyaya-preparation">
                    <?php
                    require_once __DIR__ . '/../../../backend/services/PaanyayaServiceDelivery.php';
                    $delivery = new PaanyayaServiceDelivery($preparationDb);
                    $minutesModel = new Paanyaya($preparationDb);
                    $intakeFlash = $preparationFlash;
                    if (!$paanyayaInvitations) {
                        $paanyayaInvitations[1] = null;
                    }
                    foreach ($paanyayaInvitations as $invitationNumber => $invitationRecord) {
                        $preparation = $invitationRecord;
                        $preparationFlash = (int) ($intakeFlash['invitation_number'] ?? 1) === $invitationNumber ? $intakeFlash : null;
                        $preparationParties = [];
                        $paanyayaServiceHistory = [];
                        $latestService = null;
                        $paanyayaServers = [];
                        $paanyayaServiceUnavailable = false;
                        $paanyayaMinutes = null;
                        try {
                            if ($preparation) {
                                $preparationParties = $preparation['ready_at']
                                    ? json_decode($preparation['verified_parties'], true, 512, JSON_THROW_ON_ERROR)
                                    : $preparationService->parties($complaintId);
                                if ($preparation['prepared_at']) {
                                    $paanyayaServiceHistory = $delivery->history($complaintId, $invitationNumber);
                                    $latestService = PaanyayaServiceDelivery::latestAttempt($preparationDb, $complaintId, $invitationNumber);
                                    $latestOutcome = $latestService['outcome'] ?? null;
                                    if ($preparation['eligible'] && $preparation['ready_at'] && (!$latestService || $latestOutcome === 'Not Delivered')) {
                                        $paanyayaServers = $delivery->servers();
                                    }
                                    if ($latestOutcome === 'Delivered') {
                                        $paanyayaMinutes = $minutesModel->getStageMinutes($complaintId, 'Paanyaya ' . $invitationNumber);
                                    }
                                }
                            }
                        } catch (Throwable $exception) {
                            error_log('Paanyaya service workspace: ' . $exception->getMessage());
                            $paanyayaServiceUnavailable = true;
                        }
                        include __DIR__ . '/tabs/paanyaya-preparation.php';
                    }
                    ?>
                </div>
            <?php elseif (!$isLuponReadOnly): ?>
                <div class="sophos-card" style="margin: 0 30px 24px; padding: 24px;">
                    <h3 style="margin: 0 0 8px; font-size: 1.05rem; color: #0f172a;">Stage 0 &middot; Paanyaya Workspace</h3>
                    <p style="margin: 0 0 14px; font-size: 0.9rem; color: #475569;">
                        This complaint is outside the prepared Paanyaya workflow (it may be awaiting LISO screening or already docketed directly for formal mediation).
                    </p>
                    <a href="../paanyaya/stage.php?complaint_id=<?php echo $complaintId; ?>" class="btn-case-primary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        Open Legacy Paanyaya Stage &rarr;
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <!-- ===================================================================
             TAB PANE 2: MEDIATION
             =================================================================== -->
        <div id="tab-mediation" class="case-tab-pane <?php echo $activeTab === 'tab-mediation' ? 'active' : ''; ?>">
            <!-- Punong Barangay Mediation Stage Card / Banner -->
            <div style="margin: 0 30px 20px;">
                <div id="stage1Card" class="stage-card-item active">
                    <div class="stage-card-top">
                        <span class="stage-label-num">STAGE 1 &bull; KATARUNGANG PAMBARANGAY</span>
                        <span id="stage1Icon" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,0.25); font-size: 0.75rem;">1</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px;">Punong Barangay Mediation</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span id="stage1StatusText" class="stage-sub-status" style="font-weight: 600;">In Progress</span>
                        <span style="font-size: 0.78rem; opacity: 0.9;">Presiding: Punong Barangay</span>
                    </div>
                </div>
            </div>

            <!-- Inherited Salaysay & Classification Card for Punong Barangay -->
            <?php
                $embInitCaseType = (($intakeScreeningMeta['case_type'] ?? 'Civil') === 'Criminal') ? 'Criminal' : 'Civil';
                $embInitCat = trim((string) ($intakeScreeningMeta['category_name'] ?? 'Unclassified Dispute'));
                if (!empty($intakeScreeningMeta['other_category_specification']) && (stripos($embInitCat, 'Others') !== false || stripos($embInitCat, 'Iba pa') !== false)) {
                    $embInitCat = 'Others: ' . trim((string) $intakeScreeningMeta['other_category_specification']);
                }
                $embInitNarrative = trim((string) ($intakeScreeningMeta['narrative'] ?? ''));
            ?>
            <div class="stage-section-card" id="embeddedMediationSalaysayCard" style="margin: 0 30px 20px;">
                <div class="stage-section-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h3>Statement of Complaint (Salaysay) &amp; Case Classification</h3>
                        <p>Inherited directly from initial intake / Paanyaya — read-only for Punong Barangay review (session minutes remain separate below).</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span id="embeddedMedCaseTypeBadge" class="agap-pill-badge <?php echo $embInitCaseType === 'Criminal' ? 'is-red' : 'is-blue'; ?>" style="<?php echo $embInitCaseType === 'Criminal' ? 'background:#fef2f2; color:#b91c1c; border-color:#fecaca;' : 'background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe;'; ?>"><?php echo htmlspecialchars($embInitCaseType, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span id="embeddedMedCategoryText" style="font-size: 0.8rem; font-weight: 700; color: #334155;"><?php echo htmlspecialchars($embInitCat !== '' ? $embInitCat : '—', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div style="padding: 16px 20px;">
                    <div id="embeddedMedSalaysayText" class="sophos-narrative-box" style="margin: 0; white-space: pre-wrap;"><?php echo htmlspecialchars($embInitNarrative !== '' ? $embInitNarrative : 'No Salaysay narrative recorded.', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>

                        <!-- Embedded Mediation Hearing Sessions Management -->
            <div class="stage-section-card" id="mediationSessionsSection" style="margin: 0 30px 24px;">
                <div class="stage-section-header">
                    <div>
                        <h3>Mediation Hearing Sessions</h3>
                        <p>Scheduled sessions, party appearances, service verification, and minutes status</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span id="mediationSessionsCountBadge" style="font-size: 0.78rem; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">0 sessions</span>
                        <?php if (!$isLuponReadOnly): ?>
                        <button type="button" class="btn-create" onclick="openEmbeddedScheduleModal('Mediation')" style="font-size: 0.78rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 4px;">
                            + Schedule Session
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="mediationSessionsListContainer" style="padding: 18px 20px;">
                    <div class="empty-detail-state">Loading mediation hearing sessions...</div>
                </div>
                
            </div>
        </div>

        <!-- ===================================================================
             TAB PANE 3: CONCILIATION
             =================================================================== -->
        <div id="tab-conciliation" class="case-tab-pane <?php echo $activeTab === 'tab-conciliation' ? 'active' : ''; ?>">
            <!-- Pangkat Conciliation Stage Card / Banner -->
            <div style="margin: 0 30px 20px;">
                <div id="stage2Card" class="stage-card-item locked">
                    <div class="stage-card-top">
                        <span class="stage-label-num">STAGE 2 &bull; KATARUNGANG PAMBARANGAY</span>
                        <span id="stage2Icon" style="display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem;">2</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px;">Pangkat Conciliation</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span id="stage2StatusText" class="stage-sub-status" style="font-weight: 600;">Locked until mediation concludes</span>
                        <span style="font-size: 0.78rem; opacity: 0.9;">Pangkat ng Tagapagkasundo &bull; 3-member Lupon panel (RA 7160 Sec. 410c)</span>
                    </div>
                </div>
            </div>

                        <!-- Embedded Pangkat Conciliation Hearing Sessions Management -->
            <div class="stage-section-card" id="conciliationSessionsSection" style="margin: 0 30px 24px;">
                <div class="stage-section-header">
                    <div>
                        <h3>Pangkat Conciliation Hearing Sessions</h3>
                        <p>Pangkat ng Tagapagkasundo &bull; Scheduled conciliation sessions, party appearances, and amicable settlement records</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span id="conciliationSessionsCountBadge" style="font-size: 0.78rem; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">0 sessions</span>
                        <?php if (!$isLuponReadOnly): ?>
                        <button type="button" class="btn-create" id="btnSchedConciliation" onclick="openEmbeddedScheduleModal('Conciliation')" style="font-size: 0.78rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 4px;">
                            + Schedule Conciliation Session
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="conciliationSessionsListContainer" style="padding: 18px 20px;">
                    <div class="empty-detail-state">Loading conciliation hearing sessions...</div>
                </div>
                <div style="padding: 14px 20px; border-top: 1px solid #f1f5f9; background: #fafafa; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span style="font-size: 0.82rem; color: #64748b;">
                        Pangkat is constituted from Lupon members chosen by the parties (KP Form 10/11) to conduct formal conciliation within 15 days.
                    </span>
                    <?php if (!$isLuponReadOnly): ?>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openEmbeddedRecordOutcomeModal('Conciliation')" style="font-size: 0.82rem; padding: 6px 12px;">
                            Record Conciliation Outcome
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===================================================================
             TAB PANE 4: ARBITRATION / OUTCOMES / AMICABLE SETTLEMENT
             =================================================================== -->
        <div id="tab-arbitration" class="case-tab-pane <?php echo $activeTab === 'tab-arbitration' ? 'active' : ''; ?>">
            
            <!-- A. AMICABLE SETTLEMENT DUAL PREVIEW WORKSPACE -->
            <div id="amicableSettlementWorkspace" style="display: none;">
                <!-- Header Banner -->
                <div style="margin: 0 30px 20px;">
                    <div id="stageSettledCard" class="stage-card-item active" style="background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 1.5px solid #86efac; border-radius: 12px; padding: 18px 22px; box-shadow: 0 2px 8px rgba(22, 163, 74, 0.08);">
                        <div class="stage-card-top" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <h3 id="stageSettledTitle" style="font-size: 1.2rem; margin: 0; color: #064e3b; font-weight: 800;">
                                    Amicable Settlement (Kasunduan)
                                </h3>
                                <span id="stageSettledBadge" class="status-pill" style="background: #dcfce7; color: #166534; font-weight: 800; font-size: 0.78rem; padding: 3px 10px; border-radius: 9999px;">
                                    ✓ Amicably Settled
                                </span>
                            </div>
                        </div>

                        <!-- Statutory Metric Cards -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-top: 10px;">
                            <div style="background: #ffffff; padding: 10px 14px; border-radius: 8px; border: 1px solid #bbf7d0;">
                                <span style="font-size: 0.72rem; color: #15803d; text-transform: uppercase; font-weight: 700; display: block;">Execution Date</span>
                                <strong id="metricSettlementDate" style="font-size: 0.95rem; color: #0f172a;">—</strong>
                            </div>
                            <div style="background: #ffffff; padding: 10px 14px; border-radius: 8px; border: 1px solid #bbf7d0;">
                                <span style="font-size: 0.72rem; color: #15803d; text-transform: uppercase; font-weight: 700; display: block;">10-Working-Day Expiry</span>
                                <strong id="metricRepudiationDeadline" style="font-size: 0.95rem; color: #0f172a;">—</strong>
                            </div>
                            <div style="background: #ffffff; padding: 10px 14px; border-radius: 8px; border: 1px solid #bbf7d0;">
                                <span style="font-size: 0.72rem; color: #15803d; text-transform: uppercase; font-weight: 700; display: block;">Repudiation Status</span>
                                <div id="metricCountdownBadge" style="margin-top: 2px;">
                                    <span class="status-pill status-active" style="font-size: 0.8rem;">Calculating...</span>
                                </div>
                            </div>
                        </div>

                        <!-- Repudiation Alert Callout (Shown if case is repudiated) -->
                        <div id="calloutRepudiatedAlert" style="display: none; margin-top: 14px; background: #fef2f2; border: 1.5px solid #f87171; border-radius: 8px; padding: 12px 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <strong style="color: #991b1b; font-size: 0.92rem; display: flex; align-items: center; gap: 6px;">
                                        <span>⚠️</span> Sworn Statement of Repudiation Filed (KP Form 17)
                                    </strong>
                                    <p id="calloutRepudiatedNotes" style="margin: 4px 0 0; font-size: 0.82rem; color: #7f1d1d; line-height: 1.4;">
                                        Consent was vitiated. The settlement agreement is null and void.
                                    </p>
                                </div>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <button type="button" class="btn-secondary" onclick="openKpFormModal(window.currentCaseId || window.complaintData?.case_id || window.currentComplaintId || window.complaintData?.complaint_id, 'KP Form 17', null, window.currentComplaintId || window.complaintData?.complaint_id)" style="font-size: 0.8rem; padding: 5px 12px; border-color: #fca5a5; color: #991b1b;">
                                        View KP Form 17 &rarr;
                                    </button>
                                    <button type="button" id="btnCalloutIssueRepudiatedCfa" class="btn-create" onclick="window.location.href = '../documents/cfa.php?case_id=' + encodeURIComponent(window.currentCaseId || window.complaintData?.case_id || '')" style="font-size: 0.8rem; padding: 5px 14px; background: #dc2626; border-color: #dc2626;">
                                        Issue KP Form 20 (CFA) &rarr;
                                    </button>
                                </div>
                            </div>
                        </div>

                        <?php if (!$isLuponReadOnly): ?>
                        <!-- Top Action Buttons -->
                        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #bbf7d0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <button type="button" class="btn-secondary" onclick="openEditSettlementTermsModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #ffffff; border-color: #86efac; color: #166534; font-weight: 600;">
                                    ✏️ Edit Settlement Terms
                                </button>
                                <button type="button" id="btnActionFileRepudiation" class="btn-secondary" onclick="openFileRepudiationModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #ffffff; border-color: #fca5a5; color: #991b1b; font-weight: 600;">
                                    ⚠️ File Repudiation (KP Form 17)
                                </button>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <button type="button" id="btnActionIssueExecution" class="btn-secondary" onclick="openIssueExecutionModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #ffffff; border-color: #cbd5e1; color: #334155; font-weight: 600; display: none;">
                                    📜 Issue KP Form 23 (Motion for Execution)
                                </button>
                                <button type="button" id="btnActionRecordTransmittal" class="btn-create" onclick="openRecordTransmittalModal(window.currentCaseId || window.complaintData?.case_id)" style="font-size: 0.82rem; padding: 6px 14px; display: none;">
                                    📋 Record Court &amp; DILG Transmittal
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dual Document Preview Panels -->
                <div class="dual-doc-preview-grid" style="margin: 0 30px 30px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    
                    <!-- Card 1: KP Form No. 16 (Amicable Settlement) -->
                    <div class="dual-doc-card" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
                        <!-- Header -->
                        <div style="padding: 12px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <strong style="color: #0f172a; font-size: 0.95rem;">KP Form No. 16</strong>
                                    <span class="status-pill" style="background: #dcfce7; color: #166534; font-size: 0.72rem; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">
                                        ✓ Unlocked &amp; Printable
                                    </span>
                                </div>
                                <span style="font-size: 0.76rem; color: #64748b; display: block; margin-top: 1px;">
                                    Kasunduang Pag-aayos &bull; Executed Agreement
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <button type="button" class="btn-create" onclick="printKp16PreviewIframe()" style="font-size: 0.78rem; padding: 5px 12px; display: inline-flex; align-items: center; gap: 4px; background: #16a34a; border-color: #16a34a;">
                                    🖨️ Print Form 16
                                </button>
                                <a id="btnDownloadDualKp16Pdf" href="#" class="btn-secondary" download style="font-size: 0.78rem; padding: 5px 10px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;">
                                    📥 PDF
                                </a>
                                <button type="button" class="btn-secondary" onclick="openKpFormModal(window.currentCaseId || window.complaintData?.case_id || window.currentComplaintId || window.complaintData?.complaint_id, 'KP Form 16', null, window.currentComplaintId || window.complaintData?.complaint_id)" title="Fullscreen / Scale View" style="font-size: 0.78rem; padding: 5px 8px;">
                                    🔍
                                </button>
                            </div>
                        </div>

                        <!-- Document Canvas Container -->
                        <div style="background: #334155; padding: 16px 12px; height: 680px; overflow: hidden; display: flex; justify-content: center; align-items: stretch; position: relative;">
                            <iframe id="dualKp16PreviewIframe" src="about:blank" style="width: 100%; height: 100%; border: none; background: #ffffff; border-radius: 4px; box-shadow: 0 8px 30px rgba(0,0,0,0.35);" title="KP Form 16 Preview"></iframe>
                        </div>
                    </div>

                    <!-- Card 2: Court Transmittal Letter (MTC / MeTC & DILG) -->
                    <div class="dual-doc-card" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
                        <!-- Header -->
                        <div style="padding: 12px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <strong style="color: #0f172a; font-size: 0.95rem;">Court Transmittal Letter</strong>
                                    <span id="dualTransmittalStatusBadge" class="status-pill status-amber" style="background: #fef3c7; color: #b45309; font-size: 0.72rem; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">
                                        🔒 Locked for Printing
                                    </span>
                                </div>
                                <span style="font-size: 0.76rem; color: #64748b; display: block; margin-top: 1px;">
                                    RA 7160 Sec. 411 / Sec. 416 &bull; MTC &amp; DILG Transmittal
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <button type="button" id="btnDualPrintTransmittal" class="btn-create" onclick="printDualTransmittalIframe()" disabled style="font-size: 0.78rem; padding: 5px 12px; display: inline-flex; align-items: center; gap: 4px; opacity: 0.5; cursor: not-allowed;" title="Locked pursuant to RA 7160 Sec. 416 until repudiation period expires">
                                    🔒 Print Transmittal
                                </button>
                                <a id="btnDownloadDualTransmittalPdf" href="#" class="btn-secondary" style="font-size: 0.78rem; padding: 5px 10px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px; opacity: 0.5; pointer-events: none;" title="Download restricted until 10 working days elapse">
                                    📥 PDF
                                </a>
                                <button type="button" class="btn-secondary" onclick="openPreviewTransmittalModal(window.currentCaseId || window.complaintData?.case_id || window.currentComplaintId || window.complaintData?.complaint_id, window.currentComplaintId || window.complaintData?.complaint_id)" title="Fullscreen / Scale View" style="font-size: 0.78rem; padding: 5px 8px;">
                                    🔍
                                </button>
                            </div>
                        </div>

                        <!-- Document Canvas Container -->
                        <div style="background: #334155; padding: 16px 12px; height: 680px; overflow: hidden; display: flex; justify-content: center; align-items: stretch; position: relative;">
                            <iframe id="dualTransmittalPreviewIframe" src="about:blank" style="width: 100%; height: 100%; border: none; background: #ffffff; border-radius: 4px; box-shadow: 0 8px 30px rgba(0,0,0,0.35);" title="Transmittal Letter Preview"></iframe>
                        </div>
                    </div>

                </div>
            </div>

            <!-- B. VOLUNTARY ARBITRATION 4-STEP PROGRESSIVE WORKSPACE -->
            <div id="arbitrationProgressiveWorkspace" style="display: none;"></div>

            <!-- C. ARBITRATION / CFA WORKSPACE (Existing fallback when outcome != Settled and != Arbitration) -->
            <div id="arbitrationCfaWorkspace">
                <!-- Arbitration / Court Referral Stage Card / Banner -->
                <div style="margin: 0 30px 20px;">
                <div id="stage3Card" class="stage-card-item pending">
                    <div class="stage-card-top">
                        <span class="stage-label-num">STAGE 3 &bull; KATARUNGANG PAMBARANGAY</span>
                        <span id="stage3Icon" style="display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem;">3</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px;">Dispute Resolution Outcomes</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span id="stage3StatusText" class="stage-sub-status" style="font-weight: 600;">Pending</span>
                        <span style="font-size: 0.78rem; opacity: 0.9;">Binding Arbitration (RA 7160 Sec. 413) &bull; Certification to File Action (CFA)</span>
                    </div>
                </div>
            </div>

            <!-- Card: Arbitration / Court Referral State -->
            <div class="hearings-schedule-card">
                <div class="operational-card-header">
                    <div>
                        <h3 style="font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0;">Dispute Resolution &amp; Court Referral Outcomes</h3>
                        <span class="card-subtitle">Voluntary binding arbitration proceedings or formal elevation to court</span>
                    </div>
                    <span id="cwArbitrationStatusBadge" class="status-pill status-locked" style="font-size:0.75rem;">Pending</span>
                </div>
                <div id="cwArbitrationContainer" style="margin-top: 14px;">
                    <div class="empty-detail-state">Loading arbitration details...</div>
                </div>
                <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span style="font-size: 0.82rem; color: #64748b;">
                        Under Sec. 413 of RA 7160, parties may agree in writing to submit their dispute to binding arbitration at any stage of the proceedings.
                    </span>
                    <a id="cwManageArbitrationLink" href="#" class="btn-case-outline" style="font-size: 0.84rem; padding: 6px 14px;">
                        Open Arbitration Workspace &rarr;
                    </a>
                </div>
            </div>

            <!-- Statutory Pathways: Arbitration vs Court Referral Card -->
            <div class="operational-two-col" style="margin: 0 30px 24px;">
                <div class="operational-card">
                    <div class="operational-card-header">
                        <div>
                            <h3 style="font-size: 0.92rem; font-weight: 800; color: #0f172a; margin: 0;">Binding Arbitration (Sec. 413)</h3>
                            <span class="card-subtitle">Voluntary submission by written agreement</span>
                        </div>
                    </div>
                    <div style="padding: 16px 20px; font-size: 0.84rem; color: #475569; line-height: 1.55;">
                        <p style="margin: 0 0 8px;">Parties may at any stage agree in writing (<strong>KP Form 14</strong>) that the Punong Barangay or Pangkat shall arbitrate the dispute.</p>
                        <p style="margin: 0 0 8px;">An agreement to arbitrate may be repudiated within five (5) days from the date thereof on valid legal grounds.</p>
                        <p style="margin: 0;">The arbitration award (<strong>KP Form 15</strong>) is binding and has the force and effect of a final judgment of a court after 10 days.</p>
                    </div>
                </div>

                <div class="operational-card">
                    <div class="operational-card-header">
                        <div>
                            <h3 style="font-size: 0.92rem; font-weight: 800; color: #0f172a; margin: 0;">Certification to File Action (CFA)</h3>
                            <span class="card-subtitle">Prerequisite condition for court litigation</span>
                        </div>
                    </div>
                    <div style="padding: 16px 20px; font-size: 0.84rem; color: #475569; line-height: 1.55;">
                        <p style="margin: 0 0 8px;">No individual may go directly to court without a valid Certificate to File Action issued under Katarungang Pambarangay rules.</p>
                        <ul style="margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 4px;">
                            <li><strong>KP Form 20:</strong> Issued after confrontation before Punong Barangay and Pangkat fails.</li>
                            <li><strong>KP Form 21:</strong> Issued if Complainant fails/refuses to appear.</li>
                            <li><strong>KP Form 22:</strong> Issued if Respondent fails/refuses to appear.</li>
                        </ul>
                    </div>
                </div>
            </div>
            </div> <!-- close #arbitrationCfaWorkspace -->
        </div> <!-- close #tab-arbitration -->

        <!-- Hidden legacy compatibility elements (preserves existing JS selectors) -->
        <div id="caseWorkspaceSection" style="display:none;"></div>
        <div id="complaintInfo" style="display:none;"></div>
        <div id="complaintReviewNotes" style="display:none;"></div>
        <table style="display:none;"><tbody id="legacyPartiesTable"></tbody></table>
        <table style="display:none;"><tbody id="attachmentsTable"></tbody></table>
        <span id="complaintCaseTypeBadge" style="display:none;">Civil</span>
        <span id="complaintCategoryBadge" style="display:none;">Category</span>
        <span id="infoComplaintTitle" style="display:none;">—</span>
        <span id="infoCaseType" style="display:none;">—</span>
        <span id="infoIncidentTime" style="display:none;">—</span>
        <span id="infoDocketCase" style="display:none;">—</span>
        <span id="progressionActiveStageBadge" style="display:none;">Active Stage</span>
        <span id="cwDocCountBadge" style="display:none;">0 forms</span>
        <span id="tabDocsCountBadge" style="display:none;">0</span>
        <div id="cwDocumentsContainer" style="display:none;"></div>
        <span id="partiesFullCount" style="display:none;">0</span>
        <div id="partiesFullContainer" style="display:none;"></div>
        <span id="tab2LocationAddress" style="display:none;"></span>
        <span id="tab2LocationLandmark" style="display:none;"></span>
        <span id="tab2LocationCoords" style="display:none;"></span>
        <div id="tab2DetailMap" style="display:none;"></div>
        <div id="tab2ComplaintNarrative" style="display:none;"></div>
        <div hidden style="display:none !important;"><div id="tab2AdditionalDetailsWrap" style="display:none;"><div id="tab2AdditionalDetails"></div></div></div>
        <span id="attachmentsCount" style="display:none;">0</span>
        <div id="attachmentsListContainer" style="display:none;"></div>
        <div id="evidenceDropzone" style="display:none;"><input type="file" id="evidenceFileInput" style="display:none;"><div id="dropzoneUploadProgress" style="display:none;"></div></div>
        <input type="hidden" id="partyComplaintId" value="<?php echo $complaintId; ?>">
        <input type="hidden" id="attachmentComplaintId" value="<?php echo $complaintId; ?>">

    </div>

</div>

<!-- Issue Summon Modal -->
<div id="issueSummonModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px; max-height: calc(100vh - 40px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden;">
        <div class="modal-header" style="padding: 18px 24px 14px; margin-bottom: 0; border-bottom: 1px solid #f1f5f9; flex-shrink: 0;">
            <h2 id="issueSummonModalTitle" style="font-size: 1.15rem;">Issue Summon 1st Attempt &amp; Schedule Mediation</h2>
            <button type="button" class="close-btn" onclick="closeIssueSummonModal()">&times;</button>
        </div>
        <form id="issueSummonForm" onsubmit="submitIssueSummon(event)" style="display: flex; flex-direction: column; overflow: hidden; margin: 0; flex: 1 1 auto;">
            <div style="overflow-y: auto; padding: 20px 24px; flex: 1 1 auto; max-height: calc(100vh - 180px);">
                <input type="hidden" id="summonComplaintId" name="complaint_id" value="<?php echo $complaintId; ?>">
                <p style="margin: 0 0 16px; font-size: 0.88rem; color: #475569; line-height: 1.5;">
                    In accordance with Katarungang Pambarangay rules, issuing a summons sets the 1st Mediation hearing and automatically generates <strong>KP Form 8 (Notice of Hearing for Complainant)</strong> and <strong>KP Form 9 (Summons for Respondent)</strong> for service.
                </p>
                <div class="form-group" style="margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">
                        <label for="summonMediationDate" style="margin-bottom: 0; font-weight: 700; color: #1e293b;">
                            1st Mediation Date
                        </label>
                        <span id="summonWindowStatutoryBadge" style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">
                            15-Working-Day Window (RA 7160 Sec. 410)
                        </span>
                    </div>

                    <input type="date" id="summonMediationDate" name="mediation_date" min="<?= date('Y-m-d') ?>" required style="margin-bottom: 8px;">

                    <!-- Visual 15-Working-Day Calendar & Capacity Strip -->
                    <div id="summonVisualDatePickerContainer" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.78rem; flex-wrap: wrap; gap: 6px;">
                            <span id="summonVisualDeckTitle" style="font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em; font-size: 0.74rem;">
                                Available Mediation Days (Max 8 Hearings/Day):
                            </span>
                            <div style="display: flex; gap: 10px; font-size: 0.72rem; flex-wrap: wrap;">
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #166534;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e;"></span> Available</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #991b1b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444;"></span> 8/8 Full</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #64748b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #94a3b8;"></span> Outside 15d</span>
                            </div>
                        </div>

                        <!-- Scrollable Day Cards Grid -->
                        <div id="summonVisualDayGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 6px; max-height: 155px; overflow-y: auto; padding: 4px 2px;">
                            <div style="text-align: center; color: #64748b; font-size: 0.78rem; padding: 12px; grid-column: 1 / -1;">
                                Loading statutory 15-working-day availability &amp; capacity...
                            </div>
                        </div>
                        <div id="summonVisualDateStatusNotice" style="margin-top: 6px; font-size: 0.75rem; color: #64748b; line-height: 1.4;"></div>
                    </div>
                </div>

                <!-- Existing Mediations on selected date -->
                <div id="summonDaySchedulesWrap" style="display: none; margin-bottom: 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.84rem;">
                    <div style="font-weight: 600; color: #334155; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                        <span>Scheduled Mediations on this Date:</span>
                        <span id="summonDaySlotsCount" style="font-size: 0.72rem; background: #e2e8f0; color: #475569; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">0</span>
                    </div>
                    <div id="summonDaySlotsList" style="color: #64748b; font-size: 0.82rem; line-height: 1.5;">
                        No other mediations scheduled on this day.
                    </div>
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">
                        <label for="summonMediationTime" style="margin-bottom: 0; font-weight: 700; color: #1e293b;">1st Mediation Time</label>
                        <span style="font-size: 0.72rem; color: #0369a1; background: #e0f2fe; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">Select Available Time Slot</span>
                    </div>
                    <div id="summonTimeSlotsGrid" style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-bottom: 8px;"></div>
                    <input type="time" id="summonMediationTime" name="mediation_time" value="<?= htmlspecialchars($medHoursStart) ?>" min="<?= htmlspecialchars($medHoursStart) ?>" max="<?= htmlspecialchars($medHoursEnd) ?>" step="900" required>
                    <small id="summonMediationTimeHint" class="field-hint">Designated Mediation session window: <?= htmlspecialchars($medStartFmt) ?> – <?= htmlspecialchars($medEndFmt) ?> (<?= htmlspecialchars($medDaysLabel ?? 'Tuesdays') ?>). Standard duration: <?= (int) $medDefaultDuration ?> mins (max <?= (int) $medMaxDuration ?> mins).</small>
                </div>

                <!-- Conflict Alert Box -->
                <div id="summonScheduleConflictAlert" class="alert alert-danger" style="display: none; margin: 0 0 14px; padding: 10px 12px; font-size: 0.84rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; line-height: 1.4;">
                </div>

                <div class="form-group">
                    <label for="summonMediationVenue">Venue</label>
                    <input type="text" id="summonMediationVenue" name="venue" maxlength="255" value="<?= htmlspecialchars($medVenue) ?>" readonly required style="background: #f8fafc; cursor: not-allowed;">
                    <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Mediation sessions are conducted in <?= htmlspecialchars($medVenue) ?>.</small>
                </div>
                <div class="form-group" style="margin-bottom: 6px;">
                    <label for="summonMediationRemarks">Remarks <span class="optional-label">Optional</span></label>
                    <textarea id="summonMediationRemarks" name="remarks" maxlength="5000" rows="2" placeholder="Optional mediation instructions or notes..."></textarea>
                </div>
            </div>
            <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; flex-shrink: 0; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-secondary" onclick="closeIssueSummonModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitIssueSummonBtn">Issue Summon &amp; Schedule</button>
            </div>
            <p id="issueSummonMessage" role="status" style="margin: 0; padding: 0 24px 8px; font-size: 0.84rem;"></p>
        </form>
    </div>
</div>

<!-- Session & Process Details Modal (Mediation / Conciliation Details) -->
<div id="sessionProcessDetailsModal" class="modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="spModalTitle">
    <div class="modal-content" style="max-width: 840px; max-height: calc(100vh - 40px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
        <div class="modal-header" style="padding: 18px 24px 14px; margin-bottom: 0; border-bottom: 1px solid #f1f5f9; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; background: #ffffff;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <h2 id="spModalTitle" style="font-size: 1.2rem; margin: 0; color: #0f172a; font-weight: 800;">Session Details</h2>
                    <span id="spModalStatusBadge" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase;"></span>
                    <span id="spModalStageBadge" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase;"></span>
                </div>
                <div id="spModalSubtitle" style="font-size: 0.82rem; color: #64748b; margin-top: 4px;"></div>
            </div>
            <button type="button" class="close-btn" onclick="closeSessionProcessDetailsModal()" aria-label="Close modal" style="font-size: 1.5rem; border: none; background: transparent; cursor: pointer; color: #64748b; line-height: 1; padding: 4px 8px; border-radius: 6px;">&times;</button>
        </div>
        <div id="spModalBody" style="overflow-y: auto; padding: 20px 24px; flex: 1 1 auto; max-height: calc(100vh - 160px); display: flex; flex-direction: column; gap: 16px; background: #f8fafc;">
            <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                <p style="margin: 0;">Loading session and process details...</p>
            </div>
        </div>
        <div class="modal-actions" style="padding: 14px 24px; border-top: 1px solid #e2e8f0; background: #ffffff; margin-top: 0; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div id="spModalLeftActions" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn-secondary" onclick="closeSessionProcessDetailsModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Hearing session document drawer (minutes and supporting evidence) -->
<div id="sessionDocumentsDrawer" class="modal" style="display: none; z-index: 1100; align-items: center; justify-content: center; padding: 18px;" role="dialog" aria-modal="true" aria-labelledby="sessionDocumentsTitle">
    <div class="modal-content" style="width: min(680px, 100%); max-height: calc(100vh - 36px); display: flex; flex-direction: column; padding: 0; overflow: hidden; border-radius: 12px;">
        <div class="modal-header" style="padding: 18px 22px; margin: 0; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h2 id="sessionDocumentsTitle" style="margin: 0; font-size: 1.1rem; color: #0f172a;">Session Documents</h2>
                <p style="margin: 4px 0 0; color: #64748b; font-size: .82rem;">Minutes and supporting evidence for this hearing session.</p>
            </div>
            <button type="button" class="close-btn" onclick="closeSessionDocumentsDrawer()" aria-label="Close documents drawer">&times;</button>
        </div>
        <div style="padding: 18px 22px; overflow-y: auto;">
            <input type="hidden" id="sessionDocumentsHearingId">
            <input type="hidden" id="sessionDocumentsCaseId">
            <div class="session-documents-upload-box">
                <div>
                    <strong>Add document</strong>
                    <span>PDF, image, DOC, or DOCX — up to 10 MB each.</span>
                </div>
                <div class="session-documents-upload-actions">
                    <select id="sessionDocumentsType" aria-label="Document type">
                        <option value="handwritten_minutes">Minutes</option>
                        <option value="evidence_attachment">Evidence</option>
                    </select>
                    <input type="file" id="sessionDocumentsFileInput" accept="image/*,.pdf,.doc,.docx" multiple style="display: none;" onchange="uploadSessionDocuments(this)">
                    <button type="button" id="sessionDocumentsUploadBtn" class="btn-create" onclick="document.getElementById('sessionDocumentsFileInput').click()">Upload</button>
                </div>
            </div>
            <div id="sessionDocumentsList" class="session-documents-list"></div>
        </div>
        <div class="modal-actions" style="padding: 12px 22px; margin: 0; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end;">
            <button type="button" class="btn-secondary" onclick="closeSessionDocumentsDrawer()">Close</button>
        </div>
    </div>
</div>

<!-- Modal: Digital KP Form 7 Preview & Print -->
<div id="kp7DigitalPreviewModal" class="modal" style="display:none; padding: 12px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9999;" role="dialog" aria-modal="true" aria-labelledby="kp7ModalHeading">
    <div class="modal-content" style="max-width: 1350px; width: 96vw; height: calc(100vh - 24px); max-height: calc(100vh - 24px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 35px -5px rgba(0, 0, 0, 0.35); background: #ffffff;">
        <div class="modal-header" style="padding: 14px 24px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #ffffff; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="kp7ModalHeading" style="font-size: 1.15rem; margin: 0; color: #0f172a; font-weight: 700;">Pormularyo ng KP Blg. 7: Reklamo (Formal Complaint)</h2>
                    <p style="margin: 2px 0 0; font-size: 0.78rem; color: #64748b;">Barangay Tumana Lupong Tagapamayapa Official Katarungang Pambarangay Form</p>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeKp7DigitalPreviewModal()" aria-label="Close modal">&times;</button>
        </div>
        <div style="flex: 1 1 auto; overflow: hidden; background: #334155; min-height: 0; height: 100%; display: flex;">
            <iframe id="kp7PreviewIframe" src="" style="width: 100%; height: 100%; border: none; background: #334155;"></iframe>
        </div>
        <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-shrink: 0;">
            <div style="font-size: 0.82rem; color: #64748b;">
                Official Katarungang Pambarangay Complaint Document
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <a id="btnDownloadKp7Pdf" href="#" class="btn-pdf-chip" download style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; text-decoration: none; font-size: 0.85rem;">
                    <span>PDF</span>
                </a>
                <button type="button" class="btn-create" onclick="printKp7Iframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; font-weight: 600;">
                    Print Form
                </button>
                <button type="button" class="btn-secondary" onclick="closeKp7DigitalPreviewModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Official KP Form Document Preview & Print -->
<div id="kpDocumentPreviewModal" class="modal" style="display:none; padding: 12px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9999;" role="dialog" aria-modal="true" aria-labelledby="kpDocModalHeading">
    <div class="modal-content" style="max-width: 1350px; width: 96vw; height: calc(100vh - 24px); max-height: calc(100vh - 24px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 35px -5px rgba(0, 0, 0, 0.35); background: #ffffff;">
        <div class="modal-header" style="padding: 14px 24px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #ffffff; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span id="kpDocModalIcon" style="font-size: 1.3rem;"></span>
                <div>
                    <h2 id="kpDocModalHeading" style="font-size: 1.15rem; margin: 0; color: #0f172a; font-weight: 700;">KP Form Document Preview</h2>
                    <p id="kpDocModalSub" style="margin: 2px 0 0; font-size: 0.78rem; color: #64748b;">Barangay Tumana Lupong Tagapamayapa Official Katarungang Pambarangay Form</p>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" class="btn-create" onclick="printKpDocIframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; font-weight: 600; font-size: 0.85rem;">
                    Print Form
                </button>
                <a id="btnDownloadKpDocPdf" href="#" class="btn-pdf-chip" download style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; text-decoration: none; font-size: 0.85rem;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span>PDF</span>
                </a>
                <button type="button" class="close-btn" onclick="closeKpDocumentPreviewModal()" aria-label="Close modal">&times;</button>
            </div>
        </div>
        <div style="flex: 1 1 auto; overflow: hidden; background: #334155; min-height: 0; height: 100%; display: flex; position: relative;">
            <iframe id="kpDocPreviewIframe" src="" style="width: 100%; height: 100%; border: none; background: #334155;"></iframe>
        </div>
        <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-shrink: 0;">
            <div id="kpDocModalFooterInfo" style="font-size: 0.82rem; color: #64748b;">
                Official Katarungang Pambarangay Legal Document
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn-create" onclick="printKpDocIframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; font-weight: 600;">
                    Print Form
                </button>
                <button type="button" class="btn-secondary" onclick="closeKpDocumentPreviewModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Upload / Replace Handwritten KP Form 7 Scan -->
<div id="kp7UploadScanModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="uploadScanModalTitle">
    <div class="modal-content" style="max-width: 520px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <h2 id="uploadScanModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Upload Handwritten KP Form 7</h2>
            </div>
            <button type="button" class="close-btn" onclick="closeUploadScanModal()">&times;</button>
        </div>
        <form id="uploadScanForm" onsubmit="submitScanUpload(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <p style="font-size: 0.88rem; color: #475569; margin: 0 0 16px; line-height: 1.5;">
                Upload a scanned copy or clear photo of the physical handwritten KP Form 7. Accepted file formats: <strong>PDF, JPG, JPEG, PNG</strong> (max 25 MB).
            </p>
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="scanFileInput" style="font-weight: 600; font-size: 0.88rem;">Select Document File <span class="required-mark">*</span></label>
                <input type="file" id="scanFileInput" name="scan_file" accept=".pdf,image/jpeg,image/png" required style="padding: 8px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                <div id="scanFileDetails" style="display:none; font-size: 0.8rem; color: #059669; margin-top: 6px; font-weight: 600;"></div>
            </div>
            <div class="form-group" style="margin-bottom: 18px;">
                <label for="scanReplacementReason" style="font-weight: 600; font-size: 0.88rem;">Reason for Upload / Replacement <span class="optional-label">Optional</span></label>
                <input type="text" id="scanReplacementReason" name="replacement_reason" placeholder="e.g. Initial intake scan, corrected copy, signed document" style="padding: 8px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>
            <div id="scanUploadError" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>
            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeUploadScanModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitScanUpload">Upload Document</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: KP Form 7 Scan Version History -->
<div id="kp7ScanHistoryModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
    <div class="modal-content" style="max-width: 680px; max-height: calc(100vh - 60px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #ffffff;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <h2 id="historyModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">KP Form 7 Scan Version History</h2>
            </div>
            <button type="button" class="close-btn" onclick="closeScanHistoryModal()">&times;</button>
        </div>
        <div id="scanHistoryBody" style="overflow-y: auto; padding: 20px 24px; flex: 1 1 auto; max-height: 60vh;">
            <div style="text-align: center; color: #64748b; padding: 20px;">Loading version history...</div>
        </div>
        <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: flex-end;">
            <button type="button" class="btn-secondary" onclick="closeScanHistoryModal()">Close</button>
        </div>
    </div>
</div>

<!-- Modal 1: Paanyaya Document Preview Modal -->
<div id="paanyayaPreviewModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="paanyayaModalTitle">
    <div class="modal-content" style="max-width: 900px; width: 95%; height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <div class="modal-header" style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #ffffff;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="paanyayaModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Official Paanyaya (Invitation Letter) Preview</h2>

                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <button type="button" class="btn-sm btn-outline-sm" onclick="printPaanyayaIframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px;">
                    Print
                </button>
                <button type="button" class="close-btn" onclick="closePaanyayaPreviewModal()">&times;</button>
            </div>
        </div>
        <div style="flex: 1 1 auto; background: #475569; position: relative;">
            <iframe id="paanyayaPreviewIframe" src="about:blank" style="width: 100%; height: 100%; border: none; background: #475569;"></iframe>
        </div>
        <div class="modal-actions" style="padding: 12px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: flex-end; align-items: center; gap: 10px;">
                <button type="button" class="btn-secondary" onclick="closePaanyayaPreviewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Modal 2: Schedule / Update Paanyaya Modal -->
<div id="paanyayaScheduleModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="scheduleModalTitle">
    <div class="modal-content" style="max-width: 560px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <h2 id="scheduleModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Schedule Paanyaya Session</h2>
            </div>
            <button type="button" class="close-btn" onclick="closePaanyayaScheduleModal()">&times;</button>
        </div>
        <form id="paanyayaScheduleForm" onsubmit="submitSchedulePaanyaya(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <p style="font-size: 0.88rem; color: #475569; margin: 0 0 16px; line-height: 1.5;">
                Issue an official <strong>Paanyaya (Informal Invitation)</strong> for the parties to appear at the Barangay Hall for an informal dialogue before formal case docketing.
            </p>
            <div class="form-row-2" style="margin-bottom: 12px;">
                <div class="form-group">
                    <label for="modalPaanyayaDate" style="font-weight: 600; font-size: 0.85rem;">Dialogue Date <span class="required-mark">*</span></label>
                    <input type="date" id="modalPaanyayaDate" name="invitation_date" min="<?= date('Y-m-d') ?>" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
                <div class="form-group">
                    <label for="modalPaanyayaTime" style="font-weight: 600; font-size: 0.85rem;">Dialogue Time <span class="required-mark">*</span></label>
                    <input type="time" id="modalPaanyayaTime" name="invitation_time" min="08:00" max="17:00" value="14:00" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
            </div>
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="modalPaanyayaVenue" style="font-weight: 600; font-size: 0.85rem;">Venue</label>
                <input type="text" id="modalPaanyayaVenue" name="venue" value="Tanggapan ng Barangay Tumana" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="modalPaanyayaInvitee" style="font-weight: 600; font-size: 0.85rem;">Invitee Name (Person Being Invited)</label>
                <input type="text" id="modalPaanyayaInvitee" name="invitee_name" placeholder="Name of person being invited..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>
            <input type="hidden" id="modalPaanyayaHasSig" name="has_e_signature" value="1">
            <div id="paanyayaScheduleError" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>
            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closePaanyayaScheduleModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitPaanyayaSchedule" style="background: #16a34a; border-color: #16a34a;">Issue Paanyaya</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Paanyaya Hearing Outcome Modal -->
<div id="paanyayaOutcomeModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="outcomeModalTitle">
    <div class="modal-content" style="max-width: 580px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span id="outcomeModalIcon" style="font-size: 1.3rem;"></span>
                <h2 id="outcomeModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Record Paanyaya Outcome</h2>
            </div>
            <button type="button" class="close-btn" onclick="closePaanyayaOutcomeModal()">&times;</button>
        </div>
        <form id="paanyayaOutcomeForm" onsubmit="submitPaanyayaOutcome(event)">
            <input type="hidden" id="outcomePaanyayaId" name="paanyaya_id" value="">
            <input type="hidden" id="outcomeComplaintId" name="complaint_id" value="">
            <input type="hidden" id="outcomeActionType" name="outcome" value="Resolved">

            <div id="outcomeResolvedNotice" style="display:none; margin-bottom: 16px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 14px; font-size: 0.88rem; color: #166534;">
                <strong>Informal Amicable Resolution:</strong>
                <p style="margin: 4px 0 0;">Parties reached an understanding during informal dialogue. The complaint will be closed without starting formal statutory court deadlines.</p>
            </div>

            <div id="outcomeEscalateNotice" style="display:none; margin-bottom: 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; font-size: 0.88rem; color: #991b1b;">
                <strong>Escalate to Formal Docketing (KP Form 7 &amp; 9):</strong>
                <p style="margin: 4px 0 0;">Dialogue failed or respondent failed to attend. The system will assign an official Case Number, set stage to Mediation, and generate KP Form 7 Complaint and KP Form 9 Summons.</p>
            </div>

            <!-- Escalation 1st Mediation Hearing Picker (Visible ONLY on Escalate) -->
            <div id="escalationScheduleSection" style="display: none; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 6px;">
                    <h4 style="margin: 0; font-size: 0.92rem; color: #0f172a; font-weight: 700;">1st Formal Mediation Hearing Schedule</h4>
                    <span style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">
                        <?= htmlspecialchars($medDaysLabel) ?> · <?= htmlspecialchars($medVenue) ?> (Max <?= $dailyHearingCapacity ?>/Day)
                    </span>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="escalationMedDate" style="font-weight: 600; font-size: 0.82rem; margin: 0;">Mediation Date (<?= htmlspecialchars($medDaysLabel) ?>) <span class="required-mark">*</span></label>
                            <span id="escalationCapacityBadge" class="badge-capacity" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                                Checking...
                            </span>
                        </div>
                        <input type="date" id="escalationMedDate" name="mediation_date" min="<?= date('Y-m-d') ?>" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    </div>
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="escalationMedTime" style="font-weight: 600; font-size: 0.82rem; margin: 0;">Target Call Time (<?= date('g:i A', strtotime($medHoursStart)) ?> - <?= date('g:i A', strtotime($medHoursEnd)) ?>) <span class="required-mark">*</span></label>
                            <span style="font-size: 0.72rem; color: #64748b;">15-min intervals</span>
                        </div>
                        <input type="time" id="escalationMedTime" name="mediation_time" min="<?= $medHoursStart ?>" max="<?= $medHoursEnd ?>" step="900" value="<?= $medHoursStart ?>" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px;">
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='13:00'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:00 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='13:15'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:15 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='13:30'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:30 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='13:45'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:45 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='14:00'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:00 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='14:30'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:30 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='15:00'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">3:00 PM</button>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="margin-top: 8px;">
                    <label for="escalationMedVenue" style="font-weight: 600; font-size: 0.82rem;">Hearing Venue</label>
                    <input type="text" id="escalationMedVenue" name="venue" value="<?= htmlspecialchars($medVenue) ?>" readonly style="padding: 8px 10px; font-size: 0.88rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>

                <!-- Live Day Docket Peek -->
                <div id="escalationDocketPeek" style="margin-top: 10px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                    <div style="font-size: 0.78rem; font-weight: 700; color: #334155; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                        <span>📋 Mediation Docket Call (<?= htmlspecialchars($medVenue) ?>)</span>
                        <span id="escalationDocketCount" style="font-size: 0.72rem; color: #64748b;">0 sessions</span>
                    </div>
                    <div id="escalationDocketList" style="font-size: 0.8rem; color: #64748b; line-height: 1.4;">
                        Select a mediation date to peek existing docket calls.
                    </div>
                </div>

                <!-- Inline Overlap Conflict Alert -->
                <div id="escalationConflictAlert" class="alert alert-danger" style="display: none; margin-top: 10px; padding: 8px 12px; font-size: 0.82rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;"></div>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label for="outcomeNotes" style="font-weight: 600; font-size: 0.85rem;">Dialogue &amp; Proceedings Notes <span class="required-mark">*</span></label>
                <textarea id="outcomeNotes" name="resolution_notes" rows="3" required placeholder="Describe dialogue outcome, reasons for escalation or terms of informal settlement..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <div id="paanyayaOutcomeError" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closePaanyayaOutcomeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitPaanyayaOutcome">Confirm Outcome</button>
            </div>
        </form>
    </div>
</div>





<!-- ===================================================================
     MODALS FOR PAANYAYA 1 & 2 WORKFLOW & OFFICER'S RETURN TRACKING
     =================================================================== -->

<!-- Modal 1: Issue Paanyaya Stage Modal (Paanyaya 1 or Paanyaya 2) -->
<div id="issuePaanyayaStageModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="issueStageModalHeading">
    <div class="modal-content" style="max-width: 580px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="issueStageModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Issue Paanyaya 1</h2>
                    <span id="issueStageModalSub" style="font-size: 0.8rem; color: #64748b;">First Preliminary Invitation (Informal Dialogue)</span>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeIssuePaanyayaStageModal()">&times;</button>
        </div>
        <form id="issuePaanyayaStageForm" onsubmit="submitIssuePaanyayaStage(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <input type="hidden" id="issueStageType" name="stage_type" value="Paanyaya 1">

            <p style="font-size: 0.86rem; color: #475569; margin: 0 0 16px; line-height: 1.5;">
                Prepare and issue an official preliminary invitation letter to invite the respondent to an informal dialogue before formal case docketing.
            </p>

            <div class="form-row-2" style="margin-bottom: 12px;">
                <div class="form-group">
                    <label for="issueStageDate" style="font-weight: 600; font-size: 0.84rem;">Dialogue Date <span class="required-mark">*</span></label>
                    <input type="date" id="issueStageDate" name="invitation_date" min="<?= date('Y-m-d') ?>" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
                <div class="form-group">
                    <label for="issueStageTime" style="font-weight: 600; font-size: 0.84rem;">Dialogue Time <span class="required-mark">*</span></label>
                    <input type="time" id="issueStageTime" name="invitation_time" min="08:00" max="17:00" value="14:00" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="issueStageInvitee" style="font-weight: 600; font-size: 0.84rem;">Invitee Name (Person Being Invited)</label>
                <input type="text" id="issueStageInvitee" name="invitee_name" placeholder="Name of person being invited..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="issueStageVenue" style="font-weight: 600; font-size: 0.84rem;">Venue</label>
                <input type="text" id="issueStageVenue" name="venue" value="Tanggapan ng Barangay Tumana" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <input type="hidden" id="issueStageHasSig" name="has_e_signature" value="1">

            <div id="issueStageError" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeIssuePaanyayaStageModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitIssueStage" style="background: #16a34a; border-color: #16a34a;">Issue Invitation &amp; Generate Letter</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 5: Authorized Lupon Review Modal (Exhausted Attempts / Transition Override) -->
<div id="reviewPaanyayaModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="rpmModalHeading">
    <div class="modal-content" style="max-width: 580px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="rpmModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Authorized Lupon Review Decision</h2>
                    <span id="rpmModalSub" style="font-size: 0.8rem; color: #64748b;">Review for Paanyaya 1</span>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closePaanyayaReviewModal()">&times;</button>
        </div>

        <form id="reviewPaanyayaForm" onsubmit="submitPaanyayaReview(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <input type="hidden" id="rpmStageType" name="stage_type" value="Paanyaya 1">

            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 0.86rem; color: #991b1b; line-height: 1.45;">
                <strong>Safeguard Notice:</strong> All six (6) delivery attempts have been exhausted without successful service. The system does not automatically advance or issue summons. An authorized Lupon officer must review and decide the next procedural action.
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="rpmDecision" style="font-weight: 600; font-size: 0.84rem;">Authorized Review Determination <span class="required-mark">*</span></label>
                <select id="rpmDecision" name="decision" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    <option value="">-- Select Procedural Decision --</option>
                    <option value="Approved for Paanyaya 2">Authorize Paanyaya 2 (Proceed to Second Preliminary Invitation)</option>
                    <option value="Approved for Summons">Authorize Formal Summons (Escalate to 1st Mediation Summons)</option>
                    <option value="Case Closed">Close Case (Administratively Dismissed / Dropped)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label for="rpmReviewNotes" style="font-weight: 600; font-size: 0.84rem;">Review Notes &amp; Official Justification <span class="required-mark">*</span></label>
                <textarea id="rpmReviewNotes" name="review_notes" rows="3" required placeholder="State official reasons, review findings, and Lupon instructions for this decision..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <div id="rpmErrorBox" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closePaanyayaReviewModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitReview" style="background: #dc2626; border-color: #dc2626;">Confirm Decision</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 6: Proof of Service Lightbox Viewer -->
<div id="paanyayaProofLightboxModal" class="modal" style="display:none; z-index: 10000;" role="dialog" aria-modal="true" aria-labelledby="plbTitle">
    <div class="modal-content" style="max-width: 800px; padding: 20px; border-radius: 12px; background: #ffffff;">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 0; border: none;">
            <h3 id="plbTitle" style="margin: 0; font-size: 1.1rem; color: #0f172a; font-weight: 700;">Proof of Service Attachment</h3>
            <button type="button" class="close-btn" onclick="closePaanyayaProofLightbox()">&times;</button>
        </div>
        <div style="text-align: center; max-height: calc(85vh - 120px); overflow: auto; background: #0f172a; border-radius: 8px; padding: 10px;">
            <img id="plbImage" src="" alt="Proof of Service" style="max-width: 100%; max-height: 70vh; object-fit: contain; border-radius: 4px;">
            <div id="plbPdfContainer" style="display: none; padding: 20px; color: #ffffff;">
                <p>This proof is a PDF document.</p>
                <a id="plbPdfLink" href="#" target="_blank" class="btn-create" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;">
                    Open PDF in New Window
                </a>
            </div>
        </div>
        <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px;">
            <a id="plbDownloadBtn" href="#" download class="btn-case-outline" style="text-decoration: none;">Download File</a>
            <button type="button" class="btn-secondary" onclick="closePaanyayaProofLightbox()">Close</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../layouts/summons-preview-modal.php'; ?>

<!-- ========================================== -->
<!-- MODAL: Schedule Hearing Session (Mediation / Conciliation) -->
<!-- ========================================== -->
<div id="wsScheduleModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 580px; max-height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 10px; overflow: hidden;">
        <div class="modal-header" style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; background: #fff; margin-bottom: 0; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center;">
            <h2 id="wsSchedModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Schedule Hearing Session</h2>
            <button type="button" class="close-btn" onclick="closeEmbeddedScheduleModal()">&times;</button>
        </div>
        <form id="wsScheduleForm" onsubmit="submitEmbeddedSchedule(event)" style="display: flex; flex-direction: column; overflow: hidden; margin: 0;">
            <input type="hidden" id="schedHearingType" value="Mediation">
            <input type="hidden" id="schedComplaintId" value="<?php echo (int) $complaintId; ?>">
            <input type="hidden" id="schedCaseId" value="">
            <input type="hidden" id="schedExistingHearingId" value="">
            <div class="modal-body" style="padding: 18px 24px; overflow-y: auto; flex: 1;">
                <p id="wsSchedModalSubtitle" style="color: #64748b; font-size: 0.85rem; margin-top: 0; margin-bottom: 14px; line-height: 1.45;">
                    Enter the date and time to schedule the hearing session. Regular Lupon hearing hours are 8:00 AM to 5:00 PM (Monday to Friday).
                </p>

                <div class="form-group" style="margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">
                        <label for="schedDate" style="margin-bottom: 0; font-weight: 700; color: #1e293b;">
                            <span id="schedDateLabel">Hearing Date</span>
                        </label>
                        <span id="schedWindowStatutoryBadge" style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">
                            15-Working-Day Window (RA 7160 Sec. 410)
                        </span>
                    </div>

                    <input type="date" id="schedDate" name="hearing_date" min="<?= date('Y-m-d') ?>" required onchange="handleSchedDateChange(this.value)" style="margin-bottom: 8px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box; background: #fff;">

                    <!-- Visual 15-Working-Day Calendar & Capacity Strip -->
                    <div id="schedVisualDatePickerContainer" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.78rem; flex-wrap: wrap; gap: 6px;">
                            <span id="schedVisualDeckTitle" style="font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em; font-size: 0.74rem;">
                                AVAILABLE MEDIATION DAYS (<?= htmlspecialchars(strtoupper($medDaysLabel ?? 'TUESDAYS')) ?> ONLY):
                            </span>
                            <div style="display: flex; gap: 10px; font-size: 0.72rem; flex-wrap: wrap;">
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #166534;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e;"></span> Available</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #991b1b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444;"></span> 8/8 Full</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #64748b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #94a3b8;"></span> Outside 15d</span>
                            </div>
                        </div>

                        <!-- Scrollable Day Cards Grid -->
                        <div id="schedVisualDayGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 6px; max-height: 155px; overflow-y: auto; padding: 4px 2px;">
                            <div style="text-align: center; color: #64748b; font-size: 0.78rem; padding: 12px; grid-column: 1 / -1;">
                                Loading statutory 15-working-day availability &amp; capacity...
                            </div>
                        </div>
                        <div id="schedVisualDateStatusNotice" style="margin-top: 6px; font-size: 0.75rem; color: #64748b; line-height: 1.4;"></div>
                    </div>
                </div>

                <!-- Existing Sessions on selected date -->
                <div id="schedDaySchedulesWrap" style="display: none; margin-bottom: 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.84rem;">
                    <div style="font-weight: 600; color: #334155; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                        <span>Scheduled Hearings on this Date:</span>
                        <span id="schedDaySlotsCount" style="font-size: 0.72rem; background: #e2e8f0; color: #475569; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">0</span>
                    </div>
                    <div id="schedDaySlotsList" style="color: #64748b; font-size: 0.82rem; line-height: 1.5;">
                        No other hearings scheduled on this day.
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">
                        <label for="schedTime" style="margin-bottom: 0; font-weight: 700; color: #1e293b;"><span id="schedTimeLabel">Hearing Time</span></label>
                        <span style="font-size: 0.72rem; color: #0369a1; background: #e0f2fe; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">Select Available Time Slot</span>
                    </div>
                    <div id="schedTimeSlotsGrid" style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-bottom: 8px;"></div>
                    <input type="time" id="schedTime" name="hearing_time" value="<?= htmlspecialchars($medHoursStart) ?>" min="<?= htmlspecialchars($medHoursStart) ?>" max="<?= htmlspecialchars($medHoursEnd) ?>" step="900" required onchange="handleSchedTimeChange()" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box; background: #fff;">
                    <small id="schedTimeHint" class="field-hint" style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Designated Mediation session window: <?= htmlspecialchars($medStartFmt) ?> – <?= htmlspecialchars($medEndFmt) ?> (<?= htmlspecialchars($medDaysLabel ?? 'Tuesdays') ?>). Standard duration: <?= (int) $medDefaultDuration ?> mins (max <?= (int) $medMaxDuration ?> mins).</small>
                </div>

                <!-- Conflict Alert Box -->
                <div id="schedScheduleConflictAlert" class="alert alert-danger" style="display: none; margin: 0 0 14px; padding: 10px 12px; font-size: 0.84rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; line-height: 1.4;">
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="schedVenue">Venue</label>
                    <input type="text" id="schedVenue" name="venue" maxlength="255" value="<?= htmlspecialchars($medVenue) ?>" readonly required style="background: #f8fafc; cursor: not-allowed; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box;">
                    <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Hearing sessions are conducted in <?= htmlspecialchars($medVenue) ?>.</small>
                </div>

                <div class="form-group" style="margin-bottom: 6px;">
                    <label for="schedRemarks">Remarks <span class="optional-label">Optional</span></label>
                    <textarea id="schedRemarks" name="remarks" maxlength="5000" rows="2" placeholder="Optional hearing instructions or notes..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
                </div>

                <?php if ($canAuthorizePastDate): ?>
                <div class="form-group" style="margin-top: 10px;">
                    <label for="schedPastDateExceptionReason">Administrator past-date exception reason</label>
                    <textarea id="schedPastDateExceptionReason" rows="2" maxlength="2000" placeholder="Required only when scheduling a past date or time (10–2,000 characters)." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
                    <small style="color: #64748b; font-size: 0.75rem;">The reason is saved with the hearing and audit history.</small>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; flex-shrink: 0; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-secondary" onclick="closeEmbeddedScheduleModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitSchedBtn">Save Schedule</button>
            </div>
            <p id="schedMessage" role="status" style="margin: 0; padding: 0 24px 8px; font-size: 0.84rem;"></p>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Quick Hearing Session Attendance -->
<!-- ========================================== -->
<div id="wsAttendanceModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 650px; max-height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 10px; overflow: hidden;">
        <div class="modal-header" style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; background: #fff; margin-bottom: 0; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center;">
            <h2 id="wsAttModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Quick Attendance</h2>
            <button type="button" class="close-btn" onclick="closeEmbeddedAttendanceModal()">&times;</button>
        </div>
        <div id="wsAttModalBody" style="padding: 18px 24px; overflow-y: auto; flex: 1;">
            <div class="empty-detail-state">Loading attendance records...</div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Record Stage Procedural Outcome    -->
<!-- ========================================== -->
<div id="wsRecordOutcomeModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px; border-radius: 10px; padding: 24px;">
        <div class="modal-header" style="margin-bottom: 16px;">
            <h2 id="wsOutcomeModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Record Stage Outcome</h2>
            <button type="button" class="close-btn" onclick="closeEmbeddedRecordOutcomeModal()">&times;</button>
        </div>
        <form onsubmit="submitEmbeddedRecordOutcome(event)">
            <input type="hidden" id="stageOutcomeType" value="Mediation">
            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Select the formal procedural determination for this stage.
            </p>
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="stageOutcomeSelect" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Procedural Outcome <span class="required-mark" style="color: #dc2626;">*</span></label>
                <select id="stageOutcomeSelect" required onchange="handleEmbeddedStageOutcomeChange(this.value)" style="width: 100%; padding: 9px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem;">
                    <option value="Settled">Settled — Parties Reached Amicable Settlement (KP Form 16)</option>
 <option value="Arbitration">Arbitration — Parties Agreed to Binding Arbitration (KP Form 14)</option>
                    <option value="Unsuccessful">Unsuccessful — Impasse / Referred to Next Stage</option>
                    <option value="Dismissed">Dismissed — Complainant Wilfully Failed to Appear</option>
                    <option value="Repudiated">Repudiated — Settlement Repudiated by Party</option>
                </select>
            </div>
                        <div id="stageOutcomeArbitrationBanner" style="display: none; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
                <strong style="color: #92400e; font-size: 0.88rem; display: block;">Parties Agreed to Voluntary Arbitration (KP Form 14)</strong>
                <p style="margin: 4px 0 10px; font-size: 0.82rem; color: #78350f;">
                    Both parties have voluntarily agreed in writing to submit their dispute to binding arbitration under RA 7160 Sec. 413.
                </p>
                <button type="button" class="btn-create" onclick="submitEmbeddedRecordOutcome(event)" style="background: #d97706; border-color: #d97706; font-size: 0.82rem; padding: 6px 14px;">Issue Arbitration &rarr;</button>
            </div>
<div class="form-group" style="margin-bottom: 18px;">
                <label for="stageOutcomeRemarks" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Determination Notes / Remarks <span class="optional-label" style="color: #64748b; font-weight: 400;">Optional</span></label>
                <textarea id="stageOutcomeRemarks" rows="3" placeholder="Summary of agreements reached, reasons for failure, or basis of determination..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
            </div>
            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-secondary" onclick="closeEmbeddedRecordOutcomeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitOutcomeBtn">Confirm &amp; Record Outcome</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Refer to Pangkat Tagapagkasundo    -->
<!-- ========================================== -->
<div id="wsReferPangkatModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 620px; border-radius: 10px; padding: 24px;">
        <div class="modal-header" style="margin-bottom: 16px;">
            <h2 style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Refer Case to Pangkat ng Tagapagkasundo</h2>
            <button type="button" class="close-btn" onclick="closeEmbeddedReferPangkatModal()">&times;</button>
        </div>
        <form onsubmit="submitEmbeddedReferPangkat(event)">
            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                In accordance with <strong>RA 7160 Section 410(b)</strong>, when mediation before the Punong Barangay fails or the 15-day limit expires, the case is elevated to formal conciliation by constituting the 3-member <strong>Pangkat ng Tagapagkasundo</strong>. This automatically generates <strong>KP Form 10</strong>.
            </p>

            <div class="embedded-referral-step" id="refStep1">
                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="referDate" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Referral / Formation Date <span class="required-mark" style="color: #dc2626;">*</span></label>
                    <input type="date" id="referDate" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label for="referReason" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Referral Justification / Impasse Notes <span class="required-mark" style="color: #dc2626;">*</span></label>
                    <textarea id="referReason" rows="2" required placeholder="Mediation before the Punong Barangay failed to produce an amicable settlement. Referred for formal conciliation before the Pangkat." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
                </div>

                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeEmbeddedReferPangkatModal()">Cancel</button>
                    <button type="button" class="btn-create" onclick="goEmbeddedReferralStep(2)">Continue to panel selection &rarr;</button>
                </div>
            </div>

            <div class="embedded-referral-step" id="refStep2" style="display:none;">
                <!-- BULK PANGKAT GROUP SELECTION (1-Click Assignment) -->
                <div style="background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label for="referBulkPangkatSelect" style="font-size: 0.84rem; font-weight: 800; color: #166534; margin: 0; display: inline-flex; align-items: center; gap: 6px;">
                            <span>⚡ One-Click Pangkat Group Selection</span>
                            <span style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: #dcfce7; color: #15803d;">Recommended</span>
                        </label>
                        <a href="../settings/index.php?tab=duty-groups" target="_blank" style="font-size: 0.76rem; color: #0284c7; text-decoration: none; font-weight: 600;">
                            ⚙️ Manage Duty Groups &rarr;
                        </a>
                    </div>
                    <select id="referBulkPangkatSelect" onchange="applyEmbeddedBulkPangkatGroup(this.value)" style="padding: 8px 12px; width: 100%; border: 1.5px solid #86efac; border-radius: 6px; font-size: 0.86rem; font-weight: 600; background: #ffffff; color: #0f172a;">
                        <option value="">-- Choose Standing Pangkat Group (e.g. Wednesday Team) --</option>
                    </select>
                    <div id="referBulkGroupInfo" style="display: none; margin-top: 8px; font-size: 0.78rem; color: #166534; background: #dcfce7; padding: 6px 10px; border-radius: 6px; line-height: 1.45;"></div>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="referSelectionMethod" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Pangkat Selection Method</label>
                    <select id="referSelectionMethod" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem;">
                        <option value="Party Agreement">Party Agreement (Parties chose from Lupon)</option>
                        <option value="PB Assignment">Punong Barangay Assignment</option>
                        <option value="Raffle Draw">Raffle Draw (Lots drawn by PB)</option>
                    </select>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                    <span style="font-size: 0.82rem; font-weight: 700; color: #1e293b; text-transform: uppercase; display: block; margin-bottom: 10px;">
                        Nominate 3 Active Lupon Members
                    </span>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="referChairmanId" style="font-size: 0.82rem; font-weight: 600; display: block; margin-bottom: 4px;">1. Chairperson / Head <span class="required-mark" style="color: #dc2626;">*</span></label>
                        <select id="referChairmanId" required style="width: 100%; padding: 7px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;"></select>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="referSecretaryId" style="font-size: 0.82rem; font-weight: 600; display: block; margin-bottom: 4px;">2. Pangkat Secretary <span class="required-mark" style="color: #dc2626;">*</span></label>
                        <select id="referSecretaryId" required style="width: 100%; padding: 7px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;"></select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="referMemberId" style="font-size: 0.82rem; font-weight: 600; display: block; margin-bottom: 4px;">3. Regular Member <span class="required-mark" style="color: #dc2626;">*</span></label>
                        <select id="referMemberId" required style="width: 100%; padding: 7px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;"></select>
                    </div>
                </div>
                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="goEmbeddedReferralStep(1)">&larr; Back</button>
                    <button type="button" class="btn-create" onclick="goEmbeddedReferralStep(3)">Review referral &rarr;</button>
                </div>
            </div>

            <div class="embedded-referral-step" id="refStep3" style="display:none;">
                <div id="referReviewSummary" style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px; margin-bottom:16px; font-size:.85rem; color:#1e3a8a;"></div>
                <p style="margin:0 0 16px; font-size:.84rem; color:#475569; line-height:1.5;">Confirming creates the Pangkat panel, records the 3-day convening deadline, and generates KP Form 10. You will then continue directly in the Conciliation tab to schedule its first hearing.</p>
                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="goEmbeddedReferralStep(2)">&larr; Back</button>
                    <button type="button" class="btn-secondary" onclick="closeEmbeddedReferPangkatModal()">Cancel</button>
                    <button type="submit" class="btn-create" id="submitReferBtn" style="background: #0369a1; border-color: #0369a1;">Execute Referral &amp; Generate KP Form 10</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../layouts/transmittal-modal.php'; ?>
<?php include __DIR__ . '/../../layouts/kp-form-preview-modal.php'; ?>

<!-- MODAL: Sworn Statement of Repudiation (KP Form 17 - 2-Panel 50/50 Split-Screen Live Editor) -->
<div id="modalFileRepudiation" class="modal" role="dialog" aria-modal="true" style="display: none; padding: 14px; z-index: 9999;">
    <div class="modal-content" style="max-width: 1380px; width: 96vw; height: 90vh; max-height: 90vh; border-radius: 12px; padding: 0; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 40px -5px rgba(0,0,0,0.35);">
        <div class="modal-header" style="padding: 14px 22px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; background: #0f172a; color: #f8fafc; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="background: rgba(239,68,68,0.2); border: 1px solid rgba(239,68,68,0.5); color: #fca5a5; font-size: 0.7rem; font-weight: 800; padding: 3px 9px; border-radius: 999px;">50/50 LIVE SPLIT EDITOR</span>
                <div>
                    <h2 style="font-size: 1.1rem; font-weight: 800; color: #f8fafc; margin: 0;">
                        File Sworn Repudiation &mdash; KP Form 17 Live Editor
                    </h2>
                    <p style="margin: 2px 0 0; font-size: 0.76rem; color: #94a3b8;">
                        Pagtatwa ng Kasunduan &bull; RA 7160 Section 416 (50% Form Inputs + 50% Real-Time KP Form 17 Paper Preview)
                    </p>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeFileRepudiationModal()" style="font-size: 1.6rem; border: none; background: transparent; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <div style="display: grid; grid-template-columns: 50% 50%; flex: 1 1 0; min-height: 0; overflow: hidden;">
            <!-- Left 50%: Form Inputs -->
            <form onsubmit="handleRepudiationSubmit(event)" style="padding: 20px 24px; overflow-y: auto; background: #ffffff; border-right: 1px solid #cbd5e1; display: flex; flex-direction: column;">
                <input type="hidden" id="repudiationSettlementId" name="settlement_id">

                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 0.8rem; color: #991b1b; line-height: 1.45;">
                    <strong>Statutory Notice (RA 7160 Sec. 416):</strong> Any party may, within ten (10) days from execution, repudiate the settlement by filing a sworn statement alleging that consent was vitiated by fraud, violence, or intimidation.
                </div>

                <!-- Filing Party -->
                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="repudiatingPartySelect" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Repudiating Party (Naghahain ng Pagtatwa) <span style="color: #dc2626;">*</span>
                    </label>
                    <select id="repudiatingPartySelect" name="repudiated_by" required onchange="syncWorkflowSplitPreview('kp17')" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                        <option value="">— Select Party Filing Repudiation —</option>
                    </select>
                </div>

                <!-- Statutory Grounds -->
                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 6px;">
                        Statutory Legal Grounds (RA 7160 Sec. 416) <span style="color: #dc2626;">*</span>
                    </label>
                    <div style="display: flex; flex-direction: column; gap: 6px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; font-size: 0.84rem;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="ground" value="Fraud (Panlilinlang)" onchange="updateRepudiationGroundsText(); syncWorkflowSplitPreview('kp17');">
                            <span><strong>Panlilinlang (Fraud)</strong> &mdash; Misrepresentation of terms or deceit</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="ground" value="Violence (Karahasan)" onchange="updateRepudiationGroundsText(); syncWorkflowSplitPreview('kp17');">
                            <span><strong>Karahasan (Violence)</strong> &mdash; Physical force, coercion, or assault</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="ground" value="Intimidation (Pananakot)" onchange="updateRepudiationGroundsText(); syncWorkflowSplitPreview('kp17');">
                            <span><strong>Pananakot (Intimidation)</strong> &mdash; Duress, threats, or grave harassment</span>
                        </label>
                    </div>
                </div>

                <!-- Sworn Narrative -->
                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="repudiationReasonText" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Sworn Statement / Narrative Details (Salaysay ng Pagtatwa) <span style="color: #dc2626;">*</span>
                    </label>
                    <textarea id="repudiationReasonText" name="repudiation_reason" rows="4" required oninput="syncWorkflowSplitPreview('kp17')" placeholder="State specific acts of fraud, violence, or intimidation that vitiated consent..." style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; box-sizing: border-box;"></textarea>
                </div>

                <!-- Date of Sworn Statement -->
                <div class="form-group" style="margin-bottom: 18px;">
                    <label for="repudiationDateInput" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Date of Sworn Statement (Petsa ng Sinumpaang Salaysay) <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="date" id="repudiationDateInput" name="repudiation_date" required onchange="syncWorkflowSplitPreview('kp17')" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                </div>

                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: auto; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                    <button type="button" class="btn-secondary" onclick="closeFileRepudiationModal()">Cancel</button>
                    <button type="submit" id="btnSubmitRepudiation" class="btn-create" style="background: #dc2626; border-color: #dc2626;">
                        Submit Sworn Repudiation &rarr;
                    </button>
                </div>
            </form>
            <!-- Right 50%: Live KP Form 17 Paper Preview -->
            <div style="background: #334155; padding: 14px; display: flex; flex-direction: column; overflow: hidden;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; color: #e2e8f0; font-size: 0.76rem; font-weight: 700;">
                    <span>LIVE KP FORM 17 PAPER PREVIEW (REAL-TIME DOM UPDATE)</span>
                    <button type="button" onclick="document.getElementById('liveSplitKp17Iframe')?.contentWindow?.print()" style="background: #1e293b; border: 1px solid #475569; color: #f8fafc; font-size: 0.72rem; padding: 3px 9px; border-radius: 4px; cursor: pointer;">Print Preview</button>
                </div>
                <iframe id="liveSplitKp17Iframe" title="Live KP Form 17 Preview" style="width: 100%; flex: 1 1 0; border: 0; border-radius: 4px; background: #ffffff;"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Inline Record & Attest Amicable Settlement (KP Form 16) -->
<div id="inlineRecordSettlementModal" class="modal" role="dialog" aria-modal="true" style="display: none; padding: 16px; z-index: 9999;">
    <div class="modal-content" style="max-width: 640px; width: 96vw; border-radius: 12px; padding: 0; overflow: hidden; box-shadow: 0 25px 40px -5px rgba(0,0,0,0.3);">
        <div class="modal-header" style="padding: 16px 22px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; background: #064e3b; color: #ffffff; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700;">Record &amp; Attest Amicable Settlement (KP Form 16)</h3>
                <p style="margin: 2px 0 0; font-size: 0.78rem; color: #a7f3d0;">Complete the settlement terms and required signatures to mark this stage Settled.</p>
            </div>
            <button type="button" class="close-btn" onclick="document.getElementById('inlineRecordSettlementModal').style.display='none'" style="color: #ffffff; background: transparent; border: none; font-size: 1.4rem; cursor: pointer;">&times;</button>
        </div>
        <form id="inlineRecordSettlementForm" onsubmit="submitInlineSettlementForm(event)" style="padding: 20px 22px; display: flex; flex-direction: column; gap: 14px; max-height: 78vh; overflow-y: auto;">
            <input type="hidden" id="inlineSettleHearingId" value="">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label for="inlineSettleDate" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 4px;">Settlement Date</label>
                    <input type="date" id="inlineSettleDate" required style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; box-sizing: border-box;">
                </div>
                <div>
                    <label for="inlineSettleObligationType" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 4px;">Obligation Type</label>
                    <select id="inlineSettleObligationType" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; box-sizing: border-box;">
                        <option value="Specific Performance">Action / Conduct (Specific Performance)</option>
                        <option value="Monetary">Monetary</option>
                        <option value="Property Return">Property Return</option>
                        <option value="Hybrid">Mixed (Hybrid)</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label for="inlineSettleAmount" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 4px;">Total Amount (₱, optional)</label>
                    <input type="number" id="inlineSettleAmount" step="0.01" min="0" placeholder="0.00" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; box-sizing: border-box;">
                </div>
                <div>
                    <label for="inlineSettleDueDate" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 4px;">Compliance Due Date (optional)</label>
                    <input type="date" id="inlineSettleDueDate" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; box-sizing: border-box;">
                </div>
            </div>
            <div>
                <label for="inlineSettleTerms" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 4px;">Agreement Terms / Kasunduan Details <span style="color: #dc2626;">*</span></label>
                <textarea id="inlineSettleTerms" rows="4" required placeholder="Enter the agreed settlement terms between Complainant and Respondent..." style="width: 100%; padding: 9px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; box-sizing: border-box; resize: vertical;"></textarea>
            </div>
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px;">
                <strong style="font-size: 0.82rem; color: #166534;">KP Form 16 Required Execution &amp; Attestation</strong>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #1e293b; cursor: pointer;">
                    <input type="checkbox" id="inlineSettleTermsRead">
                    <span>Terms read aloud &amp; explained to both parties</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #1e293b; cursor: pointer;">
                    <input type="checkbox" id="inlineSettleCompSigned" required>
                    <span>Complainant Signed <span style="color: #dc2626;">*</span></span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #1e293b; cursor: pointer;">
                    <input type="checkbox" id="inlineSettleRespSigned" required>
                    <span>Respondent Signed <span style="color: #dc2626;">*</span></span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #1e293b; cursor: pointer;">
                    <input type="checkbox" id="inlineSettlePbAttested" required>
                    <span>Punong Barangay Attested <span style="color: #dc2626;">*</span></span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #1e293b; cursor: pointer;">
                    <input type="checkbox" id="inlineSettleSealed">
                    <span>Barangay Seal Affixed</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #1e293b; cursor: pointer;">
                    <input type="checkbox" id="inlineSettleOriginalFiled">
                    <span>Original filed in the case folder</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #1e293b; cursor: pointer;">
                    <input type="checkbox" id="inlineSettleCopiesIssued">
                    <span>Certified copies issued to parties</span>
                </label>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 6px;">
                <button type="button" class="btn-secondary" onclick="document.getElementById('inlineRecordSettlementModal').style.display='none'">Cancel</button>
                <button type="submit" id="btnSubmitInlineSettlement" class="btn-create" style="background: #16a34a; border-color: #16a34a; color: #ffffff;">Save KP Form 16 &amp; Finalize Settlement</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit Settlement Terms (KP Form 16 - 2-Panel 50/50 Split-Screen Live Editor) -->
<div id="modalEditSettlementTerms" class="modal" role="dialog" aria-modal="true" style="display: none; padding: 14px; z-index: 9999;">
    <div class="modal-content" style="max-width: 1380px; width: 96vw; height: 90vh; max-height: 90vh; border-radius: 12px; padding: 0; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 40px -5px rgba(0,0,0,0.35);">
        <div class="modal-header" style="padding: 14px 22px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; background: #0f172a; color: #f8fafc; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="background: rgba(34,197,94,0.18); border: 1px solid rgba(34,197,94,0.45); color: #4ade80; font-size: 0.7rem; font-weight: 800; padding: 3px 9px; border-radius: 999px;">50/50 LIVE SPLIT EDITOR</span>
                <div>
                    <h2 style="font-size: 1.1rem; font-weight: 800; color: #f8fafc; margin: 0;">
                        Edit Settlement Agreement Terms &mdash; KP Form 16 Live Editor
                    </h2>
                    <p style="margin: 2px 0 0; font-size: 0.76rem; color: #94a3b8;">
                        KP Form No. 16 (Kasunduang Pag-aayos &bull; 50% Form Inputs + 50% Real-Time Paper Preview)
                    </p>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeEditSettlementTermsModal()" style="font-size: 1.6rem; border: none; background: transparent; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <div style="display: grid; grid-template-columns: 50% 50%; flex: 1 1 0; min-height: 0; overflow: hidden;">
            <!-- Left 50%: Form Inputs -->
            <form onsubmit="handleEditSettlementSubmit(event)" style="padding: 20px 24px; overflow-y: auto; background: #ffffff; border-right: 1px solid #cbd5e1; display: flex; flex-direction: column;">
                <input type="hidden" id="editSettleCaseId" name="case_id">
                <input type="hidden" id="editSettleId" name="settlement_id">

                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 0.8rem; color: #1e3a8a; line-height: 1.4;">
                    <strong>Real-Time 50/50 Split Preview:</strong> Type or refine the settlement stipulations below and watch the official KP Form 16 sheet on the right update immediately.
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="editSettleDate" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Date of Execution (Petsa ng Kasunduan) <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="date" id="editSettleDate" name="settlement_date" required onchange="syncWorkflowSplitPreview('kp16')" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="editSettleTermsText" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Detailed Terms of Settlement (Mga Napagkasunduan) <span style="color: #dc2626;">*</span>
                    </label>
                    <textarea id="editSettleTermsText" name="agreement_details" rows="7" required oninput="syncWorkflowSplitPreview('kp16')" placeholder="Specify obligations, amounts, installment dates, or conditions..." style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; line-height: 1.5; box-sizing: border-box;"></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="editSettleAmount" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                            Settlement Amount (PHP) <span style="font-weight: 400; color: #64748b;">Optional</span>
                        </label>
                        <input type="number" step="0.01" min="0" id="editSettleAmount" name="total_amount" placeholder="0.00" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="editSettleResponsible" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                            Responsible Party
                        </label>
                        <select id="editSettleResponsible" name="responsible_party" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                            <option value="Respondent" selected>Respondent</option>
                            <option value="Complainant">Complainant</option>
                            <option value="Both">Both Parties</option>
                        </select>
                    </div>
                </div>

                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: auto; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                    <button type="button" class="btn-secondary" onclick="closeEditSettlementTermsModal()">Cancel</button>
                    <button type="submit" id="btnSubmitEditSettlement" class="btn-create">Save Changes &amp; Refresh Preview</button>
                </div>
            </form>
            <!-- Right 50%: Live KP Form 16 Paper Preview -->
            <div style="background: #334155; padding: 14px; display: flex; flex-direction: column; overflow: hidden;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; color: #e2e8f0; font-size: 0.76rem; font-weight: 700;">
                    <span>LIVE KP FORM 16 PAPER PREVIEW (REAL-TIME DOM UPDATE)</span>
                    <button type="button" onclick="document.getElementById('liveSplitKp16Iframe')?.contentWindow?.print()" style="background: #1e293b; border: 1px solid #475569; color: #f8fafc; font-size: 0.72rem; padding: 3px 9px; border-radius: 4px; cursor: pointer;">Print Preview</button>
                </div>
                <iframe id="liveSplitKp16Iframe" title="Live KP Form 16 Preview" style="width: 100%; flex: 1 1 0; border: 0; border-radius: 4px; background: #ffffff;"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Issue Motion for Execution (KP Form 23 - 2-Panel 50/50 Split-Screen Live Editor) -->
<div id="modalIssueExecutionKp23" class="modal" role="dialog" aria-modal="true" style="display: none; padding: 14px; z-index: 9999;">
    <div class="modal-content" style="max-width: 1380px; width: 96vw; height: 90vh; max-height: 90vh; border-radius: 12px; padding: 0; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 40px -5px rgba(0,0,0,0.35);">
        <div class="modal-header" style="padding: 14px 22px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; background: #0f172a; color: #f8fafc; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="background: rgba(59,130,246,0.2); border: 1px solid rgba(59,130,246,0.5); color: #93c5fd; font-size: 0.7rem; font-weight: 800; padding: 3px 9px; border-radius: 999px;">50/50 LIVE SPLIT EDITOR</span>
                <div>
                    <h2 style="font-size: 1.1rem; font-weight: 800; color: #f8fafc; margin: 0;">
                        Issue Motion for Execution &mdash; KP Form 23 Live Editor
                    </h2>
                    <p style="margin: 2px 0 0; font-size: 0.76rem; color: #94a3b8;">
                        Mosyon sa Pagpapatupad &bull; RA 7160 Sec. 417 (50% Form Inputs + 50% Real-Time KP Form 23 Paper Preview)
                    </p>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeIssueExecutionModal()" style="font-size: 1.6rem; border: none; background: transparent; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <div style="display: grid; grid-template-columns: 50% 50%; flex: 1 1 0; min-height: 0; overflow: hidden;">
            <!-- Left 50%: Form Inputs -->
            <form onsubmit="handleIssueExecutionSubmit(event)" style="padding: 20px 24px; overflow-y: auto; background: #ffffff; border-right: 1px solid #cbd5e1; display: flex; flex-direction: column;">
                <input type="hidden" id="execSettlementId" name="settlement_id">

                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 0.8rem; color: #166534; line-height: 1.45;">
                    <strong>Statutory Finality (RA 7160 Sec. 417):</strong> The 10-working-day repudiation window has elapsed without sworn repudiation. The amicable settlement now has the force and effect of a final court judgment.
                </div>

                <!-- Moving Party -->
                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="execMovingPartySelect" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Moving Party (Naghain ng Mosyon) <span style="color: #dc2626;">*</span>
                    </label>
                    <select id="execMovingPartySelect" name="motion_filed_by" required onchange="syncWorkflowSplitPreview('kp23')" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                        <option value="">— Select Moving Party —</option>
                    </select>
                </div>

                <!-- Obligation Violated -->
                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="execObligationText" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Obligation Violated / Unsatisfied Settlement Terms <span style="color: #dc2626;">*</span>
                    </label>
                    <textarea id="execObligationText" name="obligation_violated" rows="4" required oninput="syncWorkflowSplitPreview('kp23')" placeholder="Specify what the respondent failed or refused to comply with..." style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; box-sizing: border-box;"></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="execAmountReq" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                            Amount / Requirement Demanded <span style="color: #dc2626;">*</span>
                        </label>
                        <input type="text" id="execAmountReq" name="amount_or_requirement" required oninput="syncWorkflowSplitPreview('kp23')" placeholder="e.g. PHP 25,000 or Return of Property" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="execMotionDate" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                            Motion Filing Date <span style="color: #dc2626;">*</span>
                        </label>
                        <input type="date" id="execMotionDate" name="motion_date" required onchange="syncWorkflowSplitPreview('kp23')" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                    </div>
                </div>

                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: auto; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                    <button type="button" class="btn-secondary" onclick="closeIssueExecutionModal()">Cancel</button>
                    <button type="submit" id="btnSubmitExecution" class="btn-create">
                        Generate KP Form 23 &rarr;
                    </button>
                </div>
            </form>
            <!-- Right 50%: Live KP Form 23 Paper Preview -->
            <div style="background: #334155; padding: 14px; display: flex; flex-direction: column; overflow: hidden;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; color: #e2e8f0; font-size: 0.76rem; font-weight: 700;">
                    <span>LIVE KP FORM 23 PAPER PREVIEW (REAL-TIME DOM UPDATE)</span>
                    <button type="button" onclick="document.getElementById('liveSplitKp23Iframe')?.contentWindow?.print()" style="background: #1e293b; border: 1px solid #475569; color: #f8fafc; font-size: 0.72rem; padding: 3px 9px; border-radius: 4px; cursor: pointer;">Print Preview</button>
                </div>
                <iframe id="liveSplitKp23Iframe" title="Live KP Form 23 Preview" style="width: 100%; flex: 1 1 0; border: 0; border-radius: 4px; background: #ffffff;"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================================
     MODAL: Reopen Settled Case for CFA (RA 7160 Sec. 417 Non-Compliance)
     ===================================================================== -->
<div id="modalReopenForCfa" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.55); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; max-width:520px; width:92%; padding:28px 28px 22px; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
            
            <div>
                <h3 style="margin:0; font-size:1rem; color:#0f172a; font-weight:800;">Reopen Case for CFA Issuance</h3>
                <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">RA 7160 Sec. 417 — Non-compliance with Amicable Settlement</p>
            </div>
        </div>
        <p style="font-size:0.84rem; color:#475569; margin:0 0 14px; line-height:1.55;">
            This action marks the Amicable Settlement as <strong>Violated</strong> and opens the case for
            Certificate to File Action (KP Form 20) issuance. This is authorised only when the respondent
            fails or refuses to comply with the terms of the settlement.
        </p>
        <div style="margin-bottom:16px;">
            <label for="reopenCfaReason" style="display:block; font-size:0.84rem; font-weight:600; color:#334155; margin-bottom:6px;">
                Non-Compliance Description <span style="color:#dc2626;">*</span>
            </label>
            <textarea id="reopenCfaReason" rows="4" placeholder="Describe specifically how the respondent failed to comply with the settlement terms (e.g., failed to pay agreed amount by due date, refused to vacate property, etc.)"
                style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #e2e8f0; border-radius:7px; font-size:0.84rem; color:#0f172a; resize:vertical; font-family:inherit;"></textarea>
        </div>
        <p style="font-size:0.78rem; color:#94a3b8; margin:0 0 18px;">
            This action is <strong>irreversible</strong>. Ensure all facts of non-compliance are accurately documented before proceeding.
        </p>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="btn-secondary" onclick="closeReopenCfaModal()" id="btnReopenCfaCancel">Cancel</button>
            <button type="button" class="btn-case-primary" id="btnReopenCfaConfirm"
                onclick="submitReopenForCfa()"
                style="background:#dc2626; border-color:#dc2626;"
            >
                Reopen &amp; Issue CFA
            </button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/summons-preview.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/summons-preview.js'); ?>"></script>
<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>


<!-- ========================================== -->
<!-- MODAL: Issue KP Form 15 (Arbitration Award)-->
<!-- ========================================== -->
<div id="issueArbitrationAwardModal" class="modal" style="display: none; z-index: 1050; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-content" style="max-width: 620px; width: 100%; max-height: 90vh; overflow-y: auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        <div class="modal-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 14px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <h2 style="font-size: 1.22rem; font-weight: 800; color: #0f172a; margin: 0;">Issue KP Form 15 (Arbitration Award)</h2>
                <span style="font-size: 0.8rem; color: #64748b;">Gawad ng Paghahatol &bull; Binding Arbitral Ruling under RA 7160 Sec. 413</span>
            </div>
            <button type="button" class="close-btn" onclick="closeIssueArbitrationAwardModal()" style="background: none; border: none; font-size: 1.6rem; color: #64748b; cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <form id="issueArbitrationAwardForm" onsubmit="submitArbitrationAward(event)">
            <input type="hidden" id="arbAwardCaseId" value="">
            <input type="hidden" id="arbAwardAgreementDate" value="">

            <!-- Statutory Window Notice -->
            <div id="arbStatutoryNoticeBanner" style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 1.15rem;">⚖️</span>
                    <strong style="color: #1e40af; font-size: 0.88rem;">RA 7160 Sec. 413 Mandatory Statutory Timeline</strong>
                </div>
                <p id="arbStatutoryNoticeText" style="margin: 4px 0 0; font-size: 0.81rem; color: #1e3a8a; line-height: 1.5;">
                    The arbitration award cannot be issued earlier than <strong>Day 6</strong> (after the 5-day repudiation window expires) and must be rendered no later than <strong>15 calendar days</strong> from signing KP Form 14.
                </p>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="arbAwardDate" style="font-weight: 700; font-size: 0.84rem; color: #0f172a; display: block; margin-bottom: 4px;">
                    Date of Arbitral Award <span class="required-mark" style="color: #dc2626;">*</span>
                </label>
                <input type="date" id="arbAwardDate" required onchange="validateArbitrationAwardDateInput()" style="width: 100%; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: 9px 12px; font-size: 0.88rem; box-sizing: border-box;">
                <small id="arbAwardDateHelper" style="color: #64748b; font-size: 0.77rem; display: block; margin-top: 4px;">
                    Allowed statutory range: Day 6 to Day 15.
                </small>
                <div id="arbAwardDateError" style="display: none; color: #dc2626; font-size: 0.8rem; font-weight: 600; margin-top: 5px; padding: 6px 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px;"></div>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="arbAwardTerms" style="font-weight: 700; font-size: 0.84rem; color: #0f172a; display: block; margin-bottom: 4px;">
                    Specific Terms of Award &amp; Resolution Findings <span class="required-mark" style="color: #dc2626;">*</span>
                </label>
                <textarea id="arbAwardTerms" rows="5" required placeholder="Isaad ang buong hatol ng Lupon, mga ebidensyang isinaalang-alang, at ang mga pinal na obligasyon o kabayaran na dapat tuparin ng mga panig..." style="width: 100%; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 0.84rem; line-height: 1.5; box-sizing: border-box;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <label for="arbArbitratorPresider" style="font-weight: 700; font-size: 0.84rem; color: #0f172a; display: block; margin-bottom: 4px;">
                    Signatures / Presiding Arbitrator Details
                </label>
                <input type="text" id="arbArbitratorPresider" placeholder="HON. AKIKO LYNNE CENTENO (Punong Barangay / Tagapangulo)" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 12px; font-size: 0.84rem; box-sizing: border-box;">
            </div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                <button type="button" class="btn-secondary" onclick="closeIssueArbitrationAwardModal()" style="font-size: 0.84rem; padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitArbAward" style="font-size: 0.84rem; padding: 8px 20px; background: #2563eb; border-color: #2563eb; font-weight: 700;">Confirm &amp; Render Award (KP Form 15)</button>
            </div>
        </form>
    </div>
</div>


<div id="routingOverrideModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:480px;">
        <div class="modal-header">
            <h2>Queue &amp; Routing Override</h2>
            <button type="button" class="close-btn" onclick="closeRoutingOverrideModal()">&times;</button>
        </div>
        <form id="routingOverrideForm" onsubmit="submitRoutingOverride(event)">
            <div class="form-group" style="margin-bottom:12px;">
                <label for="overrideQueueSelect" style="font-weight:700;display:block;margin-bottom:4px;">Approved Receiving Queue</label>
                <select id="overrideQueueSelect" required style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;">
                    <?php foreach (ComplaintRoutingService::APPROVED_QUEUES as $qCode => $qLabel): ?>
                        <option value="<?= htmlspecialchars($qCode, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($qCode . ' — ' . $qLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label for="overrideQueueReason" style="font-weight:700;display:block;margin-bottom:4px;">Override Reason *</label>
                <textarea id="overrideQueueReason" rows="3" required placeholder="State why this queue assignment is being updated..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>
            <div class="modal-actions" style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn-secondary" onclick="closeRoutingOverrideModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitRoutingOverride">Save Queue Assignment</button>
            </div>
        </form>
    </div>
</div>

<div id="pendingCloseModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h2>Request Pending Close</h2>
            <button type="button" class="close-btn" onclick="closePendingCloseModal()">&times;</button>
        </div>
        <form id="pendingCloseForm" onsubmit="submitPendingCloseRequest(event)">
            <div class="form-group" style="margin-bottom:12px;">
                <label for="pendingCloseType" style="font-weight:700;display:block;margin-bottom:4px;">Resolution Type *</label>
                <select id="pendingCloseType" required style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;">
                    <option value="Administrative Closure">Administrative Closure</option>
                    <option value="Signed Settlement">Signed Settlement</option>
                    <option value="Obligation Compliance">Obligation Compliance</option>
                    <option value="Dismissal">Dismissal</option>
                    <option value="CFA Referral">CFA Referral</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label for="pendingCloseEvidence" style="font-weight:700;display:block;margin-bottom:4px;">Supporting Evidence / Record Reference</label>
                <select id="pendingCloseEvidence" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;">
                    <option value="">Select a saved supporting record</option>
                    <?php foreach ((new ClosureEvidenceService($preparationDb))->forComplaint($complaintId) as $closureEvidence): ?>
                        <option value="<?= htmlspecialchars($closureEvidence['reference'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($closureEvidence['label'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Upload supporting evidence or finalize the relevant proceeding before requesting closure.</small>
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label for="pendingCloseReason" style="font-weight:700;display:block;margin-bottom:4px;">Closure Request Reason *</label>
                <textarea id="pendingCloseReason" rows="3" required placeholder="Explain the basis for requesting closure verification..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>
            <div class="modal-actions" style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn-secondary" onclick="closePendingCloseModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitPendingClose">Submit Pending Close Request</button>
            </div>
        </form>
    </div>
</div>

<script>
window.openRecordReturnModal = function(stage) {
    const cId = <?= (int)($complaintId ?? 0) ?>;
    window.location.href = `../gps/proof-service.php?complaint_id=${encodeURIComponent(cId)}&stage=${encodeURIComponent(stage || 'Paanyaya 1')}&open_return=1`;
};
window.openRoutingOverrideModal = function() {
    const el = document.getElementById('routingOverrideModal');
    if (el) el.style.display = 'flex';
};
window.closeRoutingOverrideModal = function() {
    const el = document.getElementById('routingOverrideModal');
    if (el) el.style.display = 'none';
};
window.submitRoutingOverride = async function(ev) {
    ev.preventDefault();
    const res = await fetch('../../../backend/api/complaints/override-routing.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': <?= json_encode(CsrfService::token()) ?>
        },
        body: JSON.stringify({
            complaint_id: <?= (int)($complaintId ?? 0) ?>,
            assigned_queue: document.getElementById('overrideQueueSelect')?.value || '',
            reason: document.getElementById('overrideQueueReason')?.value?.trim() || ''
        })
    });
    const data = await res.json();
    if (data.success) {
        window.agapNotify?.(data.message || 'Queue updated.', 'success');
        closeRoutingOverrideModal();
    } else {
        window.agapNotify?.(data.message || 'Unable to update queue.', 'error');
    }
};
window.openPendingCloseModal = function() {
    const el = document.getElementById('pendingCloseModal');
    if (el) el.style.display = 'flex';
};
window.closePendingCloseModal = function() {
    const el = document.getElementById('pendingCloseModal');
    if (el) el.style.display = 'none';
};
window.submitPendingCloseRequest = async function(ev) {
    ev.preventDefault();
    const res = await fetch('../../../backend/api/complaints/closure-request.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': <?= json_encode(CsrfService::token()) ?>
        },
        body: JSON.stringify({
            action: 'request',
            complaint_id: <?= (int)($complaintId ?? 0) ?>,
            resolution_type: document.getElementById('pendingCloseType')?.value || 'Administrative Closure',
            evidence_path: document.getElementById('pendingCloseEvidence')?.value?.trim() || '',
            request_reason: document.getElementById('pendingCloseReason')?.value?.trim() || ''
        })
    });
    const data = await res.json();
    if (data.success) {
        window.agapNotify?.(data.message || 'Pending Close request submitted.', 'success');
        closePendingCloseModal();
        setTimeout(() => window.location.reload(), 600);
    } else {
        window.agapNotify?.(data.message || 'Unable to submit closure request.', 'error');
    }
};
</script>

<?php include '../../layouts/footer.php'; ?>
