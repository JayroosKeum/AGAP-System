<?php

require_once __DIR__ . '/../models/MediationSchedule.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/NotificationService.php';

class MediationScheduleController
{
    private MediationSchedule $mediation;
    private AuditService $audit;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->mediation = new MediationSchedule();
        $this->audit = new AuditService();
        $this->notifications = new NotificationService();
    }

    public function schedule(array $data, int $userId): array
    {
        $complaintId = filter_var($data['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $date = trim((string) ($data['mediation_date'] ?? ''));
        $time = trim((string) ($data['mediation_time'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? 'Barangay Hall'));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        if (($data['schedule_confirmed'] ?? '') !== '1') {
            return ['success' => false, 'message' => 'Confirm the mediation schedule before continuing.'];
        }
        if (!$complaintId || $date === '' || $time === '') {
            return ['success' => false, 'message' => '1st Mediation date and time are required.'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return ['success' => false, 'message' => 'Provide a valid 1st Mediation date and time.'];
        }
        $hearingDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
        if (!$hearingDate || $hearingDate->format('Y-m-d H:i') !== $date . ' ' . $time || $hearingDate <= new DateTimeImmutable()) {
            return ['success' => false, 'message' => '1st Mediation must be scheduled for a future date and time.'];
        }
        if ($venue === '' || mb_strlen($venue) > 255) {
            return ['success' => false, 'message' => 'Venue is required and must not exceed 255 characters.'];
        }
        if (mb_strlen($remarks) > 5000) {
            return ['success' => false, 'message' => 'Remarks must not exceed 5,000 characters.'];
        }

        $result = $this->mediation->create((int) $complaintId, $hearingDate->format('Y-m-d H:i:s'), $venue, $remarks !== '' ? $remarks : null);
        if ($result['success']) {
            $this->audit->log($userId, 'Scheduled 1st Mediation', 'Hearings', $result['hearing_id']);
            $this->audit->log($userId, 'Docketed Case at 1st Mediation', 'Cases', $result['case_id']);
            $this->notifications->notifyCaseMembers(
                $result['case_id'],
                '1st Mediation scheduled',
                sprintf('1st Mediation for %s is scheduled on %s at %s.', $result['case_number'], $hearingDate->format('F j, Y g:i A'), $venue),
                $userId
            );
        }
        return $result;
    }
}
