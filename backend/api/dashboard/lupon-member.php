    <?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../controllers/LuponDashboardController.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Ensure user is signed in as Lupon Member (3) or Admin (1) / Clerk (2) for preview
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRoleId = (int) ($_SESSION['role_id'] ?? 0);

if ($currentUserId < 1 || !in_array($currentRoleId, [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hindi awtorisado para sa Lupon Member dashboard.']);
    exit;
}

// Allow admin/clerk to view a specific member's dashboard if provided via GET, otherwise current user
$targetUserId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT) ?: $currentUserId;
if ($currentRoleId === 3) {
    $targetUserId = $currentUserId;
}

try {
    $controller = new LuponDashboardController();

    // Check if dossier requested
    $action = $_GET['action'] ?? '';
    if ($action === 'dossier') {
        $caseId = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT);
        if (!$caseId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Walang tinukoy na Case ID.']);
            exit;
        }
        $dossier = $controller->getCaseDossier((int) $caseId, $targetUserId);
        echo json_encode(['success' => true, 'data' => $dossier]);
        exit;
    }

    $data = $controller->getDashboardData($targetUserId);
    echo json_encode(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    error_log('Lupon Member Dashboard API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Nabigo sa pagkuha ng datos ng Lupon Member: ' . $e->getMessage()]);
}
