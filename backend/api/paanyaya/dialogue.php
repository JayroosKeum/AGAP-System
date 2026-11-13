<?php

declare(strict_types=1);

session_start();
$actor = (int) ($_SESSION['user_id'] ?? 0);
if ($actor < 1 || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    exit('Only the Lupon Clerk or Administrator may record dialogue outcomes here.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

require_once __DIR__ . '/../../models/Paanyaya.php';

$id = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$number = filter_var($_POST['invitation_number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2]]);
$action = $_POST['action'] ?? '';
if (!$id || !$number || !in_array($action, ['minutes', 'docket'], true)) {
    http_response_code(400);
    exit('A valid complaint, invitation, and dialogue action are required.');
}

$db = (new Database())->connect();
if (!PaanyayaPreparationService::managesDialogue($db, $id)) {
    http_response_code(404);
    exit('This complaint has no prepared Paanyaya invitation.');
}

$model = new Paanyaya($db);
$result = $action === 'docket'
    ? $model->escalateToFormalDocketing($id, $actor)
    : $model->recordMinutes($id, 'Paanyaya ' . $number, $_POST, $actor);
$old = [];
if (!$result['success'] && $action === 'minutes') {
    foreach (['dialogue_date', 'complainant_attended', 'respondent_attended', 'outcome', 'discussion_summary', 'agreed_terms'] as $field) {
        if (is_string($_POST[$field] ?? null)) {
            $old[$field] = mb_substr($_POST[$field], 0, 10000);
        }
    }
}
$_SESSION['paanyaya_preparation_flash'] = [
    'complaint_id' => $id,
    'invitation_number' => $number,
    'type' => $result['success'] ? 'success' : 'error',
    'message' => $result['message'],
    'old' => $old,
];
$target = match (true) {
    $action === 'docket' && $result['success'] => '&tab=mediation',
    ($result['action'] ?? '') === 'request_paanyaya_2' => '#paanyaya-invitation-2',
    default => '#paanyaya-dialogue-' . $number,
};
header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . $id . $target, true, 303);
