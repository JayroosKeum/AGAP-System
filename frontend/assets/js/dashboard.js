document.addEventListener('DOMContentLoaded', () => {
    const WIDGET_STORAGE_KEY = 'agap_pb_enabled_widgets_v1';
    const DEFAULT_WIDGETS = [
        'widget-hearings',
        'widget-calendar',
        'widget-analytics',
        'widget-demographics',
        'widget-paanyaya',
        'widget-bulletin',
        'widget-audit'
    ];

    const WIDGET_CATALOG = [
        {
            id: 'widget-hearings',
            title: "Today's Mediation & Hearing Queue",
            description: 'Manage live court room docket, Quick Attendance, Standby, and PB Unavailable rescheduling.',
            tag: '#Mediation',
            thumbType: 'queue'
        },
        {
            id: 'widget-calendar',
            title: 'Session & Hearing Calendar',
            description: 'Interactive month calendar highlighting formal hearings and Paanyaya sessions.',
            tag: '#Schedule',
            thumbType: 'calendar'
        },
        {
            id: 'widget-analytics',
            title: 'Dispute Categories & Purok Hotspots',
            description: 'Identify recurring community grievances and Puroks needing peace & order focus.',
            tag: '#PeaceAndOrder',
            thumbType: 'bars'
        },
        {
            id: 'widget-demographics',
            title: 'Participant Demographics',
            description: 'Review Complainant and Respondent breakdown by gender and age bracket.',
            tag: '#Demographics',
            thumbType: 'donut'
        },
        {
            id: 'widget-paanyaya',
            title: 'Paanyaya Stage 0 Invitations',
            description: 'Track pre-docketing informal dialogues that boost LTIA settlement efficiency.',
            tag: '#Diversion',
            thumbType: 'mail'
        },
        {
            id: 'widget-bulletin',
            title: 'Barangay News & Announcements',
            description: 'Publish and manage public bulletins shown across staff and resident portals.',
            tag: '#Bulletin',
            thumbType: 'news'
        },
        {
            id: 'widget-audit',
            title: 'Security & Governance Audit Trail',
            description: 'Monitor staff sign-ins, resident verifications, and case status changes.',
            tag: '#Audit',
            thumbType: 'shield'
        }
    ];

    const loadSavedWidgets = () => {
        try {
            const raw = localStorage.getItem(WIDGET_STORAGE_KEY);
            if (!raw) return new Set(DEFAULT_WIDGETS);
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) {
                return new Set(parsed.filter((id) => WIDGET_CATALOG.some((w) => w.id === id)));
            }
        } catch (e) {}
        return new Set(DEFAULT_WIDGETS);
    };

    const state = {
        period: 'all',
        offset: 0,
        isLoading: false,
        dashboardData: null,
        activePeriodData: null,
        activeDocketTab: 'priority',
        docketSearch: '',
        activeStatTarget: null,
        casesPage: 1,
        casesPerPage: 8,
        hearings: [],
        hearingsPage: 1,
        hearingsPerPage: 8,
        hearingsPeriodLabel: '',
        currentPeriodLabel: 'All Time',
        calendarCursor: new Date(),
        calendarHearings: [],
        selectedDate: null,
        isSavingQueue: false,
        enabledWidgets: loadSavedWidgets(),
        activeQuickAttHearing: null,
        activeQuickAttHearingId: null,
        activeQuickAttData: null
    };

    const urlParams = new URLSearchParams(window.location.search);
    const initialPeriod = urlParams.get('period') || urlParams.get('tab');
    if (['all', 'weekly', 'monthly', 'quarterly', 'annual'].includes(initialPeriod)) {
        state.period = initialPeriod;
    }
    const initialOffset = parseInt(urlParams.get('offset'), 10);
    if (!Number.isNaN(initialOffset)) {
        state.offset = initialOffset;
    }

    const escapeHtml = (text) => {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    };

    const setElText = (id, text) => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    };

    const setNumericText = (id, value) => {
        setElText(id, Number(value || 0).toLocaleString());
    };

    const setMessage = (message = '', isError = false) => {
        const element = document.getElementById('dashboardMessage');
        if (!element) return;
        element.textContent = message;
        element.classList.toggle('is-error', isError);
    };

    const emptyState = (message) => {
        const item = document.createElement('p');
        item.className = 'dashboard-empty';
        item.textContent = message;
        return item;
    };

    const getCsrfToken = () => {
        return document.querySelector('meta[name="agap-csrf-token"]')?.getAttribute('content')
            || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            || window.agapCsrfToken
            || '';
    };

    const getTodayDateStr = () => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    };

    const getStatusClass = (status) => {
        const s = String(status || '').toLowerCase();
        if (s.includes('settled')) return 'status-settled';
        if (s.includes('conciliation')) return 'status-conciliation';
        if (s.includes('mediation')) return 'status-mediation';
        if (s.includes('arbitration')) return 'status-arbitration';
        if (s.includes('cfa')) return 'status-cfa';
        if (s.includes('dismissed')) return 'status-dismissed';
        if (s.includes('archived')) return 'status-archived';
        if (s.includes('docketed')) return 'status-docketed';
        return 'status-default';
    };

    const formatDateOnly = (value) => {
        if (!value) return '—';
        const date = new Date(String(value).slice(0, 10) + 'T00:00:00');
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const formatDateClean = (value) => {
        if (!value) return '';
        const raw = String(value);
        const normalized = raw.includes(' ') ? raw.replace(' ', 'T') : (raw.length === 10 ? raw + 'T00:00:00' : raw);
        const d = new Date(normalized);
        return Number.isNaN(d.getTime()) ? raw : d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const formatTimeOnly = (raw) => {
        if (!raw) return '';
        const d = new Date(typeof raw === 'string' && raw.includes(' ') ? raw.replace(' ', 'T') : raw);
        if (!Number.isNaN(d.getTime())) {
            return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        }
        if (typeof raw === 'string' && raw.includes(':')) {
            const parts = raw.split(':');
            let h = parseInt(parts[0], 10);
            const m = parts[1] || '00';
            if (!Number.isNaN(h)) {
                const ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                return `${h}:${m} ${ampm}`;
            }
        }
        return String(raw);
    };

    const timeAgo = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(String(dateStr).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return String(dateStr);
        const diffSec = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diffSec < 60) return 'Just now';
        if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
        if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
        if (diffSec < 604800) return `${Math.floor(diffSec / 86400)}d ago`;
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    };

    const getHearingDateKey = (value) => {
        const match = String(value || '').match(/^\d{4}-\d{2}-\d{2}/);
        return match ? match[0] : '';
    };

    const isActiveQueueSession = (session) => {
        const status = String(session?.status || '');
        const queueStatus = String(session?.queue_status || session?.session_queue_status || '').replace(/[\s-]+/g, '_');
        return !['Cancelled', 'Office Cancelled', 'Completed', 'Rescheduled', 'Resolved', 'Unresolved', 'Escalated'].includes(status)
            && queueStatus !== 'Concluded';
    };

    // =========================================================================
    // Add Widget Drawer & Layout Persistence
    // =========================================================================
    const saveEnabledWidgets = () => {
        try {
            localStorage.setItem(WIDGET_STORAGE_KEY, JSON.stringify([...state.enabledWidgets]));
        } catch (e) {}
    };

    const getWidgetThumbSvg = (type) => {
        switch (type) {
            case 'bars':
                return '<svg width="42" height="42" viewBox="0 0 44 44" fill="none"><rect x="6" y="22" width="7" height="14" rx="2.5" fill="#cbd5e1"/><rect x="18" y="10" width="7" height="26" rx="2.5" fill="#2563eb"/><rect x="30" y="16" width="7" height="20" rx="2.5" fill="#93c5fd"/></svg>';
            case 'donut':
                return '<svg width="42" height="42" viewBox="0 0 44 44" fill="none"><circle cx="22" cy="22" r="13" stroke="#e2e8f0" stroke-width="6"/><circle cx="22" cy="22" r="13" stroke="#16a34a" stroke-width="6" stroke-dasharray="52 82" stroke-linecap="round" transform="rotate(-90 22 22)"/></svg>';
            case 'calendar':
                return '<svg width="42" height="42" viewBox="0 0 44 44" fill="none"><rect x="7" y="10" width="30" height="26" rx="5" stroke="#1b6553" stroke-width="2.2"/><path d="M7 18h30" stroke="#1b6553" stroke-width="2.2"/><circle cx="16" cy="25" r="2.2" fill="#2563eb"/><circle cx="25" cy="25" r="2.2" fill="#16a34a"/></svg>';
            case 'mail':
                return '<svg width="42" height="42" viewBox="0 0 44 44" fill="none"><rect x="7" y="12" width="30" height="20" rx="4" stroke="#4f46e5" stroke-width="2.2"/><path d="M8 14l14 10 14-10" stroke="#4f46e5" stroke-width="2.2" stroke-linecap="round"/></svg>';
            case 'news':
                return '<svg width="42" height="42" viewBox="0 0 44 44" fill="none"><rect x="8" y="9" width="28" height="26" rx="4" stroke="#059669" stroke-width="2.2"/><path d="M14 17h16M14 23h16M14 29h10" stroke="#059669" stroke-width="2.2" stroke-linecap="round"/></svg>';
            case 'shield':
                return '<svg width="42" height="42" viewBox="0 0 44 44" fill="none"><path d="M22 7l12 5v9c0 8.5-5.2 14.2-12 16-6.8-1.8-12-7.5-12-16v-9l12-5z" stroke="#334155" stroke-width="2.2"/><path d="M17 22l3.5 3.5L27 19" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            default:
                return '<svg width="42" height="42" viewBox="0 0 44 44" fill="none"><rect x="7" y="10" width="30" height="7" rx="3" fill="#2563eb"/><rect x="7" y="20" width="30" height="6" rx="3" fill="#cbd5e1"/><rect x="7" y="29" width="22" height="6" rx="3" fill="#e2e8f0"/></svg>';
        }
    };

    const applyWidgetVisibility = () => {
        document.querySelectorAll('.pb-widget-card[data-widget-id]').forEach((card) => {
            const id = card.getAttribute('data-widget-id');
            card.classList.toggle('is-hidden-widget', !state.enabledWidgets.has(id));
        });
    };

    const renderWidgetDrawerCatalog = () => {
        const list = document.getElementById('addWidgetCatalogList');
        if (!list) return;
        list.innerHTML = WIDGET_CATALOG.map((w) => {
            const isSelected = state.enabledWidgets.has(w.id);
            return `
                <article class="pb-drawer-card">
                    <div class="pb-drawer-thumb">${getWidgetThumbSvg(w.thumbType)}</div>
                    <div class="pb-drawer-card-info">
                        <h3>${escapeHtml(w.title)}</h3>
                        <p>${escapeHtml(w.description)}</p>
                        <div class="pb-drawer-card-foot">
                            <span class="pb-drawer-tag">${escapeHtml(w.tag)}</span>
                            <button type="button" class="pb-drawer-select-btn ${isSelected ? 'is-selected' : ''}" data-toggle-widget="${escapeHtml(w.id)}">
                                ${isSelected ? 'Added ✓' : 'Select'}
                            </button>
                        </div>
                    </div>
                </article>
            `;
        }).join('');

        list.querySelectorAll('[data-toggle-widget]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-toggle-widget');
                if (state.enabledWidgets.has(id)) {
                    state.enabledWidgets.delete(id);
                } else {
                    state.enabledWidgets.add(id);
                }
                saveEnabledWidgets();
                applyWidgetVisibility();
                renderWidgetDrawerCatalog();
            });
        });
    };

    const initWidgetDrawer = () => {
        const overlay = document.getElementById('addWidgetDrawerOverlay');
        const openBtn = document.getElementById('btnOpenWidgetDrawer');
        const closeBtn = document.getElementById('btnCloseWidgetDrawer');
        const doneBtn = document.getElementById('btnDoneWidgetDrawer');
        const resetBtn = document.getElementById('btnResetWidgetsDefault');

        const openDrawer = () => {
            renderWidgetDrawerCatalog();
            if (overlay) {
                overlay.style.display = 'flex';
                overlay.setAttribute('aria-hidden', 'false');
            }
        };
        const closeDrawer = () => {
            if (overlay) {
                overlay.style.display = 'none';
                overlay.setAttribute('aria-hidden', 'true');
            }
        };

        openBtn?.addEventListener('click', openDrawer);
        closeBtn?.addEventListener('click', closeDrawer);
        doneBtn?.addEventListener('click', closeDrawer);
        overlay?.addEventListener('click', (e) => {
            if (e.target === overlay) closeDrawer();
        });
        resetBtn?.addEventListener('click', () => {
            state.enabledWidgets = new Set(DEFAULT_WIDGETS);
            saveEnabledWidgets();
            applyWidgetVisibility();
            renderWidgetDrawerCatalog();
        });

        document.querySelectorAll('[data-hide-widget]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-hide-widget');
                state.enabledWidgets.delete(id);
                saveEnabledWidgets();
                applyWidgetVisibility();
            });
        });

        applyWidgetVisibility();
    };

    // =========================================================================
    // Executive Charts: Trend Line SVG, Weekday Bar Chart, Segmented Tick Gauge
    // =========================================================================
    const renderTrendChart = (series) => {
        const svg = document.getElementById('captainTrendChartSvg');
        const wrap = document.getElementById('captainTrendChartWrap');
        const tooltip = document.getElementById('captainTrendTooltip');
        if (!svg || !wrap) return;

        const points = Array.isArray(series) && series.length > 0
            ? series
            : [{ label: 'Current', filed: 0, resolved: 0, previous_filed: 0 }];

        const W = 540;
        const H = 168;
        const padL = 32;
        const padR = 14;
        const padT = 14;
        const padB = 26;
        const chartW = W - padL - padR;
        const chartH = H - padT - padB;

        const maxVal = Math.max(
            4,
            ...points.map((p) => Math.max(Number(p.filed || 0), Number(p.resolved || 0), Number(p.previous_filed || 0)))
        );
        const yNiceMax = Math.ceil(maxVal * 1.15);

        const xAt = (idx) => points.length === 1 ? padL + chartW / 2 : padL + (idx / (points.length - 1)) * chartW;
        const yAt = (val) => padT + chartH - (Number(val || 0) / yNiceMax) * chartH;

        const buildPath = (key) => {
            return points.map((p, idx) => `${idx === 0 ? 'M' : 'L'} ${xAt(idx).toFixed(1)} ${yAt(p[key]).toFixed(1)}`).join(' ');
        };

        const filedPath = buildPath('filed');
        const resolvedPath = buildPath('resolved');
        const prevPath = buildPath('previous_filed');
        const areaPath = `${filedPath} L ${xAt(points.length - 1).toFixed(1)} ${(padT + chartH).toFixed(1)} L ${xAt(0).toFixed(1)} ${(padT + chartH).toFixed(1)} Z`;

        const yTicks = [0, 0.5, 1].map((frac) => {
            const val = Math.round(yNiceMax * frac);
            const y = yAt(val);
            return `
                <line x1="${padL}" y1="${y.toFixed(1)}" x2="${W - padR}" y2="${y.toFixed(1)}" stroke="#e9eef4" stroke-dasharray="3 3" stroke-width="1"/>
                <text x="${padL - 8}" y="${(y + 3).toFixed(1)}" text-anchor="end" font-size="10" fill="#94a3b8" font-weight="600">${val}</text>
            `;
        }).join('');

        const labelStep = points.length > 8 ? Math.ceil(points.length / 6) : 1;
        const xLabels = points.map((p, idx) => {
            if (idx !== 0 && idx !== points.length - 1 && idx % labelStep !== 0) return '';
            return `<text x="${xAt(idx).toFixed(1)}" y="${H - 6}" text-anchor="middle" font-size="10" fill="#94a3b8" font-weight="600">${escapeHtml(p.label)}</text>`;
        }).join('');

        const hoverStrips = points.map((p, idx) => {
            const cx = xAt(idx);
            const stripW = points.length === 1 ? chartW : chartW / Math.max(1, points.length - 1);
            const rx = Math.max(padL, cx - stripW / 2);
            return `<rect x="${rx.toFixed(1)}" y="${padT}" width="${stripW.toFixed(1)}" height="${chartH}" fill="transparent" class="pb-trend-hit" data-pt-idx="${idx}" style="cursor:crosshair;"/>`;
        }).join('');

        svg.innerHTML = `
            <defs>
                <linearGradient id="pbTrendAreaGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#2563eb" stop-opacity="0.22"/>
                    <stop offset="100%" stop-color="#2563eb" stop-opacity="0.0"/>
                </linearGradient>
            </defs>
            ${yTicks}
            ${xLabels}
            <path d="${prevPath}" fill="none" stroke="#cbd5e1" stroke-width="1.8" stroke-dasharray="4 4" stroke-linecap="round"/>
            <path d="${areaPath}" fill="url(#pbTrendAreaGrad)"/>
            <path d="${filedPath}" fill="none" stroke="#2563eb" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="${resolvedPath}" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <line id="pbTrendGuideLine" x1="0" y1="${padT}" x2="0" y2="${padT + chartH}" stroke="#94a3b8" stroke-dasharray="3 3" stroke-width="1" style="display:none;"/>
            <circle id="pbTrendFocusFiled" cx="0" cy="0" r="4.5" fill="#2563eb" stroke="#ffffff" stroke-width="2" style="display:none;"/>
            <circle id="pbTrendFocusResolved" cx="0" cy="0" r="4" fill="#16a34a" stroke="#ffffff" stroke-width="2" style="display:none;"/>
            ${hoverStrips}
        `;

        const guide = svg.querySelector('#pbTrendGuideLine');
        const dotFiled = svg.querySelector('#pbTrendFocusFiled');
        const dotResolved = svg.querySelector('#pbTrendFocusResolved');

        svg.querySelectorAll('.pb-trend-hit').forEach((hit) => {
            hit.addEventListener('mouseenter', () => {
                const idx = parseInt(hit.getAttribute('data-pt-idx'), 10);
                const pt = points[idx];
                if (!pt) return;
                const cx = xAt(idx);
                const cyF = yAt(pt.filed);
                const cyR = yAt(pt.resolved);
                if (guide) {
                    guide.setAttribute('x1', cx.toFixed(1));
                    guide.setAttribute('x2', cx.toFixed(1));
                    guide.style.display = 'block';
                }
                if (dotFiled) {
                    dotFiled.setAttribute('cx', cx.toFixed(1));
                    dotFiled.setAttribute('cy', cyF.toFixed(1));
                    dotFiled.style.display = 'block';
                }
                if (dotResolved) {
                    dotResolved.setAttribute('cx', cx.toFixed(1));
                    dotResolved.setAttribute('cy', cyR.toFixed(1));
                    dotResolved.style.display = 'block';
                }
                if (tooltip) {
                    tooltip.innerHTML = `
                        <strong>${escapeHtml(pt.label)}</strong>
                        <div class="pb-trend-tooltip-row"><span style="color:#93c5fd;">● Filed</span><b>${Number(pt.filed || 0)}</b></div>
                        <div class="pb-trend-tooltip-row"><span style="color:#86efac;">● Resolved</span><b>${Number(pt.resolved || 0)}</b></div>
                        <div class="pb-trend-tooltip-row"><span style="color:#94a3b8;">◌ Prior</span><b>${Number(pt.previous_filed || 0)}</b></div>
                    `;
                    const leftPct = Math.min(84, Math.max(16, (cx / W) * 100));
                    const topPct = Math.max(22, (Math.min(cyF, cyR) / H) * 100);
                    tooltip.style.left = `${leftPct}%`;
                    tooltip.style.top = `${topPct}%`;
                    tooltip.style.display = 'block';
                }
            });
        });

        wrap.onmouseleave = () => {
            if (guide) guide.style.display = 'none';
            if (dotFiled) dotFiled.style.display = 'none';
            if (dotResolved) dotResolved.style.display = 'none';
            if (tooltip) tooltip.style.display = 'none';
        };
    };

    const renderWeekdayActivity = (items) => {
        const container = document.getElementById('captainWeekdayBars');
        const peakTag = document.getElementById('peakDaySummaryTag');
        if (!container) return;

        const list = Array.isArray(items) && items.length ? items : [
            { label: 'Sun', total_count: 0, hearings_count: 0, complaints_count: 0, is_peak: false },
            { label: 'Mon', total_count: 0, hearings_count: 0, complaints_count: 0, is_peak: false },
            { label: 'Tue', total_count: 0, hearings_count: 0, complaints_count: 0, is_peak: true },
            { label: 'Wed', total_count: 0, hearings_count: 0, complaints_count: 0, is_peak: false },
            { label: 'Thu', total_count: 0, hearings_count: 0, complaints_count: 0, is_peak: false },
            { label: 'Fri', total_count: 0, hearings_count: 0, complaints_count: 0, is_peak: false },
            { label: 'Sat', total_count: 0, hearings_count: 0, complaints_count: 0, is_peak: false }
        ];

        const maxCount = Math.max(1, ...list.map((d) => Number(d.total_count || 0)));
        const peakItem = list.find((d) => d.is_peak) || list[0];
        if (peakTag && peakItem) {
            peakTag.textContent = `Peak: ${peakItem.label} (${Number(peakItem.total_count || 0)})`;
        }

        container.innerHTML = list.map((d) => {
            const total = Number(d.total_count || 0);
            const pct = total > 0 ? Math.max(16, Math.round((total / maxCount) * 100)) : 12;
            const titleText = `${d.label}: ${d.hearings_count || 0} hearing(s), ${d.complaints_count || 0} filing(s)`;
            return `
                <div class="pb-weekday-col ${d.is_peak ? 'is-peak' : ''}" title="${escapeHtml(titleText)}">
                    <div class="pb-weekday-bar" style="height:${pct}%;">
                        ${d.is_peak ? `<span class="pb-weekday-tooltip">${total.toLocaleString()}</span>` : ''}
                    </div>
                    <span class="pb-weekday-label">${escapeHtml(d.label)}</span>
                </div>
            `;
        }).join('');
    };

    const renderSettlementGauge = (data) => {
        const cap = data.captain_summary || {};
        const totalCases = Number(data.total_cases || 0);
        const settledFormal = Number(data.settled_cases || 0);
        const paanyayaResolved = Number(cap.paanyaya_resolved || 0);
        const totalResolvedAll = Number(cap.total_resolved_all ?? (settledFormal + paanyayaResolved));
        const rate = Number(cap.overall_resolution_rate_pct ?? (totalCases > 0 ? Math.round((settledFormal / totalCases) * 100) : 0));
        const clampedRate = Math.max(0, Math.min(100, rate));

        setElText('bentoAdminGaugePct', `${clampedRate}%`);
        setElText('bentoAdminGaugeSub', `${totalResolvedAll} resolved (${settledFormal} formal · ${paanyayaResolved} Paanyaya)`);
        const gap = Math.max(0, 80 - clampedRate);
        setElText(
            'captainGaugeTargetNote',
            clampedRate >= 80
                ? 'Exceeding 80% LTIA benchmark'
                : `${gap}% to 80% LTIA target · ₱${Number(cap.ltia_estimated_savings || 0).toLocaleString()} saved`
        );

        const group = document.getElementById('captainGaugeTicks');
        if (!group) return;
        const totalTicks = 28;
        const activeTicks = Math.round((clampedRate / 100) * totalTicks);
        const cx = 105;
        const cy = 102;
        const rInner = 68;
        const rOuter = 88;
        let ticksHtml = '';

        for (let i = 0; i < totalTicks; i++) {
            const angleDeg = 180 - (i / (totalTicks - 1)) * 180;
            const rad = (angleDeg * Math.PI) / 180;
            const x1 = cx + rInner * Math.cos(rad);
            const y1 = cy - rInner * Math.sin(rad);
            const x2 = cx + rOuter * Math.cos(rad);
            const y2 = cy - rOuter * Math.sin(rad);
            const isActive = i < activeTicks;
            const strokeColor = isActive ? (i >= Math.floor(totalTicks * 0.65) ? '#16a34a' : '#22c55e') : '#e2e8f0';
            ticksHtml += `<line x1="${x1.toFixed(1)}" y1="${y1.toFixed(1)}" x2="${x2.toFixed(1)}" y2="${y2.toFixed(1)}" stroke="${strokeColor}" stroke-width="4.5" stroke-linecap="round"/>`;
        }
        group.innerHTML = ticksHtml;
    };

    // =========================================================================
    // Executive KPI Row & Overview Stage Strip
    // =========================================================================
    const renderExecutiveHeaderAndKpis = (data) => {
        const cap = data.captain_summary || {};
        const info = data.captain_info || {};
        if (info.captain_name) {
            setElText('pbCaptainDisplayName', info.captain_name);
        }

        const totalDisputes = Number(cap.total_disputes ?? data.total_cases ?? 0);
        const totalCases = Number(data.total_cases || 0);
        const totalPaanyaya = Number(data.paanyaya_complaints || 0);
        const pbMediation = Number(cap.pb_mediation_active ?? data.mediation_cases ?? 0);
        const medDueSoon = Number(cap.pb_mediation_due_soon || 0);
        const medOverdue = Number(cap.pb_mediation_overdue || 0);
        const actionTotal = Number(cap.action_required_total || 0);
        const resolvedTotal = Number(cap.total_resolved_all ?? data.settled_cases ?? 0);
        const ltiaSavings = Number(cap.ltia_estimated_savings ?? (resolvedTotal * 9500));

        setNumericText('kpiTotalDisputes', totalDisputes);
        setElText('kpiTotalDisputesSub', `${totalCases} formal docketed · ${totalPaanyaya} Paanyaya records`);

        const deltaEl = document.getElementById('kpiDisputesDelta');
        const overviewDeltaEl = document.getElementById('overviewDeltaBadge');
        const deltaPct = cap.disputes_delta_pct;
        const applyDeltaBadge = (el) => {
            if (!el) return;
            el.classList.remove('is-positive', 'is-negative', 'is-neutral', 'is-amber');
            if (deltaPct === null || deltaPct === undefined) {
                el.classList.add('is-neutral');
                el.textContent = 'Baseline';
            } else if (Number(deltaPct) >= 0) {
                el.classList.add('is-positive');
                el.textContent = `↗ +${deltaPct}%`;
            } else {
                el.classList.add('is-negative');
                el.textContent = `↘ ${deltaPct}%`;
            }
        };
        applyDeltaBadge(deltaEl);
        applyDeltaBadge(overviewDeltaEl);

        setNumericText('kpiPbMediation', pbMediation);
        const medPill = document.getElementById('kpiMediationRiskPill');
        if (medPill) {
            medPill.classList.remove('is-positive', 'is-negative', 'is-neutral', 'is-amber');
            if (medOverdue > 0) {
                medPill.classList.add('is-negative');
                medPill.textContent = `${medOverdue} overdue`;
            } else if (medDueSoon > 0) {
                medPill.classList.add('is-amber');
                medPill.textContent = `${medDueSoon} due in 7d`;
            } else {
                medPill.classList.add('is-positive');
                medPill.textContent = 'All within 15d';
            }
        }
        setElText('kpiPbMediationSub', `${Number(data.hearings_today || 0)} hearings today · ${Number(data.hearings_this_week || 0)} next 7d`);

        setNumericText('kpiCaptainActions', actionTotal);
        const actPill = document.getElementById('kpiCaptainActionsPill');
        if (actPill) {
            actPill.classList.remove('is-positive', 'is-negative', 'is-neutral', 'is-amber');
            if (actionTotal > 0) {
                actPill.classList.add('is-amber');
                actPill.textContent = 'Review needed';
            } else {
                actPill.classList.add('is-positive');
                actPill.textContent = 'All clear';
            }
        }
        const pendingResCount = Number(cap.pending_resident_verifications || 0);
        setElText(
            'kpiCaptainActionsSub',
            `${Number(cap.pending_screening || 0)} screening · ${pendingResCount} verify · ${Number(cap.pending_pb_attestations || 0)} attest · ${Number(cap.pending_transmittals || 0)} transmittals`
        );

        const verifyBadge = document.getElementById('shortcutVerifyBadge');
        if (verifyBadge) {
            verifyBadge.textContent = String(pendingResCount);
            verifyBadge.style.display = pendingResCount > 0 ? 'inline-flex' : 'none';
        }

        setNumericText('kpiResolvedTotal', resolvedTotal);
        setElText('kpiLtiaSavingsPill', `₱${ltiaSavings.toLocaleString()} saved`);
        setElText('kpiResolvedSub', `${Number(data.settled_cases || 0)} formal settled · ${Number(cap.paanyaya_resolved || 0)} via Paanyaya`);

        // Overview hero metrics & bottom stage strip
        setElText('overviewLtiaHeadline', `₱${ltiaSavings.toLocaleString()}`);
        setNumericText('overviewHearingsToday', data.hearings_today);
        setNumericText('overviewHearingsWeek', data.hearings_this_week);
        setElText('overviewSummonsSla', `${Number(cap.summons_sla_pct ?? 100)}%`);

        const stagePaanyaya = Number(data.active_paanyaya || 0);
        const stageMed = Number(data.mediation_cases || 0);
        const stageCon = Number(data.pangkat_conciliations || 0);
        const stageSettled = resolvedTotal;
        const stageDenom = Math.max(1, stagePaanyaya + stageMed + stageCon + stageSettled);

        setNumericText('stageCountPaanyaya', stagePaanyaya);
        setNumericText('stageCountMediation', stageMed);
        setNumericText('stageCountConciliation', stageCon);
        setNumericText('stageCountSettled', stageSettled);

        const setBarWidth = (id, count) => {
            const el = document.getElementById(id);
            if (el) el.style.width = `${Math.max(4, Math.round((count / stageDenom) * 100))}%`;
        };
        setBarWidth('stageBarPaanyaya', stagePaanyaya);
        setBarWidth('stageBarMediation', stageMed);
        setBarWidth('stageBarConciliation', stageCon);
        setBarWidth('stageBarSettled', stageSettled);

        setElText(
            'overviewExtraOutcomesChip',
            `${Number(data.cfa_cases || 0)} CFA Issued · ${Number(data.archived_cases || 0)} Archived · ${Number(cap.active_lupon_members || 0)} Lupon Active`
        );

        // Hidden compatibility counters
        setNumericText('totalCases', data.total_cases);
        setNumericText('pendingReview', data.pending_review);
        setNumericText('hearingsToday', data.hearings_today);
        setNumericText('hearingsThisWeek', data.hearings_this_week);
        setNumericText('paanyayaComplaints', data.paanyaya_complaints);
        setNumericText('activeMediations', data.active_mediations);
        setNumericText('settledCases', data.settled_cases);
        setNumericText('archivedCases', data.archived_cases);
        setNumericText('cfaCases', data.cfa_cases);
        setNumericText('deadlinesDue', data.deadlines_due);
    };

    // =========================================================================
    // Captain's Docket & Priority Action Table (5 Tabs + Search + Pagination)
    // =========================================================================
    const getFilteredDocketRows = () => {
        const d = state.dashboardData || {};
        const q = state.docketSearch.trim().toLowerCase();
        let rows = [];

        if (state.activeDocketTab === 'priority') {
            rows = d.priority_actions || [];
        } else if (state.activeDocketTab === 'cases') {
            rows = d.cases || [];
            if (state.activeStatTarget === 'active-mediation') {
                rows = rows.filter((c) => c.case_status === 'Mediation' || c.case_status === 'Conciliation');
            } else if (state.activeStatTarget === 'settled-cases') {
                rows = rows.filter((c) => c.case_status === 'Settled');
            }
        } else if (state.activeDocketTab === 'screening') {
            rows = d.pending_complaints || [];
        } else if (state.activeDocketTab === 'residents') {
            rows = d.pending_verifications || [];
        } else if (state.activeDocketTab === 'transmittals') {
            rows = d.transmittals_due || [];
        }

        if (!q) return rows;
        return rows.filter((item) => JSON.stringify(item).toLowerCase().includes(q));
    };

    const renderCaptainDocketTable = () => {
        const d = state.dashboardData || {};
        const thead = document.getElementById('captainDocketThead');
        const tbody = document.getElementById('periodCasesTableBody');
        const countBadge = document.getElementById('periodCaseCountBadge');
        const paginationWrap = document.getElementById('casesTablePagination');
        const infoEl = document.getElementById('casesPaginationInfo');
        const prevBtn = document.getElementById('casesPrevPageBtn');
        const nextBtn = document.getElementById('casesNextPageBtn');
        const pageNums = document.getElementById('casesPageNumbers');
        if (!thead || !tbody) return;

        setElText('tabCountPriority', (d.priority_actions || []).length);
        setElText('tabCountCases', (d.cases || []).length);
        setElText('tabCountScreening', (d.pending_complaints || []).length);
        setElText('tabCountResidents', (d.pending_verifications || []).length);
        setElText('tabCountTransmittals', (d.transmittals_due || []).length);

        const headersByTab = {
            priority: ['Urgency', 'Item / Reference', 'Details / Purok', 'Deadline / Date', 'Action'],
            cases: ['Case #', 'Title & Parties', 'Type / Purok', 'Stage / Deadline', 'Action'],
            screening: ['Status', 'Complaint # & Title', 'Source / Purok', 'Date Filed', 'Action'],
            residents: ['Profile Status', 'Resident Name', 'Email / Contact', 'Purok & Address', 'Action'],
            transmittals: ['Attestation / Transmittal', 'Case # & Title', 'Settlement Date', '10-Day / Repudiation', 'Action']
        };

        const cols = headersByTab[state.activeDocketTab] || headersByTab.cases;
        thead.innerHTML = `<tr>${cols.map((c, idx) => `<th${idx === cols.length - 1 ? ' style="text-align:right;"' : ''}>${escapeHtml(c)}</th>`).join('')}</tr>`;

        const allRows = getFilteredDocketRows();
        if (countBadge) {
            countBadge.textContent = `${allRows.length} item${allRows.length === 1 ? '' : 's'}`;
        }

        const total = allRows.length;
        if (total === 0) {
            tbody.innerHTML = `<tr><td colspan="${cols.length}" class="pb-table-empty">No matching records in this view.</td></tr>`;
            if (paginationWrap) paginationWrap.style.display = 'none';
            return;
        }

        const totalPages = Math.max(1, Math.ceil(total / state.casesPerPage));
        if (state.casesPage > totalPages) state.casesPage = totalPages;
        if (state.casesPage < 1) state.casesPage = 1;

        const startIdx = (state.casesPage - 1) * state.casesPerPage;
        const endIdx = Math.min(startIdx + state.casesPerPage, total);
        const pageItems = allRows.slice(startIdx, endIdx);

        tbody.innerHTML = pageItems.map((item) => {
            if (state.activeDocketTab === 'priority') {
                const urgClass = item.urgency === 'critical' ? 'is-critical' : (item.urgency === 'warning' ? 'is-warning' : 'is-info');
                return `
                    <tr>
                        <td><span class="pb-urgency-pill ${urgClass}">${escapeHtml(item.badge || 'Action')}</span></td>
                        <td><strong>${escapeHtml(item.title)}</strong></td>
                        <td><span style="color:#475569;">${escapeHtml(item.subtitle || '—')}</span></td>
                        <td><span style="color:#64748b; font-size:0.77rem;">${escapeHtml(item.meta || '—')}</span></td>
                        <td style="text-align:right;"><a href="${escapeHtml(item.link_url || '#')}" class="pb-row-action-btn">${escapeHtml(item.action_label || 'Open')}</a></td>
                    </tr>
                `;
            }

            if (state.activeDocketTab === 'screening') {
                return `
                    <tr>
                        <td><span class="pb-urgency-pill is-warning">${escapeHtml(item.status || 'Under Review')}</span></td>
                        <td>
                            <div style="font-weight:700; color:#0f172a;">#${escapeHtml(item.complaint_number || item.complaint_id)} · ${escapeHtml(item.complaint_title || 'Complaint')}</div>
                        </td>
                        <td>${escapeHtml(item.complaint_source || 'Walk-in')}${item.incident_purok ? ' · ' + escapeHtml(item.incident_purok) : ''}</td>
                        <td>${formatDateOnly(item.filed_date)}</td>
                        <td style="text-align:right;"><a href="../complaints/complaint-details.php?id=${encodeURIComponent(item.complaint_id)}" class="pb-row-action-btn">Screen</a></td>
                    </tr>
                `;
            }

            if (state.activeDocketTab === 'residents') {
                const pillClass = item.profile_complete ? 'is-warning' : 'is-info';
                const pillText = item.profile_complete ? 'Ready to Verify' : 'Incomplete Profile';
                const addrText = [item.purok, item.address].filter(Boolean).join(' — ') || 'Not provided yet';
                return `
                    <tr>
                        <td><span class="pb-urgency-pill ${pillClass}">${pillText}</span></td>
                        <td>
                            <div style="font-weight:700; color:#0f172a;">${escapeHtml(item.full_name || item.username)}</div>
                            <div style="font-size:0.74rem; color:#64748b;">@${escapeHtml(item.username)}</div>
                        </td>
                        <td>
                            <div>${escapeHtml(item.email || '—')}</div>
                            <div style="font-size:0.74rem; color:#64748b;">${escapeHtml(item.contact_no || 'No mobile')}</div>
                        </td>
                        <td>${escapeHtml(addrText)}</td>
                        <td style="text-align:right;"><a href="../users/resident-verification.php" class="pb-row-action-btn">Review</a></td>
                    </tr>
                `;
            }

            if (state.activeDocketTab === 'transmittals') {
                const needsAttest = Number(item.pb_attested || 0) === 0;
                const badgeClass = needsAttest ? 'is-warning' : (item.transmittal_status === 'Transmitted' || item.transmittal_status === 'Acknowledged' ? 'is-success' : 'is-info');
                const badgeText = needsAttest ? 'Needs PB Attestation' : `Transmittal: ${item.transmittal_status || 'Pending'}`;
                const deadlineText = item.ten_day_expiry_date
                    ? `10d due ${formatDateOnly(item.ten_day_expiry_date)}`
                    : (item.repudiation_deadline ? `Repudiation ends ${formatDateOnly(item.repudiation_deadline)}` : '—');
                return `
                    <tr>
                        <td><span class="pb-urgency-pill ${badgeClass}">${escapeHtml(badgeText)}</span></td>
                        <td>
                            <div style="font-weight:700; color:#0f172a;">Case #${escapeHtml(item.case_number || item.case_id)}</div>
                            <div style="font-size:0.74rem; color:#64748b;">${escapeHtml(item.complaint_title || 'Amicable Settlement')}</div>
                        </td>
                        <td>${formatDateOnly(item.settlement_date)}</td>
                        <td>${escapeHtml(deadlineText)}</td>
                        <td style="text-align:right;"><a href="../cases/case-details.php?id=${encodeURIComponent(item.case_id)}" class="pb-row-action-btn">${needsAttest ? 'Attest' : 'Manage'}</a></td>
                    </tr>
                `;
            }

            // Default: Case Register
            const caseNum = item.case_number || `Case #${item.case_id}`;
            const parties = [
                item.complainant_names ? `By: ${item.complainant_names}` : '',
                item.respondent_names ? `vs. ${item.respondent_names}` : ''
            ].filter(Boolean).join(' · ');
            const typePill = item.case_type === 'Criminal'
                ? '<span class="type-pill type-criminal">Criminal</span>'
                : '<span class="type-pill type-civil">Civil</span>';
            const statusClass = getStatusClass(item.case_status);
            const deadlineNote = item.mediation_deadline_date
                ? `<div style="font-size:0.72rem; color:#64748b; margin-top:3px;">${item.is_paused ? 'Paused · ' : ''}Due ${formatDateOnly(item.mediation_deadline_date)}</div>`
                : `<div style="font-size:0.72rem; color:#64748b; margin-top:3px;">Filed ${formatDateOnly(item.docket_date)}</div>`;

            return `
                <tr>
                    <td><a href="../cases/case-details.php?id=${encodeURIComponent(item.case_id)}" class="case-number-link">${escapeHtml(caseNum)}</a></td>
                    <td>
                        <div class="case-title-text">${escapeHtml(item.complaint_title || 'Untitled Case')}</div>
                        ${parties ? `<div class="case-parties-sub">${escapeHtml(parties)}</div>` : ''}
                    </td>
                    <td>
                        ${typePill}
                        ${item.incident_purok ? `<div class="case-category-sub">${escapeHtml(item.incident_purok)}</div>` : ''}
                    </td>
                    <td>
                        <span class="status-badge ${statusClass}">${escapeHtml(item.case_status || 'Docketed')}</span>
                        ${deadlineNote}
                    </td>
                    <td style="text-align:right;"><a href="../cases/case-details.php?id=${encodeURIComponent(item.case_id)}" class="pb-row-action-btn">Open Case</a></td>
                </tr>
            `;
        }).join('');

        if (paginationWrap) {
            paginationWrap.style.display = totalPages > 1 ? 'flex' : 'none';
        }
        if (infoEl) {
            infoEl.textContent = `Showing ${startIdx + 1} to ${endIdx} of ${total} item${total === 1 ? '' : 's'}`;
        }
        if (prevBtn) prevBtn.disabled = state.casesPage <= 1;
        if (nextBtn) nextBtn.disabled = state.casesPage >= totalPages;
        if (pageNums) {
            pageNums.replaceChildren();
            if (totalPages > 1) {
                for (let i = 1; i <= totalPages; i++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `page-num-btn ${i === state.casesPage ? 'is-active' : ''}`;
                    btn.textContent = String(i);
                    btn.addEventListener('click', () => {
                        state.casesPage = i;
                        renderCaptainDocketTable();
                    });
                    pageNums.appendChild(btn);
                }
            }
        }
    };

    const setActiveDocketTab = (tabName) => {
        state.activeDocketTab = tabName;
        state.casesPage = 1;
        document.querySelectorAll('.pb-docket-tab').forEach((btn) => {
            const isMatch = btn.getAttribute('data-docket-tab') === tabName;
            btn.classList.toggle('active', isMatch);
            btn.setAttribute('aria-selected', isMatch ? 'true' : 'false');
        });
        renderCaptainDocketTable();
    };

    // =========================================================================
    // Captain AI Assistant (Live Local Insights + Optional Gemini KP Advisor)
    // =========================================================================
    const buildLocalAiAnswer = (kind) => {
        const d = state.dashboardData || {};
        const cap = d.captain_summary || {};
        const analytics = d.analytics || {};
        const topCat = analytics.top_categories?.[0];
        const topPurok = analytics.purok_hotspots?.[0];
        const actions = d.priority_actions || [];

        if (kind === 'risks') {
            return `
                <strong>SLA &amp; Statutory Deadline Check:</strong><br>
                • <strong>${Number(cap.pb_mediation_overdue || 0)}</strong> mediation clock(s) overdue, <strong>${Number(cap.pb_mediation_due_soon || 0)}</strong> due within 7 days, <strong>${Number(cap.pb_mediation_paused || 0)}</strong> paused.<br>
                • <strong>${Number(cap.pangkat_convening_overdue || 0)}</strong> Pangkat panel(s) past convening deadline.<br>
                • <strong>${Number(cap.pending_pb_attestations || 0)}</strong> Amicable Settlement(s) awaiting PB attestation &amp; <strong>${Number(cap.pending_transmittals || 0)}</strong> 10-day MTC/DILG transmittals pending.<br>
                • <strong>${Number(cap.unserved_summons || 0)}</strong> pending/unserved summons record(s) across active cases.
            `;
        }
        if (kind === 'hotspots') {
            const catList = (analytics.top_categories || []).slice(0, 3).map((c) => `${escapeHtml(c.display_name || c.category_name)} (${c.total})`).join(', ') || 'None recorded yet';
            const purokList = (analytics.purok_hotspots || []).slice(0, 3).map((p) => `${escapeHtml(p.purok_name)} (${p.total})`).join(', ') || 'No purok hotspot data';
            return `
                <strong>Peace &amp; Order Hotspots:</strong><br>
                • <strong>Top Puroks:</strong> ${purokList}<br>
                • <strong>Top Dispute Types:</strong> ${catList}<br>
                Consider prioritizing Barangay Tanod visibility in ${escapeHtml(topPurok?.purok_name || 'high-volume areas')}.
            `;
        }
        if (kind === 'council') {
            return `
                <strong>Sangguniang Barangay Talking Points (${escapeHtml(state.currentPeriodLabel)}):</strong><br>
                • Handled <strong>${Number(cap.total_disputes || 0)}</strong> community disputes (${Number(d.total_cases || 0)} formal cases, ${Number(d.paanyaya_complaints || 0)} Stage 0 Paanyaya).<br>
                • Resolved <strong>${Number(cap.total_resolved_all || 0)}</strong> disputes amicably (${Number(cap.overall_resolution_rate_pct || 0)}% overall rate), generating <strong>₱${Number(cap.ltia_estimated_savings || 0).toLocaleString()}</strong> in estimated LTIA court-fee savings.<br>
                • Summons Next-Working-Day SLA stands at <strong>${Number(cap.summons_sla_pct ?? 100)}%</strong>.
            `;
        }
        // Default: Today's Executive Brief
        const topAction = actions[0] ? `${escapeHtml(actions[0].badge)}: ${escapeHtml(actions[0].title)}` : 'No urgent overdue actions right now.';
        return `
            <strong>Captain's Executive Brief:</strong><br>
            • <strong>${Number(d.hearings_today || 0)}</strong> hearing(s) today (${Number(d.hearings_this_week || 0)} in next 7 days) &amp; <strong>${Number(cap.pb_mediation_active || 0)}</strong> active PB Mediation cases.<br>
            • <strong>${Number(cap.action_required_total || 0)}</strong> items need attention (${Number(cap.pending_screening || 0)} complaint reviews, ${Number(cap.pending_resident_verifications || 0)} resident verifications, ${Number(cap.pending_pb_attestations || 0)} PB attestations).<br>
            • <strong>Top Priority:</strong> ${topAction}<br>
            • <strong>Lead Issue:</strong> ${escapeHtml(topCat?.display_name || topCat?.category_name || 'N/A')} in ${escapeHtml(topPurok?.purok_name || 'Barangay Tumana')}.
        `;
    };

    const showAiResponse = (htmlContent) => {
        const orbWrap = document.getElementById('captainAiOrbWrap');
        const box = document.getElementById('captainAiResponseBox');
        if (orbWrap) orbWrap.style.display = 'none';
        if (box) {
            box.innerHTML = htmlContent;
            box.style.display = 'block';
        }
    };

    const initCaptainAiAssistant = () => {
        const form = document.getElementById('captainAiForm');
        const input = document.getElementById('captainAiInput');
        const resetBtn = document.getElementById('btnCaptainAiReset');
        const consentCheck = document.getElementById('captainAiGeminiConsent');

        document.querySelectorAll('.pb-ai-chip').forEach((chip) => {
            chip.addEventListener('click', () => {
                const promptKey = chip.getAttribute('data-ai-prompt') || 'brief';
                showAiResponse(buildLocalAiAnswer(promptKey));
            });
        });

        resetBtn?.addEventListener('click', () => {
            const orbWrap = document.getElementById('captainAiOrbWrap');
            const box = document.getElementById('captainAiResponseBox');
            if (orbWrap) orbWrap.style.display = 'flex';
            if (box) {
                box.innerHTML = '';
                box.style.display = 'none';
            }
            if (input) input.value = '';
        });

        form?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const q = (input?.value || '').trim();
            if (!q) return;
            const lower = q.toLowerCase();

            // Answer barangay telemetry questions locally & instantly without external AI calls
            if (!consentCheck?.checked) {
                if (lower.includes('deadline') || lower.includes('overdue') || lower.includes('sla')) {
                    showAiResponse(buildLocalAiAnswer('risks'));
                    return;
                }
                if (lower.includes('purok') || lower.includes('hotspot') || lower.includes('issue') || lower.includes('crime')) {
                    showAiResponse(buildLocalAiAnswer('hotspots'));
                    return;
                }
                if (lower.includes('council') || lower.includes('ltia') || lower.includes('saving') || lower.includes('report')) {
                    showAiResponse(buildLocalAiAnswer('council'));
                    return;
                }
                if (lower.includes('brief') || lower.includes('today') || lower.includes('summary') || lower.includes('status')) {
                    showAiResponse(buildLocalAiAnswer('brief'));
                    return;
                }
                showAiResponse(`${buildLocalAiAnswer('brief')}<div style="margin-top:8px; padding-top:8px; border-top:1px dashed #cbd5e1; color:#64748b;">Tip: Check the Gemini consent box below to ask open-ended R.A. 7160 legal/procedural questions.</div>`);
                return;
            }

            showAiResponse('<span style="color:#64748b;">Consulting AGAP Gemini KP Advisor...</span>');
            try {
                const res = await fetch('../../../backend/api/ai/chatbot.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8',
                        'Accept': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        message: q,
                        external_ai_consent: true
                    })
                });
                const payload = await res.json().catch(() => ({}));
                if (!res.ok || !payload.success) {
                    throw new Error(payload.message || 'Gemini AI advisor is currently unavailable.');
                }
                const replyHtml = escapeHtml(payload.reply || '').replace(/\n/g, '<br>');
                showAiResponse(`<strong>AGAP KP Advisor:</strong><br>${replyHtml}`);
            } catch (err) {
                showAiResponse(`<span style="color:#b91c1c;">${escapeHtml(err.message)}</span><div style="margin-top:8px;">${buildLocalAiAnswer('brief')}</div>`);
            }
        });
    };

    // =========================================================================
    // Analytics, Audit Feed, Paanyaya, Hearings & Queue Reordering
    // =========================================================================
    const renderAnalytics = (analyticsData) => {
        if (!analyticsData) return;

        const catContainer = document.getElementById('analyticsTopCategories');
        if (catContainer) {
            const categories = analyticsData.top_categories || [];
            if (categories.length === 0) {
                catContainer.innerHTML = '<div style="color: #64748b; font-size: 0.85rem; text-align: center; padding: 14px;">No categorized complaints recorded yet.</div>';
            } else {
                const maxCat = Math.max(...categories.map((c) => Number(c.total || 0)), 1);
                catContainer.innerHTML = categories.map((c) => {
                    const pct = Math.round((Number(c.total || 0) / maxCat) * 100);
                    return `
                        <div class="analytics-bar-item">
                            <div class="analytics-bar-header">
                                <span class="analytics-bar-label" title="${escapeHtml(c.category_name)}">${escapeHtml(c.display_name || c.category_name)}</span>
                                <span class="analytics-bar-count">${c.total} case${Number(c.total) === 1 ? '' : 's'}</span>
                            </div>
                            <div class="analytics-bar-track">
                                <div class="analytics-bar-fill" style="width: ${pct}%;"></div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }

        const purokContainer = document.getElementById('analyticsPurokHotspots');
        if (purokContainer) {
            const puroks = analyticsData.purok_hotspots || [];
            if (puroks.length === 0) {
                purokContainer.innerHTML = '<div style="color: #64748b; font-size: 0.85rem; text-align: center; padding: 14px;">No purok location data recorded yet.</div>';
            } else {
                const maxPurok = Math.max(...puroks.map((p) => Number(p.total || 0)), 1);
                purokContainer.innerHTML = puroks.map((p) => {
                    const totalVal = Number(p.total || 0);
                    const pct = Math.round((totalVal / maxPurok) * 100);
                    return `
                        <div class="analytics-bar-item">
                            <div class="analytics-bar-header">
                                <span class="analytics-bar-label">${escapeHtml(p.purok_name)}</span>
                                <span class="analytics-bar-count" style="color: #ea580c;">${totalVal} dispute${totalVal === 1 ? '' : 's'}</span>
                            </div>
                            <div class="analytics-bar-track">
                                <div class="analytics-bar-fill hotspot-fill" style="width: ${pct}%;"></div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }

        const genderContainer = document.getElementById('analyticsGenderBreakdown');
        if (genderContainer) {
            const genders = analyticsData.gender_breakdown || { Male: 0, Female: 0, Other: 0 };
            const totalG = (genders.Male || 0) + (genders.Female || 0) + (genders.Other || 0);
            const malePct = totalG > 0 ? Math.round(((genders.Male || 0) / totalG) * 100) : 0;
            const femalePct = totalG > 0 ? Math.round(((genders.Female || 0) / totalG) * 100) : 0;

            genderContainer.innerHTML = `
                <div class="demographic-stat-pill">
                    <span class="demo-label">Female Parties</span>
                    <span class="demo-value" style="color: #db2777;">${genders.Female || 0} <small style="font-size: 0.75rem; color: #64748b; font-weight: 500;">(${femalePct}%)</small></span>
                </div>
                <div class="demographic-stat-pill">
                    <span class="demo-label">Male Parties</span>
                    <span class="demo-value" style="color: #2563eb;">${genders.Male || 0} <small style="font-size: 0.75rem; color: #64748b; font-weight: 500;">(${malePct}%)</small></span>
                </div>
                <div class="demographic-stat-pill">
                    <span class="demo-label">Total Participants</span>
                    <span class="demo-value">${totalG}</span>
                </div>
            `;
        }

        const ageContainer = document.getElementById('analyticsAgeDistribution');
        if (ageContainer) {
            const age = analyticsData.age_distribution || { youth: 0, adults: 0, seniors: 0, total: 0 };
            const youthPct = age.total > 0 ? Math.round((age.youth / age.total) * 100) : 0;
            const adultPct = age.total > 0 ? Math.round((age.adults / age.total) * 100) : 0;
            const seniorPct = age.total > 0 ? Math.round((age.seniors / age.total) * 100) : 0;

            ageContainer.innerHTML = `
                <div class="demographic-stat-pill">
                    <span class="demo-label">Youth (18 – 29 yrs)</span>
                    <span class="demo-value" style="color: #0284c7;">${age.youth} <small style="font-size: 0.75rem; color: #64748b; font-weight: 500;">(${youthPct}%)</small></span>
                </div>
                <div class="demographic-stat-pill">
                    <span class="demo-label">Adults (30 – 59 yrs)</span>
                    <span class="demo-value" style="color: #16a34a;">${age.adults} <small style="font-size: 0.75rem; color: #64748b; font-weight: 500;">(${adultPct}%)</small></span>
                </div>
                <div class="demographic-stat-pill">
                    <span class="demo-label">Senior Citizens (60+ yrs)</span>
                    <span class="demo-value" style="color: #9333ea;">${age.seniors} <small style="font-size: 0.75rem; color: #64748b; font-weight: 500;">(${seniorPct}%)</small></span>
                </div>
            `;
        }
    };

    const renderAuditFeed = (feed) => {
        const container = document.getElementById('captainAuditFeedList');
        if (!container) return;
        const items = Array.isArray(feed) ? feed : [];
        if (!items.length) {
            container.innerHTML = '<div class="pb-table-empty">No recent audit logs recorded.</div>';
            return;
        }
        container.innerHTML = items.map((log) => `
            <div class="audit-feed-item">
                <div class="audit-feed-icon" aria-hidden="true">🛡️</div>
                <div class="audit-feed-body">
                    <div class="audit-feed-header">
                        <span class="audit-feed-user">${escapeHtml(log.user_name || 'System')} <small style="font-weight:500; color:#64748b;">(${escapeHtml(log.role_name || 'Staff')})</small></span>
                        <span class="audit-feed-time">${escapeHtml(timeAgo(log.audit_date))}</span>
                    </div>
                    <p class="audit-feed-action">${escapeHtml(log.action)} · <span style="color:#64748b;">${escapeHtml(log.module_name || 'System')}</span></p>
                </div>
            </div>
        `).join('');
    };

    let draggedHearingCard = null;

    const saveReorderedSequence = async (container) => {
        if (state.isSavingQueue) return;
        const itemsPayload = [];
        const items = container.querySelectorAll('.dashboard-list-item[data-id]');
        items.forEach((el, newIdx) => {
            const orderNum = newIdx + 1;
            const seqBadge = el.querySelector('.queue-seq-badge');
            if (seqBadge) seqBadge.textContent = `#${orderNum}`;
            el.dataset.index = String(newIdx);
            itemsPayload.push({
                type: el.dataset.type || 'hearing',
                id: parseInt(el.dataset.id, 10),
                session_id: el.dataset.sessionId ? parseInt(el.dataset.sessionId, 10) : null,
                order: orderNum
            });
        });
        if (itemsPayload.length === 0) return;

        const queueDate = items[0]?.dataset.queueDate || getTodayDateStr();
        state.isSavingQueue = true;
        items.forEach((item) => { item.draggable = false; });
        try {
            const res = await fetch('../../../backend/api/hearings/queue.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    action: 'reorder_queue',
                    items: itemsPayload,
                    queue_date: queueDate
                })
            });
            const data = await res.json().catch(() => ({}));
            if (data.success) {
                if (Array.isArray(data.updated_items)) {
                    data.updated_items.forEach((up) => {
                        const card = container.querySelector(`.dashboard-list-item[data-type="${up.type}"][data-id="${up.id}"]`);
                        if (card) {
                            const timePill = card.querySelector('.agenda-time-pill');
                            if (timePill && up.formatted_time) {
                                timePill.textContent = up.type === 'paanyaya'
                                    ? `${formatDateClean(up.hearing_date)} · ${up.formatted_time}`
                                    : up.formatted_time;
                            }
                        }
                    });
                }
                renderDashboardCalendar();
                window.dispatchEvent(new CustomEvent('agap:hearing-schedule-updated', { detail: data }));
                setTimeout(fetchDashboardData, 0);
            }
        } catch (err) {
            console.error('Error saving hearing sequence:', err);
        } finally {
            state.isSavingQueue = false;
            items.forEach((item) => { item.draggable = true; });
        }
    };

    const bindQueueDragAndDrop = (card, container) => {
        card.draggable = true;
        card.addEventListener('dragstart', (e) => {
            draggedHearingCard = card;
            card.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.dataset.index || '0');
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('is-dragging');
            container.querySelectorAll('.dashboard-list-item').forEach((c) => c.classList.remove('drag-over'));
            if (draggedHearingCard) {
                draggedHearingCard = null;
                saveReorderedSequence(container);
            }
        });
        card.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!draggedHearingCard || draggedHearingCard === card) return;
            const bounds = card.getBoundingClientRect();
            card.parentNode.insertBefore(draggedHearingCard, e.clientY - bounds.top > bounds.height / 2 ? card.nextSibling : card);
            card.classList.add('drag-over');
        });
        card.addEventListener('dragleave', () => card.classList.remove('drag-over'));
    };

    const addPaanyayaItem = (container, item, displayIndex = 0) => {
        const art = document.createElement('article');
        art.className = 'dashboard-list-item';
        art.dataset.id = item.paanyaya_id || item.id || '';
        art.dataset.type = 'paanyaya';
        art.dataset.sessionId = item.session_id || '';
        art.dataset.queueDate = String(item.scheduled_date || getTodayDateStr()).slice(0, 10);
        art.style.cssText = 'padding: 12px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: flex-start; gap: 14px;';

        const content = document.createElement('div');
        content.className = 'dashboard-list-content';
        content.style.flex = '1';

        const timeStr = item.scheduled_date
            ? `${formatDateClean(item.scheduled_date)}${item.scheduled_time ? ' · ' + formatTimeOnly(item.scheduled_time) : ''}`
            : (formatTimeOnly(item.scheduled_time) || 'Scheduled');
        const invitee = item.invitee_name || 'Inanyayahan';
        const complainant = item.complainant_name || 'Nagpaanyaya';
        const complaintRef = item.complaint_number ? `Complaint #${item.complaint_number}` : 'Pre-Docketed';

        const headerRow = document.createElement('div');
        headerRow.style.cssText = 'display: flex; align-items: center; gap: 8px; margin-bottom: 5px; flex-wrap: wrap;';

        const dragHandle = document.createElement('span');
        dragHandle.className = 'drag-handle';
        dragHandle.title = 'I-drag para baguhin ang docket sequence';
        dragHandle.innerHTML = '&#8942;&#8942;';

        const seqBadge = document.createElement('span');
        seqBadge.className = 'queue-seq-badge';
        seqBadge.textContent = `#${displayIndex + 1}`;

        const timeBadge = document.createElement('span');
        timeBadge.className = 'agenda-time-pill';
        timeBadge.style.cssText = 'font-size: 0.76rem; font-weight: 700; color: #1e40af; background: #dbeafe; padding: 2px 8px; border-radius: 5px; white-space: nowrap;';
        timeBadge.textContent = timeStr;

        const heading = document.createElement('h3');
        heading.style.cssText = 'margin: 0; font-size: 0.9rem; font-weight: 700; color: #0f172a;';
        heading.textContent = `${complaintRef} · Paanyaya Invitation`;

        headerRow.append(dragHandle, seqBadge, timeBadge, heading);

        const inviteeRow = document.createElement('div');
        inviteeRow.style.cssText = 'font-size: 0.83rem; color: #1e293b; margin-bottom: 4px; line-height: 1.4;';
        inviteeRow.innerHTML = `<span style="color: #166534; font-weight: 700;">Nagpaanyaya:</span> <strong>${escapeHtml(complainant)}</strong> <span style="color: #94a3b8; margin: 0 4px;">•</span> <span style="color: #4f46e5; font-weight: 700;">Inanyayahan:</span> <strong>${escapeHtml(invitee)}</strong>`;

        const detailsRow = document.createElement('p');
        detailsRow.style.cssText = 'font-size: 0.76rem; color: #64748b; margin: 0;';
        detailsRow.innerHTML = `<span>${escapeHtml(item.venue || 'Tanggapan ng Barangay Tumana')}</span>${item.complaint_title ? ` • <span>${escapeHtml(item.complaint_title)}</span>` : ''}`;

        content.append(headerRow, inviteeRow, detailsRow);
        art.appendChild(content);

        const actions = document.createElement('div');
        actions.style.cssText = 'display: flex; align-items: center; gap: 8px; flex-shrink: 0;';

        const statusPill = document.createElement('span');
        statusPill.style.cssText = 'font-size: 0.72rem; font-weight: 700; padding: 3px 9px; border-radius: 9999px; background: #e0e7ff; color: #3730a3; white-space: nowrap;';
        statusPill.textContent = item.status || 'Scheduled';

        const viewLink = document.createElement('a');
        viewLink.className = 'btn-open-case';
        viewLink.style.cssText = 'padding: 5px 12px; font-size: 11.5px; font-weight: 600;';
        viewLink.textContent = 'Paanyaya';
        viewLink.href = item.complaint_id ? `../complaints/paanyaya-workspace.php?complaint_id=${encodeURIComponent(item.complaint_id)}` : '../paanyaya/index.php';

        actions.append(statusPill, viewLink);
        art.appendChild(actions);
        bindQueueDragAndDrop(art, container);
        container.appendChild(art);
    };

    const renderPaanyayas = (paanyayas) => {
        const container = document.getElementById('todayPaanyayas');
        if (!container) return;
        container.replaceChildren();
        const items = (paanyayas || []).filter(isActiveQueueSession);
        if (!items.length) {
            return container.appendChild(emptyState('No active Paanyaya invitations scheduled in this timeframe.'));
        }
        items.slice(0, 10).forEach((item, idx) => addPaanyayaItem(container, item, idx));
    };

    // =========================================================================
    // Punong Barangay Unavailable & Quick Attendance
    // =========================================================================
    const openPbChoiceModal = (hearingsList, onConfirm) => {
        let modal = document.getElementById('agapPbChoiceModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'agapPbChoiceModal';
            modal.className = 'pb-choice-modal-overlay';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.innerHTML = `
                <div class="pb-choice-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <h3 style="margin:0; font-size:1.05rem; color:#102b2d;">Punong Barangay Unavailable</h3>
                        <button type="button" id="pbChoiceCloseBtn" style="border:none;background:transparent;font-size:22px;cursor:pointer;color:#64748b;">&times;</button>
                    </div>
                    <p id="pbChoiceSummary" style="margin:0 0 14px; font-size:0.86rem; color:#475569; line-height:1.45;"></p>
                    <button type="button" class="pb-choice-option" id="pbBtnAfternoonOnly">
                        <strong style="display:block; color:#102b2d; font-size:0.9rem;">Reschedule Whole Afternoon Only (1:00–5:00 PM)</strong>
                        <span style="display:block; font-size:0.78rem; color:#64748b; margin-top:2px;">Pinananatili ang morning hearings; ililipat ang pang-hapon sa susunod na Martes at gagawa ng KP Form 8 at 9.</span>
                    </button>
                    <button type="button" class="pb-choice-option" id="pbBtnWholeDay">
                        <strong style="display:block; color:#102b2d; font-size:0.9rem;">Reschedule Entire Day (All Active Today)</strong>
                        <span style="display:block; font-size:0.78rem; color:#64748b; margin-top:2px;">Ililipat ang lahat ng aktibong hearing ngayong araw patungo sa susunod na Martes at gagawa ng KP Form 8 at 9.</span>
                    </button>
                    <div style="text-align:right; margin-top:8px;">
                        <button type="button" class="btn-secondary" id="pbChoiceCancelBtn">Cancel</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.querySelector('#pbChoiceCloseBtn').onclick = () => { modal.style.display = 'none'; };
            modal.querySelector('#pbChoiceCancelBtn').onclick = () => { modal.style.display = 'none'; };
            modal.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
        }

        const totalCount = hearingsList.length;
        const afternoonCount = hearingsList.filter((h) => {
            const m = String(h.hearing_date || '').match(/\s(\d{2}):/);
            return m ? parseInt(m[1], 10) >= 12 : true;
        }).length;

        modal.querySelector('#pbChoiceSummary').textContent = `Mayroong ${totalCount} aktibong hearing ngayong araw (${afternoonCount} sa hapon 1:00–5:00 PM). Piliin kung pang-hapon lang o buong araw ang ililipat sa susunod na Martes (awtomatikong mag-iisyu ng KP Form 8 at 9):`;
        modal.querySelector('#pbBtnAfternoonOnly').onclick = () => { modal.style.display = 'none'; onConfirm('afternoon'); };
        modal.querySelector('#pbBtnWholeDay').onclick = () => { modal.style.display = 'none'; onConfirm('all'); };
        modal.style.display = 'flex';
    };

    window.triggerPbUnavailableBatch = () => {
        const activeHearings = (state.hearings || []).filter(isActiveQueueSession);
        openPbChoiceModal(activeHearings, async (slotScope) => {
            try {
                const res = await fetch('../../../backend/api/hearings/batch-reschedule.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        target_date: getTodayDateStr(),
                        slot_scope: slotScope,
                        reason: 'Punong Barangay Unavailable'
                    })
                });
                const data = await res.json().catch(() => ({}));
                if (data.success) {
                    alert(data.message || 'Matagumpay na nai-reschedule ang mga hearing patungo sa susunod na Martes!');
                    fetchDashboardData();
                } else {
                    alert(data.message || 'Nabigong i-reschedule ang mga hearing.');
                }
            } catch (err) {
                console.error(err);
                alert('May naganap na error sa pagpoproseso ng batch reschedule.');
            }
        });
    };

    const handleStartHearing = async (hearingId, caseNumber) => {
        if (!confirm(`Simulan na ba ang sesyon para sa ${caseNumber ? `Case #${caseNumber}` : 'ang kasong ito'}?`)) return;
        try {
            const res = await fetch('../../../backend/api/hearings/queue.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                body: JSON.stringify({ action: 'start_session', hearing_id: hearingId })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                alert(data.message || 'Hindi masimulan ang sesyon.');
                return;
            }
            alert(data.message || 'Nagsimula na ang sesyon sa Lupon Court Room.');
            fetchDashboardData();
        } catch (err) {
            alert('May naganap na error sa pagsisimula ng sesyon.');
        }
    };

    const handleToggleStandby = async (hearingId, isCurrentlyStandby) => {
        const actionLabel = isCurrentlyStandby ? 'ibalik mula sa Standby' : 'ilagay sa Standby';
        if (!confirm(`Nais mo bang ${actionLabel} ang pagdinig na ito?`)) return;
        try {
            const res = await fetch('../../../backend/api/hearings/queue.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                body: JSON.stringify({ action: 'standby', hearing_id: hearingId, standby: isCurrentlyStandby ? 0 : 1 })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                alert(data.message || 'Hindi ma-update ang standby status.');
                return;
            }
            fetchDashboardData();
        } catch (err) {
            alert('May naganap na error sa pag-update ng standby status.');
        }
    };

    const checkDashboardCompAbsence = () => {
        const compRadios = document.querySelectorAll('input[name^="quick_att_party_"][data-party-type="Complainant"]:checked');
        let anyAbsent = false;
        compRadios.forEach((r) => { if (r.value === 'Absent') anyAbsent = true; });
        const notice = document.getElementById('quickCompAbsentNotice');
        if (notice) notice.style.display = anyAbsent ? 'block' : 'none';
    };

    const renderQuickAttendanceParties = (parties, defaultCompAtt = 'Present', defaultRespAtt = 'Present') => {
        const container = document.getElementById('quickAttPartiesContainer');
        if (!container) return;
        if (!parties || parties.length === 0) {
            container.innerHTML = '<div style="color: #64748b; font-size: 0.85rem; padding: 12px; text-align: center;">Walang nakatalang panig para sa pagdinig na ito.</div>';
            return;
        }
        container.innerHTML = parties.map((p) => {
            const isComp = (p.party_type === 'Complainant');
            let st = p.attendance_status;
            if (!st || st === 'Pending' || st === 'Not Recorded') st = isComp ? defaultCompAtt : defaultRespAtt;
            if (st !== 'Late' && st !== 'Absent') st = 'Present';
            const roleBadgeStyle = isComp ? 'background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;' : 'background: #fdf2f8; color: #be185d; border: 1px solid #fbcfe8;';
            const roleLabel = isComp ? 'Nagpaanyaya (Complainant)' : 'Inanyayahan (Respondent)';
            const partyName = p.resident_name || p.full_name || (isComp ? 'Complainant' : 'Respondent');
            return `
                <div class="quick-att-party-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                        <div style="min-width: 0;">
                            <div style="font-size: 0.94rem; font-weight: 700; color: #0f172a;">${escapeHtml(partyName)}</div>
                            ${p.address ? `<div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">${escapeHtml(p.address)}</div>` : ''}
                        </div>
                        <span style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; white-space: nowrap; ${roleBadgeStyle}">${roleLabel}</span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 7px; font-size: 0.84rem;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 6px 10px; border-radius: 6px; background: #f0fdf4; border: 1px solid #bbf7d0;">
                            <input type="radio" name="quick_att_party_${p.resident_id}" data-party-type="${escapeHtml(p.party_type)}" value="Present" ${st === 'Present' ? 'checked' : ''} style="accent-color: #16a34a; width: 15px; height: 15px; margin: 0;">
                            <span style="color: #15803d; font-weight: 600;">Dumalo sa Oras (Present)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 6px 10px; border-radius: 6px; background: #fffbeb; border: 1px solid #fde68a;">
                            <input type="radio" name="quick_att_party_${p.resident_id}" data-party-type="${escapeHtml(p.party_type)}" value="Late" ${st === 'Late' ? 'checked' : ''} style="accent-color: #d97706; width: 15px; height: 15px; margin: 0;">
                            <span style="color: #b45309; font-weight: 600;">Nahuli / Late (Pasok sa 1-hour grace period)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 6px 10px; border-radius: 6px; background: #fef2f2; border: 1px solid #fecaca;">
                            <input type="radio" name="quick_att_party_${p.resident_id}" data-party-type="${escapeHtml(p.party_type)}" value="Absent" ${st === 'Absent' ? 'checked' : ''} style="accent-color: #dc2626; width: 15px; height: 15px; margin: 0;">
                            <span style="color: #991b1b; font-weight: 600;">Hindi Dumalo (Absent - Lampas 1 oras)</span>
                        </label>
                    </div>
                </div>
            `;
        }).join('');
        container.querySelectorAll('input[type="radio"]').forEach((r) => r.addEventListener('change', checkDashboardCompAbsence));
        checkDashboardCompAbsence();
    };

    const openQuickAttendanceModal = async (hearing) => {
        const modal = document.getElementById('quickDashboardAttendanceModal');
        if (!modal) return;
        state.activeQuickAttHearing = hearing;
        state.activeQuickAttHearingId = hearing.hearing_id;
        state.activeQuickAttData = null;

        setElText('quickAttCaseNumBadge', hearing.case_number ? `Case #${hearing.case_number}` : 'Case');
        setElText('quickAttHearingType', `${hearing.hearing_type || 'Hearing'}${hearing.mediation_attempt ? ' ' + hearing.mediation_attempt : ''}`);
        setElText('quickAttHearingTime', formatTimeOnly(hearing.hearing_date));
        setElText('quickAttHearingVenue', hearing.venue || 'Lupon Court Room');
        const remarksEl = document.getElementById('quickAttRemarks');
        if (remarksEl) remarksEl.value = hearing.attendance_notes || '';

        const container = document.getElementById('quickAttPartiesContainer');
        if (container) container.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b; font-size: 0.85rem;">Kinukuha ang talaan ng mga panig...</div>';
        const notice = document.getElementById('quickCompAbsentNotice');
        if (notice) notice.style.display = 'none';
        modal.style.display = 'flex';

        const compVal = hearing.complainant_attendance || 'Present';
        const respVal = hearing.respondent_attendance || 'Present';

        try {
            const res = await fetch(`../../../backend/api/hearings/attendance.php?hearing_id=${encodeURIComponent(hearing.hearing_id)}`);
            const json = await res.json();
            if (json.success && json.data) {
                state.activeQuickAttData = json.data;
                renderQuickAttendanceParties(json.data.parties || [], compVal, respVal);
            } else {
                throw new Error(json.message || 'Failed to fetch parties');
            }
        } catch (err) {
            const fallbackParties = [];
            let fakeId = 1;
            (hearing.complainant_names || 'Complainant').split(',').map((s) => s.trim()).filter(Boolean).forEach((name) => {
                fallbackParties.push({ resident_id: fakeId++, party_type: 'Complainant', resident_name: name, attendance_status: compVal });
            });
            (hearing.respondent_names || 'Respondent').split(',').map((s) => s.trim()).filter(Boolean).forEach((name) => {
                fallbackParties.push({ resident_id: fakeId++, party_type: 'Respondent', resident_name: name, attendance_status: respVal });
            });
            renderQuickAttendanceParties(fallbackParties, compVal, respVal);
        }
    };

    const closeQuickAttendanceModal = () => {
        const modal = document.getElementById('quickDashboardAttendanceModal');
        if (modal) modal.style.display = 'none';
        state.activeQuickAttHearing = null;
        state.activeQuickAttHearingId = null;
        state.activeQuickAttData = null;
    };

    const submitQuickAttendance = async () => {
        const hearingId = state.activeQuickAttHearingId;
        if (!hearingId) return;
        const remarks = (document.getElementById('quickAttRemarks')?.value || '').trim();
        const parties = state.activeQuickAttData?.parties || [];
        let partyRecords = [];
        let compOverall = 'Present';
        let respOverall = 'Present';

        if (parties.length > 0) {
            partyRecords = parties.map((p) => {
                const checked = document.querySelector(`input[name="quick_att_party_${p.resident_id}"]:checked`);
                const st = checked ? checked.value : (p.attendance_status || 'Present');
                const isPresentOrLate = (st === 'Present' || st === 'Late');
                return {
                    resident_id: p.resident_id,
                    party_type: p.party_type,
                    attendance_status: st,
                    is_justified: isPresentOrLate ? 0 : (remarks ? 1 : 0),
                    justification_reason: isPresentOrLate ? null : (remarks || null),
                    verification_status: isPresentOrLate ? 'Verified Served' : 'Pending'
                };
            });
            const compStatuses = partyRecords.filter((p) => p.party_type === 'Complainant').map((p) => p.attendance_status);
            const respStatuses = partyRecords.filter((p) => p.party_type === 'Respondent').map((p) => p.attendance_status);
            if (compStatuses.length > 0) compOverall = compStatuses.every((s) => s === 'Absent') ? 'Absent' : (compStatuses.some((s) => s === 'Late') ? 'Late' : 'Present');
            if (respStatuses.length > 0) respOverall = respStatuses.every((s) => s === 'Absent') ? 'Absent' : (respStatuses.some((s) => s === 'Late') ? 'Late' : 'Present');
        }

        const saveBtn = document.getElementById('quickAttSaveBtn');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Isinesave...'; }
        try {
            const res = await fetch('../../../backend/api/hearings/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                body: JSON.stringify({
                    hearing_id: hearingId,
                    complainant_attendance: compOverall,
                    respondent_attendance: respOverall,
                    attendance_notes: remarks,
                    session_remarks: remarks,
                    remarks,
                    parties: partyRecords
                })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                alert(data.message || 'Hindi maitala ang attendance.');
                return;
            }
            closeQuickAttendanceModal();
            alert('Matagumpay na naitala ang attendance!');
            fetchDashboardData();
        } catch (err) {
            alert('May naganap na error sa pag-save ng attendance.');
        } finally {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'I-save ang Attendance'; }
        }
    };

    const initQuickAttendanceModal = () => {
        document.getElementById('quickAttCloseBtn')?.addEventListener('click', closeQuickAttendanceModal);
        document.getElementById('quickAttCancelBtn')?.addEventListener('click', closeQuickAttendanceModal);
        document.getElementById('quickAttSaveBtn')?.addEventListener('click', submitQuickAttendance);
        const modal = document.getElementById('quickDashboardAttendanceModal');
        modal?.addEventListener('click', (e) => { if (e.target === modal) closeQuickAttendanceModal(); });
    };

    const addHearingItem = (container, hearing, displayIndex = 0) => {
        const item = document.createElement('article');
        item.className = 'dashboard-list-item clerk-agenda-item';
        item.dataset.index = String(displayIndex);
        item.dataset.id = hearing.hearing_id;
        item.dataset.type = 'hearing';
        item.dataset.queueDate = String(hearing.hearing_date || getTodayDateStr()).slice(0, 10);
        item.style.cssText = 'padding: 13px 14px; border: 1px solid #eef2f6; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-radius: 10px; margin-bottom: 8px; background: #ffffff;';

        bindQueueDragAndDrop(item, container);

        const content = document.createElement('div');
        content.className = 'dashboard-list-content';
        content.style.flex = '1';

        const scheduledTimeStr = formatTimeOnly(hearing.hearing_date) || 'Scheduled';
        const hearingTypeStr = `${hearing.hearing_type || 'Hearing'}${hearing.mediation_attempt ? ' ' + hearing.mediation_attempt : ''}`;
        const caseNumberStr = hearing.case_number ? `Case #${hearing.case_number}` : 'Case';

        const headerRow = document.createElement('div');
        headerRow.style.cssText = 'display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;';

        const dragHandle = document.createElement('span');
        dragHandle.className = 'drag-handle';
        dragHandle.title = 'I-drag para baguhin ang docket sequence';
        dragHandle.innerHTML = '&#8942;&#8942;';

        const seqBadge = document.createElement('span');
        seqBadge.className = 'queue-seq-badge';
        seqBadge.textContent = `#${displayIndex + 1}`;

        const timeBadge = document.createElement('span');
        timeBadge.className = 'agenda-time-pill';
        timeBadge.style.cssText = 'font-size: 0.76rem; font-weight: 700; color: #0f172a; background: #e2e8f0; padding: 2px 8px; border-radius: 5px; white-space: nowrap;';
        timeBadge.textContent = scheduledTimeStr;

        const heading = document.createElement('h3');
        heading.style.cssText = 'margin: 0; font-size: 0.9rem; font-weight: 700; color: #0f172a;';
        heading.textContent = `${caseNumberStr} · ${hearingTypeStr}`;

        headerRow.append(dragHandle, seqBadge, timeBadge, heading);

        const partiesRow = document.createElement('div');
        partiesRow.style.cssText = 'font-size: 0.83rem; color: #1e293b; margin-bottom: 4px;';
        partiesRow.innerHTML = `<span style="color: #166534; font-weight: 700;">(C)</span> ${escapeHtml(hearing.complainant_names || 'Complainant')} <span style="color: #94a3b8; margin: 0 4px;">vs.</span> <span style="color: #991b1b; font-weight: 700;">(R)</span> ${escapeHtml(hearing.respondent_names || 'Respondent')}`;

        const venueRow = document.createElement('p');
        venueRow.style.cssText = 'font-size: 0.76rem; color: #64748b; margin: 0;';
        venueRow.innerHTML = `<span>${escapeHtml(hearing.venue || 'Lupon Court Room')}</span>${hearing.complaint_title ? ` • <span>${escapeHtml(hearing.complaint_title)}</span>` : ''}`;

        content.append(headerRow, partiesRow, venueRow);
        item.appendChild(content);

        const actions = document.createElement('div');
        actions.className = 'hearing-card-actions';
        actions.style.cssText = 'display: flex; align-items: center; gap: 7px; flex-wrap: wrap; justify-content: flex-end;';

        const normQueue = (hearing.queue_status || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
        const normStatus = (hearing.status || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
        const isConcluded = normQueue === 'concluded' || normStatus === 'concluded' || normStatus === 'completed';
        const isInSession = normQueue === 'in_session';
        const isStandby = normQueue === 'standby' || normQueue === 'on_standby';
        const compCheckedIn = ['present', 'late'].includes((hearing.complainant_attendance || '').toLowerCase());
        const respCheckedIn = ['present', 'late'].includes((hearing.respondent_attendance || '').toLowerCase());
        const isCompleteAndReady = (compCheckedIn && respCheckedIn) || normQueue === 'ready' || normQueue === 'ready_for_hearing';

        const hDate = new Date(hearing.hearing_date ? String(hearing.hearing_date).replace(' ', 'T') : '');
        const nowD = new Date();
        const diffMinutes = (!Number.isNaN(hDate.getTime()) && nowD > hDate) ? Math.floor((nowD - hDate) / 60000) : 0;

        let badgeBg = '#f1f5f9', badgeColor = '#475569', badgeBorder = '#cbd5e1', badgeLabel = 'Scheduled';
        if (isInSession) {
            badgeBg = '#dbeafe'; badgeColor = '#1e40af'; badgeBorder = '#93c5fd';
            const startFormatted = formatTimeOnly(hearing.actual_start_time);
            badgeLabel = startFormatted ? `In Session (${startFormatted})` : 'In Session';
        } else if (isConcluded) {
            badgeBg = '#f3e8ff'; badgeColor = '#7e22ce'; badgeBorder = '#d8b4fe';
            badgeLabel = 'Concluded';
        } else if (isStandby) {
            badgeBg = '#fef3c7'; badgeColor = '#b45309'; badgeBorder = '#fde68a';
            badgeLabel = 'On Standby';
        } else if (isCompleteAndReady) {
            badgeBg = '#dcfce7'; badgeColor = '#15803d'; badgeBorder = '#86efac';
            badgeLabel = 'Complete & Ready';
        } else if (diffMinutes >= 60) {
            badgeBg = '#fee2e2'; badgeColor = '#991b1b'; badgeBorder = '#fca5a5';
            badgeLabel = `1-Hour Expired (${diffMinutes}m late)`;
        } else if (diffMinutes > 0) {
            badgeBg = '#fef3c7'; badgeColor = '#b45309'; badgeBorder = '#fde68a';
            badgeLabel = `Grace Period (${60 - diffMinutes}m left)`;
        }

        const statusPill = document.createElement('span');
        statusPill.className = 'hearing-status-pill';
        statusPill.style.cssText = `font-size: 0.72rem; font-weight: 700; padding: 3px 9px; border-radius: 9999px; background: ${badgeBg}; color: ${badgeColor}; border: 1px solid ${badgeBorder}; white-space: nowrap;`;
        statusPill.textContent = badgeLabel;
        actions.appendChild(statusPill);

        if (isCompleteAndReady && !isInSession && !isConcluded) {
            const startBtn = document.createElement('button');
            startBtn.type = 'button';
            startBtn.className = 'btn-action-start-hearing';
            startBtn.textContent = '▶ Start Session';
            startBtn.addEventListener('click', () => handleStartHearing(hearing.hearing_id, hearing.case_number));
            actions.appendChild(startBtn);
        }

        if (!isConcluded && !isInSession) {
            const standbyBtn = document.createElement('button');
            standbyBtn.type = 'button';
            standbyBtn.className = 'btn-action-standby-hearing';
            if (isStandby) {
                standbyBtn.style.cssText = 'background: #fffbeb; color: #b45309; border: 1.5px solid #b45309; padding: 5px 10px; font-weight: 700;';
                standbyBtn.textContent = '▶ Resume';
            } else {
                standbyBtn.style.cssText = 'background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; padding: 5px 10px; font-weight: 600;';
                standbyBtn.textContent = '⏸ Standby';
            }
            standbyBtn.addEventListener('click', () => handleToggleStandby(hearing.hearing_id, isStandby));
            actions.appendChild(standbyBtn);
        }

        if (!isConcluded) {
            const attBtn = document.createElement('button');
            attBtn.type = 'button';
            attBtn.className = 'btn-action-quick-att';
            attBtn.textContent = 'Quick Attendance';
            attBtn.addEventListener('click', () => openQuickAttendanceModal(hearing));
            actions.appendChild(attBtn);
        }

        const openCaseBtn = document.createElement('a');
        openCaseBtn.className = 'btn-open-case';
        openCaseBtn.textContent = 'Open Case';
        openCaseBtn.href = hearing.case_id
            ? `../cases/case-details.php?id=${encodeURIComponent(hearing.case_id)}`
            : (hearing.complaint_id ? `../complaints/complaint-details.php?id=${encodeURIComponent(hearing.complaint_id)}` : '#');
        actions.appendChild(openCaseBtn);

        item.appendChild(actions);
        container.appendChild(item);
    };

    const renderHearingsPage = () => {
        const container = document.getElementById('todayHearings');
        const paginationWrap = document.getElementById('hearingsTablePagination');
        const infoEl = document.getElementById('hearingsPaginationInfo');
        const prevBtn = document.getElementById('hearingsPrevPageBtn');
        const nextBtn = document.getElementById('hearingsNextPageBtn');
        const pageNumbersContainer = document.getElementById('hearingsPageNumbers');
        if (!container) return;

        container.replaceChildren();
        const totalHearings = (state.hearings || []).length;
        if (!totalHearings) {
            if (paginationWrap) paginationWrap.style.display = 'none';
            const todayStr = getTodayDateStr();
            const emptyLabel = state.selectedDate
                ? (state.selectedDate === todayStr ? 'today' : (formatDateOnly(state.selectedDate) || 'this date'))
                : (state.period === 'all' ? 'today' : (state.hearingsPeriodLabel || 'this timeframe'));
            return container.appendChild(emptyState(`No active hearings scheduled for ${emptyLabel}.`));
        }

        const totalPages = Math.max(1, Math.ceil(totalHearings / state.hearingsPerPage));
        if (state.hearingsPage > totalPages) state.hearingsPage = totalPages;
        if (state.hearingsPage < 1) state.hearingsPage = 1;

        const startIndex = (state.hearingsPage - 1) * state.hearingsPerPage;
        const endIndex = Math.min(startIndex + state.hearingsPerPage, totalHearings);
        state.hearings.slice(startIndex, endIndex).forEach((hearing, idx) => addHearingItem(container, hearing, startIndex + idx));

        if (paginationWrap) paginationWrap.style.display = totalPages > 1 ? 'flex' : 'none';
        if (infoEl) infoEl.textContent = `Showing ${startIndex + 1} to ${endIndex} of ${totalHearings} hearing${totalHearings === 1 ? '' : 's'}`;
        if (prevBtn) prevBtn.disabled = state.hearingsPage <= 1;
        if (nextBtn) nextBtn.disabled = state.hearingsPage >= totalPages;
        if (pageNumbersContainer) {
            pageNumbersContainer.replaceChildren();
            if (totalPages > 1) {
                for (let i = 1; i <= totalPages; i++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `page-num-btn ${i === state.hearingsPage ? 'is-active' : ''}`;
                    btn.textContent = String(i);
                    btn.addEventListener('click', () => { state.hearingsPage = i; renderHearingsPage(); });
                    pageNumbersContainer.appendChild(btn);
                }
            }
        }
    };

    const renderHearings = (hearings, periodLabel) => {
        state.hearings = [...(hearings || []).filter(isActiveQueueSession)].sort((a, b) => new Date(a.hearing_date || 0) - new Date(b.hearing_date || 0));
        state.hearingsPage = 1;
        state.hearingsPeriodLabel = periodLabel;

        const heading = document.getElementById('hearingsPanelHeading');
        const sub = document.getElementById('hearingsPanelSub');
        const countBadge = document.getElementById('periodHearingCountBadge');

        if (state.period === 'all') {
            state.selectedDate = getTodayDateStr();
            if (heading) heading.textContent = "Today's Hearings";
            if (sub) sub.textContent = 'Scheduled formal sessions & Lupon Court Room queue (drag to reorder)';
        } else {
            state.selectedDate = null;
            if (heading) heading.textContent = `Hearings (${periodLabel})`;
            if (sub) sub.textContent = 'Sessions scheduled within this timeframe';
        }

        if (countBadge) {
            const count = state.hearings.length;
            if (count > 0) {
                countBadge.textContent = `${count} hearing${count === 1 ? '' : 's'}`;
                countBadge.style.display = 'inline-block';
            } else {
                countBadge.style.display = 'none';
            }
        }
        renderHearingsPage();
    };

    // =========================================================================
    // Calendar & Full Calendar Modal
    // =========================================================================
    const selectCalendarDate = (dateStr) => {
        state.selectedDate = dateStr;
        const todayStr = getTodayDateStr();
        const isToday = (dateStr === todayStr);
        const matchingHearings = (state.calendarHearings || []).filter((h) => isActiveQueueSession(h) && getHearingDateKey(h.hearing_date) === dateStr);

        const formattedDate = formatDateOnly(dateStr);
        setElText('hearingsPanelHeading', isToday ? "Today's Hearings" : `Hearings (${formattedDate})`);
        setElText('hearingsPanelSub', isToday ? 'Sessions scheduled for today.' : `Sessions scheduled for ${formattedDate}.`);

        const countBadge = document.getElementById('periodHearingCountBadge');
        if (countBadge) {
            countBadge.textContent = `${matchingHearings.length} hearing${matchingHearings.length === 1 ? '' : 's'}`;
            countBadge.style.display = matchingHearings.length > 0 ? 'inline-block' : 'none';
        }

        state.hearings = matchingHearings.sort((a, b) => new Date(a.hearing_date || 0) - new Date(b.hearing_date || 0));
        state.hearingsPage = 1;
        state.hearingsPeriodLabel = isToday ? 'today' : formattedDate;
        renderHearingsPage();

        document.querySelectorAll('.dash-cal-day').forEach((cell) => {
            cell.classList.toggle('is-selected', cell.dataset.date === dateStr);
        });
    };

    const renderDashboardCalendar = () => {
        const grid = document.getElementById('dashboardCalendarGrid');
        const monthDisplay = document.getElementById('dashCalMonthDisplay');
        if (!grid) return;

        const cursor = state.calendarCursor;
        const year = cursor.getFullYear();
        const month = cursor.getMonth();
        if (monthDisplay) monthDisplay.textContent = cursor.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
        grid.replaceChildren();

        ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach((day) => {
            const header = document.createElement('div');
            header.className = 'dash-cal-weekday';
            header.textContent = day;
            grid.appendChild(header);
        });

        const todayStr = getTodayDateStr();
        const firstDayIdx = new Date(year, month, 1).getDay();
        const totalDays = new Date(year, month + 1, 0).getDate();
        const prevMonthTotalDays = new Date(year, month, 0).getDate();

        const hearingsByDate = {};
        (state.calendarHearings || []).forEach((h) => {
            const datePart = getHearingDateKey(h.hearing_date);
            if (!datePart) return;
            if (!hearingsByDate[datePart]) hearingsByDate[datePart] = [];
            hearingsByDate[datePart].push(h);
        });

        for (let i = firstDayIdx - 1; i >= 0; i--) {
            const prevDayNum = prevMonthTotalDays - i;
            const prevMonthDate = new Date(year, month - 1, prevDayNum);
            const prevDateStr = `${prevMonthDate.getFullYear()}-${String(prevMonthDate.getMonth() + 1).padStart(2, '0')}-${String(prevMonthDate.getDate()).padStart(2, '0')}`;
            const cell = document.createElement('div');
            cell.className = 'dash-cal-day is-other-month';
            cell.dataset.date = prevDateStr;
            cell.textContent = String(prevDayNum);
            cell.addEventListener('click', () => {
                state.calendarCursor = new Date(year, month - 1, 1);
                renderDashboardCalendar();
                selectCalendarDate(prevDateStr);
            });
            grid.appendChild(cell);
        }

        for (let day = 1; day <= totalDays; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const cell = document.createElement('div');
            cell.className = 'dash-cal-day';
            cell.dataset.date = dateStr;
            const dayNum = document.createElement('span');
            dayNum.textContent = String(day);
            cell.appendChild(dayNum);

            if (dateStr === todayStr) cell.classList.add('is-today');
            if (dateStr === state.selectedDate) cell.classList.add('is-selected');

            const dayHearings = hearingsByDate[dateStr] || [];
            if (dayHearings.length > 0) {
                cell.classList.add('has-hearing');
                const dot = document.createElement('span');
                dot.className = 'dash-cal-hearing-dot';
                cell.appendChild(dot);
                cell.title = `${dayHearings.length} session(s) scheduled — click to view`;
            }
            cell.addEventListener('click', () => selectCalendarDate(dateStr));
            grid.appendChild(cell);
        }

        const remaining = (7 - ((firstDayIdx + totalDays) % 7)) % 7;
        for (let nextDay = 1; nextDay <= remaining; nextDay++) {
            const nextMonthDate = new Date(year, month + 1, nextDay);
            const nextDateStr = `${nextMonthDate.getFullYear()}-${String(nextMonthDate.getMonth() + 1).padStart(2, '0')}-${String(nextMonthDate.getDate()).padStart(2, '0')}`;
            const cell = document.createElement('div');
            cell.className = 'dash-cal-day is-other-month';
            cell.dataset.date = nextDateStr;
            cell.textContent = String(nextDay);
            cell.addEventListener('click', () => {
                state.calendarCursor = new Date(year, month + 1, 1);
                renderDashboardCalendar();
                selectCalendarDate(nextDateStr);
            });
            grid.appendChild(cell);
        }
    };

    const renderFullCalendarSummary = () => {
        const content = document.getElementById('dashboardFullCalendarContent');
        const subtitle = document.getElementById('dashboardFullCalendarSubtitle');
        if (!content) return;
        content.replaceChildren();

        const hearings = (state.calendarHearings || [])
            .filter(isActiveQueueSession)
            .filter((h) => getHearingDateKey(h.hearing_date))
            .sort((a, b) => new Date(String(a.hearing_date).replace(' ', 'T')) - new Date(String(b.hearing_date).replace(' ', 'T')));

        if (subtitle) subtitle.textContent = `${hearings.length} scheduled session${hearings.length === 1 ? '' : 's'}`;
        if (!hearings.length) {
            content.appendChild(emptyState('No active hearings or Paanyaya sessions are scheduled.'));
            return;
        }

        const byDate = new Map();
        hearings.forEach((h) => {
            const k = getHearingDateKey(h.hearing_date);
            if (!byDate.has(k)) byDate.set(k, []);
            byDate.get(k).push(h);
        });

        byDate.forEach((dayHearings, dateKey) => {
            const section = document.createElement('section');
            section.style.cssText = 'margin-bottom:16px;';
            const heading = document.createElement('h3');
            heading.style.cssText = 'margin:0 0 8px; font-size:.92rem; color:#0f172a;';
            heading.textContent = formatDateOnly(dateKey);
            section.appendChild(heading);

            dayHearings.forEach((h) => {
                const card = document.createElement('article');
                card.style.cssText = 'padding:11px 12px; border:1px solid #e2e8f0; border-radius:8px; background:#fff; margin-top:7px;';
                card.innerHTML = `<strong style="display:block; font-size:.87rem; color:#0f172a;">${escapeHtml(h.case_number ? `Case ${h.case_number}` : 'Case')} · ${escapeHtml(h.hearing_type || 'Hearing')}</strong><div style="font-size:.8rem; color:#64748b; margin-top:4px;">${escapeHtml(formatTimeOnly(h.hearing_date))} · ${escapeHtml(h.venue || 'Lupon Court Room')}</div>`;
                section.appendChild(card);
            });
            content.appendChild(section);
        });
    };

    // =========================================================================
    // Settlement Details Modal ("Show details" on Gauge)
    // =========================================================================
    const openSettlementDetailsModal = () => {
        const modal = document.getElementById('settlementDetailsModal');
        const body = document.getElementById('settlementDetailsBody');
        if (!modal || !body) return;
        const d = state.dashboardData || {};
        const cap = d.captain_summary || {};
        body.innerHTML = `
            <div class="report-summary" style="margin-bottom:16px;">
                <div class="summary-card"><strong>${Number(d.settled_cases || 0)}</strong><span>Formal Amicable Settlements</span></div>
                <div class="summary-card"><strong>${Number(cap.paanyaya_resolved || 0)}</strong><span>Resolved at Paanyaya Stage 0</span></div>
                <div class="summary-card"><strong>${Number(cap.overall_resolution_rate_pct || 0)}%</strong><span>Overall Resolution Rate</span></div>
                <div class="summary-card"><strong>₱${Number(cap.ltia_estimated_savings || 0).toLocaleString()}</strong><span>Est. LTIA Court Savings</span></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px;">
                    <div style="font-size:0.78rem; color:#64748b; font-weight:700;">PB Attestation Status</div>
                    <div style="font-size:1.1rem; font-weight:800; color:#0f172a; margin-top:4px;">${Number(cap.pending_pb_attestations || 0)} awaiting PB attestation</div>
                </div>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px;">
                    <div style="font-size:0.78rem; color:#64748b; font-weight:700;">10-Day MTC / DILG Transmittals</div>
                    <div style="font-size:1.1rem; font-weight:800; color:#0f172a; margin-top:4px;">${Number(cap.pending_transmittals || 0)} pending transmittal</div>
                </div>
            </div>
            <div style="margin-top:16px; display:flex; gap:10px; justify-content:flex-end;">
                <a href="../documents/settlements.php" class="pb-row-action-btn" style="padding:8px 14px;">Open Amicable Settlements</a>
                <a href="../reports/report-list.php" class="pb-row-action-btn" style="background:#1b6553; padding:8px 14px;">LTIA &amp; Transmittals</a>
            </div>
        `;
        modal.style.display = 'flex';
    };

    // =========================================================================
    // Main Fetch & Period Controls
    // =========================================================================
    const updateControls = (period, data) => {
        const navButtons = document.getElementById('periodNavButtons');
        const labelDisplay = document.getElementById('periodLabelDisplay');
        const rangeDisplay = document.getElementById('periodDateRangeDisplay');
        const currentBtnText = document.getElementById('currentPeriodText');

        if (labelDisplay) labelDisplay.textContent = data.period_label || 'All Time';
        if (rangeDisplay) {
            rangeDisplay.textContent = (data.start_date && data.end_date)
                ? `${formatDateOnly(data.start_date)} – ${formatDateOnly(data.end_date)}`
                : (data.period_sublabel || 'All recorded data in the system');
        }
        if (navButtons) navButtons.style.display = period === 'all' ? 'none' : 'inline-flex';
        if (currentBtnText) {
            currentBtnText.textContent = { weekly: 'This Week', monthly: 'This Month', quarterly: 'This Quarter', annual: 'This Year' }[period] || 'Current';
        }
        document.getElementById('currentPeriodBtn')?.classList.toggle('is-current-active', state.offset === 0);
    };

    const updateUrl = () => {
        try {
            const url = new URL(window.location);
            if (state.period === 'all') {
                url.searchParams.delete('period');
                url.searchParams.delete('offset');
            } else {
                url.searchParams.set('period', state.period);
                if (state.offset !== 0) url.searchParams.set('offset', String(state.offset));
                else url.searchParams.delete('offset');
            }
            window.history.replaceState({}, '', url);
        } catch (e) {}
    };

    const fetchDashboardData = () => {
        if (state.isLoading) return;
        state.isLoading = true;
        const overviewEl = document.querySelector('.dashboard-overview');
        if (overviewEl) overviewEl.classList.add('is-loading');

        const params = new URLSearchParams({ period: state.period, offset: String(state.offset) });
        setMessage('');

        fetch(`../../../backend/api/dashboard/statistics.php?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store'
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || payload.success === false) {
                    throw new Error(payload.message || 'Unable to load the dashboard.');
                }
                return payload.data || payload;
            })
            .then((data) => {
                const periodLabel = data.period_label || 'Selected Period';
                state.dashboardData = data;
                state.currentPeriodLabel = periodLabel;
                state.activePeriodData = {
                    period: state.period,
                    periodLabel,
                    periodSublabel: data.period_sublabel || '',
                    startDate: data.start_date || null,
                    endDate: data.end_date || null,
                    year: data.year || new Date().getFullYear(),
                    month: data.month || (new Date().getMonth() + 1),
                    quarter: data.quarter || Math.ceil((new Date().getMonth() + 1) / 3),
                    cases: data.cases || [],
                    statusTotals: data.status_totals || {},
                    todayHearings: data.today_hearings || []
                };

                renderExecutiveHeaderAndKpis(data);
                renderTrendChart(data.trend_series || []);
                renderWeekdayActivity(data.weekday_activity || []);
                renderSettlementGauge(data);
                renderCaptainDocketTable();
                renderAnalytics(data.analytics);
                renderAuditFeed(data.audit_feed || []);

                state.calendarHearings = data.calendar_hearings || [];
                renderHearings(data.today_hearings || [], periodLabel);
                renderPaanyayas(data.today_paanyayas || []);
                renderDashboardCalendar();

                updateControls(state.period, data);
                updateUrl();
            })
            .catch((error) => {
                console.error('Dashboard Error:', error);
                setMessage(error.message || 'Unable to load the dashboard data. Please try again.', true);
                renderHearings([], 'Error');
                renderDashboardCalendar();
            })
            .finally(() => {
                state.isLoading = false;
                if (overviewEl) overviewEl.classList.remove('is-loading');
            });
    };

    // =========================================================================
    // Event Bindings: Tabs, KPI Cards, Dropdowns, Modals
    // =========================================================================
    const tabButtons = document.querySelectorAll('.period-tab');
    tabButtons.forEach((btn) => {
        const isActive = btn.dataset.period === state.period;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');

        btn.addEventListener('click', () => {
            const period = btn.dataset.period;
            if (state.period === period && state.offset === 0) return;
            state.period = period;
            state.offset = 0;
            tabButtons.forEach((b) => {
                const match = b === btn;
                b.classList.toggle('active', match);
                b.setAttribute('aria-selected', match ? 'true' : 'false');
            });
            fetchDashboardData();
        });
    });

    document.getElementById('prevPeriodBtn')?.addEventListener('click', () => { state.offset -= 1; fetchDashboardData(); });
    document.getElementById('nextPeriodBtn')?.addEventListener('click', () => { state.offset += 1; fetchDashboardData(); });
    document.getElementById('currentPeriodBtn')?.addEventListener('click', () => {
        if (state.offset === 0) return;
        state.offset = 0;
        fetchDashboardData();
    });

    // Docket Tabs & Search
    document.querySelectorAll('.pb-docket-tab').forEach((btn) => {
        btn.addEventListener('click', () => {
            state.activeStatTarget = null;
            document.querySelectorAll('.pb-kpi-card').forEach((c) => c.classList.remove('is-active'));
            const resetBtn = document.getElementById('btnResetCaseFilter');
            if (resetBtn) resetBtn.style.display = 'none';
            setActiveDocketTab(btn.getAttribute('data-docket-tab') || 'priority');
        });
    });

    document.getElementById('captainDocketSearchInput')?.addEventListener('input', (e) => {
        state.docketSearch = e.target.value || '';
        state.casesPage = 1;
        renderCaptainDocketTable();
    });

    document.getElementById('btnResetCaseFilter')?.addEventListener('click', () => {
        state.activeStatTarget = null;
        state.docketSearch = '';
        const searchInp = document.getElementById('captainDocketSearchInput');
        if (searchInp) searchInp.value = '';
        document.querySelectorAll('.pb-kpi-card').forEach((c) => c.classList.remove('is-active'));
        const resetBtn = document.getElementById('btnResetCaseFilter');
        if (resetBtn) resetBtn.style.display = 'none';
        renderCaptainDocketTable();
    });

    // KPI Card click -> Focus appropriate Docket Tab
    document.querySelectorAll('.pb-kpi-card[data-target-stat]').forEach((card) => {
        const activateCard = () => {
            const target = card.getAttribute('data-target-stat');
            document.querySelectorAll('.pb-kpi-card').forEach((c) => c.classList.toggle('is-active', c === card));
            state.activeStatTarget = target;
            const resetBtn = document.getElementById('btnResetCaseFilter');

            if (target === 'captain-actions') {
                if (resetBtn) resetBtn.style.display = 'none';
                setActiveDocketTab('priority');
            } else if (target === 'settled-cases') {
                if (resetBtn) resetBtn.style.display = 'inline-block';
                setActiveDocketTab('cases');
            } else if (target === 'active-mediation') {
                if (resetBtn) resetBtn.style.display = 'inline-block';
                setActiveDocketTab('cases');
            } else {
                if (resetBtn) resetBtn.style.display = 'none';
                setActiveDocketTab('cases');
            }
            document.getElementById('dashboardCasesPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
        card.addEventListener('click', activateCard);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activateCard();
            }
        });
    });

    // Pagination buttons
    document.getElementById('casesPrevPageBtn')?.addEventListener('click', () => {
        if (state.casesPage > 1) { state.casesPage -= 1; renderCaptainDocketTable(); }
    });
    document.getElementById('casesNextPageBtn')?.addEventListener('click', () => {
        const totalPages = Math.ceil(getFilteredDocketRows().length / state.casesPerPage);
        if (state.casesPage < totalPages) { state.casesPage += 1; renderCaptainDocketTable(); }
    });
    document.getElementById('hearingsPrevPageBtn')?.addEventListener('click', () => {
        if (state.hearingsPage > 1) { state.hearingsPage -= 1; renderHearingsPage(); }
    });
    document.getElementById('hearingsNextPageBtn')?.addEventListener('click', () => {
        const totalPages = Math.ceil((state.hearings || []).length / state.hearingsPerPage);
        if (state.hearingsPage < totalPages) { state.hearingsPage += 1; renderHearingsPage(); }
    });

    // Calendar buttons
    document.getElementById('dashCalPrevBtn')?.addEventListener('click', () => {
        state.calendarCursor = new Date(state.calendarCursor.getFullYear(), state.calendarCursor.getMonth() - 1, 1);
        renderDashboardCalendar();
    });
    document.getElementById('dashCalNextBtn')?.addEventListener('click', () => {
        state.calendarCursor = new Date(state.calendarCursor.getFullYear(), state.calendarCursor.getMonth() + 1, 1);
        renderDashboardCalendar();
    });
    document.getElementById('dashCalTodayBtn')?.addEventListener('click', () => {
        state.calendarCursor = new Date();
        renderDashboardCalendar();
        selectCalendarDate(getTodayDateStr());
    });

    const fullCalendarModal = document.getElementById('dashboardFullCalendarModal');
    const closeFullCal = () => { if (fullCalendarModal) fullCalendarModal.style.display = 'none'; };
    document.getElementById('dashCalFullBtn')?.addEventListener('click', () => {
        if (!fullCalendarModal) return;
        renderFullCalendarSummary();
        fullCalendarModal.style.display = 'flex';
    });
    document.getElementById('dashboardFullCalendarClose')?.addEventListener('click', closeFullCal);
    document.getElementById('dashboardFullCalendarCloseFooter')?.addEventListener('click', closeFullCal);
    fullCalendarModal?.addEventListener('click', (e) => { if (e.target === fullCalendarModal) closeFullCal(); });

    // Settlement details modal
    const settlementModal = document.getElementById('settlementDetailsModal');
    const closeSettlementModal = () => { if (settlementModal) settlementModal.style.display = 'none'; };
    document.getElementById('btnShowSettlementDetails')?.addEventListener('click', openSettlementDetailsModal);
    document.getElementById('settlementDetailsClose')?.addEventListener('click', closeSettlementModal);
    document.getElementById('settlementDetailsCloseFooter')?.addEventListener('click', closeSettlementModal);
    settlementModal?.addEventListener('click', (e) => { if (e.target === settlementModal) closeSettlementModal(); });

    // Export / Report Dropdown
    const dropdownGenToggle = document.getElementById('btnGenerateReportToggle');
    const menuGenerate = document.getElementById('menuGenerateReport');
    const closeAllDropdowns = () => {
        if (menuGenerate) menuGenerate.classList.remove('is-open');
        if (dropdownGenToggle) {
            dropdownGenToggle.setAttribute('aria-expanded', 'false');
            dropdownGenToggle.closest('.dash-dropdown')?.classList.remove('is-open');
        }
    };
    dropdownGenToggle?.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = menuGenerate?.classList.contains('is-open');
        closeAllDropdowns();
        if (!isOpen && menuGenerate) {
            menuGenerate.classList.add('is-open');
            dropdownGenToggle.setAttribute('aria-expanded', 'true');
            dropdownGenToggle.closest('.dash-dropdown')?.classList.add('is-open');
        }
    });
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.dash-dropdown')) closeAllDropdowns();
    });
    menuGenerate?.querySelectorAll('.dash-dropdown-item').forEach((item) => {
        item.addEventListener('click', closeAllDropdowns);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllDropdowns();
            closeFullCal();
            closeSettlementModal();
            const drawerOverlay = document.getElementById('addWidgetDrawerOverlay');
            if (drawerOverlay && drawerOverlay.style.display !== 'none') {
                drawerOverlay.style.display = 'none';
            }
        }
    });

    initWidgetDrawer();
    initCaptainAiAssistant();
    initQuickAttendanceModal();

    setInterval(() => {
        if (!document.hidden && !state.isLoading && (state.hearings || []).length > 0) {
            renderHearingsPage();
        }
    }, 60000);

    fetchDashboardData();
});
