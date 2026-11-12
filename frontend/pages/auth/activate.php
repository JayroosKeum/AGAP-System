<?php include '../../layouts/header.php'; $token = trim((string) ($_GET['token'] ?? '')); ?>
<link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">
<main class="login-screen">
    <div class="login-shell">
        <?php
        $authWelcome = [
            'title_id' => 'activate-welcome-title',
            'title_html' => 'Activate your <span>AGAP account.</span>',
            'subtitle' => 'Set your official password to begin accessing Barangay Tumana case workflows and schedules.',
        ];
        include '../../layouts/auth-welcome.php';
        ?>

        <section class="login-form-area" aria-label="Account activation form">
            <form id="activateAccountForm" class="login-card">
                <div class="login-utility-bar">
                    <a class="login-util-link" href="../landing/index.php">&larr; Public Home</a>
                </div>
                <p class="login-eyebrow">STAFF ONBOARDING</p>
                <h2>Activate your account</h2>
                <p class="login-intro">Create a strong password to activate your account. This activation link expires after 24 hours.</p>

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

                <button type="submit">Activate account</button>
                <p class="auth-link"><a href="login.php">&larr; Back to sign in</a></p>
            </form>
        </section>
    </div>
</main>
<footer class="login-footer">
    AGAP <span aria-hidden="true">·</span> Automated Grievance Assistance Platform <span aria-hidden="true">·</span> © <?php echo date('Y'); ?>
</footer>
<script src="../../assets/js/passwords.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/passwords.js'); ?>"></script><?php include '../../layouts/footer.php'; ?>
