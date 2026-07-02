<?php

require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class NotificationController
{
    private $notification;
    private $audit;

    public function __construct()
    {
        $this->notification = new Notification();
        $this->audit = new AuditService();
    }

    public function send(
        $userId,
        $title,
        $message
    )
    {
        $result = $this->notification->create(
            $userId,
            $title,
            $message
        );

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Sent Notification',
                'Notifications'
            );
        }

        return $result;
    }

    public function read($id)
    {
        $result = $this->notification->markRead($id);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Read Notification',
                'Notifications',
                $id
            );
        }

        return $result;
    }
}