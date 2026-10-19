document.addEventListener('DOMContentLoaded', () => {
    const state = {
        period: 'all',
        offset: 0,
        isLoading: false
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
        const container = document.getElementById('todayHearings');
        const heading = document.getElementById('hearingsPanelHeading');
        const sub = document.getElementById('hearingsPanelSub');
        if (!container) return;
        container.replaceChildren();

        if (state.period === 'all') {
            if (heading) heading.textContent = "Today's hearings";
            if (sub) sub.textContent = 'Sessions scheduled for today.';
        } else {
            if (heading) heading.textContent = `Hearings (${periodLabel})`;
            if (sub) sub.textContent = `Sessions scheduled within this timeframe.`;
        }

        if (!hearings || !hearings.length) {
            return container.appendChild(emptyState(
                state.period === 'all'
                    ? 'No active hearings are scheduled for today.'
                    : `No active hearings are scheduled for ${periodLabel}.`
            ));
        }

        hearings.forEach((hearing) => addListItem(
            container,
            `${hearing.case_number || 'Case'} · ${hearing.hearing_type || 'Hearing'}`,
            `${formatDateTime(hearing.hearing_date)} · ${hearing.venue || 'Barangay Hall'}${hearing.complaint_title ? ` · ${hearing.complaint_title}` : ''}`,
            hearing.status,
            hearing.case_id ? `../cases/case-details.php?id=${hearing.case_id}` : null
        ));
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

    const renderCasesTable = (cases, periodLabel) => {
        const tbody = document.getElementById('periodCasesTableBody');
        const titleSpan = document.getElementById('casesPeriodTitle');
        const descP = document.getElementById('casesPeriodDescription');
        const countBadge = document.getElementById('periodCaseCountBadge');

        if (titleSpan) titleSpan.textContent = periodLabel;
        if (descP) descP.textContent = `Docketed cases recorded for ${periodLabel}.`;
        if (countBadge) {
            const count = (cases || []).length;
            countBadge.textContent = `${count} case${count === 1 ? '' : 's'}`;
        }
        if (!tbody) return;

        tbody.replaceChildren();

        if (!cases || !cases.length) {
            const row = document.createElement('tr');
            row.innerHTML = `<td colspan="6" class="dashboard-empty-row"><p>No cases recorded for ${escapeHtml(periodLabel)}.</p></td>`;
            tbody.appendChild(row);
            return;
        }

        cases.forEach((caseItem) => {
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
    };

    const updateCardSubtitles = (period, periodLabel) => {
        const labels = {
            all: {
                totalSub: 'All recorded cases',
                reviewSub: 'Needs review or information',
                hearingsLabel: 'Hearings today',
                hearingsSub: 'Scheduled sessions today',
                deadlinesSub: 'Mediation deadlines within 7 days',
                activeSub: 'Mediation or conciliation cases',
                weekHearingsLabel: "This week's hearings",
                weekHearingsSub: 'Next 7 calendar days',
                draftsSub: 'Complaints not yet filed',
                settledSub: 'Resolved through settlement'
            },
            weekly: {
                totalSub: 'Docketed this week',
                reviewSub: 'Filed this week pending review',
                hearingsLabel: 'Hearings in week',
                hearingsSub: 'Sessions in selected week',
                deadlinesSub: 'Due within selected week',
                activeSub: 'Active mediation in week',
                weekHearingsLabel: 'Total week hearings',
                weekHearingsSub: 'Active sessions this week',
                draftsSub: 'Drafts created this week',
                settledSub: 'Settled within this week'
            },
            monthly: {
                totalSub: 'Docketed this month',
                reviewSub: 'Filed this month pending review',
                hearingsLabel: 'Hearings in month',
                hearingsSub: 'Sessions in selected month',
                deadlinesSub: 'Due within selected month',
                activeSub: 'Active mediation in month',
                weekHearingsLabel: 'Total month hearings',
                weekHearingsSub: 'Active sessions this month',
                draftsSub: 'Drafts created this month',
                settledSub: 'Settled within this month'
            },
            quarterly: {
                totalSub: 'Docketed this quarter',
                reviewSub: 'Filed this quarter pending review',
                hearingsLabel: 'Hearings in quarter',
                hearingsSub: 'Sessions in selected quarter',
                deadlinesSub: 'Due within selected quarter',
                activeSub: 'Active mediation in quarter',
                weekHearingsLabel: 'Total quarter hearings',
                weekHearingsSub: 'Active sessions this quarter',
                draftsSub: 'Drafts created this quarter',
                settledSub: 'Settled within this quarter'
            },
            annual: {
                totalSub: 'Docketed this year',
                reviewSub: 'Filed this year pending review',
                hearingsLabel: 'Hearings in year',
                hearingsSub: 'Sessions in selected year',
                deadlinesSub: 'Due within selected year',
                activeSub: 'Active mediation in year',
                weekHearingsLabel: 'Total year hearings',
                weekHearingsSub: 'Active sessions this year',
                draftsSub: 'Drafts created this year',
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
        setEl('deadlinesDueSub', config.deadlinesSub);
        setEl('activeMediationsSub', config.activeSub);
        setEl('hearingsThisWeekLabel', config.weekHearingsLabel);
        setEl('hearingsThisWeekSub', config.weekHearingsSub);
        setEl('draftComplaintsSub', config.draftsSub);
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
                setText('deadlinesDue', data.deadlines_due);
                setText('activeMediations', data.active_mediations);
                setText('hearingsThisWeek', data.hearings_this_week);
                setText('draftComplaints', data.draft_complaints);
                setText('settledCases', data.settled_cases);

                updateCardSubtitles(state.period, periodLabel);
                updateControls(state.period, data);

                renderHearings(data.today_hearings || [], periodLabel);
                renderDeadlines(data.mediation_deadlines || [], periodLabel);
                renderStatuses(data.status_totals || [], periodLabel);
                renderCasesTable(data.cases || [], periodLabel);

                updateUrl();
            })
            .catch((error) => {
                console.error('Dashboard Error:', error);
                setMessage(error.message || 'Unable to load the dashboard data. Please try again.', true);
                renderHearings([], 'Error');
                renderDeadlines([], 'Error');
                renderStatuses([], 'Error');
                renderCasesTable([], 'Error');
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

    // Navigation buttons binding
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

    // Set initial active tab state
    tabButtons.forEach((btn) => {
        const isActive = btn.dataset.period === state.period;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    // Initial load
    fetchDashboardData();
});
