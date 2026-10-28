<?php

require_once __DIR__ . '/../models/Notification.php';

class NotificationService
{
    private Notification $notification;

    public function __construct()
    {
        $this->notification = new Notification();
    }

    public function notifyUser(int $userId, string $title, string $message, ?int $excludeUserId = null, ?string $severity = null, ?string $actionUrl = null, ?int $caseId = null): void
    {
        try {
            if ($userId > 0 && $userId !== $excludeUserId) {
                $this->notification->create($userId, $title, $message, $severity ?? 'info', $actionUrl, $caseId);
            }
        } catch (Throwable $exception) {
            error_log('Notification delivery failed: ' . $exception->getMessage());
        }
    }

    public function notifyCaseMembers(int $caseId, string $title, string $message, ?int $excludeUserId = null, ?string $severity = null, ?string $actionUrl = null): void
    {
        try {
            foreach ($this->notification->getCaseRecipients($caseId) as $userId) {
                $this->notifyUser($userId, $title, $message, $excludeUserId, $severity, $actionUrl, $caseId);
            }
        } catch (Throwable $exception) {
            error_log('Notification recipient lookup failed: ' . $exception->getMessage());
        }
    }

    public function notifyRoles(array $roles, string $title, string $message, ?int $excludeUserId = null, ?string $severity = null, ?string $actionUrl = null, ?int $caseId = null): void
    {
        try {
            foreach ($this->notification->getRoleRecipients($roles) as $userId) {
                $this->notifyUser($userId, $title, $message, $excludeUserId, $severity, $actionUrl, $caseId);
            }
        } catch (Throwable $exception) {
            error_log('Notification role lookup failed: ' . $exception->getMessage());
        }
    }

