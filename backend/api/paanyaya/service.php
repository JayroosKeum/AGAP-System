<?php

declare(strict_types=1);

session_start();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if ($userId < 1 || !in_array($roleId, [1, 2, 4], true)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/../../services/PaanyayaServiceDelivery.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'GET' ? $_GET : $_POST;
$attemptId = filter_var($input['attempt_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$db = (new Database())->connect();
$service = new PaanyayaServiceDelivery($db);

if ($method === 'GET') {
    try {
        $attempt = $attemptId ? $service->findAttempt($attemptId, $roleId === 4 ? $userId : null) : null;
        if (!$attempt) {
            http_response_code(404);
            exit('Service assignment not found.');
        }
        if (($input['view'] ?? '') === 'invitation') {
            $pdf = $service->invitationPdf($attemptId);
            if ($pdf !== null) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="Paanyaya.pdf"');
                echo $pdf;
                exit;
            }
        }
        if (($input['view'] ?? '') === 'proof' && $attempt['proof_mime']) {
            $proof = $service->proof($attemptId);
            if ($proof !== null) {
                header('Content-Type: ' . $attempt['proof_mime']);
                echo $proof;
                exit;
            }
        }
        http_response_code(404);
        echo 'The requested document is unavailable.';
    } catch (DomainException $exception) {
        http_response_code(409);
        echo $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Paanyaya service document: ' . $exception->getMessage());
        http_response_code(500);
        echo 'Unable to open the document.';
    }
    exit;
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$isRelease = in_array($roleId, [1, 2], true) && ($input['action'] ?? '') === 'release' && $complaintId;
$isReturn = $roleId === 4 && ($input['action'] ?? '') === 'record' && $attemptId;
if (!$isRelease && !$isReturn) {
    http_response_code(403);
    exit('This service action is unavailable.');
}

$flash = ['type' => 'success'];
try {
    if ($isRelease) {
        $serverId = filter_var($input['assigned_to'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $version = is_string($input['document_version'] ?? null) ? $input['document_version'] : '';
        $number = filter_var($input['invitation_number'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2]]);
        if (!$number) {
            throw new DomainException('Select a valid Paanyaya invitation.');
        }
        $service->release($complaintId, $userId, $serverId, $version, $number);
        $flash['message'] = 'Paanyaya assigned for delivery.';
    } else {
        $service->record($attemptId, $userId, $input, $_FILES['proof_image'] ?? []);
        $flash['message'] = 'Delivery result recorded.';
    }
} catch (DomainException $exception) {
    $flash = ['type' => 'error', 'message' => $exception->getMessage()];
} catch (Throwable $exception) {
    error_log('Paanyaya service: ' . $exception->getMessage());
    $flash = ['type' => 'error', 'message' => 'Unable to save the service action. Please try again.'];
}

if ($isRelease) {
    $_SESSION['paanyaya_preparation_flash'] = $flash + ['complaint_id' => $complaintId, 'invitation_number' => $number ?: 1];
    header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . $complaintId . '#paanyaya-preparation', true, 303);
} else {
    $_SESSION['paanyaya_service_flash'] = $flash;
    header('Location: ../../../frontend/pages/gps/proof-service.php#paanyaya-service', true, 303);
}
