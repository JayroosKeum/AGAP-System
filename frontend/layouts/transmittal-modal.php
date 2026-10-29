<!-- ======================================================== -->
<!-- MODAL: Record Court & DILG Transmittal Submission        -->
<!-- ======================================================== -->
<div id="recordTransmittalModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="recTransTitle" style="display: none;">
    <div class="modal-content" style="width: 680px; max-width: calc(100% - 32px); max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <div>
                <h2 id="recTransTitle" style="margin: 0; font-size: 1.2rem; color: #0f172a;">Record Court &amp; DILG Transmittal</h2>
                <p style="margin: 3px 0 0; font-size: 0.82rem; color: #64748b;">
                    Official transmittal of certified settlement records pursuant to RA 7160 Sec. 411.
                </p>
            </div>
            <button type="button" class="close-btn" onclick="closeRecordTransmittalModal()">&times;</button>
        </div>

        <form id="recordTransmittalForm" onsubmit="handleRecordTransmittalSubmit(event)" enctype="multipart/form-data">
            <input type="hidden" id="transmittalCaseId" name="case_id">

            <!-- Repudiation Window Notice -->
            <div id="transmittalModalRepudiationAlert" style="margin-bottom: 14px;"></div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="transmittalDate" style="font-weight: 600; font-size: 0.84rem;">
                        Date Transmitted <span class="required-mark">*</span>
                    </label>
                    <input type="date" id="transmittalDate" name="transmittal_date" required style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem;">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="transmittalTargetAgency" style="font-weight: 600; font-size: 0.84rem;">
                        Target Agency <span class="required-mark">*</span>
                    </label>
                    <select id="transmittalTargetAgency" name="target_agency" required style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem;">
                        <option value="Both" selected>Both MTC &amp; DILG (Recommended)</option>
                        <option value="MTC">Clerk of Court, MTCC Marikina Only</option>
                        <option value="DILG">DILG Marikina Field Office Only</option>
                    </select>
                </div>
            </div>

            <!-- Two-Column Receiving Details -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 10px;">
                    Agency Receiving Particulars (Stamp / Acknowledgment)
                </span>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <!-- MTC Column -->
                    <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px;">
                        <strong style="display: block; font-size: 0.82rem; color: #1e3a8a; margin-bottom: 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
                            MTCC Marikina City
                        </strong>
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label for="transmittalMtcReceiver" style="font-size: 0.78rem; font-weight: 600;">Received by (Name/Title)</label>
                            <input type="text" id="transmittalMtcReceiver" name="mtc_receiver_name" placeholder="e.g. Atty. Juan Dela Cruz, Clerk of Court" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.8rem; box-sizing: border-box;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="transmittalMtcDate" style="font-size: 0.78rem; font-weight: 600;">Date Received by MTC</label>
                            <input type="date" id="transmittalMtcDate" name="mtc_received_date" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.8rem; box-sizing: border-box;">
                        </div>
                    </div>

                    <!-- DILG Column -->
                    <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px;">
                        <strong style="display: block; font-size: 0.82rem; color: #065f46; margin-bottom: 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
                            DILG Marikina Field Office
                        </strong>
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label for="transmittalDilgReceiver" style="font-size: 0.78rem; font-weight: 600;">Received by (Name/Title)</label>
                            <input type="text" id="transmittalDilgReceiver" name="dilg_receiver_name" placeholder="e.g. Maria Santos, Receiving Officer" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.8rem; box-sizing: border-box;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="transmittalDilgDate" style="font-size: 0.78rem; font-weight: 600;">Date Received by DILG</label>
                            <input type="date" id="transmittalDilgDate" name="dilg_received_date" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.8rem; box-sizing: border-box;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scanned Receiving Copy Upload -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="transmittalReceivingFile" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                    Scanned / Photo of Stamped Receiving Copy <span style="font-weight: 400; color: #64748b;">(Optional)</span>
                </label>
                <input type="file" id="transmittalReceivingFile" name="receiving_copy" accept=".pdf, .jpg, .jpeg, .png, .webp" style="width: 100%; font-size: 0.82rem; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 8px 10px; background: #fafafa; box-sizing: border-box;">
                <small style="color: #64748b; font-size: 0.76rem; display: block; margin-top: 4px;">
                    Attach stamped received copy from MTC or DILG for digital case archive (Max 15MB: PDF, JPG, PNG).
                </small>
                <div id="transmittalCurrentFileBadge" style="display: none; margin-top: 6px; font-size: 0.8rem; color: #0369a1;"></div>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <label for="transmittalRemarks" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                    Remarks / Court Notes <span style="font-weight: 400; color: #64748b;">(Optional)</span>
                </label>
                <textarea id="transmittalRemarks" name="remarks" rows="2" placeholder="e.g. Transmitted via registered mail / personal service; both copies received and stamped." style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; box-sizing: border-box;"></textarea>
            </div>

            <div class="modal-actions" style="margin-top: 10px;">
                <button type="button" class="btn-secondary" onclick="closeRecordTransmittalModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitTransmittal">Save Transmittal Record</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: Transmittal Letter Preview & Print (Fit-to-Document) -->
