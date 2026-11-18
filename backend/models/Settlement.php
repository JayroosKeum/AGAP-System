<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/MediationDeadlineService.php';

class Settlement
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
    }

    protected function createKpFormService(): KpFormService
    {
        require_once __DIR__ . '/../services/KpFormService.php';
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

    /** Keeps the calendar-based repudiation and jurisdiction clocks authoritative. */
    private function refreshStatutoryStates(): void
    {
        $this->conn->exec("UPDATE settlements
            SET repudiation_status = 'Enforceable', updated_at = NOW()
            WHERE repudiation_status = 'Within Repudiation Period'
              AND settlement_status = 'Signed and Attested'
              AND repudiation_deadline IS NOT NULL
              AND repudiation_deadline < CURDATE()");

        $this->conn->exec("UPDATE execution_proceedings ep
            INNER JOIN settlements s ON s.settlement_id = ep.settlement_id
            SET ep.execution_status = 'Lapsed to MTC', ep.updated_at = NOW()
            WHERE ep.execution_status IN ('Motion Filed', 'Hearing Scheduled', 'Grace Period Active')
              AND s.six_month_deadline IS NOT NULL
              AND s.six_month_deadline < CURDATE()");
    }

    private function isPartyToCase(int $caseId, int $residentId): bool
    {
        $stmt = $this->conn->prepare('SELECT 1 FROM cases c INNER JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id WHERE c.case_id = ? AND cp.resident_id = ? LIMIT 1');
        $stmt->execute([$caseId, $residentId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Retrieves paginated settlements with case details, milestone counts, and repudiation tracking.
     */
    public function getAll(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.case_number LIKE :kw OR co.complaint_number LIKE :kw OR co.complaint_title LIKE :kw OR s.agreement_details LIKE :kw)';
            $params[':kw'] = '%' . $q . '%';
        }

        $compliance = trim((string) ($filters['compliance_status'] ?? ''));
        if ($compliance !== '' && in_array($compliance, ['Pending', 'Partially Paid', 'Fully Paid', 'Overdue', 'Breached', 'Complied', 'Violated'], true)) {
            $where[] = 's.compliance_status = :compliance';
            $params[':compliance'] = $compliance;
        }

        $repudiation = trim((string) ($filters['repudiation_status'] ?? ''));
        if ($repudiation !== '' && in_array($repudiation, ['Within Repudiation Period', 'Repudiation Expired', 'Repudiated', 'Enforceable'], true)) {
            $where[] = 's.repudiation_status = :repudiation';
            $params[':repudiation'] = $repudiation;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $this->refreshStatutoryStates();

        $countSql = "
            SELECT COUNT(*) 
            FROM settlements s
            INNER JOIN cases c ON c.case_id = s.case_id
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            $whereClause
        ";
        $stmtCount = $this->conn->prepare($countSql);
        foreach ($params as $k => $v) {
            $stmtCount->bindValue($k, $v);
        }
        $stmtCount->execute();
        $totalRecords = (int) $stmtCount->fetchColumn();

        $totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $perPage) : 1;
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT s.*, 
                   c.case_number, c.case_status,
                   co.complaint_number, co.complaint_title,
                   (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', res1.first_name, res1.middle_name, res1.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp1
                    INNER JOIN residents res1 ON res1.resident_id = cp1.resident_id
                    WHERE cp1.complaint_id = co.complaint_id AND cp1.party_type = 'Complainant') AS complainant_name,
                   (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', res2.first_name, res2.middle_name, res2.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp2
                    INNER JOIN residents res2 ON res2.resident_id = cp2.resident_id
                    WHERE cp2.complaint_id = co.complaint_id AND cp2.party_type = 'Respondent') AS respondent_name,
                   TRIM(CONCAT_WS(' ', pb.first_name, pb.middle_name, pb.last_name)) AS attested_by_name,
                   TRIM(CONCAT_WS(' ', rep.first_name, rep.middle_name, rep.last_name)) AS repudiated_by_name,
                   DATEDIFF(s.repudiation_deadline, CURDATE()) AS repudiation_days_left,
                   DATEDIFF(s.six_month_deadline, CURDATE()) AS execution_days_left,
                   (SELECT COUNT(*) FROM settlement_installments si WHERE si.settlement_id = s.settlement_id) AS total_installments,
                   (SELECT COUNT(*) FROM settlement_installments si WHERE si.settlement_id = s.settlement_id AND si.payment_status = 'Paid') AS paid_installments,
                   (SELECT COUNT(*) FROM settlement_installments si WHERE si.settlement_id = s.settlement_id AND si.payment_status = 'Overdue') AS overdue_installments,
                   (SELECT COUNT(*) FROM execution_proceedings ep WHERE ep.settlement_id = s.settlement_id) AS execution_count
            FROM settlements s
            INNER JOIN cases c ON c.case_id = s.case_id
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            LEFT JOIN users pb ON pb.user_id = s.pb_attested_by
            LEFT JOIN residents rep ON rep.resident_id = s.repudiated_by
            $whereClause
            ORDER BY s.settlement_date DESC, s.settlement_id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'records' => $records,
            'pagination' => [
                'total_records' => $totalRecords,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => $totalPages,
            ]
        ];
    }

    /**
     * Retrieves a settlement record by its primary key with installments and executions.
     */
    public function getById(int $settlementId): array|false
    {
        $this->refreshStatutoryStates();
        $stmt = $this->conn->prepare("
            SELECT s.*, 
                   c.case_number, c.case_status,
                   co.complaint_number, co.complaint_title,
                   (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', res1.first_name, res1.middle_name, res1.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp1
                    INNER JOIN residents res1 ON res1.resident_id = cp1.resident_id
                    WHERE cp1.complaint_id = co.complaint_id AND cp1.party_type = 'Complainant') AS complainant_name,
                   (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', res2.first_name, res2.middle_name, res2.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp2
                    INNER JOIN residents res2 ON res2.resident_id = cp2.resident_id
                    WHERE cp2.complaint_id = co.complaint_id AND cp2.party_type = 'Respondent') AS respondent_name,
                   TRIM(CONCAT_WS(' ', pb.first_name, pb.middle_name, pb.last_name)) AS attested_by_name,
                   TRIM(CONCAT_WS(' ', rep.first_name, rep.middle_name, rep.last_name)) AS repudiated_by_name,
                   DATEDIFF(s.repudiation_deadline, CURDATE()) AS repudiation_days_left,
                   DATEDIFF(s.six_month_deadline, CURDATE()) AS execution_days_left
            FROM settlements s
            INNER JOIN cases c ON c.case_id = s.case_id
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            LEFT JOIN users pb ON pb.user_id = s.pb_attested_by
            LEFT JOIN residents rep ON rep.resident_id = s.repudiated_by
            WHERE s.settlement_id = ?
        ");
        $stmt->execute([$settlementId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            return false;
        }

        // Installments
        $instStmt = $this->conn->prepare("
            SELECT si.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS recorded_by_name
            FROM settlement_installments si
            LEFT JOIN users u ON u.user_id = si.recorded_by
            WHERE si.settlement_id = ?
            ORDER BY si.installment_number ASC
        ");
        $instStmt->execute([$settlementId]);
        $record['installments'] = $instStmt->fetchAll(PDO::FETCH_ASSOC);

        // New proceedings carry the statutory KP 23 → KP 24 → grace → KP 25 lifecycle.
        $execStmt = $this->conn->prepare("
            SELECT ep.*
            FROM execution_proceedings ep
            WHERE ep.settlement_id = ?
            ORDER BY ep.created_at DESC
        ");
        $execStmt->execute([$settlementId]);
        $record['executions'] = $execStmt->fetchAll(PDO::FETCH_ASSOC);
        $record['execution_proceedings'] = $record['executions'];
        $record['repudiation_window_open'] = $record['repudiation_status'] !== 'Repudiated'
            && !empty($record['repudiation_deadline'])
            && date('Y-m-d') <= $record['repudiation_deadline'];
        $record['is_final_executory'] = $record['repudiation_status'] === 'Enforceable';
        $record['jurisdiction_lapsed'] = !empty($record['six_month_deadline'])
            && date('Y-m-d') > $record['six_month_deadline'];

        return $record;
    }

    /**
     * Retrieves a settlement record by its case ID.
     */
    public function getByCase(int $caseId): array|false
    {
        $stmt = $this->conn->prepare('SELECT settlement_id FROM settlements WHERE case_id = ? LIMIT 1');
        $stmt->execute([$caseId]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            return false;
        }
        return $this->getById((int) $id);
    }

    /**
     * Creates or updates an amicable settlement with statutory milestone checklists and installments.
     */
    public function saveSettlement(array $data, int $actorUserId): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$caseId) {
            return ['success' => false, 'message' => 'Valid case ID is required.'];
        }

        $rawDate = trim((string) ($data['settlement_date'] ?? ''));
        if ($rawDate === '') {
            return ['success' => false, 'message' => 'Settlement signing date is required.'];
        }
        $settlementDate = date('Y-m-d', strtotime($rawDate));
        if (!self::isValidIsoDate($rawDate) && strtotime($rawDate) === false) {
            return ['success' => false, 'message' => 'Invalid settlement signing date.'];
        }
        if ($settlementDate > date('Y-m-d')) {
            return ['success' => false, 'message' => 'Settlement signing date cannot be in the future.'];
        }

        $agreementDetails = trim((string) ($data['agreement_details'] ?? ''));
        if ($agreementDetails === '') {
            return ['success' => false, 'message' => 'Detailed settlement agreement terms are required.'];
        }

        $termsRead = !empty($data['terms_read_to_parties']) ? 1 : 0;
        $compSigned = !empty($data['complainant_signed']) ? 1 : 0;
        $respSigned = !empty($data['respondent_signed']) ? 1 : 0;
        $pbAttested = !empty($data['pb_attested']) ? 1 : 0;
        $isSignedAndAttested = ($compSigned === 1 && $respSigned === 1 && $pbAttested === 1);

        // Only a signed and attested settlement triggers the 10-working-day repudiation
        // and 6-month execution deadlines (Order 4A).
        $repudiationDeadline = $isSignedAndAttested
            ? MediationDeadlineService::calculateBusinessDays($settlementDate, 10)
            : null;
        $sixMonthDeadline = $isSignedAndAttested
            ? (new DateTimeImmutable($settlementDate))->modify('+6 months')->format('Y-m-d')
            : null;
        $settlementStatus = $isSignedAndAttested ? 'Signed and Attested' : 'Draft';

        $awardType = in_array($data['award_type'] ?? '', ['Amicable Settlement', 'Arbitration Award'], true)
            ? $data['award_type'] : 'Amicable Settlement';
        $allowedObligationTypes = ['Monetary', 'Property Return', 'Specific Performance', 'Hybrid'];
        if (array_key_exists('obligation_type', $data) && $data['obligation_type'] !== null && $data['obligation_type'] !== '') {
            $rawObligationType = trim((string) $data['obligation_type']);
            if (!in_array($rawObligationType, $allowedObligationTypes, true)) {
                return [
                    'success' => false,
                    'message' => 'Invalid obligation type. Allowed values are: Monetary, Property Return, Specific Performance, Hybrid.'
                ];
            }
            $obligationType = $rawObligationType;
        } else {
            $obligationType = 'Monetary';
        }

        $pbAttestedBy = $pbAttested ? (!empty($data['pb_attested_by']) ? (int) $data['pb_attested_by'] : $actorUserId) : null;
        $pbAttestedAt = $pbAttested ? date('Y-m-d H:i:s') : null;
        $sealed = !empty($data['barangay_sealed']) ? 1 : 0;
        $caseFolder = !empty($data['original_in_case_folder']) ? 1 : 0;
        $copiesIssued = !empty($data['certified_copies_issued']) ? 1 : 0;

        $totalAmount = !empty($data['total_amount']) ? (float) $data['total_amount'] : 0.00;
        $responsibleParty = in_array($data['responsible_party'] ?? '', ['Respondent', 'Complainant', 'Both'], true) ? $data['responsible_party'] : 'Respondent';
        $hasInstallment = !empty($data['has_installment']) ? 1 : 0;
        $complianceDueDate = !empty($data['compliance_due_date']) ? date('Y-m-d', strtotime($data['compliance_due_date'])) : null;
        $complianceStatus = in_array($data['compliance_status'] ?? '', ['Pending', 'Partially Paid', 'Fully Paid', 'Overdue', 'Breached', 'Complied', 'Violated'], true) ? $data['compliance_status'] : 'Pending';

        $isNested = $this->conn->inTransaction();
        $generatedAbsolutePath = null;
        try {
            if (!$isNested) {
                $this->conn->beginTransaction();
            }

            $caseLock = $this->conn->prepare('SELECT case_id, complaint_id, case_status FROM cases WHERE case_id = ? FOR UPDATE');
            $caseLock->execute([$caseId]);
            $caseRow = $caseLock->fetch(PDO::FETCH_ASSOC);
            if (!$caseRow) {
                throw new RuntimeException('Case not found.');
            }
            if (in_array((string) $caseRow['case_status'], ['CFA Issued', 'Dismissed', 'DISMISSED_BARRED', 'Repudiated', 'Archived'], true)) {
                throw new RuntimeException("Cannot record a settlement for a case in '{$caseRow['case_status']}' status.");
            }

            $existingStmt = $this->conn->prepare('SELECT settlement_id, repudiation_status, repudiation_date, document_id, settlement_status, settlement_date, agreement_details, obligation_type FROM settlements WHERE case_id = ? FOR UPDATE');
            $existingStmt->execute([$caseId]);
            $existingSettle = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingSettle && (($existingSettle['repudiation_status'] ?? '') === 'Repudiated' || !empty($existingSettle['repudiation_date']))) {
                throw new RuntimeException('This settlement has already been repudiated and cannot be overwritten.');
            }
            $alreadySettled = ((string) $caseRow['case_status'] === 'Settled');

            $stmt = $this->conn->prepare("
                INSERT INTO settlements (
                    case_id, settlement_date, agreement_details,
                    terms_read_to_parties, complainant_signed, respondent_signed,
                    pb_attested, pb_attested_by, pb_attested_at,
                    barangay_sealed, original_in_case_folder, certified_copies_issued,
                    total_amount, responsible_party, has_installment,
                    compliance_due_date, repudiation_deadline, award_type, obligation_type, six_month_deadline, repudiation_status,
                    compliance_status, settlement_status
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, 'Within Repudiation Period',
                    ?, ?
                )
                ON DUPLICATE KEY UPDATE
                    settlement_date = VALUES(settlement_date),
                    agreement_details = VALUES(agreement_details),
                    terms_read_to_parties = VALUES(terms_read_to_parties),
                    complainant_signed = VALUES(complainant_signed),
                    respondent_signed = VALUES(respondent_signed),
                    pb_attested = VALUES(pb_attested),
                    pb_attested_by = VALUES(pb_attested_by),
                    pb_attested_at = VALUES(pb_attested_at),
                    barangay_sealed = VALUES(barangay_sealed),
                    original_in_case_folder = VALUES(original_in_case_folder),
                    certified_copies_issued = VALUES(certified_copies_issued),
                    total_amount = VALUES(total_amount),
                    responsible_party = VALUES(responsible_party),
                    has_installment = VALUES(has_installment),
                    compliance_due_date = VALUES(compliance_due_date),
                    repudiation_deadline = VALUES(repudiation_deadline),
                    award_type = VALUES(award_type),
                    obligation_type = VALUES(obligation_type),
                    six_month_deadline = VALUES(six_month_deadline),
                    compliance_status = VALUES(compliance_status),
                    settlement_status = VALUES(settlement_status),
                    updated_at = NOW()
            ");

            $stmt->execute([
                $caseId, $settlementDate, $agreementDetails,
                $termsRead, $compSigned, $respSigned,
                $pbAttested, $pbAttestedBy, $pbAttestedAt,
                $sealed, $caseFolder, $copiesIssued,
                $totalAmount, $responsibleParty, $hasInstallment,
                $complianceDueDate, $repudiationDeadline, $awardType, $obligationType, $sixMonthDeadline,
                $complianceStatus, $settlementStatus
            ]);

            // Get settlement ID
            $idStmt = $this->conn->prepare('SELECT settlement_id FROM settlements WHERE case_id = ?');
            $idStmt->execute([$caseId]);
            $settlementId = (int) $idStmt->fetchColumn();

            // Save installments if provided
            if ($hasInstallment && !empty($data['installments']) && is_array($data['installments'])) {
                // Delete previous unpaid installments
                $del = $this->conn->prepare("DELETE FROM settlement_installments WHERE settlement_id = ? AND payment_status = 'Pending'");
                $del->execute([$settlementId]);

                $insInst = $this->conn->prepare("
                    INSERT INTO settlement_installments (
                        settlement_id, installment_number, due_date, amount_due, payment_status, notes
                    ) VALUES (?, ?, ?, ?, 'Pending', ?)
                ");

                $num = 1;
                foreach ($data['installments'] as $inst) {
                    $amount = (float) ($inst['amount_due'] ?? $inst['amount'] ?? 0);
                    if (!empty($inst['due_date']) && $amount > 0) {
                        $insInst->execute([
                            $settlementId,
                            $num++,
                            date('Y-m-d', strtotime($inst['due_date'])),
                            $amount,
                            trim((string) ($inst['notes'] ?? '')) ?: null
                        ]);
                    }
                }
            }

            $settlementDocId = !empty($existingSettle['document_id']) ? (int) $existingSettle['document_id'] : null;

            // Update case status to 'Settled' and generate KP Form 16 only if signed and attested
            if ($isSignedAndAttested) {
                $sameSignedContent = $alreadySettled
                    && !empty($settlementDocId)
                    && $existingSettle
                    && ($existingSettle['settlement_status'] ?? '') === 'Signed and Attested'
                    && (string) ($existingSettle['settlement_date'] ?? '') === $settlementDate
                    && trim((string) ($existingSettle['agreement_details'] ?? '')) === $agreementDetails
                    && (string) ($existingSettle['obligation_type'] ?? '') === $obligationType;

                if (!$sameSignedContent) {
                    $kpService = $this->createKpFormService();
                    $genDoc = $kpService->generateAndPersistForm('KP Form 16', $caseId, $actorUserId ?: 1, [
                        'strict_pdf' => true,
                        'settlement_date' => $settlementDate,
                    ]);
                    if (empty($genDoc['success']) || empty($genDoc['document_id']) || empty($genDoc['file_path'])) {
                        throw new RuntimeException($genDoc['message'] ?? 'Failed to render KP Form 16 Amicable Settlement PDF.');
                    }
                    $settlementDocId = (int) $genDoc['document_id'];
                    $generatedAbsolutePath = dirname(__DIR__, 2) . '/' . ltrim((string) $genDoc['file_path'], '/');

                    $this->conn->prepare("UPDATE settlements SET document_id = ? WHERE settlement_id = ?")
                        ->execute([$settlementDocId, $settlementId]);
                }

                $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Settled', updated_at = NOW() WHERE case_id = ?");
                $updCase->execute([$caseId]);

                $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Settled', co.updated_at = NOW() WHERE c.case_id = ?");
                $updComp->execute([$caseId]);

                if ($caseRow['case_status'] === 'Conciliation') {
                    $activeStageType = 'Conciliation';
                } elseif ($alreadySettled) {
                    $stageLookup = $this->conn->prepare("
                        SELECT stage_type
                        FROM case_stages
                        WHERE case_id = ?
                          AND stage_type IN ('Conciliation', 'Mediation')
                          AND (outcome = 'Settled' OR stage_status IN ('Active', 'In Progress', 'Awaiting Outcome', 'Completed'))
                        ORDER BY CASE WHEN stage_type = 'Conciliation' THEN 1 ELSE 2 END
                        LIMIT 1
                    ");
                    $stageLookup->execute([$caseId]);
                    $activeStageType = (string) ($stageLookup->fetchColumn() ?: 'Mediation');
                } else {
                    $activeStageType = 'Mediation';
                }
                $this->conn->prepare("
                    INSERT INTO case_stages (case_id, stage_type, stage_status, outcome, outcome_remarks, completed_at)
                    VALUES (?, ?, 'Completed', 'Settled', ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        stage_status = 'Completed',
                        outcome = 'Settled',
                        outcome_remarks = VALUES(outcome_remarks),
                        completed_at = COALESCE(completed_at, NOW()),
                        updated_at = CURRENT_TIMESTAMP
                ")->execute([$caseId, $activeStageType, "Signed and attested KP Form 16 Amicable Settlement dated {$settlementDate}."]);

                $dlStmt = $this->conn->prepare("
                    INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                    VALUES (?, ?, ?, 'Pending')
                    ON DUPLICATE KEY UPDATE due_date = VALUES(due_date), status = 'Pending', completed_at = NULL
                ");
                $dlStmt->execute([$caseId, 'Repudiation Window', $repudiationDeadline]);
                $dlStmt->execute([$caseId, 'Execution Period', $sixMonthDeadline]);

                if (!$alreadySettled) {
                    $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Settled', 'Amicable settlement reached, signed by parties, and attested by Punong Barangay. 10-working-day repudiation clock started.', ?)");
                    $hist->execute([$caseId, $actorUserId]);

                    try {
                        require_once __DIR__ . '/../services/NotificationService.php';
                        $notif = new NotificationService();
                        $cNumStmt = $this->conn->prepare("SELECT case_number FROM cases WHERE case_id = ?");
                        $cNumStmt->execute([$caseId]);
                        $cNum = $cNumStmt->fetchColumn() ?: ('Case #' . $caseId);
                        $notif->notifySettlementApproved($caseId, (string)$cNum, $actorUserId);
                    } catch (Throwable $ne) {
                        error_log('Failed to dispatch settlement approved notification: ' . $ne->getMessage());
                    }
                }
            }

            if (!$isNested) {
                $this->conn->commit();
            }
            return [
                'success' => true,
                'message' => $isSignedAndAttested
                    ? 'Signed and attested Amicable Settlement (KP Form 16) recorded successfully.'
                    : 'Draft settlement terms saved. Formal disposition and deadlines remain pending until both parties sign and the Punong Barangay attests.',
                'settlement_id' => $settlementId,
                'settlement_status' => $settlementStatus,
                'document_id' => $settlementDocId,
                'repudiation_deadline' => $repudiationDeadline,
                'six_month_deadline' => $sixMonthDeadline,
            ];
        } catch (Throwable $e) {
            if (!$isNested && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            if ($generatedAbsolutePath && is_file($generatedAbsolutePath)) {
                @unlink($generatedAbsolutePath);
            }
            return ['success' => false, 'message' => 'Failed to save settlement: ' . $e->getMessage()];
        }
    }

    /**
     * Records an installment payment with receipt details.
     */
    public function recordInstallmentPayment(int $installmentId, array $data, int $actorUserId): array
    {
        $isNested = $this->conn->inTransaction();
        try {
            if (!$isNested) {
                $this->conn->beginTransaction();
            }

            $stmt = $this->conn->prepare('SELECT * FROM settlement_installments WHERE installment_id = ? FOR UPDATE');
            $stmt->execute([$installmentId]);
            $installment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$installment) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Installment record not found.'];
            }

            $settlementId = (int) $installment['settlement_id'];
            $amountPaid = (float) ($data['amount_paid'] ?? $installment['amount_due']);
            $paymentDate = !empty($data['payment_date']) ? date('Y-m-d H:i:s', strtotime($data['payment_date'])) : date('Y-m-d H:i:s');
            $receiptNumber = trim((string) ($data['receipt_number'] ?? ''));
            $receiptPath = trim((string) ($data['receipt_path'] ?? ''));
            $notes = trim((string) ($data['notes'] ?? ''));

            $status = ($amountPaid >= (float) $installment['amount_due']) ? 'Paid' : (($amountPaid > 0) ? 'Partially Paid' : 'Pending');

            $upd = $this->conn->prepare("
                UPDATE settlement_installments
                SET amount_paid = ?,
                    payment_date = ?,
                    payment_status = ?,
                    receipt_number = ?,
                    receipt_path = ?,
                    notes = ?,
                    recorded_by = ?,
                    updated_at = NOW()
                WHERE installment_id = ?
            ");
            $upd->execute([
                $amountPaid,
                $paymentDate,
                $status,
                $receiptNumber ?: null,
                $receiptPath ?: null,
                $notes ?: null,
                $actorUserId,
                $installmentId
            ]);

            // Re-evaluate settlement compliance status
            $chk = $this->conn->prepare("
                SELECT 
                    COUNT(*) AS total,
                    SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid_cnt,
                    SUM(CASE WHEN payment_status = 'Overdue' THEN 1 ELSE 0 END) AS overdue_cnt,
                    SUM(amount_paid) AS total_paid
                FROM settlement_installments
                WHERE settlement_id = ?
            ");
            $chk->execute([$settlementId]);
            $stats = $chk->fetch(PDO::FETCH_ASSOC);

            $overallStatus = 'Partially Paid';
            if ((int) $stats['paid_cnt'] === (int) $stats['total']) {
                $overallStatus = 'Fully Paid';
            } elseif ((int) $stats['overdue_cnt'] > 0) {
                $overallStatus = 'Overdue';
            }

            $updSettlement = $this->conn->prepare("UPDATE settlements SET compliance_status = ?, updated_at = NOW() WHERE settlement_id = ?");
            $updSettlement->execute([$overallStatus, $settlementId]);

            $this->conn->commit();
            return [
                'success' => true,
                'message' => "Payment of ₱" . number_format($amountPaid, 2) . " logged successfully.",
                'overall_compliance_status' => $overallStatus
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Payment logging failed: ' . $e->getMessage()];
        }
    }

    /**
     * Files a sworn statement of repudiation within the 10-day period.
     */
    public function fileRepudiation(int $settlementId, array $data, int $actorUserId): array
    {
        $settlement = $this->getById($settlementId);
        if (!$settlement) {
            return ['success' => false, 'message' => 'Settlement record not found.'];
        }

        $reason = trim((string) ($data['repudiation_reason'] ?? ''));
        if ($reason === '') {
            return ['success' => false, 'message' => 'A sworn narrative alleging fraud, violence, or intimidation is required.'];
        }

        $ground = trim((string) ($data['repudiation_ground'] ?? ''));
        if ($ground === '' && preg_match('/\b(fraud|violence|intimidation)\b/i', $reason, $match)) {
            $ground = ucfirst(strtolower($match[1]));
        }
        if (!in_array($ground, ['Fraud', 'Violence', 'Intimidation'], true)) {
            return ['success' => false, 'message' => 'Repudiation is limited to vitiated consent through Fraud, Violence, or Intimidation.'];
        }

        $residentId = filter_var($data['repudiated_by'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$residentId) {
            return ['success' => false, 'message' => 'A valid party must be specified as repudiating the settlement.'];
        }
        if (!$this->isPartyToCase((int) $settlement['case_id'], (int) $residentId)) {
            return ['success' => false, 'message' => 'Only a recorded party to this case may repudiate the settlement.'];
        }

        // Check 10-day limit
        $today = date('Y-m-d');
        if ($settlement['repudiation_status'] === 'Repudiated') {
            return ['success' => false, 'message' => 'This settlement has already been repudiated.'];
        }
        if ($settlement['repudiation_deadline'] && $today > $settlement['repudiation_deadline']) {
            return [
                'success' => false,
                'message' => 'The 10-day statutory repudiation period has lapsed.'
            ];
        }

        $isNested = $this->conn->inTransaction();
        try {
            if (!$isNested) {
                $this->conn->beginTransaction();
            }

            $lockStmt = $this->conn->prepare("SELECT repudiation_status, repudiation_date, repudiation_deadline, settlement_status, complainant_signed, respondent_signed, pb_attested FROM settlements WHERE settlement_id = ? LIMIT 1 FOR UPDATE");
            $lockStmt->execute([$settlementId]);
            $lockedRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedRow || $lockedRow['repudiation_status'] === 'Repudiated' || !empty($lockedRow['repudiation_date'])) {
                if (!$isNested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'This settlement has already been repudiated.'];
            }
            if (($lockedRow['settlement_status'] ?? 'Draft') !== 'Signed and Attested' || empty($lockedRow['complainant_signed']) || empty($lockedRow['respondent_signed']) || empty($lockedRow['pb_attested'])) {
                if (!$isNested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'Only a signed and Punong Barangay-attested Amicable Settlement may be formally repudiated.'];
            }
            if (!empty($lockedRow['repudiation_deadline']) && date('Y-m-d') > $lockedRow['repudiation_deadline']) {
                if (!$isNested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'The 10-day statutory repudiation period has lapsed.'];
            }

            $docPath = trim((string) ($data['supporting_document_path'] ?? ''));

            $stmt = $this->conn->prepare("
                UPDATE settlements
                SET repudiation_status = 'Repudiated',
                    repudiation_reason = ?,
                    repudiated_by = ?,
                    repudiation_date = NOW(),
                    supporting_document_path = ?,
                    compliance_status = 'Violated',
                    updated_at = NOW()
                WHERE settlement_id = ?
            ");
            $stmt->execute(["{$ground}: {$reason}", $residentId, $docPath ?: null, $settlementId]);

            // Update case status and history
            $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Repudiated', updated_at = NOW() WHERE case_id = ?");
            $updCase->execute([$settlement['case_id']]);

            // Invalidate amicable settlement & update stage status to Repudiated
            $updStage = $this->conn->prepare("
                INSERT INTO case_stages (case_id, stage_type, stage_status, outcome, outcome_remarks, completed_at)
                VALUES (?, 'Mediation', 'Completed', 'Repudiated', ?, NOW())
                ON DUPLICATE KEY UPDATE
                    stage_status = 'Completed',
                    outcome = 'Repudiated',
                    outcome_remarks = VALUES(outcome_remarks),
                    completed_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $updStage->execute([$settlement['case_id'], "Settlement repudiated under RA 7160 Sec. 418. Ground: {$ground}. {$reason}"]);

            // Invalidate / cancel court transmittal countdown and preserve historical packet
            $cancelTrans = $this->conn->prepare("UPDATE case_transmittals SET status = 'Cancelled', superseded_at = COALESCE(superseded_at, NOW()), transmittal_notified = 2, updated_at = NOW() WHERE case_id = ? AND superseded_at IS NULL");
            $cancelTrans->execute([$settlement['case_id']]);

            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Repudiated', ?, ?)");
            $hist->execute([$settlement['case_id'], "Settlement repudiated within the 10-day statutory window. Ground: {$ground}. Unlocked KP Form 20 (CFA) for standard issuance.", $actorUserId]);

            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $notif = new NotificationService();
                $cNumStmt = $this->conn->prepare("SELECT case_number FROM cases WHERE case_id = ?");
                $cNumStmt->execute([(int)$settlement['case_id']]);
                $cNum = $cNumStmt->fetchColumn() ?: ('Case #' . $settlement['case_id']);

                $pNameStmt = $this->conn->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) FROM residents WHERE resident_id = ?");
                $pNameStmt->execute([$residentId]);
                $pName = $pNameStmt->fetchColumn() ?: ('Resident #' . $residentId);

                $notif->notifySettlementRepudiated((int)$settlement['case_id'], (string)$cNum, (string)$pName, $reason, $actorUserId);
            } catch (Throwable $ne) {
                error_log('Failed to dispatch settlement repudiated notification: ' . $ne->getMessage());
            }

            if (!$isNested) {
                $this->conn->commit();
            }
            return [
                'success' => true,
                'message' => 'Sworn repudiation statement filed successfully. Case marked as Repudiated.',
                'kp_form' => 'KP Form 17',
                'cfa_unlocked' => true,
            ];
        } catch (Throwable $e) {
            if (!$isNested && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Repudiation filing failed: ' . $e->getMessage()];
        }
    }

    /**
     * Explicit staff review for legacy settlements lacking signing/attestation evidence.
     */
    public function confirmLegacySettlementReview(int $settlementId, array $data, int $actorUserId): array
    {
        $complainantSigned = !empty($data['complainant_signed']);
        $respondentSigned = !empty($data['respondent_signed']);
        $pbAttested = !empty($data['pb_attested']);
        if (!$complainantSigned || !$respondentSigned || !$pbAttested) {
            return [
                'success' => false,
                'message' => 'Confirming a legacy settlement requires complainant signature, respondent signature, and Punong Barangay attestation.'
            ];
        }

        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) {
                $this->conn->beginTransaction();
            }
            $lockStmt = $this->conn->prepare('SELECT * FROM settlements WHERE settlement_id = ? FOR UPDATE');
            $lockStmt->execute([$settlementId]);
            $locked = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$locked) {
                if (!$nested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'Settlement record not found.'];
            }
            if (($locked['settlement_status'] ?? '') === 'Signed and Attested') {
                if (!$nested && $this->conn->inTransaction()) {
                    $this->conn->commit();
                }
                return [
                    'success' => true,
                    'settlement_id' => $settlementId,
                    'settlement_status' => 'Signed and Attested',
                    'document_id' => !empty($locked['document_id']) ? (int) $locked['document_id'] : null,
                    'message' => 'Settlement is already marked Signed and Attested.'
                ];
            }

            $documentId = !empty($locked['document_id']) ? (int) $locked['document_id'] : null;
            if (!$documentId) {
                require_once __DIR__ . '/../services/KpFormService.php';
                $kpService = new KpFormService($this->conn);
                $docRes = $kpService->generateAndPersistForm('KP Form 16', (int) $locked['case_id'], $actorUserId);
                if (!empty($docRes['success']) && !empty($docRes['document_id'])) {
                    $documentId = (int) $docRes['document_id'];
                }
            }

            $upd = $this->conn->prepare("
                UPDATE settlements
                   SET complainant_signed = 1,
                       respondent_signed = 1,
                       pb_attested = 1,
                       settlement_status = 'Signed and Attested',
                       document_id = COALESCE(document_id, ?),
                       updated_at = NOW()
                 WHERE settlement_id = ?
            ");
            $upd->execute([$documentId, $settlementId]);

            $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, 'Settled', ?, ?)
            ")->execute([
                (int) $locked['case_id'],
                "Legacy Amicable Settlement #{$settlementId} explicitly reviewed and confirmed as Signed and Attested.",
                $actorUserId
            ]);

            if (!$nested) {
                $this->conn->commit();
            }
            return [
                'success' => true,
                'settlement_id' => $settlementId,
                'settlement_status' => 'Signed and Attested',
                'document_id' => $documentId,
                'message' => 'Legacy settlement confirmed as Signed and Attested.'
            ];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Legacy review failed: ' . $e->getMessage()];
        }
    }

    /** Files KP Form 23 and schedules the mandatory KP Form 24 hearing. */
    public function fileExecutionMotion(int $settlementId, array $data, int $actorUserId): array
    {
        $settlement = $this->getById($settlementId);
        if (!$settlement) return ['success' => false, 'message' => 'Settlement record not found.'];
        $today = date('Y-m-d');
        if ($settlement['repudiation_status'] === 'Repudiated') return ['success' => false, 'message' => 'A repudiated settlement must proceed through CFA, not barangay execution.'];
        if (!empty($settlement['repudiation_deadline']) && $today <= $settlement['repudiation_deadline']) return ['success' => false, 'message' => "Cannot file Motion for Execution while the 10-day repudiation period is active (ends {$settlement['repudiation_deadline']})."];
        if (!empty($settlement['six_month_deadline']) && $today > $settlement['six_month_deadline']) return $this->mtcLapseResult();

        $obligation = trim((string) ($data['obligation_violated'] ?? ''));
        $amountOrRequirement = trim((string) ($data['amount_or_requirement'] ?? ''));
        $movingParty = filter_var($data['motion_filed_by'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $hearingDate = !empty($data['hearing_date']) ? date('Y-m-d H:i:s', strtotime((string) $data['hearing_date'])) : '';
        $narrative = trim((string) ($data['motion_narrative'] ?? ''));
        if ($narrative === '') $narrative = trim("Obligation alleged as unfulfilled: {$obligation}\nRelief requested: {$amountOrRequirement}\n" . trim((string) ($data['evidence_notes'] ?? '')));
        if ($obligation === '' || $amountOrRequirement === '' || !$movingParty || $hearingDate === '') return ['success' => false, 'message' => 'Provide the moving party, violated obligation, relief sought, and KP Form 24 hearing date.'];
        if (substr($hearingDate, 0, 10) < $today) return ['success' => false, 'message' => 'The execution hearing cannot be scheduled in the past.'];
        if (!$this->isPartyToCase((int) $settlement['case_id'], (int) $movingParty)) return ['success' => false, 'message' => 'Only a recorded party to this case may file the Motion for Execution.'];

        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) $this->conn->beginTransaction();
            $lock = $this->conn->prepare('SELECT * FROM settlements WHERE settlement_id = ? FOR UPDATE');
            $lock->execute([$settlementId]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$locked) throw new RuntimeException('Settlement record not found.');
            if ($locked['repudiation_status'] === 'Repudiated') throw new RuntimeException('A repudiated settlement must proceed through CFA, not barangay execution.');
            if (($locked['settlement_status'] ?? 'Draft') !== 'Signed and Attested' || empty($locked['complainant_signed']) || empty($locked['respondent_signed']) || empty($locked['pb_attested'])) {
                throw new RuntimeException('Execution requires a signed and Punong Barangay-attested Amicable Settlement.');
            }
            if ($today <= $locked['repudiation_deadline']) throw new RuntimeException('Cannot file Motion for Execution while the 10-day repudiation period is active.');
            if ($today > $locked['six_month_deadline']) { if (!$nested) $this->conn->rollBack(); return $this->mtcLapseResult(); }

            $exists = $this->conn->prepare("SELECT execution_id FROM execution_proceedings WHERE settlement_id = ? AND execution_status NOT IN ('Enforced / Complied', 'Lapsed to MTC') FOR UPDATE");
            $exists->execute([$settlementId]);
            if ($exists->fetchColumn()) throw new RuntimeException('An active execution proceeding already exists for this settlement.');

            $ins = $this->conn->prepare("INSERT INTO execution_proceedings (case_id, settlement_id, motion_date, motion_narrative, hearing_date, execution_status) VALUES (?, ?, CURDATE(), ?, ?, 'Hearing Scheduled')");
            $ins->execute([(int) $locked['case_id'], $settlementId, $narrative, $hearingDate]);
            $executionId = (int) $this->conn->lastInsertId();
            $this->conn->prepare("UPDATE settlements SET compliance_status = 'Breached', updated_at = NOW() WHERE settlement_id = ?")->execute([$settlementId]);
            $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Settled', ?, ?)")->execute([(int) $locked['case_id'], "KP Form 23 motion filed (Execution #{$executionId}); KP Form 24 hearing set for {$hearingDate}.", $actorUserId]);
            if (!$nested) $this->conn->commit();
            return ['success' => true, 'message' => 'KP Form 23 filed and KP Form 24 hearing scheduled.', 'execution_id' => $executionId, 'kp_forms' => ['KP Form 23', 'KP Form 24']];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Execution filing failed: ' . $e->getMessage()];
        }
    }

    /** Files KP Form 23 and schedules KP Form 24 hearing for a finalized Arbitration Award. */
    public function fileArbitrationExecutionMotion(int $arbitrationId, array $data, int $actorUserId): array
    {
        $today = date('Y-m-d');
        $obligation = trim((string) ($data['obligation_violated'] ?? ''));
        $amountOrRequirement = trim((string) ($data['amount_or_requirement'] ?? ''));
        $movingParty = filter_var($data['motion_filed_by'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $hearingDate = !empty($data['hearing_date']) ? date('Y-m-d H:i:s', strtotime((string) $data['hearing_date'])) : '';
        $narrative = trim((string) ($data['motion_narrative'] ?? ''));
        if ($narrative === '') {
            $narrative = trim("Obligation alleged as unfulfilled: {$obligation}\nRelief requested: {$amountOrRequirement}\n" . trim((string) ($data['evidence_notes'] ?? '')));
        }
        if ($obligation === '' || $amountOrRequirement === '' || !$movingParty || $hearingDate === '') {
            return ['success' => false, 'message' => 'Provide the moving party, violated obligation, relief sought, and KP Form 24 hearing date.'];
        }
        if (substr($hearingDate, 0, 10) < $today) {
            return ['success' => false, 'message' => 'The execution hearing cannot be scheduled in the past.'];
        }

        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) {
                $this->conn->beginTransaction();
            }
            $lock = $this->conn->prepare('SELECT * FROM arbitration_records WHERE arbitration_id = ? FOR UPDATE');
            $lock->execute([$arbitrationId]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$locked) {
                throw new RuntimeException('Arbitration record not found.');
            }
            if (!$this->isPartyToCase((int) $locked['case_id'], (int) $movingParty)) {
                throw new RuntimeException('Only a recorded party to this case may file the Motion for Execution.');
            }
            if (($locked['agreement_repudiation_status'] ?? 'None') === 'Repudiated') {
                throw new RuntimeException('A repudiated arbitration agreement must proceed through CFA, not barangay execution.');
            }
            if (($locked['award_status'] ?? '') !== 'Finalized' || empty($locked['award_date']) || empty($locked['award_document_id'])) {
                throw new RuntimeException('Execution requires a finalized Arbitration Award with a saved KP Form 15 document.');
            }
            require_once __DIR__ . '/../services/MediationDeadlineService.php';
            $awardDate = substr((string) $locked['award_date'], 0, 10);
            $finalityCutoff = MediationDeadlineService::calculateBusinessDays($awardDate, 10);
            if ($today <= $finalityCutoff) {
                throw new RuntimeException("Cannot file Motion for Execution while the 10-working-day finality window is active (ends {$finalityCutoff}).");
            }
            $sixMonthDeadline = !empty($locked['six_month_deadline'])
                ? (string) $locked['six_month_deadline']
                : date('Y-m-d', strtotime($awardDate . ' +6 months'));
            if ($today > $sixMonthDeadline) {
                if (!$nested) {
                    $this->conn->rollBack();
                }
                return $this->mtcLapseResult();
            }

            $exists = $this->conn->prepare("SELECT execution_id FROM execution_proceedings WHERE arbitration_id = ? AND execution_status NOT IN ('Enforced / Complied', 'Lapsed to MTC') FOR UPDATE");
            $exists->execute([$arbitrationId]);
            if ($exists->fetchColumn()) {
                throw new RuntimeException('An active execution proceeding already exists for this arbitration award.');
            }

            $ins = $this->conn->prepare("INSERT INTO execution_proceedings (case_id, settlement_id, arbitration_id, motion_date, motion_narrative, hearing_date, execution_status) VALUES (?, NULL, ?, CURDATE(), ?, ?, 'Hearing Scheduled')");
            $ins->execute([(int) $locked['case_id'], $arbitrationId, $narrative, $hearingDate]);
            $executionId = (int) $this->conn->lastInsertId();
            $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Settled', ?, ?)")
                ->execute([(int) $locked['case_id'], "KP Form 23 motion filed on Arbitration Award (Execution #{$executionId}); KP Form 24 hearing set for {$hearingDate}.", $actorUserId]);
            if (!$nested) {
                $this->conn->commit();
            }
            return ['success' => true, 'message' => 'KP Form 23 filed and KP Form 24 hearing scheduled for Arbitration Award.', 'execution_id' => $executionId, 'kp_forms' => ['KP Form 23', 'KP Form 24']];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Execution filing failed: ' . $e->getMessage()];
        }
    }

    /** Records the due-process hearing and sets a voluntary-compliance grace period. */
    public function recordExecutionHearing(int $executionId, array $data, int $actorUserId): array
    {
        $minutes = trim((string) ($data['hearing_minutes'] ?? ''));
        $graceEnd = !empty($data['grace_period_end_date']) ? date('Y-m-d', strtotime((string) $data['grace_period_end_date'])) : '';
        $allowedAttendance = ['Present', 'Absent', 'Excused'];
        $complainant = $data['complainant_attended'] ?? 'Absent';
        $respondent = $data['respondent_attended'] ?? 'Absent';
        $absenceReason = trim((string) ($data['non_appearance_reason'] ?? ''));
        if ($minutes === '' || $graceEnd === '' || !in_array($complainant, $allowedAttendance, true) || !in_array($respondent, $allowedAttendance, true)) return ['success' => false, 'message' => 'KP Form 24 minutes, both attendance entries, and a grace-period end date are required.'];
        if (($complainant !== 'Present' || $respondent !== 'Present') && $absenceReason === '') return ['success' => false, 'message' => 'Record the reason for any party non-appearance in the hearing minutes.'];

        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) $this->conn->beginTransaction();
            $stmt = $this->conn->prepare("SELECT ep.*, COALESCE(s.six_month_deadline, ar.six_month_deadline) AS six_month_deadline FROM execution_proceedings ep LEFT JOIN settlements s ON s.settlement_id = ep.settlement_id LEFT JOIN arbitration_records ar ON ar.arbitration_id = ep.arbitration_id WHERE ep.execution_id = ? FOR UPDATE");
            $stmt->execute([$executionId]); $exec = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exec) throw new RuntimeException('Execution proceeding not found.');
            if ($exec['execution_status'] !== 'Hearing Scheduled') throw new RuntimeException('Only a scheduled KP Form 24 hearing may be recorded.');
            if (!empty($exec['six_month_deadline']) && date('Y-m-d') > $exec['six_month_deadline']) { if (!$nested) $this->conn->rollBack(); return $this->mtcLapseResult(); }
            if (date('Y-m-d H:i:s') < $exec['hearing_date']) throw new RuntimeException('KP Form 24 minutes cannot be recorded before the scheduled execution hearing.');
            if ($graceEnd < substr($exec['hearing_date'], 0, 10)) throw new RuntimeException('The grace period cannot end before the execution hearing.');
            $upd = $this->conn->prepare("UPDATE execution_proceedings SET complainant_attended = ?, respondent_attended = ?, non_appearance_reason = ?, hearing_minutes = ?, grace_period_end_date = ?, execution_status = 'Grace Period Active', updated_at = NOW() WHERE execution_id = ?");
            $upd->execute([$complainant, $respondent, $absenceReason ?: null, $minutes, $graceEnd, $executionId]);
            $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Settled', ?, ?)")->execute([(int) $exec['case_id'], "KP Form 24 execution hearing minutes recorded; voluntary compliance grace period ends {$graceEnd}.", $actorUserId]);
            if (!$nested) $this->conn->commit();
            return ['success' => true, 'message' => 'KP Form 24 minutes recorded and grace period activated.', 'kp_form' => 'KP Form 24'];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Execution hearing update failed: ' . $e->getMessage()];
        }
    }

    /** Issues KP Form 25 only after due process and expiry of the granted grace period. */
    public function issueNoticeOfExecution(int $executionId, array $data, int $actorUserId): array
    {
        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) $this->conn->beginTransaction();
            $stmt = $this->conn->prepare("SELECT ep.*, COALESCE(s.compliance_status, ar.award_status) AS compliance_status, COALESCE(s.six_month_deadline, ar.six_month_deadline) AS six_month_deadline FROM execution_proceedings ep LEFT JOIN settlements s ON s.settlement_id = ep.settlement_id LEFT JOIN arbitration_records ar ON ar.arbitration_id = ep.arbitration_id WHERE ep.execution_id = ? FOR UPDATE");
            $stmt->execute([$executionId]); $exec = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exec) throw new RuntimeException('Execution proceeding not found.');
            if (!empty($exec['six_month_deadline']) && date('Y-m-d') > $exec['six_month_deadline']) { if (!$nested) $this->conn->rollBack(); return $this->mtcLapseResult(); }
            if ($exec['execution_status'] !== 'Grace Period Active' || empty($exec['hearing_minutes']) || empty($exec['grace_period_end_date'])) throw new RuntimeException('KP Form 25 is locked until KP Form 24 minutes and a grace period are recorded.');
            if (date('Y-m-d') <= $exec['grace_period_end_date']) throw new RuntimeException('KP Form 25 is locked until the voluntary compliance grace period has expired.');
            if (in_array($exec['compliance_status'], ['Fully Paid', 'Complied'], true)) throw new RuntimeException('The settlement is already recorded as complied with.');
            $noticeDate = !empty($data['notice_of_execution_date']) ? date('Y-m-d', strtotime((string) $data['notice_of_execution_date'])) : date('Y-m-d');
            $this->conn->prepare("UPDATE execution_proceedings SET notice_of_execution_date = ?, execution_status = 'Notice of Execution Issued', updated_at = NOW() WHERE execution_id = ?")->execute([$noticeDate, $executionId]);
            $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Settled', ?, ?)")->execute([(int) $exec['case_id'], "KP Form 25 Notice of Execution issued on {$noticeDate} after unfulfilled grace period.", $actorUserId]);
            if (!$nested) $this->conn->commit();
            return ['success' => true, 'message' => 'KP Form 25 Notice of Execution issued.', 'kp_form' => 'KP Form 25'];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Notice of Execution could not be issued: ' . $e->getMessage()];
        }
    }

    /** Only completion is allowed through the legacy update action; stage skips are rejected. */
    public function updateExecutionStatus(int $executionId, array $data, int $actorUserId): array
    {
        if (($data['execution_status'] ?? '') !== 'Enforced / Complied') return ['success' => false, 'message' => 'Use the KP Form 24 hearing and KP Form 25 actions for execution transitions.'];
        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) $this->conn->beginTransaction();
            $stmt = $this->conn->prepare('SELECT * FROM execution_proceedings WHERE execution_id = ? FOR UPDATE');
            $stmt->execute([$executionId]); $exec = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exec) throw new RuntimeException('Execution proceeding not found.');
            $this->conn->prepare("UPDATE execution_proceedings SET execution_status = 'Enforced / Complied', updated_at = NOW() WHERE execution_id = ?")->execute([$executionId]);
            if (!empty($exec['settlement_id'])) {
                $this->conn->prepare("UPDATE settlements SET compliance_status = 'Complied', compliance_date = CURDATE(), updated_at = NOW() WHERE settlement_id = ?")->execute([$exec['settlement_id']]);
            }
            if (!$nested) $this->conn->commit();
            return ['success' => true, 'message' => 'Execution marked Enforced / Complied.'];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Execution completion failed: ' . $e->getMessage()];
        }
    }

    private function mtcLapseResult(): array
    {
        return ['success' => false, 'lapsed_to_mtc' => true, 'message' => 'Barangay execution jurisdiction has lapsed. Refer the aggrieved party to the MTC/MeTC for judicial enforcement.', 'mtc_referral' => 'Prepare a certified settlement or award, proof of finality, execution record, and case transmittal for the appropriate MTC/MeTC.'];
    }
}
