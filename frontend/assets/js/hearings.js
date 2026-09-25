const canManageHearings = window.AGAP_HEARINGS?.canManage === true;
let calendarHearings = [];
let calendarCursor = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
let pendingScheduleForm = null;
let currentPage = 1;
const pageSize = 25;

document.addEventListener('DOMContentLoaded', async () => {
    bindCalendarControls();
    bindSearchControls();
    await Promise.all([loadHearings(), loadCombinedRecords(1)]);
    if (!canManageHearings) return;
    await loadCases();
    bindReviewForm('addHearingForm', closeAddHearingModal);
    bindReviewForm('editHearingForm', closeEditHearingModal);
    document.getElementById('confirmHearingSchedule')?.addEventListener('click', confirmHearingSchedule);
    configureCreateDateValidation();

    const params = new URLSearchParams(window.location.search);
    if (params.get('case_id')) {
        openAddHearingModal();
    }
});

async function api(url, options = {}) {
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || result.success === false) throw new Error(result.message || 'Request failed.');
    return result;
}

async function loadHearings() {
    try {
        const result = await api('../../../backend/api/hearings/calendar.php');
        calendarHearings = result.data || [];
        renderCalendar();
        updateNextSchedule();
    } catch (error) {
        console.error('Error loading calendar hearings:', error);
    }
}

async function loadDeadlines() {
    // Kept for backward compatibility if invoked
    return [];
}

async function loadCombinedRecords(page = 1) {
    currentPage = page;
    const table = document.getElementById('combinedTable');
    if (!table) return;

    table.innerHTML = '<tr><td colspan="7" class="empty-state">Loading hearings and deadlines...</td></tr>';

    const q = document.getElementById('searchKeyword')?.value.trim() || '';
    const status = document.getElementById('searchStatus')?.value || '';
    const hearingType = document.getElementById('searchHearingType')?.value || '';
    const dateFrom = document.getElementById('searchDateFrom')?.value || '';
    const dateTo = document.getElementById('searchDateTo')?.value || '';

    const params = new URLSearchParams();
    params.set('page', page);
    if (q) params.set('q', q);
    if (status) params.set('status', status);
    if (hearingType) params.set('hearing_type', hearingType);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);

    try {
        const result = await api('../../../backend/api/hearings/list.php?' + params.toString());
        const records = result.data || [];
        const pagination = result.pagination || { total_records: records.length, per_page: pageSize, current_page: page, total_pages: 1 };

        if (!records.length) {
            table.innerHTML = '<tr><td colspan="7" class="empty-state">No hearings or deadlines found.</td></tr>';
            renderPagination(pagination);
            return;
        }

        table.innerHTML = records.map((item) => `
            <tr>
                <td>${item.case_number ? `<span class="badge-case-docket">${escapeHtml(item.case_number)}</span>` : '<span class="empty-cell">—</span>'}</td>
                <td>${renderComplaintCell(item)}</td>
                <td><span class="hearing-type ${getTypeBadgeClass(item)}">${escapeHtml(item.hearing_type)}</span></td>
                <td>${renderDateTimeCell(item)}</td>
                <td><span class="status-pill-badge ${getStatusBadgeClass(item.status)}">${escapeHtml(item.status)}</span></td>
                <td>${item.venue ? escapeHtml(item.venue) : '<span class="empty-cell">—</span>'}</td>
                <td class="action-buttons">${renderActionsCell(item)}</td>
            </tr>
        `).join('');

        table.querySelectorAll('[data-view]').forEach((button) => button.addEventListener('click', () => viewHearing(button.dataset.view)));
        table.querySelectorAll('[data-edit]').forEach((button) => button.addEventListener('click', () => editHearing(button.dataset.edit)));

        renderPagination(pagination);
    } catch (error) {
        table.innerHTML = `<tr><td colspan="7" class="empty-state">${escapeHtml(error.message)}</td></tr>`;
        renderPagination({ total_records: 0, per_page: pageSize, current_page: 1, total_pages: 1 });
    }
}

