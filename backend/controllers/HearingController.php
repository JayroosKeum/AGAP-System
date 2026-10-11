<?php

require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/DeadlineService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ValidationService.php';
require_once __DIR__ . '/../models/HearingException.php';
require_once __DIR__ . '/../services/DeadlineAlertService.php';

class HearingController
{
    private Hearing $hearing;
    private AuditService $audit;
    private NotificationService $notifications;
    private HearingException $exceptions;

    public function __construct()
    {
        $this->hearing = new Hearing();
        $this->audit = new AuditService();
        $this->notifications = new NotificationService();
        $this->exceptions = new HearingException();
    }

    public function index(array $params = []): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 25;
        $filters = [
            'q' => trim((string) ($params['q'] ?? '')),
            'status' => trim((string) ($params['status'] ?? '')),
            'hearing_type' => trim((string) ($params['hearing_type'] ?? '')),
            'date_from' => trim((string) ($params['date_from'] ?? '')),
            'date_to' => trim((string) ($params['date_to'] ?? '')),
            'attendance' => trim((string) ($params['attendance'] ?? '')),
            'date' => trim((string) ($params['date'] ?? '')),
        ];

        $result = $this->hearing->getPaginatedCombined($filters, $page, $perPage);

        return [
            'success' => true,
            'data' => $result['records'],
            'pagination' => $result['pagination'],
        ];
    }

    public function calendar(): array
    {
        return ['success' => true, 'data' => $this->hearing->getAll()];
    }

    public function show(int $id): array
    {
        if ($id < 1 || !($record = $this->hearing->getById($id))) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }
        $record['nonappearance'] = $this->exceptions->pendingForHearing($id);
        $record['parties'] = $this->exceptions->partiesForHearing($id);
        return ['success' => true, 'data' => $record];
    }

    public function deadlines(?int $caseId = null): array
    {
        return ['success' => true, 'data' => $this->hearing->getDeadlines($caseId)];
    }

    public function recordNonAppearance(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $residentId = filter_var($data['resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $remarks = trim((string) ($data['remarks'] ?? ''));
        if (!$hearingId || !$residentId || mb_strlen($remarks) > 2000) return ['success' => false, 'message' => 'Choose a party and provide remarks up to 2,000 characters.'];
        $result = $this->exceptions->recordUnjustifiedNonAppearance((int) $hearingId, (int) $residentId, $remarks, $userId);
        if ($result['success']) {
            $this->audit->log($userId, 'Recorded unjustified non-appearance', 'Hearings', (int) $hearingId);
            $this->notifications->notifyCaseMembers((int) $result['case_id'], 'Unjustified non-appearance', 'A party was marked absent without justification. Review the hearing for rescheduling or re-summons.', $userId);
        }
        return $result;
    }

    public function dispatchDeadlineAlerts(int $userId): array
    {
        return ['success' => true, 'dispatched' => (new DeadlineAlertService())->dispatch($userId)];
    }

    public function create(array $data, int $userId): array
    {
        $validated = $this->validate($data);
        if (!$validated['success']) {
            return $validated;
        }

        $values = $validated['data'];
        $conflict = $this->hearing->findConflict(
            $values['hearing_date'],
            $values['venue'],
            $values['case_id']
        );
        if ($conflict) {
            return [
                'success' => false,
                'message' => 'Schedule conflict with case ' . $conflict['case_number'] .
                    ' at the same date/time or venue.',
            ];
        }

        try {
            $deadline = DeadlineService::deadlineForHearing(
                $values['hearing_type'],
                $values['hearing_date']
            );
            $result = $this->hearing->createProgression($values, $deadline, $userId);
            if (!$result['success']) {
                return $result;
            }
            $label = $this->ordinal($result['sequence']) . ' ' . $result['hearing_type'];
            $this->audit->log($userId, 'Scheduled ' . $label, 'Hearings', $result['hearing_id']);
            $this->notifyHearingMembers($values, $label . ' scheduled', $userId, $label);
            return ['success' => true, 'message' => $label . ' scheduled successfully.', 'hearing_id' => $result['hearing_id']];
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to schedule the hearing.'];
        }
    }

    public function update(int $id, array $data, int $userId): array
    {
        $existing = $this->hearing->getById($id);
        if (!$existing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }
        if (!empty($existing['is_superseded']) || $this->hearing->isSuperseded($id)) {
            return [
                'success' => false,
                'message' => 'This hearing was superseded by a newer schedule and is permanently locked.'
            ];
        }

        $data['case_id'] = $existing['case_id'];
        // A schedule may be rescheduled, but its workflow stage cannot be changed by editing it.
        $data['hearing_type'] = $existing['hearing_type'];
        $data['reschedule_reason'] = trim((string)($data['reschedule_reason'] ?? ''));
        if ($data['reschedule_reason'] === '' || mb_strlen($data['reschedule_reason']) > 2000) return ['success'=>false,'message'=>'A rescheduling reason is required (up to 2,000 characters).'];
        $validated = $this->validate($data, true, $id);
        if (!$validated['success']) {
            return $validated;
        }

        $values = $validated['data'];
        $values['reschedule_reason'] = $data['reschedule_reason'];
        $conflict = $this->hearing->findConflict(
            $values['hearing_date'],
            $values['venue'],
            $values['case_id'],
            $id
        );
        if ($conflict) {
            return ['success' => false, 'message' => 'The requested date/time conflicts with another hearing.'];
        }

        try {
            $deadline = DeadlineService::deadlineForHearing(
                $values['hearing_type'],
                $values['hearing_date']
            );
            $this->hearing->update($id, $values, $deadline);
            $this->audit->log($userId, 'Created rescheduled hearing event from #' . $id, 'Hearings', $id);
            $this->notifyHearingMembers($values, 'Hearing rescheduled', $userId);
            return ['success' => true, 'message' => 'Hearing rescheduled successfully.'];
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to update the hearing: ' . $exception->getMessage()];
        }
    }

    public function officeCancel(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string) ($data['cancellation_reason'] ?? ''));
        $rescheduleDate = !empty($data['reschedule_date']) ? trim($data['reschedule_date']) : null;

        if (!$hearingId) {
            return ['success' => false, 'message' => 'Invalid hearing ID.'];
        }
        if ($reason === '') {
            return ['success' => false, 'message' => 'An office cancellation reason is required.'];
        }

        $result = $this->hearing->officeCancel((int) $hearingId, $userId, $reason, $rescheduleDate);
        if ($result['success']) {
            $this->audit->log($userId, "Office cancelled hearing #{$hearingId}. Reason: {$reason}", 'Hearings', (int) $hearingId);
        }
        return $result;
    }

    public function assignSubstitute(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $substituteId = filter_var($data['substitute_presider_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string) ($data['substitute_reason'] ?? ''));
        $partiesConsent = !empty($data['parties_consent_to_substitute']);

        if (!$hearingId && $caseId) {
            $hearingId = $this->hearing->getLatestHearingIdForCase((int) $caseId);
        }

        if (!$hearingId) {
            return ['success' => false, 'message' => 'A valid hearing or case is required.'];
        }
        if (!$substituteId) {
            return ['success' => false, 'message' => 'A valid substitute presider is required.'];
        }
        if ($reason === '') {
            return ['success' => false, 'message' => 'A valid reason for substitution is required.'];
        }
        if (!$partiesConsent) {
            return ['success' => false, 'message' => 'Both parties must consent to proceed with a substitute presider.'];
        }

        $result = $this->hearing->assignSubstitutePresider((int) $hearingId, (int) $substituteId, $reason, $partiesConsent);
        if ($result['success']) {
            $this->audit->log($userId, "Designated substitute presider #{$substituteId} for hearing #{$hearingId}", 'Hearings', (int) $hearingId);
        }
        return $result;
    }

    public function failMediation(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$hearingId) {
            return ['success' => false, 'message' => 'Invalid hearing ID.'];
        }

        $pangkatData = [
            'selection_method' => $data['selection_method'] ?? 'Party Agreement',
            'selection_notes' => !empty($data['selection_notes']) ? trim($data['selection_notes']) : null,
            'quorum_size' => !empty($data['quorum_size']) ? (int) $data['quorum_size'] : 3,
            'chairman_id' => !empty($data['chairman_id']) ? (int) $data['chairman_id'] : null,
            'secretary_id' => !empty($data['secretary_id']) ? (int) $data['secretary_id'] : null,
            'member_id' => !empty($data['member_id']) ? (int) $data['member_id'] : null,
        ];

        $result = $this->hearing->failedMediationToPangkat((int) $hearingId, $userId, $pangkatData);
        if ($result['success']) {
            $this->audit->log($userId, "Declared mediation failed for hearing #{$hearingId}; elevated to Pangkat Tagapagkasundo", 'Hearings', (int) $hearingId);
        }
        return $result;
    }

    public function getTransferPackage(int $caseId): array
    {
        if ($caseId < 1) {
            return ['success' => false, 'message' => 'Invalid case ID.'];
        }
        $data = $this->hearing->getCaseTransferPackage($caseId);
        return ['success' => true, 'data' => $data];
    }

    private function validate(array $data, bool $allowExistingTypes = false, ?int $excludeHearingId = null): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $type = trim((string) ($data['hearing_type'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? ''));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $rawDate = trim((string) ($data['hearing_date'] ?? ''));
        $types = $allowExistingTypes
            ? ['Initial Hearing', 'Mediation', 'Conciliation', 'Arbitration']
            : ['Mediation', 'Conciliation'];

        if (($data['schedule_reviewed'] ?? '') !== '1') {
            return ['success' => false, 'message' => 'Review the hearing details before final scheduling.'];
        }

        $date = null;
        if (ValidationService::dateTime($rawDate, 'Y-m-d\TH:i')) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rawDate);
        } elseif (ValidationService::dateTime($rawDate, 'Y-m-d H:i:s')) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDate);
        } elseif (ValidationService::dateTime($rawDate, 'Y-m-d H:i')) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', $rawDate);
        }

        if (!$caseId || !in_array($type, $types, true) || !$date) {
            return ['success' => false, 'message' => 'Provide a valid case, hearing type, date, and time.'];
        }
        if ($venue === '' || mb_strlen($venue) > 255) {
            return ['success' => false, 'message' => 'Venue is required and must not exceed 255 characters.'];
        }
        if (mb_strlen($remarks) > 5000) {
            return ['success' => false, 'message' => 'Remarks must not exceed 5,000 characters.'];
        }
        if ($date <= new DateTimeImmutable()) {
            return ['success' => false, 'message' => 'Hearing date must be in the future.'];
        }

        // Validation: Approved hearing blocks, lunch break, Monday 8-9am, legal holidays, duration rules
        $hoursValidation = $this->hearing->validateHearingOperatingHours(
            $date->format('Y-m-d H:i:s'),
            $data['end_time'] ?? ($data['duration_minutes'] ?? null),
            $type,
            $data['duration_exceed_reason'] ?? null
        );
        if (!$hoursValidation['valid']) {
            return [
                'success' => false,
                'message' => $hoursValidation['message'],
            ];
        }

        $case = $this->hearing->getCase((int) $caseId);
        if (!$case || $case['case_status'] === 'Archived') {
            return ['success' => false, 'message' => 'The selected case does not exist or is archived.'];
        }

        // Before booking a Conciliation hearing, a Lupon team must be chosen
        if ($type === 'Conciliation') {
            require_once __DIR__ . '/../models/Assignment.php';
            $assignmentModel = new Assignment();
            $teamValidation = $assignmentModel->validateConciliationTeam((int) $caseId);
            if (!$teamValidation['valid']) {
                return [
                    'success' => false,
                    'message' => $teamValidation['message']
                ];
            }
        }

        if (!$allowExistingTypes) {
            $dateProgression = $this->hearing->validateHearingDateProgression((int) $caseId, $date->format('Y-m-d H:i:s'));
            if (!$dateProgression['valid']) {
                return [
                    'success' => false,
                    'message' => $dateProgression['message'],
                ];
            }
        } else {
            $dateProgression = $this->hearing->validateHearingDateProgression((int) $caseId, $date->format('Y-m-d H:i:s'), $excludeHearingId);
            if (!$dateProgression['valid'] && !empty($dateProgression['same_day'])) {
                return [
                    'success' => false,
                    'message' => $dateProgression['message'],
                ];
            }
        }

        return [
            'success' => true,
            'data' => [
                'case_id' => (int) $caseId,
                'hearing_type' => $type,
                'hearing_date' => $date->format('Y-m-d H:i:s'),
                'end_time' => !empty($hoursValidation['end_time']) ? $hoursValidation['end_time'] : (!empty($data['end_time']) ? $data['end_time'] : null),
                'duration_minutes' => !empty($hoursValidation['duration_minutes']) ? (int) $hoursValidation['duration_minutes'] : (!empty($data['duration_minutes']) ? (int) $data['duration_minutes'] : 45),
                'duration_exceed_reason' => !empty($data['duration_exceed_reason']) ? trim($data['duration_exceed_reason']) : null,
                'presiding_officer_id' => !empty($data['presiding_officer_id']) ? (int) $data['presiding_officer_id'] : null,
                'venue' => $venue,
                'remarks' => $remarks !== '' ? $remarks : null,
                'rescheduled_by_party' => !empty($data['rescheduled_by_party']) ? trim($data['rescheduled_by_party']) : null,
                'reschedule_justification_category' => !empty($data['reschedule_justification_category']) ? trim($data['reschedule_justification_category']) : null,
                'reschedule_document_path' => !empty($data['reschedule_document_path']) ? trim($data['reschedule_document_path']) : null,
                'force_reschedule' => !empty($data['force_reschedule']) ? 1 : 0,
            ],
        ];
    }

    private function notifyHearingMembers(array $hearing, string $title, int $actorUserId, ?string $label = null): void
    {
        $caseNumber = $this->notifications->caseNumber((int) $hearing['case_id']);
        $message = sprintf('%s for %s on %s at %s (%s).', $label ?? $hearing['hearing_type'], $caseNumber, date('F j, Y g:i A', strtotime($hearing['hearing_date'])), $hearing['venue'], $title === 'Hearing updated' ? 'updated schedule' : 'new schedule');
        $this->notifications->notifyCaseMembers((int) $hearing['case_id'], $title, $message, $actorUserId);
    }

    private function ordinal(int $number): string
    {
        return match ($number) {
            1 => '1st',
            2 => '2nd',
            3 => '3rd',
            default => $number . 'th',
        };
    }
}
