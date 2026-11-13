<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'Resident') {
    header('Location: login.php?error=login_required', true, 303);
    exit;
}

require_once '../../../backend/config/database.php';
require_once '../../../backend/services/CsrfService.php';

$userId = (int) $_SESSION['user_id'];
$db = (new Database())->connect();
$dashboardUnavailable = false;
$profile = [];
$complaints = [];

try {
    $profileStatement = $db->prepare(
        "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)), ''),
                         NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS display_name
         FROM users u
         LEFT JOIN resident_user_profiles rup ON rup.user_id = u.user_id
         LEFT JOIN residents r ON r.resident_id = rup.resident_id
         WHERE u.user_id = ? LIMIT 1"
    );
    $profileStatement->execute([$userId]);
    $profile = $profileStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $complaintsStatement = $db->prepare(
        'SELECT c.complaint_id, c.intake_reference, c.complaint_number, c.complaint_title, c.status,
                c.screening_decision, c.created_at, c.updated_at, cc.category_name,
                EXISTS (SELECT 1 FROM complaint_information_requests ir
                    WHERE ir.complaint_id = c.complaint_id AND ir.responded_at IS NULL) AS awaiting_reply
         FROM complaints c
         LEFT JOIN complaint_categories cc ON cc.category_id = c.category_id
         WHERE c.submitted_by = ? AND c.is_draft = 0
         ORDER BY c.updated_at DESC, c.created_at DESC'
    );
    $complaintsStatement->execute([$userId]);
    $complaints = $complaintsStatement->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Resident dashboard query failed: ' . $exception->getMessage());
    http_response_code(503);
    $dashboardUnavailable = true;
}

$summary = ['total' => count($complaints), 'pending' => 0, 'needs_information' => 0, 'in_progress' => 0];
foreach ($complaints as $complaint) {
    $decision = (string) ($complaint['screening_decision'] ?? 'Pending');
    if ($decision === 'Pending') {
        $summary['pending']++;
    } elseif ($decision === 'Needs Information') {
        $summary['needs_information']++;
    } elseif (in_array($decision, ['Eligible', 'Referred'], true)) {
        $summary['in_progress']++;
    }
}

$statusDetails = static function (array $complaint): array {
    $decision = (string) ($complaint['screening_decision'] ?? 'Pending');
    $status = trim((string) ($complaint['status'] ?? 'Filed'));
    return match ($decision) {
        'Needs Information' => ['label' => 'More information needed', 'message' => 'Barangay personnel need additional details before screening can continue.', 'tone' => 'attention'],
        'Eligible' => ['label' => $status !== '' ? $status : 'In progress', 'message' => 'Your concern passed preliminary screening and is being processed.', 'tone' => 'progress'],
        'Referred' => ['label' => 'Referred for assistance', 'message' => 'Your concern has been referred to the appropriate next step.', 'tone' => 'neutral'],
        'Rejected' => ['label' => 'Screening completed', 'message' => 'Please contact the barangay office if you need clarification.', 'tone' => 'neutral'],
        'Duplicate' => ['label' => 'Matched with an existing concern', 'message' => 'Barangay personnel are reviewing the matching record.', 'tone' => 'neutral'],
        default => ['label' => 'Awaiting preliminary screening', 'message' => 'A Lupon Member will review your submission before it moves forward.', 'tone' => 'pending'],
    };
};

