<?php

session_start();

if (
    !isset($_SESSION['role_id']) ||
    !in_array($_SESSION['role_id'], [1, 2])
) {
    die('Access Denied');
}

$complaintFlash = $_SESSION['complaint_flash'] ?? null;
unset($_SESSION['complaint_flash']);

require_once __DIR__ . '/../../../backend/config/database.php';
$categories = [];
try {
    $db = (new Database())->connect();
    $categories = $db->query('SELECT category_id, category_name FROM complaint_categories ORDER BY category_id ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

include '../../layouts/header.php';

?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/search.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/search.css'); ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <div>
                <h1>Complaints</h1>
                <p>Record, monitor, and manage community concerns and dispute proceedings.</p>
            </div>

            <a
                href="complaint-create.php"
                class="btn-create"
                style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Add Complaint
            </a>
        </div>

        <?php if ($complaintFlash && !empty($complaintFlash['message'])): ?>
            <div class="complaint-alert-banner alert-banner-<?php echo (($complaintFlash['type'] ?? '') === 'success') ? 'success' : 'error'; ?>" role="alert">
                <span><?php echo htmlspecialchars($complaintFlash['message']); ?></span>
                <button type="button" class="alert-close-btn" onclick="this.parentElement.remove()" aria-label="Close alert">&times;</button>
            </div>
        <?php endif; ?>

        <!-- KPI Metrics Summary Grid -->
        <div class="complaints-kpi-grid">
            <div class="kpi-card active" data-kpi-filter="all" title="Click to show all complaints">
                <div class="kpi-icon-wrap kpi-icon-all">📋</div>
                <div class="kpi-content">
                    <span class="kpi-value" id="kpiCountAll">0</span>
                    <span class="kpi-label">All Complaints</span>
                </div>
            </div>
            <div class="kpi-card" data-kpi-filter="Under Review" title="Click to show complaints under review">
                <div class="kpi-icon-wrap kpi-icon-review">⏳</div>
                <div class="kpi-content">
                    <span class="kpi-value" id="kpiCountReview">0</span>
                    <span class="kpi-label">Under Review</span>
                </div>
            </div>
            <div class="kpi-card" data-kpi-filter="in_progress" title="Click to show active mediation / conciliation hearings">
                <div class="kpi-icon-wrap kpi-icon-progress">⚖️</div>
                <div class="kpi-content">
                    <span class="kpi-value" id="kpiCountProgress">0</span>
                    <span class="kpi-label">In Progress</span>
                </div>
            </div>
            <div class="kpi-card" data-kpi-filter="Settled" title="Click to show settled complaints">
                <div class="kpi-icon-wrap kpi-icon-settled">✅</div>
                <div class="kpi-content">
                    <span class="kpi-value" id="kpiCountSettled">0</span>
                    <span class="kpi-label">Settled / Closed</span>
                </div>
            </div>
        </div>

        <!-- Quick Status Navigation Tabs -->
        <div class="complaints-nav-tabs" role="tablist">
            <button type="button" class="nav-tab-btn active" data-tab-status="">
                All <span class="tab-count-pill" id="tabCountAll">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Under Review">
                Under Review <span class="tab-count-pill" id="tabCountReview">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Docketed">
                Docketed <span class="tab-count-pill" id="tabCountDocketed">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Mediation">
                Mediation <span class="tab-count-pill" id="tabCountMediation">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Conciliation">
                Conciliation <span class="tab-count-pill" id="tabCountConciliation">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Arbitration">
                Arbitration <span class="tab-count-pill" id="tabCountArbitration">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Settled">
                Settled <span class="tab-count-pill" id="tabCountSettled">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Dismissed">
                Dismissed <span class="tab-count-pill" id="tabCountDismissed">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="CFA">
                CFA <span class="tab-count-pill" id="tabCountCfa">0</span>
            </button>
        </div>

        <!-- Compact Search & Filter Toolbar -->
        <section class="complaints-toolbar-card">
            <form id="recordsSearchForm" class="complaints-toolbar-form">
                <div class="toolbar-main-row">
                    <div class="search-box-wrap">
                        <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input id="searchQuery" name="q" type="search" class="search-input-field" placeholder="Search by complaint no., title, resident name, or case no..." autocomplete="off">
                        <button type="button" id="clearSearchInput" class="search-clear-btn" title="Clear keyword">&times;</button>
                    </div>

                    <select id="searchType" name="case_type" class="toolbar-select">
                        <option value="">All Case Types</option>
                        <option value="Civil">Civil</option>
                        <option value="Criminal">Criminal</option>
                    </select>

                    <select id="searchCategory" name="category_id" class="toolbar-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int)$cat['category_id']; ?>">
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="searchSort" name="sort_by" class="toolbar-select" title="Order records">
                        <option value="incident_date">Sort: Incident Date (Newest)</option>
                        <option value="incident_date_asc">Sort: Incident Date (Oldest)</option>
                        <option value="complaint_number">Sort: Complaint No. (Asc)</option>
                        <option value="complaint_number_desc">Sort: Complaint No. (Desc)</option>
                        <option value="case_number">Sort: Case No. (Asc)</option>
                        <option value="case_number_desc">Sort: Case No. (Desc)</option>
                        <option value="category_name">Sort: Category (A-Z)</option>
                        <option value="intake_status">Sort: Intake (Review → Docketed)</option>
                        <option value="current_stage">Sort: Stage (Mediation → Conciliation → Arbitration)</option>
                        <option value="final_disposition">Sort: Disposition (Pending → Resolved)</option>
                    </select>
                    <input type="hidden" id="sortOrder" name="sort_order" value="DESC">

                    <button type="button" id="toggleFilterDrawer" class="btn-filter-drawer-toggle">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                        <span>More Filters</span>
                        <span id="filterActiveDot" class="filter-active-dot" style="display: none;"></span>
                    </button>

                    <button type="button" id="clearSearch" class="btn-reset-filters" style="display: none;">
                        Reset Filters
                    </button>
                </div>

                <!-- Collapsible Secondary Filter Drawer with Distinct Lifecycle Attributes -->
                <div id="filterDrawerPanel" class="filter-drawer-panel">
                    <div class="drawer-controls-grid">
                        <div class="drawer-field">
                            <label for="searchIntake">1. Intake Status</label>
                            <select id="searchIntake" name="intake_status">
                                <option value="">All Intake States</option>
                                <option value="Under Review">Under Review (Screening / Pre-docketing)</option>
                                <option value="Docketed">Docketed (Case Assigned)</option>
                            </select>
                        </div>
                        <div class="drawer-field">
                            <label for="searchStage">2. Dispute Stage</label>
                            <select id="searchStage" name="current_stage">
                                <option value="">All Procedural Stages</option>
                                <option value="Mediation">Mediation (Punong Barangay)</option>
                                <option value="Conciliation">Conciliation (Pangkat ng Tagapagkasundo)</option>
                                <option value="Arbitration">Arbitration (Binding Agreement)</option>
                                <option value="None">None / Pre-docketing</option>
                            </select>
                        </div>
                        <div class="drawer-field">
                            <label for="searchDisposition">3. Final Disposition</label>
                            <select id="searchDisposition" name="final_disposition">
                                <option value="">All Dispositions</option>
                                <option value="Pending">Pending (Ongoing Proceedings)</option>
                                <option value="Amicable Settlement">Amicable Settlement (Mutual Agreement)</option>
                                <option value="Arbitration Award">Arbitration Award (Binding Decision)</option>
                                <option value="Certificate to File Action (CFA)">Certificate to File Action (CFA)</option>
                                <option value="Dismissed / Dropped">Dismissed / Dropped</option>
                            </select>
                        </div>
                        <input type="hidden" id="searchStatus" name="status" value="">
                        <div class="drawer-field">
                            <label for="searchFrom">Incident Date From</label>
                            <input id="searchFrom" name="date_from" type="date">
                        </div>
                        <div class="drawer-field">
                            <label for="searchTo">Incident Date To</label>
                            <input id="searchTo" name="date_to" type="date">
                        </div>
                        <div style="display: flex; align-items: flex-end;">
                            <button class="btn-create" type="submit" style="padding: 7px 16px; font-size: 0.84rem; height: 38px;">Apply Filters</button>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <!-- Results Summary & Active Filter Indicator -->
        <div class="results-meta-bar">
            <span id="resultSummary" aria-live="polite">Loading complaint records...</span>
            <div id="activeFilterChips" class="active-filters-chips"></div>
        </div>

        <!-- 7-Column Modern Complaints Table with Clickable Sortable Headers -->
        <div class="complaints-table-container">
            <table class="modern-complaints-table">
                <thead>
                    <tr>
                        <th class="sortable-th" data-sort-key="complaint" style="min-width: 210px;" title="Click to sort by Complaint (A-Z / Z-A)">
                            <span class="th-content">Complaint <span class="sort-indicator" id="sortInd_complaint">⇅</span></span>
                        </th>
                        <th class="sortable-th" data-sort-key="case_no" style="min-width: 125px;" title="Click to sort by Case Number">
                            <span class="th-content">Case No. <span class="sort-indicator" id="sortInd_case_no">⇅</span></span>
                        </th>
                        <th class="sortable-th" data-sort-key="category" style="min-width: 140px;" title="Click to sort by Category">
                            <span class="th-content">Category <span class="sort-indicator" id="sortInd_category">⇅</span></span>
                        </th>
                        <th class="sortable-th" data-sort-key="parties" style="min-width: 180px;" title="Click to sort by Parties">
                            <span class="th-content">Parties <span class="sort-indicator" id="sortInd_parties">⇅</span></span>
                        </th>
                        <th class="sortable-th sort-active-desc" data-sort-key="date" style="min-width: 115px;" title="Click to sort by Incident Date">
                            <span class="th-content">Incident Date <span class="sort-indicator" id="sortInd_date">▼</span></span>
                        </th>
                        <th class="sortable-th" data-sort-key="lifecycle" style="min-width: 200px;" title="Click to sort by Status">
                            <span class="th-content">Status <span class="sort-indicator" id="sortInd_lifecycle">⇅</span></span>
                        </th>
                        <th style="min-width: 150px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="complaintTable">
                    <tr>
                        <td colspan="7" class="table-empty-wrap">
                            <div class="empty-icon-circle">⌛</div>
                            <div class="empty-state-title">Loading complaint records...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Modern Pagination Bar (10 items per page) -->
        <div id="complaintPagination" class="complaints-pagination" aria-label="Complaints pagination" style="display: none;">
            <span id="complaintPaginationSummary" class="complaints-pagination-summary" aria-live="polite">Showing 0 complaints</span>
            <div id="complaintPaginationControls" class="complaints-pagination-controls"></div>
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

<!-- DELETE MODAL -->
<div id="deleteComplaintModal" class="modal">
    <div class="modal-content" style="width: 440px;">
        <div class="modal-header" style="border-bottom: 0; padding-bottom: 0;">
            <h2 style="color: #b91c1c; display: flex; align-items: center; gap: 8px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                Delete Complaint
            </h2>
            <button class="close-btn" onclick="closeDeleteComplaintModal()">&times;</button>
        </div>
        <div style="padding: 10px 0 20px;">
            <p style="margin: 0; color: #475569; font-size: 0.95rem; line-height: 1.5;">
                Are you sure you want to delete this complaint record? This action cannot be undone.
            </p>
            <input type="hidden" id="deleteComplaintId">
        </div>
        <div class="modal-actions" style="margin-top: 0; border-top: 1px solid #f1f5f9; padding-top: 16px;">
            <button type="button" class="btn-secondary" onclick="closeDeleteComplaintModal()">Cancel</button>
            <button type="button" class="btn-danger" onclick="confirmDeleteComplaint()">Delete Complaint</button>
        </div>
    </div>
</div>

<script>
window.agapComplaintFlash = <?php echo json_encode(
    $complaintFlash,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
); ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>
<script src="../../assets/js/search.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/search.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
