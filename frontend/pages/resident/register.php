<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start();
if (($_SESSION['role_name'] ?? '') === 'Resident') {
    header('Location: dashboard.php', true, 303);
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';
require_once __DIR__ . '/../../../backend/services/GoogleResidentIdentity.php';

$identity = $_SESSION['resident_google_registration'] ?? [];
$googleConfigured = GoogleResidentIdentity::clientId() !== '';
$confirmedEmail = $googleConfigured && ($identity['expires_at'] ?? 0) > time() ? $identity['email'] : null;

$error = (string) ($_GET['error'] ?? '');
$messages = [
    'validation' => 'Please review the required fields and password requirements.',
    'duplicate' => 'That username or email address is already registered.',
    'unavailable' => 'We could not create your account right now. Please try again later.',
    'google_required' => 'Confirm your Gmail address with Google before registering.',
    'session_expired' => 'Your registration session expired. Please submit your details again.',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer-when-downgrade">
    <title>Create Resident Account | AGAP</title>
    <link rel="icon" type="image/jpeg" href="../../assets/images/tumana_logo.jpg">
    <link rel="stylesheet" href="../../assets/css/resident-auth.css?v=<?= filemtime(__DIR__ . '/../../assets/css/resident-auth.css') ?>">
</head>
<body class="resident-auth-page">
    <main class="resident-auth-shell resident-register-shell">
        <section class="resident-auth-intro" aria-labelledby="resident-register-title">
            <a class="resident-brand" href="../landing/index.php" aria-label="Return to the AGAP home page">
                <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
                <span><strong>AGAP</strong><small>Barangay Tumana, Marikina City</small></span>
            </a>
            <div>
                <p class="resident-eyebrow">Resident Services</p>
                <h1 id="resident-register-title">Start with a <span>secure resident account.</span></h1>
                <p>Your account lets you submit a concern online and view its status after staff screening.</p>
            </div>
            <p class="resident-auth-support">Your residency and submitted information may be verified by the Barangay before a complaint is processed.</p>
        </section>

        <section class="resident-auth-panel" aria-label="Create a resident account">
            <div class="resident-auth-card resident-register-card">
                <p class="resident-eyebrow">Resident Registration</p>
                <h2>Create your account</h2>
                <p class="resident-auth-lead"><?= $googleConfigured ? 'Use Google to confirm your Gmail address, then complete your resident profile.' : 'Complete your resident profile to create an account.' ?></p>

                <?php if (isset($messages[$error])): ?>
                    <div class="resident-message error" role="alert"><?= htmlspecialchars($messages[$error], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if (!$googleConfigured || $confirmedEmail !== null): ?>
                <form action="../../../backend/api/auth/register-resident.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CsrfService::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="resident-name-grid">
                        <div class="resident-field"><label for="first_name">First name <span>*</span></label><input id="first_name" name="first_name" type="text" autocomplete="given-name" maxlength="100" required></div>
                        <div class="resident-field"><label for="last_name">Last name <span>*</span></label><input id="last_name" name="last_name" type="text" autocomplete="family-name" maxlength="100" required></div>
                    </div>
                    <div class="resident-field"><label for="username">Username <span>*</span></label><input id="username" name="username" type="text" autocomplete="username" pattern="[A-Za-z0-9._-]{3,100}" title="Use 3 or more letters, numbers, periods, underscores, or hyphens." maxlength="100" required></div>
                    <?php if ($confirmedEmail !== null): ?>
                        <div class="resident-field"><label for="email">Google-confirmed Gmail address</label><input id="email" type="email" value="<?= htmlspecialchars($confirmedEmail, ENT_QUOTES, 'UTF-8') ?>" readonly></div>
                    <?php else: ?>
                        <div class="resident-field"><label for="email">Email address <span>*</span></label><input id="email" name="email" type="email" autocomplete="email" maxlength="150" required></div>
                    <?php endif; ?>
                    <div class="resident-field"><label for="contact_no">Mobile or contact number</label><input id="contact_no" name="contact_no" type="tel" inputmode="tel" pattern="[0-9+() .-]{10,20}" title="Enter a valid Philippine mobile or telephone number (numbers only)." autocomplete="tel" maxlength="20" oninput="this.value=this.value.replace(/[^0-9+() .-]/g,'')"></div>
                    <div class="resident-name-grid">
                        <div class="resident-field">
                            <label for="password">Password <span>*</span></label>
                            <div class="resident-password-wrap">
                                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
                                <button class="resident-password-toggle" type="button" aria-controls="password" aria-label="Show password">Show</button>
                            </div>
                        </div>
                        <div class="resident-field">
                            <label for="password_confirmation">Confirm password <span>*</span></label>
                            <div class="resident-password-wrap">
                                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
                                <button class="resident-password-toggle" type="button" aria-controls="password_confirmation" aria-label="Show password confirmation">Show</button>
                            </div>
                        </div>
                    </div>
                    <p class="resident-password-help">Use at least 12 characters, including an uppercase letter, lowercase letter, and number.</p>
                    <div class="resident-agreement">
                        <input id="terms_agreed" name="terms_agreed" type="checkbox" value="1" required aria-label="I agree to the Resident Portal User Agreement and Privacy Notice">
                        <span>I have read and agree to the </span><button type="button" data-open-resident-terms>Resident Portal User Agreement and Privacy Notice</button><span>.</span>
                    </div>
                    <button class="resident-submit" type="submit">Create Resident Account</button>
                </form>
                <?php else: ?>
                    <?php require __DIR__ . '/google-sign-in.php'; ?>
                <?php endif; ?>

                <p class="resident-switch">Already registered? <a href="login.php">Sign in to Resident Portal</a></p>
                <p class="resident-staff-link"><a href="../auth/login.php">Authorized personnel? Sign in to the Staff Portal</a></p>
            </div>
        </section>
    </main>
    <?php include __DIR__ . '/terms-modal.php'; ?>
    <script src="../../assets/js/resident-password-toggle.js" defer></script>
</body>
</html>
