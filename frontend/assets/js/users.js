let users = [];
let staffRoles = [];
let currentUserId = 0;
let cachedResidents = [];
let currentResidentFilter = 'all';
let currentStaffCardFilter = 'all';
let activePopoverTrigger = null;

const AVATAR_PALETTES = [
    { bg: '#e0e7ff', fg: '#3730a3' },
    { bg: '#dcfce7', fg: '#166534' },
    { bg: '#fef3c7', fg: '#92400e' },
    { bg: '#fae8ff', fg: '#86198f' },
    { bg: '#e0f2fe', fg: '#075985' },
    { bg: '#ffe4e6', fg: '#9f1239' },
];

const esc = (value) => {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
};

const pctText = (part, total) => {
    if (!total || total <= 0) return '0%';
    const pct = Math.round((part / total) * 100);
    return `${pct}%`;
};

function renderAvatar(fullName) {
    const clean = String(fullName || '').trim();
    const parts = clean ? clean.split(/\s+/) : ['U'];
    const initials = parts.length >= 2
        ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
        : clean.slice(0, 2).toUpperCase() || 'U';
    let hash = 0;
    for (let i = 0; i < clean.length; i += 1) {
        hash = ((hash << 5) - hash) + clean.charCodeAt(i);
        hash |= 0;
    }
    const palette = AVATAR_PALETTES[Math.abs(hash) % AVATAR_PALETTES.length];
    return `<span class="user-avatar-circle" style="background:${palette.bg}; color:${palette.fg};" aria-hidden="true">${esc(initials)}</span>`;
}

const showMessage = (message, success = false) => {
    if (window.agapNotify && message) {
        window.agapNotify(message, success ? 'success' : 'error', 'User Management');
    }
    const node = document.getElementById('userMessage');
    if (node) {
        node.textContent = '';
        node.className = '';
        node.style.display = 'none';
    }
};

const request = async (url, options = {}) => {
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || data.success === false) throw new Error(data.message || 'Request failed.');
    return data;
};

function getModuleConfig() {
    const root = document.getElementById('usersModuleRoot');
    return {
        isAdmin: root?.getAttribute('data-is-admin') === '1',
        initialTab: root?.getAttribute('data-initial-tab') || 'residents',
    };
}

function closeRowActionPopover() {
    const popover = document.getElementById('rowActionPopover');
    if (popover) {
        popover.hidden = true;
        popover.innerHTML = '';
    }
    if (activePopoverTrigger) {
        activePopoverTrigger.setAttribute('aria-expanded', 'false');
        activePopoverTrigger = null;
    }
}

function openRowActionPopover(triggerBtn, items) {
    const popover = document.getElementById('rowActionPopover');
    if (!popover || !Array.isArray(items) || items.length === 0) return;

    if (activePopoverTrigger === triggerBtn && !popover.hidden) {
        closeRowActionPopover();
        return;
    }

    closeRowActionPopover();
    activePopoverTrigger = triggerBtn;
    triggerBtn.setAttribute('aria-expanded', 'true');

    popover.innerHTML = items.map((item) => {
        if (item.divider) {
            return '<div class="row-action-divider" role="separator"></div>';
        }
        const cls = item.danger ? 'row-action-item is-danger' : (item.accent ? 'row-action-item is-accent' : 'row-action-item');
        const disabledAttr = item.disabled ? ` disabled title="${esc(item.title || '')}"` : '';
        return `<button type="button" class="${cls}" role="menuitem" data-popover-action="${esc(item.action)}" data-popover-id="${Number(item.id)}" data-popover-arg="${esc(item.arg ?? '')}"${disabledAttr}>${esc(item.label)}</button>`;
    }).join('');

    popover.hidden = false;
    const rect = triggerBtn.getBoundingClientRect();
    const popRect = popover.getBoundingClientRect();
    const margin = 10;

    let left = rect.right - popRect.width;
    if (left < margin) left = margin;
    if (left + popRect.width > window.innerWidth - margin) {
        left = window.innerWidth - popRect.width - margin;
    }

    let top = rect.bottom + 6;
    if (top + popRect.height > window.innerHeight - margin) {
        top = Math.max(margin, rect.top - popRect.height - 6);
    }

    popover.style.left = `${Math.round(left)}px`;
    popover.style.top = `${Math.round(top)}px`;
}

