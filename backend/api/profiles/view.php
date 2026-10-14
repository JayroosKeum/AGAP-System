<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/database.php'; require_once __DIR__.'/../../services/auth.php'; require_once __DIR__.'/../../services/profiles.php';
startSecureSession(); requireAdmin(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT); if(!$id){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Invalid profile.']);exit;}
$repo=(new ProfileService($pdo))->repository(); $row=$repo->find($id); if(!$row){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Profile not found.']);exit;}
echo json_encode(['success'=>true,'profile'=>$row,'history'=>$repo->history($id)],JSON_UNESCAPED_UNICODE);
