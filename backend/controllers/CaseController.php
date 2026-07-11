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

    public function show($id)
    {
        return $this->case->getById($id);
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

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Updated Case',
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