    // =========================================================================
    // 1. PAANYAYA (PRE-DOCKETING) NOTIFICATIONS
    // =========================================================================
    public function notifyPaanyayaOutcome(int $complaintId, string $complaintNum, string $outcome, ?int $actorUserId = null): void
    {
        $actionUrl = "../complaints/paanyaya-workspace.php?id=" . $complaintId;
        if ($outcome === 'Settled') {
            $title = "Paanyaya Settled (LTIA Win)";
            $msg = "Complaint {$complaintNum} was amicably settled at the Paanyaya dialogue level.";
            $severity = 'success';
        } else if ($outcome === 'Escalate_Mediation') {
            $title = "Paanyaya Escalated to Formal Mediation";
            $msg = "Complaint {$complaintNum} failed pre-docketing agreement and was escalated to formal Tuesday mediation.";
            $severity = 'warning';
        } else {
            $title = "Paanyaya Session Updated";
            $msg = "Dialogue notes recorded for {$complaintNum}. Outcome: {$outcome}.";
            $severity = 'info';
        }

        $this->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $msg, $actorUserId, $severity, $actionUrl);
    }

    // =========================================================================
    public function notifyPaanyayaServed(
        int $complaintId,
        string $complaintNum,
        string $stageType,
        string $recipientName,
        string $serverName,
        bool $isDelivered,
        string $outcome,
        ?int $actorUserId = null
    ): void {
        $actionUrl = "../complaints/complaint-details.php?id=" . $complaintId . "&tab=paanyaya";

        if ($isDelivered) {
            $title = "{$stageType} Successfully Delivered";
            $msg = "Ang {$stageType} para sa reklamo {$complaintNum} ay matagumpay na naihatid kay {$recipientName} ni {$serverName}. Handa na para sa takdang pag-uusap.";
            $severity = 'success';
        } else {
            $title = "{$stageType} Delivery Alert: " . str_replace('_', ' ', $outcome);
            $msg = "Hindi naihatid ang {$stageType} para sa reklamo {$complaintNum} kay {$recipientName} (Dahilan: {$outcome}). Suriin ang return para sa susunod na aksyon.";
            $severity = 'warning';
        }

        // Abisuhan ang Administrator at Lupon Clerk
        $this->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $msg, $actorUserId, $severity, $actionUrl);
    }

    // 2. SUMMONS & PROCESS SERVING (TANOD) NOTIFICATIONS
    // =========================================================================
    public function notifySummonsDispatched(int $caseId, string $caseNum, int $serverUserId, string $respondentName, ?int $actorUserId = null): void
    {
        $actionUrl = "../cases/case-details.php?id=" . $caseId;
        // Notify the designated summons officer (Tanod)
        $this->notifyUser(
            $serverUserId,
            "New Summons Assigned for Delivery",
            "You have been assigned to serve KP Form 9 for {$caseNum} to {$respondentName}. Please observe statutory delivery SLA.",
            $actorUserId,
            'info',
            $actionUrl,
            $caseId
        );
    }

    public function notifySummonsServed(int $caseId, string $caseNum, string $recipientName, string $serverName, ?int $actorUserId = null): void
    {
        $actionUrl = "../cases/case-details.php?id=" . $caseId;
        $title = "Summons Served Successfully";
        $msg = "Summons for {$caseNum} was served to {$recipientName} by {$serverName}. Proof of service logged.";
        
        $this->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $msg, $actorUserId, 'success', $actionUrl, $caseId);
        $this->notifyCaseMembers($caseId, $title, $msg, $actorUserId, 'success', $actionUrl);
    }

    public function notifySummonsDeliveryWarning(int $caseId, string $caseNum, int $daysUnserved, ?int $serverUserId = null): void
    {
        $actionUrl = "../cases/case-details.php?id=" . $caseId;
        $title = "Summons Delivery Warning";
        $msg = "Summons for {$caseNum} has been unserved for {$daysUnserved} days. Please verify respondent address or execute 2nd attempt.";

        $this->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $msg, null, 'warning', $actionUrl, $caseId);
        if ($serverUserId) {
            $this->notifyUser($serverUserId, $title, $msg, null, 'warning', $actionUrl, $caseId);
        }
    }

    // =========================================================================
    // 3. HEARINGS & UNJUSTIFIED NON-APPEARANCE (SHOW-CAUSE)
    // =========================================================================
    public function notifyNonAppearanceRecorded(int $caseId, string $caseNum, string $partyType, string $partyName, ?int $actorUserId = null): void
    {
        $actionUrl = "../cases/case-details.php?id=" . $caseId;
        $title = "Non-Appearance: {$partyType}";
        $msg = "{$partyType} {$partyName} failed to appear at the mediation hearing for {$caseNum} without justification. 3-day show-cause period initiated.";

        $this->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $msg, $actorUserId, 'urgent', $actionUrl, $caseId);
        $this->notifyCaseMembers($caseId, $title, $msg, $actorUserId, 'urgent', $actionUrl);
    }

    // =========================================================================
    // 4. SETTLEMENT, REPUDIATION, AND BREACH NOTIFICATIONS
    // =========================================================================
    public function notifySettlementApproved(int $caseId, string $caseNum, ?int $actorUserId = null): void
    {
        $actionUrl = "../cases/case-details.php?id=" . $caseId;
        $title = "Amicable Settlement (Kasunduan) Executed";
        $msg = "Amicable settlement signed for {$caseNum}. 10-day statutory repudiation window is now active.";

        $this->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $msg, $actorUserId, 'success', $actionUrl, $caseId);
        $this->notifyCaseMembers($caseId, $title, $msg, $actorUserId, 'success', $actionUrl);
    }

    public function notifySettlementRepudiated(int $caseId, string $caseNum, string $repudiatingParty, string $grounds, ?int $actorUserId = null): void
    {
        $actionUrl = "../cases/case-details.php?id=" . $caseId;
        $title = "URGENT: Settlement Repudiated (KP 17)";
        $msg = "Party {$repudiatingParty} filed a sworn repudiation for {$caseNum} citing: '{$grounds}'. Prepare Certificate to File Action (20-B).";

        // Urgent alert directly to Punong Barangay and Clerks
        $this->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $msg, $actorUserId, 'urgent', $actionUrl, $caseId);
        $this->notifyCaseMembers($caseId, $title, $msg, $actorUserId, 'urgent', $actionUrl);
    }

    public function notifySettlementBreached(int $caseId, string $caseNum, string $breachDetails, ?int $actorUserId = null): void
    {
        $actionUrl = "../cases/case-details.php?id=" . $caseId;
        $title = "Settlement Terms Breached";
        $msg = "Complainant reported non-compliance of amicable settlement for {$caseNum}: '{$breachDetails}'. File for execution or 10-day MTC transmittal.";

        $this->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $msg, $actorUserId, 'urgent', $actionUrl, $caseId);
        $this->notifyCaseMembers($caseId, $title, $msg, $actorUserId, 'urgent', $actionUrl);
    }

    public function notifyCfaIssued(int $caseId, string $caseNum, string $cfaType, ?int $actorUserId = null): void
    {
        $actionUrl = "../cases/case-details.php?id=" . $caseId;
        $title = "Certificate to File Action Ready ({$cfaType})";
        $msg = "{$cfaType} has been prepared and certified for {$caseNum}. Ready for Punong Barangay review and issuance to complainant.";

        $this->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $msg, $actorUserId, 'warning', $actionUrl, $caseId);
        $this->notifyCaseMembers($caseId, $title, $msg, $actorUserId, 'warning', $actionUrl);
    }

    public function caseNumber(int $caseId): string
    {
        return $this->notification->getCaseNumber($caseId) ?? ('case #' . $caseId);
    }

    public function notifyHearingCancelled(int $hearingId, string $reason, int $actorUserId): void
    {
        try {
            $stmt = (new Database())->connect()->prepare("
                SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue,
                       h.presiding_officer_id, h.substitute_presider_id,
                       c.case_number, co.encoded_by
                FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                INNER JOIN complaints co ON co.complaint_id = c.complaint_id
                WHERE h.hearing_id = ?
            ");
            $stmt->execute([$hearingId]);
            $hearing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$hearing) return;

            $caseId = (int) $hearing['case_id'];
            $caseNum = $hearing['case_number'];
            $dateFmt = date('F j, Y g:i A', strtotime($hearing['hearing_date']));
            $title = "Hearing Cancelled (Emergency)";
            $msg = sprintf(
                "Hearing for %s scheduled on %s (%s) was cancelled due to an emergency: %s. Parties are not penalized. Rescheduling will follow.",
                $caseNum,
                $dateFmt,
                $hearing['venue'],
                $reason
            );

            // 1. Notify case assigned officers
            $this->notifyCaseMembers($caseId, $title, $msg, $actorUserId);

            // 2. Notify presider
            if (!empty($hearing['presiding_officer_id'])) {
                $this->notifyUser((int) $hearing['presiding_officer_id'], $title, $msg, $actorUserId);
            }
            if (!empty($hearing['substitute_presider_id'])) {
                $this->notifyUser((int) $hearing['substitute_presider_id'], $title, $msg, $actorUserId);
            }

            // 3. Notify encoder/clerk
            if (!empty($hearing['encoded_by'])) {
                $this->notifyUser((int) $hearing['encoded_by'], $title, $msg, $actorUserId);
            }

            // 4. Notify registered complainant and respondent user accounts
            $this->notifyParties($caseId, $title, $msg, $actorUserId);
        } catch (Throwable $e) {
            error_log('Failed to dispatch hearing cancellation notifications: ' . $e->getMessage());
        }
    }

    public function notifyHearingRescheduled(int $newHearingId, int $origHearingId, string $reason, int $actorUserId): void
    {
        try {
            $db = (new Database())->connect();
            $stmt = $db->prepare("
                SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue,
                       h.presiding_officer_id, c.case_number, co.encoded_by,
                       orig.hearing_date AS orig_date, orig.venue AS orig_venue,
                       orig.presiding_officer_id AS orig_presiding_officer_id
                FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                INNER JOIN complaints co ON co.complaint_id = c.complaint_id
                LEFT JOIN hearings orig ON orig.hearing_id = ?
                WHERE h.hearing_id = ?
            ");
            $stmt->execute([$origHearingId, $newHearingId]);
            $hearing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$hearing) return;

            $caseId = (int) $hearing['case_id'];
            $caseNum = $hearing['case_number'];
            $origFmt = !empty($hearing['orig_date']) ? date('F j, Y g:i A', strtotime($hearing['orig_date'])) : 'previous schedule';
            $newFmt = date('F j, Y g:i A', strtotime($hearing['hearing_date']));
            $title = "Hearing Rescheduled";
            $msg = sprintf(
                "Hearing for %s was rescheduled from %s to %s at %s. Reason: %s.",
                $caseNum,
                $origFmt,
                $newFmt,
                $hearing['venue'],
                $reason
            );

            // 1. Notify case assigned officers
            $this->notifyCaseMembers($caseId, $title, $msg, $actorUserId);

            // 2. Notify new presider
            if (!empty($hearing['presiding_officer_id'])) {
                $this->notifyUser((int) $hearing['presiding_officer_id'], $title, $msg, $actorUserId);
            }

            // 3. Notify original presider if different
            if (!empty($hearing['orig_presiding_officer_id']) && (int) $hearing['orig_presiding_officer_id'] !== (int) $hearing['presiding_officer_id']) {
                $this->notifyUser((int) $hearing['orig_presiding_officer_id'], $title, $msg, $actorUserId);
            }

            // 4. Notify encoder/clerk
            if (!empty($hearing['encoded_by'])) {
                $this->notifyUser((int) $hearing['encoded_by'], $title, $msg, $actorUserId);
            }

            // 5. Notify registered complainant and respondent user accounts
            $this->notifyParties($caseId, $title, $msg, $actorUserId);
        } catch (Throwable $e) {
            error_log('Failed to dispatch hearing rescheduled notifications: ' . $e->getMessage());
        }
    }

    public function notifyParties(int $caseId, string $title, string $message, ?int $excludeUserId = null): void
    {
        try {
            $db = (new Database())->connect();
            $stmt = $db->prepare("
                SELECT DISTINCT u.user_id
                FROM complaint_parties cp
                INNER JOIN residents r ON r.resident_id = cp.resident_id
                INNER JOIN users u ON (u.email IS NOT NULL AND u.email != '' AND u.email = r.email)
                   OR (u.contact_no IS NOT NULL AND u.contact_no != '' AND u.contact_no = r.contact_no)
                   OR (u.first_name = r.first_name AND u.last_name = r.last_name)
                WHERE cp.complaint_id = (SELECT complaint_id FROM cases WHERE case_id = ?)
                  AND u.status = 'Active'
            ");
            $stmt->execute([$caseId]);
            $partyUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($partyUserIds as $pUid) {
                $this->notifyUser((int) $pUid, $title, $message, $excludeUserId);
            }
        } catch (Throwable $e) {
            error_log('Failed to dispatch party notifications: ' . $e->getMessage());
        }
    }
}
