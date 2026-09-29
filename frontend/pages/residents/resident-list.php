<?php
session_start();

if (
    !isset($_SESSION['role_id']) ||
    !in_array($_SESSION['role_id'], [1, 2])
) {
    die('Access Denied');
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/residents.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/residents.css'); ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <!-- Modern Page Header -->
        <div class="page-header">
            <div class="page-header-text">
                <span class="page-eyebrow">Barangay Tumana / Registry</span>
                <h1>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    Resident Profiles
                </h1>
                <p>Maintain Barangay Tumana identity directory and residency details for party verification and dispute proceedings.</p>
            </div>

            <button
                type="button"
                class="btn-create-profile"
                onclick="openAddModal()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add Resident Profile
            </button>
        </div>

        <?php if (!empty($_SESSION['resident_flash'])): ?>
            <?php
            $flash = $_SESSION['resident_flash'];
            unset($_SESSION['resident_flash']);
            $flashClass = ($flash['type'] === 'success') ? 'alert-success' : 'alert-danger';
            ?>
            <div class="resident-flash <?php echo $flashClass; ?>">
                <span><?php echo htmlspecialchars($flash['message']); ?></span>
                <button type="button" class="alert-close-btn" onclick="this.parentElement.remove()" style="background:transparent;border:0;cursor:pointer;font-size:18px;">&times;</button>
            </div>
        <?php endif; ?>

        <!-- KPI Metrics Summary Grid -->
        <div class="resident-kpi-grid">
            <div class="resident-kpi-card active" data-resident-filter="all" id="kpiAllCard" title="Click to view all resident profiles">
                <div class="resident-kpi-icon icon-all">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
                <div class="resident-kpi-content">
                    <span class="resident-kpi-value" id="kpiTotalCount">0</span>
                    <span class="resident-kpi-label">All Profiles</span>
                </div>
            </div>

            <div class="resident-kpi-card" data-resident-filter="permanent" id="kpiPermCard" title="Click to filter permanent residents">
                <div class="resident-kpi-icon icon-permanent">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                </div>
                <div class="resident-kpi-content">
                    <span class="resident-kpi-value" id="kpiPermanentCount">0</span>
                    <span class="resident-kpi-label">Permanent</span>
                </div>
            </div>

            <div class="resident-kpi-card" data-resident-filter="tenant" id="kpiTenantCard" title="Click to filter tenants">
                <div class="resident-kpi-icon icon-tenant">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                </div>
                <div class="resident-kpi-content">
                    <span class="resident-kpi-value" id="kpiTenantCount">0</span>
                    <span class="resident-kpi-label">Tenants</span>
                </div>
            </div>

            <div class="resident-kpi-card" data-resident-filter="non-resident" id="kpiNonResidentCard" title="Click to filter non-residents">
                <div class="resident-kpi-icon icon-non-resident">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="8.5" cy="7" r="4"></circle>
                        <line x1="18" y1="8" x2="23" y2="13"></line>
                        <line x1="23" y1="8" x2="18" y2="13"></line>
                    </svg>
                </div>
                <div class="resident-kpi-content">
                    <span class="resident-kpi-value" id="kpiNonResidentCount">0</span>
                    <span class="resident-kpi-label">Non-Residents</span>
                </div>
            </div>

            <div class="resident-kpi-card" data-resident-filter="purok" id="kpiPurokCard" title="Number of distinct puroks registered">
                <div class="resident-kpi-icon icon-purok">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg>
                </div>
                <div class="resident-kpi-content">
                    <span class="resident-kpi-value" id="kpiPurokCount">0</span>
                    <span class="resident-kpi-label">Active Puroks</span>
                </div>
            </div>
        </div>

        <!-- Search & Filter Toolbar Card -->
        <div class="resident-toolbar-card">
            <div class="resident-toolbar-row">
                <div class="resident-search-box">
                    <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="search" id="residentSearchInput" placeholder="Search by name, purok, address, or ID..." autocomplete="off">
                    <button type="button" id="clearResidentSearch" class="search-clear-btn" title="Clear search" style="display: none;">&times;</button>
                </div>

                <div class="resident-filter-group">
                    <select id="filterPurok" class="resident-select-filter" aria-label="Filter by Purok in Tumana">
                        <option value="">All Puroks & Areas</option>
                        <option value="Non-Resident">Non-Resident / Outside Tumana</option>
                        <optgroup label="Numbered Puroks">
                            <option value="Purok 1">Purok 1</option>
                            <option value="Purok 2">Purok 2</option>
                            <option value="Purok 3">Purok 3</option>
                            <option value="Purok 4">Purok 4</option>
                            <option value="Purok 5">Purok 5</option>
                            <option value="Purok 6">Purok 6</option>
                            <option value="Purok 7">Purok 7</option>
                            <option value="Purok 8">Purok 8</option>
                        </optgroup>
                        <optgroup label="Zones & Compounds">
                            <option value="Doña Petra">Doña Petra Compound</option>
                            <option value="Bagong Farmers">Bagong Farmers</option>
                            <option value="Bukang Liwayway">Bukang Liwayway</option>
                            <option value="Libis Tumana">Libis Tumana</option>
                            <option value="Bagong Purok">Sitio Bagong Purok</option>
                            <option value="Palay">Palay Area</option>
                            <option value="Mais">Mais Area</option>
                            <option value="Singkamas">Singkamas Area</option>
                        </optgroup>
                    </select>

                    <select id="filterTenant" class="resident-select-filter" aria-label="Filter by Residency Type">
                        <option value="">All Residency Types</option>
                        <option value="0">Permanent Resident</option>
                        <option value="1">Tenant</option>
                        <option value="2">Non-Resident</option>
                    </select>

                    <select id="filterSort" class="resident-select-filter" aria-label="Sort Profiles">
                        <option value="id_desc">Sort: Newest First</option>
                        <option value="id_asc">Sort: Oldest First</option>
                        <option value="name_asc">Sort: Name (A &rarr; Z)</option>
                        <option value="name_desc">Sort: Name (Z &rarr; A)</option>
                        <option value="purok_asc">Sort: Purok (Asc)</option>
                    </select>

                    <button type="button" id="btnResetFilters" class="btn-reset-filters" style="display: none;">
                        Reset Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Results Meta Bar & Active Chips -->
        <div class="results-meta-bar">
            <span id="residentMetaSummary" aria-live="polite">Loading resident profiles...</span>
            <div id="activeResidentFilters" class="active-filters-chips"></div>
        </div>

        <!-- Modern Data Table -->
        <div class="resident-table-card">
            <div class="table-responsive">
                <table class="modern-resident-table">
                    <thead>
                        <tr>
                            <th style="min-width: 230px;">Resident Name</th>
                            <th style="min-width: 250px;">Address</th>
                            <th style="min-width: 140px;">Purok</th>
                            <th style="min-width: 130px;">Residency</th>
                            <th style="min-width: 160px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="residentTable">
                        <tr>
                            <td colspan="5" class="table-loading-wrap">
                                <div class="empty-icon-circle">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="12" y1="2" x2="12" y2="6"></line>
                                        <line x1="12" y1="18" x2="12" y2="22"></line>
                                        <line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line>
                                        <line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line>
                                        <line x1="2" y1="12" x2="6" y2="12"></line>
                                        <line x1="18" y1="12" x2="22" y2="12"></line>
                                        <line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line>
                                        <line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line>
                                    </svg>
                                </div>
                                <div class="empty-state-title">Loading resident profiles...</div>
                                <div class="empty-state-desc">Fetching identity directory from database</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modern Pagination Bar (Strictly 25 items per page) -->
        <div id="residentPagination" class="resident-pagination" aria-label="Resident profiles pagination" style="display: none;">
            <span id="residentPaginationSummary" class="resident-pagination-summary" aria-live="polite">Showing 0 profiles</span>
            <div id="residentPaginationControls" class="resident-pagination-controls"></div>
        </div>

    </div>

</div>

<!-- ADD RESIDENT MODAL -->
<div id="addResidentModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">

    <div class="modal-content">

        <div class="modal-header">
            <div class="modal-header-text">
                <h2 id="addModalTitle">Add Resident Profile</h2>
                <p>Register a resident identity record in Barangay Tumana for party verification and dispute proceedings.</p>
            </div>

            <button
                type="button"
                class="close-btn"
                onclick="closeAddModal()"
                aria-label="Close modal">
                &times;
            </button>
        </div>

        <div id="addResidentAlert" class="modal-alert" style="display:none;" role="alert"></div>

        <form
            action="../../../backend/api/residents/create.php"
            method="POST">

            <!-- Section 1: Personal Information -->
            <div class="modal-section-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                Personal Information
            </div>

            <div class="resident-form-grid">

                <div class="form-group">
                    <label>First Name <span class="required-mark">*</span></label>
                    <input
                        type="text"
                        name="first_name"
                        placeholder="e.g. Juan"
                        maxlength="100"
                        required>
                </div>

                <div class="form-group">
                    <label>Middle Name</label>
                    <input
                        type="text"
                        name="middle_name"
                        placeholder="e.g. Santos (Optional)"
                        maxlength="100">
                </div>

                <div class="form-group">
                    <label>Last Name <span class="required-mark">*</span></label>
                    <input
                        type="text"
                        name="last_name"
                        placeholder="e.g. Dela Cruz"
                        maxlength="100"
                        required>
                </div>

                <div class="form-group">
                    <label>Birth Date</label>
                    <input
                        type="date"
                        name="birth_date">
                </div>

                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Civil Status</label>
                    <select name="civil_status">
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Widowed">Widowed</option>
                        <option value="Separated">Separated</option>
                    </select>
                </div>

            </div>

            <!-- Section 2: Residency & Contact Information -->
            <div class="modal-section-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                Residency & Contact Information (Barangay Tumana)
            </div>

            <div class="resident-form-grid">

                <div class="form-group">
                    <label>Contact Number</label>
                    <input
                        type="text"
                        name="contact_no"
                        placeholder="e.g. 0917 123 4567"
                        maxlength="20"
                        inputmode="tel">
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input
                        type="email"
                        name="email"
                        placeholder="e.g. resident@example.com"
                        maxlength="150">
                </div>

                <!-- Purok Dropdown in Barangay Tumana -->
                <div class="form-group">
                    <label for="addPurok">Purok / Zone (Tumana)</label>
                    <select name="purok" id="addPurok">
                        <option value="">Select Purok / Area</option>
                        <option value="Non-Resident">Non-Resident / Outside Tumana</option>
                        <optgroup label="Numbered Puroks">
                            <option value="Purok 1">Purok 1</option>
                            <option value="Purok 2">Purok 2</option>
                            <option value="Purok 3">Purok 3</option>
                            <option value="Purok 4">Purok 4</option>
                            <option value="Purok 5">Purok 5</option>
                            <option value="Purok 6">Purok 6</option>
                            <option value="Purok 7">Purok 7</option>
                            <option value="Purok 8">Purok 8</option>
                        </optgroup>
                        <optgroup label="Zones & Compounds">
                            <option value="Doña Petra">Doña Petra Compound</option>
                            <option value="Bagong Farmers">Bagong Farmers</option>
                            <option value="Bukang Liwayway">Bukang Liwayway</option>
                            <option value="Libis Tumana">Libis Tumana</option>
                            <option value="Bagong Purok">Sitio Bagong Purok</option>
                            <option value="Palay">Palay Area</option>
                            <option value="Mais">Mais Area</option>
                            <option value="Singkamas">Singkamas Area</option>
                        </optgroup>
                    </select>
                    <span class="form-hint-text" id="addPurokHint">Automatically detected when pinning location on the map, or select manually.</span>
                </div>

                <div class="form-group">
                    <label for="addTenant">Residency Status</label>
                    <select name="is_tenant" id="addTenant" onchange="handleResidencyStatusChange('add', this.value)">
                        <option value="0">Permanent Resident</option>
                        <option value="1">Tenant / Renter</option>
                        <option value="2">Non-Resident</option>
                    </select>
                </div>

                <!-- Complete Address with Pin Location on Map -->
                <div class="form-group full-width">
                    <div class="address-header-row">
                        <label for="addAddress">Complete Street Address <span class="required-mark">*</span></label>
                        <button
                            type="button"
                            class="btn-pin-map-toggle"
                            id="toggleAddMapBtn"
                            onclick="toggleResidentMap('add')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            <span id="addMapBtnText">Pin on Map to Obtain Address</span>
                        </button>
                    </div>

                    <!-- Collapsible Interactive Map Box -->
                    <div id="addResidentMapWrap" class="resident-map-box" style="display: none;">
                        <div class="map-box-header">
                            <div class="map-hint-text">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                Click or drag the pin anywhere in <strong>Barangay Tumana</strong> to auto-fill the street address.
                            </div>
                            <span id="addMapPinStatus" class="map-pin-status-badge">Click map to pin</span>
                        </div>
                        <div id="addResidentLeafletMap" class="resident-mini-leaflet-map"></div>
                    </div>

                    <textarea
                        name="address"
                        id="addAddress"
                        placeholder="e.g. #24 Moscow Street, Purok 6, Barangay Tumana, Marikina City"
                        rows="3"
                        required></textarea>
                    <span class="form-hint-text">Format: [House No.] [Street], [Subdivision/Purok], Barangay Tumana, Marikina City. Pinning the map will automatically assemble and fill these components.</span>
                </div>

            </div>

            <div class="modal-actions">
                <button
                    type="button"
                    class="btn-reset-filters"
                    onclick="closeAddModal()">
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn-create-profile">
                    Save Resident Profile
                </button>
            </div>

        </form>

    </div>

</div>

<!-- VIEW RESIDENT MODAL (IDENTITY CARD) -->
<div id="viewResidentModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">

    <div class="modal-content">

        <div class="modal-header">
            <div class="modal-header-text">
                <h2 id="viewModalTitle">Resident Profile Details</h2>
                <p>Identity verification details and recorded residence information in Barangay Tumana.</p>
            </div>

            <button
                type="button"
                class="close-btn"
                onclick="closeViewModal()"
                aria-label="Close modal">
                &times;
            </button>
        </div>

        <div id="residentDetails">
            <!-- Dynamically populated by viewResident() -->
        </div>

    </div>

</div>

<!-- EDIT RESIDENT MODAL -->
<div id="editResidentModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">

    <div class="modal-content">

        <div class="modal-header">
            <div class="modal-header-text">
                <h2 id="editModalTitle">Edit Resident Profile</h2>
                <p>Update personal identity, contact details, or residency address in Barangay Tumana.</p>
            </div>

            <button
                type="button"
                class="close-btn"
                onclick="closeEditModal()"
                aria-label="Close modal">
                &times;
            </button>
        </div>

        <div id="editResidentAlert" class="modal-alert" style="display:none;" role="alert"></div>

        <form
            action="../../../backend/api/residents/update.php"
            method="POST">

            <input
                type="hidden"
                name="resident_id"
                id="editResidentId">

            <!-- Section 1: Personal Information -->
            <div class="modal-section-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                Personal Information
            </div>

            <div class="resident-form-grid">

                <div class="form-group">
                    <label>First Name <span class="required-mark">*</span></label>
                    <input
                        type="text"
                        name="first_name"
                        id="editFirstName"
                        maxlength="100"
                        required>
                </div>

                <div class="form-group">
                    <label>Middle Name</label>
                    <input
                        type="text"
                        name="middle_name"
                        id="editMiddleName"
                        maxlength="100">
                </div>

                <div class="form-group">
                    <label>Last Name <span class="required-mark">*</span></label>
                    <input
                        type="text"
                        name="last_name"
                        id="editLastName"
                        maxlength="100"
                        required>
                </div>

                <div class="form-group">
                    <label>Birth Date</label>
                    <input
                        type="date"
                        name="birth_date"
                        id="editBirthDate">
                </div>

                <div class="form-group">
                    <label>Gender</label>
                    <select
                        name="gender"
                        id="editGender">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Civil Status</label>
                    <select
                        name="civil_status"
                        id="editCivilStatus">
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Widowed">Widowed</option>
                        <option value="Separated">Separated</option>
                    </select>
                </div>

            </div>

            <!-- Section 2: Residency & Contact Information -->
            <div class="modal-section-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                Residency & Contact Information (Barangay Tumana)
            </div>

            <div class="resident-form-grid">

                <div class="form-group">
                    <label>Contact Number</label>
                    <input
                        type="text"
                        name="contact_no"
                        id="editContactNo"
                        maxlength="20"
                        inputmode="tel">
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input
                        type="email"
                        name="email"
                        id="editEmail"
                        maxlength="150">
                </div>

                <!-- Purok Dropdown in Barangay Tumana -->
                <div class="form-group">
                    <label for="editPurok">Purok / Zone (Tumana)</label>
                    <select name="purok" id="editPurok">
                        <option value="">Select Purok / Area</option>
                        <option value="Non-Resident">Non-Resident / Outside Tumana</option>
                        <optgroup label="Numbered Puroks">
                            <option value="Purok 1">Purok 1</option>
                            <option value="Purok 2">Purok 2</option>
                            <option value="Purok 3">Purok 3</option>
                            <option value="Purok 4">Purok 4</option>
                            <option value="Purok 5">Purok 5</option>
                            <option value="Purok 6">Purok 6</option>
                            <option value="Purok 7">Purok 7</option>
                            <option value="Purok 8">Purok 8</option>
                        </optgroup>
                        <optgroup label="Zones & Compounds">
                            <option value="Doña Petra">Doña Petra Compound</option>
                            <option value="Bagong Farmers">Bagong Farmers</option>
                            <option value="Bukang Liwayway">Bukang Liwayway</option>
                            <option value="Libis Tumana">Libis Tumana</option>
                            <option value="Bagong Purok">Sitio Bagong Purok</option>
                            <option value="Palay">Palay Area</option>
                            <option value="Mais">Mais Area</option>
                            <option value="Singkamas">Singkamas Area</option>
                        </optgroup>
                    </select>
                    <span class="form-hint-text" id="editPurokHint">Automatically detected when pinning location on the map, or select manually.</span>
                </div>

                <div class="form-group">
                    <label for="editTenant">Residency Status</label>
                    <select
                        name="is_tenant"
                        id="editTenant"
                        onchange="handleResidencyStatusChange('edit', this.value)">
                        <option value="0">Permanent Resident</option>
                        <option value="1">Tenant / Renter</option>
                        <option value="2">Non-Resident</option>
                    </select>
                </div>

                <!-- Complete Address with Pin Location on Map -->
                <div class="form-group full-width">
                    <div class="address-header-row">
                        <label for="editAddress">Complete Street Address <span class="required-mark">*</span></label>
                        <button
                            type="button"
                            class="btn-pin-map-toggle"
                            id="toggleEditMapBtn"
                            onclick="toggleResidentMap('edit')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            <span id="editMapBtnText">Pin on Map to Obtain Address</span>
                        </button>
                    </div>

                    <!-- Collapsible Interactive Map Box for Edit -->
                    <div id="editResidentMapWrap" class="resident-map-box" style="display: none;">
                        <div class="map-box-header">
                            <div class="map-hint-text">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                Click or drag the pin anywhere in <strong>Barangay Tumana</strong> to auto-fill the street address.
                            </div>
                            <span id="editMapPinStatus" class="map-pin-status-badge">Click map to pin</span>
                        </div>
                        <div id="editResidentLeafletMap" class="resident-mini-leaflet-map"></div>
                    </div>

                    <textarea
                        name="address"
                        id="editAddress"
                        placeholder="e.g. #24 Moscow Street, Purok 6, Barangay Tumana, Marikina City"
                        rows="3"
                        required></textarea>
                    <span class="form-hint-text">Format: [House No.] [Street], [Subdivision/Purok], Barangay Tumana, Marikina City. Pinning the map will automatically assemble and fill these components.</span>
                </div>

            </div>

            <div class="modal-actions">
                <button
                    type="button"
                    class="btn-reset-filters"
                    onclick="closeEditModal()">
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn-create-profile">
                    Update Resident Profile
                </button>
            </div>

        </form>

    </div>

</div>

<!-- DELETE RESIDENT MODAL -->
<div id="deleteResidentModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">

    <div class="modal-content delete-modal">

        <div class="delete-modal-icon-wrap">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
        </div>

        <div class="modal-header" style="border-bottom: 0; padding-bottom: 0; margin-bottom: 8px;">
            <div class="modal-header-text">
                <h2 id="deleteModalTitle" style="color: #dc2626;">Delete Resident Profile</h2>
            </div>

            <button
                type="button"
                class="close-btn"
                onclick="closeDeleteModal()"
                aria-label="Close modal">
                &times;
            </button>
        </div>

        <p>Are you sure you want to delete this resident profile from the directory?</p>

        <div id="deleteResidentNamePreview" class="delete-target-preview">
            Loading profile information...
        </div>

        <p style="font-size: 0.82rem; color: #b91c1c;">
            <strong>Notice:</strong> This action cannot be undone. Associated complaint records referencing this profile will retain their case history.
        </p>

        <input
            type="hidden"
            id="deleteResidentId">

        <div class="modal-actions" style="border-top: 0; padding-top: 0;">
            <button
                type="button"
                class="btn-reset-filters"
                onclick="closeDeleteModal()">
                Cancel
            </button>

            <button
                type="button"
                class="btn-create-profile"
                style="background: #dc2626; border-color: #dc2626;"
                onclick="confirmDeleteResident()">
                Delete Profile
            </button>
        </div>

    </div>

</div>

<!-- Leaflet JS for Map Pinning -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/residents.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/residents.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
