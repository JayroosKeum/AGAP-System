<?php

require_once __DIR__ . '/../config/database.php';

class Attachment
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function complaintExists(int $complaintId): bool
    {
        $stmt = $this->conn->prepare('SELECT 1 FROM complaints WHERE complaint_id = ?');
        $stmt->execute([$complaintId]);
        return (bool) $stmt->fetchColumn();
    }

    public function create(int $complaintId, string $fileName, string $filePath, string $fileType): array
    {
        if (!$this->complaintExists($complaintId)) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO complaint_attachments (complaint_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$complaintId, $fileName, $filePath, $fileType]);

        return [
            'success' => true,
            'attachment_id' => (int) $this->conn->lastInsertId(),
            'message' => 'Attachment uploaded successfully.'
        ];
    }

    public function getByComplaint(int $complaintId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT attachment_id, complaint_id, file_name, file_type, uploaded_at
             FROM complaint_attachments WHERE complaint_id = ? ORDER BY uploaded_at DESC'
        );
        $stmt->execute([$complaintId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $attachmentId): array|false
    {
        $stmt = $this->conn->prepare(
            'SELECT attachment_id, complaint_id, file_name, file_path, file_type, uploaded_at
             FROM complaint_attachments WHERE attachment_id = ?'
        );
        $stmt->execute([$attachmentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function delete(int $attachmentId): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM complaint_attachments WHERE attachment_id = ?');
        $stmt->execute([$attachmentId]);
        return $stmt->rowCount() === 1;
    }
}