function switchModuleTab(tabName) {
    closeRowActionPopover();
    const { isAdmin } = getModuleConfig();
    const activeTab = isAdmin && tabName === 'staff' ? 'staff' : 'residents';

    document.querySelectorAll('[data-module-tab]').forEach((btn) => {
        const match = btn.getAttribute('data-module-tab') === activeTab;
        btn.classList.toggle('active', match);
        btn.setAttribute('aria-selected', match ? 'true' : 'false');
    });

    const staffPanel = document.getElementById('staffPanel');
    const residentsPanel = document.getElementById('residentsPanel');
    const addBtn = document.getElementById('btnAddStaffUser');

    if (staffPanel) staffPanel.hidden = activeTab !== 'staff';
    if (residentsPanel) residentsPanel.hidden = activeTab !== 'residents';
    if (addBtn) addBtn.hidden = activeTab !== 'staff';

    if (isAdmin && window.history?.replaceState) {
        const url = new URL(window.location.href);
        if (activeTab === 'residents') {
            url.searchParams.set('tab', 'residents');
        } else {
            url.searchParams.delete('tab');
        }
        window.history.replaceState({}, '', url.toString());
    }
}

function setResidentFilter(filterVal) {
    currentResidentFilter = filterVal || 'all';
    const sel = document.getElementById('rvFilterSelect');
    if (sel && sel.value !== currentResidentFilter) {
        sel.value = currentResidentFilter;
    }
    document.querySelectorAll('[data-rv-card-filter]').forEach((card) => {
        card.classList.toggle('is-active-filter', card.getAttribute('data-rv-card-filter') === currentResidentFilter);
    });
    renderResidentTable();
}

document.addEventListener('DOMContentLoaded', () => {
    const { isAdmin, initialTab } = getModuleConfig();

    document.querySelectorAll('[data-module-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            switchModuleTab(btn.getAttribute('data-module-tab') || 'staff');
        });
    });

    document.getElementById('rvFilterSelect')?.addEventListener('change', (e) => {
        setResidentFilter(e.target.value);
    });

    document.querySelectorAll('[data-rv-card-filter]').forEach((card) => {
        const activate = () => setResidentFilter(card.getAttribute('data-rv-card-filter') || 'all');
        card.addEventListener('click', activate);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate();
            }
        });
    });

    document.getElementById('rvSearchInput')?.addEventListener('input', renderResidentTable);

    if (isAdmin) {
        loadUsers();
        const form = document.getElementById('userForm');
        if (form) {
            form.addEventListener('submit', saveUser);
            form.addEventListener('input', clearFieldValidationMessage);
            form.addEventListener('change', clearFieldValidationMessage);
        }
        document.getElementById('staffSearchInput')?.addEventListener('input', renderStaffTable);
        document.getElementById('staffRoleFilter')?.addEventListener('change', () => {
            currentStaffCardFilter = 'custom';
            syncStaffCardHighlight();
            renderStaffTable();
        });
        document.getElementById('staffStatusFilter')?.addEventListener('change', () => {
            currentStaffCardFilter = 'custom';
            syncStaffCardHighlight();
            renderStaffTable();
        });
        document.querySelectorAll('[data-staff-card-filter]').forEach((card) => {
            const activate = () => setStaffCardFilter(card.getAttribute('data-staff-card-filter') || 'all');
            card.addEventListener('click', activate);
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    activate();
                }
            });
        });
        syncStaffCardHighlight();
    }

    switchModuleTab(initialTab);
    setResidentFilter('all');
    loadResidents();
});

window.addEventListener('scroll', closeRowActionPopover, true);
window.addEventListener('resize', closeRowActionPopover);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeRowActionPopover();
});

