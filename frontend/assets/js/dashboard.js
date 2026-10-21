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
        calendarHearings: []
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

    const renderHearings = (hearings, periodLabel) => {
        state.hearings = hearings || [];
        state.hearingsPage = 1;
        state.hearingsPeriodLabel = periodLabel;

        const heading = document.getElementById('hearingsPanelHeading');
        const sub = document.getElementById('hearingsPanelSub');
        const countBadge = document.getElementById('periodHearingCountBadge');

        if (state.period === 'all') {
            if (heading) heading.textContent = "Today's hearings";
            if (sub) sub.textContent = 'Sessions scheduled for today.';
        } else {
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
            return container.appendChild(emptyState(
                state.period === 'all'
                    ? 'No active hearings are scheduled for today.'
                    : `No active hearings are scheduled for ${state.hearingsPeriodLabel || 'this timeframe'}.`
            ));
        }

        const totalPages = Math.max(1, Math.ceil(totalHearings / state.hearingsPerPage));
        if (state.hearingsPage > totalPages) state.hearingsPage = totalPages;
        if (state.hearingsPage < 1) state.hearingsPage = 1;

        const startIndex = (state.hearingsPage - 1) * state.hearingsPerPage;
        const endIndex = Math.min(startIndex + state.hearingsPerPage, totalHearings);
        const pageItems = state.hearings.slice(startIndex, endIndex);

        pageItems.forEach((hearing) => addListItem(
            container,
            `${hearing.case_number || 'Case'} · ${hearing.hearing_type || 'Hearing'}`,
            `${formatDateTime(hearing.hearing_date)} · ${hearing.venue || 'Lupon Court Room'}${hearing.complaint_title ? ` · ${hearing.complaint_title}` : ''}`,
            hearing.status,
            hearing.case_id ? `../cases/case-details.php?id=${hearing.case_id}` : null
        ));

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

        const todayObj = new Date();
        const todayStr = `${todayObj.getFullYear()}-${String(todayObj.getMonth() + 1).padStart(2, '0')}-${String(todayObj.getDate()).padStart(2, '0')}`;

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
            const cell = document.createElement('div');
            cell.className = 'dash-cal-day is-other-month';
            cell.textContent = prevDayNum;
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

            const dayHearings = hearingsByDate[dateStr] || [];
            if (dayHearings.length > 0) {
                cell.classList.add('has-hearing');
                const dot = document.createElement('span');
                dot.className = 'dash-cal-hearing-dot';
                cell.appendChild(dot);

                const countText = `${dayHearings.length} hearing session${dayHearings.length > 1 ? 's' : ''}`;
                const details = dayHearings.map(h => `${h.case_number || 'Case'}: ${h.hearing_type || 'Session'}`).join(' | ');
                cell.title = `${countText}: ${details}`;
            }

            cell.addEventListener('click', () => {
                window.location.href = `../hearings/schedules.php?date=${encodeURIComponent(dateStr)}`;
            });

            grid.appendChild(cell);
        }

        // Fill remaining days of the last row
        const totalCells = firstDayIdx + totalDays;
        const remaining = (7 - (totalCells % 7)) % 7;
        for (let nextDay = 1; nextDay <= remaining; nextDay++) {
            const cell = document.createElement('div');
            cell.className = 'dash-cal-day is-other-month';
            cell.textContent = nextDay;
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

                renderHearings(data.today_hearings || [], periodLabel);
                renderDeadlines(data.mediation_deadlines || [], periodLabel);
                renderStatuses(data.status_totals || [], periodLabel);
                renderCasesTable(data.cases || [], periodLabel);

                state.calendarHearings = data.calendar_hearings || [];
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
        });
    }

    // Set initial active tab state
    tabButtons.forEach((btn) => {
        const isActive = btn.dataset.period === state.period;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    // Initial load
    fetchDashboardData();
});
