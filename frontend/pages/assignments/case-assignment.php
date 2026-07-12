<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    exit('Access Denied');
}
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
                <h1>Case Assignments</h1>
                <p>Assign Lupon members to active cases and review their responsibilities.</p>
            </div>
        </div>

        <section class="document-card">
            <form id="assignmentForm">
                <div class="form-group">
                    <label for="caseId">Case</label>
                    <select id="caseId" name="case_id" required></select>
                </div>
                <div class="form-group">
                    <label for="memberId">Lupon member</label>
                    <select id="memberId" name="member_id" required></select>
                </div>
                <div class="form-group">
                    <label for="assignmentRole">Assignment role</label>
                    <select id="assignmentRole" name="assignment_role" required>
                        <option value="Mediator">Mediator</option>
                        <option value="Pangkat Chairman">Pangkat Chairman</option>
                        <option value="Pangkat Secretary">Pangkat Secretary</option>
                        <option value="Pangkat Member">Pangkat Member</option>
                    </select>
                </div>
                <button class="btn-create" type="submit">Assign Member</button>
                <p id="assignmentMessage" role="status"></p>
            </form>
        </section>

        <div class="table-container">
            <table>
                <thead><tr><th>Lupon Member</th><th>Role</th><th>Date Assigned</th></tr></thead>
                <tbody id="assignmentTable"><tr><td colspan="3" class="empty-state">Select a case to view assignments.</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<script src="../../assets/js/assignments.js?v=<?php echo time(); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
