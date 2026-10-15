<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-AGAP-Deprecated: true');

http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'This legacy workflow endpoint has been retired.',
    'replacement' => $replacementEndpoint ?? null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
