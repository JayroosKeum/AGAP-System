/**
 * AGAP Draft Auto-Save & Recovery Manager
 * Provides debounced localStorage persistence, unsaved change warnings, and draft restoration.
 */
(function() {
    'use strict';

    window.agapDraftAutoSave = {
        init({ formId, storageKey, alertContainerId, onRestore, onClear }) {
            const form = document.getElementById(formId);
            if (!form || !storageKey) return null;

            let isDirty = false;
            let debounceTimer = null;

            const serialize = () => {
                const data = {};
                const elements = form.elements;
                for (let i = 0; i < elements.length; i++) {
                    const el = elements[i];
                    if (!el.name || el.type === 'file' || el.type === 'password' || el.type === 'button' || el.type === 'submit') continue;
                    if (el.type === 'radio') {
                        if (el.checked) data[el.name] = el.value;
                    } else if (el.type === 'checkbox') {
                        data[el.name] = el.checked;
                    } else {
                        data[el.name] = el.value;
                    }
                }
                return data;
            };

            const save = () => {
                try {
                    const data = serialize();
                    localStorage.setItem(storageKey, JSON.stringify(data));
                    isDirty = true;
                } catch (e) {
                    console.warn('Draft save error:', e);
                }
            };

            const clear = () => {
                try {
                    localStorage.removeItem(storageKey);
                    isDirty = false;
                    const banner = document.getElementById(`${formId}_draft_banner`);
                    if (banner) banner.remove();
                } catch (e) {}
            };

            const showBanner = () => {
                const existing = document.getElementById(`${formId}_draft_banner`);
                if (existing) return;

                const banner = document.createElement('div');
                banner.id = `${formId}_draft_banner`;
                banner.style.cssText = 'background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 0.84rem; color: #1e40af;';
                banner.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 1rem;">📝</span>
                        <span><strong>Unsaved draft restored</strong> from your local session.</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" class="btn-clear-draft" style="background: #fff; border: 1px solid #93c5fd; color: #1d4ed8; padding: 4px 10px; border-radius: 5px; font-size: 0.78rem; cursor: pointer; font-weight: 600;">Clear Draft</button>
                        <button type="button" class="btn-dismiss-draft" style="background: none; border: none; font-size: 18px; color: #60a5fa; cursor: pointer; line-height: 1;" title="Dismiss">&times;</button>
                    </div>
                `;

                banner.querySelector('.btn-clear-draft').addEventListener('click', () => {
                    clear();
                    form.reset();
                    if (typeof onClear === 'function') onClear();
                });

                banner.querySelector('.btn-dismiss-draft').addEventListener('click', () => {
                    banner.remove();
                });

                if (alertContainerId) {
                    const target = document.getElementById(alertContainerId);
                    if (target) {
                        target.prepend(banner);
                        return;
                    }
                }
                form.prepend(banner);
            };

            const restore = () => {
                try {
                    const raw = localStorage.getItem(storageKey);
                    if (!raw) return false;
                    const data = JSON.parse(raw);
                    if (!data || typeof data !== 'object') return false;

                    let hasValues = false;
                    for (const [key, val] of Object.entries(data)) {
                        const el = form.elements[key];
                        if (!el) continue;
                        if (el instanceof RadioNodeList) {
                            for (let r = 0; r < el.length; r++) {
                                if (el[r].value === String(val)) {
                                    el[r].checked = true;
                                    el[r].dispatchEvent(new Event('change', { bubbles: true }));
                                    hasValues = true;
                                }
                            }
                        } else if (el.type === 'checkbox') {
                            el.checked = Boolean(val);
                            el.dispatchEvent(new Event('change', { bubbles: true }));
                            hasValues = true;
                        } else {
                            if (val !== undefined && val !== null && String(val).trim() !== '') {
                                el.value = val;
                                el.dispatchEvent(new Event('input', { bubbles: true }));
                                hasValues = true;
                            }
                        }
                    }

                    if (hasValues) {
                        isDirty = true;
                        showBanner();
                        if (typeof onRestore === 'function') onRestore(data);
                        return true;
                    }
                } catch (e) {
                    console.warn('Draft restore error:', e);
                }
                return false;
            };

            form.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(save, 300);
            });
            form.addEventListener('change', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(save, 300);
            });

            window.addEventListener('beforeunload', (e) => {
                if (isDirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            setTimeout(restore, 50);

            return {
                save,
                clear,
                restore,
                markSubmitted: () => {
                    isDirty = false;
                    clear();
                }
            };
        }
    };
})();
