<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AuditService.php';

class ComplaintScannedDocument
{
    private PDO $conn;
    private AuditService $audit;

    public const MAX_FILE_SIZE = 26214400; // 25 MB
    public const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png'
    ];
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->audit = new AuditService();
    }

    /**
     * Validates an uploaded scan file for size, MIME type, and integrity.
     */
    public function validateScanFile(array $file): ?string
    {
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return 'A valid file upload is required.';
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $isUploaded = $tmpPath !== '' && (is_uploaded_file($tmpPath) || (PHP_SAPI === 'cli' && is_file($tmpPath)));
        if (!$isUploaded) {
            return 'A valid uploaded file is required.';
        }

        $actualSize = filesize($tmpPath);
        if ($actualSize === false || $actualSize < 1 || $actualSize > self::MAX_FILE_SIZE) {
            $name = htmlspecialchars((string) ($file['name'] ?? 'file'));
            return "File '{$name}' must not exceed 25 MB.";
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED_MIME_TYPES[$mime]) || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return "Invalid file format. Only PDF, JPG, JPEG, and PNG files are accepted for handwritten KP Form 7.";
        }

        if (str_starts_with($mime, 'image/') && @getimagesize($tmpPath) === false) {
            return 'The uploaded image is invalid or corrupted.';
        }

        if ($mime === 'application/pdf') {
            $header = file_get_contents($tmpPath, false, null, 0, 5);
            if ($header !== '%PDF-') {
                return 'The uploaded PDF is invalid or corrupted.';
            }
        }

        return null;
    }

    /**
     * Uploads or replaces a scanned handwritten KP Form 7, preserving version history.
     */
    public function upload(int $complaintId, array $file, int $userId, ?string $reason = null, ?int $caseId = null): array
    {
        if ($complaintId <= 0) {
            return ['success' => false, 'message' => 'Invalid complaint ID.'];
        }

        // Check complaint exists
        $checkStmt = $this->conn->prepare('SELECT complaint_id, status FROM complaints WHERE complaint_id = ?');
        $checkStmt->execute([$complaintId]);
        if (!$checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Complaint record not found.'];
        }

        // Validate file
        $error = $this->validateScanFile($file);
        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        // Find linked case_id if not supplied
        if (!$caseId) {
            $caseStmt = $this->conn->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
            $caseStmt->execute([$complaintId]);
            $caseId = $caseStmt->fetchColumn() ?: null;
            if ($caseId) $caseId = (int) $caseId;
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        $originalExt = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $canonicalExt = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/png' ? 'png' : 'pdf');

        $originalName = trim(basename((string) ($file['name'] ?? 'kp_form_7_scan')));
        $originalName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $originalName) ?: 'kp_form_7_scan.' . $canonicalExt;

        $targetDir = dirname(__DIR__, 2) . '/storage/uploads/complaint-scans';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
            return ['success' => false, 'message' => 'Scanned document storage directory is unavailable.'];
        }

        $storedName = sprintf('KP7_%d_%s_%s.%s', $complaintId, date('Ymd_His'), bin2hex(random_bytes(6)), $canonicalExt);
        $relativePath = 'storage/uploads/complaint-scans/' . $storedName;
        $absolutePath = $targetDir . '/' . $storedName;

        $moved = (PHP_SAPI === 'cli')
            ? @copy($tmpPath, $absolutePath)
            : @move_uploaded_file($tmpPath, $absolutePath);

        if (!$moved) {
            return ['success' => false, 'message' => 'Unable to store the uploaded scan file.'];
        }

        try {
            $this->conn->beginTransaction();

            // Check current version count
            $verStmt = $this->conn->prepare('SELECT MAX(version_number) FROM complaint_scanned_documents WHERE complaint_id = ?');
            $verStmt->execute([$complaintId]);
            $maxVer = (int) $verStmt->fetchColumn();
            $newVer = $maxVer + 1;

            // Set all existing scans to is_current = 0
            if ($maxVer > 0) {
                $deprStmt = $this->conn->prepare('UPDATE complaint_scanned_documents SET is_current = 0 WHERE complaint_id = ?');
                $deprStmt->execute([$complaintId]);
            }

            // Insert new version
            $insStmt = $this->conn->prepare("
                INSERT INTO complaint_scanned_documents
                (complaint_id, case_id, document_type, file_name, file_path, file_type, file_size, version_number, is_current, replacement_reason, uploaded_by)
                VALUES (?, ?, 'KP Form 7 Scanned Complaint', ?, ?, ?, ?, ?, 1, ?, ?)
            ");
            $insStmt->execute([
                $complaintId,
                $caseId,
                $originalName,
                $relativePath,
                $mime,
                (int) filesize($absolutePath),
                $newVer,
                $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                $userId
            ]);
            $docId = (int) $this->conn->lastInsertId();

            // Update complaint document_status (separate from procedural status)
            $newDocStatus = ($newVer > 1) ? 'Updated Scan Uploaded' : 'Scan Uploaded';
            $updStatus = $this->conn->prepare('UPDATE complaints SET document_status = ? WHERE complaint_id = ?');
            $updStatus->execute([$newDocStatus, $complaintId]);

            $this->conn->commit();

            // Audit logging
            $auditAction = ($newVer > 1)
                ? "Replaced Scanned KP Form 7 (v{$newVer})"
                : "Uploaded Scanned KP Form 7 (v1)";
            $this->audit->log($userId, $auditAction, 'Complaints', $complaintId);

            return [
                'success' => true,
                'message' => ($newVer > 1) ? "Scanned KP Form 7 replaced successfully (Version {$newVer})." : "Scanned KP Form 7 uploaded successfully.",
                'doc_id' => $docId,
                'version_number' => $newVer,
                'file_name' => $originalName,
                'file_path' => $relativePath,
                'file_type' => $mime,
                'file_size' => filesize($absolutePath),
                'document_status' => $newDocStatus
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            error_log('ComplaintScannedDocument upload error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to save scanned document record.'];
        }
    }

    /**
     * Retrieves the current active scan for a complaint.
     */
    public function getCurrentByComplaint(int $complaintId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT csd.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS uploaded_by_name
            FROM complaint_scanned_documents csd
            LEFT JOIN users u ON u.user_id = csd.uploaded_by
            WHERE csd.complaint_id = ? AND csd.is_current = 1
            ORDER BY csd.version_number DESC
            LIMIT 1
        ");
        $stmt->execute([$complaintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Retrieves all version history of scans for a complaint.
     */
    public function getHistoryByComplaint(int $complaintId): array
    {
        $stmt = $this->conn->prepare("
            SELECT csd.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS uploaded_by_name
            FROM complaint_scanned_documents csd
            LEFT JOIN users u ON u.user_id = csd.uploaded_by
            WHERE csd.complaint_id = ?
            ORDER BY csd.version_number DESC
        ");
        $stmt->execute([$complaintId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves a scan document by its primary key ID.
     */
    public function getById(int $docId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT csd.*,
                   c.complaint_number,
                   c.complaint_title,
                   cs.case_number,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS uploaded_by_name
            FROM complaint_scanned_documents csd
            INNER JOIN complaints c ON c.complaint_id = csd.complaint_id
            LEFT JOIN cases cs ON cs.case_id = csd.case_id OR cs.complaint_id = csd.complaint_id
            LEFT JOIN users u ON u.user_id = csd.uploaded_by
            WHERE csd.doc_id = ?
        ");
        $stmt->execute([$docId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
