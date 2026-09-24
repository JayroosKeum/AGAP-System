<?php

session_start();

if (
    !isset($_SESSION['role_id']) ||
    !in_array($_SESSION['role_id'], [1, 2])
) {
    die('Access Denied');
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: complaint-list.php');
    exit;
}

$complaintId = (int)$_GET['id'];

include '../../layouts/header.php';

?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <!-- Modern Page Header with Status & Action Toolbar -->
        <div class="complaint-details-header">
            <div class="complaint-details-heading">
                <a href="complaint-list.php" class="back-link">&larr; Back to Complaints</a>
                <div class="complaint-title-row">
                    <h1 id="complaintNumber">Complaint #CMP-...</h1>
                    <span id="complaintStatusBadge" class="status-pill status-filed">Loading...</span>
                </div>
                <p id="complaintTitle" class="complaint-details-subheading"></p>
                <div class="complaint-header-badges">
                    <span id="complaintCaseTypeBadge" class="meta-pill type-pill">Civil</span>
                    <span id="complaintCategoryBadge" class="meta-pill category-pill">Category</span>
                    <a id="complaintCaseLink" href="#" class="meta-pill case-pill" style="display:none;" title="View linked case workspace">
                        📁 Case #<span id="caseNumberBadgeText"></span> &rarr;
                    </a>
                </div>
            </div>

            <div class="complaint-header-actions">
                <a id="viewCaseBtn" href="#" class="btn-create" style="display: none; background: #065f46; text-decoration: none; gap: 6px; align-items: center;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Case Workspace
                </a>
                <button type="button" class="btn-create" id="scheduleMediationButton" onclick="openScheduleMediationModal()">Schedule 1st Mediation</button>
                <a id="editComplaintBtn" href="complaint-edit.php?id=<?php echo $complaintId; ?>" class="btn-secondary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Edit
                </a>
                <button type="button" class="btn-secondary" onclick="openAddPartyModal()">+ Add Party</button>
                <button type="button" class="btn-secondary" onclick="openAddAttachmentModal()">+ Upload Evidence</button>
            </div>
        </div>

        <!-- Main Workspace Grid -->
        <div class="complaint-details-workspace">
            <div class="complaint-intake-grid">

                <!-- LEFT COLUMN -->
                <div class="intake-col">
                    <!-- CARD 1: Incident & Classification -->
                    <div class="intake-card">
                        <div class="intake-card-header">
                            <h3>1. Incident &amp; Classification</h3>
                            <span class="card-subtitle">Category, case type, and timeline details</span>
                        </div>
                        <div class="detail-info-grid">
                            <div class="detail-info-item span-full">
                                <span class="detail-info-label">Complaint Title</span>
                                <span id="infoComplaintTitle" class="detail-info-value">—</span>
                            </div>
                            <div class="detail-info-item">
                                <span class="detail-info-label">Category</span>
                                <span id="infoCategory" class="detail-info-value">—</span>
                            </div>
                            <div class="detail-info-item">
                                <span class="detail-info-label">Case Type</span>
                                <span id="infoCaseType" class="detail-info-value">—</span>
                            </div>
                            <div class="detail-info-item">
                                <span class="detail-info-label">Incident Date</span>
                                <span id="infoIncidentDate" class="detail-info-value">—</span>
                            </div>
                            <div class="detail-info-item">
                                <span class="detail-info-label">Incident Time</span>
                                <span id="infoIncidentTime" class="detail-info-value">—</span>
                            </div>
                            <div class="detail-info-item">
                                <span class="detail-info-label">Date Filed</span>
                                <span id="infoDateFiled" class="detail-info-value">—</span>
                            </div>
                            <div class="detail-info-item">
                                <span class="detail-info-label">Administrative Status</span>
                                <span id="infoStatus" class="detail-info-value">—</span>
                            </div>
                            <div class="detail-info-item span-full">
                                <span class="detail-info-label">Docketed Case</span>
                                <span id="infoDocketCase" class="detail-info-value">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 2: Involved Parties -->
                    <div class="intake-card">
                        <div class="intake-card-header with-action">
                            <div>
                                <h3>2. Involved Parties (<span id="partiesCount">0</span>)</h3>
                                <span class="card-subtitle">Complainants, respondents, and witnesses</span>
                            </div>
                            <button type="button" class="card-header-btn" onclick="openAddPartyModal()">+ Add Party</button>
                        </div>
                        <div id="partiesListContainer" class="parties-detail-list">
                            <div class="empty-detail-state">Loading parties...</div>
                        </div>
                    </div>

                    <!-- CARD 3: Narrative & Facts -->
                    <div class="intake-card">
                        <div class="intake-card-header">
                            <h3>3. Narrative &amp; Facts</h3>
                            <span class="card-subtitle">Statement of the complaint as filed</span>
                        </div>
                        <div class="detail-sub-section">
                            <span class="detail-sub-heading">Statement of Complaint / Narrative</span>
                            <div id="complaintNarrative" class="detail-narrative-box">Loading narrative...</div>
                        </div>

                        <div id="complaintAdditionalDetailsWrap" class="detail-sub-section" style="display:none; margin-top: 14px;">
                            <span class="detail-sub-heading">Additional Details / Prior Attempts</span>
                            <div id="complaintAdditionalDetails" class="detail-narrative-box"></div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div class="intake-col">
                    <!-- CARD 4: Incident Location & Map Pin -->
                    <div class="intake-card location-card">
                        <div class="intake-card-header">
                            <h3>4. Incident Location</h3>
                            <span class="card-subtitle">Geographic location and pinned coordinates</span>
                        </div>
                        <div class="detail-location-info">
                            <div class="detail-location-row">
                                <strong>Specific Location:</strong> <span id="locationAddressText">—</span>
                            </div>
                            <div class="detail-location-row">
                                <strong>Nearby Landmark:</strong> <span id="locationLandmarkText">—</span>
                            </div>
                            <div class="detail-location-row">
                                <strong>GPS Coordinates:</strong> <span id="locationCoordinatesText">—</span>
                            </div>
                        </div>
                        <div id="complaintDetailMap" class="detail-map-container" style="display:none;" aria-label="Incident Location Map"></div>
                        <div id="emptyMapPlaceholder" class="empty-map-placeholder" style="display:none;">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <p style="margin: 0; font-weight: 500;">No exact map coordinates pinned for this incident.</p>
                            <span style="font-size: 0.78rem; color: #94a3b8;">A map pin can be added or adjusted anytime via Edit Complaint.</span>
                        </div>
                    </div>

                    <!-- CARD 5: Evidence & Attachments -->
                    <div class="intake-card evidence-card">
                        <div class="intake-card-header with-action">
                            <div>
                                <h3>5. Evidence / Attachments (<span id="attachmentsCount">0</span>)</h3>
                                <span class="card-subtitle">Supporting documents, pictures, or videos</span>
                            </div>
                            <button type="button" class="card-header-btn" onclick="openAddAttachmentModal()">+ Upload Evidence</button>
                        </div>
                        <div id="attachmentsListContainer" class="attachments-detail-list">
                            <div class="empty-detail-state">Loading attachments...</div>
                        </div>
                    </div>

                    <!-- CARD 6: Administrative Review Notes -->
                    <div class="intake-card">
                        <div class="intake-card-header">
                            <h3>6. Administrative Review Notes</h3>
                            <span class="card-subtitle">Barangay staff screening and review remarks</span>
                        </div>
                        <div id="complaintReviewNotes" class="detail-narrative-box">No administrative review notes recorded yet.</div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Hidden legacy compatibility containers (ensures old callers don't throw) -->
        <div id="complaintInfo" style="display:none;"></div>
        <table style="display:none;"><tbody id="partiesTable"></tbody></table>
        <table style="display:none;"><tbody id="attachmentsTable"></tbody></table>

    </div>

</div>

<!-- Add Party Modal -->
<div id="addPartyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add Party</h2>
            <button class="close-btn" onclick="closeAddPartyModal()">&times;</button>
        </div>
        <form id="addPartyForm">
            <input type="hidden" id="partyComplaintId">
            <div class="form-group">
                <label>Resident <span class="required-mark">*</span></label>
                <select id="partyResidentId" required></select>
            </div>
            <div class="form-group">
                <label>Party Type <span class="required-mark">*</span></label>
                <select id="partyType" required>
                    <option value="Complainant">Complainant</option>
                    <option value="Respondent">Respondent</option>
                    <option value="Witness">Witness</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeAddPartyModal()">Cancel</button>
                <button type="submit" class="btn-create">Add Party</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Attachment Modal -->
<div id="addAttachmentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="attachmentModalTitle">Upload Evidence</h2>
            <button class="close-btn" onclick="closeAddAttachmentModal()">&times;</button>
        </div>
        <form id="addAttachmentForm">
            <input type="hidden" id="attachmentComplaintId">
            <div class="form-group">
                <label id="attachmentFileLabel">Evidence File <span class="required-mark">*</span></label>
                <input type="file" id="attachmentFile" accept="image/*,video/*,.pdf,.doc,.docx" required>
                <small id="attachmentFileHelp" class="field-hint">Select an image, video, PDF, or Word document up to 25 MB.</small>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeAddAttachmentModal()">Cancel</button>
                <button type="submit" class="btn-create">Upload Evidence</button>
            </div>
        </form>
    </div>
</div>

<div id="scheduleMediationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Schedule 1st Mediation</h2>
            <button class="close-btn" onclick="closeScheduleMediationModal()">&times;</button>
        </div>
        <form id="scheduleMediationForm">
            <input type="hidden" id="mediationComplaintId" name="complaint_id">
            <div class="form-group">
                <label for="mediationDate">1st Mediation Date <span class="required-mark" aria-hidden="true">*</span></label>
                <input type="date" id="mediationDate" name="mediation_date" required>
            </div>
            <div class="form-group">
                <label for="mediationTime">1st Mediation Time <span class="required-mark" aria-hidden="true">*</span></label>
                <input type="time" id="mediationTime" name="mediation_time" required>
            </div>
            <div class="form-group">
                <label for="mediationVenue">Venue <span class="required-mark" aria-hidden="true">*</span></label>
                <input type="text" id="mediationVenue" name="venue" maxlength="255" value="Barangay Hall" required>
            </div>
            <div class="form-group">
                <label for="mediationRemarks">Remarks</label>
                <textarea id="mediationRemarks" name="remarks" maxlength="5000" rows="3" placeholder="Optional mediation instructions"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeScheduleMediationModal()">Cancel</button>
                <button type="submit" class="btn-create">Continue</button>
            </div>
            <p id="mediationMessage" role="status" style="margin-top: 10px;"></p>
        </form>
    </div>
</div>

<div id="confirmMediationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Confirm Mediation</h2>
            <button class="close-btn" onclick="closeConfirmMediationModal()">&times;</button>
        </div>
        <p>Are you sure you want to proceed with this 1st Mediation schedule? This will create and docket the case.</p>
        <dl id="mediationConfirmationDetails" class="hearing-details"></dl>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="closeConfirmMediationModal()">Back</button>
            <button type="button" class="btn-create" id="confirmMediationButton">Confirm 1st Mediation</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
