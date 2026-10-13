<?php
/**
 * Canonical summon delivery statuses for AGAP.
 * Place in: backend/services/SummonDeliveryStatus.php
 */

declare(strict_types=1);

final class SummonDeliveryStatus
{
    public const PENDING = 'Pending';
    public const SERVED = 'Served';
    public const SERVED_PERSONAL = 'Served Personal';
    public const SERVED_SUBSTITUTED = 'Served Substituted';
    public const SERVED_REFUSED = 'Served Refused';
    public const REFUSED = 'Refused';
    public const UNSERVED = 'Unserved';
    public const UNLOCATABLE = 'Unlocatable';
    public const WRONG_ADDRESS = 'Wrong Address';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::SERVED,
            self::SERVED_PERSONAL,
            self::SERVED_SUBSTITUTED,
            self::SERVED_REFUSED,
            self::REFUSED,
            self::UNSERVED,
            self::UNLOCATABLE,
            self::WRONG_ADDRESS,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function isSuccessfulService(string $status): bool
    {
        return in_array($status, [
            self::SERVED,
            self::SERVED_PERSONAL,
            self::SERVED_SUBSTITUTED,
        ], true);
    }

    public static function requiresProceduralReview(string $status): bool
    {
        return in_array($status, [
            self::REFUSED,
            self::SERVED_REFUSED,
            self::UNLOCATABLE,
            self::WRONG_ADDRESS,
        ], true);
    }

    public static function canUnlockMediation(string $status): bool
    {
        return self::isSuccessfulService($status);
    }
}
