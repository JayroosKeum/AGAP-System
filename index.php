<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/services/auth.php';

startSecureSession();

$page = (string) ($_GET['page'] ?? 'landing');

$publicPages = [
    'landing',
    'login',
];

$protectedPages = [
    'dashboard',
    'profiles',
    'complaints',
    'cases',
    'summons',
    'mediation',
    'conciliation',
    'cfa',
    'documents',
    'reports',
    'users',
    'settings',
];

$allowedPages = array_merge($publicPages, $protectedPages);

if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = isset($_SESSION['admin_id']) ? 'dashboard' : 'landing';
}

if ($page === 'login' && isset($_SESSION['admin_id'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

if (in_array($page, $protectedPages, true)) {
    requireAdmin();
}

require __DIR__ . '/frontend/pages/' . $page . '.php';
