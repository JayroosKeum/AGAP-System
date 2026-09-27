<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/NotificationService.php';

class DeadlineAlertService
{
    private PDO $conn;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
        $this->notifications = new NotificationService();
    }

    public function dispatch(int $actorUserId): int
    {
        $stmt = $this->conn->query("SELECT d.deadline_id, d.case_id, d.deadline_type, d.due_date, CASE WHEN d.due_date < CURDATE() THEN 'Overdue' ELSE 'Due Soon' END AS alert_type FROM case_deadlines d WHERE d.status <> 'Completed' AND d.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $deadline) {
            $log = $this->conn->prepare('INSERT IGNORE INTO deadline_alert_log (deadline_id, alert_type) VALUES (?, ?)');
            $log->execute([(int) $deadline['deadline_id'], $deadline['alert_type']]);
            if ($log->rowCount() !== 1) continue;
            $caseNumber = $this->notifications->caseNumber((int) $deadline['case_id']);
            $title = $deadline['alert_type'] === 'Overdue' ? 'Deadline overdue' : 'Deadline due soon';
            $message = sprintf('%s for %s is %s (%s).', $deadline['deadline_type'], $caseNumber, $deadline['alert_type'] === 'Overdue' ? 'overdue' : 'due within 3 days', date('F j, Y', strtotime($deadline['due_date'])));
            $this->notifications->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $message, $actorUserId);
            $this->notifications->notifyCaseMembers((int) $deadline['case_id'], $title, $message, $actorUserId);
            $sent++;
        }
        return $sent;
    }
}
