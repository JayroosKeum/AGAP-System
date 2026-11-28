/**
 * AGAP - Summons & Field Dispatch Dashboard and Service Logging Flow
 */

(() => {
    'use strict';

    const state = {
        purok: 'all',
        status: 'pending',
        search: '',
        isLoading: false,
        tasks: [],
        activeTask: null,
        mobilePreview: false
    };

    const DRAFT_PREFIX = 'agap_dispatch_draft_v1_';

    const getCsrfToken = () => {
        return window.agapCsrfToken || document.querySelector('meta[name="agap-csrf-token"]')?.content || '';
    };

    const escapeHtml = (str) => {
        if (str === null || str === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(str).replace(/[&<>"']/g, (m) => map[m]);
    };

    const getTaskKey = (task) => {
        if (task?.task_kind === 'paanyaya' && Number(task?.paanyaya_attempt_id) > 0) {
            return `paanyaya_${Number(task.paanyaya_attempt_id)}`;
        }
        return `summon_${Number(task?.delivery_id || 0)}`;
    };

    const loadDraft = (task) => {
        try {
            const raw = localStorage.getItem(DRAFT_PREFIX + getTaskKey(task));
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    };

    const saveDraft = (task, payload) => {
        try {
            localStorage.setItem(DRAFT_PREFIX + getTaskKey(task), JSON.stringify(payload));
        } catch {
            // Ignore storage quota errors
        }
    };

    const clearDraft = (task) => {
        try {
            localStorage.removeItem(DRAFT_PREFIX + getTaskKey(task));
        } catch {
            // Ignore storage errors
        }
    };

    const formatNoticeBadge = (task) => {
        const raw = String(task?.form_type || task?.hearing_type || 'Notice').trim();
        const invNum = Number(task?.invitation_number || 0);
        if (task?.task_kind === 'paanyaya' || /^Paanyaya\s+/i.test(raw)) {
            if (invNum === 2 || /2/.test(raw)) return '2nd Paanyaya';
            return '1st Paanyaya';
        }
        if (/KP\s*Form\s*8\b/i.test(raw)) return 'KP-8 Notice';
        if (/KP\s*Form\s*9\b/i.test(raw)) return 'KP-9 Summons';
        if (/KP\s*Form\s*12\b/i.test(raw)) return 'KP-12 Notice';
        if (/KP\s*Form\s*19\b/i.test(raw)) return 'KP-19 Notice';
        return raw;
    };

    const getLocalDateIso = (offsetDays = 0) => {
        const d = new Date();
        d.setDate(d.getDate() + offsetDays);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    };

    const formatScheduleBadge = (dateString) => {
        if (!dateString) return 'Schedule TBD';
        const iso = String(dateString).trim().replace(' ', 'T');
        const d = new Date(iso);
        if (isNaN(d.getTime())) return String(dateString);

        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const datePart = `${y}-${m}-${day}`;

        const timeLabel = d.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });

        let dayLabel = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        if (datePart === getLocalDateIso(0)) {
            dayLabel = 'Today';
        } else if (datePart === getLocalDateIso(1)) {
            dayLabel = 'Tomorrow';
        }

        return `${dayLabel} · ${timeLabel}`;
    };

    const showToast = (message, isError = false) => {
        if (typeof window.agapNotify === 'function') {
            window.agapNotify(message, isError ? 'error' : 'success', 'Field Dispatch');
            return;
        }
        let toast = document.getElementById('serverDashboardToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'serverDashboardToast';
            toast.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:12px 18px;background:#064e3b;color:#fff;font-size:0.88rem;font-weight:700;border-radius:10px;box-shadow:0 10px 18px rgba(0,0,0,0.2);z-index:999999;transition:opacity 0.2s ease;';
            document.body.appendChild(toast);
        }
        toast.style.background = isError ? '#991b1b' : '#064e3b';
        toast.textContent = message;
        toast.style.opacity = '1';
        setTimeout(() => { toast.style.opacity = '0'; }, 3200);
    };

    const renderKpisAndTabs = (kpis, counts) => {
        const setText = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(val ?? 0);
        };
        if (kpis) {
            setText('kpiPendingDelivery', kpis.pending_delivery);
            setText('kpiServedToday', kpis.served_today);
            setText('kpiUnservedFollowup', kpis.unserved_followup);
        }
        if (counts) {
            setText('tabCountPending', counts.pending);
            setText('tabCountServed', counts.served);
            setText('tabCountUnserved', counts.unserved);
        }
    };

    const renderPurokDropdown = (puroks) => {
        const select = document.getElementById('purokRouteSelect');
        if (!select || !puroks || select.dataset.loaded === 'true') return;

        select.innerHTML = '<option value="all">Lahat ng Purok (All Routes)</option>';
        puroks.forEach((p) => {
            const opt = document.createElement('option');
            opt.value = p;
            opt.textContent = p;
            if (p === state.purok) opt.selected = true;
            select.appendChild(opt);
        });
        select.dataset.loaded = 'true';
    };

    const getFilteredTasks = () => {
        const q = state.search.trim().toLowerCase();
        if (!q) return state.tasks;
        return state.tasks.filter((task) => {
            const haystack = [
                task.full_name,
                task.case_number,
                task.address,
                task.house_street,
                task.purok,
                task.party_type,
                task.form_type,
                formatNoticeBadge(task)
            ].filter(Boolean).join(' ').toLowerCase();
            return haystack.includes(q);
        });
    };

    const renderTasks = () => {
        const container = document.getElementById('dispatchTasksContainer');
        if (!container) return;

        const items = getFilteredTasks();
        container.replaceChildren();

        if (items.length === 0) {
            container.innerHTML = `
                <div style="padding: 36px 20px; text-align: center; color: #475569; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 12px;">
                    <strong style="font-size: 1rem; color: #0f172a;">Walang nakatakdang summons delivery para sa filter na ito.</strong>
                    <p style="margin: 6px 0 0; font-size: 0.86rem; color: #64748b;">
                        Subukan ang ibang status tab, Purok route, o search keyword.
                    </p>
                </div>
            `;
            return;
        }

        items.forEach((task) => {
            const card = document.createElement('article');
            const rawStatus = String(task.delivery_status || 'Pending');
            const isServed = rawStatus.startsWith('Served');
            const isUnserved = ['Unserved', 'Refused', 'Attempted'].includes(rawStatus);
            const statusClass = isServed ? 'is-served' : (isUnserved ? 'is-unserved' : 'is-pending');
            const statusLabel = isServed
                ? (rawStatus === 'Served Refused' ? 'Served (Refused)' : 'Served')
                : (isUnserved ? 'Follow-up' : 'Pending');

            const isPending = !isServed && !isUnserved;
            const daysUntil = task.days_until_hearing !== null && task.days_until_hearing !== undefined
                ? Number(task.days_until_hearing)
                : null;
            const isUrgent = isPending && daysUntil !== null && !isNaN(daysUntil) && daysUntil <= 1;

            const noticeBadge = formatNoticeBadge(task);
            const attemptNum = Math.max(1, Number(task.attempt_number || 1));
            const schedText = `${formatScheduleBadge(task.hearing_date)} (Attempt ${attemptNum})`;
            const addrText = task.house_street || task.address || task.purok || 'Barangay Tumana';
            const hasDraft = Boolean(loadDraft(task));

            let docLinkHtml = '';
            if (task.task_kind === 'paanyaya' && Number(task.paanyaya_attempt_id) > 0) {
                docLinkHtml = `<a class="dispatch-pdf-link" href="../../../backend/api/paanyaya/service.php?attempt_id=${Number(task.paanyaya_attempt_id)}&amp;view=invitation" target="_blank" rel="noopener">View PDF</a>`;
            } else if (task.replacement_document_id) {
                docLinkHtml = `<a class="dispatch-pdf-link" href="../../../backend/api/documents/kp-form.php?document_id=${Number(task.replacement_document_id)}&amp;pdf=1" target="_blank" rel="noopener">View Notice</a>`;
            }

            card.className = 'dispatch-task-card';
            card.innerHTML = `
                <div class="dispatch-card-row">
                    <div class="dispatch-card-info">
                        <div class="dispatch-card-badges">
                            <span class="dispatch-notice-pill">${escapeHtml(noticeBadge)}</span>
                            <span class="dispatch-ref-code">${escapeHtml(task.case_number || '')}</span>
                            <span class="dispatch-status-dot ${statusClass}">&bull; ${escapeHtml(statusLabel)}</span>
                            ${isUrgent ? '<span class="dispatch-urgent-badge">Urgent</span>' : ''}
                            ${hasDraft ? '<span class="dispatch-draft-badge">Draft</span>' : ''}
                        </div>
                        <div class="dispatch-party-line">
                            <strong>${escapeHtml(task.full_name || 'Recipient')}</strong>
                            <span class="dispatch-party-role">(${escapeHtml(task.party_type || 'Respondent')})</span>
                        </div>
                        <div class="dispatch-meta-line">
                            <span>📍 ${escapeHtml(addrText)}</span>
                            <span>&middot;</span>
                            <span>🕒 ${escapeHtml(schedText)}</span>
                            ${docLinkHtml ? `<span>&middot;</span>${docLinkHtml}` : ''}
                        </div>
                    </div>
                    <div class="dispatch-card-actions">
                        <button type="button" class="dispatch-card-cta ${isServed ? 'is-secondary' : ''}" data-log-service="1">
                            Log Service &rarr;
                        </button>
                    </div>
                </div>
            `;

            card.querySelector('[data-log-service="1"]')?.addEventListener('click', () => {
                openServiceLoggingView(task);
            });

            container.appendChild(card);
        });
    };

    const updatePhotoPreview = (file) => {
        const wrap = document.getElementById('logPhotoPreviewWrap');
        if (!wrap) return;
        if (!file) {
            wrap.style.display = 'none';
            wrap.replaceChildren();
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            wrap.style.display = 'flex';
            wrap.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px;">
                    <img src="${e.target.result}" alt="Proof preview">
                    <div>
                        <div style="font-size:0.82rem; font-weight:700; color:#0f172a;">${escapeHtml(file.name)}</div>
                        <div style="font-size:0.74rem; color:#16a34a; font-weight:600;">Photo proof attached</div>
                    </div>
                </div>
                <button type="button" class="logging-btn-secondary" id="btnRemovePhotoProof" style="min-height:32px; padding:4px 10px; font-size:0.78rem;">Remove</button>
            `;
            wrap.querySelector('#btnRemovePhotoProof')?.addEventListener('click', () => {
                const input = document.getElementById('logProofFile');
                if (input) input.value = '';
                updatePhotoPreview(null);
            });
        };
        reader.readAsDataURL(file);
    };

    const openServiceLoggingView = (task) => {
        state.activeTask = task;

        const listView = document.getElementById('dispatchListView');
        const logView = document.getElementById('serviceLoggingView');
        if (!listView || !logView) return;

        const badgeUpper = formatNoticeBadge(task).toUpperCase();
        const attemptNum = Math.max(1, Number(task.attempt_number || 1));
        const addrText = task.house_street || task.address || task.purok || 'Barangay Tumana';

        document.getElementById('logMetaOverline').textContent = `${task.case_number || 'CASE'} · ${badgeUpper} · ATTEMPT ${attemptNum}`;
        document.getElementById('logPartyHeading').textContent = `${task.full_name || 'Recipient'} (${task.party_type || 'Respondent'})`;
        document.getElementById('logAddressLine').textContent = addrText;
        document.getElementById('logScheduleText').textContent = formatScheduleBadge(task.hearing_date);

        document.getElementById('logDeliveryId').value = task.task_kind === 'paanyaya' ? '0' : String(task.delivery_id || 0);
        document.getElementById('logPaanyayaAttemptId').value = task.task_kind === 'paanyaya' ? String(task.paanyaya_attempt_id || 0) : '0';

        const draft = loadDraft(task);
        const returnSelect = document.getElementById('logOfficerReturn');
        const recipientInput = document.getElementById('logRecipientName');
        const remarksInput = document.getElementById('logRemarks');
        const fileInput = document.getElementById('logProofFile');

        if (returnSelect) {
            returnSelect.value = draft?.return_outcome || 'served_personal';
        }
        if (recipientInput) {
            recipientInput.value = draft?.recipient_name !== undefined ? draft.recipient_name : (task.full_name || '');
        }
        if (remarksInput) {
            remarksInput.value = draft?.remarks !== undefined ? draft.remarks : (task.remarks || '');
        }
        if (fileInput) {
            fileInput.value = '';
        }
        updatePhotoPreview(null);

        listView.style.display = 'none';
        logView.style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const closeServiceLoggingView = () => {
        const listView = document.getElementById('dispatchListView');
        const logView = document.getElementById('serviceLoggingView');
        if (logView) logView.style.display = 'none';
        if (listView) listView.style.display = 'block';
        state.activeTask = null;
        renderTasks();
    };

    const handleSaveDraft = () => {
        if (!state.activeTask) return;
        const returnOutcome = document.getElementById('logOfficerReturn')?.value || 'served_personal';
        const recipientName = document.getElementById('logRecipientName')?.value || '';
        const remarks = document.getElementById('logRemarks')?.value || '';

        saveDraft(state.activeTask, {
            return_outcome: returnOutcome,
            recipient_name: recipientName,
            remarks,
            saved_at: new Date().toISOString()
        });
        showToast('Delivery return draft saved.');
    };

    const handleSubmitDelivery = async (event) => {
        event.preventDefault();
        if (!state.activeTask) return;

        const form = document.getElementById('serviceLoggingForm');
        const submitBtn = document.getElementById('btnLogSubmitDelivery');
        if (!form || !submitBtn) return;

        const formData = new FormData(form);
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

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
                throw new Error(data.message || 'Failed to submit delivery status.');
            }

            clearDraft(state.activeTask);
            showToast(data.message || 'Delivery status recorded successfully.');
            closeServiceLoggingView();
            await fetchServerData();
        } catch (err) {
            showToast(err.message || 'Failed to submit delivery status.', true);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '&#10003; Submit Delivery Status';
        }
    };

    const fetchServerData = async () => {
        state.isLoading = true;
        try {
            const url = `../../../backend/api/dashboard/server-dispatch.php?purok=${encodeURIComponent(state.purok)}&status=${encodeURIComponent(state.status)}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const json = await res.json();

            if (!res.ok || !json.success || !json.data) {
                throw new Error(json.message || 'Failed to fetch dispatch tasks.');
            }

            const data = json.data;
            state.tasks = data.tasks || [];

            renderKpisAndTabs(data.kpis, data.counts);
            renderPurokDropdown(data.puroks);
            renderTasks();
        } catch (err) {
            console.error('Summons & Field Dispatch Error:', err);
            showToast('Unable to load field tasks: ' + err.message, true);
        } finally {
            state.isLoading = false;
        }
    };

    const initListeners = () => {
        // Status Filter Tabs
        document.querySelectorAll('.btn-status-filter').forEach((btn) => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.btn-status-filter').forEach((b) => {
                    b.classList.remove('active');
                    b.setAttribute('aria-selected', 'false');
                });
                btn.classList.add('active');
                btn.setAttribute('aria-selected', 'true');
                state.status = btn.dataset.status || 'pending';
                fetchServerData();
            });
        });

        // Purok Route Filter
        document.getElementById('purokRouteSelect')?.addEventListener('change', (e) => {
            state.purok = e.target.value;
            fetchServerData();
        });

        // Search Bar
        document.getElementById('dispatchSearchInput')?.addEventListener('input', (e) => {
            state.search = e.target.value || '';
            renderTasks();
        });

        // Mobile Preview Toggle
        const btnMobile = document.getElementById('btnMobilePreview');
        const rootEl = document.getElementById('serverDispatchRoot');
        btnMobile?.addEventListener('click', () => {
            state.mobilePreview = !state.mobilePreview;
            rootEl?.classList.toggle('is-mobile-preview', state.mobilePreview);
            btnMobile.classList.toggle('is-active', state.mobilePreview);
            btnMobile.setAttribute('aria-pressed', state.mobilePreview ? 'true' : 'false');
        });

        // Reset Filters & View
        document.getElementById('btnDispatchReset')?.addEventListener('click', () => {
            state.purok = 'all';
            state.status = 'pending';
            state.search = '';

            const purokSelect = document.getElementById('purokRouteSelect');
            if (purokSelect) purokSelect.value = 'all';

            const searchInput = document.getElementById('dispatchSearchInput');
            if (searchInput) searchInput.value = '';

            document.querySelectorAll('.btn-status-filter').forEach((b) => {
                const isPending = b.dataset.status === 'pending';
                b.classList.toggle('active', isPending);
                b.setAttribute('aria-selected', isPending ? 'true' : 'false');
            });

            if (state.activeTask) {
                closeServiceLoggingView();
            }
            fetchServerData();
        });

        // Logging View Controls
        document.getElementById('btnBackToDispatchList')?.addEventListener('click', closeServiceLoggingView);
        document.getElementById('btnLogCancel')?.addEventListener('click', closeServiceLoggingView);
        document.getElementById('btnLogSaveDraft')?.addEventListener('click', handleSaveDraft);
        document.getElementById('serviceLoggingForm')?.addEventListener('submit', handleSubmitDelivery);

        const photoBtn = document.getElementById('btnAttachPhotoProof');
        const photoInput = document.getElementById('logProofFile');
        photoBtn?.addEventListener('click', () => photoInput?.click());
        photoInput?.addEventListener('change', () => {
            updatePhotoPreview(photoInput.files?.[0] || null);
        });
    };

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
