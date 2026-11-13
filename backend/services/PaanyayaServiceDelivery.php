<?php

declare(strict_types=1);

require_once __DIR__ . '/PaanyayaPreparationService.php';

final class PaanyayaServiceDelivery
{
    public function __construct(private PDO $db)
    {
    }

    public function servers(): array
    {
        return $this->db->query("SELECT user_id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS full_name
            FROM users WHERE role_id = 4 AND status = 'Active' ORDER BY last_name, first_name, user_id")->fetchAll();
    }

    public function history(int $complaintId, int $invitation = 1): array
    {
        $statement = $this->db->prepare("SELECT a.attempt_id, a.released_at, a.outcome, a.notes, a.recorded_at, a.proof_mime,
                TRIM(CONCAT_WS(' ', server.first_name, server.last_name)) AS server_name,
                TRIM(CONCAT_WS(' ', clerk.first_name, clerk.last_name)) AS released_by_name
            FROM paanyaya_service_attempts a JOIN users server ON server.user_id = a.assigned_to
            LEFT JOIN users clerk ON clerk.user_id = a.released_by
            WHERE a.complaint_id = ? AND a.invitation_number = ? ORDER BY a.attempt_id DESC");
        $statement->execute([$complaintId, $invitation]);
        return $statement->fetchAll();
    }

    public function assignments(int $serverId): array
    {
        $statement = $this->db->prepare("SELECT a.attempt_id, a.complaint_id, a.invitation_number, a.outcome, a.notes, a.proof_mime,
                a.appointment_at, c.intake_reference, c.complaint_title, p.verified_parties, p.venue
            FROM paanyaya_service_attempts a JOIN complaints c ON c.complaint_id = a.complaint_id
            JOIN paanyaya_preparations p ON p.complaint_id = a.complaint_id AND p.invitation_number = a.invitation_number
            WHERE a.assigned_to = ?
              AND (a.outcome IS NOT NULL OR (c.screening_decision = 'Eligible'
                   AND c.status = IF(a.invitation_number = 1, 'Accepted', 'Pre-Docketed (Paanyaya)')))
            ORDER BY a.outcome IS NOT NULL, a.appointment_at, a.attempt_id DESC");
        $statement->execute([$serverId]);
        return $statement->fetchAll();
    }

    public function release(int $complaintId, int $clerkId, int $serverId, string $version, int $invitation = 1): void
    {
        $this->db->beginTransaction();
        try {
            $preparation = $this->lockIntake($complaintId, $invitation);
            if (!hash_equals($preparation['document_version'], $version)) {
                throw new DomainException('Review and mark the current invitation Ready for Release first.');
            }
            $this->verifyInvitation($complaintId, $preparation);

            $server = $this->db->prepare("SELECT user_id FROM users WHERE user_id = ? AND role_id = 4 AND status = 'Active' FOR SHARE");
            $server->execute([$serverId]);
            if (!$server->fetchColumn()) {
                throw new DomainException('Select an active summons server.');
            }

            $latest = self::latestAttempt($this->db, $complaintId, $invitation);
            if ($latest && $latest['outcome'] !== 'Not Delivered') {
                throw new DomainException('This invitation already has an assignment or a confirmed delivery.');
            }

            $this->db->prepare('INSERT INTO paanyaya_service_attempts
                (complaint_id, appointment_at, document_version, assigned_to, released_by, invitation_number)
                VALUES (?, ?, ?, ?, ?, ?)')->execute([
                    $complaintId, $preparation['appointment_at'], $version, $serverId, $clerkId, $invitation,
                ]);
            $this->audit($clerkId, 'Released Paanyaya invitation for service', $complaintId);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function record(int $attemptId, int $serverId, array $input, array $file): void
    {
        $outcome = $input['outcome'] ?? '';
        $notes = trim(is_string($input['notes'] ?? null) ? $input['notes'] : '');
        if (!in_array($outcome, ['Delivered', 'Not Delivered'], true)) {
            throw new DomainException('Select a delivery result.');
        }
        if (mb_strlen($notes) > 2000) {
            throw new DomainException('Delivery notes can contain up to 2,000 characters.');
        }
        if ($outcome === 'Not Delivered' && $notes === '') {
            throw new DomainException('Enter a reason for unsuccessful service.');
        }
        $attempt = $this->findAttempt($attemptId, $serverId);
        if (!$attempt) {
            throw new DomainException('This service assignment is unavailable.');
        }
        [$mime, $proof] = $this->readProof($file);

        $this->db->beginTransaction();
        try {
            $complaintId = (int) $attempt['complaint_id'];
            $invitation = (int) $attempt['invitation_number'];
            $preparation = $this->lockIntake($complaintId, $invitation);
            $latest = self::latestAttempt($this->db, $complaintId, $invitation);
            if (!$latest || (int) $latest['attempt_id'] !== $attemptId || $latest['outcome'] !== null) {
                throw new DomainException('A result has already been recorded for this assignment.');
            }
            if ($outcome === 'Delivered') {
                if (!hash_equals($preparation['document_version'], $attempt['document_version'])) {
                    throw new DomainException('The assigned invitation no longer matches the prepared document.');
                }
                $this->verifyInvitation($complaintId, $preparation);
                $this->activateDialogue($complaintId, $preparation, $attempt);
            }

            $statement = $this->db->prepare('UPDATE paanyaya_service_attempts
                SET outcome = ?, notes = ?, recorded_at = CURRENT_TIMESTAMP, proof_mime = ?, proof_image = ? WHERE attempt_id = ?');
            $statement->bindValue(1, $outcome);
            $statement->bindValue(2, $notes !== '' ? $notes : null);
            $statement->bindValue(3, $mime);
            $statement->bindValue(4, $proof, $proof === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $statement->bindValue(5, $attemptId, PDO::PARAM_INT);
            $statement->execute();
            $this->audit($serverId, 'Recorded Paanyaya service result: ' . $outcome, $complaintId);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public static function latestAttempt(PDO $db, int $complaintId, int $invitation = 1): ?array
    {
        $statement = $db->prepare('SELECT attempt_id, outcome FROM paanyaya_service_attempts
            WHERE complaint_id = ? AND invitation_number = ? ORDER BY attempt_id DESC LIMIT 1');
        $statement->execute([$complaintId, $invitation]);
        return $statement->fetch() ?: null;
    }

    public function findAttempt(int $attemptId, ?int $serverId): ?array
    {
        $sql = 'SELECT attempt_id, complaint_id, invitation_number, document_version, released_by, released_at, outcome, proof_mime
                FROM paanyaya_service_attempts WHERE attempt_id = ?';
        $parameters = [$attemptId];
        if ($serverId !== null) {
            $sql .= ' AND assigned_to = ?';
            $parameters[] = $serverId;
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetch() ?: null;
    }

    public function proof(int $attemptId): ?string
    {
        $statement = $this->db->prepare('SELECT proof_image FROM paanyaya_service_attempts WHERE attempt_id = ?');
        $statement->execute([$attemptId]);
        $image = $statement->fetchColumn();
        return is_string($image) ? $image : null;
    }

    private function lockIntake(int $complaintId, int $invitation): array
    {
        $lock = $this->db->prepare('SELECT complaint_id FROM complaints WHERE complaint_id = ? FOR UPDATE');
        $lock->execute([$complaintId]);
        $preparation = $lock->fetchColumn() ? (new PaanyayaPreparationService($this->db))->intake($complaintId, $invitation) : null;
        if (!$preparation || !$preparation['eligible'] || !$preparation['ready_at']) {
            throw new DomainException('This complaint is no longer awaiting Paanyaya service.');
        }
        return $preparation;
    }

    private function verifyInvitation(int $complaintId, array $preparation): void
    {
        if (new DateTimeImmutable($preparation['appointment_at']) <= new DateTimeImmutable()) {
            throw new DomainException('The appointment has passed. A new invitation is required before service can be confirmed.');
        }
        $parties = (new PaanyayaPreparationService($this->db))->parties($complaintId, true);
        if (json_decode($preparation['verified_parties'], true, 512, JSON_THROW_ON_ERROR) != $parties) {
            throw new DomainException('The party details changed. The clerk must regenerate the invitation.');
        }
    }

    private function activateDialogue(int $complaintId, array $preparation, array $attempt): void
    {
        $parties = json_decode($preparation['verified_parties'], true, 512, JSON_THROW_ON_ERROR);
        $names = [];
        foreach ($parties as $party) {
            $names[$party['party_type']][] = $party['full_name'];
        }
        $invitees = mb_substr(implode(' / ', $names['Respondent']), 0, 255);
        $complainants = mb_substr(implode(' / ', $names['Complainant']), 0, 255);
        [$date, $time] = explode(' ', $preparation['appointment_at'], 2);
        $number = (int) $attempt['invitation_number'];
        $this->db->prepare("INSERT INTO paanyaya_stages
            (complaint_id, stage_type, status, issued_at, issued_by, invitee_name, invitation_date, invitation_time, venue, has_e_signature)
            VALUES (?, ?, 'In Progress', ?, ?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE status = 'In Progress', issued_at = VALUES(issued_at), issued_by = VALUES(issued_by),
                invitee_name = VALUES(invitee_name), invitation_date = VALUES(invitation_date),
                invitation_time = VALUES(invitation_time), venue = VALUES(venue), has_e_signature = 0")
            ->execute([$complaintId, 'Paanyaya ' . $number, $attempt['released_at'], $attempt['released_by'], $invitees, $date, $time, $preparation['venue']]);
        $this->db->prepare('INSERT INTO paanyayas (complaint_id, complainant_name, invitee_name, scheduled_date, scheduled_time, venue, scheduled_by, attempt_number)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $complaintId, $complainants, $invitees, $date, $time, $preparation['venue'], $attempt['released_by'], $number,
            ]);
        $this->db->prepare('INSERT INTO paanyaya_sessions
            (complaint_id, invitee_name, scheduled_date, scheduled_time, venue, has_e_signature, scheduled_by, attempt_number)
            VALUES (?, ?, ?, ?, ?, 0, ?, ?)')
            ->execute([$complaintId, $invitees, $date, $time, $preparation['venue'], $attempt['released_by'], $number]);
        $this->db->prepare("UPDATE complaints SET status = 'Pre-Docketed (Paanyaya)', paanyaya_attempt_count = ? WHERE complaint_id = ?")
            ->execute([$number, $complaintId]);
    }

    private function readProof(array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        $path = $file['tmp_name'] ?? '';
        $size = $error === UPLOAD_ERR_OK && is_string($path) && is_uploaded_file($path) ? filesize($path) : false;
        if (!$size || $size > 5 * 1024 * 1024) {
            throw new DomainException('Upload a JPG, PNG, or WebP proof image up to 5 MB.');
        }
        $image = @getimagesize($path);
        $mime = $image['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new DomainException('Upload a valid JPG, PNG, or WebP proof image.');
        }
        $proof = file_get_contents($path);
        if ($proof === false) {
            throw new RuntimeException('Could not read the proof image.');
        }
        return [$mime, $proof];
    }

    private function audit(int $userId, string $action, int $complaintId): void
    {
        if (!(new AuditService($this->db))->log($userId, $action, 'Paanyaya', $complaintId)) {
            throw new RuntimeException('Unable to save the Paanyaya audit entry.');
        }
    }
}
