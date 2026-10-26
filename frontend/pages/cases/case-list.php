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
                <h1>Docketed Cases</h1>
                <p>Manage and search docketed barangay cases, complaints, and proceedings records.</p>
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

                <section class="cases-table-card stage-section-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); margin-top: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">
                <div id="caseResultSummary" class="case-result-summary" style="font-weight: 600; color: #1e293b;">Loading cases...</div>
                <small id="caseTableTip" style="color: #64748b; font-size: 0.82rem;">Select any case to view its proceedings, sessions, and stage workspace.</small>
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
<?php include '../../layouts/footer.php'; ?>
