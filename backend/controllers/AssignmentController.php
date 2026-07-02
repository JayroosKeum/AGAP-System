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

    public function assign($data)
    {
        $result = $this->assignment->assign($data);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Assigned Lupon Member',
                'Assignments'
            );
        }

        return $result;
    }

    public function list($caseId)
    {
        return $this->assignment
            ->getAssignments($caseId);
    }
}