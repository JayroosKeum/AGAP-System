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

        <div class="page-header proof-page-header">
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
                        <h1>Proof of Service</h1>
                        <p>Record officer returns, track delivery of summons and notices, and review service attempts for cases.</p>
                    </div>
                </div>
            </div>
        </div>

        <div id="proofMessage" class="proof-message" role="alert" aria-live="polite"></div>

        <section class="gps-card proof-filter-card" aria-labelledby="caseSelectionTitle">
            <div class="section-heading compact-heading">
                <div>
                    <span class="section-kicker">Case lookup</span>
                    <h2 id="caseSelectionTitle">Select a case to inspect</h2>
                    <p>Select a case to inspect summon delivery status, record officer returns, and review service attempts.</p>
                </div>
            </div>

            <div class="form-group proof-case-picker">
                <label for="proofCaseId">Case</label>
                <select id="proofCaseId" name="case_id">
                    <option value="">Select a case</option>
                </select>
            </div>
        </section>

        <section id="caseSummaryCard" class="gps-card case-summary-card" hidden aria-labelledby="summaryComplaintTitle">
            <div class="case-summary-header">
                <div>
                    <div class="summary-pills">
                        <span class="meta-pill case-pill" id="summaryCaseNumber">Case</span>
                        <span class="meta-pill complaint-pill" id="summaryComplaintNumber">Complaint</span>
                    </div>
                    <h2 id="summaryComplaintTitle">Complaint Details</h2>
                </div>
                <a id="viewComplaintLink" class="btn-secondary-link" href="../complaints/complaint-details.php">View Complaint</a>
            </div>

            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label">Complainant</span>
                    <strong id="summaryComplainants">Not available</strong>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Respondent</span>
                    <strong id="summaryRespondents">Not available</strong>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Case Status</span>
                    <strong id="summaryCaseStatus">Not available</strong>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Incident Location</span>
                    <strong id="summaryIncidentLocation">Not available</strong>
                </div>
            </div>
        </section>

        <!-- Officer's Return & Summon Deliveries Section -->
        <section id="hearingDeliveriesSection" class="gps-card" hidden aria-labelledby="hearingDeliveriesTitle">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">Officer's Return &amp; Hearing Summons</span>
                    <h2 id="hearingDeliveriesTitle">Hearing Summon Deliveries</h2>
                    <p>Track independent delivery of summons and notices for Complainant and Respondent.</p>
                </div>
            </div>

            <!-- Attendance & Hearing Unlock Status Notice -->
            <div id="serviceStatusAlert" class="service-status-banner" style="display:none; margin-bottom: 20px;"></div>

            <!-- Delivery Cards Grid -->
            <div id="hearingDeliveriesContainer" class="hearing-deliveries-grid">
                <!-- Dynamically populated by gps.js: Complainant card & Respondent card -->
            </div>
        </section>

        <section class="gps-card" aria-labelledby="summonsNoticesTitle">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">Issued documents</span>
                    <h2 id="summonsNoticesTitle">Summons Notices</h2>
                    <p>Review issued summons records and their current service state.</p>
                </div>
                <span id="noticeCount" class="count-badge">0 notices</span>
            </div>

            <div class="table-responsive">
                <table class="data-table proof-table">
                    <thead>
                        <tr>
                            <th>Date Issued</th>
                            <th>Document</th>
                            <th>Service Status</th>
                            <th>Attempts</th>
                            <th>Document</th>
                        </tr>
                    </thead>
                    <tbody id="summonsNoticesTable">
                        <tr><td colspan="5" class="empty-state">Select a case to view its summons notices.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="gps-card" aria-labelledby="serviceHistoryTitle">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">Recorded activity</span>
                    <h2 id="serviceHistoryTitle">Service History &amp; Attempts</h2>
                    <p>All recorded attempts are shown in reverse chronological order.</p>
                </div>
                <span id="historyCount" class="count-badge">0 attempts</span>
            </div>

            <div class="table-responsive">
                <table class="data-table proof-table history-table">
                    <thead>
                        <tr>
                            <th>Attempt</th>
                            <th>Summons</th>
                            <th>Service Date</th>
                            <th>Served By</th>
                            <th>Result</th>
                            <th>Remarks</th>
                            <th>Proof Image</th>
                        </tr>
                    </thead>
                    <tbody id="proofHistoryTable">
                        <tr><td colspan="7" class="empty-state">Select a case to view its service history.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<!-- Log Officer's Return Modal -->
