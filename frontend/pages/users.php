<?php
declare(strict_types=1);

$modulePage = [
    'section' => 'Administration',
    'title' => 'Users',
    'subtitle' => 'System user administration',
    'description' => 'Manage authorized system accounts and their assigned roles.',
    'empty_title' => 'No additional users configured',
    'empty_description' => 'The predetermined Administrator account remains the only account until user administration is implemented.',
];

require __DIR__ . '/../components/module-page.php';
