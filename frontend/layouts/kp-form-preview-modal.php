<?php
/**
 * Official KP Forms Preview & Multi-Panel Pangkat Notices Modal
 * Includes:
 * 1. Tri-Panel Pangkat Notices Modal (KP Forms 10, 11, and 12) with Officer's Return link
 * 2. Fit-to-Document Single KP Form Preview Modal (Legal 8.5" x 14", Zoom-to-fit, Arial typography)
 */
?>

<!-- ======================================================================= -->
<!-- 1. TRI-PANEL PANGKAT NOTICES MODAL (KP Form 10, 11, and 12)             -->
<!-- ======================================================================= -->
<div id="pangkatNoticesModal" class="modal" style="display: none; padding: 12px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9999;">
    <div class="modal-content pangkat-modal-content" style="max-width: 1400px; width: 95vw; height: calc(100vh - 24px); max-height: calc(100vh - 24px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 30px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.08); background: #fff; box-sizing: border-box;">
        
        <!-- Modal Header -->
        <div class="modal-header" style="padding: 12px 20px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; background: #fff; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 id="pangkatModalTitle" style="font-size: 1.2rem; font-weight: 700; color: #0f172a; margin: 0; line-height: 1.2;">
                    Pangkat ng Tagapagkasundo Official Notices &amp; Summons
                </h2>
                <p id="pangkatModalSubtitle" style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b; line-height: 1.3;">
                    KP Form 10 (Notice of Constitution), KP Form 11 (Notice to Chosen Members), and KP Form 12 (Notice of Hearing - Conciliation)
                </p>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn-secondary" onclick="printAllPangkatNotices()" style="font-size: 0.82rem; padding: 6px 12px; display: inline-flex; align-items: center; gap: 5px;" title="Print all 3 Pangkat notices in sequence">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Print All 3 Forms
                </button>
                <button type="button" class="close-btn" onclick="closeTriPanelPangkatModal()" title="Close Preview" style="font-size: 26px; line-height: 1; border: none; background: transparent; cursor: pointer; color: #64748b; padding: 0 4px;">&times;</button>
            </div>
        </div>

        <!-- Success Context Notice -->
        <div id="pangkatSuccessBanner" style="padding: 8px 20px; background: #f5f3ff; border-bottom: 1px solid #ddd6fe; color: #5b21b6; font-size: 0.82rem; display: flex; align-items: center; gap: 8px; flex: 0 0 auto;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <span><strong>Case elevated to Pangkat Conciliation.</strong> Review the 3 statutory notices below (KP 10 for parties, KP 11 for chosen Lupon members, KP 12 for hearing summons with Officer’s Return).</span>
        </div>

        <!-- Tri-Panel Preview Body -->
        <div class="pangkat-preview-body" style="flex: 1 1 0; min-height: 0; background: #f1f5f9; padding: 10px 14px; overflow: hidden; display: flex; flex-direction: column; box-sizing: border-box;">
            <div id="pangkatPreviewGrid" class="pangkat-tri-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; width: 100%; height: 100%; min-height: 0; flex: 1 1 0; gap: 10px; box-sizing: border-box;">
                
                <!-- Panel 1: KP Form 10 -->
                <div class="preview-document-column" style="display: flex; flex-direction: column; height: 100%; min-height: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; flex: 0 0 auto;">
                        <div style="min-width: 0; overflow: hidden;">
                            <div style="font-weight: 700; font-size: 0.84rem; color: #0f172a; display: flex; align-items: center; gap: 6px; white-space: nowrap;">
                                <span>KP Form 10</span>
                                <span style="font-size: 0.68rem; font-weight: 700; background: #e0e7ff; color: #3730a3; padding: 1px 6px; border-radius: 4px;">Pagtatag ng Pangkat</span>
                            </div>
                            <div style="font-size: 0.72rem; color: #64748b; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                Notice to Parties (Complainant &amp; Respondent)
                            </div>
                        </div>
                        <div style="display: flex; gap: 5px; align-items: center; flex-shrink: 0;">
                            <button type="button" class="btn-secondary" onclick="printPangkatFrame('kp10')" style="font-size: 0.74rem; padding: 4px 8px; display: inline-flex; align-items: center; gap: 3px;" title="Print KP Form 10">
                                🖨️ Print
                            </button>
                            <a href="#" id="btnDownloadPangkatKp10" class="btn-secondary" style="font-size: 0.74rem; padding: 4px 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;" title="Download KP Form 10 PDF" download>
                                PDF
                            </a>
                        </div>
                    </div>
                    <iframe id="pangkatPreviewFrameKp10" title="KP Form 10 Preview" style="width: 100%; height: 100%; min-height: 0; flex: 1 1 0; border: none; background: #fff; display: block;"></iframe>
                </div>

                <!-- Panel 2: KP Form 11 -->
                <div class="preview-document-column" style="display: flex; flex-direction: column; height: 100%; min-height: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; flex: 0 0 auto;">
                        <div style="min-width: 0; overflow: hidden;">
                            <div style="font-weight: 700; font-size: 0.84rem; color: #0f172a; display: flex; align-items: center; gap: 6px; white-space: nowrap;">
                                <span>KP Form 11</span>
                                <span style="font-size: 0.68rem; font-weight: 700; background: #f3e8ff; color: #6b21a8; padding: 1px 6px; border-radius: 4px;">Patalastas sa Miyembro</span>
                            </div>
                            <div style="font-size: 0.72rem; color: #64748b; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                Appointment Notice for 3 Chosen Members
                            </div>
                        </div>
                        <div style="display: flex; gap: 5px; align-items: center; flex-shrink: 0;">
                            <button type="button" class="btn-secondary" onclick="printPangkatFrame('kp11')" style="font-size: 0.74rem; padding: 4px 8px; display: inline-flex; align-items: center; gap: 3px;" title="Print KP Form 11">
                                🖨️ Print
                            </button>
                            <a href="#" id="btnDownloadPangkatKp11" class="btn-secondary" style="font-size: 0.74rem; padding: 4px 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;" title="Download KP Form 11 PDF" download>
                                PDF
                            </a>
                        </div>
                    </div>
                    <iframe id="pangkatPreviewFrameKp11" title="KP Form 11 Preview" style="width: 100%; height: 100%; min-height: 0; flex: 1 1 0; border: none; background: #fff; display: block;"></iframe>
                </div>

                <!-- Panel 3: KP Form 12 -->
                <div class="preview-document-column" style="display: flex; flex-direction: column; height: 100%; min-height: 0; background: #fff; border: 1.5px solid #6366f1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #eef2ff; border-bottom: 1px solid #c7d2fe; flex: 0 0 auto;">
                        <div style="min-width: 0; overflow: hidden;">
                            <div style="font-weight: 700; font-size: 0.84rem; color: #1e1b4b; display: flex; align-items: center; gap: 6px; white-space: nowrap;">
                                <span>KP Form 12</span>
                                <span style="font-size: 0.68rem; font-weight: 700; background: #4f46e5; color: #fff; padding: 1px 6px; border-radius: 4px;">Patawag ng Pangkat</span>
                            </div>
                            <div style="font-size: 0.72rem; color: #4338ca; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                Conciliation Hearing Notice &bull; Officer's Return
                            </div>
                        </div>
                        <div style="display: flex; gap: 5px; align-items: center; flex-shrink: 0;">
                            <button type="button" class="btn-secondary" onclick="printPangkatFrame('kp12')" style="font-size: 0.74rem; padding: 4px 8px; display: inline-flex; align-items: center; gap: 3px;" title="Print KP Form 12">
                                🖨️ Print
                            </button>
                            <a href="#" id="btnDownloadPangkatKp12" class="btn-secondary" style="font-size: 0.74rem; padding: 4px 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;" title="Download KP Form 12 PDF" download>
                                PDF
                            </a>
                        </div>
                    </div>
                    <iframe id="pangkatPreviewFrameKp12" title="KP Form 12 Preview" style="width: 100%; height: 100%; min-height: 0; flex: 1 1 0; border: none; background: #fff; display: block;"></iframe>
                </div>

            </div>
        </div>

        <!-- Action Footer -->
        <div class="modal-actions" style="padding: 10px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <button type="button" class="btn-secondary" onclick="closeTriPanelPangkatModal()" style="font-size: 0.84rem; padding: 7px 14px;">
                Close
            </button>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <a href="#" id="btnPangkatOfficersReturnComp" class="btn-secondary" style="font-size: 0.84rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border-color: #cbd5e1; color: #0369a1;" title="Record Proof of Service for Complainant">
                    📋 Complainant Return
                </a>
                <a href="#" id="btnPangkatOfficersReturnResp" class="btn-secondary" style="font-size: 0.84rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border-color: #cbd5e1; color: #b45309;" title="Record Proof of Service for Respondent">
                    📋 Respondent Return
                </a>
                <a href="#" id="btnPangkatOfficerReturnFull" class="btn-create" style="font-size: 0.84rem; padding: 7px 16px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; background: #2563eb;" title="Open Officer's Return Tracking for KP Form 12">
                    Record Return / Proof of Service (KP Form 12) &rarr;
                </a>
            </div>
        </div>

    </div>
