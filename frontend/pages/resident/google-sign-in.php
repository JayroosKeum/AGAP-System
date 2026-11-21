<?php

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';
require_once __DIR__ . '/../../../backend/services/GoogleResidentIdentity.php';

$googleClientId = GoogleResidentIdentity::clientId();
if ($googleClientId === '' || session_status() !== PHP_SESSION_ACTIVE) {
    return;
}
$googleAttempt = $_SESSION['resident_google_attempt'] ?? [];
if (($googleAttempt['expires_at'] ?? 0) <= time()) {
    $googleAttempt = ['nonce' => bin2hex(random_bytes(32)), 'expires_at' => time() + 600];
    $_SESSION['resident_google_attempt'] = $googleAttempt;
}
?>
<div class="resident-field">
    <div id="g_id_onload"
        data-client_id="<?= htmlspecialchars($googleClientId, ENT_QUOTES, 'UTF-8') ?>"
        data-callback="submitResidentGoogleCredential"
        data-nonce="<?= htmlspecialchars($googleAttempt['nonce'], ENT_QUOTES, 'UTF-8') ?>"
        data-auto_prompt="false"></div>
    <div class="g_id_signin" data-type="standard" data-text="continue_with"></div>
    <form id="resident-google-sign-in" method="post" action="../../../backend/api/auth/resident-google.php" hidden>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CsrfService::token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="credential" value="">
    </form>
</div>
<script>
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    function submitResidentGoogleCredential(response) {
        const form = document.getElementById('resident-google-sign-in');
        if (!form || form.dataset.submitting === '1') {
            return;
        }
        form.dataset.submitting = '1';
        form.elements.credential.value = response.credential;
        form.submit();
    }
</script>
<script src="https://accounts.google.com/gsi/client" async defer></script>
