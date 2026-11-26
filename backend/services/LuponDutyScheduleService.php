<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/PiiCryptoService.php';

/**
 * Direct Monday–Friday daily duty schedule assignment for active Lupon Members.
 *
 * Replaces mandatory 3-person duty-group creation by allowing Administrators and
 * Lupon Clerks to assign or remove individual Lupon Members per weekday directly
 * in `lupon_duty_schedules`.
 */
final class LuponDutyScheduleService
{
    public const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    private PDO $conn;
    private static bool $schemaReady = false;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        self::ensureSchema($this->conn);
    }

    public static function ensureSchema(PDO $conn): void
    {
        if (self::$schemaReady || $conn->inTransaction()) {
            return;
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS lupon_duty_schedules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                duty_day VARCHAR(20) NOT NULL DEFAULT 'Wednesday',
                start_time TIME DEFAULT '08:00:00',
                end_time TIME DEFAULT '17:00:00',
                notes VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_lupon_user_duty_day (user_id, duty_day),
                KEY idx_lupon_duty_day (duty_day)
            )
        ");

        $indexes = $conn->query('SHOW INDEX FROM lupon_duty_schedules')->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($indexes as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            $seq = (int) ($row['Seq_in_index'] ?? 0);
            $grouped[$name]['non_unique'] = (int) ($row['Non_unique'] ?? 1);
            $grouped[$name]['cols'][$seq] = (string) ($row['Column_name'] ?? '');
        }

        $hasCompositeUnique = false;
        $legacySingleUserUnique = [];
        foreach ($grouped as $keyName => $meta) {
            if ($keyName === 'PRIMARY' || ($meta['non_unique'] ?? 1) !== 0) {
                continue;
            }
            ksort($meta['cols']);
            $cols = array_values($meta['cols']);
            if ($cols === ['user_id', 'duty_day']) {
                $hasCompositeUnique = true;
            } elseif ($cols === ['user_id']) {
                $legacySingleUserUnique[] = $keyName;
            }
        }

        if (!$hasCompositeUnique) {
            $conn->exec('ALTER TABLE lupon_duty_schedules ADD UNIQUE KEY uq_lupon_user_duty_day (user_id, duty_day)');
        }
        foreach ($legacySingleUserUnique as $legacyKey) {
            $safeKey = str_replace('`', '', $legacyKey);
            $conn->exec("ALTER TABLE lupon_duty_schedules DROP INDEX `{$safeKey}`");
        }

        self::$schemaReady = true;
    }

    public function getRoster(): array
    {
        $membersStmt = $this->conn->query("
            SELECT u.user_id AS member_id,
                   u.first_name,
                   u.middle_name,
                   u.last_name,
                   TRIM(CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name)) AS full_name,
                   u.contact_no
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.status = 'Active'
              AND r.role_name = 'Lupon Member'
            ORDER BY u.last_name ASC, u.first_name ASC, u.user_id ASC
        ");
        $members = PiiCryptoService::decryptPayload($membersStmt ? $membersStmt->fetchAll(PDO::FETCH_ASSOC) : []);

        $assignStmt = $this->conn->query("
            SELECT s.id,
                   s.user_id,
                   s.duty_day,
                   TIME_FORMAT(COALESCE(s.start_time, '08:00:00'), '%H:%i') AS start_time,
                   TIME_FORMAT(COALESCE(s.end_time, '17:00:00'), '%H:%i') AS end_time,
                   s.notes,
                   TRIM(CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name)) AS full_name,
                   u.first_name,
                   u.middle_name,
                   u.last_name
            FROM lupon_duty_schedules s
            INNER JOIN users u ON u.user_id = s.user_id
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE s.is_active = 1
              AND u.status = 'Active'
              AND r.role_name = 'Lupon Member'
              AND s.duty_day IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')
            ORDER BY FIELD(s.duty_day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'),
                     u.last_name ASC, u.first_name ASC, s.id ASC
        ");
        $rows = $assignStmt ? $assignStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $byDay = [];
        foreach (self::WEEKDAYS as $day) {
            $byDay[$day] = [];
        }
        $memberDays = [];
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['user_id'] = (int) $row['user_id'];
            $day = (string) $row['duty_day'];
            if (isset($byDay[$day])) {
                $byDay[$day][] = $row;
            }
            $memberDays[$row['user_id']][] = $day;
        }
        unset($row);

        foreach ($members as &$member) {
            $mid = (int) $member['member_id'];
            $member['member_id'] = $mid;
            $member['assigned_days'] = array_values(array_unique($memberDays[$mid] ?? []));
        }
        unset($member);

        return [
            'weekdays' => self::WEEKDAYS,
            'members' => $members,
            'assignments' => $rows,
            'by_day' => $byDay,
        ];
    }

    public function assignMember(array $data, int $actorUserId): array
    {
        $memberId = filter_var($data['user_id'] ?? $data['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $dutyDay = trim((string) ($data['duty_day'] ?? ''));
        $startTime = $this->normalizeTime($data['start_time'] ?? '08:00') ?? '08:00:00';
        $endTime = $this->normalizeTime($data['end_time'] ?? '17:00') ?? '17:00:00';
        $notes = trim((string) ($data['notes'] ?? ''));

        if ($memberId < 1) {
            return ['success' => false, 'message' => 'Select an active Lupon Member to assign.'];
        }
        if (!in_array($dutyDay, self::WEEKDAYS, true)) {
            return ['success' => false, 'message' => 'Select a weekday from Monday to Friday.'];
        }
        if ($startTime >= $endTime) {
            return ['success' => false, 'message' => 'Duty end time must be later than start time.'];
        }
        if (mb_strlen($notes) > 255) {
            return ['success' => false, 'message' => 'Duty notes must be 255 characters or fewer.'];
        }

        $memberStmt = $this->conn->prepare("
            SELECT u.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS full_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.user_id = ? AND u.status = 'Active' AND r.role_name = 'Lupon Member'
            LIMIT 1
        ");
        $memberStmt->execute([$memberId]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            return ['success' => false, 'message' => 'Selected user must be an active Lupon Member.'];
        }

        $stmt = $this->conn->prepare("
            INSERT INTO lupon_duty_schedules (user_id, duty_day, start_time, end_time, notes, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                notes = VALUES(notes),
                is_active = 1
        ");
        $stmt->execute([$memberId, $dutyDay, $startTime, $endTime, $notes !== '' ? $notes : null]);

        (new AuditService($this->conn))->log(
            $actorUserId ?: null,
            "Assigned {$member['full_name']} to {$dutyDay} Lupon Duty",
            'Lupon Duty Schedule',
            $memberId
        );

        return [
            'success' => true,
            'message' => "{$member['full_name']} assigned to {$dutyDay} duty.",
            'data' => $this->getRoster(),
        ];
    }

    public function removeAssignment(array $data, int $actorUserId): array
    {
        $scheduleId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $memberId = filter_var($data['user_id'] ?? $data['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $dutyDay = trim((string) ($data['duty_day'] ?? ''));

        if ($scheduleId > 0) {
            $lookup = $this->conn->prepare("
                SELECT s.id, s.user_id, s.duty_day, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS full_name
                FROM lupon_duty_schedules s
                LEFT JOIN users u ON u.user_id = s.user_id
                WHERE s.id = ? LIMIT 1
            ");
            $lookup->execute([$scheduleId]);
        } elseif ($memberId > 0 && in_array($dutyDay, self::WEEKDAYS, true)) {
            $lookup = $this->conn->prepare("
                SELECT s.id, s.user_id, s.duty_day, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS full_name
                FROM lupon_duty_schedules s
                LEFT JOIN users u ON u.user_id = s.user_id
                WHERE s.user_id = ? AND s.duty_day = ? LIMIT 1
            ");
            $lookup->execute([$memberId, $dutyDay]);
        } else {
            return ['success' => false, 'message' => 'Select a valid duty assignment to remove.'];
        }

        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'message' => 'Duty assignment not found or already removed.'];
        }

        $del = $this->conn->prepare('DELETE FROM lupon_duty_schedules WHERE id = ?');
        $del->execute([(int) $row['id']]);

        $memberName = $row['full_name'] ?: ('Lupon Member #' . $row['user_id']);
        (new AuditService($this->conn))->log(
            $actorUserId ?: null,
            "Removed {$memberName} from {$row['duty_day']} Lupon Duty",
            'Lupon Duty Schedule',
            (int) $row['user_id']
        );

        return [
            'success' => true,
            'message' => "{$memberName} removed from {$row['duty_day']} duty.",
            'data' => $this->getRoster(),
        ];
    }

    private function normalizeTime(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts === false ? null : date('H:i:s', $ts);
    }
}
