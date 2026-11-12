<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/PDFService.php';

final class PaanyayaPreparationService
{
    private const ASSIGNED_WALK_IN = "
        c.intake_source = 'Walk-in' AND c.liso_assigned_to IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM cases cs WHERE cs.complaint_id = c.complaint_id)
        AND NOT EXISTS (SELECT 1 FROM paanyayas p WHERE p.complaint_id = c.complaint_id)
        AND NOT EXISTS (SELECT 1 FROM paanyaya_sessions s WHERE s.complaint_id = c.complaint_id)
        AND NOT EXISTS (SELECT 1 FROM complaint_paanyaya lp WHERE lp.complaint_id = c.complaint_id)
        AND NOT EXISTS (
            SELECT 1 FROM paanyaya_stages ps
            WHERE ps.complaint_id = c.complaint_id AND ps.status <> 'Not Started'
        )";

    private const ELIGIBLE = self::ASSIGNED_WALK_IN . "
        AND c.is_draft = 0 AND c.status = 'Accepted' AND c.screening_decision = 'Eligible'
        AND c.liso_reviewed_at IS NOT NULL AND c.liso_reviewed_by = c.liso_assigned_to";

    public function __construct(private PDO $db)
    {
    }

    public static function requiresPreparation(PDO $db, int $id): bool
    {
        $statement = $db->prepare('SELECT 1 FROM complaints c WHERE c.complaint_id = ? AND ' . self::ASSIGNED_WALK_IN);
        $statement->execute([$id]);
        return (bool) $statement->fetchColumn();
    }

    public function queue(): array
    {
        return $this->db->query(
            "SELECT c.complaint_id, c.intake_reference, c.complaint_title,
                    p.prepared_at, p.ready_at
             FROM complaints c
             LEFT JOIN paanyaya_preparations p ON p.complaint_id = c.complaint_id
             WHERE " . self::ELIGIBLE . "
             ORDER BY p.ready_at IS NOT NULL, c.liso_reviewed_at, c.complaint_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function intake(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT c.complaint_id, c.review_notes, (' . self::ELIGIBLE . ') AS eligible,
                    p.appointment_at, p.venue, p.prepared_at, p.ready_at, p.verified_parties,
                    SHA2(p.document_pdf, 256) AS document_version,
                    TRIM(CONCAT_WS(\' \', preparer.first_name, preparer.last_name)) AS prepared_by_name,
                    TRIM(CONCAT_WS(\' \', approver.first_name, approver.last_name)) AS ready_by_name
             FROM complaints c
             LEFT JOIN paanyaya_preparations p ON p.complaint_id = c.complaint_id
             LEFT JOIN users preparer ON preparer.user_id = p.prepared_by
             LEFT JOIN users approver ON approver.user_id = p.ready_by
             WHERE c.complaint_id = ?'
        );
        $statement->execute([$id]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);
        return $record && ($record['eligible'] || $record['prepared_at']) ? $record : null;
    }

    public function parties(int $id, bool $lock = false): array
    {
        $statement = $this->db->prepare(
            "SELECT cp.party_type, TRIM(CONCAT_WS(' ', r.first_name, NULLIF(r.middle_name, ''), r.last_name, NULLIF(r.suffix, ''))) AS full_name,
                    COALESCE(NULLIF(TRIM(r.address), ''), NULLIF(TRIM(r.purok), ''), '') AS address
             FROM complaint_parties cp
             JOIN residents r ON r.resident_id = cp.resident_id
             WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
             ORDER BY cp.party_id" . ($lock ? ' FOR SHARE' : '')
        );
        $statement->execute([$id]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function partyVersion(array $parties): string
    {
        return hash('sha256', json_encode($parties, JSON_THROW_ON_ERROR));
    }

    public function document(int $id): ?string
    {
        $statement = $this->db->prepare('SELECT document_pdf FROM paanyaya_preparations WHERE complaint_id = ?');
        $statement->execute([$id]);
        $pdf = $statement->fetchColumn();
        return is_string($pdf) ? $pdf : null;
    }

    public function save(int $id, int $userId, string $action, array $input): string
    {
        if (!in_array($action, ['generate', 'ready'], true)) {
            throw new DomainException('Choose a valid preparation action.');
        }
        $this->db->beginTransaction();
        try {
            // Serialize submissions for the same intake, including the first generation.
            $lock = $this->db->prepare('SELECT complaint_id FROM complaints WHERE complaint_id = ? FOR UPDATE');
            $lock->execute([$id]);
            $record = $lock->fetchColumn() ? $this->intake($id) : null;
            if (!$record || !$record['eligible']) {
                throw new DomainException('This intake is no longer eligible for Paanyaya preparation.');
            }
            if ($record['ready_at']) {
                $this->db->commit();
                return 'This invitation is already Ready for Release.';
            }

            $parties = $this->parties($id, true);
            if ($action === 'generate') {
                $this->generate($id, $userId, $input, $parties);
                $message = 'Invitation generated. Review or print the PDF before marking it Ready for Release.';
                $auditAction = 'Generated Paanyaya invitation';
            } else {
                $this->markReady($id, $userId, $record, $parties, (string) ($input['document_version'] ?? ''));
                $message = 'Paanyaya marked Ready for Release.';
                $auditAction = 'Marked Paanyaya Ready for Release';
            }

            if (!(new AuditService($this->db))->log($userId, $auditAction, 'Paanyaya', $id)) {
                throw new RuntimeException('Unable to save the Paanyaya audit entry.');
            }
            $this->db->commit();
            return $message;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function generate(int $id, int $userId, array $input, array $parties): void
    {
        $date = (string) ($input['appointment_date'] ?? '');
        $time = (string) ($input['appointment_time'] ?? '');
        $venue = trim((string) ($input['venue'] ?? ''));
        $appointment = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time");
        if (!$appointment || $appointment->format('Y-m-d H:i') !== "$date $time" || $appointment <= new DateTimeImmutable()) {
            throw new DomainException('Enter a valid future appointment date and time.');
        }
        if ($venue === '' || mb_strlen($venue) > 255) {
            throw new DomainException('Enter an appointment venue of 255 characters or fewer.');
        }
        if (($input['verify_parties'] ?? '') !== '1') {
            throw new DomainException('Confirm the names and addresses of both parties before generating the invitation.');
        }
        if (!hash_equals(self::partyVersion($parties), (string) ($input['party_version'] ?? ''))) {
            throw new DomainException('The party details changed. Verify the current details before generating the invitation.');
        }

        $complainants = [];
        $respondents = [];
        foreach ($parties as $party) {
            if ($party['full_name'] === '' || $party['address'] === '') {
                throw new DomainException('Each complainant and respondent needs a name and an address or purok on the intake record.');
            }
            if ($party['party_type'] === 'Complainant') {
                $complainants[] = $party;
            } else {
                $respondents[] = $party;
            }
        }
        if (!$complainants || !$respondents) {
            throw new DomainException('The intake must include at least one complainant and one respondent.');
        }

        $renderer = new PDFService($this->db);
        $data = $renderer->loadPaanyayaData($id, [
            'attempt_number' => 1,
            'invitation_date' => $date,
            'invitation_time' => $time,
            'venue' => $venue,
            'invitee_name' => implode(' / ', array_column($respondents, 'full_name')),
            'has_e_signature' => false,
        ]);
        $data['complainants'] = $complainants;
        $data['respondents'] = $respondents;
        $pdf = $renderer->generatePaanyaya($id, ['data' => $data]);

        $statement = $this->db->prepare(
            'INSERT INTO paanyaya_preparations
                (complaint_id, appointment_at, venue, verified_parties, document_pdf, prepared_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE appointment_at = VALUES(appointment_at), venue = VALUES(venue),
                 verified_parties = VALUES(verified_parties), document_pdf = VALUES(document_pdf),
                 prepared_by = VALUES(prepared_by), prepared_at = CURRENT_TIMESTAMP'
        );
        $statement->bindValue(1, $id, PDO::PARAM_INT);
        $statement->bindValue(2, $appointment->format('Y-m-d H:i:s'));
        $statement->bindValue(3, $venue);
        $statement->bindValue(4, json_encode($parties, JSON_THROW_ON_ERROR));
        $statement->bindValue(5, $pdf, PDO::PARAM_LOB);
        $statement->bindValue(6, $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function markReady(int $id, int $userId, array $record, array $parties, string $version): void
    {
        if (!$record['prepared_at']) {
            throw new DomainException('Generate the invitation before marking it Ready for Release.');
        }
        if (!hash_equals($record['document_version'], $version)) {
            throw new DomainException('The invitation was regenerated. Review the current PDF before marking it Ready for Release.');
        }
        if (new DateTimeImmutable($record['appointment_at']) <= new DateTimeImmutable()) {
            throw new DomainException('The appointment has passed. Generate the invitation with a new date and time.');
        }
        // MySQL can reorder keys in JSON objects during storage.
        if (json_decode($record['verified_parties'], true, 512, JSON_THROW_ON_ERROR) != $parties) {
            throw new DomainException('The party details changed. Verify them and generate the invitation again.');
        }
        $this->db->prepare('UPDATE paanyaya_preparations SET ready_by = ?, ready_at = NOW() WHERE complaint_id = ?')
            ->execute([$userId, $id]);
    }
}
