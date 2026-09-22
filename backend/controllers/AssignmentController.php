<?php

require_once __DIR__ . '/../models/Assignment.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/NotificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AssignmentController
{
    private Assignment $assignment;
    private AuditService $audit;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->assignment = new Assignment();
        $this->audit = new AuditService();
        $this->notifications = new NotificationService();
    }

    public function assign(array $data): array
    {
        return ['success' => false, 'message' => 'Save the complete Head, Secretary, and Member case team together.'];
    }

    /**
     * Returns the assignments for a case.
     * For a Mediation case, this first ensures that the
     * existing active Administrator is assigned as Head.
     */
    public function list(int $caseId): array
    {
        if ($caseId < 1) {
            return [];
        }

        $headResult = $this->assignment->ensureMediationHead($caseId);
        if (!$headResult['success']) {
            return [];
        }

        return $this->assignment->getByCase($caseId);
    }

    /**
     * Returns active Lupon Member users for the normal
     * Head, Secretary, and Member dropdowns.
     * The Administrator is intentionally not included.
     */
    public function luponMembers(): array
    {
        return $this->assignment->getLuponMembers();
    }

    /**
     * Saves the complete three-person case team.
     * For Mediation, Assignment::replaceCaseTeam() ignores
     * the submitted Head and uses the active Administrator.
     */
    public function saveCaseTeam(array $data): array
    {
        $caseId = filter_var(
            $data['case_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$caseId) {
            return [
                'success' => false,
                'message' => 'A valid case is required.'
            ];
        }

        $result = $this->assignment->replaceCaseTeam((int) $caseId, $data);
        if (!$result['success']) {
            return $result;
        }

        $actorUserId = (int) ($_SESSION['user_id'] ?? 0);
        if ($actorUserId > 0) {
            $this->audit->log($actorUserId, 'Saved Case Team', 'Assignments', (int) $caseId);
            $this->notifications->notifyCaseMembers(
                (int) $caseId,
                'Case team updated',
                'Your case-team assignment has been updated.',
                $actorUserId
            );
        }

        return $result;
    }
}
