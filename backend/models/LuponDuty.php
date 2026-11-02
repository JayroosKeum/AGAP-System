<?php

require_once __DIR__ . '/../config/Database.php';

class LuponDuty
{
    private PDO $db;

    public function __construct()
    {
        $this->db = (new Database())->connect();
    }

    /**
     * Get all active Lupon members (role_id = 3).
     */
    public function getLuponMembers(): array
    {
        $stmt = $this->db->query("
            SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, u.contact_no, u.status
            FROM users u
            WHERE u.role_id = 3
            ORDER BY u.last_name ASC, u.first_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get duty schedules for all lupon members or a specific member.
     */
    public function getSchedules(?int $userId = null): array
    {
        $sql = "
            SELECT s.id, s.user_id, s.duty_day, s.start_time, s.end_time, s.notes, s.is_active,
                   u.first_name, u.last_name, u.username
            FROM lupon_duty_schedules s
            JOIN users u ON u.user_id = s.user_id
            WHERE 1=1
        ";
        $params = [];
        if ($userId !== null && $userId > 0) {
            $sql .= " AND s.user_id = ?";
            $params[] = $userId;
        }
        $sql .= " ORDER BY FIELD(s.duty_day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), u.last_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Assign or update duty schedule for a lupon member on a specific day.
     */
    public function setSchedule(int $userId, string $dutyDay, ?string $startTime = null, ?string $endTime = null, ?string $notes = null, int $isActive = 1): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO lupon_duty_schedules (user_id, duty_day, start_time, end_time, notes, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                notes = VALUES(notes),
                is_active = VALUES(is_active),
                updated_at = NOW()
        ");
        return $stmt->execute([
            $userId,
            $dutyDay,
            $startTime ?: null,
            $endTime ?: null,
            $notes ?: null,
            $isActive
        ]);
    }

    /**
     * Batch save duty days for a user.
     * $dutyDays is an array of day names: ['Monday', 'Wednesday', 'Friday']
     */
    public function syncUserDutyDays(int $userId, array $dutyDays, ?string $startTime = null, ?string $endTime = null, ?string $notes = null): bool
    {
        $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $cleanDays = array_values(array_intersect($validDays, $dutyDays));

        $this->db->beginTransaction();
        try {
            // Deactivate days not in list or remove
            $delStmt = $this->db->prepare("DELETE FROM lupon_duty_schedules WHERE user_id = ?");
            $delStmt->execute([$userId]);

            $insStmt = $this->db->prepare("
                INSERT INTO lupon_duty_schedules (user_id, duty_day, start_time, end_time, notes, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            foreach ($cleanDays as $day) {
                $insStmt->execute([$userId, $day, $startTime ?: null, $endTime ?: null, $notes ?: null]);
            }
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('syncUserDutyDays error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a single schedule by id.
     */
    public function deleteSchedule(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM lupon_duty_schedules WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
