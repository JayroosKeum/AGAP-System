/**
 * AGAP Draft Manager
 * Universal Client-Side Auto-Save, Session Recovery & Exit Protection Engine
 * File: frontend/assets/js/draft-manager.js
 */
(function(window) {
    'use strict';

    const AgapDraftManager = {
        activeInstances: new Map(),
        isDirty: false,
        debounceTimeoutMs: 400,

        /**
         * Initialize auto-save on a container or form
         * Accepts either:
         *   AgapDraftManager.init(container, storageKey, options)
         * or:
         *   AgapDraftManager.init({ formId, storageKey, ...options })
         */
        init(containerOrConfig, maybeStorageKey, maybeOptions = {}) {
            let container = null;
            let storageKey = '';
            let options = {};

            if (typeof containerOrConfig === 'object' && containerOrConfig !== null && !containerOrConfig.nodeType) {
                // Object config style
                options = containerOrConfig;
                storageKey = options.storageKey;
                if (options.formId) {
                    container = document.getElementById(options.formId);
                } else if (options.container) {
                    container = typeof options.container === 'string' ? document.querySelector(options.container) : options.container;
                }
            } else {
                container = typeof containerOrConfig === 'string' ? document.querySelector(containerOrConfig) : containerOrConfig;
                storageKey = maybeStorageKey;
                options = maybeOptions;
            }

            if (!container || !storageKey) return null;

            // Destroy any previous instance on this storageKey
            if (this.activeInstances.has(storageKey)) {
                try {
                    this.activeInstances.get(storageKey).destroy();
                } catch (e) {}
            }

            // If session is already finalized/read-only, skip auto-save & purge any stale draft
            if (options.isReadOnly) {
                this.clear(storageKey);
                this.setIndicator(options.indicatorId, 'idle', '✓ Finalized Record');
                return null;
            }

            const instance = {
                container,
                storageKey,
                options,
                debounceTimer: null,
                isSubmitting: false,
                destroy: () => {}
            };

            this.activeInstances.set(storageKey, instance);

            // 1. Attempt to restore existing draft
            const restored = this.restore(container, storageKey, options);
            if (!restored) {
                this.setIndicator(options.indicatorId, 'idle', '✓ Saved');
            }

            // 2. Attach debounced auto-save listeners
            const handleInputChange = (e) => {
                const target = e.target;
                if (!target) return;
                // Exclude passwords and buttons
                if (['password', 'button', 'submit', 'reset'].includes(target.type)) return;

                // Mark dirty and show saving status
                this.setDirty(true);
                this.setIndicator(options.indicatorId, 'saving', '💾 Saving draft...');

                if (instance.debounceTimer) clearTimeout(instance.debounceTimer);
                instance.debounceTimer = setTimeout(() => {
                    this.save(storageKey, container);
                    this.setIndicator(options.indicatorId, 'protected', '● Unsaved changes saved locally');
                }, this.debounceTimeoutMs);
            };

            container.addEventListener('input', handleInputChange);
            container.addEventListener('change', handleInputChange);
            container.addEventListener('keyup', handleInputChange);

            instance.destroy = () => {
                if (instance.debounceTimer) clearTimeout(instance.debounceTimer);
                container.removeEventListener('input', handleInputChange);
                container.removeEventListener('change', handleInputChange);
                container.removeEventListener('keyup', handleInputChange);
                this.activeInstances.delete(storageKey);
            };

            instance.markSubmitted = () => {
                this.markSubmitted(storageKey);
                this.setIndicator(options.indicatorId, 'synced', '✓ All changes submitted');
            };

            return instance;
        },

        /**
         * Serialize all form elements inside a container
         */
        serialize(container) {
            const data = {};
            const elements = container.querySelectorAll('input, textarea, select');

            elements.forEach(el => {
                if (!el.name && !el.id) return;
                if (['button', 'submit', 'reset', 'password'].includes(el.type)) return;
                if (el.type === 'file') return; // File paths cannot be stored in localStorage

                const key = el.name || el.id;

                if (el.type === 'radio') {
                    if (el.checked) {
                        data[key] = el.value;
                    }
                } else if (el.type === 'checkbox') {
                    data[el.id || el.name] = el.checked;
                } else {
                    data[el.id || el.name] = el.value;
                }
            });

            return data;
        },

        /**
         * Save serialized data to localStorage
         */
        save(storageKey, containerOrData) {
            try {
                if (!storageKey) return;
                let fields = {};

                if (containerOrData && containerOrData.nodeType) {
                    fields = this.serialize(containerOrData);
                } else if (typeof containerOrData === 'object') {
                    fields = containerOrData;
                } else if (typeof containerOrData === 'string') {
                    const el = document.querySelector(containerOrData);
                    if (el) fields = this.serialize(el);
                }

                if (!fields || Object.keys(fields).length === 0) return;

                const payload = {
                    timestamp: new Date().toISOString(),
                    storageKey: storageKey,
                    fields: fields
                };

                localStorage.setItem(storageKey, JSON.stringify(payload));
            } catch (err) {
                console.warn('[AgapDraftManager] save error:', err);
            }
        },

        /**
         * Restore draft from localStorage into container elements
         */
        restore(containerOrSelector, storageKey, options = {}) {
            try {
                if (!storageKey) return false;
                const raw = localStorage.getItem(storageKey);
                if (!raw) return false;

                const payload = JSON.parse(raw);
                if (!payload || !payload.fields) return false;

                const container = typeof containerOrSelector === 'string'
                    ? document.querySelector(containerOrSelector)
                    : containerOrSelector;
                if (!container) return false;

                const fields = payload.fields;
                let restoredCount = 0;

                Object.keys(fields).forEach(key => {
                    const val = fields[key];

                    // 1. Try match by ID
                    let el = container.querySelector('#' + CSS.escape(key));
                    if (el) {
                        if (el.type === 'checkbox') {
                            el.checked = Boolean(val);
                        } else if (el.type === 'radio') {
                            el.checked = (el.value === val);
                        } else {
                            el.value = val;
                        }
                        restoredCount++;
                        return;
                    }

                    // 2. Try match by name
                    const elsByName = container.querySelectorAll('[name="' + CSS.escape(key) + '"]');
                    if (elsByName.length > 0) {
                        elsByName.forEach(inputEl => {
                            if (inputEl.type === 'radio') {
                                inputEl.checked = (inputEl.value === val);
                            } else if (inputEl.type === 'checkbox') {
                                inputEl.checked = Boolean(val);
                            } else {
                                inputEl.value = val;
                            }
                        });
                        restoredCount++;
                    }
                });

                if (restoredCount > 0) {
                    this.setDirty(true);
                    this.renderRestoredBanner(container, payload, storageKey, options);
                    this.setIndicator(options.indicatorId, 'protected', '● Unsaved changes saved locally');

                    if (typeof options.onRestore === 'function') {
                        options.onRestore(payload);
                    } else if (typeof options.onRestored === 'function') {
                        options.onRestored(payload);
                    }
                    return true;
                }
            } catch (err) {
                console.warn('[AgapDraftManager] restore error:', err);
            }
            return false;
        },

        /**
         * Render a restored draft notification banner
         */
        renderRestoredBanner(container, payload, storageKey, options = {}) {
            const bannerId = `draft_banner_${storageKey.replace(/[^a-zA-Z0-9_-]/g, '_')}`;
            let existing = document.getElementById(bannerId);
            if (existing) existing.remove();

            const timeAgo = this.formatTimeAgo(new Date(payload.timestamp));
            const banner = document.createElement('div');
            banner.id = bannerId;
            banner.className = 'agap-draft-restored-banner';
            banner.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 1rem;">🛡️</span>
                        <span><strong>Draft Restored:</strong> Unsaved changes from ${timeAgo} have been recovered on your device.</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <button type="button" class="agap-draft-discard-btn" onclick="AgapDraftManager.discardDraft('${storageKey}')">
                            Discard Draft
                        </button>
                    </div>
                </div>
            `;

            let mountPoint = null;
            if (options.bannerMount) {
                mountPoint = typeof options.bannerMount === 'string' ? document.querySelector(options.bannerMount) : options.bannerMount;
            }
            if (mountPoint) {
                mountPoint.prepend(banner);
            } else if (container) {
                container.prepend(banner);
            }
        },

        /**
         * Discard draft: clear localStorage and reload original server state
         */
        discardDraft(storageKey) {
            if (!confirm('Are you sure you want to discard this recovered draft and reload original values from the server?')) {
                return;
            }
            this.clear(storageKey);
            this.setDirty(false);
            window.location.reload();
        },

        /**
         * Clear draft from localStorage
         */
        clear(storageKey) {
            try {
                if (!storageKey) return;
                localStorage.removeItem(storageKey);
                const bannerId = `draft_banner_${storageKey.replace(/[^a-zA-Z0-9_-]/g, '_')}`;
                const banner = document.getElementById(bannerId);
                if (banner) banner.remove();
                this.setDirty(false);
            } catch (err) {}
        },

        /**
         * Mark draft as successfully submitted
         */
        markSubmitted(storageKey) {
            this.clear(storageKey);
            this.setDirty(false);
        },

        hasDraft(storageKey) {
            try {
                return !!localStorage.getItem(storageKey);
            } catch (e) {
                return false;
            }
        },

        getDraft(storageKey) {
            try {
                const raw = localStorage.getItem(storageKey);
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        },

        /**
         * Update visual status pill
         */
        setIndicator(indicatorId, state, customMessage) {
            const el = indicatorId ? document.getElementById(indicatorId) : document.querySelector('.agap-draft-indicator');
            if (!el) return;

            el.className = `agap-draft-indicator state-${state}`;
            if (state === 'idle') {
                el.innerHTML = customMessage || '✓ Saved';
            } else if (state === 'saving') {
                el.innerHTML = customMessage || '💾 Saving draft...';
            } else if (state === 'protected') {
                el.innerHTML = customMessage || '● Unsaved changes saved locally';
            } else if (state === 'synced') {
                el.innerHTML = customMessage || '✓ All changes submitted';
            }
        },

        setDirty(isDirty) {
            this.isDirty = Boolean(isDirty);
        },

        formatTimeAgo(date) {
            if (!(date instanceof Date) || isNaN(date.getTime())) return 'recently';
            const seconds = Math.floor((new Date() - date) / 1000);
            if (seconds < 60) return 'just now';
            const minutes = Math.floor(seconds / 60);
            if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
            const hours = Math.floor(minutes / 60);
            if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
            return date.toLocaleDateString();
        }
    };

    // Native browser exit warning on beforeunload
    window.addEventListener('beforeunload', (e) => {
        if (AgapDraftManager.isDirty) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

    // In-app internal link exit guard
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a');
        if (!link || !AgapDraftManager.isDirty) return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;

        const proceed = confirm(
            'You have unsaved changes in this session.\n\nA draft is safely saved on your device, but has not yet been submitted to the server.\n\nAre you sure you want to leave?'
        );
        if (!proceed) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    window.AgapDraftManager = AgapDraftManager;
    window.agapDraftAutoSave = AgapDraftManager; // Backwards-compatible alias
})(window);