function renderComplaintCell(item) {
    const number = item.complaint_number ? escapeHtml(item.complaint_number) : '';
    const title = item.complaint_title ? escapeHtml(item.complaint_title) : '';
    if (!number && !title) return '<span class="empty-cell">—</span>';
    return `<div class="complaint-cell">${number ? `<span class="complaint-no">${number}</span>` : ''}${title ? `<span class="complaint-title">${title}</span>` : ''}</div>`;
}

function renderDateTimeCell(item) {
    if (!item.schedule_date) return '<span class="empty-cell">—</span>';
    const dateObj = new Date(item.schedule_date.replace(' ', 'T'));
    if (isNaN(dateObj.getTime())) {
        return escapeHtml(item.schedule_date);
    }
    const dateStr = dateObj.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
    if (item.record_type === 'deadline') {
        return `<div class="table-datetime"><span class="datetime-date">${escapeHtml(dateStr)}</span></div>`;
    }
    const timeStr = dateObj.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
    return `<div class="table-datetime"><span class="datetime-date">${escapeHtml(dateStr)}</span><span class="datetime-time">${escapeHtml(timeStr)}</span></div>`;
}

function getStatusBadgeClass(status) {
    switch (status) {
        case 'Scheduled': return 'badge-status-scheduled';
        case 'Pending': return 'badge-status-pending';
        case 'Completed': return 'badge-status-completed';
        case 'Overdue': return 'badge-status-overdue';
        default: return 'badge-status-default';
    }
}

function getTypeBadgeClass(item) {
    const type = item.hearing_type || '';
    if (type.includes('Mediation Period') || type.includes('Conciliation Period') || type.includes('Extension')) {
        return 'hearing-type-deadline';
    }
    if (type.includes('Mediation')) return 'hearing-type-mediation';
    if (type.includes('Conciliation')) return 'hearing-type-conciliation';
    return 'hearing-type-default';
}

function renderActionsCell(item) {
    if (item.record_type === 'hearing') {
        const id = Number(item.record_id);
        return `
            <button type="button" class="btn-action-view" data-view="${id}">View</button>
            ${canManageHearings ? `<button type="button" class="btn-action-edit" data-edit="${id}">Edit</button>` : ''}
        `;
    }
    return '<span class="empty-cell">—</span>';
}

function renderPagination(pagination) {
    const container = document.getElementById('hearingPagination');
    const summary = document.getElementById('hearingPaginationSummary');
    const controls = document.getElementById('hearingPaginationControls');
    if (!container || !summary || !controls || !pagination) return;

    const total = Number(pagination.total_records) || 0;
    const limit = Number(pagination.per_page) || pageSize;
    const page = Number(pagination.current_page) || 1;
    const totalPages = Number(pagination.total_pages) || 1;

    if (total === 0) {
        container.style.display = 'none';
        return;
    }

    container.style.display = 'flex';
    const first = total > 0 ? ((page - 1) * limit) + 1 : 0;
    const last = Math.min(page * limit, total);
    summary.textContent = `Showing ${first}–${last} of ${total} record${total === 1 ? '' : 's'}`;

    controls.replaceChildren();

    const createBtn = (label, pageNum, disabled = false, current = false, isNav = false) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `complaints-page-btn ${current ? 'active current' : ''} ${isNav ? 'complaints-page-nav-btn' : ''}`;
        btn.textContent = label;
        btn.disabled = disabled;
        if (current) btn.setAttribute('aria-current', 'page');
        btn.addEventListener('click', () => {
            if (page !== pageNum && !disabled) {
                loadCombinedRecords(pageNum);
                const tableContainer = document.querySelector('.table-container');
                if (tableContainer) {
                    tableContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        });
        return btn;
    };

    // Previous button
    controls.appendChild(createBtn('Previous', page - 1, page <= 1, false, true));

    // Page number buttons
    const maxButtons = 5;
    let startPage = Math.max(1, page - Math.floor(maxButtons / 2));
    let endPage = Math.min(totalPages, startPage + maxButtons - 1);
    if (endPage - startPage < maxButtons - 1) {
        startPage = Math.max(1, endPage - maxButtons + 1);
    }

    if (startPage > 1) {
        controls.appendChild(createBtn('1', 1, false, page === 1));
        if (startPage > 2) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '…';
            controls.appendChild(ellipsis);
        }
    }

    for (let p = startPage; p <= endPage; p++) {
        controls.appendChild(createBtn(String(p), p, false, p === page));
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '…';
            controls.appendChild(ellipsis);
        }
        controls.appendChild(createBtn(String(totalPages), totalPages, false, page === totalPages));
    }

    // Next button
    controls.appendChild(createBtn('Next', page + 1, page >= totalPages, false, true));
}

