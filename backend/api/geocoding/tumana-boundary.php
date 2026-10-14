<?php
declare(strict_types=1);
require_once __DIR__ . '/../../services/auth.php'; require_once __DIR__ . '/_client.php';
startSecureSession(); requireAdmin(); header('Content-Type: application/json; charset=utf-8');
try {
 $url='https://nominatim.openstreetmap.org/search?format=jsonv2&polygon_geojson=1&limit=1&countrycodes=ph&q='.rawurlencode('Barangay Tumana, Marikina City, Philippines');
 $data=nominatimGet($url,'tumana_boundary',86400*30); $geometry=$data[0]['geojson']??null;
 if (!$geometry) throw new RuntimeException('Barangay boundary unavailable.');
 echo json_encode(['success'=>true,'geometry'=>$geometry],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) { http_response_code(502); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