<div id="officerReturnModal" class="proof-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="officerReturnModalTitle">
    <div class="proof-modal-content">
        <div class="proof-modal-header">
            <div>
                <h3 id="officerReturnModalTitle">Log Officer's Return</h3>
                <p id="officerReturnModalSubtitle">Record delivery result for party notice / summon</p>
            </div>
            <button type="button" class="proof-modal-close" onclick="closeOfficerReturnModal()" aria-label="Close modal">&times;</button>
        </div>

        <form id="officerReturnForm" onsubmit="submitOfficerReturn(event)" enctype="multipart/form-data">
            <input type="hidden" id="returnCaseId" name="case_id" value="">
            <input type="hidden" id="returnHearingId" name="hearing_id" value="">
            <input type="hidden" id="returnPartyType" name="party_type" value="">
            <input type="hidden" id="returnResidentId" name="resident_id" value="">
            <input type="hidden" name="action" value="officer_return">

            <div class="proof-modal-body">
                <!-- Delivery Result / Status -->
                <div class="form-group">
                    <label for="returnDeliveryStatus" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">
                        Delivery Result / Service Mode <span style="color: #dc2626;">*</span>
                    </label>
                    <select id="returnDeliveryStatus" name="delivery_status" required style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;" onchange="handleDeliveryStatusChange()">
                        <option value="Served Personal">Served - Personal (Direct to party)</option>
                        <option value="Served Substituted">Served - Substituted (Competent person of residence)</option>
                        <option value="Served Refused">Refused to Receive (Treated as served under KP rules)</option>
                        <option value="Unserved">Unserved / Delivery Failed (Cannot be served)</option>
                    </select>
                </div>

                <!-- Date & Time of Service (for Served/Refused) -->
                <div id="servedAtGroup" class="form-group">
                    <label for="returnServedAt" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">
                        Date &amp; Time Served <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="datetime-local" id="returnServedAt" name="served_at" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
                </div>

                <!-- Recipient Name (for Personal & Substituted) -->
                <div id="recipientNameGroup" class="form-group">
                    <label for="returnRecipientName" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">
                        Recipient / Receiving Person Name <span id="recipientReqStar" style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="returnRecipientName" name="recipient_name" placeholder="Full name of person who received the summon" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
                </div>

                <!-- Relationship (for Substituted) -->
                <div id="relationshipGroup" class="form-group" style="display: none;">
                    <label for="returnRelationship" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">
                        Relationship to Party <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="returnRelationship" name="relationship" placeholder="e.g., Spouse, Sibling, Parent, Co-resident" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
                    <small style="color: #64748b; font-size: 0.8rem; margin-top: 4px; display: block;">Must be a person of sufficient age and discretion residing at the same residence.</small>
                </div>

                <!-- Failure Reason (for Unserved) -->
                <div id="unservedReasonGroup" class="form-group" style="display: none;">
                    <label for="returnUnservedReason" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">
                        Reason for Failure to Serve <span style="color: #dc2626;">*</span>
                    </label>
                    <select id="returnUnservedReason" name="unserved_reason" style="width: 100%; min-height: 42px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;">
                        <option value="Moved Out">Moved Out (No longer residing at given address)</option>
                        <option value="Wrong Address">Wrong Address (Address unknown or non-existent)</option>
                        <option value="No One Home">No One Home (Unreachable after multiple attempts)</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <!-- Failure Notes (for Unserved) -->
                <div id="failureNotesGroup" class="form-group" style="display: none;">
                    <label for="returnFailureNotes" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">
                        Service Failure Notes &amp; Observations <span style="color: #dc2626;">*</span>
                    </label>
                    <textarea id="returnFailureNotes" name="failure_notes" rows="3" placeholder="Provide detailed report of attempts made and information verified from neighbors/barangay..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;"></textarea>
                    <small style="color: #dc2626; font-size: 0.8rem; margin-top: 4px; display: block;">⚠️ Marking Unserved automatically pauses the mediation hearing until address correction or re-service.</small>
                </div>

                <!-- Remarks / Notes -->
                <div class="form-group">
                    <label for="returnRemarks" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">
                        Server's Return Notes / Witness Info
                    </label>
                    <textarea id="returnRemarks" name="remarks" rows="2" placeholder="Optional notes, witness name (for refusal), or specific circumstances..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem;"></textarea>
                </div>

                <!-- Proof Photo / Signature Upload -->
                <div class="form-group">
                    <label for="returnProofImage" style="font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">
                        Proof of Service Photo / Signature (Optional)
                    </label>
                    <input type="file" id="returnProofImage" name="proof_image" accept="image/jpeg,image/png,image/webp" style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem;">
                    <small style="color: #64748b; font-size: 0.8rem; margin-top: 4px; display: block;">Upload geotagged photo of service, receiving copy with signature, or house photo (Max 5MB).</small>
                </div>
            </div>

            <div class="proof-modal-footer">
                <button type="button" class="btn-secondary" onclick="closeOfficerReturnModal()">Cancel</button>
                <button type="submit" id="submitOfficerReturnBtn" class="btn-primary" style="background: #2563eb; color: #fff; padding: 9px 18px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer;">Save Officer's Return</button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/gps.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/gps.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
