<?php

require_once __DIR__ . '/../models/Notification.php';

class NotificationService
{
    private Notification $notification;

    public function __construct()
    {
        $this->notification = new Notification();
    }

    public function notifyUser(int $userId, string $title, string $message, ?int $excludeUserId = null): void
    {
        try {
            if ($userId > 0 && $userId !== $excludeUserId) {
                $this->notification->create($userId, $title, $message);
            }
        } catch (Throwable $exception) {
            error_log('Notification delivery failed: ' . $exception->getMessage());
        }
    }

    public function notifyCaseMembers(int $caseId, string $title, string $message, ?int $excludeUserId = null): void
    {
        try {
            foreach ($this->notification->getCaseRecipients($caseId) as $userId) {
                $this->notifyUser($userId, $title, $message, $excludeUserId);
            }
        } catch (Throwable $exception) {
            error_log('Notification recipient lookup failed: ' . $exception->getMessage());
        }
    }

    public function notifyRoles(array $roles, string $title, string $message, ?int $excludeUserId = null): void
    {
        try {
            foreach ($this->notification->getRoleRecipients($roles) as $userId) {
                $this->notifyUser($userId, $title, $message, $excludeUserId);
            }
        } catch (Throwable $exception) {
            error_log('Notification role lookup failed: ' . $exception->getMessage());
        }
    }

    public function caseNumber(int $caseId): string
    {
        return $this->notification->getCaseNumber($caseId) ?? ('case #' . $caseId);
    }
}
