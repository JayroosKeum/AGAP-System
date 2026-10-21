<?php

require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../services/AuditService.php';

class NotificationController
{
    private Notification $notification;
    private AuditService $audit;

    public function __construct()
    {
        $this->notification = new Notification();
        $this->audit = new AuditService();
    }

    public function inbox(int $userId): array
    {
        return [
            'success' => true,
            'data' => $this->notification->getByUser($userId),
            'unread_count' => $this->notification->unreadCount($userId)
        ];
    }

    public function read(int $notificationId, int $userId): array
    {
        if ($notificationId < 1) return ['success' => false, 'message' => 'A valid notification is required.'];
        if (!$this->notification->markRead($notificationId, $userId)) {
            return ['success' => false, 'message' => 'Notification not found, already read, or unavailable.'];
        }
        $this->audit->log($userId, 'Read Notification', 'Notifications', $notificationId);
        return ['success' => true, 'message' => 'Notification marked as read.'];
    }

    public function markAllRead(int $userId): array
    {
        $count = $this->notification->markAllRead($userId);
        if ($count > 0) {
            $this->audit->log($userId, 'Marked all notifications as read', 'Notifications', $userId);
        }
        return [
            'success' => true,
            'message' => "Marked {$count} notification(s) as read.",
            'affected_count' => $count
        ];
    }

    public function unread(int $notificationId, int $userId): array
    {
        if ($notificationId < 1) return ['success' => false, 'message' => 'A valid notification is required.'];
        if (!$this->notification->markUnread($notificationId, $userId)) {
            return ['success' => false, 'message' => 'Notification not found or unavailable.'];
        }
        $this->audit->log($userId, 'Marked Notification as Unread', 'Notifications', $notificationId);
        return ['success' => true, 'message' => 'Notification marked as unread.'];
    }

    public function delete(int $notificationId, int $userId): array
    {
        if ($notificationId < 1) return ['success' => false, 'message' => 'A valid notification is required.'];
        if (!$this->notification->delete($notificationId, $userId)) {
            return ['success' => false, 'message' => 'Notification not found or could not be removed.'];
        }
        $this->audit->log($userId, 'Deleted Notification', 'Notifications', $notificationId);
        return ['success' => true, 'message' => 'Notification deleted.'];
    }

    public function deleteMultiple(array $notificationIds, int $userId): array
    {
        $count = $this->notification->deleteMultiple($notificationIds, $userId);
        if ($count < 1) {
            return ['success' => false, 'message' => 'No notifications were found or deleted.'];
        }
        $this->audit->log($userId, 'Deleted Multiple Notifications', 'Notifications', 0);
        return ['success' => true, 'message' => "Successfully deleted {$count} notifications."];
    }

    public function markMultipleRead(array $notificationIds, int $userId): array
    {
        $count = $this->notification->markMultipleRead($notificationIds, $userId);
        if ($count < 1) {
            return ['success' => false, 'message' => 'No notifications were found or updated.'];
        }
        $this->audit->log($userId, 'Marked Multiple Notifications Read', 'Notifications', 0);
        return ['success' => true, 'message' => "Successfully marked {$count} notifications as read."];
    }
}
