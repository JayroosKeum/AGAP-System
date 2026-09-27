/**
 * AGAP Resident Profiles Module
 * Modern, easy to navigate, with 25-item pagination, instant search, and duplicate validation.
 */

let allResidents = [];
let currentPage = 1;
const pageSize = 25; // Strictly 25 items per page

let currentFilters = {
    search: '',
    purok: '',
    tenant: '',
    sort: 'id_desc',
    kpi: 'all'
};

document.addEventListener('DOMContentLoaded', () => {
    initResidentsModule();
});

function initResidentsModule() {
    loadResidents();

    // Hook AJAX submission for Add and Edit forms
    const addForm = document.querySelector('#addResidentModal form');
    if (addForm) {
        setupFormValidation(addForm);
        addForm.addEventListener('submit', handleAddSubmit);
    }

    const editForm = document.querySelector('#editResidentModal form');
    if (editForm) {
        setupFormValidation(editForm);
        editForm.addEventListener('submit', handleEditSubmit);
    }

    // Quick search filter with debounce
    const searchInput = document.getElementById('residentSearchInput');
    const clearSearchBtn = document.getElementById('clearResidentSearch');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            currentFilters.search = searchInput.value.trim();
            if (clearSearchBtn) {
                clearSearchBtn.style.display = currentFilters.search ? 'block' : 'none';
            }
            currentPage = 1;
            applyFiltersAndRender();
        });
    }

    if (clearSearchBtn && searchInput) {
        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            currentFilters.search = '';
            clearSearchBtn.style.display = 'none';
            currentPage = 1;
            applyFiltersAndRender();
            searchInput.focus();
        });
    }

    // Dropdown filters
    const filterPurok = document.getElementById('filterPurok');
    if (filterPurok) {
        filterPurok.addEventListener('change', () => {
            currentFilters.purok = filterPurok.value;
            currentPage = 1;
            applyFiltersAndRender();
        });
    }

    const filterTenant = document.getElementById('filterTenant');
    if (filterTenant) {
        filterTenant.addEventListener('change', () => {
            currentFilters.tenant = filterTenant.value;
            // Sync with KPI card visual state if needed
            updateKpiCardActiveState();
            currentPage = 1;
            applyFiltersAndRender();
        });
    }

    const filterSort = document.getElementById('filterSort');
    if (filterSort) {
        filterSort.addEventListener('change', () => {
            currentFilters.sort = filterSort.value;
            applyFiltersAndRender();
        });
    }

    const btnResetFilters = document.getElementById('btnResetFilters');
    if (btnResetFilters) {
        btnResetFilters.addEventListener('click', resetAllFilters);
    }

    // KPI card click events for quick filtering
    initKpiCardClickEvents();

    // Close modals on Escape key or backdrop click
    setupModalBackdropCloses();
}

function setupModalBackdropCloses() {
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal').forEach(modal => {
                if (modal.style.display === 'flex') {
                    modal.style.display = 'none';
                }
            });
        }
    });
}

function initKpiCardClickEvents() {
    const kpiAll = document.getElementById('kpiAllCard');
    const kpiPerm = document.getElementById('kpiPermCard');
    const kpiTenant = document.getElementById('kpiTenantCard');

    if (kpiAll) {
        kpiAll.addEventListener('click', () => {
            resetAllFilters();
        });
    }

    if (kpiPerm) {
        kpiPerm.addEventListener('click', () => {
            const filterTenant = document.getElementById('filterTenant');
            if (filterTenant) filterTenant.value = '0';
            currentFilters.tenant = '0';
            currentFilters.kpi = 'permanent';
            currentPage = 1;
            updateKpiCardActiveState();
            applyFiltersAndRender();
        });
    }

    if (kpiTenant) {
        kpiTenant.addEventListener('click', () => {
            const filterTenant = document.getElementById('filterTenant');
            if (filterTenant) filterTenant.value = '1';
            currentFilters.tenant = '1';
            currentFilters.kpi = 'tenant';
            currentPage = 1;
            updateKpiCardActiveState();
            applyFiltersAndRender();
        });
    }
}

