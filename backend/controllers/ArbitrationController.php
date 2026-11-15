<?php

require_once __DIR__ . '/../models/Arbitration.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class ArbitrationController
{
    private Arbitration $arbitration;

    public function __construct(?Arbitration $arbitration = null)
    {
        $this->arbitration = $arbitration ?: new Arbitration();
    }

    public function create(array $data): array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 1);
        return $this->arbitration->issueAward($data, $userId);
    }

    public function recordAgreement(array $data): array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 1);
        return $this->arbitration->recordAgreement($data, $userId);
    }

    public function repudiateAgreement(array $data): array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 1);
        return $this->arbitration->repudiateAgreement($data, $userId);
    }

    public function getByCase(int $caseId): array|false
    {
        return $this->arbitration->getByCase($caseId);
    }
}
