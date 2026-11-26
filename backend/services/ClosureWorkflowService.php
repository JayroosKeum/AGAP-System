<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/ComplaintLifecycleService.php';
require_once __DIR__ . '/ClosureEvidenceService.php';

/**
 * Implements A9 & A10:
 * - Distinct resolution definitions (Signed Settlement, Obligation Compliance,
 *   Administrative Closure, Dismissal, CFA Referral)
 * - Staff-only Pending Close request with reason, evidence, notice delivery, and expiry
 * - Complainant confirmation or counter-appeal/objection (via authenticated portal OR one-time token/OTP)
 * - Idempotent scheduled timeout auto-closure for uncontested requests
 */
final class ClosureWorkflowService
{
    public const DEFAULT_TIMEOUT_DAYS = 5;
    public const MAX_FAILED_VERIFICATION_ATTEMPTS = 5;
    public const VERIFICATION_LOCKOUT_MINUTES = 15;

    private PDO $conn;
    private AuditService $audit;
    private NotificationService $notifications;

    public function __construct(?PDO $conn = null, ?NotificationService $notifications = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->audit = new AuditService($this->conn);
        $this->notifications = $notifications ?? new NotificationService($this->conn);
    }

    /**
     * A9 pre-closure readiness guard: ensures a signed settlement is not conflated
     * with completed obligation compliance and checks required evidence.
     *
     * @return array{ready: bool, reason: string|null}
     */
    public function validateResolutionReadiness(int $complaintId, string $resolutionType, ?string $evidencePath = null): array
    {
        if (!array_key_exists($resolutionType, ComplaintLifecycleService::RESOLUTION_DEFINITIONS)) {
            return ['ready' => false, 'reason' => 'Unsupported resolution type.'];
        }

        $evidence = new ClosureEvidenceService($this->conn);
        $savedEvidence = trim((string) $evidencePath) !== '' ? $evidence->resolve($complaintId, $evidencePath) : null;
        if (trim((string) $evidencePath) !== '' && $savedEvidence === null) {
            return ['ready' => false, 'reason' => 'Choose a saved supporting record belonging to this complaint. The supplied reference is unavailable or belongs to another complaint.'];
        }

        if ($resolutionType === 'Signed Settlement') {
            $stmt = $this->conn->prepare(
                "SELECT s.settlement_status
                 FROM cases c
                 INNER JOIN settlements s ON s.case_id = c.case_id
                 WHERE c.complaint_id = ?
                 ORDER BY s.settlement_id DESC
                 LIMIT 1"
            );
            $stmt->execute([$complaintId]);
            $settlementStatus = (string) ($stmt->fetchColumn() ?: '');
            if ($settlementStatus !== 'Signed and Attested') {
                return ['ready' => false, 'reason' => 'Signed Settlement closure requires a Signed and Attested settlement agreement (Draft settlements are not accepted).'];
            }
        }

        if ($resolutionType === 'Obligation Compliance') {
            $stmt = $this->conn->prepare(
                'SELECT s.settlement_status, s.compliance_status, s.repudiation_status
                 FROM cases c
                 LEFT JOIN settlements s ON s.case_id = c.case_id
                 WHERE c.complaint_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$complaintId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || ($row['settlement_status'] ?? '') !== 'Signed and Attested') {
                return ['ready' => false, 'reason' => 'Obligation Compliance requires a recorded settlement with Complied or Fully Paid status.'];
            }
            if (($row['repudiation_status'] ?? '') === 'Repudiated') {
                return ['ready' => false, 'reason' => 'Repudiated settlements cannot be closed as Obligation Compliance; use CFA Referral.'];
            }
            if (!in_array((string) ($row['compliance_status'] ?? ''), ['Complied', 'Fully Paid'], true)) {
                return ['ready' => false, 'reason' => 'Obligation Compliance requires settlement compliance status Complied or Fully Paid; a signed agreement alone does not prove fulfillment.'];
            }
        }

        if ($resolutionType === 'CFA Referral') {
            $cfaStmt = $this->conn->prepare(
                'SELECT 1
                 FROM cases c
                 JOIN cfa_records cf ON cf.case_id = c.case_id
                 WHERE c.complaint_id = ?
                 LIMIT 1'
            );
            $cfaStmt->execute([$complaintId]);
            $compStmt = $this->conn->prepare('SELECT routing_route FROM complaints WHERE complaint_id = ? LIMIT 1');
            $compStmt->execute([$complaintId]);
            $route = (string) ($compStmt->fetchColumn() ?: '');
            if (!$cfaStmt->fetchColumn() && !in_array($route, ['VAWC', 'BCPC', 'PNP', 'OVER_LIMIT'], true)) {
                return ['ready' => false, 'reason' => 'CFA Referral closure requires a recorded CFA issuance or external referral route.'];
            }
        }

        if (in_array($resolutionType, ['Administrative Closure', 'Dismissal'], true)) {
            if ($savedEvidence === null) {
                return ['ready' => false, 'reason' => "{$resolutionType} requires a selected saved supporting record for this complaint."];
            }
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * Staff initiates a Pending Close request (A10).
     * Never closes immediately; moves complaint/case to Pending Close and issues
     * a one-time verification token + OTP for complainant verification or objection.
     *
     * @param array<string, mixed> $data
     */
    public function requestPendingClose(
        int $complaintId,
        int $actorUserId,
        array $data,
        ?string $internalExpiryOverride = null
    ): array {
        $reason = trim((string) ($data['request_reason'] ?? $data['reason'] ?? ''));
        $resolutionType = trim((string) ($data['resolution_type'] ?? 'Administrative Closure'));
        $evidencePath = trim((string) ($data['evidence_path'] ?? '')) ?: null;
        $timeoutDays = filter_var($data['timeout_days'] ?? self::DEFAULT_TIMEOUT_DAYS, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 60],
        ]) ?: self::DEFAULT_TIMEOUT_DAYS;

        if ($complaintId <= 0 || $actorUserId <= 0 || $reason === '') {
            return ['success' => false, 'message' => 'A valid complaint ID and closure request reason are required.'];
        }

        $readiness = $this->validateResolutionReadiness($complaintId, $resolutionType, $evidencePath);
        if (!$readiness['ready']) {
            return ['success' => false, 'message' => $readiness['reason']];
        }
        $evidencePath = $evidencePath !== null ? (new ClosureEvidenceService($this->conn))->resolve($complaintId, $evidencePath) : null;

        $rawToken = 'AGAP-CLOSE-' . bin2hex(random_bytes(20));
        $tokenHash = hash('sha256', $rawToken);
        $rawOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = hash('sha256', $rawOtp);

        $now = date('Y-m-d H:i:s');
        $expiresAt = ($internalExpiryOverride !== null && trim($internalExpiryOverride) !== '')
            ? trim($internalExpiryOverride)
            : date('Y-m-d H:i:s', strtotime("+{$timeoutDays} days"));

        $this->conn->beginTransaction();
        try {
            $compStmt = $this->conn->prepare(
                'SELECT c.complaint_id, c.status, c.submitted_by, c.intake_reference, c.complaint_number,
                        c.deleted_at, cs.case_id, cs.case_status
                 FROM complaints c
                 LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
                 WHERE c.complaint_id = ?
                 FOR UPDATE'
            );
            $compStmt->execute([$complaintId]);
            $comp = $compStmt->fetch(PDO::FETCH_ASSOC);

            if (!$comp || $comp['deleted_at'] !== null) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Complaint not found.'];
            }

            if (in_array($comp['status'], ['Closed', 'Resolution / Closed'], true) || $comp['case_status'] === 'Closed') {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'This complaint has already completed final closure.'];
            }

