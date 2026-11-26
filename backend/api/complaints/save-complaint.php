<?php

declare(strict_types=1);

// Retired: all complaint writes must use the validated, authorized intake flow.
// No application caller uses this legacy endpoint. Never accept old payloads
// here: redirecting/replaying them could bypass screening, routing or ownership.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
    'success' => false,
    'code' => 'LEGACY_COMPLAINT_SAVE_RETIRED',
    'message' => 'This complaint-save endpoint has been retired. Open the current complaint intake or edit page and submit again.',
]);
