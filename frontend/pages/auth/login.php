<?php
$errorCode = (string) ($_GET['error'] ?? '');
$lockMins = max(1, min(120, (int) ($_GET['minutes'] ?? 15)));
$loginErrorMessage = match ($errorCode) {
    'account_locked' => "Too many failed sign-in attempts. For security, sign-in is temporarily locked for {$lockMins} minute(s).",
    'already_logged_in' => 'This account is already signed in on another device. Sign out there first or wait 15 minutes of inactivity before signing in here.',
    'session_expired' => 'Your session expired due to inactivity or is no longer active. Please sign in again.',
    'invalid_credentials' => 'Invalid username/email or password.',
    default => '',
};
$loginError = $loginErrorMessage !== '';
$loggedOutNotice = ($_GET['logged_out'] ?? '') === '1';
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">

<main class="login-screen">
    <div class="login-shell">
        <?php
        $authWelcome = [
            'title_id' => 'login-welcome-title',
            'title_html' => 'Good work starts with a <span>clear process.</span>',
            'subtitle' => 'Official Katarungang Pambarangay case management, online complaint filing, Paanyaya schedules, and hearing updates.',
            'subtitle_id' => 'loginWelcomeSubtitle',
        ];
        include '../../layouts/auth-welcome.php';
        ?>

        <section class="login-form-area" aria-label="Portal sign in">
            <div class="login-card">
                <div class="login-utility-bar">
                    <a class="login-util-link" href="../landing/index.php">&larr; Home</a>
                </div>
                <?php
                $authSignIn = [
                    'login_portal' => '',
                    'logged_out' => $loggedOutNotice,
                    'login_error' => $loginError,
                    'login_error_message' => $loginErrorMessage,
                    'register_mode' => 'link',
                ];
                include '../../layouts/auth-signin-card.php';
                ?>
            </div>
        </section>
    </div>
</main>

<?php include '../../layouts/auth-legal-modal.php'; ?>

<?php include '../../layouts/footer.php'; ?>
