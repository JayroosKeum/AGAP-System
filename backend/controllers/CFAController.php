<?php

require_once __DIR__ . '/../models/CFA.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class CFAController
{
    private $cfa;
    private $audit;

    public function __construct()
    {
        $this->cfa = new CFA();
        $this->audit = new AuditService();
    }

    public function create($data)
    {
        $result = $this->cfa->create($data);

        if ($result) {

            $this->audit->log(
                $_SESSION['user_id'],
                'Issued Certification To File Action',
                'CFA',
                $data['case_id']
            );

        }

        return $result;
    }

    public function getByCase($caseId)
    {
        return $this->cfa->getByCase($caseId);
    }
}