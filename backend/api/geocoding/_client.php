<?php
declare(strict_types=1);
function nominatimGet(string $url, string $cacheKey, int $ttl): array {
    $cacheDir=__DIR__ . '/../../../storage/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir,0775,true);
    $cacheFile=$cacheDir . '/' . $cacheKey . '.json';
    if (is_file($cacheFile) && time()-filemtime($cacheFile)<$ttl) {
        $cached=json_decode((string)file_get_contents($cacheFile),true);
        if (is_array($cached)) return $cached;
    }
    $context=stream_context_create(['http'=>['method'=>'GET','timeout'=>8,'header'=>"User-Agent: AGAP-Remastered/1.0 (Barangay Tumana profile verification)
Accept: application/json
"]]);
    $raw=@file_get_contents($url,false,$context);
    if ($raw===false) throw new RuntimeException('Unable to reach the address lookup service.');
    $data=json_decode($raw,true);
    if (!is_array($data)) throw new RuntimeException('Invalid address lookup response.');
    @file_put_contents($cacheFile,json_encode($data,JSON_UNESCAPED_UNICODE));
    return $data;
}
