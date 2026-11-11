<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/MediationDeadlineService.php';
require_once __DIR__ . '/../services/ValidationService.php';

class Settlement
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
    }

    /** Keeps the calendar-based repudiation and jurisdiction clocks authoritative. */
    private function refreshStatutoryStates(): void
    {
        $this->conn->exec("UPDATE settlements
            SET repudiation_status = 'Enforceable', updated_at = NOW()
            WHERE repudiation_status = 'Within Repudiation Period'
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

        $rawSettlementDate = trim((string) ($data['settlement_date'] ?? ''));
        if ($rawSettlementDate !== '' && !ValidationService::date($rawSettlementDate)) {
            return ['success' => false, 'message' => 'Settlement date must be a valid date (YYYY-MM-DD).'];
        }
        $settlementDate = $rawSettlementDate !== '' ? $rawSettlementDate : date('Y-m-d');
        $agreementDetails = trim((string) ($data['agreement_details'] ?? ''));
        if ($agreementDetails === '') {
            return ['success' => false, 'message' => 'Detailed settlement agreement terms are required.'];
        }

        // The workspace uses the statutory ten-working-day countdown and the
        // same holiday-aware calculation as the transmittal workflow.
        $repudiationDeadline = MediationDeadlineService::calculateBusinessDays($settlementDate, 10);
        $sixMonthDeadline = (new DateTimeImmutable($settlementDate))->modify('+6 months')->format('Y-m-d');
        $awardType = in_array($data['award_type'] ?? '', ['Amicable Settlement', 'Arbitration Award'], true)
            ? $data['award_type'] : 'Amicable Settlement';
        $obligationType = in_array($data['obligation_type'] ?? '', ['Monetary', 'Property Return', 'Specific Performance', 'Hybrid'], true)
            ? $data['obligation_type'] : 'Monetary';

        $termsRead = !empty($data['terms_read_to_parties']) ? 1 : 0;
        $compSigned = !empty($data['complainant_signed']) ? 1 : 0;
        $respSigned = !empty($data['respondent_signed']) ? 1 : 0;
        $pbAttested = !empty($data['pb_attested']) ? 1 : 0;
        $pbAttestedBy = $pbAttested ? (!empty($data['pb_attested_by']) ? (int) $data['pb_attested_by'] : $actorUserId) : null;
        $pbAttestedAt = $pbAttested ? date('Y-m-d H:i:s') : null;
        $sealed = !empty($data['barangay_sealed']) ? 1 : 0;
        $caseFolder = !empty($data['original_in_case_folder']) ? 1 : 0;
        $copiesIssued = !empty($data['certified_copies_issued']) ? 1 : 0;

        if (!ValidationService::maxLength($agreementDetails, 15000) || !ValidationService::text($agreementDetails, true)) {
            return ['success' => false, 'message' => 'Settlement agreement terms must be 15,000 characters or fewer without unsupported control characters.'];
        }
        $rawTotalAmount = $data['total_amount'] ?? '';
        if ($rawTotalAmount !== '' && $rawTotalAmount !== null) {
            $parsedAmount = ValidationService::decimal($rawTotalAmount, 0.0, 999999999.99);
            if ($parsedAmount === null) {
                return ['success' => false, 'message' => 'Total settlement amount must be a valid non-negative amount.'];
            }
            $totalAmount = $parsedAmount;
        } else {
            $totalAmount = 0.00;
        }
        $responsibleParty = in_array($data['responsible_party'] ?? '', ['Respondent', 'Complainant', 'Both'], true) ? $data['responsible_party'] : 'Respondent';
        $hasInstallment = !empty($data['has_installment']) ? 1 : 0;
        $rawComplianceDue = trim((string) ($data['compliance_due_date'] ?? ''));
        if ($rawComplianceDue !== '' && !ValidationService::date($rawComplianceDue)) {
            return ['success' => false, 'message' => 'Compliance due date must be a valid date (YYYY-MM-DD).'];
        }
        $complianceDueDate = $rawComplianceDue !== '' ? $rawComplianceDue : null;
        if (!empty($data['has_installment']) && !empty($data['installments']) && is_array($data['installments'])) {
            foreach ($data['installments'] as $idx => $instCheck) {
                $dRaw = trim((string) ($instCheck['due_date'] ?? ''));
                $aRaw = $instCheck['amount_due'] ?? $instCheck['amount'] ?? null;
                $nRaw = trim((string) ($instCheck['notes'] ?? ''));
                if ($dRaw === '' || !ValidationService::date($dRaw)) {
                    return ['success' => false, 'message' => 'Installment #' . ($idx + 1) . ' requires a valid due date (YYYY-MM-DD).'];
                }
                if (ValidationService::decimal($aRaw, 0.01, 999999999.99) === null) {
                    return ['success' => false, 'message' => 'Installment #' . ($idx + 1) . ' amount must be greater than 0.'];
                }
                if (!ValidationService::maxLength($nRaw, 500) || !ValidationService::text($nRaw, true)) {
                    return ['success' => false, 'message' => 'Installment #' . ($idx + 1) . ' notes must be 500 characters or fewer.'];
                }
            }
        }
        $complianceStatus = in_array($data['compliance_status'] ?? '', ['Pending', 'Partially Paid', 'Fully Paid', 'Overdue', 'Breached', 'Complied', 'Violated'], true) ? $data['compliance_status'] : 'Pending';

        $isNested = $this->conn->inTransaction();
        try {
            if (!$isNested) {
                $this->conn->beginTransaction();
            }

            $stmt = $this->conn->prepare("
                INSERT INTO settlements (
                    case_id, settlement_date, agreement_details,
                    terms_read_to_parties, complainant_signed, respondent_signed,
                    pb_attested, pb_attested_by, pb_attested_at,
                    barangay_sealed, original_in_case_folder, certified_copies_issued,
                    total_amount, responsible_party, has_installment,
                    compliance_due_date, repudiation_deadline, award_type, obligation_type, six_month_deadline, repudiation_status,
                    compliance_status
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, 'Within Repudiation Period',
                    ?
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
                    updated_at = NOW()
            ");

            $stmt->execute([
                $caseId, $settlementDate, $agreementDetails,
                $termsRead, $compSigned, $respSigned,
                $pbAttested, $pbAttestedBy, $pbAttestedAt,
                $sealed, $caseFolder, $copiesIssued,
                $totalAmount, $responsibleParty, $hasInstallment,
                $complianceDueDate, $repudiationDeadline, $awardType, $obligationType, $sixMonthDeadline,
                $complianceStatus
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

            // Update case status to 'Settled' if attested and signed
            if ($compSigned && $respSigned && $pbAttested) {
                $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Settled', updated_at = NOW() WHERE case_id = ?");
                $updCase->execute([$caseId]);

                $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Settled', co.updated_at = NOW() WHERE c.case_id = ?");
                $updComp->execute([$caseId]);

                $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Settled', 'Amicable settlement reached, signed by parties, and attested by Punong Barangay. 10-day repudiation clock started.', ?)");
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

            if (!$isNested) {
                $this->conn->commit();
            }
            $kp16DocId = null;
            try {
                require_once __DIR__ . '/../services/KpFormService.php';
                $kpRes = (new KpFormService($this->conn))->autoGenerateForEvent('settlement_recorded', $caseId, $actorUserId ?: 1);
                $kp16DocId = $kpRes['generated'][0]['document_id'] ?? null;
            } catch (Throwable $kpErr) {
                error_log('Settlement KP Form 16 generation warning: ' . $kpErr->getMessage());
            }
            return [
                'success' => true,
                'message' => 'Amicable settlement recorded successfully.',
                'settlement_id' => $settlementId,
                'kp_form' => 'KP Form 16',
                'kp16_document_id' => $kp16DocId,
                'repudiation_deadline' => $repudiationDeadline,
                'six_month_deadline' => $sixMonthDeadline,
            ];
        } catch (Throwable $e) {
            if (!$isNested && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Settlement::saveSettlement error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof PDOException ? 'Failed to save settlement due to a system error.' : ('Failed to save settlement: ' . $e->getMessage())];
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
            $rawAmountPaid = $data['amount_paid'] ?? $installment['amount_due'];
            $amountPaid = ValidationService::decimal($rawAmountPaid, 0.01, 999999999.99);
            if ($amountPaid === null) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Amount paid must be a valid positive number greater than 0.'];
            }
            $rawPayDate = trim((string) ($data['payment_date'] ?? ''));
            if ($rawPayDate === '') {
                $paymentDate = date('Y-m-d H:i:s');
            } elseif (ValidationService::dateTime($rawPayDate, 'Y-m-d\TH:i')) {
                $paymentDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rawPayDate)->format('Y-m-d H:i:s');
            } elseif (ValidationService::dateTime($rawPayDate, 'Y-m-d H:i:s')) {
                $paymentDate = $rawPayDate;
            } elseif (ValidationService::date($rawPayDate)) {
                $paymentDate = $rawPayDate . ' 00:00:00';
            } else {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Provide a valid payment date and time.'];
            }
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
            error_log('Settlement::recordInstallmentPayment error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof PDOException ? 'Payment logging failed due to a system error.' : ('Payment logging failed: ' . $e->getMessage())];
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
        if ($reason === '' || !ValidationService::maxLength($reason, 10000) || !ValidationService::text($reason, true)) {
            return ['success' => false, 'message' => 'Provide a valid sworn repudiation narrative (10,000 characters or fewer).'];
        }
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

            // Automatically route case to generate KP Form 20 (Certificate to File Action - CFA)
            $cfaCheck = $this->conn->prepare("SELECT cfa_id FROM cfa_records WHERE case_id = ?");
            $cfaCheck->execute([$settlement['case_id']]);
            if (!$cfaCheck->fetchColumn()) {
                $insCfa = $this->conn->prepare("INSERT INTO cfa_records (case_id, issuance_date, reason) VALUES (?, CURDATE(), ?)");
                $insCfa->execute([
                    $settlement['case_id'],
                    "Amicable settlement repudiated under RA 7160 Section 416 on grounds of: {$reason}"
                ]);
            }

            // Invalidate / cancel court transmittal countdown
            $cancelTrans = $this->conn->prepare("UPDATE case_transmittals SET status = 'Pending', transmittal_notified = 2, updated_at = NOW() WHERE case_id = ?");
            $cancelTrans->execute([$settlement['case_id']]);

            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Repudiated', ?, ?)");
            $hist->execute([$settlement['case_id'], "Settlement repudiated within the 10-day statutory window. Ground: {$ground}. Unlocked KP Form 20 (CFA).", $actorUserId]);

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
            try {
                require_once __DIR__ . '/../services/KpFormService.php';
                (new KpFormService($this->conn))->autoGenerateForEvent('settlement_repudiated', (int) $settlement['case_id'], $actorUserId ?: 1);
            } catch (Throwable $kpErr) {
                error_log('Settlement repudiation KP Form 17/20 warning: ' . $kpErr->getMessage());
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
            error_log('Settlement::fileRepudiation error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof PDOException ? 'Repudiation filing failed due to a system error.' : ('Repudiation filing failed: ' . $e->getMessage())];
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
        $rawHearingDate = trim((string) ($data['hearing_date'] ?? ''));
        if ($rawHearingDate === '') {
            $hearingDate = '';
        } elseif (ValidationService::dateTime($rawHearingDate, 'Y-m-d\TH:i')) {
            $hearingDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rawHearingDate)->format('Y-m-d H:i:s');
        } elseif (ValidationService::dateTime($rawHearingDate, 'Y-m-d H:i:s')) {
            $hearingDate = $rawHearingDate;
        } elseif (ValidationService::date($rawHearingDate)) {
            $hearingDate = $rawHearingDate . ' 09:00:00';
        } else {
            return ['success' => false, 'message' => 'Provide a valid execution hearing date and time.'];
        }
        if (!ValidationService::maxLength($obligation, 2000) || !ValidationService::text($obligation, true)
            || !ValidationService::maxLength($amountOrRequirement, 255) || !ValidationService::text($amountOrRequirement)) {
            return ['success' => false, 'message' => 'Violated obligation or relief sought contains invalid characters or exceeds maximum length.'];
        }
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
            try {
                require_once __DIR__ . '/../services/KpFormService.php';
                (new KpFormService($this->conn))->autoGenerateForEvent('execution_motion_filed', (int) $locked['case_id'], $actorUserId ?: 1);
            } catch (Throwable $kpErr) {
                error_log('Settlement execution KP Form 23/24 warning: ' . $kpErr->getMessage());
            }
            return ['success' => true, 'message' => 'KP Form 23 filed and KP Form 24 hearing scheduled.', 'execution_id' => $executionId, 'kp_forms' => ['KP Form 23', 'KP Form 24']];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) $this->conn->rollBack();
            error_log('Settlement::fileExecutionMotion error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof PDOException ? 'Execution filing failed due to a system error.' : ('Execution filing failed: ' . $e->getMessage())];
        }
    }

    /** Records the due-process hearing and sets a voluntary-compliance grace period. */
    public function recordExecutionHearing(int $executionId, array $data, int $actorUserId): array
    {
        $minutes = trim((string) ($data['hearing_minutes'] ?? ''));
        $rawGraceEnd = trim((string) ($data['grace_period_end_date'] ?? ''));
        $graceEnd = ($rawGraceEnd !== '' && ValidationService::date($rawGraceEnd)) ? $rawGraceEnd : '';
        if ($rawGraceEnd !== '' && $graceEnd === '') {
            return ['success' => false, 'message' => 'Grace period end date must be a valid date (YYYY-MM-DD).'];
        }
        if (!ValidationService::maxLength($minutes, 15000) || !ValidationService::text($minutes, true)) {
            return ['success' => false, 'message' => 'Execution hearing minutes must be 15,000 characters or fewer without control characters.'];
        }
        $allowedAttendance = ['Present', 'Absent', 'Excused'];
        $complainant = $data['complainant_attended'] ?? 'Absent';
        $respondent = $data['respondent_attended'] ?? 'Absent';
        $absenceReason = trim((string) ($data['non_appearance_reason'] ?? ''));
        if ($minutes === '' || $graceEnd === '' || !in_array($complainant, $allowedAttendance, true) || !in_array($respondent, $allowedAttendance, true)) return ['success' => false, 'message' => 'KP Form 24 minutes, both attendance entries, and a grace-period end date are required.'];
        if (($complainant !== 'Present' || $respondent !== 'Present') && $absenceReason === '') return ['success' => false, 'message' => 'Record the reason for any party non-appearance in the hearing minutes.'];

        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) $this->conn->beginTransaction();
            $stmt = $this->conn->prepare("SELECT ep.*, s.six_month_deadline FROM execution_proceedings ep INNER JOIN settlements s ON s.settlement_id = ep.settlement_id WHERE ep.execution_id = ? FOR UPDATE");
            $stmt->execute([$executionId]); $exec = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exec) throw new RuntimeException('Execution proceeding not found.');
            if ($exec['execution_status'] !== 'Hearing Scheduled') throw new RuntimeException('Only a scheduled KP Form 24 hearing may be recorded.');
            if (date('Y-m-d') > $exec['six_month_deadline']) { if (!$nested) $this->conn->rollBack(); return $this->mtcLapseResult(); }
            if (date('Y-m-d H:i:s') < $exec['hearing_date']) throw new RuntimeException('KP Form 24 minutes cannot be recorded before the scheduled execution hearing.');
            if ($graceEnd < substr($exec['hearing_date'], 0, 10)) throw new RuntimeException('The grace period cannot end before the execution hearing.');
            $upd = $this->conn->prepare("UPDATE execution_proceedings SET complainant_attended = ?, respondent_attended = ?, non_appearance_reason = ?, hearing_minutes = ?, grace_period_end_date = ?, execution_status = 'Grace Period Active', updated_at = NOW() WHERE execution_id = ?");
            $upd->execute([$complainant, $respondent, $absenceReason ?: null, $minutes, $graceEnd, $executionId]);
            $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Settled', ?, ?)")->execute([(int) $exec['case_id'], "KP Form 24 execution hearing minutes recorded; voluntary compliance grace period ends {$graceEnd}.", $actorUserId]);
            if (!$nested) $this->conn->commit();
            return ['success' => true, 'message' => 'KP Form 24 minutes recorded and grace period activated.', 'kp_form' => 'KP Form 24'];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) $this->conn->rollBack();
            error_log('Settlement::recordExecutionHearing error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof PDOException ? 'Execution hearing update failed due to a system error.' : ('Execution hearing update failed: ' . $e->getMessage())];
        }
    }

    /** Issues KP Form 25 only after due process and expiry of the granted grace period. */
    public function issueNoticeOfExecution(int $executionId, array $data, int $actorUserId): array
    {
        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) $this->conn->beginTransaction();
            $stmt = $this->conn->prepare("SELECT ep.*, s.compliance_status, s.six_month_deadline FROM execution_proceedings ep INNER JOIN settlements s ON s.settlement_id = ep.settlement_id WHERE ep.execution_id = ? FOR UPDATE");
            $stmt->execute([$executionId]); $exec = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exec) throw new RuntimeException('Execution proceeding not found.');
            if (date('Y-m-d') > $exec['six_month_deadline']) { if (!$nested) $this->conn->rollBack(); return $this->mtcLapseResult(); }
            if ($exec['execution_status'] !== 'Grace Period Active' || empty($exec['hearing_minutes']) || empty($exec['grace_period_end_date'])) throw new RuntimeException('KP Form 25 is locked until KP Form 24 minutes and a grace period are recorded.');
            if (date('Y-m-d') <= $exec['grace_period_end_date']) throw new RuntimeException('KP Form 25 is locked until the voluntary compliance grace period has expired.');
            if (in_array($exec['compliance_status'], ['Fully Paid', 'Complied'], true)) throw new RuntimeException('The settlement is already recorded as complied with.');
            $noticeDate = !empty($data['notice_of_execution_date']) ? date('Y-m-d', strtotime((string) $data['notice_of_execution_date'])) : date('Y-m-d');
            $this->conn->prepare("UPDATE execution_proceedings SET notice_of_execution_date = ?, execution_status = 'Notice of Execution Issued', updated_at = NOW() WHERE execution_id = ?")->execute([$noticeDate, $executionId]);
            $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Settled', ?, ?)")->execute([(int) $exec['case_id'], "KP Form 25 Notice of Execution issued on {$noticeDate} after unfulfilled grace period.", $actorUserId]);
            if (!$nested) $this->conn->commit();
            try {
                require_once __DIR__ . '/../services/KpFormService.php';
                (new KpFormService($this->conn))->autoGenerateForEvent('notice_of_execution_issued', (int) $exec['case_id'], $actorUserId ?: 1);
            } catch (Throwable $kpErr) {
                error_log('Settlement execution KP Form 25 warning: ' . $kpErr->getMessage());
            }
            return ['success' => true, 'message' => 'KP Form 25 Notice of Execution issued.', 'kp_form' => 'KP Form 25'];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) $this->conn->rollBack();
            error_log('Settlement::issueNoticeOfExecution error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof PDOException ? 'Notice of Execution could not be issued due to a system error.' : ('Notice of Execution could not be issued: ' . $e->getMessage())];
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
            $this->conn->prepare("UPDATE settlements SET compliance_status = 'Complied', compliance_date = CURDATE(), updated_at = NOW() WHERE settlement_id = ?")->execute([$exec['settlement_id']]);
            if (!$nested) $this->conn->commit();
            return ['success' => true, 'message' => 'Execution marked Enforced / Complied.'];
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) $this->conn->rollBack();
            error_log('Settlement::updateExecutionStatus error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof PDOException ? 'Execution completion failed due to a system error.' : ('Execution completion failed: ' . $e->getMessage())];
        }
    }

    private function mtcLapseResult(): array
    {
        return ['success' => false, 'lapsed_to_mtc' => true, 'message' => 'Barangay execution jurisdiction has lapsed. Refer the aggrieved party to the MTC/MeTC for judicial enforcement.', 'mtc_referral' => 'Prepare a certified settlement or award, proof of finality, execution record, and case transmittal for the appropriate MTC/MeTC.'];
    }
}
