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
     * Creates or returns existing transmittal for a resolved case.
     */
    public function createOrGetTransmittal(int $caseId, ?int $officerId = null): ?array
    {
        $existing = $this->getByCaseId($caseId);
        if ($existing) {
            return $existing;
        }

        // 1. Determine resolution type & date (Settlement, Arbitration Award, or CFA)
        $settleStmt = $this->conn->prepare("
            SELECT settlement_date, created_at, repudiation_deadline
            FROM settlements
            WHERE case_id = ?
            ORDER BY settlement_id DESC
            LIMIT 1
        ");
        $settleStmt->execute([$caseId]);
        $settle = $settleStmt->fetch(PDO::FETCH_ASSOC);

        $arbStmt = $this->conn->prepare("
            SELECT award_date, created_at
            FROM arbitration_records
            WHERE case_id = ?
            ORDER BY arbitration_id DESC
            LIMIT 1
        ");
        $arbStmt->execute([$caseId]);
        $arb = $arbStmt->fetch(PDO::FETCH_ASSOC);

        $cfaStmt = $this->conn->prepare("
            SELECT issuance_date, created_at
            FROM cfa_records
            WHERE case_id = ?
            ORDER BY cfa_id DESC
            LIMIT 1
        ");
        $cfaStmt->execute([$caseId]);
        $cfa = $cfaStmt->fetch(PDO::FETCH_ASSOC);

        $caseStmt = $this->conn->prepare("SELECT case_status, updated_at, docket_date FROM cases WHERE case_id = ?");
        $caseStmt->execute([$caseId]);
        $c = $caseStmt->fetch(PDO::FETCH_ASSOC);

        $resolutionType = null;
        $settlementDate = null;

        if ($settle) {
            $resolutionType = 'AMICABLE SETTLEMENT';
            $settlementDate = !empty($settle['settlement_date']) ? $settle['settlement_date'] : substr($settle['created_at'], 0, 10);
        } elseif ($arb) {
            $resolutionType = 'ARBITRATION AWARD';
            $settlementDate = !empty($arb['award_date']) ? $arb['award_date'] : substr($arb['created_at'], 0, 10);
        } elseif ($cfa) {
            $resolutionType = 'CERTIFICATE TO FILE ACTION';
            $settlementDate = !empty($cfa['issuance_date']) ? $cfa['issuance_date'] : substr($cfa['created_at'], 0, 10);
        } elseif ($c && in_array($c['case_status'], ['CFA Issued', 'Dismissed'], true)) {
            $resolutionType = 'CERTIFICATE TO FILE ACTION';
            $settlementDate = !empty($c['updated_at']) ? substr($c['updated_at'], 0, 10) : date('Y-m-d');
        } elseif ($c && $c['case_status'] === 'Settled') {
            $resolutionType = 'AMICABLE SETTLEMENT';
            $settlementDate = !empty($c['updated_at']) ? substr($c['updated_at'], 0, 10) : date('Y-m-d');
        } else {
            // Check if $caseId is a complaint_id with a Settled paanyaya or Settled status
            $compStmt = $this->conn->prepare("SELECT complaint_id, complaint_number, status, updated_at, created_at FROM complaints WHERE complaint_id = ?");
            $compStmt->execute([$caseId]);
            $comp = $compStmt->fetch(PDO::FETCH_ASSOC);

            if ($comp && ($comp['status'] === 'Settled' || $comp['status'] === 'Resolved at Paanyaya / Closed')) {
                $pMinStmt = $this->conn->prepare("SELECT dialogue_date, created_at, agreed_terms FROM paanyaya_minutes WHERE complaint_id = ? AND outcome = 'Settled' ORDER BY minutes_id DESC LIMIT 1");
                $pMinStmt->execute([$caseId]);
                $pMin = $pMinStmt->fetch(PDO::FETCH_ASSOC);

                $resolutionType = 'AMICABLE SETTLEMENT';
                $settlementDate = !empty($pMin['dialogue_date'])
                    ? substr($pMin['dialogue_date'], 0, 10)
                    : (!empty($pMin['created_at']) ? substr($pMin['created_at'], 0, 10) : substr($comp['updated_at'], 0, 10));
            } else {
                return null;
            }
        }

        // 2. Compute 10-working-day expiry date under RA 7160 Section 416 (excluding weekends & holidays)
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

        if (!$c) {
            $row = [
                'transmittal_id' => 0,
                'case_id' => 0,
                'complaint_id' => $caseId,
                'transmittal_number' => $transmittalNumber,
                'settlement_date' => $settlementDate,
                'resolution_type' => $resolutionType,
                'ten_day_expiry_date' => $expiryDate,
                'target_agency' => 'Both',
                'transmitting_officer_id' => $defaultSecId,
                'attesting_officer_id' => $defaultPbId,
                'status' => 'Pending',
                'transmittal_notified' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'transmitting_officer_name' => 'MARILOU P. CRUZ',
                'attesting_officer_name' => 'HON. AKIKO LYNNE CENTENO'
            ];
            return $this->enrichTransmittalData($row);
        }

        // 5. Insert transmittal record
        $ins = $this->conn->prepare("
            INSERT INTO case_transmittals (
                case_id, transmittal_number, settlement_date, resolution_type, ten_day_expiry_date,
                target_agency, transmitting_officer_id, attesting_officer_id, status, transmittal_notified, created_at
            ) VALUES (?, ?, ?, ?, ?, 'Both', ?, ?, 'Pending', 0, NOW())
        ");
        $ins->execute([
            $caseId,
            $transmittalNumber,
            $settlementDate,
            $resolutionType,
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
     * Enriches a transmittal database row with dynamic countdown, statutory notification, and UI badge properties.
     */
    private function enrichTransmittalData(array $row): array
    {
        $caseId = (int) ($row['case_id'] ?? 0);
        $settleData = null;
        if ($caseId > 0) {
            // Check if settlement was repudiated
            $settleCheck = $this->conn->prepare("SELECT repudiation_status, repudiation_reason, repudiation_date FROM settlements WHERE case_id = ? ORDER BY settlement_id DESC LIMIT 1");
            $settleCheck->execute([$caseId]);
            $settleData = $settleCheck->fetch(PDO::FETCH_ASSOC);
        }

        if ($settleData && ($settleData['repudiation_status'] === 'Repudiated' || !empty($settleData['repudiation_date']))) {
            $row['is_locked'] = true;
            $row['is_repudiated'] = true;
            $row['is_ready_for_transmittal'] = false;
            $row['working_days_remaining'] = 0;
            $row['badge_class'] = 'badge-red';
            $row['badge_label'] = '⚠️ Settlement Repudiated — Transmittal Cancelled';
            $row['repudiation_reason'] = $settleData['repudiation_reason'];
            $row['repudiation_date'] = $settleData['repudiation_date'];
            $row['settlement_date_formatted'] = !empty($row['settlement_date']) ? date('M j, Y', strtotime($row['settlement_date'])) : '';
            $row['ten_day_expiry_date_formatted'] = !empty($row['ten_day_expiry_date']) ? date('M j, Y', strtotime($row['ten_day_expiry_date'])) : '';
            $row['unlock_date_formatted'] = $row['ten_day_expiry_date_formatted'];
            return $row;
        }

        $row['is_repudiated'] = false;
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
        $resolutionLegalDate = $this->formatLegalDate($settlementDate);

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
            $badgeLabel = sprintf('🔒 Locked (%d working day%s remaining - Repudiation Window)', $daysRemaining, $daysRemaining === 1 ? '' : 's');
        } else {
            $badgeClass = 'badge-green';
            $badgeLabel = '🔓 Ready for MTC / DILG Transmittal';
        }

        // Automatic in-app notification when 10 working days elapse
        if ($daysRemaining <= 0 && empty($row['transmittal_notified'])) {
            $this->dispatchTransmittalNotification((int)$row['transmittal_id'], (int)$row['case_id']);
            $row['transmittal_notified'] = 1;
        }

        $row['working_days_remaining'] = $daysRemaining;
        $row['is_locked'] = $isWindowOpen;
        $row['is_repudiation_window_open'] = $isWindowOpen;
        $row['is_ready_for_transmittal'] = $isReady;
        $row['settlement_date_formatted'] = $settlementDateFormatted;
        $row['ten_day_expiry_date_formatted'] = $expiryDateFormatted;
        $row['unlock_date_formatted'] = $expiryDateFormatted;
        $row['resolution_date_legal'] = $resolutionLegalDate;
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
     * Formats a date string into formal legal style: e.g. "30th day of September, 2026".
     */
    public function formatLegalDate(string $dateStr): string
    {
        $ts = strtotime($dateStr);
        if (!$ts) return $dateStr;
        $day = date('j', $ts);
        $suffix = date('S', $ts);
        $monthYear = date('F, Y', $ts);
        return "{$day}{$suffix} day of {$monthYear}";
    }

    /**
     * Dispatches in-app notification to PB, Secretary, and Lupon Clerks when 10 working days elapse.
     */
    private function dispatchTransmittalNotification(int $transmittalId, int $caseId): void
    {
        try {
            // Mark notified immediately
            $upd = $this->conn->prepare("UPDATE case_transmittals SET transmittal_notified = 1, updated_at = NOW() WHERE transmittal_id = ?");
            $upd->execute([$transmittalId]);

            // Fetch case particulars
            $cStmt = $this->conn->prepare("SELECT case_number, complaint_id FROM cases WHERE case_id = ?");
            $cStmt->execute([$caseId]);
            $c = $cStmt->fetch(PDO::FETCH_ASSOC);
            if (!$c) return;

            $caseNumber = $c['case_number'];
            $complaintId = $c['complaint_id'];

            // Fetch staff users (role_id 1 = PB, 2 = Secretary, 3 = Lupon)
            $uStmt = $this->conn->prepare("SELECT user_id FROM users WHERE role_id IN (1, 2, 3)");
            $uStmt->execute();
            $userIds = $uStmt->fetchAll(PDO::FETCH_COLUMN);

            $title = "Case #{$caseNumber} Ready for Court Transmittal";
            $message = "The 10-working-day repudiation period has elapsed for Case #{$caseNumber}. The Court Transmittal Letter to the Metropolitan Trial Court is now unlocked and ready for printing.";
            $actionUrl = "../complaints/complaint-details.php?id={$complaintId}&tab=tab-overview";

            $notifStmt = $this->conn->prepare("
                INSERT INTO notifications (user_id, title, severity, message, action_url, case_id, created_at)
                VALUES (?, ?, 'info', ?, ?, ?, NOW())
            ");
            foreach ($userIds as $uid) {
                $notifStmt->execute([(int)$uid, $title, $message, $actionUrl, $caseId]);
            }
        } catch (Throwable $e) {
            error_log("Failed to dispatch transmittal notification: " . $e->getMessage());
        }
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

            if ($file['size'] > (15 * 1024 * 1024)) {
                return ['success' => false, 'message' => 'File size exceeds maximum allowed limit (15MB).'];
            }

            $uploadDir = __DIR__ . '/../../uploads/transmittals/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                return ['success' => false, 'message' => 'Failed to initialize transmittals storage directory.'];
            }

            $filename = sprintf('receiving_case_%d_%s.%s', $caseId, uniqid(), $ext);
            $destination = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $receivingCopyPath = 'uploads/transmittals/' . $filename;
            }
        }

        // Determine new status
        $newStatus = 'Transmitted';
        if ($targetAgency === 'Both' && $mtcReceivedDate && $dilgReceivedDate) {
            $newStatus = 'Acknowledged';
        } elseif ($targetAgency === 'MTC' && $mtcReceivedDate) {
            $newStatus = 'Acknowledged';
        } elseif ($targetAgency === 'DILG' && $dilgReceivedDate) {
            $newStatus = 'Acknowledged';
        }

        $upd = $this->conn->prepare("
            UPDATE case_transmittals
            SET status = ?,
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
            $compStmt = $this->conn->prepare("
                SELECT co.complaint_id, co.complaint_number, co.complaint_title, co.narrative,
                       co.status AS complaint_status, co.case_type, co.created_at, co.updated_at,
                       cat.category_name
                FROM complaints co
                LEFT JOIN complaint_categories cat ON cat.category_id = co.category_id
                WHERE co.complaint_id = ?
            ");
            $compStmt->execute([$caseId]);
            $compRow = $compStmt->fetch(PDO::FETCH_ASSOC);
            if ($compRow) {
                $caseInfo = [
                    'case_id' => 0,
                    'case_number' => $compRow['complaint_number'],
                    'case_type' => $compRow['case_type'] ?? 'Civil',
                    'case_status' => $compRow['complaint_status'],
                    'docket_date' => substr($compRow['created_at'], 0, 10),
                    'complaint_id' => (int) $compRow['complaint_id'],
                    'complaint_number' => $compRow['complaint_number'],
                    'complaint_title' => $compRow['complaint_title'],
                    'narrative' => $compRow['narrative'],
                    'category_name' => $compRow['category_name']
                ];
            }
        }

        if (!$caseInfo) {
            throw new RuntimeException("Case #{$caseId} not found.");
        }

        // Fetch parties with addresses
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
        $complainantsWithAddress = [];
        $respondentsWithAddress = [];
        foreach ($allParties as $p) {
            $addr = !empty($p['address']) ? $p['address'] : 'Tumana, Marikina City';
            if ($p['party_type'] === 'Complainant') {
                $complainants[] = $p['full_name'];
                $complainantsWithAddress[] = $p['full_name'] . ' resident of ' . $addr;
            } else {
                $respondents[] = $p['full_name'];
                $respondentsWithAddress[] = $p['full_name'] . ' resident of ' . $addr;
            }
        }

        // Fetch settlement record
        $settleStmt = $this->conn->prepare("
            SELECT * FROM settlements WHERE case_id = ? ORDER BY settlement_id DESC LIMIT 1
        ");
        $settleStmt->execute([$caseId]);
        $settlement = $settleStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (empty($settlement) && !empty($caseInfo['complaint_id'])) {
            $pMinStmt = $this->conn->prepare("
                SELECT minutes_id, agreed_terms, dialogue_date, created_at
                FROM paanyaya_minutes
                WHERE complaint_id = ? AND outcome = 'Settled'
                ORDER BY minutes_id DESC LIMIT 1
            ");
            $pMinStmt->execute([$caseInfo['complaint_id']]);
            $pMin = $pMinStmt->fetch(PDO::FETCH_ASSOC);
            if ($pMin) {
                $settlement = [
                    'settlement_id' => (int)$pMin['minutes_id'],
                    'case_id' => 0,
                    'settlement_date' => !empty($pMin['dialogue_date']) ? substr($pMin['dialogue_date'], 0, 10) : substr($pMin['created_at'], 0, 10),
                    'agreement_details' => $pMin['agreed_terms'] ?: '',
                    'repudiation_status' => 'Within Repudiation Period',
                    'compliance_status' => 'Pending'
                ];
            }
        }

        // Determine resolution document label
        $resType = $transmittal['resolution_type'] ?? '';
        if (!$resType) {
            if (!empty($settlement)) {
                $resType = 'AMICABLE SETTLEMENT';
            } elseif ($caseInfo['case_status'] === 'CFA Issued') {
                $resType = 'CERTIFICATE TO FILE ACTION';
            } elseif ($caseInfo['case_status'] === 'Arbitration') {
                $resType = 'ARBITRATION AWARD';
            } else {
                $resType = 'AMICABLE SETTLEMENT';
            }
        }

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
            $pbName = $pbStmt->fetchColumn() ?: 'Hon. Akiko Lynne Centeno';
        }

        return [
            'transmittal' => $transmittal,
            'case' => $caseInfo,
            'complainants' => $complainants,
            'respondents' => $respondents,
            'complainants_with_address' => !empty($complainantsWithAddress) ? implode(', ', $complainantsWithAddress) : '_________________________',
            'respondents_with_address' => !empty($respondentsWithAddress) ? implode(', ', $respondentsWithAddress) : '_________________________',
            'resolution_type' => $resType,
            'settlement' => $settlement,
            'secretary_name' => $secName,
            'punong_barangay_name' => $pbName,
        ];
    }
}