</div>

<!-- ======================================================================= -->
<!-- 2. UNIFIED 2-PANEL SPLIT-SCREEN LIVE KP DOCUMENT EDITOR & PREVIEW MODAL -->
<!--    (50% Left Panel: Live Form Inputs | 50% Right Panel: A4/Legal Sheet) -->
<!-- ======================================================================= -->
<div id="kpDocumentPreviewModal" class="modal" style="display: none; padding: 14px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9999;" role="dialog" aria-modal="true" aria-labelledby="kpDocModalHeading">
    <div class="modal-content fit-document-modal" style="max-width: 1480px; width: 96vw; height: 93vh; max-height: 93vh; display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 45px rgba(0, 0, 0, 0.45); background: #1e293b; box-sizing: border-box;">
        
        <!-- Sticky Header Toolbar -->
        <div class="modal-header fit-document-toolbar" style="padding: 12px 20px; border-bottom: 1px solid #334155; background: #0f172a; color: #f8fafc; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                <span id="kpDocModalBadge" class="status-pill" style="background: #2563eb; color: #fff; font-weight: 800; font-size: 0.75rem; padding: 4px 10px; border-radius: 5px; letter-spacing: 0.03em;">
                    KP FORM
                </span>
                <span style="display: inline-flex; align-items: center; gap: 5px; background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.4); color: #4ade80; font-size: 0.68rem; font-weight: 800; padding: 3px 8px; border-radius: 999px;">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: #22c55e;"></span>
                    LIVE 50/50 SPLIT EDITOR
                </span>
                <div style="min-width: 0;">
                    <h2 id="kpDocModalHeading" style="font-size: 1.05rem; font-weight: 700; color: #f8fafc; margin: 0; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        Official KP Form Live Editor &amp; Preview
                    </h2>
                    <p id="kpDocModalSub" style="margin: 2px 0 0; font-size: 0.75rem; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        Barangay Tumana Lupong Tagapamayapa &bull; Side-by-Side 50% Form Inputs + 50% Live A4/Legal Paper Preview
                    </p>
                </div>
            </div>

            <!-- Toolbar Controls: Split View Toggle, Zoom & Print/PDF Actions -->
            <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap;">
                <button type="button" id="btnToggleKpSplitMode" onclick="toggleKpDocSplitEditor()" style="background: #1e293b; border: 1px solid #475569; color: #e2e8f0; font-size: 0.76rem; font-weight: 700; padding: 6px 11px; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;" title="Toggle 50/50 Split-Screen Live Editor">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="12" y1="3" x2="12" y2="21"></line></svg>
                    <span id="kpSplitModeLabel">50/50 Split Active</span>
                </button>

                <!-- Zoom Controls -->
                <div class="zoom-toolbar" style="display: inline-flex; align-items: center; background: #1e293b; border: 1px solid #475569; border-radius: 6px; padding: 2px;">
                    <button type="button" onclick="zoomDocModal('fit-page', 'kpDocPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.73rem; font-weight: 600; padding: 4px 7px; cursor: pointer;" title="Fit Entire Page in View">
                        Fit Page
                    </button>
                    <button type="button" onclick="zoomDocModal('fit-width', 'kpDocPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.73rem; font-weight: 600; padding: 4px 7px; cursor: pointer;" title="Fit Width">
                        Fit Width
                    </button>
                    <button type="button" onclick="zoomDocModal('100%', 'kpDocPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.73rem; font-weight: 600; padding: 4px 7px; cursor: pointer;" title="Actual 100% Size">
                        100%
                    </button>
                    <button type="button" onclick="zoomDocModal('-', 'kpDocPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.85rem; font-weight: 700; padding: 4px 7px; cursor: pointer;" title="Zoom Out">
                        &minus;
                    </button>
                    <button type="button" onclick="zoomDocModal('+', 'kpDocPreviewIframe')" style="background: transparent; border: none; color: #cbd5e1; font-size: 0.85rem; font-weight: 700; padding: 4px 7px; cursor: pointer;" title="Zoom In">
                        +
                    </button>
                </div>

                <!-- Print & PDF Action Buttons -->
                <button type="button" class="btn-create" onclick="printKpDocIframe()" style="font-size: 0.8rem; padding: 6px 13px; display: inline-flex; align-items: center; gap: 5px; background: #2563eb; border-color: #2563eb;">
                    Print Form
                </button>
                <a href="#" id="btnDownloadKpDocPdf" class="btn-secondary" style="font-size: 0.8rem; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; background: #1e293b; color: #f8fafc; border-color: #475569;" download>
                    Download PDF
                </a>
                <!-- Proceed to Officer's Log / Proof of Service -->
                <a href="../gps/proof-service.php" id="btnHeaderProceedOfficerLog" class="btn-create" style="display: none; font-size: 0.8rem; padding: 6px 13px; background: #059669; border-color: #059669; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; align-items: center; gap: 5px;" title="Proceed to Officer's Return / Proof of Service to log delivery attempt">
                    Proceed to Officer's Log &rarr;
                </a>
                <button type="button" class="close-btn" onclick="closeKpDocumentPreviewModal()" title="Close Preview" style="font-size: 26px; line-height: 1; border: none; background: transparent; cursor: pointer; color: #94a3b8; padding: 0 4px; margin-left: 4px;">&times;</button>
            </div>
        </div>

        <!-- 2-PANEL 50/50 SPLIT WORKSPACE -->
        <div id="kpDocSplitWorkspace" class="kp-doc-split-workspace">
            <!-- LEFT 50% PANEL: Live Document Form Inputs -->
            <div id="kpDocLiveEditorPanel" class="kp-doc-live-editor-panel">
                <div class="kp-live-editor-banner">
                    <div>
                        <strong>2-Panel Split-Screen Live Document Editor</strong>
                        <span>Edit form inputs on the left (50%) and watch the official KP paper sheet update in real time on the right (50%).</span>
                    </div>
                    <div style="display: flex; gap: 6px; flex-shrink: 0;">
                        <button type="button" class="kp-live-btn-sm" onclick="applyKpLiveFullServerRefresh()" title="Re-render full form HTML with current overrides">Sync Layout</button>
                        <button type="button" class="kp-live-btn-sm" onclick="resetKpLiveEditorToOriginal()" title="Restore original case database values">Reset</button>
                    </div>
                </div>

                <div class="kp-live-editor-scroll">
                    <!-- Paper Format & Direct Edit Controls -->
                    <div class="kp-live-section-box">
                        <div class="kp-live-section-title">Paper Sheet &amp; Inline Editing</div>
                        <div class="kp-live-grid-3">
                            <div>
                                <label class="kp-live-label">Paper Format</label>
                                <select id="kpLivePaperSize" class="kp-live-input" onchange="syncKpPaperFormat()">
                                    <option value="Legal">Legal (8.5" &times; 14")</option>
                                    <option value="A4">A4 (210mm &times; 297mm)</option>
                                </select>
                            </div>
                            <div>
                                <label class="kp-live-label">Margins Top/Bottom (mm)</label>
                                <input type="number" id="kpLiveMarginTB" class="kp-live-input" value="14" min="5" max="35" oninput="syncKpPaperFormat()">
                            </div>
                            <div>
                                <label class="kp-live-label">Margins Left/Right (mm)</label>
                                <input type="number" id="kpLiveMarginLR" class="kp-live-input" value="16" min="5" max="35" oninput="syncKpPaperFormat()">
                            </div>
                        </div>
                        <label class="kp-direct-edit-bar">
                            <input type="checkbox" id="chkKpDirectPaperEdit" onchange="toggleKpDirectPaperEdit(this.checked)">
                            <span><strong>Enable Direct Paper Editing (Inline ContentEditable)</strong> &mdash; Click any paragraph or line directly on the right-hand paper sheet to type or refine wording.</span>
                        </label>
                    </div>

                    <!-- Case & Party Metadata -->
                    <div class="kp-live-section-box">
                        <div class="kp-live-section-title">Case &amp; Party Metadata (Real-Time DOM Sync)</div>
                        <div class="kp-live-grid-2">
                            <div>
                                <label class="kp-live-label">Barangay Case No. (Usaping Brgy. Blg.)</label>
                                <input type="text" id="kpLiveInput_case_number" data-kp-live="case_number" class="kp-live-input" placeholder="Case number">
                            </div>
                            <div>
                                <label class="kp-live-label">Subject / For (Ukol sa)</label>
                                <input type="text" id="kpLiveInput_complaint_title" data-kp-live="complaint_title" class="kp-live-input" placeholder="Nature of dispute">
                            </div>
                        </div>
                        <div class="kp-live-grid-2" style="margin-top: 10px;">
                            <div>
                                <label class="kp-live-label">Complainant(s) (Mga May-Sumbong)</label>
                                <input type="text" id="kpLiveInput_complainants_text" data-kp-live="complainants_text" class="kp-live-input" placeholder="Complainant name(s)">
                            </div>
                            <div>
                                <label class="kp-live-label">Respondent(s) (Mga Ipinagsusumbong)</label>
                                <input type="text" id="kpLiveInput_respondents_text" data-kp-live="respondents_text" class="kp-live-input" placeholder="Respondent name(s)">
                            </div>
                        </div>
                    </div>

                    <!-- Primary Statement / Settlement Terms / Grounds -->
                    <div class="kp-live-section-box">
                        <div class="kp-live-section-title">Document Body, Narrative &amp; Statutory Terms</div>
                        <div>
                            <label class="kp-live-label" id="kpLivePrimaryLabel">Primary Statement / Narrative / Agreement Terms</label>
                            <textarea id="kpLiveInput_primary_body_text" data-kp-live="primary_body_text" class="kp-live-textarea" rows="4" placeholder="Type here to update the document body in real time..."></textarea>
                        </div>
                        <div style="margin-top: 10px;">
                            <label class="kp-live-label" id="kpLiveSecondaryLabel">Relief Sought / Secondary Details</label>
                            <textarea id="kpLiveInput_secondary_body_text" data-kp-live="secondary_body_text" class="kp-live-textarea" rows="2" placeholder="Additional conditions, relief sought, or party details..."></textarea>
                        </div>
                        <!-- Optional DB Save Button for KP Form 16 Settlement Terms -->
                        <div id="kpLiveSaveDbBar" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px solid #e2e8f0; justify-content: space-between; align-items: center; gap: 10px;">
                            <span style="font-size: 0.75rem; color: #475569;">Persist edited terms directly to the Case Record database:</span>
                            <button type="button" id="btnKpLiveSaveToDb" onclick="saveKpLiveTermsToDatabase()" class="btn-create" style="font-size: 0.78rem; padding: 6px 14px; background: #0f766e; border-color: #0f766e;">
                                Save Terms to Case Record
                            </button>
                        </div>
                    </div>

                    <!-- Schedule, Venue & Signatories -->
                    <div class="kp-live-section-box">
                        <div class="kp-live-section-title">Schedule, Venue &amp; Authorized Signatories</div>
                        <div class="kp-live-grid-2">
                            <div>
                                <label class="kp-live-label">Hearing / Appearance Schedule</label>
                                <input type="datetime-local" id="kpLiveInput_hearing_date" data-kp-live="hearing_date" class="kp-live-input">
                            </div>
                            <div>
                                <label class="kp-live-label">Venue / Office</label>
                                <input type="text" id="kpLiveInput_venue" data-kp-live="venue" class="kp-live-input" placeholder="Tanggapan ng Lupong Tagapamayapa">
                            </div>
                        </div>
                        <div class="kp-live-grid-3" style="margin-top: 10px;">
                            <div>
                                <label class="kp-live-label">Issuance Date</label>
                                <input type="date" id="kpLiveInput_notice_date" data-kp-live="notice_date" class="kp-live-input">
                            </div>
                            <div>
                                <label class="kp-live-label">Presiding Official / Signatory</label>
                                <input type="text" id="kpLiveInput_official_name" data-kp-live="official_name" class="kp-live-input" placeholder="HON. AKIKO LYNNE CENTENO">
                            </div>
                            <div>
                                <label class="kp-live-label">Lupon / Pangkat Secretary</label>
                                <input type="text" id="kpLiveInput_secretary_name" data-kp-live="secretary_name" class="kp-live-input" placeholder="MARILOU P. CRUZ">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT 50% PANEL: Live A4/Legal Paper Preview -->
            <div id="kpDocPreviewCanvasPanel" class="fit-document-canvas" style="flex: 1 1 0; min-height: 0; background: #334155; padding: 20px; overflow-y: auto; overflow-x: hidden; display: flex; justify-content: center; box-sizing: border-box;">
                <div id="kpPaperSheetContainer" class="document-paper-container" style="width: 100%; max-width: 820px; min-height: 1080px; background: #ffffff; border-radius: 4px; box-shadow: 0 12px 36px rgba(0, 0, 0, 0.45); overflow: hidden; display: flex; flex-direction: column;">
                    <iframe id="kpDocPreviewIframe" title="Official KP Document Preview" style="width: 100%; height: 100%; min-height: 1080px; flex: 1 1 auto; border: none; background: #ffffff;"></iframe>
                </div>
            </div>
        </div>

        <!-- Footer Info -->
        <div class="modal-actions" style="padding: 10px 20px; border-top: 1px solid #334155; background: #0f172a; margin-top: 0; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; color: #94a3b8;">
            <span id="kpDocFooterSpecLabel">2-Panel Split-Screen Live Editor (50% Inputs + 50% Legal/A4 Paper Preview) &bull; Real-Time DOM Sync</span>
            <div style="display: flex; gap: 8px; align-items: center;">
                <a href="../gps/proof-service.php" id="btnKpDocProceedOfficerLog" class="btn-create" style="display: none; font-size: 0.8rem; padding: 6px 14px; background: #0284c7; border-color: #0284c7; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; align-items: center; gap: 5px;">
                    Proceed to Officer's Log / Proof of Service &rarr;
                </a>
                <button type="button" class="btn-secondary" onclick="closeKpDocumentPreviewModal()" style="font-size: 0.8rem; padding: 5px 14px; background: #334155; color: #f8fafc; border-color: #475569;">
                    Close Editor
                </button>
            </div>
        </div>

    </div>
