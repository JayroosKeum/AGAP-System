<?php

require_once __DIR__ . '/../config/database.php';

class Notification
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function create(
        $userId,
        $title,
        $message
    )
    {
        $stmt = $this->conn->prepare("
            INSERT INTO notifications
            (
                user_id,
                title,
                message
            )
            VALUES
            (
                ?,?,?
            )
        ");

        return $stmt->execute([
            $userId,
            $title,
            $message
        ]);
    }

    public function markRead($id)
    {
        $stmt = $this->conn->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE notification_id = ?
        ");

        return $stmt->execute([$id]);
    }
}