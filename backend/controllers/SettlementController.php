<?php

require_once __DIR__ . '/../models/Settlement.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class SettlementController
{
    private $settlement;
    private $audit;

    public function __construct()
    {
        $this->settlement = new Settlement();
        $this->audit = new AuditService();
    }

    public function create($data)
    {
        $result = $this->settlement->create($data);

        if ($result) {

            $this->audit->log(
                $_SESSION['user_id'],
                'Created Settlement',
                'Settlements',
                $data['case_id']
            );

        }

        return $result;
    }

    public function getByCase($caseId)
    {
        return $this->settlement->getByCase($caseId);
    }
}