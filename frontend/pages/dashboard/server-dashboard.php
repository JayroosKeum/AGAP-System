<?php
require_once '../../../backend/services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

// Enforce access control: Admin (1), Lupon Clerk (2), Summons Server (4)
if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 4], true)) {
    header('Location: ../auth/login.php');
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

        <div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 8px;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h1 style="margin: 0; font-size: 1.6rem; color: #0f172a;">Summons &amp; Field Dispatch Dashboard</h1>
                </div>
            </div>
        </div>

        <section class="dashboard-overview server-dispatch-view" aria-label="Summons Server Field Dispatch Overview">

            <!-- ── 1. Dispatch Summary KPIs ────────────────────────────────────── -->
            <section class="server-kpis-grid" aria-label="Dispatch Summary KPIs">
                <!-- Pending Delivery -->
                <div class="server-kpi-card kpi-pending" id="kpiPendingCard">
                    <div class="kpi-card-header">
                        <span class="server-kpi-label">Pending Delivery</span>
                        <span class="server-kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></span>
                    </div>
                    <div class="server-kpi-val" id="kpiPendingDelivery">0</div>
                    <span class="server-kpi-sub">Target ngayong araw / bukas</span>
                </div>

                <!-- Served Today -->
                <div class="server-kpi-card kpi-served" id="kpiServedCard">
                    <div class="kpi-card-header">
                        <span class="server-kpi-label">Served Today</span>
                        <span class="server-kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></span>
                    </div>
                    <div class="server-kpi-val" id="kpiServedToday">0</div>
                    <span class="server-kpi-sub">Matagumpay na naihatid</span>
                </div>

                <!-- Unserved / Need Follow-up -->
                <div class="server-kpi-card kpi-unserved" id="kpiUnservedCard">
                    <div class="kpi-card-header">
                        <span class="server-kpi-label">Unserved / Follow-up</span>
                        <span class="server-kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg></span>
                    </div>
                    <div class="server-kpi-val" id="kpiUnservedFollowup">0</div>
                    <span class="server-kpi-sub">Lumipat, 'di mahanap, tumanggi</span>
                </div>
            </section>

            <!-- ── 2. Purok Route Filter & Batch Routing Toolbar ───────────────── -->
            <div class="server-route-toolbar">
                <div class="route-filter-group">
                    <label for="purokRouteSelect" class="route-filter-label">
                        <span>Purok Route Filter:</span>
                    </label>
                    <select id="purokRouteSelect" class="route-filter-select" aria-label="Purok Batch Route Filter">
                        <option value="all">Lahat ng Purok (All Routes)</option>
                    </select>
                </div>

                <div class="server-status-pill-group" role="tablist" aria-label="Status Filter">
                    <button type="button" class="btn-status-filter active" data-status="pending">Nakatakda (Pending)</button>
                    <button type="button" class="btn-status-filter" data-status="served">Naihatid (Served)</button>
                    <button type="button" class="btn-status-filter" data-status="unserved">Hindi Naihatid (Unserved)</button>
                    <button type="button" class="btn-status-filter" data-status="all">Lahat (All)</button>
                </div>
            </div>

            <!-- ── 3. Field Dispatch Tasks Stream & 1-Tap Service Logger ────────── -->
            <section aria-label="Dispatch Tasks List">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
                    <h2 style="font-size:1.3rem; font-weight:800; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px;">
                        <span>Mga Ihahatid na Summons at Notisya</span>
                    </h2>
                    <span id="dispatchTasksCount" style="background:#e0e7ff; color:#3730a3; font-size:0.85rem; font-weight:800; padding:4px 12px; border-radius:9999px;">
                        0 Hatid
                    </span>
                </div>

                <div class="dispatch-tasks-stream" id="dispatchTasksContainer">
                    <div style="padding: 30px; text-align: center; color: #64748b; font-size: 1rem;">
                        Kinukuha ang mga summons para sa field dispatch...
                    </div>
                </div>
            </section>

        </section>

    </div>

</div>

<!-- ── 1-Tap Service Logger Modal with Photo / Camera Upload ──────────────── -->
<div id="serviceLoggerModal" class="server-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="loggerModalTitle">
    <div class="server-modal-card">
        <header class="server-modal-header">
            <h3 id="loggerModalTitle">1-Tap Service Logger</h3>
            <button type="button" id="loggerCloseBtn" style="background:none; border:none; color:#cbd5e1; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
        </header>

        <form id="serviceLoggerForm" class="server-modal-body" enctype="multipart/form-data">
            <input type="hidden" name="delivery_id" id="loggerDeliveryId" value="">
            <input type="hidden" name="action" id="loggerActionInput" value="served">

            <div>
                <label for="loggerRecipientName" style="display:block; font-size:0.88rem; font-weight:700; color:#0f172a; margin-bottom:6px;">
                    Pangalan ng Tumanggap / Pangalan ng Panig:
                </label>
                <input type="text" name="recipient_name" id="loggerRecipientName" style="width:100%; border:1.5px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.95rem; box-sizing:border-box;">
            </div>

            <!-- Unserved Reason Dropdown (visible when action == unserved) -->
            <div id="loggerUnservedReasonWrap" style="display: none;">
                <label for="loggerUnservedReason" style="display:block; font-size:0.88rem; font-weight:700; color:#0f172a; margin-bottom:6px;">
                    Dahilan ng Hindi Pagkakahatid (Unserved Reason):
                </label>
                <select name="unserved_reason" id="loggerUnservedReason" style="width:100%; border:1.5px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.95rem; box-sizing:border-box;">
                    <option value="Address Not Found">Hindi Mahanap ang Tirahan (Address Not Found)</option>
                    <option value="Party Moved Out">Lumipat na ng Tirahan (Party Moved Out)</option>
                    <option value="No One Home">Walang Tao sa Bahay (No One Home)</option>
                    <option value="Deceased">Pumanaw na (Deceased)</option>
                    <option value="Out of Town / Country">Nasa Ibang Lugar / Bansa (Out of Town)</option>
                </select>
            </div>

            <!-- Camera / Photo Upload for Proof-of-Service -->
            <div>
                <label style="display:block; font-size:0.88rem; font-weight:700; color:#0f172a; margin-bottom:6px;">
                    Katibayan ng Paghahatid (Camera / Photo Proof):
                </label>
                <div class="server-photo-preview-wrap" id="loggerPhotoWrap">
                    <div id="loggerPhotoPreview">
                        <span style="display:inline-flex; justify-content:center; margin-bottom:6px; color:#475569;"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg></span><br>
                        <strong>I-click upang kumuha ng litrato gamit ang camera</strong><br>
                        <small style="color:#64748b;">(Halimbawa: Gate ng bahay, larawan ng tumanggap, resibo ng pirma)</small>
                    </div>
                </div>
                <!-- Native camera capture attribute for mobile phones -->
                <input type="file" name="proof_file" id="loggerProofFile" accept="image/*" capture="environment" style="display: none;">
            </div>

            <div>
                <label for="loggerRemarks" style="display:block; font-size:0.88rem; font-weight:700; color:#0f172a; margin-bottom:6px;">
                    Mga Tala ukol sa Paghahatid (Remarks):
                </label>
                <textarea name="remarks" id="loggerRemarks" rows="2" placeholder="Halimbawa: Personal na tinanggap sa gate ng bahay..." style="width:100%; border:1.5px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.9rem; box-sizing:border-box; resize:vertical;"></textarea>
            </div>
        </form>

        <footer class="server-modal-footer">
            <button type="button" id="loggerCancelBtn" class="btn-status-filter" style="border:1px solid #cbd5e1;">Kanselahin</button>
            <button type="button" id="btnSaveServiceLog" class="btn-tap-action btn-tap-served" style="min-height:40px; padding:8px 18px;">
                I-save ang Tala
            </button>
        </footer>
    </div>
</div>

<script src="../../assets/js/server-dashboard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/server-dashboard.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
