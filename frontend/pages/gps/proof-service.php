<?php
session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}
if (!in_array($roleId, [1, 2, 4], true)) {
    http_response_code(403);
    exit('Access denied for this role.');
}

include '../../layouts/header.php';
require_once __DIR__ . '/../../../backend/models/Setting.php';
$sysSettings = Setting::all();
require_once __DIR__ . '/../../../backend/config/database.php';
$paanyayaAssignments = [];
$paanyayaServiceUnavailable = false;
$paanyayaServiceFlash = $_SESSION['paanyaya_service_flash'] ?? null;
unset($_SESSION['paanyaya_service_flash']);
$servingOfficers = [];
$currentOfficerName = trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Lupon Officer'));
try {
    $dbProof = (new Database())->connect();
    $offStmt = $dbProof->query("
        SELECT u.user_id,
               TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name,
               COALESCE(r.role_name, 'Barangay Officer') AS role_name
        FROM users u
        LEFT JOIN roles r ON r.role_id = u.role_id
        WHERE COALESCE(u.status, 'Active') = 'Active'
        ORDER BY FIELD(u.role_id, 4, 2, 1), u.first_name ASC
    ");
    $servingOfficers = $offStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($servingOfficers as $so) {
        if ((int) $so['user_id'] === (int) ($_SESSION['user_id'] ?? 0) && !empty($so['full_name'])) {
            $currentOfficerName = $so['full_name'];
            break;
        }
    }
} catch (Throwable $e) {
    $servingOfficers = [];
}
if ($roleId === 4) {
    require_once __DIR__ . '/../../../backend/services/PaanyayaServiceDelivery.php';
    try {
        $paanyayaAssignments = (new PaanyayaServiceDelivery($dbProof))->assignments((int) $_SESSION['user_id']);
    } catch (Throwable $exception) {
        error_log('Paanyaya service queue: ' . $exception->getMessage());
        $paanyayaServiceUnavailable = true;
    }
}
?>
<script>
window.AGAP_USER_ROLE_ID = <?php echo (int) $roleId; ?>;
window.AGAP_CURRENT_USER_ID = <?php echo (int) ($_SESSION['user_id'] ?? 0); ?>;
window.AGAP_CURRENT_USER_NAME = <?php echo json_encode($currentOfficerName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.AGAP_SETTINGS = <?php echo json_encode([
    'user_role_id' => (int) $roleId,
    'paanyaya_max_attempts' => (int) ($sysSettings['paanyaya_max_attempts'] ?? 6),
    'summons_max_attempts' => (int) ($sysSettings['summons_max_attempts'] ?? 6),
    'mediation_max_sessions' => (int) ($sysSettings['mediation_max_sessions'] ?? 2),
    'conciliation_max_sessions' => (int) ($sysSettings['conciliation_max_sessions'] ?? 3),
    'mediation_day_of_week' => (string) ($sysSettings['mediation_day_of_week'] ?? 'Tue'),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/gps.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/gps.css'); ?>">
<link rel="stylesheet" href="../../assets/css/agap-list-layout.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/agap-list-layout.css'); ?>">
<link rel="stylesheet" href="../../assets/css/paanyaya-preparation.css?v=<?= filemtime(__DIR__ . '/../../assets/css/paanyaya-preparation.css') ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="agap-list-shell">
            <?php if ($roleId === 4): ?>
                <?php include __DIR__ . '/paanyaya-assignments.php'; ?>
            <?php endif; ?>
            <a id="backLink" href="../complaints/complaint-list.php" class="back-link" hidden style="margin-bottom: 12px; display: inline-flex; align-items: center; gap: 6px;">
                <svg class="back-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span id="backLinkLabel">Back to Complaint</span>
            </a>

            <section class="agap-list-card">
                <div class="agap-list-header">
                    <div class="agap-list-title-wrap">
                        <div class="agap-list-title-row">
                            <h1>Summons</h1>
                            <span class="agap-stage-pill">Service of Notice</span>
                        </div>
                        <p class="agap-list-subtitle">Manage summons and notice delivery, record Officers' Returns, and review service progress for each case.</p>
                    </div>
                </div>

                <div id="proofMessage" class="proof-message" role="alert" aria-live="polite" style="margin: 0 0 14px;"></div>

                <!-- Unified Underlined Tabs + Inline Search & Filter Controls -->
                <div class="agap-list-subbar">
                    <div class="agap-underline-tabs" role="tablist" aria-label="Case service filters">
                        <button type="button" id="tabFilterAll" class="agap-tab-item proof-tab-btn active" role="tab" aria-selected="true" data-filter="all" onclick="setProofFilter('all')">
                            All Cases <span id="countAllCases" class="agap-tab-count">0</span>
                        </button>
                        <button type="button" id="tabFilterPending" class="agap-tab-item proof-tab-btn" role="tab" aria-selected="false" data-filter="pending" onclick="setProofFilter('pending')">
                            Pending Delivery <span id="countPendingDelivery" class="agap-tab-count">0</span>
                        </button>
                        <button type="button" id="tabFilterServed" class="agap-tab-item proof-tab-btn" role="tab" aria-selected="false" data-filter="served" onclick="setProofFilter('served')">
                            Fully Served <span id="countServedDelivery" class="agap-tab-count">0</span>
                        </button>
                    </div>

                    <div class="agap-inline-controls">
                        <div class="agap-inline-search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input
                                id="summonCaseSearch"
                                type="search"
                                placeholder="Search KP no., parties..."
                                autocomplete="off"
                            >
                            <button type="button" id="clearSummonSearch" class="search-clear-btn" title="Clear search">&times;</button>
                        </div>

                        <button type="button" id="toggleSummonFilterDrawer" class="agap-filter-icon-btn" title="Filter and sort options">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                            </svg>
                            <span>Filter</span>
                            <span id="summonFilterActiveDot" class="agap-filter-dot" style="display: none;"></span>
                        </button>

                        <button type="button" id="resetSummonFilters" class="agap-reset-link-btn" style="display: none;">
                            Reset
                        </button>
                    </div>

                    <!-- Hidden select for script backward-compatibility -->
                    <select id="proofCaseId" name="case_id" hidden aria-hidden="true">
                        <option value="">Select a case</option>
                    </select>
                </div>

                <!-- Collapsible Notice Type & Sort Drawer -->
                <div id="summonFilterDrawerPanel" class="filter-drawer-panel">
                    <div class="agap-drawer-grid">
                        <div class="agap-drawer-field">
                            <label for="summonNoticeTypeFilter">Notice Type</label>
                            <select id="summonNoticeTypeFilter">
                                <option value="">All Notices</option>
                                <option value="summons">Docketed Summons</option>
                                <option value="paanyaya">Paanyaya Notice</option>
                            </select>
                        </div>
                        <div class="agap-drawer-field">
                            <label for="summonSortSelect">Sort By</label>
                            <select id="summonSortSelect">
                                <option value="hearing_date:asc">Hearing Date (Earliest)</option>
                                <option value="hearing_date:desc">Hearing Date (Latest)</option>
                                <option value="kp_number:desc">KP Number (Newest)</option>
                                <option value="kp_number:asc">KP Number (Oldest)</option>
                                <option value="complainant:asc">Complainant (A-Z)</option>
                                <option value="respondent:asc">Respondent (A-Z)</option>
                                <option value="service_status:asc">Service Status</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="agap-list-meta">
                    <span id="summonListSummary">Click any case row to expand party returns and record service attempts.</span>
                </div>

                <!-- Unified Expandable Case Table Workspace -->
                <div class="agap-list-table-wrap" id="casesNeedingSummonsSection">
                    <div class="table-responsive" style="border: none; background: transparent;">
                        <table class="data-table proof-table">
                            <thead>
                                <tr>
                                    <th style="width: 44px; text-align: center;" aria-label="Expand toggle"></th>
                                    <th class="sortable-th" data-sort-key="kp_number" title="Click to sort by KP Number">
                                        <span class="th-content">KP Number <span class="sort-indicator" id="sortInd_summon_kp_number">⇅</span></span>
                                    </th>
                                    <th class="sortable-th" data-sort-key="complainant" title="Click to sort by Complainant">
                                        <span class="th-content">Complainant <span class="sort-indicator" id="sortInd_summon_complainant">⇅</span></span>
                                    </th>
                                    <th class="sortable-th" data-sort-key="respondent" title="Click to sort by Respondent">
                                        <span class="th-content">Respondent <span class="sort-indicator" id="sortInd_summon_respondent">⇅</span></span>
                                    </th>
                                    <th class="sortable-th sort-active-asc" data-sort-key="hearing_date" title="Click to sort by Hearing Date">
                                        <span class="th-content">Hearing Date <span class="sort-indicator" id="sortInd_summon_hearing_date">▲</span></span>
                                    </th>
                                    <th class="sortable-th" data-sort-key="service_status" title="Click to sort by Service Status">
                                        <span class="th-content">Service Status <span class="sort-indicator" id="sortInd_summon_service_status">⇅</span></span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="casesNeedingSummonsTable">
                                <tr>
                                    <td colspan="6" class="empty-state" style="padding: 36px; text-align: center; color: #94a3b8;">
                                        Loading case service records...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Bottom Footer: Items Per Page + Numeric Pagination -->
                <div id="summonPagination" class="agap-list-footer" aria-label="Proof of service pagination" style="display: none;">
                    <div class="agap-page-size-wrap">
                        <label for="summonPageSize">Items Per Page</label>
                        <select id="summonPageSize" class="agap-page-size-select" aria-label="Items per page">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        <span id="summonPaginationSummary" class="agap-page-summary" aria-live="polite">Showing 0 cases</span>
                    </div>
                    <div id="summonPaginationControls" class="agap-pagination-controls"></div>
                </div>
            </section>

            <!-- Case Service History & Delivered Forms Timeline Section -->
            <section class="agap-list-card proof-timeline-section" id="caseServiceTimelineSection" style="margin-top: 18px; padding: 0; overflow: hidden; display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 22px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="agap-rec-icon is-blue" style="width: 34px; height: 34px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="8" y1="13" x2="16" y2="13"/>
                                <line x1="8" y1="17" x2="13" y2="17"/>
                            </svg>
                        </span>
                        <div>
                            <h3 style="margin: 0; font-size: 1.02rem; font-weight: 700; color: #0f172a;">
                                Case Service History &amp; Delivered Forms Timeline
                            </h3>
                            <p id="timelineCaseSubtitle" style="margin: 2px 0 0; font-size: 0.82rem; color: #64748b;">
                                Chronological stream of all statutory invitations, notices, summons, and officer returns across the case lifecycle
                            </p>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button type="button" id="toggleTimelineBtn" class="agap-btn-act" onclick="toggleTimelineVisibility()">
                            Expand Timeline
                        </button>
                    </div>
                </div>
                <div id="caseServiceTimelineContainer" style="display: none; padding: 20px; background: #fff;">
                    <div style="text-align: center; color: #94a3b8; padding: 20px; font-size: 0.88rem;">
                        Select or expand a case above to review its full statutory service history timeline.
                    </div>
                </div>
            </section>

            <!-- Hidden legacy section for backward compatibility -->
            <section id="hearingDeliveriesSection" hidden aria-hidden="true" style="display: none;">
                <div id="serviceStatusAlert" style="display: none;"></div>
                <div id="hearingDeliveriesContainer"></div>
            </section>
        </div>
    </div>
</div>

<!-- Task 6: Modern Slide-in Officer's Return Drawer & Responsive Modal -->
<div id="officerReturnDrawerOverlay" class="proof-drawer-overlay proof-modal-overlay" onclick="closeOfficerReturnModal()" aria-hidden="true"></div>
<div id="officerReturnModal" class="proof-drawer" role="dialog" aria-modal="true" aria-labelledby="officerReturnModalTitle">
    <div class="proof-drawer-header">
        <div>
            <div class="drawer-badges">
                <span id="drawerCaseBadge" class="drawer-badge drawer-badge-case">Case #</span>
                <span id="drawerPartyBadge" class="drawer-badge drawer-badge-party-resp">Respondent (1st Attempt)</span>
                <span id="drawerDocBadge" class="drawer-badge" style="background: #f1f5f9; color: #475569;">KP Form No. 9</span>
            </div>
            <h3 id="officerReturnModalTitle" style="margin: 0; font-size: 1.2rem; font-weight: 700; color: #0f172a;">Log Officer's Return</h3>
            <p id="officerReturnModalSubtitle" style="margin: 4px 0 0; font-size: 0.84rem; color: #64748b;">Record delivery result and proof of service</p>
        </div>
        <button type="button" class="proof-modal-close" onclick="closeOfficerReturnModal()" aria-label="Close drawer" style="font-size: 1.6rem; color: #94a3b8; background: none; border: none; cursor: pointer;">&times;</button>
    </div>

    <form id="officerReturnForm" class="proof-drawer-form" onsubmit="submitOfficerReturn(event)" enctype="multipart/form-data">
        <input type="hidden" id="returnCaseId" name="case_id" value="">
        <input type="hidden" id="returnHearingId" name="hearing_id" value="">
        <input type="hidden" id="returnPartyType" name="party_type" value="">
        <input type="hidden" id="returnResidentId" name="resident_id" value="">
        <input type="hidden" id="returnAttemptNumber" name="attempt_number" value="1">
        <input type="hidden" id="returnDeliveryId" name="delivery_id" value="">
        <input type="hidden" id="returnIsPaanyaya" name="is_paanyaya" value="0">
        <input type="hidden" id="returnStageType" name="stage_type" value="">
        <input type="hidden" id="returnComplaintId" name="complaint_id" value="">
        <input type="hidden" name="action" value="officer_return">

        <div class="proof-drawer-body">
            <!-- Interactive Attempt Stepper & Collapsed Attempt History Switcher (Single Off-Canvas Drawer UX) -->
            <div id="drawerAttemptStepperSection" class="drawer-attempt-switcher" style="display: none;">
                <div class="drawer-attempt-switcher-header">
                    <span class="drawer-attempt-switcher-title">Attempt History &amp; Node Selector</span>
                    <span id="drawerAttemptCountPill" class="drawer-attempt-count-pill">1 Attempt</span>
                </div>
                <div id="drawerAttemptNodeStrip" class="drawer-attempt-node-strip" role="tablist" aria-label="Select Delivery Attempt"></div>
                <div id="drawerSelectedAttemptSummary" class="drawer-selected-attempt-summary"></div>
                <div id="drawerOlderAttemptsCollapse" class="drawer-older-attempts-collapse" style="display: none;"></div>
            </div>

            <!-- Delivery Result / Status -->
            <div class="form-group">
                <label for="returnDeliveryStatus" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Delivery Result / Service Mode
                </label>
                <select id="returnDeliveryStatus" name="delivery_status" required style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;" onchange="handleDeliveryStatusChange()">
                    <option value="Served Personal">Served - Personal (Direct to party)</option>
                    <option value="Served Substituted">Served - Substituted (Competent person of residence)</option>
                    <option value="Served Refused">Refused to Receive (Treated as served under KP rules)</option>
                    <option value="Unserved">Unserved / Delivery Failed (Cannot be served)</option>
                </select>
            </div>

            <!-- Serving Officer / Process Server -->
            <div class="form-group">
                <label for="returnServedBy" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Serving Officer / Process Server
                </label>
                <select id="returnServedBy" name="served_by" required style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
                    <?php if (empty($servingOfficers)): ?>
                        <option value="<?php echo (int) ($_SESSION['user_id'] ?? 1); ?>" selected><?php echo htmlspecialchars($currentOfficerName, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php else: ?>
                        <?php foreach ($servingOfficers as $off): ?>
                            <option value="<?php echo (int) $off['user_id']; ?>" data-officer-name="<?php echo htmlspecialchars($off['full_name'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ((int) $off['user_id'] === (int) ($_SESSION['user_id'] ?? 0)) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($off['full_name'] . ' — ' . $off['role_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Date & Time of Service -->
            <div id="servedAtGroup" class="form-group">
                <label for="returnServedAt" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Date &amp; Time Served <span style="color: #dc2626;">*</span>
                </label>
                <input type="datetime-local" id="returnServedAt" name="served_at" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
            </div>

            <!-- Recipient Name -->
            <div id="recipientNameGroup" class="form-group">
                <label for="returnRecipientName" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Recipient / Receiving Person Name <span id="recipientReqStar" style="color: #dc2626;">*</span>
                </label>
                <input type="text" id="returnRecipientName" name="recipient_name" placeholder="Full name of person who received the notice" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
            </div>

            <!-- Relationship (for Substituted) -->
            <div id="relationshipGroup" class="form-group" style="display: none;">
                <label for="returnRelationship" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Relationship to Party <span style="color: #dc2626;">*</span>
                </label>
                <input type="text" id="returnRelationship" name="relationship" placeholder="e.g., Spouse, Sibling, Parent, Co-resident" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
                <small style="color: #64748b; font-size: 0.8rem; margin-top: 4px; display: block;">Must be a person of sufficient age and discretion residing at the same residence.</small>
            </div>

            <!-- Failure Reason (for Unserved) -->
            <div id="unservedReasonGroup" class="form-group" style="display: none;">
                <label for="returnUnservedReason" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Reason for Failure to Serve <span style="color: #dc2626;">*</span>
                </label>
                <select id="returnUnservedReason" name="unserved_reason" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
                    <option value="Moved Out">Moved Out (No longer residing at given address)</option>
                    <option value="Wrong Address">Wrong Address (Address unknown or non-existent)</option>
                    <option value="No One Home">No One Home (Unreachable after multiple attempts)</option>
                    <option value="Refused">Refused (Party refused to sign or receive)</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <!-- Failure Notes (for Unserved) -->
            <div id="failureNotesGroup" class="form-group" style="display: none;">
                <label for="returnFailureNotes" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Service Failure Notes &amp; Observations <span style="color: #dc2626;">*</span>
                </label>
                <textarea id="returnFailureNotes" name="failure_notes" rows="3" placeholder="Provide detailed report of attempts made and information verified from neighbors/barangay..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;"></textarea>
                <small style="color: #dc2626; font-size: 0.8rem; margin-top: 4px; display: block;">⚠️ Marking Unserved automatically pauses the mediation hearing until address correction or re-service.</small>
            </div>

            <!-- Remarks / Notes -->
            <div class="form-group">
                <label for="returnRemarks" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Server's Return Notes / Witness Info
                </label>
                <textarea id="returnRemarks" name="remarks" rows="2" placeholder="Optional notes, witness name (for refusal), or specific circumstances..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;"></textarea>
            </div>

            <!-- Proof Photo / Signature Upload -->
            <div class="form-group">
                <label for="returnProofImage" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Proof of Service Photo / Signature (Optional)
                </label>
                <input type="file" id="returnProofImage" name="proof_image" accept="image/jpeg,image/png,image/webp" style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem;">
                <small style="color: #64748b; font-size: 0.8rem; margin-top: 4px; display: block;">Upload geotagged photo of service, receiving copy with signature, or house photo (Max 5MB).</small>
            </div>
        </div>

        <div class="proof-drawer-footer">
            <button type="button" class="btn-secondary" onclick="closeOfficerReturnModal()" style="padding: 9px 18px; border-radius: 8px; font-weight: 600; border: 1px solid #cbd5e1; background: #fff; cursor: pointer;">Cancel</button>
            <button type="submit" id="submitOfficerReturnBtn" class="btn-primary" style="background: #0284c7; color: #fff; padding: 9px 20px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                Save Officer's Return
            </button>
        </div>
    </form>
</div>

<script src="../../assets/js/gps.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/gps.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
