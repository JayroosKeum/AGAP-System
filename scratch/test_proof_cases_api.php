<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

require_once __DIR__ . '/../backend/controllers/GPSController.php';

$ctrl = new GPSController();
$cases = $ctrl->cases();
echo "Cases response:\n";
echo "Success: " . ($cases['success'] ? 'true' : 'false') . "\n";
echo "Count: " . count($cases['data'] ?? []) . "\n";
if (!empty($cases['data'])) {
    echo "First case:\n";
    print_r($cases['data'][0]);
} else {
    echo "Message: " . ($cases['message'] ?? 'none') . "\n";
}
