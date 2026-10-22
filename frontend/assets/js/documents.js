/**
 * AGAP System — KP Documents and Forms Controller
 * Handles Document Configuration, KP Forms Catalog (1-25), Custom Template Uploads, Online Previews, and Printing.
 */

const canManageDocuments = window.AGAP_DOCUMENTS?.canManage === true;
const csrfToken = window.AGAP_DOCUMENTS?.csrfToken || window.agapCsrfToken || '';

let allKPForms = [];
let activeCategoryFilter = 'ALL';
let activeUploadFormKey = null;

document.addEventListener('DOMContentLoaded', () => {
    // 1. Settings Form Submission
    const docSettingsForm = document.getElementById('docSettingsForm');
    if (docSettingsForm) {
        docSettingsForm.addEventListener('submit', saveDocSettings);
    }

    // 2. Load Forms Catalog
    loadKPForms();

    // 3. Search & Filter Listeners
    const searchInput = document.getElementById('catalogSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', () => renderKPFormsGrid());
    }

    const pillContainer = document.getElementById('catalogCategoryPills');
    if (pillContainer) {
        pillContainer.addEventListener('click', (e) => {
            const btn = e.target.closest('.filter-pill');
            if (!btn) return;
            pillContainer.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            activeCategoryFilter = btn.dataset.category || 'ALL';
            renderKPFormsGrid();
        });
    }

    // 4. File Upload Listener
    const fileInput = document.getElementById('templateFileInput');
    if (fileInput) {
        fileInput.addEventListener('change', handleFileSelected);
    }

    // 5. Modal Keyboard Close (ESC) & Backdrop Click
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeTemplateModal();
    });

    const modalOverlay = document.getElementById('templatePreviewModal');
    if (modalOverlay) {
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) closeTemplateModal();
        });
    }
});

// =========================================================================
// API Helper
// =========================================================================
async function api(url, options = {}) {
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || result.success === false) throw new Error(result.message || 'Request failed.');
    return result;
}

