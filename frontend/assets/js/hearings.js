const canManageHearings = window.AGAP_HEARINGS?.canManage === true;
let calendarHearings = [];
let calendarCursor = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
let pendingScheduleForm = null;
let currentPage = 1;
const pageSize = 25;
let currentAttendanceFilter = 'all';
let activeHearingAttendanceData = null;

document.addEventListener('DOMContentLoaded', async () => {
    bindCalendarControls();
    bindSearchControls();
    bindAttendanceFilterControls();
    bindAttendanceForm();
    await Promise.all([loadHearings(), loadCombinedRecords(1), loadAttendanceKPIs()]);
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
    if (params.get('hearing_id') && params.get('open_attendance')) {
        editHearing(params.get('hearing_id'));
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

    table.innerHTML = '<tr><td colspan="8" class="empty-state">Loading hearings and deadlines...</td></tr>';

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
    if (currentAttendanceFilter && currentAttendanceFilter !== 'all') {
        params.set('attendance', currentAttendanceFilter);
    }

    try {
        const result = await api('../../../backend/api/hearings/list.php?' + params.toString());
        const records = result.data || [];
        const pagination = result.pagination || { total_records: records.length, per_page: pageSize, current_page: page, total_pages: 1 };

        if (!records.length) {
            table.innerHTML = '<tr><td colspan="8" class="empty-state">No hearings or deadlines found.</td></tr>';
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
                <td>${renderAttendanceCell(item)}</td>
                <td class="action-buttons">${renderActionsCell(item)}</td>
            </tr>
        `).join('');

        table.querySelectorAll('[data-view]').forEach((button) => button.addEventListener('click', () => viewHearing(button.dataset.view)));
        table.querySelectorAll('[data-edit]').forEach((button) => button.addEventListener('click', () => editHearing(button.dataset.edit)));
        table.querySelectorAll('[data-nonappearance]').forEach((button) => button.addEventListener('click', () => openNonappearanceModal(button.dataset.nonappearance)));
        table.querySelectorAll('[data-reissue]').forEach((button) => button.addEventListener('click', () => reissueSummons(button.dataset.reissue)));
        table.querySelectorAll('[data-attendance]').forEach((button) => button.addEventListener('click', () => openHearingAttendanceModal(button.dataset.attendance)));

        renderPagination(pagination);
    } catch (error) {
        table.innerHTML = `<tr><td colspan="8" class="empty-state">${escapeHtml(error.message)}</td></tr>`;
        renderPagination({ total_records: 0, per_page: pageSize, current_page: 1, total_pages: 1 });
    }
}

function renderAttendanceCell(item) {
    if (item.record_type !== 'hearing') {
        return '<span class="empty-cell">—</span>';
    }
    const total = Number(item.attendance_count) || 0;
    const unjustified = Number(item.unjustified_absent_count) || 0;
    const excused = Number(item.excused_count) || 0;
    const present = Number(item.present_count) || 0;

    if (total === 0) {
        return '<span class="badge-attendance-pending">Pending Intake</span>';
    }
    if (unjustified > 0) {
        return `<span class="badge-attendance-unjustified" title="${unjustified} absent party; service status must be reviewed">${unjustified} Absent — service review</span>`;
    }
    if (excused > 0) {
        return `<span class="badge-attendance-excused" title="${excused} party excused / justified absence">${excused} Excused</span>`;
    }
    if (present >= 2) {
        return '<span class="badge-attendance-present" title="Both parties appeared">Both Present</span>';
    }
    return `<span class="badge-attendance-present" title="${present} party present">${present} Present</span>`;
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
            <button type="button" class="btn-action-view" ${canManageHearings ? `data-edit="${id}"` : `data-view="${id}"`}>${canManageHearings ? 'Update Hearing' : 'View'}</button>
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
        cases.filter((item) => item.case_status !== 'Archived').forEach((item) => {
            const option = new Option(`${item.case_number} - ${item.complaint_title}`, item.case_id);
            option.dataset.docketDate = item.docket_date || '';
            option.dataset.caseStatus = item.case_status || '';
            option.dataset.isLapsed = item.mediation_timer?.is_lapsed ? '1' : '0';
            option.dataset.isPaused = item.mediation_timer?.is_paused ? '1' : '0';
            option.dataset.pauseReason = item.mediation_timer?.pause_reason || '';
            select.add(option);
        });
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

    const selectedOption = select.selectedOptions[0];
    const isLapsed = selectedOption?.dataset.isLapsed === '1';
    const isPaused = selectedOption?.dataset.isPaused === '1';
    const pauseReason = selectedOption?.dataset.pauseReason || '';

    const hearings = calendarHearings.filter((item) => String(item.case_id) === String(caseId));
    const mediationCount = hearings.filter((item) => item.hearing_type === 'Mediation').length;
    const conciliationCount = hearings.filter((item) => item.hearing_type === 'Conciliation').length;
    let typeValue = ''; let label = '';

    if (mediationCount < 3) {
        if (isPaused) {
            type.add(new Option('Mediation clock paused', ''));
            type.disabled = true;
            help.innerHTML = `<span style="color: #b91c1c; font-weight: 600;">The mediation clock is currently paused (${escapeHtml(pauseReason || 'Suspension')}).</span> Resume the clock before scheduling a hearing.`;
            if (submitBtn) { submitBtn.disabled = true; submitBtn.title = 'Mediation clock is paused.'; }
            return;
        }

        if (isLapsed) {
            type.add(new Option('Mediation period lapsed', ''));
            type.disabled = true;
            help.innerHTML = `
                <span style="color: #b91c1c; font-weight: 600;">The 15-day statutory mediation period has lapsed for this case.</span>
                Further Punong Barangay mediation is restricted. You must
                <a href="../cases/case-list.php#caseAssignments" style="color: #1e40af; font-weight: 600; text-decoration: underline;">Constitute Pangkat Tagapagkasundo</a>
                or
                <a href="../documents/cfa.php?case_id=${encodeURIComponent(caseId)}" style="color: #991b1b; font-weight: 600; text-decoration: underline;">Issue a CFA</a>.
            `;
            if (submitBtn) { submitBtn.disabled = true; submitBtn.title = 'Mediation period has lapsed for this case.'; }
            return;
        }

        typeValue = 'Mediation';
        label = `${ordinal(mediationCount + 1)} Mediation`;
    }
    else if (conciliationCount < 3) {
        typeValue = 'Conciliation';
        label = `${ordinal(conciliationCount + 1)} Conciliation`;
    }

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
    details.replaceChildren(); [['Case', caseLabel], ['Hearing type', values.hearing_type], ['Date & time', formatDateTime(values.hearing_date)], ['Venue', values.venue], ['Remarks', values.remarks || 'None'], ['Rescheduling reason', values.reschedule_reason || 'Not applicable']].forEach(([label, value]) => { const dt = document.createElement('dt'); dt.textContent = label; const dd = document.createElement('dd'); dd.textContent = value; details.append(dt, dd); });
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
        const absence = item.nonappearance ? `${item.nonappearance.resident_name} (${item.nonappearance.party_type}) - pending action` : 'None recorded';
        [['Case', item.case_number], ['Complaint', `${item.complaint_number} - ${item.complaint_title}`], ['Type', hearingLabel(item)], ['Date & Time', formatDateTime(item.hearing_date)], ['Venue', item.venue], ['Remarks', item.remarks || 'None'], ['Unjustified non-appearance', absence]].forEach(([label, value]) => {
            const dt = document.createElement('dt'); dt.textContent = label;
            const dd = document.createElement('dd'); dd.textContent = value;
            details.append(dt, dd);
        });
        showModal('viewHearingModal');
    } catch (error) {
        setMessage(error.message);
    }
}

async function openNonappearanceModal(id) {
    try {
        const item = await getHearing(id);
        const select = document.getElementById('nonappearanceResident');
        select.replaceChildren(new Option('Select an absent party', ''));
        (item.parties || []).forEach((party) => select.add(new Option(`${party.party_type}: ${party.resident_name}`, party.resident_id)));
        document.getElementById('nonappearanceHearingId').value = item.hearing_id;
        document.getElementById('nonappearanceRemarks').value = '';
        showModal('nonappearanceModal');
    } catch (error) { setMessage(error.message); }
}

async function reissueSummons(id) {
    if (!window.confirm('Issue a follow-up summons for the recorded non-appearance?')) return;
    try {
        const data = new FormData(); data.set('hearing_id', id);
        const result = await api('../../../backend/api/summons/reissue-after-nonappearance.php', { method: 'POST', body: data });
        setMessage(result.message, true);
        await Promise.all([loadHearings(), loadCombinedRecords(currentPage)]);
    } catch (error) { setMessage(error.message); }
}

async function editHearing(id) {
    try {
        const item = await getHearing(id);
        document.getElementById('editHearingId').value = item.hearing_id;
        document.getElementById('editHearingType').value = hearingLabel(item);
        document.getElementById('editHearingTypeValue').value = item.hearing_type;
        document.getElementById('editHearingDate').value = toDateTimeLocal(item.hearing_date);
        document.getElementById('editHearingVenue').value = item.venue || '';
        document.getElementById('editHearingRemarks').value = item.remarks || '';
        document.getElementById('rescheduleReason').value = '';
        await openHearingAttendanceModal(id);
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

function showAttendanceModalAlert(message, isSuccess = false) {
    const topAlert = document.getElementById('attModalAlert');
    const bottomAlert = document.getElementById('attModalBottomAlert');
    const className = isSuccess ? 'alert success' : 'alert error';

    [topAlert, bottomAlert].forEach((el) => {
        if (!el) return;
        el.textContent = message;
        el.className = className;
        el.style.display = message ? 'block' : 'none';
    });

    if (message) {
        const targetAlert = bottomAlert && bottomAlert.offsetParent ? bottomAlert : topAlert;
        if (targetAlert) {
            targetAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
}

function clearAttendanceModalAlert() {
    showAttendanceModalAlert('', false);
}

function setMessage(message, success = false) {
    const box = document.getElementById('hearingMessage');
    if (box) {
        box.textContent = message;
        box.className = message ? (success ? 'alert success' : 'alert error') : '';
    }
    const modal = document.getElementById('editHearingModal');
    if (modal && modal.style.display !== 'none') {
        showAttendanceModalAlert(message, success);
    }
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

function closeNonappearanceModal() { hideModal('nonappearanceModal'); }

function closeHearingAttendanceModal() {
    hideModal('editHearingModal');
    activeHearingAttendanceData = null;
}

function bindAttendanceFilterControls() {
    document.querySelectorAll('.btn-att-filter').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.btn-att-filter').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            currentAttendanceFilter = btn.dataset.attFilter || 'all';
            loadCombinedRecords(1);
        });
    });
}

async function loadAttendanceKPIs() {
    try {
        const res = await api('../../../backend/api/hearings/attendance.php?action=overview');
        const kpis = res.data?.kpis || {
            total_hearings: 0,
            both_present: 0,
            unjustified_absences: 0,
            excused_count: 0,
            pending_count: 0
        };
        const elTotal = document.getElementById('attKpiTotal');
        const elPresent = document.getElementById('attKpiPresent');
        const elUnjustified = document.getElementById('attKpiUnjustified');
        const elExcused = document.getElementById('attKpiExcused');
        const elPending = document.getElementById('attKpiPending');

        if (elTotal) elTotal.textContent = kpis.total_hearings;
        if (elPresent) elPresent.textContent = kpis.both_present;
        if (elUnjustified) elUnjustified.textContent = kpis.unjustified_absences;
        if (elExcused) elExcused.textContent = kpis.excused_count;
        if (elPending) elPending.textContent = kpis.pending_count;
    } catch (err) {
        console.error('Failed to load attendance KPIs:', err);
    }
}

async function openHearingAttendanceModal(hearingId) {
    clearAttendanceModalAlert();
    const container = document.getElementById('attPartiesContainer');
    if (container) {
        container.innerHTML = '<div style="text-align: center; padding: 24px; color: #64748b;">Loading hearing &amp; party records...</div>';
    }
    showModal('editHearingModal');

    try {
        const res = await api(`../../../backend/api/hearings/attendance.php?hearing_id=${encodeURIComponent(hearingId)}`);
        const data = res.data;
        activeHearingAttendanceData = data;

        document.getElementById('attHearingId').value = data.hearing_id;
        document.getElementById('attMetaCaseNumber').textContent = data.case_number || '—';
        document.getElementById('attMetaComplaintTitle').textContent = data.complaint_title || (data.complaint_number ? `Complaint #${data.complaint_number}` : '—');
        document.getElementById('attMetaHearingType').textContent = data.hearing_type || '—';
        document.getElementById('attMetaDateTime').textContent = data.hearing_date ? formatDateTime(data.hearing_date) : '—';
        document.getElementById('attMetaVenue').textContent = data.venue || '—';

        const summonsCount = Number(data.summons_count) || 0;
        document.getElementById('attMetaSummons').textContent = summonsCount > 0
            ? `${summonsCount} Summons${summonsCount > 1 ? 'es' : ''} Issued`
            : 'Initial Notice';

        renderAttendanceParties(data.parties || []);
        evaluateLiveAttendanceSituation();
    } catch (err) {
        if (container) {
            container.innerHTML = `<div class="empty-state" style="color: #dc2626;">Error: ${escapeHtml(err.message)}</div>`;
        }
    }
}

function renderAttendanceParties(parties) {
    const container = document.getElementById('attPartiesContainer');
    if (!container) return;

    if (!parties || !parties.length) {
        container.innerHTML = `
            <div style="background: #fffbeb; border: 1px solid #fef08a; padding: 14px 18px; border-radius: 8px; color: #854d0e; font-size: 0.88rem;">
                <strong>Notice:</strong> No registered complainant or respondent profiles are directly linked to this complaint docket. Ensure parties are registered in the resident registry and linked to the complaint.
            </div>
        `;
        return;
    }

    const docOptions = (activeHearingAttendanceData?.available_documents || []).length
        ? activeHearingAttendanceData.available_documents.map(d => `<option value="${d.document_id}">${escapeHtml(d.template_name)} (#${d.document_id}) - ${escapeHtml(d.service_status)}</option>`).join('')
        : '<option value="">No generated documents found</option>';

    const officerOptions = (activeHearingAttendanceData?.summons_servers || []).length
        ? activeHearingAttendanceData.summons_servers.map(s => `<option value="${s.user_id}">${escapeHtml(s.full_name)}</option>`).join('')
        : '<option value="">Current User / Summons Server</option>';

    container.innerHTML = parties.map((p) => {
        const isComplainant = p.party_type === 'Complainant';
        const isRespondent = p.party_type === 'Respondent';
        const cardClass = isComplainant ? 'card-complainant' : (isRespondent ? 'card-respondent' : '');
        const badgeClass = isComplainant ? 'party-badge-complainant' : (isRespondent ? 'party-badge-respondent' : 'party-badge-witness');

        let currentChoice = '';
        if (p.attendance_status === 'Present') currentChoice = 'Present';
        else if (p.attendance_status === 'Late') currentChoice = 'Late';
        else if (p.attendance_status === 'Not Served') currentChoice = 'Not Served';
        else if (p.attendance_status === 'Excused' || Number(p.is_justified) === 1) currentChoice = 'Excused';
        else if (p.attendance_status === 'Absent') currentChoice = 'Unjustified';

        const isExcused = currentChoice === 'Excused';
        const initialStatus = ['Present', 'Absent', 'Late', 'Excused', 'Not Served'].includes(p.attendance_status)
            ? p.attendance_status
            : (currentChoice === 'Unjustified' ? 'Absent' : currentChoice);
        const historyMarkup = (p.service_history || []).length
            ? p.service_history.map((attempt) => `
                <li style="margin-bottom: 6px;">
                    <strong>${escapeHtml(attempt.service_result)}</strong> · ${escapeHtml(formatDateTime(attempt.service_date))} · Officer: ${escapeHtml(attempt.officer_name || 'Summons Server')} · ${attempt.template_name ? escapeHtml(attempt.template_name) : `Document #${Number(attempt.document_id)}`}
                    <br><strong>Return:</strong> ${escapeHtml(attempt.officer_return || '')}
                    ${attempt.reason ? `<br><span style="color: #b91c1c;">Reason: ${escapeHtml(attempt.reason)}</span>` : ''}
                    ${attempt.supporting_file ? `<br><a href="../../../${escapeHtml(attempt.supporting_file)}" target="_blank" style="color: #0284c7; text-decoration: underline; font-size: 0.78rem;">📎 View Attached Return File</a>` : ''}
                </li>
            `).join('')
            : '<li>No service attempts recorded for this hearing.</li>';

        return `
            <div class="party-att-card ${cardClass}" data-resident-id="${p.resident_id}" data-party-type="${escapeHtml(p.party_type)}" data-party-name="${escapeHtml(p.full_name)}">
                <input type="hidden" class="att-input-status" name="records[${p.resident_id}][status]" value="${escapeHtml(initialStatus)}">
                <input type="hidden" class="att-input-justified" name="records[${p.resident_id}][is_justified]" value="${Number(p.is_justified) === 1 ? '1' : '0'}">

                <div class="party-att-header">
                    <div>
                        <span class="${badgeClass}">${escapeHtml(p.party_type)}</span>
                        <span class="party-att-title" style="margin-left: 6px;">${escapeHtml(p.full_name)}</span>
                        <div class="party-att-address">
                            ${p.purok_name ? `Purok: ${escapeHtml(p.purok_name)}` : ''}${p.address ? ` · ${escapeHtml(p.address)}` : ''}
                        </div>
                    </div>
                </div>

                ${isComplainant || isRespondent ? `
                <div class="hearing-service-panel">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <span class="service-status-badge ${p.service_confirmed ? 'confirmed' : 'unconfirmed'}">
                                ${p.service_confirmed ? '✓ Service Confirmed' : '⚠ Service Not Confirmed'}
                            </span>
                            <span style="font-size: 0.78rem; color: #64748b;">
                                ${(p.service_history || []).length} attempt${(p.service_history || []).length === 1 ? '' : 's'} on record
                            </span>
                        </div>
                        <a href="../gps/proof-service.php?case_id=${encodeURIComponent(activeHearingAttendanceData?.case_id || '')}" target="_blank" style="font-size: 0.78rem; font-weight: 600; color: #0284c7; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; background: #f0f9ff; border: 1px solid #bae6fd; padding: 3px 8px; border-radius: 4px;">
                            Record in Proof of Service &rarr;
                        </a>
                    </div>

                    ${(p.service_history || []).length ? `
                    <details style="margin-top: 6px;">
                        <summary style="font-size: 0.82rem; font-weight: 600; color: #334155; cursor: pointer;">
                            Service History &amp; Officer’s Returns (${p.service_history.length})
                        </summary>
                        <ul class="party-history-list" style="margin-top: 6px;">
                            ${historyMarkup}
                        </ul>
                    </details>
                    ` : ''}
                </div>
                ` : ''}

                <div class="att-status-pills">
                    <button type="button" class="att-status-pill-btn ${currentChoice === 'Present' ? 'selected-present' : ''}" data-choice="Present" ${!canManageHearings ? 'disabled' : ''}>
                        Present
                    </button>
                    <button type="button" class="att-status-pill-btn ${currentChoice === 'Unjustified' ? 'selected-unjustified' : ''}" data-choice="Unjustified" ${!canManageHearings || (!p.service_confirmed && (isComplainant || isRespondent)) ? 'disabled title="Confirm service for this party before marking Failure to Appear. Select Not Served if party could not be served."' : ''}>
                        ${p.service_confirmed ? 'Failure to Appear' : 'Failure to Appear (Unverified)'}
                    </button>
                    <button type="button" class="att-status-pill-btn ${currentChoice === 'Not Served' ? 'selected-not-served' : ''}" data-choice="Not Served" ${!canManageHearings ? 'disabled' : ''}>
                        Not Served
                    </button>
                    <button type="button" class="att-status-pill-btn ${currentChoice === 'Excused' ? 'selected-excused' : ''}" data-choice="Excused" ${!canManageHearings ? 'disabled' : ''}>
                        Excused / Justified
                    </button>
                    <button type="button" class="att-status-pill-btn ${currentChoice === 'Late' ? 'selected-late' : ''}" data-choice="Late" ${!canManageHearings ? 'disabled' : ''}>
                        Late Appearance
                    </button>
                </div>

                <div class="att-justification-box" id="justBox_${p.resident_id}" style="${isExcused ? '' : 'display: none;'}">
                    <label for="justReason_${p.resident_id}">Justification Cause <span class="required-mark">*</span></label>
                    <select id="justReason_${p.resident_id}" name="records[${p.resident_id}][justification_reason]" class="form-control" style="width: 100%; font-size: 0.84rem; padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px;" ${!canManageHearings ? 'disabled' : ''}>
                        <option value="Medical Emergency / Illness" ${p.justification_reason === 'Medical Emergency / Illness' ? 'selected' : ''}>Medical Emergency / Illness</option>
                        <option value="Official Duty / Employment Obligation" ${p.justification_reason === 'Official Duty / Employment Obligation' ? 'selected' : ''}>Official Duty / Employment Obligation</option>
                        <option value="Force Majeure / Calamity / Severe Weather" ${p.justification_reason === 'Force Majeure / Calamity / Severe Weather' ? 'selected' : ''}>Force Majeure / Calamity / Severe Weather</option>
                        <option value="Bereavement / Family Emergency" ${p.justification_reason === 'Bereavement / Family Emergency' ? 'selected' : ''}>Bereavement / Family Emergency</option>
                        <option value="Other Justified Cause" ${p.justification_reason && !['Medical Emergency / Illness', 'Official Duty / Employment Obligation', 'Force Majeure / Calamity / Severe Weather', 'Bereavement / Family Emergency'].includes(p.justification_reason) ? 'selected' : ''}>Other Justified Cause</option>
                    </select>
                </div>

                <div class="att-remarks-box">
                    <input type="text" name="records[${p.resident_id}][remarks]" value="${escapeHtml(p.remarks || '')}" placeholder="Appearance remarks or incident notes (optional)..." style="width: 100%; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 4px; padding: 5px 8px;" ${!canManageHearings ? 'readonly' : ''}>
                </div>

                ${(isComplainant || isRespondent) && canManageHearings ? `
                <details class="post-absence-box" style="margin-top: 10px;">
                    <summary style="font-size: 0.85rem; font-weight: 700; color: #1e293b; cursor: pointer;">
                        ⚙️ Nonappearance &amp; Legal Actions Workflow (${escapeHtml(p.party_type)})
                    </summary>
                    <div style="margin-top: 10px;">
                        <div class="post-absence-tabs">
                            <button type="button" class="post-absence-tab-btn active" data-tab-target="tabNotice_${p.resident_id}">1. Issue Notice (${isComplainant ? 'KP Form 18' : 'KP Form 19'})</button>
                            <button type="button" class="post-absence-tab-btn" data-tab-target="tabExplanation_${p.resident_id}">2. Record Explanation</button>
                            <button type="button" class="post-absence-tab-btn" data-tab-target="tabLegal_${p.resident_id}">3. Legal Action Review</button>
                        </div>

                        <!-- Tab 1: Issue Notice of Hearing -->
                        <div id="tabNotice_${p.resident_id}" class="workflow-tab-content" style="display: block;">
                            <div style="font-size: 0.82rem; color: #475569; margin-bottom: 8px;">
                                Issue official notice to explain failure to appear (${isComplainant ? 'KP Form 18' : 'KP Form 19'}) and assign to Summons Server.
                            </div>
                            <div class="workflow-form-grid">
                                <div>
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #334155;">Explanation Hearing Date &amp; Time:</label>
                                    <input type="datetime-local" class="form-control" data-notice-date style="width: 100%; font-size: 0.82rem; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                </div>
                                <div>
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #334155;">Assign to Summons Server:</label>
                                    <select class="form-control" data-notice-server style="width: 100%; font-size: 0.82rem; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                        ${officerOptions}
                                    </select>
                                </div>
                                <button type="button" class="btn-create" data-issue-notice="${p.resident_id}" style="margin-top: 4px;">
                                    Generate ${isComplainant ? 'KP Form 18' : 'KP Form 19'} &amp; Assign Server
                                </button>
                                <div class="notice-download-container" style="margin-top: 6px;"></div>
                            </div>
                        </div>

                        <!-- Tab 2: Record Explanation Hearing Outcome -->
                        <div id="tabExplanation_${p.resident_id}" class="workflow-tab-content" style="display: none;">
                            <div style="font-size: 0.82rem; color: #475569; margin-bottom: 8px;">
                                Record the explanation hearing result. If Justified, the original hearing record is preserved and eligible for rescheduling.
                            </div>
                            <div class="workflow-form-grid">
                                <div>
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #334155;">Explanation Statement:</label>
                                    <textarea data-explanation-text rows="2" placeholder="Statement of reasons for non-appearance..." style="width: 100%; font-size: 0.82rem; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;"></textarea>
                                </div>
                                <div>
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #334155;">Finding / Outcome:</label>
                                    <select data-explanation-outcome class="form-control" style="width: 100%; font-size: 0.82rem; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                        <option value="Pending">Pending Review</option>
                                        <option value="Justified">Justified (Excused / Reschedulable)</option>
                                        <option value="Unjustified">Unjustified (Subject to Statutory Action)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #334155;">Supporting File (Medical cert, etc.):</label>
                                    <input type="file" data-explanation-file accept=".pdf,.jpg,.jpeg,.png" style="font-size: 0.8rem;">
                                </div>
                                <button type="button" class="btn-create" data-save-explanation="${p.resident_id}" style="margin-top: 4px;">
                                    Save Explanation Finding
                                </button>
                            </div>
                        </div>

                        <!-- Tab 3: Authorized Legal Action Review -->
                        <div id="tabLegal_${p.resident_id}" class="workflow-tab-content" style="display: none;">
                            <div class="human-review-callout">
                                ⚖️ <strong>Human Review Required:</strong> AGAP never automatically imposes legal consequences. An authorized official must review and approve adverse certifications or court transmittals.
                            </div>
                            <div class="workflow-form-grid">
                                <div>
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #334155;">Proposed Legal Action:</label>
                                    <select data-legal-action-type class="form-control" style="width: 100%; font-size: 0.82rem; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                        ${isRespondent ? `
                                        <option value="KP Form 22 - Certificate to Bar Counterclaim">KP Form 22 - Certificate to Bar Counterclaim</option>
                                        <option value="Indirect Contempt Certification to Court">Indirect Contempt Certification to Court (MTC)</option>
                                        ` : `
                                        <option value="KP Form 21 - Certificate to Bar Action">KP Form 21 - Certificate to Bar Action / Complaint Dismissal</option>
                                        `}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #334155;">Review Decision:</label>
                                    <select data-legal-action-status class="form-control" style="width: 100%; font-size: 0.82rem; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                        <option value="Approved">Approved (Generate Certificate / Execute Action)</option>
                                        <option value="Pending Review">Pending Review</option>
                                        <option value="Rejected">Rejected</option>
                                        <option value="Recorded">Recorded Only</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #334155;">Review Findings &amp; Rationale:</label>
                                    <textarea data-legal-action-details rows="2" placeholder="Record official legal review rationale..." style="width: 100%; font-size: 0.82rem; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;"></textarea>
                                </div>
                                <button type="button" class="btn-create" data-save-legal-action="${p.resident_id}" style="margin-top: 4px;">
                                    Record Authorized Legal Review
                                </button>
                            </div>
                        </div>
                    </div>
                </details>
                ` : ''}

                ${(p.explanations || []).length ? `
                <div style="margin-top: 8px; font-size: 0.8rem; background: #f1f5f9; padding: 8px 12px; border-radius: 6px;">
                    <strong>Recorded Explanations:</strong>
                    <ul class="party-history-list">
                        ${p.explanations.map(e => `
                            <li>
                                <span class="badge ${e.outcome === 'Justified' ? 'badge-success' : (e.outcome === 'Unjustified' ? 'badge-danger' : 'badge-secondary')}">${escapeHtml(e.outcome)}</span>
                                ${e.decided_at ? ` · Decided ${escapeHtml(formatDateTime(e.decided_at))} by ${escapeHtml(e.decided_by_name || 'Lupon')}` : ''}
                                <br>${escapeHtml(e.explanation)}
                                ${e.supporting_file ? `<br><a href="../../../${escapeHtml(e.supporting_file)}" target="_blank" style="color: #0284c7; text-decoration: underline;">📎 View Attached Explanation File</a>` : ''}
                            </li>
                        `).join('')}
                    </ul>
                </div>
                ` : ''}

                ${(p.legal_actions || []).length ? `
                <div style="margin-top: 8px; font-size: 0.8rem; background: #fef2f2; border: 1px solid #fee2e2; padding: 8px 12px; border-radius: 6px;">
                    <strong>Authorized Legal Actions:</strong>
                    <ul class="party-history-list">
                        ${p.legal_actions.map(a => `
                            <li>
                                <strong>${escapeHtml(a.action_type)}</strong>
                                <span class="badge ${a.status === 'Approved' ? 'badge-danger' : 'badge-secondary'}">${escapeHtml(a.status)}</span>
                                ${a.reviewed_at ? ` · Reviewed ${escapeHtml(formatDateTime(a.reviewed_at))} by ${escapeHtml(a.reviewed_by_name || 'Authorized Officer')}` : ''}
                                <br>${escapeHtml(a.details)}
                            </li>
                        `).join('')}
                    </ul>
                </div>
                ` : ''}
            </div>
        `;
    }).join('');

    // Tab switching in post-absence workflow box
    container.querySelectorAll('.post-absence-tab-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const parent = btn.closest('.post-absence-box');
            parent.querySelectorAll('.post-absence-tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const targetId = btn.dataset.tabTarget;
            parent.querySelectorAll('.workflow-tab-content').forEach(c => c.style.display = 'none');
            const target = parent.querySelector(`#${targetId}`);
            if (target) target.style.display = 'block';
        });
    });

    // Issue Notice of Hearing (KP Form 18/19)
    container.querySelectorAll('[data-issue-notice]').forEach((button) => {
        button.addEventListener('click', async () => {
            const card = button.closest('.party-att-card');
            const resId = button.dataset.issueNotice;
            const hearingId = document.getElementById('attHearingId').value;
            const expDate = card.querySelector('[data-notice-date]')?.value;
            const assignedServer = card.querySelector('[data-notice-server]')?.value;

            button.disabled = true;
            button.textContent = 'Generating...';
            try {
                const fd = new FormData();
                fd.set('action', 'issue_notice');
                fd.set('hearing_id', hearingId);
                fd.set('resident_id', resId);
                if (expDate) fd.set('explanation_date', expDate);
                if (assignedServer) fd.set('assigned_server_id', assignedServer);

                const result = await api('../../../backend/api/hearings/workflow.php', { method: 'POST', body: fd });
                setMessage(result.message, true);
                if (result.download_url) {
                    const downloadContainer = card.querySelector('.notice-download-container');
                    if (downloadContainer) {
                        downloadContainer.innerHTML = `<a href="${result.download_url}" target="_blank" class="btn-att-action btn-att-action-primary" style="display:inline-flex;margin-top:6px;">📥 Download Generated ${escapeHtml(result.form_code || 'Notice')} (PDF)</a>`;
                    }
                }
                await openHearingAttendanceModal(hearingId);
            } catch (error) {
                setMessage(error.message);
                button.disabled = false;
                button.textContent = 'Generate Notice & Assign Server';
            }
        });
    });

    // Record Explanation
    container.querySelectorAll('[data-save-explanation]').forEach((button) => {
        button.addEventListener('click', async () => {
            const card = button.closest('.party-att-card');
            const resId = button.dataset.saveExplanation;
            const hearingId = document.getElementById('attHearingId').value;
            const text = card.querySelector('[data-explanation-text]')?.value?.trim() || '';
            const outcome = card.querySelector('[data-explanation-outcome]')?.value || 'Pending';
            const file = card.querySelector('[data-explanation-file]')?.files[0];

            if (!text) { setMessage('Please enter the explanation statement.'); return; }

            button.disabled = true;
            button.textContent = 'Saving...';
            try {
                const fd = new FormData();
                fd.set('action', 'explanation');
                fd.set('hearing_id', hearingId);
                fd.set('resident_id', resId);
                fd.set('explanation', text);
                fd.set('outcome', outcome);
                if (file) fd.set('supporting_file', file);

                const result = await api('../../../backend/api/hearings/workflow.php', { method: 'POST', body: fd });
                setMessage(result.message, true);
                await openHearingAttendanceModal(hearingId);
                await loadCombinedRecords(currentPage);
            } catch (error) {
                setMessage(error.message);
                button.disabled = false;
                button.textContent = 'Save Explanation Finding';
            }
        });
    });

    // Record Legal Action Review
    container.querySelectorAll('[data-save-legal-action]').forEach((button) => {
        button.addEventListener('click', async () => {
            const card = button.closest('.party-att-card');
            const resId = button.dataset.saveLegalAction;
            const hearingId = document.getElementById('attHearingId').value;
            const type = card.querySelector('[data-legal-action-type]')?.value || '';
            const status = card.querySelector('[data-legal-action-status]')?.value || 'Pending Review';
            const details = card.querySelector('[data-legal-action-details]')?.value?.trim() || '';

            if (!details) { setMessage('Please provide official findings/rationale for this legal action.'); return; }

            button.disabled = true;
            button.textContent = 'Recording...';
            try {
                const fd = new FormData();
                fd.set('action', 'legal_action');
                fd.set('hearing_id', hearingId);
                fd.set('resident_id', resId);
                fd.set('action_type', type);
                fd.set('status', status);
                fd.set('details', details);

                const result = await api('../../../backend/api/hearings/workflow.php', { method: 'POST', body: fd });
                setMessage(result.message, true);
                await openHearingAttendanceModal(hearingId);
                await loadCombinedRecords(currentPage);
            } catch (error) {
                setMessage(error.message);
                button.disabled = false;
                button.textContent = 'Record Authorized Legal Review';
            }
        });
    });

    // Pill Button Clicks
    container.querySelectorAll('.party-att-card').forEach((card) => {
        const resId = card.dataset.residentId;
        const statusInput = card.querySelector('.att-input-status');
        const justifiedInput = card.querySelector('.att-input-justified');
        const justBox = card.querySelector(`#justBox_${resId}`);

        card.querySelectorAll('.att-status-pill-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!canManageHearings) return;
                const choice = btn.dataset.choice;

                // Reset error highlight on selection
                card.style.border = '';

                // Toggle selection
                card.querySelectorAll('.att-status-pill-btn').forEach((b) => {
                    b.classList.remove('selected-present', 'selected-unjustified', 'selected-excused', 'selected-late', 'selected-not-served');
                });

                if (choice === 'Present') {
                    btn.classList.add('selected-present');
                    statusInput.value = 'Present';
                    justifiedInput.value = '0';
                    if (justBox) justBox.style.display = 'none';
                } else if (choice === 'Unjustified') {
                    btn.classList.add('selected-unjustified');
                    statusInput.value = 'Absent';
                    justifiedInput.value = '0';
                    if (justBox) justBox.style.display = 'none';
                } else if (choice === 'Not Served') {
                    btn.classList.add('selected-not-served');
                    statusInput.value = 'Not Served';
                    justifiedInput.value = '0';
                    if (justBox) justBox.style.display = 'none';
                } else if (choice === 'Excused') {
                    btn.classList.add('selected-excused');
                    statusInput.value = 'Absent';
                    justifiedInput.value = '1';
                    if (justBox) justBox.style.display = 'block';
                } else if (choice === 'Late') {
                    btn.classList.add('selected-late');
                    statusInput.value = 'Late';
                    justifiedInput.value = '0';
                    if (justBox) justBox.style.display = 'none';
                }

                evaluateLiveAttendanceSituation();
            });
        });
    });
}