function bindSearchControls() {
    const form = document.getElementById('hearingsSearchForm');
    const keywordInput = document.getElementById('searchKeyword');
    const clearInputBtn = document.getElementById('clearSearchInput');
    const clearBtn = document.getElementById('btnClearSearch');

    if (keywordInput && clearInputBtn) {
        keywordInput.addEventListener('input', () => {
            clearInputBtn.style.display = keywordInput.value.trim() ? 'block' : 'none';
        });

        clearInputBtn.addEventListener('click', () => {
            keywordInput.value = '';
            clearInputBtn.style.display = 'none';
            loadCombinedRecords(1);
        });
    }

    if (form) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            loadCombinedRecords(1);
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (keywordInput) keywordInput.value = '';
            if (clearInputBtn) clearInputBtn.style.display = 'none';
            const statusSelect = document.getElementById('searchStatus');
            if (statusSelect) statusSelect.value = '';
            const typeSelect = document.getElementById('searchHearingType');
            if (typeSelect) typeSelect.value = '';
            const dateFromInput = document.getElementById('searchDateFrom');
            if (dateFromInput) dateFromInput.value = '';
            const dateToInput = document.getElementById('searchDateTo');
            if (dateToInput) dateToInput.value = '';

            loadCombinedRecords(1);
        });
    }
}

async function loadCases() {
    const select = document.getElementById('hearingCaseId'); if (!select) return;
    try {
        const response = await fetch('../../../backend/api/cases/list.php'); const cases = await response.json();
        if (!response.ok) throw new Error(cases.message || 'Unable to load cases.');
        select.replaceChildren(new Option('Select a case', ''));
        cases.filter((item) => item.case_status !== 'Archived').forEach((item) => { const option = new Option(`${item.case_number} - ${item.complaint_title}`, item.case_id); option.dataset.docketDate = item.docket_date || ''; select.add(option); });
        const params = new URLSearchParams(window.location.search);
        const caseId = params.get('case_id');
        if (caseId && [...select.options].some((option) => option.value === caseId)) select.value = caseId;
        updateNextSchedule();
    } catch (error) { select.replaceChildren(new Option(error.message, '')); }
}

function configureCreateDateValidation() {
    const date = document.getElementById('hearingDate'); const caseSelect = document.getElementById('hearingCaseId');
    if (!date || !caseSelect) return;
    const now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset()); date.min = now.toISOString().slice(0, 16);
    caseSelect.addEventListener('change', updateNextSchedule);
}

function updateNextSchedule() {
    const select = document.getElementById('hearingCaseId'); const type = document.getElementById('hearingType'); const help = document.getElementById('hearingProgressionHelp');
    const submitBtn = document.querySelector('#addHearingForm button[type="submit"]');
    if (!select || !type || !help) return;
    const caseId = select.value;
    type.replaceChildren();
    if (!caseId) {
        type.add(new Option('Select a case first', '')); type.disabled = true;
        help.textContent = 'Select a case to see its next permitted schedule.';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.title = ''; }
        return;
    }
    const hearings = calendarHearings.filter((item) => String(item.case_id) === String(caseId));
    const mediationCount = hearings.filter((item) => item.hearing_type === 'Mediation').length;
    const conciliationCount = hearings.filter((item) => item.hearing_type === 'Conciliation').length;
    let typeValue = ''; let label = '';
    if (mediationCount < 3) { typeValue = 'Mediation'; label = `${ordinal(mediationCount + 1)} Mediation`; }
    else if (conciliationCount < 3) { typeValue = 'Conciliation'; label = `${ordinal(conciliationCount + 1)} Conciliation`; }
    if (!typeValue) {
        type.add(new Option('No further schedules permitted', '')); type.disabled = true;
        help.textContent = 'This case already has three mediation and three conciliation schedules.';
        if (submitBtn) { submitBtn.disabled = true; submitBtn.title = 'All schedules completed for this case.'; }
        return;
    }
    type.add(new Option(label, typeValue)); type.disabled = false;
    help.textContent = `The next permitted schedule is ${label}.`;
    if (submitBtn) { submitBtn.disabled = false; submitBtn.title = ''; }
}

