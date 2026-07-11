<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 2, 3])) die('Access Denied');
include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/pangkat.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/pangkat.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>
        
        <div class="page-header">
            <div>
                <h1>Pangkat Assignments</h1>
                <p>Form conciliation panels and assign Lupon members.</p>
            </div>
            <button type="button" class="btn-create" onclick="openModal('createPangkatModal')">Form Pangkat</button>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Case No.</th>
                        <th>Complaint</th>
                        <th>Formation Date</th>
                        <th>Members</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="pangkatTable"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="createPangkatModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Form Pangkat</h2>
            <button type="button" class="close-btn" onclick="closeModal('createPangkatModal')">&times;</button>
        </div>
        <form id="createPangkatForm">
            <div class="form-group">
                <label for="pangkatCaseId">Case</label>
                <select id="pangkatCaseId" name="case_id" required></select>
            </div>
            <button class="btn-create">Create Pangkat</button>
        </form>
    </div>
</div>

<div id="membersModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Pangkat Members</h2>
            <button type="button" class="close-btn" onclick="closeModal('membersModal')">&times;</button>
        </div>
        <div id="membersList" class="members-list"></div>
        <form id="addMemberForm">
            <input type="hidden" id="memberPangkatId" name="pangkat_id">
            <div class="form-group">
                <label for="luponMemberId">Lupon Member</label>
                <select id="luponMemberId" name="member_id" required></select>
            </div>
            <div class="form-group">
                <label for="memberPosition">Position</label>
                <select id="memberPosition" name="position" required>
                    <option value="Chairman">Chairman</option>
                    <option value="Secretary">Secretary</option>
                    <option value="Member">Member</option>
                </select>
            </div>
            <button class="btn-create">Add Member</button>
        </form>
    </div>
</div>

<script src="../../assets/js/pangkat.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/pangkat.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>