/**
 * AGAP System — KP Documents & Forms Client Controller
 * Manages Master DOCX Templates, Shared Letterhead & Branding Profile,
 * Print & Paper Settings, and OpenXML Inspection.
 */

const canManageDocuments = window.AGAP_DOCUMENTS?.canManage === true;
const csrfToken = window.AGAP_DOCUMENTS?.csrfToken || window.agapCsrfToken || '';

let allKPForms = [];
let activeCategoryFilter = 'ALL';
let activeEditFormKey = null;
let currentBranding = {};
let currentPaper = {};

document.addEventListener('DOMContentLoaded', () => {
    // 1. Tab Navigation
    initTabs();

    // 2. Load Forms and Settings
    loadKPForms();

    // 3. Search and Category Filter Listeners
    const searchInput = document.getElementById('catalogSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', () => renderKPFormsGrid());
    }

    const pillContainer = document.getElementById('catalogCategoryPills');
    if (pillContainer) {
        pillContainer.addEventListener('click', (e) => {
            const btn = e.target.closest('.doc-cat-pill');
            if (!btn) return;
            pillContainer.querySelectorAll('.doc-cat-pill').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            activeCategoryFilter = btn.dataset.category || 'ALL';
            renderKPFormsGrid();
        });
    }

    // 4. Branding Form Listeners & Real-Time Live Preview
    initBrandingLivePreview();

    // 5. Print Settings Form Submission
    const printForm = document.getElementById('printSettingsForm');
    if (printForm) {
        printForm.addEventListener('submit', handleSavePrintSettings);
    }

    // 6. Template Edit / Upload Modal Setup
    initModalHandlers();
});

// =========================================================================
// API Fetch Helper
// =========================================================================
async function api(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let result;
    try {
        result = JSON.parse(text);
    } catch (e) {
        console.error('Non-JSON response from', url, text);
        throw new Error(text && text.length < 200 ? text : `Invalid response from server (HTTP ${response.status}).`);
    }
    if (!response.ok || result.success === false) {
        throw new Error(result.message || `Request failed (HTTP ${response.status}).`);
    }
    return result;
}

// =========================================================================
// 1. Tab Navigation
// =========================================================================
function initTabs() {
    const tabButtons = document.querySelectorAll('.doc-tab-btn');
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetTab = btn.dataset.tab;
            if (!targetTab) return;

            tabButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            document.querySelectorAll('.doc-tab-panel').forEach(panel => {
                panel.classList.remove('active');
            });
            const activePanel = document.getElementById(`tab-${targetTab}`);
            if (activePanel) activePanel.classList.add('active');
        });
    });
}

// =========================================================================
// 2. Load Forms, Letterhead & Paper Settings
// =========================================================================
async function loadKPForms() {
    const grid = document.getElementById('kpFormsGrid');
    try {
        const result = await api('../../../backend/api/documents/templates.php?action=list');
        allKPForms = result.data || [];
        currentBranding = result.branding || {};
        currentPaper = result.paper || {};

        // Update Stat Cards
        const statTotal = document.getElementById('statTotalForms');
        if (statTotal) statTotal.textContent = result.total_forms || `${allKPForms.length}+`;

        const statDesc = document.getElementById('statPublishedDesc');
        if (statDesc && result.active_count !== undefined) {
            statDesc.textContent = `${result.active_count} master templates active for document generation`;
        }

        // Populate Form Select inside Edit Modal
        const selectEl = document.getElementById('modalFormKeySelect');
        if (selectEl) {
            selectEl.innerHTML = allKPForms.map(f => `
                <option value="${escapeHtml(f.key)}">${escapeHtml(f.code)}: ${escapeHtml(f.title)}</option>
            `).join('');
        }

        // Populate Letterhead Editor Inputs
        populateBrandingForm(currentBranding);

        // Populate Print Settings Inputs
        populatePrintSettingsForm(currentPaper);

        // Render Cards Grid
        renderKPFormsGrid();
    } catch (err) {
        if (grid) {
            grid.innerHTML = `
                <div class="doc-loading-state" style="color: #ef4444;">
                    <div style="font-size: 1.5rem; margin-bottom: 8px;">⚠️</div>
                    <strong>Failed to load templates:</strong> ${escapeHtml(err.message)}
                </div>
            `;
        }
    }
}

