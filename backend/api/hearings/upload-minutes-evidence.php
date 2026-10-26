<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$db = (new Database())->connect();
$userId = (int) $_SESSION['user_id'];

// GET: Retrieve uploaded documents for a hearing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT);
    if (!$hearingId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Hearing ID is required.']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT id, hearing_id, case_id, document_type, file_name, file_path, file_size, mime_type, created_at
        FROM hearing_minutes_documents
        WHERE hearing_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$hearingId]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $docs]);
    exit;
}

// POST: Upload document or Delete document
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'upload'));

    if ($action === 'delete') {
        $docId = filter_input(INPUT_POST, 'document_id', FILTER_VALIDATE_INT);
        if (!$docId) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Valid document ID is required.']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM hearing_minutes_documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($doc) {
            $absPath = dirname(__DIR__, 2) . '/' . ltrim($doc['file_path'], '/');
            if (file_exists($absPath)) {
                @unlink($absPath);
            }
            $delStmt = $db->prepare("DELETE FROM hearing_minutes_documents WHERE id = ?");
            $delStmt->execute([$docId]);
        }

        echo json_encode(['success' => true, 'message' => 'Document removed successfully.']);
        exit;
    }

    // Upload handling
    $hearingId = filter_input(INPUT_POST, 'hearing_id', FILTER_VALIDATE_INT);
    $caseId = filter_input(INPUT_POST, 'case_id', FILTER_VALIDATE_INT);
    $docType = trim((string) ($_POST['document_type'] ?? 'evidence_attachment'));
    if (!in_array($docType, ['handwritten_minutes', 'evidence_attachment'], true)) {
        $docType = 'evidence_attachment';
    }

    if (!$hearingId || !$caseId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Valid hearing ID and case ID are required.']);
        exit;
    }

    if (empty($_FILES['file']) || !is_array($_FILES['file']['name'])) {
        // Single file format
        $uploadedFiles = !empty($_FILES['file']) ? [$_FILES['file']] : [];
    } else {
        // Multiple files array format
        $uploadedFiles = [];
        $count = count($_FILES['file']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['file']['error'][$i] === UPLOAD_ERR_OK) {
                $uploadedFiles[] = [
                    'name' => $_FILES['file']['name'][$i],
                    'type' => $_FILES['file']['type'][$i],
                    'tmp_name' => $_FILES['file']['tmp_name'][$i],
                    'error' => $_FILES['file']['error'][$i],
                    'size' => $_FILES['file']['size'][$i],
                ];
            }
        }
    }

    if (empty($uploadedFiles)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No valid file uploaded.']);
        exit;
    }

    $uploadDir = dirname(__DIR__, 2) . '/storage/hearing-documents/' . $caseId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $savedDocs = [];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'];

    foreach ($uploadedFiles as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) continue;
        if ($file['size'] > 10 * 1024 * 1024) continue; // 10MB limit

        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) continue;

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $originalName);
        $newFilename = time() . '_' . uniqid() . '_' . $safeName . '.' . $ext;
        $destPath = $uploadDir . '/' . $newFilename;
        $relativePath = 'storage/hearing-documents/' . $caseId . '/' . $newFilename;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $insStmt = $db->prepare("
                INSERT INTO hearing_minutes_documents
                (hearing_id, case_id, document_type, file_name, file_path, file_size, mime_type, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $mime = $file['type'] ?: mime_content_type($destPath);
            $insStmt->execute([
                $hearingId,
                $caseId,
                $docType,
                $file['name'],
                $relativePath,
                $file['size'],
                $mime,
                $userId
            ]);
            $newId = (int)$db->lastInsertId();

            $savedDocs[] = [
                'id' => $newId,
                'hearing_id' => $hearingId,
                'case_id' => $caseId,
                'document_type' => $docType,
                'file_name' => $file['name'],
                'file_path' => $relativePath,
                'file_size' => $file['size'],
                'mime_type' => $mime,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'message' => count($savedDocs) . ' file(s) uploaded successfully.',
        'data' => $savedDocs
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
