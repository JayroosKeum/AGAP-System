document.addEventListener('DOMContentLoaded', () => {
    const setText = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = Number(value || 0).toLocaleString();
    };

    const setMessage = (message = '', isError = false) => {
        const element = document.getElementById('dashboardMessage');
        if (!element) return;
        element.textContent = message;
        element.classList.toggle('is-error', isError);
    };

    const emptyState = (message) => {
        const item = document.createElement('p');
        item.className = 'dashboard-empty';
        item.textContent = message;
        return item;
    };

    const addListItem = (container, title, details, badge) => {
        const item = document.createElement('article');
        item.className = 'dashboard-list-item';
        const content = document.createElement('div');
        const heading = document.createElement('h3');
        heading.textContent = title;
        const description = document.createElement('p');
        description.textContent = details;
        content.append(heading, description);
        item.appendChild(content);
        if (badge) {
            const tag = document.createElement('span');
            tag.className = 'status-badge';
            tag.textContent = badge;
            item.appendChild(tag);
        }
        container.appendChild(item);
    };

    const formatDateTime = (value) => {
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    };

    const renderHearings = (hearings) => {
        const container = document.getElementById('todayHearings');
        if (!container) return;
        container.replaceChildren();
        if (!hearings.length) return container.appendChild(emptyState('No active hearings are scheduled for today.'));
        hearings.forEach((hearing) => addListItem(
            container,
            `${hearing.case_number} · ${hearing.hearing_type}`,
            `${formatDateTime(hearing.hearing_date)} · ${hearing.venue}${hearing.complaint_title ? ` · ${hearing.complaint_title}` : ''}`,
            hearing.status
        ));
    };

    const renderDeadlines = (deadlines) => {
        const container = document.getElementById('mediationDeadlines');
        if (!container) return;
        container.replaceChildren();
        if (!deadlines.length) return container.appendChild(emptyState('No mediation or conciliation deadlines are due within seven days.'));
        deadlines.forEach((caseItem) => addListItem(
            container,
            caseItem.case_number,
            `Deadline: ${caseItem.mediation_deadline_date}`,
            caseItem.case_status
        ));
    };

    const renderStatuses = (statuses) => {
        const container = document.getElementById('statusTotals');
        if (!container) return;
        container.replaceChildren();
        if (!statuses.length) return container.appendChild(emptyState('No case status data is available yet.'));
        statuses.forEach((status) => {
            const item = document.createElement('div');
            item.className = 'status-total';
            const label = document.createElement('span');
            label.textContent = status.status;
            const total = document.createElement('strong');
            total.textContent = Number(status.total || 0).toLocaleString();
            item.append(label, total);
            container.appendChild(item);
        });
    };

    fetch('../../../backend/api/dashboard/statistics.php', { headers: { Accept: 'application/json' } })
        .then(async (response) => {
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load the dashboard.');
            return payload.data;
        })
        .then((data) => {
            setText('totalCases', data.total_cases);
            setText('pendingReview', data.pending_review);
            setText('hearingsToday', data.hearings_today);
            setText('deadlinesDue', data.deadlines_due);
            setText('activeMediations', data.active_mediations);
            setText('hearingsThisWeek', data.hearings_this_week);
            setText('draftComplaints', data.draft_complaints);
            setText('settledCases', data.settled_cases);
            renderHearings(data.today_hearings || []);
            renderDeadlines(data.mediation_deadlines || []);
            renderStatuses(data.status_totals || []);
        })
        .catch((error) => {
            console.error(error);
            setMessage(error.message || 'Unable to load the dashboard. Please refresh and try again.', true);
            renderHearings([]);
            renderDeadlines([]);
            renderStatuses([]);
        });
});