function updateKpiCardActiveState() {
    const kpiAll = document.getElementById('kpiAllCard');
    const kpiPerm = document.getElementById('kpiPermCard');
    const kpiTenant = document.getElementById('kpiTenantCard');

    if (kpiAll) kpiAll.classList.remove('active');
    if (kpiPerm) kpiPerm.classList.remove('active');
    if (kpiTenant) kpiTenant.classList.remove('active');

    if (currentFilters.tenant === '0') {
        if (kpiPerm) kpiPerm.classList.add('active');
    } else if (currentFilters.tenant === '1') {
        if (kpiTenant) kpiTenant.classList.add('active');
    } else {
        if (kpiAll) kpiAll.classList.add('active');
    }
}

function resetAllFilters() {
    currentFilters.search = '';
    currentFilters.purok = '';
    currentFilters.tenant = '';
    currentFilters.sort = 'id_desc';
    currentFilters.kpi = 'all';

    const searchInput = document.getElementById('residentSearchInput');
    const clearSearchBtn = document.getElementById('clearResidentSearch');
    const filterPurok = document.getElementById('filterPurok');
    const filterTenant = document.getElementById('filterTenant');
    const filterSort = document.getElementById('filterSort');

    if (searchInput) searchInput.value = '';
    if (clearSearchBtn) clearSearchBtn.style.display = 'none';
    if (filterPurok) filterPurok.value = '';
    if (filterTenant) filterTenant.value = '';
    if (filterSort) filterSort.value = 'id_desc';

    updateKpiCardActiveState();
    currentPage = 1;
    applyFiltersAndRender();
}

function setupFormValidation(form) {
    const today = new Date();
    today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
    const birthDate = form.querySelector('[name="birth_date"]');
    if (birthDate) birthDate.max = today.toISOString().slice(0, 10);

    form.querySelectorAll('input:not([type="hidden"]):not([type="date"]), textarea').forEach((field) => {
        field.addEventListener('input', () => {
            field.setCustomValidity('');
        });
    });
}

