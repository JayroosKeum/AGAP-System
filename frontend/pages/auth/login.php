<?php
$loginError = ($_GET['error'] ?? '') === 'invalid_credentials';
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">

<main class="login-screen">
    <div class="login-shell">
        <section class="login-welcome" aria-labelledby="login-welcome-title">
            <a class="login-brand" href="../landing/index.php" aria-label="AGAP home">
                <img src="../../assets/images/tumana_logo.jpg" alt="">
                <span><strong>AGAP</strong><small>Barangay Tumana</small></span>
            </a>

            <div class="welcome-copy">
                <p class="login-eyebrow">Katarungang Pambarangay · Marikina City</p>
                <h1 id="login-welcome-title">Good work starts with a <span>clear process.</span></h1>
            </div>

            <div class="welcome-foot">
                <img src="../../assets/images/marikina_logo.jpg" alt="City of Marikina seal">
                <span>Supporting fair and orderly community resolution.</span>
            </div>
            <div class="login-decoration login-decoration-one" aria-hidden="true"></div>
            <div class="login-decoration login-decoration-two" aria-hidden="true"></div>
        </section>

        <section class="login-form-area" aria-label="Staff sign in">
            <form action="../../../backend/api/auth/login.php" method="POST" class="login-card">
                <p class="login-eyebrow">AGAP STAFF PORTAL</p>
                <h2>Welcome back</h2>
                <p class="login-intro">Sign in with your staff account to continue.</p>

                <?php if ($loginError): ?>
                    <div class="auth-message error" role="alert">
                        Invalid username or password.
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" autocomplete="username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                </div>

                <button type="submit">Sign in</button>
                <p class="auth-link"><a href="forgot-password.php">Forgot your password?</a></p>
            </form>
        </section>
    </div>
</main>

<footer class="login-footer">
    AGAP <span aria-hidden="true">·</span> Automated Grievance Assistance Platform <span aria-hidden="true">·</span> © <?php echo date('Y'); ?>
</footer>

<?php include '../../layouts/footer.php'; ?>
