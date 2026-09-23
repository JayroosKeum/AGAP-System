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
            <button type="button" class="nav-tab-btn" data-tab-status="in_progress">
                In Progress (Mediation / Conciliation) <span class="tab-count-pill" id="tabCountProgress">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Docketed">
                Docketed Cases <span class="tab-count-pill" id="tabCountDocketed">0</span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Settled">
                Settled <span class="tab-count-pill" id="tabCountSettled">0</span>
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

                    <button type="button" id="toggleFilterDrawer" class="btn-filter-drawer-toggle">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                        <span>More Filters</span>
                        <span id="filterActiveDot" class="filter-active-dot" style="display: none;"></span>
                    </button>

                    <button type="button" id="clearSearch" class="btn-reset-filters" style="display: none;">
                        Reset Filters
                    </button>
                </div>

                <!-- Collapsible Secondary Filter Drawer -->
                <div id="filterDrawerPanel" class="filter-drawer-panel">
                    <div class="drawer-controls-grid">
                        <div class="drawer-field">
                            <label for="searchStatus">Exact Status</label>
                            <select id="searchStatus" name="status">
                                <option value="">All statuses</option>
                                <option value="Filed">Filed</option>
                                <option value="Under Review">Under Review</option>
                                <option value="Needs Information">Needs Information</option>
                                <option value="Accepted">Accepted</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Docketed">Docketed</option>
                                <option value="Mediation">Mediation</option>
                                <option value="Conciliation">Conciliation</option>
                                <option value="Arbitration">Arbitration</option>
                                <option value="Settled">Settled</option>
                                <option value="Dismissed">Dismissed</option>
                                <option value="CFA Issued">CFA Issued</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </div>
                        <div class="drawer-field">
                            <label for="searchFrom">Incident Date From</label>
                            <input id="searchFrom" name="date_from" type="date">
                        </div>
                        <div class="drawer-field">
                            <label for="searchTo">Incident Date To</label>
                            <input id="searchTo" name="date_to" type="date">
                        </div>
                        <div>
                            <button class="btn-create" type="submit" style="padding: 7px 16px; font-size: 0.84rem;">Apply Filters</button>
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

        <!-- 7-Column Modern Complaints Table -->
        <div class="complaints-table-container">
            <table class="modern-complaints-table">
                <thead>
                    <tr>
                        <th style="min-width: 220px;">Complaint</th>
                        <th style="min-width: 125px;">Case No.</th>
                        <th style="min-width: 140px;">Category</th>
                        <th style="min-width: 190px;">Parties</th>
                        <th style="min-width: 110px;">Incident Date</th>
                        <th style="min-width: 130px;">Status</th>
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

<!-- EDIT COMPLAINT MODAL -->
<div id="editComplaintModal" class="modal">
    <div class="modal-content" style="width: 820px;">
        <div class="modal-header">
            <h2>Edit Complaint</h2>
            <button class="close-btn" onclick="closeEditComplaintModal()">&times;</button>
        </div>

        <form action="../../../backend/api/complaints/update.php" method="POST">
            <input type="hidden" name="complaint_id" id="editComplaintId">

            <div class="form-row-2">
                <div class="form-group">
                    <label for="editCategoryId">Category <span class="required-mark">*</span></label>
                    <select name="category_id" id="editCategoryId" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int)$cat['category_id']; ?>">
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="editComplaintTitle">Complaint Title <span class="required-mark">*</span></label>
                    <input type="text" name="complaint_title" id="editComplaintTitle" maxlength="255" required>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="editIncidentDate">Incident Date <span class="required-mark">*</span></label>
                    <input type="date" name="incident_date" id="editIncidentDate" required>
                </div>
                <div class="form-group">
                    <label for="editIncidentTime">Incident Time</label>
                    <input type="time" name="incident_time" id="editIncidentTime">
                </div>
            </div>

            <div class="form-group">
                <label for="editNarrative">Narrative <span class="required-mark">*</span></label>
                <textarea name="narrative" id="editNarrative" rows="4" maxlength="15000" required></textarea>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="editIncidentLocation">Specific Incident Location <span class="required-mark">*</span></label>
                    <input type="text" name="incident_location" id="editIncidentLocation" maxlength="255" required>
                </div>
                <div class="form-group">
                    <label for="editIncidentLandmark">Landmark</label>
                    <input type="text" name="incident_landmark" id="editIncidentLandmark" maxlength="255">
                </div>
            </div>

            <div class="form-group complaint-map-group">
                <label>Exact Map Location <span class="optional-label">Optional</span></label>
                <input type="hidden" name="map_location_state" value="unchanged" data-map-state>
                <input type="hidden" name="location_latitude" value="" data-map-latitude>
                <input type="hidden" name="location_longitude" value="" data-map-longitude>
                <p class="map-help">Click the map to change the saved pin, or clear it to remove the exact map point.</p>
                <div id="editComplaintMap" class="complaint-location-map compact-map" aria-label="Map for selecting the exact incident location"></div>
                <div class="map-selection-row">
                    <span class="map-selection-status" data-map-status>Loading saved map point…</span>
                    <button type="button" class="btn-secondary btn-sm" data-clear-map>Clear pin</button>
                </div>
            </div>

            <div class="form-group">
                <label for="editAdditionalDetails">Additional Details</label>
                <textarea name="additional_details" id="editAdditionalDetails" rows="2" maxlength="5000"></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeEditComplaintModal()">Cancel</button>
                <button type="submit" class="btn-create">Update Complaint</button>
            </div>
        </form>
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
