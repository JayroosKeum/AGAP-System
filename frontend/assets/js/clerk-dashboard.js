/**
 * AGAP - Lupon Clerk Dashboard
 * Focused on front-desk intake, queue management, notice dispatch,
 * 1-click attendance, 1-click outcome logging, macro analytics, and audit feeds.
 */

(() => {
    'use strict';

    const state = {
        period: 'all',
        offset: 0,
        isLoading: false,
        stats: null,
        activeAgendaFilter: 'all',
        calendarDate: new Date(),
        selectedDate: null,
        activeQuickAttHearing: null,
        activeQuickAttHearingId: null,
        activeQuickAttData: null,
        isSavingQueue: false
    };

    const getCsrfToken = () => {
        return window.agapCsrfToken || document.querySelector('meta[name="agap-csrf-token"]')?.content || '';
    };

    const escapeHtml = (str) => {
        if (str === null || str === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(str).replace(/[&<>"']/g, (m) => map[m]);
    };

    const formatDateClean = (value) => {
        if (!value) return '';
        const d = new Date(String(value).includes(' ') ? String(value).replace(' ', 'T') : (String(value).length === 10 ? String(value) + 'T00:00:00' : String(value)));
        return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const getLocalDate = () => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
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

    const isActiveQueueSession = (session) => {
        const status = String(session?.status || '');
        const queueStatus = String(session?.queue_status || session?.session_queue_status || '').replace(/[\s-]+/g, '_');
        return !['Cancelled', 'Office Cancelled', 'Completed', 'Rescheduled', 'Resolved', 'Unresolved', 'Escalated'].includes(status)
            && queueStatus !== 'Concluded';
    };

    const timeAgo = (dateStr) => {
        if (!dateStr) return '';
        const date = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(date.getTime())) return dateStr;
        const now = new Date();
        const diffSec = Math.floor((now - date) / 1000);
        if (diffSec < 60) return 'Just now';
        if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
        if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
        if (diffSec < 604800) return `${Math.floor(diffSec / 86400)}d ago`;
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    };

    const showToast = (message, isError = false) => {
        let toast = document.getElementById('clerkDashboardToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'clerkDashboardToast';
            toast.style.cssText = `
                position: fixed;
                bottom: 24px;
                right: 24px;
                padding: 12px 20px;
                background: #0f172a;
                color: #ffffff;
                font-size: 0.88rem;
                font-weight: 600;
                border-radius: 8px;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
                z-index: 9999;
                display: flex;
                align-items: center;
                gap: 8px;
                transform: translateY(100px);
                opacity: 0;
                transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            `;
            document.body.appendChild(toast);
        }

        toast.style.background = isError ? '#991b1b' : '#0f172a';
        toast.innerHTML = `<span>${escapeHtml(message)}</span>`;
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';

        setTimeout(() => {
            toast.style.transform = 'translateY(100px)';
            toast.style.opacity = '0';
        }, 3500);
    };

    const setText = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = (val !== null && val !== undefined) ? String(val) : '0';
    };

    // Quick Action Requester
    const postQuickAction = async (payload) => {
        const csrfToken = getCsrfToken();
        const res = await fetch('../../../backend/api/dashboard/quick-action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });

        const data = await res.json().catch(() => ({ success: false, message: 'Server communication error.' }));
        if (!res.ok || !data.success) {
            throw new Error(data.message || `Request failed with status ${res.status}`);
        }
        return data;
    };

    // Render Daily Intake & Docket Queue
    const renderIntakeQueue = (data) => {
        setText('clerkWalkinsToday', data.walkins_today ?? 0);
        setText('clerkActivePaanyaya', (data.active_paanyaya != null && data.active_paanyaya > 0) ? data.active_paanyaya : (data.paanyaya_complaints ?? data.active_paanyaya ?? 0));
        setText('clerkPendingForms', data.pending_forms ?? 0);
    };

    // Render System Metrics Grid & Bento Stage Visuals
    const renderSystemMetrics = (data) => {
        const total = Number(data.total_cases ?? 0);
        const med = Number(data.mediation_cases ?? data.active_mediations ?? 0);
        const con = Number(data.pangkat_conciliations ?? 0);
        const settled = Number(data.settled_cases ?? 0);
        const other = Math.max(0, total - (med + con + settled));
        const denom = Math.max(1, total);

        setText('sysMetricTotalCases', total);
        setText('sysMetricMediation', med);
        setText('sysMetricConciliation', con);
        setText('sysMetricSettled', settled);
        setText('sysMetricArchived', data.archived_cases ?? 0);
        setText('sysMetricActiveUsers', data.active_users ?? 0);

        setText('bentoClerkTotal', total.toLocaleString());
        setText('bentoClerkMed', med);
        setText('bentoClerkCon', con);
        setText('bentoClerkSet', settled);
        setText('bentoClerkOther', other);

        const setW = (id, pct) => { const el = document.getElementById(id); if (el) el.style.width = `${pct}%`; };
        setW('bentoClerkSegMed', Math.round((med / denom) * 100));
        setW('bentoClerkSegCon', Math.round((con / denom) * 100));
        setW('bentoClerkSegSet', Math.round((settled / denom) * 100));
        setW('bentoClerkSegCfa', Math.round((other / denom) * 100));

        const rate = total > 0 ? Math.round((settled / total) * 100) : 0;
        setText('bentoClerkGaugePct', `${rate}%`);
        setText('bentoClerkGaugeSub', `${settled} of ${total} cases settled`);
        const arc = document.getElementById('bentoClerkGaugeArc');
        if (arc) {
            arc.setAttribute('stroke-dashoffset', String(Math.round(126 - (126 * Math.min(100, rate) / 100))));
        }
    };

    const sortDocketItems = (items) => {
        return [...(items || [])].sort((a, b) => {
            const dateA = String(a.queue_date || a.scheduled_date || a.time || '').slice(0, 10);
            const dateB = String(b.queue_date || b.scheduled_date || b.time || '').slice(0, 10);
            if (dateA !== dateB) return dateA.localeCompare(dateB);
            const orderA = Number(a.queue_order || 0) > 0 ? Number(a.queue_order) : 9999;
            const orderB = Number(b.queue_order || 0) > 0 ? Number(b.queue_order) : 9999;
            if (orderA !== orderB) return orderA - orderB;
            return String(a.time || '').localeCompare(String(b.time || ''));
        });
    };

    // Global Punong Barangay Unavailable Batch Rescheduler (Afternoon 1-5 PM or Entire Day)
    window.triggerPbUnavailableBatch = () => {
        const hearings = (state.stats?.today_hearings || []).filter(isActiveQueueSession);
        const totalCount = hearings.length;
        const afternoonCount = hearings.filter(h => {
            const m = String(h.hearing_date || '').match(/\s(\d{2}):/);
            return m ? parseInt(m[1], 10) >= 12 : true;
        }).length;

        let modal = document.getElementById('agapClerkPbChoiceModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'agapClerkPbChoiceModal';
            modal.className = 'pb-choice-modal-overlay';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.innerHTML = `
                <div class="pb-choice-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <h3 style="margin:0; font-size:1.05rem; color:#102b2d;">Punong Barangay Unavailable</h3>
                        <button type="button" id="clerkPbCloseBtn" style="border:none;background:transparent;font-size:22px;cursor:pointer;color:#64748b;">&times;</button>
                    </div>
                    <p id="clerkPbSummary" style="margin:0 0 14px; font-size:0.86rem; color:#475569; line-height:1.45;"></p>
                    <button type="button" class="pb-choice-option" id="clerkPbAfternoonBtn">
                        <strong style="display:block; color:#102b2d; font-size:0.9rem;">Reschedule Whole Afternoon Only (1:00–5:00 PM)</strong>
                        <span style="display:block; font-size:0.78rem; color:#64748b; margin-top:2px;">Pinananatili ang morning sessions; ililipat ang pang-hapon sa susunod na Martes + KP Form 8 &amp; 9.</span>
                    </button>
                    <button type="button" class="pb-choice-option" id="clerkPbWholeDayBtn">
                        <strong style="display:block; color:#102b2d; font-size:0.9rem;">Reschedule Entire Day (All Today's Hearings)</strong>
                        <span style="display:block; font-size:0.78rem; color:#64748b; margin-top:2px;">Ililipat ang lahat ng aktibong hearing ngayong araw patungo sa susunod na Martes + KP Form 8 &amp; 9.</span>
                    </button>
                    <div style="text-align:right; margin-top:8px;">
                        <button type="button" class="btn-secondary" id="clerkPbCancelBtn">Cancel</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.querySelector('#clerkPbCloseBtn').onclick = () => { modal.style.display = 'none'; };
            modal.querySelector('#clerkPbCancelBtn').onclick = () => { modal.style.display = 'none'; };
            modal.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
        }

        modal.querySelector('#clerkPbSummary').textContent = `Mayroong ${totalCount} aktibong hearing ngayong araw (${afternoonCount} sa hapon 1:00–5:00 PM). Piliin ang saklaw ng paglipat sa susunod na Martes (mag-iisyu rin ng Batch KP Form 8 at 9):`;

        const runBatch = async (slotScope) => {
            modal.style.display = 'none';
            try {
                showToast('Ipinoproseso ang batch reschedule at KP Form 8/9...', false);
                const res = await postQuickAction({
                    action: 'batch_reschedule_pb_unavailable',
                    target_date: state.selectedDate || getLocalDate(),
                    slot_scope: slotScope,
                    reason: 'Punong Barangay Unavailable'
                });
                showToast(res.message || 'Matagumpay na nai-reschedule ang mga hearing!');
                await fetchDashboardData();
            } catch (err) {
                showToast(err.message || 'Nabigong i-reschedule ang mga hearing.', true);
            }
        };

        modal.querySelector('#clerkPbAfternoonBtn').onclick = () => runBatch('afternoon');
        modal.querySelector('#clerkPbWholeDayBtn').onclick = () => runBatch('all');
        modal.style.display = 'flex';
    };

    // Render Agenda Stream (Hearings + Paanyaya)
    const renderAgendaStream = () => {
        const container = document.getElementById('clerkAgendaStream');
        if (!container) return;

        const isSingleDayView = state.period === 'all' || Boolean(state.selectedDate);
        const activeDate = isSingleDayView ? (state.selectedDate || getLocalDate()) : null;
        if (state.period === 'all' && !state.selectedDate) {
            state.selectedDate = activeDate;
        }

        const agendaItems = [];

        if (isSingleDayView && Array.isArray(state.stats?.calendar_hearings) && state.stats.calendar_hearings.length > 0) {
            const dayRecords = state.stats.calendar_hearings.filter((rec) => {
                if (!isActiveQueueSession(rec)) return false;
                const recDate = String(rec.scheduled_date || rec.hearing_date || '').slice(0, 10);
                return recDate === activeDate;
            });

            dayRecords.forEach((rec) => {
                const isPaanyaya = String(rec.record_type || '').toLowerCase() === 'paanyaya'
                    || String(rec.hearing_type || '').toLowerCase().includes('paanyaya');
                if (isPaanyaya) {
                    agendaItems.push({
                        type: 'paanyaya',
                        id: rec.paanyaya_id || rec.id,
                        complaint_id: rec.complaint_id,
                        session_id: rec.session_id,
                        queue_date: String(rec.scheduled_date || rec.hearing_date || activeDate).slice(0, 10),
                        queue_order: Number(rec.queue_order || 0),
                        time: rec.scheduled_time || rec.hearing_date || rec.scheduled_date,
                        title: `${rec.complaint_number ? 'Complaint #' + rec.complaint_number : 'Pre-Docketed'} · Paanyaya Invitation (Stage 0)`,
                        subTitle: rec.complaint_title || '',
                        parties: `Inanyayahan: ${rec.invitee_name || rec.respondent_names || 'Invitee'}`,
                        venue: rec.venue || 'Tanggapan ng Barangay Tumana',
                        status: rec.status || 'Scheduled',
                        complainant_attended: rec.complainant_attended,
                        respondent_attended: rec.respondent_attended,
                        raw: rec
                    });
                } else {
                    agendaItems.push({
                        type: 'hearing',
                        id: rec.hearing_id,
                        case_id: rec.case_id,
                        complaint_id: rec.complaint_id,
                        queue_date: String(rec.hearing_date || activeDate).slice(0, 10),
                        queue_order: Number(rec.queue_order || 0),
                        time: rec.hearing_date,
                        title: `${rec.case_number ? 'Case #' + rec.case_number : 'Hearing'} · ${rec.hearing_type || 'Mediation Session'}`,
                        subTitle: rec.complaint_title || '',
                        parties: `${rec.complainant_names ? '(C) ' + rec.complainant_names : 'Complainant'} vs. ${rec.respondent_names ? '(R) ' + rec.respondent_names : 'Respondent'}`,
                        venue: rec.venue || 'Lupon Court Room',
                        status: rec.queue_status || rec.status || 'Scheduled',
                        raw: rec
                    });
                }
            });
        } else {
            const hearings = (state.stats?.today_hearings || []).filter(isActiveQueueSession);
            const paanyayas = (state.stats?.today_paanyayas || []).filter((p) => {
                if (!isActiveQueueSession(p)) return false;
                if (activeDate) {
                    return String(p.scheduled_date || '').slice(0, 10) === activeDate;
                }
                return true;
            });

            hearings.forEach((h) => {
                agendaItems.push({
                    type: 'hearing',
                    id: h.hearing_id,
                    case_id: h.case_id,
                    complaint_id: h.complaint_id,
                    queue_date: String(h.hearing_date || activeDate || getLocalDate()).slice(0, 10),
                    queue_order: Number(h.queue_order || 0),
                    time: h.hearing_date,
                    title: `${h.case_number ? 'Case #' + h.case_number : 'Hearing'} · ${h.hearing_type || 'Mediation Session'}`,
                    subTitle: h.complaint_title || '',
                    parties: `${h.complainant_names ? '(C) ' + h.complainant_names : 'Complainant'} vs. ${h.respondent_names ? '(R) ' + h.respondent_names : 'Respondent'}`,
                    venue: h.venue || 'Lupon Court Room',
                    status: h.queue_status || h.status || 'Scheduled',
                    raw: h
                });
            });

            paanyayas.forEach((p) => {
                agendaItems.push({
                    type: 'paanyaya',
                    id: p.paanyaya_id,
                    complaint_id: p.complaint_id,
                    session_id: p.session_id,
                    queue_date: String(p.scheduled_date || activeDate || getLocalDate()).slice(0, 10),
                    queue_order: Number(p.queue_order || 0),
                    time: p.scheduled_time || p.scheduled_date,
                    title: `${p.complaint_number ? 'Complaint #' + p.complaint_number : 'Pre-Docketed'} · Paanyaya Invitation (Stage 0)`,
                    subTitle: p.complaint_title || '',
                    parties: `Inanyayahan: ${p.invitee_name || 'Invitee'}`,
                    venue: p.venue || 'Tanggapan ng Barangay Tumana',
                    status: p.status || 'Scheduled',
                    complainant_attended: p.complainant_attended,
                    respondent_attended: p.respondent_attended,
                    raw: p
                });
            });
        }

        const sortedAll = sortDocketItems(agendaItems);

        // Filter based on activeAgendaFilter
        const isFiltered = state.activeAgendaFilter !== 'all';
        const filtered = sortedAll.filter((item) => {
            if (state.activeAgendaFilter === 'hearings') return item.type === 'hearing';
            if (state.activeAgendaFilter === 'paanyaya') return item.type === 'paanyaya';
            return true;
        });

        // Update count badges
        const countAll = sortedAll.length;
        const countHearings = sortedAll.filter((i) => i.type === 'hearing').length;
        const countPaanyaya = sortedAll.filter((i) => i.type === 'paanyaya').length;

        setText('badgeFilterAll', countAll);
        setText('badgeFilterHearings', countHearings);
        setText('badgeFilterPaanyaya', countPaanyaya);

        container.replaceChildren();

        if (filtered.length === 0) {
            const emptyDiv = document.createElement('div');
            emptyDiv.style.cssText = 'padding: 32px 16px; text-align: center; color: #64748b; font-size: 0.88rem; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;';
            emptyDiv.innerHTML = `
                <strong>Walang nakatakdang sesyon para sa kasalukuyang filter (${escapeHtml(activeDate ? formatDateClean(activeDate) : state.period)}).</strong>
                <p style="margin: 4px 0 0; font-size: 0.78rem;">Ang mga pormal na hearing at Paanyaya dialogue para sa araw na ito ay awtomatikong lilitaw dito.</p>
            `;
            container.appendChild(emptyDiv);
            return;
        }

        filtered.forEach((item, index) => {
            const raw = item.raw || {};
            const card = document.createElement('article');
            card.className = `clerk-agenda-item item-${item.type}`;
            card.dataset.index = String(index);
            card.dataset.id = String(item.id || '');
            card.dataset.type = item.type;
            card.dataset.sessionId = String(item.session_id || '');
            card.dataset.complaintId = String(item.complaint_id || '');
            card.dataset.caseId = String(item.case_id || '');
            card.dataset.queueDate = String(item.queue_date || activeDate || getLocalDate()).slice(0, 10);
            card.dataset.officialTime = String(item.time || '');
            card.dataset.venue = String(item.venue || '');
            card.dataset.presidingOfficerId = String(raw.presiding_officer_id || '');
            card.dataset.presidingOfficerName = String(raw.presiding_officer_name || '');
            card.dataset.updatedAt = String(raw.updated_at || '');
            card.dataset.locked = raw.is_locked ? '1' : '0';
            card.dataset.lockCode = String(raw.lock_code || '');
            card.dataset.lockReason = String(raw.lock_reason || '');
            card.dataset.title = item.title;

            // Header
            const header = document.createElement('div');
            header.className = 'agenda-item-header';

            const timeWrap = document.createElement('div');
            timeWrap.style.cssText = 'display: flex; align-items: center; gap: 8px; flex-wrap: wrap;';

            const dragHandle = document.createElement('span');
            dragHandle.className = 'drag-handle';
            dragHandle.title = 'I-drag para baguhin ang docket sequence';
            dragHandle.innerHTML = '&#8942;&#8942;';

            const seqBadge = document.createElement('span');
            seqBadge.className = 'queue-seq-badge';
            seqBadge.textContent = `#${index + 1}`;

            const timePill = document.createElement('span');
            timePill.className = 'agenda-time-pill';
            timePill.style.cssText = `
                font-size: 0.78rem; font-weight: 800; padding: 3px 8px; border-radius: 6px;
                background: ${item.type === 'hearing' ? '#dbeafe' : '#e0e7ff'};
                color: ${item.type === 'hearing' ? '#1e40af' : '#3730a3'};
            `;
            timePill.textContent = formatTimeOnly(item.time) || 'Scheduled';

            const titleHeading = document.createElement('h4');
            titleHeading.style.cssText = 'margin: 0; font-size: 0.94rem; font-weight: 700; color: #0f172a;';
            titleHeading.textContent = item.title;

            timeWrap.append(dragHandle, seqBadge, timePill, titleHeading);

            const statusBadge = document.createElement('span');
            statusBadge.style.cssText = 'font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: #f1f5f9; color: #475569;';
            statusBadge.textContent = item.status;

            header.append(timeWrap, statusBadge);

            // Body
            const body = document.createElement('div');
            body.className = 'agenda-item-body';

            const partiesDiv = document.createElement('div');
            partiesDiv.style.cssText = 'font-size: 0.85rem; font-weight: 600; color: #1e293b;';
            partiesDiv.textContent = item.parties;

            const venueMeta = document.createElement('div');
            venueMeta.style.cssText = 'font-size: 0.78rem; color: #64748b; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;';
            venueMeta.innerHTML = `<span>${escapeHtml(item.venue)}</span>${item.subTitle ? `<span style="color:#cbd5e1;">•</span><span>${escapeHtml(item.subTitle)}</span>` : ''}`;

            body.append(partiesDiv, venueMeta);

            // Actions: 1-Click Attendance & 1-Click Outcome Logging
            const actions = document.createElement('div');
            actions.className = 'agenda-item-actions';

            // Left: Attendance Group
            const attGroup = document.createElement('div');
            attGroup.className = 'agenda-action-group';

            const attLabel = document.createElement('span');
            attLabel.style.cssText = 'font-size: 0.74rem; font-weight: 700; color: #475569; margin-right: 2px;';
            attLabel.textContent = 'Attendance:';
            attGroup.appendChild(attLabel);

            const btnPresent = document.createElement('button');
            btnPresent.type = 'button';
            btnPresent.className = 'btn-quick-att att-present';
            btnPresent.textContent = 'Present';
            btnPresent.title = '1-click mark all parties present';
            btnPresent.addEventListener('click', async () => {
                btnPresent.disabled = true;
                btnPresent.textContent = 'Saving...';
                try {
                    if (item.type === 'paanyaya') {
                        await postQuickAction({
                            action: 'mark_paanyaya_attendance',
                            paanyaya_id: item.id,
                            status: 'Present'
                        });
                    } else {
                        await postQuickAction({
                            action: 'mark_hearing_attendance',
                            hearing_id: item.id,
                            status: 'Present'
                        });
                    }
                    showToast(`Marked ${item.title} attendance as Present!`);
                    await fetchDashboardData();
                } catch (err) {
                    showToast(err.message, true);
                    btnPresent.disabled = false;
                    btnPresent.textContent = 'Present';
                }
            });
            attGroup.appendChild(btnPresent);

            const btnAbsent = document.createElement('button');
            btnAbsent.type = 'button';
            btnAbsent.className = 'btn-quick-att att-absent';
            btnAbsent.textContent = 'Absent';
            btnAbsent.title = '1-click mark respondent/invitee absent';
            btnAbsent.addEventListener('click', async () => {
                btnAbsent.disabled = true;
                btnAbsent.textContent = 'Saving...';
                try {
                    if (item.type === 'paanyaya') {
                        await postQuickAction({
                            action: 'mark_paanyaya_attendance',
                            paanyaya_id: item.id,
                            status: 'Absent'
                        });
                    } else {
                        await postQuickAction({
                            action: 'mark_hearing_attendance',
                            hearing_id: item.id,
                            status: 'Absent'
                        });
                    }
                    showToast(`Marked ${item.title} as Absent!`);
                    await fetchDashboardData();
                } catch (err) {
                    showToast(err.message, true);
                    btnAbsent.disabled = false;
                    btnAbsent.textContent = 'Absent';
                }
            });
            attGroup.appendChild(btnAbsent);

            if (item.type === 'hearing') {
                const btnCheckin = document.createElement('button');
                btnCheckin.type = 'button';
                btnCheckin.className = 'btn-quick-att att-checkin';
                btnCheckin.textContent = 'Detailed Check-in';
                btnCheckin.addEventListener('click', () => {
                    openQuickAttendanceModal(item.raw);
                });
                attGroup.appendChild(btnCheckin);
            }

            actions.appendChild(attGroup);

            // Right: Outcome Group (Specially for Paanyaya) or Open Case
            const outcomeGroup = document.createElement('div');
            outcomeGroup.className = 'agenda-action-group';

            if (item.type === 'paanyaya') {
                const outcomeLabel = document.createElement('span');
                outcomeLabel.style.cssText = 'font-size: 0.74rem; font-weight: 700; color: #475569; margin-right: 2px;';
                outcomeLabel.textContent = '1-Click Outcome:';
                outcomeGroup.appendChild(outcomeLabel);

                // 1. Settled Privately
                const btnSettled = document.createElement('button');
                btnSettled.type = 'button';
                btnSettled.className = 'btn-quick-outcome outcome-settled';
                btnSettled.textContent = 'Settled Privately';
                btnSettled.title = 'Log private amicable agreement';
                btnSettled.addEventListener('click', async () => {
                    if (!confirm(`I-log ang 'Settled Privately' para sa Paanyaya na ito?`)) return;
                    btnSettled.disabled = true;
                    try {
                        await postQuickAction({
                            action: 'log_paanyaya_outcome',
                            paanyaya_id: item.id,
                            complaint_id: item.complaint_id,
                            outcome: 'Settled Privately'
                        });
                        showToast(`Logged outcome: Settled Privately!`);
                        await fetchDashboardData();
                    } catch (err) {
                        showToast(err.message, true);
                        btnSettled.disabled = false;
                    }
                });
                outcomeGroup.appendChild(btnSettled);

                // 2. Issue 2nd Paanyaya
                const btnRetry = document.createElement('button');
                btnRetry.type = 'button';
                btnRetry.className = 'btn-quick-outcome outcome-retry';
                btnRetry.textContent = 'Issue 2nd Paanyaya';
                btnRetry.title = 'Parties absent; schedule 2nd informal invitation';
                btnRetry.addEventListener('click', async () => {
                    if (!confirm(`Mag-isyu ng 2nd Paanyaya invitation para sa hindi sumipot na partido?`)) return;
                    btnRetry.disabled = true;
                    try {
                        await postQuickAction({
                            action: 'log_paanyaya_outcome',
                            paanyaya_id: item.id,
                            complaint_id: item.complaint_id,
                            outcome: 'Issue 2nd Paanyaya'
                        });
                        showToast(`Logged outcome: Issue 2nd Paanyaya!`);
                        await fetchDashboardData();
                    } catch (err) {
                        showToast(err.message, true);
                        btnRetry.disabled = false;
                    }
                });
                outcomeGroup.appendChild(btnRetry);

                // 3. Escalate to Mediation
                const btnEscalate = document.createElement('button');
                btnEscalate.type = 'button';
                btnEscalate.className = 'btn-quick-outcome outcome-escalate';
                btnEscalate.textContent = 'Escalate to Mediation';
                btnEscalate.title = 'Elevate dispute to formal Punong Barangay mediation';
                btnEscalate.addEventListener('click', async () => {
                    if (!confirm(`I-escalate ang reklamong ito mula sa Paanyaya patungo sa pormal na Mediation?`)) return;
                    btnEscalate.disabled = true;
                    try {
                        await postQuickAction({
                            action: 'log_paanyaya_outcome',
                            paanyaya_id: item.id,
                            complaint_id: item.complaint_id,
                            outcome: 'Escalate to Mediation'
                        });
                        showToast(`Escalated to Mediation successfully!`);
                        await fetchDashboardData();
                    } catch (err) {
                        showToast(err.message, true);
                        btnEscalate.disabled = false;
                    }
                });
                outcomeGroup.appendChild(btnEscalate);
            } else {
                // Hearing Link
                const openCaseLink = document.createElement('a');
                openCaseLink.className = 'btn-quick-att att-checkin';
                openCaseLink.style.textDecoration = 'none';
                openCaseLink.textContent = 'View Case File';
                if (item.case_id) {
                    openCaseLink.href = `../cases/case-details.php?id=${encodeURIComponent(item.case_id)}`;
                } else if (item.complaint_id) {
                    openCaseLink.href = `../complaints/complaint-details.php?id=${encodeURIComponent(item.complaint_id)}`;
                }
                outcomeGroup.appendChild(openCaseLink);
            }

            actions.appendChild(outcomeGroup);

            card.append(header, body, actions);
            container.appendChild(card);
        });

        const canReorder = isSingleDayView && !isFiltered;
        let disableReason = '';
        if (isFiltered) {
            disableReason = `Queue reordering is disabled while filtered by '${state.activeAgendaFilter}'. Select 'Lahat (All)' so the complete day is loaded.`;
        } else if (!isSingleDayView) {
            disableReason = `Queue reordering is disabled in multi-day (${state.period}) view. Click a specific date on the Hearing Calendar to load and reorder that day's docket.`;
        }

        window.AgapDocketDragSchedule?.attachDocketContainer(container, {
            itemSelector: '.clerk-agenda-item[data-id]',
            selectedDate: activeDate || getLocalDate(),
            viewMode: isSingleDayView ? 'day' : state.period,
            isFiltered,
            isPaginated: false,
            fullDayLoaded: canReorder,
            canReorder,
            disableReason,
            onRefresh: () => fetchDashboardData(),
            onToast: (msg, isErr) => showToast(msg, isErr)
        });
    };

    // Render Macro Dispute Analytics
    const renderMacroAnalytics = (analytics) => {
        if (!analytics) return;

        // 1. Most Complained Issues
        const catContainer = document.getElementById('analyticsTopCategories');
        if (catContainer) {
            catContainer.replaceChildren();
            const topCategories = analytics.top_categories || [];
            if (topCategories.length === 0) {
                catContainer.innerHTML = '<div class="analytics-loading">Walang naitalang grievance categories.</div>';
            } else {
                const maxCount = Math.max(...topCategories.map(c => c.total || 0), 1);
                topCategories.forEach(c => {
                    const pct = Math.round(((c.total || 0) / maxCount) * 100);
                    const row = document.createElement('div');
                    row.className = 'analytics-bar-item';
                    row.innerHTML = `
                        <div class="bar-item-header">
                            <span class="bar-item-label" title="${escapeHtml(c.category_name)}">${escapeHtml(c.display_name || c.category_name)}</span>
                            <span class="bar-item-count">${c.total || 0}</span>
                        </div>
                        <div class="bar-item-track">
                            <div class="bar-item-fill" style="width: ${Math.max(pct, 4)}%;"></div>
                        </div>
                    `;
                    catContainer.appendChild(row);
                });
            }
        }

        // 2. Purok Dispute Hotspots
        const purokContainer = document.getElementById('analyticsPurokHotspots');
        if (purokContainer) {
            purokContainer.replaceChildren();
            const hotspots = analytics.purok_hotspots || [];
            if (hotspots.length === 0) {
                purokContainer.innerHTML = '<div class="analytics-loading">Walang naitalang datos sa purok.</div>';
            } else {
                const maxCount = Math.max(...hotspots.map(p => p.total || 0), 1);
                hotspots.forEach(p => {
                    const pct = Math.round(((p.total || 0) / maxCount) * 100);
                    const row = document.createElement('div');
                    row.className = 'analytics-bar-item';
                    row.innerHTML = `
                        <div class="bar-item-header">
                            <span class="bar-item-label">${escapeHtml(p.purok_name)}</span>
                            <span class="bar-item-count">${p.total || 0}</span>
                        </div>
                        <div class="bar-item-track">
                            <div class="bar-item-fill hotspot-fill" style="width: ${Math.max(pct, 4)}%;"></div>
                        </div>
                    `;
                    purokContainer.appendChild(row);
                });
            }
        }

        // 3. Gender Distribution
        const genderContainer = document.getElementById('analyticsGenderBreakdown');
        if (genderContainer) {
            genderContainer.replaceChildren();
            const g = analytics.gender_breakdown || {};
            const male = Number(g.Male ?? g.male ?? 0);
            const female = Number(g.Female ?? g.female ?? 0);
            const unspecified = Number(g.Other ?? g.unspecified ?? 0);
            const total = (g.total !== undefined ? Number(g.total) : (male + female + unspecified)) || 1;
            const malePct = Math.round((male / total) * 100);
            const femalePct = Math.round((female / total) * 100);
            const unspecPct = Math.round((unspecified / total) * 100);

            genderContainer.innerHTML = `
                <div class="demographics-gender-bars">
                    <div class="gender-bar-item">
                        <div class="gender-label"><span>Lalaki (Male)</span><strong>${male} (${malePct}%)</strong></div>
                        <div class="gender-track"><div class="gender-fill male" style="width: ${malePct}%;"></div></div>
                    </div>
                    <div class="gender-bar-item">
                        <div class="gender-label"><span>Babae (Female)</span><strong>${female} (${femalePct}%)</strong></div>
                        <div class="gender-track"><div class="gender-fill female" style="width: ${femalePct}%;"></div></div>
                    </div>
                    ${unspecified > 0 ? `
                    <div class="gender-bar-item">
                        <div class="gender-label"><span>Hindi Tinukoy</span><strong>${unspecified} (${unspecPct}%)</strong></div>
                        <div class="gender-track"><div class="gender-fill unspecified" style="width: ${unspecPct}%;"></div></div>
                    </div>` : ''}
                </div>
            `;
        }

        // 4. Age Group Distribution
        const ageContainer = document.getElementById('analyticsAgeDistribution');
        if (ageContainer) {
            ageContainer.replaceChildren();
            const rawAge = analytics.age_distribution;
            let ageGroups = [];

            if (Array.isArray(rawAge)) {
                ageGroups = rawAge;
            } else if (rawAge && typeof rawAge === 'object') {
                const totalAge = Number(rawAge.total ?? 0);
                ageGroups = [
                    { bracket: 'Kabataan (18 – 29 taon)', total: Number(rawAge.youth ?? 0) },
                    { bracket: 'Nasa Hustong Gulang (30 – 59)', total: Number(rawAge.adults ?? 0) },
                    { bracket: 'Senior Citizens (60+ taon)', total: Number(rawAge.seniors ?? 0) },
                    ...(rawAge.minors ? [{ bracket: 'Menor de Edad (<18 taon)', total: Number(rawAge.minors) }] : [])
                ];
            }

            if (ageGroups.length === 0 || ageGroups.every(ag => (ag.total || 0) === 0)) {
                ageContainer.innerHTML = '<div class="analytics-loading">Walang edad na naitala.</div>';
            } else {
                const totalAge = ageGroups.reduce((acc, curr) => acc + (curr.total || 0), 0) || 1;
                const grid = document.createElement('div');
                grid.className = 'demographics-age-grid';
                ageGroups.forEach(ag => {
                    const pct = Math.round(((ag.total || 0) / totalAge) * 100);
                    const pill = document.createElement('div');
                    pill.className = 'age-stat-box';
                    pill.innerHTML = `
                        <span class="age-bracket">${escapeHtml(ag.bracket)}</span>
                        <strong class="age-count">${ag.total || 0}</strong>
                        <span class="age-pct">${pct}%</span>
                    `;
                    grid.appendChild(pill);
                });
                ageContainer.appendChild(grid);
            }
        }
    };

    // Render Security & Audit Feed
    const renderAuditFeed = (feed) => {
        const container = document.getElementById('clerkSecurityAuditFeed');
        if (!container) return;

        container.replaceChildren();
        const items = feed || [];

        if (items.length === 0) {
            container.innerHTML = '<div style="padding: 18px; text-align: center; color: #64748b; font-size: 0.82rem;">Walang kamakailang tala sa audit feed.</div>';
            return;
        }

        items.forEach(item => {
            const el = document.createElement('div');
            el.className = 'audit-feed-item';

            el.innerHTML = `
                <span class="audit-feed-icon" style="display:inline-flex;align-items:center;justify-content:center;width:8px;height:8px;border-radius:50%;background:#64748b;margin-top:6px;flex-shrink:0;"></span>
                <div class="audit-feed-body">
                    <div class="audit-feed-header">
                        <span class="audit-feed-user">${escapeHtml(item.user_name || 'Staff')}</span>
                        <span class="audit-feed-time">${escapeHtml(timeAgo(item.audit_date))}</span>
                    </div>
                    <p class="audit-feed-action">${escapeHtml(item.action || '')}</p>
                </div>
            `;
            container.appendChild(el);
        });
    };

    const selectClerkCalendarDate = (dateStr) => {
        state.selectedDate = dateStr;
        renderAgendaStream();
        document.querySelectorAll('#dashboardCalendarGrid .mini-cal-day[data-date]').forEach((cell) => {
            cell.classList.toggle('is-selected', cell.dataset.date === dateStr);
        });
    };

    // Mini Calendar implementation
    const renderMiniCalendar = () => {
        const grid = document.getElementById('dashboardCalendarGrid');
        const monthDisplay = document.getElementById('dashCalMonthDisplay');
        if (!grid || !monthDisplay) return;

        const date = state.calendarDate;
        const year = date.getFullYear();
        const month = date.getMonth();

        monthDisplay.textContent = date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
        grid.replaceChildren();

        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const todayStr = getLocalDate();

        // Get dates with hearings from calendar_hearings
        const hearingsDates = new Set();
        (state.stats?.calendar_hearings || []).forEach(h => {
            if (h.hearing_date) {
                const hd = new Date(h.hearing_date.replace(' ', 'T'));
                if (hd.getFullYear() === year && hd.getMonth() === month) {
                    hearingsDates.add(hd.getDate());
                }
            }
        });

        for (let i = 0; i < firstDay; i++) {
            const emptyCell = document.createElement('span');
            emptyCell.className = 'mini-cal-day empty';
            grid.appendChild(emptyCell);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const cell = document.createElement('span');
            cell.className = 'mini-cal-day';
            cell.dataset.date = dateStr;
            cell.style.cursor = 'pointer';
            cell.textContent = String(d);

            if (dateStr === todayStr) cell.classList.add('today');
            if (dateStr === state.selectedDate) cell.classList.add('is-selected');

            if (hearingsDates.has(d)) {
                cell.classList.add('has-hearings');
                cell.title = `May nakatakdang pagdinig sa petsang ito — click to view or drop a session card here to reschedule`;
            }

            cell.addEventListener('click', () => selectClerkCalendarDate(dateStr));
            grid.appendChild(cell);
        }

        window.AgapDocketDragSchedule?.attachCalendarDropTargets(grid, {
            onRefresh: () => fetchDashboardData(),
            onToast: (msg, isErr) => showToast(msg, isErr)
        });
    };

    // Fetch Dashboard Data from Backend
    const fetchDashboardData = async () => {
        state.isLoading = true;
        try {
            const url = `../../../backend/api/dashboard/statistics.php?period=${encodeURIComponent(state.period)}&offset=${encodeURIComponent(state.offset)}`;
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });

            if (!res.ok) throw new Error(`HTTP error ${res.status}`);
            const json = await res.json();
            const data = json.data || json;
            state.stats = data;

            // Render all sections
            renderIntakeQueue(data);
            renderSystemMetrics(data);
            renderAgendaStream();
            renderMacroAnalytics(data.analytics);
            renderAuditFeed(data.audit_feed);
            renderMiniCalendar();

            // Update period indicator
            const periodLabel = document.getElementById('periodLabelDisplay');
            if (periodLabel) periodLabel.textContent = data.period_label || data.label || 'All Time';
            const periodDate = document.getElementById('periodDateRangeDisplay');
            if (periodDate) {
                if (data.start_date && data.end_date) {
                    periodDate.textContent = `${data.start_date} – ${data.end_date}`;
                } else {
                    periodDate.textContent = data.period_sublabel || data.sublabel || 'All recorded data in the system';
                }
            }

        } catch (error) {
            console.error('Failed to load Clerk dashboard statistics:', error);
            showToast('Unable to refresh dashboard data.', true);
        } finally {
            state.isLoading = false;
        }
    };

    // Quick Attendance Modal Logic
    const openQuickAttendanceModal = async (hearing) => {
        const modal = document.getElementById('quickDashboardAttendanceModal');
        if (!modal) return;

        state.activeQuickAttHearing = hearing;
        state.activeQuickAttHearingId = hearing.hearing_id;

        const caseBadge = document.getElementById('quickAttCaseNumBadge');
        if (caseBadge) caseBadge.textContent = hearing.case_number ? `Case #${hearing.case_number}` : 'Case';

        const typeEl = document.getElementById('quickAttHearingType');
        if (typeEl) typeEl.textContent = `${hearing.hearing_type || 'Hearing'}${hearing.mediation_attempt ? ' ' + hearing.mediation_attempt : ''}`;

        const timeEl = document.getElementById('quickAttHearingTime');
        if (timeEl) timeEl.textContent = formatTimeOnly(hearing.hearing_date);

        const venueEl = document.getElementById('quickAttHearingVenue');
        if (venueEl) venueEl.textContent = hearing.venue || 'Lupon Court Room';

        modal.style.display = 'flex';

        // Load hearing attendance details
        try {
            const res = await fetch(`../../../backend/api/hearings/attendance.php?hearing_id=${encodeURIComponent(hearing.hearing_id)}`);
            const data = await res.json();
            if (data.success) {
                state.activeQuickAttData = data;
                renderQuickAttendanceParties(data.parties || []);
            }
        } catch (e) {
            console.error('Error fetching hearing attendance:', e);
        }
    };

    const renderQuickAttendanceParties = (parties) => {
        const container = document.getElementById('quickAttPartiesContainer');
        if (!container) return;

        if (!parties || parties.length === 0) {
            container.innerHTML = '<div style="color: #64748b; font-size: 0.85rem; padding: 12px; text-align: center;">Walang nakatalang panig para sa pagdinig na ito.</div>';
            return;
        }

        container.innerHTML = parties.map(p => {
            const isComp = (p.party_type === 'Complainant');
            const st = p.attendance_status || 'Present';
            return `
                <div class="quick-att-party-row" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div>
                        <span style="font-size: 0.72rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: ${isComp ? '#dcfce7' : '#fee2e2'}; color: ${isComp ? '#166534' : '#991b1b'}; margin-right: 6px;">
                            ${escapeHtml(p.party_type)}
                        </span>
                        <strong style="font-size: 0.88rem; color: #0f172a;">${escapeHtml(p.name || p.full_name || p.resident_name)}</strong>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <label style="font-size: 0.82rem; display: flex; align-items: center; gap: 4px; cursor: pointer;">
                            <input type="radio" name="quick_att_${p.resident_id || p.party_id}" value="Present" ${st === 'Present' ? 'checked' : ''}>
                            <span>Present</span>
                        </label>
                        <label style="font-size: 0.82rem; display: flex; align-items: center; gap: 4px; cursor: pointer; color: #991b1b;">
                            <input type="radio" name="quick_att_${p.resident_id || p.party_id}" value="Absent" ${st === 'Absent' ? 'checked' : ''}>
                            <span>Absent</span>
                        </label>
                    </div>
                </div>
            `;
        }).join('');
    };

    const closeQuickAttendanceModal = () => {
        const modal = document.getElementById('quickDashboardAttendanceModal');
        if (modal) modal.style.display = 'none';
        state.activeQuickAttHearing = null;
        state.activeQuickAttHearingId = null;
    };

    // Initialize Event Listeners
    const initListeners = () => {
        // Agenda filter tabs
        const setClerkAgendaFilter = (filterName, clickedElement = null) => {
            state.activeAgendaFilter = filterName;
            document.querySelectorAll('.btn-agenda-filter').forEach(b => {
                b.classList.toggle('active', b.getAttribute('data-filter') === filterName);
            });
            document.querySelectorAll('.docket-queue-card, .system-metric-pill').forEach(el => {
                el.classList.remove('is-active');
            });
            if (clickedElement) {
                clickedElement.classList.add('is-active');
            }
            renderAgendaStream();
            const panel = document.getElementById('clerkAgendaPanel');
            if (panel) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                panel.classList.remove('panel-highlight-flash');
                void panel.offsetWidth;
                panel.classList.add('panel-highlight-flash');
            }
        };

        document.querySelectorAll('.btn-agenda-filter').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const filter = btn.getAttribute('data-filter') || 'all';
                setClerkAgendaFilter(filter);
            });
        });


        // Period filter tabs
        document.querySelectorAll('.period-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.period-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                state.period = tab.getAttribute('data-period') || 'all';
                state.offset = 0;
                state.selectedDate = state.period === 'all' ? getLocalDate() : null;

                const navBtns = document.getElementById('periodNavButtons');
                if (navBtns) navBtns.style.display = state.period === 'all' ? 'none' : 'flex';

                fetchDashboardData();
            });
        });

        // Period nav buttons
        document.getElementById('prevPeriodBtn')?.addEventListener('click', () => {
            state.offset--;
            fetchDashboardData();
        });
        document.getElementById('nextPeriodBtn')?.addEventListener('click', () => {
            state.offset++;
            fetchDashboardData();
        });
        document.getElementById('currentPeriodBtn')?.addEventListener('click', () => {
            state.offset = 0;
            fetchDashboardData();
        });

        // Mini calendar nav
        document.getElementById('dashCalPrevBtn')?.addEventListener('click', () => {
            state.calendarDate.setMonth(state.calendarDate.getMonth() - 1);
            renderMiniCalendar();
        });
        document.getElementById('dashCalNextBtn')?.addEventListener('click', () => {
            state.calendarDate.setMonth(state.calendarDate.getMonth() + 1);
            renderMiniCalendar();
        });
        document.getElementById('dashCalTodayBtn')?.addEventListener('click', () => {
            state.calendarDate = new Date();
            selectClerkCalendarDate(getLocalDate());
            renderMiniCalendar();
        });

        // 1-Click Monthly Council Meeting Brief
        document.getElementById('btnClerkCouncilBrief')?.addEventListener('click', () => {
            const st = state.stats || {};
            const now = new Date();
            const query = new URLSearchParams({
                year: String(st.year || now.getFullYear()),
                month: String(st.month || (now.getMonth() + 1)),
                autoprint: '1'
            });
            if (state.period === 'monthly' && st.start_date && st.end_date) {
                query.set('start_date', st.start_date);
                query.set('end_date', st.end_date);
                if (st.period_label) query.set('period_label', st.period_label);
            }
            window.open(`../../../backend/api/reports/council_brief.php?${query.toString()}`, '_blank', 'width=1020,height=820,noopener,noreferrer');
        });

        // Quick Attendance modal close
        document.getElementById('quickAttCloseBtn')?.addEventListener('click', closeQuickAttendanceModal);
        document.getElementById('quickAttCancelBtn')?.addEventListener('click', closeQuickAttendanceModal);

        // Save detailed attendance
        document.getElementById('quickAttSaveBtn')?.addEventListener('click', async () => {
            if (!state.activeQuickAttHearingId) return;

            const parties = state.activeQuickAttData?.parties || [];
            const payloadParties = parties.map(p => {
                const id = p.resident_id || p.party_id;
                const selected = document.querySelector(`input[name="quick_att_${id}"]:checked`);
                return {
                    resident_id: id,
                    party_id: id,
                    party_type: p.party_type,
                    attendance_status: selected ? selected.value : 'Present'
                };
            });

            try {
                const res = await fetch('../../../backend/api/hearings/attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        hearing_id: state.activeQuickAttHearingId,
                        parties: payloadParties,
                        attendance_notes: document.getElementById('quickAttRemarks')?.value || ''
                    })
                });

                const data = await res.json();
                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Nabigo ang pag-save ng attendance.');
                }

                showToast('Nai-save ang attendance record nang matagumpay!');
                closeQuickAttendanceModal();
                await fetchDashboardData();
            } catch (err) {
                showToast(err.message, true);
            }
        });

        // Auto-refresh every 60 seconds
        setInterval(() => {
            if (!document.hidden && !state.isLoading) {
                fetchDashboardData();
            }
        }, 60000);
    };

    // Bootstrap
    const boot = () => {
        initListeners();
        fetchDashboardData();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

})();