// =========================================================================
// 3. Render Template Cards (Matching Wireframe)
// =========================================================================
function renderKPFormsGrid() {
    const grid = document.getElementById('kpFormsGrid');
    if (!grid) return;

    const query = (document.getElementById('catalogSearchInput')?.value || '').toLowerCase().trim();

    const filtered = allKPForms.filter(item => {
        if (activeCategoryFilter !== 'ALL' && item.category !== activeCategoryFilter) {
            return false;
        }
        if (query) {
            const cleanQuery = query.replace(/[\s\-_.]/g, '');
            const cleanCode = (item.code || '').toLowerCase().replace(/[\s\-_.]/g, '');
            const cleanKpNum = (item.kp_number || '').toLowerCase().replace(/[\s\-_.]/g, '');
            const matchesCode = (item.code || '').toLowerCase().includes(query) || cleanCode.includes(cleanQuery);
            const matchesKpNum = (item.kp_number || '').toLowerCase().includes(query) || cleanKpNum.includes(cleanQuery);
            const matchesTitle = (item.title || '').toLowerCase().includes(query);
            const matchesSubtitle = (item.subtitle || '').toLowerCase().includes(query);
            const matchesDesc = (item.description || '').toLowerCase().includes(query);
            return matchesCode || matchesKpNum || matchesTitle || matchesSubtitle || matchesDesc;
        }
        return true;
    });

    if (filtered.length === 0) {
        grid.innerHTML = `
            <div class="doc-loading-state">
                <div style="font-size: 1.8rem; margin-bottom: 8px;">🔍</div>
                <strong style="color: #1e293b; font-size: 1rem;">No matching templates found</strong>
                <p style="margin: 4px 0 0 0; font-size: 0.82rem;">Try adjusting your search query or switching categories.</p>
            </div>
        `;
        return;
    }

    grid.innerHTML = filtered.map(item => {
        let badgeClass = 'badge-status-official';
        let badgeLabel = item.badge || 'Official template';
        if (badgeLabel === 'Published') {
            badgeClass = 'badge-status-published';
        } else if (badgeLabel === 'DOCX template') {
            badgeClass = 'badge-status-docx';
        }

        const paperLabel = item.custom_template?.custom_paper?.paper_size || currentPaper.paper_size || 'Legal (8.5" x 14")';
        const headerModeLabel = (item.header_mode === 'keep') ? 'Preserved DOCX Header' : 'Shared Letterhead';
        const kpNumDisplay = item.kp_number || item.code;

        return `
            <div class="doc-template-card" data-key="${escapeHtml(item.key)}">
                <div class="template-card-top">
                    <div class="template-card-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                    </div>
                    <div class="template-card-details">
                        <div class="template-card-header-row">
                            <div>
                                <span class="kp-number-badge">${escapeHtml(kpNumDisplay)}</span>
                                <h3 class="template-card-title">${escapeHtml(item.title)}</h3>
                            </div>
                            <span class="${badgeClass}">${escapeHtml(badgeLabel)}</span>
                        </div>
                        <div class="template-card-subtitle">${escapeHtml(item.subtitle)}</div>
                    </div>
                </div>

                <div class="template-meta-pills">
                    <span class="meta-pill">📋 ${escapeHtml(item.category)}</span>
                    <span class="meta-pill">📄 ${escapeHtml(paperLabel)}</span>
                    <span class="meta-pill">🏛️ ${escapeHtml(headerModeLabel)}</span>
                </div>

                <div class="template-card-actions">
                    <button type="button" class="btn-card-edit" onclick="openEditModal('${escapeHtml(item.key)}')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                        Edit template
                    </button>
                    <button type="button" class="btn-card-preview" onclick="openPreviewModal('${escapeHtml(item.key)}', '${escapeJsString(item.title)}')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        Preview
                    </button>
                    <a href="${escapeHtml(item.download_url)}" class="btn-card-icon" title="Download Master DOCX" download>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    </a>
                </div>
            </div>
        `;
    }).join('');
}

