<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';

final class LisoDutyService
{
    private PDO $db;
    private AuditService $audit;

    public function __construct()
    {
        $this->db = (new Database())->connect();
        $this->audit = new AuditService();
    }

    public function currentForUser(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT duty_session_id, started_at
             FROM liso_duty_sessions
             WHERE user_id = ? AND active_marker = 1 AND ended_at IS NULL AND DATE(started_at) = CURDATE()
             ORDER BY started_at DESC LIMIT 1'
        );
        $statement->execute([$userId]);
        $session = $statement->fetch(PDO::FETCH_ASSOC);
        return $session ?: null;
    }

    public function activeMembers(): array
    {
        $statement = $this->db->query(
            "SELECT d.duty_session_id, d.user_id, d.started_at, u.first_name, u.last_name
             FROM liso_duty_sessions d
             INNER JOIN users u ON u.user_id = d.user_id
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE d.active_marker = 1 AND d.ended_at IS NULL AND DATE(d.started_at) = CURDATE()
               AND u.status = 'Active' AND r.role_name = 'Lupon Member'
             ORDER BY d.started_at ASC"
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function start(int $userId): array
    {
        if (!$this->isLuponMember($userId)) {
            return ['success' => false, 'message' => 'Only active Lupon Members may start LISO duty.'];
        }

        $existing = $this->currentForUser($userId);
        if ($existing) {
            return ['success' => true, 'already_active' => true, 'session' => $existing, 'message' => 'Your LISO duty is already active.'];
        }

        try {
            $this->db->beginTransaction();
            $stale = $this->db->prepare(
                'UPDATE liso_duty_sessions
                 SET ended_at = NOW(), active_marker = NULL, ended_by = ?, end_reason = ?
                 WHERE user_id = ? AND active_marker = 1 AND ended_at IS NULL AND DATE(started_at) < CURDATE()'
            );
            $stale->execute([$userId, 'Automatically closed when a new duty day began.', $userId]);

            $insert = $this->db->prepare(
                'INSERT INTO liso_duty_sessions (user_id, started_by) VALUES (?, ?)'
            );
            $insert->execute([$userId, $userId]);
            $sessionId = (int) $this->db->lastInsertId();
            $this->db->commit();

            $session = $this->currentForUser($userId);
            $this->audit->log($userId, 'Started LISO duty', 'LISO Duty', $sessionId);
            return ['success' => true, 'session' => $session, 'message' => 'LISO duty started. You can now be assigned to screen walk-in intakes.'];
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $session = $this->currentForUser($userId);
                if ($session) {
                    return ['success' => true, 'already_active' => true, 'session' => $session, 'message' => 'Your LISO duty is already active.'];
                }
            }
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to start LISO duty. Please try again.'];
        }
    }

    public function end(int $userId): array
    {
        $session = $this->currentForUser($userId);
        if (!$session) {
            return ['success' => false, 'message' => 'There is no active LISO duty to end.'];
        }

        $statement = $this->db->prepare(
            'UPDATE liso_duty_sessions
             SET ended_at = NOW(), active_marker = NULL, ended_by = ?, end_reason = ?
             WHERE duty_session_id = ? AND user_id = ? AND active_marker = 1 AND ended_at IS NULL'
        );
        $statement->execute([$userId, 'Ended by Lupon Member.', $session['duty_session_id'], $userId]);
        if ($statement->rowCount() !== 1) {
            return ['success' => false, 'message' => 'Your LISO duty could not be ended. Please refresh and try again.'];
        }

        $this->audit->log($userId, 'Ended LISO duty', 'LISO Duty', (int) $session['duty_session_id']);
        return ['success' => true, 'message' => 'LISO duty ended. You are no longer available for new walk-in screenings.'];
    }

    private function isLuponMember(int $userId): bool
    {
        $statement = $this->db->prepare(
            "SELECT 1 FROM users u INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ? AND u.status = 'Active' AND r.role_name = 'Lupon Member' LIMIT 1"
        );
        $statement->execute([$userId]);
        return (bool) $statement->fetchColumn();
    }
}
