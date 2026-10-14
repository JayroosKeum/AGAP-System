<?php
$error = (string)($_SESSION['login_error'] ?? '');
unset($_SESSION['login_error']);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login | AGAP</title><link rel="stylesheet" href="frontend/assets/css/app.css"></head>
<body class="auth-page"><main class="auth-card"><a class="brand centered" href="index.php">AGAP</a><h1>Administrator Login</h1><p class="muted">Authorized administrator access only.</p><?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" action="backend/api/auth/login.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"><label for="username">Username</label><input id="username" name="username" value="admin" required autocomplete="username"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"><button class="button full" type="submit">Sign in</button></form><a class="back-link" href="index.php">Back to landing page</a></main></body></html>
