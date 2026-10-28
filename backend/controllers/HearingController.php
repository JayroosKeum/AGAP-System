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
        try {
            $validated = $this->validate($data);
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to schedule the hearing. Please try again or contact an administrator with the time of the request.'];
        }
        if (!$validated['success']) {
            return $validated;
        }

        $values = $validated['data'];
        try {
            $conflict = $this->hearing->findConflict(
                $values['hearing_date'],
                $values['venue'],
                $values['case_id'],
                null,
                $values['presiding_officer_id'] ?? null,
                $values['duration_minutes'] ?? null,
                $values['hearing_type'] ?? 'Mediation'
            );
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to schedule the hearing. Please try again or contact an administrator with the time of the request.'];
        }
        if ($conflict && !empty($conflict['conflict'])) {
            return [
                'success' => false,
                'message' => $conflict['message'] ?? 'Schedule conflict detected.',
                'alternative_slots' => $conflict['alternative_slots'] ?? []
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
            $label = $this->ordinal($result['sequence']) . ' ' . ($result['hearing_type'] === 'Arbitration' ? 'Arbitration Hearing' : $result['hearing_type']);
            $auditAction = 'Scheduled ' . $label;
            if (!empty($values['past_date_exception_reason'])) {
                $auditAction .= ' with Administrator-approved past-date exception';
            }
            $this->audit->log($userId, $auditAction, 'Hearings', $result['hearing_id']);
            $this->notifyHearingMembers($values, $label . ' scheduled', $userId, $label);
            return [
                'success' => true,
                'message' => $label . ' scheduled successfully.',
                'hearing_id' => $result['hearing_id'],
                'sequence' => (int) ($result['sequence'] ?? 1),
                'hearing_type' => $result['hearing_type'] ?? $values['hearing_type']
            ];
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to schedule the hearing. Please try again or contact an administrator with the time of the request.'];
        }
    }

    public function update(int $id, array $data, int $userId): array
    {
        try {
            $existing = $this->hearing->getById($id);
            $isSuperseded = $existing && (!empty($existing['is_superseded']) || $this->hearing->isSuperseded($id));
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to update the hearing. Please try again or contact an administrator with the time of the request.'];
        }
        if (!$existing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }
        if ($isSuperseded) {
            return [
                'success' => false,
                'message' => 'This hearing was superseded by a newer schedule and is permanently locked.'
            ];
        }

        $data['case_id'] = $existing['case_id'];
        // A schedule may be rescheduled, but its workflow stage cannot be changed by editing it.
        $data['hearing_type'] = $existing['hearing_type'];
        $data['reschedule_reason'] = trim((string)($data['reschedule_reason'] ?? ''));
        if ($data['reschedule_reason'] === '' || mb_strlen($data['reschedule_reason']) > 2000) {
            return ['success' => false, 'message' => 'A rescheduling reason is required (up to 2,000 characters).'];
        }
        try {
            $validated = $this->validate($data, true, $id);
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to update the hearing. Please try again or contact an administrator with the time of the request.'];
        }
        if (!$validated['success']) {
            return $validated;
        }

        $values = $validated['data'];
        $values['reschedule_reason'] = $data['reschedule_reason'];
        try {
            $conflict = $this->hearing->findConflict(
                $values['hearing_date'],
                $values['venue'],
                $values['case_id'],
                $id,
                $values['presiding_officer_id'] ?? null,
                $values['duration_minutes'] ?? null,
                $values['hearing_type'] ?? 'Mediation'
            );
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to update the hearing. Please try again or contact an administrator with the time of the request.'];
        }
        if ($conflict && !empty($conflict['conflict'])) {
            return [
                'success' => false,
                'message' => $conflict['message'] ?? 'The requested date/time conflicts with another hearing.',
                'alternative_slots' => $conflict['alternative_slots'] ?? []
            ];
        }

        try {
            $deadline = DeadlineService::deadlineForHearing(
                $values['hearing_type'],
                $values['hearing_date']
            );
            if (!$this->hearing->update($id, $values, $deadline)) {
                return ['success' => false, 'message' => 'Unable to update the hearing. Please try again.'];
            }
            $auditAction = 'Created rescheduled hearing event from #' . $id;
            if (!empty($values['past_date_exception_reason'])) {
                $auditAction .= ' with Administrator-approved past-date exception';
            }
            $this->audit->log($userId, $auditAction, 'Hearings', $id);
            $this->notifyHearingMembers($values, 'Hearing rescheduled', $userId);
            return ['success' => true, 'message' => 'Hearing rescheduled successfully.'];
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to update the hearing. Please try again or contact an administrator with the time of the request.'];
        }
    }

    public function checkAvailability(array $data): array
    {
        $caseId = !empty($data['case_id']) ? (int) $data['case_id'] : null;
        $dateParam = trim((string) ($data['date'] ?? ''));
        $hearingDate = trim((string) ($data['hearing_date'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? 'Lupon Court Room'));
        $excludeHearingId = !empty($data['exclude_hearing_id']) ? (int) $data['exclude_hearing_id'] : null;
        $presidingOfficerId = !empty($data['presiding_officer_id']) ? (int) $data['presiding_officer_id'] : null;
        $durationMinutes = !empty($data['duration_minutes']) ? (int) $data['duration_minutes'] : 60;
        $hearingType = !empty($data['hearing_type']) ? trim((string) $data['hearing_type']) : 'Mediation';

        $targetDate = $dateParam !== '' ? $dateParam : substr($hearingDate, 0, 10);
        if ($targetDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            return ['valid' => false, 'conflict' => false, 'message' => 'Please provide a hearing date (YYYY-MM-DD).'];
        }

        require_once __DIR__ . '/../services/OfficeLogisticsService.php';
        require_once __DIR__ . '/../services/MediationDeadlineService.php';
        $logistics = new OfficeLogisticsService();

        $targetObj = new DateTimeImmutable($targetDate);
        $dayOfWeek = (int) $targetObj->format('w'); // 0 = Sun, 1 = Mon, 2 = Tue, 3 = Wed, 4 = Thu, 5 = Fri, 6 = Sat
        $holidayName = $logistics->isPhilippineHoliday($targetObj);
        $isHoliday = ($holidayName !== null);

        // Stage rules for Barangay Tumana Master Operating Schedule:
        // 1. Tuesdays (1:00 PM – 5:00 PM): Exclusively reserved for Formal Mediation.
        // 2. Mon, Wed, Thu, Fri (8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM): Available for Conciliation & Paanyaya.
        // 3. Tuesday Mornings (8:00 AM – 12:00 PM): Available for Conciliation & Paanyaya.
        // 4. 12:00 PM – 1:00 PM (Lunch Break) & Weekends/Holidays: Closed for all session types.
        $isValidDay = true;
        $ruleHint = '';
        $defaultSlots = [];

        if ($hearingType === 'Mediation') {
            $ruleHint = 'Mediation sessions are held on working days (Monday to Friday, 8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM) before the Punong Barangay.';
            $defaultSlots = [
                ['time' => '08:00', 'label' => '8:00 AM (Target Call Time)'],
                ['time' => '08:30', 'label' => '8:30 AM (Target Call Time)'],
                ['time' => '09:00', 'label' => '9:00 AM (Target Call Time)'],
                ['time' => '09:30', 'label' => '9:30 AM (Target Call Time)'],
                ['time' => '10:00', 'label' => '10:00 AM (Target Call Time)'],
                ['time' => '10:30', 'label' => '10:30 AM (Target Call Time)'],
                ['time' => '11:00', 'label' => '11:00 AM (Target Call Time)'],
                ['time' => '11:30', 'label' => '11:30 AM (Target Call Time)'],
                ['time' => '13:00', 'label' => '1:00 PM (Target Call Time)'],
                ['time' => '13:30', 'label' => '1:30 PM (Target Call Time)'],
                ['time' => '14:00', 'label' => '2:00 PM (Target Call Time)'],
                ['time' => '14:30', 'label' => '2:30 PM (Target Call Time)'],
                ['time' => '15:00', 'label' => '3:00 PM (Target Call Time)'],
                ['time' => '15:30', 'label' => '3:30 PM (Target Call Time)'],
                ['time' => '16:00', 'label' => '4:00 PM (Target Call Time)'],
                ['time' => '16:30', 'label' => '4:30 PM (Target Call Time)'],
            ];
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                $isValidDay = false;
            }
        } else {
            // Conciliation (Pangkat)
            if ($dayOfWeek === 2) {
                // Tuesday morning only: 8:00 AM – 12:00 PM
                $ruleHint = 'Tuesday morning conciliation hearings are held from 8:00 AM to 12:00 PM. (Tuesday afternoons are reserved exclusively for Formal Mediation).';
                $defaultSlots = [
                    ['time' => '08:00', 'label' => '8:00 AM (Target Call Time)'],
                    ['time' => '08:30', 'label' => '8:30 AM (Target Call Time)'],
                    ['time' => '09:00', 'label' => '9:00 AM (Target Call Time)'],
                    ['time' => '09:30', 'label' => '9:30 AM (Target Call Time)'],
                    ['time' => '10:00', 'label' => '10:00 AM (Target Call Time)'],
                    ['time' => '10:30', 'label' => '10:30 AM (Target Call Time)'],
                    ['time' => '11:00', 'label' => '11:00 AM (Target Call Time)'],
                    ['time' => '11:30', 'label' => '11:30 AM (Target Call Time)'],
                ];
            } else {
                $ruleHint = 'Conciliation hearings before the Pangkat are held on Mondays, Wednesdays, Thursdays, and Fridays (8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM).';
                $defaultSlots = [
                    ['time' => '08:00', 'label' => '8:00 AM (Target Call Time)'],
                    ['time' => '08:30', 'label' => '8:30 AM (Target Call Time)'],
                    ['time' => '09:00', 'label' => '9:00 AM (Target Call Time)'],
                    ['time' => '09:30', 'label' => '9:30 AM (Target Call Time)'],
                    ['time' => '10:00', 'label' => '10:00 AM (Target Call Time)'],
                    ['time' => '10:30', 'label' => '10:30 AM (Target Call Time)'],
                    ['time' => '11:00', 'label' => '11:00 AM (Target Call Time)'],
                    ['time' => '11:30', 'label' => '11:30 AM (Target Call Time)'],
                    ['time' => '13:00', 'label' => '1:00 PM (Target Call Time)'],
                    ['time' => '13:30', 'label' => '1:30 PM (Target Call Time)'],
                    ['time' => '14:00', 'label' => '2:00 PM (Target Call Time)'],
                    ['time' => '14:30', 'label' => '2:30 PM (Target Call Time)'],
                    ['time' => '15:00', 'label' => '3:00 PM (Target Call Time)'],
                    ['time' => '15:30', 'label' => '3:30 PM (Target Call Time)'],
                    ['time' => '16:00', 'label' => '4:00 PM (Target Call Time)'],
                    ['time' => '16:30', 'label' => '4:30 PM (Target Call Time)'],
                ];
            }
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                $isValidDay = false;
            }
        }

        if ($isHoliday) {
            $isValidDay = false;
        }

        // Query existing active hearings on targetDate
        $db = (new Database())->connect();
        $hStmt = $db->prepare("
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.end_time, h.duration_minutes,
                   h.venue, c.case_id, c.case_number
            FROM hearings h
            LEFT JOIN cases c ON c.case_id = h.case_id
            WHERE DATE(h.hearing_date) = ?
              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
              " . ($excludeHearingId ? " AND h.hearing_id != {$excludeHearingId}" : "") . "
        ");
        $hStmt->execute([$targetDate]);
        $existingHearings = $hStmt->fetchAll(PDO::FETCH_ASSOC);

        // Soft indicator: Total cases scheduled on target date
        $scheduledCount = count($existingHearings);
        $indicatorText = sprintf(
            '%s (%d case%s scheduled)',
            $targetObj->format('l, M j'),
            $scheduledCount,
            $scheduledCount === 1 ? '' : 's'
        );

        // Elastic Call Time Slots (No Artificial 60-Minute Locks)
        // Allow staff to schedule as many cases as agreed, showing call times and soft indicators
        $slots = [];
        foreach ($defaultSlots as $slot) {
            $slotTime = $slot['time'];
            $slotStartTs = strtotime("{$targetDate} {$slotTime}:00");

            $casesAtSlot = 0;
            $slotCases = [];

            foreach ($existingHearings as $eh) {
                $ehStartTs = strtotime($eh['hearing_date']);
                if (date('H:i', $ehStartTs) === $slotTime) {
                    $casesAtSlot++;
                    $slotCases[] = $eh['case_number'] ?? '';
                }
            }

            $slotLabel = $slot['label'];
            if ($casesAtSlot > 0) {
                $slotLabel .= " — {$casesAtSlot} case" . ($casesAtSlot === 1 ? '' : 's') . " queued";
            }

            // Booked is false because duration is elastic and staff can schedule cases on the docket
            $slots[] = [
                'time' => $slotTime,
                'label' => $slotLabel,
                'booked' => false,
                'cases_at_slot' => $casesAtSlot,
                'conflict' => null
            ];
        }

        $response = [
            'success' => true,
            'date' => $targetDate,
            'day_of_week' => $dayOfWeek,
            'is_valid_day' => $isValidDay,
            'is_holiday' => $isHoliday,
            'holiday_name' => $holidayName,
            'hearing_type' => $hearingType,
            'hint' => $ruleHint,
            'scheduled_count' => $scheduledCount,
            'indicator_text' => $indicatorText,
            'slots' => $slots
        ];

        // If a specific time was supplied, also validate that specific time slot
        $timePart = trim((string) ($data['time'] ?? ''));
        if ($timePart === '' && strlen($hearingDate) >= 16) {
            $timePart = substr($hearingDate, 11, 5);
        }

        if ($timePart !== '') {
            $rawDate = "{$targetDate} {$timePart}:00";
            if (!$isValidDay) {
                $response['valid'] = false;
                $response['conflict'] = true;
                if ($isHoliday) {
                    $response['message'] = "Scheduling is blocked on {$targetDate}: Official Philippine holiday ({$holidayName}).";
                } elseif ($hearingType === 'Mediation') {
                    $response['message'] = 'Mediation hearings can only be scheduled on working days (Monday to Friday, 8:00 AM – 5:00 PM).';
                } else {
                    $response['message'] = ($dayOfWeek === 2)
                        ? 'Conciliation hearings on Tuesdays are permitted only in the morning (8:00 AM – 12:00 PM). Tuesday afternoons are exclusively reserved for Formal Mediation.'
                        : 'Conciliation cannot be scheduled on weekends.';
                }
                return $response;
            }

            // Check lunch break
            $reqHour = (int) substr($timePart, 0, 2);
            if ($reqHour >= 12 && $reqHour < 13) {
                $response['valid'] = false;
                $response['conflict'] = true;
                $response['message'] = 'Hearings cannot be scheduled during the 12:00 PM – 1:00 PM daily lunch break.';
                return $response;
            }

            // Check Tuesday afternoon conciliation block
            if ($hearingType === 'Conciliation' && $dayOfWeek === 2 && $reqHour >= 12) {
                $response['valid'] = false;
                $response['conflict'] = true;
                $response['message'] = 'Conciliation hearings cannot be scheduled on Tuesday afternoons (1:00 PM – 5:00 PM), which are exclusively reserved for Formal Mediation in the Lupon Court Room.';
                return $response;
            }

            $conflict = $this->hearing->findConflict(
                $rawDate,
                $venue,
                $caseId,
                $excludeHearingId,
                $presidingOfficerId,
                $durationMinutes,
                $hearingType
            );

            if ($conflict && !empty($conflict['conflict'])) {
                $response['valid'] = false;
                $response['conflict'] = true;
                $response['message'] = $conflict['message'];
                $response['alternative_slots'] = $conflict['alternative_slots'] ?? [];
            } else {
                $response['valid'] = true;
                $response['conflict'] = false;
                $response['message'] = "Selected call time is available on the {$indicatorText} docket.";
            }
        } else {
            $response['valid'] = $isValidDay;
            $response['conflict'] = false;
            if (!$isValidDay) {
                if ($isHoliday) {
                    $response['message'] = "Scheduling is blocked on {$targetDate}: Official Philippine holiday ({$holidayName}).";
                } elseif ($hearingType === 'Mediation') {
                    $response['message'] = 'Mediation hearings can only be scheduled on working days (Monday to Friday, 8:00 AM – 5:00 PM).';
                } else {
                    $response['message'] = ($dayOfWeek === 2)
                        ? 'Conciliation hearings on Tuesdays are permitted only in the morning (8:00 AM – 12:00 PM).'
                        : 'Conciliation cannot be scheduled on weekends.';
                }
            } else {
                $response['message'] = "{$indicatorText}. Select target call time.";
            }
        }

        return $response;
    }

    public function officeCancel(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string) ($data['cancellation_reason'] ?? ''));
        $category = !empty($data['cancellation_category']) ? trim((string) $data['cancellation_category']) : 'Emergency';
        $rescheduleDate = !empty($data['reschedule_date']) ? trim($data['reschedule_date']) : null;

        if (!$hearingId) {
            return ['success' => false, 'message' => 'Invalid hearing ID.'];
        }
        if ($reason === '') {
            return ['success' => false, 'message' => 'An emergency cancellation reason is required.'];
        }

        $result = $this->hearing->officeCancel((int) $hearingId, $userId, $reason, $category, $rescheduleDate);
        if ($result['success']) {
            $this->audit->log($userId, "Emergency cancelled hearing #{$hearingId} ({$category}). Reason: {$reason}", 'Hearings', (int) $hearingId);
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

    public function emergencyRescheduleDueToPresiderAbsence(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rescheduleDate = trim((string) ($data['reschedule_date'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? $data['reschedule_reason'] ?? ''));

        if (!$hearingId && $caseId) {
            $hearingId = $this->hearing->getLatestHearingIdForCase((int) $caseId);
        }

        if (!$hearingId) {
            return ['success' => false, 'message' => 'A valid hearing ID is required.'];
        }
        if ($rescheduleDate === '') {
            return ['success' => false, 'message' => 'A valid new hearing date and time is required for emergency rescheduling.'];
        }

        $result = $this->hearing->emergencyRescheduleDueToPresiderAbsence(
            (int) $hearingId,
            $rescheduleDate,
            $reason,
            $userId
        );

        if ($result['success']) {
            $this->audit->log(
                $userId,
                "Emergency rescheduled hearing #{$hearingId} to {$rescheduleDate} due to presider absence. KP Form 8 and KP Form 9 issued.",
                'Hearings',
                (int) ($result['new_hearing_id'] ?? $hearingId)
            );
        }

        return $result;
    }

    public function failMediation(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$hearingId) {
            return ['success' => false, 'message' => 'Invalid hearing ID.'];
        }

        $hearing = $this->hearing->getById((int) $hearingId);
        if (!$hearing || $hearing['hearing_type'] !== 'Mediation') {
            return ['success' => false, 'message' => 'Only a Mediation hearing can be used to start a Pangkat referral.'];
        }

        // There is one authoritative Mediation-to-Conciliation transition.
        // Do not let the legacy hearings action alter case status independently:
        // CaseStage enforces the completed/lapsed mediation prerequisite, writes
        // stage records and audit history, and validates the Pangkat panel.
        require_once __DIR__ . '/../models/CaseStage.php';
        $referralData = $data;
        $referralData['case_id'] = (int) $hearing['case_id'];
        $referralData['referral_reason'] = trim((string) ($data['referral_reason'] ?? $data['selection_notes'] ?? ''))
            ?: 'Mediation concluded without an amicable settlement; referred to Pangkat Tagapagkasundo.';
        $result = (new CaseStage())->referToPangkat((int) $hearing['case_id'], $referralData, $userId);
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
            : ['Mediation', 'Conciliation', 'Arbitration'];

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
        $pastDateExceptionReason = $data['past_date_exception_reason'] ?? '';
        if (!is_string($pastDateExceptionReason)) {
            return ['success' => false, 'message' => 'A valid past-date exception reason is required.'];
        }
        $pastDateExceptionReason = trim($pastDateExceptionReason);
        $isPastDate = $date < new DateTimeImmutable('now');
        if ($pastDateExceptionReason !== '' && (int) ($_SESSION['role_id'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'Only an Administrator may request a past-date scheduling exception.'];
        }
        if ($isPastDate) {
            if ((int) ($_SESSION['role_id'] ?? 0) !== 1) {
                return ['success' => false, 'message' => 'Hearings must be scheduled in the future. Only an Administrator may approve a past-date exception.'];
            }
            if (mb_strlen($pastDateExceptionReason) < 10 || mb_strlen($pastDateExceptionReason) > 2000) {
                return ['success' => false, 'message' => 'An Administrator past-date exception requires a reason between 10 and 2,000 characters.'];
            }
        }
        $recordRemarks = $remarks;
        if ($isPastDate) {
            $exceptionNote = 'Administrator-approved past-date scheduling exception: ' . $pastDateExceptionReason;
            $recordRemarks = trim($recordRemarks . ($recordRemarks === '' ? '' : "\n\n") . $exceptionNote);
        }

        // Strict Master Operating Schedule (Barangay Tumana)
        // 1. Tuesdays (1:00 PM – 5:00 PM): Exclusively reserved for Formal Mediation.
        // 2. Mon, Wed, Thu, Fri (8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM): Conciliation & Paanyaya.
        // 3. Tuesday Mornings (8:00 AM – 12:00 PM): Conciliation & Paanyaya.
        // 4. 12:00 PM – 1:00 PM Lunch Break & Weekends/Holidays: Closed for all session types.
        $dayOfWeek = (int) date('w', strtotime($date->format('Y-m-d H:i:s'))); // 0 = Sun, 2 = Tue, etc.
        $hour = (int) date('H', strtotime($date->format('Y-m-d H:i:s')));

        if ($type === 'Mediation') {
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                return ['success' => false, 'message' => 'Mediation hearings can only be scheduled on working days (Monday to Friday).'];
            }
            if (($hour < 8 || $hour >= 17) || ($hour >= 12 && $hour < 13)) {
                return ['success' => false, 'message' => 'Mediation hearings must be during regular office hours (8:00 AM – 5:00 PM, excluding 12:00 PM – 1:00 PM lunch break).'];
            }
        }

        if ($type === 'Conciliation') {
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                return ['success' => false, 'message' => 'Conciliation hearings cannot be scheduled on weekends.'];
            }
            if ($dayOfWeek === 2) {
                // Tuesday morning only: 8:00 AM – 12:00 PM
                if ($hour < 8 || $hour >= 12) {
                    return ['success' => false, 'message' => 'Conciliation hearings on Tuesdays are permitted only in the morning (8:00 AM – 12:00 PM). Tuesday afternoons (1:00 PM – 5:00 PM) are exclusively reserved for Formal Mediation in the Lupon Court Room.'];
                }
            } else {
                // Mon, Wed, Thu, Fri
                if (($hour < 8 || $hour >= 17) || ($hour >= 12 && $hour < 13)) {
                    return ['success' => false, 'message' => 'Conciliation hearings must be during operating hours (8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM, excluding 12:00 PM – 1:00 PM lunch break).'];
                }
            }
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
        if ($type === 'Mediation' && !$allowExistingTypes) {
            $db = (new Database())->connect();
            $medStmt = $db->prepare("
                SELECT h.hearing_id, h.status, h.mediation_attempt, m.status AS minutes_status, m.session_outcome
                FROM hearings h
                LEFT JOIN hearing_minutes m ON m.hearing_id = h.hearing_id
                WHERE h.case_id = ? AND h.hearing_type IN ('Initial Hearing', 'Mediation') AND h.status NOT IN ('Cancelled', 'Office Cancelled')
                ORDER BY h.hearing_date ASC, h.hearing_id ASC
            ");
            $medStmt->execute([(int)$caseId]);
            $prevHearings = $medStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($prevHearings)) {
                $lastMed = end($prevHearings);
                $lastStatus = $lastMed['status'] ?? '';
                $lastOutcome = strtolower(trim((string)($lastMed['session_outcome'] ?? '')));

                $isSettled = strpos($lastOutcome, 'settle') !== false;

                if ($isSettled) {
                    return [
                        'success' => false,
                        'message' => 'Cannot schedule 2nd Mediation: The previous mediation concluded with an Amicable Settlement.'
                    ];
                }
                if (count($prevHearings) >= 2) {
                    return [
                        'success' => false,
                        'message' => 'Two mediation sessions (Mediation 1 and 2) have already been conducted or scheduled for this case. Under Barangay Tumana policy (RA 7160), refer the dispute to the Pangkat Tagapagkasundo.'
                    ];
                }
            }
        }

        if ($type === 'Conciliation' && !$allowExistingTypes) {
            $db = (new Database())->connect();
            $conStmt = $db->prepare("
                SELECT h.hearing_id, h.status, h.hearing_date, m.status AS minutes_status, m.session_outcome
                FROM hearings h
                LEFT JOIN hearing_minutes m ON m.hearing_id = h.hearing_id
                WHERE h.case_id = ? AND h.hearing_type = 'Conciliation' AND h.status NOT IN ('Cancelled', 'Office Cancelled')
                ORDER BY h.hearing_date ASC, h.hearing_id ASC
            ");
            $conStmt->execute([(int)$caseId]);
            $prevConciliations = $conStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($prevConciliations)) {
                $count = count($prevConciliations);
                $nextSeq = $count + 1;
                $nextOrdinal = $nextSeq === 2 ? '2nd' : ($nextSeq === 3 ? '3rd' : "{$nextSeq}th");
                $curOrdinal = $count === 1 ? '1st' : ($count === 2 ? '2nd' : "{$count}th");

                $lastCon = end($prevConciliations);
                $lastOutcome = strtolower(trim((string)($lastCon['session_outcome'] ?? '')));
                $lastMinutesStatus = $lastCon['minutes_status'] ?? '';

                if (strpos($lastOutcome, 'settle') !== false) {
                    return [
                        'success' => false,
                        'message' => "Cannot schedule {$nextOrdinal} Conciliation: The previous conciliation concluded with an Amicable Settlement."
                    ];
                }
                if (strpos($lastOutcome, 'arbitrat') !== false) {
                    return [
                        'success' => false,
                        'message' => "Cannot schedule {$nextOrdinal} Conciliation: The dispute has already been submitted to Arbitration."
                    ];
                }

                $isUnfinished = (empty($lastOutcome) || $lastOutcome === 'pending') && ($lastMinutesStatus === 'Not Recorded');
                if ($isUnfinished && strtotime($lastCon['hearing_date']) > time()) {
                    return [
                        'success' => false,
                        'message' => "Cannot schedule {$nextOrdinal} Conciliation: The {$curOrdinal} Conciliation session is currently ongoing. Please conduct the {$curOrdinal} session and record its proceedings/outcome before booking the {$nextOrdinal} session."
                    ];
                }

                if ($count >= 3) {
                    return [
                        'success' => false,
                        'message' => 'Three conciliation sessions (Conciliation 1, 2, and 3) have already been conducted or scheduled for this case. Under Katarungang Pambarangay guidelines, conciliation before the Pangkat is limited to a maximum of 3 sessions. Proceed to Certificate to File Action (KP Form 20) or Arbitration.'
                    ];
                }
            }
        }


        if (!$allowExistingTypes) {
            $dateProgression = $this->hearing->validateHearingDateProgression((int) $caseId, $date->format('Y-m-d H:i:s'), null, $type);
            if (!$dateProgression['valid']) {
                return [
                    'success' => false,
                    'message' => $dateProgression['message'],
                ];
            }
        } else {
            $dateProgression = $this->hearing->validateHearingDateProgression((int) $caseId, $date->format('Y-m-d H:i:s'), $excludeHearingId, $type);
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
                'remarks' => $recordRemarks !== '' ? $recordRemarks : null,
                'past_date_exception_reason' => $isPastDate ? $pastDateExceptionReason : null,
                'rescheduled_by_party' => !empty($data['rescheduled_by_party']) ? trim($data['rescheduled_by_party']) : null,
                'reschedule_justification_category' => !empty($data['reschedule_justification_category']) ? trim($data['reschedule_justification_category']) : null,
                'reschedule_document_path' => !empty($data['reschedule_document_path']) ? trim($data['reschedule_document_path']) : null,
                'force_reschedule' => !empty($data['force_reschedule']) ? 1 : 0,
                'authorized_exception' => !empty($data['authorized_exception']) ? 1 : 0,
                'attempt_exception_reason' => !empty($data['attempt_exception_reason']) ? trim((string) $data['attempt_exception_reason']) : null,
            ],
        ];
    }

    /**
     * Retrieves the Live Daily Docket Queue for a given date.
     * Implements the waiting room model: tracks party arrival, ready state, standby,
     * and in-session status for out-of-order calling.
     */
    public function getDailyDocket(array $params): array
    {
        $date = trim((string) ($params['date'] ?? ''));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $hearingTypeFilter = trim((string) ($params['hearing_type'] ?? ''));

        $db = (new Database())->connect();

        // 1. Fetch Hearings for target date
        $sql = "
            SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                   h.queue_status, h.actual_start_time, h.actual_end_time, h.actual_duration_minutes,
                   h.complainant_attendance, h.respondent_attendance,
                   c.case_number, c.complaint_id, c.case_status,
                   comp.complaint_title, comp.complaint_number,
                   (
                       SELECT TRIM(CONCAT_WS(' ', r1.first_name, r1.last_name))
                       FROM complaint_parties cp1
                       INNER JOIN residents r1 ON r1.resident_id = cp1.resident_id
                       WHERE cp1.complaint_id = c.complaint_id AND cp1.party_type = 'Complainant'
                       ORDER BY cp1.party_id ASC
                       LIMIT 1
                   ) AS complainant_name,
                   (
                       SELECT TRIM(CONCAT_WS(' ', r2.first_name, r2.last_name))
                       FROM complaint_parties cp2
                       INNER JOIN residents r2 ON r2.resident_id = cp2.resident_id
                       WHERE cp2.complaint_id = c.complaint_id AND cp2.party_type = 'Respondent'
                       ORDER BY cp2.party_id ASC
                       LIMIT 1
                   ) AS respondent_name,
                   TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS presiding_officer_name,
                   'hearing' AS session_source
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            LEFT JOIN complaints comp ON comp.complaint_id = c.complaint_id
            LEFT JOIN users u ON u.user_id = h.presiding_officer_id
            WHERE DATE(h.hearing_date) = ?
              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
        ";
        $queryParams = [$date];
        if ($hearingTypeFilter !== '') {
            $sql .= " AND h.hearing_type = ?";
            $queryParams[] = $hearingTypeFilter;
        }
        $sql .= "
            ORDER BY 
              CASE COALESCE(h.queue_status, 'Waiting')
                WHEN 'In_Session' THEN 1
                WHEN 'Ready' THEN 2
                WHEN 'Waiting' THEN 3
                WHEN 'Standby' THEN 4
                WHEN 'Concluded' THEN 5
                ELSE 6
              END,
              h.hearing_date ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($queryParams);
        $hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch Paanyaya sessions for target date if applicable
        $paanyayaItems = [];
        if ($hearingTypeFilter === '' || $hearingTypeFilter === 'Paanyaya') {
            try {
                $pStmt = $db->prepare("
                    SELECT ps.session_id AS hearing_id, NULL AS case_id, 'Paanyaya' AS hearing_type,
                           CONCAT(ps.scheduled_date, ' ', ps.scheduled_time) AS hearing_date,
                           ps.venue, 'Scheduled' AS status,
                           ps.queue_status, ps.actual_start_time, ps.actual_end_time, NULL AS actual_duration_minutes,
                           IF(ps.complainant_attended = 'Present', 'Present', 'Pending') AS complainant_attendance,
                           IF(ps.respondent_attended = 'Present', 'Present', 'Pending') AS respondent_attendance,
                           co.complaint_number AS case_number, ps.complaint_id, 'Pre-Docketed (Paanyaya)' AS case_status,
                           co.complaint_title, co.complaint_number,
                           (
                               SELECT TRIM(CONCAT_WS(' ', r1.first_name, r1.last_name))
                               FROM complaint_parties cp1
                               INNER JOIN residents r1 ON r1.resident_id = cp1.resident_id
                               WHERE cp1.complaint_id = ps.complaint_id AND cp1.party_type = 'Complainant'
                               ORDER BY cp1.party_id ASC
                               LIMIT 1
                           ) AS complainant_name,
                           (
                               SELECT TRIM(CONCAT_WS(' ', r2.first_name, r2.last_name))
                               FROM complaint_parties cp2
                               INNER JOIN residents r2 ON r2.resident_id = cp2.resident_id
                               WHERE cp2.complaint_id = ps.complaint_id AND cp2.party_type = 'Respondent'
                               ORDER BY cp2.party_id ASC
                               LIMIT 1
                           ) AS respondent_name,
                           'Barangay Officer' AS presiding_officer_name,
                           'paanyaya' AS session_source
                    FROM paanyaya_sessions ps
                    LEFT JOIN complaints co ON co.complaint_id = ps.complaint_id
                    WHERE ps.scheduled_date = ?
                    ORDER BY ps.scheduled_time ASC
                ");
                $pStmt->execute([$date]);
                $paanyayaItems = $pStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {}
        }

        $allSessions = array_merge($hearings, $paanyayaItems);

        // Normalize and compute readiness flags for waiting room docket
        $normalized = [];
        $readyCount = 0;
        $inSessionCount = 0;
        $standbyCount = 0;
        $concludedCount = 0;
        $waitingCount = 0;

        foreach ($allSessions as $s) {
            $partiesList = [];
            if (($s['session_source'] ?? '') === 'hearing' && !empty($s['hearing_id'])) {
                try {
                    $partyStmt = $db->prepare("
                        SELECT cp.party_id, cp.resident_id, cp.party_type,
                               TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name,
                               COALESCE(ha.attendance_status, 'Pending') AS attendance_status,
                               ha.justification_reason
                        FROM complaint_parties cp
                        INNER JOIN residents r ON r.resident_id = cp.resident_id
                        LEFT JOIN hearing_attendance ha ON ha.hearing_id = ? AND ha.resident_id = cp.resident_id
                        WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
                        ORDER BY cp.party_type ASC, cp.party_id ASC
                    ");
                    $partyStmt->execute([(int) $s['hearing_id'], (int) $s['complaint_id']]);
                    $partiesList = $partyStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable) {
                    $partiesList = [];
                }
            }
            $s['parties'] = $partiesList;

            $compCount = 0; $compPresent = 0;
            $respCount = 0; $respPresent = 0;
            if (!empty($partiesList)) {
                foreach ($partiesList as $p) {
                    $isPres = in_array($p['attendance_status'], ['Present', 'Late'], true);
                    if ($p['party_type'] === 'Complainant') {
                        $compCount++;
                        if ($isPres) $compPresent++;
                    } elseif ($p['party_type'] === 'Respondent') {
                        $respCount++;
                        if ($isPres) $respPresent++;
                    }
                }
            }

            $compIn = ($compCount > 0)
                ? ($compPresent >= $compCount)
                : in_array($s['complainant_attendance'] ?? '', ['Present', 'Late'], true);

            $respIn = ($respCount > 0)
                ? ($respPresent >= $respCount)
                : in_array($s['respondent_attendance'] ?? '', ['Present', 'Late'], true);

            $isCompleteAndReady = $compIn && $respIn;

            $qStatus = $s['queue_status'] ?? 'Waiting';
            if ($qStatus === 'Waiting' && $isCompleteAndReady) {
                $qStatus = 'Ready';
            }

            if ($qStatus === 'In_Session') $inSessionCount++;
            elseif ($qStatus === 'Ready') $readyCount++;
            elseif ($qStatus === 'Standby') $standbyCount++;
            elseif ($qStatus === 'Concluded') $concludedCount++;
            else $waitingCount++;

            $hearingTimestamp = !empty($s['hearing_date']) ? strtotime($s['hearing_date']) : false;
            $nowTime = time();
            $delayMinutes = ($hearingTimestamp && $nowTime > $hearingTimestamp && !in_array($qStatus, ['In_Session', 'Concluded'], true))
                ? (int) floor(($nowTime - $hearingTimestamp) / 60)
                : 0;

            $isGracePeriodExpired = ($delayMinutes >= 60 && !$isCompleteAndReady && !in_array($qStatus, ['In_Session', 'Concluded', 'Standby'], true));
            $isGracePeriodActive = ($delayMinutes > 0 && $delayMinutes < 60 && !$isCompleteAndReady && !in_array($qStatus, ['In_Session', 'Concluded', 'Standby'], true));

            $s['complainant_checked_in'] = $compIn;
            $s['respondent_checked_in'] = $respIn;
            $s['is_complete_and_ready'] = $isCompleteAndReady;
            $s['queue_status'] = $qStatus;
            $s['call_time_formatted'] = date('g:i A', strtotime($s['hearing_date']));
            $s['delay_minutes'] = $delayMinutes;
            $s['is_grace_period_expired'] = $isGracePeriodExpired;
            $s['is_grace_period_active'] = $isGracePeriodActive;
            $s['grace_period_minutes_remaining'] = $isGracePeriodActive ? (60 - $delayMinutes) : 0;

            $normalized[] = $s;
        }

        $totalCases = count($normalized);
        $targetObj = new DateTimeImmutable($date);
        $indicatorText = sprintf(
            '%s (%d case%s scheduled)',
            $targetObj->format('l, M j'),
            $totalCases,
            $totalCases === 1 ? '' : 's'
        );

        return [
            'success' => true,
            'date' => $date,
            'indicator' => $indicatorText,
            'summary' => [
                'total_cases' => $totalCases,
                'ready_count' => $readyCount,
                'in_session_count' => $inSessionCount,
                'standby_count' => $standbyCount,
                'waiting_count' => $waitingCount,
                'concluded_count' => $concludedCount,
            ],
            'docket' => $normalized
        ];
    }

    /**
     * Checks in a party (Complainant or Respondent) as they arrive at the barangay hall.
     * When both parties are checked in for a case, flag it with status: Complete & Ready.
     */
    public function checkInParty(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $partyType = trim((string) ($data['party_type'] ?? '')); // 'Complainant' or 'Respondent'
        $status = trim((string) ($data['status'] ?? 'Checked In')); // 'Checked In' or 'Waiting'
        $sessionSource = trim((string) ($data['session_source'] ?? 'hearing'));

        if (!$hearingId || !in_array($partyType, ['Complainant', 'Respondent'], true)) {
            return ['success' => false, 'message' => 'Provide a valid hearing ID and party type (Complainant/Respondent).'];
        }

        $db = (new Database())->connect();

        if ($sessionSource === 'paanyaya') {
            $pStmt = $db->prepare('SELECT session_id, complainant_attended, respondent_attended, queue_status FROM paanyaya_sessions WHERE session_id = ?');
            $pStmt->execute([$hearingId]);
            $session = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                return ['success' => false, 'message' => 'Paanyaya session not found.'];
            }
            if (in_array($session['queue_status'], ['In_Session', 'Concluded'], true)) {
                return ['success' => false, 'message' => 'Party check-in cannot be changed after a session has started or concluded.'];
            }

            $newVal = ($status === 'Checked In') ? 'Present' : 'Absent';
            $col = ($partyType === 'Complainant') ? 'complainant_attended' : 'respondent_attended';
            $compIn = ($partyType === 'Complainant') ? ($newVal === 'Present') : ($session['complainant_attended'] === 'Present');
            $respIn = ($partyType === 'Respondent') ? ($newVal === 'Present') : ($session['respondent_attended'] === 'Present');

            $isBoth = ($compIn && $respIn);
            $newQueue = $isBoth ? 'Ready' : ($session['queue_status'] === 'Standby' ? 'Standby' : 'Waiting');

            $upd = $db->prepare("UPDATE paanyaya_sessions SET {$col} = ?, queue_status = ? WHERE session_id = ?");
            $upd->execute([$newVal, $newQueue, $hearingId]);

            return [
                'success' => true,
                'hearing_id' => $hearingId,
                'party_type' => $partyType,
                'status' => $status,
                'queue_status' => $newQueue,
                'is_complete_and_ready' => $isBoth,
                'message' => sprintf('%s checked in (%s). Case is %s.', $partyType, $status, $isBoth ? 'Complete & Ready' : 'Waiting')
            ];
        }

        $hStmt = $db->prepare('SELECT hearing_id, case_id, complainant_attendance, respondent_attendance, queue_status, status FROM hearings WHERE hearing_id = ?');
        $hStmt->execute([$hearingId]);
        $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }
        if (in_array($hearing['queue_status'], ['In_Session', 'Concluded'], true)
            || in_array($hearing['status'], ['Completed', 'Cancelled', 'Office Cancelled'], true)) {
            return ['success' => false, 'message' => 'Party check-in cannot be changed after a hearing has started, concluded, or become unavailable.'];
        }

        $residentId = filter_var($data['resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $attVal = ($status === 'Checked In') ? 'Present' : 'Pending Verification';

        // Synchronize hearing_attendance records
        $syncCp = $db->prepare("
            SELECT cp.resident_id
            FROM complaint_parties cp
            INNER JOIN cases c ON c.complaint_id = cp.complaint_id
            WHERE c.case_id = ? AND cp.party_type = ?
        ");
        $syncCp->execute([(int) $hearing['case_id'], $partyType]);
        $residentIds = $syncCp->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($residentIds)) {
            $haUpsert = $db->prepare("
                INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, recorded_by, recorded_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE attendance_status = VALUES(attendance_status), recorded_by = VALUES(recorded_by), recorded_at = NOW()
            ");
            foreach ($residentIds as $rId) {
                if (!$residentId || (int)$rId === (int)$residentId) {
                    $haUpsert->execute([$hearingId, (int) $rId, $attVal, $userId]);
                }
            }
        }

        // Calculate multi-party readiness
        $checkStmt = $db->prepare("
            SELECT cp.party_type, COALESCE(ha.attendance_status, 'Pending Verification') AS att_status
            FROM complaint_parties cp
            INNER JOIN cases c ON c.complaint_id = cp.complaint_id
            LEFT JOIN hearing_attendance ha ON ha.hearing_id = ? AND ha.resident_id = cp.resident_id
            WHERE c.case_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
        ");
        $checkStmt->execute([$hearingId, (int) $hearing['case_id']]);
        $allParties = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

        $compCount = 0; $compPresent = 0;
        $respCount = 0; $respPresent = 0;
        foreach ($allParties as $p) {
            $isPres = in_array($p['att_status'], ['Present', 'Late'], true);
            if ($p['party_type'] === 'Complainant') {
                $compCount++;
                if ($isPres) $compPresent++;
            } elseif ($p['party_type'] === 'Respondent') {
                $respCount++;
                if ($isPres) $respPresent++;
            }
        }

        $compIn = ($compCount > 0) ? ($compPresent >= $compCount) : ($partyType === 'Complainant' ? ($status === 'Checked In') : in_array($hearing['complainant_attendance'], ['Present', 'Late'], true));
        $respIn = ($respCount > 0) ? ($respPresent >= $respCount) : ($partyType === 'Respondent' ? ($status === 'Checked In') : in_array($hearing['respondent_attendance'], ['Present', 'Late'], true));

        $compHeader = $compIn ? 'Present' : 'Pending';
        $respHeader = $respIn ? 'Present' : 'Pending';
        $isBoth = ($compIn && $respIn);
        $curQ = $hearing['queue_status'] ?? 'Waiting';

        if ($isBoth) {
            $newQueue = 'Ready';
        } elseif ($curQ === 'Standby') {
            $newQueue = 'Standby';
        } elseif ($curQ === 'In_Session') {
            $newQueue = 'In_Session';
        } elseif ($curQ === 'Concluded') {
            $newQueue = 'Concluded';
        } else {
            $newQueue = 'Waiting';
        }

        $upd = $db->prepare("
            UPDATE hearings 
            SET complainant_attendance = ?,
                respondent_attendance = ?,
                queue_status = ?,
                attendance_recorded_at = NOW()
            WHERE hearing_id = ?
        ");
        $upd->execute([$compHeader, $respHeader, $newQueue, $hearingId]);

        $this->audit->log(
            $userId,
            sprintf('Party check-in: %s set to %s for hearing #%d (Queue: %s)', $partyType, $status, $hearingId, $newQueue),
            'Hearings',
            $hearingId
        );

        return [
            'success' => true,
            'hearing_id' => $hearingId,
            'party_type' => $partyType,
            'status' => $status,
            'queue_status' => $newQueue,
            'is_complete_and_ready' => $isBoth,
            'complainant_checked_in' => $compIn,
            'respondent_checked_in' => $respIn,
            'message' => sprintf('%s marked as %s. Case queue status: %s.', $partyType, $status, $isBoth ? 'Complete & Ready' : $newQueue)
        ];
    }

    /**
     * Put on Standby (Incomplete Cases):
     * If an earlier case is incomplete, puts it on standby to keep it active
     * without penalizing the parties immediately and keeps the Lupon Court Room available.
     */
    public function putOnStandby(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $standby = isset($data['standby']) ? (bool) $data['standby'] : true;
        $sessionSource = trim((string) ($data['session_source'] ?? 'hearing'));

        if (!$hearingId) {
            return ['success' => false, 'message' => 'Provide a valid hearing ID.'];
        }

        $db = (new Database())->connect();

        if ($sessionSource === 'paanyaya') {
            $pStmt = $db->prepare('SELECT session_id, complainant_attended, respondent_attended, queue_status FROM paanyaya_sessions WHERE session_id = ?');
            $pStmt->execute([$hearingId]);
            $session = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) return ['success' => false, 'message' => 'Paanyaya session not found.'];
            if (in_array($session['queue_status'], ['In_Session', 'Concluded'], true)) {
                return ['success' => false, 'message' => 'An active or concluded session cannot be moved to standby.'];
            }

            $isBoth = ($session['complainant_attended'] === 'Present' && $session['respondent_attended'] === 'Present');
            $newQueue = $standby ? 'Standby' : ($isBoth ? 'Ready' : 'Waiting');

            $db->prepare('UPDATE paanyaya_sessions SET queue_status = ? WHERE session_id = ?')
               ->execute([$newQueue, $hearingId]);

            return [
                'success' => true,
                'hearing_id' => $hearingId,
                'queue_status' => $newQueue,
                'message' => $standby ? 'Session placed on standby.' : 'Session resumed from standby.'
            ];
        }

        $hStmt = $db->prepare('SELECT hearing_id, case_id, complainant_attendance, respondent_attendance, queue_status, status FROM hearings WHERE hearing_id = ?');
        $hStmt->execute([$hearingId]);
        $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }
        if (in_array($hearing['queue_status'], ['In_Session', 'Concluded'], true)
            || in_array($hearing['status'], ['Completed', 'Cancelled', 'Office Cancelled'], true)) {
            return ['success' => false, 'message' => 'An active, concluded, or unavailable hearing cannot be moved to standby.'];
        }

        $compIn = in_array($hearing['complainant_attendance'], ['Present', 'Late'], true);
        $respIn = in_array($hearing['respondent_attendance'], ['Present', 'Late'], true);
        $isBoth = ($compIn && $respIn);

        $newQueue = $standby ? 'Standby' : ($isBoth ? 'Ready' : 'Waiting');

        $upd = $db->prepare('UPDATE hearings SET queue_status = ? WHERE hearing_id = ?');
        $upd->execute([$newQueue, $hearingId]);

        $this->audit->log(
            $userId,
            sprintf('%s hearing #%d in docket queue. Status: %s', $standby ? 'Put on Standby' : 'Resumed from Standby', $hearingId, $newQueue),
            'Hearings',
            $hearingId
        );

        return [
            'success' => true,
            'hearing_id' => $hearingId,
            'queue_status' => $newQueue,
            'message' => $standby
                ? 'Hearing put on standby. Lupon Court Room remains open for other ready cases.'
                : 'Hearing resumed from standby.'
        ];
    }

    /**
     * Out-of-Order Session Activation (Waiting Room Model):
     * The Captain or Pangkat Chairman can click "Call to Session / Start Hearing"
     * on ANY case that is marked Complete & Ready, even if its scheduled slot was later
     * in the afternoon. Adapts without throwing time-slot conflict errors.
     * Records: actual_start_time = NOW().
     */
    public function startSession(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionSource = trim((string) ($data['session_source'] ?? 'hearing'));

        if (!$hearingId) {
            return ['success' => false, 'message' => 'Provide a valid hearing ID.'];
        }

        $db = (new Database())->connect();
        $now = date('Y-m-d H:i:s');

        if ($sessionSource === 'paanyaya') {
            try {
                $db->beginTransaction();
                $pStmt = $db->prepare('SELECT session_id, scheduled_date, venue, complainant_attended, respondent_attended, queue_status FROM paanyaya_sessions WHERE session_id = ? FOR UPDATE');
                $pStmt->execute([$hearingId]);
                $session = $pStmt->fetch(PDO::FETCH_ASSOC);
                if (!$session) {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'Paanyaya session not found.'];
                }
                if ($session['scheduled_date'] !== date('Y-m-d')) {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'Only sessions scheduled for today can be called to order.'];
                }
                if ($session['queue_status'] === 'Concluded' || $session['complainant_attended'] !== 'Present' || $session['respondent_attended'] !== 'Present') {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'A Paanyaya session can be called only after both parties have checked in.'];
                }
                $activeStmt = $db->prepare("SELECT 1 FROM hearings WHERE DATE(hearing_date) = ? AND venue = ? AND queue_status = 'In_Session' AND status NOT IN ('Cancelled', 'Office Cancelled') LIMIT 1");
                $activeStmt->execute([$session['scheduled_date'], $session['venue']]);
                $activePaanyayaStmt = $db->prepare("SELECT 1 FROM paanyaya_sessions WHERE session_id <> ? AND scheduled_date = ? AND venue = ? AND queue_status = 'In_Session' LIMIT 1");
                $activePaanyayaStmt->execute([$hearingId, $session['scheduled_date'], $session['venue']]);
                if ($activeStmt->fetchColumn() || $activePaanyayaStmt->fetchColumn()) {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'Another session is already in progress at this venue. Conclude it before calling the next ready case.'];
                }
                $db->prepare("UPDATE paanyaya_sessions SET actual_start_time = COALESCE(actual_start_time, ?), queue_status = 'In_Session' WHERE session_id = ?")
                    ->execute([$now, $hearingId]);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Unable to start Paanyaya session: ' . $exception->getMessage());
                return ['success' => false, 'message' => 'Unable to start the Paanyaya session. Please try again.'];
            }

            return ['success' => true, 'hearing_id' => $hearingId, 'actual_start_time' => $now, 'queue_status' => 'In_Session', 'message' => 'Paanyaya session called to session and started successfully.'];
        }

        try {
            $db->beginTransaction();

            // Lock this docket entry so concurrent actions cannot bypass readiness.
            $hStmt = $db->prepare("
                SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue, h.status, h.queue_status,
                       h.actual_start_time, h.complainant_attendance, h.respondent_attendance, c.case_status
                FROM hearings h INNER JOIN cases c ON c.case_id = h.case_id
                WHERE h.hearing_id = ? FOR UPDATE
            ");
            $hStmt->execute([$hearingId]);
            $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hearing) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Hearing not found.'];
            }
            if (in_array($hearing['status'], ['Completed', 'Cancelled', 'Office Cancelled'], true)
                || $hearing['queue_status'] === 'Concluded') {
                $db->rollBack();
                return ['success' => false, 'message' => 'This hearing is already concluded or unavailable.'];
            }
            if (date('Y-m-d', strtotime($hearing['hearing_date'])) !== date('Y-m-d')) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Only hearings scheduled for today can be called to order.'];
            }

            $bothPartiesPresent = in_array($hearing['complainant_attendance'], ['Present', 'Late'], true)
                && in_array($hearing['respondent_attendance'], ['Present', 'Late'], true);
            if (!$bothPartiesPresent) {
                $db->rollBack();
                return ['success' => false, 'message' => 'A session can be called only after both parties have checked in. Keep this hearing in the waiting room or place it on standby.'];
            }

            // A later ready case may jump an incomplete earlier one, but no venue
            // can have two live sessions at once.
            $activeStmt = $db->prepare("
                SELECT hearing_id FROM hearings
                WHERE hearing_id <> ? AND DATE(hearing_date) = DATE(?) AND venue = ?
                  AND queue_status = 'In_Session'
                  AND status NOT IN ('Cancelled', 'Office Cancelled')
                FOR UPDATE
            ");
            $activeStmt->execute([$hearingId, $hearing['hearing_date'], $hearing['venue']]);
            $activePaanyayaStmt = $db->prepare("SELECT 1 FROM paanyaya_sessions WHERE scheduled_date = ? AND venue = ? AND queue_status = 'In_Session' LIMIT 1");
            $activePaanyayaStmt->execute([date('Y-m-d', strtotime($hearing['hearing_date'])), $hearing['venue']]);
            if ($activeStmt->fetchColumn() || $activePaanyayaStmt->fetchColumn()) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Another hearing is already in session at this venue. Conclude it before calling the next ready case.'];
            }

            $startTime = $hearing['actual_start_time'] ?: $now;
            $wasOutOfOrder = strtotime($startTime) < strtotime($hearing['hearing_date']);
            $upd = $db->prepare("
                UPDATE hearings SET actual_start_time = COALESCE(actual_start_time, ?), queue_status = 'In_Session'
                WHERE hearing_id = ?
            ");
            $upd->execute([$startTime, $hearingId]);

            $history = $db->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)");
            $history->execute([
                (int) $hearing['case_id'],
                $hearing['case_status'],
                sprintf(
                    '%s started at %s%s. Scheduled call time: %s; both parties were present.',
                    sprintf('%s session %s', $hearing['hearing_type'], $wasOutOfOrder ? 'called early from the ready queue' : 'called to order'),
                    date('g:i A', strtotime($startTime)),
                    $wasOutOfOrder ? ' under first-come, first-served priority' : '',
                    date('g:i A', strtotime($hearing['hearing_date']))
                ),
                $userId
            ]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Unable to start docket session: ' . $exception->getMessage());
            return ['success' => false, 'message' => 'Unable to start the hearing session. Please try again.'];
        }

        $this->audit->log(
            $userId,
            sprintf('Called hearing #%d to session%s. Actual start time: %s', $hearingId, $wasOutOfOrder ? ' ahead of its scheduled call time' : '', $startTime),
            'Hearings',
            $hearingId
        );

        return [
            'success' => true,
            'hearing_id' => $hearingId,
            'actual_start_time' => $startTime,
            'actual_start_time_formatted' => date('g:i A', strtotime($startTime)),
            'queue_status' => 'In_Session',
            'message' => $wasOutOfOrder
                ? 'Ready hearing called early under first-come, first-served priority. Actual start time has been recorded.'
                : 'Hearing called to session and started. Actual start time has been recorded.'
        ];
    }

    /**
     * Concludes session and saves elastic duration tracking:
     * actual_end_time = NOW()
     * actual_duration_minutes = TIMESTAMPDIFF(MINUTE, actual_start_time, actual_end_time)
     */
    public function concludeSession(array $data, int $userId): array
    {
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionSource = trim((string) ($data['session_source'] ?? 'hearing'));

        if (!$hearingId) {
            return ['success' => false, 'message' => 'Provide a valid hearing ID.'];
        }

        $db = (new Database())->connect();
        $now = date('Y-m-d H:i:s');

        if ($sessionSource === 'paanyaya') {
            $pStmt = $db->prepare('SELECT session_id, actual_start_time, queue_status FROM paanyaya_sessions WHERE session_id = ?');
            $pStmt->execute([$hearingId]);
            $session = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) return ['success' => false, 'message' => 'Paanyaya session not found.'];
            if ($session['queue_status'] !== 'In_Session') {
                return ['success' => false, 'message' => 'Only a Paanyaya session currently in progress can be concluded.'];
            }
            $upd = $db->prepare("
                UPDATE paanyaya_sessions 
                SET actual_end_time = ?,
                    queue_status = 'Concluded'
                WHERE session_id = ?
            ");
            $upd->execute([$now, $hearingId]);

            return [
                'success' => true,
                'hearing_id' => $hearingId,
                'actual_end_time' => $now,
                'queue_status' => 'Concluded',
                'message' => 'Paanyaya session concluded successfully.'
            ];
        }

        $hStmt = $db->prepare('SELECT hearing_id, case_id, actual_start_time, duration_minutes, queue_status, status FROM hearings WHERE hearing_id = ?');
        $hStmt->execute([$hearingId]);
        $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }
        if ($hearing['queue_status'] !== 'In_Session' || in_array($hearing['status'], ['Completed', 'Cancelled', 'Office Cancelled'], true)) {
            return ['success' => false, 'message' => 'Only a hearing that is currently in session can be concluded through the docket queue.'];
        }

        $startTime = $hearing['actual_start_time'] ?: $now;
        $durationMin = max(1, (int) round((strtotime($now) - strtotime($startTime)) / 60));

        $upd = $db->prepare("
            UPDATE hearings 
            SET actual_start_time = COALESCE(actual_start_time, ?),
                actual_end_time = ?,
                actual_duration_minutes = ?,
                queue_status = 'Concluded',
                status = IF(status IN ('Cancelled', 'Office Cancelled'), status, 'Completed')
            WHERE hearing_id = ?
        ");
        $upd->execute([$startTime, $now, $durationMin, $hearingId]);

        $this->audit->log(
            $userId,
            sprintf('Concluded hearing #%d session. Actual duration: %d minutes (%s to %s).', $hearingId, $durationMin, date('g:i A', strtotime($startTime)), date('g:i A', strtotime($now))),
            'Hearings',
            $hearingId
        );

        return [
            'success' => true,
            'hearing_id' => $hearingId,
            'actual_start_time' => $startTime,
            'actual_end_time' => $now,
            'actual_duration_minutes' => $durationMin,
            'queue_status' => 'Concluded',
            'message' => sprintf('Session concluded. Actual duration: %d minutes.', $durationMin)
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
