<?php

session_start();

if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    die('Access Denied');
}

require_once __DIR__ . '/../../../backend/config/database.php';
$db = (new Database())->connect();

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$complaintId = filter_var($_GET['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$caseId && $complaintId) {
    $cStmt = $db->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
    $cStmt->execute([$complaintId]);
    $caseId = (int) $cStmt->fetchColumn();
} elseif ($caseId && !$complaintId) {
    $cStmt = $db->prepare('SELECT complaint_id FROM cases WHERE case_id = ? LIMIT 1');
    $cStmt->execute([$caseId]);
    $complaintId = (int) $cStmt->fetchColumn();
}

if (!$caseId) {
    header('Location: ../cases/case-list.php');
    exit;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/hearings.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/hearings.css'); ?>">

<style>
    .stage-workspace-container {
        padding: 24px 30px;
        max-width: 1400px;
        margin: 0 auto;
    }
    .stage-breadcrumbs {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.84rem;
        color: #64748b;
        margin-bottom: 16px;
    }
    .stage-breadcrumbs a {
        color: #2563eb;
        text-decoration: none;
    }
    .overview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }
    .overview-stat-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #64748b;
    }
    .stat-value {
        font-size: 1.05rem;
        font-weight: 700;
        color: #0f172a;
    }
    .stat-sub {
        font-size: 0.76rem;
        color: #64748b;
    }
    .stage-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .stage-section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid #f1f5f9;
    }
    .stage-section-header h3 {
        margin: 0;
        font-size: 1.12rem;
        color: #0f172a;
        font-weight: 700;
    }
    .stage-section-header p {
        margin: 3px 0 0;
        font-size: 0.82rem;
        color: #64748b;
    }
    .locked-gate-banner {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 48px 30px;
        text-align: center;
        max-width: 640px;
        margin: 40px auto;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
</style>

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="stage-workspace-container">
            <!-- Breadcrumbs -->
            <div class="stage-breadcrumbs">
                <a href="../cases/case-list.php">Cases</a>
                <span>&rsaquo;</span>
                <a id="wsBackToComplaintLink" href="../complaints/complaint-details.php?id=<?php echo $complaintId; ?>">Complaint Details</a>
                <span>&rsaquo;</span>
                <span id="wsBreadcrumbStage" style="color: #0f172a; font-weight: 600;">Pangkat Conciliation</span>
            </div>

            <!-- Page Header -->
            <div class="page-header" style="padding: 0 0 20px 0; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                        <span style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; background: #f3e8ff; color: #7e22ce; padding: 3px 8px; border-radius: 4px;">
                            STAGE 2 OF 2
                        </span>
                        <h1 id="wsStageTitle" style="margin: 0; font-size: 1.45rem; color: #0f172a; font-weight: 800;">
                            Pangkat Conciliation
                        </h1>
                        <span id="wsStageStatusBadge" class="badge-stage" style="font-size: 0.78rem; padding: 3px 9px; border-radius: 9999px; font-weight: 700;">
                            Loading...
                        </span>
                    </div>
                    <p id="wsCaseSubtext" style="margin: 0; font-size: 0.86rem; color: #64748b;">
                        Case Loading...
                    </p>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <div id="wsPrimaryAction"></div>
                    <details id="wsMoreActions" style="position: relative;">
                        <summary class="btn-secondary" style="font-size: 0.82rem; padding: 7px 12px; cursor: pointer; list-style: none;">More actions</summary>
                        <div id="wsMoreActionsMenu" style="position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; min-width: 220px; padding: 8px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 8px 18px rgba(15,23,42,.14);"></div>
                    </details>
                </div>
            </div>

            <!-- LOCKED GATE STATE (If Pangkat stage is locked) -->
            <div id="stageLockedGate" style="display: none;">
                <div class="locked-gate-banner">
                    <div style="font-size: 3rem; margin-bottom: 12px;">🔒</div>
                    <h2 style="margin: 0 0 10px; font-size: 1.25rem; color: #0f172a; font-weight: 700;">
                        Pangkat Conciliation is Locked
                    </h2>
                    <p style="margin: 0 0 20px; font-size: 0.92rem; color: #64748b; line-height: 1.6;">
                        Pangkat Conciliation is locked. Complete the mediation stage and record an authorized referral to unlock this workspace.
                    </p>
                    <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
                        <a href="mediation-workspace.php?case_id=<?php echo $caseId; ?>&complaint_id=<?php echo $complaintId; ?>" class="btn-create" style="font-size: 0.85rem; padding: 8px 18px; text-decoration: none;">
                            Go to Mediation Workspace &rarr;
                        </a>
                        <a href="../complaints/complaint-details.php?id=<?php echo $complaintId; ?>" class="btn-secondary" style="font-size: 0.85rem; padding: 8px 18px; text-decoration: none;">
                            Back to Complaint Details
                        </a>
                    </div>
                </div>
            </div>

            <!-- UNLOCKED WORKSPACE CONTENT -->
            <div id="stageWorkspaceContent">
                <!-- Section 1: Stage Overview & Pangkat Assignment -->
                <div class="overview-grid" id="wsStageOverviewGrid">
                    <div class="overview-stat-card"><span class="stat-label">Loading Overview...</span></div>
                </div>

                <!-- Section: Pangkat ng Tagapagkasundo Governance & Members -->
                <div class="stage-section-card" id="pangkatPanelSection">
                    <div id="wsPangkatPanelContainer">
                        <div class="empty-detail-state">Loading Pangkat panel governance...</div>
                    </div>
                </div>

                <!-- Section 2: Conciliation Sessions List -->
                <div class="stage-section-card" id="sessionsSection">
                    <div class="stage-section-header">
                        <div>
                            <h3>Conciliation Hearing Sessions</h3>
                            <p>Formal hearings conducted before the 3-member Pangkat ng Tagapagkasundo panel</p>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span id="wsSessionsCountBadge" style="font-size: 0.78rem; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">0 sessions</span>
                            <button type="button" class="btn-secondary" onclick="openScheduleModal()" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                + Schedule Session
                            </button>
                        </div>
                    </div>
                    <div id="wsSessionsListContainer">
                        <div class="empty-detail-state">Loading conciliation sessions...</div>
                    </div>
                </div>

                <!-- Section 3: Hearing Session Minutes & Proceedings -->
                <div class="stage-section-card" id="minutesSection">
                    <div class="stage-section-header">
                        <div>
                            <h3>Hearing Session Minutes &amp; Proceedings</h3>
                            <p>Official records of panel deliberations, statements, agreed terms, and procedural outcome</p>
                        </div>
                    </div>
                    <div id="wsMinutesSectionContainer">
                        <div class="empty-detail-state">Loading conciliation session minutes &amp; proceedings...</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 1: Schedule Conciliation Hearing    -->
<!-- ========================================== -->
<div id="wsScheduleModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2>Schedule Conciliation Session</h2>
            <button type="button" class="close-btn" onclick="closeScheduleModal()">&times;</button>
        </div>
        <form id="wsScheduleForm" onsubmit="submitSchedule(event)">
            <input type="hidden" id="schedHearingType" value="Conciliation">
            <p style="margin: 0 0 12px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                In accordance with KP rules, scheduling a conciliation hearing registers the session in the central calendar and generates <strong>KP Form 11 (Notice of Hearing before the Pangkat)</strong>.
            </p>
            <div id="schedStageRuleHint" style="padding: 10px 14px; border-radius: 6px; font-size: 0.82rem; margin-bottom: 14px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; display: flex; align-items: flex-start; gap: 8px;">
                <span style="font-size: 1rem; line-height: 1;">📅</span>
                <div>
                    <strong>Barangay Tumana Rule:</strong> Conciliation hearings before the Pangkat are held on Mondays, Wednesdays, Thursdays, and Fridays (8:00 AM – 12:00 PM &amp; 1:00 PM – 5:00 PM) and Tuesday Mornings (8:00 AM – 12:00 PM). (Tuesday afternoons are exclusively reserved for Formal Mediation).
                </div>
            </div>
            <div class="form-group">
                <label for="schedDate">Hearing Date (Mon, Tue Morning, Wed, Thu, Fri) <span class="required-mark">*</span></label>
                <input type="text" id="schedDate" placeholder="Select hearing date..." required style="background: #fff; cursor: pointer;">
                <small id="schedDateSoftIndicator" style="color: #0369a1; font-size: 0.76rem; display: block; margin-top: 4px; font-weight: 600;"></small>
                <small id="schedDateError" style="color: #b91c1c; font-size: 0.75rem; display: none; margin-top: 4px;"></small>
            </div>
            <div class="form-group">
                <label for="schedTime">Call Time / Target Start Time <span class="required-mark">*</span></label>
                <select id="schedTime" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; background: #fff;">
                    <option value="">Select a date first</option>
                </select>
                <small id="schedSlotFeedback" style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Target call times on the conciliation docket. Elastic session duration (actual start &amp; end tracked).</small>
            </div>
            <div class="form-group">
                <label for="schedVenue">Venue <span class="required-mark">*</span></label>
                <input type="text" id="schedVenue" value="Lupon Court Room" readonly required maxlength="255" style="background: #f8fafc; cursor: not-allowed;">
                <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Conciliation sessions share the Lupon Court Room during operating windows.</small>
            </div>
            <div class="form-group">
                <label for="schedRemarks">Session Instructions / Remarks <span class="optional-label">Optional</span></label>
                <textarea id="schedRemarks" rows="2" placeholder="Optional conciliation session instructions..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeScheduleModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitSchedBtn">Schedule Conciliation Hearing</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 2: Record Attendance                -->
<!-- ========================================== -->
<div id="wsAttendanceModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h2 id="wsAttModalTitle">Record Conciliation Attendance</h2>
            <button type="button" class="close-btn" onclick="closeAttendanceModal()">&times;</button>
        </div>
        <div id="wsAttModalBody">
            <div class="empty-detail-state">Loading party records...</div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 3: Record Conciliation Outcome      -->
<!-- ========================================== -->
<div id="wsRecordOutcomeModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2>Record Conciliation Outcome</h2>
            <button type="button" class="close-btn" onclick="closeRecordOutcomeModal()">&times;</button>
        </div>
        <form onsubmit="submitRecordOutcome(event)">
            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Select the formal procedural outcome of the conciliation stage.
            </p>
            <div class="form-group">
                <label for="stageOutcomeSelect">Procedural Outcome <span class="required-mark">*</span></label>
                <select id="stageOutcomeSelect" required style="padding: 9px;">
                    <option value="Settled">Settled — Parties Reached Amicable Settlement</option>
                    <option value="Unsuccessful">Unsuccessful — Conciliation Failed (Eligible for CFA)</option>
                    <option value="Dismissed">Dismissed — Complainant Wilfully Failed to Appear</option>
                    <option value="Repudiated">Repudiated — Settlement Repudiated by Party</option>
                </select>
            </div>
            <div class="form-group">
                <label for="stageOutcomeRemarks">Determination Notes / Remarks <span class="optional-label">Optional</span></label>
                <textarea id="stageOutcomeRemarks" rows="3" placeholder="Summary of terms, reasons for failure, or basis of determination..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeRecordOutcomeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitOutcomeBtn">Confirm &amp; Record Outcome</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 4: Pangkat Extension                -->
<!-- ========================================== -->
<div id="wsPangkatExtensionModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 540px;">
        <div class="modal-header">
            <h2>Pangkat Conciliation Extension</h2>
            <button type="button" class="close-btn" onclick="closePangkatExtensionModal()">&times;</button>
        </div>
        <form onsubmit="submitPangkatExtension(event)">
            <p style="margin: 0 0 14px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Pursuant to <strong>RA 7160 Sec. 410(c)</strong>, the Pangkat may extend the 15-day conciliation period for a discretionary period not exceeding 15 days, or for a clearly meritorious exception.
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
                <div style="font-size: 0.82rem; color: #64748b; margin-bottom: 4px;">CURRENT CONCILIATION DEADLINE</div>
                <div id="extCurrentDeadline" style="font-size: 1rem; font-weight: 700; color: #0f172a;">Loading...</div>
            </div>
            <div class="form-group">
                <label style="font-weight: 700; display: block; margin-bottom: 8px;">Extension Type <span class="required-mark">*</span></label>
                <div style="display: flex; gap: 16px;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.88rem;">
                        <input type="radio" name="ext_type" value="Discretionary" checked onchange="toggleExtensionType(this.value)">
                        <span>Discretionary (Up to 15 days)</span>
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.88rem;">
                        <input type="radio" name="ext_type" value="Clearly Meritorious" onchange="toggleExtensionType(this.value)">
                        <span>Clearly Meritorious Exception</span>
                    </label>
                </div>
                <small id="extTypeNote" style="display: block; margin-top: 6px; font-size: 0.78rem; color: #64748b;">
                    Under RA 7160 Sec. 410(c), discretionary extension by the Pangkat cannot exceed fifteen (15) calendar days.
                </small>
            </div>
            <div class="form-group">
                <label for="extDays">Days to Extend <span class="required-mark">*</span></label>
                <input type="number" id="extDays" min="1" max="90" value="15" required oninput="updateProjectedDeadline()">
                <small style="display: block; margin-top: 4px; font-size: 0.78rem; color: #64748b;">
                    Projected New Deadline: <strong id="extProjectedDeadline" style="color: #0369a1;">Calculating...</strong>
                </small>
            </div>
            <div class="form-group" id="extMeritoriousWrap" style="display: none;">
                <label for="extMeritoriousBasis">Clearly Meritorious Basis <span class="required-mark">*</span></label>
                <textarea id="extMeritoriousBasis" rows="2" placeholder="Specify statutory or procedural basis (e.g. pending land survey, illness of principal, formal request for court transmittal)..."></textarea>
            </div>
            <div class="form-group">
                <label for="extJustification">Extension Justification / Panel Notes <span class="required-mark">*</span></label>
                <textarea id="extJustification" rows="3" required placeholder="Detailed reason for extending the conciliation period..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closePangkatExtensionModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitExtBtn">Grant Statutory Extension</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 5: Challenge Member                 -->
<!-- ========================================== -->
<div id="wsChallengeMemberModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2>Challenge Pangkat Member</h2>
            <button type="button" class="close-btn" onclick="closeChallengeMemberModal()">&times;</button>
        </div>
        <form onsubmit="submitChallengeMember(event)">
            <p style="margin: 0 0 14px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Under <strong>RA 7160 Sec. 404(d)</strong>, any party may challenge a Pangkat member on grounds of relationship, bias, interest, or other reasons discovered after constitution.
            </p>
            <div class="form-group">
                <label for="chalMemberId">Pangkat Member to Challenge <span class="required-mark">*</span></label>
                <select id="chalMemberId" required style="padding: 9px;"></select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label for="chalParty">Challenger Party <span class="required-mark">*</span></label>
                    <select id="chalParty" required style="padding: 9px;">
                        <option value="Complainant">Complainant</option>
                        <option value="Respondent">Respondent</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="chalDate">Challenge Date <span class="required-mark">*</span></label>
                    <input type="date" id="chalDate" required>
                </div>
            </div>
            <div class="form-group">
                <label for="chalPartyName">Challenger Representative / Name <span class="optional-label">Optional</span></label>
                <input type="text" id="chalPartyName" placeholder="Full name of person raising objection...">
            </div>
            <div class="form-group">
                <label for="chalGrounds">Grounds for Disqualification <span class="required-mark">*</span></label>
                <select id="chalGrounds" required style="padding: 9px;">
                    <option value="Bias or Partiality">Bias or Partiality</option>
                    <option value="Relationship within 4th Degree of Consanguinity or Affinity">Relationship within 4th Degree of Consanguinity or Affinity</option>
                    <option value="Financial or Material Interest">Financial or Material Interest</option>
                    <option value="Other Meritorious Grounds">Other Meritorious Grounds</option>
                </select>
            </div>
            <div class="form-group">
                <label for="chalDetails">Particulars / Factual Basis <span class="required-mark">*</span></label>
                <textarea id="chalDetails" rows="3" required placeholder="Specify facts, incidents, or relationship details giving rise to the challenge..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeChallengeMemberModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitChalBtn" style="background: #dc2626;">File Member Challenge</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 6: Resolve Challenge                -->
<!-- ========================================== -->
<div id="wsResolveChallengeModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header">
            <h2>Resolve Pangkat Challenge</h2>
            <button type="button" class="close-btn" onclick="closeResolveChallengeModal()">&times;</button>
        </div>
        <form onsubmit="submitResolveChallenge(event)">
            <input type="hidden" id="resChallengeId">
            <p style="margin: 0 0 12px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Under <strong>RA 7160 Sec. 404(d)</strong>, challenges are resolved by <em>majority vote</em> of the Pangkat members. If sustained, the vacancy is filled immediately from the Lupon roster.
            </p>
            <div id="resChalSummary" style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 0.84rem; color: #991b1b; line-height: 1.5;">
                Loading challenge details...
            </div>
            <div class="form-group">
                <label style="font-weight: 700; display: block; margin-bottom: 8px;">Pangkat Majority Decision <span class="required-mark">*</span></label>
                <div style="display: flex; gap: 16px;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.88rem;">
                        <input type="radio" name="res_decision" value="Disqualify and Replace" checked onchange="toggleDecisionReplacement(this.value)">
                        <span style="font-weight: 600; color: #dc2626;">Sustain &amp; Disqualify (Replace Member)</span>
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.88rem;">
                        <input type="radio" name="res_decision" value="Dismiss Challenge" onchange="toggleDecisionReplacement(this.value)">
                        <span style="font-weight: 600; color: #166534;">Dismiss Challenge (Retain Member)</span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label for="resVoting">Panel Voting Record <span class="required-mark">*</span></label>
                <input type="text" id="resVoting" value="2-0 Majority Vote (Remaining Members Concur)" required>
            </div>
            <div class="form-group">
                <label for="resNotes">Deliberation Findings &amp; Resolution Notes <span class="optional-label">Optional</span></label>
                <textarea id="resNotes" rows="2" placeholder="Summary of Pangkat deliberations and ruling rationale..."></textarea>
            </div>
            <div id="resReplacementWrap" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="font-size: 0.86rem; color: #166534; margin: 0 0 10px; font-weight: 700;">
                    Designate Replacement Member (RA 7160 §404d)
                </h4>
                <div class="form-group" style="margin-bottom: 10px;">
                    <label for="resReplacementId">Replacement Lupon Member <span class="required-mark">*</span></label>
                    <select id="resReplacementId" style="padding: 9px;"></select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="resRepMethod">Replacement Method <span class="required-mark">*</span></label>
                    <select id="resRepMethod" style="padding: 9px;">
                        <option value="Drawing of Lots">Drawing of Lots (Raffle by Punong Barangay)</option>
                        <option value="PB Assignment">Punong Barangay Direct Appointment</option>
                        <option value="Party Agreement">Agreement by the Parties</option>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeResolveChallengeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitResBtn">Record Majority Resolution</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 7: Pangkat Panel Selection & Impasse -->
<!-- ========================================== -->
<div id="wsPangkatSelectionModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 580px;">
        <div class="modal-header">
            <h2>Pangkat Selection &amp; Constitution</h2>
            <button type="button" class="close-btn" onclick="closePangkatSelectionModal()">&times;</button>
        </div>
        <form onsubmit="submitPangkatSelection(event)">
            <p style="margin: 0 0 14px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Under <strong>RA 7160 Sec. 404</strong>, parties choose 3 panel members from the Lupon roster. If parties fail to agree, the Punong Barangay fills the panel by drawing of lots or appointment.
            </p>
            <div class="form-group">
                <label for="selChairmanId">Pangkat Chairperson <span class="required-mark">*</span></label>
                <select id="selChairmanId" required style="padding: 9px;"></select>
            </div>
            <div class="form-group">
                <label for="selSecretaryId">Pangkat Secretary <span class="required-mark">*</span></label>
                <select id="selSecretaryId" required style="padding: 9px;"></select>
            </div>
            <div class="form-group">
                <label for="selMemberId">Pangkat Member <span class="required-mark">*</span></label>
                <select id="selMemberId" required style="padding: 9px;"></select>
            </div>
            <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
                <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.86rem; font-weight: 700; color: #92400e;">
                    <input type="checkbox" id="selAgreementFailed" onchange="togglePartyAgreementFailed(this)" style="width: 16px; height: 16px;">
                    <span>Parties Failed to Reach Agreement on Panel Selection</span>
                </label>
                <div id="selFailureWrap" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #fde68a;">
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="selPbMethod" style="font-size: 0.82rem;">Punong Barangay Selection Method <span class="required-mark">*</span></label>
                        <select id="selPbMethod" style="padding: 8px;">
                            <option value="Drawing of Lots">Drawing of Lots (Raffle pursuant to RA 7160 §404b)</option>
                            <option value="PB Appointment">Direct Appointment by Punong Barangay</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="selFailureReason" style="font-size: 0.82rem;">Impasse / Disagreement Notes</label>
                        <input type="text" id="selFailureReason" placeholder="e.g. Parties struck out mutually acceptable members; deadlock on 3rd panelist...">
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="selNotes">Constitution Notes / Remarks <span class="optional-label">Optional</span></label>
                <textarea id="selNotes" rows="2" placeholder="Administrative notes regarding panel constitution or party presence..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closePangkatSelectionModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitSelBtn">Save Panel Constitution</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../layouts/transmittal-modal.php'; ?>

<script src="../../assets/js/stage-workspace.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/stage-workspace.js'); ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        initStageWorkspace({
            mode: 'Conciliation',
            caseId: <?php echo json_encode($caseId); ?>,
            complaintId: <?php echo json_encode($complaintId); ?>
        });
    });
</script>

<?php include '../../layouts/footer.php'; ?>
