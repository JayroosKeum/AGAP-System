document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('recordsSearchForm');
    const results = document.getElementById('complaintTable') || document.getElementById('searchResults');
    const summary = document.getElementById('resultSummary');
    if (!form || !results) return;

    const complaintView = results.id === 'complaintTable';
    const columnCount = complaintView ? 7 : 7;

    const searchInput = document.getElementById('searchQuery');
    const clearInputBtn = document.getElementById('clearSearchInput');
    const clearBtn = document.getElementById('clearSearch');
    const statusSelect = document.getElementById('searchStatus');
    const caseTypeSelect = document.getElementById('searchType');
    const categorySelect = document.getElementById('searchCategory');
    const fromInput = document.getElementById('searchFrom');
    const toInput = document.getElementById('searchTo');
    const drawerToggle = document.getElementById('toggleFilterDrawer');
    const drawerPanel = document.getElementById('filterDrawerPanel');
    const activeDot = document.getElementById('filterActiveDot');
    const chipsContainer = document.getElementById('activeFilterChips');

    let debounceTimer = null;
    let cachedGlobalCounts = null;

    // Toggle secondary filter drawer
    if (drawerToggle && drawerPanel) {
        drawerToggle.addEventListener('click', () => {
            const isOpen = drawerPanel.classList.toggle('open');
            drawerToggle.classList.toggle('active', isOpen);
        });
    }

    // Keyword search input live clear button & debounce
    if (searchInput) {
        const updateClearVisibility = () => {
            if (clearInputBtn) {
                clearInputBtn.style.display = searchInput.value.trim() ? 'block' : 'none';
            }
        };

        searchInput.addEventListener('input', () => {
            updateClearVisibility();
            if (complaintView) {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    form.requestSubmit();
                }, 300);
            }
        });

        if (clearInputBtn) {
            clearInputBtn.addEventListener('click', () => {
                searchInput.value = '';
                updateClearVisibility();
                form.requestSubmit();
            });
        }
    }

    // Auto-submit on primary dropdown filter changes
    [caseTypeSelect, categorySelect].forEach(select => {
        if (select) {
            select.addEventListener('change', () => {
                form.requestSubmit();
            });
        }
    });

    if (statusSelect) {
        statusSelect.addEventListener('change', () => {
            syncNavTabsWithStatus(statusSelect.value);
            form.requestSubmit();
        });
    }

    // Status Navigation Tabs
    const navTabs = document.querySelectorAll('.complaints-nav-tabs .nav-tab-btn');
    navTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const targetStatus = tab.dataset.tabStatus ?? '';
            navTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');

            if (statusSelect) {
                statusSelect.value = targetStatus;
            }

            syncKpiCardsWithStatus(targetStatus);
            form.requestSubmit();
        });
    });

    // KPI Cards Clickable Filters
    const kpiCards = document.querySelectorAll('.complaints-kpi-grid .kpi-card');
    kpiCards.forEach(card => {
        card.addEventListener('click', () => {
            const filterKey = card.dataset.kpiFilter;
            const targetStatus = (filterKey === 'all') ? '' : filterKey;

            kpiCards.forEach(c => c.classList.remove('active'));
            card.classList.add('active');

            if (statusSelect) {
                statusSelect.value = targetStatus;
            }

            syncNavTabsWithStatus(targetStatus);
            form.requestSubmit();
        });
    });

    function syncNavTabsWithStatus(statusVal) {
        navTabs.forEach(tab => {
            const s = tab.dataset.tabStatus ?? '';
            tab.classList.toggle('active', s === statusVal);
        });
        syncKpiCardsWithStatus(statusVal);
    }

    function syncKpiCardsWithStatus(statusVal) {
        kpiCards.forEach(card => {
            const f = card.dataset.kpiFilter;
            if (statusVal === '' && f === 'all') card.classList.add('active');
            else if (f === statusVal) card.classList.add('active');
            else card.classList.remove('active');
        });
    }

    // Form submission
    form.addEventListener('submit', event => {
        event.preventDefault();
        updateDrawerActiveState();
        updateFilterChips();

        results.innerHTML = `
            <tr>
                <td colspan="${columnCount}" class="table-empty-wrap">
                    <div class="empty-icon-circle">⌛</div>
                    <div class="empty-state-title">Loading complaint records...</div>
                </td>
            </tr>
        `;

        fetch('../../../backend/api/search/records.php?' + new URLSearchParams(new FormData(form)))
            .then(async response => {
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || 'The records could not be loaded.');
                return data;
            })
            .then(rows => {
                renderRows(rows);
                if (complaintView) {
                    updateKpiAndTabCounts(rows);
                }
            })
            .catch(() => {
                results.innerHTML = `
                    <tr>
                        <td colspan="${columnCount}" class="table-empty-wrap">
                            <div class="empty-icon-circle" style="color: #dc2626; background: #fee2e2;">⚠️</div>
                            <div class="empty-state-title">Unable to load records</div>
                            <div class="empty-state-desc">Please check your connection and try again.</div>
                            <button type="button" class="btn-create" onclick="document.getElementById('recordsSearchForm').requestSubmit()">Retry</button>
                        </td>
                    </tr>
                `;
                if (summary) summary.textContent = '';
            });
    });

    // Clear search and reset filters
    clearBtn?.addEventListener('click', () => {
        form.reset();
        if (clearInputBtn) clearInputBtn.style.display = 'none';
        if (drawerPanel) drawerPanel.classList.remove('open');
        if (drawerToggle) drawerToggle.classList.remove('active');

        syncNavTabsWithStatus('');
        updateDrawerActiveState();
        updateFilterChips();

        if (complaintView) {
            form.requestSubmit();
        } else {
            results.innerHTML = `<tr><td colspan="${columnCount}" class="table-empty-wrap">Use the filters above to search records.</td></tr>`;
            if (summary) summary.textContent = '';
        }
    });

    function updateDrawerActiveState() {
        if (!activeDot) return;
        const hasDrawerActive = Boolean(
            (statusSelect && statusSelect.value && statusSelect.value !== 'in_progress' && statusSelect.value !== 'Under Review' && statusSelect.value !== 'Docketed' && statusSelect.value !== 'Settled') ||
            (fromInput && fromInput.value) ||
            (toInput && toInput.value)
        );
        activeDot.style.display = hasDrawerActive ? 'inline-block' : 'none';
    }

    function updateFilterChips() {
        if (!chipsContainer) return;
        chipsContainer.innerHTML = '';
        let filterCount = 0;

        const q = searchInput?.value.trim() ?? '';
        if (q) {
            addChip(`Keyword: "${q}"`, () => {
                searchInput.value = '';
                if (clearInputBtn) clearInputBtn.style.display = 'none';
                form.requestSubmit();
            });
            filterCount++;
        }

        const type = caseTypeSelect?.value ?? '';
        if (type) {
            addChip(`Type: ${type}`, () => {
                caseTypeSelect.value = '';
                form.requestSubmit();
            });
            filterCount++;
        }

        const catId = categorySelect?.value ?? '';
        if (catId) {
            const catText = categorySelect.options[categorySelect.selectedIndex]?.text || 'Category';
            addChip(`Category: ${catText}`, () => {
                categorySelect.value = '';
                form.requestSubmit();
            });
            filterCount++;
        }

        const status = statusSelect?.value ?? '';
        if (status) {
            addChip(`Status: ${status === 'in_progress' ? 'In Progress' : status}`, () => {
                statusSelect.value = '';
                syncNavTabsWithStatus('');
                form.requestSubmit();
            });
            filterCount++;
        }

        const from = fromInput?.value ?? '';
        if (from) {
            addChip(`From: ${from}`, () => {
                fromInput.value = '';
                form.requestSubmit();
            });
            filterCount++;
        }

        const to = toInput?.value ?? '';
        if (to) {
            addChip(`To: ${to}`, () => {
                toInput.value = '';
                form.requestSubmit();
            });
            filterCount++;
        }

        if (clearBtn) {
            clearBtn.style.display = filterCount > 0 ? 'inline-block' : 'none';
        }
    }

    function addChip(label, onRemove) {
        const chip = document.createElement('span');
        chip.className = 'filter-chip';
        chip.innerHTML = `${escapeHtml(label)} <span class="filter-chip-remove" title="Remove filter">&times;</span>`;
        chip.querySelector('.filter-chip-remove').addEventListener('click', onRemove);
        chipsContainer.appendChild(chip);
    }

    function updateKpiAndTabCounts(rows) {
        const isDefaultView = !searchInput?.value.trim() &&
            !caseTypeSelect?.value &&
            !categorySelect?.value &&
            !statusSelect?.value &&
            !fromInput?.value &&
            !toInput?.value;

        if (isDefaultView || !cachedGlobalCounts) {
            const allCount = rows.length;
            const reviewCount = rows.filter(r => (r.record_status || r.case_status) === 'Under Review' || r.record_status === 'Filed' || r.record_status === 'Needs Information').length;
            const progressCount = rows.filter(r => ['Docketed', 'Mediation', 'Conciliation', 'Arbitration'].includes(r.record_status || r.case_status)).length;
            const docketedCount = rows.filter(r => (r.record_status || r.case_status) === 'Docketed').length;
            const settledCount = rows.filter(r => ['Settled', 'Dismissed', 'CFA Issued'].includes(r.record_status || r.case_status)).length;

            cachedGlobalCounts = {
                all: allCount,
                review: reviewCount,
                progress: progressCount,
                docketed: docketedCount,
                settled: settledCount
            };
        }

        const counts = cachedGlobalCounts;
        const setEl = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(val);
        };

        setEl('kpiCountAll', counts.all);
        setEl('kpiCountReview', counts.review);
        setEl('kpiCountProgress', counts.progress);
        setEl('kpiCountSettled', counts.settled);

        setEl('tabCountAll', counts.all);
        setEl('tabCountReview', counts.review);
        setEl('tabCountProgress', counts.progress);
        setEl('tabCountDocketed', counts.docketed);
        setEl('tabCountSettled', counts.settled);
    }

    function renderRows(rows) {
        if (!Array.isArray(rows)) throw new Error('Invalid records response.');
        if (summary) {
            summary.textContent = `Showing ${rows.length} complaint record${rows.length === 1 ? '' : 's'}`;
        }
        if (!rows.length) {
            results.innerHTML = `
                <tr>
                    <td colspan="${columnCount}" class="table-empty-wrap">
                        <div class="empty-icon-circle">🔍</div>
                        <div class="empty-state-title">No complaints found</div>
                        <div class="empty-state-desc">No records match your selected keyword or filter criteria.</div>
                        <button type="button" class="btn-secondary btn-sm" onclick="document.getElementById('clearSearch')?.click()">Reset Filters</button>
                    </td>
                </tr>
            `;
            return;
        }
        results.innerHTML = rows.map(row => complaintView ? renderComplaintRow(row) : renderSearchRow(row)).join('');
    }

    function renderComplaintRow(row) {
        const id = Number(row.complaint_id);
        const status = String(row.record_status || row.case_status || 'Under Review');
        const statusClass = status.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        const caseType = String(row.case_type || 'Civil');
        const caseTypeClass = caseType.toLowerCase() === 'criminal' ? 'badge-type-criminal' : 'badge-type-civil';

        // Format Parties
        let partiesHtml = '<span class="badge-case-none">No parties linked</span>';
        if (row.parties) {
            const partyEntries = String(row.parties).split(' | ');
            const renderedParties = partyEntries.slice(0, 3).map(entry => {
                const parts = entry.split(': ');
                const role = (parts[0] || 'Party').trim();
                const name = (parts[1] || '').trim();
                const isComplainant = role.toLowerCase().includes('complainant');
                const isRespondent = role.toLowerCase().includes('respondent');
                const pillClass = isComplainant ? 'party-pill-complainant' : (isRespondent ? 'party-pill-respondent' : 'party-pill-witness');
                const roleTag = isComplainant ? 'C' : (isRespondent ? 'R' : 'W');
                return `<span class="party-pill-row ${pillClass}" title="${escapeHtml(role)}: ${escapeHtml(name)}"><span class="party-role-tag">${roleTag}</span> ${escapeHtml(name)}</span>`;
            }).join('');

            const extraCount = partyEntries.length > 3 ? `<span style="font-size: 0.76rem; color: #64748b;">+${partyEntries.length - 3} more</span>` : '';
            const repeatFlag = Number(row.repeat_party_count || 0) > 1
                ? `<span class="repeat-alert-badge" title="Resident appeared in multiple cases">Repeat (${Number(row.repeat_party_count)})</span>`
                : '';

            partiesHtml = `<div class="parties-stack">${renderedParties}${extraCount}${repeatFlag}</div>`;
        }

        // Format Incident Date
        let formattedDate = '—';
        if (row.incident_date) {
            try {
                const parts = String(row.incident_date).split('-');
                if (parts.length === 3) {
                    const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
                    formattedDate = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                } else {
                    formattedDate = escapeHtml(row.incident_date);
                }
            } catch (e) {
                formattedDate = escapeHtml(row.incident_date);
            }
        }

        // Format Case Number
        const caseNumberHtml = row.case_number
            ? `<span class="badge-case-docket">${escapeHtml(row.case_number)}</span>`
            : `<span class="badge-case-none">Undocketed</span>`;

        // Format Actions
        const actionsHtml = Number.isInteger(id) && id > 0
            ? `<div class="table-actions-group">
                <button type="button" class="btn-row-action btn-view-action" onclick="window.location.href='complaint-details.php?id=${id}'" title="View full details">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    View
                </button>
                <button type="button" class="btn-row-action" onclick="editComplaint(${id})" title="Edit complaint">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Edit
                </button>
                <button type="button" class="btn-row-action btn-delete-action" onclick="deleteComplaint(${id})" title="Delete complaint">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>
               </div>`
            : '';

        return `
            <tr>
                <td>
                    <div class="cell-complaint-meta">
                        <div class="complaint-top-row">
                            <a href="complaint-details.php?id=${id}" class="complaint-link-no">${escapeHtml(row.complaint_number || `Complaint #${id}`)}</a>
                            <span class="badge-case-type ${caseTypeClass}">${escapeHtml(caseType)}</span>
                        </div>
                        <div class="complaint-title-text" title="${escapeHtml(row.complaint_title)}">${escapeHtml(row.complaint_title)}</div>
                    </div>
                </td>
                <td>${caseNumberHtml}</td>
                <td><span class="badge-category">${escapeHtml(row.category_name || 'Uncategorized')}</span></td>
                <td>${partiesHtml}</td>
                <td><span style="color: #475569; font-size: 0.85rem; white-space: nowrap;">${formattedDate}</span></td>
                <td>
                    <span class="status-pill-badge status-pill-${statusClass}">
                        <span class="status-dot"></span>
                        ${escapeHtml(status)}
                    </span>
                </td>
                <td style="text-align: right;">${actionsHtml}</td>
            </tr>
        `;
    }

    function renderSearchRow(row) {
        return `
            <tr>
                <td>${escapeHtml(row.case_number || 'Not docketed')}<small>${escapeHtml(row.case_type || '')}</small></td>
                <td>${escapeHtml(row.complaint_number)}<small>${escapeHtml(row.complaint_title)}</small></td>
                <td>${escapeHtml(row.category_name || 'Uncategorized')}</td>
                <td>${escapeHtml(row.parties || 'No parties linked')}</td>
                <td>${escapeHtml(row.record_status || row.case_status || '')}</td>
                <td>${escapeHtml(row.incident_date || '')}</td>
                <td>${Number(row.repeat_party_count || 0) > 1 ? `<span class="repeat-flag">Repeat party (${Number(row.repeat_party_count)} records)</span>` : 'No repeat record'}</td>
            </tr>
        `;
    }

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = value ?? '';
        return node.innerHTML;
    }

    // Automatically load initial dataset
    if (complaintView) form.requestSubmit();
});
