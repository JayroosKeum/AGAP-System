<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#09090b">
    <title>AGAP | Automated Grievance Assistance Platform</title>
    <link rel="stylesheet" href="../../assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/app.css'); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <?php require_once __DIR__ . '/../../backend/services/CsrfService.php'; ?>
    <meta name="agap-csrf-token" content="<?php echo htmlspecialchars(CsrfService::token(), ENT_QUOTES, 'UTF-8'); ?>">
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
