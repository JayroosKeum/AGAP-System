/**
 * AGAP Notifications Inbox Engine
 * Real-time filter stream, direct case navigation, relative timestamps, and 1-click Mark All Read.
 */

let allNotifications = [];
let currentNotifFilter = 'all';

document.addEventListener('DOMContentLoaded', () => {
    loadNotifications();
});

/**
 * Fetch notifications from the backend API
 */
async function loadNotifications() {
    const listEl = document.getElementById('notificationList');
    const summaryEl = document.getElementById('notificationSummary');

    try {
        if (summaryEl) summaryEl.textContent = 'Refreshing notifications...';
        const result = await requestNotifications('../../../backend/api/notifications/list.php');

        allNotifications = Array.isArray(result.data) ? result.data : [];
        const unreadCount = Number(result.unread_count || 0);

        // Update top-level header action state
        const markAllBtn = document.getElementById('btnMarkAllRead');
        if (markAllBtn) {
            markAllBtn.disabled = unreadCount === 0;
            markAllBtn.title = unreadCount === 0 ? 'No unread notifications' : 'Mark all notifications as read';
        }

        // Update summary text
        if (summaryEl) {
            summaryEl.textContent = unreadCount > 0
                ? `${unreadCount} unread notification${unreadCount === 1 ? '' : 's'} requiring attention.`
                : 'You are all caught up! No unread notifications.';
        }

        updateFilterCounts();
        renderFilteredNotifications();
    } catch (error) {
        console.error('Failed to load notifications:', error);
        if (listEl) {
            listEl.innerHTML = `
                <div class="notif-empty-state">
                    <span class="notif-empty-icon">⚠️</span>
                    <div class="notif-empty-title">Unable to Load Notifications</div>
                    <p class="notif-empty-desc">${escapeHtml(error.message || 'Server connection error.')}</p>
                </div>
            `;
        }
        if (window.agapNotify) {
            window.agapNotify('Failed to retrieve notification updates.', 'error');
        }
    }
}
window.loadNotifications = loadNotifications;

/**
 * Update counts on the filter pills
 */
function updateFilterCounts() {
    const counts = {
        all: allNotifications.length,
        urgent: 0,
        warning: 0,
        info: 0
    };

    allNotifications.forEach(item => {
        const sev = (item.severity || 'info').toLowerCase();
        if (sev === 'urgent') counts.urgent++;
        else if (sev === 'warning') counts.warning++;
        else counts.info++;
    });

    const elAll = document.getElementById('countAll');
    const elUrgent = document.getElementById('countUrgent');
    const elWarning = document.getElementById('countWarning');
    const elInfo = document.getElementById('countInfo');

    if (elAll) elAll.textContent = counts.all;
    if (elUrgent) elUrgent.textContent = counts.urgent;
    if (elWarning) elWarning.textContent = counts.warning;
    if (elInfo) elInfo.textContent = counts.info;
}

/**
 * Filter change handler
 */
function setNotifFilter(filterKey) {
    currentNotifFilter = filterKey;
    document.querySelectorAll('.notif-filter-pill').forEach(pill => {
        pill.classList.toggle('active', pill.dataset.filter === filterKey);
    });
    renderFilteredNotifications();
}
window.setNotifFilter = setNotifFilter;

/**
 * Render the filtered notification cards
 */
function renderFilteredNotifications() {
    const listEl = document.getElementById('notificationList');
    if (!listEl) return;

    listEl.replaceChildren();

    const filtered = allNotifications.filter(item => {
        const sev = (item.severity || 'info').toLowerCase();
        if (currentNotifFilter === 'all') return true;
        if (currentNotifFilter === 'urgent') return sev === 'urgent';
        if (currentNotifFilter === 'warning') return sev === 'warning';
        if (currentNotifFilter === 'info') return sev === 'info' || sev === 'success';
        return true;
    });

    if (filtered.length === 0) {
        const emptyDiv = document.createElement('div');
        emptyDiv.className = 'notif-empty-state';
        emptyDiv.innerHTML = `
            <span class="notif-empty-icon">🎉</span>
            <div class="notif-empty-title">All caught up!</div>
            <p class="notif-empty-desc">No notifications found in the "${escapeHtml(currentNotifFilter)}" category.</p>
        `;
        listEl.appendChild(emptyDiv);
        return;
    }

    filtered.forEach(item => {
        const card = createNotificationCard(item);
        listEl.appendChild(card);
    });
}

