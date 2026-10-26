document.addEventListener('DOMContentLoaded', () => {
    const state = {
        period: 'all',
        offset: 0,
        isLoading: false,
        cases: [],
        casesPage: 1,
        casesPerPage: 10,
        hearings: [],
        hearingsPage: 1,
        hearingsPerPage: 10,
        hearingsPeriodLabel: '',
        currentPeriodLabel: 'All Time',
        calendarCursor: new Date(),
        calendarHearings: [],
        selectedDate: null
    };

    // Check URL search params for initial tab selection
    const urlParams = new URLSearchParams(window.location.search);
    const initialPeriod = urlParams.get('period') || urlParams.get('tab');
    if (['all', 'weekly', 'monthly', 'quarterly', 'annual'].includes(initialPeriod)) {
        state.period = initialPeriod;
    }
    const initialOffset = parseInt(urlParams.get('offset'), 10);
    if (!Number.isNaN(initialOffset)) {
        state.offset = initialOffset;
    }

    const escapeHtml = (text) => {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

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

    const getStatusClass = (status) => {
        const s = String(status || '').toLowerCase();
        if (s.includes('settled')) return 'status-settled';
        if (s.includes('conciliation')) return 'status-conciliation';
        if (s.includes('mediation')) return 'status-mediation';
        if (s.includes('arbitration')) return 'status-arbitration';
        if (s.includes('cfa')) return 'status-cfa';
        if (s.includes('dismissed')) return 'status-dismissed';
        if (s.includes('archived')) return 'status-archived';
        if (s.includes('docketed')) return 'status-docketed';
        return 'status-default';
    };

    const formatDateTime = (value) => {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    };

    const formatDateOnly = (value) => {
        if (!value) return '—';
        const date = new Date(String(value).slice(0, 10) + 'T00:00:00');
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const addListItem = (container, title, details, badge, linkUrl = null) => {
        const item = document.createElement('article');
        item.className = 'dashboard-list-item';
        const content = document.createElement('div');
        const heading = document.createElement('h3');
        if (linkUrl) {
            const link = document.createElement('a');
            link.href = linkUrl;
            link.textContent = title;
            link.className = 'dashboard-list-link';
            heading.appendChild(link);
        } else {
            heading.textContent = title;
        }
        const description = document.createElement('p');
        description.textContent = details;
        content.append(heading, description);
        item.appendChild(content);
        if (badge) {
            const tag = document.createElement('span');
            tag.className = `status-badge ${getStatusClass(badge)}`;
            tag.textContent = badge;
            item.appendChild(tag);
        }
        container.appendChild(item);
    };

    const getTodayDateStr = () => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    };

    const addHearingItem = (container, hearing) => {
        const item = document.createElement('article');
        item.className = 'dashboard-list-item';

        const content = document.createElement('div');
        content.className = 'dashboard-list-content';

        const heading = document.createElement('h3');
        heading.textContent = `${hearing.case_number || 'Case'} · ${hearing.hearing_type || 'Hearing'}`;

        const description = document.createElement('p');
        description.textContent = `${formatDateTime(hearing.hearing_date)} · ${hearing.venue || 'Lupon Court Room'}${hearing.complaint_title ? ` · ${hearing.complaint_title}` : ''}`;

        content.append(heading, description);
        item.appendChild(content);

        const openCaseBtn = document.createElement('a');
        openCaseBtn.className = 'btn-open-case';
        openCaseBtn.textContent = 'Open Case';
        if (hearing.complaint_id) {
            openCaseBtn.href = `../complaints/complaint-details.php?id=${encodeURIComponent(hearing.complaint_id)}`;
        } else if (hearing.case_id) {
            openCaseBtn.href = `../cases/case-details.php?id=${encodeURIComponent(hearing.case_id)}`;
        } else {
            openCaseBtn.href = '#';
        }
        openCaseBtn.title = 'View complaint details';

        item.appendChild(openCaseBtn);
        container.appendChild(item);
    };

    const selectCalendarDate = (dateStr) => {
        state.selectedDate = dateStr;

        const todayStr = getTodayDateStr();
        const isToday = (dateStr === todayStr);

        // Filter hearings from state.calendarHearings for this date
        const matchingHearings = (state.calendarHearings || []).filter(h => {
            if (!h.hearing_date) return false;
            const d = h.hearing_date.split(' ')[0] || h.hearing_date.split('T')[0];
            return d === dateStr;
        });

        const formattedDate = formatDateOnly(dateStr);
        const heading = document.getElementById('hearingsPanelHeading');
        const sub = document.getElementById('hearingsPanelSub');
        const countBadge = document.getElementById('periodHearingCountBadge');

        if (isToday) {
            if (heading) heading.textContent = "Today's hearings";
            if (sub) sub.textContent = 'Sessions scheduled for today.';
        } else {
            if (heading) heading.textContent = `Hearings (${formattedDate})`;
            if (sub) sub.textContent = `Sessions scheduled for ${formattedDate}.`;
        }

        if (countBadge) {
            const count = matchingHearings.length;
            if (count > 0) {
                countBadge.textContent = `${count} hearing${count === 1 ? '' : 's'}`;
                countBadge.style.display = 'inline-block';
            } else {
                countBadge.style.display = 'none';
            }
        }

        state.hearings = matchingHearings;
        state.hearingsPage = 1;
        state.hearingsPeriodLabel = isToday ? 'today' : formattedDate;
        renderHearingsPage();

        // Update selected state in calendar grid
        const allDays = document.querySelectorAll('.dash-cal-day');
        allDays.forEach(cell => {
            cell.classList.toggle('is-selected', cell.dataset.date === dateStr);
        });
    };

    const renderHearings = (hearings, periodLabel) => {
        state.hearings = hearings || [];
        state.hearingsPage = 1;
        state.hearingsPeriodLabel = periodLabel;

        const heading = document.getElementById('hearingsPanelHeading');
        const sub = document.getElementById('hearingsPanelSub');
        const countBadge = document.getElementById('periodHearingCountBadge');

        if (state.period === 'all') {
            state.selectedDate = getTodayDateStr();
            if (heading) heading.textContent = "Today's hearings";
            if (sub) sub.textContent = 'Sessions scheduled for today.';
        } else {
            state.selectedDate = null;
            if (heading) heading.textContent = `Hearings (${periodLabel})`;
            if (sub) sub.textContent = `Sessions scheduled within this timeframe.`;
        }

        if (countBadge) {
            const count = state.hearings.length;
            if (count > 0 && state.period !== 'all') {
                countBadge.textContent = `${count} hearing${count === 1 ? '' : 's'}`;
                countBadge.style.display = 'inline-block';
            } else {
                countBadge.style.display = 'none';
            }
        }

        renderHearingsPage();
    };

    const renderHearingsPage = () => {
        const container = document.getElementById('todayHearings');
        const paginationWrap = document.getElementById('hearingsTablePagination');
        const infoEl = document.getElementById('hearingsPaginationInfo');
        const prevBtn = document.getElementById('hearingsPrevPageBtn');
        const nextBtn = document.getElementById('hearingsNextPageBtn');
        const pageNumbersContainer = document.getElementById('hearingsPageNumbers');
        if (!container) return;

        container.replaceChildren();

        const totalHearings = (state.hearings || []).length;
        if (!totalHearings) {
            if (paginationWrap) paginationWrap.style.display = 'none';
            const todayStr = getTodayDateStr();
            const emptyLabel = state.selectedDate
                ? (state.selectedDate === todayStr ? 'today' : (formatDateOnly(state.selectedDate) || 'this date'))
                : (state.period === 'all' ? 'today' : (state.hearingsPeriodLabel || 'this timeframe'));
            return container.appendChild(emptyState(`No active hearings are scheduled for ${emptyLabel}.`));
        }

        const totalPages = Math.max(1, Math.ceil(totalHearings / state.hearingsPerPage));
        if (state.hearingsPage > totalPages) state.hearingsPage = totalPages;
        if (state.hearingsPage < 1) state.hearingsPage = 1;

        const startIndex = (state.hearingsPage - 1) * state.hearingsPerPage;
        const endIndex = Math.min(startIndex + state.hearingsPerPage, totalHearings);
        const pageItems = state.hearings.slice(startIndex, endIndex);

        pageItems.forEach((hearing) => addHearingItem(container, hearing));

        // Pagination UI: Only show 10 at a time
        if (paginationWrap) {
            paginationWrap.style.display = totalPages > 1 ? 'flex' : 'none';
        }
        if (infoEl) {
            infoEl.textContent = `Showing ${startIndex + 1} to ${endIndex} of ${totalHearings} hearing${totalHearings === 1 ? '' : 's'}`;
        }
        if (prevBtn) {
            prevBtn.disabled = state.hearingsPage <= 1;
        }
        if (nextBtn) {
            nextBtn.disabled = state.hearingsPage >= totalPages;
        }
        if (pageNumbersContainer) {
            pageNumbersContainer.replaceChildren();
            if (totalPages > 1) {
                for (let i = 1; i <= totalPages; i++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `page-num-btn ${i === state.hearingsPage ? 'is-active' : ''}`;
                    btn.textContent = i;
                    btn.addEventListener('click', () => {
                        state.hearingsPage = i;
                        renderHearingsPage();
                    });
                    pageNumbersContainer.appendChild(btn);
                }
            }
        }
    };

    const renderDeadlines = (deadlines, periodLabel) => {
        const container = document.getElementById('mediationDeadlines');
        const heading = document.getElementById('deadlinesPanelHeading');
        const sub = document.getElementById('deadlinesPanelSub');
        if (!container) return;
        container.replaceChildren();

        if (state.period === 'all') {
            if (heading) heading.textContent = 'Upcoming mediation deadlines';
            if (sub) sub.textContent = 'Active cases due within the next seven days.';
        } else {
            if (heading) heading.textContent = `Mediation deadlines (${periodLabel})`;
            if (sub) sub.textContent = `Active cases due within this timeframe.`;
        }

        if (!deadlines || !deadlines.length) {
            return container.appendChild(emptyState(
                state.period === 'all'
                    ? 'No mediation or conciliation deadlines are due within seven days.'
                    : `No mediation or conciliation deadlines are due in ${periodLabel}.`
            ));
        }

        deadlines.forEach((caseItem) => addListItem(
            container,
            caseItem.case_number || 'Case',
            `Deadline: ${formatDateOnly(caseItem.mediation_deadline_date)}`,
            caseItem.case_status,
            caseItem.case_id ? `../cases/case-details.php?id=${caseItem.case_id}` : null
        ));
    };

    const renderStatuses = (statuses, periodLabel) => {
        const container = document.getElementById('statusTotals');
        const titleSpan = document.getElementById('statusPeriodTitle');
        if (titleSpan) titleSpan.textContent = periodLabel;
        if (!container) return;
        container.replaceChildren();

        if (!statuses || !statuses.length) {
            return container.appendChild(emptyState(`No case status data recorded for ${periodLabel}.`));
        }

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

    const renderCasesPage = () => {
        const tbody = document.getElementById('periodCasesTableBody');
        const paginationWrap = document.getElementById('casesTablePagination');
        const infoEl = document.getElementById('casesPaginationInfo');
        const prevBtn = document.getElementById('casesPrevPageBtn');
        const nextBtn = document.getElementById('casesNextPageBtn');
        const pageNumbersContainer = document.getElementById('casesPageNumbers');
        if (!tbody) return;

        tbody.replaceChildren();

        const totalCases = (state.cases || []).length;
        if (totalCases === 0) {
            const row = document.createElement('tr');
            row.innerHTML = `<td colspan="6" class="dashboard-empty-row"><p>No cases recorded for ${escapeHtml(state.currentPeriodLabel || 'this timeframe')}.</p></td>`;
            tbody.appendChild(row);
            if (paginationWrap) paginationWrap.style.display = 'none';
            return;
        }

        const totalPages = Math.max(1, Math.ceil(totalCases / state.casesPerPage));
        if (state.casesPage > totalPages) state.casesPage = totalPages;
        if (state.casesPage < 1) state.casesPage = 1;

        const startIndex = (state.casesPage - 1) * state.casesPerPage;
        const endIndex = Math.min(startIndex + state.casesPerPage, totalCases);
        const pageItems = state.cases.slice(startIndex, endIndex);

        pageItems.forEach((caseItem) => {
            const tr = document.createElement('tr');
            tr.className = 'dashboard-case-row';

            const caseNum = caseItem.case_number || `Case #${caseItem.case_id}`;
            const title = caseItem.complaint_title || 'Untitled Complaint';
            const parties = [
                caseItem.complainant_names ? `By: ${caseItem.complainant_names}` : '',
                caseItem.respondent_names ? `vs. ${caseItem.respondent_names}` : ''
            ].filter(Boolean).join(' · ');

            const typeBadge = caseItem.case_type === 'Criminal'
                ? '<span class="type-pill type-criminal">Criminal</span>'
                : '<span class="type-pill type-civil">Civil</span>';

            const statusClass = getStatusClass(caseItem.case_status);
            const statusBadge = `<span class="status-badge ${statusClass}">${escapeHtml(caseItem.case_status || 'Docketed')}</span>`;
            const docketDate = formatDateOnly(caseItem.docket_date);

            tr.innerHTML = `
                <td class="case-col-number">
                    <a href="../cases/case-details.php?id=${encodeURIComponent(caseItem.case_id)}" class="case-number-link">
                        ${escapeHtml(caseNum)}
                    </a>
                </td>
                <td class="case-col-title">
                    <div class="case-title-text">${escapeHtml(title)}</div>
                    ${parties ? `<div class="case-parties-sub">${escapeHtml(parties)}</div>` : ''}
                    ${caseItem.category_name ? `<div class="case-category-sub">${escapeHtml(caseItem.category_name)}</div>` : ''}
                </td>
                <td class="case-col-type">${typeBadge}</td>
                <td class="case-col-status">${statusBadge}</td>
                <td class="case-col-date">${escapeHtml(docketDate)}</td>
                <td class="case-col-action" style="text-align:right;">
                    <a href="../cases/case-details.php?id=${encodeURIComponent(caseItem.case_id)}" class="btn-action-view">
                        View Details
                    </a>
                </td>
            `;

            tbody.appendChild(tr);
        });

        // Pagination UI (only show 10 at a time)
        if (paginationWrap) {
            paginationWrap.style.display = totalCases > 0 ? 'flex' : 'none';
        }
        if (infoEl) {
            infoEl.textContent = `Showing ${startIndex + 1} to ${endIndex} of ${totalCases} case${totalCases === 1 ? '' : 's'}`;
        }
        if (prevBtn) {
            prevBtn.disabled = state.casesPage <= 1;
        }
        if (nextBtn) {
            nextBtn.disabled = state.casesPage >= totalPages;
        }
        if (pageNumbersContainer) {
            pageNumbersContainer.replaceChildren();
            if (totalPages > 1) {
                for (let i = 1; i <= totalPages; i++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `page-num-btn ${i === state.casesPage ? 'is-active' : ''}`;
                    btn.textContent = i;
                    btn.addEventListener('click', () => {
                        state.casesPage = i;
                        renderCasesPage();
                    });
                    pageNumbersContainer.appendChild(btn);
                }
            }
        }
    };

    const renderCasesTable = (cases, periodLabel) => {
        state.cases = cases || [];
        state.casesPage = 1;
        state.currentPeriodLabel = periodLabel;

        const titleSpan = document.getElementById('casesPeriodTitle');
        const descP = document.getElementById('casesPeriodDescription');
        const countBadge = document.getElementById('periodCaseCountBadge');

        if (titleSpan) titleSpan.textContent = periodLabel;
        if (descP) descP.textContent = `Docketed cases recorded for ${periodLabel}.`;
        if (countBadge) {
            const count = state.cases.length;
            countBadge.textContent = `${count} case${count === 1 ? '' : 's'}`;
        }

        renderCasesPage();
    };

    const renderDashboardCalendar = () => {
        const grid = document.getElementById('dashboardCalendarGrid');
        const monthDisplay = document.getElementById('dashCalMonthDisplay');
        if (!grid) return;

        const cursor = state.calendarCursor;
        const year = cursor.getFullYear();
        const month = cursor.getMonth();

        if (monthDisplay) {
            monthDisplay.textContent = cursor.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        }

        grid.replaceChildren();

        // Weekday headers
        ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(day => {
            const header = document.createElement('div');
            header.className = 'dash-cal-weekday';
            header.textContent = day;
            grid.appendChild(header);
        });

        const todayStr = getTodayDateStr();

        const firstDayIdx = new Date(year, month, 1).getDay();
        const totalDays = new Date(year, month + 1, 0).getDate();
        const prevMonthTotalDays = new Date(year, month, 0).getDate();

        // Index hearings by date: YYYY-MM-DD
        const hearingsByDate = {};
        (state.calendarHearings || []).forEach(h => {
            if (!h.hearing_date) return;
            const datePart = h.hearing_date.split(' ')[0] || h.hearing_date.split('T')[0];
            if (!hearingsByDate[datePart]) hearingsByDate[datePart] = [];
            hearingsByDate[datePart].push(h);
        });

        // Previous month filler days
        for (let i = firstDayIdx - 1; i >= 0; i--) {
            const prevDayNum = prevMonthTotalDays - i;
            const prevMonthDate = new Date(year, month - 1, prevDayNum);
            const prevDateStr = `${prevMonthDate.getFullYear()}-${String(prevMonthDate.getMonth() + 1).padStart(2, '0')}-${String(prevMonthDate.getDate()).padStart(2, '0')}`;
            const cell = document.createElement('div');
            cell.className = 'dash-cal-day is-other-month';
            cell.dataset.date = prevDateStr;
            cell.textContent = prevDayNum;
            cell.title = `Go to ${formatDateOnly(prevDateStr)}`;
            cell.addEventListener('click', () => {
                state.calendarCursor = new Date(year, month - 1, 1);
                renderDashboardCalendar();
                selectCalendarDate(prevDateStr);
            });
            grid.appendChild(cell);
        }

        // Current month days
        for (let day = 1; day <= totalDays; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const cell = document.createElement('div');
            cell.className = 'dash-cal-day';
            cell.dataset.date = dateStr;

            const dayNum = document.createElement('span');
            dayNum.textContent = day;
            cell.appendChild(dayNum);

            if (dateStr === todayStr) {
                cell.classList.add('is-today');
            }

            if (dateStr === state.selectedDate) {
                cell.classList.add('is-selected');
            }

            const dayHearings = hearingsByDate[dateStr] || [];
            if (dayHearings.length > 0) {
                cell.classList.add('has-hearing');
                const dot = document.createElement('span');
                dot.className = 'dash-cal-hearing-dot';
                cell.appendChild(dot);

                const countText = `${dayHearings.length} hearing session${dayHearings.length > 1 ? 's' : ''}`;
                const details = dayHearings.map(h => `${h.case_number || 'Case'}: ${h.hearing_type || 'Session'}`).join(' | ');
                cell.title = `${countText}: ${details} (Click to view hearings)`;
            } else {
                cell.title = `Click to view hearings for ${formatDateOnly(dateStr)}`;
            }

            cell.addEventListener('click', () => {
                selectCalendarDate(dateStr);
            });

            grid.appendChild(cell);
        }

        // Fill remaining days of the last row
        const totalCells = firstDayIdx + totalDays;
        const remaining = (7 - (totalCells % 7)) % 7;
        for (let nextDay = 1; nextDay <= remaining; nextDay++) {
            const nextMonthDate = new Date(year, month + 1, nextDay);
            const nextDateStr = `${nextMonthDate.getFullYear()}-${String(nextMonthDate.getMonth() + 1).padStart(2, '0')}-${String(nextMonthDate.getDate()).padStart(2, '0')}`;
            const cell = document.createElement('div');
            cell.className = 'dash-cal-day is-other-month';
            cell.dataset.date = nextDateStr;
            cell.textContent = nextDay;
            cell.title = `Go to ${formatDateOnly(nextDateStr)}`;
            cell.addEventListener('click', () => {
                state.calendarCursor = new Date(year, month + 1, 1);
                renderDashboardCalendar();
                selectCalendarDate(nextDateStr);
            });
            grid.appendChild(cell);
        }
    };

    const updateCardSubtitles = (period, periodLabel) => {
        const labels = {
            all: {
                totalSub: 'All recorded cases',
                reviewSub: 'Needs review or information',
                hearingsLabel: 'Hearings today',
                hearingsSub: 'Scheduled sessions today',
                archivedSub: 'Closed and archived records',
                deadlinesSub: 'Mediation deadlines within 7 days',
                activeSub: 'Mediation or conciliation cases',
                weekHearingsLabel: "This week's hearings",
                weekHearingsSub: 'Next 7 calendar days',
                paanyayaSub: 'Undocketed complaints in Paanyaya',
                settledSub: 'Resolved through settlement'
            },
            weekly: {
                totalSub: 'Docketed this week',
                reviewSub: 'Filed this week pending review',
                hearingsLabel: 'Hearings in week',
                hearingsSub: 'Sessions in selected week',
                archivedSub: 'Archived this week',
                deadlinesSub: 'Due within selected week',
                activeSub: 'Active mediation in week',
                weekHearingsLabel: 'Total week hearings',
                weekHearingsSub: 'Active sessions this week',
                paanyayaSub: 'Paanyaya created this week',
                settledSub: 'Settled within this week'
            },
            monthly: {
                totalSub: 'Docketed this month',
                reviewSub: 'Filed this month pending review',
                hearingsLabel: 'Hearings in month',
                hearingsSub: 'Sessions in selected month',
                archivedSub: 'Archived this month',
                deadlinesSub: 'Due within selected month',
                activeSub: 'Active mediation in month',
                weekHearingsLabel: 'Total month hearings',
                weekHearingsSub: 'Active sessions this month',
                paanyayaSub: 'Paanyaya created this month',
                settledSub: 'Settled within this month'
            },
            quarterly: {
                totalSub: 'Docketed this quarter',
                reviewSub: 'Filed this quarter pending review',
                hearingsLabel: 'Hearings in quarter',
                hearingsSub: 'Sessions in selected quarter',
                archivedSub: 'Archived this quarter',
                deadlinesSub: 'Due within selected quarter',
                activeSub: 'Active mediation in quarter',
                weekHearingsLabel: 'Total quarter hearings',
                weekHearingsSub: 'Active sessions this quarter',
                paanyayaSub: 'Paanyaya created this quarter',
                settledSub: 'Settled within this quarter'
            },
            annual: {
                totalSub: 'Docketed this year',
                reviewSub: 'Filed this year pending review',
                hearingsLabel: 'Hearings in year',
                hearingsSub: 'Sessions in selected year',
                archivedSub: 'Archived this year',
                deadlinesSub: 'Due within selected year',
                activeSub: 'Active mediation in year',
                weekHearingsLabel: 'Total year hearings',
                weekHearingsSub: 'Active sessions this year',
                paanyayaSub: 'Paanyaya created this year',
                settledSub: 'Settled within this year'
            }
        };

        const config = labels[period] || labels.all;
        const setEl = (id, text) => {
            const el = document.getElementById(id);
            if (el) el.textContent = text;
        };

        setEl('totalCasesSub', config.totalSub);
        setEl('pendingReviewSub', config.reviewSub);
        setEl('hearingsTodayLabel', config.hearingsLabel);
        setEl('hearingsTodaySub', config.hearingsSub);
        setEl('archivedCasesSub', config.archivedSub);
        setEl('deadlinesDueSub', config.deadlinesSub);
        setEl('activeMediationsSub', config.activeSub);
        setEl('hearingsThisWeekLabel', config.weekHearingsLabel);
        setEl('hearingsThisWeekSub', config.weekHearingsSub);
        setEl('paanyayaComplaintsSub', config.paanyayaSub || config.draftsSub);
        setEl('settledCasesSub', config.settledSub);
    };

    const updateControls = (period, data) => {
        const navButtons = document.getElementById('periodNavButtons');
        const labelDisplay = document.getElementById('periodLabelDisplay');
        const rangeDisplay = document.getElementById('periodDateRangeDisplay');
        const currentBtnText = document.getElementById('currentPeriodText');

        if (labelDisplay) labelDisplay.textContent = data.period_label || 'All Time';
        if (rangeDisplay) {
            if (data.start_date && data.end_date) {
                rangeDisplay.textContent = `${formatDateOnly(data.start_date)} – ${formatDateOnly(data.end_date)}`;
            } else {
                rangeDisplay.textContent = data.period_sublabel || 'All recorded data in the system';
            }
        }

        if (navButtons) {
            navButtons.style.display = period === 'all' ? 'none' : 'inline-flex';
        }

        if (currentBtnText) {
            if (period === 'weekly') currentBtnText.textContent = 'This Week';
            else if (period === 'monthly') currentBtnText.textContent = 'This Month';
            else if (period === 'quarterly') currentBtnText.textContent = 'This Quarter';
            else if (period === 'annual') currentBtnText.textContent = 'This Year';
            else currentBtnText.textContent = 'Current';
        }

        const currentBtn = document.getElementById('currentPeriodBtn');
        if (currentBtn) {
            currentBtn.classList.toggle('is-current-active', state.offset === 0);
        }
    };

    const updateUrl = () => {
        try {
            const url = new URL(window.location);
            if (state.period === 'all') {
                url.searchParams.delete('period');
                url.searchParams.delete('offset');
            } else {
                url.searchParams.set('period', state.period);
                if (state.offset !== 0) {
                    url.searchParams.set('offset', state.offset);
                } else {
                    url.searchParams.delete('offset');
                }
            }
            window.history.replaceState({}, '', url);
        } catch (e) {
            // Browser without history API fallback
        }
    };

    const fetchDashboardData = () => {
        if (state.isLoading) return;
        state.isLoading = true;

        const overviewEl = document.querySelector('.dashboard-overview');
        if (overviewEl) overviewEl.classList.add('is-loading');

        const params = new URLSearchParams({
            period: state.period,
            offset: state.offset
        });

        setMessage('');

        fetch(`../../../backend/api/dashboard/statistics.php?${params.toString()}`, {
            headers: { Accept: 'application/json' }
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || (payload.success === false)) {
                    throw new Error(payload.message || 'Unable to load the dashboard.');
                }
                return payload.data || payload;
            })
            .then((data) => {
                const periodLabel = data.period_label || 'Selected Period';
                state.activePeriodData = {
                    period: state.period,
                    periodLabel: periodLabel,
                    periodSublabel: data.period_sublabel || '',
                    startDate: data.start_date || null,
                    endDate: data.end_date || null,
                    year: data.year || new Date().getFullYear(),
                    month: data.month || (new Date().getMonth() + 1),
                    quarter: data.quarter || Math.ceil((new Date().getMonth() + 1) / 3),
                    cases: data.cases || [],
                    statusTotals: data.status_totals || {},
                    todayHearings: data.today_hearings || []
                };

                setText('totalCases', data.total_cases);
                setText('pendingReview', data.pending_review);
                setText('hearingsToday', data.hearings_today);
                setText('archivedCases', data.archived_cases);
                setText('deadlinesDue', data.deadlines_due);
                setText('activeMediations', data.active_mediations);
                setText('hearingsThisWeek', data.hearings_this_week);
                setText('paanyayaComplaints', data.paanyaya_complaints ?? data.draft_complaints ?? 0);
                setText('settledCases', data.settled_cases);

                updateCardSubtitles(state.period, periodLabel);
                updateControls(state.period, data);

                state.calendarHearings = data.calendar_hearings || [];
                renderHearings(data.today_hearings || [], periodLabel);
                renderDeadlines(data.mediation_deadlines || [], periodLabel);
                renderStatuses(data.status_totals || [], periodLabel);
                renderCasesTable(data.cases || [], periodLabel);
                renderDashboardCalendar();

                updateUrl();
            })
            .catch((error) => {
                console.error('Dashboard Error:', error);
                setMessage(error.message || 'Unable to load the dashboard data. Please try again.', true);
                renderHearings([], 'Error');
                renderDeadlines([], 'Error');
                renderStatuses([], 'Error');
                renderCasesTable([], 'Error');
                state.calendarHearings = [];
                renderDashboardCalendar();
            })
            .finally(() => {
                state.isLoading = false;
                if (overviewEl) overviewEl.classList.remove('is-loading');
            });
    };

    // Tab buttons event binding
    const tabButtons = document.querySelectorAll('.period-tab');
    tabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const period = btn.dataset.period;
            if (state.period === period && state.offset === 0) return;

            state.period = period;
            state.offset = 0; // Reset offset to current timeframe when switching tabs

            tabButtons.forEach((b) => {
                const isActive = b === btn;
                b.classList.toggle('active', isActive);
                b.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            fetchDashboardData();
        });
    });

    // Period Navigation buttons binding
    const prevBtn = document.getElementById('prevPeriodBtn');
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            state.offset -= 1;
            fetchDashboardData();
        });
    }

    const nextBtn = document.getElementById('nextPeriodBtn');
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            state.offset += 1;
            fetchDashboardData();
        });
    }

    const currentBtn = document.getElementById('currentPeriodBtn');
    if (currentBtn) {
        currentBtn.addEventListener('click', () => {
            if (state.offset === 0) return;
            state.offset = 0;
            fetchDashboardData();
        });
    }

    // Hearings Panel Pagination buttons binding
    const hearingsPrevBtn = document.getElementById('hearingsPrevPageBtn');
    if (hearingsPrevBtn) {
        hearingsPrevBtn.addEventListener('click', () => {
            if (state.hearingsPage > 1) {
                state.hearingsPage -= 1;
                renderHearingsPage();
            }
        });
    }

    const hearingsNextBtn = document.getElementById('hearingsNextPageBtn');
    if (hearingsNextBtn) {
        hearingsNextBtn.addEventListener('click', () => {
            const totalPages = Math.ceil((state.hearings || []).length / state.hearingsPerPage);
            if (state.hearingsPage < totalPages) {
                state.hearingsPage += 1;
                renderHearingsPage();
            }
        });
    }

    // Cases Table Pagination buttons binding
    const casesPrevBtn = document.getElementById('casesPrevPageBtn');
    if (casesPrevBtn) {
        casesPrevBtn.addEventListener('click', () => {
            if (state.casesPage > 1) {
                state.casesPage -= 1;
                renderCasesPage();
            }
        });
    }

    const casesNextBtn = document.getElementById('casesNextPageBtn');
    if (casesNextBtn) {
        casesNextBtn.addEventListener('click', () => {
            const totalPages = Math.ceil((state.cases || []).length / state.casesPerPage);
            if (state.casesPage < totalPages) {
                state.casesPage += 1;
                renderCasesPage();
            }
        });
    }

    // Calendar Navigation buttons binding
    const calPrevBtn = document.getElementById('dashCalPrevBtn');
    if (calPrevBtn) {
        calPrevBtn.addEventListener('click', () => {
            state.calendarCursor = new Date(state.calendarCursor.getFullYear(), state.calendarCursor.getMonth() - 1, 1);
            renderDashboardCalendar();
        });
    }

    const calNextBtn = document.getElementById('dashCalNextBtn');
    if (calNextBtn) {
        calNextBtn.addEventListener('click', () => {
            state.calendarCursor = new Date(state.calendarCursor.getFullYear(), state.calendarCursor.getMonth() + 1, 1);
            renderDashboardCalendar();
        });
    }

    const calTodayBtn = document.getElementById('dashCalTodayBtn');
    if (calTodayBtn) {
        calTodayBtn.addEventListener('click', () => {
            state.calendarCursor = new Date();
            renderDashboardCalendar();
            selectCalendarDate(getTodayDateStr());
        });
    }

    // Set initial active tab state
    tabButtons.forEach((btn) => {
        const isActive = btn.dataset.period === state.period;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    // =========================================================================
    // Reports and Export Dropdown & Modal Logic
    // =========================================================================
    const dropdownGenToggle = document.getElementById('btnGenerateReportToggle');
    const menuGenerate = document.getElementById('menuGenerateReport');
    const dropdownExportToggle = document.getElementById('btnExportToggle');
    const menuExport = document.getElementById('menuExport');

    const closeAllDropdowns = () => {
        if (menuGenerate) menuGenerate.classList.remove('is-open');
        if (menuExport) menuExport.classList.remove('is-open');
        if (dropdownGenToggle) {
            dropdownGenToggle.setAttribute('aria-expanded', 'false');
            dropdownGenToggle.closest('.dash-dropdown')?.classList.remove('is-open');
        }
        if (dropdownExportToggle) {
            dropdownExportToggle.setAttribute('aria-expanded', 'false');
            dropdownExportToggle.closest('.dash-dropdown')?.classList.remove('is-open');
        }
    };

    if (dropdownGenToggle && menuGenerate) {
        dropdownGenToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = menuGenerate.classList.contains('is-open');
            closeAllDropdowns();
            if (!isOpen) {
                menuGenerate.classList.add('is-open');
                dropdownGenToggle.setAttribute('aria-expanded', 'true');
                dropdownGenToggle.closest('.dash-dropdown')?.classList.add('is-open');
            }
        });
    }

    if (dropdownExportToggle && menuExport) {
        dropdownExportToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = menuExport.classList.contains('is-open');
            closeAllDropdowns();
            if (!isOpen) {
                menuExport.classList.add('is-open');
                dropdownExportToggle.setAttribute('aria-expanded', 'true');
                dropdownExportToggle.closest('.dash-dropdown')?.classList.add('is-open');
            }
        });
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.dash-dropdown')) {
            closeAllDropdowns();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllDropdowns();
            closeReportModal();
            closeKpModal();
        }
    });

    const getReportTypeForActivePeriod = () => {
        switch (state.period) {
            case 'weekly': return 'Weekly';
            case 'monthly': return 'Monthly';
            case 'quarterly': return 'Quarterly';
            case 'annual': return 'Annual';
            case 'all': default: return 'All Time';
        }
    };

    const getActiveReportParams = (overrideType = null) => {
        const ap = state.activePeriodData || {};
        const type = overrideType || getReportTypeForActivePeriod();
        return {
            type,
            year: ap.year || new Date().getFullYear(),
            month: ap.month || (new Date().getMonth() + 1),
            quarter: ap.quarter || Math.ceil((new Date().getMonth() + 1) / 3),
            start_date: ap.startDate || '',
            end_date: ap.endDate || '',
            period_label: ap.periodLabel || 'Report'
        };
    };

    // Modal elements for Report Preview
    const reportModal = document.getElementById('reportPreviewModal');
    const modalCloseBtn = document.getElementById('modalCloseBtn');
    const modalCloseFooterBtn = document.getElementById('modalCloseFooterBtn');
    const modalPrintBtn = document.getElementById('modalPrintBtn');
    const modalLoading = document.getElementById('modalLoading');
    const modalContent = document.getElementById('modalContent');

    const openReportModal = () => {
        if (reportModal) {
            reportModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    };

    const closeReportModal = () => {
        if (reportModal) {
            reportModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeReportModal);
    if (modalCloseFooterBtn) modalCloseFooterBtn.addEventListener('click', closeReportModal);
    if (reportModal) {
        reportModal.addEventListener('click', (e) => {
            if (e.target === reportModal) closeReportModal();
        });
    }

    const renderReportPreview = (report) => {
        const titleEl = document.getElementById('modalReportTitle');
        const subEl = document.getElementById('modalReportSubtitle');
        const badgeEl = document.getElementById('modalPeriodBadge');

        if (titleEl) titleEl.textContent = `${report.type} Report: ${report.period_label}`;
        if (subEl) {
            const count = (report.cases || []).length;
            subEl.textContent = `${count} case record${count === 1 ? '' : 's'} included · Docketed activity and status overview`;
        }
        if (badgeEl) badgeEl.textContent = report.type;

        // Render Summary Cards
        const cardsContainer = document.getElementById('modalSummaryCards');
        if (cardsContainer) {
            cardsContainer.replaceChildren();
            const totals = report.totals || {};
            const keyMetrics = [
                { key: 'cases_docketed', label: 'Cases Docketed' },
                { key: 'complaints_filed', label: 'Complaints Filed' },
                { key: 'settled_cases', label: 'Settled Cases' },
                { key: 'arbitration_cases', label: 'Arbitration Cases' },
                { key: 'cfa_issued_cases', label: 'CFA Issued' },
                { key: 'archived_cases', label: 'Archived Cases' },
                { key: 'hearings_scheduled', label: 'Hearings' }
            ];
            keyMetrics.forEach(({ key, label }) => {
                const card = document.createElement('div');
                card.className = 'summary-card';
                const count = document.createElement('strong');
                count.textContent = Number(totals[key] ?? 0).toLocaleString();
                const text = document.createElement('span');
                text.textContent = label;
                card.append(count, text);
                cardsContainer.appendChild(card);
            });
        }

        // Render Status Totals
        const statusEl = document.getElementById('modalStatusTotals');
        if (statusEl) {
            statusEl.replaceChildren();
            const statuses = Object.entries(report.status_totals || {});
            if (!statuses.length) {
                statusEl.textContent = 'No cases recorded in this period.';
            } else {
                const list = document.createElement('ul');
                list.className = 'totals-list';
                statuses.forEach(([name, count]) => {
                    const li = document.createElement('li');
                    li.innerHTML = `<span>${escapeHtml(name)}</span><strong>${Number(count).toLocaleString()}</strong>`;
                    list.appendChild(li);
                });
                statusEl.appendChild(list);
            }
        }

        // Render Categories
        const catEl = document.getElementById('modalCategoryTotals');
        if (catEl) {
            catEl.replaceChildren();
            const categories = report.categories || [];
            if (!categories.length) {
                catEl.textContent = 'No complaint categories recorded in this period.';
            } else {
                const list = document.createElement('ul');
                list.className = 'totals-list';
                categories.forEach(item => {
                    const li = document.createElement('li');
                    li.innerHTML = `<span>${escapeHtml(item.category_name)}</span><strong>${Number(item.total).toLocaleString()}</strong>`;
                    list.appendChild(li);
                });
                catEl.appendChild(list);
            }
        }

        // Render Cases Details Table
        const casesBody = document.getElementById('modalReportCases');
        if (casesBody) {
            casesBody.replaceChildren();
            const cases = report.cases || [];
            if (!cases.length) {
                const tr = document.createElement('tr');
                tr.innerHTML = `<td colspan="8" class="dashboard-empty-row" style="text-align:center; padding:24px; color:#64748b;">No cases docketed in this period.</td>`;
                casesBody.appendChild(tr);
            } else {
                cases.forEach(item => {
                    const tr = document.createElement('tr');
                    const resolution = item.cfa_issuance_date ? `CFA: ${item.cfa_issuance_date}` : item.award_date ? `Award: ${item.award_date}` : item.settlement_date ? `Settlement: ${item.settlement_date}` : item.archived_date ? `Archived: ${item.archived_date}` : '—';
                    tr.innerHTML = `
                        <td><strong>${escapeHtml(item.case_number)}</strong></td>
                        <td>${escapeHtml(item.complaint_title || item.complaint_number || '—')}</td>
                        <td>${escapeHtml(item.category_name || '—')}</td>
                        <td>${escapeHtml(item.case_type || '—')}</td>
                        <td><span class="badge ${getStatusClass(item.case_status)}">${escapeHtml(item.case_status || '—')}</span></td>
                        <td>${formatDateOnly(item.docket_date)}</td>
                        <td>${Number(item.hearing_count || 0)}</td>
                        <td>${escapeHtml(resolution)}</td>
                    `;
                    casesBody.appendChild(tr);
                });
            }
        }
    };

    // Action 1: Preview Report
    const previewBtn = document.getElementById('itemPreviewReport');
    if (previewBtn) {
        previewBtn.addEventListener('click', async () => {
            closeAllDropdowns();
            openReportModal();
            if (modalLoading) {
                modalLoading.innerHTML = '<div class="dash-spinner"></div><span>Generating report preview...</span>';
                modalLoading.style.display = 'flex';
            }
            if (modalContent) modalContent.style.display = 'none';

            const params = getActiveReportParams();
            try {
                const query = new URLSearchParams(params);
                const res = await fetch(`../../../backend/api/reports/generate.php?${query.toString()}`);
                const result = await res.json().catch(() => ({}));
                if (!res.ok || !result.success) {
                    throw new Error(result.message || 'Unable to generate report preview.');
                }
                renderReportPreview(result.data);
                if (modalLoading) modalLoading.style.display = 'none';
                if (modalContent) modalContent.style.display = 'block';
            } catch (err) {
                if (modalLoading) {
                    modalLoading.innerHTML = `<p style="color:#ef4444; font-weight:600;">Failed to load preview: ${escapeHtml(err.message)}</p>`;
                    modalLoading.style.display = 'flex';
                }
            }
        });
    }

    // Action 2: Print Report
    const printAction = () => {
        const params = getActiveReportParams();
        const query = new URLSearchParams(params);
        window.open(`../../../backend/api/reports/print.php?${query.toString()}`, '_blank', 'width=950,height=750,noopener,noreferrer');
    };

    const printItemBtn = document.getElementById('itemPrintReport');
    if (printItemBtn) {
        printItemBtn.addEventListener('click', () => {
            closeAllDropdowns();
            printAction();
        });
    }
    if (modalPrintBtn) {
        modalPrintBtn.addEventListener('click', printAction);
    }

    // Action 3: DILG Report
    const dilgItemBtn = document.getElementById('itemDilgReport');
    if (dilgItemBtn) {
        dilgItemBtn.addEventListener('click', () => {
            closeAllDropdowns();
            const ap = state.activePeriodData || {};
            const query = new URLSearchParams({
                type: 'DILG',
                year: ap.year || new Date().getFullYear()
            });
            window.open(`../../../backend/api/reports/print.php?${query.toString()}`, '_blank', 'width=950,height=750,noopener,noreferrer');
        });
    }

    // Action 4: Export Case Register (Excel .xlsx)
    const exportRegBtn = document.getElementById('itemExportRegister');
    if (exportRegBtn) {
        exportRegBtn.addEventListener('click', async () => {
            closeAllDropdowns();
            const ap = state.activePeriodData || {};
            const payload = {
                format: 'register_excel',
                start_date: ap.startDate || '',
                end_date: ap.endDate || ''
            };
            if (window.agapNotify) window.agapNotify('Generating Case Register .xlsx export...', 'info', 'Export');
            try {
                const res = await fetch('../../../backend/api/reports/export.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json().catch(() => ({}));
                if (!res.ok || !result.success) {
                    throw new Error(result.message || 'Unable to generate Case Register.');
                }
                if (window.agapNotify) window.agapNotify(result.message || 'Case Register exported successfully.', 'success', 'Export Complete');
                window.location.assign(result.download_url);
            } catch (err) {
                if (window.agapNotify) window.agapNotify(err.message, 'error', 'Export Failed');
            }
        });
    }

    // =========================================================================
    // KP Monitoring Form Modal Logic
    // =========================================================================
    const kpModal = document.getElementById('kpMonitoringModal');
    const kpCloseBtn = document.getElementById('kpCloseBtn');
    const kpCloseFooterBtn = document.getElementById('kpCloseFooterBtn');
    const kpPrintBtn = document.getElementById('kpPrintBtn');
    const kpExportExcelBtn = document.getElementById('kpExportExcelBtn');
    const kpLoading = document.getElementById('kpLoading');
    const kpPrintableArea = document.getElementById('kpPrintableArea');

    let kpCurrentCases = [];

    const openKpModal = () => {
        if (kpModal) {
            kpModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    };

    const closeKpModal = () => {
        if (kpModal) {
            kpModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    if (kpCloseBtn) kpCloseBtn.addEventListener('click', closeKpModal);
    if (kpCloseFooterBtn) kpCloseFooterBtn.addEventListener('click', closeKpModal);
    if (kpModal) {
        kpModal.addEventListener('click', (e) => {
            if (e.target === kpModal) closeKpModal();
        });
    }

    // Dynamic auto-recalculation for KP Monitoring Form
    const recalculateKpTotals = () => {
        const crim = Number(document.getElementById('kpInCriminal')?.value) || 0;
        const civ = Number(document.getElementById('kpInCivil')?.value) || 0;
        const oth = Number(document.getElementById('kpInOthers')?.value) || 0;
        const totalFiled = crim + civ + oth;
        const elTotalFiled = document.getElementById('kpTotalFiledDisplay');
        if (elTotalFiled) elTotalFiled.textContent = totalFiled.toLocaleString();

        const med = Number(document.getElementById('kpInMediation')?.value) || 0;
        const con = Number(document.getElementById('kpInConciliation')?.value) || 0;
        const arb = Number(document.getElementById('kpInArbitration')?.value) || 0;
        const totalSettled = med + con + arb;
        const elTotalSettled = document.getElementById('kpTotalSettledDisplay');
        if (elTotalSettled) elTotalSettled.textContent = totalSettled.toLocaleString();

        const rep = Number(document.getElementById('kpInRepudiated')?.value) || 0;
        const drp = Number(document.getElementById('kpInDropped')?.value) || 0;
        const pnd = Number(document.getElementById('kpInPending')?.value) || 0;
        const dsm = Number(document.getElementById('kpInDismissed')?.value) || 0;
        const crt = Number(document.getElementById('kpInCertified')?.value) || 0;
        const ref = Number(document.getElementById('kpInReferred')?.value) || 0;
        const totalUnsettled = rep + drp + pnd + dsm + crt + ref;
        const elTotalUnsettled = document.getElementById('kpTotalUnsettledDisplay');
        if (elTotalUnsettled) elTotalUnsettled.textContent = totalUnsettled.toLocaleString();

        const savings = totalSettled * 9500;
        const elSavings = document.getElementById('kpSavingsDisplay');
        if (elSavings) {
            elSavings.textContent = `PHP ${savings.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
    };

    // Attach listeners on cell number inputs
    document.querySelectorAll('.kp-cell-num').forEach(inp => {
        inp.addEventListener('input', recalculateKpTotals);
        inp.addEventListener('change', recalculateKpTotals);
    });

    const populateKpMonitoringData = (data) => {
        const meta = data.meta || {};
        const summary = data.summary || {};
        const cases = data.cases || [];
        kpCurrentCases = cases;

        const setVal = (id, val) => {
            const el = document.getElementById(id);
            if (el && val !== undefined && val !== null) el.value = val;
        };
        const setText = (id, txt) => {
            const el = document.getElementById(id);
            if (el && txt !== undefined && txt !== null) el.textContent = txt;
        };

        setVal('kpRegionInput', meta.region || 'NCR');
        setVal('kpCityInput', meta.city || 'MARIKINA');
        setVal('kpBarangayInput', meta.barangay || 'TUMANA');
        setVal('kpQuarterInput', meta.quarter_label || 'Quarter 1');
        setVal('kpCyInput', meta.cy || new Date().getFullYear());
        setVal('kpDateAccomplishedInput', meta.date_accomplished || '');
        setText('kpMonthDisplay', meta.month_label || '');

        setVal('kpInCriminal', summary.criminal ?? 0);
        setVal('kpInCivil', summary.civil ?? 0);
        setVal('kpInOthers', summary.others ?? 0);

        setVal('kpInMediation', summary.mediation ?? 0);
        setVal('kpInConciliation', summary.conciliation ?? 0);
        setVal('kpInArbitration', summary.arbitration ?? 0);

        setVal('kpInRepudiated', summary.repudiated ?? 0);
        setVal('kpInDropped', summary.dropped ?? 0);
        setVal('kpInPending', summary.pending ?? 0);
        setVal('kpInDismissed', summary.dismissed ?? 0);
        setVal('kpInCertified', summary.certified ?? 0);
        setVal('kpInReferred', summary.referred ?? 0);

        setVal('kpPreparedByInput', meta.prepared_by || 'LUPON MEMBER / SECRETARY');
        setVal('kpNotedByInput', meta.noted_by || 'HON. AKIKO LYNNE CENTENO');
        setVal('kpAttestedByInput', meta.attested_by || 'PAMELA CONSULTA');

        setText('kpEntriesCount', `Showing ${cases.length} entries`);
        const tbody = document.getElementById('kpCasesTbody');
        if (tbody) {
            tbody.replaceChildren();
            if (!cases.length) {
                const tr = document.createElement('tr');
                tr.innerHTML = `<td colspan="21" class="dashboard-empty-row" style="text-align:center; padding:24px; color:#64748b;">No cases docketed in this period.</td>`;
                tbody.appendChild(tr);
            } else {
                cases.forEach(c => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><strong>${escapeHtml(c.case_number || '—')}</strong></td>
                        <td>${escapeHtml(c.complaint_number || '—')}</td>
                        <td>${escapeHtml(c.case_type || '—')}</td>
                        <td>${escapeHtml(c.category_name || '—')}</td>
                        <td>${escapeHtml(c.complaint_title || '—')}</td>
                        <td>${escapeHtml(c.complainant_name || '—')}</td>
                        <td>${escapeHtml(c.respondent_name || '—')}</td>
                        <td>${formatDateOnly(c.incident_date)}</td>
                        <td>${formatDateOnly(c.docket_date)}</td>
                        <td>${formatDateOnly(c.summons_issue_date)}</td>
                        <td>${formatDateOnly(c.summons_serve_date)}</td>
                        <td>${escapeHtml(c.officer_name || '—')}</td>
                        <td>${escapeHtml(c.summons_status || '—')}</td>
                        <td>${escapeHtml(c.summons_sla || '—')}</td>
                        <td>${Number(c.mediation_sessions || 0)}</td>
                        <td>${Number(c.conciliation_sessions || 0)}</td>
                        <td>${formatDateOnly(c.settlement_date)}</td>
                        <td>${escapeHtml(c.compliance_status || '—')}</td>
                        <td>${formatDateOnly(c.cfa_issuance_date)}</td>
                        <td>${escapeHtml(c.cfa_reason || '—')}</td>
                        <td><span class="badge ${getStatusClass(c.case_status)}">${escapeHtml(c.case_status || '—')}</span></td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        }

        recalculateKpTotals();
    };

    // Action 5: KP Monitoring Form Click
    const kpItemBtn = document.getElementById('itemKpMonitoring');
    if (kpItemBtn) {
        kpItemBtn.addEventListener('click', async () => {
            closeAllDropdowns();
            openKpModal();

            if (kpLoading) {
                kpLoading.innerHTML = '<div class="dash-spinner"></div><span>Loading KP Monitoring Form data...</span>';
                kpLoading.style.display = 'flex';
            }
            if (kpPrintableArea) kpPrintableArea.style.display = 'none';

            const ap = state.activePeriodData || {};
            const query = new URLSearchParams({
                year: ap.year || new Date().getFullYear(),
                month: ap.month || (new Date().getMonth() + 1),
                start_date: ap.startDate || '',
                end_date: ap.endDate || ''
            });

            try {
                const res = await fetch(`../../../backend/api/reports/kp_monitoring.php?${query.toString()}`);
                const result = await res.json().catch(() => ({}));
                if (!res.ok || !result.success) {
                    throw new Error(result.message || 'Unable to load KP Monitoring data.');
                }
                populateKpMonitoringData(result);
                if (kpLoading) kpLoading.style.display = 'none';
                if (kpPrintableArea) kpPrintableArea.style.display = 'block';
            } catch (err) {
                if (kpLoading) {
                    kpLoading.innerHTML = `<p style="color:#ef4444; font-weight:600;">Failed to load KP Monitoring Form: ${escapeHtml(err.message)}</p>`;
                    kpLoading.style.display = 'flex';
                }
            }
        });
    }

    // Print KP Monitoring Form
    if (kpPrintBtn) {
        kpPrintBtn.addEventListener('click', () => {
            window.print();
        });
    }

    // Export KP Monitoring Form to Excel (.xlsx)
    if (kpExportExcelBtn) {
        kpExportExcelBtn.addEventListener('click', async () => {
            const getVal = (id) => (document.getElementById(id)?.value ?? '');
            const getNum = (id) => (Number(document.getElementById(id)?.value) || 0);

            const crim = getNum('kpInCriminal');
            const civ = getNum('kpInCivil');
            const oth = getNum('kpInOthers');
            const totalFiled = crim + civ + oth;

            const med = getNum('kpInMediation');
            const con = getNum('kpInConciliation');
            const arb = getNum('kpInArbitration');
            const totalSettled = med + con + arb;

            const rep = getNum('kpInRepudiated');
            const drp = getNum('kpInDropped');
            const pnd = getNum('kpInPending');
            const dsm = getNum('kpInDismissed');
            const crt = getNum('kpInCertified');
            const ref = getNum('kpInReferred');
            const totalUnsettled = rep + drp + pnd + dsm + crt + ref;

            const savings = totalSettled * 9500;

            const meta = {
                region: getVal('kpRegionInput') || 'NCR',
                city: getVal('kpCityInput') || 'MARIKINA',
                barangay: getVal('kpBarangayInput') || 'TUMANA',
                quarter_label: getVal('kpQuarterInput') || 'Quarter 1',
                cy: getVal('kpCyInput') || new Date().getFullYear(),
                date_accomplished: getVal('kpDateAccomplishedInput') || '',
                month_label: document.getElementById('kpMonthDisplay')?.textContent || 'KP-Report',
                prepared_by: getVal('kpPreparedByInput') || 'LUPON MEMBER / SECRETARY',
                noted_by: getVal('kpNotedByInput') || 'HON. AKIKO LYNNE CENTENO',
                attested_by: getVal('kpAttestedByInput') || 'PAMELA CONSULTA'
            };

            const summary = {
                civil: civ,
                criminal: crim,
                others: oth,
                total_filed: totalFiled,
                mediation: med,
                conciliation: con,
                arbitration: arb,
                total_settled: totalSettled,
                repudiated: rep,
                dropped: drp,
                pending: pnd,
                dismissed: dsm,
                certified: crt,
                referred: ref,
                total_unsettled: totalUnsettled,
                savings: savings.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            };

            const ap = state.activePeriodData || {};
            const payload = {
                format: 'kp_monitoring_excel',
                start_date: ap.startDate || '',
                end_date: ap.endDate || '',
                meta,
                summary,
                cases: kpCurrentCases
            };

            if (window.agapNotify) window.agapNotify('Generating KP Monitoring Excel workbook (.xlsx)...', 'info', 'Export');
            try {
                const res = await fetch('../../../backend/api/reports/export.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json().catch(() => ({}));
                if (!res.ok || !result.success) {
                    throw new Error(result.message || 'Unable to generate KP Monitoring Excel workbook.');
                }
                if (window.agapNotify) window.agapNotify(result.message || 'KP Monitoring Excel workbook exported successfully.', 'success', 'Export Complete');
                window.location.assign(result.download_url);
            } catch (err) {
                if (window.agapNotify) window.agapNotify(err.message, 'error', 'Export Failed');
            }
        });
    }



    // Initial load
    fetchDashboardData();
});
