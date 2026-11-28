<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/ComplaintInformationService.php';
require_once __DIR__ . '/ComplaintRoutingService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/PiiCryptoService.php';
require_once __DIR__ . '/ValidationService.php';

/**
 * Step 1 (BPSO Intake & Desk Validation) service.
 * Handles online complaint validation before LISO screening, walk-in blotter
 * logging, respondent Barangay Tumana residency confirmation, and formal rejection.
 */
final class BpsoIntakeService
{
    public const MAX_NOTES_LENGTH = 2000;
    public const MAX_BLOTTER_LENGTH = 64;

    private PDO $db;
    private AuditService $audit;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? (new Database())->connect();
        $this->audit = new AuditService($this->db);
    }

    public static function generateBlotterNumber(int $complaintId): string
    {
        return sprintf('BLT-%s-%06d', date('Y'), max(1, $complaintId));
    }

    /**
     * Heuristic helper for pre-populating the BPSO Tumana residency check from respondent address fields.
     */
    public static function looksLikeTumanaAddress(?string $barangay, ?string $purok, ?string $address, int $isTenant = 0): bool
    {
        if ($isTenant === 2 || strcasecmp(trim((string) $purok), 'Non-Resident') === 0) {
            return false;
        }
        $combined = strtolower(trim(implode(' ', array_filter([$barangay, $purok, $address]))));
        if ($combined === '') {
            return false;
        }
        if (str_contains($combined, 'non-resident') || str_contains($combined, 'outside tumana')) {
            return false;
        }
        return str_contains($combined, 'tumana') || preg_match('/\bpurok\b/i', (string) $purok) === 1;
    }

    /**
     * Processes a BPSO desk decision on a pending online submission:
     * - endorse: validates identity, details, and respondent Tumana residency; assigns blotter no; forwards to LISO.
     * - request_info: sends a clarification request to the online resident portal.
     * - reject: rejects incomplete or non-Tumana filings with a reason + walk-in desk guidance.
     *
     * @param array<string, mixed> $payload
     */
    public function reviewOnlineIntake(int $complaintId, string $action, array $payload, int $actorUserId): array
    {
        if ($complaintId <= 0 || $actorUserId <= 0) {
            return ['success' => false, 'message' => 'A valid complaint and authorized BPSO/staff account are required.'];
        }

        $normalizedAction = strtolower(trim($action));
        if (!in_array($normalizedAction, ['endorse', 'request_info', 'reject'], true)) {
            return ['success' => false, 'message' => 'Select a valid BPSO validation action (endorse, request_info, or reject).'];
        }

        $privateNotes = trim((string) ($payload['private_notes'] ?? $payload['bpso_private_notes'] ?? ''));
        if ($privateNotes !== '' && (mb_strlen($privateNotes) > self::MAX_NOTES_LENGTH || !ValidationService::text($privateNotes, true))) {
            return ['success' => false, 'message' => 'Private BPSO remarks must be 2,000 characters or fewer.'];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT c.complaint_id, c.intake_source, c.intake_reference, c.complaint_title,
                        c.bpso_status, c.bpso_blotter_no, c.status, c.screening_decision,
                        c.submitted_by, c.is_draft, c.routing_route, c.assigned_queue,
                        cs.case_id
                 FROM complaints c
                 LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
                 WHERE c.complaint_id = ? AND c.deleted_at IS NULL
                 FOR UPDATE"
            );
            $stmt->execute([$complaintId]);
            $complaint = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$complaint || !empty($complaint['is_draft'])) {
                throw new DomainException('Complaint record was not found.');
            }
            if (!empty($complaint['case_id'])) {
                throw new DomainException('This complaint is already formally docketed.');
            }
            if (($complaint['bpso_status'] ?? '') === 'Endorsed to LISO') {
                throw new DomainException('This complaint has already been logged in the blotter and endorsed to LISO.');
            }
            if (!in_array((string) ($complaint['bpso_status'] ?? ''), ['Pending BPSO Review', 'Needs Clarification', 'Rejected'], true)) {
                throw new DomainException('This complaint is not awaiting BPSO validation.');
            }

            if ($normalizedAction === 'endorse') {
                $identityVerified = !empty($payload['identity_verified']) || !empty($payload['bpso_identity_verified']);
                $detailsVerified = array_key_exists('details_verified', $payload) ? !empty($payload['details_verified']) : true;
                $respondentInTumana = !empty($payload['respondent_in_tumana']) || !empty($payload['bpso_respondent_in_tumana']);

                if (!$identityVerified) {
                    throw new DomainException('Confirm complainant identity verification before endorsing to LISO.');
                }
                if (!$detailsVerified) {
                    throw new DomainException('Confirm that the complaint narrative and details are complete before endorsing to LISO.');
                }
                if (!$respondentInTumana) {
                    throw new DomainException('Confirm that the respondent resides in Barangay Tumana. Complaints against non-Tumana respondents cannot be endorsed to LISO for KP proceedings—reject with walk-in/venue guidance instead.');
                }

                $blotterNo = trim((string) ($payload['blotter_no'] ?? $payload['bpso_blotter_no'] ?? ''));
                if ($blotterNo === '') {
                    $blotterNo = trim((string) ($complaint['bpso_blotter_no'] ?? '')) ?: self::generateBlotterNumber($complaintId);
                }
                if (mb_strlen($blotterNo) > self::MAX_BLOTTER_LENGTH || !ValidationService::text($blotterNo)) {
                    throw new DomainException('Provide a valid blotter reference number of 64 characters or fewer.');
                }

                $assignedLisoId = filter_var($payload['liso_assigned_to'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
                $assignedDutySessionId = null;
                if ($assignedLisoId !== null) {
                    $lisoStmt = $this->db->prepare(
                        "SELECT d.duty_session_id
                         FROM liso_duty_sessions d
                         INNER JOIN users u ON u.user_id = d.user_id
                         INNER JOIN roles r ON r.role_id = u.role_id
                         WHERE d.user_id = ? AND d.active_marker = 1 AND d.ended_at IS NULL
                           AND DATE(d.started_at) = CURDATE()
                           AND u.status = 'Active' AND r.role_name = 'Lupon Member'
                         ORDER BY d.started_at DESC LIMIT 1"
                    );
                    $lisoStmt->execute([$assignedLisoId]);
                    $dutyId = $lisoStmt->fetchColumn();
                    if (!$dutyId) {
                        throw new DomainException('Selected LISO officer is not currently on active LISO duty today.');
                    }
                    $assignedDutySessionId = (int) $dutyId;
                }

                $targetQueue = (string) ($complaint['assigned_queue'] ?? '');
                if ($targetQueue === '' || $targetQueue === 'BPSO_VALIDATION') {
                    $targetQueue = 'LISO_SCREENING';
                }

                $updateSql = "UPDATE complaints
                    SET bpso_status = 'Endorsed to LISO',
                        bpso_blotter_no = ?,
                        bpso_respondent_in_tumana = 1,
                        bpso_identity_verified = 1,
                        bpso_private_notes = ?,
                        bpso_rejection_reason = NULL,
                        bpso_reviewed_by = ?,
                        bpso_reviewed_at = NOW(),
                        status = 'Under Review',
                        screening_decision = 'Pending',
                        assigned_queue = ?";
                $params = [
                    $blotterNo,
                    $privateNotes !== '' ? $privateNotes : null,
                    $actorUserId,
                    $targetQueue,
                ];
                if ($assignedLisoId !== null) {
                    $updateSql .= ', liso_assigned_to = ?, liso_assigned_duty_session_id = ?, liso_assigned_at = NOW()';
                    $params[] = $assignedLisoId;
                    $params[] = $assignedDutySessionId;
                }
                $updateSql .= ' WHERE complaint_id = ?';
                $params[] = $complaintId;
                $this->db->prepare($updateSql)->execute($params);

                // If complainant has an identified resident profile with non-placeholder name & Tumana address,
                // mark residency_verified = 1 upon BPSO identity verification.
                if (!empty($complaint['submitted_by'])) {
                    $resVerifyStmt = $this->db->prepare(
                        'SELECT r.resident_id, r.first_name, r.last_name, r.purok, r.address, r.residency_verified
                         FROM resident_user_profiles p
                         INNER JOIN residents r ON r.resident_id = p.resident_id
                         WHERE p.user_id = ?
                         LIMIT 1'
                    );
                    $resVerifyStmt->execute([(int) $complaint['submitted_by']]);
                    $resRow = $resVerifyStmt->fetch(PDO::FETCH_ASSOC);
                    if ($resRow && empty($resRow['residency_verified'])) {
                        $decRes = PiiCryptoService::decryptPayload($resRow);
                        $fn = trim((string) ($decRes['first_name'] ?? ''));
                        $ln = trim((string) ($decRes['last_name'] ?? ''));
                        $hasRealName = $fn !== '' && $ln !== '' && strcasecmp($fn, 'Unverified') !== 0 && strcasecmp($ln, 'Resident') !== 0;
                        $hasAddr = trim((string) ($decRes['purok'] ?? '')) !== '' || trim((string) ($decRes['address'] ?? '')) !== '';
                        if ($hasRealName && $hasAddr) {
                            $this->db->prepare('UPDATE residents SET residency_verified = 1 WHERE resident_id = ?')
                                ->execute([(int) $resRow['resident_id']]);
                        }
                    }
                }

                $this->audit->logStrict($actorUserId, 'BPSO Endorsed Complaint to LISO', 'Complaints', $complaintId, [
                    'after' => [
                        'bpso_status' => 'Endorsed to LISO',
                        'bpso_blotter_no' => $blotterNo,
                        'bpso_respondent_in_tumana' => 1,
                        'assigned_queue' => $targetQueue,
                        'liso_assigned_to' => $assignedLisoId,
                    ],
                ]);

                $this->db->commit();
                $this->notifyAfterEndorse($complaintId, $complaint, $blotterNo, $actorUserId, $assignedLisoId);

                return [
                    'success' => true,
                    'bpso_status' => 'Endorsed to LISO',
                    'bpso_blotter_no' => $blotterNo,
                    'message' => "Logged in Blotter ({$blotterNo}) and endorsed to LISO for jurisdiction screening.",
                ];
            }

            if ($normalizedAction === 'request_info') {
                if (empty($complaint['submitted_by'])) {
                    throw new DomainException('Clarification messages can only be sent to online resident accounts. Use Reject with walk-in instructions instead.');
                }
                $requestMessage = trim((string) ($payload['request_message'] ?? ''));
                if ($requestMessage === '' || !ValidationService::maxLength($requestMessage, ComplaintInformationService::REQUEST_LIMIT)
                    || !ValidationService::text($requestMessage, true)) {
                    throw new DomainException('Provide a clear clarification message for the complainant (up to 2,000 characters).');
                }
                if (($complaint['bpso_status'] ?? '') === 'Needs Clarification') {
                    $pending = $this->db->prepare('SELECT responded_at FROM complaint_information_requests WHERE complaint_id = ? ORDER BY request_id DESC LIMIT 1');
                    $pending->execute([$complaintId]);
                    if ($pending->fetchColumn() === null) {
                        throw new DomainException('Waiting for the resident to reply to the previous clarification request.');
                    }
                }

                $this->db->prepare('INSERT INTO complaint_information_requests (complaint_id, requested_by, request_message) VALUES (?, ?, ?)')
                    ->execute([$complaintId, $actorUserId, $requestMessage]);

                $this->db->prepare(
                    "UPDATE complaints
                     SET bpso_status = 'Needs Clarification',
                         bpso_private_notes = ?,
                         bpso_reviewed_by = ?,
                         bpso_reviewed_at = NOW(),
                         status = 'Needs Information',
                         screening_decision = 'Needs Information'
                     WHERE complaint_id = ?"
                )->execute([$privateNotes !== '' ? $privateNotes : null, $actorUserId, $complaintId]);

                $this->audit->logStrict($actorUserId, 'BPSO Requested Clarification', 'Complaints', $complaintId, [
                    'after' => ['bpso_status' => 'Needs Clarification'],
                ]);

                $this->db->commit();
                $this->notifyResident(
                    (int) $complaint['submitted_by'],
                    $complaintId,
                    'BPSO Requested Additional Information',
                    'Your online complaint (' . ($complaint['intake_reference'] ?: '#' . $complaintId) . ') needs additional details before endorsement to LISO: ' . $requestMessage,
                    $actorUserId,
                    'warning'
                );

                return [
                    'success' => true,
                    'bpso_status' => 'Needs Clarification',
                    'message' => 'Clarification request sent to the complainant\'s Resident Portal.',
                ];
            }

            // reject
            $reason = trim((string) ($payload['rejection_reason'] ?? $payload['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > self::MAX_NOTES_LENGTH || !ValidationService::text($reason, true)) {
                throw new DomainException('Provide a clear rejection note explaining what is missing or why the complaint cannot proceed online (up to 2,000 characters).');
            }

            $respTumanaFlag = array_key_exists('respondent_in_tumana', $payload) || array_key_exists('bpso_respondent_in_tumana', $payload)
                ? ((!empty($payload['respondent_in_tumana']) || !empty($payload['bpso_respondent_in_tumana'])) ? 1 : 0)
                : null;

            $this->db->prepare(
                "UPDATE complaints
                 SET bpso_status = 'Rejected',
                     bpso_respondent_in_tumana = ?,
                     bpso_private_notes = ?,
                     bpso_rejection_reason = ?,
                     bpso_reviewed_by = ?,
                     bpso_reviewed_at = NOW(),
                     status = 'Rejected',
                     screening_decision = 'Rejected',
                     review_notes = ?
                 WHERE complaint_id = ?"
            )->execute([
                $respTumanaFlag,
                $privateNotes !== '' ? $privateNotes : null,
                $reason,
                $actorUserId,
                $reason,
                $complaintId,
            ]);

            $this->audit->logStrict($actorUserId, 'BPSO Rejected Complaint', 'Complaints', $complaintId, [
                'after' => [
                    'bpso_status' => 'Rejected',
                    'bpso_respondent_in_tumana' => $respTumanaFlag,
                ],
            ]);

            $this->db->commit();
            if (!empty($complaint['submitted_by'])) {
                $this->notifyResident(
                    (int) $complaint['submitted_by'],
                    $complaintId,
                    'Online Complaint Returned by BPSO',
                    'Your submission (' . ($complaint['intake_reference'] ?: '#' . $complaintId) . ') was not forwarded to LISO. Note: ' . $reason . ' You may visit the Barangay Tumana BPSO desk in person to file or complete your complaint walk-in.',
                    $actorUserId,
                    'warning'
                );
            }

            return [
                'success' => true,
                'bpso_status' => 'Rejected',
                'message' => 'Complaint rejected with reason. The complainant has been advised of the walk-in option.',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof DomainException) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
            error_log('BpsoIntakeService::reviewOnlineIntake error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to process BPSO intake validation right now.'];
        }
    }

    public function getDashboardData(): array
    {
        $summary = [
            'pending_online' => 0,
            'needs_clarification' => 0,
            'endorsed_today' => 0,
            'walkin_today' => 0,
            'rejected_total' => 0,
        ];

        $counts = $this->db->query(
            "SELECT
                SUM(CASE WHEN is_draft = 0 AND deleted_at IS NULL AND bpso_status = 'Pending BPSO Review' THEN 1 ELSE 0 END) AS pending_online,
                SUM(CASE WHEN is_draft = 0 AND deleted_at IS NULL AND bpso_status = 'Needs Clarification' THEN 1 ELSE 0 END) AS needs_clarification,
                SUM(CASE WHEN is_draft = 0 AND deleted_at IS NULL AND bpso_status = 'Endorsed to LISO' AND DATE(bpso_reviewed_at) = CURDATE() THEN 1 ELSE 0 END) AS endorsed_today,
                SUM(CASE WHEN is_draft = 0 AND deleted_at IS NULL AND intake_source = 'Walk-in' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS walkin_today,
                SUM(CASE WHEN is_draft = 0 AND deleted_at IS NULL AND bpso_status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_total
             FROM complaints"
        )->fetch(PDO::FETCH_ASSOC);

        if ($counts) {
            foreach ($summary as $k => $_) {
                $summary[$k] = (int) ($counts[$k] ?? 0);
            }
        }

        $pendingOnline = $this->fetchQueueRows(
            "c.is_draft = 0 AND c.deleted_at IS NULL AND c.bpso_status IN ('Pending BPSO Review', 'Needs Clarification')",
            'ORDER BY (c.bpso_status = \'Pending BPSO Review\') DESC, c.created_at ASC',
            50
        );

        $recentProcessed = $this->fetchQueueRows(
            "c.is_draft = 0 AND c.deleted_at IS NULL AND c.bpso_status IN ('Endorsed to LISO', 'Rejected')",
            'ORDER BY COALESCE(c.bpso_reviewed_at, c.created_at) DESC, c.complaint_id DESC',
            30
        );

        return [
            'kpis' => $summary,
            'pending_online' => $pendingOnline,
            'recent_processed' => $recentProcessed,
        ];
    }

    private function fetchQueueRows(string $whereClause, string $orderClause, int $limit): array
    {
        $sql = "SELECT c.complaint_id, c.intake_reference, c.complaint_title, c.intake_source,
                       c.bpso_status, c.bpso_blotter_no, c.bpso_respondent_in_tumana,
                       c.bpso_identity_verified, c.bpso_rejection_reason, c.bpso_reviewed_at,
                       c.status, c.screening_decision, c.incident_purok, c.incident_location,
                       c.created_at, cat.category_name,
                       (SELECT MAX(ir.responded_at) FROM complaint_information_requests ir WHERE ir.complaint_id = c.complaint_id) AS resident_replied_at
                FROM complaints c
                LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
                WHERE {$whereClause}
                {$orderClause}
                LIMIT {$limit}";
        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $partyStmt = $this->db->prepare(
            "SELECT cp.party_type, r.first_name, r.middle_name, r.last_name, r.purok, r.address, r.is_tenant, r.residency_verified
             FROM complaint_parties cp
             INNER JOIN residents r ON r.resident_id = cp.resident_id
             WHERE cp.complaint_id = ?"
        );

        foreach ($rows as &$row) {
            $partyStmt->execute([(int) $row['complaint_id']]);
            $parties = PiiCryptoService::decryptPayload($partyStmt->fetchAll(PDO::FETCH_ASSOC));
            $complainants = [];
            $respondents = [];
            $respondentAddresses = [];
            $tumanaHint = false;
            $complainantVerified = false;
            foreach ($parties as $p) {
                $fullName = trim(implode(' ', array_filter([$p['first_name'] ?? '', $p['middle_name'] ?? '', $p['last_name'] ?? ''])));
                if (strcasecmp((string) ($p['party_type'] ?? ''), 'Respondent') === 0) {
                    if ($fullName !== '') {
                        $respondents[] = $fullName;
                    }
                    $addr = trim(implode(', ', array_filter([$p['address'] ?? '', $p['purok'] ?? ''])));
                    if ($addr !== '') {
                        $respondentAddresses[] = $addr;
                    }
                    if (self::looksLikeTumanaAddress('Tumana', $p['purok'] ?? null, $p['address'] ?? null, (int) ($p['is_tenant'] ?? 0))) {
                        $tumanaHint = true;
                    }
                } elseif (strcasecmp((string) ($p['party_type'] ?? ''), 'Complainant') === 0) {
                    if ($fullName !== '') {
                        $complainants[] = $fullName;
                    }
                    if (!empty($p['residency_verified'])) {
                        $complainantVerified = true;
                    }
                }
            }
            $row['complainant_names'] = $complainants ? implode(', ', $complainants) : 'Unspecified Complainant';
            $row['respondent_names'] = $respondents ? implode(', ', $respondents) : 'Unspecified Respondent';
            $row['respondent_addresses'] = $respondentAddresses ? implode(' · ', array_unique($respondentAddresses)) : 'No address provided';
            $row['respondent_tumana_hint'] = $tumanaHint;
            $row['complainant_profile_verified'] = $complainantVerified;
        }
        unset($row);

        return PiiCryptoService::decryptPayload($rows);
    }

    private function notifyAfterEndorse(int $complaintId, array $complaint, string $blotterNo, int $actorUserId, ?int $assignedLisoId): void
    {
        try {
            $notifier = new NotificationService($this->db);
            $ref = (string) ($complaint['intake_reference'] ?: 'CMP-' . $complaintId);
            $title = (string) ($complaint['complaint_title'] ?: 'Complaint Intake');
            if ($assignedLisoId !== null) {
                $notifier->notifyUser(
                    $assignedLisoId,
                    'New BPSO-Endorsed Complaint for LISO Screening',
                    "BPSO logged {$ref} ({$title}) under Blotter {$blotterNo} and assigned it to you for KP jurisdiction evaluation.",
                    $actorUserId,
                    'info',
                    "../complaints/complaint-details.php?id={$complaintId}#intake-screening"
                );
            } else {
                $notifier->notifyRoles(
                    ['Lupon Member'],
                    'BPSO Endorsed Complaint to LISO',
                    "BPSO validated {$ref} ({$title}) under Blotter {$blotterNo}. Ready for LISO jurisdiction screening.",
                    $actorUserId,
                    'info',
                    "../complaints/complaint-details.php?id={$complaintId}#intake-screening"
                );
            }
            if (!empty($complaint['submitted_by'])) {
                $notifier->notifyUser(
                    (int) $complaint['submitted_by'],
                    'Complaint Verified by BPSO & Endorsed to LISO',
                    "Your online complaint ({$ref}) was validated by BPSO (Blotter {$blotterNo}) and forwarded to LISO for jurisdiction screening. No barangay visit is required at this stage.",
                    $actorUserId,
                    'success',
                    'complaint.php?id=' . $complaintId
                );
            }
        } catch (Throwable $e) {
            error_log('BpsoIntakeService::notifyAfterEndorse warning: ' . $e->getMessage());
        }
    }

    private function notifyResident(int $residentUserId, int $complaintId, string $title, string $message, int $actorUserId, string $severity): void
    {
        try {
            (new NotificationService($this->db))->notifyUser(
                $residentUserId,
                $title,
                $message,
                $actorUserId,
                $severity,
                'complaint.php?id=' . $complaintId
            );
        } catch (Throwable $e) {
            error_log('BpsoIntakeService::notifyResident warning: ' . $e->getMessage());
        }
    }
}
