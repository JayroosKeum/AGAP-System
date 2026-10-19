<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/MediationDeadlineService.php';
require_once __DIR__ . '/../services/AuditService.php';

class CaseTransmittal
{
    private PDO $conn;
    private AuditService $audit;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?: (new Database())->connect();
        $this->audit = new AuditService();
    }

    /**
     * Retrieves the transmittal record for a case, computing live repudiation countdown.
     */
    public function getByCaseId(int $caseId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT t.*,
                   TRIM(CONCAT_WS(' ', u_trans.first_name, u_trans.middle_name, u_trans.last_name)) AS transmitting_officer_name,
                   TRIM(CONCAT_WS(' ', u_att.first_name, u_att.middle_name, u_att.last_name)) AS attesting_officer_name
            FROM case_transmittals t
            LEFT JOIN users u_trans ON u_trans.user_id = t.transmitting_officer_id
            LEFT JOIN users u_att ON u_att.user_id = t.attesting_officer_id
            WHERE t.case_id = ?
            ORDER BY t.transmittal_id DESC
            LIMIT 1
        ");
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->enrichTransmittalData($row);
    }

    /**
     * Creates or returns existing transmittal for a settled case.
     */
    public function createOrGetTransmittal(int $caseId, ?int $officerId = null): array
    {
        $existing = $this->getByCaseId($caseId);
        if ($existing) {
            return $existing;
        }

        // 1. Determine settlement date
        $settleStmt = $this->conn->prepare("
            SELECT settlement_date, created_at, repudiation_deadline
            FROM settlements
            WHERE case_id = ?
            ORDER BY settlement_id DESC
            LIMIT 1
        ");
        $settleStmt->execute([$caseId]);
        $settle = $settleStmt->fetch(PDO::FETCH_ASSOC);

        $settlementDate = null;
        if (!empty($settle['settlement_date'])) {
            $settlementDate = $settle['settlement_date'];
        } else {
            // Fallback to case updated_at or today
            $caseStmt = $this->conn->prepare("SELECT updated_at, docket_date FROM cases WHERE case_id = ?");
            $caseStmt->execute([$caseId]);
            $c = $caseStmt->fetch(PDO::FETCH_ASSOC);
            $settlementDate = !empty($c['updated_at']) ? substr($c['updated_at'], 0, 10) : date('Y-m-d');
        }

        // 2. Compute 10-working-day expiry date under RA 7160 Section 411
        $expiryDate = MediationDeadlineService::calculateBusinessDays($settlementDate, 10);

        // 3. Generate unique transmittal reference number (e.g. TR-2026-0001)
        $year = date('Y');
        $numStmt = $this->conn->prepare("SELECT COUNT(*) FROM case_transmittals WHERE YEAR(created_at) = ?");
        $numStmt->execute([$year]);
        $seq = ((int) $numStmt->fetchColumn()) + 1;
        $transmittalNumber = sprintf('TR-%s-%04d', $year, $seq);

        // Check uniqueness and handle edge collision
        $checkStmt = $this->conn->prepare("SELECT 1 FROM case_transmittals WHERE transmittal_number = ?");
        $checkStmt->execute([$transmittalNumber]);
        if ($checkStmt->fetchColumn()) {
            $transmittalNumber = sprintf('TR-%s-%04d-%d', $year, $seq, time() % 1000);
        }

        // 4. Default officers (Secretary & Punong Barangay)
        $secStmt = $this->conn->prepare("SELECT user_id FROM users WHERE role_id IN (2, 3) ORDER BY role_id ASC LIMIT 1");
        $secStmt->execute();
        $defaultSecId = $officerId ?: ($secStmt->fetchColumn() ?: null);

        $pbStmt = $this->conn->prepare("SELECT user_id FROM users WHERE role_id = 1 LIMIT 1");
        $pbStmt->execute();
        $defaultPbId = $pbStmt->fetchColumn() ?: null;

        // 5. Insert transmittal record
        $ins = $this->conn->prepare("
            INSERT INTO case_transmittals (
                case_id, transmittal_number, settlement_date, ten_day_expiry_date,
                target_agency, transmitting_officer_id, attesting_officer_id, status, created_at
            ) VALUES (?, ?, ?, ?, 'Both', ?, ?, 'Pending', NOW())
        ");
        $ins->execute([
            $caseId,
            $transmittalNumber,
            $settlementDate,
            $expiryDate,
            $defaultSecId,
            $defaultPbId
        ]);

        return $this->getByCaseId($caseId);
    }

    /**
     * Calculates the remaining working days between current date and the 10-day expiry date.
     */
    public function calculateWorkingDaysRemaining(string $expiryDate): int
    {
        $today = new DateTimeImmutable(date('Y-m-d'));
        $expiry = new DateTimeImmutable(substr($expiryDate, 0, 10));

        if ($today >= $expiry) {
            return 0;
        }

        $cur = $today;
        $workingDays = 0;
        while ($cur < $expiry) {
            $cur = $cur->modify('+1 day');
            if (!MediationDeadlineService::isNonWorkingDay($cur)) {
                $workingDays++;
            }
        }

        return max(0, $workingDays);
    }

    /**
     * Enriches a transmittal database row with dynamic countdown and UI badge properties.
     */
    private function enrichTransmittalData(array $row): array
    {
        $settlementDate = $row['settlement_date'];
        $expiryDate = $row['ten_day_expiry_date'];
        $daysRemaining = $this->calculateWorkingDaysRemaining($expiryDate);
        $isWindowOpen = ($daysRemaining > 0);
        $isReady = (!$isWindowOpen && $row['status'] !== 'Transmitted' && $row['status'] !== 'Acknowledged');

        // Formatted dates
        $sTs = strtotime($settlementDate);
        $eTs = strtotime($expiryDate);
        $settlementDateFormatted = $sTs ? date('M j, Y', $sTs) : $settlementDate;
        $expiryDateFormatted = $eTs ? date('M j, Y', $eTs) : $expiryDate;

        // Computed status badge
        $statusKey = $row['status'];
        $badgeClass = 'badge-neutral';
        $badgeLabel = $statusKey;

        if ($statusKey === 'Transmitted') {
            $badgeClass = 'badge-blue';
            $badgeLabel = 'Transmitted to MTC & DILG';
        } elseif ($statusKey === 'Acknowledged') {
            $badgeClass = 'badge-green';
            $badgeLabel = 'Acknowledged by MTC & DILG';
        } elseif ($isWindowOpen) {
            $badgeClass = 'badge-amber';
            $badgeLabel = sprintf('Repudiation Window Open: %d working day%s left', $daysRemaining, $daysRemaining === 1 ? '' : 's');
        } else {
            $badgeClass = 'badge-green';
            $badgeLabel = '✓ Ready for Transmittal';
        }

        $row['working_days_remaining'] = $daysRemaining;
        $row['is_repudiation_window_open'] = $isWindowOpen;
        $row['is_ready_for_transmittal'] = $isReady;
        $row['settlement_date_formatted'] = $settlementDateFormatted;
        $row['ten_day_expiry_date_formatted'] = $expiryDateFormatted;
        $row['badge_class'] = $badgeClass;
        $row['badge_label'] = $badgeLabel;

        // Target agencies description
        $row['target_agency_label'] = match ($row['target_agency']) {
            'MTC' => 'Clerk of Court, MTCC Marikina',
            'DILG' => 'City Director, DILG Marikina Field Office',
            default => 'MTC (Clerk of Court) & DILG Marikina Field Office',
        };

        return $row;
    }

    /**
     * Records court and DILG transmission with receiving details and optional receiving copy upload.
     */
    public function recordSubmission(int $caseId, array $data, ?array $file, int $userId): array
    {
        $transmittal = $this->getByCaseId($caseId);
        if (!$transmittal) {
            $transmittal = $this->createOrGetTransmittal($caseId, $userId);
        }

        $transmittalDate = !empty($data['transmittal_date']) ? trim((string) $data['transmittal_date']) : date('Y-m-d');
        $targetAgency = in_array(($data['target_agency'] ?? 'Both'), ['MTC', 'DILG', 'Both'], true) ? $data['target_agency'] : 'Both';
        $mtcReceivedDate = !empty($data['mtc_received_date']) ? trim((string) $data['mtc_received_date']) : null;
        $mtcReceiverName = !empty($data['mtc_receiver_name']) ? trim((string) $data['mtc_receiver_name']) : null;
        $dilgReceivedDate = !empty($data['dilg_received_date']) ? trim((string) $data['dilg_received_date']) : null;
        $dilgReceiverName = !empty($data['dilg_receiver_name']) ? trim((string) $data['dilg_receiver_name']) : null;
        $remarks = !empty($data['remarks']) ? trim((string) $data['remarks']) : null;

        // Process file upload if provided
        $receivingCopyPath = null;
        if ($file && !empty($file['name']) && ($file['error'] === UPLOAD_ERR_OK)) {
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExtensions, true)) {
                return ['success' => false, 'message' => 'Invalid receiving copy file type. Allowed: PDF, JPG, PNG, WEBP.'];
            }

            if ($file['size'] > 15 * 1024 * 1024) {
                return ['success' => false, 'message' => 'Receiving copy file exceeds the maximum 15MB limit.'];
            }

            // Case number directory
            $caseStmt = $this->conn->prepare("SELECT case_number FROM cases WHERE case_id = ?");
            $caseStmt->execute([$caseId]);
            $caseNumber = $caseStmt->fetchColumn() ?: "case-{$caseId}";
            $safeCaseDir = preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber);

            $relativeDir = 'storage/uploads/transmittals/' . $safeCaseDir;
            $absoluteDir = __DIR__ . '/../../' . $relativeDir;
            if (!is_dir($absoluteDir)) {
                @mkdir($absoluteDir, 0755, true);
            }

            $fileName = sprintf('transmittal-receiving-copy-%d-%s.%s', $caseId, date('Ymd_His'), $ext);
            $targetPath = $absoluteDir . '/' . $fileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $receivingCopyPath = $relativeDir . '/' . $fileName;
            }
        }

        // Determine status: Acknowledged if both receivers logged, else Transmitted
        $hasReceivers = (!empty($mtcReceiverName) || !empty($dilgReceiverName));
        $newStatus = $hasReceivers ? 'Acknowledged' : 'Transmitted';

        // Update database
        $upd = $this->conn->prepare("
            UPDATE case_transmittals SET
                status = ?,
                transmittal_date = ?,
                target_agency = ?,
                mtc_received_date = ?,
                mtc_receiver_name = ?,
                dilg_received_date = ?,
                dilg_receiver_name = ?,
                receiving_copy_path = COALESCE(?, receiving_copy_path),
                remarks = ?,
                transmitting_officer_id = ?,
                updated_at = NOW()
            WHERE case_id = ?
        ");
        $upd->execute([
            $newStatus,
            $transmittalDate,
            $targetAgency,
            $mtcReceivedDate,
            $mtcReceiverName,
            $dilgReceivedDate,
            $dilgReceiverName,
            $receivingCopyPath,
            $remarks,
            $userId,
            $caseId
        ]);

        // Audit Trail
        $caseStmt = $this->conn->prepare("SELECT case_number FROM cases WHERE case_id = ?");
        $caseStmt->execute([$caseId]);
        $caseNum = $caseStmt->fetchColumn() ?: "Case #{$caseId}";

        $auditDesc = sprintf(
            'Recorded Court & DILG Transmittal for %s. Transmittal No: %s. Agency: %s. Status: %s.',
            $caseNum,
            $transmittal['transmittal_number'] ?? 'N/A',
            $targetAgency,
            $newStatus
        );
        $this->audit->log($userId, $auditDesc, 'Transmittals', $caseId);

        // Case History Entry
        try {
            $histMsg = sprintf(
                'Transmitted certified settlement records to MTCC Marikina & DILG Field Office (Ref: %s). %s',
                $transmittal['transmittal_number'] ?? 'N/A',
                $remarks ? "Remarks: {$remarks}" : ''
            );
            $hStmt = $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, 'Settled', ?, ?)
            ");
            $hStmt->execute([$caseId, $histMsg, $userId]);
        } catch (Throwable) {
            // non-fatal history log
        }

        return [
            'success' => true,
            'message' => 'Court & DILG transmittal recorded successfully.',
            'transmittal' => $this->getByCaseId($caseId)
        ];
    }

    /**
     * Fetches comprehensive case, complaint, party, and settlement details needed for rendering the transmittal letter.
     */
    public function getTransmittalFullDetails(int $caseId): array
    {
        $transmittal = $this->createOrGetTransmittal($caseId);

        // Fetch case and complaint
        $caseStmt = $this->conn->prepare("
            SELECT c.case_id, c.case_number, c.case_type, c.case_status, c.docket_date,
                   co.complaint_id, co.complaint_number, co.complaint_title, co.narrative,
                   cat.category_name
            FROM cases c
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            LEFT JOIN complaint_categories cat ON cat.category_id = co.category_id
            WHERE c.case_id = ?
        ");
        $caseStmt->execute([$caseId]);
        $caseInfo = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caseInfo) {
            throw new RuntimeException("Case #{$caseId} not found.");
        }

        // Fetch parties
        $partiesStmt = $this->conn->prepare("
            SELECT cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, COALESCE(cp.suffix, r.suffix))) AS full_name,
                   r.address, r.contact_no
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY cp.party_type ASC, cp.party_id ASC
        ");
        $partiesStmt->execute([$caseInfo['complaint_id']]);
        $allParties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainants = [];
        $respondents = [];
        foreach ($allParties as $p) {
            if ($p['party_type'] === 'Complainant') {
                $complainants[] = $p['full_name'];
            } else {
                $respondents[] = $p['full_name'];
            }
        }

        // Fetch settlement record
        $settleStmt = $this->conn->prepare("
            SELECT * FROM settlements WHERE case_id = ? ORDER BY settlement_id DESC LIMIT 1
        ");
        $settleStmt->execute([$caseId]);
        $settlement = $settleStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Fetch secretary and captain names
        $secName = $transmittal['transmitting_officer_name'] ?? '';
        if (!$secName) {
            $secStmt = $this->conn->prepare("
                SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name
                FROM users WHERE role_id IN (2, 3) ORDER BY role_id ASC LIMIT 1
            ");
            $secStmt->execute();
            $secName = $secStmt->fetchColumn() ?: 'Lupon Secretary';
        }

        $pbName = $transmittal['attesting_officer_name'] ?? '';
        if (!$pbName) {
            $pbStmt = $this->conn->prepare("
                SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name
                FROM users WHERE role_id = 1 LIMIT 1
            ");
            $pbStmt->execute();
            $pbName = $pbStmt->fetchColumn() ?: 'Punong Barangay';
        }

        return [
            'transmittal' => $transmittal,
            'case' => $caseInfo,
            'complainants' => $complainants,
            'respondents' => $respondents,
            'settlement' => $settlement,
            'secretary_name' => $secName,
            'punong_barangay_name' => $pbName,
        ];
    }
}
