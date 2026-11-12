<?php

declare(strict_types=1);

session_start();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId < 1 || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    exit('Only the Lupon Clerk or Administrator may access Paanyaya preparation.');
}

require_once __DIR__ . '/../../services/PaanyayaPreparationService.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$input = $method === 'POST' ? $_POST : $_GET;
$id = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    exit('A valid intake is required.');
}

$service = new PaanyayaPreparationService((new Database())->connect());
if ($method === 'GET') {
    try {
        $pdf = $service->document($id);
        if ($pdf === null) {
            http_response_code(404);
            exit('Generate the invitation before opening the PDF.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Paanyaya-Intake-' . $id . '.pdf"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
    } catch (Throwable $exception) {
        error_log('Paanyaya preparation PDF: ' . $exception->getMessage());
        http_response_code(500);
        echo 'Unable to open the prepared invitation.';
    }
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$flash = ['type' => 'success', 'complaint_id' => $id];
try {
    $flash['message'] = $service->save($id, $userId, $action, $_POST);
} catch (DomainException $exception) {
    $flash['type'] = 'error';
    $flash['message'] = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Paanyaya preparation: ' . $exception->getMessage());
    $flash['type'] = 'error';
    $flash['message'] = 'Unable to save the invitation. Please try again.';
}

if ($flash['type'] === 'error' && $action === 'generate') {
    foreach (['appointment_date', 'appointment_time', 'venue'] as $field) {
        if (is_string($_POST[$field] ?? null)) {
            $flash['old'][$field] = mb_substr($_POST[$field], 0, 255);
        }
    }
}
$_SESSION['paanyaya_preparation_flash'] = $flash;
header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . $id . '#paanyaya-preparation', true, 303);
