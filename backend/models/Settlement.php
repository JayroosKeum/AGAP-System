<?php

require_once __DIR__ . '/../config/database.php';

class Settlement
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
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

        // Auto-update repudiation_status to 'Enforceable' / 'Repudiation Expired' if deadline has passed
        $this->conn->exec("
            UPDATE settlements 
            SET repudiation_status = 'Enforceable' 
            WHERE repudiation_status = 'Within Repudiation Period' 
              AND repudiation_deadline IS NOT NULL 
              AND repudiation_deadline < CURDATE()
        ");

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
                   (SELECT COUNT(*) FROM settlement_installments si WHERE si.settlement_id = s.settlement_id) AS total_installments,
                   (SELECT COUNT(*) FROM settlement_installments si WHERE si.settlement_id = s.settlement_id AND si.payment_status = 'Paid') AS paid_installments,
                   (SELECT COUNT(*) FROM settlement_installments si WHERE si.settlement_id = s.settlement_id AND si.payment_status = 'Overdue') AS overdue_installments,
                   (SELECT COUNT(*) FROM settlement_executions se WHERE se.settlement_id = s.settlement_id) AS execution_count
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
                   DATEDIFF(s.repudiation_deadline, CURDATE()) AS repudiation_days_left
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

        // Executions
        $execStmt = $this->conn->prepare("
            SELECT se.*, 
                   TRIM(CONCAT_WS(' ', res.first_name, res.middle_name, res.last_name)) AS motion_filed_by_name, 
                   TRIM(CONCAT_WS(' ', off.first_name, off.middle_name, off.last_name)) AS officer_assigned_name
            FROM settlement_executions se
            LEFT JOIN residents res ON res.resident_id = se.motion_filed_by
            LEFT JOIN users off ON off.user_id = se.officer_assigned
            WHERE se.settlement_id = ?
            ORDER BY se.created_at DESC
        ");
        $execStmt->execute([$settlementId]);
        $record['executions'] = $execStmt->fetchAll(PDO::FETCH_ASSOC);

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

        $settlementDate = !empty($data['settlement_date']) ? date('Y-m-d', strtotime($data['settlement_date'])) : date('Y-m-d');
        $agreementDetails = trim((string) ($data['agreement_details'] ?? ''));
        if ($agreementDetails === '') {
            return ['success' => false, 'message' => 'Detailed settlement agreement terms are required.'];
        }

        // 10-day statutory repudiation deadline (Sec. 418 Local Government Code)
        $repudiationDeadline = date('Y-m-d', strtotime($settlementDate . ' + 10 days'));

        $termsRead = !empty($data['terms_read_to_parties']) ? 1 : 0;
        $compSigned = !empty($data['complainant_signed']) ? 1 : 0;
        $respSigned = !empty($data['respondent_signed']) ? 1 : 0;
        $pbAttested = !empty($data['pb_attested']) ? 1 : 0;
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

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("
                INSERT INTO settlements (
                    case_id, settlement_date, agreement_details,
                    terms_read_to_parties, complainant_signed, respondent_signed,
                    pb_attested, pb_attested_by, pb_attested_at,
                    barangay_sealed, original_in_case_folder, certified_copies_issued,
                    total_amount, responsible_party, has_installment,
                    compliance_due_date, repudiation_deadline, repudiation_status,
                    compliance_status
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, 'Within Repudiation Period',
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
                    compliance_status = VALUES(compliance_status),
                    updated_at = NOW()
            ");

            $stmt->execute([
                $caseId, $settlementDate, $agreementDetails,
                $termsRead, $compSigned, $respSigned,
                $pbAttested, $pbAttestedBy, $pbAttestedAt,
                $sealed, $caseFolder, $copiesIssued,
                $totalAmount, $responsibleParty, $hasInstallment,
                $complianceDueDate, $repudiationDeadline,
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
            }

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Amicable settlement recorded successfully.',
                'settlement_id' => $settlementId,
                'repudiation_deadline' => $repudiationDeadline,
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to save settlement: ' . $e->getMessage()];
        }
    }

    /**
     * Records an installment payment with receipt details.
     */
    public function recordInstallmentPayment(int $installmentId, array $data, int $actorUserId): array
    {
        try {
            $this->conn->beginTransaction();

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
            return ['success' => false, 'message' => 'Sworn grounds for repudiation (e.g., vitiated consent, fraud, violence, intimidation) are required.'];
        }

        $residentId = filter_var($data['repudiated_by'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$residentId) {
            return ['success' => false, 'message' => 'A valid party must be specified as repudiating the settlement.'];
        }

        // Check 10-day limit
        $today = date('Y-m-d');
        if ($settlement['repudiation_deadline'] && $today > $settlement['repudiation_deadline'] && empty($data['force_repudiation'])) {
            return [
                'success' => false,
                'message' => "The 10-day statutory repudiation period expired on {$settlement['repudiation_deadline']}. Under Section 418 of the Local Government Code, the settlement is now final and legally enforceable."
            ];
        }

        try {
            $this->conn->beginTransaction();

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
            $stmt->execute([$reason, $residentId, $docPath ?: null, $settlementId]);

            // Update case status
            $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Repudiated', updated_at = NOW() WHERE case_id = ?");
            $updCase->execute([$settlement['case_id']]);

            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Repudiated', ?, ?)");
            $hist->execute([$settlement['case_id'], "Settlement repudiated within 10-day statutory window. Ground: {$reason}", $actorUserId]);

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Sworn repudiation statement filed successfully. Case marked as Repudiated.'
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Repudiation filing failed: ' . $e->getMessage()];
        }
    }

    /**
     * Files a Motion for Execution upon non-compliance after the repudiation period.
     */
    public function fileExecutionMotion(int $settlementId, array $data, int $actorUserId): array
    {
        $settlement = $this->getById($settlementId);
        if (!$settlement) {
            return ['success' => false, 'message' => 'Settlement record not found.'];
        }

        // Must be past repudiation period or already enforceable
        $today = date('Y-m-d');
        if ($settlement['repudiation_deadline'] && $today <= $settlement['repudiation_deadline'] && empty($data['force_execution'])) {
            return [
                'success' => false,
                'message' => "Cannot file Motion for Execution while the 10-day repudiation period is still active (ends {$settlement['repudiation_deadline']})."
            ];
        }

        $obligation = trim((string) ($data['obligation_violated'] ?? ''));
        $amountOrReq = trim((string) ($data['amount_or_requirement'] ?? ''));
        $motionFiledBy = filter_var($data['motion_filed_by'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($obligation === '' || $amountOrReq === '' || !$motionFiledBy) {
            return ['success' => false, 'message' => 'Please provide the violated obligation, required remedy/amount, and filing party.'];
        }

        try {
            $this->conn->beginTransaction();

            $ins = $this->conn->prepare("
                INSERT INTO settlement_executions (
                    settlement_id, case_id, obligation_violated, due_date,
                    amount_or_requirement, evidence_notes, evidence_file_path,
                    motion_date, motion_filed_by, execution_status
                ) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, CURDATE(), ?, 'Motion Filed')
            ");
            $ins->execute([
                $settlementId,
                $settlement['case_id'],
                $obligation,
                $amountOrReq,
                trim((string) ($data['evidence_notes'] ?? '')) ?: null,
                trim((string) ($data['evidence_file_path'] ?? '')) ?: null,
                $motionFiledBy
            ]);
            $executionId = (int) $this->conn->lastInsertId();

            // Mark settlement as Breached
            $this->conn->prepare("UPDATE settlements SET compliance_status = 'Breached', updated_at = NOW() WHERE settlement_id = ?")->execute([$settlementId]);

            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Breached', ?, ?)");
            $hist->execute([$settlement['case_id'], "Motion for Execution filed (Execution #{$executionId}). Violated: {$obligation}", $actorUserId]);

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Motion for Execution filed successfully.',
                'execution_id' => $executionId
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Execution filing failed: ' . $e->getMessage()];
        }
    }

    /**
     * Updates execution process status (e.g. Notice Issued, Complied Under Execution, Endorsed to Court).
     */
    public function updateExecutionStatus(int $executionId, array $data, int $actorUserId): array
    {
        $status = trim((string) ($data['execution_status'] ?? ''));
        $allowed = ['Motion Filed', 'Notice Issued', 'Execution In Progress', 'Complied Under Execution', 'Execution Failed', 'Endorsed to Court'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'Invalid execution status.'];
        }

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare('SELECT * FROM settlement_executions WHERE execution_id = ?');
            $stmt->execute([$executionId]);
            $exec = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exec) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Execution record not found.'];
            }

            $actionTaken = trim((string) ($data['action_taken'] ?? ''));
            $officerId = !empty($data['officer_assigned']) ? (int) $data['officer_assigned'] : null;
            $noticeDate = !empty($data['notice_of_execution_date']) ? date('Y-m-d', strtotime($data['notice_of_execution_date'])) : null;

            $upd = $this->conn->prepare("
                UPDATE settlement_executions
                SET execution_status = ?,
                    action_taken = ?,
                    officer_assigned = ?,
                    notice_of_execution_date = COALESCE(?, notice_of_execution_date),
                    updated_at = NOW()
                WHERE execution_id = ?
            ");
            $upd->execute([$status, $actionTaken ?: null, $officerId, $noticeDate, $executionId]);

            if ($status === 'Complied Under Execution') {
                $this->conn->prepare("UPDATE settlements SET compliance_status = 'Complied', updated_at = NOW() WHERE settlement_id = ?")->execute([$exec['settlement_id']]);
            }

            $this->conn->commit();
            return ['success' => true, 'message' => "Execution status updated to '{$status}'."];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Execution update failed: ' . $e->getMessage()];
        }
    }
}
