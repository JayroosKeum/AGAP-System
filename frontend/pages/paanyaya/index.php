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

$paanyayaModel = new Paanyaya();
$paanyayas = [];
$stats = [
    'all' => 0,
    'scheduled' => 0,
    'settled' => 0,
    'escalated' => 0,
    'cancelled' => 0
];
$openComplaints = [];

try {
    $db = (new Database())->connect();
    $paanyayas = $paanyayaModel->getAllPaanyayas();

    foreach ($paanyayas as $row) {
        $stats['all']++;
        $st = strtolower($row['status'] ?? '');
        if ($st === 'scheduled' || $st === 'pending' || $st === 'waiting') {
            $stats['scheduled']++;
        } elseif ($st === 'settled' || $st === 'resolved') {
            $stats['settled']++;
        } elseif ($st === 'escalated' || $st === 'docketed') {
            $stats['escalated']++;
        } elseif ($st === 'cancelled') {
            $stats['cancelled']++;
        }
    }

    $cStmt = $db->query("
        SELECT c.complaint_id, c.complaint_number, c.complaint_title, c.status,
               (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                FROM complaint_parties cp
                JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = c.complaint_id AND cp.party_type = 'Respondent') AS respondent_name,
               (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                FROM complaint_parties cp
                JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = c.complaint_id AND cp.party_type = 'Complainant') AS complainant_name
        FROM complaints c
        WHERE c.status NOT IN ('Dismissed', 'Settled', 'Closed', 'Resolved at Paanyaya / Closed')
        ORDER BY c.complaint_id DESC
        LIMIT 60
    ");
    $openComplaints = $cStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Paanyaya page error: ' . $e->getMessage());
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<link rel="stylesheet" href="../../assets/css/search.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/search.css'); ?>">
<link rel="stylesheet" href="../../assets/css/paanyaya.css?v=<?php echo time(); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <!-- Page Header -->
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                    <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 9999px; border: 1px solid #bbf7d0;">
                        Stage 0 • Pre-Docketing
                    </span>
                    <span style="font-size: 0.75rem; color: #64748b; font-weight: 500;">Barangay Tumana</span>
                </div>
                <h1 style="margin: 0; font-size: 1.65rem; color: #0f172a; font-weight: 700;">Paanyaya (Pre-Docketing Invitations)</h1>
                <p style="margin: 4px 0 0; color: #64748b; font-size: 0.9rem;">
                    Preliminary dialogue invitations issued by the Punong Barangay / Secretary for amicable dispute settlements prior to formal case docketing.
                </p>
            </div>

            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <button type="button" id="printScheduleBtn" class="btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; font-weight: 600; font-size: 0.85rem; border-radius: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Print Schedule
                </button>
                <a href="../complaints/complaint-create.php" class="btn-secondary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; font-weight: 600; font-size: 0.85rem; border-radius: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    New Complaint
                </a>
                <button type="button" id="openScheduleModalBtn" class="btn-create" style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; font-weight: 600; font-size: 0.88rem; background: #16a34a; border-color: #15803d; color: #ffffff; border-radius: 8px; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    + Schedule Paanyaya
                </button>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="paanyaya-kpi-grid">
            <div class="paanyaya-kpi-card" data-filter="">
                <div class="paanyaya-kpi-icon" style="background: #eff6ff; color: #1d4ed8;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                </div>
                <div class="paanyaya-kpi-content">
                    <span class="paanyaya-kpi-num" id="kpiTotal"><?php echo (int) $stats['all']; ?></span>
                    <span class="paanyaya-kpi-label">Total Paanyayas Initiated</span>
                </div>
            </div>

            <div class="paanyaya-kpi-card" data-filter="Scheduled">
                <div class="paanyaya-kpi-icon" style="background: #fefce8; color: #a16207;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div class="paanyaya-kpi-content">
                    <span class="paanyaya-kpi-num" id="kpiScheduled"><?php echo (int) $stats['scheduled']; ?></span>
                    <span class="paanyaya-kpi-label">Upcoming / In Progress</span>
                </div>
            </div>

            <div class="paanyaya-kpi-card" data-filter="Settled">
                <div class="paanyaya-kpi-icon" style="background: #f0fdf4; color: #15803d;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                </div>
                <div class="paanyaya-kpi-content">
                    <span class="paanyaya-kpi-num" id="kpiSettled"><?php echo (int) $stats['settled']; ?></span>
                    <span class="paanyaya-kpi-label">Naayos sa Paanyaya (Settled)</span>
                </div>
            </div>

            <div class="paanyaya-kpi-card" data-filter="Escalated">
                <div class="paanyaya-kpi-icon" style="background: #fff7ed; color: #c2410c;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline></svg>
                </div>
                <div class="paanyaya-kpi-content">
                    <span class="paanyaya-kpi-num" id="kpiEscalated"><?php echo (int) $stats['escalated']; ?></span>
                    <span class="paanyaya-kpi-label">Escalated to Mediation</span>
                </div>
            </div>
        </div>

        <!-- Filter Status Tabs -->
        <div class="complaints-nav-tabs" role="tablist" aria-label="Paanyaya invitation filter">
            <button type="button" class="nav-tab-btn active" data-tab-status="">
                All Invitations <span class="tab-count-pill" id="tabCountAll"><?php echo (int) $stats['all']; ?></span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Scheduled">
                Scheduled / In Progress <span class="tab-count-pill" id="tabCountScheduled"><?php echo (int) $stats['scheduled']; ?></span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Settled">
                Amicably Settled <span class="tab-count-pill" id="tabCountSettled"><?php echo (int) $stats['settled']; ?></span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Escalated">
                Escalated to Mediation <span class="tab-count-pill" id="tabCountEscalated"><?php echo (int) $stats['escalated']; ?></span>
            </button>
            <button type="button" class="nav-tab-btn" data-tab-status="Cancelled">
                Cancelled <span class="tab-count-pill" id="tabCountCancelled"><?php echo (int) $stats['cancelled']; ?></span>
            </button>
        </div>

        <!-- Search & Filter Bar -->
        <section class="complaints-toolbar-card" style="margin-bottom: 20px;">
            <div class="complaints-toolbar-form">
                <div class="toolbar-main-row" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div class="search-box-wrap" style="flex: 1; min-width: 260px;">
                        <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input id="paanyayaSearchInput" type="search" class="search-input-field" placeholder="Search by invitee name, complaint #, title, or complainant..." autocomplete="off">
                        <button type="button" id="clearSearchBtn" class="search-clear-btn" title="Clear search">&times;</button>
                    </div>

                    <select id="dateFilterSelect" class="toolbar-select" style="min-width: 170px;">
                        <option value="">All Schedule Dates</option>
                        <option value="today">Scheduled Today</option>
                        <option value="tomorrow">Scheduled Tomorrow</option>
                        <option value="this_week">This Week</option>
                        <option value="next_7_days">Next 7 Days</option>
                        <option value="this_month">This Month</option>
                        <option value="past">Past / Concluded</option>
                    </select>

                    <button type="button" id="resetFiltersBtn" class="btn-reset-filters" style="display: none; padding: 8px 12px; font-size: 0.85rem; cursor: pointer;">
                        Reset
                    </button>
                </div>
            </div>
        </section>

        <!-- Records Table Card -->
        <div class="paanyaya-table-card">
            <div class="paanyaya-table-header" style="display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #e2e8f0; background: #fafafa;">
                <div style="font-weight: 700; color: #1e293b; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                    <span>📋 Paanyaya Dialogue Registry</span>
                    <span id="resultsCountBadge" style="font-size: 0.78rem; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: #f1f5f9; color: #475569;">
                        <?php echo count($paanyayas); ?> records
                    </span>
                </div>
                <div style="font-size: 0.8rem; color: #64748b;">
                    Preliminary Conciliation under Tumana Office Protocol
                </div>
            </div>

            <div class="paanyaya-table-wrap">
                <table class="paanyaya-table" id="paanyayaTable">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Complaint / Ref #</th>
                            <th style="min-width: 180px;">Invitee (Inanyayahan)</th>
                            <th style="min-width: 180px;">Nagpaanyaya (Complainant)</th>
                            <th style="min-width: 220px;">Pamagat ng Usapin</th>
                            <th style="min-width: 170px;">Petsa at Oras</th>
                            <th style="width: 100px;">Yugto</th>
                            <th style="width: 120px;">Katayuan</th>
                            <th style="width: 150px; text-align: right;">Aksyon</th>
                        </tr>
                    </thead>
                    <tbody id="paanyayaTableBody">
                        <?php if (empty($paanyayas)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 36px 16px; color: #64748b;">
                                    <div style="font-size: 2rem; margin-bottom: 8px;">✉️</div>
                                    <div style="font-weight: 600; color: #1e293b; margin-bottom: 4px;">No Paanyaya invitations recorded yet.</div>
                                    <div style="font-size: 0.85rem;">Click "+ Schedule Paanyaya" above to issue a formal dialogue invitation for an open complaint.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($paanyayas as $p): ?>
                                <?php
                                $statusLower = strtolower($p['status'] ?? 'scheduled');
                                $pillClass = 'badge-sched';
                                $pillLabel = htmlspecialchars($p['status'] ?? 'Scheduled');
                                if ($statusLower === 'settled' || $statusLower === 'resolved') {
                                    $pillClass = 'badge-settled';
                                    $pillLabel = 'Settled';
                                } elseif ($statusLower === 'escalated' || $statusLower === 'docketed') {
                                    $pillClass = 'badge-escalated';
                                    $pillLabel = 'Escalated to Mediation';
                                } elseif ($statusLower === 'cancelled') {
                                    $pillClass = 'badge-cancelled';
                                    $pillLabel = 'Cancelled';
                                }
                                $schedDate = !empty($p['scheduled_date']) ? date('M j, Y', strtotime($p['scheduled_date'])) : 'Not set';
                                $schedTime = !empty($p['scheduled_time']) ? date('g:i A', strtotime($p['scheduled_time'])) : '';
                                $attemptNum = (int) ($p['attempt_number'] ?? 1);
                                ?>
                                <tr data-id="<?php echo (int) $p['paanyaya_id']; ?>" data-status="<?php echo htmlspecialchars($p['status'] ?? ''); ?>" data-date="<?php echo htmlspecialchars($p['scheduled_date'] ?? ''); ?>">
                                    <td>
                                        <div style="font-weight: 700; color: #1e40af; font-size: 0.88rem;">
                                            <?php echo htmlspecialchars($p['complaint_number'] ?: ('CMP-' . $p['complaint_id'])); ?>
                                        </div>
                                        <div style="font-size: 0.72rem; color: #64748b;">
                                            ID #<?php echo (int) $p['complaint_id']; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #0f172a; font-size: 0.88rem;">
                                            <?php echo htmlspecialchars($p['invitee_name'] ?: ($p['respondent_name'] ?: 'Inanyayahang Residente')); ?>
                                        </div>
                                        <span style="font-size: 0.72rem; color: #475569; background: #f1f5f9; padding: 1px 6px; border-radius: 4px;">
                                            Respondent
                                        </span>
                                    </td>
                                    <td>
                                        <div style="color: #334155; font-size: 0.88rem; font-weight: 500;">
                                            <?php echo htmlspecialchars($p['complainant_name'] ?: 'Nagrereklamo'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #1e293b; font-size: 0.88rem; line-height: 1.3;">
                                            <?php echo htmlspecialchars($p['complaint_title'] ?: 'Barangay Dispute Dialogue'); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                            📍 <?php echo htmlspecialchars($p['venue'] ?: 'Tanggapan ng Barangay Tumana'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #0f172a; font-size: 0.85rem;">
                                            <?php echo $schedDate; ?>
                                        </div>
                                        <div style="font-size: 0.8rem; color: #15803d; font-weight: 600;">
                                            <?php echo $schedTime; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.76rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;">
                                            Attempt #<?php echo $attemptNum; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="paanyaya-badge <?php echo $pillClass; ?>">
                                            <?php echo $pillLabel; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div class="paanyaya-action-group" style="display: inline-flex; gap: 4px; align-items: center; justify-content: flex-end;">
                                            <a href="../complaints/paanyaya-workspace.php?id=<?php echo (int) $p['complaint_id']; ?>" class="btn-action-icon" title="Open Paanyaya Workspace" style="padding: 6px 9px; font-size: 0.78rem; border-radius: 6px; text-decoration: none; background: #f0fdf4; border: 1px solid #86efac; color: #15803d; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                                Workspace
                                            </a>
                                            <?php if (!empty($p['pdf_path'])): ?>
                                                <button type="button" class="btn-action-icon preview-pdf-btn" data-pdf="<?php echo htmlspecialchars($p['pdf_path']); ?>" data-title="Paanyaya #<?php echo (int) $p['paanyaya_id']; ?> (<?php echo htmlspecialchars($p['invitee_name']); ?>)" title="View Invitation PDF" style="padding: 6px; border-radius: 6px; border: 1px solid #e2e8f0; background: #ffffff; color: #475569; cursor: pointer;">
                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                                                </button>
                                            <?php endif; ?>
                                            <a href="../complaints/record-dialogue-minutes.php?complaint_id=<?php echo (int) $p['complaint_id']; ?>&stage=<?php echo $attemptNum; ?>" class="btn-action-icon" title="Record Dialogue Minutes" style="padding: 6px; border-radius: 6px; border: 1px solid #e2e8f0; background: #ffffff; color: #0284c7; text-decoration: none;">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                                            </a>
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

<!-- ======================================================== -->
<!-- MODAL: SCHEDULE NEW PAANYAYA DIALOGUE                     -->
<!-- ======================================================== -->
<div id="schedulePaanyayaModal" class="modal-backdrop" style="display: none;">
    <div class="modal-box" style="max-width: 580px; border-radius: 12px; overflow: hidden; background: #ffffff; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
        <div class="modal-header" style="padding: 16px 20px; background: #f0fdf4; border-bottom: 1.5px solid #86efac; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: #15803d; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                    ✉️
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.1rem; color: #166534; font-weight: 700;">Schedule Paanyaya Invitation</h3>
                    <p style="margin: 2px 0 0; font-size: 0.78rem; color: #15803d;">Issue an official informal dialogue invitation letter to respondent</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" id="closeScheduleModalBtn" style="border: none; background: transparent; font-size: 1.5rem; color: #166534; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <form id="paanyayaScheduleForm" style="padding: 20px;">
            <div id="modalAlertBox" style="display: none; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem;"></div>

            <!-- Komplainante -->
            <div style="margin-bottom: 16px; position: relative;">
                <label for="complainantInput" style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                    Komplainante <span style="color: #dc2626;">*</span>
                </label>
                <input type="text" id="complainantInput" name="complainant_name" placeholder="Pangalan ng Komplainante" required autocomplete="off"
                    style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 0.88rem; box-sizing: border-box;">
                <ul id="complainantSuggestions" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #cbd5e1; border-radius:0 0 8px 8px; list-style:none; margin:0; padding:0; z-index:999; max-height:180px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.08);"></ul>
            </div>

            <!-- Responde -->
            <div style="margin-bottom: 16px; position: relative;">
                <label for="respondentInput" style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                    Responde <span style="color: #dc2626;">*</span>
                </label>
                <input type="text" id="respondentInput" name="respondent_name" placeholder="Pangalan ng Responde" required autocomplete="off"
                    style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 0.88rem; box-sizing: border-box;">
                <ul id="respondentSuggestions" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #cbd5e1; border-radius:0 0 8px 8px; list-style:none; margin:0; padding:0; z-index:999; max-height:180px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.08);"></ul>
            </div>

            <!-- Petsa ng Paanyaya -->
            <div style="margin-bottom: 16px;">
                <label for="scheduledDateInput" style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                    Petsa ng Paanyaya <span style="color: #dc2626;">*</span>
                </label>
                <input type="date" id="scheduledDateInput" name="scheduled_date"
                    value="<?php echo date('Y-m-d', strtotime('+3 weekdays')); ?>" required
                    style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 0.88rem; box-sizing: border-box;">
                <div id="derivedDateInfo" style="margin-top: 6px; font-size: 0.8rem; color: #64748b; display: flex; gap: 14px; flex-wrap: wrap;"></div>
            </div>

            <!-- Oras ng Paanyaya (optional) -->
            <div style="margin-bottom: 16px;">
                <label for="scheduledTimeInput" style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                    Oras ng Paanyaya <span style="font-weight: 400; color: #94a3b8;">(opsyonal)</span>
                </label>
                <input type="time" id="scheduledTimeInput" name="scheduled_time"
                    style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 0.88rem; box-sizing: border-box;">
            </div>

            <!-- Maikling Salaysay -->
            <div style="margin-bottom: 20px;">
                <label for="shortNarrativeInput" style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px;">
                    Maikling Salaysay <span style="color: #dc2626;">*</span>
                </label>
                <textarea id="shortNarrativeInput" name="short_narrative" rows="3"
                    placeholder="Maikling paliwanag ukol sa usapin o layunin ng paanyaya..." required
                    style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem; box-sizing: border-box; resize: vertical;"></textarea>
            </div>

            <!-- Hidden derived fields -->
            <input type="hidden" name="invitation_month" id="invitationMonthInput">
            <input type="hidden" name="invitation_year" id="invitationYearInput">
            <input type="hidden" name="invitation_day_name" id="invitationDayNameInput">

            <!-- Modal Footer -->
            <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #f1f5f9; padding-top: 16px;">
                <button type="button" id="cancelScheduleModalBtn" class="btn-secondary" style="padding: 9px 16px; border-radius: 8px; font-size: 0.88rem;">
                    Kanselahin
                </button>
                <button type="submit" id="submitScheduleBtn" class="btn-create"
                    style="padding: 9px 20px; border-radius: 8px; font-size: 0.88rem; background: #16a34a; border-color: #15803d; color: #ffffff; font-weight: 600; cursor: pointer;">
                    I-iskedyul ang Paanyaya
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: PDF PREVIEW                                       -->
<!-- ======================================================== -->
<div id="paanyayaPdfModal" class="modal-backdrop" style="display: none;">
    <div class="modal-box" style="max-width: 850px; width: 92%; height: 88vh; border-radius: 12px; overflow: hidden; background: #ffffff; display: flex; flex-direction: column;">
        <div class="modal-header" style="padding: 12px 18px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <div style="font-weight: 700; color: #0f172a; font-size: 0.95rem;" id="pdfModalTitle">
                Paanyaya Invitation Document Preview
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <a id="pdfDownloadBtn" href="#" download class="btn-secondary" style="padding: 6px 12px; font-size: 0.8rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download
                </a>
                <button type="button" id="closePdfModalBtn" style="border: none; background: transparent; font-size: 1.5rem; color: #64748b; cursor: pointer; line-height: 1;">&times;</button>
            </div>
        </div>
        <div style="flex: 1; position: relative; background: #525659;">
            <iframe id="pdfViewerIframe" src="about:blank" style="width: 100%; height: 100%; border: none;"></iframe>
        </div>
    </div>
</div>

<script src="../../assets/js/paanyaya.js?v=<?php echo time(); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
