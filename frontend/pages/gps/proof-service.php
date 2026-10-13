<?php
session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!isset($_SESSION['user_id']) || !in_array($roleId, [1, 2, 4], true)) {
    http_response_code(403);
    die('Access Denied');
}

include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/gps.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/gps.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <!-- Page Header -->
        <div class="page-header proof-page-header">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; width: 100%;">
                <div>
                    <a id="backLink" href="../complaints/complaint-list.php" class="back-link" hidden>
                        <svg class="back-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg>
                        <span id="backLinkLabel">Back to Complaint</span>
                    </a>
                    <div class="proof-title-row">
                        <div>
                            <h1 style="font-size: 1.6rem; font-weight: 800; color: #0f172a; margin: 0 0 4px 0; letter-spacing: -0.02em;">Summons</h1>
                            <p style="margin: 0; color: #64748b; font-size: 0.92rem;">Manage summons and notice delivery, record Officers' Returns, and review service progress for each case.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="proofMessage" class="proof-message" role="alert" aria-live="polite"></div>

        <!-- Filter Tabs & Unified Search Toolbar -->
        <div class="proof-workspace-toolbar">
            <div class="proof-filter-tabs" role="tablist" aria-label="Case service filters">
                <button type="button" id="tabFilterAll" class="proof-tab-btn active" role="tab" aria-selected="true" onclick="setProofFilter('all')">
                    All Cases <span id="countAllCases" class="proof-tab-count">0</span>
                </button>
                <button type="button" id="tabFilterPending" class="proof-tab-btn" role="tab" aria-selected="false" onclick="setProofFilter('pending')">
                    Pending Delivery <span id="countPendingDelivery" class="proof-tab-count">0</span>
                </button>
            </div>

            <div class="proof-search-container">
                <svg style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 17px; height: 17px; color: #94a3b8; pointer-events: none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input
                    id="summonCaseSearch"
                    type="search"
                    class="search-input-field"
                    placeholder="Search KP number, parties, or description..."
                    autocomplete="off"
                    style="width: 100%; padding: 9px 36px 9px 38px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.88rem; outline: none; background: #fff;"
                >
                <button
                    type="button"
                    id="clearSummonSearch"
                    title="Clear search"
                    style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; font-size: 18px; line-height: 1; color: #94a3b8; cursor: pointer; display: none;"
                >&times;</button>
            </div>

            <!-- Hidden select for script backward-compatibility -->
            <select id="proofCaseId" name="case_id" hidden aria-hidden="true">
                <option value="">Select a case</option>
            </select>
        </div>

        <!-- Unified Expandable Case Table Workspace -->
        <section class="gps-card proof-table-section" id="casesNeedingSummonsSection" style="margin-bottom: 24px; padding: 0; overflow: hidden;">
            <div class="table-responsive" style="border: none; border-radius: 12px; overflow-x: auto; background: #fff;">
                <table class="data-table proof-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                            <th style="width: 44px; padding: 12px 14px; text-align: center;" aria-label="Expand toggle"></th>
                            <th class="sortable-th" data-sort-key="kp_number" style="padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;" title="Click to sort by KP Number">
                                <span class="th-content">KP Number <span class="sort-indicator" id="sortInd_summon_kp_number">⇅</span></span>
                            </th>
                            <th class="sortable-th" data-sort-key="complainant" style="padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;" title="Click to sort by Complainant">
                                <span class="th-content">Complainant <span class="sort-indicator" id="sortInd_summon_complainant">⇅</span></span>
                            </th>
                            <th class="sortable-th" data-sort-key="respondent" style="padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;" title="Click to sort by Respondent">
                                <span class="th-content">Respondent <span class="sort-indicator" id="sortInd_summon_respondent">⇅</span></span>
                            </th>
                            <th class="sortable-th sort-active-asc" data-sort-key="hearing_date" style="padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;" title="Click to sort by Hearing Date">
                                <span class="th-content">Hearing Date <span class="sort-indicator" id="sortInd_summon_hearing_date">▲</span></span>
                            </th>
                            <th class="sortable-th" data-sort-key="service_status" style="padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;" title="Click to sort by Service Status">
                                <span class="th-content">Service Status <span class="sort-indicator" id="sortInd_summon_service_status">⇅</span></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="casesNeedingSummonsTable">
                        <tr>
                            <td colspan="6" class="empty-state" style="padding: 36px; text-align: center; color: #94a3b8;">
                                Loading case service records...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Modern Pagination Bar (5 items per page) -->
            <div id="summonPagination" class="complaints-pagination" aria-label="Proof of service pagination" style="display: none; padding: 14px 20px; border-top: 1px solid #e2e8f0; border-radius: 0 0 12px 12px;">
                <span id="summonPaginationSummary" class="complaints-pagination-summary" aria-live="polite">Showing 0 cases</span>
                <div id="summonPaginationControls" class="complaints-pagination-controls"></div>
            </div>
        </section>

        <!-- Hidden legacy section for backward compatibility -->
        <section id="hearingDeliveriesSection" hidden aria-hidden="true" style="display: none;">
            <div id="serviceStatusAlert" style="display: none;"></div>
            <div id="hearingDeliveriesContainer"></div>
        </section>
    </div>
