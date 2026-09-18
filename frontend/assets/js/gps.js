const gpsApi = async (url, options = {}) => {
    const response = await fetch(url, options);
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
    box.className = message ? `alert ${success ? 'success' : 'error'}` : '';
};

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('proofForm')) initProofs();
});

async function initProofs() {
    const select = document.getElementById('proofCaseId');
    document.getElementById('servedDate').value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    try {
        const result = await gpsApi('../../../backend/api/gps/proof-service.php');
        result.data.forEach(item => select.add(new Option(`${item.case_number} - ${item.complaint_title}`, item.case_id)));
    } catch (error) {
        setGpsMessage('proofMessage', error.message);
    }

    select.addEventListener('change', async () => {
        await loadServiceDocuments(select.value);
        loadProofs(select.value);
    });

    document.getElementById('proofForm').addEventListener('submit', async event => {
        event.preventDefault();
        const selectedCaseId = select.value;
        try {
            const result = await gpsApi('../../../backend/api/gps/proof-service.php', { method: 'POST', body: new FormData(event.currentTarget) });
            setGpsMessage('proofMessage', result.message, true);
            event.currentTarget.reset();
            select.value = selectedCaseId;
            await loadServiceDocuments(selectedCaseId);
            document.getElementById('servedDate').value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            if (selectedCaseId) loadProofs(selectedCaseId);
        } catch (error) {
            setGpsMessage('proofMessage', error.message);
        }
    });
}

async function loadServiceDocuments(caseId) {
    const select = document.getElementById('proofDocumentId');
    select.replaceChildren(new Option(caseId ? 'Loading documents…' : 'Select a case first', ''));
    select.disabled = !caseId;
    if (!caseId) return;
    try {
        const result = await gpsApi('../../../backend/api/gps/proof-service.php?case_id=' + encodeURIComponent(caseId));
        const documents = result.data || [];
        select.replaceChildren(new Option(documents.length ? 'Select a generated document' : 'No generated documents available', ''));
        documents.forEach(item => select.add(new Option(`${item.template_name} — ${item.service_status}`, item.document_id)));
        select.disabled = documents.length === 0;
    } catch (error) {
        select.replaceChildren(new Option(error.message, ''));
        select.disabled = true;
    }
}

async function loadProofs(caseId) {
    const table = document.getElementById('proofsTable');
    if (!caseId) {
        table.innerHTML = '<tr><td colspan="4" class="empty-state">Select a case to view service history.</td></tr>';
        return;
    }
    try {
        const result = await gpsApi('../../../backend/api/gps/proof-service.php?case_id=' + encodeURIComponent(caseId));
        const rows = result.data || [];
        table.innerHTML = rows.length ? rows.map(item => `<tr><td>${formatDateTime(item.served_date)}</td><td>${escapeGpsHtml(item.served_by_name || 'Unknown')}</td><td>${escapeGpsHtml(item.remarks || '—')}</td><td>${item.image_path ? `<a target="_blank" rel="noopener" href="../../../backend/api/gps/proof-image.php?id=${Number(item.proof_id)}"><img class="proof-image" src="../../../backend/api/gps/proof-image.php?id=${Number(item.proof_id)}" alt="Proof image"></a>` : '—'}</td></tr>`).join('') : '<tr><td colspan="4" class="empty-state">No proof of service recorded.</td></tr>';
    } catch (error) {
        table.innerHTML = `<tr><td colspan="4" class="empty-state">${escapeGpsHtml(error.message)}</td></tr>`;
    }
}
