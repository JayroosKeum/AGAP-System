<?php

require_once __DIR__ . '/../models/Pangkat.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/NotificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class PangkatController
{
    private $pangkat;
    private $audit;
    private $notifications;

    public function __construct()
    {
        $this->pangkat = new Pangkat();
        $this->audit = new AuditService();
        $this->notifications = new NotificationService();
    }

    public function create($caseId)
    {
        $result = $this->pangkat->create($caseId);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Created Pangkat',
                'Pangkat'
            );
            $caseNumber = $this->notifications->caseNumber((int) $caseId);
            $this->notifications->notifyRoles(['Administrator', 'Lupon Clerk'], 'Pangkat formed', 'A Pangkat group was formed for ' . $caseNumber . '.', (int) $_SESSION['user_id']);
        }

        return $result;
    }

    public function addMember(
        $pangkatId,
        $memberId,
        $position
    )
    {
        $result = $this->pangkat->addMember(
            $pangkatId,
            $memberId,
            $position
        );

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Added Pangkat Member',
                'Pangkat',
                $pangkatId
            );
            $this->notifications->notifyUser((int) $memberId, 'Pangkat appointment', 'You were appointed as Pangkat ' . $position . '.', (int) $_SESSION['user_id']);
        }

        return $result;
    }

    public function members($pangkatId)
    {
        return $this->pangkat
            ->getMembers($pangkatId);
    }

    public function index()
    {
        return $this->pangkat->getAll();
    }

    public function luponMembers()
    {
        return $this->pangkat->getLuponMembers();
    }
}
