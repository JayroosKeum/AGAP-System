<?php

class DeadlineService
{
    public const INITIAL_HEARING = 'Initial Hearing';
    public const MEDIATION = 'Mediation Period';
    public const CONCILIATION = 'Conciliation Period';
    public const CONCILIATION_EXTENSION = 'Conciliation Extension';

    public static function addCalendarDays(string $date, int $days): string
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$value || $value->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid date supplied for deadline calculation.');
        }

        return $value->modify('+' . $days . ' days')->format('Y-m-d');
    }

    public static function deadlineForHearing(string $type, string $hearingDate): ?array
    {
        $date = substr($hearingDate, 0, 10);

        return match ($type) {
            'Initial Hearing' => [
                'deadline_type' => self::INITIAL_HEARING,
                'due_date' => $date,
            ],
            'Mediation' => [
                'deadline_type' => self::MEDIATION,
                'due_date' => self::addCalendarDays($date, 15),
            ],
            'Conciliation' => [
                'deadline_type' => self::CONCILIATION,
                'due_date' => self::addCalendarDays($date, 15),
            ],
            default => null,
        };
    }
}