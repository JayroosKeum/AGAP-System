const canManageHearings =
    window.AGAP_HEARINGS?.canManage === true;

document.addEventListener('DOMContentLoaded', () => {
    loadHearings();
    loadDeadlines();

    if (canManageHearings) {
        loadCases();
        bindAjaxForm('addHearingForm', closeAddHearingModal);
        bindAjaxForm('editHearingForm', closeEditHearingModal);
    }
});

async function api(url, options = {}) {
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || result.success === false) throw new Error(result.message || 'Request failed.');
    return result;
}

async function loadHearings() {
    const table = document.getElementById('hearingTable');
    try {
        const result = await api('../../../backend/api/hearings/calendar.php');
        const hearings = result.data || [];
        table.innerHTML = hearings.length ? hearings.map(item => `
            <tr>
                <td>${escapeHtml(item.case_number)}</td>
                <td>${escapeHtml(item.complaint_number)} - ${escapeHtml(item.complaint_title)}</td>
                <td><span class="hearing-type">${escapeHtml(item.hearing_type)}</span></td>
                <td>${formatDateTime(item.hearing_date)}</td>
                <td>${escapeHtml(item.venue)}</td>
                <td class="action-buttons">
                    <button
                        type="button"
                        data-view="${Number(item.hearing_id)}"
                    >
                        View
                    </button>

                    ${canManageHearings ? `
                        <button
                            type="button"
                            data-edit="${Number(item.hearing_id)}"
                        >
                            Edit
                        </button>
                    ` : ''}
                </td>
            </tr>`).join('') : '<tr><td colspan="6" class="empty-state">No hearings scheduled.</td></tr>';

        table.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => viewHearing(button.dataset.view)));
        table.querySelectorAll('[data-edit]').forEach(button => button.addEventListener('click', () => editHearing(button.dataset.edit)));
    } catch (error) {
        table.innerHTML = `<tr><td colspan="6" class="empty-state">${escapeHtml(error.message)}</td></tr>`;
    }
}

async function loadDeadlines() {
    const table = document.getElementById('deadlineTable');
    try {
        const result = await api('../../../backend/api/hearings/deadlines.php');
        const deadlines = result.data || [];
        table.innerHTML = deadlines.length ? deadlines.map(item => `
            <tr><td>${escapeHtml(item.case_number)}</td><td>${escapeHtml(item.deadline_type)}</td>
            <td>${formatDate(item.due_date)}</td><td>${escapeHtml(item.status)}</td></tr>`).join('')
            : '<tr><td colspan="4" class="empty-state">No tracked deadlines.</td></tr>';
    } catch (error) {
        table.innerHTML = `<tr><td colspan="4" class="empty-state">${escapeHtml(error.message)}</td></tr>`;
    }
}

async function loadCases() {
    const select = document.getElementById('hearingCaseId');

    if (!select) {
        return;
    }

    try {
        const response = await fetch(
            '../../../backend/api/cases/list.php'
        );

        const cases = await response.json();

        if (!response.ok) {
            throw new Error(
                cases.message || 'Unable to load cases.'
            );
        }

        select.replaceChildren(
            new Option('Select a case', '')
        );

        cases
            .filter(item => item.case_status !== 'Archived')
            .forEach(item => {
                select.add(
                    new Option(
                        `${item.case_number} - ${item.complaint_title}`,
                        item.case_id
                    )
                );
            });
    } catch (error) {
        select.replaceChildren(
            new Option(error.message, '')
        );
    }
}

function bindAjaxForm(id, onSuccess) {
    const form = document.getElementById(id);

    if (!form) {
        return;
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        setMessage('');
        try {
            const result = await api(form.action, { method: 'POST', body: new FormData(form) });
            setMessage(result.message, true);
            onSuccess();
            form.reset();
            await Promise.all([loadHearings(), loadDeadlines()]);
        } catch (error) {
            setMessage(error.message, false);
        }
    });
}

async function getHearing(id) {
    const result = await api('../../../backend/api/hearings/view.php?id=' + encodeURIComponent(id));
    return result.data;
}

async function viewHearing(id) {
    try {
        const item = await getHearing(id);
        const details = document.getElementById('hearingDetails');
        details.replaceChildren();
        const dl = document.createElement('dl');
        dl.className = 'hearing-details';
        [['Case', item.case_number], ['Complaint', `${item.complaint_number} - ${item.complaint_title}`],
         ['Type', item.hearing_type], ['Date & Time', formatDateTime(item.hearing_date)],
         ['Venue', item.venue], ['Remarks', item.remarks || 'None']].forEach(([label, value]) => {
            const dt = document.createElement('dt'); dt.textContent = label;
            const dd = document.createElement('dd'); dd.textContent = value;
            dl.append(dt, dd);
        });
        details.appendChild(dl);
        showModal('viewHearingModal');
    } catch (error) { setMessage(error.message, false); }
}

async function editHearing(id) {
    try {
        const item = await getHearing(id);
        document.getElementById('editHearingId').value = item.hearing_id;
        document.getElementById('editHearingType').value = item.hearing_type;
        document.getElementById('editHearingDate').value = toDateTimeLocal(item.hearing_date);
        document.getElementById('editHearingVenue').value = item.venue || '';
        document.getElementById('editHearingRemarks').value = item.remarks || '';
        showModal('editHearingModal');
    } catch (error) { setMessage(error.message, false); }
}

function setMessage(message, success = false) {
    const box = document.getElementById('hearingMessage');
    box.textContent = message;
    box.className = message ? (success ? 'alert success' : 'alert error') : '';
}
function escapeHtml(value) { const node = document.createElement('div'); node.textContent = value || ''; return node.innerHTML; }
function formatDate(value) { return value ? new Date(value + 'T00:00:00').toLocaleDateString() : ''; }
function formatDateTime(value) { return value ? new Date(value.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : ''; }
function toDateTimeLocal(value) { return value ? value.replace(' ', 'T').slice(0, 16) : ''; }
function showModal(id) { document.getElementById(id).style.display = 'flex'; }
function hideModal(id) { document.getElementById(id).style.display = 'none'; }
function openAddHearingModal() { showModal('addHearingModal'); }
function closeAddHearingModal() { hideModal('addHearingModal'); }
function closeViewHearingModal() { hideModal('viewHearingModal'); }
function closeEditHearingModal() { hideModal('editHearingModal'); }