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
    cursor: pointer;
    text-decoration: none;
    display: block;
    user-select: none;
    position: relative;
    transition: all .18s cubic-bezier(0.16, 1, 0.3, 1);
}
.paanyaya-stat-card:hover {
    transform: translateY(-2px);
    border-color: #cbd5e1;
    box-shadow: 0 6px 16px rgba(0,0,0,0.06);
}
.paanyaya-stat-card:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.35);
}
.paanyaya-stat-card.is-active {
    border-width: 2px;
}
.paanyaya-stat-card.card-all.is-active {
    border-color: #0f172a;
    background: #f8fafc;
    box-shadow: 0 0 0 1px #0f172a;
}
.paanyaya-stat-card.card-scheduled.is-active {
    border-color: #2563eb;
    background: #f8faff;
    box-shadow: 0 0 0 1px #2563eb;
}
.paanyaya-stat-card.card-settled.is-active {
    border-color: #16a34a;
    background: #f0fdf4;
    box-shadow: 0 0 0 1px #16a34a;
}
.paanyaya-stat-card.card-cancelled.is-active {
    border-color: #64748b;
    background: #f8fafc;
    box-shadow: 0 0 0 1px #64748b;
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
.status-pill-escalated {
    background: #f3e8ff;
    color: #6b21a8;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 9999px;
    border: 1px solid #e9d5ff;
    display: inline-block;
}
.btn-action-view {
    padding: 6px 12px;
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
    padding: 6px 12px;
    background: #f0fdf4;
    color: #15803d;
    border: 1px solid #bbf7d0;
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
.btn-action-print:hover {
    background: #dcfce7;
    border-color: #86efac;
}

/* Modals */
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
    max-width: 620px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
    overflow: hidden;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}
.modal-card-large {
    max-width: 920px;
}
.modal-head {
    padding: 16px 22px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
    flex-shrink: 0;
}
.modal-head h3 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-close-btn {
    background: none;
    border: none;
    font-size: 1.4rem;
    line-height: 1;
    color: #64748b;
    cursor: pointer;
    padding: 4px;
    transition: color 0.15s ease;
}
.modal-close-btn:hover {
    color: #0f172a;
}
.modal-body {
    padding: 22px;
    overflow-y: auto;
}
.modal-foot {
    padding: 14px 22px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex-shrink: 0;
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
    font-family: inherit;
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
.form-row-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
}
@media (max-width: 640px) {
    .form-row, .form-row-3 {
        grid-template-columns: 1fr;
    }
}
.modal-section-title {
    font-size: 0.88rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Attendance Pills */
.attendance-party-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 12px;
}
.attendance-party-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.attendance-party-label {
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #475569;
}
.attendance-party-name {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
}
.attendance-pills-row {
    display: flex;
    gap: 8px;
}
.attendance-radio-pill {
    flex: 1;
    position: relative;
}
.attendance-radio-pill input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.attendance-pill-label {
    display: block;
    text-align: center;
    padding: 7px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 700;
    color: #475569;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.attendance-radio-pill input[value="Present"]:checked + .attendance-pill-label {
    background: #dcfce7;
    color: #15803d;
    border-color: #86efac;
    box-shadow: 0 0 0 1px #16a34a;
}
.attendance-radio-pill input[value="Absent"]:checked + .attendance-pill-label {
    background: #fee2e2;
    color: #b91c1c;
    border-color: #fca5a5;
    box-shadow: 0 0 0 1px #dc2626;
}

/* Escalation Button */
.btn-escalate-mediation {
    background: #7c3aed;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.84rem;
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 1px 3px rgba(124, 58, 237, 0.25);
    transition: all .15s ease;
}
.btn-escalate-mediation:hover {
    background: #6d28d9;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(124, 58, 237, 0.3);
}
.btn-resolve-dialogue {
    background: #16a34a;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.84rem;
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s ease;
}
.btn-resolve-dialogue:hover {
    background: #15803d;
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
            <div class="paanyaya-stats-grid" id="paanyayaStatsGrid" role="region" aria-label="Paanyaya Summary Metrics">
                <a href="index.php<?php echo $searchFilter !== '' ? '?search=' . urlencode($searchFilter) : ''; ?>" 
                   class="paanyaya-stat-card card-all <?php echo $statusFilter === '' ? 'is-active' : ''; ?>" 
                   data-status="" 
                   id="paanyayaCardAll"
                   title="Click to view all Paanyayas (Ipakita ang lahat ng Paanyaya)">
                    <div class="stat-label">Total Paanyayas</div>
                    <div class="stat-value"><?php echo $countTotal; ?></div>
                </a>
                <a href="index.php?status=Scheduled<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>" 
                   class="paanyaya-stat-card card-scheduled <?php echo $statusFilter === 'Scheduled' ? 'is-active' : ''; ?>" 
                   data-status="Scheduled" 
                   id="paanyayaCardScheduled"
                   title="Click to filter scheduled / pending Paanyayas">
                    <div class="stat-label">Scheduled / Pending</div>
                    <div class="stat-value" style="color: #2563eb;"><?php echo $countScheduled; ?></div>
                </a>
                <a href="index.php?status=Resolved<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>" 
                   class="paanyaya-stat-card card-settled <?php echo in_array($statusFilter, ['Resolved', 'Completed', 'Settled']) ? 'is-active' : ''; ?>" 
                   data-status="Resolved" 
                   id="paanyayaCardSettled"
                   title="Click to filter settled / closed Paanyayas">
                    <div class="stat-label">Settled / Closed</div>
                    <div class="stat-value" style="color: #16a34a;"><?php echo $countCompleted; ?></div>
                </a>
                <a href="index.php?status=Cancelled<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>" 
                   class="paanyaya-stat-card card-cancelled <?php echo in_array($statusFilter, ['Cancelled', 'Escalated', 'Failed']) ? 'is-active' : ''; ?>" 
                   data-status="Cancelled" 
                   id="paanyayaCardCancelled"
                   title="Click to filter cancelled / escalated Paanyayas">
                    <div class="stat-label">Cancelled / Escalated</div>
                    <div class="stat-value" style="color: #64748b;"><?php echo $countCancelled; ?></div>
                </a>
            </div>

            <!-- Main Table Card (Venue and Reference Complaint columns removed per user spec) -->
            <div class="paanyaya-table-card">
                <div class="paanyaya-toolbar">
                    <form method="GET" action="index.php" class="paanyaya-search-form">
                        <input type="text" name="search" class="paanyaya-search-input" placeholder="Search by invitee or complainant name..." value="<?php echo htmlspecialchars($searchFilter); ?>">
                        <?php if ($statusFilter !== ''): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn-action-view">Search</button>
                        <?php if ($searchFilter !== ''): ?>
                            <a href="index.php<?php echo $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''; ?>" class="btn-action-view">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="paanyaya-table">
                        <thead>
                            <tr>
                                <th>Invitee (Inanyayahan)</th>
                                <th>Complainant (Nagpaanyaya)</th>
                                <th>Scheduled Date &amp; Time</th>
                                <th>Status</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($paanyayas)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #64748b; padding: 36px 20px;">
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
                                        elseif ($st === 'Escalated') $statusClass = 'status-pill-escalated';
                                        elseif (in_array($st, ['Cancelled', 'Failed'])) $statusClass = 'status-pill-cancelled';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong style="color: #0f172a; font-size: 0.92rem;"><?php echo htmlspecialchars($p['invitee_name'] ?: 'Inanyayahan'); ?></strong>
                                            <?php if (!empty($p['scheduled_by_name'])): ?>
                                                <div style="font-size: 0.74rem; color: #64748b; margin-top: 2px;">Issued by <?php echo htmlspecialchars($p['scheduled_by_name']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="color: #166534; font-size: 0.88rem;"><?php echo htmlspecialchars(!empty($p['complainant_name']) ? $p['complainant_name'] : 'Nagpaanyaya'); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #0f172a;"><?php echo $d; ?></div>
                                            <div style="font-size: 0.78rem; color: #64748b;"><?php echo $t; ?></div>
                                        </td>
                                        <td>
                                            <span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($st); ?></span>
                                            <?php if (!empty($p['complainant_attended']) || !empty($p['invitee_attended'])): ?>
                                                <div style="font-size: 0.72rem; color: #64748b; margin-top: 3px;">
                                                    Att: <?php echo htmlspecialchars(substr($p['complainant_attended'] ?? '?', 0, 1)); ?>/<?php echo htmlspecialchars(substr($p['invitee_attended'] ?? '?', 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                <!-- Preview Action: Opens in Modal without redirecting -->
                                                <button type="button" class="btn-action-print btn-preview-modal" 
                                                        data-paanyaya-id="<?php echo (int)$p['paanyaya_id']; ?>" 
                                                        data-complaint-id="<?php echo (int)($p['complaint_id'] ?? 0); ?>" 
                                                        data-name="<?php echo htmlspecialchars($p['invitee_name'] ?: 'Inanyayahan'); ?>"
                                                        title="Preview Paanyaya Official Letter">
                                                    📄 Letter
                                                </button>

                                                <!-- Attendance Tracking & Action: Opens attendance modal -->
                                                <button type="button" class="btn-action-view btn-open-attendance" 
                                                        data-paanyaya-id="<?php echo (int)$p['paanyaya_id']; ?>" 
                                                        data-complaint-id="<?php echo (int)($p['complaint_id'] ?? 0); ?>" 
                                                        data-invitee="<?php echo htmlspecialchars($p['invitee_name'] ?: 'Inanyayahan'); ?>"
                                                        data-complainant="<?php echo htmlspecialchars($p['complainant_name'] ?: 'Nagpaanyaya'); ?>"
                                                        data-date="<?php echo htmlspecialchars($d); ?>"
                                                        data-time="<?php echo htmlspecialchars($t); ?>"
                                                        data-status="<?php echo htmlspecialchars($st); ?>"
                                                        title="Track Attendance and Resolve or Escalate">
                                                    📋 Attendance
                                                </button>
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

<!-- =========================================================================
     MODAL 1: SCHEDULE NEW PAANYAYA (With Salaysay Statement Narrative Field)
     ========================================================================= -->
<div class="modal-overlay" id="schedulePaanyayaModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>✉️ Schedule Paanyaya Invitation</h3>
            <button type="button" class="modal-close-btn" id="modalCloseBtn">&times;</button>
        </div>
        <form id="schedulePaanyayaForm">
            <div class="modal-body">
                <!-- Name of Complainant -->
                <div class="modal-section-title">
                    Name of Complainant (Nagpaanyaya)
                </div>
                <div class="form-row-3" style="margin-bottom: 14px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="schedComplainantFirstName">First Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="schedComplainantFirstName" name="complainant_first_name" class="form-control" placeholder="First name" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="schedComplainantMiddleName">Middle Name</label>
                        <input type="text" id="schedComplainantMiddleName" name="complainant_middle_name" class="form-control" placeholder="Middle name (optional)">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="schedComplainantLastName">Last Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="schedComplainantLastName" name="complainant_last_name" class="form-control" placeholder="Last name" required>
                    </div>
                </div>

                <!-- Name of Person Being Invited -->
                <div class="modal-section-title">
                    Name of Person Being Invited (Inanyayahan)
                </div>
                <div class="form-row-3" style="margin-bottom: 14px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="schedInviteeFirstName">First Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="schedInviteeFirstName" name="invitee_first_name" class="form-control" placeholder="First name" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="schedInviteeMiddleName">Middle Name</label>
                        <input type="text" id="schedInviteeMiddleName" name="invitee_middle_name" class="form-control" placeholder="Middle name (optional)">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="schedInviteeLastName">Last Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="schedInviteeLastName" name="invitee_last_name" class="form-control" placeholder="Last name" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="schedDate">Scheduled Date <span style="color:#ef4444;">*</span></label>
                        <input type="date" id="schedDate" name="scheduled_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d', strtotime('+3 weekdays')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="schedTime">Scheduled Time <span style="color:#ef4444;">*</span></label>
                        <input type="time" id="schedTime" name="scheduled_time" class="form-control" value="14:00" required>
                    </div>
                </div>

                <!-- Salaysay (Statement Narrative) Input Field -->
                <div class="form-group">
                    <label for="schedSalaysay" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Salaysay (Statement / Dispute Narrative)</span>
                        <span style="font-size: 0.74rem; color: #64748b; font-weight: normal;">Optional · Nililipat sa Mediation</span>
                    </label>
                    <textarea id="schedSalaysay" name="salaysay" class="form-control" rows="3" placeholder="Ilarawan ang buod ng sumbong, dahilan ng pag-anyaya, o salaysay ng nagpaanyaya..."></textarea>
                    <div style="font-size: 0.74rem; color: #64748b; margin-top: 4px;">
                        Ang salaysay na ito ay awtomatikong ililipat sa Pormal na Mediation kapag inakyat ang kaso.
                    </div>
                </div>

                <input type="hidden" id="schedVenue" name="venue" value="Tanggapan ng Barangay Tumana">
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-action-view" id="modalCancelBtn">Cancel</button>
                <button type="submit" class="btn-new-paanyaya" id="btnSubmitPaanyaya">Save &amp; Generate Paanyaya</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL 2: IN-PAGE LETTER PREVIEW MODAL (Replaces Page Redirection)
     ========================================================================= -->
<div class="modal-overlay" id="paanyayaPreviewModal">
    <div class="modal-card modal-card-large">
        <div class="modal-head">
            <div>
                <h3 id="previewModalTitle">✉️ Paanyaya Letter Preview</h3>
                <div id="previewModalSubtitle" style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Official Barangay Tumana Invitation</div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" class="btn-action-print" id="btnPrintFromModal" title="Print Letter">
                    🖨️ Print
                </button>
                <a href="#" target="_blank" class="btn-action-view" id="btnDownloadFromModal" title="Download PDF">
                    ⬇️ Download PDF
                </a>
                <button type="button" class="modal-close-btn" id="previewModalCloseBtn">&times;</button>
            </div>
        </div>
        <div class="modal-body" style="padding: 0; background: #525659;">
            <div id="previewLoadingNotice" style="padding: 40px 20px; text-align: center; color: #ffffff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;">
                <div style="font-size: 1.8rem;">⏳</div>
                <div style="font-weight: 600;">Naglo-load ng Paanyaya Letter...</div>
            </div>
            <iframe id="paanyayaPreviewIframe" src="" style="width: 100%; height: 65vh; min-height: 500px; border: none; display: none;"></iframe>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn-action-view" id="previewModalCloseFootBtn">Close</button>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL 3: ATTENDANCE TRACKING & SEAMLESS ESCALATION MODAL
     ========================================================================= -->
<div class="modal-overlay" id="paanyayaAttendanceModal">
    <div class="modal-card">
        <div class="modal-head">
            <div>
                <h3>📋 Paanyaya Attendance &amp; Action</h3>
                <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Stage 0 Dialogue Attendance &amp; Mediation Escalation</div>
            </div>
            <button type="button" class="modal-close-btn" id="attendanceModalCloseBtn">&times;</button>
        </div>
        <form id="attendanceActionForm">
            <input type="hidden" id="attPaanyayaId" name="paanyaya_id" value="">
            <input type="hidden" id="attComplaintId" name="complaint_id" value="">

            <div class="modal-body">
                <!-- Case Info Pill Strip -->
                <div style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 0.82rem; font-weight: 700; color: #1e293b;" id="attInviteeLabel">Inanyayahan: —</span>
                        <span class="status-pill-scheduled" id="attStatusBadge">Scheduled</span>
                    </div>
                    <div style="font-size: 0.82rem; color: #475569;" id="attComplainantLabel">Nagpaanyaya: —</div>
                    <div style="font-size: 0.78rem; color: #64748b; margin-top: 4px;" id="attScheduleLabel">Iskedyul: —</div>
                </div>

                <!-- 1. Complainant Attendance Tracking -->
                <div class="attendance-party-box">
                    <div class="attendance-party-head">
                        <div class="attendance-party-label">Nagpaanyaya (Complainant)</div>
                        <div class="attendance-party-name" id="attCompNameDisplay">Nagpaanyaya</div>
                    </div>
                    <div class="attendance-pills-row">
                        <label class="attendance-radio-pill">
                            <input type="radio" name="complainant_attended" value="Present" checked>
                            <span class="attendance-pill-label">✓ Present</span>
                        </label>
                        <label class="attendance-radio-pill">
                            <input type="radio" name="complainant_attended" value="Absent">
                            <span class="attendance-pill-label">✕ Absent</span>
                        </label>
                    </div>
                </div>

                <!-- 2. Invitee (Respondent) Attendance Tracking -->
                <div class="attendance-party-box">
                    <div class="attendance-party-head">
                        <div class="attendance-party-label">Inanyayahan (Invitee / Respondent)</div>
                        <div class="attendance-party-name" id="attInvNameDisplay">Inanyayahan</div>
                    </div>
                    <div class="attendance-pills-row">
                        <label class="attendance-radio-pill">
                            <input type="radio" name="invitee_attended" value="Present">
                            <span class="attendance-pill-label">✓ Present</span>
                        </label>
                        <label class="attendance-radio-pill">
                            <input type="radio" name="invitee_attended" value="Absent" checked>
                            <span class="attendance-pill-label">✕ Absent</span>
                        </label>
                    </div>
                </div>

                <!-- 3. Salaysay (Statement Narrative) -->
                <div class="form-group">
                    <label for="attSalaysay" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Salaysay (Statement Narrative)</span>
                        <span style="font-size: 0.74rem; color: #16a34a; font-weight: 600;">✓ Awtomatikong lilipat sa Mediation</span>
                    </label>
                    <textarea id="attSalaysay" name="salaysay" class="form-control" rows="3" placeholder="Salaysay ng nagpaanyaya ukol sa reklamo..."></textarea>
                </div>

                <!-- 4. Dialogue Minutes / Notes -->
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="attDialogueNotes">Tala ng Pag-uusap (Dialogue Summary / Session Notes)</label>
                    <textarea id="attDialogueNotes" name="dialogue_notes" class="form-control" rows="2" placeholder="Mga napag-usapan, napagkasunduan, o dahilan ng hindi pagkakasundo..."></textarea>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn-action-view" id="attendanceCancelBtn">Cancel</button>
                <button type="button" class="btn-action-view" id="btnSaveAttendanceOnly" style="background:#f1f5f9; border-color:#cbd5e1;">
                    💾 Save Attendance
                </button>
                <button type="button" class="btn-resolve-dialogue" id="btnResolveDialogue">
                    🤝 Kasunduan (Settle)
                </button>
                <button type="button" class="btn-escalate-mediation" id="btnEscalateMediation">
                    ⚖️ Escalate to Mediation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // =========================================================================
    // 1. SCHEDULE PAANYAYA MODAL
    // =========================================================================
    const schedModal = document.getElementById('schedulePaanyayaModal');
    const openSchedBtn = document.getElementById('btnOpenScheduleModal');
    const closeSchedBtn = document.getElementById('modalCloseBtn');
    const cancelSchedBtn = document.getElementById('modalCancelBtn');
    const schedForm = document.getElementById('schedulePaanyayaForm');
    const submitSchedBtn = document.getElementById('btnSubmitPaanyaya');
    const schedDateInput = document.getElementById('schedDate');
    const schedTimeInput = document.getElementById('schedTime');

    const updateMinDate = () => {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const todayStr = `${year}-${month}-${day}`;
        if (schedDateInput) schedDateInput.min = todayStr;
    };
    updateMinDate();

    const openSchedModal = () => {
        updateMinDate();
        if (schedModal) schedModal.style.display = 'flex';
    };
    const closeSchedModal = () => { if (schedModal) schedModal.style.display = 'none'; };

    if (openSchedBtn) openSchedBtn.addEventListener('click', openSchedModal);
    if (closeSchedBtn) closeSchedBtn.addEventListener('click', closeSchedModal);
    if (cancelSchedBtn) cancelSchedBtn.addEventListener('click', closeSchedModal);
    if (schedModal) {
        schedModal.addEventListener('click', (e) => {
            if (e.target === schedModal) closeSchedModal();
        });
    }

    if (schedForm) {
        schedForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const compFirst = document.getElementById('schedComplainantFirstName').value.trim();
            const compMiddle = document.getElementById('schedComplainantMiddleName').value.trim();
            const compLast = document.getElementById('schedComplainantLastName').value.trim();

            const invFirst = document.getElementById('schedInviteeFirstName').value.trim();
            const invMiddle = document.getElementById('schedInviteeMiddleName').value.trim();
            const invLast = document.getElementById('schedInviteeLastName').value.trim();

            const date = schedDateInput.value;
            const time = schedTimeInput.value;
            const venue = document.getElementById('schedVenue').value.trim() || 'Tanggapan ng Barangay Tumana';
            const salaysay = document.getElementById('schedSalaysay').value.trim();

            if (!compFirst || !compLast) {
                agapAlert('Please enter both First Name and Last Name of the complainant (nagpaanyaya).', 'warning');
                return;
            }

            if (!invFirst || !invLast) {
                agapAlert('Please enter both First Name and Last Name of the person being invited (inanyayahan).', 'warning');
                return;
            }

            if (!date || !time) {
                agapAlert('Please provide both scheduled date and time.', 'warning');
                return;
            }

            const complainantFullName = [compFirst, compMiddle, compLast].filter(Boolean).join(' ');
            const inviteeFullName = [invFirst, invMiddle, invLast].filter(Boolean).join(' ');

            submitSchedBtn.disabled = true;
            submitSchedBtn.textContent = 'Saving...';

            try {
                const payload = {
                    complainant_first_name: compFirst,
                    complainant_middle_name: compMiddle,
                    complainant_last_name: compLast,
                    complainant_name: complainantFullName,
                    invitee_first_name: invFirst,
                    invitee_middle_name: invMiddle,
                    invitee_last_name: invLast,
                    invitee_name: inviteeFullName,
                    scheduled_date: date,
                    scheduled_time: time,
                    venue: venue,
                    salaysay: salaysay,
                    complaint_id: 0,
                    has_e_signature: 1
                };

                const res = await fetch('../../../backend/api/paanyaya/schedule.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(payload)
                });
                const result = await res.json().catch(() => ({}));

                if (!res.ok || !result.success) {
                    agapAlert(result.message || 'Hindi ma-iskedyul ang Paanyaya.', 'error');
                    submitSchedBtn.disabled = false;
                    submitSchedBtn.textContent = 'Save & Generate Paanyaya';
                    return;
                }

                closeSchedModal();
                if (typeof window.showSuccessPopup === 'function') {
                    await window.showSuccessPopup('Matagumpay na nai-iskedyul ang Paanyaya para kay ' + inviteeFullName + '!', 'Tagumpay!');
                } else {
                    alert('Matagumpay na nai-iskedyul ang Paanyaya para kay ' + inviteeFullName + '!');
                }
                window.location.reload();
            } catch (err) {
                console.error(err);
                agapAlert('May naganap na error sa pag-save ng Paanyaya.', 'error');
                submitSchedBtn.disabled = false;
                submitSchedBtn.textContent = 'Save & Generate Paanyaya';
            }
        });
    }

    // =========================================================================
    // 2. LETTER PREVIEW MODAL (Replaces Page Redirection)
    // =========================================================================
    const previewModal = document.getElementById('paanyayaPreviewModal');
    const previewIframe = document.getElementById('paanyayaPreviewIframe');
    const previewLoading = document.getElementById('previewLoadingNotice');
    const previewCloseBtn = document.getElementById('previewModalCloseBtn');
    const previewCloseFootBtn = document.getElementById('previewModalCloseFootBtn');
    const previewPrintBtn = document.getElementById('btnPrintFromModal');
    const previewDownloadBtn = document.getElementById('btnDownloadFromModal');
    const previewTitle = document.getElementById('previewModalTitle');
    const previewSubtitle = document.getElementById('previewModalSubtitle');

    const closePreviewModal = () => {
        if (previewModal) previewModal.style.display = 'none';
        if (previewIframe) previewIframe.src = '';
    };

    if (previewCloseBtn) previewCloseBtn.addEventListener('click', closePreviewModal);
    if (previewCloseFootBtn) previewCloseFootBtn.addEventListener('click', closePreviewModal);
    if (previewModal) {
        previewModal.addEventListener('click', (e) => {
            if (e.target === previewModal) closePreviewModal();
        });
    }

    document.querySelectorAll('.btn-preview-modal').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const paanyayaId = this.dataset.paanyayaId || 0;
            const complaintId = this.dataset.complaintId || 0;
            const invitee = this.dataset.name || 'Inanyayahan';

            if (previewTitle) previewTitle.textContent = `✉️ Paanyaya Letter: ${invitee}`;
            if (previewSubtitle) previewSubtitle.textContent = `Barangay Tumana Official Dialogue Invitation Letter`;

            const previewUrl = (paanyayaId > 0)
                ? `../../../backend/api/paanyaya/preview.php?paanyaya_id=${paanyayaId}&format=html`
                : `../../../backend/api/paanyaya/preview.php?complaint_id=${complaintId}&format=html`;

            const pdfUrl = (paanyayaId > 0)
                ? `../../../backend/api/paanyaya/preview.php?paanyaya_id=${paanyayaId}&format=pdf`
                : `../../../backend/api/paanyaya/preview.php?complaint_id=${complaintId}&format=pdf`;

            if (previewDownloadBtn) {
                previewDownloadBtn.href = pdfUrl + '&download=1';
            }

            if (previewLoading) previewLoading.style.display = 'flex';
            if (previewIframe) {
                previewIframe.style.display = 'none';
                previewIframe.onload = function() {
                    if (previewLoading) previewLoading.style.display = 'none';
                    previewIframe.style.display = 'block';
                };
                previewIframe.src = previewUrl;
            }

            if (previewModal) previewModal.style.display = 'flex';
        });
    });

    if (previewPrintBtn) {
        previewPrintBtn.addEventListener('click', function() {
            if (previewIframe && previewIframe.contentWindow) {
                previewIframe.contentWindow.focus();
                previewIframe.contentWindow.print();
            }
        });
    }

    // =========================================================================
    // 3. ATTENDANCE TRACKING & SEAMLESS ESCALATION MODAL
    // =========================================================================
    const attModal = document.getElementById('paanyayaAttendanceModal');
    const attCloseBtn = document.getElementById('attendanceModalCloseBtn');
    const attCancelBtn = document.getElementById('attendanceCancelBtn');
    const attIdInput = document.getElementById('attPaanyayaId');
    const attComplaintIdInput = document.getElementById('attComplaintId');
    const attInviteeLabel = document.getElementById('attInviteeLabel');
    const attComplainantLabel = document.getElementById('attComplainantLabel');
    const attScheduleLabel = document.getElementById('attScheduleLabel');
    const attStatusBadge = document.getElementById('attStatusBadge');
    const attCompNameDisplay = document.getElementById('attCompNameDisplay');
    const attInvNameDisplay = document.getElementById('attInvNameDisplay');
    const attSalaysayInput = document.getElementById('attSalaysay');
    const attNotesInput = document.getElementById('attDialogueNotes');

    const btnSaveAttOnly = document.getElementById('btnSaveAttendanceOnly');
    const btnResolveDiag = document.getElementById('btnResolveDialogue');
    const btnEscalateMed = document.getElementById('btnEscalateMediation');

    const closeAttModal = () => { if (attModal) attModal.style.display = 'none'; };
    if (attCloseBtn) attCloseBtn.addEventListener('click', closeAttModal);
    if (attCancelBtn) attCancelBtn.addEventListener('click', closeAttModal);
    if (attModal) {
        attModal.addEventListener('click', (e) => {
            if (e.target === attModal) closeAttModal();
        });
    }

    document.querySelectorAll('.btn-open-attendance').forEach(btn => {
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            const paanyayaId = this.dataset.paanyayaId || 0;
            const complaintId = this.dataset.complaintId || 0;
            const invitee = this.dataset.invitee || 'Inanyayahan';
            const complainant = this.dataset.complainant || 'Nagpaanyaya';
            const date = this.dataset.date || '';
            const time = this.dataset.time || '';
            const status = this.dataset.status || 'Scheduled';

            if (attIdInput) attIdInput.value = paanyayaId;
            if (attComplaintIdInput) attComplaintIdInput.value = complaintId;
            if (attInviteeLabel) attInviteeLabel.textContent = `Inanyayahan: ${invitee}`;
            if (attComplainantLabel) attComplainantLabel.textContent = `Nagpaanyaya: ${complainant}`;
            if (attScheduleLabel) attScheduleLabel.textContent = `Iskedyul: ${date} ${time}`;
            if (attStatusBadge) {
                attStatusBadge.textContent = status;
                attStatusBadge.className = (status === 'Resolved') ? 'status-pill-resolved' : ((status === 'Escalated') ? 'status-pill-escalated' : 'status-pill-scheduled');
            }
            if (attCompNameDisplay) attCompNameDisplay.textContent = complainant;
            if (attInvNameDisplay) attInvNameDisplay.textContent = invitee;

            // Reset inputs to clean state
            if (attSalaysayInput) attSalaysayInput.value = '';
            if (attNotesInput) attNotesInput.value = '';
            setRadio('complainant_attended', 'Present');
            setRadio('invitee_attended', 'Absent');

            // Open modal immediately
            if (attModal) attModal.style.display = 'flex';

            // Fetch latest persisted attendance and salaysay
            try {
                const res = await fetch(`../../../backend/api/paanyaya/attendance.php?paanyaya_id=${paanyayaId}`);
                const json = await res.json();
                if (json.success && json.data) {
                    const row = json.data;
                    if (row.salaysay && attSalaysayInput) attSalaysayInput.value = row.salaysay;
                    if (row.dialogue_notes && attNotesInput) attNotesInput.value = row.dialogue_notes;
                    if (row.complainant_attended) setRadio('complainant_attended', row.complainant_attended);
                    if (row.invitee_attended) setRadio('invitee_attended', row.invitee_attended);
                    if (row.status && attStatusBadge) {
                        attStatusBadge.textContent = row.status;
                        attStatusBadge.className = (row.status === 'Resolved') ? 'status-pill-resolved' : ((row.status === 'Escalated') ? 'status-pill-escalated' : 'status-pill-scheduled');
                    }
                }
            } catch (err) {
                console.warn('Could not fetch existing attendance data:', err);
            }
        });
    });

    function setRadio(name, value) {
        const rad = document.querySelector(`input[name="${name}"][value="${value}"]`);
        if (rad) rad.checked = true;
    }

    function getRadio(name) {
        return document.querySelector(`input[name="${name}"]:checked`)?.value || 'Present';
    }

    async function submitAttendanceAction(actionType, confirmMessage = null) {
        if (confirmMessage && !confirm(confirmMessage)) {
            return;
        }

        const paanyayaId = parseInt(attIdInput.value, 10);
        if (!paanyayaId) {
            agapAlert('Invalid Paanyaya record.', 'error');
            return;
        }

        const payload = {
            paanyaya_id: paanyayaId,
            complaint_id: parseInt(attComplaintIdInput.value || 0, 10),
            complainant_attended: getRadio('complainant_attended'),
            invitee_attended: getRadio('invitee_attended'),
            salaysay: attSalaysayInput?.value.trim() || '',
            dialogue_notes: attNotesInput?.value.trim() || '',
            action: actionType
        };

        const activeBtn = (actionType === 'escalate') ? btnEscalateMed : ((actionType === 'resolve') ? btnResolveDiag : btnSaveAttOnly);
        const originalText = activeBtn ? activeBtn.innerHTML : '';
        if (activeBtn) {
            activeBtn.disabled = true;
            activeBtn.innerHTML = 'Saving...';
        }

        try {
            const res = await fetch('../../../backend/api/paanyaya/attendance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok || !data.success) {
                agapAlert(data.message || 'Nabigo ang pag-proseso ng aksyon.', 'error');
                if (activeBtn) {
                    activeBtn.disabled = false;
                    activeBtn.innerHTML = originalText;
                }
                return;
            }

            closeAttModal();

            if (actionType === 'escalate' && data.case_number) {
                const navMsg = `Matagumpay na nai-akyat ang Paanyaya sa Pormal na Mediation (Stage 1)!\nCase Number: ${data.case_number}\n\nNailipat ang Salaysay at tala ng Pagdalo ng mga panig.`;
                if (typeof window.showSuccessPopup === 'function') {
                    await window.showSuccessPopup(navMsg, 'Inilipat sa Mediation!');
                } else {
                    alert(navMsg);
                }
                if (data.complaint_id) {
                    window.location.href = `../complaints/complaint-details.php?id=${data.complaint_id}`;
                    return;
                }
            } else {
                if (typeof window.showSuccessPopup === 'function') {
                    await window.showSuccessPopup(data.message || 'Matagumpay na naitala ang aksyon.', 'Tagumpay!');
                } else {
                    alert(data.message || 'Matagumpay na naitala ang aksyon.');
                }
            }

            window.location.reload();
        } catch (err) {
            console.error(err);
            agapAlert('May naganap na error sa pag-proseso ng aksyon.', 'error');
            if (activeBtn) {
                activeBtn.disabled = false;
                activeBtn.innerHTML = originalText;
            }
        }
    }

    if (btnSaveAttOnly) {
        btnSaveAttOnly.addEventListener('click', () => submitAttendanceAction('save_attendance'));
    }
    if (btnResolveDiag) {
        btnResolveDiag.addEventListener('click', () => submitAttendanceAction('resolve', 'Sigurado ka bang naayos na ang sigalot at mamarkahang "Resolved" ang Paanyaya na ito?'));
    }
    if (btnEscalateMed) {
        btnEscalateMed.addEventListener('click', () => submitAttendanceAction('escalate', 'Sigurado ka bang nais nang i-akyat ang usaping ito sa Pormal na Mediation (Stage 1)?\n\nAwtomatikong ililipat ang Salaysay ng Nagpaanyaya at ang tala ng Pagdalo ng bawat panig.'));
    }

    function agapAlert(msg, type = 'info') {
        if (typeof window.showPopupModal === 'function') {
            window.showPopupModal(msg, type, type === 'error' ? 'Pansin' : 'Paalala');
        } else if (typeof window.agapNotify === 'function') {
            window.agapNotify(msg, type);
        } else {
            alert(msg);
        }
    }

    // Summary Cards toggle & click handling
    document.querySelectorAll('.paanyaya-stat-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (this.classList.contains('is-active') && this.dataset.status !== '') {
                e.preventDefault();
                const searchParam = new URLSearchParams(window.location.search).get('search');
                window.location.href = 'index.php' + (searchParam ? '?search=' + encodeURIComponent(searchParam) : '');
            }
        });
    });
});
</script>

<?php include '../../layouts/footer.php'; ?>
