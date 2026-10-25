<?php

require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../services/AuditService.php';

class NotificationController
{
    private ?Notification $notification = null;
    private ?AuditService $audit = null;

    public function __construct()
    {
        try {
            $this->notification = new Notification();
            $this->audit = new AuditService();
        } catch (Throwable $e) {
            error_log("NotificationController construct error: " . $e->getMessage());
        }
    }

    private function getNotificationModel(): Notification
    {
        if ($this->notification === null) {
            $this->notification = new Notification();
        }
        return $this->notification;
    }

    private function getAuditService(): AuditService
    {
        if ($this->audit === null) {
            $this->audit = new AuditService();
        }
        return $this->audit;
    }

    public function inbox(int $userId): array
    {
        try {
            $model = $this->getNotificationModel();
            $data = $model->getByUser($userId);
            $unreadCount = $model->unreadCount($userId);

            return [
                'status' => 'success',
                'success' => true,
                'data' => $data,
                'unread_count' => $unreadCount
            ];
        } catch (Throwable $e) {
            error_log("NotificationController inbox error: " . $e->getMessage());
            return [
                'status' => 'error',
                'success' => false,
                'message' => 'Unable to retrieve notifications at this time. Please try again.',
                'error_detail' => $e->getMessage(),
                'data' => [],
                'unread_count' => 0
            ];
        }
    }

    public function read(int $notificationId, int $userId): array
    {
        if ($notificationId < 1) {
            return ['status' => 'error', 'success' => false, 'message' => 'A valid notification is required.'];
        }

        try {
            $model = $this->getNotificationModel();
            if (!$model->markRead($notificationId, $userId)) {
                return ['status' => 'error', 'success' => false, 'message' => 'Notification not found, already read, or unavailable.'];
            }

            try {
                $this->getAuditService()->log($userId, 'Read Notification', 'Notifications', $notificationId);
            } catch (Throwable $at) {
                error_log("Audit log failed for read notification: " . $at->getMessage());
            }

            return ['status' => 'success', 'success' => true, 'message' => 'Notification marked as read.'];
        } catch (Throwable $e) {
            error_log("NotificationController read error: " . $e->getMessage());
            return ['status' => 'error', 'success' => false, 'message' => 'Unable to mark notification as read.', 'error_detail' => $e->getMessage()];
        }
    }

    public function markAllRead(int $userId): array
    {
        try {
            $model = $this->getNotificationModel();
            $count = $model->markAllRead($userId);

            if ($count > 0) {
                try {
                    $this->getAuditService()->log($userId, 'Marked all notifications as read', 'Notifications', $userId);
                } catch (Throwable $at) {
                    error_log("Audit log failed for markAllRead: " . $at->getMessage());
                }
            }

            return [
                'status' => 'success',
                'success' => true,
                'message' => "Marked {$count} notification(s) as read.",
                'affected_count' => $count
            ];
        } catch (Throwable $e) {
            error_log("NotificationController markAllRead error: " . $e->getMessage());
            return ['status' => 'error', 'success' => false, 'message' => 'Unable to mark all notifications as read.', 'error_detail' => $e->getMessage()];
        }
    }

    public function unread(int $notificationId, int $userId): array
    {
        if ($notificationId < 1) {
            return ['status' => 'error', 'success' => false, 'message' => 'A valid notification is required.'];
        }

        try {
            $model = $this->getNotificationModel();
            if (!$model->markUnread($notificationId, $userId)) {
                return ['status' => 'error', 'success' => false, 'message' => 'Notification not found or unavailable.'];
            }

            try {
                $this->getAuditService()->log($userId, 'Marked Notification as Unread', 'Notifications', $notificationId);
            } catch (Throwable $at) {
                error_log("Audit log failed for mark unread: " . $at->getMessage());
            }

            return ['status' => 'success', 'success' => true, 'message' => 'Notification marked as unread.'];
        } catch (Throwable $e) {
            error_log("NotificationController unread error: " . $e->getMessage());
            return ['status' => 'error', 'success' => false, 'message' => 'Unable to mark notification as unread.', 'error_detail' => $e->getMessage()];
        }
    }

    public function delete(int $notificationId, int $userId): array
    {
        if ($notificationId < 1) {
            return ['status' => 'error', 'success' => false, 'message' => 'A valid notification is required.'];
        }

        try {
            $model = $this->getNotificationModel();
            if (!$model->delete($notificationId, $userId)) {
                return ['status' => 'error', 'success' => false, 'message' => 'Notification not found or could not be removed.'];
            }

            try {
                $this->getAuditService()->log($userId, 'Deleted Notification', 'Notifications', $notificationId);
            } catch (Throwable $at) {
                error_log("Audit log failed for delete notification: " . $at->getMessage());
            }

            return ['status' => 'success', 'success' => true, 'message' => 'Notification deleted.'];
        } catch (Throwable $e) {
            error_log("NotificationController delete error: " . $e->getMessage());
            return ['status' => 'error', 'success' => false, 'message' => 'Unable to delete notification.', 'error_detail' => $e->getMessage()];
        }
    }

    public function deleteMultiple(array $notificationIds, int $userId): array
    {
        try {
            $model = $this->getNotificationModel();
            $count = $model->deleteMultiple($notificationIds, $userId);

            if ($count < 1) {
                return ['status' => 'error', 'success' => false, 'message' => 'No notifications were found or deleted.'];
            }

            try {
                $this->getAuditService()->log($userId, 'Deleted Multiple Notifications', 'Notifications', 0);
            } catch (Throwable $at) {
                error_log("Audit log failed for deleteMultiple: " . $at->getMessage());
            }

            return ['status' => 'success', 'success' => true, 'message' => "Successfully deleted {$count} notifications."];
        } catch (Throwable $e) {
            error_log("NotificationController deleteMultiple error: " . $e->getMessage());
            return ['status' => 'error', 'success' => false, 'message' => 'Unable to delete notifications.', 'error_detail' => $e->getMessage()];
        }
    }

    public function markMultipleRead(array $notificationIds, int $userId): array
    {
        try {
            $model = $this->getNotificationModel();
            $count = $model->markMultipleRead($notificationIds, $userId);

            if ($count < 1) {
                return ['status' => 'error', 'success' => false, 'message' => 'No notifications were found or updated.'];
            }

            try {
                $this->getAuditService()->log($userId, 'Marked Multiple Notifications Read', 'Notifications', 0);
            } catch (Throwable $at) {
                error_log("Audit log failed for markMultipleRead: " . $at->getMessage());
            }

            return ['status' => 'success', 'success' => true, 'message' => "Successfully marked {$count} notifications as read."];
        } catch (Throwable $e) {
            error_log("NotificationController markMultipleRead error: " . $e->getMessage());
            return ['status' => 'error', 'success' => false, 'message' => 'Unable to mark notifications as read.', 'error_detail' => $e->getMessage()];
        }
    }
}
