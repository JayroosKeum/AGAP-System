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
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">

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
                    required></textarea>

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
                    required></textarea>

            </div>

            <div class="form-group">

                <label>Status</label>

                <select
                    name="status"
                    id="editStatus">

                    <option value="Filed">Filed</option>
                    <option value="Docketed">Docketed</option>
                    <option value="Mediation">Mediation</option>
                    <option value="Conciliation">Conciliation</option>
                    <option value="Arbitration">Arbitration</option>
                    <option value="Settled">Settled</option>
                    <option value="Dismissed">Dismissed</option>

                </select>

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

<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
