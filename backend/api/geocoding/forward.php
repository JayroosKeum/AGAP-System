<?php
declare(strict_types=1);
require_once __DIR__ . '/../../services/auth.php';
startSecureSession(); requireAdmin(); header('Content-Type: application/json; charset=utf-8');
$query=trim((string)($_GET['q']??''));
if($query===''){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Address is required.']);exit;}
if(stripos($query,'Tumana')===false)$query.=', Barangay Tumana';
if(stripos($query,'Marikina')===false)$query.=', Marikina City';
$url='https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=ph&q='.rawurlencode($query);
$raw=false;
if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_USERAGENT=>'AGAP-Remastered/1.0 Barangay Tumana Profile Registry',CURLOPT_HTTPHEADER=>['Accept: application/json','Accept-Language: en']]);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($code!==200)$raw=false;}
if($raw===false&&filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)){$ctx=stream_context_create(['http'=>['timeout'=>10,'header'=>"User-Agent: AGAP-Remastered/1.0 Barangay Tumana Profile Registry\r\nAccept: application/json\r\n"]]);$raw=@file_get_contents($url,false,$ctx);}
$data=$raw!==false?json_decode($raw,true):[];
if(!is_array($data)||empty($data[0])){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Address could not be located. Move the pin manually.']);exit;}
echo json_encode(['success'=>true,'latitude'=>(float)$data[0]['lat'],'longitude'=>(float)$data[0]['lon']],JSON_UNESCAPED_UNICODE);