</div>

<script>
let currentModalDocZoom = 1.0;
let currentKpLiveMeta = null;
let currentKpOriginalBaseUrl = '';

function toggleKpDocSplitEditor(forceState) {
    const ws = document.getElementById('kpDocSplitWorkspace');
    const lbl = document.getElementById('kpSplitModeLabel');
    if (!ws) return;
    const hideEditor = (typeof forceState === 'boolean') ? !forceState : !ws.classList.contains('kp-preview-only');
    ws.classList.toggle('kp-preview-only', hideEditor);
    if (lbl) {
        lbl.textContent = hideEditor ? 'Open 50/50 Live Editor' : '50/50 Split Active';
    }
}
window.toggleKpDocSplitEditor = toggleKpDocSplitEditor;

function hydrateKpLiveEditorFromIframe() {
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (!iframe) return;
    try {
        const doc = iframe.contentDocument || iframe.contentWindow?.document;
        if (!doc) return;

        const srcUrl = iframe.getAttribute('src') || '';
        if (srcUrl && srcUrl !== 'about:blank' && !srcUrl.includes('case_number=')) {
            currentKpOriginalBaseUrl = srcUrl;
        }

        const metaScript = doc.getElementById('kpFormLiveMeta');
        if (metaScript) {
            currentKpLiveMeta = JSON.parse(metaScript.textContent || '{}');
            const f = currentKpLiveMeta.fields || {};
            const labels = currentKpLiveMeta.labels || {};

            const primLbl = document.getElementById('kpLivePrimaryLabel');
            if (primLbl && labels.primary_body_label) primLbl.textContent = labels.primary_body_label;
            const secLbl = document.getElementById('kpLiveSecondaryLabel');
            if (secLbl && labels.secondary_body_label) secLbl.textContent = labels.secondary_body_label;

            document.querySelectorAll('[data-kp-live]').forEach(el => {
                const k = el.getAttribute('data-kp-live');
                if (k && f[k] !== undefined) {
                    el.value = f[k];
                }
            });

            // Show "Save Terms to Case Record" bar if KP Form 16
            const saveBar = document.getElementById('kpLiveSaveDbBar');
            if (saveBar) {
                const fc = String(currentKpLiveMeta.form_code || '');
                saveBar.style.display = fc.includes('16') ? 'flex' : 'none';
            }
        }

        // Attach reverse two-way sync when user types directly on the paper sheet
        doc.querySelectorAll('.live-doc-field').forEach(span => {
            span.addEventListener('input', () => {
                const key = span.getAttribute('data-live-field');
                if (!key) return;
                const input = document.querySelector(`[data-kp-live="${key}"]`);
                if (input && document.activeElement !== input) {
                    input.value = span.innerText;
                    updateKpLivePdfDownloadLink();
                }
            });
        });

        const chkDirect = document.getElementById('chkKpDirectPaperEdit');
        if (chkDirect && chkDirect.checked) {
            toggleKpDirectPaperEdit(true);
        }
        syncKpPaperFormat();
        updateKpLivePdfDownloadLink();
    } catch (e) {
        console.warn('Could not hydrate KP live editor from iframe:', e);
    }
}

