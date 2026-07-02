<?php

require_once __DIR__ . '/../config/database.php';

class Report
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function dashboardStats()
    {
        return [

            'total_cases' => $this->conn->query(
                "SELECT COUNT(*) FROM cases"
            )->fetchColumn(),

            'settled_cases' => $this->conn->query(
                "SELECT COUNT(*) FROM cases
                 WHERE case_status='Settled'"
            )->fetchColumn(),

            'cfa_cases' => $this->conn->query(
                "SELECT COUNT(*) FROM cases
                 WHERE case_status='CFA Issued'"
            )->fetchColumn(),

            'archived_cases' => $this->conn->query(
                "SELECT COUNT(*) FROM cases
                 WHERE case_status='Archived'"
            )->fetchColumn()

        ];
    }
}