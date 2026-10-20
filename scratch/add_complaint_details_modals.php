<?php
$file = __DIR__ . '/../frontend/pages/complaints/complaint-details.php';
$content = file_get_contents($file);

$target = "<?php include __DIR__ . '/../../layouts/summons-preview-modal.php'; ?>";

$modalsHtml = <<<'HTML'
<!-- ===================================================================
     MODALS FOR PAANYAYA 1 & 2 WORKFLOW & OFFICER'S RETURN TRACKING
     =================================================================== -->

<!-- Modal 1: Issue Paanyaya Stage Modal (Paanyaya 1 or Paanyaya 2) -->
<div id="issuePaanyayaStageModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="issueStageModalHeading">
    <div class="modal-content" style="max-width: 580px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">✉️</span>
                <div>
                    <h2 id="issueStageModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Issue Paanyaya 1</h2>
                    <span id="issueStageModalSub" style="font-size: 0.8rem; color: #64748b;">First Preliminary Invitation (Informal Dialogue)</span>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeIssuePaanyayaStageModal()">&times;</button>
        </div>
        <form id="issuePaanyayaStageForm" onsubmit="submitIssuePaanyayaStage(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <input type="hidden" id="issueStageType" name="stage_type" value="Paanyaya 1">

            <p style="font-size: 0.86rem; color: #475569; margin: 0 0 16px; line-height: 1.5;">
                Prepare and issue an official preliminary invitation letter to invite the respondent to an informal dialogue before formal case docketing.
            </p>

            <div class="form-row-2" style="margin-bottom: 12px;">
                <div class="form-group">
                    <label for="issueStageDate" style="font-weight: 600; font-size: 0.84rem;">Dialogue Date <span class="required-mark">*</span></label>
                    <input type="date" id="issueStageDate" name="invitation_date" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
                <div class="form-group">
                    <label for="issueStageTime" style="font-weight: 600; font-size: 0.84rem;">Dialogue Time <span class="required-mark">*</span></label>
                    <input type="time" id="issueStageTime" name="invitation_time" value="14:00" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="issueStageOfficer" style="font-weight: 600; font-size: 0.84rem;">Assigned Serving Officer <span class="required-mark">*</span></label>
                <select id="issueStageOfficer" name="assigned_officer_id" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    <option value="">-- Select Serving Officer --</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="issueStageVenue" style="font-weight: 600; font-size: 0.84rem;">Venue</label>
                <input type="text" id="issueStageVenue" name="venue" value="Tanggapan ng Barangay Tumana" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="issueStageSalaysay" style="font-weight: 600; font-size: 0.84rem;">Salaysay / Purpose of Dialogue (Optional override)</label>
                <textarea id="issueStageSalaysay" name="salaysay" rows="2" placeholder="Leave blank to use complaint narrative..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.86rem; color: #1e293b;">
                    <input type="checkbox" id="issueStageHasSig" name="has_e_signature" value="1" checked style="width: 16px; height: 16px;">
                    <span>Embed Punong Barangay (Hon. Akiko Lynne Centeno) calligraphic e-signature</span>
                </label>
            </div>

            <div id="issueStageError" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeIssuePaanyayaStageModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitIssueStage" style="background: #16a34a; border-color: #16a34a;">Issue Invitation &amp; Generate Letter</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Record Officer's Return Modal (Attempts 1 to 6) -->
