/**
 * AGAP Shared Docket Drag-and-Drop & Dynamic Rescheduling Controller
 *
 * Enforces strict separation between:
 *  1. Same-day queue reordering (`action: 'reorder_queue'`):
 *     - Changes call order (`queue_order`) ONLY.
 *     - Preserves official appointment times (`hearing_date`, `end_time`, `scheduled_time`, `appointment_at`).
 *     - Makes no network request when a drag is cancelled or leaves order unchanged.
 *     - Restores original card order and badges if saving fails.
 *  2. Formal appointment rescheduling (`action: 'reschedule_item'`):
 *     - Triggered by dropping a card onto another calendar date or clicking "Reschedule".
 *     - Fetches server-validated slots (`action=check_slots`) derived from database settings.
 *     - Displays old vs. proposed Date, Time, Call Order, Venue, and Presiding Officer.
 *     - Explains why any invalid date or slot cannot be used.
 *     - Requires explicit confirmation and a mandatory reason before saving.
 */
(function (window, document) {
    'use strict';

    const DEFAULT_API_URL = '../../../backend/api/hearings/queue.php';

    const escapeHtml = (value) => {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const getCsrfToken = () => {
        return window.agapCsrfToken
            || document.querySelector('meta[name="agap-csrf-token"]')?.getAttribute('content')
            || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            || '';
    };

    const formatDateReadable = (dateStr) => {
        if (!dateStr) return '—';
        const clean = String(dateStr).slice(0, 10);
        const d = new Date(clean + 'T00:00:00');
        if (Number.isNaN(d.getTime())) return String(dateStr);
        return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    };

    const formatTimeReadable = (raw) => {
        if (!raw) return '—';
        const str = String(raw).trim();
        if (/^\d{2}:\d{2}(:\d{2})?$/.test(str)) {
            const parts = str.split(':');
            const h = parseInt(parts[0], 10);
            const m = parts[1];
            const ampm = h >= 12 ? 'PM' : 'AM';
            const h12 = h % 12 || 12;
            return `${h12}:${m} ${ampm}`;
        }
        const d = new Date(str.includes(' ') ? str.replace(' ', 'T') : str);
        if (Number.isNaN(d.getTime())) return str;
        return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    };

    // Inject shared styles once
    const ensureStyles = () => {
        if (document.getElementById('agapDocketDragScheduleStyles')) return;
        const style = document.createElement('style');
        style.id = 'agapDocketDragScheduleStyles';
        style.textContent = `
            .docket-dnd-status-bar {
                display: none;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                padding: 8px 12px;
                margin-bottom: 10px;
                border-radius: 8px;
                font-size: 0.8rem;
                font-weight: 600;
            }
            .docket-dnd-status-bar.is-visible { display: flex; }
            .docket-dnd-status-bar.is-saving {
                background: #eff6ff;
                color: #1d4ed8;
                border: 1px solid #bfdbfe;
            }
            .docket-dnd-status-bar.is-success {
                background: #f0fdf4;
                color: #15803d;
                border: 1px solid #bbf7d0;
            }
            .docket-dnd-status-bar.is-error {
                background: #fef2f2;
                color: #b91c1c;
                border: 1px solid #fecaca;
            }
            .docket-dnd-disabled-banner {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 12px;
                margin-bottom: 10px;
                border-radius: 8px;
                background: #fffbeb;
                color: #92400e;
                border: 1px solid #fde68a;
                font-size: 0.78rem;
                font-weight: 600;
            }
            .docket-card-locked {
                opacity: 0.92;
                background: #f8fafc !important;
            }
            .docket-card-locked .drag-handle {
                cursor: not-allowed !important;
                opacity: 0.4;
            }
            .docket-lock-pill {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-size: 0.7rem;
                font-weight: 700;
                padding: 2px 8px;
                border-radius: 9999px;
                background: #fef2f2;
                color: #991b1b;
                border: 1px solid #fecaca;
                white-space: nowrap;
            }
            .docket-card-controls {
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }
            .btn-docket-move, .btn-docket-reschedule {
                border: 1px solid #cbd5e1;
                background: #ffffff;
                color: #334155;
                border-radius: 6px;
                padding: 3px 7px;
                font-size: 0.74rem;
                font-weight: 700;
                cursor: pointer;
                line-height: 1.2;
                transition: background 0.15s, border-color 0.15s;
            }
            .btn-docket-move:hover:not(:disabled), .btn-docket-reschedule:hover:not(:disabled) {
                background: #f1f5f9;
                border-color: #94a3b8;
            }
            .btn-docket-move:disabled, .btn-docket-reschedule:disabled {
                opacity: 0.4;
                cursor: not-allowed;
            }
            .btn-docket-reschedule {
                color: #1e40af;
                border-color: #bfdbfe;
                background: #eff6ff;
            }
            .btn-docket-reschedule:hover:not(:disabled) {
                background: #dbeafe;
                border-color: #93c5fd;
            }
            .cal-drop-ready {
                outline: 1.5px dashed #3b82f6 !important;
                outline-offset: -2px;
                transition: transform 0.12s, background-color 0.12s;
            }
            .cal-drop-over {
                background: #dbeafe !important;
                color: #1e3a8a !important;
                outline: 2px solid #1d4ed8 !important;
                transform: scale(1.06);
                z-index: 2;
            }
            .agap-reschedule-modal-overlay {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.55);
                z-index: 10000;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 16px;
            }
            .agap-reschedule-modal-overlay.is-open {
                display: flex;
            }
            .agap-reschedule-modal-card {
                background: #ffffff;
                border-radius: 12px;
                max-width: 680px;
                width: 100%;
                max-height: 90vh;
                display: flex;
                flex-direction: column;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
                border: 1px solid #e2e8f0;
                overflow: hidden;
            }
            .agap-reschedule-header {
                padding: 16px 20px;
                background: #f8fafc;
                border-bottom: 1px solid #e2e8f0;
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 12px;
            }
            .agap-reschedule-body {
                padding: 18px 20px;
                overflow-y: auto;
                display: flex;
                flex-direction: column;
                gap: 14px;
            }
            .agap-reschedule-footer {
                padding: 14px 20px;
                background: #f8fafc;
                border-top: 1px solid #e2e8f0;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }
            .agap-schedule-compare-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            @media (max-width: 600px) {
                .agap-schedule-compare-grid { grid-template-columns: 1fr; }
            }
            .agap-schedule-box {
                padding: 12px 14px;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
                font-size: 0.82rem;
            }
            .agap-schedule-box.is-old {
                background: #f8fafc;
                color: #475569;
            }
            .agap-schedule-box.is-new {
                background: #f0fdf4;
                border-color: #bbf7d0;
                color: #0f172a;
            }
            .agap-slot-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(125px, 1fr));
                gap: 8px;
                max-height: 190px;
                overflow-y: auto;
                padding: 4px 2px;
            }
            .agap-slot-btn {
                padding: 8px 10px;
                border-radius: 8px;
                border: 1px solid #cbd5e1;
                background: #ffffff;
                font-size: 0.8rem;
                font-weight: 700;
                color: #0f172a;
                cursor: pointer;
                text-align: left;
                display: flex;
                flex-direction: column;
                gap: 2px;
            }
            .agap-slot-btn.is-selected {
                background: #1e40af;
                color: #ffffff;
                border-color: #1e3a8a;
            }
            .agap-slot-btn.is-unavailable {
                background: #f8fafc;
                color: #94a3b8;
                border-color: #e2e8f0;
                cursor: not-allowed;
            }
        `;
        document.head.appendChild(style);
    };

    // Active drag state shared between docket list and calendar drop targets
    let activeDragContext = null;

    const getCardItemMetadata = (card) => {
        if (!card) return null;
        return {
            type: card.dataset.type || 'hearing',
            id: parseInt(card.dataset.id || '0', 10),
            session_id: card.dataset.sessionId ? parseInt(card.dataset.sessionId, 10) : 0,
            complaint_id: card.dataset.complaintId ? parseInt(card.dataset.complaintId, 10) : 0,
            case_id: card.dataset.caseId ? parseInt(card.dataset.caseId, 10) : 0,
            queue_date: card.dataset.queueDate || '',
            official_time: card.dataset.officialTime || '',
            venue: card.dataset.venue || '',
            presiding_officer_id: card.dataset.presidingOfficerId ? parseInt(card.dataset.presidingOfficerId, 10) : null,
            presiding_officer_name: card.dataset.presidingOfficerName || '',
            updated_at: card.dataset.updatedAt || '',
            title: card.dataset.title || '',
            order: parseInt(card.dataset.order || '1', 10),
            is_locked: card.dataset.locked === '1',
            lock_reason: card.dataset.lockReason || ''
        };
    };

    const captureContainerSnapshot = (container, itemSelector) => {
        const cards = Array.from(container.querySelectorAll(itemSelector));
        return cards.map((card, index) => {
            const seqBadge = card.querySelector('.queue-seq-badge');
            const timePill = card.querySelector('.agenda-time-pill');
            return {
                card,
                key: `${card.dataset.type || 'hearing'}:${card.dataset.id || ''}:${card.dataset.sessionId || '0'}`,
                index,
                seqText: seqBadge ? seqBadge.textContent : `#${index + 1}`,
                timeText: timePill ? timePill.textContent : ''
            };
        });
    };

    const restoreContainerSnapshot = (container, snapshot) => {
        if (!container || !Array.isArray(snapshot) || !snapshot.length) return;
        snapshot.forEach((entry, idx) => {
            container.appendChild(entry.card);
            entry.card.dataset.index = String(idx);
            const seqBadge = entry.card.querySelector('.queue-seq-badge');
            if (seqBadge) seqBadge.textContent = entry.seqText;
            const timePill = entry.card.querySelector('.agenda-time-pill');
            if (timePill && entry.timeText) timePill.textContent = entry.timeText;
        });
    };

    const refreshSequenceBadgesOnly = (container, itemSelector) => {
        const cards = Array.from(container.querySelectorAll(itemSelector));
        cards.forEach((card, idx) => {
            card.dataset.index = String(idx);
            card.dataset.order = String(idx + 1);
            const seqBadge = card.querySelector('.queue-seq-badge');
            if (seqBadge) {
                seqBadge.textContent = `#${idx + 1}`;
            }
            const upBtn = card.querySelector('.btn-docket-move-up');
            const downBtn = card.querySelector('.btn-docket-move-down');
            const isLocked = card.dataset.locked === '1';
            if (upBtn) upBtn.disabled = isLocked || idx === 0;
            if (downBtn) downBtn.disabled = isLocked || idx === cards.length - 1;
        });
    };

    const getOrCreateStatusBar = (container) => {
        let bar = container.parentElement?.querySelector('.docket-dnd-status-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'docket-dnd-status-bar';
            bar.setAttribute('role', 'status');
            bar.setAttribute('aria-live', 'polite');
            container.parentElement?.insertBefore(bar, container);
        }
        return bar;
    };

    const showStatusBar = (container, stateClass, message, autoHideMs = 0) => {
        const bar = getOrCreateStatusBar(container);
        if (!bar) return;
        bar.className = `docket-dnd-status-bar is-visible ${stateClass}`;
        bar.textContent = message;
        if (bar._hideTimer) clearTimeout(bar._hideTimer);
        if (autoHideMs > 0) {
            bar._hideTimer = setTimeout(() => {
                bar.classList.remove('is-visible');
            }, autoHideMs);
        }
    };

    /**
     * Persist same-day queue order WITHOUT changing official appointment times.
     */
    const persistQueueReorder = async (container, options, preDragSnapshot) => {
        const itemSelector = options.itemSelector || '[data-id]';
        const apiUrl = options.apiUrl || DEFAULT_API_URL;
        const allCards = Array.from(container.querySelectorAll(itemSelector));

        const currentOrderKey = allCards
            .map((c) => `${c.dataset.type || 'hearing'}:${c.dataset.id || ''}:${c.dataset.sessionId || '0'}`)
            .join('|');
        const initialOrderKey = (preDragSnapshot || [])
            .map((s) => s.key)
            .join('|');

        // Do not send a request for an unchanged drag
        if (!allCards.length || currentOrderKey === initialOrderKey) {
            if (preDragSnapshot) {
                restoreContainerSnapshot(container, preDragSnapshot);
            }
            return { success: true, no_op: true };
        }

        // Collect unlocked items for queue order persistence
        const unlockedCards = allCards.filter((c) => c.dataset.locked !== '1');
        if (!unlockedCards.length) {
            restoreContainerSnapshot(container, preDragSnapshot);
            return { success: false, message: 'All items in this docket are locked.' };
        }

        // Update sequence badges only (never overwrite official time pills)
        refreshSequenceBadgesOnly(container, itemSelector);

        const queueDate = options.selectedDate || unlockedCards[0]?.dataset.queueDate || '';
        const itemsPayload = unlockedCards.map((card, idx) => ({
            type: card.dataset.type || 'hearing',
            id: parseInt(card.dataset.id || '0', 10),
            session_id: card.dataset.sessionId ? parseInt(card.dataset.sessionId, 10) : null,
            complaint_id: card.dataset.complaintId ? parseInt(card.dataset.complaintId, 10) : null,
            order: idx + 1,
            expected_updated_at: card.dataset.updatedAt || null
        }));

        showStatusBar(container, 'is-saving', `Saving call order for ${formatDateReadable(queueDate)}...`);
        allCards.forEach((c) => { c.draggable = false; });

        try {
            const res = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8',
                    'Accept': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    action: 'reorder_queue',
                    queue_date: queueDate,
                    view_mode: options.viewMode || 'day',
                    is_filtered: Boolean(options.isFiltered),
                    is_paginated: Boolean(options.isPaginated),
                    full_day_loaded: options.fullDayLoaded !== false,
                    items: itemsPayload
                })
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                throw new Error(data.message || `Unable to save call order (HTTP ${res.status}).`);
            }

            // Update card order attributes only — official appointment times remain untouched
            if (Array.isArray(data.updated_items)) {
                data.updated_items.forEach((up) => {
                    const match = unlockedCards.find(
                        (c) => (c.dataset.type || 'hearing') === up.type && Number(c.dataset.id) === Number(up.id)
                    );
                    if (match) {
                        match.dataset.order = String(up.order);
                        const seq = match.querySelector('.queue-seq-badge');
                        if (seq) seq.textContent = `#${up.order}`;
                    }
                });
            }

            const statusMsg = data.no_op
                ? 'Call order is already up to date.'
                : `Call order saved (#1 – #${unlockedCards.length}). Official appointment times preserved.`;
            showStatusBar(container, 'is-success', statusMsg, 4000);
            if (typeof options.onToast === 'function') {
                options.onToast(statusMsg, false);
            }
            window.dispatchEvent(new CustomEvent('agap:queue-reordered', { detail: data }));
            window.dispatchEvent(new CustomEvent('agap:hearing-schedule-updated', { detail: data }));
            if (typeof options.onRefresh === 'function') {
                options.onRefresh(data);
            }
            return data;
        } catch (err) {
            restoreContainerSnapshot(container, preDragSnapshot);
            refreshSequenceBadgesOnly(container, itemSelector);
            const errMsg = err.message || 'Failed to save call order. Original order restored.';
            showStatusBar(container, 'is-error', errMsg, 6000);
            if (typeof options.onToast === 'function') {
                options.onToast(errMsg, true);
            }
            return { success: false, message: errMsg };
        } finally {
            allCards.forEach((c) => {
                c.draggable = Boolean(options.canReorder) && c.dataset.locked !== '1';
            });
        }
    };

    // =========================================================================
    // Shared Reschedule Confirmation Modal
    // =========================================================================
    let modalState = {
        item: null,
        targetDate: '',
        selectedTime: '',
        selectedVenue: '',
        selectedOfficerId: null,
        slotsData: null,
        apiUrl: DEFAULT_API_URL,
        onRefresh: null,
        onToast: null
    };

    const getOrCreateRescheduleModal = () => {
        let modal = document.getElementById('agapDocketRescheduleModal');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'agapDocketRescheduleModal';
        modal.className = 'agap-reschedule-modal-overlay';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'agapRescheduleModalTitle');

        modal.innerHTML = `
            <div class="agap-reschedule-modal-card">
                <header class="agap-reschedule-header">
                    <div>
                        <span id="agapRescheduleTypeBadge" style="display:inline-block; font-size:0.72rem; font-weight:700; padding:2px 8px; border-radius:9999px; background:#dbeafe; color:#1e40af; margin-bottom:4px;">Formal Reschedule</span>
                        <h2 id="agapRescheduleModalTitle" style="margin:0; font-size:1.08rem; color:#0f172a;">Confirm Appointment Reschedule</h2>
                        <p id="agapRescheduleModalSub" style="margin:2px 0 0; font-size:0.8rem; color:#64748b;">Moving an item to another date or time updates official schedule records, notices, and party notifications.</p>
                    </div>
                    <button type="button" id="agapRescheduleCloseBtn" aria-label="Close modal" style="border:none; background:transparent; font-size:1.5rem; line-height:1; cursor:pointer; color:#64748b;">&times;</button>
                </header>

                <div class="agap-reschedule-body">
                    <div id="agapRescheduleAlertBox" style="display:none; padding:10px 12px; border-radius:8px; font-size:0.82rem; font-weight:600;"></div>

                    <div class="agap-schedule-compare-grid">
                        <div class="agap-schedule-box is-old">
                            <div style="font-size:0.72rem; font-weight:800; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; margin-bottom:6px;">Current Schedule</div>
                            <div id="agapOldScheduleDetails" style="line-height:1.55;"></div>
                        </div>
                        <div class="agap-schedule-box is-new">
                            <div style="font-size:0.72rem; font-weight:800; text-transform:uppercase; letter-spacing:0.04em; color:#15803d; margin-bottom:6px;">Proposed Schedule</div>
                            <div id="agapNewScheduleDetails" style="line-height:1.55;"></div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                        <div>
                            <label for="agapRescheduleDateInput" style="display:block; font-size:0.78rem; font-weight:700; color:#0f172a; margin-bottom:4px;">Target Date</label>
                            <input type="date" id="agapRescheduleDateInput" style="width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.84rem; box-sizing:border-box;">
                        </div>
                        <div>
                            <label for="agapRescheduleVenueSelect" style="display:block; font-size:0.78rem; font-weight:700; color:#0f172a; margin-bottom:4px;">Venue</label>
                            <select id="agapRescheduleVenueSelect" style="width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.84rem; box-sizing:border-box;"></select>
                        </div>
                        <div>
                            <label for="agapRescheduleOfficerSelect" style="display:block; font-size:0.78rem; font-weight:700; color:#0f172a; margin-bottom:4px;">Presiding Officer</label>
                            <select id="agapRescheduleOfficerSelect" style="width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.84rem; box-sizing:border-box;"></select>
                        </div>
                    </div>

                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label style="font-size:0.78rem; font-weight:700; color:#0f172a;">Select Official Time Slot</label>
                            <span id="agapRescheduleSlotMeta" style="font-size:0.74rem; color:#64748b;"></span>
                        </div>
                        <div id="agapRescheduleSlotsContainer" class="agap-slot-grid"></div>
                    </div>

                    <div>
                        <label for="agapRescheduleReasonInput" style="display:block; font-size:0.78rem; font-weight:700; color:#0f172a; margin-bottom:4px;">Reason for Rescheduling <span style="color:#dc2626;">*</span></label>
                        <textarea id="agapRescheduleReasonInput" rows="2" placeholder="Provide the official reason for rescheduling this appointment (required)..." style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.84rem; font-family:inherit; box-sizing:border-box; resize:vertical;"></textarea>
                    </div>

                    <label style="display:flex; align-items:flex-start; gap:8px; font-size:0.8rem; color:#334155; cursor:pointer; background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
                        <input type="checkbox" id="agapRescheduleConfirmCheck" style="margin-top:2px; width:15px; height:15px;">
                        <span>I confirm this formal schedule change and understand that official schedule records, affected notices/summons, audit history, and party notifications will be updated.</span>
                    </label>
                </div>

                <footer class="agap-reschedule-footer">
                    <button type="button" id="agapRescheduleCancelBtn" style="padding:8px 16px; border:1px solid #cbd5e1; background:#ffffff; color:#475569; border-radius:6px; font-size:0.84rem; font-weight:600; cursor:pointer;">Cancel</button>
                    <button type="button" id="agapRescheduleSubmitBtn" style="padding:8px 18px; border:none; background:#1e40af; color:#ffffff; border-radius:6px; font-size:0.84rem; font-weight:700; cursor:pointer;">Confirm &amp; Reschedule</button>
                </footer>
            </div>
        `;

        document.body.appendChild(modal);

        const closeModal = () => {
            modal.classList.remove('is-open');
        };

        modal.querySelector('#agapRescheduleCloseBtn')?.addEventListener('click', closeModal);
        modal.querySelector('#agapRescheduleCancelBtn')?.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        modal.querySelector('#agapRescheduleDateInput')?.addEventListener('change', (e) => {
            modalState.targetDate = e.target.value;
            modalState.selectedTime = '';
            loadRescheduleSlots();
        });
        modal.querySelector('#agapRescheduleVenueSelect')?.addEventListener('change', (e) => {
            modalState.selectedVenue = e.target.value;
            loadRescheduleSlots();
        });
        modal.querySelector('#agapRescheduleOfficerSelect')?.addEventListener('change', (e) => {
            modalState.selectedOfficerId = e.target.value ? parseInt(e.target.value, 10) : null;
            loadRescheduleSlots();
        });
        modal.querySelector('#agapRescheduleSubmitBtn')?.addEventListener('click', submitRescheduleModal);

        return modal;
    };

    const updateComparisonBoxes = () => {
        const oldBox = document.getElementById('agapOldScheduleDetails');
        const newBox = document.getElementById('agapNewScheduleDetails');
        const cur = modalState.slotsData?.current_item || modalState.item || {};
        const prop = modalState.slotsData?.proposed_summary || {};

        if (oldBox) {
            const oldDate = cur.old_date || cur.queue_date || '';
            const oldTime = cur.old_time_formatted || formatTimeReadable(cur.old_time || cur.official_time);
            const oldOrder = cur.old_order || cur.order || 1;
            const oldVenue = cur.old_venue || cur.venue || 'Default Venue';
            const oldOfficer = cur.old_presiding_officer_name || cur.presiding_officer_name || 'Default Officer';
            oldBox.innerHTML = `
                <div><strong>Date:</strong> ${escapeHtml(formatDateReadable(oldDate))}</div>
                <div><strong>Time:</strong> ${escapeHtml(oldTime)}</div>
                <div><strong>Call Order:</strong> #${escapeHtml(String(oldOrder))}</div>
                <div><strong>Venue:</strong> ${escapeHtml(oldVenue)}</div>
                <div><strong>Presiding Officer:</strong> ${escapeHtml(oldOfficer)}</div>
            `;
        }

        if (newBox) {
            const newDate = modalState.targetDate || prop.target_date || '';
            const newTime = modalState.selectedTime ? formatTimeReadable(modalState.selectedTime) : 'Select a slot below';
            const newOrder = prop.proposed_order || 1;
            const newVenue = modalState.selectedVenue || prop.venue || 'Default Venue';
            const officerSelect = document.getElementById('agapRescheduleOfficerSelect');
            const selectedOfficerText = officerSelect && officerSelect.selectedIndex >= 0
                ? officerSelect.options[officerSelect.selectedIndex].text
                : (prop.presiding_officer_name || 'Default Officer');

            newBox.innerHTML = `
                <div><strong>Date:</strong> ${escapeHtml(formatDateReadable(newDate))}</div>
                <div><strong>Time:</strong> <span style="color:#15803d; font-weight:700;">${escapeHtml(newTime)}</span></div>
                <div><strong>Call Order:</strong> #${escapeHtml(String(newOrder))}</div>
                <div><strong>Venue:</strong> ${escapeHtml(newVenue)}</div>
                <div><strong>Presiding Officer:</strong> ${escapeHtml(selectedOfficerText)}</div>
            `;
        }
    };

    const setModalAlert = (message, isError = true) => {
        const alertBox = document.getElementById('agapRescheduleAlertBox');
        if (!alertBox) return;
        if (!message) {
            alertBox.style.display = 'none';
            alertBox.textContent = '';
            return;
        }
        alertBox.style.display = 'block';
        alertBox.style.background = isError ? '#fef2f2' : '#f0fdf4';
        alertBox.style.color = isError ? '#b91c1c' : '#15803d';
        alertBox.style.border = `1px solid ${isError ? '#fecaca' : '#bbf7d0'}`;
        alertBox.textContent = message;
    };

    const loadRescheduleSlots = async () => {
        const slotsContainer = document.getElementById('agapRescheduleSlotsContainer');
        const slotMeta = document.getElementById('agapRescheduleSlotMeta');
        const submitBtn = document.getElementById('agapRescheduleSubmitBtn');
        if (!slotsContainer || !modalState.item) return;

        slotsContainer.innerHTML = '<div style="grid-column: 1 / -1; color:#64748b; font-size:0.82rem; padding:10px;">Checking configured schedule rules and slot availability...</div>';
        setModalAlert('');

        const query = new URLSearchParams({
            action: 'check_slots',
            item_type: modalState.item.type || 'hearing',
            item_id: String(modalState.item.id || 0),
            session_id: String(modalState.item.session_id || 0),
            target_date: modalState.targetDate || ''
        });
        if (modalState.selectedVenue) query.set('venue', modalState.selectedVenue);
        if (modalState.selectedOfficerId) query.set('presiding_officer_id', String(modalState.selectedOfficerId));

        try {
            const res = await fetch(`${modalState.apiUrl}?${query.toString()}`, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            const data = await res.json().catch(() => ({}));
            modalState.slotsData = data;

            // Populate venues dropdown
            const venueSelect = document.getElementById('agapRescheduleVenueSelect');
            if (venueSelect && Array.isArray(data.venues)) {
                const targetVenue = modalState.selectedVenue || data.proposed_summary?.venue || modalState.item.venue || data.venues[0] || '';
                modalState.selectedVenue = targetVenue;
                venueSelect.innerHTML = data.venues
                    .map((v) => `<option value="${escapeHtml(v)}"${v === targetVenue ? ' selected' : ''}>${escapeHtml(v)}</option>`)
                    .join('');
            }

            // Populate presiding officers dropdown
            const officerSelect = document.getElementById('agapRescheduleOfficerSelect');
            if (officerSelect && Array.isArray(data.presiding_officers)) {
                const targetOfficer = modalState.selectedOfficerId ?? data.proposed_summary?.presiding_officer_id ?? modalState.item.presiding_officer_id ?? '';
                officerSelect.innerHTML = `<option value="">Default Presiding Officer</option>` + data.presiding_officers
                    .map((o) => `<option value="${Number(o.user_id)}"${Number(o.user_id) === Number(targetOfficer) ? ' selected' : ''}>${escapeHtml(o.full_name)} (${escapeHtml(o.role_name)})</option>`)
                    .join('');
            }

            if (slotMeta && data.constraints) {
                slotMeta.textContent = `${data.constraints.duration_minutes || 45} min duration · Lunch ${formatTimeReadable(data.constraints.lunch_start)}–${formatTimeReadable(data.constraints.lunch_end)}`;
            }

            if (data.valid_date === false) {
                setModalAlert(data.date_error || data.message || 'Selected date is not available for scheduling.', true);
            }

            const slots = Array.isArray(data.slots) ? data.slots : [];
            if (!slots.length) {
                slotsContainer.innerHTML = `<div style="grid-column: 1 / -1; color:#b91c1c; font-size:0.82rem; padding:10px; background:#fef2f2; border-radius:8px; border:1px solid #fecaca;">${escapeHtml(data.date_error || data.message || 'No valid time slots available on this date.')}</div>`;
                if (submitBtn) submitBtn.disabled = true;
                updateComparisonBoxes();
                return;
            }

            // Auto-select first available slot if none chosen yet
            if (!modalState.selectedTime || !slots.some((s) => s.time === modalState.selectedTime && s.available)) {
                const firstAvail = slots.find((s) => s.available);
                modalState.selectedTime = firstAvail ? firstAvail.time : '';
            }

            slotsContainer.replaceChildren();
            slots.forEach((slot) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                const isSelected = slot.available && slot.time === modalState.selectedTime;
                btn.className = `agap-slot-btn ${isSelected ? 'is-selected' : ''} ${!slot.available ? 'is-unavailable' : ''}`;
                btn.dataset.slotTime = slot.time;
                btn.title = slot.available ? `Available: ${slot.formatted} – ${slot.end_formatted}` : (slot.reason || 'Unavailable');
                btn.innerHTML = `
                    <span>${escapeHtml(slot.formatted)}</span>
                    <small style="font-size:0.68rem; font-weight:600; opacity:0.85;">
                        ${slot.available ? `Ends ${escapeHtml(slot.end_formatted)}` : escapeHtml(slot.reason || 'Unavailable')}
                    </small>
                `;
                btn.addEventListener('click', () => {
                    if (!slot.available) {
                        setModalAlert(`Slot ${slot.formatted} cannot be used: ${slot.reason || 'Conflict detected.'}`, true);
                        return;
                    }
                    setModalAlert('');
                    modalState.selectedTime = slot.time;
                    slotsContainer.querySelectorAll('.agap-slot-btn').forEach((b) => {
                        b.classList.toggle('is-selected', b.dataset.slotTime === slot.time);
                    });
                    updateComparisonBoxes();
                });
                slotsContainer.appendChild(btn);
            });

            if (submitBtn) {
                submitBtn.disabled = !modalState.selectedTime || data.valid_date === false;
            }
            updateComparisonBoxes();
        } catch (err) {
            slotsContainer.innerHTML = `<div style="grid-column: 1 / -1; color:#b91c1c; font-size:0.82rem; padding:10px;">Unable to load schedule slots: ${escapeHtml(err.message)}</div>`;
        }
    };

    const openRescheduleModal = (itemMeta, targetDate, options = {}) => {
        ensureStyles();
        if (!itemMeta || itemMeta.is_locked) {
            const msg = itemMeta?.lock_reason || 'This record is locked and cannot be rescheduled.';
            if (typeof options.onToast === 'function') options.onToast(msg, true);
            return;
        }

        const modal = getOrCreateRescheduleModal();
        modalState = {
            item: itemMeta,
            targetDate: targetDate || itemMeta.queue_date || '',
            selectedTime: '',
            selectedVenue: itemMeta.venue || '',
            selectedOfficerId: itemMeta.presiding_officer_id || null,
            slotsData: null,
            apiUrl: options.apiUrl || DEFAULT_API_URL,
            onRefresh: options.onRefresh || null,
            onToast: options.onToast || null
        };

        const badge = document.getElementById('agapRescheduleTypeBadge');
        if (badge) {
            badge.textContent = itemMeta.type === 'paanyaya' ? 'Paanyaya Stage 0 Reschedule' : 'Formal Hearing Reschedule';
        }
        const titleEl = document.getElementById('agapRescheduleModalTitle');
        if (titleEl) {
            titleEl.textContent = itemMeta.title ? `Reschedule: ${itemMeta.title}` : 'Confirm Appointment Reschedule';
        }
        const dateInput = document.getElementById('agapRescheduleDateInput');
        if (dateInput) dateInput.value = modalState.targetDate;
        const reasonInput = document.getElementById('agapRescheduleReasonInput');
        if (reasonInput) reasonInput.value = '';
        const confirmCheck = document.getElementById('agapRescheduleConfirmCheck');
        if (confirmCheck) confirmCheck.checked = false;

        modal.classList.add('is-open');
        updateComparisonBoxes();
        loadRescheduleSlots();
    };

    const submitRescheduleModal = async () => {
        const reasonInput = document.getElementById('agapRescheduleReasonInput');
        const confirmCheck = document.getElementById('agapRescheduleConfirmCheck');
        const submitBtn = document.getElementById('agapRescheduleSubmitBtn');
        const reason = (reasonInput?.value || '').trim();

        if (!modalState.targetDate) {
            setModalAlert('Please select a target date.', true);
            return;
        }
        if (!modalState.selectedTime) {
            setModalAlert('Please select an available time slot.', true);
            return;
        }
        if (reason.length < 3) {
            setModalAlert('Please enter a reason for rescheduling (at least 3 characters).', true);
            reasonInput?.focus();
            return;
        }
        if (!confirmCheck?.checked) {
            setModalAlert('Please check the confirmation box to confirm this formal schedule change.', true);
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Rescheduling...';
        }
        setModalAlert('');

        try {
            const res = await fetch(modalState.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8',
                    'Accept': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    action: 'reschedule_item',
                    item_type: modalState.item.type || 'hearing',
                    item_id: modalState.item.id,
                    session_id: modalState.item.session_id || null,
                    complaint_id: modalState.item.complaint_id || null,
                    target_date: modalState.targetDate,
                    target_time: modalState.selectedTime,
                    venue: modalState.selectedVenue,
                    presiding_officer_id: modalState.selectedOfficerId,
                    reschedule_reason: reason,
                    confirmed: true,
                    expected_updated_at: modalState.slotsData?.current_item?.updated_at || modalState.item.updated_at || null
                })
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                throw new Error(data.message || `Reschedule failed (HTTP ${res.status}).`);
            }

            const modal = document.getElementById('agapDocketRescheduleModal');
            modal?.classList.remove('is-open');

            const successMsg = data.message || 'Appointment rescheduled successfully.';
            if (typeof modalState.onToast === 'function') {
                modalState.onToast(successMsg, false);
            }
            window.dispatchEvent(new CustomEvent('agap:hearing-schedule-updated', { detail: data }));
            if (typeof modalState.onRefresh === 'function') {
                modalState.onRefresh(data);
            }
        } catch (err) {
            setModalAlert(err.message || 'Unable to reschedule appointment.', true);
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Confirm & Reschedule';
            }
        }
    };

    // =========================================================================
    // Attach Shared Docket Drag & Reschedule Behavior to a Container
    // =========================================================================
    const attachDocketContainer = (container, config = {}) => {
        ensureStyles();
        if (!container) return;

        const itemSelector = config.itemSelector || '[data-id]';
        const canReorder = Boolean(config.canReorder);
        const disableReason = config.disableReason || '';

        // Manage disabled banner
        let banner = container.parentElement?.querySelector('.docket-dnd-disabled-banner');
        if (!canReorder && disableReason) {
            if (!banner) {
                banner = document.createElement('div');
                banner.className = 'docket-dnd-disabled-banner';
                container.parentElement?.insertBefore(banner, container);
            }
            banner.style.display = 'flex';
            banner.innerHTML = `<span>&#9888;&#65039;</span><span>${escapeHtml(disableReason)}</span>`;
        } else if (banner) {
            banner.style.display = 'none';
        }

        const cards = Array.from(container.querySelectorAll(itemSelector));
        cards.forEach((card, idx) => {
            const isLocked = card.dataset.locked === '1';
            card.dataset.index = String(idx);
            card.dataset.order = String(idx + 1);
            card.classList.toggle('docket-card-locked', isLocked);

            // A card is draggable if it is unlocked AND either same-day reorder or calendar drop is available
            card.draggable = !isLocked;

            const handle = card.querySelector('.drag-handle');
            if (handle) {
                handle.title = isLocked
                    ? (card.dataset.lockReason || 'Locked session — cannot be reordered or rescheduled')
                    : (canReorder
                        ? 'Drag within list to reorder call sequence, or drag onto a calendar date to reschedule'
                        : 'Drag onto a calendar date to formally reschedule');
            }

            // Inject accessible Move Up / Move Down / Reschedule controls if not already present
            let controls = card.querySelector('.docket-card-controls');
            if (!controls) {
                controls = document.createElement('div');
                controls.className = 'docket-card-controls';

                const upBtn = document.createElement('button');
                upBtn.type = 'button';
                upBtn.className = 'btn-docket-move btn-docket-move-up';
                upBtn.innerHTML = '&uarr;';
                upBtn.title = 'Move up in call queue (Alt+Up)';
                upBtn.setAttribute('aria-label', 'Move up in call queue');
                upBtn.disabled = !canReorder || isLocked || idx === 0;
                upBtn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    if (!canReorder || isLocked) return;
                    const currentCards = Array.from(container.querySelectorAll(itemSelector));
                    const pos = currentCards.indexOf(card);
                    if (pos <= 0) return;
                    const prevCard = currentCards[pos - 1];
                    if (prevCard.dataset.locked === '1') return;
                    const snapshot = captureContainerSnapshot(container, itemSelector);
                    container.insertBefore(card, prevCard);
                    await persistQueueReorder(container, config, snapshot);
                });

                const downBtn = document.createElement('button');
                downBtn.type = 'button';
                downBtn.className = 'btn-docket-move btn-docket-move-down';
                downBtn.innerHTML = '&darr;';
                downBtn.title = 'Move down in call queue (Alt+Down)';
                downBtn.setAttribute('aria-label', 'Move down in call queue');
                downBtn.disabled = !canReorder || isLocked || idx === cards.length - 1;
                downBtn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    if (!canReorder || isLocked) return;
                    const currentCards = Array.from(container.querySelectorAll(itemSelector));
                    const pos = currentCards.indexOf(card);
                    if (pos < 0 || pos >= currentCards.length - 1) return;
                    const nextCard = currentCards[pos + 1];
                    if (nextCard.dataset.locked === '1') return;
                    const snapshot = captureContainerSnapshot(container, itemSelector);
                    container.insertBefore(nextCard, card);
                    await persistQueueReorder(container, config, snapshot);
                });

                const reschedBtn = document.createElement('button');
                reschedBtn.type = 'button';
                reschedBtn.className = 'btn-docket-reschedule';
                reschedBtn.textContent = 'Reschedule';
                reschedBtn.title = isLocked
                    ? (card.dataset.lockReason || 'Locked session')
                    : 'Reschedule appointment date or time';
                reschedBtn.setAttribute('aria-label', 'Reschedule appointment date or time');
                reschedBtn.disabled = isLocked;
                reschedBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const meta = getCardItemMetadata(card);
                    openRescheduleModal(meta, meta.queue_date, config);
                });

                controls.append(upBtn, downBtn, reschedBtn);

                // Append lock badge if locked
                if (isLocked && card.dataset.lockReason && !card.querySelector('.docket-lock-pill')) {
                    const lockPill = document.createElement('span');
                    lockPill.className = 'docket-lock-pill';
                    lockPill.title = card.dataset.lockReason;
                    lockPill.textContent = `Locked: ${card.dataset.lockCode || 'Unavailable'}`;
                    controls.prepend(lockPill);
                }

                const targetHeader = card.querySelector('.agenda-item-header, .hearing-card-actions') || card;
                targetHeader.appendChild(controls);
            }

            // Keyboard accessibility on the card itself
            card.tabIndex = 0;
            card.addEventListener('keydown', (e) => {
                if (e.altKey && e.key === 'ArrowUp') {
                    e.preventDefault();
                    card.querySelector('.btn-docket-move-up')?.click();
                } else if (e.altKey && e.key === 'ArrowDown') {
                    e.preventDefault();
                    card.querySelector('.btn-docket-move-down')?.click();
                } else if (e.key === 'Escape' && activeDragContext) {
                    activeDragContext.cancelled = true;
                    restoreContainerSnapshot(activeDragContext.container, activeDragContext.snapshot);
                }
            });

            // HTML5 Drag Events
            card.addEventListener('dragstart', (e) => {
                if (isLocked) {
                    e.preventDefault();
                    return;
                }
                const snapshot = captureContainerSnapshot(container, itemSelector);
                activeDragContext = {
                    card,
                    container,
                    config,
                    snapshot,
                    itemMeta: getCardItemMetadata(card),
                    cancelled: false,
                    droppedInList: false,
                    droppedOnCalendar: false
                };
                card.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', `${card.dataset.type || 'hearing'}:${card.dataset.id || ''}`);

                document.querySelectorAll('[data-date]').forEach((cell) => {
                    cell.classList.add('cal-drop-ready');
                });
            });

            card.addEventListener('dragover', (e) => {
                if (!activeDragContext || activeDragContext.container !== container) return;
                if (!canReorder || isLocked) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';

                const dragged = activeDragContext.card;
                if (!dragged || dragged === card) return;

                const bounds = card.getBoundingClientRect();
                const insertAfter = (e.clientY - bounds.top) > (bounds.height / 2);
                container.insertBefore(dragged, insertAfter ? card.nextSibling : card);
                card.classList.add('drag-over');
            });

            card.addEventListener('dragleave', () => {
                card.classList.remove('drag-over');
            });

            card.addEventListener('drop', (e) => {
                card.classList.remove('drag-over');
                if (!activeDragContext || activeDragContext.container !== container) return;
                if (!canReorder || isLocked) return;
                e.preventDefault();
                activeDragContext.droppedInList = true;
            });

            card.addEventListener('dragend', async (e) => {
                card.classList.remove('is-dragging');
                container.querySelectorAll(itemSelector).forEach((c) => c.classList.remove('drag-over'));
                document.querySelectorAll('[data-date]').forEach((cell) => {
                    cell.classList.remove('cal-drop-ready', 'cal-drop-over');
                });

                const ctx = activeDragContext;
                activeDragContext = null;
                if (!ctx) return;

                // If dropped on a calendar date cell, restore current list DOM and let the modal handle rescheduling
                if (ctx.droppedOnCalendar) {
                    restoreContainerSnapshot(ctx.container, ctx.snapshot);
                    refreshSequenceBadgesOnly(ctx.container, itemSelector);
                    return;
                }

                // If drag was cancelled (Escape or dropEffect === 'none' without list drop) or reordering is disabled
                const dropCancelled = ctx.cancelled || (!ctx.droppedInList && e.dataTransfer?.dropEffect === 'none');
                if (dropCancelled || !canReorder) {
                    restoreContainerSnapshot(ctx.container, ctx.snapshot);
                    refreshSequenceBadgesOnly(ctx.container, itemSelector);
                    return;
                }

                await persistQueueReorder(ctx.container, ctx.config, ctx.snapshot);
            });
        });

        // Allow dropping anywhere inside the docket container itself
        if (!container._agapContainerDropBound) {
            container._agapContainerDropBound = true;
            container.addEventListener('dragover', (e) => {
                if (!activeDragContext || activeDragContext.container !== container) return;
                if (!activeDragContext.config.canReorder) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });
            container.addEventListener('drop', (e) => {
                if (!activeDragContext || activeDragContext.container !== container) return;
                if (!activeDragContext.config.canReorder) return;
                e.preventDefault();
                activeDragContext.droppedInList = true;
            });
        }

        refreshSequenceBadgesOnly(container, itemSelector);
    };

    // =========================================================================
    // Attach Calendar Day Cell Drop Targets
    // =========================================================================
    const attachCalendarDropTargets = (calendarContainer, options = {}) => {
        ensureStyles();
        if (!calendarContainer) return;

        const cells = calendarContainer.querySelectorAll('[data-date]');
        cells.forEach((cell) => {
            if (cell._agapDropBound) return;
            cell._agapDropBound = true;

            cell.addEventListener('dragover', (e) => {
                if (!activeDragContext || !activeDragContext.itemMeta || activeDragContext.itemMeta.is_locked) return;
                const targetDate = cell.dataset.date;
                if (!targetDate) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                cell.classList.add('cal-drop-over');
            });

            cell.addEventListener('dragleave', () => {
                cell.classList.remove('cal-drop-over');
            });

            cell.addEventListener('drop', (e) => {
                cell.classList.remove('cal-drop-over');
                if (!activeDragContext || !activeDragContext.itemMeta) return;
                e.preventDefault();
                e.stopPropagation();

                const targetDate = cell.dataset.date || '';
                const itemMeta = activeDragContext.itemMeta;
                const ctxConfig = activeDragContext.config || {};
                activeDragContext.droppedOnCalendar = true;

                // Restore card in original list immediately — never save or mutate silently
                restoreContainerSnapshot(activeDragContext.container, activeDragContext.snapshot);

                openRescheduleModal(itemMeta, targetDate, {
                    apiUrl: options.apiUrl || ctxConfig.apiUrl || DEFAULT_API_URL,
                    onRefresh: options.onRefresh || ctxConfig.onRefresh,
                    onToast: options.onToast || ctxConfig.onToast
                });
            });
        });
    };

    window.AgapDocketDragSchedule = {
        attachDocketContainer,
        attachCalendarDropTargets,
        openRescheduleModal,
        persistQueueReorder
    };
})(window, document);
