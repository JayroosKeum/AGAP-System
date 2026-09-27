/**
 * AGAP Resident Profiles Module
 * Modern, easy to navigate, with 25-item pagination, Tumana purok dropdown,
 * required address validation, and Leaflet map pin-to-address integration.
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

// Barangay Tumana polygon boundary, OpenStreetMap relation 1225795
const tumanaBoundary = [
    [14.6554682,121.0857081],[14.6562612,121.0859908],[14.6557911,121.0865123],[14.6566853,121.0867891],
    [14.6573361,121.0874608],[14.6566672,121.0882081],[14.6596216,121.0912009],[14.6605249,121.0911456],
    [14.6609324,121.0914765],[14.6617729,121.0920319],[14.6634173,121.0935248],[14.6639892,121.0936321],
    [14.6643486,121.0936995],[14.6645004,121.0938826],[14.6649347,121.0948585],[14.6652695,121.0952371],
    [14.6652424,121.0956829],[14.6648805,121.0961861],[14.664908,121.0963356],[14.6645002,121.0966408],
    [14.6642299,121.0967374],[14.6637829,121.0977901],[14.6629907,121.0988145],[14.6625832,121.0991486],
    [14.6625136,121.0996819],[14.6625395,121.100022],[14.6623861,121.1006225],[14.6621551,121.1005299],
    [14.6614757,121.1023379],[14.6602368,121.1019108],[14.6595948,121.1016599],[14.6589154,121.1019614],
    [14.6581343,121.1022551],[14.6574788,121.102553],[14.6569962,121.1027094],[14.6566377,121.102703],
    [14.656158,121.1026733],[14.6559169,121.1026982],[14.6553478,121.1027577],[14.6550262,121.1027938],
    [14.6548318,121.1027249],[14.6541841,121.1025018],[14.6534713,121.1024013],[14.6532707,121.102373],
    [14.6510737,121.101336],[14.6508768,121.1007973],[14.6507211,121.0992879],[14.650753,121.0989571],
    [14.6509538,121.098811],[14.6514718,121.0983827],[14.6521496,121.0978851],[14.6527322,121.0972236],
    [14.6535364,121.0959443],[14.6539003,121.0955034],[14.654028,121.0953166],[14.6537619,121.0950108],
    [14.6533329,121.0942299],[14.6530062,121.0934184]
];

// Leaflet map controllers for Add and Edit modals
const residentMaps = {
    add: { map: null, marker: null, isOpen: false },
    edit: { map: null, marker: null, isOpen: false }
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
                if (modal.id === 'addResidentModal') closeAddModal();
                else if (modal.id === 'editResidentModal') closeEditModal();
                else if (modal.id === 'viewResidentModal') closeViewModal();
                else if (modal.id === 'deleteResidentModal') closeDeleteModal();
                else modal.style.display = 'none';
            }
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal').forEach(modal => {
                if (modal.style.display === 'flex') {
                    if (modal.id === 'addResidentModal') closeAddModal();
                    else if (modal.id === 'editResidentModal') closeEditModal();
                    else if (modal.id === 'viewResidentModal') closeViewModal();
                    else if (modal.id === 'deleteResidentModal') closeDeleteModal();
                    else modal.style.display = 'none';
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

    form.querySelectorAll('input:not([type="hidden"]):not([type="date"]), textarea, select').forEach((field) => {
        field.addEventListener('input', () => {
            field.setCustomValidity('');
        });
        field.addEventListener('change', () => {
            field.setCustomValidity('');
        });
    });
}

function validateResidentFields(form) {
    let isValid = true;
    form.querySelectorAll('input:not([type="hidden"]):not([type="date"]), textarea, select').forEach((field) => {
        field.value = (field.value || '').trim();
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
        // Complete address is strictly required
        if (field.name === 'address' && field.value === '') {
            field.setCustomValidity('Please provide a complete street address in Barangay Tumana.');
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

// -------------------------------------------------------------
// Interactive Leaflet Map for Pinning Location & Obtaining Address
// -------------------------------------------------------------

function isWithinTumana(lat, lng) {
    let inside = false;
    for (let i = 0, j = tumanaBoundary.length - 1; i < tumanaBoundary.length; j = i++) {
        const [yi, xi] = tumanaBoundary[i];
        const [yj, xj] = tumanaBoundary[j];
        const intersect = ((yi > lat) !== (yj > lat)) && (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi);
        if (intersect) inside = !inside;
    }
    return inside;
}

function toggleResidentMap(mode) {
    const wrap = document.getElementById(mode === 'add' ? 'addResidentMapWrap' : 'editResidentMapWrap');
    const btn = document.getElementById(mode === 'add' ? 'toggleAddMapBtn' : 'toggleEditMapBtn');
    const label = document.getElementById(mode === 'add' ? 'addMapBtnText' : 'editMapBtnText');
    if (!wrap) return;

    const isOpen = wrap.style.display !== 'none';
    if (isOpen) {
        wrap.style.display = 'none';
        if (label) label.textContent = 'Pin on Map to Obtain Address';
        if (btn) btn.classList.remove('is-active');
        residentMaps[mode].isOpen = false;
    } else {
        wrap.style.display = 'block';
        if (label) label.textContent = 'Hide Map';
        if (btn) btn.classList.add('is-active');
        residentMaps[mode].isOpen = true;

        if (!residentMaps[mode].map) {
            initResidentLeafletMap(mode);
        } else {
            setTimeout(() => {
                if (residentMaps[mode].map) {
                    residentMaps[mode].map.invalidateSize();
                }
            }, 100);
        }
    }
}

function initResidentLeafletMap(mode) {
    if (!window.L) return;
    const containerId = mode === 'add' ? 'addResidentLeafletMap' : 'editResidentLeafletMap';
    const container = document.getElementById(containerId);
    if (!container) return;

    const tumanaBounds = L.latLngBounds(tumanaBoundary);
    const map = L.map(container, {
        maxBounds: tumanaBounds,
        maxBoundsViscosity: 1
    }).fitBounds(tumanaBounds, { padding: [10, 10] });

    map.setMinZoom(map.getZoom());

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    // Blue polygon highlight of Barangay Tumana boundary
    L.polygon(tumanaBoundary, {
        color: '#2563eb',
        weight: 2,
        fillColor: '#60a5fa',
        fillOpacity: 0.12,
        interactive: false
    }).addTo(map);

    residentMaps[mode].map = map;

    map.on('click', (event) => {
        handlePinLocation(mode, event.latlng.lat, event.latlng.lng);
    });

    setTimeout(() => {
        map.invalidateSize();
    }, 150);
}

function handlePinLocation(mode, lat, lng) {
    const statusBadge = document.getElementById(mode === 'add' ? 'addMapPinStatus' : 'editMapPinStatus');

    if (!isWithinTumana(lat, lng)) {
        if (statusBadge) {
            statusBadge.className = 'map-pin-status-badge error';
            statusBadge.textContent = 'Point is outside Barangay Tumana boundary';
        }
        return;
    }

    const map = residentMaps[mode].map;
    if (!map) return;

    // Create or move draggable marker
    if (residentMaps[mode].marker) {
        residentMaps[mode].marker.setLatLng([lat, lng]);
    } else {
        const marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', (e) => {
            const pt = e.target.getLatLng();
            handlePinLocation(mode, pt.lat, pt.lng);
        });
        residentMaps[mode].marker = marker;
    }

    if (statusBadge) {
        statusBadge.className = 'map-pin-status-badge loading';
        statusBadge.textContent = 'Obtaining address in Tumana...';
    }

    // Reverse geocode using Nominatim API
    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=1`, {
        headers: { 'Accept': 'application/json' }
    })
    .then(res => res.json())
    .then(result => {
        const addr = result.address || {};
        const houseNo = addr.house_number || '';
        const road = addr.road || addr.pedestrian || addr.neighbourhood || addr.suburb || '';
        const quarter = addr.quarter || addr.suburb || '';

        let streetAddress = [houseNo, road].filter(Boolean).join(' ');
        if (!streetAddress && road) streetAddress = road;
        if (!streetAddress && quarter) streetAddress = quarter;
        if (!streetAddress) streetAddress = result.name || 'Barangay Tumana';

        let fullAddress = streetAddress;
        if (quarter && !fullAddress.toLowerCase().includes(quarter.toLowerCase())) {
            fullAddress += `, ${quarter}`;
        }
        if (!fullAddress.toLowerCase().includes('tumana')) {
            fullAddress += ', Barangay Tumana';
        }
        if (!fullAddress.toLowerCase().includes('marikina')) {
            fullAddress += ', Marikina City';
        }

        const addressTextarea = document.getElementById(mode === 'add' ? 'addAddress' : 'editAddress');
        if (addressTextarea) {
            addressTextarea.value = fullAddress;
            addressTextarea.setCustomValidity('');
        }

        // Try to auto-match and select Purok dropdown
        autoMatchPurok(mode, fullAddress, quarter, road);

        if (statusBadge) {
            statusBadge.className = 'map-pin-status-badge success';
            statusBadge.textContent = `✓ Obtained: ${streetAddress}`;
        }
    })
    .catch(() => {
        const addressTextarea = document.getElementById(mode === 'add' ? 'addAddress' : 'editAddress');
        if (addressTextarea && !addressTextarea.value.trim()) {
            addressTextarea.value = `Barangay Tumana, Marikina City (Coordinates: ${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)})`;
            addressTextarea.setCustomValidity('');
        }
        if (statusBadge) {
            statusBadge.className = 'map-pin-status-badge success';
            statusBadge.textContent = `✓ Pin located (${Number(lat).toFixed(4)}, ${Number(lng).toFixed(4)})`;
        }
    });
}

function autoMatchPurok(mode, fullAddress, quarter, road) {
    const purokSelect = document.getElementById(mode === 'add' ? 'addPurok' : 'editPurok');
    if (!purokSelect) return;
    const combined = `${fullAddress} ${quarter || ''} ${road || ''}`.toLowerCase();

    // Check numbered puroks (Purok 1 through 8)
    for (let i = 1; i <= 8; i++) {
        if (combined.includes(`purok ${i}`) || combined.includes(`purok-${i}`)) {
            purokSelect.value = `Purok ${i}`;
            return;
        }
    }

    // Check recognized zones and compounds
    const namedAreas = [
        'Doña Petra',
        'Bagong Farmers',
        'Bukang Liwayway',
        'Libis Tumana',
        'Bagong Purok',
        'Palay',
        'Mais',
        'Singkamas'
    ];
    for (const area of namedAreas) {
        if (combined.includes(area.toLowerCase())) {
            purokSelect.value = area;
            return;
        }
    }
}

// -------------------------------------------------------------
// Form Submissions
// -------------------------------------------------------------

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
            window.agapNotify(data.message || 'Resident profile created successfully in Barangay Tumana.', 'success', 'Success');
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
            purokSet.add(normalizePurok(r.purok));
        }
    });

    if (totalCountEl) totalCountEl.textContent = total;
    if (permCountEl) permCountEl.textContent = permanent;
    if (tenantCountEl) tenantCountEl.textContent = tenants;
    if (purokCountEl) purokCountEl.textContent = purokSet.size;
}

function normalizePurok(val) {
    if (!val) return '';
    const clean = String(val).trim().toLowerCase();
    const m = clean.match(/^purok\s*([0-9]+)$/);
    if (m) return `purok ${m[1]}`;
    if (/^[0-9]+$/.test(clean)) return `purok ${clean}`;
    return clean;
}

function normalizePurokValue(val) {
    if (!val) return '';
    const clean = String(val).trim();
    if (/^[0-9]+$/.test(clean)) return `Purok ${clean}`;
    const m = clean.match(/^purok\s*([0-9]+)$/i);
    if (m) return `Purok ${m[1]}`;
    return clean;
}

function formatPurokBadge(purokVal) {
    if (!purokVal) return '';
    const clean = String(purokVal).trim();
    const label = /^purok/i.test(clean) ? clean : (clean.length <= 2 && /^\d+$/.test(clean) ? `Purok ${clean}` : clean);
    return `<span class="purok-pill">${escapeResidentHtml(label)}</span>`;
}

function applyFiltersAndRender() {
    const q = (currentFilters.search || '').toLowerCase();
    const purokFilter = normalizePurok(currentFilters.purok);
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
            const residentPurok = normalizePurok(r.purok);
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

    // Pagination calculations (strictly 25 items per page)
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
                ${escapeResidentHtml(currentFilters.purok)}
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

        // Location & Purok
        const purokBadge = formatPurokBadge(r.purok);
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

    // If only 1 page, no need for extensive controls
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
    const mapWrap = document.getElementById('addResidentMapWrap');
    if (mapWrap) mapWrap.style.display = 'none';
    const label = document.getElementById('addMapBtnText');
    if (label) label.textContent = 'Pin on Map to Obtain Address';
    const btn = document.getElementById('toggleAddMapBtn');
    if (btn) btn.classList.remove('is-active');
    residentMaps.add.isOpen = false;
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
            const purokBadge = formatPurokBadge(data.purok);

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
                                ${purokBadge}
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
                        Residency & Contact Records (Barangay Tumana)
                    </div>

                    <div class="view-profile-grid">
                        <div class="view-info-box">
                            <span class="view-info-label">Purok / Zone</span>
                            <span class="view-info-value">${data.purok ? escapeResidentHtml(normalizePurokValue(data.purok)) : '—'}</span>
                        </div>
                        <div class="view-info-box">
                            <span class="view-info-label">Contact Number</span>
                            <span class="view-info-value">${data.contact_no ? `<a href="tel:${escapeResidentHtml(data.contact_no)}" style="color:#1d4ed8; text-decoration:none;">${escapeResidentHtml(data.contact_no)}</a>` : '—'}</span>
                        </div>
                        <div class="view-info-box">
                            <span class="view-info-label">Email Address</span>
                            <span class="view-info-value">${data.email ? `<a href="mailto:${escapeResidentHtml(data.email)}" style="color:#1d4ed8; text-decoration:none;">${escapeResidentHtml(data.email)}</a>` : '—'}</span>
                        </div>
                        <div class="view-info-box">
                            <span class="view-info-label">Residency Status</span>
                            <span class="view-info-value">${isTenant ? 'Tenant / Renter' : 'Permanent Resident'}</span>
                        </div>
                        <div class="view-info-box" style="grid-column: 1 / -1;">
                            <span class="view-info-label">Complete Street Address</span>
                            <span class="view-info-value">${escapeResidentHtml(data.address || '—')}</span>
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

            // Handle Purok Select with normalization
            const editPurokSelect = document.getElementById('editPurok');
            if (editPurokSelect) {
                const norm = normalizePurokValue(data.purok);
                editPurokSelect.value = norm;
                if (!editPurokSelect.value && data.purok) {
                    let matched = false;
                    for (const opt of editPurokSelect.options) {
                        if (opt.value.toLowerCase() === String(data.purok).toLowerCase() || opt.text.toLowerCase() === String(data.purok).toLowerCase()) {
                            opt.selected = true;
                            matched = true;
                            break;
                        }
                    }
                    if (!matched) {
                        const opt = document.createElement('option');
                        opt.value = data.purok;
                        opt.text = data.purok;
                        opt.selected = true;
                        editPurokSelect.appendChild(opt);
                    }
                }
            }

            document.getElementById('editTenant').value = data.is_tenant ?? '0';
            document.getElementById('editAddress').value = data.address ?? '';

            // Reset edit map state
            const editMapWrap = document.getElementById('editResidentMapWrap');
            if (editMapWrap) editMapWrap.style.display = 'none';
            const label = document.getElementById('editMapBtnText');
            if (label) label.textContent = 'Pin on Map to Obtain Address';
            const btn = document.getElementById('toggleEditMapBtn');
            if (btn) btn.classList.remove('is-active');
            residentMaps.edit.isOpen = false;

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
    const mapWrap = document.getElementById('editResidentMapWrap');
    if (mapWrap) mapWrap.style.display = 'none';
    const label = document.getElementById('editMapBtnText');
    if (label) label.textContent = 'Pin on Map to Obtain Address';
    const btn = document.getElementById('toggleEditMapBtn');
    if (btn) btn.classList.remove('is-active');
    residentMaps.edit.isOpen = false;
}

function deleteResident(id) {
    const resident = allResidents.find(r => Number(r.resident_id) === Number(id));
    const previewEl = document.getElementById('deleteResidentNamePreview');

    if (previewEl) {
        if (resident) {
            const fullName = [resident.first_name, resident.middle_name, resident.last_name].filter(Boolean).join(' ');
            const loc = resident.purok ? normalizePurokValue(resident.purok) : (resident.address || 'Barangay Tumana resident');
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