function syncKpFieldToIframe(fieldKey, value) {
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (!iframe) return;
    try {
        const doc = iframe.contentDocument || iframe.contentWindow?.document;
        if (!doc) return;

        const nodes = doc.querySelectorAll(`[data-live-field="${fieldKey}"]`);
        if (nodes.length > 0) {
            nodes.forEach(node => {
                const div = document.createElement('div');
                div.textContent = value ?? '';
                node.innerHTML = div.innerHTML.replace(/\n/g, '<br>');
                node.classList.remove('live-field-flash');
                void node.offsetWidth;
                node.classList.add('live-field-flash');
                clearTimeout(node._flashTimer);
                node._flashTimer = setTimeout(() => node.classList.remove('live-field-flash'), 550);
            });
        } else if (fieldKey === 'hearing_date' || fieldKey === 'notice_date') {
            // Dates are formatted into Filipino day/month/year across the statutory template, trigger debounced refresh
            clearTimeout(window._kpDateSyncTimer);
            window._kpDateSyncTimer = setTimeout(() => applyKpLiveFullServerRefresh(), 450);
        }
        updateKpLivePdfDownloadLink();
    } catch (e) {
        console.warn('Live KP field sync error:', e);
    }
}

function syncKpPaperFormat() {
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (!iframe) return;
    try {
        const doc = iframe.contentDocument || iframe.contentWindow?.document;
        if (!doc) return;
        const size = document.getElementById('kpLivePaperSize')?.value || 'Legal';
        const tb = parseInt(document.getElementById('kpLiveMarginTB')?.value || '14', 10);
        const lr = parseInt(document.getElementById('kpLiveMarginLR')?.value || '16', 10);

        const sheet = doc.querySelector('.preview-sheet') || doc.querySelector('.page-sheet') || doc.querySelector('.printable-document-sheet');
        if (sheet) {
            sheet.style.maxWidth = (size === 'A4') ? '210mm' : '215.9mm';
            sheet.style.minHeight = (size === 'A4') ? '297mm' : '355.6mm';
            sheet.style.padding = `${tb}mm ${lr}mm`;
        }
        const container = document.getElementById('kpPaperSheetContainer');
        if (container) {
            container.style.maxWidth = (size === 'A4') ? '794px' : '820px';
        }
    } catch (e) {
        console.warn('Paper format sync error:', e);
    }
}
window.syncKpPaperFormat = syncKpPaperFormat;

