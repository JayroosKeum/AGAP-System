/**
 * AGAP Notifications System (Global)
 * 1. Modern Floating Toast Notification Engine (agapNotify)
 * 2. Navbar Bell Popover Dropdown Engine (with tabs, relative time, live badges, and 1-click batch read)
 */

(function() {
    // =========================================================================
    // 1. Modern Floating Toast Notification Engine
    // =========================================================================

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
        error: '✕',
        warning: '!',
        info: 'i'
    };

    const defaultTitles = {
        success: 'Success',
        error: 'Error / Attention Needed',
        warning: 'Warning',
        info: 'Notice'
    };

    /**
     * Safely parse JSON from fetch Response, handling non-JSON/HTML error pages
     * without throwing raw syntax errors.
     */
    async function safeParseJson(response, fallbackMsg = 'Failed to process server response.') {
        let rawText = '';
        try {
            rawText = await response.text();
        } catch (_) {
            throw new Error('Unable to read server response.');
        }

        const trimmed = (rawText || '').trim();
        let parsed = null;
        if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
            try {
                parsed = JSON.parse(trimmed);
            } catch (_) {}
        }

        if (parsed && typeof parsed === 'object') {
            if (!response.ok && (parsed.message || parsed.error_detail)) {
                throw new Error(parsed.message || parsed.error_detail || fallbackMsg);
            }
            return parsed;
        }

        if (!response.ok) {
            throw new Error(`Server returned error HTTP ${response.status}. Please try again later.`);
        }

        throw new Error(fallbackMsg);
    }
    window.safeParseJson = safeParseJson;

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

    // =========================================================================
    // 2. Navbar Bell Popover Dropdown Engine
    // =========================================================================

    let popoverNotifications = [];
    let popoverActiveTab = 'all';
    let isPopoverOpen = false;

    window.toggleNotificationPopover = function(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }

        const popover = document.getElementById('navNotificationPopover');
        const bellBtn = document.getElementById('navBellBtn');
        if (!popover || !bellBtn) return;

        isPopoverOpen = !isPopoverOpen;
        if (isPopoverOpen) {
            popover.style.display = 'flex';
            bellBtn.classList.add('active');
            bellBtn.setAttribute('aria-expanded', 'true');
            loadPopoverNotifications();
        } else {
            closeNotificationPopover();
        }
    };

    window.closeNotificationPopover = function() {
        const popover = document.getElementById('navNotificationPopover');
        const bellBtn = document.getElementById('navBellBtn');
        if (popover) popover.style.display = 'none';
        if (bellBtn) {
            bellBtn.classList.remove('active');
            bellBtn.setAttribute('aria-expanded', 'false');
        }
        isPopoverOpen = false;
    };

    window.setPopoverTab = function(tabKey, e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        popoverActiveTab = tabKey;
        document.querySelectorAll('.nav-tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tabKey);
        });
        renderPopoverList();
    };

    async function loadPopoverNotifications() {
        const listEl = document.getElementById('navPopoverList');
        if (!listEl) return;

        try {
            const res = await fetch('../../../backend/api/notifications/list.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await safeParseJson(res, 'Failed to load notifications.');
            if (res.ok && data.success) {
                popoverNotifications = Array.isArray(data.data) ? data.data : [];
                const unreadCount = Number(data.unread_count || 0);
                updateNavNotificationBadge(unreadCount);
                updatePopoverCounts();
                renderPopoverList();
            } else {
                listEl.innerHTML = '<div class="nav-popover-empty">Could not load notifications.</div>';
            }
        } catch (_) {
            if (listEl) listEl.innerHTML = '<div class="nav-popover-empty">Failed to load notifications.</div>';
        }
    }
    window.loadPopoverNotifications = loadPopoverNotifications;

    function updateNavNotificationBadge(unreadCount) {
        const badge = document.getElementById('navBellBadge');
        if (badge) {
            badge.style.display = unreadCount > 0 ? 'block' : 'none';
            badge.title = `${unreadCount} unread notification${unreadCount === 1 ? '' : 's'}`;
        }
    }
    window.updateNavNotificationBadge = updateNavNotificationBadge;

    function updatePopoverCounts() {
        const counts = { all: popoverNotifications.length, urgent: 0, warning: 0 };
        popoverNotifications.forEach(n => {
            const sev = (n.severity || 'info').toLowerCase();
            if (sev === 'urgent') counts.urgent++;
            else if (sev === 'warning') counts.warning++;
        });

        const elAll = document.getElementById('popoverCountAll');
        const elUrgent = document.getElementById('popoverCountUrgent');
        const elWarning = document.getElementById('popoverCountWarning');

        if (elAll) elAll.textContent = counts.all;
        if (elUrgent) elUrgent.textContent = counts.urgent;
        if (elWarning) elWarning.textContent = counts.warning;

        const markAllBtn = document.getElementById('popoverMarkAllBtn');
        const hasUnread = popoverNotifications.some(n => !Number(n.is_read));
        if (markAllBtn) markAllBtn.style.display = hasUnread ? 'inline' : 'none';
    }

    function renderPopoverList() {
        const listEl = document.getElementById('navPopoverList');
        if (!listEl) return;

        listEl.replaceChildren();

        const filtered = popoverNotifications.filter(item => {
            const sev = (item.severity || 'info').toLowerCase();
            if (popoverActiveTab === 'all') return true;
            if (popoverActiveTab === 'urgent') return sev === 'urgent';
            if (popoverActiveTab === 'warning') return sev === 'warning';
            return true;
        });

        if (filtered.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'nav-popover-empty';
            empty.textContent = 'No notifications in this tab.';
            listEl.appendChild(empty);
            return;
        }

        filtered.slice(0, 15).forEach(item => {
            const card = createPopoverItem(item);
            listEl.appendChild(card);
        });
    }

    function stripNotificationEmoji(text) {
        if (!text && text !== 0) return '';
        return String(text)
            .replace(/[\u{1F300}-\u{1F9FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{1F1E6}-\u{1F1FF}\u{1F600}-\u{1F64F}\u{1F680}-\u{1F6FF}\u{2300}-\u{23FF}]/gu, '')
            .replace(/^[:\s\-–—]+|[:\s\-–—]+$/g, '')
            .trim();
    }

    function createPopoverItem(item) {
        const isUnread = !Number(item.is_read);
        const sev = (item.severity || 'info').toLowerCase();

        // Avatar styling & clean SVG icon
        let avClass = 'av-info';
        let dotClass = 'dot-blue';
        let avIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>';

        if (sev === 'urgent') {
            avClass = 'av-urgent';
            dotClass = 'dot-red';
            avIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
        } else if (sev === 'warning') {
            avClass = 'av-warning';
            dotClass = 'dot-orange';
            avIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
        } else if (sev === 'success') {
            avClass = 'av-success';
            dotClass = 'dot-green';
            avIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        }

        // Action URL
        let actionUrl = item.action_url || '';
        if (!actionUrl && item.case_id) {
            actionUrl = `../cases/case-details.php?id=${encodeURIComponent(item.case_id)}`;
        }
        if (!actionUrl) {
            const match = (item.title + ' ' + item.message).match(/(?:Case|case)\s*(?:#|No\.\s*|number\s*)?([0-9A-Za-z\-_]+)/i);
            if (match && match[1]) {
                actionUrl = `../search/records.php?q=${encodeURIComponent(match[1])}`;
            }
        }

        const el = document.createElement('div');
        el.className = `nav-popover-item${isUnread ? ' unread' : ''}`;
        el.dataset.id = item.notification_id;

        const relTime = formatPopoverRelativeTime(item.created_at || '');

        let actionsHtml = '';
        if (actionUrl) {
            actionsHtml += `
                <a href="${escapePopoverAttr(actionUrl)}" class="btn-popover-primary" onclick="handlePopoverActionClick(event, ${item.notification_id}, '${escapePopoverAttr(actionUrl)}')">
                    Open Case
                </a>
            `;
        }
        if (isUnread) {
            actionsHtml += `
                <button type="button" class="btn-popover-secondary" onclick="markReadFromPopover(${item.notification_id}, event)">
                    Mark Read
                </button>
            `;
        }

        const cleanTitle = stripNotificationEmoji(item.title);
        el.innerHTML = `
            <div class="nav-item-avatar ${avClass}">
                ${avIcon}
                <span class="nav-item-status-dot ${dotClass}"></span>
            </div>
            <div class="nav-item-body">
                <div class="nav-item-title">${escapePopoverHtml(cleanTitle)}</div>
                <div class="nav-item-sub">${escapePopoverHtml(relTime)}${item.case_number ? ' • ' + escapePopoverHtml(item.case_number) : ''}</div>
                ${actionsHtml ? `<div class="nav-item-actions">${actionsHtml}</div>` : ''}
            </div>
        `;

        return el;
    }

    window.handlePopoverActionClick = async function(event, notificationId, url) {
        event.stopPropagation();
        try {
            await fetch('../../../backend/api/notifications/read.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: new URLSearchParams({ notification_id: notificationId })
            });
        } catch (_) {}
        window.location.href = url;
    };

    window.markReadFromPopover = async function(notificationId, event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        try {
            const res = await fetch('../../../backend/api/notifications/read.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: new URLSearchParams({ notification_id: notificationId })
            });
            const data = await safeParseJson(res, 'Failed to mark notification read.');
            if (data && data.success) {
                const item = popoverNotifications.find(n => Number(n.notification_id) === Number(notificationId));
                if (item) {
                    item.is_read = 1;
                    item.read_at = new Date().toISOString();
                }
                const unreadLeft = popoverNotifications.filter(n => !Number(n.is_read)).length;
                updateNavNotificationBadge(unreadLeft);
                updatePopoverCounts();
                renderPopoverList();

                // If on inbox page, also refresh inbox stream
                if (typeof window.loadNotifications === 'function') {
                    window.loadNotifications();
                }
            }
        } catch (_) {}
    };

    window.markAllAsReadFromPopover = async function(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        const markBtn = document.getElementById('popoverMarkAllBtn');
        if (markBtn) markBtn.textContent = 'Marking...';

        try {
            const res = await fetch('../../../backend/api/notifications/mark-all-read.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json' }
            });
            const data = await safeParseJson(res, 'Failed to mark all as read.');
            if (data && data.success) {
                popoverNotifications.forEach(n => {
                    n.is_read = 1;
                    n.read_at = n.read_at || new Date().toISOString();
                });
                updateNavNotificationBadge(0);
                updatePopoverCounts();
                renderPopoverList();

                if (window.agapNotify) {
                    window.agapNotify('All notifications marked as read.', 'success');
                }

                if (typeof window.loadNotifications === 'function') {
                    window.loadNotifications();
                }
            }
        } catch (_) {
        } finally {
            if (markBtn) markBtn.textContent = 'Mark all as read';
        }
    };

    function formatPopoverRelativeTime(dateString) {
        if (!dateString) return '';
        try {
            const then = new Date(dateString.replace(' ', 'T')).getTime();
            if (isNaN(then)) return dateString;
            const diffSeconds = Math.max(0, Math.floor((Date.now() - then) / 1000));
            if (diffSeconds < 60) return 'Just now';
            const diffMins = Math.floor(diffSeconds / 60);
            if (diffMins < 60) return `${diffMins}m ago`;
            const diffHours = Math.floor(diffMins / 60);
            if (diffHours < 24) return `${diffHours}h ago`;
            const diffDays = Math.floor(diffHours / 24);
            if (diffDays === 1) return 'Yesterday';
            if (diffDays < 7) return `${diffDays}d ago`;
            return new Date(then).toLocaleDateString([], { month: 'short', day: 'numeric' });
        } catch (_) {
            return dateString;
        }
    }

    function escapePopoverHtml(str) {
        if (!str && str !== 0) return '';
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    function escapePopoverAttr(str) {
        if (!str && str !== 0) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Dismiss popover on outside click or Escape key
    document.addEventListener('click', (e) => {
        const wrapper = document.getElementById('navNotificationWrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            closeNotificationPopover();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeNotificationPopover();
        }
    });

    // Check notifications on initial load to set badge state
    document.addEventListener('DOMContentLoaded', async () => {
        const badge = document.getElementById('navBellBadge');
        if (!badge) return;
        try {
            const res = await fetch('../../../backend/api/notifications/list.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await safeParseJson(res);
            if (res.ok && data && data.success) {
                const count = Number(data.unread_count || 0);
                updateNavNotificationBadge(count);
            }
        } catch (_) {}
    });

})();