function validateResidentFields(form) {
    let isValid = true;
    form.querySelectorAll('input:not([type="hidden"]):not([type="date"]), textarea').forEach((field) => {
        field.value = field.value.trim();
        field.setCustomValidity('');

        if (['first_name', 'middle_name', 'last_name'].includes(field.name)
            && field.value !== ''
            && !/^[\p{L}\p{M}]+(?:[ '-][\p{L}\p{M}]+)*$/u.test(field.value)) {
            field.setCustomValidity('Please enter a name using letters, spaces, hyphens, or apostrophes.');
            isValid = false;
        }
        if (field.name === 'contact_no' && field.value !== '' && !validPhilippinePhone(field.value)) {
            field.setCustomValidity('Please enter a valid Philippine mobile or telephone number.');
            isValid = false;
        }
        if (['address', 'purok'].includes(field.name) && /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/.test(field.value)) {
            field.setCustomValidity('Please remove unsupported control characters.');
            isValid = false;
        }
    });

    if (!form.reportValidity()) {
        return false;
    }
    return isValid;
}

async function handleAddSubmit(event) {
    event.preventDefault();
    const form = event.target;
    if (!validateResidentFields(form)) return;

    const alertBox = document.getElementById('addResidentAlert');
    if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.textContent = '';
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving Profile...';

    try {
        const formData = new FormData(form);
        const res = await fetch('../../../backend/api/residents/create.php?format=json', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await res.json();

        if (!res.ok || !data.success) {
            throw new Error(data.message || 'Unable to save resident profile.');
        }

        closeAddModal();
        form.reset();
        currentPage = 1; // Stay on page 1 so newly created resident appears at the top
        await loadResidents();

        if (window.agapNotify) {
            window.agapNotify(data.message || 'Resident profile created successfully.', 'success', 'Success');
        }
    } catch (err) {
        if (alertBox) {
            alertBox.style.display = 'flex';
            alertBox.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:1px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>${escapeResidentHtml(err.message)}</span>
            `;
            alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        if (window.agapNotify) {
            window.agapNotify(err.message, 'error', 'Validation Notice');
        }
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
}

async function handleEditSubmit(event) {
    event.preventDefault();
    const form = event.target;
    if (!validateResidentFields(form)) return;

    const alertBox = document.getElementById('editResidentAlert');
    if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.textContent = '';
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Updating Profile...';

    try {
        const formData = new FormData(form);
        const res = await fetch('../../../backend/api/residents/update.php?format=json', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await res.json();

        if (!res.ok || !data.success) {
            throw new Error(data.message || 'Unable to update resident profile.');
        }

        closeEditModal();
        await loadResidents();

        if (window.agapNotify) {
            window.agapNotify(data.message || 'Resident profile updated successfully.', 'success', 'Success');
        }
    } catch (err) {
        if (alertBox) {
            alertBox.style.display = 'flex';
            alertBox.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:1px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>${escapeResidentHtml(err.message)}</span>
            `;
            alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        if (window.agapNotify) {
            window.agapNotify(err.message, 'error', 'Validation Notice');
        }
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
}

async function loadResidents() {
    const table = document.getElementById('residentTable');
    if (!table) return;

    try {
        const res = await fetch('../../../backend/api/residents/list.php?_t=' + Date.now(), {
            cache: 'no-store'
        });
        const data = await res.json();
        allResidents = Array.isArray(data) ? data : [];

        updateKpiMetrics();
        populatePurokFilterOptions();
        applyFiltersAndRender();
    } catch (err) {
        console.error('Failed to load residents:', err);
        table.innerHTML = `
            <tr>
                <td colspan="6" class="table-empty-wrap">
                    <div class="empty-icon-circle" style="color:#ef4444; background:#fee2e2;">!</div>
                    <div class="empty-state-title">Failed to load resident profiles</div>
                    <div class="empty-state-desc">Unable to retrieve identity registry data. Please refresh the page.</div>
                </td>
            </tr>
        `;
    }
}

function updateKpiMetrics() {
    const totalCountEl = document.getElementById('kpiTotalCount');
    const permCountEl = document.getElementById('kpiPermanentCount');
    const tenantCountEl = document.getElementById('kpiTenantCount');
    const purokCountEl = document.getElementById('kpiPurokCount');

    const total = allResidents.length;
    let permanent = 0;
    let tenants = 0;
    const purokSet = new Set();

    allResidents.forEach(r => {
        if (Number(r.is_tenant) === 1) {
            tenants++;
        } else {
            permanent++;
        }
        if (r.purok && String(r.purok).trim() !== '') {
            purokSet.add(String(r.purok).trim().toLowerCase());
        }
    });

    if (totalCountEl) totalCountEl.textContent = total;
    if (permCountEl) permCountEl.textContent = permanent;
    if (tenantCountEl) tenantCountEl.textContent = tenants;
    if (purokCountEl) purokCountEl.textContent = purokSet.size;
}

function populatePurokFilterOptions() {
    const select = document.getElementById('filterPurok');
    if (!select) return;

    const currentVal = select.value;
    const puroks = new Set();

    allResidents.forEach(r => {
        if (r.purok && String(r.purok).trim() !== '') {
            puroks.add(String(r.purok).trim());
        }
    });

    // Sort naturally
    const sortedPuroks = Array.from(puroks).sort((a, b) => {
        const numA = parseInt(a, 10);
        const numB = parseInt(b, 10);
        if (!isNaN(numA) && !isNaN(numB)) return numA - numB;
        return a.localeCompare(b);
    });

    let html = '<option value="">All Puroks</option>';
    sortedPuroks.forEach(p => {
        const selected = (currentVal === p) ? 'selected' : '';
        html += `<option value="${escapeResidentHtml(p)}" ${selected}>Purok ${escapeResidentHtml(p)}</option>`;
    });

    select.innerHTML = html;
}

function applyFiltersAndRender() {
    const q = (currentFilters.search || '').toLowerCase();
    const purokFilter = (currentFilters.purok || '').toLowerCase();
    const tenantFilter = currentFilters.tenant;

    let filtered = allResidents.filter(r => {
        // Keyword search
        if (q) {
            const fullName = [r.first_name, r.middle_name, r.last_name].filter(Boolean).join(' ').toLowerCase();
            const address = (r.address || '').toLowerCase();
            const purok = (r.purok || '').toLowerCase();
            const contact = (r.contact_no || '').toLowerCase();
            const email = (r.email || '').toLowerCase();
            const id = String(r.resident_id);

            const matches = fullName.includes(q)
                || address.includes(q)
                || purok.includes(q)
                || contact.includes(q)
                || email.includes(q)
                || id === q;

            if (!matches) return false;
        }

        // Purok filter
        if (purokFilter) {
            const residentPurok = (r.purok || '').toLowerCase();
            if (residentPurok !== purokFilter) return false;
        }

        // Tenant filter
        if (tenantFilter !== '') {
            const isTenant = Number(r.is_tenant);
            if (isTenant !== Number(tenantFilter)) return false;
        }

        return true;
    });

    // Sorting
    filtered.sort((a, b) => {
        switch (currentFilters.sort) {
            case 'id_asc':
                return Number(a.resident_id) - Number(b.resident_id);
            case 'name_asc': {
                const nameA = `${a.last_name} ${a.first_name}`.toLowerCase();
                const nameB = `${b.last_name} ${b.first_name}`.toLowerCase();
                return nameA.localeCompare(nameB);
            }
            case 'name_desc': {
                const nameA = `${a.last_name} ${a.first_name}`.toLowerCase();
                const nameB = `${b.last_name} ${b.first_name}`.toLowerCase();
                return nameB.localeCompare(nameA);
            }
            case 'purok_asc': {
                const pA = String(a.purok || '');
                const pB = String(b.purok || '');
                return pA.localeCompare(pB, undefined, { numeric: true });
            }
            case 'id_desc':
            default:
                return Number(b.resident_id) - Number(a.resident_id);
        }
    });

    // Update active filter chips & reset button
    renderActiveFilterChips();

    // Pagination calculations (25 items per page)
    const totalRecords = filtered.length;
    const totalPages = Math.ceil(totalRecords / pageSize) || 1;

    if (currentPage > totalPages) {
        currentPage = totalPages;
    }
    if (currentPage < 1) {
        currentPage = 1;
    }

    const startIdx = (currentPage - 1) * pageSize;
    const endIdx = Math.min(startIdx + pageSize, totalRecords);
    const paginatedItems = filtered.slice(startIdx, endIdx);

    // Render Table Rows
    renderResidentTableRows(paginatedItems, filtered.length);

    // Render Pagination Controls
    renderPaginationBar(totalRecords, totalPages, startIdx, endIdx);
}

function renderActiveFilterChips() {
    const chipsContainer = document.getElementById('activeResidentFilters');
    const resetBtn = document.getElementById('btnResetFilters');
    if (!chipsContainer) return;

    let chipsHtml = '';
    let hasActiveFilter = false;

    if (currentFilters.search) {
        hasActiveFilter = true;
        chipsHtml += `
            <span class="filter-chip">
                Search: "${escapeResidentHtml(currentFilters.search)}"
                <span class="filter-chip-remove" onclick="removeFilter('search')">&times;</span>
            </span>
        `;
    }

    if (currentFilters.purok) {
        hasActiveFilter = true;
        chipsHtml += `
            <span class="filter-chip">
                Purok ${escapeResidentHtml(currentFilters.purok)}
                <span class="filter-chip-remove" onclick="removeFilter('purok')">&times;</span>
            </span>
        `;
    }

    if (currentFilters.tenant !== '') {
        hasActiveFilter = true;
        const tenantLabel = currentFilters.tenant === '1' ? 'Tenants Only' : 'Permanent Only';
        chipsHtml += `
            <span class="filter-chip">
                ${tenantLabel}
                <span class="filter-chip-remove" onclick="removeFilter('tenant')">&times;</span>
            </span>
        `;
    }

    chipsContainer.innerHTML = chipsHtml;
    if (resetBtn) {
        resetBtn.style.display = hasActiveFilter ? 'inline-block' : 'none';
    }
}

function removeFilter(type) {
    if (type === 'search') {
        const input = document.getElementById('residentSearchInput');
        const clearBtn = document.getElementById('clearResidentSearch');
        if (input) input.value = '';
        if (clearBtn) clearBtn.style.display = 'none';
        currentFilters.search = '';
    } else if (type === 'purok') {
        const select = document.getElementById('filterPurok');
        if (select) select.value = '';
        currentFilters.purok = '';
    } else if (type === 'tenant') {
        const select = document.getElementById('filterTenant');
        if (select) select.value = '';
        currentFilters.tenant = '';
        updateKpiCardActiveState();
    }
    currentPage = 1;
    applyFiltersAndRender();
}

function renderResidentTableRows(residents, totalFiltered) {
    const table = document.getElementById('residentTable');
    const metaSummary = document.getElementById('residentMetaSummary');

    if (metaSummary) {
        if (totalFiltered === 0) {
            metaSummary.textContent = 'Showing 0 resident profiles';
        } else {
            const start = ((currentPage - 1) * pageSize) + 1;
            const end = Math.min(currentPage * pageSize, totalFiltered);
            metaSummary.textContent = `Showing ${start}–${end} of ${totalFiltered} resident profile${totalFiltered === 1 ? '' : 's'}`;
        }
    }

    if (!table) return;

    if (residents.length === 0) {
        table.innerHTML = `
            <tr>
                <td colspan="6" class="table-empty-wrap">
                    <div class="empty-icon-circle">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </div>
                    <div class="empty-state-title">No resident profiles found</div>
                    <div class="empty-state-desc">Try adjusting your search criteria or clear active filters.</div>
                </td>
            </tr>
        `;
        return;
    }

    let rowsHtml = '';
    residents.forEach((r, idx) => {
        const fullName = [r.first_name, r.middle_name, r.last_name].filter(Boolean).join(' ');
        const initials = getInitials(r.first_name, r.last_name);
        const tintClass = `avatar-tint-${((Number(r.resident_id) || idx) % 6) + 1}`;

        // Demographics subtext
        const demoParts = [];
        if (r.gender) demoParts.push(escapeResidentHtml(r.gender));
        if (r.civil_status) demoParts.push(escapeResidentHtml(r.civil_status));
        if (r.birth_date) {
            const age = calculateAge(r.birth_date);
            if (age !== null) demoParts.push(`${age} yrs old`);
        }
        const demoText = demoParts.join(' &bull; ') || 'Identity verified';

        // Location
        const purokBadge = r.purok ? `<span class="purok-pill">Purok ${escapeResidentHtml(r.purok)}</span>` : '';
        const streetAddress = r.address ? escapeResidentHtml(r.address) : '<span style="color:#94a3b8; font-style:italic;">No street address</span>';

        // Contact info
        const phone = r.contact_no ? `<span class="contact-phone"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>${escapeResidentHtml(r.contact_no)}</span>` : '<span style="color:#94a3b8;">—</span>';
        const email = r.email ? `<span class="contact-email">${escapeResidentHtml(r.email)}</span>` : '';

        // Residency Status
        const isTenant = Number(r.is_tenant) === 1;
        const statusBadge = isTenant
            ? `<span class="residency-badge badge-tenant"><span class="residency-dot"></span>Tenant</span>`
            : `<span class="residency-badge badge-permanent"><span class="residency-dot"></span>Permanent</span>`;

        rowsHtml += `
            <tr>
                <td>
                    <span class="cell-id-badge">#RP-${String(r.resident_id).padStart(4, '0')}</span>
                </td>
                <td>
                    <div class="resident-identity-cell">
                        <div class="resident-avatar ${tintClass}">${initials}</div>
                        <div class="resident-name-meta">
                            <span class="resident-full-name">${escapeResidentHtml(fullName)}</span>
                            <span class="resident-demographics">${demoText}</span>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="cell-address-wrap">
                        ${purokBadge}
                        <div class="street-address-text">${streetAddress}</div>
                    </div>
                </td>
                <td>
                    <div class="cell-contact-wrap">
                        ${phone}
                        ${email}
                    </div>
                </td>
                <td>
                    ${statusBadge}
                </td>
                <td>
                    <div class="table-actions">
                        <button
                            type="button"
                            class="btn-row-action btn-view-profile"
                            onclick="viewResident(${r.resident_id})"
                            title="View full resident profile details">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            View
                        </button>
                        <button
                            type="button"
                            class="btn-row-action btn-edit-profile"
                            onclick="editResident(${r.resident_id})"
                            title="Edit resident profile">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                            Edit
                        </button>
                        <button
                            type="button"
                            class="btn-row-action btn-delete-profile"
                            onclick="deleteResident(${r.resident_id})"
                            title="Delete resident profile">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                            Delete
                        </button>
                    </div>
                </td>
            </tr>
        `;
    });

    table.innerHTML = rowsHtml;
}

function renderPaginationBar(totalRecords, totalPages, startIdx, endIdx) {
    const container = document.getElementById('residentPagination');
    const summary = document.getElementById('residentPaginationSummary');
    const controls = document.getElementById('residentPaginationControls');

    if (!container || !summary || !controls) return;

    if (totalRecords === 0) {
        container.style.display = 'none';
        return;
    }

    container.style.display = 'flex';

    const firstNum = startIdx + 1;
    summary.textContent = `Showing ${firstNum}–${endIdx} of ${totalRecords} profile${totalRecords === 1 ? '' : 's'}`;

    // Clean previous buttons
    controls.innerHTML = '';

    // If only 1 page, no need for extensive controls, but keep summary
    if (totalPages <= 1) {
        return;
    }

    const createBtn = (label, pageNum, disabled = false, isCurrent = false, isNav = false) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'resident-page-btn' + (isCurrent ? ' active' : '');
        btn.textContent = label;
        btn.disabled = disabled;
        if (isCurrent) btn.setAttribute('aria-current', 'page');
        if (isNav) btn.setAttribute('aria-label', label);

        btn.addEventListener('click', () => {
            if (!disabled && pageNum !== currentPage) {
                currentPage = pageNum;
                applyFiltersAndRender();
                // Smoothly scroll table into view
                const tableCard = document.querySelector('.resident-table-card');
                if (tableCard) tableCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
        return btn;
    };

    // First and Previous buttons
    controls.appendChild(createBtn('« First', 1, currentPage <= 1, false, true));
    controls.appendChild(createBtn('‹ Prev', currentPage - 1, currentPage <= 1, false, true));

    // Dynamic numeric buttons with windowing
    const maxVisibleButtons = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisibleButtons / 2));
    let endPage = startPage + maxVisibleButtons - 1;

    if (endPage > totalPages) {
        endPage = totalPages;
        startPage = Math.max(1, endPage - maxVisibleButtons + 1);
    }

    if (startPage > 1) {
        controls.appendChild(createBtn('1', 1, false, currentPage === 1));
        if (startPage > 2) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '…';
            controls.appendChild(ellipsis);
        }
    }

    for (let p = startPage; p <= endPage; p++) {
        controls.appendChild(createBtn(String(p), p, false, p === currentPage));
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '…';
            controls.appendChild(ellipsis);
        }
        controls.appendChild(createBtn(String(totalPages), totalPages, false, currentPage === totalPages));
    }

    // Next and Last buttons
    controls.appendChild(createBtn('Next ›', currentPage + 1, currentPage >= totalPages, false, true));
    controls.appendChild(createBtn('Last »', totalPages, currentPage >= totalPages, false, true));
}

function getInitials(firstName, lastName) {
    const f = (firstName || '').trim().charAt(0);
    const l = (lastName || '').trim().charAt(0);
    return `${f}${l}`.toUpperCase() || 'RP';
}

function calculateAge(birthDateStr) {
    if (!birthDateStr) return null;
    const dob = new Date(birthDateStr);
    if (isNaN(dob.getTime())) return null;
    const diffMs = Date.now() - dob.getTime();
    const ageDt = new Date(diffMs);
    return Math.abs(ageDt.getUTCFullYear() - 1970);
}

function formatDateReadable(dateStr) {
    if (!dateStr) return '—';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (_) {
        return dateStr;
    }
}

function validPhilippinePhone(value) {
    if (!/^\+?[0-9 .()\-]+$/.test(value)) return false;
    let digits = value.replace(/\D/g, '');
    if (value.startsWith('+') || digits.startsWith('63')) {
        if (!digits.startsWith('63')) return false;
        digits = digits.slice(2);
    } else if (digits.startsWith('0')) {
        digits = digits.slice(1);
    }
    return /^9\d{9}$/.test(digits) || /^(?:2\d{8}|[3-8]\d{8,9})$/.test(digits);
}

function openAddModal() {
    const modal = document.getElementById('addResidentModal');
    if (!modal) return;
    const alertBox = document.getElementById('addResidentAlert');
    if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.textContent = '';
    }
    modal.style.display = 'flex';
    const firstInput = modal.querySelector('input[name="first_name"]');
    if (firstInput) setTimeout(() => firstInput.focus(), 50);
}

function closeAddModal() {
    const modal = document.getElementById('addResidentModal');
    if (modal) modal.style.display = 'none';
}

function viewResident(id) {
    fetch('../../../backend/api/residents/view.php?id=' + id + '&_t=' + Date.now(), { cache: 'no-store' })
        .then(response => response.json())
        .then(data => {
            if (!data) return;
            const fullName = [data.first_name, data.middle_name, data.last_name].filter(Boolean).join(' ');
            const initials = getInitials(data.first_name, data.last_name);
            const isTenant = Number(data.is_tenant) === 1;
            const age = calculateAge(data.birth_date);
            const ageDisplay = age !== null ? `${age} years old` : 'Not recorded';

            const detailsHtml = `
                <div class="view-profile-card">
                    <div class="view-profile-header">
                        <div class="view-profile-avatar">${initials}</div>
                        <div class="view-profile-titles">
                            <h3>${escapeResidentHtml(fullName)}</h3>
                            <div class="view-profile-badges">
                                <span class="cell-id-badge">#RP-${String(data.resident_id).padStart(4, '0')}</span>
                                ${isTenant
                                    ? '<span class="residency-badge badge-tenant"><span class="residency-dot"></span>Tenant / Renter</span>'
                                    : '<span class="residency-badge badge-permanent"><span class="residency-dot"></span>Permanent Resident</span>'
                                }
                                ${data.purok ? `<span class="purok-pill">Purok ${escapeResidentHtml(data.purok)}</span>` : ''}
                            </div>
                        </div>
                    </div>

                    <div class="modal-section-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        Personal Verification Information
                    </div>

                    <div class="view-profile-grid">
                        <div class="view-info-box">
                            <span class="view-info-label">Birth Date</span>
                            <span class="view-info-value">${data.birth_date ? formatDateReadable(data.birth_date) : '—'}</span>
                        </div>
                        <div class="view-info-box">
                            <span class="view-info-label">Calculated Age</span>
                            <span class="view-info-value">${ageDisplay}</span>
                        </div>
                        <div class="view-info-box">
                            <span class="view-info-label">Gender</span>
                            <span class="view-info-value">${escapeResidentHtml(data.gender || '—')}</span>
                        </div>
                        <div class="view-info-box">
                            <span class="view-info-label">Civil Status</span>
                            <span class="view-info-value">${escapeResidentHtml(data.civil_status || '—')}</span>
                        </div>
                    </div>

                    <div class="modal-section-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        Residency & Contact Records
                    </div>

                    <div class="view-profile-grid">
                        <div class="view-info-box">
                            <span class="view-info-label">Contact Number</span>
                            <span class="view-info-value">${data.contact_no ? `<a href="tel:${escapeResidentHtml(data.contact_no)}" style="color:#1d4ed8; text-decoration:none;">${escapeResidentHtml(data.contact_no)}</a>` : '—'}</span>
                        </div>
                        <div class="view-info-box">
                            <span class="view-info-label">Email Address</span>
                            <span class="view-info-value">${data.email ? `<a href="mailto:${escapeResidentHtml(data.email)}" style="color:#1d4ed8; text-decoration:none;">${escapeResidentHtml(data.email)}</a>` : '—'}</span>
                        </div>
                        <div class="view-info-box" style="grid-column: 1 / -1;">
                            <span class="view-info-label">Complete Street Address</span>
                            <span class="view-info-value">${escapeResidentHtml(data.address || '—')}${data.purok ? ` (Purok ${escapeResidentHtml(data.purok)})` : ''}</span>
                        </div>
                    </div>

                    <div class="modal-actions" style="margin-top: 10px;">
                        <button
                            type="button"
                            class="btn-reset-filters"
                            onclick="closeViewModal()">
                            Close
                        </button>
                        <button
                            type="button"
                            class="btn-create-profile"
                            onclick="switchToEditModal(${data.resident_id})">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                            Edit This Profile
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('residentDetails').innerHTML = detailsHtml;
            document.getElementById('viewResidentModal').style.display = 'flex';
        })
        .catch(err => {
            console.error('Error viewing resident:', err);
            if (window.agapNotify) window.agapNotify('Unable to load resident details.', 'error');
        });
}

function switchToEditModal(id) {
    closeViewModal();
    editResident(id);
}

function closeViewModal() {
    const modal = document.getElementById('viewResidentModal');
    if (modal) modal.style.display = 'none';
}

function editResident(id) {
    const alertBox = document.getElementById('editResidentAlert');
    if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.textContent = '';
    }

    fetch('../../../backend/api/residents/view.php?id=' + id + '&_t=' + Date.now(), { cache: 'no-store' })
        .then(response => response.json())
        .then(data => {
            if (!data) return;
            document.getElementById('editResidentId').value = data.resident_id;
            document.getElementById('editFirstName').value = data.first_name ?? '';
            document.getElementById('editMiddleName').value = data.middle_name ?? '';
            document.getElementById('editLastName').value = data.last_name ?? '';
            document.getElementById('editBirthDate').value = data.birth_date ?? '';
            document.getElementById('editGender').value = data.gender ?? 'Male';
            document.getElementById('editCivilStatus').value = data.civil_status ?? 'Single';
            document.getElementById('editContactNo').value = data.contact_no ?? '';
            document.getElementById('editEmail').value = data.email ?? '';
            document.getElementById('editPurok').value = data.purok ?? '';
            document.getElementById('editTenant').value = data.is_tenant ?? '0';
            document.getElementById('editAddress').value = data.address ?? '';

            document.getElementById('editResidentModal').style.display = 'flex';
        })
        .catch(err => {
            console.error('Error opening edit modal:', err);
            if (window.agapNotify) window.agapNotify('Unable to load resident details for editing.', 'error');
        });
}

function closeEditModal() {
    const modal = document.getElementById('editResidentModal');
    if (modal) modal.style.display = 'none';
}

function deleteResident(id) {
    const resident = allResidents.find(r => Number(r.resident_id) === Number(id));
    const previewEl = document.getElementById('deleteResidentNamePreview');

    if (previewEl) {
        if (resident) {
            const fullName = [resident.first_name, resident.middle_name, resident.last_name].filter(Boolean).join(' ');
            const loc = resident.purok ? `Purok ${resident.purok}` : (resident.address || 'Barangay resident');
            previewEl.textContent = `Profile #RP-${String(resident.resident_id).padStart(4, '0')}: ${fullName} (${loc})`;
        } else {
            previewEl.textContent = `Resident Profile #RP-${String(id).padStart(4, '0')}`;
        }
    }

    document.getElementById('deleteResidentId').value = id;
    document.getElementById('deleteResidentModal').style.display = 'flex';
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteResidentModal');
    if (modal) modal.style.display = 'none';
}

async function confirmDeleteResident() {
    const id = document.getElementById('deleteResidentId').value;
    if (!id) return;

    try {
        const res = await fetch(`../../../backend/api/residents/delete.php?id=${id}&format=json`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json'
            }
        });
        const data = await res.json();
        closeDeleteModal();

        if (res.ok && data.success) {
            await loadResidents();
            if (window.agapNotify) {
                window.agapNotify(data.message || 'Resident profile deleted successfully.', 'success', 'Success');
            }
        } else {
            throw new Error(data.message || 'Unable to delete resident profile.');
        }
    } catch (err) {
        closeDeleteModal();
        if (window.agapNotify) {
            window.agapNotify(err.message, 'error', 'Error');
        }
    }
}

function escapeResidentHtml(value) {
    const node = document.createElement('span');
    node.textContent = value ?? '';
    return node.innerHTML;
}