function toggleKpDirectPaperEdit(enabled) {
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (!iframe) return;
    try {
        const doc = iframe.contentDocument || iframe.contentWindow?.document;
        if (!doc || !doc.body) return;
        doc.body.classList.toggle('direct-edit-active', !!enabled);
        const sheet = doc.querySelector('.preview-sheet') || doc.querySelector('.page-sheet') || doc.body;
        if (enabled) {
            sheet.setAttribute('contenteditable', 'true');
        } else {
            sheet.removeAttribute('contenteditable');
        }
    } catch (e) {
        console.warn('Direct edit toggle error:', e);
    }
}
window.toggleKpDirectPaperEdit = toggleKpDirectPaperEdit;

function buildKpLiveQueryParams() {
    const params = new URLSearchParams();
    document.querySelectorAll('[data-kp-live]').forEach(el => {
        const k = el.getAttribute('data-kp-live');
        if (k && el.value.trim() !== '') {
            params.set(k, el.value.trim());
        }
    });
    return params;
}

function updateKpLivePdfDownloadLink() {
    const pdfBtn = document.getElementById('btnDownloadKpDocPdf');
    if (!pdfBtn || !currentKpLiveMeta) return;
    const params = buildKpLiveQueryParams();
    params.set('case_id', currentKpLiveMeta.case_id || '');
    if (currentKpLiveMeta.hearing_id) params.set('hearing_id', currentKpLiveMeta.hearing_id);
    params.set('form_code', currentKpLiveMeta.form_code || 'KP Form 7');
    params.set('pdf', '1');
    pdfBtn.href = `../../../backend/api/documents/kp-form.php?${params.toString()}`;
}

