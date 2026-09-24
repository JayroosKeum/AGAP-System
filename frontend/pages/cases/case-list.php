<?php
session_start();

if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 2])) {
    die('Access Denied');
}

include '../../layouts/header.php';

$docketError = $_GET['error'] ?? '';
$docketMessages = [
    'invalid_complaint' => 'The Complaint ID does not exist. Choose a complaint from the list below.',
    'complaint_not_accepted' => 'A complaint must be reviewed and accepted before it can be docketed.',
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
        </div>

        <?php if (isset($docketMessages[$docketError])): ?>
            <div class="page-alert" role="alert"><?php echo $docketMessages[$docketError]; ?></div>
        <?php endif; ?>

        <section class="case-assignment-panel" id="caseAssignments">
            <div class="section-heading">
                <div>
                    <h2>Case team assignment</h2>
                    <p>Assign the required Head, Secretary, and Member together for each case.</p>
                </div>
            </div>
            <div class="assignment-grid">
                <form id="teamForm" class="assignment-form">
                    <div class="form-group">
                        <label for="caseId">Case <span class="required-mark" aria-hidden="true">*</span></label>
                        <select id="caseId" name="case_id" required><option value="">Select a case</option></select>
                    </div>
                    <p id="assignmentHelp" class="form-hint">All three roles are required and must be assigned to different active Lupon Members.</p>
                    <p id="assignmentRuleMessage" class="form-hint" role="status" hidden></p>
                    <div class="form-group">
                        <label for="headId">Head <span class="required-mark" aria-hidden="true">*</span></label>
                        <select id="headId" name="head_id" required>
                            <option value="">Select a Lupon Member</option>
                        </select>
                        <div id="automaticHeadDisplay" class="automatic-head-display" hidden>
                            <strong id="automaticHeadName">Administrator</strong>
                            <small>Barangay Captain and Lupon Head. Automatically assigned at the Docketed/Mediation stage and cannot be changed.</small>
                        </div>
                    </div>
                    <div class="form-group"><label for="secretaryId">Secretary <span class="required-mark" aria-hidden="true">*</span></label><select id="secretaryId" name="secretary_id" required></select></div>
                    <div class="form-group"><label for="teamMemberId">Member <span class="required-mark" aria-hidden="true">*</span></label><select id="teamMemberId" name="member_id" required></select></div>
                    <button class="btn-create" type="submit">Save Three-Member Team</button>
                    <p id="teamMessage" role="status"></p>
                </form>
                <div class="assignment-history">
                    <h3>Assigned members</h3>
                    <div class="assignment-table-wrap"><table>
                        <thead><tr><th>Lupon Member</th><th>Role</th><th>Date Assigned</th></tr></thead>
                        <tbody id="assignmentTable"><tr><td colspan="3" class="empty-state">Select a case to view assignments.</td></tr></tbody>
                    </table></div>
                </div>
            </div>
        </section>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Case No.</th>
                        <th>Complaint</th>
                        <th>Complainant</th>
                        <th>Respondent</th>
                        <th>Case Type</th>
                        <th>Docket Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody id="caseTable">
                    <tr>
                        <td colspan="8" class="empty-state">
                            Loading cases...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="casePagination" class="case-pagination" aria-label="Cases pagination" hidden>
            <span id="casePaginationSummary" class="case-pagination-summary" aria-live="polite"></span>
            <div id="casePaginationControls" class="case-pagination-controls"></div>
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
        <form id="editCaseForm" action="../../../backend/api/cases/update.php" method="POST">
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
            <div id="editTeamSection" hidden>
                <h3>Lupon Team</h3>
                <p id="editTeamGuidance" class="form-hint"></p>
                <div id="editAutomaticHead" class="automatic-head-display" hidden>
                    <strong>Barangay Captain / Administrator</strong>
                    <small>Automatically assigned at the Docketed/Mediation stage. Secretary and Member are not manually assignable during this stage.</small>
                </div>
                <div id="editTeamFields">
                    <div class="form-group">
                        <label for="editHeadId">Head <span class="required-mark" aria-hidden="true">*</span></label>
                        <select id="editHeadId" name="head_id"><option value="">Select a Lupon Member</option></select>
                    </div>
                    <div class="form-group">
                        <label for="editSecretaryId">Secretary <span class="required-mark" aria-hidden="true">*</span></label>
                        <select id="editSecretaryId" name="secretary_id"><option value="">Select a Lupon Member</option></select>
                    </div>
                    <div class="form-group">
                        <label for="editMemberId">Member <span class="required-mark" aria-hidden="true">*</span></label>
                        <select id="editMemberId" name="member_id"><option value="">Select a Lupon Member</option></select>
                    </div>
                </div>
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
<script src="../../assets/js/assignments.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/assignments.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
