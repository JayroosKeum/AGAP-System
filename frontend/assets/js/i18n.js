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
        "No hearings scheduled for this date.": "Walang nakatakdang pagdinig para sa petsang ito."
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

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            updateTogglePills(currentLang);
            if (currentLang === 'tl') {
                applyTranslationsToNode(document.body, 'tl');
            }
            initObserver();
        });
    } else {
        updateTogglePills(currentLang);
        if (currentLang === 'tl') {
            applyTranslationsToNode(document.body, 'tl');
        }
        initObserver();
    }
})();