/**
 * Create a single compact activity card element
 */
function createNotificationCard(item) {
    const isUnread = !Number(item.is_read);
    const sev = (item.severity || 'info').toLowerCase();

    // Map severity to icons and badges
    let icon = '📄';
    let badgeLabel = 'UPDATE';
    let badgeClass = 'badge-info';

    if (sev === 'urgent') {
        icon = '🔴';
        badgeLabel = 'URGENT';
        badgeClass = 'badge-urgent';
    } else if (sev === 'warning') {
        icon = '⚠️';
        badgeLabel = 'DEADLINE';
        badgeClass = 'badge-warning';
    } else if (sev === 'success') {
        icon = '✓';
        badgeLabel = 'RESOLVED';
        badgeClass = 'badge-success';
    }

    // Resolve direct action URL
    let actionUrl = item.action_url || '';
    if (!actionUrl && item.case_id) {
        actionUrl = `../cases/case-details.php?id=${encodeURIComponent(item.case_id)}`;
    }
    if (!actionUrl) {
        // Fallback: check text for case number
        const match = (item.title + ' ' + item.message).match(/(?:Case|case)\s*(?:#|No\.\s*|number\s*)?([0-9A-Za-z\-_]+)/i);
        if (match && match[1]) {
            actionUrl = `../search/records.php?q=${encodeURIComponent(match[1])}`;
        }
    }

    const card = document.createElement('article');
    card.className = `notification-card severity-${sev}${isUnread ? ' unread' : ''}`;
    card.dataset.id = item.notification_id;
    card.dataset.severity = sev;

    // Time handling
    const rawDate = item.created_at || '';
    const relTime = formatRelativeTime(rawDate);
    const fullTime = rawDate ? new Date(rawDate.replace(' ', 'T')).toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short'
    }) : '';

    // Actions row HTML
    let actionsHtml = '';
    if (actionUrl) {
        actionsHtml += `
            <a href="${escapeAttr(actionUrl)}" class="btn-notif-action btn-open-case">
                🚀 Open Case
            </a>
        `;
    }
    if (isUnread) {
        actionsHtml += `
            <button type="button" class="btn-notif-action btn-mark-read" onclick="markRead(${item.notification_id})">
                ✓ Mark Read
            </button>
        `;
    } else {
        actionsHtml += `
            <span class="badge-read-status" title="${item.read_at ? 'Read on ' + item.read_at : 'Read'}">
                ✓ Read
            </span>
        `;
    }

    card.innerHTML = `
        <div class="notif-card-indicator" aria-hidden="true">${icon}</div>
        <div class="notif-card-main">
            <div class="notif-card-header">
                <div class="notif-title-group">
                    <span class="notif-severity-badge ${badgeClass}">${badgeLabel}</span>
                    <h2 class="notif-title">${escapeHtml(item.title)}</h2>
                </div>
                <time class="notif-time" datetime="${escapeAttr(rawDate)}" title="${escapeAttr(fullTime)}">${escapeHtml(relTime)}</time>
            </div>
            <p class="notif-message">${escapeHtml(item.message)}</p>
            <div class="notif-actions-row">
                ${actionsHtml}
            </div>
        </div>
    `;

    return card;
}

/**
 * Relative time formatter
 */
