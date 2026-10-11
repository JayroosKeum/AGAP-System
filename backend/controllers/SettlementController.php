<?php

require_once __DIR__ . '/../models/Settlement.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class SettlementController
{
    private Settlement $settlement;
    private AuditService $audit;

    public function __construct()
    {
        $this->settlement = new Settlement();
        $this->audit = new AuditService();
    }

    public function index(array $params = []): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 25;
        $filters = [
            'q' => trim((string) ($params['q'] ?? '')),
            'compliance_status' => trim((string) ($params['compliance_status'] ?? '')),
            'repudiation_status' => trim((string) ($params['repudiation_status'] ?? '')),
        ];

        $result = $this->settlement->getAll($filters, $page, $perPage);
        return [
            'success' => true,
            'data' => $result['records'],
            'pagination' => $result['pagination'],
        ];
    }

    public function show(int $id): array
    {
        if ($id < 1 || !($record = $this->settlement->getById($id))) {
            return ['success' => false, 'message' => 'Settlement record not found.'];
        }
        return ['success' => true, 'data' => $record];
    }

    public function getByCase(int $caseId): array
    {
        if ($caseId < 1) {
            return ['success' => false, 'message' => 'Invalid case ID.'];
        }
        $record = $this->settlement->getByCase($caseId);
        return ['success' => true, 'data' => $record ?: null];
    }

    public function save(array $data, int $actorUserId): array
    {
        $result = $this->settlement->saveSettlement($data, $actorUserId);
        if ($result['success']) {
            $this->audit->log(
                $actorUserId,
                'Saved Amicable Settlement',
                'Settlements',
                $result['settlement_id'] ?? null
            );
        }
        return $result;
    }

    public function recordPayment(int $installmentId, array $data, int $actorUserId): array
    {
        if ($installmentId < 1) {
            return ['success' => false, 'message' => 'Invalid installment ID.'];
        }
        $result = $this->settlement->recordInstallmentPayment($installmentId, $data, $actorUserId);
        if ($result['success']) {
            $this->audit->log(
                $actorUserId,
                'Recorded Installment Payment',
                'Settlements',
                $installmentId
            );
        }
        return $result;
    }

    public function fileRepudiation(int $settlementId, array $data, int $actorUserId): array
    {
        if ($settlementId < 1) {
            return ['success' => false, 'message' => 'Invalid settlement ID.'];
        }
        $result = $this->settlement->fileRepudiation($settlementId, $data, $actorUserId);
        if ($result['success']) {
            $this->audit->log(
                $actorUserId,
                'Filed Settlement Repudiation',
                'Settlements',
                $settlementId
            );
        }
        return $result;
    }

    public function fileExecution(int $settlementId, array $data, int $actorUserId): array
    {
        if ($settlementId < 1) {
            return ['success' => false, 'message' => 'Invalid settlement ID.'];
        }
        $result = $this->settlement->fileExecutionMotion($settlementId, $data, $actorUserId);
        if ($result['success']) {
            $this->audit->log(
                $actorUserId,
                'Filed Motion for Execution',
                'Settlements',
                $settlementId
            );
        }
        return $result;
    }

    public function updateExecution(int $executionId, array $data, int $actorUserId): array
    {
        if ($executionId < 1) {
            return ['success' => false, 'message' => 'Invalid execution ID.'];
        }
        $result = $this->settlement->updateExecutionStatus($executionId, $data, $actorUserId);
        if ($result['success']) {
            $this->audit->log(
                $actorUserId,
                'Updated Execution Status',
                'Settlements',
                $executionId
            );
        }
        return $result;
    }
}