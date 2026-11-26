<?php

require_once __DIR__ . '/../config/database.php';

class ComplaintLifecycleService
{
    public const PAANYAYA = 'Paanyaya';
    public const MEDIATION = 'Mediation';
    public const CONCILIATION = 'Conciliation';
    public const CFA = 'CFA';
    public const PENDING_CLOSE = 'Pending Close';
    public const CLOSED = 'Resolution / Closed';

    /**
     * Explicit resolution definitions distinguishing signed settlement, obligation
     * compliance, administrative closure, dismissal, and CFA referral (A9).
     */
    public const RESOLUTION_DEFINITIONS = [
        'Signed Settlement' => [
            'description' => 'Parties executed an attested amicable settlement or arbitration award (KP Form 16); obligation performance may still be ongoing.',
            'required_evidence' => 'Signed and Punong Barangay-attested settlement record or arbitration award.',
            'responsible_actor' => 'Punong Barangay / Pangkat Chairman / Lupon Clerk',
            'dispute_reopen_route' => 'Repudiation within 10-day window or execution proceedings on breach.',
        ],
        'Obligation Compliance' => [
            'description' => 'All monetary, property, or specific-performance obligations in a signed settlement have been verified as fulfilled.',
            'required_evidence' => 'Settlement compliance status Complied/Fully Paid or complainant-verified closure.',
            'responsible_actor' => 'Lupon Clerk + Complainant Verification',
            'dispute_reopen_route' => 'Motion for execution or closure objection reopens compliance review.',
        ],
        'Administrative Closure' => [
            'description' => 'Non-adjudicatory administrative closure following complainant verification or uncontested Pending Close timeout.',
            'required_evidence' => 'Closure request reason, supporting evidence/note, and complainant confirmation or expired uncontested notice.',
            'responsible_actor' => 'Administrator / Lupon Clerk (request) + Complainant or Policy Timeout (close)',
            'dispute_reopen_route' => 'Complainant objection blocks closure and restores prior active stage.',
        ],
        'Dismissal' => [
            'description' => 'Formal dismissal due to withdrawal, failure of complainant to appear, lack of jurisdiction, or bar to action.',
            'required_evidence' => 'Dismissal minutes, non-appearance/show-cause evaluation, or written withdrawal.',
            'responsible_actor' => 'Punong Barangay / Pangkat / Assigned LISO',
            'dispute_reopen_route' => 'Complainant objection during Pending Close or administrative review.',
        ],
        'CFA Referral' => [
            'description' => 'Issuance of Certificate to File Action (KP Form 20/20-A/20-B) or court/external referral after failed or repudiated settlement.',
            'required_evidence' => 'Recorded CFA issuance or failed conciliation/repudiation record.',
            'responsible_actor' => 'Lupon Secretary / Punong Barangay',
            'dispute_reopen_route' => 'External judicial/prosecutorial filing; cannot be silently marked fulfilled.',
        ],
    ];

    private PDO $conn;

    public function __construct(
        ?PDO $connection = null
    ) {
        $this->conn =
            $connection ??
            (new Database())->connect();
    }

    public static function canAccessKpForm7(array $complaint): bool
    {
        return (int) ($complaint['case_id'] ?? 0) > 0
            && empty($complaint['resolved_without_docketing'])
            && stripos((string) ($complaint['status'] ?? ''), 'paanyaya') === false;
    }

    public static function isKpForm7(string $name): bool
    {
        return stripos($name, 'KP Form 7') !== false || stripos($name, 'Blg. 7') !== false;
    }

    public static function allowedStatuses(): array
    {
        return [
            self::PAANYAYA,
            self::MEDIATION,
            self::CONCILIATION,
            self::CFA,
            self::PENDING_CLOSE,
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

            self::PENDING_CLOSE =>
                'Closure requested by staff; awaiting complainant verification or approved uncontested timeout.',

            self::CLOSED =>
                'Case resolved or officially closed.',

            default =>
                'Undocketed informal Paanyaya dialogue session.'
        };
    }

    public function forComplaint(
        int $complaintId
    ): string {
        if ($complaintId < 1) {
            return self::MEDIATION;
        }

        $statement = $this->conn->prepare(
            "
            SELECT
                co.status AS complaint_status,
                c.case_status,

                EXISTS(
                    SELECT 1
                    FROM settlements s
                    WHERE s.case_id = c.case_id
                      AND s.settlement_status = 'Signed and Attested'
                ) AS has_settlement,

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
                    FROM hearings hc
                    WHERE hc.case_id = c.case_id
                    AND hc.hearing_type = 'Conciliation'
                ) AS has_conciliation,

                EXISTS(
                    SELECT 1
                    FROM hearings hm
                    WHERE hm.case_id = c.case_id
                    AND hm.hearing_type = 'Mediation'
                ) AS has_mediation

            FROM complaints co

            LEFT JOIN cases c
                ON c.complaint_id = co.complaint_id

            WHERE co.complaint_id = ?

            LIMIT 1
            "
        );

        $statement->execute([
            $complaintId
        ]);

        $record = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$record) {
            return self::MEDIATION;
        }

        return self::resolveFromValues(
            $record['complaint_status'] ?? null,
            $record['case_status'] ?? null,
            (bool) (
                $record['has_settlement'] ??
                false
            ),
            (bool) (
                $record['has_cfa'] ??
                false
            ),
            (bool) (
                $record['has_arbitration'] ??
                false
            ),
            (bool) (
                $record['has_conciliation'] ??
                false
            ),
            (bool) (
                $record['has_mediation'] ??
                false
            )
        );
    }

    public static function resolveFromValues(
        ?string $complaintStatus,
        ?string $caseStatus,
        bool $hasSettlement = false,
        bool $hasCfa = false,
        bool $hasArbitration = false,
        bool $hasConciliation = false,
        bool $hasMediation = false
    ): string {
        $complaintStatus =
            trim((string) $complaintStatus);

        $caseStatus =
            trim((string) $caseStatus);

        if ($complaintStatus === 'Pending Close' || $caseStatus === 'Pending Close') {
            return self::PENDING_CLOSE;
        }

        /*
         * Dismissed / Barred cases are closed; CFA and Repudiation take priority
         * over historical settlement rows so CFA queue and badges stay accurate.
         */
        $barredOrDismissed = ['Dismissed', 'DISMISSED_BARRED', 'Rejected', 'Archived'];
        if (
            in_array($caseStatus, ['Dismissed', 'DISMISSED_BARRED', 'Rejected', 'Archived'], true) ||
            in_array($complaintStatus, $barredOrDismissed, true)
        ) {
            return self::CLOSED;
        }

        if (
            $hasCfa ||
            $caseStatus === 'CFA Issued' ||
            $complaintStatus === 'CFA Issued' ||
            $caseStatus === 'Repudiated' ||
            $complaintStatus === 'Repudiated'
        ) {
            return self::CFA;
        }

        $closedStatuses = [
            'Settled',
            'Closed',
            'Resolution / Closed',
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
            $caseStatus === 'Docketed' ||
            $complaintStatus === 'Mediation' ||
            $hasMediation
        ) {
            return self::MEDIATION;
        }

        return self::PAANYAYA;
    }
}
