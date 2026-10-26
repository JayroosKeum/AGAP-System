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
        });

        // Update URL hash without scroll jump
        history.replaceState(null, '', '#' + tabId.replace('tab-', ''));
    };

    navButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            switchSettingsTab(btn.dataset.tab);
        });
    });

    // Handle initial hash in URL
    const initialHash = window.location.hash.replace('#', '');
    if (initialHash && document.getElementById('tab-' + initialHash)) {
        switchSettingsTab('tab-' + initialHash);
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

        // Toggles / checkboxes not in form data if unchecked
        const toggles = settingsForm.querySelectorAll('input[type="checkbox"]');
        toggles.forEach(t => {
            if (t.name) {
                payload[t.name] = t.checked ? '1' : '0';
            }
        });

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
            alert('Please fill in all password fields.');
            return;
        }

        if (newP !== conf) {
            alert('New password and confirm password do not match.');
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

    // Initialize Holidays Table on initial load
    renderHolidaysTable();
});
