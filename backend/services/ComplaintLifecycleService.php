<?php

require_once __DIR__ . '/../config/database.php';

class ComplaintLifecycleService
{
    public const FILED = 'Filed';
    public const UNDER_REVIEW = 'Under Review';
    public const NEEDS_INFORMATION = 'Needs Information';
    public const APPROVED = 'Approved';
    public const ACCEPTED = 'Accepted';
    public const PAANYAYA = 'Paanyaya';
    public const MEDIATION = 'Mediation';
    public const CONCILIATION = 'Conciliation';
    public const CFA = 'CFA';
    public const CLOSED = 'Resolution / Closed';

    private PDO $conn;

    public function __construct(
        ?PDO $connection = null
    ) {
        $this->conn =
            $connection ??
            (new Database())->connect();
    }

    public static function allowedStatuses(): array
    {
        return [

            self::MEDIATION,
            self::CONCILIATION,
            self::CFA,
            self::CLOSED
        ];
    }

    public static function description(
        string $status
    ): string {
        return match ($status) {
            self::PAANYAYA =>
                'Undocketed informal Paanyaya dialogue session.',

            self::MEDIATION =>
                'Active mediation with the Punong Barangay (Docketed).',

            self::CONCILIATION =>
                'Active conciliation with the Lupon panel.',

            self::CFA =>
                'Certificate to File Action.',

            self::CLOSED =>
                'Case resolved or officially closed.',

            default =>
                'Undocketed informal Paanyaya dialogue session.'
        };
    }

    public function resolveSummaryForComplaint(int $complaintId): array
    {
        if ($complaintId < 1) {
            return [
                'lifecycle_status' => self::MEDIATION,
                'workflow_stage' => 'Mediation',
            ];
        }

        $statement = $this->conn->prepare(
            "
            SELECT
                co.is_draft,
                co.status AS complaint_status,
                co.paanyaya_1_outcome,
                c.case_id,
                c.case_status,

                EXISTS(
                    SELECT 1
                    FROM settlements s
                    WHERE s.case_id = c.case_id
                      AND COALESCE(s.repudiation_status, '') <> 'Repudiated'
                      AND s.repudiation_date IS NULL
                ) AS has_active_settlement,

                EXISTS(
                    SELECT 1
                    FROM settlements sr
                    WHERE sr.case_id = c.case_id
                      AND (sr.repudiation_status = 'Repudiated' OR sr.repudiation_date IS NOT NULL)
                ) AS is_repudiated,

                EXISTS(
                    SELECT 1
                    FROM settlements sa
                    WHERE sa.case_id = c.case_id
                ) AS has_any_settlement,

                EXISTS(
                    SELECT 1
                    FROM cfa_records cf
                    WHERE cf.case_id = c.case_id
                ) AS has_cfa,

                EXISTS(
                    SELECT 1
                    FROM arbitration_records ar
                    WHERE ar.case_id = c.case_id
                ) AS has_arbitration,

                EXISTS(
                    SELECT 1
                    FROM arbitration_records ar2
                    WHERE ar2.case_id = c.case_id
                      AND ar2.award_date IS NOT NULL
                ) AS has_arbitration_award,

                EXISTS(
                    SELECT 1
                    FROM hearings hc
                    WHERE hc.case_id = c.case_id
                    AND hc.hearing_type = 'Conciliation'
                ) AS has_conciliation,

                EXISTS(
                    SELECT 1
                    FROM hearings hm
                    WHERE hm.case_id = c.case_id
                    AND hm.hearing_type = 'Mediation'
                ) AS has_mediation,

                EXISTS(
                    SELECT 1
                    FROM hearings ha
                    WHERE ha.case_id = c.case_id
                    AND ha.hearing_type = 'Arbitration'
                ) AS has_arbitration_hearing,

                (
                    EXISTS(SELECT 1 FROM paanyaya_sessions ps WHERE ps.complaint_id = co.complaint_id)
                    OR EXISTS(
                        SELECT 1
                        FROM paanyaya_stages pst
                        WHERE pst.complaint_id = co.complaint_id
                          AND (pst.issued_at IS NOT NULL OR pst.invitation_date IS NOT NULL OR (pst.status IS NOT NULL AND pst.status <> 'Not Started'))
                    )
                    OR EXISTS(SELECT 1 FROM complaint_paanyaya cp WHERE cp.complaint_id = co.complaint_id)
                ) AS has_paanyaya_schedule

            FROM complaints co

            LEFT JOIN cases c
                ON c.complaint_id = co.complaint_id

            WHERE co.complaint_id = ?

            LIMIT 1
            "
        );

        $statement->execute([$complaintId]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return [
                'lifecycle_status' => self::MEDIATION,
                'workflow_stage' => 'Mediation',
            ];
        }

        $lifecycleStatus = self::resolveFromValues(
            $record['complaint_status'] ?? null,
            $record['case_status'] ?? null,
            (bool) ($record['has_any_settlement'] ?? false),
            (bool) ($record['has_cfa'] ?? false),
            (bool) ($record['has_arbitration'] ?? false),
            (bool) ($record['has_conciliation'] ?? false),
            (bool) ($record['has_mediation'] ?? false),
            (bool) ($record['has_paanyaya_schedule'] ?? false),
            (bool) ($record['is_repudiated'] ?? false)
        );

        return [
            'lifecycle_status' => $lifecycleStatus,
            'workflow_stage' => self::resolveWorkflowStageFromRecord($record),
        ];
    }

