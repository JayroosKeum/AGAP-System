<?php
$path = __DIR__ . '/../frontend/pages/complaints/complaint-details.php';
$content = file_get_contents($path);

// 1. Add Proceed to Officer's Return buttons to paanyayaPreviewModal
$targetModalHeader = '<div style="display: flex; align-items: center; gap: 10px;">
                <button type="button" class="btn-sm btn-outline-sm" onclick="printPaanyayaIframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px;">
                    🖨️ Print
                </button>
                <a id="btnModalDownloadPaanyaya" href="#" target="_blank" class="btn-sm btn-create" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; text-decoration: none;">
                    ⬇ Download PDF
                </a>
                <button type="button" class="close-btn" onclick="closePaanyayaPreviewModal()">&times;</button>
            </div>';

$replaceModalHeader = '<div style="display: flex; align-items: center; gap: 10px;">
                <button type="button" class="btn-sm btn-outline-sm" onclick="printPaanyayaIframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px;">
                    🖨️ Print
                </button>
                <a id="btnModalDownloadPaanyaya" href="#" target="_blank" class="btn-sm btn-create" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; text-decoration: none; background: #0f172a;">
                    ⬇ Download PDF
                </a>
                <button type="button" class="btn-sm btn-create" id="btnHeaderToOfficersReturn" onclick="proceedToOfficersReturnFromPreview()" style="background: #2563eb; border-color: #2563eb; display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-weight: 700;">
                    📝 Proceed to Officer\'s Return &rarr;
                </button>
                <button type="button" class="close-btn" onclick="closePaanyayaPreviewModal()">&times;</button>
            </div>';

$targetModalFooter = '<div class="modal-actions" style="padding: 10px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: flex-end;">
            <button type="button" class="btn-secondary" onclick="closePaanyayaPreviewModal()">Close Preview</button>
        </div>';

$replaceModalFooter = '<div class="modal-actions" style="padding: 12px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: space-between; align-items: center;">
            <div style="font-size: 0.83rem; color: #475569; display: flex; align-items: center; gap: 6px;">
                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #2563eb;"></span>
                <span>Recipient for Delivery: <strong style="color: #0f172a;">Respondent Only</strong> (Up to 6 Attempts on Paanyaya)</span>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <button type="button" class="btn-create" id="btnFooterToOfficersReturn" onclick="proceedToOfficersReturnFromPreview()" style="background: #2563eb; border-color: #2563eb; padding: 8px 18px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(37,99,235,0.25);">
                    📝 Proceed to Officer\'s Return &rarr;
                </button>
                <button type="button" class="btn-secondary" onclick="closePaanyayaPreviewModal()">Close Preview</button>
            </div>
        </div>';

$content = str_replace($targetModalHeader, $replaceModalHeader, $content);
$content = str_replace($targetModalFooter, $replaceModalFooter, $content);

// 2. Enhance LTIA banner text to explain the unlocked rule
$targetLtia = 'Each stage allows up to <strong>six (6) recorded service attempts</strong> with documented <strong>Officer’s Returns</strong> before authorized case escalation.';
$replaceLtia = 'Paanyaya invitations are served <strong>exclusively to the Respondent</strong> (complainants do not require delivery). Up to <strong>six (6) delivery attempts</strong> are recorded. <strong>If Paanyaya is served OR after 6 failed attempts, Issue Summon 1st Attempt is unlocked.</strong>';
$content = str_replace($targetLtia, $replaceLtia, $content);

// 3. Update Paanyaya 1 card header to emphasize Respondent Delivery Tracking
$content = str_replace(
    '<span class="paanyaya-stage-tag">Stage 0.1 &bull; Initial Preliminary</span>',
    '<span class="paanyaya-stage-tag">Stage 0.1 &bull; Officer\'s Return (Respondent Only)</span>',
    $content
);
$content = str_replace(
    '<h3 class="paanyaya-card-title">Paanyaya 1 – First Preliminary Invitation</h3>',
    '<h3 class="paanyaya-card-title">Paanyaya 1 – Preliminary Invitation Delivery (Respondent)</h3>',
    $content
);

// 4. Update Paanyaya 2 card header
$content = str_replace(
    '<span class="paanyaya-stage-tag tag-p2">Stage 0.2 &bull; Second Preliminary</span>',
    '<span class="paanyaya-stage-tag tag-p2">Stage 0.2 &bull; Officer\'s Return (Respondent Only)</span>',
    $content
);
$content = str_replace(
    '<h3 class="paanyaya-card-title">Paanyaya 2 – Second Preliminary Invitation</h3>',
    '<h3 class="paanyaya-card-title">Paanyaya 2 – Second Preliminary Delivery (Respondent)</h3>',
    $content
);

file_put_contents($path, $content);
echo "Updated complaint-details.php with Proceed to Officer's Return button and Respondent-only delivery focus.\n";
