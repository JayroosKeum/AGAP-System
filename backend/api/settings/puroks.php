<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/AuditService.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = (new Database())->connect();

// GET: List puroks
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $includeAll = isset($_GET['all']) && $_GET['all'] == '1';
    
    // Check if table exists, if not return fallback default Tumana list
    try {
        $sql = "SELECT * FROM `barangay_puroks`";
        if (!$includeAll) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, purok_name ASC";
        
        $stmt = $db->query($sql);
        $puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'puroks' => $puroks]);
        exit;
    } catch (PDOException $e) {
        // Fallback default list if table not yet migrated
        $fallback = [
            ['id' => 1, 'purok_name' => 'Purok 1', 'description' => 'Purok 1', 'is_active' => 1],
            ['id' => 2, 'purok_name' => 'Purok 2', 'description' => 'Purok 2', 'is_active' => 1],
            ['id' => 3, 'purok_name' => 'Purok 3', 'description' => 'Purok 3', 'is_active' => 1],
            ['id' => 4, 'purok_name' => 'Purok 4', 'description' => 'Purok 4', 'is_active' => 1],
            ['id' => 5, 'purok_name' => 'Purok 5', 'description' => 'Purok 5', 'is_active' => 1],
            ['id' => 6, 'purok_name' => 'Purok 6', 'description' => 'Purok 6', 'is_active' => 1],
            ['id' => 7, 'purok_name' => 'Palmera Homes', 'description' => 'Palmera Homes', 'is_active' => 1],
            ['id' => 8, 'purok_name' => 'Farmers Compound', 'description' => 'Farmers Compound', 'is_active' => 1],
            ['id' => 9, 'purok_name' => 'Twin River Subdivision', 'description' => 'Twin River', 'is_active' => 1],
            ['id' => 10, 'purok_name' => 'Riverside Area', 'description' => 'Riverside Area', 'is_active' => 1]
        ];
        echo json_encode(['success' => true, 'puroks' => $fallback, 'fallback' => true]);
        exit;
    }
}

// Ensure user is authenticated for mutations
$userId = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)($_SESSION['role_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated session.']);
    exit;
}

// POST: Add or Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;
    $action = $data['action'] ?? 'save';

    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $purokName = trim((string)($data['purok_name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;

    if ($action === 'delete') {
        $purokId = (int)($data['id'] ?? 0);
        handleDelete($db, $purokId);
        exit;
    }

    if ($purokName === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Purok or Area name is required.']);
        exit;
    }

    try {
        if ($id > 0) {
            // Update
            $stmt = $db->prepare("UPDATE `barangay_puroks` SET `purok_name` = :name, `description` = :desc, `is_active` = :active, `sort_order` = :sort WHERE `id` = :id");
            $stmt->execute([
                ':name' => $purokName,
                ':desc' => $description,
                ':active' => $isActive,
                ':sort' => $sortOrder,
                ':id' => $id
            ]);
            AuditService::log('PUROK_UPDATED', "Purok #{$id} ({$purokName}) updated by user #{$userId}");
            echo json_encode(['success' => true, 'message' => 'Purok updated successfully.']);
            exit;
        } else {
            // Insert
            $stmt = $db->prepare("INSERT INTO `barangay_puroks` (`purok_name`, `description`, `is_active`, `sort_order`) VALUES (:name, :desc, :active, :sort)");
            $stmt->execute([
                ':name' => $purokName,
                ':desc' => $description,
                ':active' => $isActive,
                ':sort' => $sortOrder
            ]);
            $newId = (int)$db->lastInsertId();
            AuditService::log('PUROK_CREATED', "New Purok #{$newId} ({$purokName}) created by user #{$userId}");
            echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Purok added successfully.']);
            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// DELETE:
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_GET;
    $purokId = (int)($data['id'] ?? 0);
    handleDelete($db, $purokId);
    exit;
}

function handleDelete(PDO $db, int $id) {
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid Purok ID.']);
        exit;
    }

    try {
        // Fetch purok name
        $nameStmt = $db->prepare("SELECT purok_name FROM `barangay_puroks` WHERE id = :id");
        $nameStmt->execute([':id' => $id]);
        $purokName = $nameStmt->fetchColumn();

        if (!$purokName) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Purok not found.']);
            exit;
        }

        // Check if any complaints reference this purok name or id
        $usageStmt = $db->prepare("SELECT COUNT(*) FROM `complaints` WHERE `incident_purok` = :name");
        $usageStmt->execute([':name' => $purokName]);
        $count = (int)$usageStmt->fetchColumn();

        if ($count > 0) {
            // Soft delete / deactivate to preserve historical integrity
            $deact = $db->prepare("UPDATE `barangay_puroks` SET `is_active` = 0 WHERE `id` = :id");
            $deact->execute([':id' => $id]);
            echo json_encode([
                'success' => true,
                'soft_deleted' => true,
                'message' => "Purok '{$purokName}' is used in {$count} complaint record(s). It has been deactivated/hidden instead of deleted to protect historical cases."
            ]);
            exit;
        } else {
            // Safe hard delete
            $del = $db->prepare("DELETE FROM `barangay_puroks` WHERE `id` = :id");
            $del->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => "Purok '{$purokName}' deleted successfully."]);
            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
