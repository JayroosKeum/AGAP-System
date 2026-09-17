const canManageDocuments = window.AGAP_DOCUMENTS?.canManage === true;

document.addEventListener('DOMContentLoaded', () => {
    loadGeneratedDocuments();
    if (canManageDocuments) {
        loadCases();
        document.getElementById('documentCaseId')?.addEventListener('change', loadKp12Preview);
        document.getElementById('kp12Form')?.addEventListener('submit', generateKp12);
    }
});

async function api(url, options = {}) {
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || result.success === false) throw new Error(result.message || 'Request failed.');
    return result;
}

async function loadCases() {
    const select = document.getElementById('documentCaseId');
    try {
        const result = await api('../../../backend/api/documents/cases.php');
        select.replaceChildren(new Option('Select a case', ''));
        result.data.forEach(item => select.add(new Option(`${item.case_number} - ${item.complaint_title}`, item.case_id)));
    } catch (error) { setMessage(error.message, false); }
}

async function loadKp12Preview() {
    const caseId = document.getElementById('documentCaseId').value;
    const preview = document.getElementById('kp12Preview');
    if (!caseId) { preview.textContent = 'Select a case to review the form data.'; return; }
    try {
        const result = await api('../../../backend/api/documents/kp12-data.php?case_id=' + encodeURIComponent(caseId));
        const item = result.data;
        preview.replaceChildren();
        const values = [
            ['Complainant(s)', item.complainants.map(p => p.full_name).join(', ') || 'Missing'],
            ['Respondent(s)', item.respondents.map(p => p.full_name).join(', ') || 'Missing'],
            ['Conciliation hearing', item.hearing_date ? formatDateTime(item.hearing_date) : 'Not scheduled'],
            ['Venue', item.venue || 'Missing'],
            ['Case-team Head', item.chairman_name || 'Not assigned']
        ];
        values.forEach(([label, value]) => {
            const row = document.createElement('div');
            const strong = document.createElement('strong'); strong.textContent = label + ': ';
            row.append(strong, document.createTextNode(value)); preview.appendChild(row);
        });
    } catch (error) { preview.textContent = error.message; }
}

async function generateKp12(event) {
    event.preventDefault();
    setMessage('');
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form).entries());
    try {
        const result = await api('../../../backend/api/documents/generate.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data)
        });
        setMessage(result.message, true);
        await loadGeneratedDocuments();
        window.open(result.download_url, '_blank', 'noopener');
    } catch (error) { setMessage(error.message, false); }
}

async function loadGeneratedDocuments() {
    const table = document.getElementById('generatedDocumentsTable');
    try {
        const result = await api('../../../backend/api/documents/list.php');
        const rows = result.data || [];
        table.innerHTML = rows.length ? rows.map(item => `
            <tr><td>${escapeHtml(item.template_name)}</td><td>${escapeHtml(item.case_number)}</td>
            <td>${escapeHtml(item.complaint_title)}</td><td>${escapeHtml(item.generated_by_name || 'Unknown')}</td>
            <td>${formatDateTime(item.generated_at)}</td><td class="action-buttons">
            <a class="btn-view" target="_blank" rel="noopener" href="../../../backend/api/documents/download.php?id=${Number(item.document_id)}">View PDF</a>
            ${canManageDocuments ? `<button type="button" data-delete="${Number(item.document_id)}">Delete</button>` : ''}
            </td></tr>`).join('') : '<tr><td colspan="6" class="empty-state">No generated documents yet.</td></tr>';
        table.querySelectorAll('[data-delete]').forEach(button => button.addEventListener('click', () => deleteDocument(button.dataset.delete)));
    } catch (error) { table.innerHTML = `<tr><td colspan="6" class="empty-state">${escapeHtml(error.message)}</td></tr>`; }
}

async function deleteDocument(id) {
    if (!confirm('Delete this generated document?')) return;
    const body = new URLSearchParams({ document_id: id });
    try {
        const result = await api('../../../backend/api/documents/delete.php', { method: 'POST', body });
        setMessage(result.message, true); await loadGeneratedDocuments();
    } catch (error) { setMessage(error.message, false); }
}

function setMessage(message, success = false) {
    const box = document.getElementById('documentMessage');
    box.textContent = message; box.className = message ? (success ? 'alert success' : 'alert error') : '';
}
function escapeHtml(value) { const node = document.createElement('div'); node.textContent = value ?? ''; return node.innerHTML; }
function formatDateTime(value) { return value ? new Date(value.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : ''; }
