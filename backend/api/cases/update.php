<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../config/database.php';
require_once '../../middleware/rbac.php';
require_once '../../controllers/CaseController.php';
require_once __DIR__ . '/../../services/CsrfService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (!CsrfService::expectsJson()) {
        CsrfService::redirectBrowser('../../../frontend/pages/cases/case-list.php');
    }
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk'], $db);

$caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($caseId) {
    agapRequireCaseAccess($db, (int) $caseId, 'write', true);
}

$result = (new CaseController())->update($_POST['case_id'] ?? null, $_POST);
http_response_code(!empty($result['success']) ? 200 : 422);
echo json_encode($result);
