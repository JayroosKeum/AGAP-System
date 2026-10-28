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
                banner.className = 'agap-draft-restored-banner';
                banner.style.cssText = 'background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #2563eb; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 0.85rem; color: #1e40af; box-shadow: 0 1px 3px rgba(37,99,235,0.08);';
                banner.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 9px;">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                        <span><strong>Unsaved draft restored</strong> from your local session.</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" class="btn-clear-draft agap-draft-discard-btn" style="background: #ffffff; border: 1px solid #fca5a5; color: #dc2626; font-size: 0.78rem; font-weight: 600; padding: 4px 10px; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg> Clear Draft</button>
                        <button type="button" class="btn-dismiss-draft agap-draft-dismiss-btn" style="background: none; border: none; font-size: 18px; color: #60a5fa; cursor: pointer; line-height: 1; padding: 2px 6px;" title="Dismiss">&times;</button>
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
