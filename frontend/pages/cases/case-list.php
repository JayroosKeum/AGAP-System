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
<link rel="stylesheet" href="../../assets/css/search.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/search.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <div>
                <h1>Cases &amp; Team Assignments</h1>
                <p>Manage docketed barangay cases, search records, and assign Lupon teams.</p>
            </div>
        </div>

        <?php if (isset($docketMessages[$docketError])): ?>
            <div class="page-alert" role="alert"><?php echo $docketMessages[$docketError]; ?></div>
        <?php endif; ?>

        <!-- Compact Search & Filter Toolbar (copied from complaints page) -->
        <section class="cases-toolbar-card complaints-toolbar-card">
            <form id="caseRecordsSearchForm" class="complaints-toolbar-form" onsubmit="event.preventDefault();">
                <div class="toolbar-main-row">
                    <div class="search-box-wrap">
                        <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input id="caseSearchQuery" name="q" type="search" class="search-input-field" placeholder="Search by case no., complaint title, complainant, or respondent..." autocomplete="off">
                        <button type="button" id="clearCaseSearchInput" class="search-clear-btn" title="Clear keyword">&times;</button>
                    </div>

                    <select id="caseSearchType" name="case_type" class="toolbar-select">
                        <option value="">All Case Types</option>
                        <option value="Civil">Civil</option>
                        <option value="Criminal">Criminal</option>
                    </select>

                    <select id="caseSearchStatus" name="case_status" class="toolbar-select">
                        <option value="">All Ongoing Statuses</option>
                        <option value="Docketed">Docketed</option>
                        <option value="Mediation">Mediation</option>
                        <option value="Conciliation">Conciliation</option>
                        <option value="Arbitration">Arbitration</option>
                    </select>

                    <select id="caseSearchSort" name="sort_by" class="toolbar-select" title="Order records">
                        <option value="case_number_desc">Sort: Case No. (Desc)</option>
                        <option value="case_number_asc">Sort: Case No. (Asc)</option>
                    </select>

                    <button type="button" id="clearCaseFilters" class="btn-reset-filters" style="display: none;">
                        Reset Filters
                    </button>
                </div>
            </form>
        </section>

        <section class="case-assignment-panel" id="caseAssignments">
            <div class="section-heading">
                <div>
                    <h2>Team assignment for selected case</h2>
                    <p>Select or assign the required Lupon mediation/conciliation officers.</p>
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
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap;">
                                <div>
                                    <strong id="automaticHeadName">Administrator</strong>
                                    <small style="display: block; margin-top: 2px;">Barangay Captain and Lupon Head. Automatically assigned at Docketed/Mediation stage.</small>
                                </div>
                                <button type="button" onclick="openSubstitutePresiderForCase()" style="background: #2563eb; color: #fff; border: none; padding: 4px 10px; border-radius: 5px; font-size: 0.75rem; font-weight: 600; cursor: pointer; white-space: nowrap; flex-shrink: 0;" title="Assign a substitute mediator if the Punong Barangay cannot preside">
                                    👤 Substitute Presider
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group"><label for="secretaryId">Secretary <span class="required-mark" aria-hidden="true">*</span></label><select id="secretaryId" name="secretary_id" required></select></div>
                    <div class="form-group"><label for="teamMemberId">Member <span class="required-mark" aria-hidden="true">*</span></label><select id="teamMemberId" name="member_id" required></select></div>
                    <!-- Pangkat Tagapagkasundo Configuration (Section C) -->
                    <div id="pangkatConfigSection" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-bottom: 12px;">
                        <div class="form-group">
                            <label for="selectionMethod">Pangkat Selection Method</label>
                            <select id="selectionMethod" name="selection_method">
                                <option value="Party Agreement">Agreement by the Parties</option>
                                <option value="PB Assignment">Punong Barangay Assignment (Parties Failed to Agree)</option>
                                <option value="Raffle Draw">Raffle / Drawing of Lots</option>
                            </select>
                            <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 2px;">Sec. 404, LGC: Parties choose 3 members; PB assigns or draws lots if no agreement.</small>
                        </div>
                        <div class="form-group">
                            <label for="selectionNotes">Selection Notes / Remarks</label>
                            <input type="text" id="selectionNotes" name="selection_notes" placeholder="e.g. Parties selected from Lupon pool; or PB lot drawing record">
                        </div>
                        <div class="form-group">
                            <label for="quorumSize">Quorum Size</label>
                            <select id="quorumSize" name="quorum_size" onchange="toggleQuorumReason(this.value)">
                                <option value="3" selected>3 Members (Standard Pangkat)</option>
                                <option value="2">2 Members (Permitted Exception Quorum)</option>
                            </select>
                        </div>
                        <div class="form-group" id="quorumReasonGroup" style="display: none;">
                            <label for="quorumExceptionReason" style="color: #b45309;">Quorum Exception Justification <span class="required-mark">*</span></label>
                            <input type="text" id="quorumExceptionReason" name="quorum_exception_reason" placeholder="State valid reason / parties consent to proceed with 2 members">
                        </div>
                    </div>

                    <button class="btn-create" type="submit" id="btnSaveTeam">Save Case Team</button>
                    <p id="teamMessage" role="status"></p>
                </form>
                <div class="assignment-history">
                    <h3>Assigned members</h3>
                    <div class="assignment-table-wrap"><table>
                        <thead>
                            <tr>
                                <th>Lupon Member</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="assignmentTable"><tr><td colspan="4" class="empty-state">Select a case to view assignments.</td></tr></tbody>
                    </table></div>
                </div>
            </div>

            <div class="section-heading" style="margin-top: 28px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                <div>
                    <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;">Case team assignment &amp; details</h3>
                    <p style="margin: 4px 0 0; color: #64748b; font-size: 0.88rem;">Search a case to view its details in the table and assign the required Head, Secretary, and Member together.</p>
                </div>
            </div>

            <!-- Integrated Case Details / Records Table -->
            <div class="integrated-case-table-section" style="margin-bottom: 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                    <div id="caseResultSummary" class="case-result-summary">Loading cases...</div>
                    <small id="caseTableTip" style="color: #64748b; font-size: 0.82rem;">Click <strong>Assign</strong> or search to load a case into the assignment form above.</small>
                </div>

                <div class="table-container integrated-cases-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Case No.</th>
                                <th>Complainant</th>
                                <th>Respondent</th>
                                <th>Case Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody id="caseTable">
                            <tr>
                                <td colspan="6" class="empty-state">
                                    Loading cases...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div id="casePagination" class="case-pagination" aria-label="Cases pagination" hidden style="margin: 12px 0 0; padding: 10px 14px;">
                    <span id="casePaginationSummary" class="case-pagination-summary" aria-live="polite"></span>
                    <div id="casePaginationControls" class="case-pagination-controls"></div>
                </div>
            </div>
        </section>

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
                    <div id="editPangkatConfigSection" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-top: 10px;">
                        <div class="form-group">
                            <label for="editSelectionMethod">Selection Method</label>
                            <select id="editSelectionMethod" name="selection_method">
                                <option value="Party Agreement">Agreement by the Parties</option>
                                <option value="PB Assignment">Punong Barangay Assignment</option>
                                <option value="Raffle Draw">Raffle / Drawing of Lots</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="editSelectionNotes">Selection Remarks</label>
                            <input type="text" id="editSelectionNotes" name="selection_notes" placeholder="Notes on selection method">
                        </div>
                        <div class="form-group">
                            <label for="editQuorumSize">Quorum Size</label>
                            <select id="editQuorumSize" name="quorum_size" onchange="toggleEditQuorumReason(this.value)">
                                <option value="3" selected>3 Members</option>
                                <option value="2">2 Members (Permitted Exception)</option>
                            </select>
                        </div>
                        <div class="form-group" id="editQuorumReasonGroup" style="display: none;">
                            <label for="editQuorumExceptionReason" style="color: #b45309;">Quorum Justification <span class="required-mark">*</span></label>
                            <input type="text" id="editQuorumExceptionReason" name="quorum_exception_reason" placeholder="Reason for 2-member exception">
                        </div>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn-create">Save Changes</button>
        </form>
    </div>
