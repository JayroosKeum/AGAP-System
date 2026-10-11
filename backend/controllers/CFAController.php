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
        $userId = (int) ($_SESSION['user_id'] ?? 1);
        $result = $this->cfa->create($data, $userId);

        if (!empty($result['success'])) {
            $this->audit->log(
                $userId,
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

    public function index()
    {
        return $this->cfa->getAll();
    }
}