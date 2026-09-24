<?php

require_once __DIR__ . '/../models/CaseModel.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class CaseController
{
    private $case;
    private $audit;

    public function __construct()
    {
        $this->case = new CaseModel();
        $this->audit = new AuditService();
    }

    public function index()
    {
        return $this->case->getAll();
    }

    public function page(int $page): array
    {
        return $this->case->getPage($page, 25);
    }

    public function show($id)
    {
        return $this->case->getById($id);
    }

    public function workspace(int $id): array
    {
        $workspace = $this->case->getWorkspace($id);
        return $workspace ? ['success' => true, 'data' => $workspace] : ['success' => false, 'message' => 'Case not found.'];
    }

    public function getDocketingError($complaintId)
    {
        return $this->case->getDocketingError($complaintId);
    }

    public function store($data)
    {
        $result = $this->case->create($data);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Created Case',
                'Cases'
            );
        }

        return $result;
    }

    public function update($id, $data)
    {
        $result = $this->case->update($id, $data);

        if (is_array($result) && !empty($result['success'])) {
            $this->audit->log(
                $_SESSION['user_id'],
                isset($data['head_id'], $data['secretary_id'], $data['member_id'])
                    ? 'Updated Case and Lupon Team'
                    : 'Updated Case',
                'Cases',
                $id
            );
        }

        return $result;
    }

    public function archive($id)
    {
        $result = $this->case->archive($id);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Archived Case',
                'Cases',
                $id
            );
        }

        return $result;
    }
}
