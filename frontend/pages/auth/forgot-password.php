<?php
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/services/GoogleResidentIdentity.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$googleClientId = GoogleResidentIdentity::clientId();
$googleAttempt = $_SESSION['forgot_password_google_attempt'] ?? [];
if (($googleAttempt['expires_at'] ?? 0) <= time()) {
    $googleAttempt = ['nonce' => bin2hex(random_bytes(32)), 'expires_at' => time() + 600];
    $_SESSION['forgot_password_google_attempt'] = $googleAttempt;
}

$portal = (($_GET['portal'] ?? '') === 'resident') ? 'resident' : 'staff';
$signInHref = ($portal === 'resident') ? '../resident/login.php' : 'login.php';
$signInLabel = ($portal === 'resident') ? 'Resident Sign In →' : 'Staff Sign In →';

include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">

<main class="login-screen">
    <div class="login-shell">
        <?php
        $authWelcome = [
            'title_id' => 'forgot-welcome-title',
            'title_html' => 'Recover your <span>AGAP account.</span>',
            'subtitle' => 'Sign in with your registered Gmail account to verify your identity and receive a password reset confirmation link.',
        ];
        include '../../layouts/auth-welcome.php';
        ?>

        <section class="login-form-area" aria-label="Forgot password request">
            <div class="login-card">
                <div class="login-utility-bar">
                    <a class="login-util-link" href="../landing/index.php">&larr; Home</a>
                    <a class="login-util-link" href="<?php echo htmlspecialchars($signInHref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($signInLabel, ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
                <p class="login-eyebrow">ACCOUNT RECOVERY</p>
                <h2>Forgot password</h2>
                <p class="login-intro">Sign in with your Gmail account below. We will send a confirmation link to your Gmail inbox where you can enter your new password.</p>

                <div id="authMessage" role="alert" aria-live="polite"></div>

                <?php if ($googleClientId !== ''): ?>
                    <div id="forgotGoogleSection" style="background: #f4f9f6; border: 1.5px solid #cde2d6; border-radius: 12px; padding: 18px; margin-bottom: 18px;">
                        <div style="font-size: 0.78rem; font-weight: 800; color: #1f5c4e; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 6px;">
                            Step 1 · Verify with Gmail
                        </div>
                        <p style="font-size: 0.88rem; color: #26433e; margin: 0 0 14px; line-height: 1.5;">
                            Choose the Gmail account linked to your AGAP Resident or Staff profile. Once verified, check your Gmail inbox for the confirmation email to set your new password.
                        </p>
                        <div id="g_id_onload"
                             data-client_id="<?php echo htmlspecialchars($googleClientId, ENT_QUOTES, 'UTF-8'); ?>"
                             data-callback="submitForgotPasswordGoogleCredential"
                             data-nonce="<?php echo htmlspecialchars($googleAttempt['nonce'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-auto_prompt="false"></div>
                        <div class="g_id_signin"
                             data-type="standard"
                             data-size="large"
                             data-theme="outline"
                             data-text="signin_with"
                             data-shape="rectangular"
                             data-logo_alignment="left"></div>
                    </div>
                    <script src="https://accounts.google.com/gsi/client" async defer></script>
                <?php endif; ?>

                <details id="forgotManualFallback" <?php echo $googleClientId === '' ? 'open' : ''; ?> style="margin-top: 8px; border-top: 1px solid #e2ebe5; padding-top: 12px;">
                    <summary style="cursor: pointer; font-size: 0.84rem; font-weight: 600; color: #2b6b5e; user-select: none;">
                        <?php echo $googleClientId !== '' ? 'Or send a reset confirmation email by typing your username or email' : 'Send a reset confirmation email using your username or email'; ?>
                    </summary>
                    <form id="forgotPasswordForm" style="margin-top: 14px;">
                        <div class="form-group">
                            <label for="identity">Username or Registered Gmail / Email</label>
                            <input type="text" id="identity" name="identity" maxlength="150" autocomplete="username" placeholder="Enter your username or Gmail address" required>
                            <span class="hint">Works for both Barangay Staff and Resident Portal accounts.</span>
                        </div>

                        <button type="submit" id="submitBtn">
                            <span>Send confirmation email to Gmail</span>
                        </button>
                    </form>
                </details>

                <div class="login-legal-notice" role="note" style="margin-top: 20px;">
                    <div class="login-legal-notice-head">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <strong>How it works</strong>
                    </div>
                    <p class="login-legal-notice-text">
                        1. Sign in with your Gmail above.<br>
                        2. Open the confirmation email sent to your Gmail inbox.<br>
                        3. Click <strong>Confirm &amp; Enter New Password</strong> in the email to input your new password.
                    </p>
                </div>
            </div>
        </section>
    </div>
</main>

<footer class="login-footer">
    AGAP <span aria-hidden="true">·</span> Automated Grievance Assistance Platform <span aria-hidden="true">·</span> © <?php echo date('Y'); ?>
</footer>

<script src="../../assets/js/passwords.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/passwords.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
