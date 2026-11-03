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
        activeQuickAttHearing: null,
        activeQuickAttHearingId: null,
        activeQuickAttData: null
    };

    const getCsrfToken = () => {
        return window.agapCsrfToken || document.querySelector('meta[name="agap-csrf-token"]')?.content || '';
    };

    const escapeHtml = (str) => {
        if (str === null || str === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(str).replace(/[&<>"']/g, (m) => map[m]);
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
        setText('clerkActivePaanyaya', data.active_paanyaya ?? 0);
        setText('clerkPendingForms', data.pending_forms ?? 0);
    };

    // Render System Metrics Grid
    const renderSystemMetrics = (data) => {
        setText('sysMetricTotalCases', data.total_cases ?? 0);
        setText('sysMetricMediation', data.active_mediations ?? 0);
        setText('sysMetricConciliation', data.pangkat_conciliations ?? 0);
        setText('sysMetricSettled', data.settled_cases ?? 0);
        setText('sysMetricArchived', data.archived_cases ?? 0);
        setText('sysMetricActiveUsers', data.active_users ?? 0);
    };

    // Render Agenda Stream (Hearings + Paanyaya)
    const renderAgendaStream = () => {
        const container = document.getElementById('clerkAgendaStream');
        if (!container) return;

        const hearings = state.stats?.today_hearings || [];
        const paanyayas = state.stats?.today_paanyayas || [];

        // Normalize items
        const agendaItems = [];

        hearings.forEach(h => {
            agendaItems.push({
                type: 'hearing',
                id: h.hearing_id,
                case_id: h.case_id,
                complaint_id: h.complaint_id,
                time: h.hearing_date,
                title: `${h.case_number ? 'Case #' + h.case_number : 'Hearing'} · ${h.hearing_type || 'Mediation Session'}`,
                subTitle: h.complaint_title || '',
                parties: `${h.complainant_names ? '(C) ' + h.complainant_names : 'Complainant'} vs. ${h.respondent_names ? '(R) ' + h.respondent_names : 'Respondent'}`,
                venue: h.venue || 'Lupon Court Room',
                status: h.queue_status || h.status || 'Scheduled',
                raw: h
            });
        });

        paanyayas.forEach(p => {
            agendaItems.push({
                type: 'paanyaya',
                id: p.paanyaya_id,
                complaint_id: p.complaint_id,
                session_id: p.session_id,
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

        // Filter based on activeAgendaFilter
        const filtered = agendaItems.filter(item => {
            if (state.activeAgendaFilter === 'hearings') return item.type === 'hearing';
            if (state.activeAgendaFilter === 'paanyaya') return item.type === 'paanyaya';
            return true;
        });

        // Update count badges
        const countAll = agendaItems.length;
        const countHearings = agendaItems.filter(i => i.type === 'hearing').length;
        const countPaanyaya = agendaItems.filter(i => i.type === 'paanyaya').length;

        setText('badgeFilterAll', countAll);
        setText('badgeFilterHearings', countHearings);
        setText('badgeFilterPaanyaya', countPaanyaya);

        container.replaceChildren();

        if (filtered.length === 0) {
            const emptyDiv = document.createElement('div');
            emptyDiv.style.cssText = 'padding: 32px 16px; text-align: center; color: #64748b; font-size: 0.88rem; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;';
            emptyDiv.innerHTML = `
                
                <strong>Walang nakatakdang sesyon para sa kasalukuyang filter.</strong>
                <p style="margin: 4px 0 0; font-size: 0.78rem;">Ang mga pormal na hearing at Paanyaya dialogue para sa araw na ito ay awtomatikong lilitaw dito.</p>
            `;
            container.appendChild(emptyDiv);
            return;
        }

        filtered.forEach(item => {
            const card = document.createElement('article');
            card.className = `clerk-agenda-item item-${item.type}`;

            // Header
            const header = document.createElement('div');
            header.className = 'agenda-item-header';

            const timeWrap = document.createElement('div');
            timeWrap.style.cssText = 'display: flex; align-items: center; gap: 8px; flex-wrap: wrap;';

            const timePill = document.createElement('span');
            timePill.style.cssText = `
                font-size: 0.78rem; font-weight: 800; padding: 3px 8px; border-radius: 6px;
                background: ${item.type === 'hearing' ? '#dbeafe' : '#e0e7ff'};
                color: ${item.type === 'hearing' ? '#1e40af' : '#3730a3'};
            `;
            timePill.textContent = formatTimeOnly(item.time) || 'Scheduled';

            const titleHeading = document.createElement('h4');
            titleHeading.style.cssText = 'margin: 0; font-size: 0.94rem; font-weight: 700; color: #0f172a;';
            titleHeading.textContent = item.title;

            timeWrap.append(timePill, titleHeading);

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
        const today = new Date();

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
            const cell = document.createElement('span');
            cell.className = 'mini-cal-day';
            cell.textContent = String(d);

            const isToday = (today.getFullYear() === year && today.getMonth() === month && today.getDate() === d);
            if (isToday) cell.classList.add('today');

            if (hearingsDates.has(d)) {
                cell.classList.add('has-hearings');
                cell.title = `May nakatakdang pagdinig sa petsang ito`;
            }

            grid.appendChild(cell);
        }
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
        document.querySelectorAll('.btn-agenda-filter').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.btn-agenda-filter').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                state.activeAgendaFilter = btn.getAttribute('data-filter') || 'all';
                renderAgendaStream();
            });
        });

        // Period filter tabs
        document.querySelectorAll('.period-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.period-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                state.period = tab.getAttribute('data-period') || 'all';
                state.offset = 0;

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
            renderMiniCalendar();
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