<div id="recordOfficersReturnModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="rorModalHeading">
    <div class="modal-content" style="max-width: 620px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15); max-height: calc(100vh - 40px); overflow-y: auto;">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">📝</span>
                <div>
                    <h2 id="rorModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Record Officer's Return</h2>
                    <div style="display: flex; gap: 6px; align-items: center; margin-top: 3px;">
                        <span id="rorStageBadge" class="paanyaya-stage-tag">Paanyaya 1</span>
                        <span id="rorAttemptBadge" style="font-size: 0.75rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px;">Attempt 1 of 6</span>
                    </div>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeRecordOfficersReturnModal()">&times;</button>
        </div>

        <form id="recordOfficersReturnForm" onsubmit="submitRecordOfficersReturn(event)" enctype="multipart/form-data">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <input type="hidden" id="rorStageType" name="stage_type" value="Paanyaya 1">

            <!-- 1. Date and Time of Service Attempt -->
            <div class="form-row-2" style="margin-bottom: 12px;">
                <div class="form-group">
                    <label for="rorServiceDatetime" style="font-weight: 600; font-size: 0.84rem;">Date &amp; Time Visited <span class="required-mark">*</span></label>
                    <input type="datetime-local" id="rorServiceDatetime" name="service_datetime" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
                <div class="form-group">
                    <label for="rorServingOfficer" style="font-weight: 600; font-size: 0.84rem;">Assigned Serving Officer <span class="required-mark">*</span></label>
                    <select id="rorServingOfficer" name="serving_officer_id" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                        <option value="">-- Select Serving Officer --</option>
                    </select>
                </div>
            </div>

            <!-- 2. Respondent / Intended Recipient -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="rorRecipientName" style="font-weight: 600; font-size: 0.84rem;">Respondent / Intended Recipient Visited <span class="required-mark">*</span></label>
                <input type="text" id="rorRecipientName" name="recipient_name" required placeholder="Name of respondent or authorized recipient..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <!-- 3. Address Visited -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="rorServiceAddress" style="font-weight: 600; font-size: 0.84rem;">Address Visited <span class="required-mark">*</span></label>
                <input type="text" id="rorServiceAddress" name="service_address" required placeholder="Complete address visited in Barangay Tumana..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <!-- 4. Delivery Outcome -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="rorDeliveryOutcome" style="font-weight: 600; font-size: 0.84rem;">Delivery Outcome <span class="required-mark">*</span></label>
                <select id="rorDeliveryOutcome" name="delivery_outcome" required onchange="handleRorOutcomeChange()" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    <option value="">-- Select Actual Delivery Outcome --</option>
                    <option value="Successfully delivered">✓ Successfully delivered</option>
                    <option value="Recipient unavailable">⚠️ Recipient unavailable</option>
                    <option value="Recipient refused to receive">⛔ Recipient refused to receive</option>
                    <option value="Respondent not found at the address">❓ Respondent not found at the address</option>
                    <option value="Incorrect or incomplete address">📍 Incorrect or incomplete address</option>
                    <option value="Other reason">📋 Other reason</option>
                </select>
            </div>

            <!-- 5. Reason for Unsuccessful Delivery (Conditional) -->
            <div class="form-group" id="rorUnsuccessfulReasonWrap" style="display: none; margin-bottom: 12px;">
                <label for="rorUnsuccessfulReason" style="font-weight: 600; font-size: 0.84rem; color: #b91c1c;">Reason for Unsuccessful Delivery <span class="required-mark">*</span></label>
                <input type="text" id="rorUnsuccessfulReason" name="unsuccessful_reason" placeholder="Explain why the invitation could not be served..." style="padding: 8px 10px; font-size: 0.88rem; border: 1.5px solid #fca5a5; border-radius: 6px; width: 100%;">
            </div>

            <!-- 6. Remarks or Explanation -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="rorRemarks" style="font-weight: 600; font-size: 0.84rem;">Remarks / Detailed Explanation</label>
                <textarea id="rorRemarks" name="remarks" rows="2" placeholder="Describe interaction, who was present, verbal statements, or serving notes..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <!-- 7. Proof of Service (Attachment) -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="rorProofFile" style="font-weight: 600; font-size: 0.84rem;">Proof of Service Attachment (Photo / Document)</label>
                <input type="file" id="rorProofFile" name="proof_file" accept="image/jpeg,image/png,image/webp,application/pdf" style="padding: 6px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; background: #f8fafc;">
                <span style="font-size: 0.74rem; color: #64748b;">Accepted formats: JPG, PNG, WEBP, PDF (Max 10MB). Photos of the residence or received letter.</span>
            </div>

            <!-- 8. GPS Coordinates & Recorded Service Location -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: #0f172a;">📍 GPS Location Coordinates</span>
                    <button type="button" class="btn-sm btn-case-outline" onclick="captureReturnGpsLocation()" style="padding: 3px 10px; font-size: 0.76rem;">
                        📍 Capture Current Location
                    </button>
                </div>
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom: 6px;">
                        <input type="text" id="rorLatitude" name="latitude" placeholder="Latitude (e.g. 14.6521)" style="padding: 6px 8px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    </div>
                    <div class="form-group" style="margin-bottom: 6px;">
                        <input type="text" id="rorLongitude" name="longitude" placeholder="Longitude (e.g. 121.0854)" style="padding: 6px 8px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    </div>
                </div>
                <div id="rorGpsStatus" style="font-size: 0.74rem; color: #64748b; margin-top: 2px;"></div>
            </div>

            <!-- 9. Recorded By Display -->
            <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 16px; padding: 8px 12px; background: #f1f5f9; border-radius: 6px;">
                <strong>Recorded by:</strong> <?php echo htmlspecialchars($_SESSION['username'] ?? 'Authorized User'); ?> &bull; 
                <strong>Submission Time:</strong> <?php echo date('M j, Y g:i A'); ?>
            </div>

            <div id="rorErrorBox" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeRecordOfficersReturnModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitRecordReturn" style="background: #2563eb; border-color: #2563eb;">Save Officer's Return</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: View Officer's Return Details Modal -->
