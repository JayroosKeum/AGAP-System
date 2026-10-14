<?php
declare(strict_types=1);

$modulePage = [
    'section' => 'Case Management',
    'title' => 'Complaints',
    'subtitle' => 'Complaint intake and review',
    'description' => 'Record, review, and prepare complaints before a case is docketed.',
    'empty_title' => 'No complaints recorded',
    'empty_description' => 'Complaint records will appear here after the complaint workflow is connected to the database.',
    'action_label' => 'New complaint',
    'action_url' => 'index.php?page=complaints&action=create',
];

require __DIR__ . '/../components/module-page.php';
