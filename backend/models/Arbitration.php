<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/CaseStage.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/KpFormService.php';
require_once __DIR__ . '/../services/MediationDeadlineService.php';

class Arbitration
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?: (new Database())->connect();
    }

    protected function createKpFormService(): KpFormService
    {
        return new KpFormService($this->conn);
    }

    private static function isValidIsoDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }

    private function isPartyToCase(int $caseId, int $residentId): bool
    {
        $stmt = $this->conn->prepare(
            'SELECT 1
               FROM cases c
               INNER JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id
              WHERE c.case_id = ? AND cp.resident_id = ?
              LIMIT 1'
        );
        $stmt->execute([$caseId, $residentId]);
        return (bool) $stmt->fetchColumn();
    }

    public function create(array $data): bool
    {
        $stmt = $this->conn->prepare("
            INSERT INTO arbitration_records (case_id, agreement_date, award_date, award_details)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                agreement_date = VALUES(agreement_date),
                award_date = VALUES(award_date),
                award_details = VALUES(award_details)
        ");

        return $stmt->execute([
            $data['case_id'],
            $data['agreement_date'],
            $data['award_date'],
            $data['award_details'],
        ]);
    }

    public function getByCase(int $caseId): array|false
    {
        $stmt = $this->conn->prepare("
            SELECT ar.*,
                   gd.file_path AS award_file_path,
                   agd.file_path AS agreement_file_path
              FROM arbitration_records ar
              LEFT JOIN generated_documents gd ON gd.document_id = ar.award_document_id
              LEFT JOIN generated_documents agd ON agd.document_id = ar.agreement_document_id
             WHERE ar.case_id = ?
             LIMIT 1
        ");
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if (!empty($row['award_document_id'])) {
            $row['award_pdf_url'] = '../../backend/api/documents/download.php?document_id=' . (int) $row['award_document_id'];
        }
        if (!empty($row['agreement_document_id'])) {
            $row['agreement_pdf_url'] = '../../backend/api/documents/download.php?document_id=' . (int) $row['agreement_document_id'];
        }
        return $row;
    }

    /**
     * Records a signed written Agreement for Arbitration (KP Form 14) with its actual signing date.
     */
    public function recordAgreement(array $data, int $userId): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$caseId) {
            return ['success' => false, 'message' => 'A valid case ID is required.'];
        }

        $agreementDate = trim((string) ($data['agreement_date'] ?? ''));
        if (!self::isValidIsoDate($agreementDate)) {
            return ['success' => false, 'message' => 'A valid written arbitration agreement signing date (YYYY-MM-DD) is required.'];
        }
        if ($agreementDate > date('Y-m-d')) {
            return ['success' => false, 'message' => 'Arbitration agreement signing date cannot be in the future.'];
        }

        if (isset($data['agreement_signed']) && empty($data['agreement_signed'])) {
            return ['success' => false, 'message' => 'The written Agreement for Arbitration (KP Form 14) must be signed by the parties.'];
        }

        $remarks = trim((string) ($data['remarks'] ?? $data['agreement_notes'] ?? ''));
        $repudiationDeadline = date('Y-m-d', strtotime($agreementDate . ' +5 days'));
        $awardDeadline = date('Y-m-d', strtotime($agreementDate . ' +15 days'));

        $nested = $this->conn->inTransaction();
        $generatedAbsolutePath = null;
        try {
            if (!$nested) {
                $this->conn->beginTransaction();
            }

            $caseStmt = $this->conn->prepare('SELECT case_id, complaint_id, case_status FROM cases WHERE case_id = ? FOR UPDATE');
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
            if (!$case) {
                throw new RuntimeException('Case not found.');
            }
            if (in_array((string) $case['case_status'], ['Settled', 'Resolved', 'Completed', 'Dismissed', 'DISMISSED_BARRED', 'Archived', 'CFA Issued', 'Repudiated'], true)) {
                throw new RuntimeException("Cannot record an arbitration agreement for a case in '{$case['case_status']}' status.");
            }

            $arbStmt = $this->conn->prepare('SELECT * FROM arbitration_records WHERE case_id = ? LIMIT 1 FOR UPDATE');
            $arbStmt->execute([$caseId]);
            $existing = $arbStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && ($existing['agreement_repudiation_status'] ?? '') === 'Repudiated') {
                throw new RuntimeException('This arbitration agreement has already been repudiated.');
            }
            if ($existing && ($existing['award_status'] ?? '') === 'Finalized' && !empty($existing['award_date'])) {
                throw new RuntimeException('An Arbitration Award has already been finalized for this case.');
            }

            $detailsText = $remarks !== '' ? $remarks : 'Written Agreement for Arbitration (KP Form 14) signed by parties under RA 7160 Sec. 413.';
            if ($existing) {
                $upd = $this->conn->prepare("
                    UPDATE arbitration_records
                       SET agreement_date = ?,
                           agreement_signed = 1,
                           agreement_repudiation_status = CASE
                               WHEN date(?) < DATE_SUB(CURDATE(), INTERVAL 5 DAY) THEN 'Expired'
                               ELSE 'Within Repudiation Period'
                           END,
                           repudiation_deadline = ?,
                           award_deadline = ?,
                           award_details = CASE WHEN TRIM(COALESCE(award_details, '')) = '' THEN ? ELSE award_details END,
                           updated_at = NOW()
                     WHERE arbitration_id = ?
                ");
                $upd->execute([$agreementDate, $agreementDate, $repudiationDeadline, $awardDeadline, $detailsText, $existing['arbitration_id']]);
                $arbitrationId = (int) $existing['arbitration_id'];
            } else {
                $repStatus = (date('Y-m-d') > $repudiationDeadline) ? 'Expired' : 'Within Repudiation Period';
                $ins = $this->conn->prepare("
                    INSERT INTO arbitration_records (
                        case_id, agreement_date, agreement_signed, agreement_repudiation_status,
                        repudiation_deadline, award_deadline, award_details
                    ) VALUES (?, ?, 1, ?, ?, ?, ?)
                ");
                $ins->execute([$caseId, $agreementDate, $repStatus, $repudiationDeadline, $awardDeadline, $detailsText]);
                $arbitrationId = (int) $this->conn->lastInsertId();
            }

            $this->conn->prepare("UPDATE cases SET case_status = 'Arbitration', updated_at = NOW() WHERE case_id = ?")->execute([$caseId]);
            $this->conn->prepare("UPDATE complaints SET status = 'Arbitration', updated_at = NOW() WHERE complaint_id = ?")->execute([(int) $case['complaint_id']]);

            $this->conn->prepare("
                INSERT INTO case_stages (case_id, stage_type, stage_status, outcome, outcome_remarks, started_at)
                VALUES (?, 'Arbitration', 'In Progress', 'Arbitration', ?, NOW())
                ON DUPLICATE KEY UPDATE
                    stage_status = 'In Progress',
                    outcome = 'Arbitration',
                    outcome_remarks = VALUES(outcome_remarks),
                    updated_at = CURRENT_TIMESTAMP
            ")->execute([$caseId, $detailsText]);

            $kpService = $this->createKpFormService();
            $genDoc = $kpService->generateAndPersistForm('KP Form 14', $caseId, $userId ?: 1, [
                'strict_pdf' => true,
                'agreement_date' => $agreementDate,
            ]);
            if (empty($genDoc['success']) || empty($genDoc['document_id'])) {
                throw new RuntimeException($genDoc['message'] ?? 'Failed to render KP Form 14 Agreement for Arbitration PDF.');
            }
            if (!empty($genDoc['file_path'])) {
                $generatedAbsolutePath = dirname(__DIR__, 2) . '/' . ltrim((string) $genDoc['file_path'], '/');
            }

            $this->conn->prepare("UPDATE arbitration_records SET agreement_document_id = ? WHERE arbitration_id = ?")
                ->execute([(int) $genDoc['document_id'], $arbitrationId]);

            $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, 'Arbitration', ?, ?)
            ")->execute([
                $caseId,
                "Written Agreement for Arbitration (KP Form 14) signed on {$agreementDate}. 5-day repudiation window ends {$repudiationDeadline}; award window ends {$awardDeadline}.",
                $userId ?: null,
            ]);

            $audit = new AuditService($this->conn);
            if (!$audit->log($userId ?: 1, 'Recorded Signed Agreement for Arbitration (KP Form 14)', 'Arbitration', $caseId)) {
                throw new RuntimeException('Unable to write audit trail for arbitration agreement.');
            }

            if (!$nested) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'message' => 'Written Agreement for Arbitration (KP Form 14) recorded.',
                'case_id' => $caseId,
                'arbitration_id' => $arbitrationId,
                'agreement_date' => $agreementDate,
                'repudiation_deadline' => $repudiationDeadline,
                'award_deadline' => $awardDeadline,
                'agreement_document_id' => (int) $genDoc['document_id'],
            ];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            if ($generatedAbsolutePath && is_file($generatedAbsolutePath)) {
                @unlink($generatedAbsolutePath);
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Records sworn repudiation of KP Form 14 within 5 calendar days from agreement_date (RA 7160 Sec. 413).
     */
    public function repudiateAgreement(array $data, int $userId): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $residentId = filter_var($data['repudiated_by'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string) ($data['repudiation_reason'] ?? ''));
        $ground = trim((string) ($data['repudiation_ground'] ?? ''));

        if (!$caseId || !$residentId) {
            return ['success' => false, 'message' => 'Valid case_id and repudiating party resident_id are required.'];
        }
        if ($reason === '') {
            return ['success' => false, 'message' => 'A sworn statement alleging Fraud, Violence, or Intimidation is required.'];
        }
        if ($ground === '' && preg_match('/\b(fraud|violence|intimidation)\b/i', $reason, $m)) {
            $ground = ucfirst(strtolower($m[1]));
        }
        if (!in_array($ground, ['Fraud', 'Violence', 'Intimidation'], true)) {
            return ['success' => false, 'message' => 'Arbitration agreement repudiation must state Fraud, Violence, or Intimidation.'];
        }
        if (!$this->isPartyToCase($caseId, $residentId)) {
            return ['success' => false, 'message' => 'Only a recorded party to this case may repudiate the arbitration agreement.'];
        }

        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) {
                $this->conn->beginTransaction();
            }

            $caseStmt = $this->conn->prepare('SELECT case_id, complaint_id, case_status FROM cases WHERE case_id = ? FOR UPDATE');
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
            if (!$case) {
                throw new RuntimeException('Case not found.');
            }

            $arbStmt = $this->conn->prepare('SELECT * FROM arbitration_records WHERE case_id = ? LIMIT 1 FOR UPDATE');
            $arbStmt->execute([$caseId]);
            $arb = $arbStmt->fetch(PDO::FETCH_ASSOC);
            if (!$arb || empty($arb['agreement_date']) || empty($arb['agreement_signed'])) {
                throw new RuntimeException('No signed Agreement for Arbitration (KP Form 14) exists for this case.');
            }
            if (($arb['agreement_repudiation_status'] ?? '') === 'Repudiated' || !empty($arb['agreement_repudiated_at'])) {
                throw new RuntimeException('This Agreement for Arbitration has already been repudiated.');
            }
            if (($arb['award_status'] ?? '') === 'Finalized' && !empty($arb['award_date'])) {
                throw new RuntimeException('Cannot repudiate an agreement after an Arbitration Award has been finalized.');
            }

            $repDeadline = $arb['repudiation_deadline'] ?: date('Y-m-d', strtotime($arb['agreement_date'] . ' +5 days'));
            if (date('Y-m-d') > $repDeadline) {
                throw new RuntimeException("The 5-day statutory repudiation window for KP Form 14 expired on {$repDeadline}.");
            }

            $this->conn->prepare("
                UPDATE arbitration_records
                   SET agreement_repudiation_status = 'Repudiated',
                       agreement_repudiated_by = ?,
                       agreement_repudiated_at = NOW(),
                       agreement_repudiation_reason = ?,
                       repudiation_deadline = ?,
                       updated_at = NOW()
                 WHERE arbitration_id = ?
            ")->execute([$residentId, "{$ground}: {$reason}", $repDeadline, $arb['arbitration_id']]);

            $this->conn->prepare("UPDATE cases SET case_status = 'Repudiated', updated_at = NOW() WHERE case_id = ?")->execute([$caseId]);
            $this->conn->prepare("UPDATE complaints SET status = 'Repudiated', updated_at = NOW() WHERE complaint_id = ?")->execute([(int) $case['complaint_id']]);

            $this->conn->prepare("
                INSERT INTO case_stages (case_id, stage_type, stage_status, outcome, outcome_remarks, completed_at)
                VALUES (?, 'Arbitration', 'Completed', 'Repudiated', ?, NOW())
                ON DUPLICATE KEY UPDATE
                    stage_status = 'Completed',
                    outcome = 'Repudiated',
                    outcome_remarks = VALUES(outcome_remarks),
                    completed_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
            ")->execute([$caseId, "Arbitration agreement repudiated under RA 7160 Sec. 413 ({$ground}). {$reason}"]);

            $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, 'Repudiated', ?, ?)
            ")->execute([
                $caseId,
                "Agreement for Arbitration (KP Form 14) repudiated within 5-day window ({$ground}). Unlocked KP Form 20 (CFA) for standard issuance.",
                $userId ?: null,
            ]);

            $audit = new AuditService($this->conn);
            if (!$audit->log($userId ?: 1, 'Repudiated Agreement for Arbitration (KP Form 14)', 'Arbitration', $caseId)) {
                throw new RuntimeException('Unable to write audit trail for arbitration agreement repudiation.');
            }

            if (!$nested) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'message' => 'Arbitration agreement repudiation recorded. KP Form 20 (CFA) is unlocked.',
                'case_id' => $caseId,
                'cfa_unlocked' => true,
            ];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Issues KP Form 15 Arbitration Award atomically under lock.
     */
    public function issueAward(array $data, int $userId): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$caseId) {
            return ['success' => false, 'message' => 'A valid case ID is required.'];
        }

        $awardDate = trim((string) ($data['award_date'] ?? ''));
        if ($awardDate === '' || !self::isValidIsoDate($awardDate)) {
            return ['success' => false, 'message' => 'A valid arbitration award date (YYYY-MM-DD) is required.'];
        }

        $awardDetails = trim((string) ($data['award_details'] ?? $data['award_terms'] ?? ''));
        if ($awardDetails === '') {
            return ['success' => false, 'message' => 'Specific terms and findings of the arbitration award are required.'];
        }

        $arbitratorName = trim((string) ($data['arbitrator_presider'] ?? $data['arbitrator_name'] ?? ''));

        $nested = $this->conn->inTransaction();
        $generatedAbsolutePath = null;
        try {
            if (!$nested) {
                $this->conn->beginTransaction();
            }

            $caseStmt = $this->conn->prepare('SELECT case_id, complaint_id, case_status FROM cases WHERE case_id = ? FOR UPDATE');
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
            if (!$case) {
                throw new RuntimeException('Case not found.');
            }

            $arbStmt = $this->conn->prepare('SELECT * FROM arbitration_records WHERE case_id = ? LIMIT 1 FOR UPDATE');
            $arbStmt->execute([$caseId]);
            $existing = $arbStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && ($existing['award_status'] ?? '') === 'Finalized' && !empty($existing['award_date']) && !empty($existing['award_document_id'])) {
                $docStmt = $this->conn->prepare('SELECT document_id, file_path FROM generated_documents WHERE document_id = ? LIMIT 1');
                $docStmt->execute([(int) $existing['award_document_id']]);
                $docRow = $docStmt->fetch(PDO::FETCH_ASSOC);
                $docExists = $docRow && !empty($docRow['file_path']) && is_file(dirname(__DIR__, 2) . '/' . ltrim((string) $docRow['file_path'], '/'));
                if ($docExists) {
                    if (!$nested) {
                        $this->conn->commit();
                    }
                    return [
                        'success' => true,
                        'idempotent' => true,
                        'message' => 'Arbitration award was already issued and finalized; returning the existing award.',
                        'case_id' => $caseId,
                        'arbitration_id' => (int) $existing['arbitration_id'],
                        'award_date' => $existing['award_date'],
                        'agreement_date' => $existing['agreement_date'],
                        'document_id' => (int) $existing['award_document_id'],
                        'pdf_url' => '../../backend/api/documents/download.php?document_id=' . (int) $existing['award_document_id'],
                    ];
                }
            }

            if (in_array((string) $case['case_status'], ['CFA Issued', 'Dismissed', 'DISMISSED_BARRED', 'Archived', 'Repudiated'], true)) {
                throw new RuntimeException("Cannot issue an Arbitration Award when case status is '{$case['case_status']}'.");
            }

            // Require a saved written arbitration agreement belonging to this case; never fall back to request or server date.
            if (!$existing || empty($existing['agreement_date']) || !self::isValidIsoDate((string) $existing['agreement_date'])) {
                throw new RuntimeException('A recorded written Agreement for Arbitration (KP Form 14) with its actual signing date is required before issuing an Arbitration Award.');
            }
            if (empty($existing['agreement_signed'])) {
                throw new RuntimeException('The Agreement for Arbitration (KP Form 14) must be signed by the parties before issuing an Arbitration Award.');
            }
            if (($existing['agreement_repudiation_status'] ?? '') === 'Repudiated' || !empty($existing['agreement_repudiated_at'])) {
                throw new RuntimeException('Cannot issue an Arbitration Award because the Agreement for Arbitration was repudiated.');
            }

            $agreementDate = substr((string) $existing['agreement_date'], 0, 10);
            $earliestAllowed = date('Y-m-d', strtotime($agreementDate . ' +6 days'));
            $latestAllowed = date('Y-m-d', strtotime($agreementDate . ' +15 days'));

            if ($awardDate < $earliestAllowed) {
                $formattedEarliest = date('F j, Y', strtotime($earliestAllowed));
                $formattedAgreement = date('F j, Y', strtotime($agreementDate));
                throw new RuntimeException("Pursuant to RA 7160 Sec. 413, the Arbitration Award (KP Form 15) cannot be rendered earlier than the 6th day after signing the agreement to arbitrate (Day 0: {$formattedAgreement}). Earliest permitted date is {$formattedEarliest}.");
            }
            if ($awardDate > $latestAllowed) {
                $formattedLatest = date('F j, Y', strtotime($latestAllowed));
                $formattedAgreement = date('F j, Y', strtotime($agreementDate));
                throw new RuntimeException("Pursuant to RA 7160 Sec. 413, the Arbitration Award (KP Form 15) must be rendered within fifteen (15) calendar days from the date the arbitration agreement was signed (Day 0: {$formattedAgreement}). Latest permitted date is {$formattedLatest}.");
            }

            $finalityDate = MediationDeadlineService::calculateBusinessDays($awardDate, 10);
            $sixMonthDeadline = (new DateTimeImmutable($awardDate))->modify('+6 months')->format('Y-m-d');
            $repDeadline = date('Y-m-d', strtotime($agreementDate . ' +5 days'));

            $updArb = $this->conn->prepare("
                UPDATE arbitration_records
                   SET award_date = ?,
                       award_details = ?,
                       award_status = 'Finalized',
                       agreement_repudiation_status = 'Expired',
                       arbitrator_name = COALESCE(NULLIF(?, ''), arbitrator_name),
                       issued_by = ?,
                       issued_at = NOW(),
                       finalized_at = NOW(),
                       repudiation_deadline = COALESCE(repudiation_deadline, ?),
                       award_deadline = COALESCE(award_deadline, ?),
                       six_month_deadline = ?,
                       updated_at = NOW()
                 WHERE arbitration_id = ?
            ");
            $updArb->execute([
                $awardDate,
                $awardDetails,
                $arbitratorName,
                $userId ?: null,
                $repDeadline,
                $latestAllowed,
                $sixMonthDeadline,
                (int) $existing['arbitration_id'],
            ]);

            // Render and commit KP Form 15 PDF inside the same atomic transaction
            $kpService = $this->createKpFormService();
            $genDoc = $kpService->generateAndPersistForm('KP Form 15', $caseId, $userId ?: 1, [
                'strict_pdf' => true,
                'award_date' => $awardDate,
                'award_details' => $awardDetails,
            ]);
            if (empty($genDoc['success']) || empty($genDoc['document_id']) || empty($genDoc['file_path'])) {
                throw new RuntimeException($genDoc['message'] ?? 'Document generation failed for KP Form 15.');
            }
            $docId = (int) $genDoc['document_id'];
            $generatedAbsolutePath = dirname(__DIR__, 2) . '/' . ltrim((string) $genDoc['file_path'], '/');

            $this->conn->prepare("UPDATE arbitration_records SET award_document_id = ? WHERE arbitration_id = ?")
                ->execute([$docId, (int) $existing['arbitration_id']]);

            // Upsert Arbitration stage outcome & update case/complaint status atomically
            $this->conn->prepare("
                INSERT INTO case_stages (case_id, stage_type, stage_status, outcome, outcome_remarks, completed_at)
                VALUES (?, 'Arbitration', 'Completed', 'Arbitration Award', ?, NOW())
                ON DUPLICATE KEY UPDATE
                    stage_status = 'Completed',
                    outcome = 'Arbitration Award',
                    outcome_remarks = VALUES(outcome_remarks),
                    completed_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
            ")->execute([$caseId, $awardDetails]);

            $this->conn->prepare("UPDATE cases SET case_status = 'Settled', updated_at = NOW() WHERE case_id = ?")->execute([$caseId]);
            $this->conn->prepare("UPDATE complaints SET status = 'Settled', updated_at = NOW() WHERE complaint_id = ?")->execute([(int) $case['complaint_id']]);

            // Record finality / transmittal & execution deadlines in case_deadlines
            $dlStmt = $this->conn->prepare("
                INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                VALUES (?, ?, ?, 'Pending')
                ON DUPLICATE KEY UPDATE due_date = VALUES(due_date), status = 'Pending', completed_at = NULL
            ");
            $dlStmt->execute([$caseId, 'Arbitration Award Finality', $finalityDate]);
            $dlStmt->execute([$caseId, 'Execution Period', $sixMonthDeadline]);

            $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, 'Settled', ?, ?)
            ")->execute([
                $caseId,
                "Arbitration Award (KP Form 15) rendered on {$awardDate} pursuant to Agreement dated {$agreementDate}. Finality/transmittal date: {$finalityDate}.",
                $userId ?: null,
            ]);

            $audit = new AuditService($this->conn);
            if (!$audit->log($userId ?: 1, 'Rendered Arbitration Award (KP Form 15)', 'Arbitration', $caseId)) {
                throw new RuntimeException('Unable to write audit trail for arbitration award.');
            }

            if (!$nested) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'message' => 'Arbitration award successfully rendered and recorded.',
                'case_id' => $caseId,
                'arbitration_id' => (int) $existing['arbitration_id'],
                'award_date' => $awardDate,
                'agreement_date' => $agreementDate,
                'finality_date' => $finalityDate,
                'six_month_deadline' => $sixMonthDeadline,
                'document_id' => $docId,
                'pdf_url' => '../../backend/api/documents/download.php?document_id=' . $docId,
            ];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            if ($generatedAbsolutePath && is_file($generatedAbsolutePath)) {
                @unlink($generatedAbsolutePath);
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
