<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/services/SecurityBootstrap.php';
SecurityBootstrap::startSession();
if (empty($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'Resident') {
    header('Location: login.php?error=login_required', true, 303);
    exit;
}
header('Cache-Control: private, no-store');

require_once __DIR__ . '/../../../backend/services/ResidentComplaintTimeline.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$residentUserId = (int) $_SESSION['user_id'];
$db = (new Database())->connect();
$timeline = $id ? (new ResidentComplaintTimeline($db))->find($id, $residentUserId) : null;
if (!$timeline) {
    http_response_code(404);
}
$error = null;
$message = '';
if ($timeline && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = is_string($_POST['response'] ?? null) ? trim($_POST['response']) : '';
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!CsrfService::validateRequest()) {
        http_response_code(419);
        $error = 'Your form expired. Please try again.';
    } elseif (!$requestId) {
        http_response_code(422);
        $error = 'Select a valid request before replying.';
    } else {
        try {
            (new ComplaintInformationService($db))->reply($id, $residentUserId, $requestId, $message);
            $_SESSION['resident_reply_confirmation'] = $id;
            header('Location: complaint.php?id=' . $id . '#timeline', true, 303);
            exit;
        } catch (DomainException $exception) {
            http_response_code(422);
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Resident complaint reply: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Unable to save your reply. Please try again.';
        }
        $timeline = (new ResidentComplaintTimeline($db))->find($id, $residentUserId);
    }
}
$confirmed = $id && ($_SESSION['resident_reply_confirmation'] ?? null) === $id;
if ($confirmed) {
    unset($_SESSION['resident_reply_confirmation']);
}
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$complaint = $timeline['complaint'] ?? null;
$request = $timeline['request'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102b2d">
    <title>Concern details | AGAP Resident Portal</title>
    <link rel="icon" type="image/jpeg" href="../../assets/images/tumana_logo.jpg">
    <link rel="stylesheet" href="../../assets/css/resident-dashboard.css?v=<?= filemtime(__DIR__ . '/../../assets/css/resident-dashboard.css') ?>">
</head>
<body class="resident-dashboard-page">
    <header class="resident-topbar">
        <a class="resident-dashboard-brand" href="dashboard.php" aria-label="AGAP Resident Portal home">
            <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
            <span><strong>AGAP</strong><small>Resident Portal</small></span>
        </a>
        <form method="post" action="../../../backend/api/auth/logout.php">
            <input type="hidden" name="csrf_token" value="<?= $escape(CsrfService::token()) ?>">
            <button type="submit" class="resident-signout">Sign out</button>
        </form>
    </header>
    <main class="resident-dashboard-shell resident-detail-shell">
        <a class="resident-text-link" href="dashboard.php#my-concerns">← Back to my concerns</a>
        <?php if (!$complaint): ?>
            <section class="resident-panel resident-concerns-panel">
                <h1>Concern unavailable</h1>
                <p>Open a concern from your dashboard to view its progress.</p>
            </section>
        <?php else: ?>
            <section class="rd-hero-card resident-detail-heading" aria-labelledby="concern-title">
                <div class="rd-hero-copy">
                    <p class="rd-hero-sub"><?= $escape($complaint['intake_reference'] ?: $complaint['complaint_number'] ?: 'Concern submitted') ?></p>
                    <h1 id="concern-title"><?= $escape($complaint['complaint_title']) ?></h1>
                    <?php if ($request): ?><a class="resident-primary-action" href="#reply">Reply to information request</a><?php endif; ?>
                </div>
            </section>
            <?php if ($error): ?><p class="resident-detail-feedback tone-attention" role="alert"><?= $escape($error) ?></p><?php endif; ?>
            <?php if ($confirmed): ?><p class="resident-detail-feedback tone-progress" role="status">Reply sent. Your concern is back with the screening officer.</p><?php endif; ?>
            <?php if ($request): ?>
                <section id="reply" class="resident-panel resident-concerns-panel" aria-labelledby="reply-title">
                    <h2 id="reply-title">Reply to the barangay</h2>
                    <p class="resident-message"><?= $escape($request['request_message']) ?></p>
                    <form class="resident-reply-form" method="post" action="complaint.php?id=<?= $id ?>#reply">
                        <input type="hidden" name="csrf_token" value="<?= $escape(CsrfService::token()) ?>">
                        <input type="hidden" name="request_id" value="<?= $escape($request['request_id']) ?>">
                        <label for="resident-response">Your response</label>
                        <textarea id="resident-response" name="response" rows="5" maxlength="<?= ComplaintInformationService::RESPONSE_LIMIT ?>" required><?= $escape(mb_substr($message, 0, ComplaintInformationService::RESPONSE_LIMIT)) ?></textarea>
                        <button class="rd-hero-cta" type="submit">Send response</button>
                    </form>
                </section>
            <?php elseif ($complaint['screening_decision'] === 'Needs Information'): ?>
                <p class="resident-detail-feedback tone-attention">Contact the barangay office with your reference number to ask what information is needed.</p>
            <?php endif; ?>

            <section id="timeline" class="resident-panel resident-concerns-panel" aria-labelledby="timeline-title">
                <h2 id="timeline-title">Complaint timeline</h2>
                <ol class="resident-timeline">
                    <?php foreach ($timeline['events'] as $event): ?>
                        <li>
                            <time datetime="<?= $escape(str_replace(' ', 'T', $event['date'])) ?>"><?= $escape(date(strlen($event['date']) > 10 ? 'M j, Y · g:i A' : 'M j, Y', strtotime($event['date']))) ?></time>
                            <h3><?= $escape($event['title']) ?></h3>
                            <?php if ($event['detail'] !== ''): ?><p class="resident-message"><?= $escape($event['detail']) ?></p><?php endif; ?>
                            <?php if ($event['reply']): ?><a class="resident-text-link" href="#reply">Go to reply form</a><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