// =========================================================================
// 4. Centralized Letterhead & Branding Editor
// =========================================================================
function populateBrandingForm(b) {
    if (!b) return;
    setVal('brand_republic_heading', b.republic_heading || 'REPUBLIKA NG PILIPINAS');
    setVal('brand_province_name', b.province_name || 'LALAWIGAN NG RIZAL');
    setVal('city_name', b.city_name || 'LUNGSOD NG MARIKINA');
    setVal('brand_city_name', b.city_name || 'LUNGSOD NG MARIKINA');
    setVal('brand_barangay_name', b.barangay_name || 'BARANGAY TUMANA');
    setVal('brand_office_title', b.office_title || 'TANGGAPAN NG LUPONG TAGAPAMAYAPA');
    setVal('brand_address', b.address || 'Barangay Hall, Tumana, Marikina City, Metro Manila');
    setVal('brand_phone', b.phone || '(02) 8942-0123 / 0917-123-4567');
    setVal('brand_email', b.email || 'barangay.tumana@marikina.gov.ph');

    const off = b.officers || {};
    setVal('sig_pb_name', off.punong_barangay?.name || 'HON. FRANCISCO G. SANDOVAL');
    setVal('sig_pb_title', off.punong_barangay?.title || 'Punong Barangay / Lupon Chairman');
    setVal('sig_sec_name', off.lupon_secretary?.name || 'MARIA CLARA DE GUZMAN');
    setVal('sig_sec_title', off.lupon_secretary?.title || 'Kalihim ng Lupon');
    setVal('sig_pangkat_c_name', off.pangkat_chairman?.name || 'JUAN C. DELA CRUZ');
    setVal('sig_pangkat_c_title', off.pangkat_chairman?.title || 'Tagapangulo ng Pangkat');

    if (b.logo_primary_data_uri) {
        const tumanaSeal = document.getElementById('previewTumanaSeal');
        if (tumanaSeal) tumanaSeal.src = b.logo_primary_data_uri;
        const liveL = document.querySelector('#liveHeaderSheet .live-logo-left');
        if (liveL) liveL.src = b.logo_primary_data_uri;
    }
    if (b.logo_secondary_data_uri) {
        const marikinaSeal = document.getElementById('previewMarikinaSeal');
        if (marikinaSeal) marikinaSeal.src = b.logo_secondary_data_uri;
        const liveR = document.querySelector('#liveHeaderSheet .live-logo-right');
        if (liveR) liveR.src = b.logo_secondary_data_uri;
    }

    updateLiveHeaderPreview();
}

function initBrandingLivePreview() {
    const fields = [
        'brand_republic_heading', 'brand_province_name', 'brand_city_name',
        'brand_barangay_name', 'brand_address', 'brand_phone'
    ];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateLiveHeaderPreview);
    });

    const form = document.getElementById('brandingConfigForm');
    if (form) {
        form.addEventListener('submit', handleSaveBranding);
    }

    document.getElementById('inputPrimaryLogo')?.addEventListener('change', (e) => handleLogoUploadSelected(e, 'primary'));
    document.getElementById('inputSecondaryLogo')?.addEventListener('change', (e) => handleLogoUploadSelected(e, 'secondary'));
}

function triggerLogoUpload(logoType) {
    const inputId = logoType === 'primary' ? 'inputPrimaryLogo' : 'inputSecondaryLogo';
    const input = document.getElementById(inputId);
    if (input) {
        input.value = '';
        input.click();
    }
}

