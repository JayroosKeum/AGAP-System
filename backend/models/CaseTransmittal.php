<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Setting.php';
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
     * Retrieves the active (non-superseded, non-cancelled) transmittal record for a case.
     */
    public function getByCaseId(int $caseId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT t.*,
                   gd.file_path AS source_document_path,
                   dt.template_name AS source_document_code,
                   TRIM(CONCAT_WS(' ', u_trans.first_name, u_trans.middle_name, u_trans.last_name)) AS transmitting_officer_name,
                   TRIM(CONCAT_WS(' ', u_att.first_name, u_att.middle_name, u_att.last_name)) AS attesting_officer_name
            FROM case_transmittals t
            LEFT JOIN generated_documents gd ON gd.document_id = t.source_document_id
            LEFT JOIN document_templates dt ON dt.template_id = gd.template_id
            LEFT JOIN users u_trans ON u_trans.user_id = t.transmitting_officer_id
            LEFT JOIN users u_att ON u_att.user_id = t.attesting_officer_id
            WHERE t.case_id = ?
              AND t.superseded_at IS NULL
              AND t.status <> 'Cancelled'
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
     * Resolves the current operative eligible instrument and saved PDF document for court transmittal.
     * Priority: Issued CFA with PDF > Signed & Attested Settlement with PDF > Finalized Arbitration Award with PDF.
     */
    public function resolveOperativeSource(int $caseId): ?array
    {
        // 1. Issued CFA with saved KP Form 20* PDF
        $cfaStmt = $this->conn->prepare("
            SELECT c.cfa_id, c.cfa_type, c.issuance_date, c.created_at,
                   gd.document_id, gd.file_path, dt.template_name AS form_code
            FROM cfa_records c
            LEFT JOIN (
                generated_documents gd
                INNER JOIN document_templates dt ON dt.template_id = gd.template_id
            ) ON gd.case_id = c.case_id
             AND dt.template_name = c.cfa_type
            WHERE c.case_id = ?
            ORDER BY c.cfa_id DESC, gd.document_id DESC
            LIMIT 1
        ");
        $cfaStmt->execute([$caseId]);
        $cfa = $cfaStmt->fetch(PDO::FETCH_ASSOC);
        if ($cfa && !empty($cfa['document_id']) && !empty($cfa['file_path'])) {
            $issueDate = !empty($cfa['issuance_date'])
                ? substr((string) $cfa['issuance_date'], 0, 10)
                : substr((string) $cfa['created_at'], 0, 10);
            return [
                'source_type' => 'CFA',
                'source_record_id' => (int) $cfa['cfa_id'],
                'source_document_id' => (int) $cfa['document_id'],
                'source_document_path' => (string) $cfa['file_path'],
                'source_document_code' => (string) $cfa['form_code'],
                'resolution_type' => 'CERTIFICATE TO FILE ACTION',
                'settlement_date' => $issueDate,
                'ten_day_expiry_date' => $issueDate,
            ];
        }

        // 2. Signed & Attested Amicable Settlement (not repudiated) with saved KP Form 16 PDF
        $settleStmt = $this->conn->prepare("
            SELECT s.settlement_id, s.settlement_date, s.repudiation_deadline,
                   s.settlement_status, s.repudiation_status, s.repudiation_date,
                   s.complainant_signed, s.respondent_signed, s.pb_attested,
                   s.document_id, gd.file_path, dt.template_name AS form_code
            FROM settlements s
            LEFT JOIN generated_documents gd ON gd.document_id = s.document_id
            LEFT JOIN document_templates dt ON dt.template_id = gd.template_id
            WHERE s.case_id = ?
            ORDER BY s.settlement_id DESC
            LIMIT 1
        ");
        $settleStmt->execute([$caseId]);
        $settle = $settleStmt->fetch(PDO::FETCH_ASSOC);
        if (
            $settle
            && ($settle['settlement_status'] ?? '') === 'Signed and Attested'
            && !empty($settle['complainant_signed'])
            && !empty($settle['respondent_signed'])
            && !empty($settle['pb_attested'])
            && ($settle['repudiation_status'] ?? 'None') !== 'Repudiated'
            && empty($settle['repudiation_date'])
            && !empty($settle['document_id'])
            && !empty($settle['file_path'])
        ) {
            $sDate = substr((string) $settle['settlement_date'], 0, 10);
            $expiry = !empty($settle['repudiation_deadline'])
                ? substr((string) $settle['repudiation_deadline'], 0, 10)
                : MediationDeadlineService::calculateBusinessDays($sDate, 10);
            return [
                'source_type' => 'Settlement',
                'source_record_id' => (int) $settle['settlement_id'],
                'source_document_id' => (int) $settle['document_id'],
                'source_document_path' => (string) $settle['file_path'],
                'source_document_code' => (string) ($settle['form_code'] ?: 'KP Form 16'),
                'resolution_type' => 'AMICABLE SETTLEMENT',
                'settlement_date' => $sDate,
                'ten_day_expiry_date' => $expiry,
            ];
        }

        // 3. Finalized Arbitration Award (agreement not repudiated) with saved KP Form 15 PDF
        $arbStmt = $this->conn->prepare("
            SELECT ar.arbitration_id, ar.award_date, ar.award_status, ar.agreement_repudiation_status,
                   ar.award_document_id, gd.file_path, dt.template_name AS form_code
            FROM arbitration_records ar
            LEFT JOIN generated_documents gd ON gd.document_id = ar.award_document_id
            LEFT JOIN document_templates dt ON dt.template_id = gd.template_id
            WHERE ar.case_id = ?
            ORDER BY ar.arbitration_id DESC
            LIMIT 1
        ");
        $arbStmt->execute([$caseId]);
        $arb = $arbStmt->fetch(PDO::FETCH_ASSOC);
        if (
            $arb
            && ($arb['award_status'] ?? '') === 'Finalized'
            && ($arb['agreement_repudiation_status'] ?? 'None') !== 'Repudiated'
            && !empty($arb['award_date'])
            && !empty($arb['award_document_id'])
            && !empty($arb['file_path'])
        ) {
            $aDate = substr((string) $arb['award_date'], 0, 10);
            return [
                'source_type' => 'Arbitration Award',
                'source_record_id' => (int) $arb['arbitration_id'],
                'source_document_id' => (int) $arb['award_document_id'],
                'source_document_path' => (string) $arb['file_path'],
                'source_document_code' => (string) ($arb['form_code'] ?: 'KP Form 15'),
                'resolution_type' => 'ARBITRATION AWARD',
                'settlement_date' => $aDate,
                'ten_day_expiry_date' => MediationDeadlineService::calculateBusinessDays($aDate, 10),
            ];
        }

        return null;
    }

    /**
     * Creates or returns existing transmittal for an eligible resolved case.
     */
    public function createOrGetTransmittal(int $caseId, ?int $officerId = null): ?array
    {
        $caseStmt = $this->conn->prepare("SELECT case_id, case_status FROM cases WHERE case_id = ?");
        $caseStmt->execute([$caseId]);
        $c = $caseStmt->fetch(PDO::FETCH_ASSOC);

        $source = $c ? $this->resolveOperativeSource($caseId) : null;
        $existing = $c ? $this->getByCaseId($caseId) : null;

        if ($existing) {
            $sameSource = $source
                && ($existing['source_type'] ?? null) === $source['source_type']
                && (int) ($existing['source_record_id'] ?? 0) === (int) $source['source_record_id'];
            if ($sameSource || !$source) {
                return $existing;
            }
            // Supersede stale packet when a newer operative instrument (e.g., CFA after settlement breach) takes over
            $this->conn->prepare("
                UPDATE case_transmittals
                SET superseded_at = NOW(),
                    status = CASE WHEN status = 'Pending' THEN 'Cancelled' ELSE status END,
                    updated_at = NOW()
                WHERE transmittal_id = ?
            ")->execute([(int) $existing['transmittal_id']]);
        }

        if ($c && !$source) {
            return null;
        }

        if (!$c) {
            // Paanyaya complaint-only preview (no case_transmittals row created)
            $compStmt = $this->conn->prepare("SELECT complaint_id, complaint_number, status, updated_at, created_at FROM complaints WHERE complaint_id = ?");
            $compStmt->execute([$caseId]);
            $comp = $compStmt->fetch(PDO::FETCH_ASSOC);
            if (!$comp || !in_array($comp['status'], ['Settled', 'Resolved at Paanyaya / Closed'], true)) {
                return null;
            }
            $pMinStmt = $this->conn->prepare("SELECT dialogue_date, created_at FROM paanyaya_minutes WHERE complaint_id = ? AND outcome = 'Settled' ORDER BY minutes_id DESC LIMIT 1");
            $pMinStmt->execute([$caseId]);
            $pMin = $pMinStmt->fetch(PDO::FETCH_ASSOC);
            $settlementDate = !empty($pMin['dialogue_date'])
                ? substr($pMin['dialogue_date'], 0, 10)
                : (!empty($pMin['created_at']) ? substr($pMin['created_at'], 0, 10) : substr($comp['updated_at'], 0, 10));
            $source = [
                'source_type' => 'Settlement',
                'source_record_id' => 0,
                'source_document_id' => 0,
                'source_document_path' => '',
                'source_document_code' => 'KP Form 16',
                'resolution_type' => 'AMICABLE SETTLEMENT',
                'settlement_date' => $settlementDate,
                'ten_day_expiry_date' => MediationDeadlineService::calculateBusinessDays($settlementDate, 10),
            ];
        }

        $resolutionType = $source['resolution_type'];
        $settlementDate = $source['settlement_date'];
        $expiryDate = $source['ten_day_expiry_date'];

        // Generate unique transmittal reference number (e.g. TR-2026-0001)
        $year = date('Y');
        $numStmt = $this->conn->prepare("SELECT COUNT(*) FROM case_transmittals WHERE YEAR(created_at) = ?");
        $numStmt->execute([$year]);
        $seq = ((int) $numStmt->fetchColumn()) + 1;
        $transmittalNumber = sprintf('TR-%s-%04d', $year, $seq);

        $checkStmt = $this->conn->prepare("SELECT 1 FROM case_transmittals WHERE transmittal_number = ?");
        $checkStmt->execute([$transmittalNumber]);
        if ($checkStmt->fetchColumn()) {
            $transmittalNumber = sprintf('TR-%s-%04d-%d', $year, $seq, time() % 1000);
        }

        // Default officers (Secretary & Punong Barangay)
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
                'source_type' => $source['source_type'],
                'source_record_id' => $source['source_record_id'],
                'source_document_id' => $source['source_document_id'],
                'source_document_path' => $source['source_document_path'],
                'source_document_code' => $source['source_document_code'],
                'ten_day_expiry_date' => $expiryDate,
                'target_agency' => 'Both',
                'transmitting_officer_id' => $defaultSecId,
                'attesting_officer_id' => $defaultPbId,
                'status' => 'Pending',
                'transmittal_notified' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'transmitting_officer_name' => Setting::getBarangayProfile($this->conn)['lupon_secretary_name'],
                'attesting_officer_name' => Setting::getBarangayProfile($this->conn)['captain_name']
            ];
            return $this->enrichTransmittalData($row);
        }

        $ins = $this->conn->prepare("
            INSERT INTO case_transmittals (
                case_id, transmittal_number, settlement_date, resolution_type,
                source_type, source_record_id, source_document_id, ten_day_expiry_date,
                target_agency, transmitting_officer_id, attesting_officer_id, status, transmittal_notified, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Both', ?, ?, 'Pending', 0, NOW())
        ");
        $ins->execute([
            $caseId,
            $transmittalNumber,
            $settlementDate,
            $resolutionType,
            $source['source_type'],
            $source['source_record_id'],
            $source['source_document_id'],
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
        $sourceType = (string) ($row['source_type'] ?? '');
        if ($sourceType === '') {
            $sourceType = match ($row['resolution_type'] ?? '') {
                'CERTIFICATE TO FILE ACTION' => 'CFA',
                'ARBITRATION AWARD' => 'Arbitration Award',
                default => 'Settlement',
            };
            $row['source_type'] = $sourceType;
        }

        $isCfaPacket = ($sourceType === 'CFA');
        $hasSourceDocument = ($caseId === 0 && !empty($row['complaint_id']))
            || (!empty($row['source_document_id']) && !empty($row['source_document_path']));
        $row['has_source_document'] = $hasSourceDocument;

        if ($caseId > 0 && $sourceType === 'Settlement') {
            $settleCheck = $this->conn->prepare("
                SELECT settlement_status, complainant_signed, respondent_signed, pb_attested,
                       repudiation_status, repudiation_reason, repudiation_date
                FROM settlements
                WHERE case_id = ?
                ORDER BY settlement_id DESC
                LIMIT 1
            ");
            $settleCheck->execute([$caseId]);
            $settleData = $settleCheck->fetch(PDO::FETCH_ASSOC);

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

            if (
                !$settleData
                || ($settleData['settlement_status'] ?? '') !== 'Signed and Attested'
                || empty($settleData['complainant_signed'])
                || empty($settleData['respondent_signed'])
                || empty($settleData['pb_attested'])
            ) {
                $row['is_locked'] = true;
                $row['is_repudiated'] = false;
                $row['is_ready_for_transmittal'] = false;
                $row['working_days_remaining'] = 0;
                $row['badge_class'] = 'badge-amber';
                $row['badge_label'] = '⚠️ Signed & Attested Settlement Required';
                return $row;
            }
        } elseif ($caseId > 0 && $sourceType === 'Arbitration Award') {
            $arbCheck = $this->conn->prepare("
                SELECT award_status, agreement_repudiation_status, agreement_repudiation_reason, agreement_repudiated_at
                FROM arbitration_records
                WHERE case_id = ?
                ORDER BY arbitration_id DESC
                LIMIT 1
            ");
            $arbCheck->execute([$caseId]);
            $arbData = $arbCheck->fetch(PDO::FETCH_ASSOC);

            if ($arbData && ($arbData['agreement_repudiation_status'] ?? 'None') === 'Repudiated') {
                $row['is_locked'] = true;
                $row['is_repudiated'] = true;
                $row['is_ready_for_transmittal'] = false;
                $row['working_days_remaining'] = 0;
                $row['badge_class'] = 'badge-red';
                $row['badge_label'] = '⚠️ Arbitration Agreement Repudiated — Transmittal Cancelled';
                $row['repudiation_reason'] = $arbData['agreement_repudiation_reason'];
                $row['repudiation_date'] = $arbData['agreement_repudiated_at'];
                return $row;
            }

            if (!$arbData || ($arbData['award_status'] ?? '') !== 'Finalized') {
                $row['is_locked'] = true;
                $row['is_repudiated'] = false;
                $row['is_ready_for_transmittal'] = false;
                $row['working_days_remaining'] = 0;
                $row['badge_class'] = 'badge-amber';
                $row['badge_label'] = '⚠️ Finalized Arbitration Award Required';
                return $row;
            }
        }

        $row['is_repudiated'] = false;
        $settlementDate = $row['settlement_date'];
        $expiryDate = $row['ten_day_expiry_date'];
        $daysRemaining = $isCfaPacket ? 0 : $this->calculateWorkingDaysRemaining($expiryDate);
        $isWindowOpen = (!$isCfaPacket && $daysRemaining > 0);
        $isReady = (!$isWindowOpen && $hasSourceDocument && !in_array($row['status'], ['Transmitted', 'Acknowledged', 'Cancelled'], true));

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
        } elseif (!$hasSourceDocument) {
            $badgeClass = 'badge-red';
            $badgeLabel = '⚠️ Missing Certified Instrument Document';
        } elseif ($isWindowOpen) {
            $badgeClass = 'badge-amber';
            $badgeLabel = sprintf('🔒 Locked (%d working day%s remaining - Repudiation Window)', $daysRemaining, $daysRemaining === 1 ? '' : 's');
        } else {
            $badgeClass = 'badge-green';
            $badgeLabel = '🔓 Ready for MTC / DILG Transmittal';
        }

        // Automatic in-app notification when the 10-working-day repudiation period elapses (Settlement / Arbitration Award only; CFA packets have no repudiation countdown)
        if (!$isCfaPacket && $isReady && $caseId > 0 && (int) ($row['transmittal_id'] ?? 0) > 0 && empty($row['transmittal_notified'])) {
            $this->dispatchTransmittalNotification((int)$row['transmittal_id'], (int)$row['case_id']);
            $row['transmittal_notified'] = 1;
        }

        $row['working_days_remaining'] = $daysRemaining;
        $row['is_locked'] = ($isWindowOpen || !$hasSourceDocument);
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
        $nested = $this->conn->inTransaction();
        try {
            if (!$nested) {
                $this->conn->beginTransaction();
            }

            $transmittal = $this->createOrGetTransmittal($caseId, $userId);
            if (!$transmittal || empty($transmittal['transmittal_id'])) {
                if (!$nested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'No eligible signed settlement, finalized award, or issued CFA exists for court transmittal.'];
            }

            $lockStmt = $this->conn->prepare("
                SELECT t.*, gd.file_path AS source_document_path, dt.template_name AS source_document_code
                FROM case_transmittals t
                LEFT JOIN generated_documents gd ON gd.document_id = t.source_document_id
                LEFT JOIN document_templates dt ON dt.template_id = gd.template_id
                WHERE t.transmittal_id = ?
                  AND t.superseded_at IS NULL
                  AND t.status <> 'Cancelled'
                LIMIT 1
                FOR UPDATE
            ");
            $lockStmt->execute([(int) $transmittal['transmittal_id']]);
            $lockedRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedRow) {
                if (!$nested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'Court transmittal packet is no longer active. Please reload and try again.'];
            }

            $source = $this->resolveOperativeSource($caseId);
            $enriched = $this->enrichTransmittalData($lockedRow);
            if (
                !$source
                || ($lockedRow['source_type'] ?? null) !== $source['source_type']
                || (int) ($lockedRow['source_record_id'] ?? 0) !== (int) $source['source_record_id']
            ) {
                if (!$nested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'The underlying resolution record has changed. Refresh the court packet before submitting.'];
            }
            if (!empty($enriched['is_repudiated'])) {
                if (!$nested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'Cannot submit a court packet for a repudiated instrument.'];
            }
            if (empty($enriched['has_source_document'])) {
                if (!$nested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'Cannot submit a court packet without a saved certified instrument PDF document.'];
            }
            if (!empty($enriched['is_locked'])) {
                if (!$nested && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'message' => 'Court transmittal is still locked during the 10-working-day finality/repudiation window.'];
            }

            $transmittalDate = !empty($data['transmittal_date']) ? trim((string) $data['transmittal_date']) : date('Y-m-d');
            $targetAgency = in_array(($data['target_agency'] ?? 'Both'), ['MTC', 'DILG', 'Both'], true) ? $data['target_agency'] : 'Both';
            $mtcReceivedDate = !empty($data['mtc_received_date']) ? trim((string) $data['mtc_received_date']) : null;
            $mtcReceiverName = !empty($data['mtc_receiver_name']) ? trim((string) $data['mtc_receiver_name']) : null;
            $dilgReceivedDate = !empty($data['dilg_received_date']) ? trim((string) $data['dilg_received_date']) : null;
            $dilgReceiverName = !empty($data['dilg_receiver_name']) ? trim((string) $data['dilg_receiver_name']) : null;
            $remarks = !empty($data['remarks']) ? trim((string) $data['remarks']) : null;

            // Idempotent repeat submission guard when already Transmitted/Acknowledged with no new acknowledgement data
            $hasNewAck = ($mtcReceivedDate !== null || $dilgReceivedDate !== null);
            if (in_array($lockedRow['status'], ['Transmitted', 'Acknowledged'], true) && !$hasNewAck) {
                if (!$nested && $this->conn->inTransaction()) {
                    $this->conn->commit();
                }
                return [
                    'success' => true,
                    'already_submitted' => true,
                    'message' => 'Court & DILG transmittal has already been recorded for this packet.',
                    'transmittal' => $enriched
                ];
            }

            // Process file upload if provided
            $receivingCopyPath = null;
            if ($file && !empty($file['name']) && ($file['error'] === UPLOAD_ERR_OK)) {
                $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExtensions, true)) {
                    if (!$nested && $this->conn->inTransaction()) {
                        $this->conn->rollBack();
                    }
                    return ['success' => false, 'message' => 'Invalid receiving copy file type. Allowed: PDF, JPG, PNG, WEBP.'];
                }

                if ($file['size'] > (15 * 1024 * 1024)) {
                    if (!$nested && $this->conn->inTransaction()) {
                        $this->conn->rollBack();
                    }
                    return ['success' => false, 'message' => 'File size exceeds maximum allowed limit (15MB).'];
                }

                $uploadDir = __DIR__ . '/../../uploads/transmittals/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    if (!$nested && $this->conn->inTransaction()) {
                        $this->conn->rollBack();
                    }
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
                WHERE transmittal_id = ?
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
                (int) $lockedRow['transmittal_id']
            ]);

            if (!$nested) {
                $this->conn->commit();
            }
        } catch (Throwable $e) {
            if (!$nested && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Transmittal submission failed: ' . $e->getMessage()];
        }

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

        require_once __DIR__ . '/../services/PiiCryptoService.php';
        $complainants = [];
        $respondents = [];
        $complainantsWithAddress = [];
        $respondentsWithAddress = [];
        foreach ($allParties as $p) {
            $decAddr = PiiCryptoService::decryptField($p['address'] ?? null);
            $addr = !empty($decAddr) ? $decAddr : 'Address not provided';
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
            $pbName = $pbStmt->fetchColumn() ?: Setting::getBarangayProfile($this->conn)['captain_name'];
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