<!-- ======================================================== -->
<div id="previewTransmittalModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="prevTransTitle" style="display: none; padding: 16px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9999;">
    <div class="modal-content fit-document-modal" style="max-width: 1050px; width: 95vw; height: 90vh; max-height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 45px rgba(0, 0, 0, 0.45); background: #334155; box-sizing: border-box;">
        
        <!-- Sticky Header Toolbar -->
        <div class="modal-header fit-document-toolbar" style="padding: 12px 20px; border-bottom: 1px solid #475569; background: #1e293b; color: #f8fafc; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                <span id="transmittalModalLockBadge" class="status-pill" style="background: #f59e0b; color: #fff; font-weight: 800; font-size: 0.76rem; padding: 3px 10px; border-radius: 4px;">
                    TRANSMITTAL
                </span>
                <div style="min-width: 0;">
                    <h2 id="prevTransTitle" style="font-size: 1.1rem; font-weight: 700; color: #f8fafc; margin: 0; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        Court &amp; DILG Transmittal Letter
                    </h2>
                    <p style="margin: 2px 0 0; font-size: 0.76rem; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        Under RA 7160 Sec. 411 / Sec. 416 &bull; MTC Marikina &amp; DILG Field Office
                    </p>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                <!-- Zoom Controls -->
                <div class="zoom-toolbar" style="display: inline-flex; align-items: center; background: #334155; border: 1px solid #475569; border-radius: 6px; padding: 2px;">
                    <button type="button" onclick="zoomDocModal('fit-page', 'transmittalLetterPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.74rem; font-weight: 600; padding: 4px 8px; cursor: pointer;" title="Fit Entire Page">
                        Fit Page
                    </button>
                    <button type="button" onclick="zoomDocModal('fit-width', 'transmittalLetterPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.74rem; font-weight: 600; padding: 4px 8px; cursor: pointer;" title="Fit Width">
                        Fit Width
                    </button>
                    <button type="button" onclick="zoomDocModal('100%', 'transmittalLetterPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.74rem; font-weight: 600; padding: 4px 8px; cursor: pointer;" title="100% Size">
                        100%
                    </button>
                    <button type="button" onclick="zoomDocModal('-', 'transmittalLetterPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.85rem; font-weight: 700; padding: 4px 8px; cursor: pointer;">
                        &minus;
                    </button>
                    <button type="button" onclick="zoomDocModal('+', 'transmittalLetterPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.85rem; font-weight: 700; padding: 4px 8px; cursor: pointer;">
                        +
                    </button>
                </div>

                <button type="button" id="btnModalPrintTransmittal" class="btn-create" onclick="printTransmittalIframe()" style="font-size: 0.82rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 5px;">
                    🖨️ Print
                </button>
                <a id="btnDownloadTransmittalPdf" href="#" class="btn-secondary" style="font-size: 0.82rem; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; background: #334155; color: #f8fafc; border-color: #475569;">
                    📥 PDF
                </a>
                <button type="button" class="close-btn" onclick="closePreviewTransmittalModal()" style="font-size: 26px; line-height: 1; border: none; background: transparent; cursor: pointer; color: #94a3b8; padding: 0 4px; margin-left: 4px;">&times;</button>
            </div>
        </div>

        <!-- Document Canvas Container -->
        <div class="fit-document-canvas" style="flex: 1 1 0; min-height: 0; background: #334155; padding: 24px; overflow-y: auto; overflow-x: hidden; display: flex; justify-content: center; box-sizing: border-box;">
            <div class="document-paper-container" style="width: 8.5in; max-width: 850px; min-height: 14in; background: #ffffff; border-radius: 4px; box-shadow: 0 12px 36px rgba(0, 0, 0, 0.45); overflow: hidden; display: flex; flex-direction: column;">
                <iframe id="transmittalLetterPreviewIframe" src="about:blank" style="width: 100%; height: 100%; min-height: 1100px; flex: 1 1 auto; border: none; background: #ffffff;" title="Transmittal Letter Preview"></iframe>
            </div>
        </div>

        <!-- Footer Info -->
        <div class="modal-actions" style="padding: 10px 20px; border-top: 1px solid #475569; background: #1e293b; margin-top: 0; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; color: #94a3b8;">
            <span id="transmittalModalFooterCountdown">10-working-day statutory print lock enforced under RA 7160 Section 416</span>
            <button type="button" class="btn-secondary" onclick="closePreviewTransmittalModal()" style="font-size: 0.8rem; padding: 5px 14px; background: #334155; color: #f8fafc; border-color: #475569;">
                Close Preview
            </button>
        </div>

    </div>
</div>
