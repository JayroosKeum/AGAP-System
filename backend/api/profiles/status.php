<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/auth.php';
require_once __DIR__ . '/../../services/profiles.php';
startSecureSession(); requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
if (!hash_equals($_SESSION['profiles_csrf'] ?? '', (string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); exit('Invalid request.'); }
$id=filter_input(INPUT_POST,'profile_id',FILTER_VALIDATE_INT); $status=(string)($_POST['status'] ?? '');
if (!$id) { http_response_code(422); exit('Invalid profile.'); }
try { (new ProfileService($pdo))->changeStatus($id,$status,(int)$_SESSION['admin_id']); $_SESSION['profile_flash']=$status==='Archived'?'Profile archived.':'Profile reactivated.'; }
catch(Throwable $e) { $_SESSION['profile_flash']=$e->getMessage(); }
header('Location: ../../../index.php?page=profiles&action=view&id='.$id); exit;
