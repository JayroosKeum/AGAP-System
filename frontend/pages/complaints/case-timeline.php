<?php

session_start();

// Access control: Admin (1), Lupon Clerk (2), Lupon Member (3)
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int)$_SESSION['role_id'], [1, 2, 3], true)) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/models/PartyModel.php';
require_once __DIR__ . '/../../../backend/models/CaseModel.php';
require_once __DIR__ . '/../../../backend/controllers/ComplaintController.php';

$complaintId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$caseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : 0;
$residentId = isset($_GET['resident_id']) ? (int)$_GET['resident_id'] : 0;

$db = (new Database())->connect();
$partyModel = new PartyModel($db);
$caseModel = new CaseModel();

// Resolve IDs
if ($complaintId === 0 && $caseId > 0) {
    $cStmt = $db->prepare('SELECT complaint_id FROM cases WHERE case_id = ?');
    $cStmt->execute([$caseId]);
    $complaintId = (int)$cStmt->fetchColumn();
} elseif ($complaintId > 0 && $caseId === 0) {
    $cStmt = $db->prepare('SELECT case_id FROM cases WHERE complaint_id = ?');
    $cStmt->execute([$complaintId]);
    $caseId = (int)$cStmt->fetchColumn();
}

$complaintData = null;
if ($complaintId > 0) {
    $complaintData = (new ComplaintController())->show($complaintId);
}

// Get parties for this complaint
$parties = $complaintId > 0 ? $partyModel->getPartiesForComplaint($complaintId) : [];

// Check concurrent disputes for the complaint
$concurrentDisputes = $complaintId > 0 ? $partyModel->checkConcurrentDisputesForComplaint($complaintId) : ['has_concurrent' => false, 'alerts' => []];

