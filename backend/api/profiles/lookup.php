<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/auth.php';
startSecureSession(); requireAdmin(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$q=trim((string)($_GET['q']??''));
$sql="SELECT profile_id,profile_no,first_name,middle_name,last_name,suffix,contact_no,address_line,purok_sitio,residency_status FROM profiles WHERE status='Active'";
$params=[];
if($q!==''){$sql.=" AND (profile_no LIKE :q OR CONCAT_WS(' ',first_name,middle_name,last_name,suffix) LIKE :q OR address_line LIKE :q)";$params['q']='%'.$q.'%';}
$sql.=' ORDER BY last_name,first_name LIMIT 30';$stmt=$pdo->prepare($sql);$stmt->execute($params);echo json_encode(['success'=>true,'profiles'=>$stmt->fetchAll()],JSON_UNESCAPED_UNICODE);
