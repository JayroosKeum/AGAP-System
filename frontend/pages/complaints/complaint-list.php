<?php

session_start();

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/middleware/rbac.php';
$rbacDb = (new Database())->connect();
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!agapHasRole(['Administrator', 'Lupon Clerk', 'BPSO', 'LISO'], $rbacDb)) {
    $redirect = match ($roleId) {
        3 => '../dashboard/lupon-dashboard.php',
        4 => '../dashboard/server-dashboard.php',
        default => '../auth/login.php',
    };
    header('Location: ' . $redirect);
    exit;
}

$complaintFlash = $_SESSION['complaint_flash'] ?? null;
unset($_SESSION['complaint_flash']);

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';
$categories = [];
try {
    $db = (new Database())->connect();
    $categories = $db->query("
        SELECT category_id, category_name 
        FROM complaint_categories 
        WHERE COALESCE(is_active, 1) = 1 
        ORDER BY 
            CASE 
                WHEN category_name LIKE 'Others%' OR category_name LIKE 'Iba pa%' THEN 1 
                ELSE 0 
            END ASC, 
            category_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

include '../../layouts/header.php';

?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/search.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/search.css'); ?>">
<link rel="stylesheet" href="../../assets/css/agap-list-layout.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/agap-list-layout.css'); ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <?php if ($complaintFlash && !empty($complaintFlash['message'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    window.agapNotify && window.agapNotify(
                        <?php echo json_encode($complaintFlash['message']); ?>,
                        <?php echo json_encode((($complaintFlash['type'] ?? '') === 'success') ? 'success' : 'error'); ?>
                    );
                });
            </script>
        <?php endif; ?>

        <div class="agap-list-shell">
            <div class="agap-list-card">
                <!-- Header -->
                <div class="agap-list-header">
                    <div class="agap-list-title-wrap">
                        <div class="agap-list-title-row">
                            <h1>Complaints</h1>
                        </div>
                        <p class="agap-list-subtitle">Record, monitor, and manage community concerns and dispute proceedings.</p>
                    </div>
                    <a href="complaint-create.php" class="agap-list-primary-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>File Official Complaint</span>
                    </a>
                </div>

                <!-- Unified Form wrapping Underlined Tabs + Inline Controls + Collapsible Filter Drawer -->
                <form id="recordsSearchForm" class="complaints-toolbar-form">
                    <div class="agap-list-subbar">
                        <div class="complaints-nav-tabs" role="tablist" aria-label="Complaint lifecycle status">
                            <button type="button" class="nav-tab-btn active" data-tab-status="">
                                All <span class="tab-count-pill" id="tabCountAll">0</span>
                            </button>
                            <button type="button" class="nav-tab-btn" data-tab-status="Under Review">
                                Verification <span class="tab-count-pill" id="tabCountReview">0</span>
                            </button>
                            <button type="button" class="nav-tab-btn" data-tab-status="Paanyaya">
                                Paanyaya <span class="tab-count-pill" id="tabCountPaanyaya">0</span>
                            </button>
                            <button type="button" class="nav-tab-btn" data-tab-status="Mediation">
                                Mediation <span class="tab-count-pill" id="tabCountMediation">0</span>
                            </button>
                            <button type="button" class="nav-tab-btn" data-tab-status="Conciliation">
                                Conciliation <span class="tab-count-pill" id="tabCountConciliation">0</span>
                            </button>
                            <button type="button" class="nav-tab-btn" data-tab-status="CFA">
                                CFA <span class="tab-count-pill" id="tabCountCfa">0</span>
                            </button>
                            <button type="button" class="nav-tab-btn" data-tab-status="Resolution / Closed">
                                Closed <span class="tab-count-pill" id="tabCountClosed">0</span>
                            </button>
                        </div>

                        <div class="agap-inline-controls">
                            <div class="agap-inline-search">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                <input id="searchQuery" name="q" type="search" placeholder="Search complaints..." autocomplete="off">
                                <button type="button" id="clearSearchInput" class="search-clear-btn" title="Clear keyword">&times;</button>
                            </div>

                            <button type="button" id="toggleFilterDrawer" class="agap-filter-icon-btn" title="Filter and sort options">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                                <span>Filter</span>
                                <span id="filterActiveDot" class="agap-filter-dot" style="display: none;"></span>
                            </button>

                            <button type="button" id="clearSearch" class="agap-reset-link-btn" style="display: none;">
                                Reset
                            </button>
                        </div>
                    </div>

                    <input type="hidden" id="sortOrder" name="sort_order" value="DESC">
                    <input type="hidden" id="searchStatus" name="status" value="">

                    <!-- Collapsible Secondary Filter Drawer -->
                    <div id="filterDrawerPanel" class="filter-drawer-panel">
                        <div class="agap-drawer-grid">
                            <div class="agap-drawer-field">
                                <label for="searchType">Case Type</label>
                                <select id="searchType" name="case_type">
                                    <option value="">All Case Types</option>
                                    <option value="Civil">Civil</option>
                                    <option value="Criminal">Criminal</option>
                                </select>
                            </div>
                            <div class="agap-drawer-field">
                                <label for="searchCategory">Category</label>
                                <select id="searchCategory" name="category_id">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['category_id']; ?>">
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="agap-drawer-field">
                                <label for="searchSort">Sort By</label>
                                <select id="searchSort" name="sort_by" title="Order records">
                                    <option value="case_number_desc">Case No. (Desc)</option>
                                    <option value="case_number">Case No. (Asc)</option>
                                    <option value="complaint_title">Title (A-Z)</option>
                                    <option value="complaint_title_desc">Title (Z-A)</option>
                                    <option value="complaint_number_desc">Complaint No. (Desc)</option>
                                    <option value="complaint_number">Complaint No. (Asc)</option>
                                    <option value="incident_date">Incident Date (Newest)</option>
                                    <option value="incident_date_asc">Incident Date (Oldest)</option>
                                    <option value="category_name">Category (A-Z)</option>
                                    <option value="intake_status">Intake Status</option>
                                    <option value="current_stage">Stage (Mediation → Arbitration)</option>
                                    <option value="final_disposition">Disposition</option>
                                </select>
                            </div>
                            <div class="agap-drawer-field">
                                <label for="searchIntake">Intake Status</label>
                                <select id="searchIntake" name="intake_status">
                                    <option value="">All Intake States</option>
                                    <option value="Under Review">Under Review</option>
                                </select>
                            </div>
                            <div class="agap-drawer-field">
                                <label for="searchStage">Dispute Stage</label>
                                <select id="searchStage" name="current_stage">
                                    <option value="">All Procedural Stages</option>
                                    <option value="Mediation">Mediation (Punong Barangay)</option>
                                    <option value="Conciliation">Conciliation (Pangkat)</option>
                                    <option value="Arbitration">Arbitration (Binding Agreement)</option>
                                </select>
                            </div>
                            <div class="agap-drawer-field">
                                <label for="searchDisposition">Final Disposition</label>
                                <select id="searchDisposition" name="final_disposition">
                                    <option value="">All Dispositions</option>
                                    <option value="Pending">Pending (Ongoing)</option>
                                    <option value="Amicable Settlement">Amicable Settlement</option>
                                    <option value="Arbitration Award">Arbitration Award</option>
                                    <option value="Certificate to File Action (CFA)">Certificate to File Action (CFA)</option>
                                    <option value="Dismissed / Dropped">Dismissed / Dropped</option>
                                </select>
                            </div>
                            <div class="agap-drawer-field">
                                <label for="searchFrom">Incident Date From</label>
                                <input id="searchFrom" name="date_from" type="date">
                            </div>
                            <div class="agap-drawer-field">
                                <label for="searchTo">Incident Date To</label>
                                <input id="searchTo" name="date_to" type="date">
                            </div>
                            <div style="display: flex; align-items: flex-end;">
                                <button class="agap-list-primary-btn" type="submit" style="height: 36px; width: 100%; justify-content: center;">Apply Filters</button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Results Meta & Active Filter Chips -->
                <div class="agap-list-meta">
                    <span id="resultSummary" aria-live="polite">Loading complaint records...</span>
                    <div id="activeFilterChips" class="active-filters-chips"></div>
                </div>

                <!-- 5-Column Modern Complaints Table -->
                <div class="complaints-table-container agap-list-table-wrap">
                    <table class="modern-complaints-table">
                        <thead>
                            <tr>
                                <th class="sortable-th sort-active-desc" data-sort-key="case_no" style="min-width: 140px;" title="Click to sort by Case Number">
                                    <span class="th-content">Case / Ref No. <span class="sort-indicator" id="sortInd_case_no">▼</span></span>
                                </th>
                                <th class="sortable-th" data-sort-key="complaint" style="min-width: 240px;" title="Click to sort by Complaint (A-Z / Z-A)">
                                    <span class="th-content">Complaint <span class="sort-indicator" id="sortInd_complaint">⇅</span></span>
                                </th>
                                <th class="sortable-th" data-sort-key="parties" style="min-width: 200px;" title="Click to sort by Parties">
                                    <span class="th-content">Parties <span class="sort-indicator" id="sortInd_parties">⇅</span></span>
                                </th>
                                <th class="sortable-th" data-sort-key="lifecycle" style="min-width: 170px;" title="Click to sort by Status">
                                    <span class="th-content">Status <span class="sort-indicator" id="sortInd_lifecycle">⇅</span></span>
                                </th>
                                <th style="min-width: 145px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="complaintTable">
                            <tr>
                                <td colspan="5" class="table-empty-wrap">
                                    <div class="empty-state-title">Loading complaint records...</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Bar + Items Per Page -->
                <div id="complaintPagination" class="agap-list-footer" aria-label="Complaints pagination" style="display: none;">
                    <div class="agap-page-size-wrap">
                        <span>Items Per Page</span>
                        <select id="complaintPageSize" class="agap-page-size-select" aria-label="Complaints items per page">
                            <option value="10" selected>10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        <span id="complaintPaginationSummary" class="agap-page-summary" aria-live="polite">Showing 0 complaints</span>
                    </div>
                    <div id="complaintPaginationControls" class="agap-pagination-controls"></div>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- VIEW MODAL -->