function bindReviewForm(id, onSuccess) {
    const form = document.getElementById(id); if (!form) return;
    form.addEventListener('submit', (event) => { event.preventDefault(); if (!form.reportValidity()) return; pendingScheduleForm = { form, onSuccess }; renderScheduleReview(form); showModal('reviewHearingModal'); });
}

function renderScheduleReview(form) {
    const values = Object.fromEntries(new FormData(form)); const caseLabel = form.querySelector('[name="case_id"]')?.selectedOptions?.[0]?.textContent || 'Current case'; const details = document.getElementById('reviewHearingDetails');
    details.replaceChildren(); [['Case', caseLabel], ['Hearing type', values.hearing_type], ['Date & time', formatDateTime(values.hearing_date)], ['Venue', values.venue], ['Remarks', values.remarks || 'None']].forEach(([label, value]) => { const dt = document.createElement('dt'); dt.textContent = label; const dd = document.createElement('dd'); dd.textContent = value; details.append(dt, dd); });
}

async function confirmHearingSchedule() {
    if (!pendingScheduleForm) return; const { form, onSuccess } = pendingScheduleForm; setMessage('');
    try {
        const data = new FormData(form);
        data.set('schedule_reviewed', '1');
        const result = await api(form.action, { method: 'POST', body: data });
        setMessage(result.message, true);
        closeReviewHearingModal();
        onSuccess();
        form.reset();
        await Promise.all([loadHearings(), loadCombinedRecords(currentPage)]);
    } catch (error) {
        setMessage(error.message);
    } finally {
        pendingScheduleForm = null;
    }
}

async function getHearing(id) { return (await api('../../../backend/api/hearings/view.php?id=' + encodeURIComponent(id))).data; }
async function viewHearing(id) {
    try {
        const item = await getHearing(id);
        const details = document.getElementById('hearingDetails');
        details.replaceChildren();
        [['Case', item.case_number], ['Complaint', `${item.complaint_number} - ${item.complaint_title}`], ['Type', hearingLabel(item)], ['Date & Time', formatDateTime(item.hearing_date)], ['Venue', item.venue], ['Remarks', item.remarks || 'None']].forEach(([label, value]) => {
            const dt = document.createElement('dt'); dt.textContent = label;
            const dd = document.createElement('dd'); dd.textContent = value;
            details.append(dt, dd);
        });
        showModal('viewHearingModal');
    } catch (error) {
        setMessage(error.message);
    }
}

async function editHearing(id) {
    try {
        const item = await getHearing(id);
        document.getElementById('editHearingId').value = item.hearing_id;
        document.getElementById('editHearingType').value = hearingLabel(item);
        document.getElementById('editHearingDate').value = toDateTimeLocal(item.hearing_date);
        document.getElementById('editHearingVenue').value = item.venue || '';
        document.getElementById('editHearingRemarks').value = item.remarks || '';
        showModal('editHearingModal');
    } catch (error) {
        setMessage(error.message);
    }
}

function bindCalendarControls() {
    document.getElementById('previousMonth')?.addEventListener('click', () => { calendarCursor.setMonth(calendarCursor.getMonth() - 1); renderCalendar(); });
    document.getElementById('nextMonth')?.addEventListener('click', () => { calendarCursor.setMonth(calendarCursor.getMonth() + 1); renderCalendar(); });
}