    public function forComplaint(
        int $complaintId
    ): string {
        return $this->resolveSummaryForComplaint($complaintId)['lifecycle_status'];
    }

    public static function resolveWorkflowStageFromRecord(array $record): string
    {
        if (!empty($record['is_draft'])) {
            return 'Saved Draft';
        }

        $complaintStatus = trim((string) ($record['complaint_status'] ?? ''));
        $caseStatus = trim((string) ($record['case_status'] ?? ''));
        $paanyaya1Outcome = trim((string) ($record['paanyaya_1_outcome'] ?? ''));
        $hasCase = !empty($record['case_id']);

        $hasCfa = !empty($record['has_cfa']);
        $isRepudiated = !empty($record['is_repudiated']);
        $hasActiveSettlement = !empty($record['has_active_settlement']);
        $hasArbitration = !empty($record['has_arbitration']);
        $hasArbitrationAward = !empty($record['has_arbitration_award']);
        $hasConciliation = !empty($record['has_conciliation']);
        $hasMediation = !empty($record['has_mediation']);
        $hasArbitrationHearing = !empty($record['has_arbitration_hearing']);
        $hasPaanyayaSchedule = !empty($record['has_paanyaya_schedule']);

        if ($hasCfa || $caseStatus === 'CFA Issued' || $complaintStatus === 'CFA Issued') {
            return 'CFA';
        }
        if ($caseStatus === 'For CFA') {
            return 'For CFA';
        }

        if ($isRepudiated || $caseStatus === 'Repudiated' || $complaintStatus === 'Repudiated') {
            return 'Repudiated';
        }

        $dismissedStatuses = ['Dismissed', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Rejected'];
        if (
            in_array($caseStatus, $dismissedStatuses, true)
            || in_array($complaintStatus, $dismissedStatuses, true)
            || $paanyaya1Outcome === 'Dismissed'
        ) {
            return 'Dismissed';
        }

        if ($caseStatus === 'Archived' || $complaintStatus === 'Archived') {
            return 'Archived';
        }

        if ($hasArbitrationAward || ($hasArbitration && $caseStatus === 'Settled')) {
            return 'Settled (Arbitration)';
        }
        if ($hasArbitration || $hasArbitrationHearing || $caseStatus === 'Arbitration' || $complaintStatus === 'Arbitration') {
            return $hasArbitration ? 'Settled (Arbitration)' : 'Arbitration';
        }

        if (
            $hasActiveSettlement
            || $caseStatus === 'Settled'
            || $complaintStatus === 'Settled'
            || $complaintStatus === 'Resolved at Paanyaya / Closed'
            || $paanyaya1Outcome === 'Settled'
        ) {
            return 'Settled (Amicable Settlement)';
        }

        if ($caseStatus === 'Conciliation' || $complaintStatus === 'Conciliation' || $hasConciliation) {
            return 'Conciliation';
        }

        if ($caseStatus === 'Mediation' || $complaintStatus === 'Mediation' || $caseStatus === 'Docketed' || $hasMediation || $hasCase) {
            return 'Mediation';
        }

        if (
            $hasPaanyayaSchedule
            || in_array($complaintStatus, ['Pre-Docketed (Paanyaya)', 'Paanyaya In Progress', '1st Paanyaya Failed (No-Show)', 'Paanyaya'], true)
            || stripos($complaintStatus, 'paanyaya') !== false
        ) {
            return 'Paanyaya';
        }

        if ($complaintStatus === 'Accepted') {
            return 'Approved';
        }

        if ($complaintStatus === 'Needs Information') {
            return 'Needs Information';
        }

        return 'For Review';
    }

    public static function resolveFromValues(
        ?string $complaintStatus,
        ?string $caseStatus,
        bool $hasSettlement = false,
        bool $hasCfa = false,
        bool $hasArbitration = false,
        bool $hasConciliation = false,
        bool $hasMediation = false,
        bool $hasPaanyayaSchedule = false,
        bool $isRepudiated = false
    ): string {
        $complaintStatus =
            trim((string) $complaintStatus);

        $caseStatus =
            trim((string) $caseStatus);

        /*
         * CFA overrides active mediation, conciliation, and repudiated settlement once issued.
         */
        if (
            $hasCfa ||
            $caseStatus === 'CFA Issued' ||
            $complaintStatus === 'CFA Issued'
        ) {
            return self::CFA;
        }

        if (
            $isRepudiated ||
            $caseStatus === 'Repudiated' ||
            $complaintStatus === 'Repudiated'
        ) {
            return self::CLOSED;
        }

        /*
         * Resolution / Closed has priority after CFA/Repudiation.
         */
        $closedStatuses = [
            'Settled',
            'Dismissed',
            'Archived',
            'Rejected',
            'Arbitration',
            'DISMISSED_BARRED',
            'RESPONDENT_DEFAULT',
            'Resolved at Paanyaya / Closed'
        ];

        if (
            $hasSettlement ||
            $hasArbitration ||
            in_array(
                $caseStatus,
                $closedStatuses,
                true
            ) ||
            in_array(
                $complaintStatus,
                $closedStatuses,
                true
            )
        ) {
            return self::CLOSED;
        }

        if (
            $caseStatus === 'Conciliation' ||
            $complaintStatus === 'Conciliation' ||
            $hasConciliation
        ) {
            return self::CONCILIATION;
        }

        if (
            $caseStatus === 'Mediation' ||
            $complaintStatus === 'Mediation' ||
            $caseStatus === 'Docketed' ||
            $hasMediation
        ) {
            return self::MEDIATION;
        }

        if (
            $hasPaanyayaSchedule ||
            in_array($complaintStatus, [
                'Pre-Docketed (Paanyaya)',
                'Paanyaya In Progress',
                '1st Paanyaya Failed (No-Show)',
                'Paanyaya',
            ], true) ||
            stripos($complaintStatus, 'paanyaya') !== false
        ) {
            return self::PAANYAYA;
        }
        if ($complaintStatus === 'Accepted') {
            return self::APPROVED;
        }
        if ($complaintStatus === 'Needs Information') {
            return self::NEEDS_INFORMATION;
        }
        if ($complaintStatus === 'Under Review') {
            return self::UNDER_REVIEW;
        }
        if ($complaintStatus === 'Filed') {
            return self::FILED;
        }

        return self::FILED;
    }
}
