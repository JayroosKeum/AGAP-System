/**
 * AGAP System — Settings Module Frontend Controller
 */

document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let formDirty = false;
    let leafletMiniMap = null;
    let mapMarker = null;

    // Toast helper
    window.showToast = function(message, type = 'success') {
        let toastContainer = document.getElementById('settingsToastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'settingsToastContainer';
            toastContainer.style.cssText = 'position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px;';
            document.body.appendChild(toastContainer);
        }

        const toast = document.createElement('div');
        const bg = (type === 'success') ? '#15803d' : (type === 'error' ? '#b91c1c' : '#2563eb');
        toast.style.cssText = `background:${bg}; color:#ffffff; padding:12px 18px; border-radius:8px; font-size:0.88rem; font-weight:600; box-shadow:0 4px 12px rgba(0,0,0,0.15); display:flex; align-items:center; gap:8px; animation:fadeIn 0.2s ease;`;
        toast.innerHTML = `<span>${type === 'success' ? '✓' : (type === 'error' ? '⚠️' : 'ℹ️')}</span><span>${escapeHtml(message)}</span>`;
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    };

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    // 1. Tab Switching
    const navButtons = document.querySelectorAll('.settings-nav-btn');
    const tabPanes = document.querySelectorAll('.settings-tab-pane');

    window.switchSettingsTab = function(tabId) {
        navButtons.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tabId);
        });

        tabPanes.forEach(pane => {
            const isActive = pane.id === tabId;
            pane.classList.toggle('active', isActive);
            if (isActive && tabId === 'tab-categories') {
                setTimeout(initLeafletMiniMap, 150);
            }
            if (isActive && tabId === 'tab-duty-groups') {
                loadDutyGroups();
            }
        });

        // Update URL hash without scroll jump
        history.replaceState(null, '', '#' + tabId.replace('tab-', ''));
    };

    navButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            switchSettingsTab(btn.dataset.tab);
        });
    });

    // Handle initial query param or hash in URL
    const urlParams = new URLSearchParams(window.location.search);
    const queryTab = urlParams.get('tab');
    let targetTabName = queryTab || window.location.hash.replace('#', '');
    if (targetTabName === 'ai' || targetTabName === 'gemini') {
        targetTabName = 'external';
    }
    if (targetTabName) {
        const fullTabId = targetTabName.startsWith('tab-') ? targetTabName : 'tab-' + targetTabName;
        if (document.getElementById(fullTabId)) {
            switchSettingsTab(fullTabId);
        }
    }

    const focusTarget = urlParams.get('focus');
    if (focusTarget || targetTabName === 'external') {
        const targetInput = document.getElementById(focusTarget === 'gemini_api_key' ? 'inputGeminiApiKey' : (focusTarget || 'inputGeminiApiKey'));
        if (targetInput) {
            setTimeout(() => {
                targetInput.focus();
                targetInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                targetInput.style.outline = '3px solid #f59e0b';
                targetInput.style.boxShadow = '0 0 12px rgba(245, 158, 11, 0.4)';
                setTimeout(() => {
                    targetInput.style.outline = '';
                    targetInput.style.boxShadow = '';
                }, 3500);
            }, 300);
        }
    }

    // 2. Form Dirty Tracking & Floating Save Bar
    const floatingBar = document.getElementById('settingsFloatingBar');
    const settingsForm = document.getElementById('settingsMainForm');

    function markDirty() {
        if (!formDirty) {
            formDirty = true;
            if (floatingBar) floatingBar.classList.add('visible');
        }
    }

    if (settingsForm) {
        settingsForm.addEventListener('input', markDirty);
        settingsForm.addEventListener('change', markDirty);
    }

    window.resetSettingsForm = function() {
        if (confirm('Are you sure you want to discard unsaved changes?')) {
            window.location.reload();
        }
    };

    // 3. Save Settings Handler
    window.saveAllSettings = async function() {
        const saveBtn = document.getElementById('btnSaveSettings');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = 'Saving...';
        }

        const formData = new FormData(settingsForm);
        const payload = {};

        // Extract regular inputs
        for (const [key, value] of formData.entries()) {
            payload[key] = value;
        }

        // Toggles / checkboxes not in form data if unchecked (excluding array checkboxes)
        const toggles = settingsForm.querySelectorAll('input[type="checkbox"]:not([name$="[]"])');
        toggles.forEach(t => {
            if (t.name) {
                payload[t.name] = t.checked ? '1' : '0';
            }
        });

        // Collect multi-select array checkboxes (e.g. mediation_days[])
        const medDays = [];
        settingsForm.querySelectorAll('input[name="mediation_days[]"]:checked').forEach(cb => {
            medDays.push(cb.value);
        });
        payload['mediation_days'] = medDays;
        payload['mediation_day_of_week'] = medDays.join(',');

        // Holidays JSON
        payload['configured_holidays'] = JSON.stringify(window.currentHolidaysList || []);

        try {
            const res = await fetch('../../../backend/api/settings/index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            if (data.success) {
                formDirty = false;
                if (floatingBar) floatingBar.classList.remove('visible');
                showToast(data.message || 'Settings saved successfully!', 'success');
            } else {
                showToast(data.message || 'Failed to save settings.', 'error');
            }
        } catch (err) {
            showToast('Network error while saving settings: ' + err.message, 'error');
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '💾 Save All Changes';
            }
        }
    };

    // 4. Seal Uploaders
    window.handleSealUpload = async function(type, fileInput) {
        const file = fileInput.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('type', type);
        formData.append('seal_file', file);
        formData.append('csrf_token', csrfToken);

        const previewEl = document.getElementById(type + 'SealPreview');
        const statusEl = document.getElementById(type + 'SealStatus');
        if (statusEl) statusEl.textContent = 'Uploading...';

        try {
            const res = await fetch('../../../backend/api/settings/upload-seal.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                if (previewEl) previewEl.src = data.path + '?v=' + Date.now();
                if (statusEl) statusEl.textContent = 'Uploaded successfully!';
                showToast(data.message, 'success');
            } else {
                if (statusEl) statusEl.textContent = 'Upload failed';
                showToast(data.message, 'error');
            }
        } catch (err) {
            if (statusEl) statusEl.textContent = 'Error';
            showToast('Failed to upload seal: ' + err.message, 'error');
        }
    };

    // 5. Leaflet Mini Map Picker
    function initLeafletMiniMap() {
        const mapContainer = document.getElementById('settingsMiniMap');
        if (!mapContainer || leafletMiniMap) return;

        const latInput = document.getElementById('inputMapLat');
        const lngInput = document.getElementById('inputMapLng');
        const zoomInput = document.getElementById('inputMapZoom');

        const lat = parseFloat(latInput?.value) || 14.6644;
        const lng = parseFloat(lngInput?.value) || 121.0967;
        const zoom = parseInt(zoomInput?.value) || 15;

        if (typeof L === 'undefined') return;

        leafletMiniMap = L.map('settingsMiniMap').setView([lat, lng], zoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(leafletMiniMap);

        mapMarker = L.marker([lat, lng], { draggable: true }).addTo(leafletMiniMap);
        mapMarker.bindPopup('<b>Barangay Tumana Default Pin</b><br>Drag to update default center.').openPopup();

        mapMarker.on('dragend', function (e) {
            const position = mapMarker.getLatLng();
            if (latInput) latInput.value = position.lat.toFixed(6);
            if (lngInput) lngInput.value = position.lng.toFixed(6);
            markDirty();
        });

        leafletMiniMap.on('zoomend', function () {
            if (zoomInput) zoomInput.value = leafletMiniMap.getZoom();
            markDirty();
        });
    }

    // 6. Test Gemini AI Connection
    window.testGeminiApiConnection = async function() {
        const btn = document.getElementById('btnTestGemini');
        const chip = document.getElementById('geminiTestResultChip');
        const apiKeyInput = document.getElementById('inputGeminiApiKey');

        if (btn) btn.disabled = true;
        if (chip) {
            chip.style.display = 'inline-flex';
            chip.style.background = '#f1f5f9';
            chip.style.color = '#475569';
            chip.innerHTML = '⏳ Testing connection to Google Gemini API...';
        }

        try {
            const res = await fetch('../../../backend/api/settings/test-ai.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ gemini_api_key: apiKeyInput?.value || '' })
            });

            const data = await res.json();
            if (data.success) {
                if (chip) {
                    chip.style.background = '#dcfce7';
                    chip.style.color = '#15803d';
                    chip.innerHTML = `✓ <strong>Online</strong> (${data.latency_ms} ms) &bull; ${escapeHtml(data.response_sample)}`;
                }
                showToast(data.message, 'success');
            } else {
                if (chip) {
                    chip.style.background = '#fee2e2';
                    chip.style.color = '#b91c1c';
                    chip.innerHTML = `⚠️ <strong>Failed:</strong> ${escapeHtml(data.message)}`;
                }
                showToast(data.message, 'error');
            }
        } catch (err) {
            if (chip) {
                chip.style.background = '#fee2e2';
                chip.style.color = '#b91c1c';
                chip.innerHTML = '⚠️ Network connection failed';
            }
            showToast('Connection error: ' + err.message, 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    };

    // 7. Test SMS Modal & Sender
    window.openTestSmsModal = function() {
        document.getElementById('modalTestSms')?.classList.add('open');
    };

    window.closeTestSmsModal = function() {
        document.getElementById('modalTestSms')?.classList.remove('open');
    };

    window.submitTestSms = async function() {
        const recipient = document.getElementById('testSmsRecipient')?.value || '';
        const message = document.getElementById('testSmsMessage')?.value || '';
        const provider = document.getElementById('selectSmsProvider')?.value || 'Semaphore';
        const apiKey = document.getElementById('inputSmsApiKey')?.value || '';

        if (!recipient) {
            alert('Please enter a recipient mobile number.');
            return;
        }

        const btn = document.getElementById('btnSendTestSms');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Sending...';
        }

        try {
            const res = await fetch('../../../backend/api/settings/test-sms.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    provider: provider,
                    sms_api_key: apiKey,
                    recipient: recipient,
                    message: message
                })
            });

            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                closeTestSmsModal();
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Failed to dispatch test SMS: ' + err.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Send Test SMS';
            }
        }
    };

    // 8. Categories CRUD Modals & Handlers
    window.openCategoryModal = function(catData = null) {
        document.getElementById('catModalId').value = catData ? (catData.category_id || '') : '';
        document.getElementById('catModalName').value = catData ? (catData.category_name || '') : '';
        document.getElementById('catModalTagalog').value = catData ? (catData.tagalog_name || '') : '';
        document.getElementById('catModalLtia').value = catData ? (catData.ltia_code || '') : '';
        document.getElementById('catModalDesc').value = catData ? (catData.description || '') : '';
        document.getElementById('catModalActive').checked = catData ? (Number(catData.is_active) === 1) : true;
        document.getElementById('catModalTitle').textContent = catData ? 'Edit Dispute Category' : 'Add New Dispute Category';
        document.getElementById('modalCategoryForm')?.classList.add('open');
    };

    window.closeCategoryModal = function() {
        document.getElementById('modalCategoryForm')?.classList.remove('open');
    };

    window.submitCategoryForm = async function() {
        const id = document.getElementById('catModalId')?.value;
        const name = document.getElementById('catModalName')?.value;
        const tagalog = document.getElementById('catModalTagalog')?.value;
        const ltia = document.getElementById('catModalLtia')?.value;
        const desc = document.getElementById('catModalDesc')?.value;
        const active = document.getElementById('catModalActive')?.checked ? 1 : 0;

        if (!name.trim()) {
            alert('Category name is required.');
            return;
        }

        try {
            const res = await fetch('../../../backend/api/settings/categories.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    category_id: id,
                    category_name: name,
                    tagalog_name: tagalog,
                    ltia_code: ltia,
                    description: desc,
                    is_active: active
                })
            });

            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                closeCategoryModal();
                reloadCategoriesTable();
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Error saving category: ' + err.message, 'error');
        }
    };

    window.deleteCategory = async function(catId, catName) {
        if (!confirm(`Are you sure you want to delete or deactivate category "${catName}"?`)) return;

        try {
            const res = await fetch('../../../backend/api/settings/categories.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ action: 'delete', category_id: catId })
            });

            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                reloadCategoriesTable();
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Error deleting category: ' + err.message, 'error');
        }
    };

    async function reloadCategoriesTable() {
        try {
            const res = await fetch('../../../backend/api/settings/categories.php');
            const data = await res.json();
            if (data.success && data.categories) {
                const tbody = document.getElementById('categoriesTableTbody');
                if (!tbody) return;
                tbody.innerHTML = data.categories.map(c => `
                    <tr>
                        <td style="font-weight:700; color:#64748b;">#${c.category_id}</td>
                        <td><strong>${escapeHtml(c.category_name)}</strong></td>
                        <td style="color:#047857;">${escapeHtml(c.tagalog_name || '—')}</td>
                        <td><span class="badge-ltia">${escapeHtml(c.ltia_code || 'N/A')}</span></td>
                        <td><span class="${Number(c.is_active) === 1 ? 'badge-active' : 'badge-inactive'}">${Number(c.is_active) === 1 ? 'Active' : 'Inactive'}</span></td>
                        <td>${c.complaint_count || 0}</td>
                        <td style="text-align:right;">
                            <button type="button" class="btn-secondary btn-sm" onclick='openCategoryModal(${JSON.stringify(c)})'>✏️ Edit</button>
                            <button type="button" class="btn-danger btn-sm" onclick='deleteCategory(${c.category_id}, "${escapeHtml(c.category_name)}")'>🗑️</button>
                        </td>
                    </tr>
                `).join('');
            }
        } catch (_) {}
    }

    // 9. Holiday Manager Handlers
    window.openAddHolidayModal = function() {
        document.getElementById('holidayDate').value = new Date().toISOString().split('T')[0];
        document.getElementById('holidayName').value = '';
        document.getElementById('holidayType').value = 'Regular Holiday';
        document.getElementById('modalHolidayForm')?.classList.add('open');
    };

    window.closeHolidayModal = function() {
        document.getElementById('modalHolidayForm')?.classList.remove('open');
    };

    window.submitHolidayForm = function() {
        const date = document.getElementById('holidayDate')?.value;
        const name = document.getElementById('holidayName')?.value;
        const type = document.getElementById('holidayType')?.value;

        if (!date || !name.trim()) {
            alert('Date and holiday name are required.');
            return;
        }

        window.currentHolidaysList = window.currentHolidaysList || [];
        window.currentHolidaysList.push({ date, name: name.trim(), type });
        renderHolidaysTable();
        closeHolidayModal();
        markDirty();
        showToast('Holiday added to schedule queue.', 'info');
    };

    window.removeHoliday = function(index) {
        if (!confirm('Remove this holiday from the schedule exclusion list?')) return;
        window.currentHolidaysList.splice(index, 1);
        renderHolidaysTable();
        markDirty();
    };

    function renderHolidaysTable() {
        const tbody = document.getElementById('holidaysTableTbody');
        if (!tbody) return;
        const list = window.currentHolidaysList || [];
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:20px;">No holidays configured.</td></tr>';
            return;
        }
        tbody.innerHTML = list.map((h, idx) => `
            <tr>
                <td style="font-family:monospace; font-weight:700;">${escapeHtml(h.date)}</td>
                <td><strong>${escapeHtml(h.name)}</strong></td>
                <td><span class="badge-ltia">${escapeHtml(h.type || 'Holiday')}</span></td>
                <td style="text-align:right;">
                    <button type="button" class="btn-danger btn-sm" onclick="removeHoliday(${idx})">&times; Remove</button>
                </td>
            </tr>
        `).join('');
    }

    window.renderHolidaysTable = renderHolidaysTable;

    // 10. Personal Profile & Password Update Handlers
    window.savePersonalProfile = async function() {
        const btn = document.getElementById('btnSaveProfile');
        if (btn) btn.disabled = true;

        const fn = document.getElementById('profileFirstName')?.value || '';
        const mn = document.getElementById('profileMiddleName')?.value || '';
        const ln = document.getElementById('profileLastName')?.value || '';
        const email = document.getElementById('profileEmail')?.value || '';
        const contact = document.getElementById('profileContact')?.value || '';
        const audio = document.getElementById('profileAudioToggle')?.checked ? 1 : 0;
        const inbox = document.getElementById('profileInboxToggle')?.checked ? 1 : 0;
        const lang = document.getElementById('profileLangSelect')?.value || 'fil';

        try {
            const res = await fetch('../../../backend/api/settings/profile.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    first_name: fn,
                    middle_name: mn,
                    last_name: ln,
                    email: email,
                    contact_no: contact,
                    notification_audio: audio,
                    notification_inbox: inbox,
                    preferred_language: lang
                })
            });

            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Failed to update profile: ' + err.message, 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    };

    window.savePersonalPassword = async function() {
        const cur = document.getElementById('passCurrent')?.value || '';
        const newP = document.getElementById('passNew')?.value || '';
        const conf = document.getElementById('passConfirm')?.value || '';

        if (!cur || !newP || !conf) {
            showToast('Please fill in all password fields.', 'error');
            return;
        }

        if (newP !== conf) {
            showToast('New password and confirm password do not match.', 'error');
            return;
        }

        if (newP.length < 12 || !/[a-z]/.test(newP) || !/[A-Z]/.test(newP) || !/\d/.test(newP)) {
            showToast('Use at least 12 characters with uppercase, lowercase, and a number.', 'error');
            return;
        }

        const btn = document.getElementById('btnSavePassword');
        if (btn) btn.disabled = true;

        try {
            const res = await fetch('../../../backend/api/settings/change-password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    current_password: cur,
                    new_password: newP,
                    confirm_password: conf
                })
            });

            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                document.getElementById('passCurrent').value = '';
                document.getElementById('passNew').value = '';
                document.getElementById('passConfirm').value = '';
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Failed to update password: ' + err.message, 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    };

    // =========================================================================
    // PANGKAT DUTY GROUPS & ROSTER MANAGEMENT
    // =========================================================================
    let pangkatDutyGroupsList = [];
    let cachedLuponMembers = [];

    async function fetchLuponMembersForDuty() {
        if (cachedLuponMembers.length > 0) return cachedLuponMembers;
        try {
            const res = await fetch('../../../backend/api/assignments/lupon-members.php');
            const data = await res.json();
            cachedLuponMembers = Array.isArray(data) ? data : (data.data || []);
        } catch (e) {
            console.error('Failed to load Lupon members:', e);
            cachedLuponMembers = [];
        }
        return cachedLuponMembers;
    }

    window.loadDutyGroups = async function() {
        const tbody = document.getElementById('dutyGroupsTableTbody');
        if (!tbody) return;
        try {
            const res = await fetch('../../../backend/api/pangkat/duty-groups.php');
            const data = await res.json();
            pangkatDutyGroupsList = data.data || [];
            renderDutyGroupsTable();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#dc2626; padding:20px;">Failed to load duty groups: ${escapeHtml(e.message)}</td></tr>`;
        }
    };

    function renderDutyGroupsTable() {
        const tbody = document.getElementById('dutyGroupsTableTbody');
        if (!tbody) return;
        if (!pangkatDutyGroupsList || pangkatDutyGroupsList.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align: center; color: #64748b; padding: 32px 16px;">
                        <div style="font-size: 1.5rem; margin-bottom: 6px;">👥</div>
                        <strong>No Pangkat duty groups created yet.</strong>
                        <p style="margin: 4px 0 12px 0; font-size: 0.82rem; color: #94a3b8;">
                            Create named groups (e.g., Wednesday Team) with official scheduled duty days to enable 1-click bulk conciliation panel selection.
                        </p>
                        <button type="button" class="btn-primary btn-sm" onclick="openDutyGroupModal()">+ Create First Duty Group</button>
                    </td>
                </tr>
            `;
            return;
        }

        const dayColors = {
            'Monday': '#0284c7',
            'Tuesday': '#d97706',
            'Wednesday': '#16a34a',
            'Thursday': '#7c3aed',
            'Friday': '#059669',
            'Saturday': '#475569'
        };

        tbody.innerHTML = pangkatDutyGroupsList.map(g => {
            const dayColor = dayColors[g.duty_day] || '#0284c7';
            const chair = g.members?.find(m => m.position === 'Chairman');
            const sec = g.members?.find(m => m.position === 'Secretary');
            const mem = g.members?.find(m => m.position === 'Member');

            const chairLabel = chair ? `<strong>${escapeHtml(chair.member_name)}</strong> <small style="color:#0369a1; font-weight:600;">(Chair)</small>` : '<em style="color:#dc2626;">Missing Chair</em>';
            const secLabel = sec ? `<strong>${escapeHtml(sec.member_name)}</strong> <small style="color:#047857; font-weight:600;">(Sec)</small>` : '<em style="color:#dc2626;">Missing Sec</em>';
            const memLabel = mem ? `<strong>${escapeHtml(mem.member_name)}</strong> <small style="color:#64748b; font-weight:600;">(Member)</small>` : '<em style="color:#dc2626;">Missing Member</em>';

            const statusBadge = g.is_active === 1
                ? (g.is_complete ? '<span class="badge-active">Active</span>' : '<span class="badge-inactive" style="background:#fef3c7; color:#92400e;">Incomplete</span>')
                : '<span class="badge-inactive">Inactive</span>';

            const payloadAttr = JSON.stringify(g).replace(/"/g, '&quot;');

            return `
                <tr>
                    <td>
                        <strong style="color: #0f172a; font-size: 0.92rem;">${escapeHtml(g.group_name)}</strong>
                        ${g.notes ? `<div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">${escapeHtml(g.notes)}</div>` : ''}
                    </td>
                    <td>
                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 9999px; font-size: 0.78rem; font-weight: 700; background: ${dayColor}15; color: ${dayColor}; border: 1px solid ${dayColor}40;">
                            📅 ${escapeHtml(g.duty_day)}
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #1e293b; font-size: 0.84rem;">⏰ ${escapeHtml(g.start_time)} – ${escapeHtml(g.end_time)}</div>
                        <div style="font-size: 0.78rem; color: #64748b;">📍 ${escapeHtml(g.station || 'Lupon Court Room')}</div>
                    </td>
                    <td style="font-size: 0.82rem; line-height: 1.55;">
                        <div>${chairLabel}</div>
                        <div>${secLabel}</div>
                        <div>${memLabel}</div>
                    </td>
                    <td>
                        <span style="font-weight: 700; color: #0f172a; font-size: 0.88rem;">${g.cases_assigned}</span>
                        <div style="font-size: 0.74rem; color: #64748b;">case panels</div>
                    </td>
                    <td>${statusBadge}</td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button type="button" class="btn-secondary btn-sm" onclick="editDutyGroup(${payloadAttr})">✏️ Edit</button>
                        <button type="button" class="btn-danger btn-sm" onclick="deleteDutyGroup(${g.duty_group_id}, '${escapeHtml(g.group_name).replace(/'/g, "\\'")}')">🗑️</button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    window.openDutyGroupModal = async function(data = null) {
        const modal = document.getElementById('modalDutyGroupForm');
        if (!modal) return;

        const members = await fetchLuponMembersForDuty();
        const populateSelect = (id, selectedVal) => {
            const sel = document.getElementById(id);
            if (!sel) return;
            let opts = '<option value="">-- Select Active Lupon Member --</option>';
            members.forEach(m => {
                const name = `${m.last_name}, ${m.first_name} ${m.middle_name || ''}`.trim();
                opts += `<option value="${m.member_id}">${escapeHtml(name)}</option>`;
            });
            sel.innerHTML = opts;
            if (selectedVal) sel.value = String(selectedVal);
        };

        const title = document.getElementById('dutyGroupModalTitle');
        const idInput = document.getElementById('dutyGroupId');
        const nameInput = document.getElementById('dutyGroupName');
        const daySelect = document.getElementById('dutyGroupDay');
        const startInput = document.getElementById('dutyGroupStart');
        const endInput = document.getElementById('dutyGroupEnd');
        const stationInput = document.getElementById('dutyGroupStation');
        const notesInput = document.getElementById('dutyGroupNotes');
        const activeInput = document.getElementById('dutyGroupActive');
        const conflictNotice = document.getElementById('dutyGroupConflictNotice');
        if (conflictNotice) conflictNotice.style.display = 'none';

        if (data) {
            title.textContent = 'Edit Pangkat Duty Group';
            idInput.value = data.duty_group_id || '';
            nameInput.value = data.group_name || '';
            daySelect.value = data.duty_day || 'Wednesday';
            startInput.value = data.start_time || '08:00';
            endInput.value = data.end_time || '17:00';
            stationInput.value = data.station || 'Lupon Court Room';
            notesInput.value = data.notes || '';
            activeInput.checked = (data.is_active === 1);

            const chair = data.members?.find(m => m.position === 'Chairman');
            const sec = data.members?.find(m => m.position === 'Secretary');
            const mem = data.members?.find(m => m.position === 'Member');

            populateSelect('dutyGroupChairman', chair?.member_id);
            populateSelect('dutyGroupSecretary', sec?.member_id);
            populateSelect('dutyGroupMember', mem?.member_id);
        } else {
            title.textContent = 'Create Pangkat Duty Group';
            idInput.value = '';
            nameInput.value = '';
            daySelect.value = 'Wednesday';
            startInput.value = '08:00';
            endInput.value = '17:00';
            stationInput.value = 'Lupon Court Room';
            notesInput.value = '';
            activeInput.checked = true;

            populateSelect('dutyGroupChairman', null);
            populateSelect('dutyGroupSecretary', null);
            populateSelect('dutyGroupMember', null);
        }

        modal.classList.add('active');
    };

    window.closeDutyGroupModal = function() {
        document.getElementById('modalDutyGroupForm')?.classList.remove('active');
    };

    window.editDutyGroup = function(group) {
        openDutyGroupModal(group);
    };

    window.submitDutyGroupForm = async function() {
        const id = document.getElementById('dutyGroupId')?.value;
        const name = document.getElementById('dutyGroupName')?.value.trim();
        const day = document.getElementById('dutyGroupDay')?.value;
        const start = document.getElementById('dutyGroupStart')?.value;
        const end = document.getElementById('dutyGroupEnd')?.value;
        const station = document.getElementById('dutyGroupStation')?.value.trim();
        const notes = document.getElementById('dutyGroupNotes')?.value.trim();
        const isActive = document.getElementById('dutyGroupActive')?.checked ? 1 : 0;

        const chairId = Number(document.getElementById('dutyGroupChairman')?.value);
        const secId = Number(document.getElementById('dutyGroupSecretary')?.value);
        const memId = Number(document.getElementById('dutyGroupMember')?.value);

        if (!name) {
            showToast('Please provide a name for the Pangkat duty group.', 'error');
            return;
        }
        if (!chairId || !secId || !memId) {
            showToast('Please assign all three positions: Chairman, Secretary, and regular Member.', 'error');
            return;
        }
        if (new Set([chairId, secId, memId]).size !== 3) {
            showToast('Chairman, Secretary, and Member must be three different Lupon Members.', 'error');
            return;
        }

        const btn = document.getElementById('btnSaveDutyGroup');
        if (btn) btn.disabled = true;

        const payload = {
            action: 'save',
            duty_group_id: id ? Number(id) : null,
            group_name: name,
            duty_day: day,
            start_time: start,
            end_time: end,
            station: station || 'Lupon Court Room',
            notes: notes,
            is_active: isActive,
            chairman_id: chairId,
            secretary_id: secId,
            member_id: memId
        };

        try {
            const res = await fetch('../../../backend/api/pangkat/duty-groups.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                closeDutyGroupModal();
                loadDutyGroups();
            } else {
                showToast(data.message || 'Unable to save duty group.', 'error');
            }
        } catch (e) {
            showToast('Failed to save duty group: ' + e.message, 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    };

    window.deleteDutyGroup = async function(groupId, groupName) {
        if (!confirm(`Are you sure you want to delete "${groupName}"?\n\nMembers will no longer have this group stationed on their official duty calendar.`)) {
            return;
        }
        try {
            const res = await fetch('../../../backend/api/pangkat/duty-groups.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ action: 'delete', duty_group_id: groupId })
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                loadDutyGroups();
            } else {
                showToast(data.message || 'Unable to delete duty group.', 'error');
            }
        } catch (e) {
            showToast('Failed to delete duty group: ' + e.message, 'error');
        }
    };

    // If initial tab is duty-groups
    if (targetTabName === 'duty-groups' || targetTabName === 'tab-duty-groups') {
        loadDutyGroups();
    }

    // Initialize Holidays Table on initial load
    renderHolidaysTable();
});