async function handleLogoUploadSelected(event, logoType) {
    const file = event.target.files?.[0];
    if (!file) return;

    const validTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/svg+xml'];
    if (!validTypes.includes(file.type)) {
        notify('Invalid file format. Please upload a PNG, JPG, WEBP, or SVG image.', false);
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        notify('Image size cannot exceed 5 MB.', false);
        return;
    }

    const formData = new FormData();
    formData.append('logo_file', file);
    formData.append('logo_type', logoType);

    try {
        notify('Uploading seal...', true);
        const resp = await fetch('../../../backend/api/documents/templates.php?action=upload_logo', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken
            },
            body: formData
        });
        const result = await resp.json();
        if (!result.success) {
            throw new Error(result.message || 'Failed to upload seal.');
        }

        notify(result.message || 'Seal updated successfully!', true);

        if (result.logo_data_uri) {
            const previewId = logoType === 'primary' ? 'previewTumanaSeal' : 'previewMarikinaSeal';
            const liveSelector = logoType === 'primary' ? '#liveHeaderSheet .live-logo-left' : '#liveHeaderSheet .live-logo-right';
            const previewEl = document.getElementById(previewId);
            if (previewEl) previewEl.src = result.logo_data_uri;
            const liveEl = document.querySelector(liveSelector);
            if (liveEl) liveEl.src = result.logo_data_uri;
        }

        if (result.branding) {
            currentBranding = result.branding;
        }
    } catch (err) {
        notify(err.message, false);
    }
}

