const gpsApi = async (url, options = {}) => {
    let fetchUrl = url;
    if (!options.method || options.method.toUpperCase() === 'GET') {
        const sep = fetchUrl.includes('?') ? '&' : '?';
        fetchUrl = `${fetchUrl}${sep}_t=${Date.now()}`;
    }
    const fetchOptions = {
        cache: 'no-store',
        ...options,
        headers: {
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache',
            ...(options.headers || {})
        }
    };
    const response = await fetch(fetchUrl, fetchOptions);
    const data = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || data.success === false) throw new Error(data.message || 'Request failed.');
    return data;
};

const escapeGpsHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
})[character]);

const formatDateTime = (value) => value
    ? new Date(value.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
    : '—';

const setGpsMessage = (id, message, success = false) => {
    const box = document.getElementById(id);
    if (!box) return;
    box.textContent = message;
    box.className = message ? `alert ${success ? 'alert-success' : 'alert-error'}` : '';
};

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('proofForm')) initProofs();
});

async function initProofs() {
    const select = document.getElementById('proofCaseId');
    const servedDateInput = document.getElementById('servedDate');
    if (servedDateInput) {
        servedDateInput.value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    }

    // Check for query parameters (?case_id=X&document_id=Y)
    const urlParams = new URLSearchParams(window.location.search);
    const preselectedCaseId = urlParams.get('case_id');
    const preselectedDocId = urlParams.get('document_id');

    // Load servers
    await loadSummonsServers();

    // Load cases
    try {
        const result = await gpsApi('../../../backend/api/gps/proof-service.php');
        select.replaceChildren(new Option('Select a case', ''));
        (result.data || []).forEach(item => {
            const opt = new Option(`${item.case_number} — ${item.complaint_title}`, item.case_id);
            select.add(opt);
        });

        if (preselectedCaseId) {
            select.value = preselectedCaseId;
            if (select.value) {
                await onCaseSelected(preselectedCaseId, preselectedDocId);
            }
        }
    } catch (error) {
        setGpsMessage('proofMessage', error.message);
    }

    select.addEventListener('change', async () => {
        const cid = select.value;
        await onCaseSelected(cid);
    });

    document.getElementById('proofForm').addEventListener('submit', async event => {
        event.preventDefault();
        const selectedCaseId = select.value;
        const currentDocId = document.getElementById('proofDocumentId')?.value;
        const submitBtn = event.currentTarget.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const formData = new FormData(event.currentTarget);
            const docSelect = document.getElementById('proofDocumentId');
            if (docSelect && docSelect.value && !formData.get('document_id')) {
                formData.set('document_id', docSelect.value);
            }
            if (select && select.value && !formData.get('case_id')) {
                formData.set('case_id', select.value);
            }

            const result = await gpsApi('../../../backend/api/gps/proof-service.php', {
                method: 'POST',
                body: formData
            });

            const successMsg = result.message || 'Proof of service recorded successfully.';
            setGpsMessage('proofMessage', successMsg, true);
            window.agapNotify?.(successMsg, 'success');

            // Reset form input values
            event.currentTarget.reset();

            // Re-select active case and reset date to now
            select.value = selectedCaseId;
            if (servedDateInput) {
                servedDateInput.value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            }

            // Immediately auto-refresh service documents, service history table, and case summary
            await loadServiceDocuments(selectedCaseId, currentDocId);
            await loadProofs(selectedCaseId);
            await loadCaseSummary(selectedCaseId);
        } catch (error) {
            setGpsMessage('proofMessage', error.message);
            window.agapNotify?.(error.message, 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });
}

async function onCaseSelected(caseId, targetDocId = null) {
    if (!caseId) {
        document.getElementById('caseSummaryCard').style.display = 'none';
        document.getElementById('backLink').style.display = 'none';
        await loadServiceDocuments(null);
        await loadProofs(null);
        return;
    }

    await loadCaseSummary(caseId);
    await loadServiceDocuments(caseId, targetDocId);
    await loadProofs(caseId);
}

async function loadCaseSummary(caseId) {
    const card = document.getElementById('caseSummaryCard');
    const backLink = document.getElementById('backLink');
    if (!caseId) {
        if (card) card.style.display = 'none';
        if (backLink) backLink.style.display = 'none';
        return;
    }

    try {
        const result = await gpsApi(`../../../backend/api/gps/proof-service.php?case_id=${encodeURIComponent(caseId)}&mode=summary`);
        const data = result.data;
        if (!data) return;

        document.getElementById('summaryCaseNumber').textContent = data.case_number ? `Case #${data.case_number}` : `Case #${caseId}`;
        document.getElementById('summaryComplaintNumber').textContent = data.complaint_number || `CMP-${data.complaint_id}`;
        document.getElementById('summaryComplaintTitle').textContent = data.complaint_title || 'Untitled Complaint';
        document.getElementById('summaryComplainants').textContent = (data.complainants && data.complainants.length) ? data.complainants.join(', ') : 'None listed';
        document.getElementById('summaryRespondents').textContent = (data.respondents && data.respondents.length) ? data.respondents.join(', ') : 'None listed';

        if (card) card.style.display = 'block';

        if (backLink && data.complaint_id) {
            backLink.href = `../complaints/complaint-details.php?id=${encodeURIComponent(data.complaint_id)}`;
            document.getElementById('backLinkLabel').textContent = `Back to Complaint #${data.complaint_number || data.complaint_id}`;
            backLink.style.display = 'inline-flex';
        }
    } catch (_) {
        if (card) card.style.display = 'none';
    }
}

async function loadSummonsServers() {
    const select = document.getElementById('servedBy');
    if (!select) return;
    try {
        const result = await gpsApi('../../../backend/api/gps/proof-service.php?mode=servers');
        const servers = result.data || [];
        if (servers.length) {
            const currentVal = select.value;
            select.replaceChildren();
            servers.forEach(s => {
                const opt = new Option(`${s.full_name} (${s.role_name})`, s.user_id);
                if (String(s.user_id) === String(currentVal)) opt.selected = true;
                select.add(opt);
            });
        }
    } catch (_) {}
}

async function loadServiceDocuments(caseId, targetDocId = null) {
    const select = document.getElementById('proofDocumentId');
    if (!select) return;
    select.replaceChildren(new Option(caseId ? 'Loading documents…' : 'Select a case first', ''));
    select.disabled = !caseId;
    if (!caseId) return;

    try {
        const result = await gpsApi(`../../../backend/api/gps/proof-service.php?case_id=${encodeURIComponent(caseId)}&mode=documents`);
        const documents = result.data || [];
        select.replaceChildren(new Option(documents.length ? 'Select a summons / generated document' : 'No documents available for service', ''));

        documents.forEach(item => {
            const statusLabel = item.service_status ? `[${item.service_status}]` : '';
            const opt = new Option(`${item.template_name} ${statusLabel} — Issued ${item.generated_at ? item.generated_at.slice(0, 10) : ''}`, item.document_id);
            if (targetDocId && String(item.document_id) === String(targetDocId)) {
                opt.selected = true;
            }
            select.add(opt);
        });

        select.disabled = documents.length === 0;

        // Auto-select if only 1 document or target specified
        if (targetDocId) {
            select.value = targetDocId;
        } else if (documents.length === 1) {
            select.selectedIndex = 1;
        }
    } catch (error) {
        select.replaceChildren(new Option(error.message, ''));
        select.disabled = true;
    }
}

async function loadProofs(caseId) {
    const table = document.getElementById('proofsTable');
    if (!table) return;
    if (!caseId) {
        table.innerHTML = '<tr><td colspan="6" class="empty-state" style="text-align: center; padding: 24px; color: #64748b;">Select a case to view service history.</td></tr>';
        return;
    }
    try {
        const result = await gpsApi(`../../../backend/api/gps/proof-service.php?case_id=${encodeURIComponent(caseId)}`);
        const rows = result.data || [];
        if (!rows.length) {
            table.innerHTML = '<tr><td colspan="6" class="empty-state" style="text-align: center; padding: 24px; color: #64748b;">No summons service attempts recorded for this case yet.</td></tr>';
            return;
        }

        table.innerHTML = rows.map((item, idx) => {
            const res = item.service_result || 'Served';
            let badgeClass = 'badge-other';
            let badgeIcon = '●';
            if (res === 'Served') {
                badgeClass = 'badge-served';
                badgeIcon = '✓';
            } else if (res === 'Not Served') {
                badgeClass = 'badge-not-served';
                badgeIcon = '✕';
            } else if (res === 'Refused') {
                badgeClass = 'badge-refused';
                badgeIcon = '⚠';
            } else if (res === 'Respondent Not Found' || res === 'Address Problem') {
                badgeClass = 'badge-warning';
                badgeIcon = '!';
            }

            const imageHtml = item.image_path
                ? `<a target="_blank" rel="noopener" href="../../../backend/api/gps/proof-image.php?id=${Number(item.proof_id)}"><img class="proof-image" src="../../../backend/api/gps/proof-image.php?id=${Number(item.proof_id)}" alt="Proof photo"></a>`
                : '<span style="color:#94a3b8; font-size:0.85rem;">None</span>';

            const docName = item.template_name || 'Summons Document';

            return `
                <tr>
                    <td><strong>${formatDateTime(item.served_date)}</strong><br><small style="color:#64748b;">Attempt #${rows.length - idx}</small></td>
                    <td>${escapeGpsHtml(docName)}</td>
                    <td>${escapeGpsHtml(item.served_by_name || 'Barangay Server')}</td>
                    <td><span class="service-result-badge ${badgeClass}">${badgeIcon} ${escapeGpsHtml(res)}</span></td>
                    <td>${escapeGpsHtml(item.remarks || '—')}</td>
                    <td>${imageHtml}</td>
                </tr>
            `;
        }).join('');
    } catch (error) {
        table.innerHTML = `<tr><td colspan="6" class="empty-state" style="color: #ef4444; padding: 16px;">${escapeGpsHtml(error.message)}</td></tr>`;
    }
}
