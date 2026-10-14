<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/auth.php';
require_once __DIR__ . '/../../services/profiles.php';
startSecureSession();
requireAdmin();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed.']); exit; }
if (!hash_equals($_SESSION['profiles_csrf'] ?? '', (string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }
$result=(new ProfileService($pdo))->save($_POST,(int)$_SESSION['admin_id']);
if (!$result['ok']) { http_response_code(422); echo json_encode(['success'=>false,'message'=>$result['errors']['duplicate'] ?? $result['errors']['general'] ?? 'Please correct the highlighted fields.','errors'=>$result['errors']],JSON_UNESCAPED_UNICODE); exit; }
echo json_encode(['success'=>true,'message'=>'Profile created successfully.','id'=>$result['id']],JSON_UNESCAPED_UNICODE);