function renderCalendar() {
    const calendar = document.getElementById('hearingCalendar');
    const title = document.getElementById('calendarMonth');
    if (!calendar || !title) return;
    title.textContent = calendarCursor.toLocaleDateString([], { month: 'long', year: 'numeric' });
    const year = calendarCursor.getFullYear();
    const month = calendarCursor.getMonth();
    const firstDay = new Date(year, month, 1).getDay();
    const days = new Date(year, month + 1, 0).getDate();
    const byDay = calendarHearings.reduce((groups, item) => {
        const date = new Date(item.hearing_date.replace(' ', 'T'));
        if (date.getFullYear() === year && date.getMonth() === month) (groups[date.getDate()] ||= []).push(item);
        return groups;
    }, {});
    calendar.replaceChildren();
    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach((day) => {
        const cell = document.createElement('div');
        cell.className = 'calendar-weekday';
        cell.textContent = day;
        calendar.appendChild(cell);
    });
    for (let blank = 0; blank < firstDay; blank += 1) calendar.appendChild(document.createElement('div'));
    for (let day = 1; day <= days; day += 1) {
        const cell = document.createElement('div');
        cell.className = 'calendar-day';
        if (canManageHearings) {
            cell.classList.add('calendar-day-actionable');
            cell.addEventListener('click', () => openCalendarSchedule(year, month, day));
        }
        const number = document.createElement('strong');
        number.textContent = day;
        cell.appendChild(number);
        (byDay[day] || []).forEach((item) => {
            const entry = document.createElement('button');
            entry.type = 'button';
            entry.className = 'calendar-hearing';
            entry.textContent = `${new Date(item.hearing_date.replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })} ${item.case_number}`;
            entry.addEventListener('click', (event) => {
                event.stopPropagation();
                if (canManageHearings) editHearing(item.hearing_id);
                else viewHearing(item.hearing_id);
            });
            cell.appendChild(entry);
        });
        calendar.appendChild(cell);
    }
}

function openCalendarSchedule(year, month, day) {
    const input = document.getElementById('hearingDate');
    if (!input) return;
    const selected = new Date(year, month, day, 9, 0);
    const now = new Date();
    if (selected <= now) {
        setMessage('Choose a future date to schedule a hearing.');
        return;
    }
    selected.setMinutes(selected.getMinutes() - selected.getTimezoneOffset());
    input.value = selected.toISOString().slice(0, 16);
    openAddHearingModal();
}

function setMessage(message, success = false) {
    const box = document.getElementById('hearingMessage');
    if (!box) return;
    box.textContent = message;
    box.className = message ? (success ? 'alert success' : 'alert error') : '';
}

function escapeHtml(value) {
    const node = document.createElement('div');
    node.textContent = value || '';
    return node.innerHTML;
}

function formatDate(value) {
    return value ? new Date(value + 'T00:00:00').toLocaleDateString() : '';
}

function formatDateTime(value) {
    return value ? new Date(value.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '';
}

function toDateTimeLocal(value) {
    return value ? value.replace(' ', 'T').slice(0, 16) : '';
}

function ordinal(number) {
    return number === 1 ? '1st' : (number === 2 ? '2nd' : (number === 3 ? '3rd' : `${number}th`));
}

function hearingLabel(item) {
    if (!['Mediation', 'Conciliation'].includes(item.hearing_type)) return item.hearing_type;
    const sequence = calendarHearings
        .filter((entry) => String(entry.case_id) === String(item.case_id) && entry.hearing_type === item.hearing_type)
        .sort((left, right) => String(left.created_at).localeCompare(String(right.created_at)) || Number(left.hearing_id) - Number(right.hearing_id))
        .findIndex((entry) => String(entry.hearing_id) === String(item.hearing_id)) + 1;
    return sequence > 0 ? `${ordinal(sequence)} ${item.hearing_type}` : item.hearing_type;
}

function showModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}

function hideModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function openAddHearingModal() {
    updateNextSchedule();
    showModal('addHearingModal');
}

function closeAddHearingModal() {
    hideModal('addHearingModal');
}

function closeViewHearingModal() {
    hideModal('viewHearingModal');
}

function closeEditHearingModal() {
    hideModal('editHearingModal');
}

function closeReviewHearingModal() {
    hideModal('reviewHearingModal');
    pendingScheduleForm = null;
}
