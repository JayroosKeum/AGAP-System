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
                <button type="button" class="btn-create" onclick="openAddAttachmentModal()">Add Attachment</button>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <!-- Complaint Info -->
            <div class="table-container">
                <h3>Complaint Details</h3>
                <div id="complaintInfo"></div>
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
            <h2>Add Attachment</h2>
            <button class="close-btn" onclick="closeAddAttachmentModal()">&times;</button>
        </div>
        <form id="addAttachmentForm">
            <input type="hidden" id="attachmentComplaintId">
            <div class="form-group">
                <label>File</label>
                <input type="file" id="attachmentFile" accept=".jpg,.jpeg,.png,.pdf" required>
            </div>
            <button type="submit" class="btn-create">Add Attachment</button>
        </form>
    </div>
</div>

<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
