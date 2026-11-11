<?php
$cfg = isset($authSignIn) && is_array($authSignIn) ? $authSignIn : [];
$loginPortalVal = (string) ($cfg['login_portal'] ?? '');
$loggedOutNotice = !empty($cfg['logged_out']);
$loginError = !empty($cfg['login_error']);
$loginErrorMessage = (string) ($cfg['login_error_message'] ?? '');
$registerMode = ($cfg['register_mode'] ?? 'link') === 'button' ? 'button' : 'link';
$alertId = (string) ($cfg['alert_id'] ?? '');
?>
<p class="login-eyebrow">AGAP &middot; BARANGAY TUMANA</p>
<h2>Welcome back</h2>
<p class="login-intro">Sign in with your AGAP account to continue.</p>

<?php if ($loggedOutNotice && !$loginError): ?>
    <div class="auth-message success" role="status">
        You have been signed out.
    </div>
<?php endif; ?>
<?php if ($alertId !== ''): ?>
    <div id="<?php echo htmlspecialchars($alertId, ENT_QUOTES, 'UTF-8'); ?>" class="auth-message error" role="alert" style="<?php echo $loginError ? 'display:block;' : 'display:none;'; ?>"><?php echo htmlspecialchars($loginErrorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php elseif ($loginError): ?>
    <div class="auth-message error" role="alert">
        <?php echo htmlspecialchars($loginErrorMessage, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<form action="../../../backend/api/auth/login.php" method="POST">
    <input type="hidden" name="login_portal" value="<?php echo htmlspecialchars($loginPortalVal, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="form-group">
        <label for="username">Username or Email</label>
        <input type="text" id="username" name="username" autocomplete="username" data-validate="login-identity" placeholder="Enter your username or email" required>
    </div>

    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>

    <button type="submit">Sign in</button>
</form>

<p class="auth-link"><a href="../auth/forgot-password.php">Forgot your password?</a></p>

<div class="resident-register-box">
    <p>New Barangay Tumana resident? Create an online account to submit complaints and track updates.</p>
    <?php if ($registerMode === 'button'): ?>
        <button type="button" class="resident-register-btn" id="tabResidentRegisterBtn" style="background:#ffffff; color:var(--auth-deep); border:1px solid #b7cec0;">Create Resident Account &rarr;</button>
    <?php else: ?>
        <a href="../complaints/resident-portal.php?tab=register" class="resident-register-btn">Create Resident Account &rarr;</a>
    <?php endif; ?>
</div>

<div class="login-legal-notice" role="note" aria-label="Authorized Use and Data Privacy Notice">
    <div class="login-legal-notice-head">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
        </svg>
        <strong>Data Privacy &amp; Terms</strong>
    </div>
    <p class="login-legal-notice-text">
        Protected under <strong>RA 7160</strong> and <strong>RA 10173 (Data Privacy Act)</strong>.
        <a href="#agapLegalModal" id="openLegalModalBtn" class="login-legal-link">Terms, Conditions &amp; Data Privacy Policy</a>.
    </p>
</div>
