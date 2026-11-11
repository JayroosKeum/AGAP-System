/**
 * AGAP - Lupon Member / Conciliator Dashboard Client Logic
 * Senior-Friendly, High-Contrast UI, Duty Day Calendar & Digital Case Dossier Preview
 */

(() => {
    'use strict';

    const state = {
        userId: null,
        data: null,
        isLoading: false,
        activeCaseDossier: null,
        activeDossierCaseId: null,
        fontSizeMode: localStorage.getItem('agap_lupon_font_size') || 'normal',
        contrastMode: localStorage.getItem('agap_lupon_contrast') === 'true'
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

    const formatDateClean = (dateString) => {
        if (!dateString) return '';
        const d = new Date(dateString.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateString;
        return d.toLocaleDateString('fil-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const setText = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = (val !== null && val !== undefined) ? String(val) : '0';
    };

    // Accessibility Controls: Large Text & High Contrast
    const applyAccessibilitySettings = () => {
        const root = document.body;
        const contrastBtn = document.getElementById('btnToggleContrast');
        const currentStep = typeof window.getAgapFontStep === 'function' ? window.getAgapFontStep() : (state.fontSizeMode === 'large' ? 2 : 1);
        root.classList.toggle('font-size-large', currentStep >= 2);

        if (state.contrastMode) {
            root.classList.add('high-contrast-mode');
            if (contrastBtn) contrastBtn.classList.add('active');
        } else {
            root.classList.remove('high-contrast-mode');
            if (contrastBtn) contrastBtn.classList.remove('active');
        }
    };

    const toggleFontSize = () => {
        state.fontSizeMode = state.fontSizeMode === 'large' ? 'normal' : 'large';
        localStorage.setItem('agap_lupon_font_size', state.fontSizeMode);
        applyAccessibilitySettings();
    };

    const toggleHighContrast = () => {
        state.contrastMode = !state.contrastMode;
        localStorage.setItem('agap_lupon_contrast', String(state.contrastMode));
        applyAccessibilitySettings();
    };

    // Render KPI Top Cards
    const renderKpis = (kpis, dutyDay) => {
        if (!kpis) return;
        setText('kpiTotalAssigned', kpis.total_assigned ?? 0);
        setText('kpiChairmanCount', kpis.as_chairman ?? 0);
        setText('kpiSecretaryCount', kpis.as_secretary ?? 0);
        setText('kpiMemberCount', kpis.as_member ?? 0);
        setText('kpiTodayHearings', kpis.today_hearings ?? 0);
        setText('dutyDayDisplay', dutyDay || 'Wednesday');
        const gName = state.data?.duty_schedule?.group_name;
        const gStation = state.data?.duty_schedule?.station || 'Lupon Court Room';
        const gPos = state.data?.duty_schedule?.group_position ? ` (${state.data.duty_schedule.group_position})` : '';
        if (gName) {
            setText('dutyDayDisplaySub', `${gName}${gPos} · ${gStation}`);
            const bannerHeader = document.querySelector('.duty-day-info h3');
            if (bannerHeader) bannerHeader.textContent = `${gName} · ${gStation}`;
            const headerTag = document.getElementById('dutyDayHeaderTag');
            if (headerTag) headerTag.textContent = `${gName} (${dutyDay})`;
        } else {
            setText('dutyDayDisplaySub', `${dutyDay || 'Wednesday'} Team · Regular Pangkat Duty`);
        }
    };

    // Render Pending Resident Submissions for Jurisdiction Verification (Lupon passes to Clerk)
    const renderPendingVerification = (items) => {
        const container = document.getElementById('luponJurisdictionQueueContainer');
        const badge = document.getElementById('jurisdictionQueueBadge');
        const pillCount = document.getElementById('quickVerifyCount');
        const rows = Array.isArray(items) ? items : [];
        if (badge) badge.textContent = `${rows.length} Pending`;
        if (pillCount) pillCount.textContent = String(rows.length);
        if (!container) return;

        container.replaceChildren();
        if (rows.length === 0) {
            container.innerHTML = '<div style="padding:18px; text-align:center; color:#64748b; background:#f8faf9; border:1px dashed #d5e4dc; border-radius:10px; grid-column:1/-1;">Walang pending complaint na naghihintay ng jurisdiction verification.</div>';
            return;
        }

        rows.forEach(item => {
            const card = document.createElement('article');
            card.className = 'jurisdiction-verify-card';
            const compRef = item.complaint_number ? `#${item.complaint_number}` : `#${item.complaint_id}`;
            const sourceLabel = item.complaint_source === 'Online' ? 'Online Resident Submission' : 'Walk-in Filing';
            card.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
                    <strong style="color:#102b2d; font-size:0.95rem;">Complaint ${escapeHtml(compRef)}</strong>
                    <span style="font-size:0.74rem; font-weight:700; padding:3px 8px; border-radius:999px; background:#eef6f2; color:#1b6553; border:1px solid #cde2d6;">${escapeHtml(sourceLabel)}</span>
                </div>
                <div style="font-weight:700; color:#0f172a; font-size:0.96rem;">${escapeHtml(item.complaint_title || 'Submitted Dispute')}</div>
                <div style="font-size:0.86rem; color:#334155;">
                    <div><strong>Nagsusumbong:</strong> ${escapeHtml(item.complainant_names || 'Complainant')}</div>
                    <div><strong>Inirereklamo:</strong> ${escapeHtml(item.respondent_names || 'Respondent')}</div>
                    <div><strong>Kategorya:</strong> ${escapeHtml(item.category_name || item.case_type || 'General KP')}</div>
                </div>
                <div style="font-size:0.84rem; color:#475569; line-height:1.4; max-height:68px; overflow:hidden;">
                    ${escapeHtml(String(item.narrative || '').slice(0, 180))}${String(item.narrative || '').length > 180 ? '...' : ''}
                </div>
                <div class="jurisdiction-verify-actions">
                    <button type="button" class="btn-verify-pass" data-decision="Accepted">Verify Jurisdiction &rarr; Pass to Clerk</button>
                    <button type="button" class="btn-verify-reject" data-decision="Rejected">Flag Outside KP</button>
                    <a href="../complaints/complaint-details.php?id=${encodeURIComponent(item.complaint_id)}" style="margin-left:auto; font-size:0.82rem; font-weight:700; color:#1b6553; text-decoration:none; align-self:center;">Read Full Salaysay &rarr;</a>
                </div>
            `;

            card.querySelectorAll('button[data-decision]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const decision = btn.dataset.decision;
                    const notePrompt = decision === 'Accepted'
                        ? 'Lupon Member Note (optional, e.g., Verified within Barangay Tumana KP jurisdiction):'
                        : 'Ilagay ang dahilan kung bakit outside KP jurisdiction / kailangan ng info:';
                    const defaultNote = decision === 'Accepted'
                        ? 'Jurisdiction verified by Lupon Member; endorsed to Lupon Clerk for Paanyaya scheduling.'
                        : '';
                    const note = window.prompt(notePrompt, defaultNote);
                    if (note === null) return;

                    btn.disabled = true;
                    try {
                        const fd = new FormData();
                        fd.append('complaint_id', String(item.complaint_id));
                        fd.append('status', decision);
                        fd.append('review_notes', note.trim() || defaultNote || 'Reviewed by Lupon Member');
                        const res = await fetch('../../../backend/api/complaints/review.php', {
                            method: 'POST',
                            body: fd
                        });
                        const json = await res.json().catch(() => ({}));
                        if (!res.ok || !json.success) {
                            throw new Error(json.message || 'Hindi ma-verify ang reklamo.');
                        }
                        await fetchLuponData();
                    } catch (err) {
                        alert(err.message || 'Error during verification.');
                        btn.disabled = false;
                    }
                });
            });

            container.appendChild(card);
        });
    };

    // Render Duty Day Hearing Calendar & Schedule
    const renderDutyHearings = (hearings, dutyDay) => {
        const container = document.getElementById('luponDutyHearingsContainer');
        const countBadge = document.getElementById('dutyHearingsCountBadge');
        if (!container) return;

        const items = hearings || [];
        if (countBadge) countBadge.textContent = `${items.length} Schedule${items.length === 1 ? '' : 's'}`;

        container.replaceChildren();

        if (items.length === 0) {
            container.innerHTML = `
                <div style="padding: 30px; text-align: center; color: #475569; background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; font-size: 1.05rem;">
                    
                    <strong>Walang nakatakdang pagdinig para sa ${escapeHtml(dutyDay)} Team sa kasalukuyan.</strong>
                    <p style="margin: 6px 0 0; font-size: 0.92rem; color: #64748b;">
                        Awtomatikong lilitaw dito ang mga susunod na sesyon ng pagdinig at conciliation ng iyong grupo.
                    </p>
                </div>
            `;
            return;
        }

        const todayDateStr = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();

        items.forEach(h => {
            const hDateOnly = (h.hearing_date || '').split(' ')[0] || (h.hearing_date || '').split('T')[0];
            const isToday = hDateOnly === todayDateStr;

            const card = document.createElement('article');
            card.className = `duty-hearing-item ${isToday ? 'is-today' : ''}`;

            const roleBadgeClass = h.my_role === 'Chairman' ? 'role-chairman' : (h.my_role === 'Secretary' ? 'role-secretary' : 'role-member');
            const roleBadgeText = h.my_role ? `Tungkulin: ${h.my_role}` : 'Duty Observer';

            card.innerHTML = `
                <div class="duty-hearing-header">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span class="duty-hearing-time-pill">
                            ${formatTimeOnly(h.hearing_date) || 'Oras ng Pagdinig'}
                        </span>
                        <span style="font-size: 0.95rem; font-weight: 800; color: #0f172a;">
                            ${escapeHtml(formatDateClean(h.hearing_date))} (${escapeHtml(h.day_of_week || dutyDay)})
                        </span>
                        ${isToday ? '<span style="background:#10b981; color:#fff; font-size:0.8rem; font-weight:800; padding:2px 8px; border-radius:9999px;">NGAYONG ARAW</span>' : ''}
                    </div>
                    <span class="role-badge ${roleBadgeClass}">${escapeHtml(roleBadgeText)}</span>
                </div>

                <div class="duty-hearing-body">
                    <h4>Case #${escapeHtml(h.case_number || 'N/A')} · ${escapeHtml(h.hearing_type || 'Hearing')}${h.mediation_attempt ? ' (Pang-' + h.mediation_attempt + ' Paghaharap)' : ''}</h4>
                    <div class="duty-hearing-parties">
                        <strong>${escapeHtml(h.complainant_names || 'Complainant')}</strong> vs. <strong>${escapeHtml(h.respondent_names || 'Respondent')}</strong>
                    </div>
                    <div class="duty-hearing-meta">
                        <span>${escapeHtml(h.venue || 'Lupon Chamber / Court Room')}</span>
                        <span>•</span>
                        <span>Usapin: ${escapeHtml(h.complaint_title || 'N/A')}</span>
                        <span>•</span>
                        <span style="background:#f1f5f9; padding:2px 8px; border-radius:6px; font-weight:700;">Status: ${escapeHtml(h.status || 'Scheduled')}</span>
                    </div>
                </div>

                <div class="duty-hearing-actions">
                    <button type="button" class="btn-lupon-touch btn-dossier" data-case-id="${h.case_id}">
                        Buksan ang Digital Case Dossier
                    </button>
                    <a href="${(h.hearing_type === 'Conciliation' || h.pangkat_id) ? '../cases/pangkat-workspace.php' : '../cases/mediation-workspace.php'}?case_id=${encodeURIComponent(h.case_id)}&hearing_id=${encodeURIComponent(h.hearing_id)}" class="btn-lupon-touch btn-secondary">
                        Tingnan ang Attendance &amp; Minutes
                    </a>
                </div>
            `;

            // Bind click to open dossier
            card.querySelector('.btn-dossier')?.addEventListener('click', () => {
                openCaseDossier(h.case_id);
            });

            container.appendChild(card);
        });
    };

    // Render Assigned Pangkat Cases
    const renderAssignedCases = (cases) => {
        const container = document.getElementById('luponAssignedCasesContainer');
        const countBadge = document.getElementById('assignedCasesCountBadge');
        if (!container) return;

        const items = cases || [];
        if (countBadge) countBadge.textContent = `${items.length} Kaso`;

        container.replaceChildren();

        if (items.length === 0) {
            container.innerHTML = `
                <div style="padding: 24px; text-align: center; color: #475569; background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px;">
                    
                    <strong>Walang nakatalagang kaso sa Pangkat sa kasalukuyan.</strong>
                </div>
            `;
            return;
        }

        items.forEach(c => {
            const card = document.createElement('article');
            card.className = 'pangkat-case-card';

            const roleBadgeClass = c.my_role === 'Chairman' ? 'role-chairman' : (c.my_role === 'Secretary' ? 'role-secretary' : 'role-member');

            card.innerHTML = `
                <div class="pangkat-case-card-header">
                    <div class="pangkat-case-number">Case #${escapeHtml(c.case_number)}</div>
                    <span class="role-badge ${roleBadgeClass}">Tungkulin: ${escapeHtml(c.my_role || 'Member')}</span>
                </div>

                <div>
                    <h4 class="pangkat-case-title">${escapeHtml(c.complaint_title || 'Reklamo ukol sa Pangkat Conciliation')}</h4>
                    <div class="pangkat-case-parties">
                        <div><strong>Nagsusumbong (C):</strong> ${escapeHtml(c.complainant_names || 'N/A')}</div>
                        <div><strong>Inirereklamo (R):</strong> ${escapeHtml(c.respondent_names || 'N/A')}</div>
                    </div>
                </div>

                <div class="pangkat-case-meta">
                    <span>Pangkat Formed: ${escapeHtml(formatDateClean(c.formation_date) || 'Recent')}</span>
                    <span>•</span>
                    <span>Kabuuang Hearings: <strong>${c.hearing_count || 0}</strong></span>
                    ${c.settlement_status ? `<span>•</span><span style="color:#15803d; font-weight:800;">Kasunduan: ${escapeHtml(c.settlement_status)}</span>` : ''}
                </div>

                <div style="margin-top: 4px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="btn-lupon-touch btn-dossier" data-case-id="${c.case_id}" style="width: 100%; justify-content: center;">
                        Tingnan ang Case Dossier &amp; Mga Kasunduan
                    </button>
                </div>
            `;

            card.querySelector('.btn-dossier')?.addEventListener('click', () => {
                openCaseDossier(c.case_id);
            });

            container.appendChild(card);
        });
    };

    // Open Digital Case Dossier Modal
    const openCaseDossier = async (caseId) => {
        const modal = document.getElementById('digitalCaseDossierModal');
        const modalBody = document.getElementById('dossierModalBody');
        const modalCaseNum = document.getElementById('dossierModalCaseNumber');
        const modalSub = document.getElementById('dossierModalSub');

        if (!modal || !modalBody) return;

        state.activeDossierCaseId = caseId;
        modal.style.display = 'flex';
        modalBody.innerHTML = '<div style="padding: 40px; text-align: center; color: #475569; font-size: 1.1rem; font-weight: 700;">Kinukuha ang kumpletong Digital Case Dossier...</div>';

        try {
            const url = `../../../backend/api/dashboard/lupon-member.php?action=dossier&case_id=${encodeURIComponent(caseId)}`;
            const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const json = await res.json();

            if (!res.ok || !json.success || !json.data) {
                throw new Error(json.message || 'Nabigo sa pagkuha ng dossier.');
            }

            const dossier = json.data;
            state.activeCaseDossier = dossier;
            renderDossierContent(dossier);

            if (modalCaseNum) modalCaseNum.textContent = `Case #${dossier.case.case_number}`;
            if (modalSub) modalSub.textContent = `${dossier.case.complaint_title} · ${dossier.case.case_status || 'Conciliation'}`;

        } catch (err) {
            console.error('Dossier error:', err);
            modalBody.innerHTML = `<div style="padding: 24px; color: #dc2626; font-weight: 700;">Error: ${escapeHtml(err.message)}</div>`;
        }
    };

    // Render Digital Case Dossier Content
    const renderDossierContent = (d) => {
        const modalBody = document.getElementById('dossierModalBody');
        if (!modalBody) return;

        const c = d.case;
        const parties = d.parties || [];
        const hearings = d.hearings || [];
        const settlement = d.settlement;
        const pangkat = d.pangkat_members || [];

        modalBody.replaceChildren();

        // 1. Complaint & Dispute Overview
        const overviewSec = document.createElement('section');
        overviewSec.className = 'dossier-section';
        overviewSec.innerHTML = `
            <h3 class="dossier-section-title">Buod ng Reklamo at Alitan</h3>
            <div style="font-size: 1.05rem; line-height: 1.6; margin-bottom: 12px; color: #0f172a;">
                <strong>Salaysay (Narrative):</strong><br>
                ${escapeHtml(c.narrative || 'Walang nakatalang detalyadong salaysay.')}
            </div>
            ${c.requested_resolution ? `
            <div style="background: #e0f2fe; border-left: 4px solid #0284c7; padding: 10px 14px; border-radius: 4px; font-size: 0.95rem; color: #0369a1;">
                <strong>Hinihinging Solusyon (Requested Relief):</strong> ${escapeHtml(c.requested_resolution)}
            </div>` : ''}
            <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 10px; font-size: 0.88rem; color: #64748b;">
                <span>Lokasyon: ${escapeHtml(c.incident_location || c.incident_purok || 'Tumana')}</span>
                <span>Petsa ng Insidente: ${escapeHtml(formatDateClean(c.incident_date) || 'N/A')}</span>
                <span>Kategorya: ${escapeHtml(c.category_name || 'Grievance')}</span>
            </div>
        `;
        modalBody.appendChild(overviewSec);

        // 2. Contact & Party Dossier
        const partiesSec = document.createElement('section');
        partiesSec.className = 'dossier-section';
        partiesSec.innerHTML = `<h3 class="dossier-section-title">Talaan ng mga Panig at Contact Numbers</h3>`;

        const partiesGrid = document.createElement('div');
        partiesGrid.className = 'dossier-parties-list';

        parties.forEach(p => {
            const pBox = document.createElement('div');
            pBox.className = 'dossier-party-box';
            const isComp = p.party_type === 'Complainant';
            pBox.innerHTML = `
                <span class="dossier-party-type ${isComp ? 'complainant' : 'respondent'}">${escapeHtml(p.party_type)}</span>
                <div class="dossier-party-name">${escapeHtml(p.full_name)}</div>
                <div class="dossier-party-contact">
                    <div><strong>Telepono:</strong> ${p.contact_no ? `<a href="tel:${escapeHtml(p.contact_no)}">${escapeHtml(p.contact_no)}</a>` : '<span style="color:#94a3b8;">Walang nakatalang numero</span>'}</div>
                    <div><strong>Address:</strong> ${escapeHtml(p.house_street || p.purok || p.address || 'Barangay Tumana')}</div>
                    ${p.civil_status ? `<div><strong>Katayuan:</strong> ${escapeHtml(p.civil_status)}</div>` : ''}
                </div>
            `;
            partiesGrid.appendChild(pBox);
        });
        partiesSec.appendChild(partiesGrid);
        modalBody.appendChild(partiesSec);

        // 3. Pangkat Panel Constitution
        if (pangkat.length > 0) {
            const pangkatSec = document.createElement('section');
            pangkatSec.className = 'dossier-section';
            pangkatSec.innerHTML = `<h3 class="dossier-section-title">Komposisyon ng Pangkat Tagapagkasundo</h3>`;
            const panelGrid = document.createElement('div');
            panelGrid.style.cssText = 'display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;';

            pangkat.forEach(pm => {
                const badgeClass = pm.position === 'Chairman' ? 'role-chairman' : (pm.position === 'Secretary' ? 'role-secretary' : 'role-member');
                const pItem = document.createElement('div');
                pItem.style.cssText = 'background: #fff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 14px;';
                pItem.innerHTML = `
                    <span class="role-badge ${badgeClass}" style="margin-bottom: 6px;">${escapeHtml(pm.position)}</span>
                    <div style="font-weight: 800; color: #0f172a; font-size: 1.05rem;">${escapeHtml(pm.full_name)}</div>
                    ${pm.contact_no ? `<div style="font-size: 0.88rem; color: #64748b; margin-top: 4px;">${escapeHtml(pm.contact_no)}</div>` : ''}
                `;
                panelGrid.appendChild(pItem);
            });
            pangkatSec.appendChild(panelGrid);
            modalBody.appendChild(pangkatSec);
        }

        // 4. Agreed Terms & Settlement
        if (settlement) {
            const settleSec = document.createElement('section');
            settleSec.className = 'dossier-section';
            settleSec.style.border = '2px solid #16a34a';
            settleSec.style.background = '#f0fdf4';
            settleSec.innerHTML = `
                <h3 class="dossier-section-title" style="color: #15803d; border-color: #bbf7d0;">
                    Kasunduan at Pinagkasunduang Termino (Amicable Settlement)
                </h3>
                <div style="font-size: 1.05rem; line-height: 1.6; color: #14532d; font-weight: 600; margin-bottom: 10px;">
                    ${escapeHtml(settlement.agreement_details || 'Pormal na nagkasundo ang mga panig.')}
                </div>
                <div style="display: flex; gap: 14px; flex-wrap: wrap; font-size: 0.88rem; color: #166534; font-weight: 700;">
                    <span>Petsa ng Kasunduan: ${escapeHtml(formatDateClean(settlement.settlement_date))}</span>
                    ${settlement.compliance_due_date ? `<span>Takdang Petsa: ${escapeHtml(formatDateClean(settlement.compliance_due_date))}</span>` : ''}
                    <span>Katayuan: ${escapeHtml(settlement.compliance_status || 'Pending')}</span>
                </div>
            `;
            modalBody.appendChild(settleSec);
        }

        // 5. Hearing History & Minutes Timeline
        const histSec = document.createElement('section');
        histSec.className = 'dossier-section';
        histSec.innerHTML = `<h3 class="dossier-section-title">Kasaysayan ng Pagdinig at Minutes Timeline</h3>`;

        if (hearings.length === 0) {
            histSec.innerHTML += '<div style="color: #64748b; font-size: 0.95rem;">Wala pang naitalang nakaraang pagdinig.</div>';
        } else {
            const timeline = document.createElement('div');
            timeline.className = 'dossier-history-timeline';

            hearings.forEach((h, idx) => {
                const entry = document.createElement('div');
                entry.className = 'dossier-history-entry';
                entry.innerHTML = `
                    <div class="dossier-history-date">Ika-${idx + 1} Pagdinig · ${escapeHtml(formatDateClean(h.hearing_date))} (${formatTimeOnly(h.hearing_date)})</div>
                    <div class="dossier-history-title">${escapeHtml(h.hearing_type)} · ${escapeHtml(h.status)}</div>
                    <div style="font-size: 0.92rem; color: #334155; margin-bottom: 4px;">
                        Lugar: <strong>${escapeHtml(h.venue || 'Lupon Chamber')}</strong> |
                        Pagdalo: C (${escapeHtml(h.complainant_attendance || 'N/A')}), R (${escapeHtml(h.respondent_attendance || 'N/A')})
                    </div>
                    ${h.agreements_action_items ? `
                    <div class="dossier-history-terms">
                        <strong>Napagkasunduang Aksyon / Hakbang:</strong><br>
                        ${escapeHtml(h.agreements_action_items).replace(/\n/g, '<br>')}
                    </div>` : ''}
                    ${h.complainant_statement ? `
                    <div style="font-size: 0.88rem; color: #1e293b; margin-top: 6px; background:#f8fafc; padding:8px 10px; border-radius:6px;">
                        <strong>Salaysay ng Nagsusumbong:</strong> ${escapeHtml(h.complainant_statement)}
                    </div>` : ''}
                    ${h.respondent_statement ? `
                    <div style="font-size: 0.88rem; color: #1e293b; margin-top: 6px; background:#f8fafc; padding:8px 10px; border-radius:6px;">
                        <strong>Salaysay ng Inirereklamo:</strong> ${escapeHtml(h.respondent_statement)}
                    </div>` : ''}
                    ${h.settlement_discussion_notes ? `
                    <div style="font-size: 0.88rem; color: #475569; margin-top: 6px;">
                        <em>Tala ng Usapan / Minutes:</em> ${escapeHtml(h.settlement_discussion_notes)}
                    </div>` : ''}
                    ${h.session_outcome ? `
                    <div style="font-size: 0.86rem; color: #102b2d; font-weight:700; margin-top: 6px;">
                        Resulta ng Sesyon: ${escapeHtml(h.session_outcome)}
                    </div>` : ''}
                `;
                timeline.appendChild(entry);
            });
            histSec.appendChild(timeline);
        }
        modalBody.appendChild(histSec);

        // 6. Created KP Forms & Documents (Read-Only Preview Only)
        const docs = Array.isArray(d.documents) ? d.documents : [];
        const docSec = document.createElement('section');
        docSec.className = 'dossier-section';
        docSec.innerHTML = `<h3 class="dossier-section-title">Mga Nalikhang KP Forms (Read-Only Preview)</h3>`;

        if (docs.length === 0) {
            docSec.innerHTML += '<div style="color:#64748b; font-size:0.92rem;">Wala pang naitalang nalikhang KP Form para sa kasong ito.</div>';
        } else {
            const grid = document.createElement('div');
            grid.className = 'dossier-docs-grid';
            docs.forEach(doc => {
                const card = document.createElement('div');
                card.className = 'dossier-doc-item';
                card.innerHTML = `
                    <div>
                        <strong style="color:#102b2d; font-size:0.95rem;">${escapeHtml(doc.template_name || 'KP Form')}</strong>
                        <div style="font-size:0.8rem; color:#475569; margin-top:2px;">${escapeHtml(doc.template_description || '')}</div>
                        <div style="font-size:0.75rem; color:#1b6553; font-weight:700; margin-top:4px;">Status: ${escapeHtml(doc.service_status || 'Generated')}</div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="${escapeHtml(doc.preview_url || '#')}" target="_blank" rel="noopener noreferrer" class="btn-lupon-touch" style="padding:7px 12px; font-size:0.84rem; min-height:auto; text-decoration:none; background:#102b2d; color:#fff;">
                            Preview KP Form
                        </a>
                    </div>
                `;
                grid.appendChild(card);
            });
            docSec.appendChild(grid);
        }
        modalBody.appendChild(docSec);

        // Prepend clear Read-Only Notice
        const roBanner = document.createElement('div');
        roBanner.className = 'dossier-readonly-banner';
        roBanner.innerHTML = '<strong>Read-Only Lupon View:</strong> Maaari mong tingnan ang detalye ng kaso, hearing minutes, at mga KP Form. Tanging Lupon Clerk lamang ang maaaring mag-edit o mag-schedule.';
        modalBody.prepend(roBanner);
    };

    const closeDossierModal = () => {
        const modal = document.getElementById('digitalCaseDossierModal');
        if (modal) modal.style.display = 'none';
        state.activeDossierCaseId = null;
        state.activeCaseDossier = null;
    };

    // Load Dashboard Data from Server
    const fetchLuponData = async () => {
        state.isLoading = true;
        try {
            const res = await fetch('../../../backend/api/dashboard/lupon-member.php', {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            if (!json.success || !json.data) throw new Error(json.message || 'Error fetching lupon dashboard data');

            const data = json.data;
            state.data = data;
            state.userId = data.user_id;

            // Render all UI components
            renderKpis(data.kpis, data.duty_day);
            renderPendingVerification(data.pending_verification_complaints || []);
            renderDutyHearings(data.duty_hearings, data.duty_day);
            renderAssignedCases(data.assigned_cases);

        } catch (err) {
            console.error('Failed to load Lupon Member dashboard:', err);
        } finally {
            state.isLoading = false;
        }
    };

    // Event Listeners
    const initListeners = () => {
        window.addEventListener('agapFontScaleChanged', () => applyAccessibilitySettings());
        document.getElementById('btnToggleContrast')?.addEventListener('click', toggleHighContrast);

        document.getElementById('dossierCloseBtn')?.addEventListener('click', closeDossierModal);
        document.getElementById('dossierCloseFooterBtn')?.addEventListener('click', closeDossierModal);

        document.getElementById('digitalCaseDossierModal')?.addEventListener('click', (e) => {
            if (e.target.id === 'digitalCaseDossierModal') {
                closeDossierModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeDossierModal();
        });

        // Print Dossier
        document.getElementById('dossierPrintBtn')?.addEventListener('click', () => {
            window.print();
        });

    };

    // Bootstrap
    const boot = () => {
        applyAccessibilitySettings();
        initListeners();
        fetchLuponData();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