<div id="viewComplaintModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Complaint Details</h2>
            <button class="close-btn" onclick="closeViewComplaintModal()">&times;</button>
        </div>
        <div id="complaintDetails"></div>
    </div>
</div>

<!-- ARCHIVE COMPLAINT MODAL -->
<div id="archiveComplaintModal" class="modal">
    <div class="modal-content" style="width: 440px;">
        <div class="modal-header" style="border-bottom: 0; padding-bottom: 0;">
            <h2 style="color: #c2410c; display: flex; align-items: center; gap: 8px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
                Archive Complaint
            </h2>
            <button class="close-btn" onclick="closeArchiveComplaintModal()">&times;</button>
        </div>
        <div style="padding: 10px 0 20px;">
            <p style="margin: 0; color: #475569; font-size: 0.95rem; line-height: 1.5;">
                Archive this complaint record? It will be safely moved to archived records and removed from active processing.
            </p>
            <input type="hidden" id="archiveComplaintId">
        </div>
        <div class="modal-actions" style="margin-top: 0; border-top: 1px solid #f1f5f9; padding-top: 16px;">
            <button type="button" class="btn-secondary" onclick="closeArchiveComplaintModal()">Cancel</button>
            <button type="button" class="btn-warning" style="background: #ea580c; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer;" onclick="confirmArchiveComplaint()">Archive Complaint</button>
        </div>
    </div>
</div>

<script>
window.agapComplaintFlash = <?php echo json_encode(
    $complaintFlash,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
); ?>;
window.agapCsrfToken = <?php echo json_encode(CsrfService::token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>
<script src="../../assets/js/search.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/search.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
