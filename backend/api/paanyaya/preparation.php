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

$rawBody = $method === 'POST' ? file_get_contents('php://input') : '';
$jsonBody = ($method === 'POST' && is_string($rawBody) && trim($rawBody) !== '') ? json_decode($rawBody, true) : null;
$isJsonRequest = is_array($jsonBody) || (($_POST['response_mode'] ?? '') === 'json');
$input = $method === 'POST' ? (is_array($jsonBody) ? $jsonBody : $_POST) : $_GET;
$id = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$number = filter_var($input['invitation_number'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2]]);
if (!$id || !$number) {
    http_response_code(400);
    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'A valid intake is required.']);
    } else {
        exit('A valid intake is required.');
    }
    exit;
}

$db = (new Database())->connect();
$service = new PaanyayaPreparationService($db);
if ($method === 'GET') {
    $version = $_GET['version'] ?? null;
    if ($version !== null && (!is_string($version) || !preg_match('/\A[a-f0-9]{64}\z/', $version))) {
        http_response_code(400);
        exit('A valid invitation version is required.');
    }
    header('Cache-Control: private, no-store');
    try {
        $pdf = $service->document($id, $version, $number);
        if ($pdf === null) {
            http_response_code(404);
            exit('Generate the invitation before opening the PDF.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Paanyaya-Intake-' . $id . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
    } catch (DomainException $exception) {
        http_response_code(409);
        echo $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Paanyaya preparation PDF: ' . $exception->getMessage());
        http_response_code(500);
        echo 'Unable to open the prepared invitation.';
    }
    exit;
}

$action = (string) ($input['action'] ?? '');
if ($action === 'render_preview') {
    header('Cache-Control: private, no-store');
    try {
        require_once __DIR__ . '/../../services/PDFService.php';
        $parties = $service->parties($id);
        $complainants = [];
        $respondents = [];
        foreach ($parties as $party) {
            if (($party['party_type'] ?? '') === 'Complainant') {
                $complainants[] = $party;
            } elseif (($party['party_type'] ?? '') === 'Respondent') {
                $respondents[] = $party;
            }
        }
        $renderer = new PDFService($db);
        $data = $renderer->loadPaanyayaData($id, [
            'attempt_number' => $number,
            'issued_date' => date('Y-m-d'),
            'invitation_date' => trim((string) ($input['appointment_date'] ?? '')),
            'invitation_time' => trim((string) ($input['appointment_time'] ?? '')),
            'venue' => trim((string) ($input['venue'] ?? '')),
            'invitee_name' => trim((string) ($input['respondent_display'] ?? $input['invitee_name'] ?? '')) ?: implode(' / ', array_column($respondents, 'full_name')),
            'has_e_signature' => false,
        ]);
        if ($complainants) $data['complainants'] = $complainants;
        if ($respondents) $data['respondents'] = $respondents;
        $data['intake_reference'] = $data['complaint']['intake_reference'] ?? '';
        foreach ([
            'doc_title',
            'complainant_display',
            'complainant_address',
            'respondent_address',
            'salutation',
            'salaysay',
            'body_paragraph_1',
            'body_paragraph_2',
            'body_paragraph_3',
            'closing_phrase',
            'captain_name',
        ] as $optKey) {
            if (is_string($input[$optKey] ?? null) && trim($input[$optKey]) !== '') {
                $data[$optKey] = mb_substr(trim($input[$optKey]), 0, 4000);
            }
        }
        $html = $renderer->renderPaanyayaHtml($id, ['data' => $data]);
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unable to render invitation preview.']);
    }
    exit;
}

$flash = ['type' => 'success', 'complaint_id' => $id, 'invitation_number' => $number];
$saveDetails = null;
try {
    $saveDetails = $service->saveWithDetails($id, $userId, $action, $input, $number);
    $flash['message'] = $saveDetails['message'];
} catch (DomainException $exception) {
    $flash['type'] = 'error';
    $flash['message'] = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Paanyaya preparation: ' . $exception->getMessage());
    $flash['type'] = 'error';
    $flash['message'] = 'Unable to save the invitation. Please try again.';
}

if ($isJsonRequest) {
    header('Content-Type: application/json; charset=utf-8');
    if ($flash['type'] === 'error') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $flash['message']]);
    } else {
        $docVer = (string) ($saveDetails['document_version'] ?? '');
        echo json_encode([
            'success' => true,
            'message' => $flash['message'],
            'attempt_id' => (int) ($saveDetails['attempt_id'] ?? 0),
            'server_id' => (int) ($saveDetails['server_id'] ?? 0),
            'server_name' => (string) ($saveDetails['server_name'] ?? ''),
            'document_version' => $docVer,
            'pdf_url' => $docVer !== ''
                ? ('../../../backend/api/paanyaya/preparation.php?complaint_id=' . $id . '&invitation_number=' . $number . '&version=' . urlencode($docVer))
                : '',
        ]);
    }
    exit;
}

if ($flash['type'] === 'error' && $action === 'generate') {
    foreach (['appointment_date', 'appointment_time', 'venue'] as $field) {
        if (is_string($input[$field] ?? null)) {
            $flash['old'][$field] = mb_substr($input[$field], 0, 255);
        }
    }
}
$_SESSION['paanyaya_preparation_flash'] = $flash;
header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . $id . '#paanyaya-preparation', true, 303);
