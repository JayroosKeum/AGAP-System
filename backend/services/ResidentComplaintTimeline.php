<?php

declare(strict_types=1);

require_once __DIR__ . '/ComplaintInformationService.php';

final class ResidentComplaintTimeline
{
    private const SCREENING_LABELS = [
        'Under Review' => 'Screening started',
        'Accepted' => 'Eligible for Paanyaya',
        'Rejected' => 'Screening completed',
        'Referred' => 'Referred for assistance',
        'Duplicate' => 'Matched with an existing concern',
        'Needs Information' => 'More information requested',
    ];
    private const OUTCOME_LABELS = [
        'Settled' => 'Complaint settled',
        'Dismissed' => 'Complaint dismissed',
        'CFA Issued' => 'Certificate to File Action issued',
        'DISMISSED_BARRED' => 'Complaint closed after a bar to action',
        'RESPONDENT_DEFAULT' => 'Respondent default recorded',
        'Repudiated' => 'Settlement or agreement repudiated',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function find(int $id, int $residentUserId): ?array
    {
        $query = $this->db->prepare('SELECT c.complaint_id, c.complaint_title, c.intake_reference, c.complaint_number,
                c.status, c.screening_decision, c.created_at, c.updated_at, c.liso_reviewed_at,
                cs.case_id, cs.case_status, cs.docket_date, cs.updated_at AS case_updated_at, c.docketed_at
            FROM complaints c LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
            WHERE c.complaint_id = ? AND c.submitted_by = ? AND c.is_draft = 0');
        $query->execute([$id, $residentUserId]);
        $complaint = $query->fetch();
        if (!$complaint) {
            return null;
        }

        $requests = (new ComplaintInformationService($this->db))->history($id);
        $openRequest = $requests[0] ?? null;
        if ($complaint['status'] !== 'Needs Information' || $complaint['screening_decision'] !== 'Needs Information' || $complaint['case_id']
            || ($openRequest['responded_at'] ?? null) !== null) {
            $openRequest = null;
        }

        $events = [];
        $add = static function (?string $date, string $title, string $detail = '', bool $reply = false) use (&$events): void {
            if ($date) {
                $events[] = ['date' => $date, 'title' => $title, 'detail' => $detail, 'reply' => $reply];
            }
        };
        $add($complaint['created_at'], 'Concern submitted');
        foreach ($requests as $request) {
            $add($request['requested_at'], 'More information requested', $request['request_message'],
                $request['request_id'] === ($openRequest['request_id'] ?? null));
            $add($request['responded_at'], 'Your response was sent', $request['response_message'] ?? '');
        }

        $reviews = $this->db->prepare("SELECT action, audit_date FROM audit_trails
            WHERE module_name = 'Complaints' AND affected_record = ? AND action LIKE 'Reviewed Complaint: %'
            ORDER BY audit_date, audit_id");
        $reviews->execute([$id]);
        $seen = [];
        foreach ($reviews->fetchAll() as $review) {
            $status = substr($review['action'], strlen('Reviewed Complaint: '));
            if (isset(self::SCREENING_LABELS[$status]) && ($status !== 'Needs Information' || !$requests)) {
                $add($review['audit_date'], self::SCREENING_LABELS[$status]);
                $seen[$status] = true;
            }
        }
        $screeningStatus = $complaint['screening_decision'] === 'Eligible' ? 'Accepted' : $complaint['screening_decision'];
        if (isset(self::SCREENING_LABELS[$screeningStatus]) && !isset($seen[$screeningStatus])
            && ($screeningStatus !== 'Needs Information' || !$requests)) {
            $add($complaint['liso_reviewed_at'], self::SCREENING_LABELS[$screeningStatus]);
        }

        $appointments = $this->db->prepare("SELECT a.invitation_number, COALESCE(p.appointment_at, a.appointment_at) AS appointment_at, p.venue, a.recorded_at
            FROM paanyaya_service_attempts a JOIN paanyaya_preparations p
                ON p.complaint_id = a.complaint_id AND p.invitation_number = a.invitation_number
            WHERE a.complaint_id = ? AND a.outcome = 'Delivered' ORDER BY a.attempt_id");
        $appointments->execute([$id]);
        foreach ($appointments->fetchAll() as $appointment) {
            $label = (int) $appointment['invitation_number'] === 2 ? 'Second Paanyaya appointment confirmed' : 'Paanyaya appointment confirmed';
            $add($appointment['recorded_at'], $label, self::appointmentDetails($appointment['appointment_at'], $appointment['venue']));
        }

        $minutes = $this->db->prepare('SELECT dialogue_date, outcome, complainant_choice FROM paanyaya_minutes
            WHERE complaint_id = ? ORDER BY minutes_id');
        $minutes->execute([$id]);
        $paanyayaClosed = false;
        foreach ($minutes->fetchAll() as $minute) {
            $title = match (true) {
                $minute['outcome'] === 'Settled' => 'Settled at Paanyaya',
                $minute['outcome'] === 'Dismissed' => 'Dismissed at Paanyaya',
                $minute['complainant_choice'] === 'Request_Paanyaya_2' => 'Second Paanyaya requested',
                $minute['complainant_choice'] === 'Escalate_Mediation' => 'Dialogue ended without agreement. Formal mediation requested.',
                default => 'Paanyaya dialogue concluded',
            };
            $paanyayaClosed = $paanyayaClosed || in_array($minute['outcome'], ['Settled', 'Dismissed'], true);
            $add($minute['dialogue_date'], $title);
        }

        if ($complaint['case_id']) {
            $add($complaint['docketed_at'] ?: $complaint['docket_date'], 'Formal case docketed', (string) $complaint['complaint_number']);
            $hearings = $this->db->prepare("SELECT hearing_type, hearing_date, venue, status, created_at FROM hearings
                WHERE case_id = ? AND hearing_type IN ('Initial Hearing', 'Mediation', 'Conciliation', 'Arbitration')
                AND status IN ('Scheduled', 'Attendance Recorded', 'Completed')
                AND NOT EXISTS (SELECT 1 FROM hearings nh WHERE nh.rescheduled_from_id = hearings.hearing_id)
                ORDER BY hearing_id");
            $hearings->execute([$complaint['case_id']]);
            foreach ($hearings->fetchAll() as $hearing) {
                $add($hearing['created_at'], $hearing['hearing_type'] . ' appointment scheduled',
                    self::appointmentDetails($hearing['hearing_date'], $hearing['venue']));
            }
            $outcomes = $this->db->prepare('SELECT status, updated_at FROM case_history WHERE case_id = ? ORDER BY history_id');
            $outcomes->execute([$complaint['case_id']]);
            $recordedOutcomes = [];
            $lastStatus = null;
            foreach ($outcomes->fetchAll() as $outcome) {
                if ($outcome['status'] !== $lastStatus && isset(self::OUTCOME_LABELS[$outcome['status']])) {
                    $add($outcome['updated_at'], self::OUTCOME_LABELS[$outcome['status']]);
                    $recordedOutcomes[$outcome['status']] = true;
                }
                $lastStatus = $outcome['status'];
            }
            $award = $this->db->prepare('SELECT award_date FROM arbitration_records WHERE case_id = ?');
            $award->execute([$complaint['case_id']]);
            $add($award->fetchColumn() ?: null, 'Arbitration award issued');
            $status = $complaint['case_status'];
            if (isset(self::OUTCOME_LABELS[$status]) && !isset($recordedOutcomes[$status])) {
                $add($complaint['case_updated_at'], self::OUTCOME_LABELS[$status]);
            }
        } elseif (!$paanyayaClosed) {
            $status = $complaint['status'];
            if (isset(self::OUTCOME_LABELS[$status]) || $status === 'Resolved at Paanyaya / Closed') {
                $add($complaint['updated_at'], self::OUTCOME_LABELS[$status] ?? 'Settled at Paanyaya');
            }
        }

        usort($events, static fn (array $left, array $right): int => strcmp($left['date'], $right['date']));
        return ['complaint' => $complaint, 'events' => $events, 'request' => $openRequest];
    }

    private static function appointmentDetails(string $date, string $venue): string
    {
        return date('M j, Y, g:i A', strtotime($date)) . ' · ' . $venue;
    }
}
