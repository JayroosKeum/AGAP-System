<?php
session_start();

if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 2])) {
    die('Access Denied');
}

include '../../layouts/header.php';

$docketError = $_GET['error'] ?? '';
$docketMessages = [
    'invalid_complaint' => 'The Complaint ID does not exist. Choose a complaint from the list below.',
    'duplicate_case' => 'This complaint has already been docketed as a case.',
    'save_failed' => 'The case could not be docketed. Please try again.'
];
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <div>
                <h1>Cases</h1>
                <p>Manage docketed barangay cases and their progress.</p>
            </div>
            <button type="button" class="btn-create" onclick="openAddCaseModal()">Docket Case</button>
        </div>

        <?php if (isset($docketMessages[$docketError])): ?>
            <div class="page-alert" role="alert"><?php echo $docketMessages[$docketError]; ?></div>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Case No.</th>
                        <th>Complaint</th>
                        <th>Case Type</th>
                        <th>Docket Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="caseTable"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="addCaseModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Docket a Case</h2>
            <button type="button" class="close-btn" onclick="closeAddCaseModal()">&times;</button>
        </div>
        <form id="docketCaseForm" action="../../../backend/api/cases/create.php" method="POST">
            <div class="form-group">
                <label for="complaintId">Complaint ID</label>
                <input type="number" id="complaintId" name="complaint_id" min="1" required aria-describedby="complaintIdHelp complaintIdWarning">
                <small id="complaintIdHelp">Select a complaint below or enter its ID.</small>
                <p id="complaintIdWarning" class="field-warning" role="alert" hidden></p>
            </div>
            <div class="form-group">
                <label for="availableComplaints">Available Complaints</label>
                <select id="availableComplaints" size="6" aria-label="Available complaints"></select>
                <small>Complaints already docketed are marked and cannot be selected.</small>
            </div>
            <div class="form-group">
                <label for="caseType">Case Type</label>
                <select id="caseType" name="case_type" required>
                    <option value="Civil">Civil</option>
                    <option value="Criminal">Criminal</option>
                </select>
            </div>
            <button type="submit" class="btn-create">Docket Case</button>
        </form>
    </div>
</div>

<div id="viewCaseModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Case Details</h2>
            <button type="button" class="close-btn" onclick="closeViewCaseModal()">&times;</button>
        </div>
        <div id="caseDetails"></div>
    </div>
</div>

<div id="editCaseModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Update Case</h2>
            <button type="button" class="close-btn" onclick="closeEditCaseModal()">&times;</button>
        </div>
        <form action="../../../backend/api/cases/update.php" method="POST">
            <input type="hidden" name="case_id" id="editCaseId">
            <div class="form-group">
                <label for="editCaseType">Case Type</label>
                <select id="editCaseType" name="case_type" required>
                    <option value="Civil">Civil</option>
                    <option value="Criminal">Criminal</option>
                </select>
            </div>
            <div class="form-group">
                <label for="editCaseStatus">Status</label>
                <select id="editCaseStatus" name="case_status" required>
                    <option value="Docketed">Docketed</option>
                    <option value="Mediation">Mediation</option>
                    <option value="Conciliation">Conciliation</option>
                    <option value="Arbitration">Arbitration</option>
                    <option value="Settled">Settled</option>
                    <option value="Dismissed">Dismissed</option>
                </select>
            </div>
            <button type="submit" class="btn-create">Save Changes</button>
        </form>
    </div>
</div>

<div id="archiveCaseModal" class="modal" aria-hidden="true">
    <div class="modal-content delete-modal">
        <div class="modal-header">
            <h2>Archive Case</h2>
            <button type="button" class="close-btn" onclick="closeArchiveCaseModal()">&times;</button>
        </div>
        <p>Archive this case? It will be kept in the records but removed from active work.</p>
        <form action="../../../backend/api/cases/archive.php" method="POST" class="modal-actions">
            <input type="hidden" name="case_id" id="archiveCaseId">
            <button type="button" class="btn-secondary" onclick="closeArchiveCaseModal()">Cancel</button>
            <button type="submit" class="btn-danger">Archive</button>
        </form>
    </div>
</div>

<script src="../../assets/js/cases.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/cases.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
