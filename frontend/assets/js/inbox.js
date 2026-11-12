function stripNotificationEmoji(text) {
    if (!text && text !== 0) return '';
    return String(text).replace(/[\u{1F300}-\u{1F9FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{1F1E6}-\u{1F1FF}\u{1F600}-\u{1F64F}\u{1F680}-\u{1F6FF}\u{2300}-\u{23FF}]/gu, '').replace(/^[:\s\-–—]+|[:\s\-–—]+$/g, '').trim();
}
/**
 * AGAP Notifications Inbox Engine
 * Layout and interaction designed to match modern activity format:
 * - Unread / Read tab navigation
 * - Real-time keyword filter and sorting
 * - Clean row layout: [dot] [Title + Message] [Category/Tag] [Timestamp] [··· Action]
 * - 1-Click Mark All as Read, Mark as Unread, and Direct Navigation.
 */

let allNotifications = [];
let currentStatusFilter = 'all';
let notifSearchQuery = '';
let notifCategoryFilter = 'all';
let notifSeverityFilter = 'all';
let notifDateFilter = 'all';
let notifSortBy = 'date_desc';
let selectedNotificationIds = new Set();

document.addEventListener('DOMContentLoaded', () => {
    loadNotifications();

    // Close any active dropdown menu or toolbar popover on outside click
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.notif-row-more')) {
            closeAllMenus();
        }
        if (!e.target.closest('.notif-popover-wrap')) {
            closeToolbarPopovers();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllMenus();
            closeToolbarPopovers();
        }
    });
});

/**
 * Fetch all notifications for the current user
 */
/**
 * Safely parses JSON response from API.
 * Handles HTML error pages, non-JSON output, and HTTP errors gracefully
 * without throwing unhandled SyntaxErrors.
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
async function loadNotifications() {
    const listEl = document.getElementById('notificationList');

    try {
        const response = await fetch('../../../backend/api/notifications/list.php', {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        });

        const result = await safeParseJson(response, 'Failed to load notifications.');

        if (result.status === 'error' || result.success === false) {
            throw new Error(result.message || 'Failed to retrieve notification updates.');
        }

        allNotifications = Array.isArray(result.data) ? result.data : [];

        updateTabBadges();
        updateCategoryChipCounts();
        renderNotifications();
    } catch (error) {
        console.error('Failed to load notifications:', error);
        if (listEl) {
            listEl.innerHTML = `
                <div class="notif-empty-state">
                    
                    <div class="notif-empty-title">Unable to Load Notifications</div>
                    <p class="notif-empty-desc">${escapeHtml(error.message || 'Server connection error.')}</p>
                </div>
            `;
        }
        if (window.agapNotify) {
            window.agapNotify(error.message || 'Failed to retrieve notification updates.', 'error');
        }
    }
}
window.loadNotifications = loadNotifications;

/**
 * Update unread/read badge counters and toolbar button state
 */
function updateTabBadges() {
    const totalCount = allNotifications.length;
    const unreadCount = allNotifications.filter(n => !Number(n.is_read)).length;
    const readCount = allNotifications.filter(n => Number(n.is_read) === 1).length;
    const urgentCount = allNotifications.filter(n => {
        const cat = inferCategoryTag(n.title || '', n.message || '', n.severity || 'info');
        const sev = (n.severity && n.severity !== 'info') ? String(n.severity).toLowerCase() : cat.severity;
        return sev === 'urgent' || sev === 'warning';
    }).length;

    const allBadge = document.getElementById('tabCountAll');
    const unreadBadge = document.getElementById('tabCountUnread');
    const readBadge = document.getElementById('tabCountRead');
    const markAllBtn = document.getElementById('btnMarkAllRead');

    if (allBadge) allBadge.textContent = totalCount;
    if (unreadBadge) unreadBadge.textContent = unreadCount;
    if (readBadge) readBadge.textContent = readCount;

    const statTotal = document.getElementById('notifStatTotal');
    const statUnread = document.getElementById('notifStatUnread');
    const statUrgent = document.getElementById('notifStatUrgent');
    const statRead = document.getElementById('notifStatRead');
    if (statTotal) statTotal.textContent = totalCount;
    if (statUnread) statUnread.textContent = unreadCount;
    if (statUrgent) statUrgent.textContent = urgentCount;
    if (statRead) statRead.textContent = readCount;

    if (markAllBtn) {
        markAllBtn.disabled = unreadCount === 0;
        markAllBtn.style.opacity = unreadCount === 0 ? '0.5' : '1';
        markAllBtn.style.cursor = unreadCount === 0 ? 'not-allowed' : 'pointer';
    }
}

