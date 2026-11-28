<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/ValidationService.php';

/**
 * Step 5 (Formal KP Form No. 7 Filing Fee) tracking & gatekeeper service.
 * Records official barangay receipt payment or approved indigency exemption prior
 * to formal case numbering / KP Form No. 7 docketing.
 */
final class FilingFeeService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? (new Database())->connect();
    }

    public function getDefaultFeeAmount(): float
    {
        $raw = (string) Setting::getSetting('formal_kp_filing_fee_amount', '100.00', $this->db);
        $val = is_numeric($raw) ? (float) $raw : 100.00;
        return max(0.0, round($val, 2));
    }

    public function isStrictApiEnforcementEnabled(): bool
    {
        return (string) Setting::getSetting('require_filing_fee_before_docket', '0', $this->db) === '1';
    }

    public function getStatus(int $complaintId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.filing_fee_status, c.filing_fee_amount, c.filing_fee_or_number,
                    c.filing_fee_paid_at, c.filing_fee_waiver_reason,
                    TRIM(CONCAT_WS(\' \', u.first_name, u.last_name)) AS recorded_by_name
             FROM complaints c
             LEFT JOIN users u ON u.user_id = c.filing_fee_recorded_by
             WHERE c.complaint_id = ? LIMIT 1'
        );
        $stmt->execute([$complaintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'filing_fee_status' => (string) ($row['filing_fee_status'] ?? 'Unpaid'),
            'filing_fee_amount' => isset($row['filing_fee_amount']) ? (float) $row['filing_fee_amount'] : null,
            'filing_fee_or_number' => $row['filing_fee_or_number'] ?? null,
            'filing_fee_paid_at' => $row['filing_fee_paid_at'] ?? null,
            'filing_fee_waiver_reason' => $row['filing_fee_waiver_reason'] ?? null,
            'recorded_by_name' => $row['recorded_by_name'] ?? null,
            'default_fee_amount' => $this->getDefaultFeeAmount(),
            'strict_api_enforcement' => $this->isStrictApiEnforcementEnabled(),
        ];
    }

    /**
     * Records payment (Official Receipt) or Indigency/Exemption on a complaint.
     *
     * @param array<string, mixed> $data
     */
    public function record(int $complaintId, array $data, int $actorUserId): array
    {
        if ($complaintId <= 0 || $actorUserId <= 0) {
            return ['success' => false, 'message' => 'A valid complaint and authorized staff account are required.'];
        }

        $rawStatus = trim((string) ($data['filing_fee_status'] ?? ''));
        $orNumber = trim((string) ($data['filing_fee_or_number'] ?? $data['or_number'] ?? ''));
        $waiverReason = trim((string) ($data['filing_fee_waiver_reason'] ?? $data['waiver_reason'] ?? ''));
        if ($rawStatus === '' && $orNumber !== '') {
            $rawStatus = 'Paid';
        } elseif ($rawStatus === '' && $waiverReason !== '') {
            $rawStatus = 'Exempt / Indigent';
        }

        if (!in_array($rawStatus, ['Paid', 'Exempt / Indigent'], true)) {
            return ['success' => false, 'message' => 'Select Paid or Exempt / Indigent for the official KP filing fee.'];
        }

        if ($rawStatus === 'Paid') {
            if ($orNumber === '' || mb_strlen($orNumber) > 64 || !ValidationService::text($orNumber)) {
                return ['success' => false, 'message' => 'Enter the Official Receipt (OR) Number for the formal KP filing fee.'];
            }
            $rawAmount = $data['filing_fee_amount'] ?? $data['amount'] ?? null;
            $amount = ($rawAmount !== null && $rawAmount !== '' && is_numeric($rawAmount))
                ? round((float) $rawAmount, 2)
                : $this->getDefaultFeeAmount();
            if ($amount < 0 || $amount > 100000) {
                return ['success' => false, 'message' => 'Enter a valid filing fee amount.'];
            }
            $waiverReason = null;
        } else {
            if ($waiverReason === '' || mb_strlen($waiverReason) > 255 || !ValidationService::text($waiverReason)) {
                return ['success' => false, 'message' => 'Specify the indigency / fee exemption basis (up to 255 characters).'];
            }
            $amount = 0.00;
            $orNumber = null;
        }

        $ownsTx = !$this->db->inTransaction();
        if ($ownsTx) {
            $this->db->beginTransaction();
        }
        try {
            $check = $this->db->prepare('SELECT complaint_id FROM complaints WHERE complaint_id = ? AND deleted_at IS NULL FOR UPDATE');
            $check->execute([$complaintId]);
            if (!$check->fetchColumn()) {
                throw new DomainException('Complaint record not found.');
            }

            $this->db->prepare(
                'UPDATE complaints
                 SET filing_fee_status = ?,
                     filing_fee_amount = ?,
                     filing_fee_or_number = ?,
                     filing_fee_paid_at = NOW(),
                     filing_fee_recorded_by = ?,
                     filing_fee_waiver_reason = ?
                 WHERE complaint_id = ?'
            )->execute([
                $rawStatus,
                $amount,
                $orNumber,
                $actorUserId,
                $waiverReason,
                $complaintId,
            ]);

            (new AuditService($this->db))->logStrict(
                $actorUserId,
                'Recorded KP Form 7 Filing Fee (' . $rawStatus . ')',
                'Complaints',
                $complaintId,
                [
                    'after' => [
                        'filing_fee_status' => $rawStatus,
                        'filing_fee_amount' => $amount,
                        'filing_fee_or_number' => $orNumber,
                        'filing_fee_waiver_reason' => $waiverReason,
                    ],
                ]
            );

            if ($ownsTx) {
                $this->db->commit();
            }

            return [
                'success' => true,
                'message' => $rawStatus === 'Paid'
                    ? "Official filing fee recorded (OR #{$orNumber}). Ready for formal KP Form No. 7 docketing."
                    : 'Filing fee exemption recorded. Ready for formal KP Form No. 7 docketing.',
                'filing_fee' => $this->getStatus($complaintId),
            ];
        } catch (Throwable $e) {
            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof DomainException) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
            error_log('FilingFeeService::record error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to record the filing fee right now.'];
        }
    }

    /**
     * Validates a formal docketing request payload against filing fee policy
     * before creating a complaint/case record.
     *
     * @param array<string, mixed> $input
     * @throws DomainException
     */
    public function assertPayloadSatisfiesPolicy(array $input): void
    {
        $rawStatus = trim((string) ($input['filing_fee_status'] ?? ''));
        $orNumber = trim((string) ($input['filing_fee_or_number'] ?? $input['or_number'] ?? ''));
        $waiverReason = trim((string) ($input['filing_fee_waiver_reason'] ?? $input['waiver_reason'] ?? ''));
        $hasExplicitFee = $rawStatus === 'Paid' || $rawStatus === 'Exempt / Indigent' || $orNumber !== '' || $waiverReason !== '';

        if ($hasExplicitFee) {
            $effectiveStatus = $rawStatus !== '' ? $rawStatus : ($orNumber !== '' ? 'Paid' : 'Exempt / Indigent');
            if ($effectiveStatus === 'Paid') {
                if ($orNumber === '' || mb_strlen($orNumber) > 64 || !ValidationService::text($orNumber)) {
                    throw new DomainException('Enter the Official Receipt (OR) Number for the formal KP filing fee.');
                }
                $rawAmount = $input['filing_fee_amount'] ?? $input['amount'] ?? null;
                $amount = ($rawAmount !== null && $rawAmount !== '' && is_numeric($rawAmount))
                    ? round((float) $rawAmount, 2)
                    : $this->getDefaultFeeAmount();
                if ($amount < 0 || $amount > 100000) {
                    throw new DomainException('Enter a valid filing fee amount.');
                }
                return;
            }
            if ($effectiveStatus === 'Exempt / Indigent') {
                if ($waiverReason === '' || mb_strlen($waiverReason) > 255 || !ValidationService::text($waiverReason)) {
                    throw new DomainException('Specify the indigency / fee exemption basis (up to 255 characters).');
                }
                return;
            }
            throw new DomainException('Select Paid or Exempt / Indigent for the official KP filing fee.');
        }

        if (!empty($input['enforce_filing_fee']) || $this->isStrictApiEnforcementEnabled()) {
            throw new DomainException('Official filing fee payment (OR Number) or approved indigency exemption is required before formal KP Form No. 7 docketing.');
        }
    }

    /**
     * Records inline filing fee fields if provided, and enforces fee completion
     * when either the caller passes `enforce_filing_fee` or strict policy is enabled.
     *
     * @param array<string, mixed> $input
     * @throws DomainException
     */
    public function ensureReadyForDocketing(int $complaintId, array $input, int $actorUserId): void
    {
        $hasInlineFee = trim((string) ($input['filing_fee_or_number'] ?? $input['or_number'] ?? '')) !== ''
            || trim((string) ($input['filing_fee_waiver_reason'] ?? $input['waiver_reason'] ?? '')) !== ''
            || in_array((string) ($input['filing_fee_status'] ?? ''), ['Paid', 'Exempt / Indigent'], true);

        if ($hasInlineFee) {
            $rec = $this->record($complaintId, $input, $actorUserId);
            if (empty($rec['success'])) {
                throw new DomainException((string) ($rec['message'] ?? 'Invalid official filing fee details.'));
            }
        }

        $mustEnforce = !empty($input['enforce_filing_fee']) || $this->isStrictApiEnforcementEnabled();
        if (!$mustEnforce) {
            return;
        }

        $stmt = $this->db->prepare('SELECT filing_fee_status FROM complaints WHERE complaint_id = ? LIMIT 1');
        $stmt->execute([$complaintId]);
        $status = (string) ($stmt->fetchColumn() ?: 'Unpaid');
        if (!in_array($status, ['Paid', 'Exempt / Indigent'], true)) {
            throw new DomainException('Official filing fee payment (OR Number) or approved indigency exemption is required before formal KP Form No. 7 docketing.');
        }
    }
}