function evaluateLiveAttendanceSituation() {
    const cardContainer = document.getElementById('attPartiesContainer');
    const situationBox = document.getElementById('attSituationCard');
    const iconEl = document.getElementById('attSituationIcon');
    const badgeTextEl = document.getElementById('attSituationBadgeText');
    const refEl = document.getElementById('attSituationRef');
    const consequencesListEl = document.getElementById('attConsequencesList');
    const recommendationTextEl = document.getElementById('attRecommendationText');
    const shortcutsEl = document.getElementById('attActionShortcuts');

    if (!situationBox || !cardContainer) return;

    const cards = cardContainer.querySelectorAll('.party-att-card');
    if (!cards.length) {
        situationBox.className = 'att-situation-box sit-neutral';
        iconEl.textContent = '⚖️';
        badgeTextEl.textContent = 'No Parties Found';
        refEl.textContent = 'R.A. 7160';
        consequencesListEl.innerHTML = '<li>Complaint parties must be registered in the resident database.</li>';
        recommendationTextEl.textContent = 'Verify complaint parties before proceeding.';
        shortcutsEl.innerHTML = '';
        return;
    }

    let complainantCount = 0;
    let complainantPresent = 0;
    let complainantUnjustified = 0;
    let complainantExcused = 0;
    let complainantLate = 0;
    let complainantNotServed = 0;

    let respondentCount = 0;
    let respondentPresent = 0;
    let respondentUnjustified = 0;
    let respondentExcused = 0;
    let respondentLate = 0;
    let respondentNotServed = 0;

    cards.forEach((card) => {
        const type = card.dataset.partyType;
        const status = card.querySelector('.att-input-status')?.value || '';
        const isJustified = card.querySelector('.att-input-justified')?.value === '1';

        if (type === 'Complainant') {
            complainantCount++;
            if (status === 'Present') complainantPresent++;
            else if (status === 'Late') complainantLate++;
            else if (status === 'Not Served') complainantNotServed++;
            else if (status === 'Absent' && isJustified) complainantExcused++;
            else if (status === 'Absent' && !isJustified) complainantUnjustified++;
        } else if (type === 'Respondent') {
            respondentCount++;
            if (status === 'Present') respondentPresent++;
            else if (status === 'Late') respondentLate++;
            else if (status === 'Not Served') respondentNotServed++;
            else if (status === 'Absent' && isJustified) respondentExcused++;
            else if (status === 'Absent' && !isJustified) respondentUnjustified++;
        }
    });

    const summonsCount = Number(activeHearingAttendanceData?.summons_count) || 1;
    const caseId = activeHearingAttendanceData?.case_id;
    const hearingId = activeHearingAttendanceData?.hearing_id;

    situationBox.className = 'att-situation-box';
    situationBox.style.display = '';

    // If completely unrecorded
    if (complainantPresent === 0 && complainantUnjustified === 0 && complainantExcused === 0 && complainantLate === 0 && complainantNotServed === 0
        && respondentPresent === 0 && respondentUnjustified === 0 && respondentExcused === 0 && respondentLate === 0 && respondentNotServed === 0) {
        situationBox.classList.add('sit-neutral');
        iconEl.textContent = '⚖️';
        badgeTextEl.textContent = 'Pending Appearance Intake';
        refEl.textContent = 'R.A. 7160 Sec. 415';
        consequencesListEl.innerHTML = '<li>Record attendance for Complainant and Respondent to determine statutory proceedings.</li>';
        recommendationTextEl.textContent = 'Select appearance status for each party above.';
        shortcutsEl.innerHTML = '';
        return;
    }

    // Situation: Both Not Served
    if (complainantNotServed > 0 && respondentNotServed > 0) {
        situationBox.classList.add('sit-warning');
        iconEl.textContent = '⚠️';
        badgeTextEl.textContent = 'Both Parties Not Served — Re-issue Notices / Summonses';
        refEl.textContent = 'R.A. 7160 Sec. 410(b)';
        consequencesListEl.innerHTML = `
            <li>Neither the Complainant nor Respondent has verified service of hearing notices.</li>
            <li>Failure to appear cannot be recorded, and no statutory sanctions or dismissal may apply without verified service.</li>
            <li>Summons Server must attempt service again or verify addresses with the Lupong Tagapamayapa.</li>
        `;
        recommendationTextEl.textContent = 'Verify addresses and re-issue notices for a reset appearance date.';
        shortcutsEl.innerHTML = canManageHearings ? `<button type="button" class="btn-att-action btn-att-action-primary" onclick="closeHearingAttendanceModal(); editHearing(${hearingId});">Reschedule Hearing</button>` : '';
        return;
    }

    // Situation: Respondent Not Served
    if (respondentNotServed > 0) {
        situationBox.classList.add('sit-warning');
        iconEl.textContent = '⚠️';
        badgeTextEl.textContent = 'Respondent Not Served — Failure to Appear Cannot Be Imposed';
        refEl.textContent = 'R.A. 7160 Sec. 410(b)';
        consequencesListEl.innerHTML = `
            <li>Summons has not been successfully served to the respondent for this hearing.</li>
            <li>Under KP Law, no statutory sanctions, CFA, or counterclaim bars may be imposed without verified service.</li>
            <li>Review the Officer’s Return reasons (e.g. wrong address, not found) and re-dispatch the Summons Server.</li>
        `;
        recommendationTextEl.textContent = 'Re-issue summons with updated address/purok and reschedule appearance date.';
        shortcutsEl.innerHTML = canManageHearings ? `<button type="button" class="btn-att-action btn-att-action-primary" onclick="closeHearingAttendanceModal(); editHearing(${hearingId});">Reschedule Hearing</button>` : '';
        return;
    }

    // Situation: Complainant Not Served
    if (complainantNotServed > 0) {
        situationBox.classList.add('sit-warning');
        iconEl.textContent = '⚠️';
        badgeTextEl.textContent = 'Complainant Not Served — Complaint Cannot Be Dismissed';
        refEl.textContent = 'Katarungang Pambarangay Rules';
        consequencesListEl.innerHTML = `
            <li>Notice of hearing was not successfully served to the complainant.</li>
            <li>Complaint cannot be dismissed for failure to prosecute without verified service.</li>
            <li>Verify complainant contact details and serve notice for the reset session.</li>
        `;
        recommendationTextEl.textContent = 'Verify complainant contact information and re-issue notice.';
        shortcutsEl.innerHTML = canManageHearings ? `<button type="button" class="btn-att-action btn-att-action-primary" onclick="closeHearingAttendanceModal(); editHearing(${hearingId});">Reschedule Hearing</button>` : '';
        return;
    }

    // 1. Both Absent (Unjustified)
    if (complainantUnjustified > 0 && respondentUnjustified > 0) {
        situationBox.classList.add('sit-danger');
        iconEl.textContent = '❌';
        badgeTextEl.textContent = 'Both Parties Absent (Unjustified) — Dismissal Without Prejudice';
        refEl.textContent = 'R.A. 7160 Sec. 415 / KP Rules';
        consequencesListEl.innerHTML = `
            <li>Neither Complainant nor Respondent appeared without justifiable cause despite verified service.</li>
            <li>Dispute is dismissed without prejudice for mutual non-appearance and lack of interest.</li>
            <li>Parties are not barred from filing in the future, but the current docket is closed.</li>
        `;
        recommendationTextEl.textContent = 'Dismiss complaint without prejudice and archive proceedings. Parties must re-file to pursue claims.';
        shortcutsEl.innerHTML = caseId ? `<a href="../cases/case-list.php" class="btn-att-action btn-att-action-danger" target="_blank">Open Cases &amp; Assignments &rarr;</a>` : '';
        return;
    }

    // 2. Complainant Absent (Unjustified)
    if (complainantUnjustified > 0) {
        situationBox.classList.add('sit-danger');
        iconEl.textContent = '🛑';
        badgeTextEl.textContent = 'Complainant Absent (Unjustified) — Dismissal & Judicial Recourse Barred';
        refEl.textContent = 'R.A. 7160 Sec. 415 / KP Rule VI Sec. 8';
        consequencesListEl.innerHTML = `
            <li>Complainant failed to appear at the scheduled session without justifiable cause despite verified service.</li>
            <li><strong>STATUTORY BAR:</strong> Complainant is legally barred from filing this complaint in court or seeking judicial recourse.</li>
            <li>The complaint is subject to dismissal for failure to prosecute. Issue KP Form 18 Notice to Explain.</li>
            <li>Respondent is entitled to a Certificate to Bar Action (KP Form 21) upon authorized human review.</li>
        `;
        recommendationTextEl.textContent = 'Issue KP Form 18 Notice. Following explanation hearing, review dismissal and Certificate to Bar Action.';
        shortcutsEl.innerHTML = caseId ? `<a href="../cases/case-list.php" class="btn-att-action btn-att-action-danger" target="_blank">Open Cases &amp; Assignments &rarr;</a>` : '';
        return;
    }

    // 3. Respondent Absent (Unjustified)
    if (respondentUnjustified > 0) {
        if (summonsCount <= 1) {
            situationBox.classList.add('sit-warning');
            iconEl.textContent = '⚠️';
            badgeTextEl.textContent = 'Respondent Absent (1st Notice) — Issue KP Form 19 Notice to Explain';
            refEl.textContent = 'R.A. 7160 Sec. 410 / KP Form 19';
            consequencesListEl.innerHTML = `
                <li>First unjustified non-appearance of Respondent after verified service of summons.</li>
                <li>Issue Notice of Hearing for Failure to Appear (KP Form 19) to require respondent to explain absence.</li>
                <li>A 2nd Summons (KP Form 9) must be issued with statutory warning of Indirect Contempt (Sec. 515) and bar from filing counterclaims (Sec. 415).</li>
            `;
            recommendationTextEl.textContent = 'Issue KP Form 19 Notice to Explain, and reset hearing date for 2nd Mediation session.';
            shortcutsEl.innerHTML = `
                ${caseId ? `<a href="../documents/summons.php?case_id=${encodeURIComponent(caseId)}" class="btn-att-action btn-att-action-primary" target="_blank">Generate 2nd Summons (KP Form 9) &rarr;</a>` : ''}
                ${canManageHearings ? `<button type="button" class="btn-att-action btn-att-action-secondary" onclick="closeHearingAttendanceModal(); editHearing(${hearingId});">Reschedule Hearing</button>` : ''}
            `;
        } else if (summonsCount === 2) {
            situationBox.classList.add('sit-warning');
            iconEl.textContent = '⚠️';
            badgeTextEl.textContent = 'Respondent Absent (2nd Notice) — Issue 3rd & Final Summons with Warning';
            refEl.textContent = 'R.A. 7160 Sec. 410 / KP Form 9';
            consequencesListEl.innerHTML = `
                <li>Second unjustified non-appearance despite two summons attempts.</li>
                <li>A 3rd and final Summons (KP Form 9) must be issued — last notice before sanctions and bar apply.</li>
                <li>Warn Respondent: failure on 3rd notice leads to Indirect Contempt (Sec. 515) and bar from counterclaims (Sec. 415).</li>
            `;
            recommendationTextEl.textContent = 'Reschedule hearing and issue 3rd (final) Summons with stern statutory warning.';
            shortcutsEl.innerHTML = `
                ${caseId ? `<a href="../documents/summons.php?case_id=${encodeURIComponent(caseId)}" class="btn-att-action btn-att-action-primary" target="_blank">Generate 3rd Summons (KP Form 9) &rarr;</a>` : ''}
                ${canManageHearings ? `<button type="button" class="btn-att-action btn-att-action-secondary" onclick="closeHearingAttendanceModal(); editHearing(${hearingId});">Reschedule Hearing</button>` : ''}
            `;
        } else {
            situationBox.classList.add('sit-danger');
            iconEl.textContent = '🚫';
            badgeTextEl.textContent = 'Respondent Repeated Non-Appearance — Barred Counterclaim & Issue CFA';
            refEl.textContent = 'R.A. 7160 Sec. 415 & Sec. 515 / KP Form 20 & 22';
            consequencesListEl.innerHTML = `
                <li>Respondent repeatedly and unjustifiably failed to appear despite all summonses (${summonsCount} issued).</li>
                <li><strong>STATUTORY BAR:</strong> Respondent is legally barred from filing any counterclaim arising from this dispute in court (KP Form 22).</li>
                <li>Complainant is entitled to an immediate Certificate to File Action (CFA - KP Form 20) permitting direct court filing.</li>
                <li>Punong Barangay / Lupon may certify Respondent to the Municipal Trial Court (MTC) for Indirect Contempt of Court.</li>
            `;
            recommendationTextEl.textContent = 'Review and approve KP Form 22 (Bar Counterclaim) and issue CFA (KP Form 20) to Complainant.';
            shortcutsEl.innerHTML = `
                ${caseId ? `<a href="../documents/cfa.php?case_id=${encodeURIComponent(caseId)}" class="btn-att-action btn-att-action-danger" target="_blank">Generate Certificate to File Action (CFA) &rarr;</a>` : ''}
                ${caseId ? `<a href="../cases/case-list.php" class="btn-att-action btn-att-action-secondary" target="_blank">Open Cases &amp; Assignments</a>` : ''}
            `;
        }
        return;
    }

    // 4. Excused / Justified Absence
    if (complainantExcused > 0 || respondentExcused > 0) {
        situationBox.classList.add('sit-info');
        iconEl.textContent = 'ℹ️';
        badgeTextEl.textContent = 'Excused / Justified Absence — Reset Without Sanction';
        refEl.textContent = 'R.A. 7160 Sec. 410 & KP Rule VI';
        consequencesListEl.innerHTML = `
            <li>Party absence is verified as justified (medical emergency, official duty, force majeure).</li>
            <li>No adverse sanctions, CFA, or counter-claim bars apply to the excused party.</li>
            <li>The original hearing record is preserved and the dispute remains eligible for amicable conciliation.</li>
        `;
        recommendationTextEl.textContent = 'Reschedule hearing to the next available date. Log justified suspension on mediation clock if needed.';
        shortcutsEl.innerHTML = `
            ${canManageHearings ? `<button type="button" class="btn-att-action btn-att-action-primary" onclick="closeHearingAttendanceModal(); editHearing(${hearingId});">Reschedule Hearing</button>` : ''}
            ${caseId ? `<a href="../cases/case-list.php" class="btn-att-action btn-att-action-secondary" target="_blank">Open Cases &amp; Assignments &rarr;</a>` : ''}
        `;
        return;
    }

    // 5. Late Appearance
    if (complainantLate > 0 || respondentLate > 0) {
        situationBox.classList.add('sit-info');
        iconEl.textContent = '⏱️';
        badgeTextEl.textContent = 'Late Appearance Noted — Hearing In Progress';
        refEl.textContent = 'KP Procedural Rules';
        consequencesListEl.innerHTML = `
            <li>Party arrived after scheduled time, but parties are now present.</li>
            <li>Session is authorized to proceed or be briefly adjourned to accommodate dialogue.</li>
        `;
        recommendationTextEl.textContent = 'Continue mediation session. Remind parties of prompt punctuality.';
        shortcutsEl.innerHTML = caseId ? `<a href="../cases/case-list.php" class="btn-att-action btn-att-action-secondary" target="_blank">Open Cases &amp; Assignments &rarr;</a>` : '';
        return;
    }

    // Both parties present requires no separate legal-consequence panel.
    if ((complainantPresent > 0 || complainantCount === 0) && (respondentPresent > 0 || respondentCount === 0)) {
        situationBox.style.display = 'none';
        return;
    }

    // Default neutral
    situationBox.classList.add('sit-neutral');
    iconEl.textContent = '⚖️';
    badgeTextEl.textContent = 'Attendance Incomplete';
    refEl.textContent = 'R.A. 7160 Sec. 415';
    consequencesListEl.innerHTML = '<li>Complete attendance selection for all parties to evaluate statutory next steps.</li>';
    recommendationTextEl.textContent = 'Select appearance status for each party.';
    shortcutsEl.innerHTML = '';
}
function bindAttendanceForm() {
    const form = document.getElementById('hearingAttendanceForm');
    const submitBtn = document.getElementById('btnSaveAttendance');

    const handleSaveAttendance = async (event) => {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        clearAttendanceModalAlert();

        const hearingId = document.getElementById('attHearingId')?.value;
        if (!hearingId) {
            showAttendanceModalAlert('Invalid hearing reference.');
            return;
        }

        const cards = document.querySelectorAll('#attPartiesContainer .party-att-card');
        if (!cards.length) {
            showAttendanceModalAlert('No party records available for this hearing.');
            return;
        }

        const unselectedParties = [];
        const records = [];

        cards.forEach((card) => {
            const residentId = card.dataset.residentId;
            const partyName = card.dataset.partyName || 'Party';
            const status = card.querySelector('.att-input-status')?.value || '';
            const isJustified = card.querySelector('.att-input-justified')?.value === '1' ? 1 : 0;
            const reason = card.querySelector(`select[name="records[${residentId}][justification_reason]"]`)?.value || '';
            const remarks = card.querySelector(`input[name="records[${residentId}][remarks]"]`)?.value || '';

            if (!status || status === 'Pending') {
                unselectedParties.push(partyName);
                card.style.border = '2px solid #ef4444';
            } else {
                card.style.border = '';
                records.push({
                    resident_id: Number(residentId),
                    attendance_status: status,
                    is_justified: isJustified,
                    justification_reason: isJustified ? reason : '',
                    remarks: remarks.trim()
                });
            }
        });

        if (unselectedParties.length > 0) {
            showAttendanceModalAlert(`Please select an appearance status (Present, Failure to Appear, Not Served, Excused, or Late) for: ${unselectedParties.join(', ')}.`);
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving Attendance & Applying Findings...';
        }

        try {
            const res = await api('../../../backend/api/hearings/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    hearing_id: Number(hearingId),
                    parties: records,
                    records: records
                })
            });

            // Refresh table and KPIs in background
            await Promise.all([loadCombinedRecords(currentPage), loadAttendanceKPIs(), loadHearings()]);

            // Re-render modal in place to show updated status, service badges, and statutory findings
            await openHearingAttendanceModal(hearingId);
            showAttendanceModalAlert(res.message || 'Attendance recorded and statutory findings applied successfully.', true);
        } catch (err) {
            showAttendanceModalAlert(err.message || 'Failed to save attendance.');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Attendance & Apply Findings';
            }
        }
    };

    if (form) {
        form.addEventListener('submit', handleSaveAttendance);
    }
    if (submitBtn) {
        submitBtn.addEventListener('click', handleSaveAttendance);
    }
}


document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('nonappearanceForm');
    form?.addEventListener('submit', async (event) => {
        event.preventDefault(); if (!form.reportValidity()) return;
        try {
            const result = await api('../../../backend/api/hearings/nonappearance.php', { method: 'POST', body: new FormData(form) });
            setMessage(result.message, true); closeNonappearanceModal();
            await Promise.all([loadHearings(), loadCombinedRecords(currentPage)]);
        } catch (error) { setMessage(error.message); }
    });
});


// AGAP_UNIFIED_SYNC_HEARINGS
window.addEventListener('agap:data-changed', async (event) => {
    const changed = event.detail?.modules || [];
    if (!changed.some((name) => ['complaints', 'cases', 'assignments', 'pangkat', 'hearings', 'deadlines', 'history'].includes(name))) {
        return;
    }
    if (document.body.dataset.hearingSyncRefreshing === '1') return;
    document.body.dataset.hearingSyncRefreshing = '1';
    try {
        await Promise.allSettled([
            loadHearings(),
            loadDeadlines(),
            loadCombinedRecords(currentPage),
            loadCases(),
            loadAttendanceKPIs()
        ]);
    } finally {
        window.setTimeout(() => delete document.body.dataset.hearingSyncRefreshing, 400);
    }
});
