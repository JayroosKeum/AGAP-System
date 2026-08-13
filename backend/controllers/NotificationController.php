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
        return ['success' => true, 'data' => $this->notification->getByUser($userId), 'unread_count' => $this->notification->unreadCount($userId)];
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
}
