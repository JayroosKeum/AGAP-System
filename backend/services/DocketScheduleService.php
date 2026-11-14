<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/DeadlineService.php';
require_once __DIR__ . '/MediationDeadlineService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/OfficeLogisticsService.php';
require_once __DIR__ . '/PDFService.php';
require_once __DIR__ . '/ValidationService.php';
require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../models/Setting.php';

class DocketScheduleService
{
    private PDO $conn;
    private AuditService $audit;
    private NotificationService $notifications;
    private OfficeLogisticsService $logistics;
    private Hearing $hearingModel;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->audit = new AuditService($this->conn);
        $this->notifications = new NotificationService();
        $this->logistics = new OfficeLogisticsService($this->conn);
        $this->hearingModel = new Hearing($this->conn);
    }

    /**
     * Dynamically resolves role IDs for Administrator and Lupon Clerk from the roles table.
     */
    public function getAuthorizedRoleIds(): array
    {
        try {
            $stmt = $this->conn->query("SELECT role_id FROM roles WHERE role_name IN ('Administrator', 'Lupon Clerk') ORDER BY role_id ASC");
            $ids = array_map('intval', $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []);
            if (!empty($ids)) {
                return $ids;
            }
        } catch (Throwable) {
            // Fallback if roles table query fails
        }
        return [1, 2];
    }

    /**
     * Enforces server-side staff authorization (Administrator and Lupon Clerk only).
     */
    public function isAuthorizedStaff(?int $userId, ?int $roleId = null): array
    {
        if (!$userId || $userId < 1) {
            return [
                'authorized' => false,
                'http_code' => 401,
                'message' => 'Authentication required.'
            ];
        }

        $allowedRoles = $this->getAuthorizedRoleIds();

        try {
            $stmt = $this->conn->prepare("SELECT user_id, role_id, status FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                if (($user['status'] ?? 'Active') !== 'Active') {
                    return [
                        'authorized' => false,
                        'http_code' => 403,
                        'message' => 'Access denied. Inactive user account.'
                    ];
                }
                $effectiveRoleId = ($roleId !== null && $roleId > 0) ? $roleId : (int) $user['role_id'];
            } else {
                $effectiveRoleId = (int) ($roleId ?? 0);
            }
        } catch (Throwable) {
            $effectiveRoleId = (int) ($roleId ?? 0);
        }

        if (!in_array($effectiveRoleId, $allowedRoles, true)) {
            return [
                'authorized' => false,
                'http_code' => 403,
                'message' => 'Access denied. Only Administrators and Lupon Clerks are authorized to reorder the docket queue or reschedule appointments.'
            ];
        }

        return [
            'authorized' => true,
            'http_code' => 200,
            'role_id' => $effectiveRoleId,
            'message' => 'Authorized.'
        ];
    }

    /**
     * Evaluates whether a Hearing or Paanyaya record is eligible to be reordered or rescheduled.
     * Locks records that are in session, attendance-recorded, concluded, completed, cancelled,
     * resolved, superseded, or otherwise unavailable.
     */
    public function evaluateItemEligibility(array $record, string $type): array
    {
        $normType = strtolower(trim($type));

        if ($normType === 'hearing') {
            $hearingId = (int) ($record['hearing_id'] ?? $record['id'] ?? 0);
            $status = trim((string) ($record['status'] ?? 'Scheduled'));
            $queueStatus = trim((string) ($record['queue_status'] ?? 'Waiting'));
            $caseStatus = trim((string) ($record['case_status'] ?? ''));

            // Check superseded status
            $isSuperseded = !empty($record['is_superseded']);
            if (!$isSuperseded && $hearingId > 0) {
                $supStmt = $this->conn->prepare("SELECT EXISTS(SELECT 1 FROM hearings WHERE rescheduled_from_id = ?)");
                $supStmt->execute([$hearingId]);
                $isSuperseded = (bool) $supStmt->fetchColumn();
            }
            if ($isSuperseded || $status === 'Rescheduled') {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'SUPERSEDED',
                    'lock_reason' => 'This hearing was superseded by a newer schedule and is permanently locked.'
                ];
            }

            if (in_array($status, ['Cancelled', 'Office Cancelled'], true)) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'CANCELLED',
                    'lock_reason' => 'Cancelled hearings are locked and cannot be reordered or rescheduled.'
                ];
            }

            if ($status === 'Completed' || $queueStatus === 'Concluded' || !empty($record['actual_end_time'])) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'CONCLUDED',
                    'lock_reason' => 'Completed or concluded hearings are locked and cannot be reordered or rescheduled.'
                ];
            }

            if (in_array($queueStatus, ['In_Session', 'In Progress'], true) || !empty($record['actual_start_time'])) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'IN_SESSION',
                    'lock_reason' => 'This hearing is currently in session and is locked.'
                ];
            }

            // Check attendance recorded on the hearing row or in hearing_attendance / hearing_minutes
            $compAtt = trim((string) ($record['complainant_attendance'] ?? 'Pending'));
            $respAtt = trim((string) ($record['respondent_attendance'] ?? 'Pending'));
            $hasPartyAtt = in_array($compAtt, ['Present', 'Late', 'Absent', 'Excused'], true)
                || in_array($respAtt, ['Present', 'Late', 'Absent', 'Excused'], true)
                || !empty($record['attendance_recorded_at'])
                || $status === 'Attendance Recorded'
                || $queueStatus === 'Ready';

            if (!$hasPartyAtt && $hearingId > 0) {
                $attStmt = $this->conn->prepare("
                    SELECT EXISTS(
                        SELECT 1 FROM hearing_attendance
                        WHERE hearing_id = ? AND attendance_status IN ('Present', 'Late', 'Absent', 'Excused')
                    )
                ");
                $attStmt->execute([$hearingId]);
                $hasPartyAtt = (bool) $attStmt->fetchColumn();
            }

            if ($hasPartyAtt) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'ATTENDANCE_RECORDED',
                    'lock_reason' => 'Attendance has already been recorded for this hearing; it is locked.'
                ];
            }

            if ($hearingId > 0) {
                $minStmt = $this->conn->prepare("SELECT EXISTS(SELECT 1 FROM hearing_minutes WHERE hearing_id = ?)");
                $minStmt->execute([$hearingId]);
                if ((bool) $minStmt->fetchColumn()) {
                    return [
                        'eligible' => false,
                        'is_locked' => true,
                        'lock_code' => 'MINUTES_RECORDED',
                        'lock_reason' => 'Hearing minutes have already been recorded for this session.'
                    ];
                }
            }

            if ($caseStatus !== '' && in_array($caseStatus, [
                'Settled', 'Dismissed', 'CFA Issued', 'Archived', 'Closed',
                'Resolved', 'Escalated', 'Unresolved', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Repudiated'
            ], true)) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'CASE_CLOSED',
                    'lock_reason' => "The parent case is already {$caseStatus} and locked."
                ];
            }

            if (!in_array($status, ['Scheduled', 'Pending'], true)) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'UNAVAILABLE',
                    'lock_reason' => "Hearing status '{$status}' is not eligible for reordering or rescheduling."
                ];
            }

            return [
                'eligible' => true,
                'is_locked' => false,
                'lock_code' => null,
                'lock_reason' => null
            ];
        }

        if ($normType === 'paanyaya') {
            $paanyayaId = (int) ($record['paanyaya_id'] ?? $record['id'] ?? 0);
            $sessionId = (int) ($record['session_id'] ?? 0);
            $complaintId = (int) ($record['complaint_id'] ?? 0);
            $attemptNumber = max(1, (int) ($record['attempt_number'] ?? 1));
            $rawStatus = trim((string) ($record['raw_status'] ?? $record['status'] ?? 'Scheduled'));
            $workflowStatus = trim((string) ($record['current_workflow_status'] ?? $rawStatus));
            $sessionQueueStatus = trim((string) ($record['session_queue_status'] ?? $record['queue_status'] ?? 'Waiting'));

            if ($rawStatus === 'Cancelled' || stripos($workflowStatus, 'Cancelled') !== false) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'CANCELLED',
                    'lock_reason' => 'Cancelled Paanyaya invitations are locked.'
                ];
            }

            if (in_array($rawStatus, ['Completed', 'Resolved', 'Unresolved', 'Escalated'], true)
                || in_array($workflowStatus, ['Completed', 'Resolved', 'Unresolved', 'Escalated', 'Settled', 'Dismissed'], true)
                || $sessionQueueStatus === 'Concluded'
                || !empty($record['actual_end_time'])
                || !empty($record['session_outcome'])
                || !empty($record['resolved_at'])
                || !empty($record['escalated_at'])) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'CONCLUDED',
                    'lock_reason' => 'Concluded, resolved, or escalated Paanyaya sessions are locked.'
                ];
            }

            if (in_array($sessionQueueStatus, ['In_Session', 'In Progress'], true) || !empty($record['actual_start_time'])) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'IN_SESSION',
                    'lock_reason' => 'This Paanyaya dialogue session is currently in progress and is locked.'
                ];
            }

            // Check attendance recorded on paanyayas or paanyaya_sessions
            // Note: 'Absent' is the schema default before check-in, whereas 'Present', 'Late', or attendance_recorded_at indicates recorded attendance.
            $compAtt = trim((string) ($record['complainant_attended'] ?? ''));
            $invAtt = trim((string) ($record['invitee_attended'] ?? ''));
            $respAtt = trim((string) ($record['respondent_attended'] ?? ''));
            $hasPaanyayaAtt = !empty($record['attendance_recorded_at'])
                || in_array($compAtt, ['Present', 'Late'], true)
                || in_array($invAtt, ['Present', 'Late'], true)
                || in_array($respAtt, ['Present', 'Late'], true)
                || $sessionQueueStatus === 'Ready';

            if (!$hasPaanyayaAtt && $sessionId > 0) {
                try {
                    $psaStmt = $this->conn->prepare("
                        SELECT EXISTS(SELECT 1 FROM paanyaya_session_attendance WHERE session_id = ?)
                    ");
                    $psaStmt->execute([$sessionId]);
                    $hasPaanyayaAtt = (bool) $psaStmt->fetchColumn();
                } catch (Throwable) {}
            }

            if ($hasPaanyayaAtt) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'ATTENDANCE_RECORDED',
                    'lock_reason' => 'Party attendance/check-in has already been recorded for this Paanyaya session.'
                ];
            }

            if ($complaintId > 0) {
                // Check if complaint is already closed/resolved or docketed into a formal case
                $cStmt = $this->conn->prepare("
                    SELECT c.status, c.resolved_without_docketing, cs.case_id
                    FROM complaints c
                    LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
                    WHERE c.complaint_id = ?
                    LIMIT 1
                ");
                $cStmt->execute([$complaintId]);
                $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                if ($cRow) {
                    if (!empty($cRow['case_id'])) {
                        return [
                            'eligible' => false,
                            'is_locked' => true,
                            'lock_code' => 'SUPERSEDED',
                            'lock_reason' => 'This complaint has already been escalated to a formal case docket.'
                        ];
                    }
                    if (!empty($cRow['resolved_without_docketing'])
                        || in_array($cRow['status'] ?? '', ['Settled', 'Dismissed', 'Resolved at Paanyaya / Closed', 'Rejected'], true)) {
                        return [
                            'eligible' => false,
                            'is_locked' => true,
                            'lock_code' => 'RESOLVED',
                            'lock_reason' => 'This complaint has already been resolved or closed.'
                        ];
                    }
                }

                // Check if superseded by a later Paanyaya attempt or session
                if ($paanyayaId > 0) {
                    $laterStmt = $this->conn->prepare("
                        SELECT EXISTS(
                            SELECT 1 FROM paanyayas p2
                            WHERE p2.complaint_id = ?
                              AND (p2.attempt_number > ? OR (p2.attempt_number = ? AND p2.paanyaya_id > ?))
                              AND p2.status <> 'Cancelled'
                        )
                    ");
                    $laterStmt->execute([$complaintId, $attemptNumber, $attemptNumber, $paanyayaId]);
                    if ((bool) $laterStmt->fetchColumn()) {
                        return [
                            'eligible' => false,
                            'is_locked' => true,
                            'lock_code' => 'SUPERSEDED',
                            'lock_reason' => 'This Paanyaya invitation was superseded by a newer Paanyaya attempt.'
                        ];
                    }
                }

                // Check if minutes or completed/cancelled stage exist for this attempt
                $stageType = 'Paanyaya ' . $attemptNumber;
                $minStmt = $this->conn->prepare("
                    SELECT EXISTS(SELECT 1 FROM paanyaya_minutes WHERE complaint_id = ? AND stage_type = ?)
                ");
                $minStmt->execute([$complaintId, $stageType]);
                if ((bool) $minStmt->fetchColumn()) {
                    return [
                        'eligible' => false,
                        'is_locked' => true,
                        'lock_code' => 'MINUTES_RECORDED',
                        'lock_reason' => 'Dialogue minutes have already been recorded for this Paanyaya stage.'
                    ];
                }

                $stStmt = $this->conn->prepare("
                    SELECT status FROM paanyaya_stages WHERE complaint_id = ? AND stage_type = ? LIMIT 1
                ");
                $stStmt->execute([$complaintId, $stageType]);
                $stStatus = $stStmt->fetchColumn();
                if ($stStatus && in_array($stStatus, ['Completed', 'Cancelled'], true)) {
                    return [
                        'eligible' => false,
                        'is_locked' => true,
                        'lock_code' => 'STAGE_CLOSED',
                        'lock_reason' => "Paanyaya stage is already marked {$stStatus}."
                    ];
                }
            }

            if (!in_array($rawStatus, ['Scheduled', 'Pending'], true)) {
                return [
                    'eligible' => false,
                    'is_locked' => true,
                    'lock_code' => 'UNAVAILABLE',
                    'lock_reason' => "Paanyaya status '{$rawStatus}' is not eligible for reordering or rescheduling."
                ];
            }

            return [
                'eligible' => true,
                'is_locked' => false,
                'lock_code' => null,
                'lock_reason' => null
            ];
        }

        return [
            'eligible' => false,
            'is_locked' => true,
            'lock_code' => 'INVALID_TYPE',
            'lock_reason' => 'Unrecognized schedule item type.'
        ];
    }

    /**
     * Reorders the same-day call queue WITHOUT modifying official appointment times.
     */
    public function reorderQueue(array $items, int $userId, ?string $queueDate = null, array $context = []): array
    {
        $roleId = isset($context['role_id']) ? (int) $context['role_id'] : (isset($_SESSION['role_id']) ? (int) $_SESSION['role_id'] : null);
        $auth = $this->isAuthorizedStaff($userId, $roleId);
        if (!$auth['authorized']) {
            return [
                'success' => false,
                'http_code' => $auth['http_code'],
                'code' => 'UNAUTHORIZED',
                'message' => $auth['message']
            ];
        }

        // Guard against filtered, paginated, or multi-day views
        if (!empty($context['is_filtered'])) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'FILTERED_VIEW_LOCKED',
                'message' => 'Queue reordering is disabled while a filter or search query is active. Clear filters so the complete day is loaded.'
            ];
        }
        if (!empty($context['is_paginated'])) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'PAGINATED_VIEW_LOCKED',
                'message' => 'Queue reordering is disabled on paginated views unless the complete day is loaded.'
            ];
        }
        $viewMode = strtolower(trim((string) ($context['view_mode'] ?? 'day')));
        if (in_array($viewMode, ['week', 'weekly', 'month', 'monthly', 'quarter', 'quarterly', 'year', 'yearly', 'annual'], true)
            && empty($context['full_day_loaded'])) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'PERIOD_VIEW_LOCKED',
                'message' => 'Queue reordering is disabled in multi-day (week, month, quarter, or year) views. Select a specific single day first.'
            ];
        }

        if (empty($items)) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'EMPTY_PAYLOAD',
                'message' => 'No docket items were provided for queue reordering.'
            ];
        }

        $targetDate = ($queueDate !== null && trim($queueDate) !== '') ? trim($queueDate) : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'INVALID_DATE',
                'message' => 'Provide a valid docket date (YYYY-MM-DD).'
            ];
        }

        try {
            $this->conn->beginTransaction();

            // 1. Lock all active records for targetDate to serialize concurrent reorders/reschedules
            $dayHearingsStmt = $this->conn->prepare("
                SELECT h.*, COALESCE(h.queue_order, 0) AS queue_order, c.case_status
                FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                WHERE DATE(h.hearing_date) = ?
                  AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Completed', 'Rescheduled')
                  AND COALESCE(h.queue_status, 'Waiting') <> 'Concluded'
                ORDER BY CASE WHEN COALESCE(h.queue_order, 0) > 0 THEN h.queue_order ELSE 9999 END ASC,
                         h.hearing_date ASC, h.hearing_id ASC
                FOR UPDATE
            ");
            $dayHearingsStmt->execute([$targetDate]);
            $dayHearings = $dayHearingsStmt->fetchAll(PDO::FETCH_ASSOC);

            $dayPaanyayasStmt = $this->conn->prepare("
                SELECT p.*, COALESCE(p.queue_order, 0) AS queue_order
                FROM paanyayas p
                WHERE p.scheduled_date = ?
                  AND p.status NOT IN ('Completed', 'Cancelled', 'Resolved', 'Unresolved', 'Escalated')
                ORDER BY CASE WHEN COALESCE(p.queue_order, 0) > 0 THEN p.queue_order ELSE 9999 END ASC,
                         p.scheduled_time ASC, p.paanyaya_id ASC
                FOR UPDATE
            ");
            $dayPaanyayasStmt->execute([$targetDate]);
            $dayPaanyayas = $dayPaanyayasStmt->fetchAll(PDO::FETCH_ASSOC);

            $daySessionsStmt = $this->conn->prepare("
                SELECT ps.*, COALESCE(ps.queue_order, 0) AS queue_order
                FROM paanyaya_sessions ps
                WHERE ps.scheduled_date = ?
                  AND COALESCE(ps.queue_status, 'Waiting') <> 'Concluded'
                ORDER BY CASE WHEN COALESCE(ps.queue_order, 0) > 0 THEN ps.queue_order ELSE 9999 END ASC,
                         ps.scheduled_time ASC, ps.session_id ASC
                FOR UPDATE
            ");
            $daySessionsStmt->execute([$targetDate]);
            $daySessions = $daySessionsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Index day records
            $unlockedDayHearings = [];
            foreach ($dayHearings as $dh) {
                $elig = $this->evaluateItemEligibility($dh, 'hearing');
                if ($elig['eligible']) {
                    $unlockedDayHearings[(int) $dh['hearing_id']] = $dh;
                }
            }

            $sessionsByComplaintAttempt = [];
            $sessionsById = [];
            foreach ($daySessions as $ds) {
                $sessionsById[(int) $ds['session_id']] = $ds;
                $cId = (int) ($ds['complaint_id'] ?? 0);
                $att = max(1, (int) ($ds['attempt_number'] ?? 1));
                if ($cId > 0) {
                    $sessionsByComplaintAttempt["{$cId}:{$att}"] = $ds;
                }
            }

            $unlockedDayPaanyayas = [];
            foreach ($dayPaanyayas as $dp) {
                $cId = (int) ($dp['complaint_id'] ?? 0);
                $att = max(1, (int) ($dp['attempt_number'] ?? 1));
                $linkedSession = $cId > 0 ? ($sessionsByComplaintAttempt["{$cId}:{$att}"] ?? null) : null;
                $merged = $dp;
                if ($linkedSession) {
                    $merged['session_id'] = (int) $linkedSession['session_id'];
                    $merged['session_queue_status'] = $linkedSession['queue_status'] ?? 'Waiting';
                    $merged['actual_start_time'] = $linkedSession['actual_start_time'] ?? null;
                    $merged['actual_end_time'] = $linkedSession['actual_end_time'] ?? null;
                    $merged['session_outcome'] = $linkedSession['session_outcome'] ?? null;
                    $merged['respondent_attended'] = $linkedSession['respondent_attended'] ?? null;
                    if (empty($merged['complainant_attended']) || $merged['complainant_attended'] === 'Absent') {
                        $merged['complainant_attended'] = $linkedSession['complainant_attended'] ?? null;
                    }
                }
                $elig = $this->evaluateItemEligibility($merged, 'paanyaya');
                if ($elig['eligible']) {
                    $unlockedDayPaanyayas[(int) $dp['paanyaya_id']] = $merged;
                }
            }

            // Prepare individual row lock queries for any item not already in day maps
            $hLookup = $this->conn->prepare("
                SELECT h.*, COALESCE(h.queue_order, 0) AS queue_order, c.case_status
                FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                WHERE h.hearing_id = ?
                FOR UPDATE
            ");
            $pLookup = $this->conn->prepare("
                SELECT p.*, COALESCE(p.queue_order, 0) AS queue_order
                FROM paanyayas p
                WHERE p.paanyaya_id = ?
                FOR UPDATE
            ");
            $psLookup = $this->conn->prepare("
                SELECT ps.*, COALESCE(ps.queue_order, 0) AS queue_order
                FROM paanyaya_sessions ps
                WHERE ps.session_id = ?
                FOR UPDATE
            ");

            $seenKeys = [];
            $seenSessionIds = [];
            $submittedHearingIds = [];
            $submittedPaanyayaIds = [];
            $validatedItems = [];

            foreach ($items as $idx => $it) {
                if (!is_array($it)) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'http_code' => 422,
                        'code' => 'INVALID_ITEM',
                        'message' => 'Invalid item structure in docket queue payload.'
                    ];
                }

                $type = strtolower(trim((string) ($it['type'] ?? 'hearing')));
                $id = (int) ($it['id'] ?? 0);
                $sessionId = (int) ($it['session_id'] ?? 0);
                $submittedComplaintId = isset($it['complaint_id']) && $it['complaint_id'] !== '' ? (int) $it['complaint_id'] : null;

                if (!in_array($type, ['hearing', 'paanyaya'], true) || $id < 1) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'http_code' => 422,
                        'code' => 'INVALID_ITEM',
                        'message' => 'Every docket item must specify a valid type (hearing or paanyaya) and positive ID.'
                    ];
                }

                $itemKey = "{$type}:{$id}";
                if (isset($seenKeys[$itemKey])) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'http_code' => 422,
                        'code' => 'DUPLICATE_ITEM',
                        'message' => 'Duplicate item detected in docket queue payload.'
                    ];
                }
                $seenKeys[$itemKey] = true;

                if ($type === 'hearing') {
                    $hLookup->execute([$id]);
                    $hRow = $hLookup->fetch(PDO::FETCH_ASSOC);
                    if (!$hRow) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 422,
                            'code' => 'NOT_FOUND',
                            'message' => "Hearing #{$id} does not exist."
                        ];
                    }

                    $itemDate = substr((string) $hRow['hearing_date'], 0, 10);
                    if ($itemDate !== $targetDate) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 422,
                            'code' => 'CROSS_DATE_REJECTED',
                            'message' => "Hearing #{$id} is scheduled on {$itemDate}, not the selected docket date ({$targetDate}). Moving an item to another date requires formal rescheduling."
                        ];
                    }

                    // Stale payload checks if caller provided expected snapshot values
                    if (!empty($it['expected_hearing_date'])) {
                        $expTime = date('Y-m-d H:i', strtotime((string) $it['expected_hearing_date']));
                        $curTime = date('Y-m-d H:i', strtotime((string) $hRow['hearing_date']));
                        if ($expTime !== $curTime) {
                            $this->conn->rollBack();
                            return [
                                'success' => false,
                                'http_code' => 409,
                                'code' => 'STALE_PAYLOAD',
                                'message' => "Hearing #{$id} schedule was modified by another user. Reload the docket before reordering."
                            ];
                        }
                    }
                    if (!empty($it['expected_updated_at']) && (string) $it['expected_updated_at'] !== (string) ($hRow['updated_at'] ?? '')) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 409,
                            'code' => 'STALE_PAYLOAD',
                            'message' => "Hearing #{$id} was modified concurrently. Reload the docket before reordering."
                        ];
                    }
                    if (isset($it['expected_queue_order']) && $it['expected_queue_order'] !== '' && (int) $it['expected_queue_order'] !== (int) $hRow['queue_order']) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 409,
                            'code' => 'STALE_PAYLOAD',
                            'message' => "Docket queue order changed concurrently. Reload the docket before reordering."
                        ];
                    }

                    $elig = $this->evaluateItemEligibility($hRow, 'hearing');
                    if (!$elig['eligible']) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 422,
                            'code' => $elig['lock_code'] ?? 'ITEM_LOCKED',
                            'message' => $elig['lock_reason'] ?? "Hearing #{$id} is locked and cannot be reordered."
                        ];
                    }

                    $submittedHearingIds[$id] = true;
                    $validatedItems[] = [
                        'type' => 'hearing',
                        'id' => $id,
                        'session_id' => 0,
                        'complaint_id' => null,
                        'old_order' => (int) $hRow['queue_order'],
                        'new_order' => $idx + 1,
                        'scheduled_date' => $itemDate,
                        'scheduled_time' => date('H:i:s', strtotime((string) $hRow['hearing_date'])),
                        'hearing_date' => $hRow['hearing_date'],
                        'end_time' => $hRow['end_time'],
                        'duration_minutes' => (int) ($hRow['duration_minutes'] ?: 45)
                    ];
                } else {
                    // Paanyaya verification
                    $pLookup->execute([$id]);
                    $pRow = $pLookup->fetch(PDO::FETCH_ASSOC);
                    if (!$pRow) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 422,
                            'code' => 'NOT_FOUND',
                            'message' => "Paanyaya #{$id} does not exist."
                        ];
                    }

                    $paanyayaComplaintId = (int) ($pRow['complaint_id'] ?: ($pRow['escalated_to_complaint_id'] ?? 0));
                    if ($submittedComplaintId !== null && $submittedComplaintId > 0 && $paanyayaComplaintId !== $submittedComplaintId) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 422,
                            'code' => 'PAANYAYA_COMPLAINT_MISMATCH',
                            'message' => "Paanyaya #{$id} does not belong to complaint #{$submittedComplaintId}."
                        ];
                    }

                    $linkedSession = null;
                    if ($sessionId > 0) {
                        if (isset($seenSessionIds[$sessionId])) {
                            $this->conn->rollBack();
                            return [
                                'success' => false,
                                'http_code' => 422,
                                'code' => 'DUPLICATE_SESSION',
                                'message' => "Duplicate Paanyaya session #{$sessionId} in docket payload."
                            ];
                        }
                        $seenSessionIds[$sessionId] = true;

                        $psLookup->execute([$sessionId]);
                        $linkedSession = $psLookup->fetch(PDO::FETCH_ASSOC);
                        if (!$linkedSession) {
                            $this->conn->rollBack();
                            return [
                                'success' => false,
                                'http_code' => 422,
                                'code' => 'SESSION_NOT_FOUND',
                                'message' => "Paanyaya session #{$sessionId} does not exist."
                            ];
                        }

                        // Verify that the Paanyaya session belongs to the submitted complaint and Paanyaya record
                        $sessionComplaintId = (int) ($linkedSession['complaint_id'] ?? 0);
                        $pAttempt = max(1, (int) ($pRow['attempt_number'] ?? 1));
                        $sAttempt = max(1, (int) ($linkedSession['attempt_number'] ?? 1));
                        if ($paanyayaComplaintId < 1
                            || $sessionComplaintId !== $paanyayaComplaintId
                            || ($submittedComplaintId !== null && $submittedComplaintId > 0 && $sessionComplaintId !== $submittedComplaintId)
                            || ($pAttempt !== $sAttempt && (string) $linkedSession['scheduled_date'] !== (string) $pRow['scheduled_date'])) {
                            $this->conn->rollBack();
                            return [
                                'success' => false,
                                'http_code' => 422,
                                'code' => 'PAANYAYA_SESSION_MISMATCH',
                                'message' => "Paanyaya session #{$sessionId} does not belong to the submitted complaint and Paanyaya #{$id} record."
                            ];
                        }
                    } elseif ($paanyayaComplaintId > 0) {
                        // Resolve linked session automatically if one exists for this complaint and attempt/date
                        $pAttempt = max(1, (int) ($pRow['attempt_number'] ?? 1));
                        $linkedSession = $sessionsByComplaintAttempt["{$paanyayaComplaintId}:{$pAttempt}"] ?? null;
                        if ($linkedSession) {
                            $sessionId = (int) $linkedSession['session_id'];
                        }
                    }

                    if ((string) $pRow['scheduled_date'] !== $targetDate
                        || ($linkedSession && (string) $linkedSession['scheduled_date'] !== $targetDate)) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 422,
                            'code' => 'CROSS_DATE_REJECTED',
                            'message' => "Paanyaya #{$id} is not scheduled on {$targetDate}. Moving an item to another date requires formal rescheduling."
                        ];
                    }

                    if (!empty($it['expected_scheduled_time'])) {
                        $expT = substr(trim((string) $it['expected_scheduled_time']), 0, 5);
                        $curT = substr(trim((string) ($pRow['scheduled_time'] ?? '')), 0, 5);
                        if ($expT !== $curT) {
                            $this->conn->rollBack();
                            return [
                                'success' => false,
                                'http_code' => 409,
                                'code' => 'STALE_PAYLOAD',
                                'message' => "Paanyaya #{$id} time was modified by another user. Reload the docket before reordering."
                            ];
                        }
                    }
                    if (!empty($it['expected_updated_at']) && (string) $it['expected_updated_at'] !== (string) ($pRow['updated_at'] ?? '')) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 409,
                            'code' => 'STALE_PAYLOAD',
                            'message' => "Paanyaya #{$id} was modified concurrently. Reload the docket before reordering."
                        ];
                    }
                    if (isset($it['expected_queue_order']) && $it['expected_queue_order'] !== '' && (int) $it['expected_queue_order'] !== (int) $pRow['queue_order']) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 409,
                            'code' => 'STALE_PAYLOAD',
                            'message' => "Docket queue order changed concurrently. Reload the docket before reordering."
                        ];
                    }

                    $mergedPaanyaya = $pRow;
                    if ($linkedSession) {
                        $mergedPaanyaya['session_id'] = (int) $linkedSession['session_id'];
                        $mergedPaanyaya['session_queue_status'] = $linkedSession['queue_status'];
                        $mergedPaanyaya['actual_start_time'] = $linkedSession['actual_start_time'];
                        $mergedPaanyaya['actual_end_time'] = $linkedSession['actual_end_time'];
                        $mergedPaanyaya['session_outcome'] = $linkedSession['session_outcome'];
                        $mergedPaanyaya['respondent_attended'] = $linkedSession['respondent_attended'];
                        if (empty($mergedPaanyaya['complainant_attended']) || $mergedPaanyaya['complainant_attended'] === 'Absent') {
                            $mergedPaanyaya['complainant_attended'] = $linkedSession['complainant_attended'];
                        }
                    }

                    $elig = $this->evaluateItemEligibility($mergedPaanyaya, 'paanyaya');
                    if (!$elig['eligible']) {
                        $this->conn->rollBack();
                        return [
                            'success' => false,
                            'http_code' => 422,
                            'code' => $elig['lock_code'] ?? 'ITEM_LOCKED',
                            'message' => $elig['lock_reason'] ?? "Paanyaya #{$id} is locked and cannot be reordered."
                        ];
                    }

                    $submittedPaanyayaIds[$id] = true;
                    $validatedItems[] = [
                        'type' => 'paanyaya',
                        'id' => $id,
                        'session_id' => $sessionId,
                        'complaint_id' => $paanyayaComplaintId ?: null,
                        'old_order' => (int) ($pRow['queue_order'] ?: ($linkedSession['queue_order'] ?? 0)),
                        'new_order' => $idx + 1,
                        'scheduled_date' => (string) $pRow['scheduled_date'],
                        'scheduled_time' => (string) ($pRow['scheduled_time'] ?: '09:00:00')
                    ];
                }
            }

            // 2. Verify completeness: reject partial/filtered/incomplete day payloads
            $missingHearings = array_diff(array_keys($unlockedDayHearings), array_keys($submittedHearingIds));
            $missingPaanyayas = array_diff(array_keys($unlockedDayPaanyayas), array_keys($submittedPaanyayaIds));

            if (!empty($missingHearings) || !empty($missingPaanyayas)) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'INCOMPLETE_DOCKET_PAYLOAD',
                    'message' => 'Incomplete docket payload: all active unlocked items scheduled for ' . $targetDate . ' must be included to reorder the queue.'
                ];
            }

            // 3. Detect unchanged drag (no-op)
            // Check if every item already has queue_order === new_order
            $isUnchanged = true;
            foreach ($validatedItems as $vi) {
                if ((int) $vi['old_order'] !== (int) $vi['new_order']) {
                    $isUnchanged = false;
                    break;
                }
            }

            if ($isUnchanged) {
                $this->conn->commit();
                $noopItems = [];
                foreach ($validatedItems as $vi) {
                    $timeShort = substr((string) $vi['scheduled_time'], 0, 5);
                    $noopItems[] = [
                        'type' => $vi['type'],
                        'id' => $vi['id'],
                        'session_id' => $vi['session_id'] ?: null,
                        'queue_order' => $vi['new_order'],
                        'new_time' => $timeShort,
                        'formatted_time' => date('g:i A', strtotime("{$targetDate} {$timeShort}:00"))
                    ];
                }
                return [
                    'success' => true,
                    'http_code' => 200,
                    'no_op' => true,
                    'updated_count' => 0,
                    'queue_date' => $targetDate,
                    'updated_items' => $noopItems,
                    'message' => 'Queue order is unchanged.'
                ];
            }

            // 4. Update ONLY queue_order — preserve official appointment times untouched
            $hUpdate = $this->conn->prepare("UPDATE hearings SET queue_order = ? WHERE hearing_id = ?");
            $pUpdate = $this->conn->prepare("UPDATE paanyayas SET queue_order = ? WHERE paanyaya_id = ?");
            $psUpdate = $this->conn->prepare("UPDATE paanyaya_sessions SET queue_order = ? WHERE session_id = ? AND scheduled_date = ?");

            $updatedItems = [];
            $orderSummaryParts = [];

            foreach ($validatedItems as $vi) {
                $order = (int) $vi['new_order'];
                $id = (int) $vi['id'];
                $timeShort = substr((string) $vi['scheduled_time'], 0, 5);
                $formattedTime = date('g:i A', strtotime("{$targetDate} {$timeShort}:00"));

                if ($vi['type'] === 'hearing') {
                    $hUpdate->execute([$order, $id]);
                    $updatedItems[] = [
                        'type' => 'hearing',
                        'id' => $id,
                        'queue_order' => $order,
                        'new_time' => $timeShort,
                        'formatted_time' => $formattedTime,
                        'hearing_date' => $vi['hearing_date']
                    ];
                    $orderSummaryParts[] = "H#{$id}:{$vi['old_order']}->{$order}";
                } else {
                    $pUpdate->execute([$order, $id]);
                    if (!empty($vi['session_id'])) {
                        $psUpdate->execute([$order, (int) $vi['session_id'], $targetDate]);
                    }
                    $updatedItems[] = [
                        'type' => 'paanyaya',
                        'id' => $id,
                        'session_id' => $vi['session_id'] ?: null,
                        'queue_order' => $order,
                        'new_time' => $timeShort,
                        'formatted_time' => $formattedTime,
                        'scheduled_date' => $vi['scheduled_date'],
                        'scheduled_time' => $vi['scheduled_time']
                    ];
                    $orderSummaryParts[] = "P#{$id}:{$vi['old_order']}->{$order}";
                }
            }

            $summaryStr = implode(', ', $orderSummaryParts);
            $auditAction = "Reordered {$targetDate} docket queue (" . count($updatedItems) . " items; times preserved): {$summaryStr}";
            if (mb_strlen($auditAction) > 250) {
                $auditAction = mb_substr($auditAction, 0, 247) . '...';
            }

            $auditStmt = $this->conn->prepare("
                INSERT INTO audit_trails (user_id, action, module_name, affected_record)
                VALUES (?, ?, 'Hearings', ?)
            ");
            $firstId = (int) ($validatedItems[0]['id'] ?? 0);
            $auditStmt->execute([$userId, $auditAction, $firstId ?: null]);

            $this->conn->commit();

            return [
                'success' => true,
                'http_code' => 200,
                'no_op' => false,
                'updated_count' => count($updatedItems),
                'queue_date' => $targetDate,
                'updated_items' => $updatedItems,
                'message' => 'Daily call order updated. Official appointment times were preserved.'
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('DocketScheduleService::reorderQueue error: ' . $e->getMessage());
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'REORDER_FAILED',
                'message' => 'Unable to reorder docket queue: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Returns available slots, venues, presiding officers, and rule explanations for dynamic calendar drops / rescheduling.
     */
    public function getAvailableSlots(array $params, ?int $userId = null, ?int $roleId = null): array
    {
        if ($userId !== null) {
            $auth = $this->isAuthorizedStaff($userId, $roleId);
            if (!$auth['authorized']) {
                return [
                    'success' => false,
                    'http_code' => $auth['http_code'],
                    'message' => $auth['message']
                ];
            }
        }

        $itemType = strtolower(trim((string) ($params['item_type'] ?? ($params['type'] ?? 'hearing'))));
        $itemId = (int) ($params['item_id'] ?? ($params['id'] ?? ($params['hearing_id'] ?? 0)));
        $sessionId = (int) ($params['session_id'] ?? 0);
        $targetDate = trim((string) ($params['target_date'] ?? ($params['date'] ?? '')));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            return [
                'success' => false,
                'http_code' => 422,
                'valid_date' => false,
                'message' => 'Please provide a valid target date (YYYY-MM-DD).'
            ];
        }

        // Load current item details
        $currentItem = null;
        $hearingType = trim((string) ($params['hearing_type'] ?? 'Mediation'));
        $caseId = !empty($params['case_id']) ? (int) $params['case_id'] : null;
        $complaintId = !empty($params['complaint_id']) ? (int) $params['complaint_id'] : null;
        $defaultDuration = (int) $this->logistics->getSetting('mediation_default_duration_min', 45);

        if ($itemType === 'hearing' && $itemId > 0) {
            $hRow = $this->hearingModel->getById($itemId);
            if (!$hRow) {
                return [
                    'success' => false,
                    'http_code' => 404,
                    'message' => "Hearing #{$itemId} not found."
                ];
            }
            $elig = $this->evaluateItemEligibility($hRow, 'hearing');
            $hearingType = $hRow['hearing_type'] ?: 'Mediation';
            $caseId = (int) $hRow['case_id'];
            $complaintId = (int) ($hRow['complaint_id'] ?? 0);
            $defaultDuration = (int) ($hRow['duration_minutes'] ?: (($hearingType === 'Conciliation')
                ? (int) $this->logistics->getSetting('conciliation_default_duration_min', 60)
                : (int) $this->logistics->getSetting('mediation_default_duration_min', 45)));

            $currentItem = [
                'type' => 'hearing',
                'id' => (int) $hRow['hearing_id'],
                'case_id' => $caseId,
                'case_number' => $hRow['case_number'] ?? '',
                'complaint_id' => $complaintId,
                'complaint_title' => $hRow['complaint_title'] ?? '',
                'hearing_type' => $hearingType,
                'old_date' => substr((string) $hRow['hearing_date'], 0, 10),
                'old_time' => date('H:i', strtotime((string) $hRow['hearing_date'])),
                'old_time_formatted' => date('g:i A', strtotime((string) $hRow['hearing_date'])),
                'old_order' => max(1, (int) ($hRow['queue_order'] ?? 1)),
                'old_venue' => $hRow['venue'] ?: OfficeLogisticsService::getDefaultVenue($hearingType),
                'old_presiding_officer_id' => !empty($hRow['presiding_officer_id']) ? (int) $hRow['presiding_officer_id'] : null,
                'old_presiding_officer_name' => $hRow['presiding_officer_name'] ?: 'Punong Barangay / Presiding Officer',
                'duration_minutes' => $defaultDuration,
                'updated_at' => $hRow['updated_at'] ?? null,
                'is_locked' => $elig['is_locked'],
                'lock_reason' => $elig['lock_reason']
            ];
        } elseif ($itemType === 'paanyaya' && $itemId > 0) {
            $pStmt = $this->conn->prepare("
                SELECT p.*, co.complaint_number, co.complaint_title,
                       ps.session_id AS linked_session_id, ps.queue_status AS session_queue_status,
                       ps.actual_start_time, ps.actual_end_time, ps.session_outcome,
                       ps.complainant_attended AS session_complainant_attended,
                       ps.respondent_attended AS session_respondent_attended,
                       ps.presiding_officer_ids, ps.queue_order AS session_queue_order
                FROM paanyayas p
                LEFT JOIN complaints co ON co.complaint_id = COALESCE(NULLIF(p.complaint_id, 0), p.escalated_to_complaint_id)
                LEFT JOIN paanyaya_sessions ps ON ps.complaint_id = p.complaint_id
                     AND (ps.session_id = ? OR (? = 0 AND ps.scheduled_date = p.scheduled_date))
                WHERE p.paanyaya_id = ?
                LIMIT 1
            ");
            $pStmt->execute([$sessionId, $sessionId, $itemId]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$pRow) {
                return [
                    'success' => false,
                    'http_code' => 404,
                    'message' => "Paanyaya #{$itemId} not found."
                ];
            }
            $elig = $this->evaluateItemEligibility($pRow, 'paanyaya');
            $hearingType = 'Paanyaya';
            $complaintId = (int) ($pRow['complaint_id'] ?: ($pRow['escalated_to_complaint_id'] ?? 0));
            $defaultDuration = 30;

            $presOfficerId = null;
            $presOfficerName = 'Barangay Officer / Lupon Chairman';
            if (!empty($pRow['presiding_officer_ids'])) {
                $decodedIds = json_decode((string) $pRow['presiding_officer_ids'], true);
                if (is_array($decodedIds) && !empty($decodedIds[0])) {
                    $presOfficerId = (int) $decodedIds[0];
                } elseif (is_numeric($pRow['presiding_officer_ids'])) {
                    $presOfficerId = (int) $pRow['presiding_officer_ids'];
                }
                if ($presOfficerId) {
                    $uStmt = $this->conn->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM users WHERE user_id = ?");
                    $uStmt->execute([$presOfficerId]);
                    $presOfficerName = (string) ($uStmt->fetchColumn() ?: $presOfficerName);
                }
            }

            $oldTimeRaw = (string) ($pRow['scheduled_time'] ?: '09:00:00');
            $currentItem = [
                'type' => 'paanyaya',
                'id' => (int) $pRow['paanyaya_id'],
                'session_id' => (int) ($pRow['linked_session_id'] ?: $sessionId),
                'complaint_id' => $complaintId,
                'case_number' => $pRow['complaint_number'] ?: ('PAANYAYA-' . $pRow['paanyaya_id']),
                'complaint_title' => $pRow['complaint_title'] ?: ($pRow['salaysay'] ?: 'Paanyaya Dialogue'),
                'hearing_type' => 'Paanyaya',
                'old_date' => (string) $pRow['scheduled_date'],
                'old_time' => substr($oldTimeRaw, 0, 5),
                'old_time_formatted' => date('g:i A', strtotime($pRow['scheduled_date'] . ' ' . $oldTimeRaw)),
                'old_order' => max(1, (int) ($pRow['queue_order'] ?: ($pRow['session_queue_order'] ?? 1))),
                'old_venue' => $pRow['venue'] ?: 'Tanggapan ng Barangay Tumana',
                'old_presiding_officer_id' => $presOfficerId,
                'old_presiding_officer_name' => $presOfficerName,
                'duration_minutes' => 30,
                'updated_at' => $pRow['updated_at'] ?? null,
                'is_locked' => $elig['is_locked'],
                'lock_reason' => $elig['lock_reason']
            ];
        }

        $venue = trim((string) ($params['venue'] ?? ($currentItem['old_venue'] ?? OfficeLogisticsService::getDefaultVenue($hearingType))));
        $presidingOfficerId = !empty($params['presiding_officer_id'])
            ? (int) $params['presiding_officer_id']
            : ($currentItem['old_presiding_officer_id'] ?? null);
        $durationMinutes = !empty($params['duration_minutes'])
            ? max(15, (int) $params['duration_minutes'])
            : $defaultDuration;

        // Evaluate date validity from settings & logistics
        $targetObj = new DateTimeImmutable($targetDate);
        $todayObj = new DateTimeImmutable(date('Y-m-d'));
        $dayOfWeek = (int) $targetObj->format('w'); // 0 = Sun .. 6 = Sat
        $dayCode = $targetObj->format('D');
        $holidayName = $this->logistics->isPhilippineHoliday($targetObj);
        $isHoliday = ($holidayName !== null);
        $isPastDate = ($targetObj < $todayObj);

        $workStart = (string) $this->logistics->getSetting('working_hours_start', '08:00');
        $workEnd = (string) $this->logistics->getSetting('working_hours_end', '17:00');
        $medHoursStart = (string) $this->logistics->getSetting('mediation_hours_start', '13:00');
        $medHoursEnd = (string) $this->logistics->getSetting('mediation_hours_end', '16:30');

        $rawMedDays = $this->logistics->getSetting('mediation_day_of_week', 'Tue');
        $allowedMedDays = [];
        if (is_string($rawMedDays)) {
            $dec = json_decode($rawMedDays, true);
            $allowedMedDays = is_array($dec) ? $dec : array_filter(array_map('trim', explode(',', $rawMedDays)));
        } elseif (is_array($rawMedDays)) {
            $allowedMedDays = $rawMedDays;
        }
        if (empty($allowedMedDays)) {
            $allowedMedDays = ['Tue'];
        }
        $pluralMap = [
            'Mon' => 'Mondays', 'Tue' => 'Tuesdays', 'Wed' => 'Wednesdays',
            'Thu' => 'Thursdays', 'Fri' => 'Fridays', 'Sat' => 'Saturdays', 'Sun' => 'Sundays'
        ];
        $medDaysLabel = implode(', ', array_map(fn($d) => $pluralMap[$d] ?? $d, $allowedMedDays));
        $isMedDay = in_array($dayCode, $allowedMedDays, true);

        $validDate = true;
        $dateReason = '';
        $ruleHint = '';
        $candidateTimes = [];

        if ($isPastDate) {
            $validDate = false;
            $dateReason = 'Appointments cannot be rescheduled to a past date.';
        } elseif ($isHoliday) {
            $validDate = false;
            $dateReason = "Scheduling is blocked on {$targetObj->format('F j, Y')}: Official / configured holiday ({$holidayName}).";
        } elseif ($dayOfWeek === 0 || ($dayOfWeek === 6 && !($hearingType === 'Mediation' && $isMedDay))) {
            $validDate = false;
            $dateReason = 'Sessions cannot be scheduled on weekends.';
        } elseif ($itemType === 'hearing' && in_array($hearingType, ['Mediation', 'Initial Hearing'], true)) {
            $startFmt = date('g:i A', strtotime($medHoursStart));
            $endFmt = date('g:i A', strtotime($medHoursEnd));
            $ruleHint = "Formal Mediation sessions are held on designated mediation days ({$medDaysLabel}, {$startFmt} – {$endFmt}).";
            if (!$isMedDay) {
                $validDate = false;
                $dateReason = "Mediation hearings can only be scheduled on designated mediation days ({$medDaysLabel}).";
            } else {
                $curTs = strtotime("{$targetDate} {$medHoursStart}:00");
                $endTs = strtotime("{$targetDate} {$medHoursEnd}:00");
                while ($curTs !== false && $endTs !== false && $curTs <= $endTs) {
                    $tStr = date('H:i', $curTs);
                    if ($tStr < '12:00' || $tStr >= '13:00') {
                        $candidateTimes[] = $tStr;
                    }
                    $curTs = strtotime('+30 minutes', $curTs);
                }
            }
        } elseif ($itemType === 'hearing' && $hearingType === 'Conciliation') {
            $ruleHint = 'Conciliation hearings before the Pangkat are held on Mondays, Wednesdays, Thursdays, and Fridays (8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM).';
            if ($dayOfWeek === 2) {
                $validDate = false;
                $dateReason = 'Conciliation hearings cannot be scheduled on Tuesdays; Tuesdays are reserved for formal mediation.';
            } else {
                $curTs = strtotime("{$targetDate} {$workStart}:00");
                $endTs = strtotime("-{$durationMinutes} minutes", strtotime("{$targetDate} {$workEnd}:00"));
                while ($curTs !== false && $endTs !== false && $curTs <= $endTs) {
                    $tStr = date('H:i', $curTs);
                    $slotEndStr = date('H:i', strtotime("+{$durationMinutes} minutes", $curTs));
                    if (($tStr < '12:00' && $slotEndStr <= '12:00') || ($tStr >= '13:00' && $slotEndStr <= $workEnd)) {
                        $candidateTimes[] = $tStr;
                    }
                    $curTs = strtotime('+30 minutes', $curTs);
                }
            }
        } else {
            // Paanyaya or Arbitration / other
            $ruleHint = "Operating hours: {$workStart} – 12:00 & 13:00 – {$workEnd} (excluding 12:00 PM – 1:00 PM lunch break).";
            $curTs = strtotime("{$targetDate} {$workStart}:00");
            $endTs = strtotime("-{$durationMinutes} minutes", strtotime("{$targetDate} {$workEnd}:00"));
            while ($curTs !== false && $endTs !== false && $curTs <= $endTs) {
                $tStr = date('H:i', $curTs);
                $slotEndStr = date('H:i', strtotime("+{$durationMinutes} minutes", $curTs));
                if (($tStr < '12:00' && $slotEndStr <= '12:00') || ($tStr >= '13:00' && $slotEndStr <= $workEnd)) {
                    $candidateTimes[] = $tStr;
                }
                $curTs = strtotime('+30 minutes', $curTs);
            }
        }

        // Count existing active items on targetDate to compute proposed queue order
        $excludeHearingId = ($itemType === 'hearing' && $itemId > 0) ? $itemId : 0;
        $excludePaanyayaId = ($itemType === 'paanyaya' && $itemId > 0) ? $itemId : 0;

        $hCountStmt = $this->conn->prepare("
            SELECT hearing_id, hearing_date, end_time, duration_minutes, venue, COALESCE(queue_order, 0) AS queue_order
            FROM hearings
            WHERE DATE(hearing_date) = ?
              AND status NOT IN ('Cancelled', 'Office Cancelled', 'Completed', 'Rescheduled')
              AND COALESCE(queue_status, 'Waiting') <> 'Concluded'
              AND hearing_id <> ?
        ");
        $hCountStmt->execute([$targetDate, $excludeHearingId]);
        $existingDayHearings = $hCountStmt->fetchAll(PDO::FETCH_ASSOC);

        $pCountStmt = $this->conn->prepare("
            SELECT paanyaya_id, scheduled_date, scheduled_time, venue, COALESCE(queue_order, 0) AS queue_order
            FROM paanyayas
            WHERE scheduled_date = ?
              AND status NOT IN ('Cancelled', 'Completed', 'Resolved', 'Unresolved', 'Escalated')
              AND paanyaya_id <> ?
        ");
        $pCountStmt->execute([$targetDate, $excludePaanyayaId]);
        $existingDayPaanyayas = $pCountStmt->fetchAll(PDO::FETCH_ASSOC);

        $maxOrder = 0;
        foreach ($existingDayHearings as $eh) {
            $maxOrder = max($maxOrder, (int) $eh['queue_order']);
        }
        foreach ($existingDayPaanyayas as $ep) {
            $maxOrder = max($maxOrder, (int) $ep['queue_order']);
        }
        $totalExistingOnDay = count($existingDayHearings) + count($existingDayPaanyayas);
        $proposedOrder = ($currentItem && $currentItem['old_date'] === $targetDate)
            ? (int) $currentItem['old_order']
            : max($totalExistingOnDay + 1, $maxOrder + 1);

        $slots = [];
        $nowTs = time();

        foreach ($candidateTimes as $tStr) {
            $slotStartDateTime = "{$targetDate} {$tStr}:00";
            $slotStartTs = strtotime($slotStartDateTime);
            $slotEndDateTime = date('Y-m-d H:i:s', strtotime("+{$durationMinutes} minutes", $slotStartTs));
            $casesAtSlot = 0;

            foreach ($existingDayHearings as $eh) {
                if (date('H:i', strtotime((string) $eh['hearing_date'])) === $tStr) {
                    $casesAtSlot++;
                }
            }
            foreach ($existingDayPaanyayas as $ep) {
                if (substr((string) ($ep['scheduled_time'] ?? ''), 0, 5) === $tStr) {
                    $casesAtSlot++;
                }
            }

            $isAvailable = $validDate;
            $unavailableReason = $validDate ? null : $dateReason;

            if ($isAvailable && $targetDate === date('Y-m-d') && $slotStartTs <= $nowTs) {
                $isAvailable = false;
                $unavailableReason = 'This time slot has already passed today.';
            }

            if ($isAvailable) {
                if ($itemType === 'hearing') {
                    $schedCheck = $this->logistics->validateHearingSchedule($slotStartDateTime, $durationMinutes, $hearingType);
                    if (!$schedCheck['valid']) {
                        $isAvailable = false;
                        $unavailableReason = $schedCheck['message'];
                    } else {
                        $conflict = $this->hearingModel->findConflict(
                            $slotStartDateTime,
                            $venue,
                            $caseId,
                            $excludeHearingId ?: null,
                            $presidingOfficerId,
                            $durationMinutes,
                            $hearingType
                        );
                        if ($conflict && !empty($conflict['conflict'])) {
                            $isAvailable = false;
                            $unavailableReason = $conflict['message'] ?? 'Schedule conflict at this slot.';
                        }
                    }
                } else {
                    // Paanyaya venue and officer check
                    $venueCheck = $this->logistics->validateVenueAvailability($venue, $slotStartDateTime, $slotEndDateTime, null);
                    if (!$venueCheck['available']) {
                        $isAvailable = false;
                        $unavailableReason = $venueCheck['message'];
                    } elseif ($presidingOfficerId) {
                        $presCheck = $this->logistics->validateMemberAvailability($presidingOfficerId, $slotStartDateTime, $slotEndDateTime, null, 'Presiding Officer');
                        if (!$presCheck['available']) {
                            $isAvailable = false;
                            $unavailableReason = $presCheck['message'];
                        }
                    }
                }
            }

            $label = date('g:i A', $slotStartTs);
            if ($casesAtSlot > 0) {
                $label .= " ({$casesAtSlot} scheduled)";
            }

            $slots[] = [
                'time' => $tStr,
                'label' => $label,
                'available' => $isAvailable,
                'booked' => !$isAvailable,
                'cases_at_slot' => $casesAtSlot,
                'reason' => $unavailableReason,
                'conflict' => $unavailableReason
            ];
        }

        // Load configured venues & active presiding officers from database
        $defaultConfiguredVenue = (string) $this->logistics->getSetting('default_hearing_venue', OfficeLogisticsService::getDefaultVenue($hearingType));
        $venues = array_filter([
            $venue,
            $defaultConfiguredVenue,
            OfficeLogisticsService::getDefaultVenue($hearingType),
        ]);
        try {
            $vStmt = $this->conn->query("
                SELECT DISTINCT TRIM(venue) AS venue FROM hearings WHERE venue IS NOT NULL AND TRIM(venue) <> ''
                UNION
                SELECT DISTINCT TRIM(venue) AS venue FROM paanyayas WHERE venue IS NOT NULL AND TRIM(venue) <> ''
                LIMIT 10
            ");
            if ($vStmt) {
                $venues = array_merge($venues, $vStmt->fetchAll(PDO::FETCH_COLUMN));
            }
        } catch (Throwable) {}
        $venues = array_values(array_unique(array_filter(array_map('trim', $venues))));

        $officersStmt = $this->conn->query("
            SELECT u.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS full_name, r.role_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.status = 'Active'
              AND r.role_name IN ('Administrator', 'Lupon Clerk', 'Lupon Member')
            ORDER BY r.role_id ASC, u.last_name ASC, u.first_name ASC
        ");
        $officers = $officersStmt ? $officersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            'success' => true,
            'http_code' => 200,
            'date' => $targetDate,
            'formatted_date' => $targetObj->format('F j, Y (l)'),
            'day_of_week' => $dayOfWeek,
            'is_valid_day' => $validDate,
            'valid_date' => $validDate,
            'date_valid' => $validDate,
            'date_reason' => $dateReason ?: null,
            'is_holiday' => $isHoliday,
            'holiday_name' => $holidayName,
            'hearing_type' => $hearingType,
            'hint' => $ruleHint,
            'scheduled_count' => $totalExistingOnDay,
            'proposed_order' => $proposedOrder,
            'default_venue' => $venue,
            'venues' => $venues,
            'presiding_officers' => $officers,
            'current_item' => $currentItem,
            'slots' => $slots,
            'message' => $validDate
                ? ($ruleHint ?: 'Select an available time slot.')
                : ($dateReason ?: 'The selected date is not available for scheduling.')
        ];
    }

    /**
     * Formally reschedules a Hearing or Paanyaya appointment (cross-date or time/venue/officer change).
     * Requires explicit confirmation and reason, validates all scheduling rules, reconciles all authoritative
     * schedule tables, regenerates/supersedes notices, notifies affected parties, and writes detailed audit/history.
     */
    public function rescheduleItem(array $data, int $userId, ?int $roleId = null): array
    {
        $effectiveRoleId = $roleId ?? (isset($_SESSION['role_id']) ? (int) $_SESSION['role_id'] : null);
        $auth = $this->isAuthorizedStaff($userId, $effectiveRoleId);
        if (!$auth['authorized']) {
            return [
                'success' => false,
                'http_code' => $auth['http_code'],
                'code' => 'UNAUTHORIZED',
                'message' => $auth['message']
            ];
        }
        $effectiveRoleId = (int) $auth['role_id'];

        $confirmed = filter_var($data['confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$confirmed) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'CONFIRMATION_REQUIRED',
                'message' => 'Formal rescheduling requires explicit staff confirmation.'
            ];
        }

        $reason = trim((string) ($data['reschedule_reason'] ?? ($data['reason'] ?? '')));
        if ($reason === '' || mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'REASON_REQUIRED',
                'message' => 'A valid rescheduling reason is required (3 to 2,000 characters).'
            ];
        }

        $itemType = strtolower(trim((string) ($data['item_type'] ?? ($data['type'] ?? 'hearing'))));
        $itemId = (int) ($data['item_id'] ?? ($data['id'] ?? ($data['hearing_id'] ?? ($data['paanyaya_id'] ?? 0))));
        $sessionId = (int) ($data['session_id'] ?? 0);
        $submittedComplaintId = isset($data['complaint_id']) && $data['complaint_id'] !== '' ? (int) $data['complaint_id'] : null;

        if (!in_array($itemType, ['hearing', 'paanyaya'], true) || $itemId < 1) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'INVALID_ITEM',
                'message' => 'Provide a valid item type (hearing or paanyaya) and record ID.'
            ];
        }

        $targetDate = trim((string) ($data['target_date'] ?? ($data['hearing_date'] ?? ($data['scheduled_date'] ?? ''))));
        $targetTime = trim((string) ($data['target_time'] ?? ($data['hearing_time'] ?? ($data['scheduled_time'] ?? ($data['time'] ?? '')))));

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2}(?::\d{2})?)$/', $targetDate, $dtMatches)) {
            $targetDate = $dtMatches[1];
            if ($targetTime === '') {
                $targetTime = $dtMatches[2];
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'INVALID_DATE',
                'message' => 'Provide a valid target date (YYYY-MM-DD).'
            ];
        }

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $targetTime)) {
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'INVALID_TIME',
                'message' => 'Select a valid appointment time slot (HH:MM).'
            ];
        }

        $normalizedTime = strlen($targetTime) === 5 ? $targetTime . ':00' : $targetTime;
        $targetDateTimeStr = "{$targetDate} {$normalizedTime}";

        if ($itemType === 'hearing') {
            return $this->rescheduleHearing($itemId, $targetDate, $normalizedTime, $targetDateTimeStr, $reason, $data, $userId, $effectiveRoleId);
        }

        return $this->reschedulePaanyaya($itemId, $sessionId, $submittedComplaintId, $targetDate, $normalizedTime, $targetDateTimeStr, $reason, $data, $userId);
    }

    /**
     * Handles formal rescheduling for a Hearing record under transaction and row locks.
     */
    private function rescheduleHearing(
        int $hearingId,
        string $targetDate,
        string $normalizedTime,
        string $targetDateTimeStr,
        string $reason,
        array $data,
        int $userId,
        int $roleId
    ): array {
        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("
                SELECT h.*, c.case_number, c.case_status, c.complaint_id, co.complaint_number, co.complaint_title, co.submitted_by,
                       TRIM(CONCAT_WS(' ', po.first_name, po.middle_name, po.last_name)) AS presiding_officer_name
                FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                INNER JOIN complaints co ON co.complaint_id = c.complaint_id
                LEFT JOIN users po ON po.user_id = h.presiding_officer_id
                WHERE h.hearing_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$hearingId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 404,
                    'code' => 'NOT_FOUND',
                    'message' => "Hearing #{$hearingId} not found."
                ];
            }

            // Concurrency / Stale payload guard
            if (!empty($data['expected_updated_at']) && (string) $data['expected_updated_at'] !== (string) ($existing['updated_at'] ?? '')) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 409,
                    'code' => 'STALE_PAYLOAD',
                    'message' => 'This hearing was modified by another session. Reload the docket and try again.'
                ];
            }
            if (!empty($data['expected_hearing_date'])) {
                $expDt = date('Y-m-d H:i', strtotime((string) $data['expected_hearing_date']));
                $curDt = date('Y-m-d H:i', strtotime((string) $existing['hearing_date']));
                if ($expDt !== $curDt) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'http_code' => 409,
                        'code' => 'STALE_PAYLOAD',
                        'message' => 'This hearing schedule was already changed by another user. Reload the docket.'
                    ];
                }
            }

            $elig = $this->evaluateItemEligibility($existing, 'hearing');
            if (!$elig['eligible']) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => $elig['lock_code'] ?? 'ITEM_LOCKED',
                    'message' => $elig['lock_reason'] ?? 'This hearing is locked and cannot be rescheduled.'
                ];
            }

            $caseId = (int) $existing['case_id'];
            $complaintId = (int) $existing['complaint_id'];
            $hearingType = (string) ($existing['hearing_type'] ?: 'Mediation');
            $oldDateTime = (string) $existing['hearing_date'];
            $oldDate = substr($oldDateTime, 0, 10);
            $oldTime = date('H:i', strtotime($oldDateTime));
            $oldOrder = max(1, (int) ($existing['queue_order'] ?: 1));
            $oldVenue = (string) ($existing['venue'] ?: OfficeLogisticsService::getDefaultVenue($hearingType));
            $oldPresidingOfficerId = !empty($existing['presiding_officer_id']) ? (int) $existing['presiding_officer_id'] : null;
            $oldPresidingOfficerName = (string) ($existing['presiding_officer_name'] ?: 'Punong Barangay / Presiding Officer');

            $newVenue = trim((string) ($data['venue'] ?? $oldVenue));
            if ($newVenue === '') {
                $newVenue = $oldVenue ?: OfficeLogisticsService::getDefaultVenue($hearingType);
            }
            if (mb_strlen($newVenue) > 255) {
                $this->conn->rollBack();
                return ['success' => false, 'http_code' => 422, 'message' => 'Venue must not exceed 255 characters.'];
            }

            $newPresidingOfficerId = array_key_exists('presiding_officer_id', $data) && $data['presiding_officer_id'] !== '' && $data['presiding_officer_id'] !== null
                ? (int) $data['presiding_officer_id']
                : $oldPresidingOfficerId;

            $durationMin = !empty($data['duration_minutes'])
                ? (int) $data['duration_minutes']
                : (!empty($existing['duration_minutes']) ? (int) $existing['duration_minutes'] : (($hearingType === 'Conciliation')
                    ? (int) $this->logistics->getSetting('conciliation_default_duration_min', 60)
                    : (int) $this->logistics->getSetting('mediation_default_duration_min', 45)));

            // Reject unchanged schedule
            if ($oldDate === $targetDate
                && $oldTime === substr($normalizedTime, 0, 5)
                && $oldVenue === $newVenue
                && $oldPresidingOfficerId === $newPresidingOfficerId) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'UNCHANGED_SCHEDULE',
                    'message' => 'The proposed date, time, venue, and presiding officer are identical to the current schedule.'
                ];
            }

            // Past date check (unless Administrator past-date exception is provided)
            $targetDtObj = new DateTimeImmutable($targetDateTimeStr);
            $nowObj = new DateTimeImmutable('now');
            $pastExceptionReason = trim((string) ($data['past_date_exception_reason'] ?? ''));
            if ($targetDtObj <= $nowObj) {
                if ($roleId !== 1 || mb_strlen($pastExceptionReason) < 10) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'http_code' => 422,
                        'code' => 'PAST_DATE_BLOCKED',
                        'message' => 'Hearings must be rescheduled to a future date and time.'
                    ];
                }
            }

            // Validate operating hours, mediation day rules, holidays, lunch break
            $hoursValidation = $this->logistics->validateHearingSchedule(
                $targetDateTimeStr,
                $data['end_time'] ?? $durationMin,
                $hearingType,
                $data['duration_exceed_reason'] ?? null
            );
            if (!$hoursValidation['valid']) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'SCHEDULE_RULE_VIOLATION',
                    'message' => $hoursValidation['message']
                ];
            }

            $startTime = $hoursValidation['start_time'];
            $endTime = $hoursValidation['end_time'];
            $durationMin = (int) $hoursValidation['duration_minutes'];

            // Conciliation Tuesday restriction (matches HearingController::validate)
            if ($hearingType === 'Conciliation' && (int) date('w', strtotime($startTime)) === 2) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'CONCILIATION_TUESDAY_BLOCKED',
                    'message' => 'Conciliation hearings cannot be scheduled on Tuesdays; select Monday, Wednesday, Thursday, or Friday.'
                ];
            }

            // Party reschedule limit check if requested by a party
            $partyType = trim((string) ($data['rescheduled_by_party'] ?? 'Office'));
            if (in_array($partyType, ['Complainant', 'Respondent'], true) && empty($data['force_reschedule'])) {
                $partyLimit = $this->logistics->checkPartyRescheduleLimit($caseId, $partyType);
                if ($partyLimit['exceeded']) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'http_code' => 422,
                        'code' => 'PARTY_RESCHEDULE_LIMIT',
                        'message' => $partyLimit['message']
                    ];
                }
            }

            // Same-day progression check against other hearings of the same case
            $dateProgression = $this->hearingModel->validateHearingDateProgression($caseId, $startTime, $hearingId, $hearingType);
            if (!$dateProgression['valid'] && !empty($dateProgression['same_day'])) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'SAME_DAY_CASE_CONFLICT',
                    'message' => $dateProgression['message']
                ];
            }

            // Lock target date hearings to prevent concurrent slot conflicts and compute new queue_order
            $targetDayLock = $this->conn->prepare("
                SELECT hearing_id, COALESCE(queue_order, 0) AS queue_order
                FROM hearings
                WHERE DATE(hearing_date) = ?
                  AND status NOT IN ('Cancelled', 'Office Cancelled', 'Completed', 'Rescheduled')
                  AND COALESCE(queue_status, 'Waiting') <> 'Concluded'
                  AND hearing_id <> ?
                FOR UPDATE
            ");
            $targetDayLock->execute([$targetDate, $hearingId]);
            $targetDayRows = $targetDayLock->fetchAll(PDO::FETCH_ASSOC);

            $targetPaanyayaLock = $this->conn->prepare("
                SELECT paanyaya_id, COALESCE(queue_order, 0) AS queue_order
                FROM paanyayas
                WHERE scheduled_date = ?
                  AND status NOT IN ('Cancelled', 'Completed', 'Resolved', 'Unresolved', 'Escalated')
                FOR UPDATE
            ");
            $targetPaanyayaLock->execute([$targetDate]);
            $targetPaanyayaRows = $targetPaanyayaLock->fetchAll(PDO::FETCH_ASSOC);

            // Conflict check (venue, presiding officer, case officers, duplicate hearing)
            $conflict = $this->hearingModel->findConflict(
                $startTime,
                $newVenue,
                $caseId,
                $hearingId,
                $newPresidingOfficerId,
                $durationMin,
                $hearingType,
                !empty($existing['mediation_attempt']) ? (int) $existing['mediation_attempt'] : null
            );
            if ($conflict && !empty($conflict['conflict'])) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 409,
                    'code' => 'SCHEDULE_CONFLICT',
                    'message' => $conflict['message'] ?? 'The requested date and time conflicts with another scheduled session.',
                    'alternative_slots' => $conflict['alternative_slots'] ?? []
                ];
            }

            // Compute new queue_order on targetDate
            if ($oldDate === $targetDate && empty($data['proposed_order'])) {
                $newOrder = $oldOrder;
            } else {
                $maxOrder = 0;
                foreach ($targetDayRows as $r) {
                    $maxOrder = max($maxOrder, (int) $r['queue_order']);
                }
                foreach ($targetPaanyayaRows as $r) {
                    $maxOrder = max($maxOrder, (int) $r['queue_order']);
                }
                $totalOnDay = count($targetDayRows) + count($targetPaanyayaRows);
                $newOrder = !empty($data['proposed_order'])
                    ? max(1, (int) $data['proposed_order'])
                    : max($totalOnDay + 1, $maxOrder + 1);
            }

            // Resolve new presiding officer name
            $newPresidingOfficerName = 'Punong Barangay / Presiding Officer';
            if ($newPresidingOfficerId) {
                $poStmt = $this->conn->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) FROM users WHERE user_id = ?");
                $poStmt->execute([$newPresidingOfficerId]);
                $poName = trim((string) $poStmt->fetchColumn());
                if ($poName !== '') {
                    $newPresidingOfficerName = $poName;
                }
            }

            // Update authoritative hearings row
            $updHearing = $this->conn->prepare("
                UPDATE hearings
                SET hearing_date = ?,
                    end_time = ?,
                    duration_minutes = ?,
                    duration_exceed_reason = ?,
                    venue = ?,
                    presiding_officer_id = ?,
                    reschedule_reason = ?,
                    rescheduled_by_party = ?,
                    reschedule_justification_category = ?,
                    reschedule_approved_by = ?,
                    reschedule_approved_at = NOW(),
                    queue_order = ?,
                    updated_at = NOW()
                WHERE hearing_id = ?
            ");
            $updHearing->execute([
                $startTime,
                $endTime,
                $durationMin,
                !empty($data['duration_exceed_reason']) ? trim((string) $data['duration_exceed_reason']) : ($existing['duration_exceed_reason'] ?? null),
                $newVenue,
                $newPresidingOfficerId,
                $reason,
                $partyType ?: 'Office',
                !empty($data['reschedule_justification_category']) ? trim((string) $data['reschedule_justification_category']) : null,
                $userId,
                $newOrder,
                $hearingId
            ]);

            // Update case_deadlines if applicable
            $deadline = DeadlineService::deadlineForHearing($hearingType, $startTime);
            if ($deadline !== null && $hearingType !== 'Conciliation') {
                $dlCheck = $this->conn->prepare("
                    SELECT deadline_id FROM case_deadlines
                    WHERE case_id = ? AND deadline_type = ? AND status <> 'Completed'
                    ORDER BY deadline_id DESC LIMIT 1
                ");
                $dlCheck->execute([$caseId, $deadline['deadline_type']]);
                $dlId = $dlCheck->fetchColumn();
                if ($dlId) {
                    $this->conn->prepare("UPDATE case_deadlines SET due_date = ?, updated_at = CURRENT_TIMESTAMP WHERE deadline_id = ?")
                        ->execute([$deadline['due_date'], $dlId]);
                } else {
                    $this->conn->prepare("INSERT INTO case_deadlines (case_id, deadline_type, due_date, status) VALUES (?, ?, ?, 'Pending')")
                        ->execute([$caseId, $deadline['deadline_type'], $deadline['due_date']]);
                }
            }

            $oldFmt = date('M j, Y g:i A', strtotime($oldDateTime));
            $newFmt = date('M j, Y g:i A', strtotime($startTime));
            $supersededNote = "Superseded by reschedule from {$oldFmt} ({$oldVenue}) to {$newFmt} ({$newVenue}). Reason: {$reason}";

            // Supersede conflicting summon_deliveries and generated_documents for this hearing, and issue fresh ones
            $existingDeliveriesStmt = $this->conn->prepare("
                SELECT * FROM summon_deliveries
                WHERE hearing_id = ? AND delivery_status <> 'Superseded'
                ORDER BY delivery_id ASC
                FOR UPDATE
            ");
            $existingDeliveriesStmt->execute([$hearingId]);
            $existingDeliveries = $existingDeliveriesStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($existingDeliveries)) {
                $supDelivStmt = $this->conn->prepare("
                    UPDATE summon_deliveries
                    SET delivery_status = 'Superseded',
                        remarks = TRIM(CONCAT(COALESCE(remarks, ''), ' [', ?, ']'))
                    WHERE delivery_id = ?
                ");
                $supDocStmt = $this->conn->prepare("
                    UPDATE generated_documents
                    SET regeneration_reason = ?,
                        file_path = ''
                    WHERE document_id = ?
                ");
                $insNewDelivStmt = $this->conn->prepare("
                    INSERT INTO summon_deliveries (
                        hearing_id, case_id, party_type, resident_id, form_type, attempt_number,
                        delivery_status, recipient_name, document_id, created_by, remarks
                    ) VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?)
                ");

                // Create regenerated document record if linked
                $tStmt = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = ? LIMIT 1");
                $tStmt->execute([$hearingType === 'Conciliation' ? 'KP Form 12' : 'KP Form 9']);
                $templateId = (int) ($tStmt->fetchColumn() ?: 3);

                $insDocStmt = $this->conn->prepare("
                    INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status, regeneration_reason)
                    VALUES (?, ?, ?, '', 'For Service', ?)
                ");
                $insDocStmt->execute([$caseId, $templateId, $userId, "Rescheduled from {$oldFmt} to {$newFmt}: {$reason}"]);
                $newDocId = (int) $this->conn->lastInsertId();

                foreach ($existingDeliveries as $ed) {
                    $supDelivStmt->execute([$supersededNote, (int) $ed['delivery_id']]);
                    if (!empty($ed['document_id'])) {
                        $supDocStmt->execute([$supersededNote, (int) $ed['document_id']]);
                    }
                    $nextAttempt = max(1, (int) ($ed['attempt_number'] ?? 1)) + 1;
                    $newFormType = ($ed['form_type'] === 'KP Form 12') ? 'KP Form 12' : 'Notice of Rescheduled Hearing';
                    $insNewDelivStmt->execute([
                        $hearingId,
                        $caseId,
                        $ed['party_type'],
                        $ed['resident_id'] ?: null,
                        $newFormType,
                        $nextAttempt,
                        $ed['recipient_name'],
                        $newDocId,
                        $userId,
                        "Rescheduled hearing notice ({$newFmt} at {$newVenue}). Reason: {$reason}"
                    ]);
                }
            } else {
                // Also mark any case-level generated_documents for KP Form 8/9/12 with regeneration_reason
                $this->conn->prepare("
                    UPDATE generated_documents gd
                    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                    SET gd.regeneration_reason = ?, gd.file_path = ''
                    WHERE gd.case_id = ? AND dt.template_name IN ('KP Form 8', 'KP Form 9', 'KP Form 12')
                ")->execute([$supersededNote, $caseId]);
            }

            // Record detailed case_history entry with old and new date, time, order, venue, presiding officer, and reason
            $historyRemarks = sprintf(
                'Hearing #%d (%s) rescheduled: Date/Time %s -> %s | Queue Order #%d -> #%d | Venue %s -> %s | Presiding Officer %s -> %s | Reason: %s',
                $hearingId,
                $hearingType,
                $oldFmt,
                $newFmt,
                $oldOrder,
                $newOrder,
                $oldVenue,
                $newVenue,
                $oldPresidingOfficerName,
                $newPresidingOfficerName,
                $reason
            );
            $histStmt = $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, ?, ?, ?)
            ");
            $histStmt->execute([$caseId, $existing['case_status'] ?: 'Mediation', $historyRemarks, $userId]);

            // Record detailed audit_trails entry (<= 255 chars for VARCHAR(255))
            $auditAction = sprintf(
                'Rescheduled Hearing #%d (%s): %s %s (#%d, %s, Officer:%s) -> %s %s (#%d, %s, Officer:%s). Reason: %s',
                $hearingId,
                $existing['case_number'] ?? "Case#{$caseId}",
                $oldDate,
                $oldTime,
                $oldOrder,
                $oldVenue,
                $oldPresidingOfficerId ?: 'Default',
                $targetDate,
                substr($normalizedTime, 0, 5),
                $newOrder,
                $newVenue,
                $newPresidingOfficerId ?: 'Default',
                $reason
            );
            if (mb_strlen($auditAction) > 250) {
                $auditAction = mb_substr($auditAction, 0, 247) . '...';
            }
            $this->conn->prepare("
                INSERT INTO audit_trails (user_id, action, module_name, affected_record)
                VALUES (?, ?, 'Hearings', ?)
            ")->execute([$userId, $auditAction, $hearingId]);

            $this->conn->commit();

            // Notify case members, presiding officer, and linked resident accounts
            $notifTitle = "Hearing Rescheduled: " . ($existing['case_number'] ?? "Case #{$caseId}");
            $notifMessage = sprintf(
                '%s for %s was rescheduled from %s (%s, Order #%d) to %s (%s, Order #%d). Presiding Officer: %s. Reason: %s',
                $hearingType,
                $existing['case_number'] ?? "Case #{$caseId}",
                $oldFmt,
                $oldVenue,
                $oldOrder,
                $newFmt,
                $newVenue,
                $newOrder,
                $newPresidingOfficerName,
                $reason
            );
            $this->notifications->notifyCaseMembers($caseId, $notifTitle, $notifMessage, $userId, 'warning');
            $this->notifications->notifyRoles(['Administrator', 'Lupon Clerk'], $notifTitle, $notifMessage, $userId, 'info', "../cases/case-details.php?id={$caseId}", $caseId);
            if ($newPresidingOfficerId && $newPresidingOfficerId !== $userId) {
                $this->notifications->notifyUser($newPresidingOfficerId, $notifTitle, $notifMessage, $userId, 'info', "../cases/case-details.php?id={$caseId}", $caseId);
            }
            if (!empty($existing['submitted_by'])) {
                $this->notifications->notifyUser((int) $existing['submitted_by'], $notifTitle, $notifMessage, $userId, 'info', "../complaints/complaint-details.php?id={$complaintId}", $caseId);
            }

            return [
                'success' => true,
                'http_code' => 200,
                'item_type' => 'hearing',
                'hearing_id' => $hearingId,
                'case_id' => $caseId,
                'old_schedule' => [
                    'date' => $oldDate,
                    'time' => $oldTime,
                    'datetime' => $oldDateTime,
                    'formatted' => $oldFmt,
                    'queue_order' => $oldOrder,
                    'venue' => $oldVenue,
                    'presiding_officer_id' => $oldPresidingOfficerId,
                    'presiding_officer_name' => $oldPresidingOfficerName
                ],
                'new_schedule' => [
                    'date' => $targetDate,
                    'time' => substr($normalizedTime, 0, 5),
                    'datetime' => $startTime,
                    'end_time' => $endTime,
                    'formatted' => $newFmt,
                    'queue_order' => $newOrder,
                    'venue' => $newVenue,
                    'presiding_officer_id' => $newPresidingOfficerId,
                    'presiding_officer_name' => $newPresidingOfficerName
                ],
                'reason' => $reason,
                'message' => "Hearing successfully rescheduled from {$oldFmt} to {$newFmt}."
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('DocketScheduleService::rescheduleHearing error: ' . $e->getMessage());
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'RESCHEDULE_FAILED',
                'message' => 'Unable to reschedule hearing: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Handles formal rescheduling for a Paanyaya record across all 5 Paanyaya tables
     * (paanyayas, paanyaya_sessions, paanyaya_preparations, paanyaya_service_attempts, paanyaya_stages).
     */
    private function reschedulePaanyaya(
        int $paanyayaId,
        int $sessionId,
        ?int $submittedComplaintId,
        string $targetDate,
        string $normalizedTime,
        string $targetDateTimeStr,
        string $reason,
        array $data,
        int $userId
    ): array {
        try {
            $this->conn->beginTransaction();

            $pStmt = $this->conn->prepare("
                SELECT p.*, co.complaint_number, co.complaint_title, co.submitted_by
                FROM paanyayas p
                LEFT JOIN complaints co ON co.complaint_id = COALESCE(NULLIF(p.complaint_id, 0), p.escalated_to_complaint_id)
                WHERE p.paanyaya_id = ?
                FOR UPDATE
            ");
            $pStmt->execute([$paanyayaId]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

            if (!$pRow) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 404,
                    'code' => 'NOT_FOUND',
                    'message' => "Paanyaya #{$paanyayaId} not found."
                ];
            }

            $complaintId = (int) ($pRow['complaint_id'] ?: ($pRow['escalated_to_complaint_id'] ?? 0));
            if ($submittedComplaintId !== null && $submittedComplaintId > 0 && $complaintId !== $submittedComplaintId) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'PAANYAYA_COMPLAINT_MISMATCH',
                    'message' => "Paanyaya #{$paanyayaId} does not belong to complaint #{$submittedComplaintId}."
                ];
            }

            $attemptNumber = max(1, (int) ($pRow['attempt_number'] ?? 1));
            $linkedSession = null;

            if ($sessionId > 0) {
                $psStmt = $this->conn->prepare("SELECT * FROM paanyaya_sessions WHERE session_id = ? FOR UPDATE");
                $psStmt->execute([$sessionId]);
                $linkedSession = $psStmt->fetch(PDO::FETCH_ASSOC);
                if (!$linkedSession || (int) ($linkedSession['complaint_id'] ?? 0) !== $complaintId) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'http_code' => 422,
                        'code' => 'PAANYAYA_SESSION_MISMATCH',
                        'message' => "Paanyaya session #{$sessionId} does not belong to complaint #{$complaintId} and Paanyaya #{$paanyayaId}."
                    ];
                }
            } elseif ($complaintId > 0) {
                $psStmt = $this->conn->prepare("
                    SELECT * FROM paanyaya_sessions
                    WHERE complaint_id = ? AND (attempt_number = ? OR scheduled_date = ?)
                    ORDER BY session_id DESC LIMIT 1
                    FOR UPDATE
                ");
                $psStmt->execute([$complaintId, $attemptNumber, $pRow['scheduled_date']]);
                $linkedSession = $psStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($linkedSession) {
                    $sessionId = (int) $linkedSession['session_id'];
                }
            }

            // Concurrency / Stale payload guard
            if (!empty($data['expected_updated_at']) && (string) $data['expected_updated_at'] !== (string) ($pRow['updated_at'] ?? '')) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 409,
                    'code' => 'STALE_PAYLOAD',
                    'message' => 'This Paanyaya record was modified by another session. Reload the docket and try again.'
                ];
            }

            $merged = $pRow;
            if ($linkedSession) {
                $merged['session_id'] = (int) $linkedSession['session_id'];
                $merged['session_queue_status'] = $linkedSession['queue_status'];
                $merged['actual_start_time'] = $linkedSession['actual_start_time'];
                $merged['actual_end_time'] = $linkedSession['actual_end_time'];
                $merged['session_outcome'] = $linkedSession['session_outcome'];
                $merged['respondent_attended'] = $linkedSession['respondent_attended'];
                if (empty($merged['complainant_attended']) || $merged['complainant_attended'] === 'Absent') {
                    $merged['complainant_attended'] = $linkedSession['complainant_attended'];
                }
            }

            $elig = $this->evaluateItemEligibility($merged, 'paanyaya');
            if (!$elig['eligible']) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => $elig['lock_code'] ?? 'ITEM_LOCKED',
                    'message' => $elig['lock_reason'] ?? 'This Paanyaya record is locked and cannot be rescheduled.'
                ];
            }

            $oldDate = (string) $pRow['scheduled_date'];
            $oldTimeRaw = (string) ($pRow['scheduled_time'] ?: '09:00:00');
            $oldTime = substr($oldTimeRaw, 0, 5);
            $oldDateTime = "{$oldDate} " . (strlen($oldTimeRaw) === 5 ? "{$oldTimeRaw}:00" : $oldTimeRaw);
            $oldOrder = max(1, (int) ($pRow['queue_order'] ?: ($linkedSession['queue_order'] ?? 1)));
            $oldVenue = (string) ($pRow['venue'] ?: 'Tanggapan ng Barangay Tumana');

            $oldPresidingOfficerId = null;
            $oldPresidingOfficerName = 'Barangay Officer / Lupon Chairman';
            if (!empty($linkedSession['presiding_officer_ids'])) {
                $dec = json_decode((string) $linkedSession['presiding_officer_ids'], true);
                if (is_array($dec) && !empty($dec[0])) {
                    $oldPresidingOfficerId = (int) $dec[0];
                } elseif (is_numeric($linkedSession['presiding_officer_ids'])) {
                    $oldPresidingOfficerId = (int) $linkedSession['presiding_officer_ids'];
                }
                if ($oldPresidingOfficerId) {
                    $uStmt = $this->conn->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM users WHERE user_id = ?");
                    $uStmt->execute([$oldPresidingOfficerId]);
                    $oldPresidingOfficerName = (string) ($uStmt->fetchColumn() ?: $oldPresidingOfficerName);
                }
            }

            $newVenue = trim((string) ($data['venue'] ?? $oldVenue));
            if ($newVenue === '') {
                $newVenue = 'Tanggapan ng Barangay Tumana';
            }
            if (mb_strlen($newVenue) > 255) {
                $this->conn->rollBack();
                return ['success' => false, 'http_code' => 422, 'message' => 'Venue must not exceed 255 characters.'];
            }

            $newPresidingOfficerId = array_key_exists('presiding_officer_id', $data) && $data['presiding_officer_id'] !== '' && $data['presiding_officer_id'] !== null
                ? (int) $data['presiding_officer_id']
                : $oldPresidingOfficerId;

            if ($oldDate === $targetDate
                && $oldTime === substr($normalizedTime, 0, 5)
                && $oldVenue === $newVenue
                && $oldPresidingOfficerId === $newPresidingOfficerId) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'UNCHANGED_SCHEDULE',
                    'message' => 'The proposed date, time, venue, and presiding officer are identical to the current Paanyaya schedule.'
                ];
            }

            // Validate future date/time, weekday, holiday, operating hours, lunch break
            $targetDtObj = new DateTimeImmutable($targetDateTimeStr);
            if ($targetDtObj <= new DateTimeImmutable('now')) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'PAST_DATE_BLOCKED',
                    'message' => 'Paanyaya invitations must be scheduled to a future date and time.'
                ];
            }

            $dayOfWeek = (int) $targetDtObj->format('w');
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'WEEKEND_BLOCKED',
                    'message' => 'Paanyaya sessions cannot be scheduled on weekends.'
                ];
            }

            $holidayName = $this->logistics->isPhilippineHoliday($targetDtObj);
            if ($holidayName !== null) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'HOLIDAY_BLOCKED',
                    'message' => "Scheduling is blocked on {$targetDtObj->format('F j, Y')}: Official / configured holiday ({$holidayName})."
                ];
            }

            $workStart = (string) $this->logistics->getSetting('working_hours_start', '08:00');
            $workEnd = (string) $this->logistics->getSetting('working_hours_end', '17:00');
            $reqTimeShort = substr($normalizedTime, 0, 5);
            if ($reqTimeShort < $workStart || $reqTimeShort >= $workEnd || ($reqTimeShort >= '12:00' && $reqTimeShort < '13:00')) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 422,
                    'code' => 'OUTSIDE_OPERATING_HOURS',
                    'message' => "Paanyaya sessions must be scheduled within operating hours ({$workStart} – 12:00 & 13:00 – {$workEnd}, excluding the 12:00 PM – 1:00 PM lunch break)."
                ];
            }

            $endDateTimeStr = date('Y-m-d H:i:s', strtotime('+30 minutes', strtotime($targetDateTimeStr)));
            $venueCheck = $this->logistics->validateVenueAvailability($newVenue, $targetDateTimeStr, $endDateTimeStr, null);
            if (!$venueCheck['available']) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'http_code' => 409,
                    'code' => 'VENUE_UNAVAILABLE',
                    'message' => $venueCheck['message']
                ];
            }

            if ($newPresidingOfficerId) {
                $presCheck = $this->logistics->validateMemberAvailability($newPresidingOfficerId, $targetDateTimeStr, $endDateTimeStr, null, 'Presiding Officer');
                if (!$presCheck['available']) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'http_code' => 409,
                        'code' => 'OFFICER_UNAVAILABLE',
                        'message' => $presCheck['message']
                    ];
                }
            }

            // Lock target date records to compute new queue_order
            $targetDayHearings = $this->conn->prepare("
                SELECT COALESCE(queue_order, 0) AS queue_order FROM hearings
                WHERE DATE(hearing_date) = ?
                  AND status NOT IN ('Cancelled', 'Office Cancelled', 'Completed', 'Rescheduled')
                FOR UPDATE
            ");
            $targetDayHearings->execute([$targetDate]);
            $hOrders = $targetDayHearings->fetchAll(PDO::FETCH_COLUMN);

            $targetDayPaanyayas = $this->conn->prepare("
                SELECT COALESCE(queue_order, 0) AS queue_order FROM paanyayas
                WHERE scheduled_date = ?
                  AND status NOT IN ('Cancelled', 'Completed', 'Resolved', 'Unresolved', 'Escalated')
                  AND paanyaya_id <> ?
                FOR UPDATE
            ");
            $targetDayPaanyayas->execute([$targetDate, $paanyayaId]);
            $pOrders = $targetDayPaanyayas->fetchAll(PDO::FETCH_COLUMN);

            if ($oldDate === $targetDate && empty($data['proposed_order'])) {
                $newOrder = $oldOrder;
            } else {
                $allOrders = array_map('intval', array_merge($hOrders, $pOrders));
                $maxOrder = !empty($allOrders) ? max($allOrders) : 0;
                $totalOnDay = count($allOrders);
                $newOrder = !empty($data['proposed_order'])
                    ? max(1, (int) $data['proposed_order'])
                    : max($totalOnDay + 1, $maxOrder + 1);
            }

            $newPresidingOfficerName = $oldPresidingOfficerName;
            if ($newPresidingOfficerId) {
                $uStmt = $this->conn->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM users WHERE user_id = ?");
                $uStmt->execute([$newPresidingOfficerId]);
                $newPresidingOfficerName = (string) ($uStmt->fetchColumn() ?: $newPresidingOfficerName);
            }

            // Regenerate official Paanyaya PDF file and remove/supersede old file on disk
            $storageDir = __DIR__ . '/../../storage/paanyaya';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0777, true);
            }
            $pdfFileName = sprintf('paanyaya_%d_%s.pdf', $paanyayaId, date('Ymd_His'));
            $pdfFullPath = $storageDir . '/' . $pdfFileName;
            $pdfRelativePath = 'storage/paanyaya/' . $pdfFileName;
            $newPdfBinary = null;

            try {
                $pdfService = new PDFService($this->conn);
                $verifiedParties = !empty($prepRow['verified_parties']) ? json_decode((string) $prepRow['verified_parties'], true) : [];
                $pdfOverrides = [
                    'paanyaya_id' => $paanyayaId,
                    'attempt_number' => $attemptNumber,
                    'issued_date' => date('Y-m-d'),
                    'invitation_date' => $targetDate,
                    'invitation_time' => substr($normalizedTime, 0, 5),
                    'venue' => $newVenue,
                    'invitee_name' => $pRow['invitee_name'] ?? 'Inanyayahang Residente',
                    'complainant_name' => $pRow['complainant_name'] ?? '',
                    'salaysay' => $pRow['salaysay'] ?? '',
                    'has_e_signature' => false,
                ];
                if (!empty($verifiedParties['complainants']) && is_array($verifiedParties['complainants'])) {
                    $pdfOverrides['complainants'] = $verifiedParties['complainants'];
                }
                if (!empty($verifiedParties['respondents']) && is_array($verifiedParties['respondents'])) {
                    $pdfOverrides['respondents'] = $verifiedParties['respondents'];
                }
                $pdfData = $pdfService->loadPaanyayaData($complaintId > 0 ? $complaintId : 0, $pdfOverrides);
                $newPdfBinary = $pdfService->generatePaanyaya($complaintId > 0 ? $complaintId : 0, [
                    'data' => $pdfData
                ]);
                if (is_string($newPdfBinary) && $newPdfBinary !== '') {
                    @file_put_contents($pdfFullPath, $newPdfBinary, LOCK_EX);
                }

                // Remove old disk PDF if it exists so no stored file retains a conflicting appointment
                if (!empty($pRow['pdf_path']) && $pRow['pdf_path'] !== $pdfRelativePath) {
                    $oldFullPath = __DIR__ . '/../../' . ltrim((string) $pRow['pdf_path'], '/\\');
                    if (is_file($oldFullPath)) {
                        @unlink($oldFullPath);
                    }
                }
            } catch (Throwable $pdfErr) {
                error_log('DocketScheduleService::reschedulePaanyaya PDF generation warning: ' . $pdfErr->getMessage());
            }

            // 1. Update paanyayas
            $this->conn->prepare("
                UPDATE paanyayas
                SET scheduled_date = ?,
                    scheduled_time = ?,
                    venue = ?,
                    queue_order = ?,
                    pdf_path = COALESCE(?, pdf_path),
                    updated_at = NOW()
                WHERE paanyaya_id = ?
            ")->execute([
                $targetDate,
                $normalizedTime,
                $newVenue,
                $newOrder,
                $newPdfBinary ? $pdfRelativePath : null,
                $paanyayaId
            ]);

            $oldFmt = date('M j, Y g:i A', strtotime($oldDateTime));
            $newFmt = date('M j, Y g:i A', strtotime($targetDateTimeStr));
            $reschedRemark = sprintf('[Rescheduled %s -> %s (%s): %s]', $oldFmt, $newFmt, $newVenue, $reason);
            $presOfficerJson = $newPresidingOfficerId ? json_encode([$newPresidingOfficerId]) : ($linkedSession['presiding_officer_ids'] ?? null);

            // 2. Reconcile paanyaya_sessions
            if ($sessionId > 0) {
                $this->conn->prepare("
                    UPDATE paanyaya_sessions
                    SET scheduled_date = ?,
                        scheduled_time = ?,
                        venue = ?,
                        queue_order = ?,
                        pdf_path = COALESCE(?, pdf_path),
                        presiding_officer_ids = ?,
                        session_remarks = TRIM(CONCAT(COALESCE(session_remarks, ''), ' ', ?)),
                        updated_at = NOW()
                    WHERE session_id = ?
                ")->execute([
                    $targetDate,
                    $normalizedTime,
                    $newVenue,
                    $newOrder,
                    $newPdfBinary ? $pdfRelativePath : null,
                    $presOfficerJson,
                    $reschedRemark,
                    $sessionId
                ]);
            } elseif ($complaintId > 0) {
                $insSession = $this->conn->prepare("
                    INSERT INTO paanyaya_sessions (
                        complaint_id, invitee_name, attempt_number, scheduled_date, scheduled_time,
                        venue, salaysay, pdf_path, presiding_officer_ids, queue_order, session_remarks, scheduled_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insSession->execute([
                    $complaintId,
                    $pRow['invitee_name'] ?? 'Inanyayahang Residente',
                    $attemptNumber,
                    $targetDate,
                    $normalizedTime,
                    $newVenue,
                    $pRow['salaysay'] ?? null,
                    $newPdfBinary ? $pdfRelativePath : ($pRow['pdf_path'] ?? null),
                    $presOfficerJson,
                    $newOrder,
                    $reschedRemark,
                    $userId
                ]);
                $sessionId = (int) $this->conn->lastInsertId();
            }

            // 3. Reconcile paanyaya_stages, paanyaya_preparations, paanyaya_service_attempts, and complaint_paanyaya
            $assignedServerIds = [];
            if ($complaintId > 0) {
                $stageType = 'Paanyaya ' . $attemptNumber;
                $stageLock = $this->conn->prepare("SELECT * FROM paanyaya_stages WHERE complaint_id = ? AND stage_type = ? FOR UPDATE");
                $stageLock->execute([$complaintId, $stageType]);
                $stageRow = $stageLock->fetch(PDO::FETCH_ASSOC);
                if ($stageRow) {
                    $stageSet = ['invitation_date = ?', 'invitation_time = ?', 'venue = ?'];
                    $stageParams = [$targetDate, $normalizedTime, $newVenue];
                    if (array_key_exists('pdf_path', $stageRow)) {
                        $stageSet[] = 'pdf_path = COALESCE(?, pdf_path)';
                        $stageParams[] = $newPdfBinary ? $pdfRelativePath : null;
                    }
                    if (array_key_exists('updated_at', $stageRow)) {
                        $stageSet[] = 'updated_at = NOW()';
                    }
                    $stageParams[] = $complaintId;
                    $stageParams[] = $stageType;
                    $this->conn->prepare("
                        UPDATE paanyaya_stages
                        SET " . implode(', ', $stageSet) . "
                        WHERE complaint_id = ? AND stage_type = ?
                    ")->execute($stageParams);
                }

                // Reconcile paanyaya_preparations
                $prepLock = $this->conn->prepare("
                    SELECT * FROM paanyaya_preparations
                    WHERE complaint_id = ? AND invitation_number = ?
                    FOR UPDATE
                ");
                $prepLock->execute([$complaintId, $attemptNumber]);
                $prepRow = $prepLock->fetch(PDO::FETCH_ASSOC);

                $newDocVersion = null;
                if ($prepRow) {
                    if (!$newPdfBinary) {
                        $pdfService = new PDFService($this->conn);
                        $parties = json_decode((string) ($prepRow['verified_parties'] ?? '[]'), true) ?: [];
                        $complainants = array_values(array_filter($parties, fn($pt) => ($pt['party_type'] ?? '') === 'Complainant'));
                        $respondents = array_values(array_filter($parties, fn($pt) => ($pt['party_type'] ?? '') === 'Respondent'));
                        $pData = $pdfService->loadPaanyayaData($complaintId, [
                            'attempt_number' => $attemptNumber,
                            'issued_date' => date('Y-m-d'),
                            'invitation_date' => $targetDate,
                            'invitation_time' => substr($normalizedTime, 0, 5),
                            'venue' => $newVenue,
                            'invitee_name' => implode(' / ', array_column($respondents, 'full_name')) ?: ($pRow['invitee_name'] ?? 'Inanyayahang Residente'),
                            'has_e_signature' => false,
                        ]);
                        if ($complainants) $pData['complainants'] = $complainants;
                        if ($respondents) $pData['respondents'] = $respondents;
                        $pData['intake_reference'] = $pData['complaint']['intake_reference'] ?? '';
                        $newPdfBinary = $pdfService->generatePaanyaya($complaintId, ['data' => $pData]);
                    }
                    $newDocVersion = hash('sha256', (string) $newPdfBinary);

                    $updPrep = $this->conn->prepare("
                        UPDATE paanyaya_preparations
                        SET appointment_at = ?,
                            venue = ?,
                            document_pdf = ?,
                            prepared_by = ?,
                            prepared_at = CURRENT_TIMESTAMP
                        WHERE complaint_id = ? AND invitation_number = ?
                    ");
                    $updPrep->bindValue(1, $targetDateTimeStr);
                    $updPrep->bindValue(2, $newVenue);
                    $updPrep->bindValue(3, $newPdfBinary, PDO::PARAM_LOB);
                    $updPrep->bindValue(4, $userId, PDO::PARAM_INT);
                    $updPrep->bindValue(5, $complaintId, PDO::PARAM_INT);
                    $updPrep->bindValue(6, $attemptNumber, PDO::PARAM_INT);
                    $updPrep->execute();
                }

                // Reconcile paanyaya_service_attempts so served invitations and ResidentComplaintTimeline stay 100% consistent
                $attLock = $this->conn->prepare("
                    SELECT * FROM paanyaya_service_attempts
                    WHERE complaint_id = ? AND invitation_number = ?
                    ORDER BY attempt_id ASC
                    FOR UPDATE
                ");
                $attLock->execute([$complaintId, $attemptNumber]);
                $serviceAttempts = $attLock->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($serviceAttempts)) {
                    $updAtt = $this->conn->prepare("
                        UPDATE paanyaya_service_attempts
                        SET appointment_at = ?,
                            document_version = COALESCE(?, document_version),
                            notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?))
                        WHERE attempt_id = ?
                    ");
                    $supAttemptNote = sprintf('[Superseded appointment %s -> %s (%s): %s]', $oldFmt, $newFmt, $newVenue, $reason);
                    foreach ($serviceAttempts as $sa) {
                        $updAtt->execute([
                            $targetDateTimeStr,
                            $newDocVersion,
                            $supAttemptNote,
                            (int) $sa['attempt_id']
                        ]);
                        if (!empty($sa['assigned_to'])) {
                            $assignedServerIds[(int) $sa['assigned_to']] = true;
                        }
                    }
                }

                // Reconcile legacy complaint_paanyaya if present
                $this->conn->prepare("
                    UPDATE complaint_paanyaya
                    SET invitation_date = ?,
                        invitation_time = ?,
                        venue = ?,
                        pdf_path = COALESCE(?, pdf_path)
                    WHERE complaint_id = ? AND status = 'Scheduled'
                ")->execute([
                    $targetDate,
                    $normalizedTime,
                    $newVenue,
                    $newPdfBinary ? $pdfRelativePath : null,
                    $complaintId
                ]);
            }

            // Record detailed audit trail entry containing old and new schedule values
            $refLabel = $pRow['complaint_number'] ?: "Paanyaya#{$paanyayaId}";
            $auditAction = sprintf(
                'Rescheduled Paanyaya #%d (%s): %s %s (#%d, %s, Officer:%s) -> %s %s (#%d, %s, Officer:%s). Reason: %s',
                $paanyayaId,
                $refLabel,
                $oldDate,
                $oldTime,
                $oldOrder,
                $oldVenue,
                $oldPresidingOfficerId ?: 'Default',
                $targetDate,
                substr($normalizedTime, 0, 5),
                $newOrder,
                $newVenue,
                $newPresidingOfficerId ?: 'Default',
                $reason
            );
            if (mb_strlen($auditAction) > 250) {
                $auditAction = mb_substr($auditAction, 0, 247) . '...';
            }

            $this->conn->prepare("
                INSERT INTO audit_trails (user_id, action, module_name, affected_record)
                VALUES (?, ?, 'Paanyaya', ?)
            ")->execute([$userId, $auditAction, $complaintId ?: $paanyayaId]);

            $this->conn->commit();

            // Notify affected parties and staff
            $notifTitle = "Paanyaya Rescheduled: {$refLabel}";
            $notifMessage = sprintf(
                'Paanyaya dialogue for %s (%s) was rescheduled from %s (%s, Order #%d) to %s (%s, Order #%d). Presiding Officer: %s. Reason: %s',
                $pRow['invitee_name'] ?? 'Respondent',
                $refLabel,
                $oldFmt,
                $oldVenue,
                $oldOrder,
                $newFmt,
                $newVenue,
                $newOrder,
                $newPresidingOfficerName,
                $reason
            );
            $actionUrl = $complaintId > 0 ? "../complaints/complaint-details.php?id={$complaintId}&tab=paanyaya" : "../complaints/paanyaya-list.php";
            $this->notifications->notifyRoles(['Administrator', 'Lupon Clerk'], $notifTitle, $notifMessage, $userId, 'info', $actionUrl);
            foreach (array_keys($assignedServerIds) as $sId) {
                $this->notifications->notifyUser((int) $sId, $notifTitle, $notifMessage, $userId, 'info', $actionUrl);
            }
            if ($newPresidingOfficerId && $newPresidingOfficerId !== $userId) {
                $this->notifications->notifyUser($newPresidingOfficerId, $notifTitle, $notifMessage, $userId, 'info', $actionUrl);
            }
            if (!empty($pRow['submitted_by'])) {
                $this->notifications->notifyUser((int) $pRow['submitted_by'], $notifTitle, $notifMessage, $userId, 'info', $actionUrl);
            }

            return [
                'success' => true,
                'http_code' => 200,
                'item_type' => 'paanyaya',
                'paanyaya_id' => $paanyayaId,
                'session_id' => $sessionId ?: null,
                'complaint_id' => $complaintId ?: null,
                'pdf_path' => $newPdfBinary ? $pdfRelativePath : ($pRow['pdf_path'] ?? null),
                'old_schedule' => [
                    'date' => $oldDate,
                    'time' => $oldTime,
                    'datetime' => $oldDateTime,
                    'formatted' => $oldFmt,
                    'queue_order' => $oldOrder,
                    'venue' => $oldVenue,
                    'presiding_officer_id' => $oldPresidingOfficerId,
                    'presiding_officer_name' => $oldPresidingOfficerName
                ],
                'new_schedule' => [
                    'date' => $targetDate,
                    'time' => substr($normalizedTime, 0, 5),
                    'datetime' => $targetDateTimeStr,
                    'formatted' => $newFmt,
                    'queue_order' => $newOrder,
                    'venue' => $newVenue,
                    'presiding_officer_id' => $newPresidingOfficerId,
                    'presiding_officer_name' => $newPresidingOfficerName
                ],
                'reason' => $reason,
                'message' => "Paanyaya appointment successfully rescheduled from {$oldFmt} to {$newFmt}."
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('DocketScheduleService::reschedulePaanyaya error: ' . $e->getMessage());
            return [
                'success' => false,
                'http_code' => 422,
                'code' => 'RESCHEDULE_FAILED',
                'message' => 'Unable to reschedule Paanyaya appointment: ' . $e->getMessage()
            ];
        }
    }
}