$displayName = trim((string) ($profile['display_name'] ?? 'Resident'));
$firstName = trim((string) strtok($displayName, ' '));
$recentComplaints = array_slice($complaints, 0, 5);
$concernLink = static function (array $complaint): array {
    $reply = $complaint['status'] === 'Needs Information' && $complaint['awaiting_reply'];
    return ['url' => 'complaint.php?id=' . (int) $complaint['complaint_id'] . ($reply ? '#reply' : '#timeline'),
        'label' => $reply ? 'Reply to request' : 'View timeline'];
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102b2d">
    <title>My Dashboard | AGAP Resident Portal</title>
    <link rel="icon" type="image/jpeg" href="../../assets/images/tumana_logo.jpg">
    <link rel="stylesheet" href="../../assets/css/resident-dashboard.css?v=<?= filemtime(__DIR__ . '/../../assets/css/resident-dashboard.css') ?>">
</head>
<body class="resident-dashboard-page">
    <header class="resident-topbar">
        <a class="resident-dashboard-brand" href="dashboard.php" aria-label="AGAP Resident Portal home">
            <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
            <span><strong>AGAP</strong><small>Resident Portal</small></span>
        </a>
        <div class="resident-topbar-actions">
            <?php if (!$dashboardUnavailable): ?>
                <span class="resident-topbar-name">Signed in as <?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <form method="post" action="../../../backend/api/auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CsrfService::token(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="resident-signout">Sign out</button>
            </form>
        </div>
    </header>

    <main class="resident-dashboard-shell">
        <?php if ($dashboardUnavailable): ?>
            <section class="resident-panel resident-concerns-panel" aria-labelledby="resident-dashboard-title">
                <h1 id="resident-dashboard-title">Resident dashboard unavailable</h1>
                <p>We couldn’t load your profile or concerns. Try again shortly. If the problem continues, contact the barangay office.</p>
                <a class="resident-secondary-action" href="dashboard.php">Try again</a>
            </section>
        <?php else: ?>
        <section class="rd-hero-card" aria-labelledby="resident-dashboard-title">
            <div class="rd-hero-copy">
                <span class="rd-hero-badge">Barangay Tumana, Marikina City</span>
                <h1 id="resident-dashboard-title">Good day, <?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?>.</h1>
                <p class="rd-hero-sub">Submit a community concern and follow its progress.</p>
                <div class="rd-hero-actions">
                    <a class="rd-hero-cta" href="../complaints/complaint-create.php">Submit a concern</a>
                    <?php if ($complaints): ?>
                        <a class="rd-hero-ghost" href="#my-concerns">Track my concerns</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rd-hero-art" aria-hidden="true">
                <div class="rd-hero-seal"><img src="../../assets/images/tumana_logo.jpg" alt=""></div>
            </div>
        </section>

        <section class="resident-summary-grid" aria-label="My concern summary">
            <article class="resident-summary-card"><span class="resident-summary-icon resident-icon-deep" aria-hidden="true">▤</span><div><strong><?= $summary['total'] ?></strong><span>Submitted concerns</span></div></article>
            <article class="resident-summary-card"><span class="resident-summary-icon resident-icon-wait" aria-hidden="true">◷</span><div><strong><?= $summary['pending'] ?></strong><span>Awaiting screening</span></div></article>
            <article class="resident-summary-card"><span class="resident-summary-icon resident-icon-attention" aria-hidden="true">!</span><div><strong><?= $summary['needs_information'] ?></strong><span>Need your information</span></div></article>
            <article class="resident-summary-card"><span class="resident-summary-icon resident-icon-progress" aria-hidden="true">✓</span><div><strong><?= $summary['in_progress'] ?></strong><span>Moving forward</span></div></article>
        </section>

        <div class="resident-dashboard-grid">
            <section class="resident-panel resident-concerns-panel" id="my-concerns" aria-labelledby="my-concerns-title">
                <div class="resident-panel-heading">
                    <div><p class="resident-dashboard-eyebrow">My submissions</p><h2 id="my-concerns-title">Recent concerns</h2></div>
                    <?php if (count($complaints) > count($recentComplaints)): ?><a href="#all-concerns" class="resident-text-link">View all <span aria-hidden="true">↓</span></a><?php endif; ?>
                </div>

                <?php if (!$recentComplaints): ?>
                    <div class="resident-empty-state">
                        <span aria-hidden="true">⌁</span><h3>No concerns submitted yet</h3>
                        <p>When you are ready, submit a concern for preliminary screening by the barangay.</p>
                        <a class="resident-secondary-action" href="../complaints/complaint-create.php">Submit your first concern</a>
                    </div>
                <?php else: ?>
                    <div class="resident-concern-list">
                        <?php foreach ($recentComplaints as $complaint): ?>
                            <?php $detail = $statusDetails($complaint); $link = $concernLink($complaint); ?>
                            <article class="resident-concern-row">
                                <div class="resident-concern-main">
                                    <div class="resident-reference"><?= htmlspecialchars((string) ($complaint['intake_reference'] ?: $complaint['complaint_number'] ?: 'Submission received'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <h3><?= htmlspecialchars((string) $complaint['complaint_title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <p><?= htmlspecialchars((string) ($complaint['category_name'] ?: 'Community concern'), ENT_QUOTES, 'UTF-8') ?> · Submitted <?= htmlspecialchars(date('M j, Y', strtotime((string) $complaint['created_at'])), ENT_QUOTES, 'UTF-8') ?></p>
                                    <a class="resident-text-link resident-concern-link" href="<?= htmlspecialchars($link['url']) ?>"><?= $link['label'] ?></a>
                                </div>
                                <div class="resident-concern-status"><span class="resident-status-badge tone-<?= htmlspecialchars($detail['tone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($detail['label'], ENT_QUOTES, 'UTF-8') ?></span><p><?= htmlspecialchars($detail['message'], ENT_QUOTES, 'UTF-8') ?></p></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="resident-sidebar-stack">
                <section class="resident-panel resident-next-panel" aria-labelledby="next-title">
                    <p class="resident-dashboard-eyebrow">Your guide</p><h2 id="next-title">What happens next?</h2>
                    <ol class="resident-steps">
                        <li><span>1</span><div><strong>Submit</strong><p>Share the concern and the relevant details.</p></div></li>
                        <li><span>2</span><div><strong>Screening</strong><p>A Lupon Member reviews whether informal Paanyaya is appropriate.</p></div></li>
                        <li><span>3</span><div><strong>Barangay action</strong><p>The barangay contacts you when the next action or information is needed.</p></div></li>
                    </ol>
                </section>
                <section class="resident-help-card" aria-labelledby="resident-help-title">
                    <div class="resident-help-icon" aria-hidden="true">?</div><div><h2 id="resident-help-title">Need help with a submission?</h2><p>Visit the barangay office and provide your reference number.</p></div>
                </section>
            </aside>
        </div>

        <?php if (count($complaints) > count($recentComplaints)): ?>
            <section class="resident-panel resident-all-concerns" id="all-concerns" aria-labelledby="all-concerns-title">
                <div class="resident-panel-heading"><div><p class="resident-dashboard-eyebrow">Complete record</p><h2 id="all-concerns-title">All submitted concerns</h2></div></div>
                <div class="resident-concern-list">
                    <?php foreach (array_slice($complaints, 5) as $complaint): ?>
                        <?php $detail = $statusDetails($complaint); $link = $concernLink($complaint); ?>
                        <article class="resident-concern-row">
                            <div class="resident-concern-main"><div class="resident-reference"><?= htmlspecialchars((string) ($complaint['intake_reference'] ?: $complaint['complaint_number'] ?: 'Submission received'), ENT_QUOTES, 'UTF-8') ?></div><h3><?= htmlspecialchars((string) $complaint['complaint_title'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars((string) ($complaint['category_name'] ?: 'Community concern'), ENT_QUOTES, 'UTF-8') ?> · Submitted <?= htmlspecialchars(date('M j, Y', strtotime((string) $complaint['created_at'])), ENT_QUOTES, 'UTF-8') ?></p><a class="resident-text-link resident-concern-link" href="<?= htmlspecialchars($link['url']) ?>"><?= $link['label'] ?></a></div>
                            <div class="resident-concern-status"><span class="resident-status-badge tone-<?= htmlspecialchars($detail['tone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($detail['label'], ENT_QUOTES, 'UTF-8') ?></span><p><?= htmlspecialchars($detail['message'], ENT_QUOTES, 'UTF-8') ?></p></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php endif; ?>
    </main>
    <footer class="resident-dashboard-footer"><span>AGAP Resident Portal</span><span>Personal information is visible only to authorized barangay personnel handling your concern.</span></footer>
</body>
</html>
