<?php

session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3, 4], true)) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/models/Paanyaya.php';
require_once __DIR__ . '/../../../backend/models/Setting.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';

$paanyayaModel = new Paanyaya();
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$searchFilter = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$paanyayas = $paanyayaModel->getAllPaanyayas($statusFilter !== '' ? $statusFilter : null, $searchFilter !== '' ? $searchFilter : null);

// Count summary
$allList = $paanyayaModel->getAllPaanyayas();
$countTotal = count($allList);
$countScheduled = 0;
$countCompleted = 0;
$countCancelled = 0;
foreach ($allList as $item) {
    $st = $item['status'] ?? '';
    if ($st === 'Scheduled') $countScheduled++;
    elseif ($st === 'Completed' || $st === 'Resolved') $countCompleted++;
    elseif ($st === 'Cancelled' || $st === 'Failed' || $st === 'Escalated') $countCancelled++;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">

<style>
.paanyaya-container {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}
.paanyaya-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}
.paanyaya-header-title h1 {
    font-size: 1.6rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 4px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.paanyaya-header-title p {
    font-size: 0.9rem;
    color: #64748b;
    margin: 0;
}
.paanyaya-badge-stage0 {
    font-size: 0.72rem;
    font-weight: 700;
    background: #e0e7ff;
    color: #3730a3;
    padding: 3px 9px;
    border-radius: 9999px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.btn-new-paanyaya {
    background: #2563eb;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.88rem;
    padding: 10px 18px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(37,99,235,0.25);
    transition: all .15s ease;
}
.btn-new-paanyaya:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(37,99,235,0.3);
}
.paanyaya-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.paanyaya-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.paanyaya-stat-card .stat-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 6px;
}
.paanyaya-stat-card .stat-value {
    font-size: 1.7rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
}
.paanyaya-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.paanyaya-toolbar {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    background: #f8fafc;
}
.paanyaya-search-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    max-width: 500px;
}
.paanyaya-search-input {
    flex: 1;
    padding: 8px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.88rem;
    background: #ffffff;
}
.paanyaya-filter-tabs {
    display: flex;
    gap: 6px;
    align-items: center;
}
.paanyaya-filter-btn {
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748b;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    cursor: pointer;
    text-decoration: none;
    transition: all .15s ease;
}
.paanyaya-filter-btn.active, .paanyaya-filter-btn:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}
.table-responsive {
    width: 100%;
    overflow-x: auto;
}
.paanyaya-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
    text-align: left;
}
.paanyaya-table th {
    background: #f1f5f9;
    padding: 12px 18px;
    font-weight: 700;
    color: #475569;
    border-bottom: 1px solid #e2e8f0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.paanyaya-table td {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}
.paanyaya-table tr:hover {
    background: #f8fafc;
}
.status-pill-scheduled {
    background: #dbeafe;
    color: #1e40af;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 9999px;
    border: 1px solid #bfdbfe;
    display: inline-block;
}
.status-pill-resolved {
    background: #dcfce7;
    color: #15803d;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 9999px;
    border: 1px solid #bbf7d0;
    display: inline-block;
}
.status-pill-cancelled {
    background: #fee2e2;
    color: #991b1b;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 9999px;
    border: 1px solid #fecaca;
    display: inline-block;
}
.btn-action-view {
    padding: 5px 12px;
    background: #ffffff;
    color: #0f172a;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all .15s ease;
}
.btn-action-view:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.btn-action-print {
    padding: 5px 10px;
    background: #f0fdf4;
    color: #15803d;
    border: 1px solid #bbf7d0;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-action-print:hover {
    background: #dcfce7;
}

/* Modal styles */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 16px;
}
.modal-card {
    background: #ffffff;
    border-radius: 12px;
    width: 100%;
    max-width: 580px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
    overflow: hidden;
}
.modal-head {
    padding: 16px 22px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
}
.modal-head h3 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
}
.modal-close-btn {
    background: none;
    border: none;
    font-size: 1.3rem;
    color: #64748b;
    cursor: pointer;
    padding: 4px;
}
.modal-body {
    padding: 22px;
}
.form-group {
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    font-size: 0.84rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
}
.form-control {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.9rem;
    box-sizing: border-box;
    background: #ffffff;
}
.form-control:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37,99,235,0.2);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.modal-foot {
    padding: 14px 22px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
</style>

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="paanyaya-container">
            <!-- Header Section -->
            <div class="paanyaya-header">
                <div class="paanyaya-header-title">
                    <h1>
                        <span>✉️ Paanyaya Management</span>
                        <span class="paanyaya-badge-stage0">Stage 0 · Informal Dialogue</span>
                    </h1>
                    <p>Pre-docketed amicable dispute invitations pursuant to Barangay Tumana KP process</p>
                </div>
                <div>
                    <button type="button" class="btn-new-paanyaya" id="btnOpenScheduleModal">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>Schedule New Paanyaya</span>
                    </button>
                </div>
            </div>

            <!-- Summary KPI Grid -->
            <div class="paanyaya-stats-grid">
                <div class="paanyaya-stat-card">
                    <div class="stat-label">Total Paanyayas</div>
                    <div class="stat-value"><?php echo $countTotal; ?></div>
                </div>
                <div class="paanyaya-stat-card">
                    <div class="stat-label">Scheduled / Pending</div>
                    <div class="stat-value" style="color: #2563eb;"><?php echo $countScheduled; ?></div>
                </div>
                <div class="paanyaya-stat-card">
                    <div class="stat-label">Settled / Closed</div>
                    <div class="stat-value" style="color: #16a34a;"><?php echo $countCompleted; ?></div>
                </div>
                <div class="paanyaya-stat-card">
                    <div class="stat-label">Cancelled / Escalated</div>
                    <div class="stat-value" style="color: #64748b;"><?php echo $countCancelled; ?></div>
                </div>
            </div>

            <!-- Main Table Card -->
            <div class="paanyaya-table-card">
                <div class="paanyaya-toolbar">
                    <form method="GET" action="index.php" class="paanyaya-search-form">
                        <input type="text" name="search" class="paanyaya-search-input" placeholder="Search by invitee name or complaint..." value="<?php echo htmlspecialchars($searchFilter); ?>">
                        <?php if ($statusFilter !== ''): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn-action-view">Search</button>
                        <?php if ($searchFilter !== ''): ?>
                            <a href="index.php<?php echo $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''; ?>" class="btn-action-view">Clear</a>
                        <?php endif; ?>
                    </form>

                    <div class="paanyaya-filter-tabs">
                        <a href="index.php<?php echo $searchFilter !== '' ? '?search=' . urlencode($searchFilter) : ''; ?>" class="paanyaya-filter-btn <?php echo $statusFilter === '' ? 'active' : ''; ?>">All (<?php echo $countTotal; ?>)</a>
                        <a href="index.php?status=Scheduled<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>" class="paanyaya-filter-btn <?php echo $statusFilter === 'Scheduled' ? 'active' : ''; ?>">Scheduled</a>
                        <a href="index.php?status=Resolved<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>" class="paanyaya-filter-btn <?php echo in_array($statusFilter, ['Resolved', 'Completed']) ? 'active' : ''; ?>">Resolved</a>
                        <a href="index.php?status=Cancelled<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>" class="paanyaya-filter-btn <?php echo $statusFilter === 'Cancelled' ? 'active' : ''; ?>">Cancelled</a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="paanyaya-table">
                        <thead>
                            <tr>
                                <th>Invitee (Inanyayahan)</th>
                                <th>Complainant (Nagpaanyaya)</th>
                                <th>Scheduled Date &amp; Time</th>
                                <th>Venue</th>
                                <th>Reference Complaint</th>
                                <th>Status</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($paanyayas)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #64748b; padding: 36px 20px;">
                                        <div style="font-size: 2rem; margin-bottom: 8px;">✉️</div>
                                        <div style="font-size: 0.95rem; font-weight: 600;">No Paanyaya invitations found.</div>
                                        <p style="font-size: 0.85rem; margin-top: 4px; color: #94a3b8;">Click "Schedule New Paanyaya" above to issue an informal invitation.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($paanyayas as $p): ?>
                                    <?php 
                                        $d = !empty($p['scheduled_date']) ? date('M j, Y', strtotime($p['scheduled_date'])) : '—';
                                        $t = !empty($p['scheduled_time']) ? date('g:i A', strtotime($p['scheduled_time'])) : '';
                                        $st = $p['status'] ?? 'Scheduled';
                                        $statusClass = 'status-pill-scheduled';
                                        if (in_array($st, ['Resolved', 'Completed'])) $statusClass = 'status-pill-resolved';
                                        elseif (in_array($st, ['Cancelled', 'Failed', 'Escalated'])) $statusClass = 'status-pill-cancelled';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong style="color: #0f172a; font-size: 0.92rem;"><?php echo htmlspecialchars($p['invitee_name'] ?: 'Inanyayahan'); ?></strong>
                                            <?php if (!empty($p['scheduled_by_name'])): ?>
                                                <div style="font-size: 0.74rem; color: #64748b; margin-top: 2px;">Issued by <?php echo htmlspecialchars($p['scheduled_by_name']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="color: #166534; font-size: 0.88rem;"><?php echo htmlspecialchars($p['complainant_name'] ?: 'Nagpaanyaya'); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #0f172a;"><?php echo $d; ?></div>
                                            <div style="font-size: 0.78rem; color: #64748b;"><?php echo $t; ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.84rem; color: #334155;">📍 <?php echo htmlspecialchars($p['venue'] ?: 'Tanggapan ng Barangay Tumana'); ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($p['complaint_number'])): ?>
                                                <a href="../complaints/complaint-details.php?id=<?php echo (int)$p['complaint_id']; ?>" style="color: #2563eb; font-weight: 600; text-decoration: none;">
                                                    <?php echo htmlspecialchars($p['complaint_number']); ?>
                                                </a>
                                                <?php if (!empty($p['complaint_title'])): ?>
                                                    <div style="font-size: 0.75rem; color: #64748b; max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                        <?php echo htmlspecialchars($p['complaint_title']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 0.82rem;">Pre-Docketed (None)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($st); ?></span>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                <?php if (!empty($p['complaint_id'])): ?>
                                                    <a href="../../../backend/api/paanyaya/preview.php?complaint_id=<?php echo (int)$p['complaint_id']; ?>&format=pdf" target="_blank" class="btn-action-print" title="Print Paanyaya Letter">
                                                        📄 Letter
                                                    </a>
                                                    <a href="../complaints/paanyaya-workspace.php?complaint_id=<?php echo (int)$p['complaint_id']; ?>" class="btn-action-view" title="Open Dialogue Workspace">
                                                        Workspace &rarr;
                                                    </a>
                                                <?php else: ?>
                                                    <a href="../../../backend/api/paanyaya/preview.php?paanyaya_id=<?php echo (int)$p['paanyaya_id']; ?>&format=pdf" target="_blank" class="btn-action-print" title="Print Paanyaya Letter">
                                                        📄 Letter
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Schedule New Paanyaya (Tracks ONLY essential info per user spec) -->
<div class="modal-overlay" id="schedulePaanyayaModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>✉️ Schedule Paanyaya Invitation</h3>
            <button type="button" class="modal-close-btn" id="modalCloseBtn">&times;</button>
        </div>
        <form id="schedulePaanyayaForm">
            <div class="modal-body">
                <div class="form-group">
                    <label for="schedComplainantName">Name of Complainant (Nagpaanyaya) <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="schedComplainantName" name="complainant_name" class="form-control" placeholder="Full name of complainant / nagpaanyaya" required>
                </div>

                <div class="form-group">
                    <label for="schedInviteeName">Name of Person Being Invited (Inanyayahan) <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="schedInviteeName" name="invitee_name" class="form-control" placeholder="Full name of respondent / inanyayahan" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="schedDate">Scheduled Date <span style="color: #dc2626;">*</span></label>
                        <input type="date" id="schedDate" name="scheduled_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+3 weekdays')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="schedTime">Scheduled Time <span style="color: #dc2626;">*</span></label>
                        <input type="time" id="schedTime" name="scheduled_time" class="form-control" value="14:00" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="schedVenue">Venue</label>
                    <input type="text" id="schedVenue" name="venue" class="form-control" value="Tanggapan ng Barangay Tumana" required>
                </div>

                <div class="form-group">
                    <label for="schedComplaintSelect">Optional: Link to Existing Complaint</label>
                    <select id="schedComplaintSelect" name="complaint_id" class="form-control">
                        <option value="">-- None (Standalone Pre-Docketed Paanyaya) --</option>
                        <?php
                            try {
                                $recentComplaints = (new Database())->connect()->query("
                                    SELECT complaint_id, complaint_number, complaint_title 
                                    FROM complaints 
                                    WHERE status IN ('Pre-Docketed (Paanyaya)', 'Under Review', 'Paanyaya In Progress') 
                                    ORDER BY complaint_id DESC LIMIT 30
                                ")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($recentComplaints as $rc) {
                                    echo '<option value="' . (int)$rc['complaint_id'] . '">' . htmlspecialchars(($rc['complaint_number'] ?: 'Complaint #' . $rc['complaint_id']) . ' - ' . $rc['complaint_title']) . '</option>';
                                }
                            } catch (Throwable $e) {}
                        ?>
                    </select>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-action-view" id="modalCancelBtn">Cancel</button>
                <button type="submit" class="btn-new-paanyaya" id="btnSubmitPaanyaya">Save &amp; Generate Paanyaya</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('schedulePaanyayaModal');
    const openBtn = document.getElementById('btnOpenScheduleModal');
    const closeBtn = document.getElementById('modalCloseBtn');
    const cancelBtn = document.getElementById('modalCancelBtn');
    const form = document.getElementById('schedulePaanyayaForm');
    const submitBtn = document.getElementById('btnSubmitPaanyaya');

    const openModal = () => { if (modal) modal.style.display = 'flex'; };
    const closeModal = () => { if (modal) modal.style.display = 'none'; };

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            const complainant = document.getElementById('schedComplainantName').value.trim();
            const invitee = document.getElementById('schedInviteeName').value.trim();
            const date = document.getElementById('schedDate').value;
            const time = document.getElementById('schedTime').value;
            const venue = document.getElementById('schedVenue').value.trim();
            const complaintId = document.getElementById('schedComplaintSelect').value;

            if (!complainant || !invitee || !date || !time) {
                alert('Paki-puno ang pangalan ng nagpaanyaya, inanyayahan, petsa, at oras.');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Isinesave...';

            try {
                const payload = {
                    complainant_name: complainant,
                    invitee_name: invitee,
                    scheduled_date: date,
                    scheduled_time: time,
                    venue: venue,
                    complaint_id: complaintId ? parseInt(complaintId, 10) : 0,
                    has_e_signature: 1
                };

                const res = await fetch('../../../backend/api/paanyaya/schedule.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                const result = await res.json().catch(() => ({}));

                if (!res.ok || !result.success) {
                    if (typeof window.showPopupModal === 'function') {
                        await window.showPopupModal(result.message || 'Hindi ma-iskedyul ang Paanyaya.', 'error', 'Pansin');
                    } else {
                        alert(result.message || 'Hindi ma-iskedyul ang Paanyaya.');
                    }
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Save & Generate Paanyaya';
                    return;
                }

                closeModal();
                if (typeof window.showSuccessPopup === 'function') {
                    await window.showSuccessPopup('Matagumpay na nai-iskedyul ang Paanyaya para kay ' + invitee + '!', 'Tagumpay!');
                } else {
                    alert('Matagumpay na nai-iskedyul ang Paanyaya para kay ' + invitee + '!');
                }
                window.location.reload();
            } catch (err) {
                console.error(err);
                if (typeof window.showPopupModal === 'function') {
                    await window.showPopupModal('May naganap na error sa pag-save ng Paanyaya.', 'error', 'Error');
                } else {
                    alert('May naganap na error sa pag-save ng Paanyaya.');
                }
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save & Generate Paanyaya';
            }
        });
    }
});
</script>

<?php include '../../layouts/footer.php'; ?>
