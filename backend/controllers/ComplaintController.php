<?php

require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/ComplaintParty.php';
require_once __DIR__ . '/../models/Attachment.php';
require_once __DIR__ . '/../models/Location.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class ComplaintController
{
    private $complaint;
    private $complaintParty;
    private $attachment;
    private $location;
    private $audit;

    public function __construct()
    {
        $this->complaint = new Complaint();
        $this->complaintParty = new ComplaintParty();
        $this->attachment = new Attachment();
        $this->location = new Location();
        $this->audit = new AuditService();
    }

    public function index()
    {
        return $this->complaint->getAll();
    }

    public function show($id)
    {
        $complaint = $this->complaint->getById($id);
        if (!$complaint) return false;
        $complaint['parties'] = $this->complaintParty->getByComplaint($id);
        $complaint['attachments'] = $this->attachment->getByComplaint($id);
        $complaint['location'] = $this->location->getByComplaint((int) $id);
        return $complaint;
    }

    public function store(array $data, array $files = []): array
    {
        $evidenceFiles = [];
        if (!empty($files['evidence'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['evidence']);
        } elseif (!empty($files['attachments'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['attachments']);
        }

        foreach ($evidenceFiles as $file) {
            $validationError = $this->validateAttachmentFile($file);
            if ($validationError !== null) {
                return ['success' => false, 'message' => $validationError];
            }
        }

        $result = $this->complaint->create($data);

        if ($result['success']) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Created Complaint',
                'Complaints',
                $result['complaint_id'] ?? null
            );

            if (!empty($evidenceFiles) && !empty($result['complaint_id'])) {
                $complaintId = (int) $result['complaint_id'];
                $uploadedCount = 0;
                foreach ($evidenceFiles as $file) {
                    $attachResult = $this->addAttachment($complaintId, $file);
                    if ($attachResult['success']) {
                        $uploadedCount++;
                    }
                }
                if ($uploadedCount > 0) {
                    $result['message'] .= " ($uploadedCount attachment(s) uploaded.)";
                }
            }
        }

        return $result;
    }

    public function update($id, $data, array $files = []): array
    {
        $evidenceFiles = [];
        if (!empty($files['evidence'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['evidence']);
        } elseif (!empty($files['attachments'])) {
            $evidenceFiles = $this->normalizeFilesArray($files['attachments']);
        } elseif (!empty($_FILES['evidence'])) {
            $evidenceFiles = $this->normalizeFilesArray($_FILES['evidence']);
        }

        foreach ($evidenceFiles as $file) {
            $validationError = $this->validateAttachmentFile($file);
            if ($validationError !== null) {
                return ['success' => false, 'message' => $validationError];
            }
        }

        $result = $this->complaint->update($id, $data);

        if ($result['success']) {
            $complaintId = (int) $id;
            if (!empty($evidenceFiles) && $complaintId > 0) {
                $uploadedCount = 0;
                foreach ($evidenceFiles as $file) {
                    $attachResult = $this->addAttachment($complaintId, $file);
                    if ($attachResult['success']) {
                        $uploadedCount++;
                    }
                }
                if ($uploadedCount > 0) {
                    $result['message'] .= " ($uploadedCount attachment(s) uploaded.)";
                }
            }

            $this->audit->log(
                $_SESSION['user_id'],
                'Updated Complaint',
                'Complaints',
                $id
            );
        }

        return $result;
    }

    public function review(int $id, array $data): array
    {
        $status = trim((string) ($data['status'] ?? ''));
        $notes = trim((string) ($data['review_notes'] ?? ''));
        if ($id < 1 || mb_strlen($notes) > 2000) {
            return ['success' => false, 'message' => 'Provide valid review notes of 2,000 characters or fewer.'];
        }
        $result = $this->complaint->review($id, $status, $notes !== '' ? $notes : null);
        if ($result['success']) {
            $this->audit->log((int) $_SESSION['user_id'], 'Reviewed Complaint: ' . $status, 'Complaints', $id);
        }
        return $result;
    }

    public function destroy($id)
    {
        $result = $this->complaint->delete($id);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Deleted Complaint',
                'Complaints',
                $id
            );
        }

        return $result;
    }

    public function addParty(int $complaintId, int $residentId, string $partyType): array
    {
        $result = $this->complaintParty->create($complaintId, $residentId, $partyType);
        if ($result['success']) {
            $this->audit->log((int) $_SESSION['user_id'], 'Added Party to Complaint', 'Complaints', $complaintId);
        }
        return $result;
    }
    public function deleteParty(int $partyId): array
    {
        $party = $this->complaintParty->getById($partyId);
        if (!$party) {
            return ['success' => false, 'message' => 'Complaint party not found.'];
        }
        if (!$this->complaintParty->delete($partyId)) {
            return ['success' => false, 'message' => 'Unable to remove complaint party.'];
        }
        $this->audit->log((int) $_SESSION['user_id'], 'Removed Party from Complaint', 'Complaints', (int) $party['complaint_id']);
        return ['success' => true, 'message' => 'Complaint party removed successfully.'];
    }

    public function getParties(int $complaintId): array
    {
        return $this->complaintParty->getByComplaint($complaintId);
    }

    public function normalizeFilesArray(array $files): array
    {
        $normalized = [];
        if (!isset($files['name'])) {
            return $normalized;
        }

        if (is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($error === UPLOAD_ERR_NO_FILE || empty($files['name'][$i])) {
                    continue;
                }
                $normalized[] = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $error,
                    'size' => (int) ($files['size'][$i] ?? 0)
                ];
            }
        } else {
            $error = (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_NO_FILE && !empty($files['name'])) {
                $normalized[] = $files;
            }
        }

        return $normalized;
    }

    public function validateAttachmentFile(array $file): ?string
    {
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return 'A valid file upload is required.';
        }
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $isUploaded = $temporaryPath !== '' && (is_uploaded_file($temporaryPath) || (PHP_SAPI === 'cli' && is_file($temporaryPath)));
        if (!$isUploaded) {
            return 'A valid file upload is required.';
        }
        $actualSize = filesize($temporaryPath);
        if ($actualSize === false || $actualSize < 1 || $actualSize > 25 * 1024 * 1024
            || (int) ($file['size'] ?? -1) !== $actualSize) {
            $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
            return "File '{$fileName}' must not exceed 25 MB.";
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporaryPath);
        $allowed = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
            'video/mp4' => ['mp4'],
            'video/webm' => ['webm'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/zip' => ['docx'],
            'application/x-zip' => ['docx'],
            'application/x-zip-compressed' => ['docx'],
            'application/octet-stream' => ['doc', 'docx']
        ];
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset($allowed[$mime]) || !in_array($extension, $allowed[$mime], true)) {
            $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
            return "File '{$fileName}' has an invalid format. Allowed formats: JPG, PNG, GIF, WebP, MP4, WebM, PDF, DOC, DOCX.";
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($temporaryPath) === false) {
            $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
            return "The uploaded image '{$fileName}' is invalid or corrupted.";
        }
        if ($mime === 'application/pdf' && file_get_contents($temporaryPath, false, null, 0, 5) !== '%PDF-') {
            $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
            return "The uploaded PDF '{$fileName}' is invalid or corrupted.";
        }
        if ($extension === 'docx') {
            if (file_get_contents($temporaryPath, false, null, 0, 2) !== 'PK') {
                $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
                return "The uploaded DOCX file '{$fileName}' is invalid or corrupted.";
            }
        }
        if ($extension === 'doc') {
            if (substr(file_get_contents($temporaryPath, false, null, 0, 4), 0, 4) !== "\xD0\xCF\x11\xE0") {
                $fileName = htmlspecialchars((string) ($file['name'] ?? 'file'));
                return "The uploaded DOC file '{$fileName}' is invalid or corrupted.";
            }
        }

        return null;
    }

    public function addAttachment(int $complaintId, array $file): array
    {
        if (!$this->attachment->complaintExists($complaintId)) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        $error = $this->validateAttachmentFile($file);
        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporaryPath);
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        $originalName = trim(basename((string) ($file['name'] ?? 'attachment')));
        $originalName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $originalName) ?: 'attachment.' . $extension;
        $storedExtension = $mime === 'image/jpeg' ? 'jpg' : $extension;
        $storedName = bin2hex(random_bytes(16)) . '.' . $storedExtension;
        $relativePath = 'storage/uploads/complaint-evidence/' . $storedName;
        $targetDirectory = dirname(__DIR__, 2) . '/storage/uploads/complaint-evidence';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0750, true) && !is_dir($targetDirectory)) {
            return ['success' => false, 'message' => 'Upload storage is unavailable.'];
        }
        $targetPath = $targetDirectory . '/' . $storedName;
        $moved = PHP_SAPI === 'cli'
            ? @copy($file['tmp_name'], $targetPath)
            : @move_uploaded_file($file['tmp_name'], $targetPath);
        if (!$moved) {
            return ['success' => false, 'message' => 'Unable to store the uploaded file.'];
        }

        try {
            $result = $this->attachment->create($complaintId, $originalName, $relativePath, $mime);
            if (!$result['success']) {
                @unlink($targetPath);
                return $result;
            }
        } catch (Throwable $exception) {
            @unlink($targetPath);
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to save the attachment record.'];
        }

        $this->audit->log((int) $_SESSION['user_id'], 'Added Attachment to Complaint', 'Complaints', $complaintId);
        return $result;
    }
    public function getAttachments(int $complaintId): array
    {
        return $this->attachment->getByComplaint($complaintId);
    }

    public function getAttachment(int $attachmentId): array|false
    {
        return $this->attachment->getById($attachmentId);
    }

    public function deleteAttachment(int $attachmentId): array
    {
        $attachment = $this->attachment->getById($attachmentId);
        if (!$attachment) {
            return ['success' => false, 'message' => 'Attachment not found.'];
        }
        if (!$this->attachment->delete($attachmentId)) {
            return ['success' => false, 'message' => 'Unable to delete the attachment.'];
        }

        $base1 = realpath(dirname(__DIR__, 2) . '/storage/uploads/complaint-evidence');
        $base2 = realpath(dirname(__DIR__, 2) . '/storage/uploads/evidence');
        $path = realpath(dirname(__DIR__, 2) . '/' . $attachment['file_path']);
        if ($path !== false && is_file($path)) {
            if (($base1 !== false && str_starts_with($path, $base1 . DIRECTORY_SEPARATOR)) ||
                ($base2 !== false && str_starts_with($path, $base2 . DIRECTORY_SEPARATOR))) {
                @unlink($path);
            }
        }
        $this->audit->log((int) $_SESSION['user_id'], 'Deleted Attachment from Complaint', 'Complaints', (int) $attachment['complaint_id']);
        return ['success' => true, 'message' => 'Attachment deleted successfully.'];
    }
}
