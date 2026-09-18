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

include '../../layouts/header.php';

?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">

            <div>
                <h1>Complaints</h1>
                <p>Record and monitor community concerns from filing to resolution.</p>
            </div>

            <button
                type="button"
                class="btn-create"
                onclick="openAddComplaintModal()">

                Add Complaint

            </button>

        </div>

        <div class="table-container">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>Complaint No.</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>

                </thead>

                <tbody id="complaintTable">

                </tbody>

            </table>

        </div>

    </div>

</div>

<!-- ADD MODAL -->

<div id="addComplaintModal" class="modal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>Add Complaint</h2>

            <button
                class="close-btn"
                onclick="closeAddComplaintModal()">

                &times;

            </button>

        </div>

        <form
            action="../../../backend/api/complaints/create.php"
            method="POST">

            <div class="form-group">

                <label>Complaint Category</label>

                <select
                    name="category_id"
                    required>

                    <option value="">
                        Select Category
                    </option>

                    <option value="1">
                        Non-Payment of Debt
                    </option>

                    <option value="2">
                        Breach of Agreement
                    </option>

                    <option value="3">
                        Physical Injuries
                    </option>

                    <option value="4">
                        Defamation
                    </option>

                    <option value="5">
                        Threats
                    </option>

                    <option value="6">
                        Property and Rental Disputes
                    </option>

                    <option value="7">
                        Disturbance and Public Disorder
                    </option>

                    <option value="8">
                        Malicious Mischief
                    </option>

                    <option value="9">
                        Trespassing
                    </option>

                    <option value="10">
                        Family and Domestic Disputes
                    </option>

                </select>

            </div>

            <div class="form-group">

                <label>Complaint Title</label>

                <input
                    type="text"
                    name="complaint_title"
                    maxlength="255"
                    required>

            </div>

            <div class="form-group">

                <label>Incident Date</label>

                <input
                    type="date"
                    name="incident_date"
                    required>

            </div>

            <div class="form-group">

                <label>Narrative</label>

                <textarea
                    name="narrative"
                    rows="5"
                    maxlength="15000"
                    required></textarea>

            </div>

            <div class="form-group">
                <label>Incident Time</label>
                <input type="time" name="incident_time">
            </div>

            <div class="form-group">
                <label>Specific Incident Location <span class="required-mark" aria-hidden="true">*</span></label>
                <input type="text" name="incident_location" maxlength="255" required placeholder="Street, building, purok, or nearby place">
            </div>

            <div class="form-group complaint-map-group">
                <label>Exact Map Location <span class="optional-label">Optional</span></label>
                <input type="hidden" name="map_location_state" value="none" data-map-state>
                <input type="hidden" name="location_latitude" value="" data-map-latitude>
                <input type="hidden" name="location_longitude" value="" data-map-longitude>
                <p class="map-help">Click the map to pin the incident location. The pin is saved with this complaint.</p>
                <div id="addComplaintMap" class="complaint-location-map" aria-label="Map for selecting the exact incident location"></div>
                <div class="map-selection-row"><span class="map-selection-status" data-map-status>No map point selected.</span><button type="button" class="btn-secondary" data-clear-map>Clear pin</button></div>
            </div>

            <div class="form-group">
                <label>Landmark</label>
                <input type="text" name="incident_landmark" maxlength="255" placeholder="Optional nearby landmark">
            </div>

            <div class="form-group">
                <label>Additional Details</label>
                <textarea name="additional_details" rows="3" maxlength="5000" placeholder="People involved, location context, prior actions, or other relevant facts"></textarea>
                <small>Optional; up to 5,000 characters.</small>
            </div>

            <button
                type="submit"
                class="btn-create">

                Save Complaint

            </button>

        </form>

    </div>

</div>

<!-- VIEW MODAL -->

<div id="viewComplaintModal" class="modal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>Complaint Details</h2>

            <button
                class="close-btn"
                onclick="closeViewComplaintModal()">

                &times;

            </button>

        </div>

        <div id="complaintDetails"></div>

    </div>

