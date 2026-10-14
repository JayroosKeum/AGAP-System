<?php
declare(strict_types=1);
require_once __DIR__ . '/../../services/auth.php';
startSecureSession();
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
$lng = filter_input(INPUT_GET, 'lng', FILTER_VALIDATE_FLOAT);
if ($lat === false || $lng === false || $lat < 14.62 || $lat > 14.69 || $lng < 121.05 || $lng > 121.12) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid map coordinates.']);
    exit;
}

$cacheDir = __DIR__ . '/../../../storage/cache/geocoding';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/' . sha1(round($lat, 6) . ',' . round($lng, 6)) . '.json';
if (is_file($cacheFile) && time() - filemtime($cacheFile) < 2592000) {
    readfile($cacheFile);
    exit;
}

$url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&lat=' . rawurlencode((string)$lat) . '&lon=' . rawurlencode((string)$lng);
$raw = false;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en'],
        CURLOPT_USERAGENT => 'AGAP-Remastered/1.0 Barangay Tumana Profile Registry'
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code !== 200) $raw = false;
}
if ($raw === false && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    $context = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: AGAP-Remastered/1.0 Barangay Tumana Profile Registry\r\nAccept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $context);
}
if ($raw === false) {
    echo json_encode(['success' => true, 'address' => 'Barangay Tumana, Marikina City', 'purok' => '', 'lookup_unavailable' => true]);
    exit;
}
$data = json_decode($raw, true);
$a = is_array($data) ? ($data['address'] ?? []) : [];
$road = $a['road'] ?? $a['pedestrian'] ?? $a['residential'] ?? $a['footway'] ?? '';
$house = $a['house_number'] ?? '';
$zone = $a['neighbourhood'] ?? $a['quarter'] ?? $a['suburb'] ?? '';
$first = trim($house . ' ' . $road);
$parts = array_values(array_unique(array_filter([$first, $zone, 'Barangay Tumana', 'Marikina City'])));
$result = ['success' => true, 'address' => implode(', ', $parts), 'purok' => $zone];
$json = json_encode($result, JSON_UNESCAPED_UNICODE);
@file_put_contents($cacheFile, $json);
echo $json;