function applyKpLiveFullServerRefresh() {
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (!iframe || !currentKpLiveMeta) return;
    const params = buildKpLiveQueryParams();
    params.set('case_id', currentKpLiveMeta.case_id || '');
    if (currentKpLiveMeta.hearing_id) params.set('hearing_id', currentKpLiveMeta.hearing_id);
    params.set('form_code', currentKpLiveMeta.form_code || 'KP Form 7');
    params.set('preview', '1');
    params.set('bare', '1');
    iframe.src = `../../../backend/api/documents/kp-form.php?${params.toString()}`;
}
window.applyKpLiveFullServerRefresh = applyKpLiveFullServerRefresh;

function resetKpLiveEditorToOriginal() {
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (iframe && currentKpOriginalBaseUrl) {
        iframe.src = currentKpOriginalBaseUrl;
    }
}
window.resetKpLiveEditorToOriginal = resetKpLiveEditorToOriginal;

async function saveKpLiveTermsToDatabase() {
    if (!currentKpLiveMeta || !currentKpLiveMeta.case_id) return;
    const btn = document.getElementById('btnKpLiveSaveToDb');
    const terms = document.getElementById('kpLiveInput_primary_body_text')?.value.trim() || '';
    if (!terms) return;

    const origText = btn ? btn.textContent : '';
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Saving...';
    }
    try {
        const fd = new FormData();
        fd.append('action', 'update_settlement_terms');
        fd.append('case_id', String(currentKpLiveMeta.case_id));
        if (currentKpLiveMeta.complaint_id) fd.append('complaint_id', String(currentKpLiveMeta.complaint_id));
        fd.append('agreement_details', terms);

        const res = await fetch('../../../backend/api/settlements/save.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        const data = await res.json();
        if (data.success) {
            if (window.agapNotify) window.agapNotify('Settlement terms saved to case record!', 'success');
            applyKpLiveFullServerRefresh();
        } else {
            alert(data.message || 'Unable to save terms.');
        }
    } catch (err) {
        console.error(err);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = origText;
        }
    }
}
window.saveKpLiveTermsToDatabase = saveKpLiveTermsToDatabase;

