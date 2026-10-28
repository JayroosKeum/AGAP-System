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
let currentNotifTab = 'unread';
let notifSearchQuery = '';
let notifCategoryFilter = 'all';
let notifSeverityFilter = 'all';
let notifDateFilter = 'all';
let notifSortBy = 'date_desc';
let selectedNotificationIds = new Set();

document.addEventListener('DOMContentLoaded', () => {
    loadNotifications();

    // Close any active dropdown menu on outside click
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.notif-row-more')) {
            closeAllMenus();
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
    const unreadCount = allNotifications.filter(n => !Number(n.is_read)).length;
    const readCount = allNotifications.filter(n => Number(n.is_read) === 1).length;

    const unreadBadge = document.getElementById('tabCountUnread');
    const readBadge = document.getElementById('tabCountRead');
    const markAllBtn = document.getElementById('btnMarkAllRead');

    if (unreadBadge) {
        unreadBadge.textContent = unreadCount;
        unreadBadge.style.display = unreadCount > 0 ? 'inline-block' : 'none';
    }
    if (readBadge) {
        readBadge.textContent = readCount;
        readBadge.style.display = readCount > 0 ? 'inline-block' : 'none';
    }

    if (markAllBtn) {
        markAllBtn.disabled = unreadCount === 0;
        markAllBtn.style.opacity = unreadCount === 0 ? '0.5' : '1';
        markAllBtn.style.cursor = unreadCount === 0 ? 'not-allowed' : 'pointer';
    }
}

/**
 * Update category filter chips live count badges
 */
function updateCategoryChipCounts() {
    const tabRows = allNotifications.filter(item => {
        const isUnread = !Number(item.is_read);
        return currentNotifTab === 'unread' ? isUnread : !isUnread;
    });

    const counts = {
        all: tabRows.length,
        critical: 0,
        warning: 0,
        hearing: 0,
        summons: 0,
        pangkat: 0,
        complaint: 0,
        document: 0,
        success: 0
    };

    tabRows.forEach(item => {
        const cat = inferCategoryTag(item.title || '', item.message || '', item.severity || 'info');
        if (counts.hasOwnProperty(cat.key)) {
            counts[cat.key]++;
        }
    });

    Object.keys(counts).forEach(key => {
        const el = document.getElementById(`chipCount_${key}`);
        if (el) el.textContent = counts[key];
    });
}

/**
 * Switch between Unread and Read tabs
 */
function setNotifTab(tab) {
    if (currentNotifTab === tab) return;
    currentNotifTab = tab;

    const tabUnread = document.getElementById('tabUnread');
    const tabRead = document.getElementById('tabRead');

    if (tabUnread) tabUnread.classList.toggle('active', tab === 'unread');
    if (tabRead) tabRead.classList.toggle('active', tab === 'read');

    selectedNotificationIds.clear();
    closeAllMenus();
    updateCategoryChipCounts();
    renderNotifications();
}
window.setNotifTab = setNotifTab;

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
 * Category Filter Chip Handler
 */
function setCategoryFilter(category) {
    notifCategoryFilter = category;
    document.querySelectorAll('.notif-chip').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-category') === category);
    });
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

    const searchInput = document.getElementById('notifSearchInput');
    const clearSearchBtn = document.getElementById('notifClearSearch');
    const severitySelect = document.getElementById('notifSeveritySelect');
    const dateSelect = document.getElementById('notifDateSelect');
    const sortSelect = document.getElementById('notifSortSelect');

    if (searchInput) searchInput.value = '';
    if (clearSearchBtn) clearSearchBtn.style.display = 'none';
    if (severitySelect) severitySelect.value = 'all';
    if (dateSelect) dateSelect.value = 'all';
    if (sortSelect) sortSelect.value = 'date_desc';

    document.querySelectorAll('.notif-chip').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-category') === 'all');
    });

    updateClearFiltersButton();
    renderNotifications();
}
window.resetAllFilters = resetAllFilters;

/**
 * Show / hide reset filters button based on filter activity
 */
function updateClearFiltersButton() {
    const btn = document.getElementById('btnClearFilters');
    if (!btn) return;
    const hasActiveFilters = Boolean(
        notifSearchQuery ||
        notifCategoryFilter !== 'all' ||
        notifSeverityFilter !== 'all' ||
        notifDateFilter !== 'all'
    );
    btn.style.display = hasActiveFilters ? 'inline-flex' : 'none';
}

/**
 * Main render function for notification rows
 */
