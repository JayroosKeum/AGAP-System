/**
 * Modern Floating Toast Notification System (Global)
 * Lightweight, non-blocking, zero layout-shift notifications for AGAP.
 */
(function() {
    function getContainer() {
        let container = document.getElementById('agapToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'agapToastContainer';
            if (document.body) {
                document.body.appendChild(container);
            } else {
                document.addEventListener('DOMContentLoaded', () => {
                    if (!document.getElementById('agapToastContainer') && document.body) {
                        document.body.appendChild(container);
                    }
                });
            }
        }
        return container;
    }

    if (document.body) {
        getContainer();
    } else {
        document.addEventListener('DOMContentLoaded', getContainer);
    }

    const defaultIcons = {
        success: '✓',
        error: '✗',
        warning: '⚠️',
        info: 'ℹ️'
    };

    const defaultTitles = {
        success: 'Success',
        error: 'Error / Attention Needed',
        warning: 'Warning',
        info: 'Notice'
    };

    function escapeToastText(str) {
        if (!str && str !== 0) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    window.agapNotify = function(message, type = 'success', title = null, duration = 4000) {
        if (!message && message !== 0) return;
        const container = getContainer();

        const validTypes = ['success', 'error', 'warning', 'info'];
        const toastType = validTypes.includes(type) ? type : 'info';
        const toastTitle = title || defaultTitles[toastType];
        const toastIcon = defaultIcons[toastType];

        const toast = document.createElement('div');
        toast.className = `agap-toast toast-${toastType}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML = `
            <div class="agap-toast-icon">${toastIcon}</div>
            <div class="agap-toast-content">
                <div class="agap-toast-title">${escapeToastText(toastTitle)}</div>
                <div class="agap-toast-message">${escapeToastText(message).replace(/\n/g, '<br>')}</div>
            </div>
            <button type="button" class="agap-toast-close" aria-label="Close">&times;</button>
            <div class="agap-toast-progress"></div>
        `;

        if (container && container.parentElement) {
            container.appendChild(toast);
        } else if (document.body) {
            document.body.appendChild(container);
            container.appendChild(toast);
        } else {
            document.addEventListener('DOMContentLoaded', () => {
                const c = getContainer();
                if (c && !c.parentElement && document.body) document.body.appendChild(c);
                if (c) c.appendChild(toast);
            });
        }

        const closeBtn = toast.querySelector('.agap-toast-close');
        const progressBar = toast.querySelector('.agap-toast-progress');

        let startTime = Date.now();
        let remaining = duration;
        let timer = null;
        let progressAnim = null;
        let isClosed = false;

        function closeToast() {
            if (isClosed) return;
            isClosed = true;
            clearTimeout(timer);
            if (progressAnim) cancelAnimationFrame(progressAnim);
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(50px)';
            setTimeout(() => {
                try { toast.remove(); } catch (_) {}
            }, 200);
        }

        function updateProgress() {
            if (isClosed) return;
            const elapsed = Date.now() - startTime;
            const pct = Math.max(0, 1 - (elapsed / duration));
            if (progressBar) progressBar.style.transform = `scaleX(${pct})`;
            if (pct > 0 && !isClosed) {
                progressAnim = requestAnimationFrame(updateProgress);
            }
        }

        function startTimer() {
            if (isClosed) return;
            startTime = Date.now();
            timer = setTimeout(closeToast, remaining);
            progressAnim = requestAnimationFrame(updateProgress);
        }

        function pauseTimer() {
            if (isClosed) return;
            clearTimeout(timer);
            if (progressAnim) cancelAnimationFrame(progressAnim);
            remaining -= (Date.now() - startTime);
            if (remaining < 0) remaining = 0;
        }

        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            closeToast();
        });

        toast.addEventListener('mouseenter', pauseTimer);
        toast.addEventListener('mouseleave', () => {
            if (remaining > 0 && !isClosed) startTimer();
        });

        startTimer();
    };
})();
