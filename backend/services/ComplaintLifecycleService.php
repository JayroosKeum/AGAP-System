<?php

require_once __DIR__ . '/../config/database.php';

class ComplaintLifecycleService
{
    public const PENDING = 'Pending';
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
            self::PENDING,
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
            self::MEDIATION =>
                'Active mediation with the Lupon Head.',

            self::CONCILIATION =>
                'Active conciliation with the Lupon panel.',

            self::CFA =>
                'Certificate to File Action.',

            self::CLOSED =>
                'Case resolved or officially closed.',

            default =>
                'Summon is currently being served.'
        };
    }

    public function forComplaint(
        int $complaintId
    ): string {
        if ($complaintId < 1) {
            return self::PENDING;
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
            return self::PENDING;
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

        /*
         * Resolution / Closed has the highest priority.
         */
        $closedStatuses = [
            'Settled',
            'Dismissed',
            'Archived',
            'Rejected',
            'Arbitration',
            'DISMISSED_BARRED',
            'RESPONDENT_DEFAULT'
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

        /*
         * CFA overrides active mediation and conciliation.
         */
        if (
            $hasCfa ||
            $caseStatus === 'CFA Issued' ||
            $complaintStatus === 'CFA Issued'
        ) {
            return self::CFA;
        }

        /*
         * Conciliation is active only when:
         *
         * 1. The case status is Conciliation;
         * 2. The complaint status is Conciliation; or
         * 3. A Conciliation hearing exists.
         *
         * Creating or assigning a Pangkat group alone must not
         * automatically change the displayed status.
         */
        if (
            $caseStatus === 'Conciliation' ||
            $complaintStatus === 'Conciliation' ||
            $hasConciliation
        ) {
            return self::CONCILIATION;
        }

        /*
         * Mediation is active when:
         *
         * 1. The case status is Mediation;
         * 2. The complaint status is Mediation; or
         * 3. A Mediation hearing exists.
         */
        if (
            $caseStatus === 'Mediation' ||
            $complaintStatus === 'Mediation' ||
            $hasMediation
        ) {
            return self::MEDIATION;
        }

        return self::PENDING;
    }
}