document.addEventListener('click', async (event) => {
    const staffKebab = event.target.closest('[data-staff-menu]');
    if (staffKebab) {
        event.stopPropagation();
        const uid = Number(staffKebab.getAttribute('data-staff-menu'));
        const user = users.find((u) => Number(u.user_id) === uid);
        if (!user) return;
        const isSelf = currentUserId > 0 && uid === currentUserId;
        const isPending = Number(user.pending_verification || 0) === 1;
        const isActive = (user.status || 'Active') === 'Active';
        const menuItems = [
            { action: 'edit-staff', id: uid, label: 'Edit User' },
        ];
        if (isPending) {
            menuItems.push({ action: 'resend-staff', id: uid, label: 'Resend Verification Email' });
        } else if (isActive) {
            menuItems.push({ action: 'status-staff', id: uid, arg: 'Inactive', label: 'Disable Account', disabled: isSelf, title: isSelf ? 'You cannot disable your own account.' : '' });
        } else {
            menuItems.push({ action: 'status-staff', id: uid, arg: 'Active', label: 'Enable Account', accent: true });
        }
        menuItems.push({ divider: true });
        menuItems.push({ action: 'delete-staff', id: uid, label: 'Delete Account', danger: true, disabled: isSelf, title: isSelf ? 'You cannot delete your own account.' : '' });
        openRowActionPopover(staffKebab, menuItems);
        return;
    }

    const resKebab = event.target.closest('[data-resident-menu]');
    if (resKebab) {
        event.stopPropagation();
        const uid = Number(resKebab.getAttribute('data-resident-menu'));
        const item = cachedResidents.find((r) => Number(r.user_id) === uid);
        if (!item) return;
        const { isAdmin } = getModuleConfig();
        const isVerified = Number(item.residency_verified) === 1;
        const canVerify = Boolean(item.profile_complete);
        const isLoginActive = (item.status || 'Active') === 'Active';

        const menuItems = [];
        if (isVerified) {
            menuItems.push({ action: 'rv-toggle', id: uid, arg: '0', label: 'Revert Verification to Pending' });
        } else {
            menuItems.push({
                action: 'rv-toggle',
                id: uid,
                arg: '1',
                label: 'Verify Resident Profile',
                accent: true,
                disabled: !canVerify,
                title: canVerify ? '' : 'Complete the linked resident record before verifying.',
            });
        }
        if (isAdmin) {
            menuItems.push({ divider: true });
            menuItems.push(isLoginActive
                ? { action: 'resident-login-status', id: uid, arg: 'Inactive', label: 'Disable Portal Login', danger: true }
                : { action: 'resident-login-status', id: uid, arg: 'Active', label: 'Enable Portal Login', accent: true });
        }
        openRowActionPopover(resKebab, menuItems);
        return;
    }

    const popItem = event.target.closest('[data-popover-action]');
    if (popItem && !popItem.disabled) {
        const action = popItem.getAttribute('data-popover-action');
        const uid = Number(popItem.getAttribute('data-popover-id'));
        const arg = popItem.getAttribute('data-popover-arg') || '';
        closeRowActionPopover();

        if (action === 'edit-staff') editUser(uid);
        if (action === 'resend-staff') await resendVerificationEmail(uid);
        if (action === 'status-staff') await toggleUserStatus(uid, arg);
        if (action === 'delete-staff') await deleteUser(uid);
        if (action === 'resident-login-status') await toggleResidentLoginStatus(uid, arg);
        if (action === 'rv-toggle') await toggleResidentVerification(uid, Number(arg));
        return;
    }

    const rvBtn = event.target.closest('[data-rv-toggle]');
    if (rvBtn && !rvBtn.disabled) {
        const userId = Number(rvBtn.getAttribute('data-rv-toggle'));
        const verified = Number(rvBtn.getAttribute('data-rv-target'));
        await toggleResidentVerification(userId, verified, rvBtn);
        return;
    }

    if (!event.target.closest('#rowActionPopover')) {
        closeRowActionPopover();
    }
});

