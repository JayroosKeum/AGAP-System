<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/auth.php';
require_once __DIR__ . '/../../services/profiles.php';
startSecureSession(); requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
if (!hash_equals($_SESSION['profiles_csrf'] ?? '', (string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); exit('Invalid request.'); }
$id=filter_input(INPUT_POST,'profile_id',FILTER_VALIDATE_INT) ?: null;
$result=(new ProfileService($pdo))->save($_POST,(int)$_SESSION['admin_id'],$id);
if (!$result['ok']) { $_SESSION['profile_form_errors']=$result['errors']; $_SESSION['profile_form_data']=$result['data']; header('Location: ../../../index.php?page=profiles&action=form'.($id?'&id='.$id:'')); exit; }
unset($_SESSION['profile_form_errors'],$_SESSION['profile_form_data']);
$_SESSION['profile_flash']=$id?'Profile updated.':'Profile created.';
header('Location: ../../../index.php?page=profiles&action=view&id='.$result['id']); exit;
