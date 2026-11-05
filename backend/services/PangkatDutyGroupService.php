<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';

/**
 * Standing Pangkat Duty Groups (Duty Roster & Stationing).
 *
 * The Punong Barangay organizes active Lupon Members into named, standing
 * three-person groups (e.g. "Wednesday Pangkat / Group 1"). Each group reports
 * to the barangay hall on its official duty day. When a case is elevated from
 * Mediation to Conciliation, the whole group can be assigned to the case's
 * Pangkat panel in one click (see CaseStage::referToPangkat()).
 *
 * The case-level Pangkat record (`pangkat_groups` / `pangkat_members`) remains
 * the legal record of the constituted panel; duty groups are only the standing
 * roster the panel is drawn from.
 */
class PangkatDutyGroupService
{
    public const DUTY_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    public const POSITIONS = ['Chairman', 'Secretary', 'Member'];

    private PDO $conn;
    private static bool $schemaReady = false;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->ensureSchema();
    }

    /**
     * Creates the roster tables on first use (the project has no migration runner;
     * other modules follow the same CREATE TABLE IF NOT EXISTS convention).
     */
    private function ensureSchema(): void
    {
        // DDL causes an implicit COMMIT in MySQL, so never run it inside a caller's transaction.
        if (self::$schemaReady || $this->conn->inTransaction()) {
            return;
        }

        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS pangkat_duty_groups (
                duty_group_id INT AUTO_INCREMENT PRIMARY KEY,
                group_name VARCHAR(100) NOT NULL,
                duty_day VARCHAR(10) NOT NULL,
                start_time TIME NOT NULL DEFAULT '08:00:00',
                end_time TIME NOT NULL DEFAULT '17:00:00',
                station VARCHAR(150) NOT NULL DEFAULT 'Lupon Court Room',
                notes VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pangkat_duty_group_name (group_name)
            )
        ");

        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS pangkat_duty_group_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                duty_group_id INT NOT NULL,
                member_id INT NOT NULL,
                position VARCHAR(20) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_duty_group_position (duty_group_id, position),
                UNIQUE KEY uq_duty_group_member (duty_group_id, member_id),
                KEY idx_duty_group_member (member_id),
                CONSTRAINT fk_duty_group_members_group
                    FOREIGN KEY (duty_group_id) REFERENCES pangkat_duty_groups (duty_group_id)
                    ON DELETE CASCADE
            )
        ");

        // Shared with LuponDashboardController (drives the Lupon member's "Araw ng Duty").
        $this->conn->exec("CREATE TABLE IF NOT EXISTS lupon_duty_schedules (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, duty_day VARCHAR(20) NOT NULL DEFAULT 'Wednesday', start_time TIME DEFAULT '08:00:00', end_time TIME DEFAULT '17:00:00', notes VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");

        // Remember which standing group a case panel was drawn from.
        $colStmt = $this->conn->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pangkat_groups' AND COLUMN_NAME = 'duty_group_id'
        ");
        $tableStmt = $this->conn->query("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pangkat_groups'
        ");
        if ((int) $tableStmt->fetchColumn() > 0 && (int) $colStmt->fetchColumn() === 0) {
            $this->conn->exec("ALTER TABLE pangkat_groups ADD COLUMN duty_group_id INT NULL DEFAULT NULL");
        }

        self::$schemaReady = true;
    }

    /**
     * Lists duty groups with their members.
     */
    public function listGroups(bool $activeOnly = false): array
    {
        $sql = "
            SELECT g.duty_group_id, g.group_name, g.duty_day,
                   TIME_FORMAT(g.start_time, '%H:%i') AS start_time,
                   TIME_FORMAT(g.end_time, '%H:%i') AS end_time,
                   g.station, g.notes, g.is_active, g.updated_at,
                   (SELECT COUNT(*) FROM pangkat_groups pg WHERE pg.duty_group_id = g.duty_group_id) AS cases_assigned
            FROM pangkat_duty_groups g
        ";
        if ($activeOnly) {
            $sql .= " WHERE g.is_active = 1";
        }
        $sql .= " ORDER BY FIELD(g.duty_day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), g.group_name";

        $groups = $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!$groups) {
            return [];
        }

        $memStmt = $this->conn->query("
            SELECT gm.duty_group_id, gm.member_id, gm.position,
                   u.first_name, u.middle_name, u.last_name, u.status AS user_status,
                   r.role_name
            FROM pangkat_duty_group_members gm
            INNER JOIN users u ON u.user_id = gm.member_id
            LEFT JOIN roles r ON r.role_id = u.role_id
            ORDER BY FIELD(gm.position, 'Chairman', 'Secretary', 'Member')
        ");
        $byGroup = [];
        foreach ($memStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $m['member_name'] = trim(preg_replace('/\s+/', ' ', ($m['first_name'] ?? '') . ' ' . ($m['middle_name'] ?? '') . ' ' . ($m['last_name'] ?? '')));
            $m['is_eligible'] = ($m['user_status'] === 'Active' && $m['role_name'] === 'Lupon Member');
            $byGroup[(int) $m['duty_group_id']][] = $m;
        }

        foreach ($groups as &$g) {
            $g['duty_group_id'] = (int) $g['duty_group_id'];
            $g['is_active'] = (int) $g['is_active'];
            $g['cases_assigned'] = (int) $g['cases_assigned'];
            $g['members'] = $byGroup[$g['duty_group_id']] ?? [];
            $g['is_complete'] = count(array_filter($g['members'], fn($m) => $m['is_eligible'])) === 3;
        }
        unset($g);

        return $groups;
    }

    public function getGroup(int $groupId): ?array
    {
        foreach ($this->listGroups(false) as $g) {
            if ($g['duty_group_id'] === $groupId) {
                return $g;
            }
        }
        return null;
    }

    /**
     * Creates or updates a duty group and syncs members' personal duty schedules.
     */
    public function saveGroup(array $data, int $userId): array
    {
        $groupId = (int) ($data['duty_group_id'] ?? 0);
        $name = trim((string) ($data['group_name'] ?? ''));
        $dutyDay = trim((string) ($data['duty_day'] ?? ''));
        $start = $this->normalizeTime($data['start_time'] ?? '08:00') ?? '08:00:00';
        $end = $this->normalizeTime($data['end_time'] ?? '17:00') ?? '17:00:00';
        $station = trim((string) ($data['station'] ?? '')) ?: 'Lupon Court Room';
        $notes = trim((string) ($data['notes'] ?? ''));
        $isActive = !isset($data['is_active']) || !empty($data['is_active']) ? 1 : 0;

        $positions = [
            'Chairman' => (int) ($data['chairman_id'] ?? 0),
            'Secretary' => (int) ($data['secretary_id'] ?? 0),
            'Member' => (int) ($data['member_id'] ?? 0),
        ];

        if ($name === '' || mb_strlen($name) > 100) {
            return ['success' => false, 'message' => 'Enter a group name of up to 100 characters (e.g. "Wednesday Pangkat / Group 1").'];
        }
        if (!in_array($dutyDay, self::DUTY_DAYS, true)) {
            return ['success' => false, 'message' => 'Select a valid official duty day.'];
        }
        if ($start >= $end) {
            return ['success' => false, 'message' => 'Duty end time must be later than the start time.'];
        }
        if (in_array(0, $positions, true)) {
            return ['success' => false, 'message' => 'Assign a Chairman, Secretary, and Member to the group.'];
        }
        if (count(array_unique(array_values($positions))) !== 3) {
            return ['success' => false, 'message' => 'The Chairman, Secretary, and Member must be three different Lupon Members.'];
        }

        // Eligibility: active users with the Lupon Member role (the Administrator / PB cannot sit on a Pangkat).
        $ph = implode(',', array_fill(0, 3, '?'));
        $elig = $this->conn->prepare("
            SELECT u.user_id FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.user_id IN ($ph) AND u.status = 'Active' AND r.role_name = 'Lupon Member'
        ");
        $elig->execute(array_values($positions));
        if (count($elig->fetchAll(PDO::FETCH_COLUMN)) !== 3) {
            return ['success' => false, 'message' => 'All group members must be active users with the "Lupon Member" role.'];
        }

        // Duplicate name check
        $dup = $this->conn->prepare("SELECT duty_group_id FROM pangkat_duty_groups WHERE group_name = ? AND duty_group_id <> ? LIMIT 1");
        $dup->execute([$name, $groupId]);
        if ($dup->fetchColumn()) {
            return ['success' => false, 'message' => "A duty group named \"{$name}\" already exists."];
        }

        // One standing duty station per member: a member may only belong to one active group.
        if ($isActive) {
            $conf = $this->conn->prepare("
                SELECT gm.member_id, g.group_name,
                       TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS member_name
                FROM pangkat_duty_group_members gm
                INNER JOIN pangkat_duty_groups g ON g.duty_group_id = gm.duty_group_id
                INNER JOIN users u ON u.user_id = gm.member_id
                WHERE g.is_active = 1 AND gm.duty_group_id <> ? AND gm.member_id IN ($ph)
                LIMIT 1
            ");
            $conf->execute(array_merge([$groupId], array_values($positions)));
            if ($row = $conf->fetch(PDO::FETCH_ASSOC)) {
                return [
                    'success' => false,
                    'message' => "{$row['member_name']} is already stationed in \"{$row['group_name']}\". Remove them from that group first (each Lupon Member has one official duty day).",
                ];
            }
        }

        try {
            $this->conn->beginTransaction();

            $previousMembers = [];
            if ($groupId > 0) {
                $exists = $this->conn->prepare("SELECT duty_group_id FROM pangkat_duty_groups WHERE duty_group_id = ?");
                $exists->execute([$groupId]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('Duty group not found.');
                }
                $prev = $this->conn->prepare("SELECT member_id FROM pangkat_duty_group_members WHERE duty_group_id = ?");
                $prev->execute([$groupId]);
                $previousMembers = array_map('intval', $prev->fetchAll(PDO::FETCH_COLUMN));

                $upd = $this->conn->prepare("
                    UPDATE pangkat_duty_groups
                    SET group_name = ?, duty_day = ?, start_time = ?, end_time = ?, station = ?, notes = ?, is_active = ?
                    WHERE duty_group_id = ?
                ");
                $upd->execute([$name, $dutyDay, $start, $end, $station, $notes ?: null, $isActive, $groupId]);
            } else {
                $ins = $this->conn->prepare("
                    INSERT INTO pangkat_duty_groups (group_name, duty_day, start_time, end_time, station, notes, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$name, $dutyDay, $start, $end, $station, $notes ?: null, $isActive, $userId ?: null]);
                $groupId = (int) $this->conn->lastInsertId();
            }

            $this->conn->prepare("DELETE FROM pangkat_duty_group_members WHERE duty_group_id = ?")->execute([$groupId]);
            $insMem = $this->conn->prepare("INSERT INTO pangkat_duty_group_members (duty_group_id, member_id, position) VALUES (?, ?, ?)");
            foreach ($positions as $position => $memberId) {
                $insMem->execute([$groupId, $memberId, $position]);
            }

            // Sync personal duty schedules (shown on the Lupon Member dashboard).
            $removed = array_diff($previousMembers, array_values($positions));
            $this->deactivateSchedules($removed);
            if ($isActive) {
                $sched = $this->conn->prepare("
                    INSERT INTO lupon_duty_schedules (user_id, duty_day, start_time, end_time, notes, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE duty_day = VALUES(duty_day), start_time = VALUES(start_time),
                        end_time = VALUES(end_time), notes = VALUES(notes), is_active = 1
                ");
                foreach ($positions as $position => $memberId) {
                    $sched->execute([$memberId, $dutyDay, $start, $end, mb_substr("{$name} · {$position} · {$station}", 0, 255)]);
                }
            } else {
                $this->deactivateSchedules(array_values($positions));
            }

            $this->conn->commit();
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save the duty group: ' . $e->getMessage()];
        }

        (new AuditService())->log($userId, ($data['duty_group_id'] ?? 0) ? "Updated Pangkat duty group \"{$name}\"" : "Created Pangkat duty group \"{$name}\"", 'Pangkat Duty Groups', $groupId);

        return ['success' => true, 'message' => "Duty group \"{$name}\" saved. Members are now stationed every {$dutyDay}.", 'duty_group_id' => $groupId];
    }

    public function deleteGroup(int $groupId, int $userId): array
    {
        $group = $this->getGroup($groupId);
        if (!$group) {
            return ['success' => false, 'message' => 'Duty group not found.'];
        }

        try {
            $this->conn->beginTransaction();
            $this->deactivateSchedules(array_map(fn($m) => (int) $m['member_id'], $group['members']));
            // Historical case panels keep their members; only the link to the standing group is cleared.
            $this->conn->prepare("UPDATE pangkat_groups SET duty_group_id = NULL WHERE duty_group_id = ?")->execute([$groupId]);
            $this->conn->prepare("DELETE FROM pangkat_duty_groups WHERE duty_group_id = ?")->execute([$groupId]);
            $this->conn->commit();
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Unable to delete the duty group: ' . $e->getMessage()];
        }

        (new AuditService())->log($userId, "Deleted Pangkat duty group \"{$group['group_name']}\"", 'Pangkat Duty Groups', $groupId);
        return ['success' => true, 'message' => "Duty group \"{$group['group_name']}\" deleted."];
    }

    /**
     * Resolves an active, complete duty group into the panel payload used by
     * CaseStage::referToPangkat(). Server-side source of truth for bulk selection.
     *
     * @return array{success: bool, message?: string, group?: array, chairman_id?: int, secretary_id?: int, member_id?: int}
     */
    public function resolvePanel(int $groupId): array
    {
        $group = $this->getGroup($groupId);
        if (!$group || !$group['is_active']) {
            return ['success' => false, 'message' => 'The selected Pangkat duty group is missing or inactive.'];
        }
        if (!$group['is_complete']) {
            return ['success' => false, 'message' => "Duty group \"{$group['group_name']}\" is incomplete: every seat must be filled by an active Lupon Member. Update it under Settings → Pangkat Duty Groups."];
        }

        $panel = [];
        foreach ($group['members'] as $m) {
            $panel[$m['position']] = (int) $m['member_id'];
        }

        return [
            'success' => true,
            'group' => $group,
            'chairman_id' => $panel['Chairman'],
            'secretary_id' => $panel['Secretary'],
            'member_id' => $panel['Member'],
        ];
    }

    private function deactivateSchedules(array $memberIds): void
    {
        $memberIds = array_values(array_filter(array_map('intval', $memberIds)));
        if (!$memberIds) {
            return;
        }
        $ph = implode(',', array_fill(0, count($memberIds), '?'));
        $this->conn->prepare("UPDATE lupon_duty_schedules SET is_active = 0 WHERE user_id IN ($ph)")->execute($memberIds);
    }

    private function normalizeTime($value): ?string
    {
        $value = trim((string) $value);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value)) {
            return null;
        }
        return substr($value, 0, 5) . ':00';
    }
}
