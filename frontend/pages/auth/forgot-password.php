<?php
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">

<main class="login-screen">
    <div class="login-shell">
        <?php
        $authWelcome = [
            'title_id' => 'forgot-welcome-title',
            'title_html' => 'Recover your <span>AGAP account.</span>',
            'subtitle' => 'Provide your account username or registered email address to receive a secure password reset link.',
        ];
        include '../../layouts/auth-welcome.php';
        ?>

        <section class="login-form-area" aria-label="Forgot password request">
            <form id="forgotPasswordForm" class="login-card">
                <div class="login-utility-bar">
                    <a class="login-util-link" href="../landing/index.php">&larr; Home</a>
                    <a class="login-util-link" href="login.php">Sign In &rarr;</a>
                </div>
                <p class="login-eyebrow">ACCOUNT RECOVERY</p>
                <h2>Forgot password</h2>
                <p class="login-intro">Enter your username or email below. We'll send a one-time verification link to reset your credentials.</p>

                <div id="authMessage" role="alert" aria-live="polite"></div>

                <div class="form-group">
                    <label for="identity">Username or Email</label>
                    <input type="text" id="identity" name="identity" maxlength="150" autocomplete="username" placeholder="Enter your username or email" required autofocus>
                    <span class="hint">Works for both Barangay Staff and Resident Portal accounts.</span>
                </div>

                <button type="submit" id="submitBtn">
                    <span>Send reset link</span>
                </button>


                <div class="login-legal-notice" role="note" style="margin-top: 20px;">
                    <div class="login-legal-notice-head">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <strong>Need assistance?</strong>
                    </div>
                    <p class="login-legal-notice-text">
                        If you have forgotten your username or no longer have access to your registered email, please visit the Lupon Office at Barangay Tumana Hall for assistance.
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
