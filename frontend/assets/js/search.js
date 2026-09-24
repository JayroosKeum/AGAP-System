document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('recordsSearchForm');
    const results = document.getElementById('complaintTable') || document.getElementById('searchResults');
    const summary = document.getElementById('resultSummary');
    if (!form || !results) return;

    const complaintView = results.id === 'complaintTable';
    const columnCount = 7;

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

    // Distinct Lifecycle Attribute Selectors
    const intakeSelect = document.getElementById('searchIntake');
    const stageSelect = document.getElementById('searchStage');
    const dispositionSelect = document.getElementById('searchDisposition');

    // Sorting Elements
    const sortSelect = document.getElementById('searchSort');
    const sortOrderInput = document.getElementById('sortOrder');
    const sortableHeaders = document.querySelectorAll('.sortable-th');

    let debounceTimer = null;
    let cachedGlobalCounts = null;
    let loadedRows = [];
    let activeSortKey = 'date';
    let activeSortDir = 'desc';

    // Pagination State (Max 10 per page)
    const pageSize = 10;
    let currentPage = 1;
    const paginationContainer = document.getElementById('complaintPagination');
    const paginationSummary = document.getElementById('complaintPaginationSummary');
    const paginationControls = document.getElementById('complaintPaginationControls');

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

    // Auto-submit on distinct lifecycle drawer filter changes
    [intakeSelect, stageSelect, dispositionSelect].forEach(select => {
        if (select) {
            select.addEventListener('change', () => {
                // If user filters specifically by intake/stage/disposition, clear single status quick filter
                if (statusSelect && (intakeSelect?.value || stageSelect?.value || dispositionSelect?.value)) {
                    statusSelect.value = '';
                    syncNavTabsWithStatus('');
                }
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

    // Sort selector dropdown change
    if (sortSelect) {
        sortSelect.addEventListener('change', () => {
            const val = sortSelect.value;
            applySortFromDropdownValue(val);
        });
    }

    function applySortFromDropdownValue(val) {
        if (val.endsWith('_asc')) {
            activeSortKey = mapSortValueToHeaderKey(val.replace('_asc', ''));
            activeSortDir = 'asc';
        } else if (val.endsWith('_desc')) {
            activeSortKey = mapSortValueToHeaderKey(val.replace('_desc', ''));
            activeSortDir = 'desc';
        } else {
            activeSortKey = mapSortValueToHeaderKey(val);
            activeSortDir = (activeSortKey === 'date') ? 'desc' : 'asc';
        }

        updateSortHeadersUI();
        if (loadedRows.length > 0) {
            sortRows(loadedRows, activeSortKey, activeSortDir);
            if (complaintView) {
                currentPage = 1;
                renderPaginatedRows();
            } else {
                renderRows(loadedRows);
            }
        } else {
            form.requestSubmit();
        }
    }

    function mapSortValueToHeaderKey(val) {
        switch (val) {
            case 'incident_date': return 'date';
            case 'complaint_number':
            case 'complaint_title': return 'complaint';
            case 'case_number': return 'case_no';
            case 'category_name': return 'category';
            case 'parties': return 'parties';
            case 'intake_status':
            case 'current_stage':
            case 'final_disposition': return 'lifecycle';
            default: return val || 'date';
        }
    }

    // Interactive Column Header Sorting
    if (sortableHeaders.length > 0) {
        sortableHeaders.forEach(th => {
            th.addEventListener('click', () => {
                const sortKey = th.dataset.sortKey;
                if (!sortKey) return;

                if (activeSortKey === sortKey) {
                    activeSortDir = (activeSortDir === 'asc') ? 'desc' : 'asc';
                } else {
                    activeSortKey = sortKey;
                    activeSortDir = (sortKey === 'date') ? 'desc' : 'asc';
                }

                updateSortHeadersUI();

                if (sortOrderInput) {
                    sortOrderInput.value = activeSortDir.toUpperCase();
                }

                // Sync with #searchSort dropdown if appropriate option exists
                syncSortDropdown();

                if (loadedRows.length > 0) {
                    sortRows(loadedRows, activeSortKey, activeSortDir);
                    if (complaintView) {
                        currentPage = 1;
                        renderPaginatedRows();
                    } else {
                        renderRows(loadedRows);
                    }
                } else {
                    form.requestSubmit();
                }
            });
        });
    }

    function updateSortHeadersUI() {
        sortableHeaders.forEach(th => {
            const key = th.dataset.sortKey;
            const ind = document.getElementById(`sortInd_${key}`);
            th.classList.remove('sort-active-asc', 'sort-active-desc');

            if (key === activeSortKey) {
                th.classList.add(`sort-active-${activeSortDir}`);
                if (ind) ind.textContent = (activeSortDir === 'asc') ? '▲' : '▼';
            } else {
                if (ind) ind.textContent = '⇅';
            }
        });
    }

    function syncSortDropdown() {
        if (!sortSelect) return;
        const targetVal = (activeSortKey === 'date')
            ? (activeSortDir === 'asc' ? 'incident_date_asc' : 'incident_date')
            : (activeSortKey === 'complaint')
                ? (activeSortDir === 'desc' ? 'complaint_number_desc' : 'complaint_number')
                : (activeSortKey === 'case_no')
                    ? (activeSortDir === 'desc' ? 'case_number_desc' : 'case_number')
                    : (activeSortKey === 'category')
                        ? 'category_name'
                        : '';

        if (targetVal) {
            const opt = sortSelect.querySelector(`option[value="${targetVal}"]`);
            if (opt) sortSelect.value = targetVal;
        }
    }

    // In-memory row sorting for instantaneous UI feedback
    function sortRows(rows, key, dir) {
        const mult = (dir === 'asc') ? 1 : -1;
        rows.sort((a, b) => {
            switch (key) {
                case 'complaint': {
                    const cA = String(a.complaint_number || a.complaint_title || '').toLowerCase();
                    const cB = String(b.complaint_number || b.complaint_title || '').toLowerCase();
                    return cA.localeCompare(cB) * mult;
                }
                case 'case_no': {
                    const noA = String(a.case_number || '').toLowerCase();
                    const noB = String(b.case_number || '').toLowerCase();
                    if (!noA && noB) return 1;
                    if (noA && !noB) return -1;
                    return noA.localeCompare(noB) * mult;
                }
                case 'category': {
                    const catA = String(a.category_name || '').toLowerCase();
                    const catB = String(b.category_name || '').toLowerCase();
                    return catA.localeCompare(catB) * mult;
                }
                case 'parties': {
                    const pA = String(a.parties || '').toLowerCase();
                    const pB = String(b.parties || '').toLowerCase();
                    return pA.localeCompare(pB) * mult;
                }
                case 'date': {
                    const dA = a.incident_date || '';
                    const dB = b.incident_date || '';
                    return dA.localeCompare(dB) * mult;
                }
                case 'lifecycle': {
                    const rank = (r) => {
                        const disp = r.final_disposition || '';
                        if (disp === 'Amicable Settlement') return 10;
                        if (disp.includes('Arbitration')) return 9;
                        if (disp.includes('CFA')) return 8;
                        if (disp.includes('Dismissed')) return 7;
                        const st = r.current_stage || '';
                        if (st === 'Arbitration') return 6;
                        if (st === 'Conciliation') return 5;
                        if (st === 'Mediation') return 4;
                        if (r.intake_status === 'Docketed') return 3;
                        return 1;
                    };
                    return (rank(a) - rank(b)) * mult;
                }
                default:
                    return 0;
            }
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

            // Clear secondary drawer lifecycle filters so tab filter takes priority
            if (intakeSelect) intakeSelect.value = '';
            if (stageSelect) stageSelect.value = '';
            if (dispositionSelect) dispositionSelect.value = '';

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

            if (intakeSelect) intakeSelect.value = '';
            if (stageSelect) stageSelect.value = '';
            if (dispositionSelect) dispositionSelect.value = '';

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
            else if (f === 'in_progress' && ['Mediation', 'Conciliation', 'Arbitration', 'Docketed', 'in_progress'].includes(statusVal)) card.classList.add('active');
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
                loadedRows = Array.isArray(rows) ? rows : [];
                sortRows(loadedRows, activeSortKey, activeSortDir);
                if (complaintView) {
                    currentPage = 1;
                    updateKpiAndTabCounts(loadedRows);
                    renderPaginatedRows();
                } else {
                    renderRows(loadedRows);
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
                if (paginationContainer) paginationContainer.style.display = 'none';
                if (summary) summary.textContent = '';
            });
    });

    // Clear search and reset filters
    clearBtn?.addEventListener('click', () => {
        form.reset();
        currentPage = 1;
        if (clearInputBtn) clearInputBtn.style.display = 'none';
        if (drawerPanel) drawerPanel.classList.remove('open');
        if (drawerToggle) drawerToggle.classList.remove('active');

        if (intakeSelect) intakeSelect.value = '';
        if (stageSelect) stageSelect.value = '';
        if (dispositionSelect) dispositionSelect.value = '';
        if (statusSelect) statusSelect.value = '';

        activeSortKey = 'date';
        activeSortDir = 'desc';
        updateSortHeadersUI();
        if (sortOrderInput) sortOrderInput.value = 'DESC';
        if (sortSelect) sortSelect.value = 'incident_date';

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
            (intakeSelect && intakeSelect.value) ||
            (stageSelect && stageSelect.value) ||
            (dispositionSelect && dispositionSelect.value) ||
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

        const intake = intakeSelect?.value ?? '';
        if (intake) {
            addChip(`Intake: ${intake}`, () => {
                intakeSelect.value = '';
                form.requestSubmit();
            });
            filterCount++;
        }

        const stage = stageSelect?.value ?? '';
        if (stage) {
            addChip(`Stage: ${stage}`, () => {
                stageSelect.value = '';
                form.requestSubmit();
            });
            filterCount++;
        }

        const disp = dispositionSelect?.value ?? '';
        if (disp) {
            addChip(`Disposition: ${disp}`, () => {
                dispositionSelect.value = '';
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
            !intakeSelect?.value &&
            !stageSelect?.value &&
            !dispositionSelect?.value &&
            !fromInput?.value &&
            !toInput?.value;

        if (isDefaultView || !cachedGlobalCounts) {
            const allCount = rows.length;
            const reviewCount = rows.filter(r => r.intake_status === 'Under Review').length;
            const docketedCount = rows.filter(r => r.intake_status === 'Docketed').length;
            const mediationCount = rows.filter(r => r.current_stage === 'Mediation').length;
            const conciliationCount = rows.filter(r => r.current_stage === 'Conciliation').length;
            const arbitrationCount = rows.filter(r => r.current_stage === 'Arbitration').length;
            const settledCount = rows.filter(r => r.final_disposition === 'Amicable Settlement').length;
            const dismissedCount = rows.filter(r => r.final_disposition === 'Dismissed / Dropped').length;
            const cfaCount = rows.filter(r => r.final_disposition === 'Certificate to File Action (CFA)').length;
            const progressCount = rows.filter(r => {
                return ['Mediation', 'Conciliation', 'Arbitration'].includes(r.current_stage) || r.intake_status === 'Docketed';
            }).length;

            cachedGlobalCounts = {
                all: allCount,
                review: reviewCount,
                docketed: docketedCount,
                mediation: mediationCount,
                conciliation: conciliationCount,
                arbitration: arbitrationCount,
                settled: settledCount,
                dismissed: dismissedCount,
                cfa: cfaCount,
                progress: progressCount
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
        setEl('tabCountDocketed', counts.docketed);
        setEl('tabCountMediation', counts.mediation);
        setEl('tabCountConciliation', counts.conciliation);
        setEl('tabCountArbitration', counts.arbitration);
        setEl('tabCountSettled', counts.settled);
        setEl('tabCountDismissed', counts.dismissed);
        setEl('tabCountCfa', counts.cfa);
    }

    function renderPaginatedRows() {
        if (!complaintView) {
            renderRows(loadedRows);
            return;
        }

        const total = loadedRows.length;
        const totalPages = Math.ceil(total / pageSize) || 1;

        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const startIdx = (currentPage - 1) * pageSize;
        const endIdx = Math.min(startIdx + pageSize, total);
        const pageSlice = loadedRows.slice(startIdx, endIdx);

        renderRows(pageSlice, total);
        renderPaginationControls(total, totalPages, startIdx, endIdx);
    }

    function renderPaginationControls(total, totalPages, startIdx, endIdx) {
        if (!paginationContainer || !paginationSummary || !paginationControls) return;

        if (total === 0) {
            paginationContainer.style.display = 'none';
            return;
        }

        paginationContainer.style.display = 'flex';
        const firstNum = total > 0 ? (startIdx + 1) : 0;
        const summaryText = `Showing ${firstNum}–${endIdx} of ${total} complaint record${total === 1 ? '' : 's'}`;
        paginationSummary.textContent = summaryText;

        if (summary) {
            summary.textContent = summaryText;
        }

        paginationControls.replaceChildren();

        if (totalPages <= 1) {
            return;
        }

        const createBtn = (label, pageNum, disabled = false, current = false, isNav = false) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `complaints-page-btn ${current ? 'active current' : ''} ${isNav ? 'complaints-page-nav-btn' : ''}`;
            btn.textContent = label;
            btn.disabled = disabled;
            if (current) btn.setAttribute('aria-current', 'page');
            btn.addEventListener('click', () => {
                if (currentPage !== pageNum && !disabled) {
                    currentPage = pageNum;
                    renderPaginatedRows();
                    const tableWrap = document.querySelector('.complaints-table-container');
                    if (tableWrap) {
                        tableWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }
            });
            return btn;
        };

        // Previous button
        paginationControls.appendChild(createBtn('← Previous', currentPage - 1, currentPage <= 1, false, true));

        // Numeric buttons with ellipsis if many pages
        const maxButtons = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
        let endPage = Math.min(totalPages, startPage + maxButtons - 1);
        if (endPage - startPage < maxButtons - 1) {
            startPage = Math.max(1, endPage - maxButtons + 1);
        }

        if (startPage > 1) {
            paginationControls.appendChild(createBtn('1', 1, false, currentPage === 1));
            if (startPage > 2) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'pagination-ellipsis';
                ellipsis.textContent = '…';
                paginationControls.appendChild(ellipsis);
            }
        }

        for (let p = startPage; p <= endPage; p++) {
            paginationControls.appendChild(createBtn(String(p), p, false, p === currentPage));
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'pagination-ellipsis';
                ellipsis.textContent = '…';
                paginationControls.appendChild(ellipsis);
            }
            paginationControls.appendChild(createBtn(String(totalPages), totalPages, false, currentPage === totalPages));
        }

        // Next button
        paginationControls.appendChild(createBtn('Next →', currentPage + 1, currentPage >= totalPages, false, true));
    }

    function renderRows(rows, totalCount) {
        if (!Array.isArray(rows)) throw new Error('Invalid records response.');
        const effectiveTotal = typeof totalCount === 'number' ? totalCount : rows.length;
        if (!effectiveTotal) {
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
            if (paginationContainer) paginationContainer.style.display = 'none';
            if (summary) summary.textContent = 'Showing 0 complaint records';
            return;
        }
        results.innerHTML = rows.map(row => complaintView ? renderComplaintRow(row) : renderSearchRow(row)).join('');
    }

    function renderComplaintRow(row) {
        const id = Number(row.complaint_id);
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
                <button type="button" class="btn-row-action" onclick="window.location.href='complaint-edit.php?id=${id}'" title="Edit complaint">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Edit
                </button>
                <button type="button" class="btn-row-action btn-delete-action" onclick="deleteComplaint(${id})" title="Delete complaint">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>
               </div>`
            : '';

        // Distinct Lifecycle Representation without label prefixes
        const intake = row.intake_status || (row.case_id ? 'Docketed' : 'Under Review');
        const intakeClass = (intake === 'Docketed') ? 'badge-intake-docketed' : 'badge-intake-under-review';

        const medCount = Number(row.mediation_count || 0);
        const conCount = Number(row.conciliation_count || 0);
        const isStageExhausted = Boolean(row.is_stage_exhausted) || (conCount >= 3) || (medCount >= 3 && conCount >= 3);

        const stage = isStageExhausted ? 'None' : (row.current_stage || 'None');
        let stageClass = 'badge-stage-none';
        if (stage === 'Mediation') stageClass = 'badge-stage-mediation';
        else if (stage === 'Conciliation') stageClass = 'badge-stage-conciliation';
        else if (stage === 'Arbitration') stageClass = 'badge-stage-arbitration';

        const disp = row.final_disposition || 'Pending';
        let dispClass = 'badge-disp-pending';
        let dispLabel = 'Pending';
        if (disp === 'Amicable Settlement' || disp === 'Settled') {
            dispClass = 'badge-disp-settled';
            dispLabel = 'Amicable Settlement';
        } else if (disp === 'Arbitration Award') {
            dispClass = 'badge-disp-arbitration';
            dispLabel = 'Arbitration Award';
        } else if (disp.includes('CFA') || disp.includes('Certificate')) {
            dispClass = 'badge-disp-cfa';
            dispLabel = 'CFA Issued';
        } else if (disp.includes('Dismissed') || disp.includes('Dropped')) {
            dispClass = 'badge-disp-dismissed';
            dispLabel = 'Dismissed';
        }

        const statusHtml = `
            <div class="lifecycle-group">
                <div class="lifecycle-subrow">
                    <span class="lifecycle-badge ${intakeClass}">${escapeHtml(intake)}</span>
                    ${stage !== 'None' ? `<span class="lifecycle-badge ${stageClass}">${escapeHtml(stage)}</span>` : ''}
                </div>
                <div class="lifecycle-subrow">
                    <span class="lifecycle-badge ${dispClass}">${escapeHtml(dispLabel)}</span>
                </div>
            </div>
        `;

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
                <td>${statusHtml}</td>
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
