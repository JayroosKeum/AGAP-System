<?php
/**
 * Authoritative service gate for Mediation hearings.
 * File: backend/services/SummonServiceGate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class SummonServiceGate
{
    private PDO $db;

    private const SUCCESSFUL_STATUSES = [
        'Served',
        'Served Personal',
        'Served Substituted',
    ];

    private const REVIEW_STATUSES = [
        'Refused',
        'Served Refused',
        'Unlocatable',
        'Wrong Address',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? (new Database())->connect();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function forCase(int $caseId): array
    {
        if ($caseId < 1) {
            return $this->result(false, 'INVALID_CASE', 'A valid case ID is required.');
        }

        // Find latest Mediation hearing for this case
        $stmt = $this->db->prepare(
            'SELECT hearing_id, case_id, hearing_type, hearing_date, status
             FROM hearings
             WHERE case_id = ? AND hearing_type = \'Mediation\'
             ORDER BY hearing_date DESC, hearing_id DESC
             LIMIT 1'
        );
        $stmt->execute([$caseId]);
        $hearing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($hearing) {
            return $this->forHearing((int) $hearing['hearing_id']);
        }

        // If no mediation hearing yet, check deliveries directly by case_id
        $attempts = $this->deliveryAttempts($caseId, 0);
        if ($attempts === []) {
            return $this->result(
                false,
                'NO_SERVICE_ATTEMPT',
                'No summon service record found for this case.',
                ['case_id' => $caseId],
                [],
                false
            );
        }

        foreach ($attempts as $attempt) {
            $status = trim((string) ($attempt['delivery_status'] ?? ''));
            if (in_array($status, self::SUCCESSFUL_STATUSES, true)) {
                return $this->result(
                    true,
                    'SERVICE_CONFIRMED',
                    'A successful summon service record permits Mediation attendance.',
                    ['case_id' => $caseId],
                    $attempts,
                    false,
                    $attempt
                );
            }
        }

        $latest = $attempts[0];
        $latestStatus = trim((string) ($latest['delivery_status'] ?? 'Pending'));
        $reviewRequired = in_array($latestStatus, self::REVIEW_STATUSES, true);

        return $this->result(
            false,
            $reviewRequired ? 'PROCEDURAL_REVIEW_REQUIRED' : 'SERVICE_NOT_CONFIRMED',
            $reviewRequired
                ? 'The latest service result requires authorized procedural review before Mediation attendance can be recorded.'
                : 'Successful summon service has not been confirmed.',
            ['case_id' => $caseId],
            $attempts,
            $reviewRequired,
            $latest
        );
    }

    public function forHearing(int $hearingId): array
    {
        $hearing = $this->findHearing($hearingId);
        if (!$hearing) {
            return $this->result(false, 'HEARING_NOT_FOUND', 'The selected hearing was not found.');
        }

        if (strcasecmp((string) $hearing['hearing_type'], 'Mediation') !== 0) {
            return $this->result(
                true,
                'NOT_MEDIATION',
                'The summon-service gate does not apply to this hearing type.',
                $hearing
            );
        }

        $caseId = (int) ($hearing['case_id'] ?? 0);
        if ($caseId > 0) {
            $seqStmt = $this->db->prepare("
                SELECT COUNT(*) FROM hearings
                WHERE case_id = ? AND hearing_type IN ('Initial Hearing', 'Mediation') AND hearing_id <= ?
            ");
            $seqStmt->execute([$caseId, $hearingId]);
            $hearingSequence = (int) $seqStmt->fetchColumn() ?: 1;

            if ($hearingSequence >= 2) {
                return $this->result(
                    true,
                    'SUBSEQUENT_MEDIATION',
                    'Second or subsequent mediation session does not require re-issuance of summons. Attendance tracking is unlocked.',
                    $hearing,
                    [],
                    false
                );
            }
        }

        $attempts = $this->deliveryAttempts((int) $hearing['case_id'], $hearingId);
        if ($attempts === []) {
            return $this->result(
                false,
                'NO_SERVICE_ATTEMPT',
                'Record proof of summon service before recording Mediation attendance.',
                $hearing,
                [],
                false
            );
        }

        foreach ($attempts as $attempt) {
            $status = trim((string) ($attempt['delivery_status'] ?? ''));
            if (in_array($status, self::SUCCESSFUL_STATUSES, true)) {
                return $this->result(
                    true,
                    'SERVICE_CONFIRMED',
                    'A successful summon service record permits Mediation attendance.',
                    $hearing,
                    $attempts,
                    false,
                    $attempt
                );
            }
        }

        $latest = $attempts[0];
        $latestStatus = trim((string) ($latest['delivery_status'] ?? 'Pending'));
        $reviewRequired = in_array($latestStatus, self::REVIEW_STATUSES, true);

        return $this->result(
            false,
            $reviewRequired ? 'PROCEDURAL_REVIEW_REQUIRED' : 'SERVICE_NOT_CONFIRMED',
            $reviewRequired
                ? 'The latest service result requires authorized procedural review before Mediation attendance can be recorded.'
                : 'Successful summon service has not been confirmed. Mediation attendance remains locked.',
            $hearing,
            $attempts,
            $reviewRequired,
            $latest
        );
    }

    private function findHearing(int $hearingId): ?array
    {
        if ($hearingId < 1) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT hearing_id, case_id, hearing_type, hearing_date, status
             FROM hearings
             WHERE hearing_id = ?
             LIMIT 1'
        );
        $stmt->execute([$hearingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function deliveryAttempts(int $caseId, int $hearingId): array
    {
        $columns = $this->columnsFor('summon_deliveries');
        if ($columns === []) {
            return [];
        }

        $where = [];
        $params = [];

        if ($hearingId > 0 && isset($columns['hearing_id'])) {
            $where[] = 'hearing_id = ?';
            $params[] = $hearingId;
        }
        if ($caseId > 0 && isset($columns['case_id'])) {
            $where[] = 'case_id = ?';
            $params[] = $caseId;
        }
        if ($where === []) {
            return [];
        }

        $orderParts = [];
        if (isset($columns['attempted_at'])) {
            $orderParts[] = 'attempted_at DESC';
        }
        if (isset($columns['delivery_id'])) {
            $orderParts[] = 'delivery_id DESC';
        }
        $order = $orderParts ? ' ORDER BY ' . implode(', ', $orderParts) : '';

        $sql = 'SELECT * FROM summon_deliveries WHERE (' . implode(' OR ', $where) . ')' . $order;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function columnsFor(string $table): array
    {
        $database = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $this->db->prepare(
            'SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $stmt->execute([$database, $table]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $columns[(string) $column] = true;
        }
        return $columns;
    }

    private function result(
        bool $allowed,
        string $code,
        string $message,
        array $hearing = [],
        array $attempts = [],
        bool $proceduralReviewRequired = false,
        ?array $basis = null
    ): array {
        return [
            'allowed' => $allowed,
            'code' => $code,
            'message' => $message,
            'procedural_review_required' => $proceduralReviewRequired,
            'hearing' => $hearing,
            'basis_delivery' => $basis,
            'attempts' => $attempts,
        ];
    }
}
