<?php
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">

<main class="login-screen">
    <div class="login-shell">
        <section class="login-welcome" aria-labelledby="forgot-welcome-title">
            <a class="login-brand" href="../landing/index.php" aria-label="AGAP home">
                <img src="../../assets/images/tumana_logo.jpg" alt="">
                <span><strong>AGAP</strong><small>Barangay Tumana</small></span>
            </a>

            <div class="welcome-copy">
                <p class="login-eyebrow">Katarungang Pambarangay · Marikina City</p>
                <h1 id="forgot-welcome-title">Recover your <span>staff account.</span></h1>
                <p>Provide your account username to receive a secure password reset link at your registered email address.</p>
            </div>

            <div class="welcome-foot">
                <img src="../../assets/images/marikina_logo.jpg" alt="City of Marikina seal">
                <span>Supporting fair and orderly community resolution.</span>
            </div>
            <div class="login-decoration login-decoration-one" aria-hidden="true"></div>
            <div class="login-decoration login-decoration-two" aria-hidden="true"></div>
        </section>

        <section class="login-form-area" aria-label="Forgot password request">
            <form id="forgotPasswordForm" class="login-card" novalidate>
                <p class="login-eyebrow">ACCOUNT RECOVERY</p>
                <h2>Forgot password</h2>
                <p class="login-intro">Enter your username below. We'll send a one-time verification link to reset your credentials.</p>

                <div id="authMessage" role="alert" aria-live="polite"></div>

                <div class="form-group">
                    <label for="identity">Username</label>
                    <input type="text" id="identity" name="identity" autocomplete="username" placeholder="Enter your staff username" required autofocus>
                    <small style="display:block; margin-top: 6px; font-size: 11px; color: #687a76;">
                        You may also enter your registered email address.
                    </small>
                </div>

                <button type="submit" id="submitBtn">
                    <span>Send reset link</span>
                </button>

                <p class="auth-link">
                    <a href="login.php">← Back to sign in</a>
                </p>

                <div class="login-legal-notice" role="note" style="margin-top: 24px;">
                    <div class="login-legal-notice-head">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <strong>Need assistance?</strong>
                    </div>
                    <p class="login-legal-notice-text">
                        If you have forgotten your username or no longer have access to your registered email, please visit the Lupon Office to have an administrator assist you.
                    </p>
                </div>
            </form>
        </section>
    </div>
</main>

<footer class="login-footer">
    AGAP <span aria-hidden="true">·</span> Automated Grievance Assistance Platform <span aria-hidden="true">·</span> © <?php echo date('Y'); ?>
</footer>

<script src="../../assets/js/passwords.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/passwords.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
