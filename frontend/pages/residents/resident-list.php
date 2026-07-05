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

<link rel="stylesheet" href="../../assets/css/dashboard.css">
<link rel="stylesheet" href="../../assets/css/residents.css">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">

            <h1>Residents</h1>

            <button
                class="btn-create"
                onclick="openAddModal()">

                Add Resident

            </button>

        </div>

        <div class="table-container">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Actions</th>
                    </tr>

                </thead>

                <tbody id="residentTable">

                </tbody>

            </table>

        </div>

    </div>

</div>

<!-- ADD RESIDENT MODAL -->

<div id="addResidentModal" class="modal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>Add Resident</h2>

            <button
                type="button"
                class="close-btn"
                onclick="closeAddModal()">

                &times;

            </button>

        </div>

        <form
            action="../../../backend/api/residents/create.php"
            method="POST">

            <div class="resident-form-grid">

                <div class="form-group">
                    <label>First Name</label>
                    <input
                        type="text"
                        name="first_name"
                        required>
                </div>

                <div class="form-group">
                    <label>Middle Name</label>
                    <input
                        type="text"
                        name="middle_name">
                </div>

                <div class="form-group">
                    <label>Last Name</label>
                    <input
                        type="text"
                        name="last_name"
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

                <div class="form-group">
                    <label>Contact Number</label>

                    <input
                        type="text"
                        name="contact_no">
                </div>

                <div class="form-group">
                    <label>Email</label>

                    <input
                        type="email"
                        name="email">
                </div>

                <div class="form-group">
                    <label>Purok</label>

                    <input
                        type="text"
                        name="purok">
                </div>

                <div class="form-group">
                    <label>Tenant Status</label>

                    <select name="is_tenant">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>

                <div class="form-group full-width">
                    <label>Address</label>

                    <textarea
                        name="address"
                        rows="3"></textarea>
                </div>

            </div>

            <button
                type="submit"
                class="btn-create">

                Save Resident

            </button>

        </form>

    </div>

</div>

<!-- VIEW RESIDENT MODAL -->

<div id="viewResidentModal" class="modal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>Resident Details</h2>

            <button
                type="button"
                class="close-btn"
                onclick="closeViewModal()">

                &times;

            </button>

        </div>

        <div id="residentDetails">

        </div>

    </div>

</div>

<!-- EDIT RESIDENT MODAL -->

<div id="editResidentModal" class="modal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>Edit Resident</h2>

            <button
                type="button"
                class="close-btn"
                onclick="closeEditModal()">

                &times;

            </button>

        </div>

        <form
            action="../../../backend/api/residents/update.php"
            method="POST">

            <input
                type="hidden"
                name="resident_id"
                id="editResidentId">

            <div class="resident-form-grid">

                <div class="form-group">
                    <label>First Name</label>
                    <input
                        type="text"
                        name="first_name"
                        id="editFirstName"
                        required>
                </div>

                <div class="form-group">
                    <label>Middle Name</label>
                    <input
                        type="text"
                        name="middle_name"
                        id="editMiddleName">
                </div>

                <div class="form-group">
                    <label>Last Name</label>
                    <input
                        type="text"
                        name="last_name"
                        id="editLastName"
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

                <div class="form-group">
                    <label>Contact Number</label>

                    <input
                        type="text"
                        name="contact_no"
                        id="editContactNo">
                </div>

                <div class="form-group">
                    <label>Email</label>

                    <input
                        type="email"
                        name="email"
                        id="editEmail">
                </div>

                <div class="form-group">
                    <label>Purok</label>

                    <input
                        type="text"
                        name="purok"
                        id="editPurok">
                </div>

                <div class="form-group">
                    <label>Tenant Status</label>

                    <select
                        name="is_tenant"
                        id="editTenant">

                        <option value="0">No</option>
                        <option value="1">Yes</option>

                    </select>
                </div>

                <div class="form-group full-width">

                    <label>Address</label>

                    <textarea
                        name="address"
                        id="editAddress"
                        rows="3"></textarea>

                </div>

            </div>

            <button
                type="submit"
                class="btn-create">

                Update Resident

            </button>

        </form>

    </div>

</div>

<!-- DELETE RESIDENT MODAL -->

<div id="deleteResidentModal" class="modal">

    <div class="modal-content delete-modal">

        <div class="modal-header">

            <h2>Delete Resident</h2>

            <button
                type="button"
                class="close-btn"
                onclick="closeDeleteModal()">

                &times;

            </button>

        </div>

        <p>
            Are you sure you want to delete this resident?
        </p>

        <p>
            This action cannot be undone.
        </p>

        <input
            type="hidden"
            id="deleteResidentId">

        <div class="modal-actions">

            <button
                type="button"
                class="btn-secondary"
                onclick="closeDeleteModal()">

                Cancel

            </button>

            <button
                type="button"
                class="btn-danger"
                onclick="confirmDeleteResident()">

                Delete

            </button>

        </div>

    </div>

</div>

<script src="../../assets/js/residents.js"></script>

<?php include '../../layouts/footer.php'; ?>