<div id="viewOfficersReturnModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="vorModalHeading">
    <div class="modal-content" style="max-width: 660px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15); max-height: calc(100vh - 40px); overflow-y: auto;">
        <div class="modal-header" style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">📄</span>
                <div>
                    <h2 id="vorModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Officer's Return Details</h2>
                    <span id="vorModalSub" style="font-size: 0.8rem; color: #64748b;">Official Service Attempt Record</span>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeViewOfficersReturnModal()">&times;</button>
        </div>

        <div id="vorContentBody" style="display: flex; flex-direction: column; gap: 14px;">
            <!-- Populated dynamically by complaints.js -->
        </div>

        <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
            <button type="button" class="btn-case-outline" id="btnVorEditCorrection" style="display: none;" onclick="openEditFromViewReturn()">
                ✏️ Edit Correction
            </button>
            <button type="button" class="btn-secondary" onclick="closeViewOfficersReturnModal()">Close</button>
        </div>
    </div>
</div>

<!-- Modal 4: Edit Officer's Return Correction Modal -->
<div id="editOfficersReturnModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="eorModalHeading">
    <div class="modal-content" style="max-width: 560px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.3rem;">✏️</span>
                <h2 id="eorModalHeading" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Edit Officer's Return (Permitted Correction)</h2>
            </div>
            <button type="button" class="close-btn" onclick="closeEditOfficersReturnModal()">&times;</button>
        </div>
        <form id="editOfficersReturnForm" onsubmit="submitEditOfficersReturn(event)">
            <input type="hidden" id="eorReturnId" name="return_id" value="">

            <p style="font-size: 0.84rem; color: #475569; margin: 0 0 14px; line-height: 1.45;">
                Permitted correction to remarks, address, or explanation. The original record is preserved and all corrections are recorded in the audit trail.
            </p>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="eorServiceAddress" style="font-weight: 600; font-size: 0.84rem;">Service Address Visited</label>
                <input type="text" id="eorServiceAddress" name="service_address" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="eorUnsuccessfulReason" style="font-weight: 600; font-size: 0.84rem;">Reason for Unsuccessful Delivery</label>
                <input type="text" id="eorUnsuccessfulReason" name="unsuccessful_reason" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="eorRemarks" style="font-weight: 600; font-size: 0.84rem;">Remarks / Explanation</label>
                <textarea id="eorRemarks" name="remarks" rows="2" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label for="eorCorrectionNotes" style="font-weight: 600; font-size: 0.84rem; color: #b91c1c;">Correction Justification / Reason <span class="required-mark">*</span></label>
                <input type="text" id="eorCorrectionNotes" name="correction_notes" required placeholder="State reason for this correction (required for audit trail)..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <div id="eorErrorBox" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeEditOfficersReturnModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitEditReturn">Save Correction</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 5: Authorized Lupon Review Modal (Exhausted Attempts / Transition Override) -->
