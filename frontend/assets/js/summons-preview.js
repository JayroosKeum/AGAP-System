/**
 * AGAP First Mediation Summons Preview & Actions (KP Form No. 8 & KP Form No. 9)
 * Handles dual side-by-side previewing, printing, downloading, and transitioning to Officer's Return.
 */

window.currentSummonsPreview = {
    caseId: null,
    documentId: null,
    hearingId: null,
    officerReturnUrl: null,
    kp8: {
        documentId: null,
        pdfUrl: null,
        downloadUrl: null,
        html: null
    },
    kp9: {
        documentId: null,
        pdfUrl: null,
        downloadUrl: null,
        html: null
    }
};

/**
 * Open the First Mediation Summons Preview modal (Print summon).
 *
 * @param {number|string} caseId
 * @param {number|string|null} documentId
 * @param {number|string|null} hearingId
 * @param {boolean} isJustSaved Whether opened immediately after scheduling/booking
 */
async function openSummonsPreviewModal(caseId, documentId = null, hearingId = null, isJustSaved = false) {
    const modal = document.getElementById('summonsPreviewModal');
    if (!modal) {
        console.warn('summonsPreviewModal element not found in DOM.');
        return;
    }

    window.currentSummonsPreview = {
        caseId: caseId ? Number(caseId) : null,
        documentId: documentId ? Number(documentId) : null,
        hearingId: hearingId ? Number(hearingId) : null,
        officerReturnUrl: null,
        kp8: { documentId: null, pdfUrl: null, downloadUrl: null, html: null },
        kp9: { documentId: null, pdfUrl: null, downloadUrl: null, html: null }
    };

    const titleEl = document.getElementById('summonsPreviewModalTitle');
    const subtitleEl = document.getElementById('summonsPreviewModalSubtitle');
    const successBanner = document.getElementById('summonsPreviewSuccessBanner');
    const loadingEl = document.getElementById('summonsPreviewLoading');
    const gridEl = document.getElementById('summonsPreviewGrid');
    const frameKp8 = document.getElementById('summonsPreviewFrameKp8');
    const frameKp9 = document.getElementById('summonsPreviewFrameKp9');
    const errorEl = document.getElementById('summonsPreviewError');
    const errorMsgEl = document.getElementById('summonsPreviewErrorMsg');
    const kp8Hint = document.getElementById('kp8PartyHint');
    const kp9Hint = document.getElementById('kp9PartyHint');
    const btnDownloadKp8 = document.getElementById('btnDownloadKp8');
    const btnDownloadKp9 = document.getElementById('btnDownloadKp9');
    const btnPrintBoth = document.getElementById('btnPrintBothForms');
    const btnPrint = document.getElementById('btnPrintSummons');
    const btnDownload = document.getElementById('btnDownloadSummonsPdf');
    const btnContinue = document.getElementById('btnContinueOfficerReturn');

    if (successBanner) {
        successBanner.style.display = isJustSaved ? 'flex' : 'none';
    }

    if (titleEl) titleEl.textContent = 'Print summon';
    if (subtitleEl) subtitleEl.textContent = 'KP Form No. 8 (Patalastas ng Pagdinig) & KP Form No. 9 (Patawag) · Loading documents…';

    if (loadingEl) loadingEl.style.display = 'flex';
    if (gridEl) gridEl.style.display = 'none';
    if (frameKp8) frameKp8.srcdoc = '';
    if (frameKp9) frameKp9.srcdoc = '';
    if (errorEl) errorEl.style.display = 'none';

    [btnPrintBoth, btnPrint, btnContinue].forEach(btn => {
        if (btn) btn.disabled = true;
    });
    [btnDownloadKp8, btnDownloadKp9, btnDownload].forEach(link => {
        if (link) {
            link.style.pointerEvents = 'none';
            link.style.opacity = '0.6';
            link.removeAttribute('href');
        }
    });

    modal.style.display = 'flex';

    try {
        const params = new URLSearchParams();
        if (caseId) params.append('case_id', caseId);
        if (documentId) params.append('document_id', documentId);
        if (hearingId) params.append('hearing_id', hearingId);

        const response = await fetch(`../../../backend/api/summons/preview.php?${params.toString()}`, {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Failed to load summons preview documents.');
        }

        window.currentSummonsPreview.caseId = data.case_id;
        window.currentSummonsPreview.documentId = data.document_id;
        window.currentSummonsPreview.hearingId = data.hearing_id;
        window.currentSummonsPreview.officerReturnUrl = data.officer_return_url;

        if (data.kp8) {
            window.currentSummonsPreview.kp8 = data.kp8;
        }
        if (data.kp9) {
            window.currentSummonsPreview.kp9 = data.kp9;
        } else {
            window.currentSummonsPreview.kp9 = {
                document_id: data.document_id,
                pdf_url: data.pdf_url,
                download_url: data.download_url,
                html: data.html
            };
        }

        if (subtitleEl) {
            subtitleEl.textContent = `Case #${data.case_number || caseId} · Scheduled: ${data.hearing_date_formatted || 'Mediation'}`;
        }

        if (kp8Hint) {
            const compName = data.kp8?.target_party_name || data.primary_complainant || 'Complainant';
            kp8Hint.textContent = `Para sa May-sumbong: ${compName}`;
        }

        if (kp9Hint) {
            const respName = data.kp9?.target_party_name || data.primary_respondent || 'Respondent';
            kp9Hint.textContent = `Para sa Ipinagsusumbong: ${respName}`;
        }

        // Render KP Form 8 in left frame
        if (frameKp8 && data.kp8?.html) {
            frameKp8.srcdoc = data.kp8.html;
        }

        // Render KP Form 9 in right frame
        if (frameKp9) {
            frameKp9.srcdoc = data.kp9?.html || data.html || '';
        }

        // Download links
        if (btnDownloadKp8 && data.kp8?.download_url) {
            btnDownloadKp8.href = data.kp8.download_url;
            btnDownloadKp8.style.pointerEvents = 'auto';
            btnDownloadKp8.style.opacity = '1';
        }

        const kp9DownloadUrl = data.kp9?.download_url || data.download_url;
        if (btnDownloadKp9 && kp9DownloadUrl) {
            btnDownloadKp9.href = kp9DownloadUrl;
            btnDownloadKp9.style.pointerEvents = 'auto';
            btnDownloadKp9.style.opacity = '1';
        }

        if (btnDownload && kp9DownloadUrl) {
            btnDownload.href = kp9DownloadUrl;
            btnDownload.style.pointerEvents = 'auto';
            btnDownload.style.opacity = '1';
        }

        // Buttons
        [btnPrintBoth, btnPrint, btnContinue].forEach(btn => {
            if (btn) btn.disabled = false;
        });

        if (loadingEl) loadingEl.style.display = 'none';
        if (gridEl) gridEl.style.display = 'grid';

    } catch (err) {
        if (loadingEl) loadingEl.style.display = 'none';
        if (gridEl) gridEl.style.display = 'none';
        if (errorEl) {
            errorEl.style.display = 'block';
            if (errorMsgEl) errorMsgEl.textContent = err.message || 'Unable to load summons preview.';
        }
        // Allow user to continue to Officer's Return if caseId is known, preventing dead ends
        if (btnContinue && window.currentSummonsPreview.caseId) {
            btnContinue.disabled = false;
            window.currentSummonsPreview.officerReturnUrl = `../gps/proof-service.php?case_id=${encodeURIComponent(window.currentSummonsPreview.caseId)}` + (window.currentSummonsPreview.documentId ? `&document_id=${encodeURIComponent(window.currentSummonsPreview.documentId)}` : '');
        }
    }
}

function closeSummonsPreviewModal() {
    const modal = document.getElementById('summonsPreviewModal');
    if (modal) modal.style.display = 'none';
    const frameKp8 = document.getElementById('summonsPreviewFrameKp8');
    const frameKp9 = document.getElementById('summonsPreviewFrameKp9');
    if (frameKp8) frameKp8.srcdoc = '';
    if (frameKp9) frameKp9.srcdoc = '';
}

function retrySummonsPreview() {
    const state = window.currentSummonsPreview;
    if (state.caseId || state.documentId) {
        openSummonsPreviewModal(state.caseId, state.documentId, state.hearingId, false);
    }
}

/**
 * Automatically mark KP Form No. 8 as served when printed for First Mediation.
 */
async function markKp8AsServedOnPrint() {
    const state = window.currentSummonsPreview;
    if (!state || !state.caseId) return;
    const kp8DocId = state.kp8?.documentId || state.documentId;
    if (!kp8DocId) return;

    try {
        const formData = new FormData();
        formData.append('case_id', state.caseId);
        formData.append('document_id', kp8DocId);
        if (state.hearingId) {
            formData.append('hearing_id', state.hearingId);
        }

        const res = await fetch('../../../backend/api/summons/mark-served-kp8.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json();
        if (data && data.success) {
            window.agapNotify?.(
                data.message || 'KP Form No. 8 (Complainant Notice) marked as Served upon printing.',
                'success',
                'Complainant Notice Served'
            );
            const kp8Hint = document.getElementById('kp8PartyHint');
            if (kp8Hint) {
                kp8Hint.innerHTML = (kp8Hint.innerHTML || '').replace(/Status:[^<]*/, '') + ' · <span style="color:#16a34a; font-weight:700;">Status: Served (Print Workflow)</span>';
            }
            if (typeof window.loadHearingDeliveries === 'function') {
                window.loadHearingDeliveries(state.caseId);
            }
        }
    } catch (err) {
        console.warn('Failed to auto-mark KP Form 8 as served on print:', err);
    }
}

/**
 * Print summons or notice of hearing.
 *
 * @param {'kp8'|'kp9'|'both'} target Which document to print
 */
function printSummonsPreview(target = 'kp9') {
    const frame8 = document.getElementById('summonsPreviewFrameKp8');
    const frame9 = document.getElementById('summonsPreviewFrameKp9');

    const printFrame = (frame, fallbackPdf) => {
        if (frame && frame.contentWindow) {
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
                return true;
            } catch (e) {
                console.warn('Iframe print failed, falling back:', e);
            }
        }
        if (fallbackPdf) {
            window.open(fallbackPdf, '_blank');
            return true;
        }
        return false;
    };

    if (target === 'kp8') {
        const printed = printFrame(frame8, window.currentSummonsPreview?.kp8?.pdfUrl);
        if (printed) {
            markKp8AsServedOnPrint();
        }
    } else if (target === 'kp9') {
        // Do NOT mark KP Form 9 as served merely on print
        printFrame(frame9, window.currentSummonsPreview?.kp9?.pdfUrl);
    } else if (target === 'both') {
        // Print KP Form 8 first, then sequence KP Form 9
        if (frame8?.contentWindow) {
            frame8.contentWindow.focus();
            frame8.contentWindow.print();
            markKp8AsServedOnPrint();

            // After focus returns from KP 8 print dialog, trigger KP 9
            const onAfterPrint = () => {
                window.removeEventListener('focus', onAfterPrint);
                setTimeout(() => {
                    if (frame9?.contentWindow) {
                        frame9.contentWindow.focus();
                        frame9.contentWindow.print();
                    }
                }, 400);
            };
            window.addEventListener('focus', onAfterPrint, { once: true });
        } else {
            const printed8 = printFrame(frame8, window.currentSummonsPreview?.kp8?.pdfUrl);
            if (printed8) {
                markKp8AsServedOnPrint();
            }
            printFrame(frame9, window.currentSummonsPreview?.kp9?.pdfUrl);
        }
    }
}

function downloadSummonsPdf(event) {
    const kp9Url = window.currentSummonsPreview?.kp9?.downloadUrl;
    if (!kp9Url) {
        if (event) event.preventDefault();
        window.agapNotify?.('Summons PDF is not ready yet.', 'warning');
        return;
    }
}

function continueToOfficerReturn() {
    if (window.currentSummonsPreview?.officerReturnUrl) {
        window.location.href = window.currentSummonsPreview.officerReturnUrl;
        return;
    }
    const caseId = window.currentSummonsPreview?.caseId;
    const docId = window.currentSummonsPreview?.documentId;
    if (caseId) {
        window.location.href = `../gps/proof-service.php?case_id=${encodeURIComponent(caseId)}` + (docId ? `&document_id=${encodeURIComponent(docId)}` : '');
        return;
    }
    window.location.href = '../gps/proof-service.php';
}

// Global exports
window.openSummonsPreviewModal = openSummonsPreviewModal;
window.closeSummonsPreviewModal = closeSummonsPreviewModal;
window.retrySummonsPreview = retrySummonsPreview;
window.printSummonsPreview = printSummonsPreview;
window.downloadSummonsPdf = downloadSummonsPdf;
window.continueToOfficerReturn = continueToOfficerReturn;
