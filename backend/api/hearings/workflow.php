<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../models/HearingWorkflow.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed.']); exit; }
if (!isset($_SESSION['user_id'],$_SESSION['role_id'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
$data=$_POST;
if (str_contains($_SERVER['CONTENT_TYPE']??'','application/json')) $data=json_decode(file_get_contents('php://input'),true)?:[];
$result=(new HearingWorkflow())->act(trim((string)($data['action']??'')),$data,(int)$_SESSION['user_id'],(int)$_SESSION['role_id']);
http_response_code($result['success']?201:($result['status']??422)); unset($result['status']); echo json_encode($result);
