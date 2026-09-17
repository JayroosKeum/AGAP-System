<?php

require_once __DIR__ . '/../models/Assignment.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/NotificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AssignmentController
{
    private $assignment;
    private $audit;
    private $notifications;

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

    public function list(int $caseId): array
    {
        return $this->assignment->getAssignments($caseId);
    }

    public function luponMembers(): array
    {
        return $this->assignment->getLuponMembers();
    }

    public function saveCaseTeam(array $data): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$caseId) return ['success' => false, 'message' => 'Select a valid case.'];
        $result = $this->assignment->replaceCaseTeam((int) $caseId, $data);
        if ($result['success']) {
            $this->audit->log((int) $_SESSION['user_id'], 'Saved Case Team', 'Assignments', (int) $caseId);
            $caseNumber = $this->notifications->caseNumber((int) $caseId);
            foreach (['head_id' => 'Head', 'secretary_id' => 'Secretary', 'member_id' => 'Member'] as $field => $role) {
                $this->notifications->notifyUser((int) $data[$field], 'Case team assignment', 'You were assigned as ' . $role . ' for ' . $caseNumber . '.', (int) $_SESSION['user_id']);
            }
        }
        return $result;
    }
}
