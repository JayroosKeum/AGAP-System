/**
 * AGAP - Summons / Process Server Dashboard ("Kuya")
 * Mobile-First Field Dispatch, Purok-based Batch Routing & 1-Tap Service Logger
 */

(() => {
    'use strict';

    const state = {
        purok: 'all',
        status: 'pending',
        isLoading: false,
        tasks: [],
        activeDelivery: null
    };

    const getCsrfToken = () => {
        return window.agapCsrfToken || document.querySelector('meta[name="agap-csrf-token"]')?.content || '';
    };

    const escapeHtml = (str) => {
        if (str === null || str === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(str).replace(/[&<>"']/g, (m) => map[m]);
    };

    const formatDateClean = (dateString) => {
        if (!dateString) return '';
        const d = new Date(dateString.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateString;
        return d.toLocaleDateString('fil-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const formatTimeOnly = (dateString) => {
        if (!dateString) return '';
        const clean = String(dateString).trim();
        if (/^\d{2}:\d{2}(:\d{2})?$/.test(clean)) {
            const parts = clean.split(':');
            const h = parseInt(parts[0], 10);
            const m = parts[1];
            const ampm = h >= 12 ? 'PM' : 'AM';
            const h12 = h % 12 || 12;
            return `${h12}:${m} ${ampm}`;
        }
        const d = new Date(dateString.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateString;
        return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    };

    const showToast = (message, isError = false) => {
        let toast = document.getElementById('serverDashboardToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'serverDashboardToast';
            toast.style.cssText = `
                position: fixed;
                bottom: 24px;
                right: 24px;
                padding: 14px 22px;
                background: #064e3b;
                color: #ffffff;
                font-size: 0.95rem;
                font-weight: 700;
                border-radius: 10px;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2);
                z-index: 999999;
                display: flex;
                align-items: center;
                gap: 10px;
                transform: translateY(100px);
                opacity: 0;
                transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            `;
            document.body.appendChild(toast);
        }

        toast.style.background = isError ? '#991b1b' : '#064e3b';
        toast.innerHTML = `<span>${escapeHtml(message)}</span>`;
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';

        setTimeout(() => {
            toast.style.transform = 'translateY(100px)';
            toast.style.opacity = '0';
        }, 3500);
    };

    // Render Dispatch Summary KPIs
    const renderKpis = (kpis) => {
        if (!kpis) return;
        const setText = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(val ?? 0);
        };
        setText('kpiPendingDelivery', kpis.pending_delivery);
        setText('kpiServedToday', kpis.served_today);
        setText('kpiUnservedFollowup', kpis.unserved_followup);
    };

    // Populate Purok Dropdown
    const renderPurokDropdown = (puroks) => {
        const select = document.getElementById('purokRouteSelect');
        if (!select || !puroks || select.dataset.loaded === 'true') return;

        select.innerHTML = '<option value="all">Lahat ng Purok (All Routes)</option>';
        puroks.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p;
            opt.textContent = p;
            if (p === state.purok) opt.selected = true;
            select.appendChild(opt);
        });
        select.dataset.loaded = 'true';
    };

    // Render Dispatch Tasks Stream
    const renderTasks = (tasks) => {
        const container = document.getElementById('dispatchTasksContainer');
        const countBadge = document.getElementById('dispatchTasksCount');
        if (!container) return;

        const items = tasks || [];
        if (countBadge) countBadge.textContent = `${items.length} Hatid`;

        container.replaceChildren();

        if (items.length === 0) {
            container.innerHTML = `
                <div style="padding: 36px 20px; text-align: center; color: #475569; background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 14px;">
                    
                    <strong style="font-size: 1.15rem; color: #0f172a;">Walang nakatakdang summons delivery para sa filter na ito.</strong>
                    <p style="margin: 6px 0 0; font-size: 0.92rem; color: #64748b;">
                        Pumili ng ibang Purok o i-filter ang status gamit ang mga pindutan sa itaas.
                    </p>
                </div>
            `;
            return;
        }

        items.forEach(task => {
            const card = document.createElement('article');
            const isServed = (task.delivery_status || '').startsWith('Served');
            const isUnserved = ['Unserved', 'Refused', 'Attempted'].includes(task.delivery_status);
            const cardClass = isServed ? 'task-served' : (isUnserved ? 'task-unserved' : 'task-pending');
            card.className = `dispatch-task-card ${cardClass}`;

            const isComp = task.party_type === 'Complainant';

            card.innerHTML = `
                <div class="dispatch-card-top">
                    <div>
                        <span class="dispatch-purok-tag">${escapeHtml(task.purok || 'Tumana')}</span>
                        <div class="dispatch-party-name">${escapeHtml(task.full_name)}</div>
                        <span class="dispatch-party-type ${isComp ? 'complainant' : 'respondent'}">${escapeHtml(task.party_type || 'Panig')}</span>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 0.82rem; font-weight: 800; padding: 4px 10px; border-radius: 6px; background: #f1f5f9; color: #334155;">
                            ${escapeHtml(task.form_type || 'KP Form')}
                        </span>
                        <div style="font-size: 0.88rem; font-weight: 700; color: #0f172a; margin-top: 4px;">
                            Case #${escapeHtml(task.case_number)}
                        </div>
                    </div>
                </div>

                <div class="dispatch-card-address">
                    <div><strong>Tirahan:</strong> ${escapeHtml(task.house_street || task.address || task.purok || 'Tumana, Marikina')}</div>
                    ${task.contact_no ? `<div><strong>Telepono:</strong> <a href="tel:${escapeHtml(task.contact_no)}">${escapeHtml(task.contact_no)}</a></div>` : ''}
                </div>

                <div class="dispatch-card-hearing-meta">
                    <span>Pagdinig: <strong>${escapeHtml(formatDateClean(task.hearing_date))} (${formatTimeOnly(task.hearing_date)})</strong></span>
                    <span>•</span>
                    <span>Lugar: ${escapeHtml(task.venue || 'Lupon Court Room')}</span>
                    <span>•</span>
                    <span style="font-weight: 800; color: ${isServed ? '#15803d' : (isUnserved ? '#b91c1c' : '#b45309')};">
                        Status: ${escapeHtml(task.delivery_status || 'Pending')}
                    </span>
                    ${task.proof_file_path ? `<span>•</span><a href="../../../${escapeHtml(task.proof_file_path)}" target="_blank" style="color:#0284c7; font-weight:700;">Tingnan ang Litrato</a>` : ''}
                </div>

                <div class="tap-actions-toolbar">
                    ${task.replacement_document_id ? `
                    <a class="btn-secondary" href="../../../backend/api/documents/kp-form.php?document_id=${Number(task.replacement_document_id)}&amp;pdf=1" target="_blank" rel="noopener">Open printable notice</a>
                    <a class="btn-secondary" href="../gps/proof-service.php?case_id=${Number(task.case_id)}&amp;hearing_id=${Number(task.hearing_id)}">Open Proof of Service</a>
                    ` : `
                    <button type="button" class="btn-tap-action btn-tap-served" data-action="served" data-id="${task.delivery_id}">
                        Served
                    </button>
                    <button type="button" class="btn-tap-action btn-tap-refused" data-action="refused" data-id="${task.delivery_id}">
                        Refused to Sign
                    </button>
                    <button type="button" class="btn-tap-action btn-tap-unserved" data-action="unserved" data-id="${task.delivery_id}">
                        Unserved (Not Found)
                    </button>
                    <button type="button" class="btn-tap-action btn-tap-photo" data-action="photo" data-id="${task.delivery_id}">
                        Upload Proof
                    </button>
                    `}
                </div>
            `;

            // Bind 1-tap quick buttons
            card.querySelectorAll('.btn-tap-action').forEach(btn => {
                btn.addEventListener('click', () => {
                    const action = btn.dataset.action;
                    handleServiceAction(task, action);
                });
            });

            container.appendChild(card);
        });
    };

    // Open Modal for Detailed Service Logging & Photo Capture
    const handleServiceAction = (task, action) => {
        state.activeDelivery = task;
        const modal = document.getElementById('serviceLoggerModal');
        const modalTitle = document.getElementById('loggerModalTitle');
        const actionInput = document.getElementById('loggerActionInput');
        const deliveryIdInput = document.getElementById('loggerDeliveryId');
        const recipientInput = document.getElementById('loggerRecipientName');
        const remarksInput = document.getElementById('loggerRemarks');
        const unservedReasonWrap = document.getElementById('loggerUnservedReasonWrap');
        const photoPreview = document.getElementById('loggerPhotoPreview');
        const photoInput = document.getElementById('loggerProofFile');

        if (!modal) return;

        deliveryIdInput.value = task.delivery_id;
        actionInput.value = action === 'photo' ? 'served' : action;
        recipientInput.value = task.full_name || '';
        remarksInput.value = '';
        if (photoInput) photoInput.value = '';
        if (photoPreview) photoPreview.innerHTML = '<strong>I-click upang kumuha o mag-upload ng litrato (Gate/Tirahan/Kopya)</strong>';

        if (action === 'served' || action === 'photo') {
            modalTitle.textContent = 'Itala bilang "Served" (Naihatid)';
            if (unservedReasonWrap) unservedReasonWrap.style.display = 'none';
        } else if (action === 'refused') {
            modalTitle.textContent = 'Itala bilang "Refused to Sign" (Tumanggi Lumagda)';
            if (unservedReasonWrap) unservedReasonWrap.style.display = 'none';
            remarksInput.value = 'Tumanggi lumagda ang panig ngunit personal na iniwan ang kopya.';
        } else if (action === 'unserved') {
            modalTitle.textContent = 'Itala bilang "Unserved" (Hindi Naihatid / Need Follow-up)';
            if (unservedReasonWrap) unservedReasonWrap.style.display = 'block';
        }

        modal.style.display = 'flex';
    };

    const closeLoggerModal = () => {
        const modal = document.getElementById('serviceLoggerModal');
        if (modal) modal.style.display = 'none';
        state.activeDelivery = null;
    };

    // Submit 1-Tap Service Action
    const submitServiceAction = async () => {
        const form = document.getElementById('serviceLoggerForm');
        const saveBtn = document.getElementById('btnSaveServiceLog');
        if (!form) return;

        const formData = new FormData(form);

        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = 'Isinusumite...';
        }

        try {
            const res = await fetch('../../../backend/api/dashboard/server-dispatch.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': getCsrfToken()
                },
                body: formData
            });

            const data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.message || 'Nabigo sa pag-log ng service action.');
            }

            showToast(data.message || 'Nai-save ang service action!');
            closeLoggerModal();
            fetchServerData();
        } catch (err) {
            showToast(err.message, true);
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = 'I-save ang Tala';
            }
        }
    };

    // Fetch Dashboard Data from API
    const fetchServerData = async () => {
        state.isLoading = true;
        try {
            const url = `../../../backend/api/dashboard/server-dispatch.php?purok=${encodeURIComponent(state.purok)}&status=${encodeURIComponent(state.status)}`;
            const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const json = await res.json();

            if (!res.ok || !json.success || !json.data) {
                throw new Error(json.message || 'Failed to fetch dispatch tasks.');
            }

            const data = json.data;
            state.tasks = data.tasks || [];

            renderKpis(data.kpis);
            renderPurokDropdown(data.puroks);
            renderTasks(data.tasks);

        } catch (err) {
            console.error('Process Server Dispatch Error:', err);
            showToast('Unable to load field tasks: ' + err.message, true);
        } finally {
            state.isLoading = false;
        }
    };

    // Initialize Listeners
    const initListeners = () => {
        // Purok Filter change
        document.getElementById('purokRouteSelect')?.addEventListener('change', (e) => {
            state.purok = e.target.value;
            fetchServerData();
        });

        const updateServerKpiActiveUI = () => {
            document.getElementById('kpiPendingCard')?.classList.toggle('is-active-tab', state.status === 'pending');
            document.getElementById('kpiServedCard')?.classList.toggle('is-active-tab', state.status === 'served');
            document.getElementById('kpiUnservedCard')?.classList.toggle('is-active-tab', state.status === 'unserved');
        };

        const setServerStatus = (newStatus) => {
            if (state.status === newStatus) {
                state.status = 'all';
            } else {
                state.status = newStatus;
            }
            document.querySelectorAll('.btn-status-filter').forEach(b => b.classList.toggle('active', b.dataset.status === state.status));
            updateServerKpiActiveUI();
            fetchServerData();
            document.getElementById('dispatchTasksContainer')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        // Status Tabs click
        document.querySelectorAll('.btn-status-filter').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.btn-status-filter').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                state.status = btn.dataset.status;
                updateServerKpiActiveUI();
                fetchServerData();
            });
        });

        // KPI card quick click & keyboard navigation
        const bindKpiCard = (id, targetStatus) => {
            const card = document.getElementById(id);
            if (!card) return;
            card.addEventListener('click', () => setServerStatus(targetStatus));
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    setServerStatus(targetStatus);
                }
            });
        };

        // KPI stat cards remain static; explicit filter tabs handle status switching.

        // Modal triggers
        document.getElementById('loggerCloseBtn')?.addEventListener('click', closeLoggerModal);
        document.getElementById('loggerCancelBtn')?.addEventListener('click', closeLoggerModal);
        document.getElementById('btnSaveServiceLog')?.addEventListener('click', submitServiceAction);

        // Photo Upload Trigger & Preview
        const photoInput = document.getElementById('loggerProofFile');
        const photoWrap = document.getElementById('loggerPhotoWrap');
        const photoPreview = document.getElementById('loggerPhotoPreview');

        if (photoWrap && photoInput) {
            photoWrap.addEventListener('click', () => photoInput.click());
            photoInput.addEventListener('change', () => {
                const file = photoInput.files[0];
                if (file && photoPreview) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        photoPreview.innerHTML = `<img src="${e.target.result}" alt="Proof Preview"><div style="margin-top:6px; font-weight:700; color:#047857;">Litrato napili: ${escapeHtml(file.name)}</div>`;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        document.getElementById('serviceLoggerModal')?.addEventListener('click', (e) => {
            if (e.target.id === 'serviceLoggerModal') closeLoggerModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeLoggerModal();
        });
    };

    // Bootstrap
    const boot = () => {
        initListeners();
        fetchServerData();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
