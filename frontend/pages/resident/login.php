<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start();
if (($_SESSION['role_name'] ?? '') === 'Resident') {
    header('Location: dashboard.php', true, 303);
    exit;
}

$loggedOut = !empty($_GET['logged_out']);
$notice = (string) ($_GET['notice'] ?? $_GET['error'] ?? ($loggedOut ? 'logged_out' : ''));
$messages = [
    'logged_out' => 'You have been signed out of the Resident Portal.',
    'login_required' => 'Please sign in to your Resident account to continue.',
    'email_verified' => 'Your email is verified. Sign in with the password you chose when you registered.',
    'registered' => 'Your resident account is ready. Sign in to continue.',
    'verification_sent' => 'Check your inbox for the confirmation link. It expires in 24 hours.',
    'verification_pending' => 'Confirm your email before signing in. Check your inbox or request a new link below.',
    'verification_failed' => 'We could not send the confirmation email. Enter your username and password to request a new link.',
    'verification_wait' => 'Wait at least one minute between requests for a new link.',
    'invalid_credentials' => 'The username or password is incorrect. Resident accounts only can sign in here.',
    'google_failed' => 'Google could not verify this sign-in. Try again with your Gmail account.',
    'google_existing' => 'An account already uses this email. Sign in with your existing credentials.',
];
$showResend = in_array($notice, ['verification_sent', 'verification_pending', 'verification_failed', 'verification_wait'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer-when-downgrade">
    <title>Resident Sign In | AGAP</title>
    <link rel="icon" type="image/jpeg" href="../../assets/images/tumana_logo.jpg">
    <link rel="stylesheet" href="../../assets/css/resident-auth.css?v=<?= filemtime(__DIR__ . '/../../assets/css/resident-auth.css') ?>">
</head>
<body class="resident-auth-page">
    <main class="resident-auth-shell">
        <section class="resident-auth-intro" aria-labelledby="resident-auth-title">
            <a class="resident-brand" href="../landing/index.php" aria-label="Return to the AGAP home page">
                <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
                <span><strong>AGAP</strong><small>Barangay Tumana, Marikina City</small></span>
            </a>
            <div>
                <p class="resident-eyebrow">Resident Services</p>
                <h1 id="resident-auth-title">Your community concerns, <span>in one secure place.</span></h1>
                <p>Sign in to submit a concern, follow its screening status, and keep your contact details up to date.</p>
            </div>
            <p class="resident-auth-support">For public use only. Authorized barangay personnel should use the Staff Portal.</p>
        </section>

        <section class="resident-auth-panel" aria-label="Resident sign in">
            <div class="resident-auth-card">
                <p class="resident-eyebrow">Resident Portal</p>
                <h2>Welcome back</h2>
                <p class="resident-auth-lead">Sign in with the username and password you created.</p>

                <?php if (isset($messages[$notice])): ?>
                    <?php $isSuccess = in_array($notice, ['email_verified', 'registered', 'verification_sent', 'logged_out'], true); ?>
                    <div class="resident-message <?= $isSuccess ? 'success' : 'error' ?>" role="<?= $isSuccess ? 'status' : 'alert' ?>"><?= htmlspecialchars($messages[$notice], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form action="../../../backend/api/auth/resident-login.php" method="post">
                    <div class="resident-field">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" autocomplete="username" required autofocus>
                    </div>
                    <div class="resident-field">
                        <label for="password">Password</label>
                        <div class="resident-password-wrap">
                            <input id="password" name="password" type="password" autocomplete="current-password" required>
                            <button class="resident-password-toggle" type="button" aria-controls="password" aria-label="Show password">Show</button>
                        </div>
                    </div>
                    <button class="resident-submit" type="submit">Sign in to Resident Portal</button>
                    <?php if ($showResend): ?>
                        <p class="resident-terms-link"><button type="submit" name="action" value="resend_verification">Resend verification email</button></p>
                    <?php endif; ?>
                </form>

                <p class="resident-switch" style="margin-top: 12px; margin-bottom: 4px;"><a href="../auth/forgot-password.php?portal=resident">Forgot your password?</a></p>

                <?php require __DIR__ . '/google-sign-in.php'; ?>

                <p class="resident-switch">New to AGAP? <a href="register.php">Create a resident account</a></p>
                <p class="resident-terms-link"><button type="button" data-open-resident-terms>Read the Resident Portal User Agreement</button></p>
                <p class="resident-staff-link"><a href="../auth/login.php">Authorized personnel? Sign in to the Staff Portal</a></p>
            </div>
        </section>
    </main>
    <?php include __DIR__ . '/terms-modal.php'; ?>
    <script src="../../assets/js/resident-password-toggle.js" defer></script>
</body>
</html>