            $existingPending = $this->conn->prepare("SELECT * FROM closure_requests WHERE complaint_id = ? AND status = 'Pending' LIMIT 1 FOR UPDATE");
            $existingPending->execute([$complaintId]);
            $previousRequest = $existingPending->fetch(PDO::FETCH_ASSOC);
            if ($previousRequest && $previousRequest['notice_delivery_status'] === 'Unverified') {
                // Older notices have no delivery proof. Supersede them only as
                // part of a new, successfully delivered request in this transaction.
                $this->conn->prepare("UPDATE closure_requests SET status = 'Cancelled' WHERE closure_request_id = ?")
                    ->execute([(int) $previousRequest['closure_request_id']]);
                $this->audit->logStrict($actorUserId, 'Superseded Unverified Closure Notice', 'Complaints', $complaintId, [
                    'before' => ['closure_request_id' => (int) $previousRequest['closure_request_id'], 'status' => 'Pending'],
                    'after' => ['status' => 'Cancelled', 'reason' => 'Replacement notice requires verified delivery.'],
                ]);
                $comp['status'] = $previousRequest['previous_complaint_status'];
                $comp['case_status'] = $previousRequest['previous_case_status'];
            } elseif ($previousRequest || $comp['status'] === 'Pending Close') {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'A Pending Close request is already pending verification.'];
            }

            // Recheck under the complaint and supporting-record locks so a
            // concurrent evidence change cannot invalidate the saved reference.
            $readiness = $this->validateResolutionReadiness($complaintId, $resolutionType, $evidencePath);
            if (!$readiness['ready']) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => $readiness['reason']];
            }

            $caseId = !empty($comp['case_id']) ? (int) $comp['case_id'] : null;

            $ins = $this->conn->prepare(
                'INSERT INTO closure_requests
                 (complaint_id, case_id, requested_by, resolution_type, request_reason, evidence_path,
                  previous_complaint_status, previous_case_status, notice_sent_at, expires_at,
                  verification_token_hash, otp_hash, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending\')'
            );
            $ins->execute([
                $complaintId,
                $caseId,
                $actorUserId,
                $resolutionType,
                $reason,
                $evidencePath,
                $comp['status'],
                $comp['case_status'] ?? null,
                null,
                $expiresAt,
                $tokenHash,
                $otpHash,
            ]);
            $requestId = (int) $this->conn->lastInsertId();

            $this->conn->prepare("UPDATE complaints SET status = 'Pending Close', resolution_type = ? WHERE complaint_id = ?")
                ->execute([$resolutionType, $complaintId]);
            if ($caseId !== null) {
                $this->conn->prepare("UPDATE cases SET case_status = 'Pending Close' WHERE case_id = ?")
                    ->execute([$caseId]);
            }

            $this->audit->logStrict(
                $actorUserId,
                'Requested Pending Close',
                'Complaints',
                $complaintId,
                [
                    'before' => [
                        'complaint_status' => $comp['status'],
                        'case_status' => $comp['case_status'] ?? null,
                    ],
                    'after' => [
                        'closure_request_id' => $requestId,
                        'complaint_status' => 'Pending Close',
                        'case_status' => $caseId !== null ? 'Pending Close' : null,
                        'resolution_type' => $resolutionType,
                        'expires_at' => $expiresAt,
                        'request_reason' => $reason,
                    ],
                ]
            );

            $deliveryChannel = [];
            $refLabel = $comp['complaint_number'] ?: ($comp['intake_reference'] ?: ('#' . $complaintId));

            // Notify registered resident owner in portal if present
            if (!empty($comp['submitted_by'])) {
                try {
                    $portalDelivered = $this->notifications->notifyUser(
                        (int) $comp['submitted_by'],
                        'Pending Close Verification Required',
                        "Staff requested closure ({$resolutionType}) for complaint {$refLabel}. Confirm or submit an objection before {$expiresAt}.",
                        $actorUserId,
                        'warning',
                        "../resident/complaint.php?id={$complaintId}",
                        $caseId
                    );
                    if ($portalDelivered) $deliveryChannel[] = 'resident_portal';
                } catch (Throwable $ne) {
                    error_log('Pending close notification warning: ' . $ne->getMessage());
                }
            }

            // Out-of-band email delivery when complainant email + SMTP are configured
            try {
                require_once __DIR__ . '/PiiCryptoService.php';
                require_once __DIR__ . '/EmailVerificationMailer.php';
                $emailStmt = $this->conn->prepare(
                    "SELECT r.first_name, r.email
                     FROM complaint_parties cp
                     JOIN residents r ON r.resident_id = cp.resident_id
                     WHERE cp.complaint_id = ? AND cp.party_type = 'Complainant'
                     LIMIT 1"
                );
                $emailStmt->execute([$complaintId]);
                $partyRow = $emailStmt->fetch(PDO::FETCH_ASSOC);
                $recipientEmail = $partyRow ? PiiCryptoService::decryptField($partyRow['email'] ?? null) : null;
                if (is_string($recipientEmail) && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                    (new EmailVerificationMailer())->sendClosureVerification(
                        $recipientEmail,
                        (string) ($partyRow['first_name'] ?? 'Complainant'),
                        (string) $refLabel,
                        $resolutionType,
                        $rawOtp,
                        $expiresAt
                    );
                    $deliveryChannel[] = 'email_otp';
                }
            } catch (Throwable $me) {
                error_log('Pending close email delivery notice: ' . $me->getMessage());
            }

            if ($deliveryChannel === []) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'Pending Close requires at least one verified complainant delivery channel (a registered Resident Portal account or deliverable email address) before starting the verification window.',
                ];
            }

            $deliveredAt = date('Y-m-d H:i:s');
            $expiresAt = ($internalExpiryOverride !== null && PHP_SAPI === 'cli')
                ? $internalExpiryOverride
                : date('Y-m-d H:i:s', strtotime($deliveredAt . " +{$timeoutDays} days"));
            $this->conn->prepare("UPDATE closure_requests SET notice_sent_at = ?, expires_at = ?, notice_delivery_status = 'Delivered', notice_delivery_channels = ? WHERE closure_request_id = ?")
                ->execute([$deliveredAt, $expiresAt, json_encode($deliveryChannel), $requestId]);
            $this->audit->logStrict($actorUserId, 'Delivered Closure Verification Notice', 'Complaints', $complaintId, [
                'after' => ['closure_request_id' => $requestId, 'channels' => $deliveryChannel, 'notice_sent_at' => $deliveredAt, 'expires_at' => $expiresAt],
            ]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Closure request submitted. Complaint is now in Pending Close awaiting complainant confirmation or timeout.',
                'closure_request_id' => $requestId,
                'status' => 'Pending',
                'expires_at' => $expiresAt,
                'delivery_channels' => $deliveryChannel,
                'verification_token' => $rawToken,
                'verification_otp' => $rawOtp,
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Pending close request error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to create closure request.'];
        }
    }

    /**
     * Complainant confirms or objects to a Pending Close request.
     * Supports either authenticated owner ($ownerUserId matching complaints.submitted_by)
     * OR a valid one-time verification token / OTP.
     * Objections immediately block closure and restore the prior active stage.
     */
    public function respond(
        string $decision,
        ?int $complaintId = null,
        ?int $ownerUserId = null,
        ?string $token = null,
        ?string $otp = null,
        string $objectionReason = ''
    ): array {
        $action = strtolower(trim($decision));
        if (!in_array($action, ['confirm', 'object'], true)) {
            return ['success' => false, 'http_code' => 422, 'message' => 'Decision must be confirm or object.'];
        }
        $cleanObjection = trim($objectionReason);
        if ($action === 'object' && $cleanObjection === '') {
            return ['success' => false, 'http_code' => 422, 'message' => 'Provide an objection / counter-appeal reason.'];
        }

        $this->conn->beginTransaction();
        try {
            $req = null;
            if ($token !== null && trim($token) !== '') {
                $tokenHash = hash('sha256', trim($token));
                $stmt = $this->conn->prepare(
                    "SELECT cr.*, c.submitted_by, c.deleted_at
                     FROM closure_requests cr
                     JOIN complaints c ON c.complaint_id = cr.complaint_id
                     WHERE cr.verification_token_hash = ? AND cr.status = 'Pending'
                     LIMIT 1 FOR UPDATE"
                );
                $stmt->execute([$tokenHash]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
            } elseif ($complaintId !== null && $complaintId > 0) {
                $stmt = $this->conn->prepare(
                    "SELECT cr.*, c.submitted_by, c.deleted_at
                     FROM closure_requests cr
                     JOIN complaints c ON c.complaint_id = cr.complaint_id
                     WHERE cr.complaint_id = ? AND cr.status = 'Pending'
                     ORDER BY cr.closure_request_id DESC
                     LIMIT 1 FOR UPDATE"
                );
                $stmt->execute([$complaintId]);
                $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($candidate) {
                    if (!empty($candidate['locked_until']) && strtotime((string) $candidate['locked_until']) > time()) {
                        $this->conn->rollBack();
                        return ['success' => false, 'http_code' => 429, 'message' => 'Too many invalid verification attempts. Please try again later.'];
                    }
                    $authorizedByOwner = ($ownerUserId !== null && $ownerUserId > 0 && (int) ($candidate['submitted_by'] ?? 0) === $ownerUserId);
                    $otpProvided = $otp !== null && trim($otp) !== '';
                    $authorizedByOtp = ($otpProvided && !empty($candidate['otp_hash']) && hash_equals((string) $candidate['otp_hash'], hash('sha256', trim((string) $otp))));
                    if ($authorizedByOwner || $authorizedByOtp) {
                        $req = $candidate;
                    } else {
                        if ($otpProvided) {
                            $attempts = ((int) ($candidate['failed_verification_attempts'] ?? 0)) + 1;
                            $lockedUntil = $attempts >= self::MAX_FAILED_VERIFICATION_ATTEMPTS
                                ? date('Y-m-d H:i:s', time() + (self::VERIFICATION_LOCKOUT_MINUTES * 60))
                                : null;
                            $this->conn->prepare(
                                'UPDATE closure_requests SET failed_verification_attempts = ?, locked_until = ? WHERE closure_request_id = ?'
                            )->execute([$attempts, $lockedUntil, (int) $candidate['closure_request_id']]);
                            $this->conn->commit();
                            return [
                                'success' => false,
                                'http_code' => $lockedUntil !== null ? 429 : 403,
                                'message' => $lockedUntil !== null
                                    ? 'Too many invalid OTP attempts. Verification temporarily locked.'
                                    : 'Invalid verification OTP.',
                            ];
                        }
                        $this->conn->rollBack();
                        return ['success' => false, 'http_code' => 403, 'message' => 'Only the complainant owner or holder of the one-time verification token/OTP can respond.'];
                    }
                }
            }

            if (!$req || $req['deleted_at'] !== null) {
                $this->conn->rollBack();
                return ['success' => false, 'http_code' => 404, 'message' => 'No active Pending Close request was found for verification.'];
            }

            if (!empty($req['locked_until']) && strtotime((string) $req['locked_until']) > time()) {
                $this->conn->rollBack();
                return ['success' => false, 'http_code' => 429, 'message' => 'Too many invalid verification attempts. Please try again later.'];
            }

            if (!empty($req['expires_at']) && strtotime((string) $req['expires_at']) <= time()) {
                $this->conn->rollBack();
                return ['success' => false, 'http_code' => 410, 'message' => 'This Pending Close verification window has expired.'];
            }

            if ((int) ($req['failed_verification_attempts'] ?? 0) > 0 || !empty($req['locked_until'])) {
                $this->conn->prepare(
                    'UPDATE closure_requests SET failed_verification_attempts = 0, locked_until = NULL WHERE closure_request_id = ?'
                )->execute([(int) $req['closure_request_id']]);
            }

            $reqId = (int) $req['closure_request_id'];
            $targetComplaintId = (int) $req['complaint_id'];
            $targetCaseId = !empty($req['case_id']) ? (int) $req['case_id'] : null;
            $now = date('Y-m-d H:i:s');

            if ($action === 'confirm') {
                $this->conn->prepare(
                    "UPDATE closure_requests
                     SET status = 'Confirmed', confirmed_by_user_id = ?, confirmed_at = ?, closed_at = ?
                     WHERE closure_request_id = ?"
                )->execute([$ownerUserId ?: null, $now, $now, $reqId]);

                $this->conn->prepare(
                    "UPDATE complaints
                     SET status = 'Resolution / Closed', resolution_type = ?, resolution_notes = ?, resolved_at = ?
                     WHERE complaint_id = ?"
                )->execute([$req['resolution_type'], $req['request_reason'], $now, $targetComplaintId]);

                if ($targetCaseId !== null) {
                    $this->conn->prepare("UPDATE cases SET case_status = 'Closed' WHERE case_id = ?")
                        ->execute([$targetCaseId]);
                }

                $this->audit->logStrict(
                    $ownerUserId ?: null,
                    'Complainant Confirmed Closure',
                    'Complaints',
                    $targetComplaintId,
                    [
                        'before' => ['closure_request_status' => 'Pending', 'complaint_status' => 'Pending Close'],
                        'after' => [
                            'closure_request_id' => $reqId,
                            'closure_request_status' => 'Confirmed',
                            'complaint_status' => 'Resolution / Closed',
                            'resolution_type' => $req['resolution_type'],
                        ],
                    ]
                );

                $this->conn->commit();
                return [
                    'success' => true,
                    'http_code' => 200,
                    'status' => 'Confirmed',
                    'complaint_status' => 'Resolution / Closed',
                    'message' => 'Closure confirmed by complainant. Case is now officially closed.',
                ];
            }

            // Objection / counter-appeal blocks closure and restores active review stage
            $restoreComplaintStatus = !empty($req['previous_complaint_status']) && $req['previous_complaint_status'] !== 'Pending Close'
                ? (string) $req['previous_complaint_status']
                : ($targetCaseId !== null ? 'Mediation' : 'Accepted');
            $restoreCaseStatus = !empty($req['previous_case_status']) && $req['previous_case_status'] !== 'Pending Close'
                ? (string) $req['previous_case_status']
                : 'Mediation';

            $this->conn->prepare(
                "UPDATE closure_requests
                 SET status = 'Objected', objected_at = ?, objection_reason = ?, confirmed_by_user_id = ?
                 WHERE closure_request_id = ?"
            )->execute([$now, $cleanObjection, $ownerUserId ?: null, $reqId]);

            $this->conn->prepare(
                'UPDATE complaints SET status = ? WHERE complaint_id = ?'
            )->execute([$restoreComplaintStatus, $targetComplaintId]);

            if ($targetCaseId !== null) {
                $this->conn->prepare('UPDATE cases SET case_status = ? WHERE case_id = ?')
                    ->execute([$restoreCaseStatus, $targetCaseId]);
            }

            $this->audit->logStrict(
                $ownerUserId ?: null,
                'Complainant Objected to Closure (Reopened)',
                'Complaints',
                $targetComplaintId,
                [
                    'before' => ['closure_request_status' => 'Pending', 'complaint_status' => 'Pending Close'],
                    'after' => [
                        'closure_request_id' => $reqId,
                        'closure_request_status' => 'Objected',
                        'restored_complaint_status' => $restoreComplaintStatus,
                        'objection_reason' => $cleanObjection,
                    ],
                ]
            );

            try {
                (new NotificationService($this->conn))->notifyRoles(
                    ['Administrator', 'Lupon Clerk'],
                    'Closure Objection Submitted',
                    "Complainant objected to closure request #{$reqId} for complaint #{$targetComplaintId}: {$cleanObjection}",
                    $ownerUserId ?: null,
                    'warning',
                    "../complaints/complaint-details.php?id={$targetComplaintId}",
                    $targetCaseId
                );
            } catch (Throwable $ne) {
                error_log('Closure objection notification warning: ' . $ne->getMessage());
            }

            $this->conn->commit();
            return [
                'success' => true,
                'http_code' => 200,
                'status' => 'Objected',
                'complaint_status' => $restoreComplaintStatus,
                'message' => 'Closure blocked by complainant objection. Case returned to active review.',
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Closure verification response error: ' . $e->getMessage());
            return ['success' => false, 'http_code' => 500, 'message' => 'Unable to process closure response.'];
        }
    }

    /**
     * Idempotent scheduled timeout processor (A10).
     * Closes ONLY Pending requests past expires_at that have not been objected to.
     *
     * @return array{processed_count: int, closed_request_ids: array<int, int>}
     */
    public function processExpiredRequests(?string $asOfDatetime = null): array
    {
        $cutoff = $asOfDatetime ?? date('Y-m-d H:i:s');
        $closedIds = [];

        $dueStmt = $this->conn->prepare(
            "SELECT closure_request_id
             FROM closure_requests
             WHERE status = 'Pending' AND expires_at <= ? AND notice_delivery_status = 'Delivered' AND notice_sent_at IS NOT NULL
             ORDER BY closure_request_id ASC"
        );
        $dueStmt->execute([$cutoff]);
        $ids = $dueStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $rawId) {
            $reqId = (int) $rawId;
            $this->conn->beginTransaction();
            try {
                $lock = $this->conn->prepare(
                    "SELECT cr.*, c.submitted_by, c.deleted_at
                     FROM closure_requests cr
                     JOIN complaints c ON c.complaint_id = cr.complaint_id
                     WHERE cr.closure_request_id = ? AND cr.status = 'Pending' AND cr.expires_at <= ?
                       AND cr.notice_delivery_status = 'Delivered' AND cr.notice_sent_at IS NOT NULL
                     FOR UPDATE"
                );
                $lock->execute([$reqId, $cutoff]);
                $req = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$req || $req['deleted_at'] !== null) {
                    $this->conn->rollBack();
                    continue;
                }

                $now = date('Y-m-d H:i:s');
                $targetComplaintId = (int) $req['complaint_id'];
                $targetCaseId = !empty($req['case_id']) ? (int) $req['case_id'] : null;

                $this->conn->prepare(
                    "UPDATE closure_requests
                     SET status = 'TimedOutClosed', timeout_processed_at = ?, closed_at = ?
                     WHERE closure_request_id = ? AND status = 'Pending'"
                )->execute([$now, $now, $reqId]);

                $this->conn->prepare(
                    "UPDATE complaints
                     SET status = 'Resolution / Closed', resolution_type = ?, resolution_notes = ?, resolved_at = ?
                     WHERE complaint_id = ?"
                )->execute([$req['resolution_type'], $req['request_reason'], $now, $targetComplaintId]);

                if ($targetCaseId !== null) {
                    $this->conn->prepare("UPDATE cases SET case_status = 'Closed' WHERE case_id = ?")
                        ->execute([$targetCaseId]);
                }

                $this->audit->logStrict(
                    null,
                    'Auto-Closed Pending Close on Uncontested Timeout',
                    'Complaints',
                    $targetComplaintId,
                    [
                        'before' => ['closure_request_status' => 'Pending', 'complaint_status' => 'Pending Close'],
                        'after' => [
                            'closure_request_id' => $reqId,
                            'closure_request_status' => 'TimedOutClosed',
                            'complaint_status' => 'Resolution / Closed',
                            'cutoff_used' => $cutoff,
                        ],
                    ]
                );

                if (!empty($req['submitted_by'])) {
                    try {
                        (new NotificationService($this->conn))->notifyUser(
                            (int) $req['submitted_by'],
                            'Complaint Closed After Verification Window',
                            "No objection was received before {$req['expires_at']}; complaint #{$targetComplaintId} has been closed ({$req['resolution_type']}).",
                            null,
                            'info',
                            "../resident/complaint.php?id={$targetComplaintId}",
                            $targetCaseId
                        );
                    } catch (Throwable $ne) {
                        error_log('Timeout closure notification warning: ' . $ne->getMessage());
                    }
                }

                $this->conn->commit();
                $closedIds[] = $reqId;
            } catch (Throwable $e) {
                if ($this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                error_log("Timeout processor failed for closure_request {$reqId}: " . $e->getMessage());
            }
        }

        return [
            'processed_count' => count($closedIds),
            'closed_request_ids' => $closedIds,
        ];
    }

    public function getHistoryForComplaint(int $complaintId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT closure_request_id, complaint_id, case_id, requested_by, resolution_type, request_reason,
                    evidence_path, notice_sent_at, notice_delivery_status, notice_delivery_channels, expires_at, status, confirmed_at, objected_at, objection_reason,
                    closed_at, timeout_processed_at, created_at
             FROM closure_requests
             WHERE complaint_id = ?
             ORDER BY closure_request_id DESC'
        );
        $stmt->execute([$complaintId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
