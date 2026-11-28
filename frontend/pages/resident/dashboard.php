<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
setcookie('agap_portal', 'resident', ['expires' => time() + 86400, 'path' => '/', 'samesite' => 'Lax']);

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'Resident') {
    header('Location: login.php?error=login_required', true, 303);
    exit;
}

require_once '../../../backend/config/database.php';
require_once '../../../backend/services/CsrfService.php';
require_once '../../../backend/services/PiiCryptoService.php';

$userId = (int) $_SESSION['user_id'];
$residentDashboardFlashes = [];
foreach (['complaint_flash', 'resident_flash', 'flash'] as $flashKey) {
    if (!empty($_SESSION[$flashKey]) && is_array($_SESSION[$flashKey]) && !empty($_SESSION[$flashKey]['message'])) {
        $residentDashboardFlashes[] = [
            'message' => (string) $_SESSION[$flashKey]['message'],
            'type' => (string) ($_SESSION[$flashKey]['type'] ?? 'success'),
            'title' => isset($_SESSION[$flashKey]['title']) ? (string) $_SESSION[$flashKey]['title'] : null,
        ];
    }
    unset($_SESSION[$flashKey]);
}
$db = (new Database())->connect();
$dashboardUnavailable = false;
$profile = [];
$complaints = [];