</div>

<!-- EDIT COMPLAINT MODAL -->

<div id="editComplaintModal" class="modal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>Edit Complaint</h2>

            <button
                class="close-btn"
                onclick="closeEditComplaintModal()">

                &times;

            </button>

        </div>

        <form
            action="../../../backend/api/complaints/update.php"
            method="POST">

            <input
                type="hidden"
                name="complaint_id"
                id="editComplaintId">

            <div class="form-group">

                <label>Category</label>

                <select
                    name="category_id"
                    id="editCategoryId"
                    required>

                    <option value="1">Non-Payment of Debt</option>
                    <option value="2">Breach of Agreement</option>
                    <option value="3">Physical Injuries</option>
                    <option value="4">Defamation</option>
                    <option value="5">Threats</option>
                    <option value="6">Property and Rental Disputes</option>
                    <option value="7">Disturbance and Public Disorder</option>
                    <option value="8">Malicious Mischief</option>
                    <option value="9">Trespassing</option>
                    <option value="10">Family and Domestic Disputes</option>

                </select>

            </div>

            <div class="form-group">

                <label>Complaint Title</label>

                <input
                    type="text"
                    name="complaint_title"
                    id="editComplaintTitle"
                    maxlength="255"
                    required>

            </div>

            <div class="form-group">

                <label>Incident Date</label>

                <input
                    type="date"
                    name="incident_date"
                    id="editIncidentDate"
                    required>

            </div>

            <div class="form-group">

                <label>Narrative</label>

                <textarea
                    name="narrative"
                    id="editNarrative"
                    rows="5"
                    maxlength="15000"
                    required></textarea>

            </div>

            <div class="form-group"><label>Incident Time</label><input type="time" name="incident_time" id="editIncidentTime"></div>
            <div class="form-group"><label>Specific Incident Location <span class="required-mark" aria-hidden="true">*</span></label><input type="text" name="incident_location" id="editIncidentLocation" maxlength="255" required></div>
            <div class="form-group complaint-map-group">
                <label>Exact Map Location <span class="optional-label">Optional</span></label>
                <input type="hidden" name="map_location_state" value="unchanged" data-map-state>
                <input type="hidden" name="location_latitude" value="" data-map-latitude>
                <input type="hidden" name="location_longitude" value="" data-map-longitude>
                <p class="map-help">Click the map to change the saved pin, or clear it to remove the exact map point.</p>
                <div id="editComplaintMap" class="complaint-location-map" aria-label="Map for selecting the exact incident location"></div>
                <div class="map-selection-row"><span class="map-selection-status" data-map-status>Loading saved map point…</span><button type="button" class="btn-secondary" data-clear-map>Clear pin</button></div>
            </div>
            <div class="form-group"><label>Landmark</label><input type="text" name="incident_landmark" id="editIncidentLandmark" maxlength="255"></div>

            <div class="form-group">
                <label>Additional Details</label>
                <textarea name="additional_details" id="editAdditionalDetails" rows="3" maxlength="5000"></textarea>
            </div>

            <div class="form-group">

                <small>Review status is managed from the complaint review workspace.</small>

            </div>

            <button
                type="submit"
                class="btn-create">

                Update Complaint

            </button>

        </form>

    </div>

</div>

<!-- DELETE MODAL -->

<div id="deleteComplaintModal" class="modal">

    <div class="modal-content">

        <h3>Delete Complaint</h3>

        <p>
            Are you sure you want to delete this complaint?
        </p>

        <input
            type="hidden"
            id="deleteComplaintId">

        <div class="modal-actions">

            <button
                class="btn-create"
                onclick="closeDeleteComplaintModal()">

                Cancel

            </button>

            <button
                class="btn-danger"
                onclick="confirmDeleteComplaint()">

                Delete

            </button>

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

<?php include '../../layouts/footer.php'; ?>