async function toggleResidentVerification(userId, verified, triggerBtn = null) {
    const actionMsg = verified === 1
        ? 'Verify this resident account using their submitted mobile number and address?'
        : 'Revert this resident account verification status back to Pending?';
    if (!confirm(actionMsg)) return;

    if (triggerBtn) triggerBtn.disabled = true;
    try {
        const resp = await fetch('../../../backend/api/users/resident-verification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ user_id: userId, verified }),
        });
        const data = await resp.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
        if (!resp.ok || !data.success) {
            window.agapNotify?.(data.message || 'Unable to update verification.', 'error', 'Resident Verification');
            if (triggerBtn) triggerBtn.disabled = false;
            return;
        }
        window.agapNotify?.(data.message || 'Verification status updated.', 'success', 'Resident Verification');
        await loadResidents();
    } catch (_) {
        window.agapNotify?.('Connection error while updating verification.', 'error', 'Resident Verification');
        if (triggerBtn) triggerBtn.disabled = false;
    }
}

function clearFieldValidationMessage(event) {
    if (event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement) {
        event.target.setCustomValidity('');
    }
}

function populateStaffRoleControls(roles) {
    if (!Array.isArray(roles) || roles.length === 0) return;

    const modalSelect = document.getElementById('roleId');
    if (modalSelect) {
        const currentVal = modalSelect.value;
        modalSelect.innerHTML = roles
            .map((role) => `<option value="${Number(role.role_id)}">${esc(role.role_name)}</option>`)
            .join('');
        if (currentVal && modalSelect.querySelector(`option[value="${currentVal}"]`)) {
            modalSelect.value = currentVal;
        }
    }

    const filterSelect = document.getElementById('staffRoleFilter');
    if (filterSelect) {
        const currentFilter = filterSelect.value;
        filterSelect.innerHTML = '<option value="">All roles</option>' + roles
            .map((role) => `<option value="${Number(role.role_id)}">${esc(role.role_name)}</option>`)
            .join('');
        if (currentFilter) {
            filterSelect.value = currentFilter;
        }
    }
}

function matchesStaffCardCategory(user, category) {
    const roleName = String(user.role_name || '');
    const isPending = Number(user.pending_verification || 0) === 1;
    const isActive = (user.status || 'Active') === 'Active';

    switch (category) {
        case 'secretary':
            return /secretary/i.test(roleName);
        case 'clerk':
            return /lupon\s*clerk/i.test(roleName);
        case 'member':
            return /lupon\s*members?/i.test(roleName);
        case 'server':
            return /summons?\s*server/i.test(roleName);
        case 'disabled':
            return !isActive && !isPending;
        default:
            return true;
    }
}

function syncStaffCardHighlight() {
    document.querySelectorAll('[data-staff-card-filter]').forEach((card) => {
        card.classList.toggle('is-active-filter', card.getAttribute('data-staff-card-filter') === currentStaffCardFilter);
    });
}

function setStaffCardFilter(category) {
    currentStaffCardFilter = category || 'all';
    const roleSelect = document.getElementById('staffRoleFilter');
    const statusSelect = document.getElementById('staffStatusFilter');

    if (category === 'all') {
        if (roleSelect) roleSelect.value = '';
        if (statusSelect) statusSelect.value = '';
    } else if (category === 'disabled') {
        if (roleSelect) roleSelect.value = '';
        if (statusSelect) statusSelect.value = 'Inactive';
    } else {
        if (statusSelect) statusSelect.value = '';
        if (roleSelect) {
            const matchedRole = staffRoles.find((r) => matchesStaffCardCategory({ role_name: r.role_name, status: 'Active' }, category));
            roleSelect.value = matchedRole ? String(matchedRole.role_id) : '';
        }
    }

    syncStaffCardHighlight();
    renderStaffTable();
}