</div>

<!-- Task 6: Modern Slide-in Officer's Return Drawer & Responsive Modal -->
<div id="officerReturnDrawerOverlay" class="proof-drawer-overlay proof-modal-overlay" onclick="closeOfficerReturnModal()" aria-hidden="true"></div>
<div id="officerReturnModal" class="proof-drawer" role="dialog" aria-modal="true" aria-labelledby="officerReturnModalTitle">
    <div class="proof-drawer-header">
        <div>
            <div class="drawer-badges">
                <span id="drawerCaseBadge" class="drawer-badge drawer-badge-case">Case #</span>
                <span id="drawerPartyBadge" class="drawer-badge drawer-badge-party-resp">Respondent (1st Attempt)</span>
                <span id="drawerDocBadge" class="drawer-badge" style="background: #f1f5f9; color: #475569;">KP Form No. 9</span>
            </div>
            <h3 id="officerReturnModalTitle" style="margin: 0; font-size: 1.2rem; font-weight: 700; color: #0f172a;">Log Officer's Return</h3>
            <p id="officerReturnModalSubtitle" style="margin: 4px 0 0; font-size: 0.84rem; color: #64748b;">Record delivery result and proof of service</p>
        </div>
        <button type="button" class="proof-modal-close" onclick="closeOfficerReturnModal()" aria-label="Close drawer" style="font-size: 1.6rem; color: #94a3b8; background: none; border: none; cursor: pointer;">&times;</button>
    </div>

    <form id="officerReturnForm" onsubmit="submitOfficerReturn(event)" enctype="multipart/form-data" style="display: flex; flex-direction: column; height: calc(100% - 100px); justify-content: space-between;">
        <input type="hidden" id="returnCaseId" name="case_id" value="">
        <input type="hidden" id="returnHearingId" name="hearing_id" value="">
        <input type="hidden" id="returnPartyType" name="party_type" value="">
        <input type="hidden" id="returnResidentId" name="resident_id" value="">
        <input type="hidden" id="returnAttemptNumber" name="attempt_number" value="1">
        <input type="hidden" id="returnDeliveryId" name="delivery_id" value="">
        <input type="hidden" name="action" value="officer_return">

        <div class="proof-drawer-body">
            <!-- Delivery Result / Status -->
            <div class="form-group">
                <label for="returnDeliveryStatus" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Delivery Result / Service Mode <span style="color: #dc2626;">*</span>
                </label>
                <select id="returnDeliveryStatus" name="delivery_status" required style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;" onchange="handleDeliveryStatusChange()">
                    <option value="Served Personal">Served - Personal (Direct to party)</option>
                    <option value="Served Substituted">Served - Substituted (Competent person of residence)</option>
                    <option value="Served Refused">Refused to Receive (Treated as served under KP rules)</option>
                    <option value="Unserved">Unserved / Delivery Failed (Cannot be served)</option>
                </select>
            </div>

            <!-- Date & Time of Service -->
            <div id="servedAtGroup" class="form-group">
                <label for="returnServedAt" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Date &amp; Time Served <span style="color: #dc2626;">*</span>
                </label>
                <input type="datetime-local" id="returnServedAt" name="served_at" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
            </div>

            <!-- Recipient Name -->
            <div id="recipientNameGroup" class="form-group">
                <label for="returnRecipientName" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Recipient / Receiving Person Name <span id="recipientReqStar" style="color: #dc2626;">*</span>
                </label>
                <input type="text" id="returnRecipientName" name="recipient_name" placeholder="Full name of person who received the notice" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
            </div>

            <!-- Relationship (for Substituted) -->
            <div id="relationshipGroup" class="form-group" style="display: none;">
                <label for="returnRelationship" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Relationship to Party <span style="color: #dc2626;">*</span>
                </label>
                <input type="text" id="returnRelationship" name="relationship" placeholder="e.g., Spouse, Sibling, Parent, Co-resident" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
                <small style="color: #64748b; font-size: 0.8rem; margin-top: 4px; display: block;">Must be a person of sufficient age and discretion residing at the same residence.</small>
            </div>

            <!-- Failure Reason (for Unserved) -->
            <div id="unservedReasonGroup" class="form-group" style="display: none;">
                <label for="returnUnservedReason" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Reason for Failure to Serve <span style="color: #dc2626;">*</span>
                </label>
                <select id="returnUnservedReason" name="unserved_reason" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
                    <option value="Moved Out">Moved Out (No longer residing at given address)</option>
                    <option value="Wrong Address">Wrong Address (Address unknown or non-existent)</option>
                    <option value="No One Home">No One Home (Unreachable after multiple attempts)</option>
                    <option value="Refused">Refused (Party refused to sign or receive)</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <!-- Failure Notes (for Unserved) -->
            <div id="failureNotesGroup" class="form-group" style="display: none;">
                <label for="returnFailureNotes" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Service Failure Notes &amp; Observations <span style="color: #dc2626;">*</span>
                </label>
                <textarea id="returnFailureNotes" name="failure_notes" rows="3" placeholder="Provide detailed report of attempts made and information verified from neighbors/barangay..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;"></textarea>
                <small style="color: #dc2626; font-size: 0.8rem; margin-top: 4px; display: block;">⚠️ Marking Unserved automatically pauses the mediation hearing until address correction or re-service.</small>
            </div>

            <!-- Remarks / Notes -->
            <div class="form-group">
                <label for="returnRemarks" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Server's Return Notes / Witness Info
                </label>
                <textarea id="returnRemarks" name="remarks" rows="2" placeholder="Optional notes, witness name (for refusal), or specific circumstances..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;"></textarea>
            </div>

            <!-- Proof Photo / Signature Upload -->
            <div class="form-group">
                <label for="returnProofImage" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block; font-size: 0.88rem;">
                    Proof of Service Photo / Signature (Optional)
                </label>
                <input type="file" id="returnProofImage" name="proof_image" accept="image/jpeg,image/png,image/webp" style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem;">
                <small style="color: #64748b; font-size: 0.8rem; margin-top: 4px; display: block;">Upload geotagged photo of service, receiving copy with signature, or house photo (Max 5MB).</small>
            </div>
        </div>

        <div class="proof-drawer-footer">
            <button type="button" class="btn-secondary" onclick="closeOfficerReturnModal()" style="padding: 9px 18px; border-radius: 8px; font-weight: 600; border: 1px solid #cbd5e1; background: #fff; cursor: pointer;">Cancel</button>
            <button type="submit" id="submitOfficerReturnBtn" class="btn-primary" style="background: #0284c7; color: #fff; padding: 9px 20px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                Save Officer's Return
            </button>
        </div>
    </form>
</div>

<script src="../../assets/js/gps.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/gps.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