document.addEventListener('DOMContentLoaded', () => {
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (iframe) {
        iframe.addEventListener('load', () => {
            hydrateKpLiveEditorFromIframe();
        });
    }
    document.querySelectorAll('[data-kp-live]').forEach(input => {
        input.addEventListener('input', () => {
            const key = input.getAttribute('data-kp-live');
            if (key) syncKpFieldToIframe(key, input.value);
        });
    });
});

function zoomDocModal(action, iframeId) {
    const iframe = document.getElementById(iframeId);
    if (!iframe) return;
    try {
        const doc = iframe.contentDocument || iframe.contentWindow.document;
        if (!doc) return;
        const target = doc.querySelector('.printable-document-sheet') || doc.querySelector('.page-sheet') || doc.querySelector('.preview-sheet') || doc.body;
        if (!target) return;

        if (action === '100%') {
            currentModalDocZoom = 1.0;
        } else if (action === '+') {
            currentModalDocZoom = Math.min(2.0, currentModalDocZoom + 0.1);
        } else if (action === '-') {
            currentModalDocZoom = Math.max(0.4, currentModalDocZoom - 0.1);
        } else if (action === 'fit-width') {
            const containerW = iframe.parentElement ? iframe.parentElement.clientWidth : 800;
            currentModalDocZoom = Math.min(1.2, Math.max(0.6, (containerW - 60) / 820));
        } else if (action === 'fit-page') {
            const containerH = iframe.parentElement ? iframe.parentElement.clientHeight : 900;
            currentModalDocZoom = Math.min(1.0, Math.max(0.5, (containerH - 80) / 1150));
        }

        target.style.transformOrigin = 'top center';
        target.style.transform = `scale(${currentModalDocZoom})`;
        target.style.transition = 'transform 0.18s ease';
    } catch (e) {
        console.warn('Zoom error:', e);
    }
}
window.zoomDocModal = zoomDocModal;

