<?php

declare(strict_types=1);

require_once '../../../backend/services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

require_once '../../../backend/config/database.php';
require_once '../../../backend/middleware/rbac.php';
require_once '../../../backend/services/BpsoIntakeService.php';

$db = (new Database())->connect();
if (!isset($_SESSION['user_id']) || !agapHasRole(['BPSO', 'Administrator', 'Super Administrator'], $db)) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}

$dashboardData = (new BpsoIntakeService($db))->getDashboardData();
$kpis = $dashboardData['kpis'];
$pendingOnline = $dashboardData['pending_online'];
$recentProcessed = $dashboardData['recent_processed'];

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<style>
    .bpso-grid-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }
    .bpso-kpi-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px 18px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }
    .bpso-kpi-label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .bpso-kpi-value {
        font-size: 1.9rem;
        font-weight: 800;
        color: #0f172a;
        margin: 6px 0 4px;
        line-height: 1.1;
    }
    .bpso-kpi-sub {
        font-size: 0.8rem;
        color: #475569;
    }
    .bpso-panel {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
    }
    .bpso-panel-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 14px;
    }
    .bpso-panel-head h2 {
        margin: 0;
        font-size: 1.15rem;
        color: #0f172a;
    }
    .bpso-queue-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.84rem;
    }
    .bpso-queue-table th,
    .bpso-queue-table td {
        text-align: left;
        padding: 10px 8px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: top;
    }
    .bpso-queue-table th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #64748b;
        font-weight: 700;
        background: #f8fafc;
    }
    .bpso-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 11px;
        border-radius: 8px;
        background: #1b6553;
        color: #ffffff;
        font-size: 0.78rem;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }
    .bpso-pill {
        display: inline-flex;
        align-items: center;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 700;
        border: 1px solid transparent;
        white-space: nowrap;
    }
    .bpso-pill.is-pending { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .bpso-pill.is-clarify { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
    .bpso-pill.is-endorsed { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .bpso-pill.is-rejected { background: #ffe4e6; color: #9f1239; border-color: #fecdd3; }
    .bpso-pill.is-tumana { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    .bpso-pill.is-check { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
    .bpso-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        border-radius: 8px;
        background: #1b6553;
        color: #ffffff;
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }
    .bpso-action-btn:hover { background: #145041; }
    .bpso-action-btn.is-secondary {
        background: #f1f5f9;
        color: #0f172a;
        border: 1px solid #cbd5e1;
    }
</style>

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
            <div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <h1 style="margin:0; font-size:1.6rem; color:#0f172a;">BPSO Desk &amp; Blotter Intake</h1>
                    <span style="background:#eef6f2; color:#1b6553; border:1px solid #cde2d6; font-size:0.75rem; font-weight:700; padding:3px 10px; border-radius:9999px;">
                        Step 1 · Validation &amp; Endorsement to LISO
                    </span>
                </div>
                <p style="margin:4px 0 0; color:#475569; font-size:0.9rem;">
                    Validate online grievances (details, identity, and respondent Barangay Tumana residency), log walk-in blotters, and endorse valid cases to LISO.
                </p>
            </div>
            <div class="top-quick-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="../complaints/complaint-create.php" class="btn-top-primary" id="btnBpsoLogWalkIn">+ Log Walk-in Blotter</a>
                <a href="../users/user-list.php?tab=residents" class="btn-top-secondary">Verify Resident Accounts</a>
                <a href="../complaints/complaint-list.php" class="btn-top-secondary">All Complaints</a>
            </div>
        </div>

        <section class="bpso-grid-kpis" aria-label="BPSO Desk Summary">
            <article class="bpso-kpi-card">
                <div class="bpso-kpi-label">Pending Online Validation</div>
                <div class="bpso-kpi-value"><?= (int) $kpis['pending_online'] ?></div>
                <div class="bpso-kpi-sub">Online grievances awaiting blotter check</div>
            </article>
            <article class="bpso-kpi-card">
                <div class="bpso-kpi-label">Awaiting Clarification</div>
                <div class="bpso-kpi-value"><?= (int) $kpis['needs_clarification'] ?></div>
                <div class="bpso-kpi-sub">Returned to online complainant for info</div>
            </article>
            <article class="bpso-kpi-card">
                <div class="bpso-kpi-label">Endorsed to LISO Today</div>
                <div class="bpso-kpi-value"><?= (int) $kpis['endorsed_today'] ?></div>
                <div class="bpso-kpi-sub">Logged in blotter &amp; forwarded today</div>
            </article>
            <article class="bpso-kpi-card">
                <div class="bpso-kpi-label">Walk-in Blotters Today</div>
                <div class="bpso-kpi-value"><?= (int) $kpis['walkin_today'] ?></div>
                <div class="bpso-kpi-sub">Desk grievances encoded today</div>
            </article>
        </section>

        <section class="bpso-panel" aria-labelledby="bpsoOnlineQueueTitle">
            <div class="bpso-panel-head">
                <div>
                    <h2 id="bpsoOnlineQueueTitle">Online Submissions Pending BPSO Validation (<?= count($pendingOnline) ?>)</h2>
                    <p style="margin:2px 0 0; font-size:0.84rem; color:#64748b;">Verify details and confirm the respondent is a Barangay Tumana resident before endorsing to LISO.</p>
                </div>
            </div>

            <?php if (!$pendingOnline): ?>
                <div style="padding:28px; text-align:center; background:#f8fafc; border-radius:12px; color:#64748b;">
                    No online complaints are currently waiting for BPSO validation.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="bpso-queue-table">
                        <thead>
                            <tr>
                                <th>Complaint Reference Number &amp; Subject</th>
                                <th>Complainant</th>
                                <th>Respondent Information &amp; Tumana Check</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>BPSO Review &amp; Verification</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingOnline as $item): ?>
                                <?php
                                    $cid = (int) $item['complaint_id'];
                                    $ref = (string) ($item['intake_reference'] ?: ('CMP-' . $cid));
                                    $isClarify = ($item['bpso_status'] ?? '') === 'Needs Clarification';
                                    $replied = !empty($item['resident_replied_at']);
                                    $channelLabel = (($item['intake_source'] ?? 'Online') === 'Online') ? 'Online Grievance Submission' : 'Walk-in Blotter';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div style="color:#0f172a; font-weight:600; margin-top:2px;"><?= htmlspecialchars((string) ($item['complaint_title'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <small style="color:#64748b;"><?= htmlspecialchars($channelLabel, ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars((string) $item['complainant_names'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($item['complainant_profile_verified'])): ?>
                                            <span class="bpso-pill is-tumana" style="margin-top:4px;">Verified Resident</span>
                                        <?php else: ?>
                                            <span class="bpso-pill is-check" style="margin-top:4px;">Unverified / Pending Verification</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars((string) $item['respondent_names'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div style="font-size:0.8rem; color:#475569;"><?= htmlspecialchars((string) $item['respondent_addresses'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($item['respondent_tumana_hint'])): ?>
                                            <span class="bpso-pill is-tumana" style="margin-top:4px;">Tumana Address Detected</span>
                                        <?php else: ?>
                                            <span class="bpso-pill is-check" style="margin-top:4px;">Verify Tumana Residency</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="bpso-pill <?= $isClarify ? 'is-clarify' : 'is-pending' ?>">
                                            <?= htmlspecialchars((string) $item['bpso_status'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php if ($replied): ?>
                                            <div style="font-size:0.76rem; color:#15803d; font-weight:600; margin-top:4px;">Resident replied</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:#475569; font-size:0.8rem;">
                                        <?= htmlspecialchars(date('M j, Y', strtotime((string) $item['created_at'])), ENT_QUOTES, 'UTF-8') ?><br>
                                        <small><?= htmlspecialchars(date('g:i A', strtotime((string) $item['created_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td>
                                        <a class="bpso-action-btn" href="../complaints/complaint-details.php?id=<?= $cid ?>#bpso-validation">
                                            Validate &amp; Endorse &rarr;
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="bpso-panel" aria-labelledby="bpsoRecentTitle">
            <div class="bpso-panel-head">
                <h2 id="bpsoRecentTitle">Recent Blotter Logs, Endorsements &amp; Rejections</h2>
            </div>
            <?php if (!$recentProcessed): ?>
                <p style="color:#64748b; margin:0;">No processed BPSO entries yet.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="bpso-queue-table">
                        <thead>
                            <tr>
                                <th>Blotter / Intake Ref</th>
                                <th>Channel</th>
                                <th>Parties</th>
                                <th>BPSO Decision</th>
                                <th>KP / LISO Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentProcessed as $item): ?>
                                <?php
                                    $cid = (int) $item['complaint_id'];
                                    $isRejected = ($item['bpso_status'] ?? '') === 'Rejected';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string) ($item['bpso_blotter_no'] ?: ($item['intake_reference'] ?: ('CMP-' . $cid))), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div style="font-size:0.8rem; color:#64748b;"><?= htmlspecialchars((string) ($item['intake_reference'] ?: ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($item['intake_source'] ?? 'Walk-in'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <div><strong><?= htmlspecialchars((string) $item['complainant_names'], ENT_QUOTES, 'UTF-8') ?></strong> vs. <?= htmlspecialchars((string) $item['respondent_names'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <small style="color:#64748b;"><?= htmlspecialchars((string) ($item['complaint_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td>
                                        <span class="bpso-pill <?= $isRejected ? 'is-rejected' : 'is-endorsed' ?>">
                                            <?= htmlspecialchars((string) $item['bpso_status'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($item['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <a class="bpso-action-btn is-secondary" href="../complaints/complaint-details.php?id=<?= $cid ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php include '../../layouts/footer.php'; ?>
