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
        return $this->cfa->create($data, $userId);
    }

    public function recoverPdf(array $data): array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 1);
        return $this->cfa->recoverPdf($data, $userId);
    }

    public function getEligibility(int $caseId): array
    {
        return $this->cfa->determineCfaEligibility($caseId);
    }

    public function getByCase($caseId)
    {
        return $this->cfa->getByCase($caseId);
    }

    public function getAllByCase($caseId): array
    {
        return $this->cfa->getAllByCase($caseId);
    }

    public function index()
    {
        return $this->cfa->getAll();
    }
}