function printKpDocIframe() {
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (!iframe) return;
    try {
        if (iframe.contentWindow) {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }
    } catch (e) {
        console.warn('Print iframe error:', e);
    }
}
window.printKpDocIframe = printKpDocIframe;

function closeKpDocumentPreviewModal() {
    const modal = document.getElementById('kpDocumentPreviewModal');
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (iframe) iframe.src = 'about:blank';
    if (modal) modal.style.display = 'none';
    if (window._kpPreviewProceedUrl) {
        const dest = window._kpPreviewProceedUrl;
        window._kpPreviewProceedUrl = null;
        window.location.href = dest;
    }
}
window.closeKpDocumentPreviewModal = closeKpDocumentPreviewModal;
</script>

<style>
/* 2-Panel 50/50 Split-Screen Live Editor Layout for KP Document Modal */
.kp-doc-split-workspace {
    display: grid;
    grid-template-columns: 50% 50%;
    flex: 1 1 0;
    min-height: 0;
    overflow: hidden;
    background: #f8fafc;
}

.kp-doc-split-workspace.kp-preview-only {
    grid-template-columns: 0px 100%;
}

.kp-doc-split-workspace.kp-preview-only .kp-doc-live-editor-panel {
    display: none;
}

@media (max-width: 1024px) {
    .kp-doc-split-workspace {
        grid-template-columns: 1fr;
        overflow-y: auto;
    }
}

.kp-doc-live-editor-panel {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
    background: #ffffff;
    border-right: 1px solid #cbd5e1;
    color: #0f172a;
}

.kp-live-editor-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 18px;
    background: #eff6ff;
    border-bottom: 1px solid #bfdbfe;
    flex-shrink: 0;
}

.kp-live-editor-banner strong {
    display: block;
    font-size: 0.84rem;
    color: #1e3a8a;
}

.kp-live-editor-banner span {
    display: block;
    font-size: 0.74rem;
    color: #2563eb;
    margin-top: 2px;
}

.kp-live-btn-sm {
    background: #ffffff;
    border: 1px solid #93c5fd;
    color: #1d4ed8;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 5px 10px;
    border-radius: 6px;
    cursor: pointer;
}

.kp-live-btn-sm:hover {
    background: #dbeafe;
}

.kp-live-editor-scroll {
    flex: 1 1 0;
    min-height: 0;
    overflow-y: auto;
    padding: 16px 18px 24px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.kp-live-section-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
}

.kp-live-section-title {
    font-size: 0.76rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #475569;
    margin-bottom: 10px;
}

.kp-live-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.kp-live-grid-3 {
    display: grid;
    grid-template-columns: 1.2fr 1fr 1fr;
    gap: 10px;
}

.kp-live-label {
    display: block;
    font-size: 0.76rem;
    font-weight: 700;
    color: #334155;
    margin-bottom: 4px;
}

.kp-live-input,
.kp-live-textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.84rem;
    color: #0f172a;
    background: #ffffff;
    box-sizing: border-box;
    font-family: inherit;
}

.kp-live-input:focus,
.kp-live-textarea:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.kp-direct-edit-bar {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 10px;
    padding: 8px 10px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.76rem;
    color: #334155;
    cursor: pointer;
}

.kp-direct-edit-bar input {
    margin-top: 2px;
}

@media print {
    @page {
        size: 8.5in 14in;
        margin: 15mm;
    }
    body * {
        visibility: hidden !important;
    }
    .document-paper-container, .document-paper-container * {
        visibility: visible !important;
    }
    .document-paper-container {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .modal-header, .countdown-banner, .btn-action, .no-print, .fit-document-toolbar, .kp-doc-live-editor-panel {
        display: none !important;
    }
}
</style>
