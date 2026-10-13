<?php
/**
 * AGAP summon delivery data-access model.
 * Copy to: backend/models/SummonDelivery.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/SummonDeliveryStatus.php';
require_once __DIR__ . '/../services/SummonDeliveryValidator.php';

final class SummonDelivery
{
    private PDO $db;
    private SummonDeliveryValidator $validator;

    public function __construct()
    {
        $this->db = (new Database())->connect();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->validator = new SummonDeliveryValidator();
    }

    public function recordAttempt(array $input, int $actorUserId): array
    {
        $documentId = filter_var($input['document_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $partyId = filter_var($input['party_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        $errors = [];
        if (!$documentId) {
            $errors['document_id'] = 'A valid summons document is required.';
        }
        if (!$caseId) {
            $errors['case_id'] = 'A valid case is required.';
        }

        $validation = $this->validator->validate($input);
        $errors = array_merge($errors, $validation['errors']);

        if ($errors !== []) {
            return [
                'success' => false,
                'message' => 'Please correct the summon delivery information.',
                'errors' => $errors,
            ];
        }

        $data = $validation['normalized'];
        $partyType = trim((string) ($input['party_type'] ?? 'Respondent'));
        if (!in_array($partyType, ['Complainant', 'Respondent', 'Witness', 'Other'], true)) {
            return [
                'success' => false,
                'message' => 'The selected party type is invalid.',
                'errors' => ['party_type' => 'Select a valid party type.'],
            ];
        }

        if (!$this->documentBelongsToCase((int) $documentId, (int) $caseId)) {
            return [
                'success' => false,
                'message' => 'The selected summons document does not belong to this case.',
                'errors' => ['document_id' => 'Summons and case reference do not match.'],
            ];
        }

        $attemptNumber = $this->nextAttemptNumber((int) $documentId, $partyId ? (int) $partyId : null, $partyType);
        $servedAt = SummonDeliveryStatus::isSuccessfulService($data['delivery_status'])
            ? $data['attempted_at']
            : null;

        $hearingStmt = $this->db->prepare('SELECT hearing_id FROM hearings WHERE case_id = ? ORDER BY hearing_date DESC, hearing_id DESC LIMIT 1');
        $hearingStmt->execute([(int) $caseId]);
        $hearingId = $hearingStmt->fetchColumn() ?: null;

        $this->db->beginTransaction();
        try {
            $sql = <<<'SQL'
INSERT INTO summon_deliveries (
    hearing_id,
    document_id,
    case_id,
    party_id,
    resident_id,
    party_type,
    attempt_number,
    attempt_count,
    service_method,
    delivery_status,
    attempted_at,
    served_at,
    served_by,
    served_by_user_id,
    recipient_name,
    service_address,
    remarks,
    proof_file_path,
    created_by
) VALUES (
    :hearing_id,
    :document_id,
    :case_id,
    :party_id,
    :resident_id,
    :party_type,
    :attempt_number,
    :attempt_count,
    :service_method,
    :delivery_status,
    :attempted_at,
    :served_at,
    :served_by,
    :served_by_user_id,
    :recipient_name,
    :service_address,
    :remarks,
    :proof_file_path,
    :created_by
)
SQL;
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':hearing_id' => $hearingId ? (int) $hearingId : null,
                ':document_id' => (int) $documentId,
                ':case_id' => (int) $caseId,
                ':party_id' => $partyId ? (int) $partyId : null,
                ':resident_id' => $partyId ? (int) $partyId : null,
                ':party_type' => $partyType,
                ':attempt_number' => $attemptNumber,
                ':attempt_count' => $attemptNumber,
                ':service_method' => $data['service_method'],
                ':delivery_status' => $data['delivery_status'],
                ':attempted_at' => date('Y-m-d H:i:s', strtotime($data['attempted_at'])),
                ':served_at' => $servedAt ? date('Y-m-d H:i:s', strtotime($servedAt)) : null,
                ':served_by' => $actorUserId,
                ':served_by_user_id' => $actorUserId,
                ':recipient_name' => $data['recipient_name'] !== '' ? $data['recipient_name'] : null,
                ':service_address' => $data['service_address'],
                ':remarks' => $data['remarks'] !== '' ? $data['remarks'] : null,
                ':proof_file_path' => null,
                ':created_by' => $actorUserId,
            ]);

            $deliveryId = (int) $this->db->lastInsertId();
            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Summon delivery attempt recorded successfully.',
                'data' => [
                    'delivery_id' => $deliveryId,
                    'document_id' => (int) $documentId,
                    'case_id' => (int) $caseId,
                    'attempt_number' => $attemptNumber,
                    'delivery_status' => $data['delivery_status'],
                    'mediation_unlocked' => SummonDeliveryStatus::canUnlockMediation($data['delivery_status']),
                    'procedural_review_required' => SummonDeliveryStatus::requiresProceduralReview($data['delivery_status']),
                ],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Summon delivery save failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'The delivery attempt could not be recorded.',
                'errors' => [],
            ];
        }
    }

    public function historyForCase(int $caseId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM summon_deliveries WHERE case_id = ? ORDER BY attempted_at ASC, delivery_id ASC'
        );
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function nextAttemptNumber(int $documentId, ?int $partyId, string $partyType): int
    {
        if ($partyId !== null) {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM summon_deliveries WHERE document_id = ? AND party_id = ?'
            );
            $stmt->execute([$documentId, $partyId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM summon_deliveries WHERE document_id = ? AND party_id IS NULL AND party_type = ?'
            );
            $stmt->execute([$documentId, $partyType]);
        }
        return max(1, (int) $stmt->fetchColumn());
    }

    private function documentBelongsToCase(int $documentId, int $caseId): bool
    {
        $candidates = [
            ['table' => 'documents', 'id' => 'document_id'],
            ['table' => 'generated_documents', 'id' => 'document_id'],
            ['table' => 'case_documents', 'id' => 'document_id'],
        ];

        foreach ($candidates as $candidate) {
            if (!$this->tableHasColumns($candidate['table'], [$candidate['id'], 'case_id'])) {
                continue;
            }
            $table = $candidate['table'];
            $id = $candidate['id'];
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$id}` = ? AND `case_id` = ?");
            $stmt->execute([$documentId, $caseId]);
            return (int) $stmt->fetchColumn() > 0;
        }

        // Fail closed. The project must map the actual document table before saving.
        return false;
    }

    private function tableHasColumns(string $table, array $columns): bool
    {
        $database = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = "SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$database, $table], $columns));
        return (int) $stmt->fetchColumn() === count($columns);
    }
}
