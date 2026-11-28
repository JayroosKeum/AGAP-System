<?php
require_once '../../../backend/services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

// Enforce strict Summons Server access control on the field dispatch dashboard
$sessionRoleId = (int) ($_SESSION['role_id'] ?? 0);
$sessionRoleName = (string) ($_SESSION['role_name'] ?? '');
if (!isset($_SESSION['user_id']) || ($sessionRoleId !== 4 && $sessionRoleName !== 'Summons Server')) {
    if ($sessionRoleId === 1) {
        header('Location: admin-dashboard.php', true, 303);
    } elseif ($sessionRoleId === 2) {
        header('Location: clerk-dashboard.php', true, 303);
    } elseif ($sessionRoleId === 3) {
        header('Location: lupon-dashboard.php', true, 303);
    } elseif ($sessionRoleName === 'BPSO') {
        header('Location: bpso-dashboard.php', true, 303);
    } else {
        header('Location: ../auth/login.php', true, 303);
    }
    exit;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/server-dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/server-dashboard.css'); ?>">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <section class="dashboard-overview server-dispatch-view" id="serverDispatchRoot" aria-label="Summons &amp; Field Dispatch">

            <!-- ── 1. Dashboard Header ──────────────────────────────────────────── -->
            <header class="dispatch-page-header" id="dispatchPageHeader">
                <div>
                    <div class="dispatch-overline">BARANGAY TUMANA &middot; KATARUNGANG PAMBARANGAY</div>
                    <h1 class="dispatch-main-title">Summons &amp; Field Dispatch</h1>
                </div>
                <div class="dispatch-header-actions">
                    <button type="button" class="dispatch-header-btn" id="btnMobilePreview" aria-pressed="false">
                        <span aria-hidden="true">📱</span>
                        <span>Mobile Preview</span>
                    </button>
                    <button type="button" class="dispatch-header-btn" id="btnDispatchReset">
                        <span aria-hidden="true">&#8635;</span>
                        <span>Reset</span>
                    </button>
                </div>
            </header>

            <!-- ── 2. Dispatch List Workspace ───────────────────────────────────── -->
            <div id="dispatchListView">
                <!-- 3 Top Summary KPI Cards -->
                <section class="server-kpis-grid" aria-label="Dispatch Summary KPIs">
                    <div class="server-kpi-card kpi-pending" id="kpiPendingCard">
                        <div class="kpi-card-header">
                            <span class="server-kpi-label">Pending</span>
                            <span class="server-kpi-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></svg>
                            </span>
                        </div>
                        <div class="server-kpi-val" id="kpiPendingDelivery">0</div>
                        <span class="server-kpi-sub">Target ngayong araw</span>
                    </div>

                    <div class="server-kpi-card kpi-served" id="kpiServedCard">
                        <div class="kpi-card-header">
                            <span class="server-kpi-label">Served Today</span>
                            <span class="server-kpi-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                        </div>
                        <div class="server-kpi-val" id="kpiServedToday">0</div>
                        <span class="server-kpi-sub">Matagumpay na naihatid</span>
                    </div>

                    <div class="server-kpi-card kpi-unserved" id="kpiUnservedCard">
                        <div class="kpi-card-header">
                            <span class="server-kpi-label">Follow-up</span>
                            <span class="server-kpi-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                        </div>
                        <div class="server-kpi-val" id="kpiUnservedFollowup">0</div>
                        <span class="server-kpi-sub">'Di mahanap / tumanggi</span>
                    </div>
                </section>

                <!-- Filter Tabs, Route Selector, Search & Dispatch Queue Cards -->
                <div class="server-dispatch-panel">
                    <div class="server-status-tabs" role="tablist" aria-label="Status Filter">
                        <button type="button" class="btn-status-filter active" data-status="pending" role="tab" aria-selected="true">
                            Nakatakda (<span id="tabCountPending">0</span>)
                        </button>
                        <button type="button" class="btn-status-filter" data-status="served" role="tab" aria-selected="false">
                            Naihatid (<span id="tabCountServed">0</span>)
                        </button>
                        <button type="button" class="btn-status-filter" data-status="unserved" role="tab" aria-selected="false">
                            Hindi Naihatid (<span id="tabCountUnserved">0</span>)
                        </button>
                        <button type="button" class="btn-status-filter" data-status="all" role="tab" aria-selected="false">
                            Lahat
                        </button>
                    </div>

                    <div class="server-filter-bar">
                        <div class="server-filter-col">
                            <select id="purokRouteSelect" class="dispatch-control" aria-label="Purok Route Filter">
                                <option value="all">Lahat ng Purok (All Routes)</option>
                            </select>
                        </div>
                        <div class="server-filter-col">
                            <input type="search"
                                   id="dispatchSearchInput"
                                   class="dispatch-control"
                                   placeholder="Hanapin ang pangalan o kaso..."
                                   aria-label="Search dispatch items by name or case reference">
                        </div>
                    </div>

                    <div class="dispatch-tasks-stream" id="dispatchTasksContainer">
                        <div class="agap-skeleton" role="status" aria-label="Loading field dispatch tasks">
                            <span class="agap-skeleton-line"></span>
                            <span class="agap-skeleton-line" style="width:84%"></span>
                            <span class="agap-skeleton-line" style="width:68%"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 3. Service Logging View (Log Service ->) ─────────────────────── -->
            <div id="serviceLoggingView" style="display: none;" aria-label="Service Logging View">
                <div class="logging-back-row">
                    <button type="button" class="dispatch-back-btn" id="btnBackToDispatchList">
                        &larr; Back to Dispatch List
                    </button>
                </div>

                <div class="service-logging-card">
                    <div class="logging-summary-header">
                        <div class="logging-summary-main">
                            <div class="logging-meta-overline" id="logMetaOverline">REF &middot; STAGE &middot; ATTEMPT 1</div>
                            <h2 class="logging-party-heading" id="logPartyHeading">Recipient Name (Role)</h2>
                            <div class="logging-address-line">
                                <span aria-hidden="true">📍</span>
                                <span id="logAddressLine">Address not specified</span>
                            </div>
                        </div>
                        <div class="logging-schedule-pill" id="logSchedulePill">
                            <span aria-hidden="true">🕒</span>
                            <span id="logScheduleText">Today &middot; 4:00 PM</span>
                        </div>
                    </div>

                    <form id="serviceLoggingForm" class="logging-form-body" enctype="multipart/form-data">
                        <input type="hidden" name="delivery_id" id="logDeliveryId" value="">
                        <input type="hidden" name="paanyaya_attempt_id" id="logPaanyayaAttemptId" value="">

                        <div class="logging-field-group">
                            <label for="logOfficerReturn" class="logging-field-label">
                                Log Officer's Return <span class="required-mark">*</span>
                            </label>
                            <select id="logOfficerReturn" name="return_outcome" class="logging-input" required>
                                <option value="served_personal">&#10003; Served Personally (Personal na Naihatid)</option>
                                <option value="served_substituted">Served via Substituted Service (Iniwan sa Kasama sa Bahay)</option>
                                <option value="served_refused">Refused to Sign / Tendered (Tumanggi Lumagda)</option>
                                <option value="unserved_not_found">Unserved &mdash; Address Not Found ('Di Mahanap ang Tirahan)</option>
                                <option value="unserved_moved_out">Unserved &mdash; Party Moved Out (Lumipat na)</option>
                                <option value="unserved_no_one_home">Unserved &mdash; No One Home (Walang Tao sa Bahay)</option>
                                <option value="unserved_out_of_town">Unserved &mdash; Out of Town / Deceased (Wala sa Lugar / Pumanaw)</option>
                            </select>
                        </div>

                        <div class="logging-field-group">
                            <label for="logRecipientName" class="logging-field-label">
                                Pangalan ng Tumanggap / Panig
                            </label>
                            <input type="text"
                                   id="logRecipientName"
                                   name="recipient_name"
                                   class="logging-input"
                                   placeholder="Ilagay ang pangalan ng tumanggap o panig">
                        </div>

                        <div class="logging-field-group">
                            <button type="button" class="logging-attach-btn" id="btnAttachPhotoProof">
                                <span aria-hidden="true">📷</span>
                                <span>Attach Photo Proof</span>
                            </button>
                            <input type="file"
                                   id="logProofFile"
                                   name="proof_file"
                                   accept="image/*"
                                   capture="environment"
                                   style="display: none;">
                            <div id="logPhotoPreviewWrap" class="logging-photo-preview" style="display: none;"></div>
                        </div>

                        <div class="logging-field-group">
                            <label for="logRemarks" class="logging-field-label">
                                Mga Tala ukol sa Paghahatid (Remarks)
                            </label>
                            <textarea id="logRemarks"
                                      name="remarks"
                                      class="logging-textarea"
                                      rows="4"
                                      placeholder="Halimbawa: Personal na tinanggap sa gate ng bahay..."></textarea>
                        </div>

                        <div class="logging-actions-footer">
                            <button type="button" class="logging-btn-secondary" id="btnLogCancel">Cancel</button>
                            <div class="logging-actions-right">
                                <button type="button" class="logging-btn-secondary" id="btnLogSaveDraft">Save Draft</button>
                                <button type="submit" class="logging-btn-primary" id="btnLogSubmitDelivery">
                                    &#10003; Submit Delivery Status
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </section>

    </div>

</div>

<script src="../../assets/js/server-dashboard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/server-dashboard.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
