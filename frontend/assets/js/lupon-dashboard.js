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
        const fontBtn = document.getElementById('btnToggleFontSize');
        const contrastBtn = document.getElementById('btnToggleContrast');

        if (state.fontSizeMode === 'large') {
            root.classList.add('font-size-large');
            if (fontBtn) fontBtn.classList.add('active');
        } else {
            root.classList.remove('font-size-large');
            if (fontBtn) fontBtn.classList.remove('active');
        }

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
        setText('dutyDayDisplaySub', `${dutyDay || 'Wednesday'} Team · Regular Pangkat Duty`);
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
                    <a href="../hearings/index.php?hearing_id=${h.hearing_id}" class="btn-lupon-touch btn-secondary">
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
                    ${h.settlement_discussion_notes ? `
                    <div style="font-size: 0.88rem; color: #475569; margin-top: 6px;">
                        <em>Tala ng Usapan:</em> ${escapeHtml(h.settlement_discussion_notes)}
                    </div>` : ''}
                `;
                timeline.appendChild(entry);
            });
            histSec.appendChild(timeline);
        }
        modalBody.appendChild(histSec);
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
        document.getElementById('btnToggleFontSize')?.addEventListener('click', toggleFontSize);
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

        // KPI Cards Click & Filter Navigation
        document.querySelectorAll('.lupon-kpi-card[data-lupon-card]').forEach(card => {
            const clickHandler = () => {
                const type = card.dataset.luponCard;
                const wasActive = card.classList.contains('is-active');
                document.querySelectorAll('.lupon-kpi-card').forEach(c => c.classList.remove('is-active'));

                if (type === 'chair') {
                    if (wasActive) {
                        renderAssignedCases(state.data?.assigned_cases || []);
                    } else {
                        card.classList.add('is-active');
                        const allCases = state.data?.assigned_cases || [];
                        const chairCases = allCases.filter(c => c.my_role === 'Chairman');
                        renderAssignedCases(chairCases);
                        const countBadge = document.getElementById('assignedCasesCountBadge');
                        if (countBadge) countBadge.textContent = `${chairCases.length} Kaso (Chairperson)`;
                    }
                    document.getElementById('luponAssignedCasesContainer')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else if (type === 'assigned') {
                    if (!wasActive) card.classList.add('is-active');
                    renderAssignedCases(state.data?.assigned_cases || []);
                    document.getElementById('luponAssignedCasesContainer')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else if (type === 'today') {
                    const allHearings = state.data?.duty_hearings || [];
                    const todayDateStr = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();
                    if (wasActive) {
                        renderDutyHearings(allHearings, state.data?.duty_day);
                    } else {
                        card.classList.add('is-active');
                        const todayOnly = allHearings.filter(h => {
                            const hDateOnly = (h.hearing_date || '').split(' ')[0] || (h.hearing_date || '').split('T')[0];
                            return hDateOnly === todayDateStr;
                        });
                        renderDutyHearings(todayOnly, state.data?.duty_day);
                        const countBadge = document.getElementById('dutyHearingsCountBadge');
                        if (countBadge) countBadge.textContent = `${todayOnly.length} Schedule (Ngayong Araw)`;
                    }
                    document.getElementById('luponDutyHearingsContainer')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else if (type === 'duty') {
                    if (!wasActive) card.classList.add('is-active');
                    renderDutyHearings(state.data?.duty_hearings || [], state.data?.duty_day);
                    document.getElementById('luponDutyHearingsContainer')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            };

            card.addEventListener('click', clickHandler);
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    clickHandler();
                }
            });
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
