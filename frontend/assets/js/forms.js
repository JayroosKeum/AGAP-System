(() => {
    function initSegmentedControls(root = document) {
        const groups = root.querySelectorAll('.agap-segmented-group[data-seg-target]');
        groups.forEach((group) => {
            if (group.dataset.segBound === '1') return;
            group.dataset.segBound = '1';

            const targetId = group.getAttribute('data-seg-target');
            const targetEl = targetId ? document.getElementById(targetId) : null;
            if (!targetEl) return;

            targetEl.classList.add('agap-seg-native-select');
            targetEl.setAttribute('tabindex', '-1');
            targetEl.setAttribute('aria-hidden', 'true');

            const syncButtons = () => {
                const cur = String(targetEl.value ?? '');
                group.querySelectorAll('.agap-seg-btn[data-seg-value]').forEach((btn) => {
                    const active = btn.getAttribute('data-seg-value') === cur;
                    btn.classList.toggle('is-active', active);
                    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            };

            group.querySelectorAll('.agap-seg-btn[data-seg-value]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const nextVal = btn.getAttribute('data-seg-value') ?? '';
                    if (String(targetEl.value) !== nextVal) {
                        targetEl.value = nextVal;
                        targetEl.dispatchEvent(new Event('change', { bubbles: true }));
                    } else {
                        syncButtons();
                    }
                });
            });

            targetEl.addEventListener('change', syncButtons);
            const parentForm = targetEl.form || group.closest('form');
            parentForm?.addEventListener('reset', () => {
                window.setTimeout(syncButtons, 0);
            });

            syncButtons();
        });
    }

    const EYE_OPEN_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const EYE_OFF_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function initPasswordToggles(root = document) {
        const inputs = root.querySelectorAll('input[type="password"]:not([data-no-pw-toggle="1"])');
        inputs.forEach((input) => {
            if (input.closest('.agap-pw-wrap') || input.dataset.agapPwBound === '1') return;
            input.dataset.agapPwBound = '1';
            input.dataset.agapPasswordField = '1';

            const wrap = document.createElement('div');
            wrap.className = 'agap-pw-wrap';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);

            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'agap-pw-toggle-btn';
            toggleBtn.setAttribute('aria-label', 'Show password');
            toggleBtn.setAttribute('aria-pressed', 'false');
            toggleBtn.setAttribute('title', 'Show password');
            toggleBtn.innerHTML = EYE_OPEN_SVG;
            wrap.appendChild(toggleBtn);

            const setVisible = (visible) => {
                input.type = visible ? 'text' : 'password';
                toggleBtn.setAttribute('aria-pressed', visible ? 'true' : 'false');
                toggleBtn.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
                toggleBtn.setAttribute('title', visible ? 'Hide password' : 'Show password');
                toggleBtn.innerHTML = visible ? EYE_OFF_SVG : EYE_OPEN_SVG;
            };

            toggleBtn.addEventListener('click', () => {
                const nextVisible = input.type === 'password';
                setVisible(nextVisible);
                try {
                    const pos = input.value.length;
                    input.focus({ preventScroll: true });
                    input.setSelectionRange(pos, pos);
                } catch (_) {
                    // Selection range is optional.
                }
            });

            const parentForm = input.form || input.closest('form');
            parentForm?.addEventListener('reset', () => {
                window.setTimeout(() => setVisible(false), 0);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initSegmentedControls();
            initPasswordToggles();
        });
    } else {
        initSegmentedControls();
        initPasswordToggles();
    }

    window.agapInitSegmentedControls = initSegmentedControls;
    window.agapInitPasswordToggles = initPasswordToggles;
})();
