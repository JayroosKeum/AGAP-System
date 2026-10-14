<?php
declare(strict_types=1);

$modulePage = [
    'section' => 'Administration',
    'title' => 'Settings',
    'subtitle' => 'System configuration',
    'description' => 'Manage controlled application settings without bypassing backend validation.',
    'empty_title' => 'No configurable settings available',
    'empty_description' => 'System settings will appear here after the settings service is implemented.',
];

require __DIR__ . '/../components/module-page.php';