async function resetLogoToDefault(logoType) {
    const label = logoType === 'primary' ? 'Barangay Tumana Seal' : 'City of Marikina Seal';
    if (!confirm(`Are you sure you want to reset the ${label} to the system default?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('logo_type', logoType);

    try {
        notify('Resetting seal...', true);
        const resp = await fetch('../../../backend/api/documents/templates.php?action=reset_logo', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken
            },
            body: formData
        });
        const result = await resp.json();
        if (!result.success) {
            throw new Error(result.message || 'Failed to reset seal.');
        }

        notify(result.message || 'Seal reset to default successfully!', true);

        const defaultSrc = logoType === 'primary' ? '../../assets/images/tumana_logo.jpg' : '../../assets/images/marikina_logo.jpg';
        const previewId = logoType === 'primary' ? 'previewTumanaSeal' : 'previewMarikinaSeal';
        const liveSelector = logoType === 'primary' ? '#liveHeaderSheet .live-logo-left' : '#liveHeaderSheet .live-logo-right';
        const previewEl = document.getElementById(previewId);
        if (previewEl) previewEl.src = defaultSrc;
        const liveEl = document.querySelector(liveSelector);
        if (liveEl) liveEl.src = defaultSrc;

        if (result.branding) {
            currentBranding = result.branding;
        }
    } catch (err) {
        notify(err.message, false);
    }
}

window.triggerLogoUpload = triggerLogoUpload;
window.resetLogoToDefault = resetLogoToDefault;


function updateLiveHeaderPreview() {
    const rep = document.getElementById('brand_republic_heading')?.value || 'REPUBLIKA NG PILIPINAS';
    const prov = document.getElementById('brand_province_name')?.value || 'LALAWIGAN NG RIZAL';
    const city = document.getElementById('brand_city_name')?.value || 'LUNGSOD NG MARIKINA';
    const brgy = document.getElementById('brand_barangay_name')?.value || 'BARANGAY TUMANA';
    const addr = document.getElementById('brand_address')?.value || 'Barangay Hall, Tumana, Marikina City';
    const phone = document.getElementById('brand_phone')?.value || '(02) 8942-0123';

    const elRep = document.getElementById('liveTextRepublic');
    if (elRep) elRep.textContent = rep;
    const elProv = document.getElementById('liveTextProvince');
    if (elProv) elProv.textContent = prov;
    const elCity = document.getElementById('liveTextCity');
    if (elCity) elCity.textContent = city;
    const elBrgy = document.getElementById('liveTextBarangay');
    if (elBrgy) elBrgy.textContent = brgy;
    const elContact = document.getElementById('liveTextContact');
    if (elContact) elContact.textContent = `${addr} • Tel: ${phone}`;
}

async function handleSaveBranding(event) {
    if (event) event.preventDefault();
    const btn = document.getElementById('btnSaveBranding');
    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Saving...';
    }

    const payload = {
        republic_heading: document.getElementById('brand_republic_heading')?.value,
        province_name: document.getElementById('brand_province_name')?.value,
        city_name: document.getElementById('brand_city_name')?.value,
        barangay_name: document.getElementById('brand_barangay_name')?.value,
        office_title: document.getElementById('brand_office_title')?.value,
        address: document.getElementById('brand_address')?.value,
        phone: document.getElementById('brand_phone')?.value,
        email: document.getElementById('brand_email')?.value,
        officers: {
            punong_barangay: {
                name: document.getElementById('sig_pb_name')?.value,
                title: document.getElementById('sig_pb_title')?.value
            },
            lupon_secretary: {
                name: document.getElementById('sig_sec_name')?.value,
                title: document.getElementById('sig_sec_title')?.value
            },
            pangkat_chairman: {
                name: document.getElementById('sig_pangkat_c_name')?.value,
                title: document.getElementById('sig_pangkat_c_title')?.value
            }
        }
    };

    try {
        const result = await api('../../../backend/api/documents/templates.php?action=save_branding', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });
        currentBranding = result.branding || payload;
        notify(result.message || 'Shared letterhead & branding saved successfully!', true);
    } catch (err) {
        notify(err.message, false);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }
}

// =========================================================================
// 5. Centralized Print & Paper Settings
// =========================================================================
function populatePrintSettingsForm(p) {
    if (!p) return;
    const size = p.paper_size || 'Legal';
    const rLegal = document.getElementById('paper_size_legal');
    const rA4 = document.getElementById('paper_size_a4');
    if (size === 'A4') {
        if (rA4) rA4.checked = true;
    } else {
        if (rLegal) rLegal.checked = true;
    }

    setVal('print_orientation', p.orientation || 'portrait');
    setVal('print_font_family', p.font_family || 'Times New Roman');
    setVal('margin_top', p.margin_top ?? 15);
    setVal('margin_bottom', p.margin_bottom ?? 15);
    setVal('margin_left', p.margin_left ?? 18);
    setVal('margin_right', p.margin_right ?? 18);
}

async function handleSavePrintSettings(event) {
    if (event) event.preventDefault();
    const btn = document.getElementById('btnSavePrintSettings');
    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Saving...';
    }

    const form = document.getElementById('printSettingsForm');
    const formData = new FormData(form);
    const paperSize = form.querySelector('input[name="paper_size"]:checked')?.value || 'Legal';

    const payload = {
        paper_size: paperSize,
        orientation: document.getElementById('print_orientation')?.value || 'portrait',
        font_family: document.getElementById('print_font_family')?.value || 'Times New Roman',
        margin_top: parseInt(document.getElementById('margin_top')?.value || '15', 10),
        margin_bottom: parseInt(document.getElementById('margin_bottom')?.value || '15', 10),
        margin_left: parseInt(document.getElementById('margin_left')?.value || '18', 10),
        margin_right: parseInt(document.getElementById('margin_right')?.value || '18', 10)
    };

    try {
        const result = await api('../../../backend/api/documents/templates.php?action=save_paper_settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });
        currentPaper = result.paper || payload;
        notify(result.message || 'Paper & print settings saved successfully!', true);
    } catch (err) {
        notify(err.message, false);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }
}

// =========================================================================
// 6. Template Configuration & Inspection Modal
// =========================================================================
function initModalHandlers() {
    // Open Upload Modal Button
    const btnOpenUpload = document.getElementById('btnOpenUploadModal');
    if (btnOpenUpload) {
        btnOpenUpload.addEventListener('click', () => {
            openEditModal(allKPForms[0]?.key || 'kp_7', true);
        });
    }

    // Dropzone Click
    const dropzone = document.getElementById('templateDropzone');
    const fileInput = document.getElementById('modalFileInput');
    if (dropzone && fileInput) {
        dropzone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', handleModalFileSelected);

        // Drag & Drop
        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
        ['dragleave', 'dragend'].forEach(ev => {
            dropzone.addEventListener(ev, () => dropzone.classList.remove('dragover'));
        });
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                handleModalFileSelected({ target: fileInput });
            }
        });
    }

    // Modal Form Key Change
    const selectKey = document.getElementById('modalFormKeySelect');
    if (selectKey) {
        selectKey.addEventListener('change', (e) => {
            activeEditFormKey = e.target.value;
            loadTemplateInspection(activeEditFormKey);
        });
    }

    // Paper Mode Toggle
    const paperRadios = document.querySelectorAll('input[name="modal_paper_mode"]');
    paperRadios.forEach(r => {
        r.addEventListener('change', () => {
            const row = document.getElementById('customPaperInputsRow');
            if (row) row.style.display = (r.value === 'custom') ? 'grid' : 'none';
        });
    });

    // Save Template Config Button
    const btnSave = document.getElementById('btnSaveTemplateConfig');
    if (btnSave) {
        btnSave.addEventListener('click', handleSaveTemplateConfig);
    }

    // Modal Download DOCX Button
    const btnDownload = document.getElementById('btnModalDownloadDocx');
    if (btnDownload) {
        btnDownload.addEventListener('click', () => {
            if (activeEditFormKey) {
                window.location.href = `../../../backend/api/documents/templates.php?action=download_docx&form_key=${encodeURIComponent(activeEditFormKey)}`;
            }
        });
    }

    // Modal Preview DOCX Button
    const btnPrev = document.getElementById('btnModalPreviewDocx');
    if (btnPrev) {
        btnPrev.addEventListener('click', () => {
            if (activeEditFormKey) {
                const item = allKPForms.find(f => f.key === activeEditFormKey);
                openPreviewModal(activeEditFormKey, item?.title || 'Preview');
            }
        });
    }

    // Close on Escape or click outside
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeEditModal();
            closePreviewModal();
        }
    });

    const editOverlay = document.getElementById('templateEditModal');
    if (editOverlay) {
        editOverlay.addEventListener('click', (e) => {
            if (e.target === editOverlay) closeEditModal();
        });
    }

    const prevOverlay = document.getElementById('templatePreviewModal');
    if (prevOverlay) {
        prevOverlay.addEventListener('click', (e) => {
            if (e.target === prevOverlay) closePreviewModal();
        });
    }
}

async function openEditModal(formKey, isUploadTrigger = false) {
    activeEditFormKey = formKey;
    const item = allKPForms.find(f => f.key === formKey);

    const titleEl = document.getElementById('editModalTitle');
    const subEl = document.getElementById('editModalSubtitle');
    const selectGroup = document.getElementById('modalFormSelectGroup');
    const selectKey = document.getElementById('modalFormKeySelect');

    if (titleEl) {
        titleEl.textContent = isUploadTrigger ? 'Upload template' : `Upload template — ${item?.kp_number || item?.code || formKey}`;
    }
    if (subEl) {
        subEl.textContent = item?.title ? `${item.title} (${item.category})` : 'Configure master DOCX file and header settings.';
    }

    if (selectKey) selectKey.value = formKey;
    if (selectGroup) selectGroup.style.display = isUploadTrigger ? 'block' : 'none';

    // Reset current file indicator
    const curFile = document.getElementById('dropzoneCurrentFile');
    if (curFile) {
        if (item?.custom_template?.file_name) {
            curFile.textContent = `Active Template: ${item.custom_template.file_name} (${item.badge})`;
            curFile.style.display = 'block';
        } else {
            curFile.textContent = `Using Official AGAP Master DOCX Template`;
            curFile.style.display = 'block';
        }
    }

    // Set Header Mode
    const headerMode = item?.header_mode || 'shared';
    const hRadio = document.querySelector(`input[name="modal_header_mode"][value="${headerMode}"]`);
    if (hRadio) hRadio.checked = true;

    // Set Paper Mode
    const paperMode = item?.custom_template?.paper_mode || 'shared';
    const pRadio = document.querySelector(`input[name="modal_paper_mode"][value="${paperMode}"]`);
    if (pRadio) pRadio.checked = true;
    const row = document.getElementById('customPaperInputsRow');
    if (row) row.style.display = (paperMode === 'custom') ? 'grid' : 'none';

    // Load inspection
    await loadTemplateInspection(formKey);

    const modal = document.getElementById('templateEditModal');
    if (modal) modal.style.display = 'flex';
}

function closeEditModal() {
    const modal = document.getElementById('templateEditModal');
    if (modal) modal.style.display = 'none';
    const fileInput = document.getElementById('modalFileInput');
    if (fileInput) fileInput.value = '';
}

async function loadTemplateInspection(formKey) {
    // Inspection card removed per UI simplification
    return;
}

async function handleModalFileSelected(event) {
    const file = event.target.files[0];
    if (!file || !activeEditFormKey) return;

    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('form_key', activeEditFormKey);
    formData.append('template_file', file);

    const curFile = document.getElementById('dropzoneCurrentFile');
    if (curFile) {
        curFile.textContent = `Uploading ${file.name}...`;
        curFile.style.display = 'block';
    }

    try {
        const result = await api('../../../backend/api/documents/templates.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData
        });

        notify(result.message || 'Template uploaded successfully!', true);
        if (curFile) {
            curFile.textContent = `Uploaded: ${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
        }

        // Auto-refresh inspection and template list
        await loadTemplateInspection(activeEditFormKey);
        await loadKPForms();
    } catch (err) {
        notify(err.message, false);
        if (curFile) {
            curFile.textContent = `Upload failed: ${err.message}`;
        }
    }
}

async function handleSaveTemplateConfig() {
    if (!activeEditFormKey) return;
    const btn = document.getElementById('btnSaveTemplateConfig');
    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Saving...';
    }

    const headerMode = document.querySelector('input[name="modal_header_mode"]:checked')?.value || 'shared';
    const paperMode = document.querySelector('input[name="modal_paper_mode"]:checked')?.value || 'shared';
    const signatoryProfile = document.getElementById('modal_signatory_profile')?.value || 'punong_barangay';

    const payload = {
        form_key: activeEditFormKey,
        header_mode: headerMode,
        paper_mode: paperMode,
        signatory_profile: signatoryProfile,
        status: 'published'
    };

    if (paperMode === 'custom') {
        payload.custom_paper = {
            paper_size: document.getElementById('modal_custom_paper_size')?.value || 'Legal',
            margin_top: parseInt(document.getElementById('modal_custom_margin_top')?.value || '15', 10),
            margin_bottom: parseInt(document.getElementById('modal_custom_margin_bottom')?.value || '15', 10),
            margin_left: parseInt(document.getElementById('modal_custom_margin_left')?.value || '18', 10),
            margin_right: parseInt(document.getElementById('modal_custom_margin_left')?.value || '18', 10)
        };
    }

    try {
        const result = await api('../../../backend/api/documents/templates.php?action=save_template', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });

        notify(result.message || 'Template configuration saved successfully!', true);
        closeEditModal();
        await loadKPForms();
    } catch (err) {
        notify(err.message, false);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }
}

