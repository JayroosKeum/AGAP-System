<?php
session_start();

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/middleware/rbac.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}

$rbacDb = (new Database())->connect();
if (!agapHasRole(['Administrator', 'Secretary', 'Lupon Clerk'], $rbacDb)) {
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    $redirect = match ($roleId) {
        3 => '../dashboard/lupon-dashboard.php',
        4 => '../dashboard/server-dashboard.php',
        default => '../complaints/complaint-list.php',
    };
    header('Location: ' . $redirect);
    exit;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/users.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/users.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header" style="flex-wrap:wrap; gap:12px;">
            <div>
                <h1>Resident Account Verification</h1>
                <p>Review Email, Mobile Number, and Barangay Tumana Address submitted inside the Resident Portal profile before verifying accounts.</p>
            </div>
            <?php if ((int) ($_SESSION['role_id'] ?? 0) === 1): ?>
                <a href="user-list.php" class="btn-top-secondary" style="text-decoration:none;">Staff Accounts &rarr;</a>
            <?php endif; ?>
        </div>

        <div style="padding: 0 20px;">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:16px;">
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px;">
                    <div style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase;">All Residents</div>
                    <div id="rvTotalCount" style="font-size:1.5rem; font-weight:800; color:#0f172a; margin-top:4px;">0</div>
                </div>
                <div style="background:#fff; border:1px solid #fde68a; border-radius:10px; padding:14px 16px;">
                    <div style="font-size:0.75rem; font-weight:700; color:#b45309; text-transform:uppercase;">Pending Verification</div>
                    <div id="rvPendingCount" style="font-size:1.5rem; font-weight:800; color:#b45309; margin-top:4px;">0</div>
                </div>
                <div style="background:#fff; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px;">
                    <div style="font-size:0.75rem; font-weight:700; color:#15803d; text-transform:uppercase;">Verified Residents</div>
                    <div id="rvVerifiedCount" style="font-size:1.5rem; font-weight:800; color:#15803d; margin-top:4px;">0</div>
                </div>
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px;">
                    <div style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase;">Incomplete Profile</div>
                    <div id="rvIncompleteCount" style="font-size:1.5rem; font-weight:800; color:#334155; margin-top:4px;">0</div>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
                <div class="period-tabs" role="tablist" aria-label="Filter resident accounts">
                    <button type="button" class="period-tab active" data-rv-filter="all">All</button>
                    <button type="button" class="period-tab" data-rv-filter="pending">Pending Verification</button>
                    <button type="button" class="period-tab" data-rv-filter="verified">Verified</button>
                    <button type="button" class="period-tab" data-rv-filter="incomplete">Missing Mobile / Address</button>
                </div>
                <input type="search" id="rvSearchInput" placeholder="Search name, email, mobile, or address..." style="min-width:260px; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.86rem;">
            </div>
        </div>

        <div class="table-container" style="padding-top:0;">
            <table>
                <thead>
                    <tr>
                        <th>Resident</th>
                        <th>Email Address</th>
                        <th>Mobile Number</th>
                        <th>Purok &amp; Complete Address</th>
                        <th>Verification Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="residentVerificationTbody">
                    <tr><td colspan="6">Loading resident accounts...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(() => {
    let cachedResidents = [];
    let currentFilter = 'all';

    const esc = (v) => {
        const div = document.createElement('div');
        div.textContent = v ?? '';
        return div.innerHTML;
    };

    function renderTable() {
        const tbody = document.getElementById('residentVerificationTbody');
        const q = (document.getElementById('rvSearchInput')?.value || '').trim().toLowerCase();
        if (!tbody) return;

        const rows = cachedResidents.filter((item) => {
            if (currentFilter === 'pending' && Number(item.residency_verified) === 1) return false;
            if (currentFilter === 'verified' && Number(item.residency_verified) !== 1) return false;
            if (currentFilter === 'incomplete' && item.profile_complete) return false;
            if (!q) return true;
            const hay = `${item.full_name} ${item.username} ${item.email} ${item.contact_no} ${item.purok} ${item.address}`.toLowerCase();
            return hay.includes(q);
        });

        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#64748b;">No resident accounts match the current filter.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map((item) => {
            const isVerified = Number(item.residency_verified) === 1;
            const canVerify = Boolean(item.profile_complete);
            const addrParts = [item.purok, item.address].filter(Boolean);
            const addrHtml = addrParts.length > 0
                ? esc(addrParts.join(' — '))
                : '<span style="color:#94a3b8; font-style:italic;">Not added yet in profile</span>';
            const mobileHtml = item.contact_no
                ? esc(item.contact_no)
                : '<span style="color:#94a3b8; font-style:italic;">Not added yet in profile</span>';

            let statusBadge = '<span class="user-status-badge status-pending">Pending Verification</span>';
            if (isVerified) {
                statusBadge = '<span class="user-status-badge status-active">Verified</span>';
            } else if (!canVerify) {
                statusBadge = '<span class="user-status-badge status-disabled" title="Resident must complete Mobile Number and Address in My Profile">Incomplete Profile</span>';
            }

            const actionBtn = isVerified
                ? `<button type="button" class="btn-disable" data-rv-toggle="${Number(item.user_id)}" data-rv-target="0">Unverify</button>`
                : `<button type="button" class="btn-enable" ${canVerify ? '' : 'disabled title="Resident must fill Mobile Number and Address in My Profile first" style="opacity:0.55; cursor:not-allowed;"'} data-rv-toggle="${Number(item.user_id)}" data-rv-target="1">Verify Resident</button>`;

            return `
                <tr>
                    <td>
                        <div style="font-weight:700; color:#0f172a;">${esc(item.full_name || item.username)}</div>
                        <div style="font-size:0.78rem; color:#64748b;">@${esc(item.username)}</div>
                    </td>
                    <td>${esc(item.email || '—')}</td>
                    <td>${mobileHtml}</td>
                    <td>${addrHtml}</td>
                    <td>${statusBadge}</td>
                    <td class="action-buttons">${actionBtn}</td>
                </tr>
            `;
        }).join('');
    }

    async function loadResidents() {
        const tbody = document.getElementById('residentVerificationTbody');
        try {
            const resp = await fetch('../../../backend/api/users/resident-verification.php', {
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            const data = await resp.json();
            if (!resp.ok || !data.success) {
                if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="color:#b91c1c;">${esc(data.message || 'Unable to load resident accounts.')}</td></tr>`;
                return;
            }
            cachedResidents = Array.isArray(data.residents) ? data.residents : [];
            const sum = data.summary || {};
            document.getElementById('rvTotalCount').textContent = String(sum.total ?? cachedResidents.length);
            document.getElementById('rvPendingCount').textContent = String(sum.pending ?? 0);
            document.getElementById('rvVerifiedCount').textContent = String(sum.verified ?? 0);
            document.getElementById('rvIncompleteCount').textContent = String(sum.incomplete ?? 0);
            renderTable();
        } catch (_) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="color:#b91c1c;">Connection error while loading resident accounts.</td></tr>';
        }
    }

    document.querySelectorAll('[data-rv-filter]').forEach((btn) => {
        btn.addEventListener('click', () => {
            currentFilter = btn.getAttribute('data-rv-filter') || 'all';
            document.querySelectorAll('[data-rv-filter]').forEach((b) => b.classList.toggle('active', b === btn));
            renderTable();
        });
    });

    document.getElementById('rvSearchInput')?.addEventListener('input', renderTable);

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-rv-toggle]');
        if (!btn || btn.disabled) return;
        const userId = Number(btn.getAttribute('data-rv-toggle'));
        const verified = Number(btn.getAttribute('data-rv-target'));
        const actionMsg = verified === 1
            ? 'Verify this resident account using their submitted mobile number and address?'
            : 'Revert this resident account verification status back to Pending?';
        if (!confirm(actionMsg)) return;

        btn.disabled = true;
        try {
            const resp = await fetch('../../../backend/api/users/resident-verification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ user_id: userId, verified })
            });
            const data = await resp.json();
            if (!resp.ok || !data.success) {
                window.agapNotify?.(data.message || 'Unable to update verification.', 'error', 'Resident Verification');
                btn.disabled = false;
                return;
            }
            window.agapNotify?.(data.message || 'Verification status updated.', 'success', 'Resident Verification');
            await loadResidents();
        } catch (_) {
            window.agapNotify?.('Connection error while updating verification.', 'error', 'Resident Verification');
            btn.disabled = false;
        }
    });

    loadResidents();
})();
</script>

<?php include '../../layouts/footer.php'; ?>
