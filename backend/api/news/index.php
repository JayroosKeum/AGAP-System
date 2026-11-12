<?php

require_once __DIR__ . '/../../services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../models/News.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$db = (new Database())->connect();
$newsModel = new News($db);
$canManage = agapHasRole(['Administrator', 'Secretary', 'Lupon Clerk'], $db);
$actorUserId = agapSessionUserId();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $scope = strtolower(trim((string) ($_GET['scope'] ?? 'published')));
        $includeArchived = ($scope === 'all' && $canManage);
        $limit = max(1, min(30, (int) ($_GET['limit'] ?? ($includeArchived ? 30 : 6))));

        echo json_encode([
            'success' => true,
            'can_manage' => $canManage,
            'news' => $newsModel->listNews($includeArchived, $limit),
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('news GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to load barangay news.']);
        exit;
    }
}

CsrfService::enforceForApiRequest();
agapRequireApiRoles(['Administrator', 'Secretary', 'Lupon Clerk'], $db);

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$action = strtolower(trim((string) ($input['action'] ?? 'create')));
$audit = new AuditService($db);

try {
    if ($action === 'create') {
        $res = $newsModel->create($input, $actorUserId);
        $audit->log($actorUserId, 'Published Barangay News: ' . ($res['item']['title'] ?? ''), 'Announcements', (int) $res['news_id']);
        echo json_encode([
            'success' => true,
            'message' => 'Barangay news item published.',
            'item' => $res['item'],
        ]);
        exit;
    }

    if ($action === 'update') {
        $newsId = (int) ($input['news_id'] ?? 0);
        $res = $newsModel->update($newsId, $input);
        $audit->log($actorUserId, 'Updated Barangay News #' . $newsId, 'Announcements', $newsId);
        echo json_encode([
            'success' => true,
            'message' => 'Barangay news item updated.',
            'item' => $res['item'],
        ]);
        exit;
    }

    if ($action === 'toggle_pin') {
        $newsId = (int) ($input['news_id'] ?? 0);
        $existing = $newsModel->findById($newsId);
        if (!$existing) {
            throw new InvalidArgumentException('News item not found.');
        }
        $nextPin = !empty($existing['is_pinned']) ? 0 : 1;
        $res = $newsModel->setField($newsId, 'is_pinned', $nextPin);
        $audit->log($actorUserId, ($nextPin ? 'Pinned' : 'Unpinned') . ' Barangay News #' . $newsId, 'Announcements', $newsId);
        echo json_encode([
            'success' => true,
            'message' => $nextPin ? 'News pinned to top.' : 'News unpinned.',
            'item' => $res['item'],
        ]);
        exit;
    }

    if ($action === 'toggle_status') {
        $newsId = (int) ($input['news_id'] ?? 0);
        $existing = $newsModel->findById($newsId);
        if (!$existing) {
            throw new InvalidArgumentException('News item not found.');
        }
        $nextStatus = ($existing['status'] ?? 'Published') === 'Published' ? 'Archived' : 'Published';
        $res = $newsModel->setField($newsId, 'status', $nextStatus);
        $audit->log($actorUserId, $nextStatus . ' Barangay News #' . $newsId, 'Announcements', $newsId);
        echo json_encode([
            'success' => true,
            'message' => $nextStatus === 'Published' ? 'News item published.' : 'News item archived.',
            'item' => $res['item'],
        ]);
        exit;
    }

    if ($action === 'delete') {
        $newsId = (int) ($input['news_id'] ?? 0);
        $res = $newsModel->delete($newsId);
        if (!$res['success']) {
            throw new InvalidArgumentException('News item not found.');
        }
        $audit->log($actorUserId, 'Deleted Barangay News #' . $newsId, 'Announcements', $newsId);
        echo json_encode([
            'success' => true,
            'message' => 'News item deleted.',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid news action.']);
    exit;
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    error_log('news POST error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process barangay news update.']);
    exit;
}