// =========================================================================
// 7. Synchronized Visual Preview Modal
// =========================================================================
function openPreviewModal(formKey, title) {
    const modal = document.getElementById('templatePreviewModal');
    const titleEl = document.getElementById('previewModalTitle');
    const subEl = document.getElementById('previewModalSubtitle');
    const iframe = document.getElementById('previewIframe');
    const downloadDocxBtn = document.getElementById('previewDownloadDocxBtn');

    const item = allKPForms.find(f => f.key === formKey);
    const kpNum = item?.kp_number || item?.code || formKey;

    const previewUrl = `../../../backend/api/documents/templates.php?action=preview&form_key=${encodeURIComponent(formKey)}`;
    const downloadUrl = `../../../backend/api/documents/templates.php?action=download_docx&form_key=${encodeURIComponent(formKey)}`;

    if (titleEl) titleEl.textContent = kpNum;
    if (subEl) subEl.textContent = '';
    if (downloadDocxBtn) downloadDocxBtn.href = downloadUrl;
    if (iframe) iframe.src = previewUrl;
    if (modal) modal.style.display = 'flex';
}

function closePreviewModal() {
    const modal = document.getElementById('templatePreviewModal');
    const iframe = document.getElementById('previewIframe');
    if (modal) modal.style.display = 'none';
    if (iframe) iframe.src = 'about:blank';
}

function printPreviewIframe() {
    const iframe = document.getElementById('previewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}

// =========================================================================
// Utilities & Notifications
// =========================================================================
function notify(msg, isSuccess = false) {
    if (window.agapNotify) {
        window.agapNotify(msg, isSuccess ? 'success' : 'error', 'KP Documents & Forms');
    } else {
        alert(msg);
    }
}

function setVal(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function escapeJsString(str) {
    return String(str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}
