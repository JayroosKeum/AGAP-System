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
        $sent = 0;

        // =====================================================================
        // 1. STATUTORY CASE DEADLINES (15-Day Mediation, Pangkat Convening, etc.)
        // =====================================================================
        $stmt = $this->conn->query("
            SELECT d.deadline_id, d.case_id, d.deadline_type, d.due_date,
                   CASE WHEN d.due_date < CURDATE() THEN 'Overdue' ELSE 'Due Soon' END AS alert_type,
                   DATEDIFF(d.due_date, CURDATE()) AS days_diff
            FROM case_deadlines d
            WHERE d.status <> 'Completed'
              AND d.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $deadline) {
            $log = $this->conn->prepare('INSERT IGNORE INTO deadline_alert_log (deadline_id, alert_type) VALUES (?, ?)');
            $log->execute([(int) $deadline['deadline_id'], $deadline['alert_type']]);
            if ($log->rowCount() !== 1) continue;

            $caseId = (int) $deadline['case_id'];
            $caseNumber = $this->notifications->caseNumber($caseId);
            $actionUrl = "../cases/case-details.php?id=" . $caseId;
            $dueDateFmt = date('F j, Y', strtotime($deadline['due_date']));
            $isOverdue = $deadline['alert_type'] === 'Overdue';
            $severity = $isOverdue ? 'urgent' : 'warning';

            if ($deadline['deadline_type'] === 'Pangkat Convening') {
                $title = $isOverdue ? '⚠️ Pangkat Convening Overdue' : '⏰ Pangkat Convening Due Soon';
                $urgency = $isOverdue ? 'OVERDUE' : 'due within 3 days';
                $message = sprintf('Case %s: Under RA 7160 Sec. 410(c), Pangkat must convene no later than 3 days from constitution. Convening deadline is %s (%s). Schedule the first conciliation session immediately.', $caseNumber, $urgency, $dueDateFmt);
            } elseif ($deadline['deadline_type'] === '15-Day Mediation Deadline' || $deadline['deadline_type'] === 'Mediation Period') {
                $title = $isOverdue ? '⚠️ 15-Day Mediation Deadline Overdue' : '⏰ 15-Day Mediation Deadline Due Soon';
                $urgency = $isOverdue ? 'OVERDUE' : 'due within 3 days';
                $message = sprintf('Case %s: Under RA 7160 Sec. 410(b), the 15-day statutory mediation period is %s (%s). Convene Pangkat Tagapagkasundo or execute amicable settlement.', $caseNumber, $urgency, $dueDateFmt);
            } elseif (str_contains($deadline['deadline_type'], 'Summon')) {
                $title = $isOverdue ? '⚠️ Summons Delivery Overdue' : '⏰ Summons Delivery SLA Due Soon';
                $days = abs((int) $deadline['days_diff']);
                $message = sprintf('Case %s: Summons service is %s (%s). Please verify service status.', $caseNumber, $isOverdue ? "{$days} day(s) overdue" : "due soon", $dueDateFmt);
            } else {
                $title = $isOverdue ? "⚠️ {$deadline['deadline_type']} Overdue" : "⏰ {$deadline['deadline_type']} Due Soon";
                $message = sprintf('%s for %s is %s (%s).', $deadline['deadline_type'], $caseNumber, $isOverdue ? 'overdue' : 'due within 3 days', $dueDateFmt);
            }

            $this->notifications->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $message, $actorUserId, $severity, $actionUrl, $caseId);
            $this->notifications->notifyCaseMembers($caseId, $title, $message, $actorUserId, $severity, $actionUrl);
            $sent++;
        }

        // =====================================================================
        // 2. 10-DAY REPUDIATION EXPIRY (FINALITY & ENFORCEABILITY)
        // =====================================================================
        $repStmt = $this->conn->prepare("
            SELECT s.settlement_id, s.case_id, s.repudiation_deadline, c.case_number
            FROM settlements s
            INNER JOIN cases c ON c.case_id = s.case_id
            WHERE s.complainant_signed = 1
              AND s.respondent_signed = 1
              AND s.pb_attested = 1
              AND s.repudiation_status = 'Within Repudiation Period'
              AND s.repudiation_deadline IS NOT NULL
              AND s.repudiation_deadline < CURDATE()
        ");
        $repStmt->execute();
        $repExpired = $repStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($repExpired as $settle) {
            $sId = (int) $settle['settlement_id'];
            $cId = (int) $settle['case_id'];
            $cNum = $settle['case_number'];
            $actionUrl = "../cases/case-details.php?id=" . $cId;

            // Transition repudiation_status so notification is sent once
            $this->conn->prepare("UPDATE settlements SET repudiation_status = 'Repudiation Expired', updated_at = NOW() WHERE settlement_id = ?")->execute([$sId]);

            $title = "Amicable Settlement Finality Reached";
            $message = "Amicable settlement for {$cNum} has passed the 10-day repudiation window without repudiation. Settlement has the force and effect of a final court judgment (RA 7160 Sec. 418).";

            $this->notifications->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $message, $actorUserId, 'success', $actionUrl, $cId);
            $this->notifications->notifyCaseMembers($cId, $title, $message, $actorUserId, 'success', $actionUrl);
            $sent++;
        }

        // =====================================================================
        // 3. OVERDUE SUMMONS (> 3 DAYS UNSERVED)
        // =====================================================================
        $sumStmt = $this->conn->prepare("
            SELECT sd.delivery_id, sd.case_id, sd.served_by_user_id, sd.created_at,
                   DATEDIFF(CURDATE(), DATE(sd.created_at)) AS days_unserved,
                   c.case_number
            FROM summon_deliveries sd
            INNER JOIN cases c ON c.case_id = sd.case_id
            WHERE sd.delivery_status IN ('Pending', 'Unserved')
              AND DATEDIFF(CURDATE(), DATE(sd.created_at)) >= 3
        ");
        $sumStmt->execute();
        foreach ($sumStmt->fetchAll(PDO::FETCH_ASSOC) as $sd) {
            $cId = (int) $sd['case_id'];
            $cNum = $sd['case_number'];
            $daysUnserved = (int) $sd['days_unserved'];
            $serverUid = !empty($sd['served_by_user_id']) ? (int) $sd['served_by_user_id'] : null;

            // Check if warning already sent within 24 hours to avoid spamming
            $chk = $this->conn->prepare("
                SELECT COUNT(*) FROM notifications
                WHERE case_id = ? AND title = '⚠️ Summons Delivery Warning' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
            $chk->execute([$cId]);
            if ((int) $chk->fetchColumn() > 0) {
                continue;
            }

            $this->notifications->notifySummonsDeliveryWarning($cId, $cNum, $daysUnserved, $serverUid);
            $sent++;
        }

        return $sent;
    }
}