// Collect all timeline events
$timelineEvents = [];
if ($caseId > 0) {
    $timelineEvents = $caseModel->getCrossCaseTimeline($caseId);
} elseif ($complaintId > 0 && !empty($parties)) {
    $seen = [];
    foreach ($parties as $p) {
        $events = $partyModel->getCrossCaseTimelineForResident((int)$p['resident_id']);
        foreach ($events as $ev) {
            $key = ($ev['type'] ?? '') . '_' . ($ev['complaint_id'] ?? '') . '_' . ($ev['date'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $timelineEvents[] = $ev;
            }
        }
    }
    usort($timelineEvents, function ($a, $b) {
        return strtotime($b['date']) <=> strtotime($a['date']);
    });
} elseif ($residentId > 0) {
    $timelineEvents = $partyModel->getCrossCaseTimelineForResident($residentId);
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">

<style>
.timeline-container {
    max-width: 1040px;
    margin: 0 auto;
    padding-bottom: 40px;
}

.timeline-action-bar {
    background: #ffffff;
    border: 1px solid #dfe8e3;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    box-shadow: 0 8px 24px rgba(32, 76, 62, .035);
}

.timeline-header-title {
    margin: 0;
    font-size: 1.45rem;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
}

.timeline-badge {
    background: #e0e7ff;
    color: #3730a3;
    font-size: 0.78rem;
    font-weight: 800;
    padding: 3px 10px;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.resident-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.resident-card {
    background: #ffffff;
    border: 1px solid #dfe8e3;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
}

.resident-role-tag {
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 2px 8px;
    border-radius: 6px;
    display: inline-block;
    margin-bottom: 6px;
}

.role-complainant { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.role-respondent { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.role-witness { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

.concurrent-alert-pill {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 0.82rem;
    color: #92400e;
    margin-top: 10px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    line-height: 1.4;
}

.cross-timeline-stream {
    position: relative;
    padding-left: 28px;
    margin-top: 24px;
}

.cross-timeline-stream::before {
    content: '';
    position: absolute;
    top: 6px;
    bottom: 6px;
    left: 8px;
    width: 2px;
    background: #e2e8f0;
}

.timeline-event-item {
    position: relative;
    margin-bottom: 24px;
}

.timeline-event-marker {
    position: absolute;
    left: -28px;
    top: 4px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #ffffff;
    border: 3px solid #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.event-intake .timeline-event-marker { border-color: #2563eb; }
.event-paanyaya .timeline-event-marker { border-color: #6366f1; }
.event-docketed .timeline-event-marker { border-color: #0d9488; }
.event-summon .timeline-event-marker { border-color: #d97706; }
.event-hearing .timeline-event-marker { border-color: #7c3aed; }
.event-settlement .timeline-event-marker { border-color: #16a34a; }

.timeline-event-card {
    background: #ffffff;
    border: 1px solid #dfe8e3;
    border-radius: 12px;
    padding: 14px 18px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.timeline-event-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.05);
    border-color: #cbd5e1;
}

.timeline-event-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 6px;
}

.timeline-event-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.timeline-event-meta {
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 600;
}

.timeline-case-badge {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
    font-size: 0.76rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 4px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.timeline-case-badge:hover {
    background: #e2e8f0;
    color: #0f172a;
}
</style>

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <div class="timeline-container">

            <!-- Action Bar -->
            <div class="timeline-action-bar">
                <div>
                    <h1 class="timeline-header-title">
                        <span>Cross-Case Dispute Timeline</span>
                        <span class="timeline-badge">Module 5</span>
                    </h1>
                    <p style="margin: 4px 0 0; font-size: 0.86rem; color: #64748b;">
                        Pinagsamang kasaysayan at relational timeline ng mga partidong sangkot sa iba't ibang reklamo
                    </p>
                </div>

                <div style="display: flex; gap: 8px; align-items: center;">
                    <?php if ($complaintId > 0): ?>
                        <a href="complaint-details.php?id=<?php echo $complaintId; ?>" class="btn-period-nav" style="text-decoration: none;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            <span>Back to Case Details</span>
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn-period-nav" onclick="window.print()" title="Print Cross-Case Timeline">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        <span>Print Timeline</span>
                    </button>
                </div>
            </div>

            <!-- Linked Resident Profiles Section -->
            <div style="margin-bottom: 22px;">
                <h2 style="font-size: 1.1rem; font-weight: 800; color: #0f172a; margin: 0 0 12px;">
                    Linked Resident Profiles (Single Profile Relational Mapping)
                </h2>

                <div class="resident-card-grid">
                    <?php if (!empty($parties)): ?>
                        <?php foreach ($parties as $party): ?>
                            <?php 
                                $rId = (int)$party['resident_id'];
                                $roleClass = 'role-' . strtolower($party['party_type']);
                                $concurrentList = $partyModel->getConcurrentDisputesForResident($rId, $complaintId);
                            ?>
                            <div class="resident-card">
                                <span class="resident-role-tag <?php echo $roleClass; ?>"><?php echo htmlspecialchars($party['party_type']); ?></span>
                                <h3 style="margin: 0 0 4px; font-size: 1.05rem; font-weight: 800; color: #0f172a;">
                                    <?php echo htmlspecialchars($party['full_name']); ?>
                                </h3>
                                <div style="font-size: 0.82rem; color: #64748b; line-height: 1.4;">
                                    <div><strong>Resident ID:</strong> #<?php echo $party['resident_id']; ?> (Single Relational Profile)</div>
                                    <div><strong>Address:</strong> <?php echo htmlspecialchars(trim(($party['house_street'] ?? '') . ' ' . ($party['address'] ?? '') . ' ' . ($party['purok'] ?? 'Barangay Tumana'))); ?></div>
                                    <?php if (!empty($party['contact_no'])): ?>
                                        <div><strong>Contact:</strong> <?php echo htmlspecialchars($party['contact_no']); ?></div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($concurrentList)): ?>
                                    <div class="concurrent-alert-pill">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                                        <div>
                                            <strong>Active in Other Cases:</strong>
                                            <?php foreach ($concurrentList as $c): ?>
                                                <div style="margin-top: 3px;">
                                                    &bull; <a href="complaint-details.php?id=<?php echo $c['complaint_id']; ?>" style="color: #92400e; font-weight: 700; text-decoration: underline;">
                                                        Case #<?php echo htmlspecialchars($c['case_number'] ?: $c['complaint_number']); ?>
                                                    </a>
                                                    as <strong><?php echo htmlspecialchars($c['resident_role']); ?></strong> (<?php echo htmlspecialchars($c['case_status'] ?: $c['complaint_status']); ?>)
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding: 18px; color: #64748b; background: #ffffff; border-radius: 12px; border: 1px solid #dfe8e3;">
                            Walang naitalang residente o partikular na kaso para sa timeline na ito.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chronological Cross-Case Timeline Stream -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <h2 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0;">
                        Chronological Dispute &amp; Milestone Stream
                    </h2>
                    <span style="font-size: 0.82rem; color: #64748b; font-weight: 600;">
                        <?php echo count($timelineEvents); ?> Naitalang Kaganapan
                    </span>
                </div>

                <?php if (!empty($timelineEvents)): ?>
                    <div class="cross-timeline-stream">
                        <?php foreach ($timelineEvents as $event): ?>
                            <?php
                                $type = $event['type'] ?? 'general';
                                $markerClass = 'event-' . (str_contains($type, 'paanyaya') ? 'paanyaya' : (str_contains($type, 'docket') ? 'docketed' : (str_contains($type, 'summon') ? 'summon' : (str_contains($type, 'hearing') ? 'hearing' : 'intake'))));
                                $eventDate = !empty($event['date']) ? date('M j, Y — g:i A', strtotime($event['date'])) : 'No date recorded';
                            ?>
                            <div class="timeline-event-item <?php echo $markerClass; ?>">
                                <div class="timeline-event-marker"></div>
                                <div class="timeline-event-card">
                                    <div class="timeline-event-header">
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <h4 class="timeline-event-title"><?php echo htmlspecialchars($event['title']); ?></h4>
                                            <?php if (!empty($event['case_number'])): ?>
                                                <a href="complaint-details.php?id=<?php echo (int)($event['complaint_id'] ?? 0); ?>" class="timeline-case-badge" title="View this case workspace">
                                                    <span>Case #<?php echo htmlspecialchars($event['case_number']); ?></span>
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <span class="timeline-event-meta"><?php echo htmlspecialchars($eventDate); ?></span>
                                    </div>
                                    <p style="margin: 0; font-size: 0.88rem; color: #334155; line-height: 1.45;">
                                        <?php echo htmlspecialchars($event['description']); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="background: #ffffff; border: 1px solid #dfe8e3; border-radius: 12px; padding: 32px; text-align: center; color: #64748b;">
                        Walang mga kaganapang naitala para sa mga kaugnay na kaso sa kasalukuyan.
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>

</div>

<?php include '../../layouts/footer.php'; ?>
