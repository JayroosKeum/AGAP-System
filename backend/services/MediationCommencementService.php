<?php
/**
 * NEW FILE
 * Path: backend/services/MediationCommencementService.php
 *
 * Records the first actual meeting of both parties in Mediation. The service
 * is idempotent per case and does not replace AGAP's existing deadline tables.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/MediationDeadlineService.php';

final class MediationCommencementService
{
    private PDO $db;
    private AuditService $audit;

    private const ATTENDANCE_PRESENT = ['Present', 'Late'];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? (new Database())->connect();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->audit = new AuditService();
    }

    public function recordFromAttendance(
        int $hearingId,
        array $input,
        int $actorUserId
    ): array {
        $hearing = $this->findMediationHearing($hearingId);
        if (!$hearing) {
            return [
                'success' => true,
                'recorded' => false,
                'code' => 'NOT_MEDIATION',
                'message' => 'No Mediation commencement update was required.',
            ];
        }

        if (!$this->bothPartiesActuallyAppeared($input)) {
            return [
                'success' => true,
                'recorded' => false,
                'code' => 'PARTIES_NOT_BOTH_PRESENT',
                'message' => 'The Mediation clock was not started because both parties did not actually appear.',
            ];
        }

        $existing = $this->getByCase((int) $hearing['case_id']);
        if ($existing) {
            return [
                'success' => true,
                'recorded' => false,
                'code' => 'ALREADY_RECORDED',
                'message' => 'The first actual Mediation meeting was already recorded.',
                'data' => $existing,
            ];
        }

        $meetingAt = $this->meetingDateTime($hearing);
        $meetingDate = substr($meetingAt, 0, 10);

        // Delegate deadline computation to MediationDeadlineService, which enforces
        // RA 7160 §410: 15 CALENDAR days from the first actual meeting.
        // The deadline is stored on the cases table and case_deadlines by that service.
        $deadlineService = new MediationDeadlineService($this->db);
        $clockResult = $deadlineService->commenceStatutoryClock(
            (int) $hearing['case_id'],
            $meetingDate,
            $actorUserId
        );
        $deadlineAt = $clockResult['mediation_deadline_date'] ?? null;

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO mediation_commencements
                    (case_id, hearing_id, first_actual_meeting_at, mediation_deadline_at, source, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $hearing['case_id'],
                $hearingId,
                $meetingAt,
                $deadlineAt,
                'Attendance',
                $actorUserId,
            ]);

            $commencementId = (int) $this->db->lastInsertId();
            $this->audit->log(
                $actorUserId,
                "Recorded first actual Mediation meeting on {$meetingDate}. Statutory 15-calendar-day period opened (RA 7160 §410). Deadline: {$deadlineAt}.",
                'Hearings',
                $hearingId
            );

            return [
                'success'  => true,
                'recorded' => true,
                'code'     => 'MEDIATION_COMMENCED',
                'message'  => 'The first actual Mediation meeting was recorded and the statutory 15-day period has started.',
                'data'     => [
                    'commencement_id'        => $commencementId,
                    'case_id'                => (int) $hearing['case_id'],
                    'hearing_id'             => $hearingId,
                    'first_actual_meeting_at' => $meetingAt,
                    'mediation_deadline_at'  => $deadlineAt,
                    'counting_mode'          => $clockResult['counting_mode'] ?? 'calendar days (RA 7160 §410 default)',
                ],
            ];
        } catch (PDOException $e) {
            // A competing request may have inserted the unique case row first.
            if ((string) $e->getCode() === '23000') {
                $existing = $this->getByCase((int) $hearing['case_id']);
                return [
                    'success' => true,
                    'recorded' => false,
                    'code' => 'ALREADY_RECORDED',
                    'message' => 'The first actual Mediation meeting was already recorded.',
                    'data' => $existing,
                ];
            }
            throw $e;
        }
    }

    public function getByCase(int $caseId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mediation_commencements WHERE case_id = ? LIMIT 1'
        );
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findMediationHearing(int $hearingId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT hearing_id, case_id, hearing_type, hearing_date
             FROM hearings
             WHERE hearing_id = ? AND hearing_type = 'Mediation'
             LIMIT 1"
        );
        $stmt->execute([$hearingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function bothPartiesActuallyAppeared(array $input): bool
    {
        if (isset($input['complainant_attendance'], $input['respondent_attendance'])) {
            return $this->isPresent((string) $input['complainant_attendance'])
                && $this->isPresent((string) $input['respondent_attendance']);
        }

        $parties = $input['parties'] ?? $input['records'] ?? [];
        if (!is_array($parties)) {
            return false;
        }

        $complainantPresent = false;
        $respondentPresent = false;

        foreach ($parties as $key => $party) {
            if (!is_array($party)) {
                continue;
            }
            $type = strtolower(trim((string) (
                $party['party_type'] ?? $party['role'] ?? $party['participant_type'] ?? $key
            )));
            $status = (string) ($party['attendance_status'] ?? $party['attendance'] ?? $party['status'] ?? '');

            if (str_contains($type, 'complainant') && $this->isPresent($status)) {
                $complainantPresent = true;
            }
            if (str_contains($type, 'respondent') && $this->isPresent($status)) {
                $respondentPresent = true;
            }
        }

        return $complainantPresent && $respondentPresent;
    }

    private function isPresent(string $status): bool
    {
        return in_array(trim($status), self::ATTENDANCE_PRESENT, true);
    }

    private function meetingDateTime(array $hearing): string
    {
        $value = trim((string) ($hearing['hearing_date'] ?? ''));
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new RuntimeException('The hearing has no valid scheduled date and time.');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
}
