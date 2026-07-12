<?php

require_once __DIR__ . '/../models/Assignment.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AssignmentController
{
    private $assignment;
    private $audit;

    public function __construct()
    {
        $this->assignment = new Assignment();
        $this->audit = new AuditService();
    }

    public function assign(array $data): array
    {
        $result = $this->assignment->assign($data);

        if ($result['success']) {
            $this->audit->log($_SESSION['user_id'], 'Assigned Lupon Member', 'Assignments');
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
