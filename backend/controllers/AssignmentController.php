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
        $result = $this->assignment->assign($data);

        if ($result['success']) {
            $this->audit->log($_SESSION['user_id'], 'Assigned Lupon Member', 'Assignments');
            $caseNumber = $this->notifications->caseNumber((int) $data['case_id']);
            $this->notifications->notifyUser(
                (int) $data['member_id'],
                'Case assignment',
                'You were assigned as ' . $data['assignment_role'] . ' for ' . $caseNumber . '.',
                (int) $_SESSION['user_id']
            );
        }

        return $result;
    }

    public function list(int $caseId): array
    {
        return $this->assignment->getAssignments($caseId);
    }

    public function luponMembers(): array
    {
        return $this->assignment->getLuponMembers();
    }
}
