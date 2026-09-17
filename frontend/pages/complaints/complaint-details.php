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

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <div>
                <a href="complaint-list.php" style="text-decoration: none; color: var(--text-secondary); margin-bottom: 8px; display: inline-flex;">&larr; Back to Complaints</a>
                <h1 id="complaintNumber">Complaint #</h1>
                <p id="complaintTitle"></p>
            </div>
            <div class="action-buttons">
                <button type="button" class="btn-create" onclick="openAddPartyModal()">Add Party</button>
                <button type="button" class="btn-create" onclick="openAddAttachmentModal('image')">Upload Picture</button>
                <button type="button" class="btn-create" onclick="openAddAttachmentModal('video')">Upload Video</button>
                <button type="button" class="btn-create" onclick="openAddAttachmentModal('document')">Upload Document</button>
                <button type="button" class="btn-create" onclick="openReviewComplaintModal()">Review Complaint</button>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <!-- Complaint Info -->
            <div class="table-container">
                <h3>Complaint Details</h3>
                <div id="complaintInfo"></div>
            </div>

            <div class="table-container">
                <h3>Incident Location</h3>
                <form id="incidentLocationForm">
                    <input type="hidden" id="locationComplaintId" name="complaint_id">
                    <div class="form-group"><label for="incidentAddress">Location description <span class="required-mark" aria-hidden="true">*</span></label><textarea id="incidentAddress" name="address" rows="3" maxlength="2000" required placeholder="Street, purok, building, and other identifying details"></textarea></div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;"><div class="form-group"><label for="incidentLatitude">Latitude</label><input id="incidentLatitude" name="latitude" type="number" step="any" min="-90" max="90" placeholder="Optional"></div><div class="form-group"><label for="incidentLongitude">Longitude</label><input id="incidentLongitude" name="longitude" type="number" step="any" min="-180" max="180" placeholder="Optional"></div></div>
                    <button type="submit" class="btn-create">Save Incident Location</button><p id="locationMessage" role="status"></p>
                </form>
            </div>

            <!-- Complaint Parties -->
            <div class="table-container">
                <h3>Parties</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="partiesTable"></tbody>
                </table>
            </div>
        </div>

        <!-- Attachments -->
        <div class="table-container" style="margin-top: 24px;">
            <h3>Attachments</h3>
            <table>
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Type</th>
                        <th>Uploaded At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="attachmentsTable"></tbody>
            </table>
        </div>

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
                <label>Resident</label>
                <select id="partyResidentId" required></select>
            </div>
            <div class="form-group">
                <label>Party Type</label>
                <select id="partyType" required>
                    <option value="Complainant">Complainant</option>
                    <option value="Respondent">Respondent</option>
                    <option value="Witness">Witness</option>
                </select>
            </div>
            <button type="submit" class="btn-create">Add Party</button>
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
                <label id="attachmentFileLabel">Evidence file</label>
                <input type="file" id="attachmentFile" required>
                <small id="attachmentFileHelp">Select a file up to 25 MB.</small>
            </div>
            <button type="submit" class="btn-create">Add Attachment</button>
        </form>
    </div>
</div>

<div id="reviewComplaintModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2>Review Complaint</h2><button class="close-btn" onclick="closeReviewComplaintModal()">&times;</button></div>
        <form id="reviewComplaintForm">
            <input type="hidden" id="reviewComplaintId" name="complaint_id">
            <div class="form-group"><label for="reviewStatus">Decision</label><select id="reviewStatus" name="status" required><option value="Under Review">Under Review</option><option value="Needs Information">Needs Information</option><option value="Accepted">Accept for Docketing</option><option value="Rejected">Reject / Refer</option></select></div>
            <div class="form-group"><label for="reviewNotes">Review Notes</label><textarea id="reviewNotes" name="review_notes" maxlength="2000" rows="4" placeholder="Record the review outcome or missing information"></textarea></div>
            <button type="submit" class="btn-create">Save Review</button>
            <p id="reviewMessage" role="status"></p>
        </form>
    </div>
</div>

<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