function updateStaffKpis() {
    const total = users.length;
    let active = 0;
    let secretary = 0;
    let clerk = 0;
    let member = 0;
    let server = 0;
    let disabled = 0;

    users.forEach((user) => {
        const isPending = Number(user.pending_verification || 0) === 1;
        const isActive = (user.status || 'Active') === 'Active';
        if (isActive) active += 1;
        if (!isActive && !isPending) disabled += 1;

        if (matchesStaffCardCategory(user, 'secretary')) secretary += 1;
        if (matchesStaffCardCategory(user, 'clerk')) clerk += 1;
        if (matchesStaffCardCategory(user, 'member')) member += 1;
        if (matchesStaffCardCategory(user, 'server')) server += 1;
    });

    const setTxt = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(val);
    };

    setTxt('staffTotalCount', total);
    setTxt('staffActiveTotalPill', `${active} active`);
    setTxt('staffSecretaryCount', secretary);
    setTxt('staffSecretaryShare', pctText(secretary, total));
    setTxt('staffClerkCount', clerk);
    setTxt('staffClerkShare', pctText(clerk, total));
    setTxt('staffMemberCount', member);
    setTxt('staffMemberShare', pctText(member, total));
    setTxt('staffServerCount', server);
    setTxt('staffServerShare', pctText(server, total));
    setTxt('staffDisabledCount', disabled);
    setTxt('staffDisabledShare', pctText(disabled, total));
}