/**
 * Update category filter option live count badges
 */
function updateCategoryChipCounts() {
    const scopedRows = allNotifications.filter(item => {
        const isUnread = !Number(item.is_read);
        if (currentStatusFilter === 'unread') return isUnread;
        if (currentStatusFilter === 'read') return !isUnread;
        return true;
    });

    const counts = {
        all: scopedRows.length,
        critical: 0,
        warning: 0,
        hearing: 0,
        summons: 0,
        pangkat: 0,
        complaint: 0,
        document: 0,
        success: 0
    };

    scopedRows.forEach(item => {
        const cat = inferCategoryTag(item.title || '', item.message || '', item.severity || 'info');
        if (Object.prototype.hasOwnProperty.call(counts, cat.key)) {
            counts[cat.key]++;
        }
    });

    Object.keys(counts).forEach(key => {
        const el = document.getElementById(`chipCount_${key}`);
        if (el) el.textContent = counts[key];
    });
}

function toggleToolbarPopover(event, popoverId) {
    if (event) event.stopPropagation();
    const target = document.getElementById(popoverId);
    if (!target) return;

    const wasHidden = target.hidden;
    closeToolbarPopovers();
    closeAllMenus();

    if (wasHidden) {
        target.hidden = false;
        const trigger = target.closest('.notif-popover-wrap')?.querySelector('.notif-tool-btn');
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
    }
}
window.toggleToolbarPopover = toggleToolbarPopover;

function closeToolbarPopovers() {
    document.querySelectorAll('.notif-popover-menu').forEach(menu => {
        menu.hidden = true;
    });
    document.querySelectorAll('.notif-popover-wrap .notif-tool-btn').forEach(btn => {
        btn.setAttribute('aria-expanded', 'false');
    });
}
window.closeToolbarPopovers = closeToolbarPopovers;

function toggleAnalyticsStrip() {
    const strip = document.getElementById('notifSummaryStrip');
    const btn = document.getElementById('btnNotifAnalytics');
    if (!strip || !btn) return;
    const willOpen = strip.hidden;
    strip.hidden = !willOpen;
    btn.classList.toggle('is-active', willOpen);
    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
}
window.toggleAnalyticsStrip = toggleAnalyticsStrip;

/**
 * Filter by All / Unread / Read
 */
function setStatusFilter(status) {
    const normalized = (status === 'unread' || status === 'read') ? status : 'all';
    currentStatusFilter = normalized;

    document.querySelectorAll('.notif-status-pill-btn[data-status-filter]').forEach(btn => {
        btn.classList.toggle('is-active', btn.getAttribute('data-status-filter') === normalized);
    });

    selectedNotificationIds.clear();
    closeAllMenus();
    updateCategoryChipCounts();
    updateClearFiltersButton();
    renderNotifications();
}
window.setStatusFilter = setStatusFilter;
window.setNotifTab = setStatusFilter;

/**
 * Real-time search handler
 */
function handleSearchInput(e) {
    notifSearchQuery = (e.target.value || '').trim().toLowerCase();
    const clearBtn = document.getElementById('notifClearSearch');
    if (clearBtn) {
        clearBtn.style.display = notifSearchQuery ? 'block' : 'none';
    }
    updateClearFiltersButton();
    renderNotifications();
}
window.handleSearchInput = handleSearchInput;

function clearSearch() {
    const input = document.getElementById('notifSearchInput');
    const clearBtn = document.getElementById('notifClearSearch');
    if (input) input.value = '';
    if (clearBtn) clearBtn.style.display = 'none';
    notifSearchQuery = '';
    updateClearFiltersButton();
    renderNotifications();
}
window.clearSearch = clearSearch;

/**
 * Category / Type Filter Handler
 */
function setCategoryFilter(category) {
    notifCategoryFilter = category || 'all';
    const labelMap = {
        all: 'Type',
        critical: 'Critical',
        warning: 'Timeline',
        hearing: 'Hearing',
        summons: 'Summons',
        pangkat: 'Pangkat',
        complaint: 'Intake',
        document: 'Document',
        success: 'Resolved',
    };
    const labelEl = document.getElementById('notifTypeBtnLabel');
    if (labelEl) {
        labelEl.textContent = labelMap[notifCategoryFilter] || 'Type';
    }
    const typeBtn = document.getElementById('btnNotifTypeMenu');
    if (typeBtn) {
        typeBtn.classList.toggle('is-filtered', notifCategoryFilter !== 'all');
    }
    document.querySelectorAll('.notif-type-opt').forEach(btn => {
        btn.classList.toggle('is-active', btn.getAttribute('data-category') === notifCategoryFilter);
    });
    closeToolbarPopovers();
    updateClearFiltersButton();
    renderNotifications();
}
window.setCategoryFilter = setCategoryFilter;

