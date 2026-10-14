<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/services/auth.php';
require_once __DIR__ . '/../../backend/services/complaints.php';

startSecureSession();
requireAdmin();

$action = (string) ($_GET['action'] ?? 'list');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

if ($action === 'create') {
    require __DIR__ . '/complaints-create.php';
    return;
}

$service = new ComplaintService($pdo);
$currentPage = 'complaints';

if (!function_exists('h')) {
    function h(?string $val): string {
        return htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if ($action === 'view') {
    $complaint = $id > 0 ? $service->find($id) : null;
    $complainant = null;
    $respondent = null;
    $witnesses = [];

    if ($complaint && !empty($complaint['parties'])) {
        foreach ($complaint['parties'] as $party) {
            if ($party['party_type'] === 'Complainant' && !$complainant) {
                $complainant = $party;
            } elseif ($party['party_type'] === 'Respondent' && !$respondent) {
                $respondent = $party;
            } else {
                $witnesses[] = $party;
            }
        }
    }
} else {
    $tabStatus = trim((string)($_GET['status'] ?? ''));
    $searchQuery = trim((string)($_GET['q'] ?? ''));
    $caseTypeFilter = trim((string)($_GET['case_type'] ?? ''));

    $complaints = $service->all($tabStatus ?: null, $searchQuery ?: null, $caseTypeFilter ?: null);

    // Tab counts
    $allComplaints = $service->all();
    $countAll = count($allComplaints);
    $countPending = count(array_filter($allComplaints, fn($c) => in_array($c['status'] ?? '', ['Filed', 'Pending Review', 'Under Review'], true) || ($c['process_stage'] ?? '') === 'Intake'));
    $countMediation = count(array_filter($allComplaints, fn($c) => ($c['process_stage'] ?? '') === 'Mediation' || ($c['status'] ?? '') === 'Mediation'));
    $countConciliation = count(array_filter($allComplaints, fn($c) => ($c['process_stage'] ?? '') === 'Conciliation' || ($c['status'] ?? '') === 'Conciliation'));
    $countCfa = count(array_filter($allComplaints, fn($c) => ($c['process_stage'] ?? '') === 'CFA' || ($c['status'] ?? '') === 'CFA Issued'));
    $countClosed = count(array_filter($allComplaints, fn($c) => in_array($c['status'] ?? '', ['Resolved', 'Settled', 'Dismissed', 'Archived', 'Closed'], true) || ($c['process_stage'] ?? '') === 'Closed'));

    $categoriesStmt = $pdo->query('SELECT category_id, category_name FROM complaint_categories ORDER BY category_id ASC');
    $categories = $categoriesStmt ? $categoriesStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $action === 'view' && !empty($complaint) ? h($complaint['case_no']) . ' | Complaint Details' : 'Complaints' ?> | AGAP-Remastered</title>
    <link rel="stylesheet" href="frontend/assets/css/app.css">
    <link rel="stylesheet" href="frontend/assets/css/dashboard.css">
    <link rel="stylesheet" href="frontend/assets/css/navigation-pane.css">
    <link rel="stylesheet" href="frontend/assets/css/complaints.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <style>
        .complaints-nav-tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .nav-tab-btn {
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            padding: 10px 16px;
            font-size: 0.925rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .nav-tab-btn:hover {
            color: #0f172a;
            border-bottom-color: #cbd5e1;
        }
        .nav-tab-btn.active {
            color: #0f172a;
            border-bottom-color: #0f172a;
        }
        .tab-count-pill {
            background: #e2e8f0;
            color: #334155;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .nav-tab-btn.active .tab-count-pill {
            background: #0f172a;
            color: #ffffff;
        }
        .complaints-toolbar-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
        }
        .complaints-toolbar-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .search-box-wrap {
            position: relative;
            flex: 1;
            min-width: 240px;
        }
        .search-icon-svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: #94a3b8;
        }
        .search-input-field {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .toolbar-select {
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.9rem;
            background: #fff;
        }
        .complaint-view-wrap {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            max-width: 1100px;
            margin: 0 auto;
        }
        .party-badge-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.725rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .party-badge-pill.complainant { background: #dbeafe; color: #1e40af; }
        .party-badge-pill.respondent { background: #fee2e2; color: #991b1b; }
        .party-badge-pill.witness { background: #fef3c7; color: #92400e; }
        .stage-pill {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            background: #e0f2fe;
            color: #0369a1;
        }
        .status-pill-badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            background: #fef3c7;
            color: #92400e;
        }
        .status-pill-badge.status-resolved { background: #dcfce7; color: #15803d; }
        .status-pill-badge.status-dismissed { background: #f1f5f9; color: #475569; }
        .status-pill-badge.status-active { background: #dbeafe; color: #1e40af; }
        .detail-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            padding: 0.5rem 0;
        }
        .detail-item dt {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.03em;
            margin-bottom: 0.25rem;
        }
        .detail-item dd {
            margin: 0;
            font-size: 0.95rem;
            color: #0f172a;
            font-weight: 500;
        }
        .detail-narrative {
            white-space: pre-wrap;
            line-height: 1.6;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 1rem;
            font-size: 0.95rem;
            color: #1e293b;
        }
        #complaintViewMap {
            height: 320px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            margin-top: 0.75rem;
        }
        .attachment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 6px;
        }
    </style>
</head>
<body class="dashboard-body">
<div class="dashboard-layout">
    <?php require __DIR__ . '/../components/sidebar.php'; ?>

    <main class="dashboard-workspace">
        <?php if ($action === 'view'): ?>
            <!-- VIEW COMPLAINT DETAILS -->
            <?php if (!$complaint): ?>
                <div class="page-header">
                    <div>
                        <a href="index.php?page=complaints" class="back-link">
                            <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            <span>Back to Complaints</span>
                        </a>
                        <h1>Complaint Not Found</h1>
                    </div>
                </div>
                <section class="dashboard-content">
                    <div class="empty-state">
                        <strong>The requested complaint could not be found.</strong>
                        <p>It may have been removed or the ID is invalid.</p>
                        <div style="margin-top: 1rem;">
                            <a class="btn-create" style="text-decoration:none;" href="index.php?page=complaints">Return to Complaints</a>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <div class="page-header">
                    <div>
                        <a href="index.php?page=complaints" class="back-link">
                            <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            <span>Back to Complaints</span>
                        </a>
                        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:6px;">
                            <h1 style="margin:0;"><?= h($complaint['case_no']) ?>: <?= h($complaint['complaint_title'] ?: 'Untitled Incident') ?></h1>
                            <span class="stage-pill"><?= h($complaint['process_stage']) ?></span>
                            <span class="status-pill-badge status-<?= strtolower(h($complaint['status'])) ?>"><?= h($complaint['status']) ?></span>
                            <span class="party-badge-pill" style="background:#e0e7ff; color:#3730a3;"><?= h($complaint['case_type'] ?: 'Civil') ?></span>
                        </div>
                        <p style="margin-top:4px;">Filed on <?= h(date('F j, Y \a\t g:i A', strtotime($complaint['created_at']))) ?> by <?= h($complaint['creator_name'] ?: 'Administrator') ?></p>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center;">
                        <a class="btn-create" style="text-decoration:none;" href="index.php?page=complaints-create">+ File New Complaint</a>
                    </div>
                </div>

                <section class="dashboard-content">
                    <div class="complaint-view-wrap">
                        <!-- Card 1: Incident & Classification -->
                        <section class="intake-card">
                            <div class="intake-card-header">
                                <h3>1. Incident &amp; Classification</h3>
                                <span class="card-subtitle">Basic details about the incident</span>
                            </div>
                            <div class="detail-row">
                                <div class="detail-item">
                                    <dt>Complaint Title</dt>
                                    <dd><?= h($complaint['complaint_title'] ?: 'Not recorded') ?></dd>
                                </div>
                                <div class="detail-item">
                                    <dt>Category</dt>
                                    <dd><?= h($complaint['category_name'] ?: ($complaint['complaint_type'] ?: 'General Dispute')) ?></dd>
                                </div>
                                <div class="detail-item">
                                    <dt>Case Type</dt>
                                    <dd><?= h($complaint['case_type'] ?: 'Civil') ?></dd>
                                </div>
                                <div class="detail-item">
                                    <dt>Incident Date &amp; Time</dt>
                                    <dd>
                                        <?php
                                        if (!empty($complaint['incident_date'])) {
                                            echo h(date('M d, Y', strtotime($complaint['incident_date'])));
                                            if (!empty($complaint['incident_time'])) {
                                                echo ' ' . h(date('g:i A', strtotime($complaint['incident_time'])));
                                            }
                                        } else {
                                            echo h(date('M d, Y g:i A', strtotime($complaint['incident_at'])));
                                        }
                                        ?>
                                    </dd>
                                </div>
                            </div>
                        </section>

                        <!-- Card 2: Involved Parties -->
                        <section class="intake-card">
                            <div class="intake-card-header">
                                <h3>2. Involved Parties</h3>
                                <span class="card-subtitle">Complainant, Respondent, and registered witnesses</span>
                            </div>

                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px;">
                                <!-- Complainant -->
                                <div class="party-intake-group" style="margin:0;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <span class="party-badge-pill complainant">Complainant Details</span>
                                        <span class="party-search-badge-verified">Verified</span>
                                    </div>
                                    <strong style="font-size:1.05rem; color:#0f172a;"><?= h($complainant['full_name'] ?? 'Not recorded') ?></strong>
                                    <div style="font-size:0.85rem; color:#475569; margin-top:6px; display:flex; flex-direction:column; gap:4px;">
                                        <span>📍 Purok / Sitio: <?= h($complainant['purok_sitio'] ?? 'N/A') ?></span>
                                        <span>🏠 Address: <?= h($complainant['address_line'] ?? 'N/A') ?></span>
                                        <span>📞 Contact: <?= h($complainant['contact_no'] ?? 'N/A') ?></span>
                                        <?php if (!empty($complainant['residency_status'])): ?>
                                            <span>🏷 Residency: <?= h($complainant['residency_status']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Respondent -->
                                <div class="party-intake-group" style="margin:0;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <span class="party-badge-pill respondent">Respondent Details</span>
                                        <span class="party-search-badge-verified">Verified Resident</span>
                                    </div>
                                    <strong style="font-size:1.05rem; color:#0f172a;"><?= h($respondent['full_name'] ?? 'Not recorded') ?></strong>
                                    <div style="font-size:0.85rem; color:#475569; margin-top:6px; display:flex; flex-direction:column; gap:4px;">
                                        <span>📍 Purok / Sitio: <?= h($respondent['purok_sitio'] ?? 'N/A') ?></span>
                                        <span>🏠 Address: <?= h($respondent['address_line'] ?? 'N/A') ?></span>
                                        <span>📞 Contact: <?= h($respondent['contact_no'] ?? 'N/A') ?></span>
                                        <?php if (!empty($respondent['residency_status'])): ?>
                                            <span>🏷 Residency: <?= h($respondent['residency_status']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Witnesses / Extra Parties -->
                            <?php if (!empty($witnesses)): ?>
                                <div style="margin-top:16px;">
                                    <strong style="font-size:0.85rem; text-transform:uppercase; color:#64748b; letter-spacing:0.04em;">Witness(es) &amp; Additional Parties</strong>
                                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:10px; margin-top:8px;">
                                        <?php foreach ($witnesses as $w): ?>
                                            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px;">
                                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                                    <strong style="color:#0f172a;"><?= h($w['full_name']) ?></strong>
                                                    <span class="party-badge-pill witness"><?= h($w['party_type']) ?></span>
                                                </div>
                                                <div style="font-size:0.8rem; color:#64748b; margin-top:4px;">
                                                    <span>📍 <?= h($w['purok_sitio'] ?: 'Barangay Tumana') ?></span>
                                                    <?= $w['contact_no'] ? ' · 📞 ' . h($w['contact_no']) : '' ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>

                        <!-- Card 3: Narrative & Facts -->
                        <section class="intake-card">
                            <div class="intake-card-header">
                                <h3>3. Narrative &amp; Facts</h3>
                                <span class="card-subtitle">Statement and description of the complaint</span>
                            </div>
                            <div class="detail-item">
                                <dt>Statement / Narrative</dt>
                                <div class="detail-narrative"><?= nl2br(h($complaint['narrative'])) ?></div>
                            </div>
                            <?php if (!empty($complaint['additional_details'])): ?>
                                <div class="detail-item" style="margin-top:14px;">
                                    <dt>Additional Details</dt>
                                    <div class="detail-narrative" style="background:#fff;"><?= nl2br(h($complaint['additional_details'])) ?></div>
                                </div>
                            <?php endif; ?>
                        </section>

                        <!-- Card 4: Incident Location & Map Pin -->
                        <section class="intake-card">
                            <div class="intake-card-header">
                                <h3>4. Incident Location</h3>
                                <span class="card-subtitle">Location pinning within Barangay Tumana</span>
                            </div>
                            <div class="detail-row">
                                <div class="detail-item">
                                    <dt>City / Municipality</dt>
                                    <dd><?= h($complaint['incident_city'] ?: 'Marikina City') ?></dd>
                                </div>
                                <div class="detail-item">
                                    <dt>Barangay</dt>
                                    <dd><?= h($complaint['incident_barangay'] ?: 'Tumana') ?></dd>
                                </div>
                                <div class="detail-item">
                                    <dt>Street / Specific Location</dt>
                                    <dd><?= h($complaint['incident_street'] ?: ($complaint['incident_location'] ?: 'Not recorded')) ?></dd>
                                </div>
                                <div class="detail-item">
                                    <dt>Purok / Zone</dt>
                                    <dd><?= h($complaint['incident_purok'] ?: 'N/A') ?></dd>
                                </div>
                                <?php if (!empty($complaint['incident_landmark'])): ?>
                                    <div class="detail-item">
                                        <dt>Nearby Landmark</dt>
                                        <dd><?= h($complaint['incident_landmark']) ?></dd>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div id="complaintViewMap"></div>
                        </section>

                        <!-- Card 5: Evidence / Attachments & Proof Notes -->
                        <?php if (!empty($complaint['attachments']) || !empty($complaint['proof_ocr_notes'])): ?>
                            <section class="intake-card">
                                <div class="intake-card-header">
                                    <h3>5. Evidence / Attachments &amp; Proof Notes</h3>
                                    <span class="card-subtitle">Supporting documents, pictures, and scanned notes</span>
                                </div>

                                <?php if (!empty($complaint['attachments'])): ?>
                                    <div style="margin-bottom:16px;">
                                        <dt style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:8px;">Attached Files</dt>
                                        <?php foreach ($complaint['attachments'] as $att): ?>
                                            <div class="attachment-item">
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <span class="party-badge-pill" style="background:#e2e8f0; color:#334155;"><?= h(strtoupper(pathinfo($att['file_name'], PATHINFO_EXTENSION))) ?></span>
                                                    <strong style="font-size:0.9rem; color:#0f172a;"><?= h($att['file_name']) ?></strong>
                                                    <span style="font-size:0.75rem; color:#64748b;">(<?= round($att['file_size'] / 1024, 1) ?> KB)</span>
                                                </div>
                                                <a href="<?= h($att['file_path']) ?>" download class="btn-outline-sm" style="text-decoration:none;">Download</a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($complaint['proof_ocr_notes'])): ?>
                                    <div class="detail-item">
                                        <dt>Proof Notes (OCR / Extracted Context)</dt>
                                        <div class="detail-narrative"><?= nl2br(h($complaint['proof_ocr_notes'])) ?></div>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <!-- Card 6: Audit History -->
                        <?php if (!empty($complaint['history'])): ?>
                            <section class="intake-card">
                                <div class="intake-card-header">
                                    <h3>Audit Trail &amp; Processing History</h3>
                                    <span class="card-subtitle">Complete chronological event timeline</span>
                                </div>
                                <div class="table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Action</th>
                                                <th>Details</th>
                                                <th>Acted By</th>
                                                <th>Date &amp; Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($complaint['history'] as $log): ?>
                                                <tr>
                                                    <td><strong><?= h($log['action']) ?></strong></td>
                                                    <td>
                                                        <?php
                                                        $details = json_decode($log['details'] ?? '', true);
                                                        if (is_array($details)) {
                                                            echo h(implode(' | ', array_map(fn($k, $v) => "$k: $v", array_keys($details), $details)));
                                                        } else {
                                                            echo h($log['details'] ?? '—');
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?= h($log['actor_name'] ?: 'Administrator') ?></td>
                                                    <td><?= h(date('M d, Y g:i A', strtotime($log['acted_at']))) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        <?php endif; ?>
                    </div>
                </section>

                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const lat = <?= (float) ($complaint['incident_latitude'] ?? 14.6568) ?>;
                        const lng = <?= (float) ($complaint['incident_longitude'] ?? 121.0940) ?>;
                        if (typeof L !== 'undefined') {
                            const map = L.map('complaintViewMap').setView([lat, lng], 15);
                            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                maxZoom: 19,
                                attribution: '&copy; OpenStreetMap contributors'
                            }).addTo(map);

                            const tumanaShape = [[14.6713, 121.0780], [14.6680, 121.0868], [14.6637, 121.0917], [14.6568, 121.0940], [14.6490, 121.0917], [14.6440, 121.0849], [14.6462, 121.0767], [14.6530, 121.0731], [14.6610, 121.0732]];
                            L.polygon(tumanaShape, { color: '#2563eb', weight: 2, fillColor: '#60a5fa', fillOpacity: 0.12 }).addTo(map);

                            <?php if (!empty($complaint['incident_latitude']) && !empty($complaint['incident_longitude'])): ?>
                                L.marker([lat, lng])
                                    .addTo(map)
                                    .bindPopup('<strong><?= h(addslashes($complaint["complaint_title"])) ?></strong><br><?= h(addslashes($complaint["incident_street"] ?: $complaint["incident_location"])) ?>')
                                    .openPopup();
                            <?php endif; ?>
                        }
                    });
                </script>
            <?php endif; ?>

        <?php else: ?>
            <!-- COMPLAINT LIST VIEW -->
            <div class="page-header">
                <div>
                    <h1>Complaints</h1>
                    <p>Record, monitor, and manage community concerns and dispute proceedings.</p>
                </div>
                <a href="index.php?page=complaints-create" class="btn-create" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Add Complaint
                </a>
            </div>

            <!-- Standard Complaint Lifecycle Tabs -->
            <div class="complaints-nav-tabs" role="tablist">
                <a class="nav-tab-btn <?= empty($tabStatus) ? 'active' : '' ?>" href="index.php?page=complaints">
                    All <span class="tab-count-pill"><?= $countAll ?></span>
                </a>
                <a class="nav-tab-btn <?= $tabStatus === 'Pending' ? 'active' : '' ?>" href="index.php?page=complaints&status=Pending">
                    Pending <span class="tab-count-pill"><?= $countPending ?></span>
                </a>
                <a class="nav-tab-btn <?= $tabStatus === 'Mediation' ? 'active' : '' ?>" href="index.php?page=complaints&status=Mediation">
                    Mediation <span class="tab-count-pill"><?= $countMediation ?></span>
                </a>
                <a class="nav-tab-btn <?= $tabStatus === 'Conciliation' ? 'active' : '' ?>" href="index.php?page=complaints&status=Conciliation">
                    Conciliation <span class="tab-count-pill"><?= $countConciliation ?></span>
                </a>
                <a class="nav-tab-btn <?= $tabStatus === 'CFA' ? 'active' : '' ?>" href="index.php?page=complaints&status=CFA">
                    CFA <span class="tab-count-pill"><?= $countCfa ?></span>
                </a>
                <a class="nav-tab-btn <?= $tabStatus === 'Resolution / Closed' ? 'active' : '' ?>" href="index.php?page=complaints&status=<?= urlencode('Resolution / Closed') ?>">
                    Resolution / Closed <span class="tab-count-pill"><?= $countClosed ?></span>
                </a>
            </div>

            <!-- Search & Filter Toolbar -->
            <section class="complaints-toolbar-card">
                <form method="get" action="index.php" class="complaints-toolbar-form">
                    <input type="hidden" name="page" value="complaints">
                    <?php if (!empty($tabStatus)): ?>
                        <input type="hidden" name="status" value="<?= h($tabStatus) ?>">
                    <?php endif; ?>

                    <div class="search-box-wrap">
                        <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input name="q" type="search" class="search-input-field" placeholder="Search by complaint no., title, resident profile, or case no..." value="<?= h($searchQuery) ?>" autocomplete="off">
                    </div>

                    <select name="case_type" class="toolbar-select">
                        <option value="">All Case Types</option>
                        <option value="Civil" <?= ($caseTypeFilter === 'Civil') ? 'selected' : '' ?>>Civil</option>
                        <option value="Criminal" <?= ($caseTypeFilter === 'Criminal') ? 'selected' : '' ?>>Criminal</option>
                    </select>

                    <button type="submit" class="btn-create" style="padding:8px 16px;">Filter</button>
                    <?php if (!empty($searchQuery) || !empty($caseTypeFilter) || !empty($tabStatus)): ?>
                        <a href="index.php?page=complaints" class="btn-secondary" style="padding:8px 14px;">Reset</a>
                    <?php endif; ?>
                </form>
            </section>

            <!-- Complaints Table -->
            <section class="dashboard-panel">
                <?php if (empty($complaints)): ?>
                    <div class="empty-state">
                        <strong>No complaints found</strong>
                        <p>There are no complaints matching the selected filter or no complaints recorded yet.</p>
                        <div style="margin-top: 1rem;">
                            <a class="btn-create" style="text-decoration:none;" href="index.php?page=complaints-create">+ Add Complaint</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Case No.</th>
                                    <th>Title &amp; Category</th>
                                    <th>Type</th>
                                    <th>Complainant</th>
                                    <th>Respondent</th>
                                    <th>Incident Date</th>
                                    <th>Stage</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($complaints as $row): ?>
                                    <tr>
                                        <td><strong><?= h($row['case_no']) ?></strong></td>
                                        <td>
                                            <strong style="color:#0f172a;"><?= h($row['complaint_title'] ?: 'Untitled Incident') ?></strong>
                                            <div style="font-size:0.78rem; color:#64748b; margin-top:2px;"><?= h($row['category_name'] ?: ($row['complaint_type'] ?: 'General')) ?></div>
                                        </td>
                                        <td><span class="party-badge-pill" style="background:#e0e7ff; color:#3730a3;"><?= h($row['case_type'] ?: 'Civil') ?></span></td>
                                        <td><?= h($row['complainant_name'] ?: 'Not recorded') ?></td>
                                        <td><?= h($row['respondent_name'] ?: 'Not recorded') ?></td>
                                        <td>
                                            <?= h(date('M d, Y', strtotime($row['incident_date'] ?: $row['incident_at']))) ?>
                                            <div style="font-size:0.78rem; color:#64748b;"><?= h($row['incident_purok'] ?: 'Tumana') ?></div>
                                        </td>
                                        <td><span class="stage-pill"><?= h($row['process_stage']) ?></span></td>
                                        <td><span class="status-pill-badge status-<?= strtolower(h($row['status'])) ?>"><?= h($row['status']) ?></span></td>
                                        <td>
                                            <a class="btn-secondary" style="padding:4px 10px; font-size:0.8rem; text-decoration:none;" href="index.php?page=complaints&action=view&id=<?= (int)$row['complaint_id'] ?>">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