// =========================================================================
// 1. Save Document Settings Configuration
// =========================================================================
async function saveDocSettings(event) {
    if (event) event.preventDefault();
    const form = document.getElementById('docSettingsForm');
    if (!form) return;
    const btn = document.getElementById('btnSaveDocSettings');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Saving...';
    }

    const formData = new FormData(form);
    const payload = {};
    for (const [key, value] of formData.entries()) {
        payload[key] = value;
    }
    const toggles = form.querySelectorAll('input[type="checkbox"]');
    toggles.forEach(t => {
        if (t.name) {
            payload[t.name] = t.checked ? '1' : '0';
        }
    });

    try {
        const response = await fetch('../../../backend/api/settings/index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });
        const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
        if (result.success) {
            setMessage(result.message || 'KP document settings saved successfully!', true);
        } else {
            throw new Error(result.message || 'Failed to save settings.');
        }
    } catch (err) {
        setMessage(err.message, false);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}

// =========================================================================
// 2. Load & Render KP Forms Catalog (KP Forms 1 - 25)
// =========================================================================
async function loadKPForms() {
    const grid = document.getElementById('kpFormsGrid');
    try {
        const result = await api('../../../backend/api/documents/templates.php');
        allKPForms = result.data || [];

        // Update counts
        const uploadedCount = result.uploaded_count || 0;
        const totalCount = result.total_forms || allKPForms.length;
        
        const countBadge = document.getElementById('formsCountBadge');
        if (countBadge) countBadge.textContent = `${totalCount} Forms Available`;
        
        const uploadedBadge = document.getElementById('uploadedCountBadge');
        if (uploadedBadge) {
            uploadedBadge.textContent = `${uploadedCount} Custom Uploaded`;
            uploadedBadge.style.display = uploadedCount > 0 ? 'inline-block' : 'none';
        }

        renderKPFormsGrid();
    } catch (err) {
        if (grid) {
            grid.innerHTML = `<div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #ef4444;">
                ⚠️ Failed to load KP Form templates: ${escapeHtml(err.message)}
            </div>`;
        }
    }
}

function renderKPFormsGrid() {
    const grid = document.getElementById('kpFormsGrid');
    if (!grid) return;

    const query = (document.getElementById('catalogSearchInput')?.value || '').toLowerCase().trim();

    const filtered = allKPForms.filter(item => {
        // Category filter
        if (activeCategoryFilter !== 'ALL' && item.category !== activeCategoryFilter) {
            return false;
        }
        // Search query
        if (query) {
            const matchesCode = (item.code || '').toLowerCase().includes(query);
            const matchesTitle = (item.title || '').toLowerCase().includes(query);
            const matchesSubtitle = (item.subtitle || '').toLowerCase().includes(query);
            const matchesDesc = (item.description || '').toLowerCase().includes(query);
            return matchesCode || matchesTitle || matchesSubtitle || matchesDesc;
        }
        return true;
    });

    if (filtered.length === 0) {
        grid.innerHTML = `
            <div style="grid-column: 1 / -1; text-align: center; padding: 50px 20px; color: #64748b;">
                <div style="font-size: 2rem; margin-bottom: 8px;">🔍</div>
                <div style="font-size: 1.1rem; font-weight: 700; color: #1e293b;">No matching KP forms found</div>
                <p style="margin-top: 4px; font-size: 0.88rem;">Try clearing your search query or switching the category filter.</p>
            </div>
        `;
        return;
    }

    grid.innerHTML = filtered.map(item => {
        const hasCustom = item.has_custom_template;
        const custom = item.custom_template;
        
        let statusHtml = '';
        if (hasCustom && custom) {
            statusHtml = `
                <div class="kp-status-bar has-custom">
                    <span title="${escapeHtml(custom.file_name)}">
                        ✅ <strong>Custom Template:</strong> ${escapeHtml(custom.file_name)}
                    </span>
                    <span style="font-size: 0.7rem; opacity: 0.8;">${formatDateOnly(custom.uploaded_at)}</span>
                </div>
            `;
        } else {
            statusHtml = `
                <div class="kp-status-bar is-placeholder">
                    <span>📄 <strong>Official System Template</strong></span>
                    <span style="font-size: 0.7rem; color: #15803d; font-weight: 600;">Ready to Print</span>
                </div>
            `;
        }

        const previewTitle = `${item.code}: ${item.title}`;

        return `
            <div class="kp-form-card" data-key="${escapeHtml(item.key)}">
                <div>
                    <div class="kp-form-card-header">
                        <span class="kp-badge">${escapeHtml(item.code)}</span>
                        <span class="kp-category-tag">${escapeHtml(item.category)}</span>
                    </div>

                    <h3 class="kp-form-title">${escapeHtml(item.title)}</h3>
                    <div class="kp-form-subtitle">${escapeHtml(item.subtitle)}</div>
                    <div class="kp-form-desc">${escapeHtml(item.description)}</div>
                </div>

                <div>
                    ${statusHtml}

                    <div class="kp-card-actions">
                        <button type="button" class="btn-preview-kp" onclick="openTemplateModal('${escapeHtml(item.key)}', '${escapeJsString(previewTitle)}', '${escapeJsString(item.preview_url)}')">
                            <span>👁️</span> Preview
                        </button>
                        <button type="button" class="btn-print-kp" onclick="printDirect('${escapeJsString(item.preview_url)}')">
                            <span>🖨️</span> Print
                        </button>

                        ${canManageDocuments ? `
                            <button type="button" class="btn-upload-kp" onclick="triggerUpload('${escapeHtml(item.key)}')">
                                <span>📤</span> ${hasCustom ? 'Replace' : 'Upload'}
                            </button>
                            ${hasCustom ? `
                                <button type="button" class="btn-reset-kp" title="Reset to official template placeholder" onclick="resetTemplate('${escapeHtml(item.key)}')">
                                    <span>🗑️</span>
                                </button>
                            ` : ''}
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// =========================================================================
// 3. Upload & Reset Handlers
// =========================================================================
function triggerUpload(formKey) {
    activeUploadFormKey = formKey;
    const fileInput = document.getElementById('templateFileInput');
    if (fileInput) {
        fileInput.value = '';
        fileInput.click();
    }
}

async function handleFileSelected(event) {
    const file = event.target.files[0];
    if (!file || !activeUploadFormKey) return;

    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('form_key', activeUploadFormKey);
    formData.append('template_file', file);

    setMessage('Uploading template file...', true);

    try {
        const response = await fetch('../../../backend/api/documents/templates.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken
            },
            body: formData
        });
        const result = await response.json().catch(() => ({ success: false, message: 'Invalid response from server.' }));
        if (result.success) {
            setMessage(result.message || 'Template uploaded successfully!', true);
            await loadKPForms();
        } else {
            throw new Error(result.message || 'Failed to upload template.');
        }
    } catch (err) {
        setMessage(err.message, false);
    } finally {
        activeUploadFormKey = null;
    }
}

async function resetTemplate(formKey) {
    if (!confirm('Reset this form back to the official Katarungang Pambarangay system template?')) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('form_key', formKey);

    try {
        const response = await fetch('../../../backend/api/documents/templates.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken
            },
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            setMessage(result.message, true);
            await loadKPForms();
        } else {
            throw new Error(result.message || 'Failed to reset template.');
        }
    } catch (err) {
        setMessage(err.message, false);
    }
}

// =========================================================================
// 4. Modal Preview & Print
// =========================================================================
function openTemplateModal(formKey, title, previewUrl) {
    const modal = document.getElementById('templatePreviewModal');
    const titleEl = document.getElementById('modalFormTitle');
    const iframe = document.getElementById('templatePreviewIframe');
    const newTabBtn = document.getElementById('btnModalNewTab');

    if (titleEl) titleEl.innerHTML = `<span>📄</span> ${escapeHtml(title)}`;
    if (newTabBtn) newTabBtn.href = previewUrl;
    if (iframe) iframe.src = previewUrl;
    if (modal) modal.style.display = 'flex';
}

function closeTemplateModal() {
    const modal = document.getElementById('templatePreviewModal');
    const iframe = document.getElementById('templatePreviewIframe');
    if (modal) modal.style.display = 'none';
    if (iframe) iframe.src = 'about:blank';
}

function printModalIframe() {
    const iframe = document.getElementById('templatePreviewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}

function printDirect(previewUrl) {
    const printWin = window.open(previewUrl, '_blank');
    if (printWin) {
        printWin.addEventListener('load', () => {
            setTimeout(() => {
                printWin.focus();
                printWin.print();
            }, 300);
        });
    }
}

// =========================================================================
// Notifications & Utilities
// =========================================================================
function setMessage(message, success = false) {
    if (window.agapNotify && message) {
        window.agapNotify(message, success ? 'success' : 'error', 'KP Documents and Forms');
    }
    const box = document.getElementById('documentMessage');
    if (box) {
        box.textContent = '';
        box.className = '';
        box.style.display = 'none';
    }
}

function escapeHtml(value) {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
}

function escapeJsString(str) {
    return String(str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}

function formatDateOnly(value) {
    if (!value) return '';
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
}