</div>

<!-- MODAL: Assign Substitute Presider -->
<div id="substitutePresiderModal" class="modal" aria-hidden="true">
    <div class="modal-content" style="width: 540px; max-width: calc(100% - 32px); padding: 26px 28px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.15), 0 8px 10px -6px rgba(15, 23, 42, 0.1);">
        <div class="modal-header" style="margin-bottom: 16px; align-items: flex-start;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                </div>
                <div>
                    <h2 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0; line-height: 1.3;">Designate Substitute Presider</h2>
                    <p style="margin: 3px 0 0; font-size: 0.82rem; color: #64748b;">Assign an active Lupon mediator when primary presider is unavailable.</p>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeSubstitutePresiderModal()" aria-label="Close" style="background: #f1f5f9; border: none; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 18px; cursor: pointer; transition: all 0.15s ease;">&times;</button>
        </div>

        <div id="substituteAlert" class="alert" style="display: none; margin-bottom: 14px; padding: 10px 14px; border-radius: 8px; font-size: 0.84rem; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b;"></div>

        <div style="display: flex; align-items: flex-start; gap: 10px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px; font-size: 0.84rem; color: #1e40af; line-height: 1.45;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            <div>
                <strong>Katarungang Pambarangay Rule:</strong> If the Punong Barangay / original presider is unavailable, an active Lupon mediator may proceed <strong>only if both parties mutually consent</strong>.
            </div>
        </div>

        <form id="substitutePresiderForm" onsubmit="handleSubstituteSubmit(event)">
            <input type="hidden" id="substituteCaseId" name="case_id">
            <input type="hidden" id="substituteHearingId" name="hearing_id">

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="substitutePresiderId" style="display: block; font-weight: 600; font-size: 0.86rem; color: #1e293b; margin-bottom: 6px;">
                    Designated Substitute Mediator <span class="required-mark" style="color: #dc2626;">*</span>
                </label>
                <select id="substitutePresiderId" name="substitute_presider_id" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; background: #fff; color: #0f172a; box-sizing: border-box;">
                    <option value="">Select an active Lupon Member</option>
                </select>
                <small style="display: block; margin-top: 4px; color: #64748b; font-size: 0.78rem;">Select an active Lupon member available for mediation.</small>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label for="substituteReason" style="display: block; font-weight: 600; font-size: 0.86rem; color: #1e293b; margin-bottom: 6px;">
                    Reason for Presider Substitution <span class="required-mark" style="color: #dc2626;">*</span>
                </label>
                <textarea id="substituteReason" name="substitute_reason" rows="3" required placeholder="Specify reason (e.g. Punong Barangay on official municipal mission; designated Lupon mediator stepping in)..." style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; color: #0f172a; box-sizing: border-box; line-height: 1.45; resize: vertical; font-family: inherit;"></textarea>
            </div>

            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 14px; margin-bottom: 20px;">
                <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin: 0;">
                    <input type="checkbox" id="partiesConsentCheckbox" name="parties_consent_to_substitute" value="1" required style="width: 18px; height: 18px; accent-color: #d97706; margin-top: 2px; flex-shrink: 0; cursor: pointer;">
                    <span style="font-size: 0.84rem; color: #92400e; line-height: 1.45;">
                        <strong style="display: block; color: #78350f; font-size: 0.86rem; margin-bottom: 2px;">Mandatory Mutual Consent Confirmed</strong>
                        Both Complainant and Respondent were consulted and have explicitly consented to proceed with this designated substitute presider.
                    </span>
                </label>
            </div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; align-items: center; gap: 10px; border-top: 1px solid #f1f5f9; padding-top: 16px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeSubstitutePresiderModal()" style="padding: 8px 18px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; font-weight: 500; font-size: 0.88rem; cursor: pointer; transition: background 0.15s ease;">
                    Cancel
                </button>
                <button type="submit" class="btn-create" style="padding: 8px 20px; border-radius: 6px; border: none; background: #2563eb; color: #ffffff; font-weight: 600; font-size: 0.88rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background 0.15s ease;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    Designate Substitute Presider
                </button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/cases.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/cases.js'); ?>"></script>
<script src="../../assets/js/assignments.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/assignments.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