function renderNotifications() {
    const listEl = document.getElementById('notificationList');
    if (!listEl) return;

    closeAllMenus();

    // 1. Filter by Tab (Unread vs Read)
    let rows = allNotifications.filter(item => {
        const isUnread = !Number(item.is_read);
        return currentNotifTab === 'unread' ? isUnread : !isUnread;
    });

    // 2. Filter by search keyword (title, message, case #, complaint #)
    if (notifSearchQuery) {
        rows = rows.filter(item => {
            const combined = `${item.title || ''} ${item.message || ''} ${item.case_number || ''} ${item.complaint_number || ''} ${item.case_id || ''} ${item.complaint_id || ''}`.toLowerCase();
            return combined.includes(notifSearchQuery);
        });
    }

    // 3. Filter by Category Chip
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
        // Default: date_desc
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
    });

    // 7. Handle empty state
    if (rows.length === 0) {
        updateSelectionUI();
        const hasFilters = Boolean(
            notifSearchQuery ||
            notifCategoryFilter !== 'all' ||
            notifSeverityFilter !== 'all' ||
            notifDateFilter !== 'all'
        );
        let emptyTitle = currentNotifTab === 'unread' ? 'No Unread Notifications' : 'No Read Notifications';
        let emptyDesc = currentNotifTab === 'unread' ? 'All caught up! No unread notifications.' : 'No read notifications found.';

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
                ` : (currentNotifTab === 'unread' ? `
                    <button type="button" class="notif-btn-switch-tab" onclick="setNotifTab('read')">
                        View Read Notifications &rarr;
                    </button>
                ` : '')}
            </div>
        `;
        return;
    }

    // 8. Build HTML rows
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
                    <span class="notif-dot ${isUnread ? 'dot-unread dot-' + effectiveSeverity : 'dot-read'}" title="${isUnread ? 'Unread (' + effectiveSeverity.toUpperCase() + ')' : 'Read'}"></span>
                    <div class="notif-text-col">
                        <div class="notif-row-title">${escapeHtml(stripNotificationEmoji(item.title) || 'Notification')}</div>
                        <div class="notif-row-msg">${escapeHtml(item.message || '')}</div>
                    </div>
                </div>
                <div class="notif-row-category">
                    <span class="notif-category-badge ${cat.class}">
                        ${cat.icon ? `<span class="notif-category-icon">${cat.icon}</span>` : ''}
                        <span class="notif-category-label">${escapeHtml(cat.label)}</span>
                    </span>
                </div>
                <div class="notif-row-time">
                    <time datetime="${escapeAttr(item.created_at || '')}" title="${escapeAttr(fullTime)}">${escapeHtml(timeStr)}</time>
                </div>
                <div class="notif-row-actions-group" onclick="event.stopPropagation()">
                    ${actionUrl ? `
                        <a href="${escapeAttr(actionUrl)}" class="btn-notif-open-link" title="Open Case / Complaint">
                            <span>Open</span> &rarr;
                        </a>
                    ` : ''}
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
                                    <span>•</span> Mark as unread
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
    const actionsEl = document.getElementById('selectionBarActions');
    const badgeEl = document.getElementById('selectionCountBadge');
    const selectAllCb = document.getElementById('selectAllCheckbox');
    const selectAllLabel = document.getElementById('selectAllLabel');
    const bulkCounts = document.querySelectorAll('.bulk-count');

    bulkCounts.forEach(el => el.textContent = count);

    if (actionsEl) actionsEl.style.display = count > 0 ? 'flex' : 'none';
    if (badgeEl) {
        badgeEl.textContent = `${count} selected`;
        badgeEl.style.display = count > 0 ? 'inline-flex' : 'none';
    }

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

    if (selectAllLabel) {
        selectAllLabel.textContent = count > 0 ? `${count} selected` : 'Select all';
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
        return { key: 'critical', icon: '', label: 'Critical Blocker', class: 'tag-critical', severity: 'urgent' };
    }
    if (text.includes('deadline') || text.includes('due soon') || text.includes('warning') || text.includes('lapsed') || sev === 'warning') {
        return { key: 'warning', icon: '', label: 'Timeline Risk', class: 'tag-warning', severity: 'warning' };
    }
    if (text.includes('hearing') || text.includes('rescheduled') || text.includes('appearance') || text.includes('mediation')) {
        return { key: 'hearing', icon: '', label: 'Hearing Schedule', class: 'tag-hearing', severity: 'info' };
    }
    if (text.includes('summon') || text.includes('kp form 9') || text.includes('kp form 8')) {
        return { key: 'summons', icon: '', label: 'Summons Service', class: 'tag-summons', severity: 'info' };
    }
    if (text.includes('pangkat') || text.includes('conciliation')) {
        return { key: 'pangkat', icon: '', label: 'Pangkat Formation', class: 'tag-pangkat', severity: 'info' };
    }
    if (text.includes('paanyaya') || text.includes('complaint') || text.includes('intake')) {
        return { key: 'complaint', icon: '', label: 'Complaint Intake', class: 'tag-complaint', severity: 'info' };
    }
    if (text.includes('kp form') || text.includes('document')) {
        return { key: 'document', icon: '', label: 'Legal Document', class: 'tag-document', severity: 'info' };
    }
    if (text.includes('settled') || text.includes('resolved') || text.includes('amicable') || sev === 'success') {
        return { key: 'success', icon: '', label: 'Case Resolution', class: 'tag-success', severity: 'success' };
    }
    return { key: 'info', icon: '', label: 'System Notice', class: 'tag-info', severity: sev || 'info' };
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
    if (item.action_url) return item.action_url;
    if (item.case_id) return `../cases/case-details.php?id=${encodeURIComponent(item.case_id)}`;
    if (item.complaint_id) return `../complaints/complaint-details.php?id=${encodeURIComponent(item.complaint_id)}`;

    // Fallback: search in message text
    const match = `${item.title} ${item.message}`.match(/(?:Case|case)\s*(?:#|No\.\s*|number\s*)?([0-9A-Za-z\-_]+)/i);
    if (match && match[1]) {
        return `../cases/case-list.php?search=${encodeURIComponent(match[1])}`;
    }
    const compMatch = `${item.title} ${item.message}`.match(/(?:Complaint|P-)\s*(?:#|No\.\s*|number\s*)?([0-9A-Za-z\-_]+)/i);
    if (compMatch && compMatch[1]) {
        return `../complaints/complaint-list.php?search=${encodeURIComponent(compMatch[1])}`;
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
