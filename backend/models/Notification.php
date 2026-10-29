<?php

require_once __DIR__ . '/../config/database.php';

class Notification
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function create(
        int $userId,
        string $title,
        string $message,
        string $severity = 'info',
        ?string $actionUrl = null,
        ?int $caseId = null
    ): bool {
        $allowedSeverities = ['urgent', 'warning', 'info', 'success'];
        $severity = in_array(strtolower($severity), $allowedSeverities, true) ? strtolower($severity) : $this->inferSeverity($title, $message);

        // Discard any obsolete records.php URL
        if ($actionUrl !== null && str_contains($actionUrl, 'records.php')) {
            $actionUrl = null;
        }

        // Auto-generate action URL if case ID is provided and action URL is empty
        if ($caseId !== null && $caseId > 0 && empty($actionUrl)) {
            $actionUrl = "../cases/case-details.php?id=" . $caseId;
        }

        $stmt = $this->conn->prepare('
            INSERT INTO notifications (user_id, title, severity, message, action_url, case_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        return $stmt->execute([
            $userId,
            $title,
            $severity,
            $message,
            $actionUrl ?: null,
            ($caseId !== null && $caseId > 0) ? $caseId : null
        ]);
    }

    public function getByUser(int $userId): array
    {
        $stmt = $this->conn->prepare('
            SELECT n.notification_id, n.title, n.severity, n.message, n.action_url, n.case_id,
                   n.is_read, n.read_at, n.created_at,
                   c.case_number, co.complaint_number, co.complaint_id
            FROM notifications n
            LEFT JOIN cases c ON c.case_id = n.case_id
            LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE n.user_id = ?
            ORDER BY n.is_read ASC, n.created_at DESC, n.notification_id DESC
        ');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Dynamic resolution for records where case_id or action_url may not have been explicitly set
        foreach ($rows as &$row) {
            if (empty($row['severity'])) {
                $row['severity'] = $this->inferSeverity($row['title'], $row['message']);
            }

            // If action_url points to the obsolete records.php page, clear it so it resolves directly to the case
            if (!empty($row['action_url']) && str_contains($row['action_url'], 'records.php')) {
                $row['action_url'] = null;
            }

            // If action_url is already present, ensure it is properly formatted
            if (!empty($row['action_url'])) {
                continue;
            }

            // If case_id is available, direct to case details
            if (!empty($row['case_id'])) {
                $row['action_url'] = "../cases/case-details.php?id=" . (int)$row['case_id'];
                continue;
            }

            // Attempt to resolve case from message/title patterns (e.g. "Case 10-04-2026", "Case #12")
            if (preg_match('/(?:Case|case)\s*(?:#|No\.\s*|number\s*)?([0-9A-Za-z\-_]+)/i', $row['title'] . ' ' . $row['message'], $matches)) {
                $identifier = trim($matches[1]);
                $cStmt = $this->conn->prepare('SELECT case_id FROM cases WHERE case_number = ? OR case_id = ? LIMIT 1');
                $cStmt->execute([$identifier, is_numeric($identifier) ? (int)$identifier : 0]);
                $foundCaseId = $cStmt->fetchColumn();
                if ($foundCaseId) {
                    $row['case_id'] = (int)$foundCaseId;
                    $row['action_url'] = "../cases/case-details.php?id=" . (int)$foundCaseId;
                    continue;
                }
                // Fallback direct case redirect by number (handled by case-details.php)
                $row['action_url'] = "../cases/case-details.php?case_number=" . urlencode($identifier);
                continue;
            }

            // Attempt to resolve complaint from message/title (e.g. "Complaint #12")
            if (preg_match('/(?:Complaint|complaint)\s*(?:#|No\.\s*|number\s*)?([0-9A-Za-z\-_]+)/i', $row['title'] . ' ' . $row['message'], $cMatches)) {
                $compIdentifier = trim($cMatches[1]);
                $compStmt = $this->conn->prepare('SELECT complaint_id FROM complaints WHERE complaint_number = ? OR complaint_id = ? LIMIT 1');
                $compStmt->execute([$compIdentifier, is_numeric($compIdentifier) ? (int)$compIdentifier : 0]);
                $foundCompId = $compStmt->fetchColumn();
                if ($foundCompId) {
                    $row['action_url'] = "../complaints/complaint-details.php?id=" . (int)$foundCompId;
                    continue;
                }
                $row['action_url'] = "../cases/case-details.php?case_number=" . urlencode($compIdentifier);
                continue;
            }
        }
        unset($row);

        return $rows;
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        $stmt = $this->conn->prepare('
            UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE notification_id = ? AND user_id = ?
        ');
        return $stmt->execute([$notificationId, $userId]);
    }

    public function markAllRead(int $userId): int
    {
        $stmt = $this->conn->prepare('
            UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE user_id = ? AND is_read = 0
        ');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public function markUnread(int $notificationId, int $userId): bool
    {
        $stmt = $this->conn->prepare('
            UPDATE notifications SET is_read = 0, read_at = NULL
            WHERE notification_id = ? AND user_id = ?
        ');
        return $stmt->execute([$notificationId, $userId]);
    }

    public function delete(int $notificationId, int $userId): bool
    {
        $stmt = $this->conn->prepare('
            DELETE FROM notifications
            WHERE notification_id = ? AND user_id = ?
        ');
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() === 1;
    }

    public function deleteMultiple(array $notificationIds, int $userId): int
    {
        $ids = array_values(array_filter(array_map('intval', $notificationIds), fn($i) => $i > 0));
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conn->prepare("
            DELETE FROM notifications
            WHERE notification_id IN ($placeholders) AND user_id = ?
        ");
        $params = array_merge($ids, [$userId]);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function markMultipleRead(array $notificationIds, int $userId): int
    {
        $ids = array_values(array_filter(array_map('intval', $notificationIds), fn($i) => $i > 0));
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conn->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE notification_id IN ($placeholders) AND user_id = ?
        ");
        $params = array_merge($ids, [$userId]);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function inferSeverity(string $title, string $message): string
    {
        $combined = strtolower($title . ' ' . $message);
        if (
            str_contains($combined, 'overdue') ||
            str_contains($combined, 'urgent') ||
            str_contains($combined, 'breached') ||
            str_contains($combined, 'cancelled') ||
            str_contains($combined, 'emergency') ||
            str_contains($combined, 'lapsed') ||
            str_contains($combined, 'sanction') ||
            str_contains($combined, 'failed')
        ) {
            return 'urgent';
        }
        if (
            str_contains($combined, 'due soon') ||
            str_contains($combined, 'warning') ||
            str_contains($combined, 'standby') ||
            str_contains($combined, 'deadline') ||
            str_contains($combined, 'unjustified') ||
            str_contains($combined, 'rescheduled') ||
            str_contains($combined, 'delay')
        ) {
            return 'warning';
        }
        if (
            str_contains($combined, 'success') ||
            str_contains($combined, 'settled') ||
            str_contains($combined, 'amicable') ||
            str_contains($combined, 'resolved') ||
            str_contains($combined, 'completed')
        ) {
            return 'success';
        }
        return 'info';
    }

    public function getCaseRecipients(int $caseId): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT u.user_id
            FROM users u
            WHERE u.status = 'Active' AND u.user_id IN (
                SELECT member_id FROM case_assignments WHERE case_id = ?
                UNION
                SELECT pm.member_id FROM pangkat_members pm
                INNER JOIN pangkat_groups pg ON pg.pangkat_id = pm.pangkat_id
                WHERE pg.case_id = ?
            )
        ");
        $stmt->execute([$caseId, $caseId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function getRoleRecipients(array $roleNames): array
    {
        if ($roleNames === []) return [];
        $placeholders = implode(',', array_fill(0, count($roleNames), '?'));
        $stmt = $this->conn->prepare("
            SELECT u.user_id FROM users u INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.status = 'Active' AND r.role_name IN ($placeholders)
        ");
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
