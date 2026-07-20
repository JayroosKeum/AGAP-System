<?php

require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/DeadlineService.php';

class HearingController
{
    private Hearing $hearing;
    private AuditService $audit;

    public function __construct()
    {
        $this->hearing = new Hearing();
        $this->audit = new AuditService();
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
        return ['success' => true, 'data' => $record];
    }

    public function deadlines(?int $caseId = null): array
    {
        return ['success' => true, 'data' => $this->hearing->getDeadlines($caseId)];
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
            $id = $this->hearing->create($values, $deadline);
            $this->audit->log($userId, 'Created Hearing', 'Hearings', $id);
            return ['success' => true, 'message' => 'Hearing scheduled successfully.', 'hearing_id' => $id];
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

        $data['case_id'] = $existing['case_id'];
        $validated = $this->validate($data);
        if (!$validated['success']) {
            return $validated;
        }

        $values = $validated['data'];
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
            $this->audit->log($userId, 'Updated Hearing', 'Hearings', $id);
            return ['success' => true, 'message' => 'Hearing updated successfully.'];
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to update the hearing.'];
        }
    }

    private function validate(array $data): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $type = trim((string) ($data['hearing_type'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? ''));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $rawDate = trim((string) ($data['hearing_date'] ?? ''));
        $types = ['Initial Hearing', 'Mediation', 'Conciliation', 'Arbitration'];

        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rawDate)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDate);

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

        $case = $this->hearing->getCase((int) $caseId);
        if (!$case || $case['case_status'] === 'Archived') {
            return ['success' => false, 'message' => 'The selected case does not exist or is archived.'];
        }

        if ($type === 'Initial Hearing') {
            $latest = (new DateTimeImmutable($case['docket_date']))->modify('+5 days')->setTime(23, 59, 59);
            if ($date > $latest) {
                return ['success' => false, 'message' => 'Initial hearing must be scheduled within five calendar days of docketing.'];
            }
        }

        return [
            'success' => true,
            'data' => [
                'case_id' => (int) $caseId,
                'hearing_type' => $type,
                'hearing_date' => $date->format('Y-m-d H:i:s'),
                'venue' => $venue,
                'remarks' => $remarks !== '' ? $remarks : null,
            ],
        ];
    }
}