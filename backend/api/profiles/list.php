<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/database.php'; require_once __DIR__.'/../../services/auth.php'; require_once __DIR__.'/../../services/profiles.php';
startSecureSession(); requireAdmin(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$repo=(new ProfileService($pdo))->repository();
$rows=$repo->filtered(trim((string)($_GET['q']??'')),(string)($_GET['status']??'Active'),(string)($_GET['residency']??''),trim((string)($_GET['purok']??'')),(string)($_GET['sort']??'newest'));
echo json_encode(['success'=>true,'rows'=>$rows,'metrics'=>$repo->metrics(),'puroks'=>$repo->puroks()],JSON_UNESCAPED_UNICODE);
