<?php

require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/ComplaintParty.php';
require_once __DIR__ . '/../models/Attachment.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class ComplaintController
{
    private $complaint;
    private $complaintParty;
    private $attachment;
    private $audit;

    public function __construct()
    {
        $this->complaint = new Complaint();
        $this->complaintParty = new ComplaintParty();
        $this->attachment = new Attachment();
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
        return $complaint;
    }

    public function store($data)
    {
        $result = $this->complaint->create($data);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Created Complaint',
                'Complaints'
            );
        }

        return $result;
    }

    public function update($id, $data)
    {
        $result = $this->complaint->update($id, $data);

        if ($result) {
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

    public function addAttachment(int $complaintId, array $file): array
    {
        if (!$this->attachment->complaintExists($complaintId)) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'A valid file upload is required.'];
        }
        if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 25 * 1024 * 1024) {
            return ['success' => false, 'message' => 'The file must not exceed 25 MB.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm'
        ];
        if (!isset($allowed[$mime])) {
            return ['success' => false, 'message' => 'Only JPG, PNG, PDF, MP4, and WebM files are allowed.'];
        }

        $originalName = trim(basename((string) ($file['name'] ?? 'attachment')));
        $originalName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $originalName) ?: 'attachment.' . $allowed[$mime];
        $storedName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $relativePath = 'storage/uploads/evidence/' . $storedName;
        $targetDirectory = dirname(__DIR__, 2) . '/storage/uploads/evidence';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0750, true) && !is_dir($targetDirectory)) {
            return ['success' => false, 'message' => 'Upload storage is unavailable.'];
        }
        $targetPath = $targetDirectory . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
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

        $base = realpath(dirname(__DIR__, 2) . '/storage/uploads/evidence');
        $path = realpath(dirname(__DIR__, 2) . '/' . $attachment['file_path']);
        if ($base !== false && $path !== false && str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            @unlink($path);
        }
        $this->audit->log((int) $_SESSION['user_id'], 'Deleted Attachment from Complaint', 'Complaints', (int) $attachment['complaint_id']);
        return ['success' => true, 'message' => 'Attachment deleted successfully.'];
    }
}