try {
    $profileStatement = $db->prepare(
        "SELECT u.username,
                r.first_name, r.middle_name, r.last_name, r.contact_no, r.email, r.purok, r.address,
                COALESCE(r.residency_verified, 0) AS residency_verified,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)), ''),
                         NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS display_name
         FROM users u
         LEFT JOIN resident_user_profiles rup ON rup.user_id = u.user_id
         LEFT JOIN residents r ON r.resident_id = rup.resident_id
         WHERE u.user_id = ? LIMIT 1"
    );
    $profileStatement->execute([$userId]);
    $rawProfile = $profileStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $profile = $rawProfile ? PiiCryptoService::decryptPayload($rawProfile) : [];

    $complaintsStatement = $db->prepare(
        'SELECT c.complaint_id, c.intake_reference, c.complaint_number, c.complaint_title, c.status,
                c.bpso_status, c.bpso_blotter_no, c.bpso_rejection_reason,
                c.screening_decision, c.created_at, c.updated_at, cc.category_name, cc.tagalog_name,
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

$translateStatusLabel = static function (string $status): string {
    return match ($status) {
        'Filed' => 'Naisumite',
        'Under Review' => 'Sinusuri',
        'Accepted' => 'Tinanggap',
        'Pre-Docketed (Paanyaya)' => 'Nakatakda sa Paanyaya',
        'Paanyaya In Progress' => 'Nasa Paanyaya',
        '1st Paanyaya Failed (No-Show)' => 'Hindi Dumalo sa Unang Paanyaya',
        'Pre-Docketed (Paanyaya 2 Authorized)' => 'Pinahintulutan ang Ikalawang Paanyaya',
        'Resolved at Paanyaya / Closed' => 'Naayos sa Paanyaya / Sarado',
        'Docketed' => 'Nai-docket Na',
        'Docketed (Mediation)' => 'Nai-docket (Mediation)',
        'Mediation' => 'Nasa Mediation',
        'Conciliation' => 'Nasa Conciliation',
        'Arbitration' => 'Nasa Arbitration',
        'Settled' => 'Naayos Na',
        'CFA Issued' => 'May Inisyung CFA',
        'For CFA' => 'Para sa CFA',
        'Dismissed' => 'Na-dismiss',
        'Needs Information' => 'Kailangan ng Impormasyon',
        default => $status !== '' ? $status : 'Pinoproseso',
    };
};

$statusDetails = static function (array $complaint) use ($translateStatusLabel): array {
    $bpsoStatus = (string) ($complaint['bpso_status'] ?? 'Endorsed to LISO');
    $decision = (string) ($complaint['screening_decision'] ?? 'Pending');
    $status = trim((string) ($complaint['status'] ?? 'Filed'));
    if ($bpsoStatus === 'Pending BPSO Review' && $decision === 'Pending') {
        return [
            'label' => 'Naghihintay ng beripikasyon ng BPSO',
            'label_en' => 'Pending BPSO Validation',
            'message' => 'Sinusuri ng BPSO Desk ang kumpletong detalye at kung taga-Barangay Tumana ang inirereklamo bago ipasa sa LISO.',
            'message_en' => 'BPSO is validating your submission details and confirming Tumana residency before endorsing to LISO.',
            'tone' => 'pending',
        ];
    }
    if ($bpsoStatus === 'Rejected') {
        $reason = trim((string) ($complaint['bpso_rejection_reason'] ?? ''));
        return [
            'label' => 'Ibinalik ng BPSO (Hindi kumpleto)',
            'label_en' => 'Returned by BPSO',
            'message' => ($reason !== '' ? $reason . ' ' : '') . 'Maaari kayong magtungo nang personal (walk-in) sa BPSO Desk ng Barangay Tumana.',
            'message_en' => ($reason !== '' ? $reason . ' ' : '') . 'You may visit the Barangay Tumana BPSO Desk in person to file walk-in.',
            'tone' => 'attention',
        ];
    }
    return match ($decision) {
        'Needs Information' => [
            'label' => 'Kailangan ng karagdagang impormasyon',
            'label_en' => 'More information needed',
            'message' => 'Kailangan ng kawani ng barangay ang karagdagang detalye bago maipagpatuloy ang pagsusuri.',
            'message_en' => 'Barangay personnel need additional details before screening can continue.',
            'tone' => 'attention',
        ],
        'Eligible' => [
            'label' => $translateStatusLabel($status),
            'label_en' => $status !== '' ? $status : 'In progress',
            'message' => 'Nakapasa ang iyong sumbong sa paunang pagsusuri at kasalukuyan nang pinoproseso.',
            'message_en' => 'Your concern passed preliminary screening and is being processed.',
            'tone' => 'progress',
        ],
        'Referred' => [
            'label' => 'Inirekomenda para sa tulong',
            'label_en' => 'Referred for assistance',
            'message' => 'Ang iyong sumbong ay inirekomenda sa naaangkop na susunod na hakbang.',
            'message_en' => 'Your concern has been referred to the appropriate next step.',
            'tone' => 'neutral',
        ],
        'Rejected' => [
            'label' => 'Tapos na ang pagsusuri',
            'label_en' => 'Screening completed',
            'message' => 'Mangyaring makipag-ugnayan sa tanggapan ng barangay kung kailangan mo ng paglilinaw.',
            'message_en' => 'Please contact the barangay office if you need clarification.',
            'tone' => 'neutral',
        ],
        'Duplicate' => [
            'label' => 'Itinugma sa umiiral na sumbong',
            'label_en' => 'Matched with an existing concern',
            'message' => 'Sinusuri ng kawani ng barangay ang magkatugmang rekord.',
            'message_en' => 'Barangay personnel are reviewing the matching record.',
            'tone' => 'neutral',
        ],
        default => [
            'label' => 'Naghihintay ng paunang pagsusuri',
            'label_en' => 'Awaiting preliminary screening',
            'message' => 'Susuriin muna ng isang Miyembro ng Lupon ang iyong isinumite bago ito umusad.',
            'message_en' => 'A Lupon Member will review your submission before it moves forward.',
            'tone' => 'pending',
        ],
    };
};

$formatTagalogDate = static function (string $dateString): string {
    $ts = strtotime($dateString);
    if ($ts === false) {
        return $dateString;
    }
    $months = [
        1 => 'Ene', 2 => 'Peb', 3 => 'Mar', 4 => 'Abr',
        5 => 'May', 6 => 'Hun', 7 => 'Hul', 8 => 'Ago',
        9 => 'Set', 10 => 'Okt', 11 => 'Nob', 12 => 'Dis',
    ];
    $m = $months[(int) date('n', $ts)] ?? date('M', $ts);
    return $m . ' ' . date('j, Y', $ts);
};

$displayName = trim((string) ($profile['display_name'] ?? 'Residente'));
$firstName = trim((string) strtok($displayName, ' '));
$residentHandle = trim((string) ($profile['username'] ?? ($_SESSION['username'] ?? '')));
$isVerifiedResident = !empty($profile['residency_verified']);
$isPlaceholderName = strcasecmp(trim((string) ($profile['first_name'] ?? '')), 'Unverified') === 0
    && strcasecmp(trim((string) ($profile['last_name'] ?? '')), 'Resident') === 0;
$recentComplaints = array_slice($complaints, 0, 5);
$concernLink = static function (array $complaint): array {
    $reply = $complaint['status'] === 'Needs Information' && $complaint['awaiting_reply'];
    return [
        'url' => 'complaint.php?id=' . (int) $complaint['complaint_id'] . ($reply ? '#reply' : '#timeline'),
        'label' => $reply ? 'Sumagot sa kahilingan' : 'Tingnan ang kasaysayan',
        'label_en' => $reply ? 'Reply to request' : 'View timeline',
    ];
};

$renderConcernRow = static function (array $complaint) use ($statusDetails, $concernLink, $formatTagalogDate): void {
    $detail = $statusDetails($complaint);
    $link = $concernLink($complaint);
    $refTl = (string) ($complaint['intake_reference'] ?: $complaint['complaint_number'] ?: 'Natanggap ang isinumite');
    $refEn = (string) ($complaint['intake_reference'] ?: $complaint['complaint_number'] ?: 'Submission received');
    $catEn = (string) ($complaint['category_name'] ?: 'Community concern');
    $catTl = (string) (($complaint['tagalog_name'] ?? '') !== '' ? $complaint['tagalog_name'] : ($complaint['category_name'] ?: 'Sumbong sa komunidad'));
    $dateTl = 'Naisumite noong ' . $formatTagalogDate((string) $complaint['created_at']);
    $dateEn = 'Submitted ' . date('M j, Y', strtotime((string) $complaint['created_at']));
    ?>
    <article class="resident-concern-row">
        <div class="resident-concern-main">
            <div class="resident-reference"<?= $refTl !== $refEn ? ' data-en="' . htmlspecialchars($refEn, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($refTl, ENT_QUOTES, 'UTF-8') ?></div>
            <h3><?= htmlspecialchars((string) $complaint['complaint_title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p><span data-en="<?= htmlspecialchars($catEn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($catTl, ENT_QUOTES, 'UTF-8') ?></span> · <span data-en="<?= htmlspecialchars($dateEn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dateTl, ENT_QUOTES, 'UTF-8') ?></span></p>
            <a class="resident-text-link resident-concern-link" href="<?= htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8') ?>" data-en="<?= htmlspecialchars($link['label_en'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?></a>
        </div>
        <div class="resident-concern-status">
            <span class="resident-status-badge tone-<?= htmlspecialchars($detail['tone'], ENT_QUOTES, 'UTF-8') ?>" data-en="<?= htmlspecialchars($detail['label_en'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($detail['label'], ENT_QUOTES, 'UTF-8') ?></span>
            <p data-en="<?= htmlspecialchars($detail['message_en'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($detail['message'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </article>
    <?php
};
?>
<!doctype html>
<html lang="tl" data-agap-lang="tl" data-default-lang="tl" data-resident-portal="1">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102b2d">
    <title data-en="My Dashboard | AGAP Resident Portal">Aking Talaan | AGAP Portal ng Residente</title>
    <link rel="icon" type="image/jpeg" href="../../assets/images/tumana_logo.jpg">
    <link rel="stylesheet" href="../../assets/css/notifications.css?v=<?= filemtime(__DIR__ . '/../../assets/css/notifications.css') ?>">
    <link rel="stylesheet" href="../../assets/css/resident-dashboard.css?v=<?= filemtime(__DIR__ . '/../../assets/css/resident-dashboard.css') ?>">
    <script src="../../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../../assets/js/notifications.js') ?>" defer></script>
    <script src="../../assets/js/i18n.js?v=<?= filemtime(__DIR__ . '/../../assets/js/i18n.js') ?>" defer></script>
</head>
<body class="resident-dashboard-page">
    <?php if (!empty($residentDashboardFlashes)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const flashes = <?= json_encode($residentDashboardFlashes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        flashes.forEach(function(f) {
            if (window.agapNotify && f && f.message) {
                window.agapNotify(f.message, f.type || 'success', f.title || null);
            }
        });
    });
    </script>
    <?php endif; ?>
    <?php
    $residentTopbarName = !$dashboardUnavailable ? $firstName : '';
    include __DIR__ . '/../../layouts/resident-topbar.php';
    ?>

    <main class="resident-dashboard-shell">
        <?php if ($dashboardUnavailable): ?>
            <section class="resident-panel resident-concerns-panel" aria-labelledby="resident-dashboard-title">
                <h1 id="resident-dashboard-title" data-en="Resident dashboard unavailable">Hindi magamit ang talaan ng residente</h1>
                <p data-en="We couldn’t load your profile or concerns. Try again shortly. If the problem continues, contact the barangay office.">Hindi namin ma-load ang iyong profile o mga sumbong. Subukang muli maya-maya. Kung magpapatuloy ang problema, makipag-ugnayan sa tanggapan ng barangay.</p>
                <a class="resident-secondary-action" href="dashboard.php" data-en="Try again">Subukang muli</a>
            </section>
        <?php else: ?>
        <section class="rd-hero-card" aria-labelledby="resident-dashboard-title">
            <div class="rd-hero-copy">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
                    <span class="rd-hero-badge" data-en="Barangay Tumana, Marikina City">Barangay Tumana, Lungsod ng Marikina</span>
                    <span style="display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px; font-size:0.74rem; font-weight:700; <?= $isVerifiedResident ? 'background:#dcfce7; color:#166534; border:1px solid #86efac;' : 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;' ?>">
                        <?= $isVerifiedResident ? 'Verified Resident' : 'Unverified / Pending Verification' ?>
                        <?= $residentHandle !== '' ? (' · ' . htmlspecialchars($residentHandle, ENT_QUOTES, 'UTF-8')) : '' ?>
                    </span>
                </div>
                <h1 id="resident-dashboard-title"><span data-en="Good day,">Magandang araw,</span> <?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?>.</h1>
                <p class="rd-hero-sub" data-en="Submit a community concern and follow its progress.">Magsumite ng sumbong sa komunidad at subaybayan ang progreso nito.</p>
                <div class="rd-hero-actions">
                    <a class="rd-hero-cta" href="../complaints/complaint-create.php" data-en="Submit a concern">Magsumite ng sumbong</a>
                    <?php if ($complaints): ?>
                        <a class="rd-hero-ghost" href="#my-concerns" data-en="Track my concerns">Subaybayan ang aking mga sumbong</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rd-hero-art" aria-hidden="true">
                <div class="rd-hero-seal"><img src="../../assets/images/tumana_logo.jpg" alt=""></div>
            </div>
        </section>

        <section class="resident-summary-grid" aria-label="Buod ng aking mga sumbong" data-en-aria-label="My concern summary">
            <article class="resident-summary-card"><span class="resident-summary-icon resident-icon-deep" aria-hidden="true">▤</span><div><strong><?= $summary['total'] ?></strong><span data-en="Submitted concerns">Mga naisumiteng sumbong</span></div></article>
            <article class="resident-summary-card"><span class="resident-summary-icon resident-icon-wait" aria-hidden="true">◷</span><div><strong><?= $summary['pending'] ?></strong><span data-en="Awaiting screening">Naghihintay ng pagsusuri</span></div></article>
            <article class="resident-summary-card"><span class="resident-summary-icon resident-icon-attention" aria-hidden="true">!</span><div><strong><?= $summary['needs_information'] ?></strong><span data-en="Need your information">Kailangan ng iyong impormasyon</span></div></article>
            <article class="resident-summary-card"><span class="resident-summary-icon resident-icon-progress" aria-hidden="true">✓</span><div><strong><?= $summary['in_progress'] ?></strong><span data-en="Moving forward">Umuusad na</span></div></article>
        </section>

        <div class="resident-dashboard-grid">
            <section class="resident-panel resident-concerns-panel" id="my-concerns" aria-labelledby="my-concerns-title">
                <div class="resident-panel-heading">
                    <div><p class="resident-dashboard-eyebrow" data-en="My submissions">Aking mga isinumite</p><h2 id="my-concerns-title" data-en="Recent concerns">Mga kamakailang sumbong</h2></div>
                    <?php if (count($complaints) > count($recentComplaints)): ?><a href="#all-concerns" class="resident-text-link"><span data-en="View all">Tingnan lahat</span> <span aria-hidden="true">↓</span></a><?php endif; ?>
                </div>

                <?php if (!$recentComplaints): ?>
                    <div class="resident-empty-state">
                        <span aria-hidden="true">⌁</span><h3 data-en="No concerns submitted yet">Wala pang naisumiteng sumbong</h3>
                        <p data-en="When you are ready, submit a concern for preliminary screening by the barangay.">Kapag handa ka na, magsumite ng sumbong para sa paunang pagsusuri ng barangay.</p>
                        <a class="resident-secondary-action" href="../complaints/complaint-create.php" data-en="Submit your first concern">Magsumite ng iyong unang sumbong</a>
                    </div>
                <?php else: ?>
                    <div class="resident-concern-list">
                        <?php foreach ($recentComplaints as $complaint): ?>
                            <?php $renderConcernRow($complaint); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="resident-sidebar-stack">
                <section class="resident-panel resident-next-panel" aria-labelledby="next-title">
                    <p class="resident-dashboard-eyebrow" data-en="Your guide">Iyong gabay</p><h2 id="next-title" data-en="What happens next?">Ano ang susunod na mangyayari?</h2>
                    <ol class="resident-steps">
                        <li><span>1</span><div><strong data-en="Submit">Magsumite</strong><p data-en="Share the concern and the relevant details.">Ibahagi ang sumbong at ang mga mahahalagang detalye.</p></div></li>
                        <li><span>2</span><div><strong data-en="Screening">Pagsusuri</strong><p data-en="A Lupon Member reviews whether informal Paanyaya is appropriate.">Susuriin ng isang Miyembro ng Lupon kung angkop ang impormal na Paanyaya.</p></div></li>
                        <li><span>3</span><div><strong data-en="Barangay action">Aksyon ng Barangay</strong><p data-en="The barangay contacts you when the next action or information is needed.">Makikipag-ugnayan sa iyo ang barangay kapag may susunod na hakbang o kailangang impormasyon.</p></div></li>
                    </ol>
                </section>
                <section class="resident-help-card" aria-labelledby="resident-help-title">
                    <div class="resident-help-icon" aria-hidden="true">?</div><div><h2 id="resident-help-title" data-en="Need help with a submission?">Kailangan ng tulong sa isinumite?</h2><p data-en="Visit the barangay office and provide your reference number.">Pumunta sa tanggapan ng barangay at ibigay ang iyong reference number.</p></div>
                </section>

                <?php if (!$isVerifiedResident): ?>
                <section class="resident-panel" id="resident-verify-profile" aria-labelledby="verify-profile-title" style="padding:18px; border:1.5px solid #fde68a; background:#fffbeb;">
                    <p class="resident-dashboard-eyebrow" style="color:#b45309;">Identity Verification Tier</p>
                    <h2 id="verify-profile-title" style="margin:0 0 6px; font-size:1.05rem; color:#78350f;">Complete Profile for BPSO Verification</h2>
                    <p style="margin:0 0 12px; font-size:0.84rem; color:#92400e; line-height:1.45;">
                        Your account (<strong><?= htmlspecialchars($residentHandle, ENT_QUOTES, 'UTF-8') ?></strong>) is currently <strong>Unverified / Pending Verification</strong>. Provide your legal name and Barangay Tumana address so BPSO can validate your grievance remotely without requiring a personal visit.
                    </p>
                    <form id="residentProfileCompleteForm" style="display:grid; gap:8px;">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <input type="text" name="first_name" required maxlength="100" placeholder="First Name *" value="<?= htmlspecialchars($isPlaceholderName ? '' : (string) ($profile['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
                            <input type="text" name="last_name" required maxlength="100" placeholder="Last Name *" value="<?= htmlspecialchars($isPlaceholderName ? '' : (string) ($profile['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
                        </div>
                        <input type="tel" name="contact_no" maxlength="20" placeholder="Mobile Number (e.g. 0917...)" value="<?= htmlspecialchars((string) ($profile['contact_no'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
                        <input type="email" name="email" maxlength="150" placeholder="Email Address (Optional)" value="<?= htmlspecialchars((string) ($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <input type="text" name="purok" maxlength="100" placeholder="Purok (e.g. Purok 2) *" value="<?= htmlspecialchars((string) ($profile['purok'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
                            <input type="text" name="address" maxlength="255" placeholder="Street / House No. *" value="<?= htmlspecialchars((string) ($profile['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
                        </div>
                        <button type="submit" id="btnSaveResidentProfile" class="rd-hero-cta" style="border:none; cursor:pointer; justify-content:center; padding:9px 14px; font-size:0.86rem;">Save Details for Verification</button>
                    </form>
                    <div id="residentProfileMsg" hidden style="margin-top:8px; font-size:0.82rem; font-weight:600;"></div>
                </section>
                <script>
                    document.getElementById('residentProfileCompleteForm')?.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const form = e.currentTarget;
                        const btn = document.getElementById('btnSaveResidentProfile');
                        const msg = document.getElementById('residentProfileMsg');
                        const payload = Object.fromEntries(new FormData(form).entries());
                        if (btn) btn.disabled = true;
                        try {
                            const res = await fetch('../../../backend/api/residents/update-profile.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': <?= json_encode(CsrfService::token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                                },
                                body: JSON.stringify(payload)
                            });
                            const data = await res.json();
                            if (!res.ok || !data.success) {
                                throw new Error(data.message || 'Unable to save profile.');
                            }
                            if (msg) {
                                msg.hidden = false;
                                msg.style.color = '#166534';
                                msg.textContent = data.message || 'Profile saved.';
                            }
                            window.agapNotify?.(data.message || 'Profile updated.', 'success');
                            setTimeout(() => window.location.reload(), 900);
                        } catch (err) {
                            if (msg) {
                                msg.hidden = false;
                                msg.style.color = '#991b1b';
                                msg.textContent = err.message || 'Unable to update profile.';
                            }
                        } finally {
                            if (btn) btn.disabled = false;
                        }
                    });
                </script>
                <?php endif; ?>
            </aside>
        </div>

        <?php if (count($complaints) > count($recentComplaints)): ?>
            <section class="resident-panel resident-all-concerns" id="all-concerns" aria-labelledby="all-concerns-title">
                <div class="resident-panel-heading"><div><p class="resident-dashboard-eyebrow" data-en="Complete record">Kumpletong talaan</p><h2 id="all-concerns-title" data-en="All submitted concerns">Lahat ng naisumiteng sumbong</h2></div></div>
                <div class="resident-concern-list">
                    <?php foreach (array_slice($complaints, 5) as $complaint): ?>
                        <?php $renderConcernRow($complaint); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php endif; ?>
    </main>
    <footer class="resident-dashboard-footer"><span data-en="AGAP Resident Portal">AGAP Portal ng Residente</span><span data-en="Personal information is visible only to authorized barangay personnel handling your concern.">Ang personal na impormasyon ay makikita lamang ng mga awtorisadong kawani ng barangay na humahawak sa iyong sumbong.</span></footer>
</body>
</html>
