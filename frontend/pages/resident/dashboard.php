<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'Resident') {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}
require_once '../../../backend/config/database.php';
require_once '../../../backend/services/CsrfService.php';
$db = (new Database())->connect();
$stmt = $db->prepare('SELECT c.intake_reference, c.complaint_title, c.intake_source, c.status, c.screening_decision, c.created_at FROM complaints c WHERE c.submitted_by = ? ORDER BY c.created_at DESC');
$stmt->execute([(int) $_SESSION['user_id']]);
$complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Resident Portal | AGAP</title><link rel="stylesheet" href="../../assets/css/style.css"></head><body><main class="container" style="max-width:960px;margin:40px auto"><h1>My complaint intakes</h1><p>Online complaints are reviewed by a Lupon Member performing preliminary screening before they receive a formal KP case number.</p><p><a class="btn-create" href="../complaints/complaint-create.php">Submit an online complaint</a> <form method="post" action="../../../backend/api/auth/logout.php" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CsrfService::token()) ?>"><button type="submit">Sign out</button></form></p><table class="modern-complaints-table"><thead><tr><th>Reference ID</th><th>Complaint</th><th>Source</th><th>Screening status</th><th>Submitted</th></tr></thead><tbody><?php foreach ($complaints as $complaint): ?><tr><td><?= htmlspecialchars($complaint['intake_reference'] ?: 'Pending') ?></td><td><?= htmlspecialchars($complaint['complaint_title']) ?></td><td><?= htmlspecialchars($complaint['intake_source']) ?></td><td><?= htmlspecialchars($complaint['screening_decision']) ?></td><td><?= htmlspecialchars($complaint['created_at']) ?></td></tr><?php endforeach; if (!$complaints): ?><tr><td colspan="5">No online complaints submitted yet.</td></tr><?php endif; ?></tbody></table></main></body></html>
