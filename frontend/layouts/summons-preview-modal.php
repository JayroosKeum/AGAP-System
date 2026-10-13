<?php
/**
 * Reusable First Mediation Summons Preview & Print Modal (KP Form No. 8 & KP Form No. 9)
 * Used in Complaint Details and Mediation Workspace.
 */
?>
<div id="summonsPreviewModal" class="modal" style="display: none; padding: 12px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9999;">
    <div class="modal-content summons-modal-content" style="max-width: 1400px; width: 98%; height: calc(100vh - 24px); max-height: calc(100vh - 24px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 30px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.08); background: #fff; box-sizing: border-box;">
        
        <!-- Modal Header (Pinned at Top) -->
        <div class="modal-header" style="padding: 12px 20px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; background: #fff; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 id="summonsPreviewModalTitle" style="font-size: 1.2rem; font-weight: 700; color: #0f172a; margin: 0; line-height: 1.2;">Print summon</h2>
                <p id="summonsPreviewModalSubtitle" style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b; line-height: 1.3;">
                    KP Form No. 8 (Patalastas ng Pagdinig) &amp; KP Form No. 9 (Patawag) · Loading case details…
                </p>
            </div>
            <button type="button" class="close-btn" onclick="closeSummonsPreviewModal()" title="Close Preview" style="font-size: 26px; line-height: 1; border: none; background: transparent; cursor: pointer; color: #64748b; padding: 0 4px;">&times;</button>
        </div>

        <!-- Success Context Notice (Pinned below Header) -->
        <div id="summonsPreviewSuccessBanner" style="padding: 8px 20px; background: #ecfdf5; border-bottom: 1px solid #a7f3d0; color: #065f46; font-size: 0.82rem; display: flex; align-items: center; gap: 8px; flex: 0 0 auto;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <span><strong>First mediation schedule saved successfully.</strong> Review KP Form No. 8 and KP Form No. 9 summons below before printing or continuing to service.</span>
        </div>

        <!-- Document Preview Container (Flexible Middle, Fits Viewport) -->
        <div class="summons-preview-body" style="flex: 1 1 0; min-height: 0; background: #f1f5f9; padding: 10px 14px; overflow: hidden; display: flex; flex-direction: column; box-sizing: border-box;">
            
            <!-- Loading State -->
            <div id="summonsPreviewLoading" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; color: #64748b; margin: auto;">
                <div class="loading-spinner" style="width: 32px; height: 32px; border: 3px solid #cbd5e1; border-top-color: #2563eb; border-radius: 50%; animation: agapSpin 0.8s linear infinite; margin-bottom: 12px;"></div>
                <span style="font-size: 0.88rem; font-weight: 500;">Rendering official Barangay Tumana KP Form No. 8 and KP Form No. 9…</span>
            </div>

            <!-- Side-by-side Dual Document Grid -->
            <div id="summonsPreviewGrid" class="summons-dual-grid" style="display: none; width: 100%; height: 100%; min-height: 0; flex: 1 1 0; gap: 12px; box-sizing: border-box;">
                
                <!-- Column 1: KP Form No. 8 (Complainant) -->
                <div class="preview-document-column" style="display: flex; flex-direction: column; height: 100%; min-height: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; flex: 0 0 auto;">
                        <div style="min-width: 0; overflow: hidden;">
                            <div style="font-weight: 700; font-size: 0.85rem; color: #0f172a; display: flex; align-items: center; gap: 6px; white-space: nowrap;">
                                <span>📄 KP Form No. 8</span>
                                <span style="font-size: 0.68rem; font-weight: 700; background: #dbeafe; color: #1e40af; padding: 1px 6px; border-radius: 4px;">Patalastas ng Pagdinig</span>
                            </div>
                            <div id="kp8PartyHint" style="font-size: 0.72rem; color: #64748b; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                Para sa May-sumbong (Complainant)
                            </div>
                        </div>
                        <div style="display: flex; gap: 6px; align-items: center; flex-shrink: 0;">
                            <button type="button" class="btn-secondary" onclick="printSummonsPreview('kp8')" style="font-size: 0.75rem; padding: 4px 9px; display: inline-flex; align-items: center; gap: 4px;" title="Print KP Form No. 8">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                Print KP 8
                            </button>
                            <a href="#" id="btnDownloadKp8" class="btn-secondary" style="font-size: 0.75rem; padding: 4px 9px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;" title="Download KP Form No. 8 PDF">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                PDF
                            </a>
                        </div>
                    </div>
                    <iframe id="summonsPreviewFrameKp8" title="KP Form No. 8 Preview" style="width: 100%; height: 100%; min-height: 0; flex: 1 1 0; border: none; background: #fff; display: block;"></iframe>
                </div>

                <!-- Column 2: KP Form No. 9 (Respondent) -->
                <div class="preview-document-column" style="display: flex; flex-direction: column; height: 100%; min-height: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; flex: 0 0 auto;">
                        <div style="min-width: 0; overflow: hidden;">
                            <div style="font-weight: 700; font-size: 0.85rem; color: #0f172a; display: flex; align-items: center; gap: 6px; white-space: nowrap;">
                                <span>📄 KP Form No. 9</span>
                                <span style="font-size: 0.68rem; font-weight: 700; background: #fef3c7; color: #92400e; padding: 1px 6px; border-radius: 4px;">Patawag</span>
                            </div>
                            <div id="kp9PartyHint" style="font-size: 0.72rem; color: #64748b; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                Para sa Ipinagsusumbong (Respondent)
                            </div>
                        </div>
                        <div style="display: flex; gap: 6px; align-items: center; flex-shrink: 0;">
                            <button type="button" class="btn-secondary" onclick="printSummonsPreview('kp9')" style="font-size: 0.75rem; padding: 4px 9px; display: inline-flex; align-items: center; gap: 4px;" title="Print KP Form No. 9">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                Print KP 9
                            </button>
                            <a href="#" id="btnDownloadKp9" class="btn-secondary" style="font-size: 0.75rem; padding: 4px 9px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;" title="Download KP Form No. 9 PDF">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                PDF
                            </a>
                        </div>
                    </div>
                    <iframe id="summonsPreviewFrameKp9" title="KP Form No. 9 Preview" style="width: 100%; height: 100%; min-height: 0; flex: 1 1 0; border: none; background: #fff; display: block;"></iframe>
                </div>
            </div>

            <!-- Error State -->
            <div id="summonsPreviewError" style="display: none; width: 100%; max-width: 500px; margin: auto; padding: 24px; background: #fff; border: 1px solid #fecaca; border-radius: 8px; text-align: center; color: #991b1b;">
                <p id="summonsPreviewErrorMsg" style="margin: 0 0 12px; font-size: 0.88rem;"></p>
                <button type="button" class="btn-secondary" onclick="retrySummonsPreview()">Retry Preview</button>
            </div>
        </div>

        <!-- Action Footer (Pinned at Bottom, ALWAYS Fully Visible) -->
        <div class="modal-actions" style="padding: 10px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; z-index: 10;">
            <button type="button" class="btn-secondary" onclick="closeSummonsPreviewModal()" style="font-size: 0.84rem; padding: 7px 14px;">
                Close
            </button>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <button type="button" class="btn-secondary" id="btnPrintBothForms" onclick="printSummonsPreview('both')" style="font-size: 0.84rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 6px;" title="Print both KP Form 8 and KP Form 9">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Print Both (KP 8 &amp; KP 9)
                </button>
                <button type="button" class="btn-secondary" id="btnPrintSummons" onclick="printSummonsPreview('kp9')" style="font-size: 0.84rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 6px;" title="Print Respondent KP Form 9 Summons">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Print KP 9 Summons
                </button>
                <a href="#" id="btnDownloadSummonsPdf" onclick="downloadSummonsPdf(event)" class="btn-secondary" style="font-size: 0.84rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;" title="Download KP Form 9 PDF">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download PDF
                </a>
                <button type="button" class="btn-create" id="btnContinueOfficerReturn" onclick="continueToOfficerReturn()" style="font-size: 0.84rem; padding: 7px 16px; display: inline-flex; align-items: center; gap: 6px; background: #0284c7;" title="Proceed to Officer's Return">
                    Continue to Officer’s Return &rarr;
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes agapSpin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.summons-dual-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
}

@media (max-width: 991px) {
    .summons-modal-content {
        height: auto !important;
        max-height: calc(100vh - 20px) !important;
    }
    .summons-preview-body {
        overflow-y: auto !important;
    }
    .summons-dual-grid {
        grid-template-columns: 1fr !important;
        height: auto !important;
    }
    .preview-document-column {
        height: 480px !important;
        margin-bottom: 10px;
    }
}
</style>