/**
 * Severity / Priority Select Filter Handler
 */
function handleSeverityChange(e) {
    notifSeverityFilter = e.target.value || 'all';
    updateClearFiltersButton();
    renderNotifications();
}
window.handleSeverityChange = handleSeverityChange;

/**
 * Date Range Select Filter Handler
 */
function handleDateChange(e) {
    notifDateFilter = e.target.value || 'all';
    updateClearFiltersButton();
    renderNotifications();
}
window.handleDateChange = handleDateChange;

/**
 * Sort change handler
 */
function handleSortChange(e) {
    notifSortBy = e.target.value || 'date_desc';
    renderNotifications();
}
window.handleSortChange = handleSortChange;

/**
 * Reset all active search filters
 */
function resetAllFilters() {
    notifSearchQuery = '';
    notifCategoryFilter = 'all';
    notifSeverityFilter = 'all';
    notifDateFilter = 'all';
    notifSortBy = 'date_desc';
    currentStatusFilter = 'all';

    const searchInput = document.getElementById('notifSearchInput');
    const clearSearchBtn = document.getElementById('notifClearSearch');
    const severitySelect = document.getElementById('notifSeveritySelect');
    const dateSelect = document.getElementById('notifDateSelect');
    const sortSelect = document.getElementById('notifSortSelect');
    const typeLabel = document.getElementById('notifTypeBtnLabel');
    const typeBtn = document.getElementById('btnNotifTypeMenu');

    if (searchInput) searchInput.value = '';
    if (clearSearchBtn) clearSearchBtn.style.display = 'none';
    if (severitySelect) severitySelect.value = 'all';
    if (dateSelect) dateSelect.value = 'all';
    if (sortSelect) sortSelect.value = 'date_desc';
    if (typeLabel) typeLabel.textContent = 'Type';
    if (typeBtn) typeBtn.classList.remove('is-filtered');

    document.querySelectorAll('.notif-status-pill-btn[data-status-filter]').forEach(btn => {
        btn.classList.toggle('is-active', btn.getAttribute('data-status-filter') === 'all');
    });
    document.querySelectorAll('.notif-type-opt').forEach(btn => {
        btn.classList.toggle('is-active', btn.getAttribute('data-category') === 'all');
    });

    updateCategoryChipCounts();
    updateClearFiltersButton();
    renderNotifications();
}
window.resetAllFilters = resetAllFilters;

/**
 * Show / hide active filter indicators
 */
function updateClearFiltersButton() {
    const btn = document.getElementById('btnClearFilters');
    const dot = document.getElementById('notifFilterActiveDot');
    const filterMenuBtn = document.getElementById('btnNotifFilterMenu');

    const hasMenuFilter = Boolean(
        currentStatusFilter !== 'all' ||
        notifSeverityFilter !== 'all' ||
        notifDateFilter !== 'all'
    );
    const hasAnyFilters = Boolean(
        hasMenuFilter ||
        notifSearchQuery ||
        notifCategoryFilter !== 'all'
    );

    if (btn) btn.style.visibility = hasAnyFilters ? 'visible' : 'hidden';
    if (dot) dot.hidden = !hasMenuFilter;
    if (filterMenuBtn) filterMenuBtn.classList.toggle('is-filtered', hasMenuFilter);
}

/**
 * Main render function for notification rows
 */
