<?php
require_once __DIR__ . '/../../../backend/controllers/AuthController.php';
$token = trim((string) ($_GET['token'] ?? ''));
$isValidToken = (new AuthController())->isPasswordResetTokenValid($token);

include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">

<main class="login-screen">
    <div class="login-shell">
        <section class="login-welcome" aria-labelledby="reset-welcome-title">
            <a class="login-brand" href="../landing/index.php" aria-label="AGAP home">
                <img src="../../assets/images/tumana_logo.jpg" alt="">
                <span><strong>AGAP</strong><small>Barangay Tumana</small></span>
            </a>

            <div class="welcome-copy">
                <p class="login-eyebrow">Katarungang Pambarangay · Marikina City</p>
                <h1 id="reset-welcome-title">Set a new <span>secure password.</span></h1>
                <p>Choose a strong password to protect your staff access and official case records.</p>
            </div>

            <div class="welcome-foot">
                <img src="../../assets/images/marikina_logo.jpg" alt="City of Marikina seal">
                <span>Supporting fair and orderly community resolution.</span>
            </div>
            <div class="login-decoration login-decoration-one" aria-hidden="true"></div>
            <div class="login-decoration login-decoration-two" aria-hidden="true"></div>
        </section>

        <section class="login-form-area" aria-label="Reset password form">
            <?php if (!$isValidToken): ?>
                <div class="login-card">
                    <p class="login-eyebrow" style="color: #c53030;">SECURITY NOTICE</p>
                    <h2>Link Expired or Invalid</h2>
                    <p class="login-intro">This password reset link is invalid or has expired. For your security, reset links are single-use and expire after 1 hour.</p>

                    <div class="auth-message error" role="alert" style="margin-bottom: 20px;">
                        The verification token has expired or has already been used.
                    </div>

                    <a href="forgot-password.php" style="display: block; text-align: center; text-decoration: none;">
                        <button type="button" style="width: 100%;">Request a new reset link</button>
                    </a>

                    <p class="auth-link" style="margin-top: 16px;">
                        <a href="login.php">← Return to sign in</a>
                    </p>
                </div>
            <?php else: ?>
                <form id="resetPasswordForm" class="login-card" novalidate>
                    <p class="login-eyebrow">CREDENTIAL UPDATE</p>
                    <h2>Reset your password</h2>
                    <p class="login-intro">Create a new password. Use at least 12 characters with uppercase, lowercase, and a number.</p>

                    <div id="authMessage" role="alert" aria-live="polite"></div>

                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-group">
                        <label for="newPassword">New Password</label>
                        <input type="password" id="newPassword" name="password" autocomplete="new-password" placeholder="At least 12 characters" required autofocus>
                    </div>

                    <div class="form-group">
                        <label for="passwordConfirmation">Confirm New Password</label>
                        <input type="password" id="passwordConfirmation" name="password_confirmation" autocomplete="new-password" placeholder="Re-enter your new password" required>
                    </div>

                    <button type="submit" id="submitBtn">
                        <span>Reset Password</span>
                    </button>

                    <p class="auth-link">
                        <a href="login.php">← Back to sign in</a>
                    </p>
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>

<footer class="login-footer">
    AGAP <span aria-hidden="true">·</span> Automated Grievance Assistance Platform <span aria-hidden="true">·</span> © <?php echo date('Y'); ?>
</footer>

<script src="../../assets/js/passwords.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/passwords.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
