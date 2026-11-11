<?php
declare(strict_types=1);

session_start();
if (($_SESSION['role_name'] ?? '') === 'Resident') {
    header('Location: dashboard.php', true, 303);
    exit;
}

$error = ($_GET['error'] ?? '') === 'invalid_credentials';
$registered = ($_GET['registered'] ?? '') === '1';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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

                <?php if ($registered): ?>
                    <div class="resident-message success" role="status">Your account is ready. Sign in to continue.</div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="resident-message error" role="alert">The username or password is incorrect. Resident accounts only can sign in here.</div>
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
                            <button class="resident-password-toggle" type="button" aria-controls="password" aria-label="Show password" aria-pressed="false">Show</button>
                        </div>
                    </div>
                    <button class="resident-submit" type="submit">Sign in to Resident Portal</button>
                </form>

                <p class="resident-switch">New to AGAP? <a href="register.php">Create a resident account</a></p>
                <p class="resident-terms-link"><button type="button" data-open-resident-terms>Read the Resident Portal User Agreement</button></p>
                <p class="resident-staff-link"><a href="../auth/login.php">Authorized personnel? Sign in to the Staff Portal</a></p>
            </div>
        </section>
    </main>
    <?php include __DIR__ . '/terms-modal.php'; ?>
    <script>
        const password = document.getElementById('password');
        const toggle = document.querySelector('.resident-password-toggle');
        toggle?.addEventListener('click', () => {
            const show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Hide' : 'Show';
            toggle.setAttribute('aria-pressed', String(show));
            toggle.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} password`);
        });
    </script>
</body>
</html>