function renderStaffTable() {
    const tbody = document.getElementById('userTable');
    if (!tbody) return;

    const search = (document.getElementById('staffSearchInput')?.value || '').trim().toLowerCase();
    const roleFilter = document.getElementById('staffRoleFilter')?.value || '';
    const statusFilter = document.getElementById('staffStatusFilter')?.value || '';

    const filtered = users.filter((user) => {
        const isPending = Number(user.pending_verification || 0) === 1;
        const isActive = (user.status || 'Active') === 'Active';

        if (!['all', 'custom'].includes(currentStaffCardFilter) && roleFilter === '' && statusFilter === '') {
            if (!matchesStaffCardCategory(user, currentStaffCardFilter)) return false;
        }
        if (roleFilter !== '' && String(user.role_id) !== roleFilter) return false;
        if (statusFilter === 'Active' && !isActive) return false;
        if (statusFilter === 'pending' && !isPending) return false;
        if (statusFilter === 'Inactive' && (isActive || isPending)) return false;

        if (!search) return true;
        const hay = `${user.first_name || ''} ${user.last_name || ''} ${user.username || ''} ${user.email || ''} ${user.contact_no || ''} ${user.role_name || ''}`.toLowerCase();
        return hay.includes(search);
    });

    const shownMeta = document.getElementById('staffShownMeta');
    if (shownMeta) {
        shownMeta.textContent = `${filtered.length} of ${users.length} shown`;
    }

    tbody.innerHTML = filtered.map((user) => {
        const uid = Number(user.user_id);
        const fullName = `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.username;
        const isPending = Number(user.pending_verification || 0) === 1;
        const isActive = (user.status || 'Active') === 'Active';
        const statusLabel = isActive ? 'Active' : (isPending ? 'Pending' : 'Disabled');
        const statusClass = isActive ? 'status-active' : (isPending ? 'status-pending' : 'status-disabled');

        return `
        <tr class="${!isActive && !isPending ? 'row-user-disabled' : ''}">
            <td>
                <div class="user-identity-cell">
                    ${renderAvatar(fullName)}
                    <div class="user-identity-text">
                        <strong>${esc(fullName)}</strong>
                        <span>@${esc(user.username)}</span>
                    </div>
                </div>
            </td>
            <td class="cell-muted">${esc(user.email)}</td>
            <td class="cell-muted">${esc(user.contact_no || '—')}</td>
            <td><span class="role-chip">${esc(user.role_name)}</span></td>
            <td><span class="user-status-badge ${statusClass}">${esc(statusLabel)}</span></td>
            <td class="col-actions">
                <button type="button" class="btn-kebab" data-staff-menu="${uid}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(fullName)}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"></circle><circle cx="12" cy="12" r="1.8"></circle><circle cx="12" cy="19" r="1.8"></circle></svg>
                </button>
            </td>
        </tr>`;
    }).join('') || '<tr><td colspan="6" class="table-state-cell">No staff accounts match the current filter.</td></tr>';
}

async function loadUsers() {
    const tbody = document.getElementById('userTable');
    if (!tbody) return;
    try {
        const data = await request('../../../backend/api/users/list.php');
        users = Array.isArray(data.users) ? data.users : [];
        staffRoles = Array.isArray(data.roles) ? data.roles : [];
        currentUserId = Number(data.current_user_id || 0);
        populateStaffRoleControls(staffRoles);
        updateStaffKpis();

        const countBadge = document.getElementById('staffTabCountBadge');
        if (countBadge) {
            countBadge.textContent = String(users.length);
            countBadge.hidden = false;
        }
        renderStaffTable();
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="6" class="table-state-cell is-error">${esc(error.message)}</td></tr>`;
    }
}

function renderResidentTable() {
    const tbody = document.getElementById('residentVerificationTbody');
    const q = (document.getElementById('rvSearchInput')?.value || '').trim().toLowerCase();
    if (!tbody) return;

    const rows = cachedResidents.filter((item) => {
        if (currentResidentFilter === 'pending' && Number(item.residency_verified) === 1) return false;
        if (currentResidentFilter === 'verified' && Number(item.residency_verified) !== 1) return false;
        if (currentResidentFilter === 'incomplete' && item.profile_complete) return false;
        if (!q) return true;
        const hay = `${item.full_name} ${item.username} ${item.email} ${item.contact_no} ${item.purok} ${item.address}`.toLowerCase();
        return hay.includes(q);
    });

    const shownMeta = document.getElementById('rvShownMeta');
    if (shownMeta) {
        shownMeta.textContent = `${rows.length} of ${cachedResidents.length} shown`;
    }

    if (rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="table-state-cell">No resident accounts match the current filter.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map((item) => {
        const uid = Number(item.user_id);
        const fullName = item.full_name || item.username;
        const isVerified = Number(item.residency_verified) === 1;
        const canVerify = Boolean(item.profile_complete);
        const isLoginActive = (item.status || 'Active') === 'Active';
        const addrParts = [item.purok, item.address].filter(Boolean);
        const addrHtml = addrParts.length > 0
            ? esc(addrParts.join(' — '))
            : '<span class="cell-placeholder">Not added yet in profile</span>';
        const mobileHtml = item.contact_no
            ? esc(item.contact_no)
            : '<span class="cell-placeholder">Not added yet in profile</span>';

        let statusBadge = '<span class="user-status-badge status-pending">Pending</span>';
        if (isVerified) {
            statusBadge = '<span class="user-status-badge status-active">Verified</span>';
        } else if (!canVerify) {
            statusBadge = '<span class="user-status-badge status-disabled" title="The linked resident record is missing contact or address details">Incomplete</span>';
        }

        const loginBadge = !isLoginActive
            ? '<span class="user-status-badge status-disabled" style="margin-left:4px;">Disabled</span>'
            : '';

        return `
            <tr class="${!isLoginActive ? 'row-user-disabled' : ''}">
                <td>
                    <div class="user-identity-cell">
                        ${renderAvatar(fullName)}
                        <div class="user-identity-text">
                            <strong>${esc(fullName)}</strong>
                            <span>@${esc(item.username)}</span>
                        </div>
                    </div>
                </td>
                <td class="cell-muted">${esc(item.email || '—')}</td>
                <td class="cell-muted">${mobileHtml}</td>
                <td class="cell-muted">${addrHtml}</td>
                <td>${statusBadge}${loginBadge}</td>
                <td class="col-actions">
                    <button type="button" class="btn-kebab" data-resident-menu="${uid}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(fullName)}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"></circle><circle cx="12" cy="12" r="1.8"></circle><circle cx="12" cy="19" r="1.8"></circle></svg>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

async function loadResidents() {
    const tbody = document.getElementById('residentVerificationTbody');
    if (!tbody) return;
    try {
        const resp = await fetch('../../../backend/api/users/resident-verification.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        const data = await resp.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
        if (!resp.ok || !data.success) {
            tbody.innerHTML = `<tr><td colspan="6" class="table-state-cell is-error">${esc(data.message || 'Unable to load resident accounts.')}</td></tr>`;
            return;
        }
        cachedResidents = Array.isArray(data.residents) ? data.residents : [];
        const sum = data.summary || {};
        const total = Number(sum.total ?? cachedResidents.length);
        const pendingCount = Number(sum.pending ?? 0);
        const verifiedCount = Number(sum.verified ?? 0);
        const incompleteCount = Number(sum.incomplete ?? 0);

        const setTxt = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(val);
        };

        setTxt('rvTotalCount', total);
        setTxt('rvTotalShare', total > 0 ? '100%' : '0%');
        setTxt('rvVerifiedCount', verifiedCount);
        setTxt('rvVerifiedShare', pctText(verifiedCount, total));
        setTxt('rvPendingCount', pendingCount);
        setTxt('rvPendingShare', pctText(pendingCount, total));
        setTxt('rvIncompleteCount', incompleteCount);
        setTxt('rvIncompleteShare', pctText(incompleteCount, total));

        const badge = document.getElementById('rvTabPendingBadge');
        if (badge) {
            badge.textContent = String(pendingCount);
            badge.hidden = pendingCount < 1;
        }

        renderResidentTable();
    } catch (_) {
        tbody.innerHTML = '<tr><td colspan="6" class="table-state-cell is-error">Connection error while loading resident accounts.</td></tr>';
    }
}

async function toggleResidentLoginStatus(id, targetStatus) {
    const resident = cachedResidents.find((item) => Number(item.user_id) === Number(id));
    const displayName = resident ? `${resident.full_name || resident.username} (@${resident.username})` : `Resident #${id}`;
    const warningText = targetStatus === 'Inactive'
        ? `Disable portal login for ${displayName}? They will not be able to sign in until re-enabled.`
        : `Re-enable portal login for ${displayName}? They will regain access to the Resident Portal.`;

    if (!confirm(warningText)) return;

    try {
        const result = await request('../../../backend/api/users/status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: Number(id), status: targetStatus }),
        });
        window.agapNotify?.(result.message || 'Resident login access updated.', 'success', 'User Management');
        await loadResidents();
    } catch (error) {
        window.agapNotify?.(error.message, 'error', 'User Management');
    }
}

function openUserModal() {
    const form = document.getElementById('userForm');
    if (!form) return;
    form.reset();
    form.querySelectorAll('input, select').forEach((field) => field.setCustomValidity(''));
    form.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
    form.querySelectorAll('.field-validation-message').forEach((message) => {
        message.hidden = true;
        message.textContent = '';
    });
    form.action = '../../../backend/api/users/create.php';
    document.getElementById('userModalTitle').textContent = 'Add Staff User';
    setAdministratorRoleOption(false);
    document.getElementById('password').required = false;
    document.getElementById('password').value = '';
    document.getElementById('passwordConfirmation').required = false;
    document.getElementById('passwordConfirmation').value = '';
    document.getElementById('passwordGroup').style.display = '';
    document.getElementById('passwordHint').textContent = '(optional; leave blank to send verification email)';
    showMessage('');
    document.getElementById('userModal').style.display = 'flex';
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    if (modal) modal.style.display = 'none';
}

function editUser(id) {
    const user = users.find((item) => Number(item.user_id) === Number(id));
    if (!user) return;
    openUserModal();
    setAdministratorRoleOption(Number(user.role_id) === 1);
    document.getElementById('userForm').action = '../../../backend/api/users/update.php';
    document.getElementById('userModalTitle').textContent = 'Edit Staff User';
    document.getElementById('userId').value = user.user_id;
    document.getElementById('firstName').value = user.first_name;
    document.getElementById('lastName').value = user.last_name;
    document.getElementById('username').value = user.username;
    document.getElementById('email').value = user.email;
    document.getElementById('contactNo').value = user.contact_no || '';
    document.getElementById('roleId').value = user.role_id;
    document.getElementById('password').required = false;
    document.getElementById('password').value = '';
    document.getElementById('passwordConfirmation').required = false;
    document.getElementById('passwordConfirmation').value = '';
    document.getElementById('passwordGroup').style.display = '';
    document.getElementById('passwordHint').textContent = '(leave blank to keep current password)';
}

function setAdministratorRoleOption(allowAdministrator) {
    const roleSelect = document.getElementById('roleId');
    const administratorOption = roleSelect?.querySelector('option[value="1"]');
    if (!roleSelect || !administratorOption) return;

    administratorOption.hidden = !allowAdministrator;
    administratorOption.disabled = !allowAdministrator;
    if (!allowAdministrator && roleSelect.value === '1') {
        const firstNonAdmin = Array.from(roleSelect.options).find((opt) => opt.value !== '1');
        if (firstNonAdmin) {
            roleSelect.value = firstNonAdmin.value;
        }
    }
}

async function resendVerificationEmail(id) {
    if (!confirm('Send a new email-verification link? Existing links will no longer work.')) return;
    try {
        const form = new FormData();
        form.append('user_id', id);
        const result = await request('../../../backend/api/users/resend-verification.php', { method: 'POST', body: form });
        window.agapNotify?.(result.message, 'success', 'User Management');
    } catch (error) {
        window.agapNotify?.(error.message, 'error', 'User Management');
    }
}

async function saveUser(event) {
    event.preventDefault();
    const form = event.currentTarget;
    showMessage('');
    for (const field of form.querySelectorAll('input:not([type="hidden"]):not([type="password"])')) {
        field.value = field.value.trim();
        field.setCustomValidity('');
        if (['first_name', 'last_name'].includes(field.name)
            && !/^[\p{L}\p{M}]+(?:[ '-][\p{L}\p{M}]+)*$/u.test(field.value)) {
            field.setCustomValidity('Please enter a valid name using letters, spaces, hyphens, or apostrophes.');
        }
        if (field.name === 'contact_no' && !validPhilippinePhone(field.value)) {
            field.setCustomValidity('Enter a valid Philippine mobile or telephone number, such as 09171234567 or +639171234567.');
        }
    }
    const password = document.getElementById('password');
    const passwordConfirmation = document.getElementById('passwordConfirmation');
    if (password.value !== '' || passwordConfirmation.value !== '') {
        if (password.value === '') {
            password.setCustomValidity('Enter a password before confirming it.');
        }
        if (password.value !== passwordConfirmation.value) {
            passwordConfirmation.setCustomValidity('Password confirmation must match the password.');
        }
    }
    if (!form.reportValidity()) return;
    try {
        const result = await request(form.action, { method: 'POST', body: new FormData(form) });
        showMessage(result.message, true);
        window.agapNotify?.(result.message, 'success', 'User Management');
        closeUserModal();
        await loadUsers();
    } catch (error) {
        showMessage(error.message);
        window.agapNotify?.(error.message, 'error', 'User Management');
    }
}

function validPhilippinePhone(value) {
    if (!/^\+?[0-9 .()\-]+$/.test(value)) return false;
    let digits = value.replace(/\D/g, '');
    if (value.startsWith('+') || digits.startsWith('63')) {
        if (!digits.startsWith('63')) return false;
        digits = digits.slice(2);
    } else if (digits.startsWith('0')) {
        digits = digits.slice(1);
    }
    return /^9\d{9}$/.test(digits) || /^(?:2\d{8}|[3-8]\d{8,9})$/.test(digits);
}

async function toggleUserStatus(id, targetStatus) {
    const user = users.find((item) => Number(item.user_id) === Number(id));
    const displayName = user ? `${user.first_name} ${user.last_name} (@${user.username})` : `User #${id}`;
    const actionWord = targetStatus === 'Inactive' ? 'Disable' : 'Enable';
    const warningText = targetStatus === 'Inactive'
        ? `Disable account for ${displayName}? They will not be able to sign in until re-enabled.`
        : `Re-enable account for ${displayName}? They will regain sign-in access.`;

    if (!confirm(warningText)) return;

    try {
        const result = await request('../../../backend/api/users/status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: Number(id), status: targetStatus }),
        });
        window.agapNotify?.(result.message || `${actionWord}d user account.`, 'success', 'User Management');
        await loadUsers();
    } catch (error) {
        window.agapNotify?.(error.message, 'error', 'User Management');
    }
}

async function deleteUser(id) {
    if (!confirm('Delete this user account?')) return;
    try {
        const form = new FormData();
        form.append('user_id', id);
        const result = await request('../../../backend/api/users/delete.php', { method: 'POST', body: form });
        window.agapNotify?.(result.message, 'success', 'User Management');
        await loadUsers();
    } catch (error) {
        window.agapNotify?.(error.message, 'error', 'User Management');
    }
}
