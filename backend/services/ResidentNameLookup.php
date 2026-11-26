<?php

declare(strict_types=1);

/** Name comparisons run after authorized server-side decryption, never on ciphertext. */
final class ResidentNameLookup
{
    private static function normalized(string $value): string
    { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value)); }

    public static function find(PDO $db, ?string $first = null, ?string $last = null, ?string $full = null): ?int
    {
        $rows = $db->query('SELECT resident_id, first_name, middle_name, last_name, suffix FROM residents ORDER BY resident_id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($first !== null && $last !== null && self::normalized((string) $row['first_name']) === self::normalized($first) && self::normalized((string) $row['last_name']) === self::normalized($last)) return (int) $row['resident_id'];
            if ($full !== null) {
                $variants = [implode(' ', array_filter([$row['first_name'], $row['middle_name'], $row['last_name']])), trim($row['first_name'] . ' ' . $row['last_name']), $row['last_name'] . ', ' . $row['first_name']];
                foreach ($variants as $variant) if (self::normalized($variant) === self::normalized($full)) return (int) $row['resident_id'];
            }
        }
        return null;
    }

    public static function matchingIds(PDO $db, string $term): array
    {
        $term = self::normalized($term); $ids = [];
        foreach ($db->query('SELECT resident_id, first_name, middle_name, last_name, suffix FROM residents')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = self::normalized(implode(' ', array_filter([$row['first_name'], $row['middle_name'], $row['last_name'], $row['suffix']])));
            if ($term !== '' && str_contains($name, $term)) $ids[] = (int) $row['resident_id'];
        }
        return $ids;
    }
}
