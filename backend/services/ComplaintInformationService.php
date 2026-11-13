<?php

declare(strict_types=1);

require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/ValidationService.php';

final class ComplaintInformationService
{
    public const REQUEST_LIMIT = 2000;
    public const RESPONSE_LIMIT = 5000;

    public function __construct(private PDO $db)
    {
    }

    public function history(int $complaintId): array
    {
        $statement = $this->db->prepare('SELECT request_id, request_message, requested_at, response_message, responded_at
            FROM complaint_information_requests WHERE complaint_id = ? ORDER BY request_id DESC');
        $statement->execute([$complaintId]);
        return $statement->fetchAll();
    }

    public function reply(int $complaintId, int $residentUserId, int $requestId, string $message): void
    {
        if ($message === '' || !ValidationService::maxLength($message, self::RESPONSE_LIMIT)
            || !ValidationService::text($message, true) || !mb_check_encoding($message, 'UTF-8')) {
            throw new DomainException('Enter a response of up to 5,000 characters.');
        }

        $this->db->beginTransaction();
        try {
            $complaint = $this->db->prepare('SELECT c.status, c.screening_decision
                FROM complaints c WHERE c.complaint_id = ? AND c.submitted_by = ? AND c.is_draft = 0
                AND NOT EXISTS (SELECT 1 FROM cases WHERE complaint_id = c.complaint_id) FOR UPDATE');
            $complaint->execute([$complaintId, $residentUserId]);
            $row = $complaint->fetch();
            if (!$row || $row['screening_decision'] !== 'Needs Information' || $row['status'] !== 'Needs Information') {
                throw new DomainException('This complaint is no longer waiting for a response.');
            }

            $latest = $this->db->prepare('SELECT request_id FROM complaint_information_requests
                WHERE complaint_id = ? ORDER BY request_id DESC LIMIT 1');
            $latest->execute([$complaintId]);
            if ((int) $latest->fetchColumn() !== $requestId) {
                throw new DomainException('The information request has changed. Review the current request.');
            }

            $response = $this->db->prepare('UPDATE complaint_information_requests
                SET response_message = ?, responded_at = NOW() WHERE request_id = ? AND responded_at IS NULL');
            $response->execute([$message, $requestId]);
            if ($response->rowCount() !== 1) {
                throw new DomainException('A response has already been saved for this request.');
            }

            $this->db->prepare("UPDATE complaints SET status = 'Under Review', screening_decision = 'Pending'
                WHERE complaint_id = ?")->execute([$complaintId]);
            if (!(new AuditService($this->db))->log($residentUserId, 'Replied to complaint information request', 'Complaints', $complaintId)) {
                throw new RuntimeException('Unable to audit the resident response.');
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }
}
