<?php
require_once __DIR__ . '/../../backend/services/CsrfService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$headerCsrfToken = CsrfService::token();
$headerFlashMessages = [];
if (!empty($_SESSION['complaint_flash']) && is_array($_SESSION['complaint_flash']) && !empty($_SESSION['complaint_flash']['message'])) {
    $headerFlashMessages[] = [
        'message' => (string) $_SESSION['complaint_flash']['message'],
        'type' => (string) ($_SESSION['complaint_flash']['type'] ?? 'success'),
        'title' => isset($_SESSION['complaint_flash']['title']) ? (string) $_SESSION['complaint_flash']['title'] : null,
    ];
    if (isset($_SESSION['complaint_flash']['old'])) {
        $_SESSION['complaint_flash'] = ['old' => $_SESSION['complaint_flash']['old']];
    } else {
        unset($_SESSION['complaint_flash']);
    }
}
if (!empty($_SESSION['resident_flash']) && is_array($_SESSION['resident_flash']) && !empty($_SESSION['resident_flash']['message'])) {
    $headerFlashMessages[] = [
        'message' => (string) $_SESSION['resident_flash']['message'],
        'type' => (string) ($_SESSION['resident_flash']['type'] ?? 'success'),
        'title' => isset($_SESSION['resident_flash']['title']) ? (string) $_SESSION['resident_flash']['title'] : null,
    ];
    unset($_SESSION['resident_flash']);
}
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash']) && !empty($_SESSION['flash']['message'])) {
    $headerFlashMessages[] = [
        'message' => (string) $_SESSION['flash']['message'],
        'type' => (string) ($_SESSION['flash']['type'] ?? 'success'),
        'title' => isset($_SESSION['flash']['title']) ? (string) $_SESSION['flash']['title'] : null,
    ];
    unset($_SESSION['flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#102b2d">
    <title>AGAP | Automated Grievance Assistance Platform</title>
    <link rel="stylesheet" href="../../assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/app.css'); ?>">
    <link rel="stylesheet" href="../../assets/css/notifications.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/notifications.css'); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="../../assets/js/notifications.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/notifications.js'); ?>"></script>
    <script src="../../assets/js/draft-manager.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/draft-manager.js'); ?>"></script>
    <script src="../../assets/js/draft-autosave.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/draft-autosave.js'); ?>"></script>
    <script>
        (function() {
            try {
                if (localStorage.getItem('agap_sidebar_collapsed') === 'true' && window.innerWidth > 900) {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (e) {}
        })();
    </script>
    <meta name="agap-csrf-token" content="<?php echo htmlspecialchars($headerCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<script>
(() => {
    const token = document.querySelector('meta[name="agap-csrf-token"]')?.content || '';
    window.agapCsrfToken = token;
    const unsafe = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
    const nativeFetch = window.fetch.bind(window);

    window.fetch = (input, init = {}) => {
        const method = String(init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        const url = new URL(input instanceof Request ? input.url : input, window.location.href);
        if (unsafe.has(method) && url.origin === window.location.origin) {
            const headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
            headers.set('X-CSRF-Token', token);
            init = { ...init, headers, credentials: init.credentials || 'same-origin' };
        }
        return nativeFetch(input, init);
    };

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || String(form.method || 'GET').toUpperCase() !== 'POST') return;
        const action = new URL(form.action || window.location.href, window.location.href);
        if (action.origin !== window.location.origin || form.querySelector('input[name="csrf_token"]')) return;
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'csrf_token';
        field.value = token;
        form.appendChild(field);
    }, true);
})();
</script>
<?php if (!empty($headerFlashMessages)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const flashes = <?php echo json_encode($headerFlashMessages); ?>;
    flashes.forEach(function(f) {
        if (window.agapNotify && f && f.message) {
            window.agapNotify(f.message, f.type || 'success', f.title || null);
        }
    });
});
</script>
<?php endif; ?>
