<?php
session_start();

if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 2, 3])) {
    die('Access Denied');
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/hearings.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/hearings.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <div>
                <h1>Hearing Schedules</h1>
                <p>Plan and monitor mediation and conciliation hearings.</p>
            </div>
            <button type="button" class="btn-create" onclick="openAddHearingModal()">Schedule Hearing</button>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Case No.</th>
                        <th>Complaint</th>
                        <th>Hearing Type</th>
                        <th>Date &amp; Time</th>
                        <th>Venue</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="hearingTable"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="addHearingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Schedule Hearing</h2>
            <button type="button" class="close-btn" onclick="closeAddHearingModal()">&times;</button>
        </div>
        <form action="../../../backend/api/hearings/create.php" method="POST">
            <div class="form-group">
                <label for="hearingCaseId">Case</label>
                <select id="hearingCaseId" name="case_id" required></select>
            </div>
            <div class="form-group">
                <label for="hearingType">Hearing Type</label>
                <select id="hearingType" name="hearing_type" required>
                    <option value="Mediation">Mediation</option>
                    <option value="Conciliation">Conciliation</option>
                    <option value="Arbitration">Arbitration</option>
                </select>
            </div>
            <div class="form-group">
                <label for="hearingDate">Date &amp; Time</label>
                <input type="datetime-local" id="hearingDate" name="hearing_date" required>
            </div>
            <div class="form-group">
                <label for="hearingVenue">Venue</label>
                <input type="text" id="hearingVenue" name="venue" placeholder="e.g., Barangay Hall - Hearing Room 1" required>
            </div>
            <div class="form-group">
                <label for="hearingRemarks">Remarks</label>
                <textarea id="hearingRemarks" name="remarks" rows="3" placeholder="Optional notes or instructions"></textarea>
            </div>
            <button type="submit" class="btn-create">Save Schedule</button>
        </form>
    </div>
</div>

<div id="viewHearingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Hearing Details</h2>
            <button type="button" class="close-btn" onclick="closeViewHearingModal()">&times;</button>
        </div>
        <div id="hearingDetails"></div>
    </div>
</div>

<div id="editHearingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Update Hearing</h2>
            <button type="button" class="close-btn" onclick="closeEditHearingModal()">&times;</button>
        </div>
        <form action="../../../backend/api/hearings/update.php" method="POST">
            <input type="hidden" name="hearing_id" id="editHearingId">
            <div class="form-group">
                <label for="editHearingType">Hearing Type</label>
                <select id="editHearingType" name="hearing_type" required>
                    <option value="Mediation">Mediation</option>
                    <option value="Conciliation">Conciliation</option>
                    <option value="Arbitration">Arbitration</option>
                </select>
            </div>
            <div class="form-group">
                <label for="editHearingDate">Date &amp; Time</label>
                <input type="datetime-local" id="editHearingDate" name="hearing_date" required>
            </div>
            <div class="form-group">
                <label for="editHearingVenue">Venue</label>
                <input type="text" id="editHearingVenue" name="venue" required>
            </div>
            <div class="form-group">
                <label for="editHearingRemarks">Remarks</label>
                <textarea id="editHearingRemarks" name="remarks" rows="3"></textarea>
            </div>
            <button type="submit" class="btn-create">Save Changes</button>
        </form>
    </div>
</div>

<script src="../../assets/js/hearings.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/hearings.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