<div id="reviewPaanyayaModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="rpmModalHeading">
    <div class="modal-content" style="max-width: 580px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">⚖️</span>
                <div>
                    <h2 id="rpmModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Authorized Lupon Review Decision</h2>
                    <span id="rpmModalSub" style="font-size: 0.8rem; color: #64748b;">Review for Paanyaya 1</span>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closePaanyayaReviewModal()">&times;</button>
        </div>

        <form id="reviewPaanyayaForm" onsubmit="submitPaanyayaReview(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <input type="hidden" id="rpmStageType" name="stage_type" value="Paanyaya 1">

            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 0.86rem; color: #991b1b; line-height: 1.45;">
                <strong>⚠️ Safeguard Notice:</strong> All six (6) delivery attempts have been exhausted without successful service. The system does not automatically advance or issue summons. An authorized Lupon officer must review and decide the next procedural action.
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="rpmDecision" style="font-weight: 600; font-size: 0.84rem;">Authorized Review Determination <span class="required-mark">*</span></label>
                <select id="rpmDecision" name="decision" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    <option value="">-- Select Procedural Decision --</option>
                    <option value="Approved for Paanyaya 2">Authorize Paanyaya 2 (Proceed to Second Preliminary Invitation)</option>
                    <option value="Approved for Summons">Authorize Formal Summons (Escalate to 1st Mediation Summons)</option>
                    <option value="Case Closed">Close Case (Administratively Dismissed / Dropped)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label for="rpmReviewNotes" style="font-weight: 600; font-size: 0.84rem;">Review Notes &amp; Official Justification <span class="required-mark">*</span></label>
                <textarea id="rpmReviewNotes" name="review_notes" rows="3" required placeholder="State official reasons, review findings, and Lupon instructions for this decision..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <div id="rpmErrorBox" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closePaanyayaReviewModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitReview" style="background: #dc2626; border-color: #dc2626;">Confirm Decision</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 6: Proof of Service Lightbox Viewer -->
<div id="paanyayaProofLightboxModal" class="modal" style="display:none; z-index: 10000;" role="dialog" aria-modal="true" aria-labelledby="plbTitle">
    <div class="modal-content" style="max-width: 800px; padding: 20px; border-radius: 12px; background: #ffffff;">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 0; border: none;">
            <h3 id="plbTitle" style="margin: 0; font-size: 1.1rem; color: #0f172a; font-weight: 700;">Proof of Service Attachment</h3>
            <button type="button" class="close-btn" onclick="closePaanyayaProofLightbox()">&times;</button>
        </div>
        <div style="text-align: center; max-height: calc(85vh - 120px); overflow: auto; background: #0f172a; border-radius: 8px; padding: 10px;">
            <img id="plbImage" src="" alt="Proof of Service" style="max-width: 100%; max-height: 70vh; object-fit: contain; border-radius: 4px;">
            <div id="plbPdfContainer" style="display: none; padding: 20px; color: #ffffff;">
                <p>This proof is a PDF document.</p>
                <a id="plbPdfLink" href="#" target="_blank" class="btn-create" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;">
                    📄 Open PDF in New Window
                </a>
            </div>
        </div>
        <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px;">
            <a id="plbDownloadBtn" href="#" download class="btn-case-outline" style="text-decoration: none;">Download File</a>
            <button type="button" class="btn-secondary" onclick="closePaanyayaProofLightbox()">Close</button>
        </div>
    </div>
</div>

HTML;

if (strpos($content, $target) !== false) {
    $content = str_replace($target, $modalsHtml . "\n" . $target, $content);
    file_put_contents($file, $content);
    echo "Modals added successfully to complaint-details.php!\n";
} else {
    echo "Target summons-preview-modal not found in complaint-details.php\n";
}
