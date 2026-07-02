<?php

require_once __DIR__ . '/../models/Arbitration.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class ArbitrationController
{
    private $arbitration;
    private $audit;

    public function __construct()
    {
        $this->arbitration = new Arbitration();
        $this->audit = new AuditService();
    }

    public function create($data)
    {
        $result = $this->arbitration->create($data);

        if ($result) {

            $this->audit->log(
                $_SESSION['user_id'],
                'Created Arbitration Award',
                'Arbitration',
                $data['case_id']
            );

        }

        return $result;
    }

    public function getByCase($caseId)
    {
        return $this->arbitration->getByCase($caseId);
    }
}