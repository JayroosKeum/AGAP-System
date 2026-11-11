/**
 * AGAP Internationalization & Accessibility Module (i18n)
 * Provides instant language switching between Tagalog (tl) and English (en)
 * with localStorage persistence, full keyboard accessibility, and zero backend dependencies.
 */

class I18nManager {
    constructor() {
        this.currentLang = localStorage.getItem('agap_language') || 'en';
        this.dictionaries = {
            tl: null,
            en: null
        };
        this.isLoaded = false;
    }

    /**
     * Initialize the i18n manager and bind UI elements
     */
    async init() {
        await this.loadDictionaries();
        this.applyLanguage(this.currentLang);
        this.bindSwitcherUI();
    }

    /**
     * Load locale JSON dictionaries
     */
    async loadDictionaries() {
        try {
            const getLangBase = () => {
                const match = window.location.pathname.match(/^(.*\/frontend\/)/);
                if (match) return match[1] + 'lang/';
                return '../../lang/';
            };
            const langBase = getLangBase();
            let [tlRes, enRes] = await Promise.all([
                fetch(langBase + 'tl/landing.json').catch(() => null),
                fetch(langBase + 'en/landing.json').catch(() => null)
            ]);

            // Fallback to relative path if dynamic match failed
            if ((!tlRes || !tlRes.ok) && langBase !== '../../lang/') {
                [tlRes, enRes] = await Promise.all([
                    fetch('../../lang/tl/landing.json').catch(() => null),
                    fetch('../../lang/en/landing.json').catch(() => null)
                ]);
            }

            if (tlRes && tlRes.ok) {
                this.dictionaries.tl = await tlRes.json();
            }
            if (enRes && enRes.ok) {
                this.dictionaries.en = await enRes.json();
            }
            this.isLoaded = true;
        } catch (e) {
            console.warn('I18n: Failed to fetch external locale files. Using fallback.', e);
        }
    }

    /**
     * Switch active language
     * @param {string} lang 'tl' or 'en'
     */
    setLanguage(lang) {
        if (!['tl', 'en'].includes(lang)) return;
        this.currentLang = lang;
        localStorage.setItem('agap_language', lang);
        this.applyLanguage(lang);
    }

    /**
     * Apply active language to the DOM
     * @param {string} lang
     */
    applyLanguage(lang) {
        document.documentElement.setAttribute('lang', lang);

        const dict = this.dictionaries[lang];
        if (!dict) return;

        // Translate text elements
        const elements = document.querySelectorAll('[data-i18n]');
        elements.forEach(el => {
            const key = el.getAttribute('data-i18n');
            const translation = this.resolveKey(dict, key);
            if (translation !== undefined) {
                el.innerHTML = translation;
            }
        });

        // Translate placeholders
        const placeholderElements = document.querySelectorAll('[data-i18n-placeholder]');
        placeholderElements.forEach(el => {
            const key = el.getAttribute('data-i18n-placeholder');
            const translation = this.resolveKey(dict, key);
            if (translation !== undefined) {
                el.setAttribute('placeholder', translation);
            }
        });

        // Translate titles / aria-labels
        const ariaElements = document.querySelectorAll('[data-i18n-aria]');
        ariaElements.forEach(el => {
            const key = el.getAttribute('data-i18n-aria');
            const translation = this.resolveKey(dict, key);
            if (translation !== undefined) {
                el.setAttribute('aria-label', translation);
            }
        });

        // Update Switcher Buttons State
        this.updateSwitcherButtons(lang);
    }

    /**
     * Resolve dot-notated key in nested dictionary object (e.g. 'hero.title_main')
     */
    resolveKey(obj, path) {
        if (!obj || !path) return undefined;
        return path.split('.').reduce((acc, part) => (acc && acc[part] !== undefined ? acc[part] : undefined), obj);
    }

    /**
     * Bind click and keyboard events to the language switcher buttons
     */
    bindSwitcherUI() {
        const btnTl = document.getElementById('langToggleTl');
        const btnEn = document.getElementById('langToggleEn');

        if (btnTl) {
            btnTl.addEventListener('click', () => this.setLanguage('tl'));
            btnTl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.setLanguage('tl');
                }
            });
        }

        if (btnEn) {
            btnEn.addEventListener('click', () => this.setLanguage('en'));
            btnEn.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.setLanguage('en');
                }
            });
        }

        this.updateSwitcherButtons(this.currentLang);
    }

    /**
     * Update active visual state and ARIA attributes for switcher buttons
     */
    updateSwitcherButtons(lang) {
        const btnTl = document.getElementById('langToggleTl');
        const btnEn = document.getElementById('langToggleEn');

        if (btnTl) {
            const isTl = (lang === 'tl');
            btnTl.setAttribute('aria-pressed', isTl ? 'true' : 'false');
            btnTl.classList.toggle('active', isTl);
        }

        if (btnEn) {
            const isEn = (lang === 'en');
            btnEn.setAttribute('aria-pressed', isEn ? 'true' : 'false');
            btnEn.classList.toggle('active', isEn);
        }
    }
}

// Auto-instantiate globally on window for easy access
window.agapI18n = new I18nManager();
document.addEventListener('DOMContentLoaded', () => {
    window.agapI18n.init();
});
