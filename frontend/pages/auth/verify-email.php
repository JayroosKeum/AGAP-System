<?php
require_once '../../../backend/controllers/AuthController.php';
$token = trim((string) ($_GET['token'] ?? ''));
$validToken = (new AuthController())->isEmailVerificationTokenValid($token);
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">
<main class="login-screen">
    <div class="login-shell">
        <?php
        $authWelcome = [
            'title_id' => 'verify-welcome-title',
            'title_html' => 'Verify your <span>email &amp; account.</span>',
            'subtitle' => 'Confirm your registered Barangay Tumana email and set your secure password to sign in.',
        ];
        include '../../layouts/auth-welcome.php';
        ?>

        <section class="login-form-area" aria-label="Email verification form">
            <?php if (!$validToken): ?>
                <div class="login-card">
                    <div class="login-utility-bar">
                        <a class="login-util-link" href="../landing/index.php">&larr; Public Home</a>
                    </div>
                    <p class="login-eyebrow" style="color: #c53030;">SECURITY NOTICE</p>
                    <h2>Verification link unavailable</h2>
                    <p class="login-intro">This verification link is invalid, has already been used, or has expired. Please ask an administrator to resend it.</p>
                    <div class="auth-message error" role="alert">Verification token unavailable.</div>
                    <p class="auth-link"><a href="login.php">&larr; Back to sign in</a></p>
                </div>
            <?php else: ?>
                <form id="verifyEmailForm" class="login-card">
                    <div class="login-utility-bar">
                        <a class="login-util-link" href="../landing/index.php">&larr; Public Home</a>
                    </div>
                    <p class="login-eyebrow">EMAIL VERIFICATION</p>
                    <h2>Verify email &amp; set password</h2>
                    <p class="login-intro">Set a strong password to complete verification. This link expires after 24 hours.</p>
                    <div id="authMessage" role="alert" aria-live="polite"></div>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-group">
                        <label for="newPassword">New Password</label>
                        <input id="newPassword" type="password" name="password" minlength="12" maxlength="128" data-validate="password" autocomplete="new-password" placeholder="At least 12 characters" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="passwordConfirmation">Confirm New Password</label>
                        <input id="passwordConfirmation" type="password" name="password_confirmation" minlength="12" maxlength="128" data-validate="password-confirm" autocomplete="new-password" placeholder="Re-enter your password" required>
                    </div>
                    <button type="submit">Verify &amp; activate account</button>
                    <p class="auth-link"><a href="login.php">&larr; Back to sign in</a></p>
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>
<footer class="login-footer">
    AGAP <span aria-hidden="true">·</span> Automated Grievance Assistance Platform <span aria-hidden="true">·</span> © <?php echo date('Y'); ?>
</footer>
<script src="../../assets/js/passwords.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/passwords.js'); ?>"></script><?php include '../../layouts/footer.php'; ?>
