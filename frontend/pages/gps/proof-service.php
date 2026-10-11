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

        <!-- Search Toolbar -->
        <section class="gps-card proof-search-card" style="margin-bottom: 20px; padding: 18px 24px;">
            <div style="display: flex; gap: 12px; align-items: center; position: relative;">
                <svg style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: #94a3b8; pointer-events: none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input
                    id="summonCaseSearch"
                    type="search"
                    class="search-input-field"
                    placeholder="Search by KP number, complainant, or respondent..."
                    autocomplete="off"
                    style="width: 100%; padding: 10px 40px 10px 42px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.92rem; outline: none;"
                >
                <button
                    type="button"
                    id="clearSummonSearch"
                    title="Clear search"
                    style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; font-size: 20px; line-height: 1; color: #94a3b8; cursor: pointer; display: none;"
                >&times;</button>
            </div>
            <!-- Hidden select for script backward-compatibility -->
            <select id="proofCaseId" name="case_id" hidden aria-hidden="true">
                <option value="">Select a case</option>
            </select>
        </section>

        <!-- Cases Needing Summons Delivery Table -->
        <section class="gps-card" id="casesNeedingSummonsSection" style="margin-bottom: 24px;">
            <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
                <div>
                    <span class="section-kicker" style="text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: #2563eb; letter-spacing: 0.5px;">Pending Field Service</span>
                    <h2 style="font-size: 1.25rem; font-weight: 700; color: #1e293b; margin: 2px 0 0;">Cases Requiring Summons Delivery</h2>
                    <p style="margin: 4px 0 0; color: #64748b; font-size: 0.85rem;">Select a case to inspect summon delivery status and record officer returns.</p>
                </div>
                <span id="casesNeedingSummonsCount" class="count-badge" style="background: #eff6ff; color: #1d4ed8; font-weight: 600; padding: 4px 12px; border-radius: 20px; font-size: 0.82rem; border: 1px solid #bfdbfe;">0 cases</span>
            </div>

            <div class="table-responsive" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow-x: auto; background: #fff;">
                <table class="data-table proof-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;">KP Number</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;">Complainant</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;">Respondent</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;">Hearing Date</th>
                            <th style="padding: 12px 16px; text-align: center; font-weight: 600; font-size: 0.82rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="casesNeedingSummonsTable">
                        <tr>
                            <td colspan="5" class="empty-state" style="padding: 30px; text-align: center; color: #94a3b8;">
                                Loading cases requiring summons...
                            </td>
                        </tr>
                    </tbody>
                </table>
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
