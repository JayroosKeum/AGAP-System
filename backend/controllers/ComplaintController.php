<?php

require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class ComplaintController
{
    private $complaint;
    private $audit;

    public function __construct()
    {
        $this->complaint = new Complaint();
        $this->audit = new AuditService();
    }

    public function index()
    {
        return $this->complaint->getAll();
    }

    public function show($id)
    {
        return $this->complaint->getById($id);
    }

    public function store($data)
    {
        $result = $this->complaint->create($data);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Created Complaint',
                'Complaints'
            );
        }

        return $result;
    }

    public function update($id, $data)
    {
        $result = $this->complaint->update($id, $data);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Updated Complaint',
                'Complaints',
                $id
            );
        }

        return $result;
    }

    public function destroy($id)
    {
        $result = $this->complaint->delete($id);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Deleted Complaint',
                'Complaints',
                $id
            );
        }

        return $result;
    }
}