function renderNotifications() {
    const listEl = document.getElementById('notificationList');
    if (!listEl) return;

    closeAllMenus();

    // 1. Filter by Status (All vs Unread vs Read)
    let rows = allNotifications.filter(item => {
        const isUnread = !Number(item.is_read);
        if (currentStatusFilter === 'unread') return isUnread;
        if (currentStatusFilter === 'read') return !isUnread;
        return true;
    });

    // 2. Filter by search keyword (title, message, case #, complaint #)
    if (notifSearchQuery) {
        rows = rows.filter(item => {
            const combined = `${item.title || ''} ${item.message || ''} ${item.case_number || ''} ${item.complaint_number || ''} ${item.case_id || ''} ${item.complaint_id || ''}`.toLowerCase();
            return combined.includes(notifSearchQuery);
        });
    }

    // 3. Filter by Category
    if (notifCategoryFilter !== 'all') {
        rows = rows.filter(item => {
            const cat = inferCategoryTag(item.title || '', item.message || '', item.severity || 'info');
            return cat.key === notifCategoryFilter;
        });
    }

    // 4. Filter by Severity / Priority Select
    if (notifSeverityFilter !== 'all') {
        rows = rows.filter(item => {
            const cat = inferCategoryTag(item.title || '', item.message || '', item.severity || 'info');
            const effectiveSeverity = (item.severity && item.severity !== 'info') ? item.severity.toLowerCase() : cat.severity;
            return effectiveSeverity === notifSeverityFilter.toLowerCase();
        });
    }

    // 5. Filter by Date range
    if (notifDateFilter !== 'all') {
        const now = Date.now();
        rows = rows.filter(item => {
            if (!item.created_at) return false;
            const itemTime = new Date(item.created_at.replace(' ', 'T')).getTime();
            if (isNaN(itemTime)) return false;
            const diffDays = (now - itemTime) / (1000 * 60 * 60 * 24);

            if (notifDateFilter === 'today') {
                const itemDate = new Date(itemTime);
                const today = new Date();
                return (
                    itemDate.getDate() === today.getDate() &&
                    itemDate.getMonth() === today.getMonth() &&
                    itemDate.getFullYear() === today.getFullYear()
                );
            }
            if (notifDateFilter === 'week') {
                return diffDays <= 7;
            }
            if (notifDateFilter === 'month') {
                return diffDays <= 30;
            }
            return true;
        });
    }

    // 6. Sort
    rows.sort((a, b) => {
        if (notifSortBy === 'date_asc') {
            return new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
        }
        if (notifSortBy === 'urgency') {
            const rank = { urgent: 3, warning: 2, info: 1, success: 0 };
            const rA = rank[(a.severity || '').toLowerCase()] || 0;
            const rB = rank[(b.severity || '').toLowerCase()] || 0;
            if (rA !== rB) return rB - rA;
            return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
        }
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
    });

    // 7. Handle empty state
    if (rows.length === 0) {
        updateSelectionUI();
        const hasFilters = Boolean(
            currentStatusFilter !== 'all' ||
            notifSearchQuery ||
            notifCategoryFilter !== 'all' ||
            notifSeverityFilter !== 'all' ||
            notifDateFilter !== 'all'
        );
        let emptyTitle = 'No Notifications Yet';
        let emptyDesc = 'Case updates, hearing reminders, and barangay notices will appear here.';

        if (currentStatusFilter === 'unread') {
            emptyTitle = 'No Unread Notifications';
            emptyDesc = 'All caught up! No unread notifications right now.';
        } else if (currentStatusFilter === 'read') {
            emptyTitle = 'No Read Notifications';
            emptyDesc = 'No read notifications found.';
        }

        if (hasFilters) {
            emptyTitle = 'No Matching Notifications';
            emptyDesc = 'No notifications matched your active search query or filter settings.';
        }

        listEl.innerHTML = `
            <div class="notif-empty-state">
                <div class="notif-empty-title">${escapeHtml(emptyTitle)}</div>
                <p class="notif-empty-desc">${escapeHtml(emptyDesc)}</p>
                ${hasFilters ? `
                    <button type="button" class="notif-btn-switch-tab" onclick="resetAllFilters()">
                        Reset All Filters
                    </button>
                ` : ''}
            </div>
        `;
        return;
    }

    // 8. Build HTML table rows matching the structured 4-column reference layout
    listEl.innerHTML = rows.map(item => {
        const isUnread = !Number(item.is_read);
        const isSelected = selectedNotificationIds.has(Number(item.notification_id));
        const cat = inferCategoryTag(item.title || '', item.message || '', item.severity || 'info');
        const effectiveSeverity = (item.severity && item.severity !== 'info') ? item.severity.toLowerCase() : cat.severity;
        const timeStr = formatNotificationTime(item.created_at);
        const fullTime = item.created_at ? new Date(item.created_at.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '';
        const actionUrl = resolveActionUrl(item);

        return `
            <div class="notif-item-row ${isUnread ? 'unread' : 'read'} severity-${effectiveSeverity} ${isSelected ? 'is-selected' : ''}" data-id="${item.notification_id}" onclick="handleRowClick(event, ${item.notification_id}, '${escapeJs(actionUrl)}')">
                <div class="notif-check-col" onclick="event.stopPropagation()">
                    <input type="checkbox" class="notif-checkbox notif-row-checkbox" value="${item.notification_id}" ${isSelected ? 'checked' : ''} onchange="handleRowCheckChange(event, ${item.notification_id})" aria-label="Select notification">
                </div>
                <div class="notif-row-left">
                    <span class="notif-type-icon-tile tile-${cat.key}" aria-hidden="true">${getCategoryTileSvg(cat.key)}</span>
                    <div class="notif-text-col">
                        <div class="notif-row-title">${escapeHtml(stripNotificationEmoji(item.title) || 'Notification')}</div>
                        <div class="notif-row-msg">${escapeHtml(item.message || '')}</div>
                    </div>
                </div>
                <div class="notif-row-category">
                    <span class="notif-category-badge ${cat.class}">${escapeHtml(cat.shortLabel || cat.label)}</span>
                </div>
                <div class="notif-row-time">
                    <time datetime="${escapeAttr(item.created_at || '')}" title="${escapeAttr(fullTime)}">${escapeHtml(timeStr)}</time>
                </div>
                <div class="notif-row-status-col" onclick="event.stopPropagation()">
                    <span class="notif-status-pill ${isUnread ? 'is-unread' : 'is-read'}">${isUnread ? 'unread' : 'read'}</span>
                    <div class="notif-row-more">
                        <button type="button" class="btn-notif-dots" onclick="toggleMenu(event, ${item.notification_id})" title="More options" aria-label="More options">
                            &bull;&bull;&bull;
                        </button>
                        <div class="notif-menu-dropdown" id="notifMenu_${item.notification_id}">
                            ${actionUrl ? `
                                <a href="${escapeAttr(actionUrl)}" class="notif-menu-item" onclick="closeAllMenus()">
                                    Open Case / Record
                                </a>
                            ` : ''}
                            ${isUnread ? `
                                <button type="button" class="notif-menu-item" onclick="markRead(${item.notification_id})">
                                    Mark as read
                                </button>
                            ` : `
                                <button type="button" class="notif-menu-item" onclick="markUnread(${item.notification_id})">
                                    Mark as unread
                                </button>
                            `}
                            <button type="button" class="notif-menu-item text-danger" onclick="deleteNotification(${item.notification_id})">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    updateSelectionUI();
}

function getCategoryTileSvg(catKey) {
    switch (catKey) {
        case 'critical':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        case 'warning':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        case 'hearing':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
        case 'summons':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>';
        case 'pangkat':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
        case 'complaint':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>';
        case 'document':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
        case 'success':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
        default:
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
    }
}

/**
 * Toggle select all notifications currently visible in the active tab/filters
 */
function toggleSelectAll(event) {
    const isChecked = event.target.checked;
    const visibleCheckboxes = document.querySelectorAll('.notif-row-checkbox');

    visibleCheckboxes.forEach(cb => {
        const id = Number(cb.value);
        if (isChecked) {
            selectedNotificationIds.add(id);
            cb.checked = true;
            cb.closest('.notif-item-row')?.classList.add('is-selected');
        } else {
            selectedNotificationIds.delete(id);
            cb.checked = false;
            cb.closest('.notif-item-row')?.classList.remove('is-selected');
        }
    });

    updateSelectionUI();
}
window.toggleSelectAll = toggleSelectAll;

/**
 * Handle checking/unchecking an individual notification row
 */
function handleRowCheckChange(event, notificationId) {
    event.stopPropagation();
    const id = Number(notificationId);
    if (event.target.checked) {
        selectedNotificationIds.add(id);
        event.target.closest('.notif-item-row')?.classList.add('is-selected');
    } else {
        selectedNotificationIds.delete(id);
        event.target.closest('.notif-item-row')?.classList.remove('is-selected');
    }
    updateSelectionUI();
}
window.handleRowCheckChange = handleRowCheckChange;

/**
 * Clear all selected checkboxes
 */
function clearAllSelections() {
    selectedNotificationIds.clear();
    document.querySelectorAll('.notif-row-checkbox').forEach(cb => {
        cb.checked = false;
        cb.closest('.notif-item-row')?.classList.remove('is-selected');
    });
    updateSelectionUI();
}
window.clearAllSelections = clearAllSelections;

/**
 * Update selection bar state, counts, and select-all checkbox
 */
function updateSelectionUI() {
    const count = selectedNotificationIds.size;
    const selBar = document.getElementById('notifSelectionBar');
    const badgeEl = document.getElementById('selectionCountBadge');
    const selectAllCb = document.getElementById('selectAllCheckbox');
    const bulkCounts = document.querySelectorAll('.bulk-count');

    bulkCounts.forEach(el => el.textContent = count);

    if (selBar) selBar.style.display = count > 0 ? 'flex' : 'none';
    if (badgeEl) badgeEl.textContent = `${count} selected`;

    const visibleCbs = document.querySelectorAll('.notif-row-checkbox');
    const visibleCount = visibleCbs.length;

    if (selectAllCb) {
        if (visibleCount === 0) {
            selectAllCb.checked = false;
            selectAllCb.indeterminate = false;
            selectAllCb.disabled = true;
        } else {
            selectAllCb.disabled = false;
            let checkedVisible = 0;
            visibleCbs.forEach(cb => {
                if (cb.checked) checkedVisible++;
            });

            if (checkedVisible === 0) {
                selectAllCb.checked = false;
                selectAllCb.indeterminate = false;
            } else if (checkedVisible === visibleCount) {
                selectAllCb.checked = true;
                selectAllCb.indeterminate = false;
            } else {
                selectAllCb.checked = false;
                selectAllCb.indeterminate = true;
            }
        }
    }
}

/**
 * Bulk delete selected notifications
 */
async function deleteSelectedNotifications() {
    const ids = Array.from(selectedNotificationIds);
    if (ids.length === 0) return;

    if (!confirm(`Are you sure you want to permanently delete ${ids.length} selected notification(s)?`)) {
        return;
    }

    const deleteBtn = document.querySelector('.btn-bulk-delete');
    if (deleteBtn) deleteBtn.disabled = true;

    try {
        const formData = new FormData();
        formData.append('notification_ids', ids.join(','));

        const resp = await fetch('../../../backend/api/notifications/delete.php', {
            method: 'POST',
            body: formData
        });

        const res = await safeParseJson(resp, 'Failed to delete selected notifications.');
        if (res && res.success) {
            const idSet = new Set(ids);
            allNotifications = allNotifications.filter(n => !idSet.has(Number(n.notification_id)));

            if (window.agapNotify) {
                window.agapNotify(`${ids.length} notification(s) deleted successfully.`, 'success');
            }

            selectedNotificationIds.clear();
            updateTabBadges();
            updateCategoryChipCounts();
            renderNotifications();
        } else {
            throw new Error(res.message || 'Failed to delete selected notifications.');
        }
    } catch (e) {
        console.error('Error in bulk delete:', e);
        if (window.agapNotify) {
            window.agapNotify(e.message || 'Error deleting notifications.', 'error');
        }
    } finally {
        if (deleteBtn) deleteBtn.disabled = false;
    }
}
window.deleteSelectedNotifications = deleteSelectedNotifications;

/**
 * Bulk mark selected notifications as read
 */
async function markSelectedAsRead() {
    const ids = Array.from(selectedNotificationIds);
    if (ids.length === 0) return;

    try {
        await Promise.all(ids.map(async id => {
            const fd = new FormData();
            fd.append('notification_id', id);
            const resp = await fetch('../../../backend/api/notifications/read.php', { method: 'POST', body: fd });
            return safeParseJson(resp, 'Failed to mark notification read.').catch(err => {
                console.warn(`Failed marking notification ${id} read:`, err);
            });
        }));

        const idSet = new Set(ids);
        allNotifications.forEach(n => {
            if (idSet.has(Number(n.notification_id))) {
                n.is_read = 1;
                n.read_at = new Date().toISOString();
            }
        });

        if (window.agapNotify) {
            window.agapNotify(`${ids.length} notification(s) marked as read.`, 'success');
        }

        selectedNotificationIds.clear();
        updateTabBadges();
        updateCategoryChipCounts();
        renderNotifications();
    } catch (e) {
        console.error('Error marking selected as read:', e);
    }
}
window.markSelectedAsRead = markSelectedAsRead;

/**
 * Categorize notification matching the exact layout style (▲ Critical Blocker, etc.)
 */
function inferCategoryTag(title, message, severity) {
    const text = `${title} ${message}`.toLowerCase();
    const sev = (severity || '').toLowerCase();

    if (text.includes('sla') || text.includes('overdue') || text.includes('emergency') || text.includes('cancelled') || text.includes('breach') || sev === 'urgent') {
        return { key: 'critical', icon: '', label: 'Critical Blocker', shortLabel: 'Critical', class: 'tag-critical', severity: 'urgent' };
    }
    if (text.includes('deadline') || text.includes('due soon') || text.includes('warning') || text.includes('lapsed') || sev === 'warning') {
        return { key: 'warning', icon: '', label: 'Timeline Risk', shortLabel: 'Timeline', class: 'tag-warning', severity: 'warning' };
    }
    if (text.includes('hearing') || text.includes('rescheduled') || text.includes('appearance') || text.includes('mediation')) {
        return { key: 'hearing', icon: '', label: 'Hearing Schedule', shortLabel: 'Hearing', class: 'tag-hearing', severity: 'info' };
    }
    if (text.includes('summon') || text.includes('kp form 9') || text.includes('kp form 8')) {
        return { key: 'summons', icon: '', label: 'Summons Service', shortLabel: 'Summons', class: 'tag-summons', severity: 'info' };
    }
    if (text.includes('pangkat') || text.includes('conciliation')) {
        return { key: 'pangkat', icon: '', label: 'Pangkat Formation', shortLabel: 'Pangkat', class: 'tag-pangkat', severity: 'info' };
    }
    if (text.includes('paanyaya') || text.includes('complaint') || text.includes('intake')) {
        return { key: 'complaint', icon: '', label: 'Complaint Intake', shortLabel: 'Intake', class: 'tag-complaint', severity: 'info' };
    }
    if (text.includes('kp form') || text.includes('document')) {
        return { key: 'document', icon: '', label: 'Legal Document', shortLabel: 'Document', class: 'tag-document', severity: 'info' };
    }
    if (text.includes('settled') || text.includes('resolved') || text.includes('amicable') || sev === 'success') {
        return { key: 'success', icon: '', label: 'Case Resolution', shortLabel: 'Resolved', class: 'tag-success', severity: 'success' };
    }
    return { key: 'info', icon: '', label: 'System Notice', shortLabel: 'Notice', class: 'tag-info', severity: sev || 'info' };
}

/**
 * Format relative time matching the screenshot (Just now, Today, 11:20 AM, 2 hours ago, 20 minutes ago, 2 days ago)
 */
function formatNotificationTime(dateString) {
    if (!dateString) return '';
    try {
        const then = new Date(dateString.replace(' ', 'T')).getTime();
        if (isNaN(then)) return dateString;

        const now = Date.now();
        const diffSeconds = Math.max(0, Math.floor((now - then) / 1000));

        if (diffSeconds < 60) return 'Just now';
        const diffMins = Math.floor(diffSeconds / 60);
        if (diffMins < 60) return `${diffMins} minutes ago`;

        const diffHours = Math.floor(diffMins / 60);
        if (diffHours < 4) return `${diffHours} hour${diffHours === 1 ? '' : 's'} ago`;

        const dateObj = new Date(then);
        const todayObj = new Date();
        const isToday = (
            dateObj.getDate() === todayObj.getDate() &&
            dateObj.getMonth() === todayObj.getMonth() &&
            dateObj.getFullYear() === todayObj.getFullYear()
        );

        const timeFormat = dateObj.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });

        if (isToday) {
            return `Today, ${timeFormat}`;
        }

        const yesterdayObj = new Date(todayObj);
        yesterdayObj.setDate(yesterdayObj.getDate() - 1);
        const isYesterday = (
            dateObj.getDate() === yesterdayObj.getDate() &&
            dateObj.getMonth() === yesterdayObj.getMonth() &&
            dateObj.getFullYear() === yesterdayObj.getFullYear()
        );

        if (isYesterday) {
            return `Yesterday, ${timeFormat}`;
        }

        const diffDays = Math.floor(diffHours / 24);
        if (diffDays < 7) {
            return `${diffDays} days ago`;
        }

        return dateObj.toLocaleDateString([], {
            month: 'short',
            day: 'numeric',
            year: (todayObj.getFullYear() !== dateObj.getFullYear()) ? 'numeric' : undefined
        });
    } catch (_) {
        return dateString;
    }
}

/**
 * Resolve target action URL
 */
function resolveActionUrl(item) {
    if (item.action_url && !item.action_url.includes('records.php')) return item.action_url;
    if (item.case_id) return `../cases/case-details.php?id=${encodeURIComponent(item.case_id)}`;
    if (item.complaint_id) return `../complaints/complaint-details.php?id=${encodeURIComponent(item.complaint_id)}`;

    // Fallback: search in message text and redirect directly to case details
    const match = `${item.title} ${item.message}`.match(/(?:Case|case)\s*(?:#|No\.\s*|number\s*)?([0-9A-Za-z\-_]+)/i);
    if (match && match[1]) {
        return `../cases/case-details.php?case_number=${encodeURIComponent(match[1])}`;
    }
    const compMatch = `${item.title} ${item.message}`.match(/(?:Complaint|P-)\s*(?:#|No\.\s*|number\s*)?([0-9A-Za-z\-_]+)/i);
    if (compMatch && compMatch[1]) {
        return `../complaints/complaint-details.php?id=${encodeURIComponent(compMatch[1])}`;
    }
    return '';
}

/**
 * Handle clicking anywhere on the notification row
 */
function handleRowClick(event, notificationId, actionUrl) {
    // If clicking a button or link inside, do nothing
    if (event.target.closest('.notif-row-more') || event.target.tagName === 'A' || event.target.tagName === 'BUTTON') {
        return;
    }

    const item = allNotifications.find(n => Number(n.notification_id) === Number(notificationId));
    if (item && !Number(item.is_read)) {
        markRead(notificationId, false);
    }

    if (actionUrl) {
        window.location.href = actionUrl;
    }
}
window.handleRowClick = handleRowClick;

/**
 * Toggle the 3-dots action dropdown
 */
function toggleMenu(event, notificationId) {
    event.stopPropagation();
    const menu = document.getElementById(`notifMenu_${notificationId}`);
    if (!menu) return;

    const isOpen = menu.classList.contains('show');
    closeAllMenus();
    if (!isOpen) {
        menu.classList.add('show');
    }
}
window.toggleMenu = toggleMenu;

function closeAllMenus() {
    document.querySelectorAll('.notif-menu-dropdown.show').forEach(m => m.classList.remove('show'));
}

/**
 * Mark a single notification as read
 */
async function markRead(notificationId, showToast = true) {
    closeAllMenus();
    try {
        const formData = new FormData();
        formData.append('notification_id', notificationId);

        const resp = await fetch('../../../backend/api/notifications/read.php', {
            method: 'POST',
            body: formData
        });

        const res = await safeParseJson(resp, 'Failed to mark notification read.');
        if (res && res.success) {
            const item = allNotifications.find(n => Number(n.notification_id) === Number(notificationId));
            if (item) {
                item.is_read = 1;
                item.read_at = new Date().toISOString();
            }
            if (showToast && window.agapNotify) {
                window.agapNotify('Notification marked as read.', 'success');
            }
            updateTabBadges();
            updateCategoryChipCounts();
            renderNotifications();
        }
    } catch (e) {
        console.error('Error marking read:', e);
    }
}
window.markRead = markRead;

/**
 * Mark a single notification as unread
 */
async function markUnread(notificationId) {
    closeAllMenus();
    try {
        const formData = new FormData();
        formData.append('notification_id', notificationId);

        const resp = await fetch('../../../backend/api/notifications/unread.php', {
            method: 'POST',
            body: formData
        });

        const res = await safeParseJson(resp, 'Failed to mark notification unread.');
        if (res && res.success) {
            const item = allNotifications.find(n => Number(n.notification_id) === Number(notificationId));
            if (item) {
                item.is_read = 0;
                item.read_at = null;
            }
            if (window.agapNotify) {
                window.agapNotify('Notification marked as unread.', 'info');
            }
            updateTabBadges();
            updateCategoryChipCounts();
            renderNotifications();
        }
    } catch (e) {
        console.error('Error marking unread:', e);
    }
}
window.markUnread = markUnread;

/**
 * Delete a notification
 */
async function deleteNotification(notificationId) {
    closeAllMenus();
    if (!confirm('Are you sure you want to remove this notification?')) return;

    try {
        const formData = new FormData();
        formData.append('notification_id', notificationId);

        const resp = await fetch('../../../backend/api/notifications/delete.php', {
            method: 'POST',
            body: formData
        });

        const res = await safeParseJson(resp, 'Failed to delete notification.');
        if (res && res.success) {
            allNotifications = allNotifications.filter(n => Number(n.notification_id) !== Number(notificationId));
            if (window.agapNotify) {
                window.agapNotify('Notification removed.', 'success');
            }
            updateTabBadges();
            updateCategoryChipCounts();
            renderNotifications();
        }
    } catch (e) {
        console.error('Error deleting notification:', e);
    }
}
window.deleteNotification = deleteNotification;

/**
 * Mark all as read
 */
async function markAllAsRead() {
    closeAllMenus();
    const btn = document.getElementById('btnMarkAllRead');
    if (btn) btn.disabled = true;

    try {
        const resp = await fetch('../../../backend/api/notifications/mark-all-read.php', {
            method: 'POST'
        });

        const res = await safeParseJson(resp, 'Failed to mark all notifications read.');
        if (res && res.success) {
            allNotifications.forEach(n => {
                n.is_read = 1;
                n.read_at = new Date().toISOString();
            });

            if (window.agapNotify) {
                window.agapNotify('All notifications marked as read.', 'success');
            }
            updateTabBadges();
            updateCategoryChipCounts();
            renderNotifications();
        }
    } catch (e) {
        console.error('Error marking all read:', e);
    } finally {
        if (btn) btn.disabled = false;
    }
}
window.markAllAsRead = markAllAsRead;

/**
 * Helper string escaper
 */
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escapeAttr(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escapeJs(str) {
    if (!str) return '';
    return String(str)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '\\"')
        .replace(/\n/g, '\\n')
        .replace(/\r/g, '\\r');
}
