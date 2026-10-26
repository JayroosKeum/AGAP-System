<?php
/**
 * Official KP Forms Preview & Multi-Panel Pangkat Notices Modal
 * Includes:
 * 1. Tri-Panel Pangkat Notices Modal (KP Forms 10, 11, and 12) with Officer's Return link
 * 2. Reusable Single KP Form Preview Modal (KP Forms 14, 15, 16, 17, 20, 20-A, 20-B, etc.)
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
            
            <!-- Side-by-side Tri-Panel Grid -->
            <div id="pangkatPreviewGrid" class="pangkat-tri-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; width: 100%; height: 100%; min-height: 0; flex: 1 1 0; gap: 10px; box-sizing: border-box;">
                
                <!-- Panel 1: KP Form 10 (Notice for Constitution of Pangkat) -->
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

                <!-- Panel 2: KP Form 11 (Notice to Chosen Pangkat Members) -->
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

                <!-- Panel 3: KP Form 12 (Notice of Hearing - Conciliation) -->
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

        <!-- Action Footer with Officer's Return Integration -->
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
<!-- 2. REUSABLE SINGLE KP FORM PREVIEW MODAL (KP 14, 15, 16, 17, 20, etc.)   -->
<!-- ======================================================================= -->
<div id="kpDocumentPreviewModal" class="modal" style="display: none; padding: 12px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9999;">
    <div class="modal-content" style="max-width: 900px; width: 95vw; height: calc(100vh - 40px); max-height: calc(100vh - 40px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 30px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.08); background: #fff; box-sizing: border-box;">
        
        <!-- Header -->
        <div class="modal-header" style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; background: #fff; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 id="kpDocModalHeading" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0; line-height: 1.2;">
                    Official KP Form Preview
                </h2>
                <p id="kpDocModalSub" style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b;">
                    Barangay Tumana Lupong Tagapamayapa
                </p>
            </div>
            <button type="button" class="close-btn" onclick="closeKpDocumentPreviewModal()" title="Close Preview" style="font-size: 26px; line-height: 1; border: none; background: transparent; cursor: pointer; color: #64748b; padding: 0 4px;">&times;</button>
        </div>

        <!-- Body -->
        <div style="flex: 1 1 0; min-height: 0; background: #f8fafc; padding: 8px; overflow: hidden; display: flex; flex-direction: column;">
            <iframe id="kpDocPreviewIframe" title="Official KP Document Preview" style="width: 100%; height: 100%; min-height: 0; flex: 1 1 0; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;"></iframe>
        </div>

        <!-- Footer Actions -->
        <div class="modal-actions" style="padding: 10px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; flex: 0 0 auto; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
            <button type="button" class="btn-secondary" onclick="closeKpDocumentPreviewModal()" style="font-size: 0.84rem; padding: 7px 14px;">
                Close
            </button>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn-secondary" onclick="printKpDocIframe()" style="font-size: 0.84rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                    🖨️ Print Form
                </button>
                <a href="#" id="btnDownloadKpDocPdf" class="btn-create" style="font-size: 0.84rem; padding: 7px 16px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;" download>
                    Download PDF
                </a>
            </div>
        </div>

    </div>
</div>

<style>
@media (max-width: 900px) {
    .pangkat-tri-grid {
        grid-template-columns: 1fr !important;
        overflow-y: auto !important;
    }
    .preview-document-column {
        min-height: 480px !important;
    }
}
</style>