function formatRelativeTime(dateString) {
    if (!dateString) return '';
    try {
        const then = new Date(dateString.replace(' ', 'T')).getTime();
        if (isNaN(then)) return dateString;

        const now = Date.now();
        const diffSeconds = Math.max(0, Math.floor((now - then) / 1000));

        if (diffSeconds < 60) return 'Just now';
        const diffMins = Math.floor(diffSeconds / 60);
        if (diffMins < 60) return `${diffMins} min${diffMins === 1 ? '' : 's'} ago`;
        const diffHours = Math.floor(diffMins / 60);
        if (diffHours < 24) return `${diffHours} hour${diffHours === 1 ? '' : 's'} ago`;
        const diffDays = Math.floor(diffHours / 24);
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return `${diffDays} days ago`;

        return new Date(then).toLocaleDateString([], {
            month: 'short',
            day: 'numeric',
            year: (new Date().getFullYear() !== new Date(then).getFullYear()) ? 'numeric' : undefined
        });
    } catch (_) {
        return dateString;
    }
}

/**
 * Mark a single notification as read
 */
async function markRead(notificationId) {
    try {
        const result = await requestNotifications('../../../backend/api/notifications/read.php', {
            method: 'POST',
            body: new URLSearchParams({ notification_id: notificationId })
        });

        // Update local item
        const item = allNotifications.find(n => Number(n.notification_id) === Number(notificationId));
        if (item) {
            item.is_read = 1;
            item.read_at = new Date().toISOString();
        }

        if (window.agapNotify) {
            window.agapNotify(result.message || 'Notification marked as read.', 'success');
        }

        updateFilterCounts();
        renderFilteredNotifications();

        // Update summary
        const unreadRemaining = allNotifications.filter(n => !Number(n.is_read)).length;
        const summaryEl = document.getElementById('notificationSummary');
        if (summaryEl) {
            summaryEl.textContent = unreadRemaining > 0
                ? `${unreadRemaining} unread notification${unreadRemaining === 1 ? '' : 's'} requiring attention.`
                : 'You are all caught up! No unread notifications.';
        }
        const markAllBtn = document.getElementById('btnMarkAllRead');
        if (markAllBtn) markAllBtn.disabled = unreadRemaining === 0;

    } catch (error) {
        if (window.agapNotify) {
            window.agapNotify(error.message || 'Could not mark notification as read.', 'error');
        }
    }
}
window.markRead = markRead;

/**
 * Mark all notifications as read in 1 click
 */
async function markAllAsRead() {
    const btn = document.getElementById('btnMarkAllRead');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Marking all read...';
    }

    try {
        const result = await requestNotifications('../../../backend/api/notifications/mark-all-read.php', {
            method: 'POST'
        });

        allNotifications.forEach(n => {
            n.is_read = 1;
            n.read_at = n.read_at || new Date().toISOString();
        });

        if (window.agapNotify) {
            window.agapNotify(result.message || 'All notifications marked as read.', 'success', 'Inbox Cleared');
        }

        updateFilterCounts();
        renderFilteredNotifications();

        const summaryEl = document.getElementById('notificationSummary');
        if (summaryEl) {
            summaryEl.textContent = 'You are all caught up! No unread notifications.';
        }
        if (btn) {
            btn.innerHTML = '<span class="btn-icon">✓</span> Mark All as Read';
            btn.disabled = true;
        }
    } catch (error) {
        if (window.agapNotify) {
            window.agapNotify(error.message || 'Failed to mark all as read.', 'error');
        }
        if (btn) {
            btn.innerHTML = '<span class="btn-icon">✓</span> Mark All as Read';
            btn.disabled = false;
        }
    }
}
window.markAllAsRead = markAllAsRead;

/**
 * Network fetch wrapper
 */
async function requestNotifications(url, options = {}) {
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || result.success === false) throw new Error(result.message || 'Request failed.');
    return result;
}

function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    const node = document.createElement('div');
    node.textContent = String(value);
    return node.innerHTML;
}

function escapeAttr(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}
