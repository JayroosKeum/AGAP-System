<?php

require_once __DIR__ . '/../config/database.php';

class Notification
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function create(int $userId, string $title, string $message): bool
    {
        $stmt = $this->conn->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
        return $stmt->execute([$userId, $title, $message]);
    }

    public function getByUser(int $userId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT notification_id, title, message, is_read, read_at, created_at
             FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC, notification_id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        $stmt = $this->conn->prepare(
            'UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE notification_id = ? AND user_id = ? AND is_read = 0'
        );
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() === 1;
    }

    public function getCaseRecipients(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT u.user_id
             FROM users u
             WHERE u.status = 'Active' AND u.user_id IN (
                SELECT member_id FROM case_assignments WHERE case_id = ?
                UNION
                SELECT pm.member_id FROM pangkat_members pm
                INNER JOIN pangkat_groups pg ON pg.pangkat_id = pm.pangkat_id
                WHERE pg.case_id = ?
             )"
        );
        $stmt->execute([$caseId, $caseId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function getRoleRecipients(array $roleNames): array
    {
        if ($roleNames === []) return [];
        $placeholders = implode(',', array_fill(0, count($roleNames), '?'));
        $stmt = $this->conn->prepare(
            "SELECT u.user_id FROM users u INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'Active' AND r.role_name IN ($placeholders)"
        );
        $stmt->execute($roleNames);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function getCaseNumber(int $caseId): ?string
    {
        $stmt = $this->conn->prepare('SELECT case_number FROM cases WHERE case_id = ?');
        $stmt->execute([$caseId]);
        $number = $stmt->fetchColumn();
        return $number === false ? null : (string) $number;
    }
}
