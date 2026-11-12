/**
 * AGAP - Katarungang Pambarangay Internationalization (i18n) Engine
 * Dedicated Tagalog Language Switch & Dynamic DOM Translation
 * Ensures 100% frontend UI accessibility without modifying backend payload values.
 */

(function () {
    'use strict';

    const STORAGE_KEY = 'agap_language';

    // Comprehensive English to Tagalog dictionary
    const DICTIONARY = {
        // Specific Required Legal / KP Accessibility Terms
        "Complainant": "Nagsusumbong",
        "Respondent": "Inirereklamo",
        "Complaint Substance": "Pinag-ugatan ng Usapin",
        "Substance of Complaint": "Pinag-ugatan ng Usapin",
        "Complaint Details": "Pinag-ugatan ng Usapin",
        "Nature of Dispute": "Pinag-ugatan ng Usapin",
        "Punong Barangay Unavailable": "Punong Barangay Hindi Magagamit",

        // Main Navigation & Headers
        "Dashboard": "Talaan",
        "Complaints": "Mga Reklamo",
        "Paanyaya": "Paanyaya",
        "Cases": "Mga Kaso",
        "Hearings": "Mga Pagdinig",
        "Documents": "Mga Dokumento",
        "Reports": "Mga Ulat",
        "Settings": "Mga Setting",
        "Notifications": "Mga Abiso",
        "Sign out": "Mag-sign out",
        "Front-Desk & Docket Intake": "Tanggapan at Pagtanggap ng Reklamo",
        "Lupon Clerk Dashboard": "Talaan ng Lupon Clerk",
        "Administrator Dashboard": "Talaan ng Administrator",
        "Punong Barangay Dashboard": "Talaan ng Punong Barangay",
        "Total Disputes": "Kabuuang Alitan",
        "PB Mediation Docket": "Mediation ng Punong Barangay",
        "Needs Captain's Action": "Kailangan ng Aksyon ng Kapitan",
        "Amicable Resolutions": "Mapayapang Pag-aayos",
        "Most Active Days": "Mga Pinaka-aktibong Araw",
        "Amicable Settlement Rate": "Antas ng Mapayapang Pag-aayos",
        "Add widget": "Magdagdag ng Widget",
        "Export": "I-export",
        "Lupon Member Dashboard": "Talaan ng Lupon Member",
        "Summons Server Dashboard": "Talaan ng Tagapagdala ng Patawag",

        // Top Metrics & KPI Cards
        "Total Cases": "Kabuuang Kaso",
        "All recorded cases": "Lahat ng naitalang kaso",
        "Hearings Today": "Mga Pagdinig Ngayong Araw",
        "Scheduled sessions today": "Nakatakdang sesyon ngayong araw",
        "Active Mediation": "Aktibong Mediation",
        "Mediation or conciliation cases": "Mga kasong nasa mediation o conciliation",
        "Mediation cases": "Mga kasong nasa mediation",
        "Pangkat Conciliation": "Pangkat Conciliation",
        "Active panel cases": "Mga aktibong kaso sa pangkat",
        "Settled Cases": "Mga Naayos na Kaso",
        "Resolved through settlement": "Naayos sa pamamagitan ng kasunduan",
        "Active Users": "Aktibong Gumagamit",
        "Barangay staff & lupon": "Mga kawani at miyembro ng lupon",
        "Paanyaya Invitations": "Mga Imbitasyon sa Paanyaya",
        "Pre-docketed informal invitations": "Di-nakadaketang di-pormal na imbitasyon",
        "Undocketed complaints in Paanyaya": "Mga di-nakadaketang reklamo sa Paanyaya",
        "Pending Walk-ins": "Mga Naghihintay na Walk-in",
        "Pending Forms": "Mga Nakabinbing Dokumento",

        // Agenda Stream & Daily Docket
        "Today's Hearings & Paanyaya Agenda Stream": "Talaan ng mga Pagdinig at Paanyaya Ngayong Araw",
        "Today's Hearings": "Mga Pagdinig Ngayong Araw",
        "1-click attendance marking at outcome logging para sa mabilisang sesyon": "Mabilisang pagtatala ng pagdalo at resulta sa isang pindot",
        "Scheduled formal sessions": "Mga pormal na nakatakdang sesyon",
        "Queue Status": "Katayuan sa Queue",
        "Waiting": "Naghihintay",
        "Ready": "Handa Na",
        "In Session": "Kasalukuyang Dinirinig",
        "In_Session": "Kasalukuyang Dinirinig",
        "Standby": "Naka-standby",
        "Concluded": "Nakatapos Na",
        "Put on Standby": "Ilagay sa Standby",
        "Start Hearing Session": "Simulan ang Pagdinig",
        "Resume from Standby": "Ipagpatuloy mula sa Standby",
        "Conclude Hearing": "Tapusin ang Pagdinig",
        "Attendance": "Pagdalo",
        "Present": "Dumalo",
        "Absent": "Hindi Dumalo",
        "Late": "Huli",
        "Excused": "May Paumanhin",
        "Pending": "Nakabinbin",
        "Scheduled": "Nakatakda",
        "Completed": "Tapos Na",
        "Cancelled": "Kinansela",
        "Office Cancelled": "Kinansela ng Tanggapan",
        "Rescheduled": "Nai-reschedule",

        // Action Buttons & Links
        "Schedule New Paanyaya": "Magtakda ng Bagong Paanyaya",
        "Schedule Hearing": "Magtakda ng Pagdinig",
        "New Complaint": "Bagong Reklamo",
        "View Details": "Tingnan ang Detalye",
        "Manage Stage": "Pamahalaan ang Yugto",
        "Stage Details": "Detalye ng Yugto",
        "Record Attendance": "Itala ang Pagdalo",
        "Search": "Maghanap",
        "Filter": "Piliin",
        "All Time": "Lahat ng Panahon",
        "Weekly": "Lingguhan",
        "Monthly": "Buwanan",
        "Quarterly": "Kada Tatlong Buwan",
        "Annual": "Taunan",
        "Lahat": "Lahat",
        "Save": "I-save",
        "Cancel": "Kanselahin",
        "Confirm": "Kumpirmahin",
        "Close": "Isara",
        "Back": "Bumalik",
        "Next": "Susunod",
        "Submit": "Isumite",
        "Print": "I-print",
        "Download": "I-download",

        // Dialogues & Alerts
        "Are you sure you want to sign out?": "Sigurado ka bang nais mong mag-sign out?",
        "Drag & Drop Reorder Active": "Aktibo ang Drag & Drop Reordering",
        "Drag to reorder queue sequence": "I-drag para baguhin ang sequence ng queue",
        "Batch Reschedule": "Maramihang Pag-reschedule",
        "No hearings scheduled for this date.": "Walang nakatakdang pagdinig para sa petsang ito.",

        // Global Shell, Bento Analytics, Role Workflows & Always-On Tagalog Settings
        "Wika / Language": "Wika (Language)",
        "Tagalog / Wika": "Tagalog / Wika",
        "Wika": "Wika",
        "Quick Access": "Mabilisang Aksyon",
        "Clerk Quick Access": "Mabilisang Aksyon ng Klerk",
        "Complaints & Intake": "Mga Reklamo at Pagtatala",
        "Paanyaya Stage 0": "Paanyaya (Stage 0)",
        "Case Register": "Talaan ng Kaso",
        "LTIA & Transmittals": "LTIA at Transmittals",
        "Case Stage Breakdown": "Estado ng mga Kaso",
        "Case Stage Pipeline": "Daloy ng mga Kaso",
        "Peak Hearing Schedule": "Araw ng Maraming Pagdinig",
        "Amicable Settlement Rate": "Antas ng Pagkakasundo",
        "Settlement Completion Rate": "Antas ng Natapos na Kasunduan",
        "Transmittals & LTIA Queue": "Talaan ng Transmittals at LTIA",
        "Open LTIA & Transmittals": "Buksan ang LTIA at Transmittals",
        "Verify & Screen Complaints": "Suriin ang mga Reklamo",
        "Schedule Paanyaya": "I-schedule ang Paanyaya",
        "Court & DILG Transmittals": "Pagpasa sa Korte (MTC) at DILG",
        "LTIA Forms 6 & 8": "LTIA Forms 6 at 8",
        "Read-Only Conciliator View": "Tingnan Lamang (Lupon Member)",
        "Read-Only Lupon View": "Tingnan Lamang (Lupon Member)",
        "Jurisdiction Review": "Pagsusuri ng Saklaw (Jurisdiction)",
        "Minutes & KP Forms": "Mga Minutes at KP Forms",
        "Pending Resident Submissions — Jurisdiction Verification": "Mga Bagong Reklamo — Pagsusuri ng Saklaw (KP)",
        "Verify Jurisdiction → Pass to Clerk": "Aprubahan ang Saklaw → Ipasa sa Klerk",
        "Flag Outside KP": "Labas sa Saklaw ng KP",
        "Read Full Salaysay →": "Basahin ang Buong Salaysay →",
        "Preview KP Form": "Silipin ang KP Form",
        "Generate Form & Record Transmittal": "Gumawa ng Form at Itala ang Transmittal",
        "Language & Tagalog Settings": "Wika at Tagalog Settings",
        "System Interface Language": "Wika ng Sistema (Interface)",
        "KP Form & Printout Language": "Wika ng KP Forms at Printouts",
        "Reschedule Whole Afternoon Only (1:00–5:00 PM)": "Ilipat ang Pang-Hapon Lamang (1:00–5:00 PM)",
        "Reschedule Entire Day (All Today's Hearings)": "Ilipat ang Buong Araw (Lahat ng Pagdinig)",
        "Punong Barangay Unavailable": "Wala ang Punong Barangay",
        "Search Case #, Complaint, or Party...": "Hanapin ang Case #, Reklamo, o Partido...",
        "Resident Sign In": "Mag-sign In bilang Residente",
        "Create Resident Account": "Gumawa ng Account ng Residente",
        "File or Track Your Barangay Complaint Online": "Maghain o Subaybayan ang Reklamo sa Barangay Online",
        "Online Resident Complaint Portal": "Online na Portal ng Reklamo ng Residente",
        "Total cases": "Kabuuang mga Kaso",
        "Total Cases": "Kabuuang Kaso",
        "Pending screening": "Naghihintay ng Pagsusuri",
        "Pending Screening": "Naghihintay ng Pagsusuri",
        "Needs jurisdiction check": "Kailangang suriin ang saklaw",
        "Hearings": "Mga Pagdinig",
        "Hearings today": "Mga Pagdinig Ngayon",
        "Hearings Today": "Mga Pagdinig Ngayon",
        "Hearings in period": "Mga Pagdinig sa Panahong Ito",
        "Scheduled sessions": "Nakatakdang sesyon",
        "Next 7 calendar days": "Susunod na 7 araw",
        "Undocketed complaints": "Hindi pa naka-docket na reklamo",
        "All recorded cases": "Lahat ng naitalang kaso",
        "Active mediation": "Aktibong Medyasyon",
        "Active Mediation": "Aktibong Medyasyon",
        "Settled cases": "Mga Naayos na Kaso",
        "Settled Cases": "Mga Naayos na Kaso",
        "Mediation": "Medyasyon",
        "Conciliation": "Konsilyasyon",
        "Settled": "Naayos",
        "Archived / Other": "Naka-archive / Iba pa",
        "Complaint-bound pipeline from Mediation to Resolution": "Daloy ng kaso mula Medyasyon hanggang Kasunduan",
        "End-to-end complaint-bound docket stages": "Kumpletong daloy ng mga yugto ng kaso",
        "Tue (Mediation) & Wed (Pangkat)": "Mar (Medyasyon) at Miy (Pangkat)",
        "Live resolution tracking": "Aktwal na pagsubaybay ng pagkakasundo",
        "Ready for 10-day MTC/DILG transmittal cycle": "Handa sa 10-araw na siklo ng pagpasa sa MTC/DILG",
        "Generate Report": "Gumawa ng Ulat",
        "Today's hearings": "Mga Pagdinig Ngayong Araw",
        "Sessions scheduled for today": "Mga nakatakdang sesyon ngayon",
        "No active hearings are scheduled for today.": "Walang nakatakdang pagdinig para sa araw na ito.",
        "Calendar": "Kalendaryo",
        "Full calendar": "Buong kalendaryo",
        "Today": "Ngayon",
        "Announcements": "Mga Anunsyo",
        "Announcements & News": "Mga Balita at Anunsyo",
        "Barangay News & Advisories": "Mga Balita at Abiso ng Barangay",
        "Official updates, Lupon schedules, and community advisories from Barangay Tumana.": "Mga opisyal na balita, schedule ng Lupon, at paalala mula sa Barangay Tumana.",
        "Manage News": "Pamahalaan ang Balita",
        "Barangay Tumana bulletin": "Abiso ng Barangay Tumana",
        "Monthly Council Meeting": "Buwanang Pulong ng Sanggunian",
        "Lupon Duty Attendance": "Pagdalo ng Lupon sa Duty",
        "Most Complained Issues": "Pinakamadalas na Usapin",
        "Top recurring dispute categories in the barangay": "Nangungunang kategorya ng alitan sa barangay",
        "Purok Dispute Hotspots": "Mga Purok na may Pinakamaraming Alitan",
        "Areas with highest grievance volume": "Mga lugar na may pinakamataas na reklamo",
        "Gender Distribution": "Distribusyon ayon sa Kasarian",
        "Complainants and Respondents by gender": "Mga Nagsusumbong at Inirereklamo ayon sa kasarian",
        "Age Group Demographics": "Grupo ng Edad ng mga Kalahok",
        "Dispute participants by age bracket": "Bilang ng kalahok ayon sa edad",
        "Walk-ins / Today's Intakes": "Walk-in / Bagong Tala Ngayon",
        "Active Paanyaya Queue": "Talaan ng Aktibong Paanyaya",
        "Active Staff & Lupon": "Aktibong Staff at Lupon",
        "Loading dispute statistics...": "Kinukuha ang estadistika ng mga alitan...",
        "Loading area hotspots...": "Kinukuha ang datos ng mga purok...",
        "Loading gender data...": "Kinukuha ang datos ng kasarian...",
        "Loading age demographics...": "Kinukuha ang datos ng edad...",
        "Loading Paanyaya invitations...": "Kinukuha ang mga imbitasyon sa Paanyaya...",
        "Disable": "I-disable",
        "Enable": "I-enable",
        "Disabled": "Naka-disable",
        "Pending Verification": "Naghihintay ng Beripikasyon",
        "Create accounts, assign roles, and disable or re-enable account access.": "Gumawa ng mga account, magtalaga ng role, at mag-disable o mag-enable ng access.",
        "Font Size": "Laki ng Titik",
        "Compact": "Maliit",
        "Normal": "Normal",
        "Large": "Malaki",
        "Extra Large": "Pinakamalaki"
    };

    // State tracking
    let currentLang = 'en';
    try {
        currentLang = localStorage.getItem(STORAGE_KEY) || 'en';
    } catch (e) {
        currentLang = 'en';
    }

    // WeakMap to store original English text for exact restoration
    const originalTextMap = new WeakMap();

    /**
     * Determines whether an element should be ignored from translation.
     * Prevents altering form values, inputs, code, or internal identifiers.
     */
    function shouldSkipElement(el) {
        if (!el || el.nodeType !== Node.ELEMENT_NODE) return true;
        const tag = el.tagName.toLowerCase();
        if (['script', 'style', 'input', 'textarea', 'select', 'option', 'code', 'pre'].includes(tag)) {
            return true;
        }
        if (el.hasAttribute('data-no-i18n') || el.classList.contains('no-translate')) {
            return true;
        }
        // Skip user-editable fields or contenteditable
        if (el.isContentEditable) return true;
        return false;
    }

    /**
     * Translates a single text string if a match exists.
     */
    function translateString(str) {
        if (!str || typeof str !== 'string') return str;
        const trimmed = str.trim();
        if (!trimmed) return str;

        if (DICTIONARY[trimmed]) {
            return str.replace(trimmed, DICTIONARY[trimmed]);
        }

        // Substring replacements for core terms if part of phrases
        let result = str;
        if (result.includes('Complainant') && !result.includes('Nagsusumbong')) {
            result = result.replace(/\bComplainant\b/g, 'Nagsusumbong');
        }
        if (result.includes('Respondent') && !result.includes('Inirereklamo')) {
            result = result.replace(/\bRespondent\b/g, 'Inirereklamo');
        }
        if (result.includes('Complaint Substance') && !result.includes('Pinag-ugatan ng Usapin')) {
            result = result.replace(/Complaint Substance/g, 'Pinag-ugatan ng Usapin');
        }
        return result;
    }

    /**
     * Walks the DOM starting from a root node and translates text nodes.
     */
    function applyTranslationsToNode(node, lang) {
        if (!node) return;

        if (node.nodeType === Node.TEXT_NODE) {
            const parent = node.parentElement;
            if (!parent || shouldSkipElement(parent)) return;

            const text = node.nodeValue;
            if (!text || !text.trim()) return;

            if (lang === 'tl') {
                if (!originalTextMap.has(node)) {
                    originalTextMap.set(node, text);
                }
                const translated = translateString(text);
                if (translated !== text) {
                    node.nodeValue = translated;
                }
            } else {
                // Restore English
                if (originalTextMap.has(node)) {
                    node.nodeValue = originalTextMap.get(node);
                }
            }
            return;
        }

        if (node.nodeType === Node.ELEMENT_NODE) {
            if (shouldSkipElement(node)) return;

            // Handle title or placeholder attributes safely
            if (node.hasAttribute('placeholder')) {
                const ph = node.getAttribute('placeholder');
                if (lang === 'tl') {
                    if (!node.hasAttribute('data-en-placeholder')) {
                        node.setAttribute('data-en-placeholder', ph);
                    }
                    node.setAttribute('placeholder', translateString(ph));
                } else if (node.hasAttribute('data-en-placeholder')) {
                    node.setAttribute('placeholder', node.getAttribute('data-en-placeholder'));
                }
            }

            // Recurse children
            for (let i = 0; i < node.childNodes.length; i++) {
                applyTranslationsToNode(node.childNodes[i], lang);
            }
        }
    }

    /**
     * Updates the Navbar language pills UI.
     */
    function updateTogglePills(lang) {
        const pillEn = document.getElementById('langPillEn');
        const pillTl = document.getElementById('langPillTl');
        const toggleBtn = document.getElementById('langSwitchToggle');

        if (pillEn && pillTl) {
            if (lang === 'tl') {
                pillEn.classList.remove('active');
                pillTl.classList.add('active');
            } else {
                pillTl.classList.remove('active');
                pillEn.classList.add('active');
            }
        }

        // Keep Sidebar footer quick switcher and Settings Language buttons in sync
        const sbEn = document.getElementById('sidebarLangEnBtn');
        const sbTl = document.getElementById('sidebarLangTlBtn');
        if (sbEn && sbTl) {
            sbEn.classList.toggle('is-active', lang !== 'tl');
            sbTl.classList.toggle('is-active', lang === 'tl');
        }

        const setEn = document.getElementById('settingsLangEnBtn');
        const setTl = document.getElementById('settingsLangTlBtn');
        if (setEn && setTl) {
            setEn.classList.toggle('is-active', lang !== 'tl');
            setTl.classList.toggle('is-active', lang === 'tl');
        }

        const profSelect = document.getElementById('profileLangSelect');
        if (profSelect) {
            const wantVal = lang === 'tl' ? 'fil' : 'en';
            if (profSelect.value !== wantVal) {
                profSelect.value = wantVal;
            }
        }

        if (toggleBtn) {
            toggleBtn.setAttribute('data-active-lang', lang);
            toggleBtn.setAttribute('title', lang === 'tl' ? 'Kasalukuyang Wika: Tagalog (I-click para mag-English)' : 'Current Language: English (Click for Tagalog)');
        }

        document.documentElement.setAttribute('data-agap-lang', lang);
        document.documentElement.lang = (lang === 'tl' ? 'fil' : 'en');
    }

    /**
     * Applies the current language across the whole document.
     */
    function setLanguage(lang) {
        currentLang = (lang === 'tl') ? 'tl' : 'en';
        try {
            localStorage.setItem(STORAGE_KEY, currentLang);
        } catch (e) {}

        updateTogglePills(currentLang);
        applyTranslationsToNode(document.body, currentLang);

        window.dispatchEvent(new CustomEvent('agapLanguageChanged', { detail: { language: currentLang } }));
    }

    /**
     * Global Toggle Function triggered from Navbar.
     */
    window.toggleAppLanguage = function () {
        const nextLang = (currentLang === 'en') ? 'tl' : 'en';
        setLanguage(nextLang);
    };

    window.getAppLanguage = function () {
        return currentLang;
    };

    window.setAppLanguage = setLanguage;

    window.getKpFormLanguage = function () {
        try {
            return localStorage.getItem('agap_kp_form_lang') || 'fil';
        } catch (e) {
            return 'fil';
        }
    };

    window.setKpFormLanguage = function (formLang) {
        const clean = (formLang === 'en') ? 'en' : 'fil';
        try {
            localStorage.setItem('agap_kp_form_lang', clean);
        } catch (e) {}
        return clean;
    };

    /**
     * Translates a string for JS usage without touching payloads.
     */
    window.t = function (key) {
        if (currentLang === 'tl' && DICTIONARY[key]) {
            return DICTIONARY[key];
        }
        return key;
    };

    // Observe dynamic elements (e.g. AJAX rendered cards, tables, modals)
    let observer = null;
    function initObserver() {
        if (!window.MutationObserver) return;
        observer = new MutationObserver(mutations => {
            if (currentLang !== 'tl') return;
            mutations.forEach(m => {
                m.addedNodes.forEach(addedNode => {
                    if (addedNode.nodeType === Node.ELEMENT_NODE || addedNode.nodeType === Node.TEXT_NODE) {
                        applyTranslationsToNode(addedNode, 'tl');
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Shared Stepped Font Size Slider with 4 discrete points (0..3)
    const FONT_SCALE_STEPS = {
        0: { px: '14px', label: '14px · Compact', large: false },
        1: { px: '16px', label: '16px · Normal', large: false },
        2: { px: '18px', label: '18px · Large', large: true },
        3: { px: '20px', label: '20px · XL', large: true }
    };

    const resolveSavedFontStep = () => {
        try {
            const raw = localStorage.getItem('agap_font_scale_step');
            if (raw !== null && FONT_SCALE_STEPS[Number(raw)]) return Number(raw);
            if (localStorage.getItem('agap_lupon_font_size') === 'large') return 2;
        } catch (e) {}
        return 1;
    };

    const syncFontSliderUI = (step) => {
        const meta = FONT_SCALE_STEPS[step] || FONT_SCALE_STEPS[1];
        document.documentElement.style.fontSize = meta.px;
        document.documentElement.setAttribute('data-agap-font-step', String(step));
        if (document.body) {
            document.body.classList.toggle('font-size-large', Boolean(meta.large));
        }
        document.querySelectorAll('.agap-font-range').forEach((input) => {
            if (String(input.value) !== String(step)) input.value = String(step);
        });
        document.querySelectorAll('.agap-slider-point[data-font-step]').forEach((btn) => {
            const btnStep = Number(btn.getAttribute('data-font-step'));
            btn.classList.toggle('is-active', btnStep === step);
            btn.classList.toggle('is-passed', btnStep < step);
        });
        document.querySelectorAll('[data-font-size-badge]').forEach((badge) => {
            badge.textContent = meta.label;
        });
    };

    window.getAgapFontStep = () => resolveSavedFontStep();

    window.setAgapFontStep = (nextStep) => {
        const clamped = Math.max(0, Math.min(3, parseInt(nextStep, 10) || 0));
        const meta = FONT_SCALE_STEPS[clamped] || FONT_SCALE_STEPS[1];
        try {
            localStorage.setItem('agap_font_scale_step', String(clamped));
            localStorage.setItem('agap_lupon_font_size', meta.large ? 'large' : 'normal');
        } catch (e) {}
        syncFontSliderUI(clamped);
        window.dispatchEvent(new CustomEvent('agapFontScaleChanged', { detail: { step: clamped, px: meta.px } }));
    };

    const bindFontSliders = () => {
        const initialStep = resolveSavedFontStep();
        syncFontSliderUI(initialStep);
        document.querySelectorAll('.agap-font-range').forEach((input) => {
            if (input.dataset.fontBound === '1') return;
            input.dataset.fontBound = '1';
            const onSlide = () => window.setAgapFontStep(input.value);
            input.addEventListener('input', onSlide);
            input.addEventListener('change', onSlide);
        });
        document.querySelectorAll('.agap-slider-point[data-font-step]').forEach((btn) => {
            if (btn.dataset.fontBound === '1') return;
            btn.dataset.fontBound = '1';
            btn.addEventListener('click', () => window.setAgapFontStep(btn.getAttribute('data-font-step')));
        });
    };

    // Initialize when DOM is ready
    const bootI18nAndFont = () => {
        updateTogglePills(currentLang);
        bindFontSliders();
        if (currentLang === 'tl') {
            applyTranslationsToNode(document.body, 'tl');
        }
        initObserver();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootI18nAndFont);
    } else {
        bootI18nAndFont();
    }
})();
