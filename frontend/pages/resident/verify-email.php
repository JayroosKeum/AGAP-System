<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$confirming = $_SERVER['REQUEST_METHOD'] === 'POST';
$token = $confirming ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '');
$showConfirmation = is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1;

if ($confirming && $showConfirmation) {
    require_once __DIR__ . '/../../../backend/controllers/AuthController.php';
    $password = $_POST['password'] ?? '';
    if (is_string($password) && (new AuthController())->confirmResidentEmail($token, $password)) {
        header('Location: login.php?notice=email_verified', true, 303);
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Resident Email | AGAP</title>
    <link rel="stylesheet" href="../../assets/css/resident-auth.css?v=<?= filemtime(__DIR__ . '/../../assets/css/resident-auth.css') ?>">
</head>
<body class="resident-auth-page">
    <main class="resident-auth-shell">
        <section class="resident-auth-intro" aria-label="AGAP Resident Portal">
            <a class="resident-brand" href="../landing/index.php" aria-label="Return to the AGAP home page">
                <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
                <span><strong>AGAP</strong><small>Barangay Tumana, Marikina City</small></span>
            </a>
        </section>
        <section class="resident-auth-panel" aria-labelledby="confirm-resident-email">
            <div class="resident-auth-card">
                <h1 id="confirm-resident-email">Confirm your email</h1>
                <?php if ($confirming || !$showConfirmation): ?>
                    <p class="resident-message error" role="alert">Check your password or sign in to request a new link if this one has expired or was already used.</p>
                <?php endif; ?>
                <?php if ($showConfirmation): ?>
                    <form action="verify-email.php" method="post">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="resident-field">
                            <label for="verification-password">Password you chose during registration</label>
                            <input id="verification-password" name="password" type="password" autocomplete="current-password" required>
                        </div>
                        <button class="resident-submit" type="submit">Confirm email address</button>
                    </form>
                <?php endif; ?>
                <p class="resident-switch"><a href="login.php">Go to Resident Sign In</a></p>
            </div>
        </section>
    </main>
</body>
</html>
