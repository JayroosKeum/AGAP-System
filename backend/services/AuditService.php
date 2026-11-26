<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class AuditService
{
    private PDO $conn;
    private ?string $hmacKey;

    public function __construct(?PDO $connection = null)
    {
        $this->conn = $connection ?? (new Database())->connect();
        $rawKey = getenv('APP_AUDIT_HMAC_KEY') ?: ($_ENV['APP_AUDIT_HMAC_KEY'] ?? ($_SERVER['APP_AUDIT_HMAC_KEY'] ?? ''));
        if ($rawKey === '') {
            $rawKey = getenv('APP_PII_KEY') ?: ($_ENV['APP_PII_KEY'] ?? ($_SERVER['APP_PII_KEY'] ?? ''));
        }
        $this->hmacKey = is_string($rawKey) && trim($rawKey) !== '' ? hash('sha256', trim($rawKey), true) : null;
    }

    /**
     * Records an append-only audit entry with structured before/after state,
     * client IP address, and cryptographic hash-chain integrity fields.
     *
     * @param mixed $userId
     * @param string $action
     * @param string $module
     * @param mixed $recordId
     * @param array{before?: mixed, after?: mixed, ip_address?: string|null, strict?: bool} $context
     */
    public function log(
        $userId,
        $action,
        $module,
        $recordId = null,
        array $context = []
    ): bool {
        $strict = !empty($context['strict']);
        $ownsTx = !$this->conn->inTransaction();
        try {
            if ($ownsTx) {
                $this->conn->beginTransaction();
            }

            $normalizedUserId = ($userId !== null && $userId !== '' && (int) $userId > 0) ? (int) $userId : null;
            $normalizedRecordId = ($recordId !== null && $recordId !== '' && (int) $recordId > 0) ? (int) $recordId : null;
            $beforeJson = array_key_exists('before', $context)
                ? json_encode($context['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
            $afterJson = array_key_exists('after', $context)
                ? json_encode($context['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
            $ipAddress = array_key_exists('ip_address', $context)
                ? ($context['ip_address'] === null ? null : (string) $context['ip_address'])
                : $this->resolveClientIp();
            $auditDate = date('Y-m-d H:i:s');

            $prevStmt = $this->conn->query(
                'SELECT entry_hash FROM audit_trails WHERE entry_hash IS NOT NULL ORDER BY audit_id DESC LIMIT 1 FOR UPDATE'
            );
            $prevHash = $prevStmt ? ($prevStmt->fetchColumn() ?: str_repeat('0', 64)) : str_repeat('0', 64);

            $entryHash = $this->computeEntryHash(
                (string) $prevHash,
                $normalizedUserId,
                (string) $action,
                (string) $module,
                $normalizedRecordId,
                $beforeJson ?: null,
                $afterJson ?: null,
                $ipAddress,
                $auditDate
            );

            $stmt = $this->conn->prepare("
                INSERT INTO audit_trails
                (
                    user_id,
                    action,
                    module_name,
                    affected_record,
                    before_state,
                    after_state,
                    ip_address,
                    prev_hash,
                    entry_hash,
                    audit_date
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $ok = $stmt->execute([
                $normalizedUserId,
                (string) $action,
                (string) $module,
                $normalizedRecordId,
                $beforeJson ?: null,
                $afterJson ?: null,
                $ipAddress,
                $prevHash,
                $entryHash,
                $auditDate,
            ]);

            if ($ownsTx) {
                $this->conn->commit();
            }

            if (!$ok && $strict) {
                throw new RuntimeException('Audit log insert returned false.');
            }
            return $ok;
        } catch (Throwable $e) {
            if ($ownsTx && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('AuditService error: ' . $e->getMessage());
            if ($strict) {
                throw new RuntimeException('Unable to write mandatory audit trail record: ' . $e->getMessage(), 0, $e);
            }
            return false;
        }
    }

    /**
     * Strict transactional audit helper; throws RuntimeException on write failure
     * so caller transactions roll back atomically.
     *
     * @param array{before?: mixed, after?: mixed, ip_address?: string|null} $context
     */
    public function logStrict(
        ?int $userId,
        string $action,
        string $module,
        ?int $recordId = null,
        array $context = []
    ): void {
        $context['strict'] = true;
        $this->log($userId, $action, $module, $recordId, $context);
    }

    /**
     * Verifies the cryptographic hash chain across all chained audit_trails records.
     *
     * @return array{valid: bool, checked: int, broken_audit_id: int|null, reason: string|null}
     */
    public function verifyChain(?int $limit = null): array
    {
        $sql = '/* pii:raw */ SELECT audit_id, user_id, action, module_name, affected_record, before_state, after_state, ip_address, prev_hash, entry_hash, audit_date
                FROM audit_trails
                WHERE entry_hash IS NOT NULL
                ORDER BY audit_id ASC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $rows = $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $expectedPrev = str_repeat('0', 64);
        $checked = 0;

        foreach ($rows as $row) {
            $checked++;
            $auditId = (int) $row['audit_id'];
            if ((string) $row['prev_hash'] !== $expectedPrev) {
                return [
                    'valid' => false,
                    'checked' => $checked,
                    'broken_audit_id' => $auditId,
                    'reason' => 'Previous hash link mismatch (possible deletion or reordering).',
                ];
            }

            $recomputed = $this->computeEntryHash(
                (string) $row['prev_hash'],
                $row['user_id'] !== null ? (int) $row['user_id'] : null,
                (string) $row['action'],
                (string) $row['module_name'],
                $row['affected_record'] !== null ? (int) $row['affected_record'] : null,
                $row['before_state'] !== null ? (string) $row['before_state'] : null,
                $row['after_state'] !== null ? (string) $row['after_state'] : null,
                $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                (string) $row['audit_date']
            );

            if (!hash_equals($recomputed, (string) $row['entry_hash'])) {
                return [
                    'valid' => false,
                    'checked' => $checked,
                    'broken_audit_id' => $auditId,
                    'reason' => 'Entry hash mismatch (record payload was altered).',
                ];
            }

            $expectedPrev = (string) $row['entry_hash'];
        }

        return [
            'valid' => true,
            'checked' => $checked,
            'broken_audit_id' => null,
            'reason' => null,
        ];
    }

    private function computeEntryHash(
        string $prevHash,
        ?int $userId,
        string $action,
        string $module,
        ?int $recordId,
        ?string $beforeJson,
        ?string $afterJson,
        ?string $ipAddress,
        string $auditDate
    ): string {
        $payload = json_encode([
            $prevHash,
            $userId,
            $action,
            $module,
            $recordId,
            $beforeJson,
            $afterJson,
            $ipAddress,
            $auditDate,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->hmacKey !== null) {
            return hash_hmac('sha256', (string) $payload, $this->hmacKey);
        }
        return hash('sha256', (string) $payload);
    }

    private function resolveClientIp(): ?string
    {
        $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $first = trim(explode(',', $raw)[0]);
        return filter_var($first, FILTER_VALIDATE_IP) ? $first : null;
    }
}
