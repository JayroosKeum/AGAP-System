<?php

require_once __DIR__ . '/../models/Report.php';

class ReportController
{
    private $report;

    public function __construct()
    {
        $this->report = new Report();
    }

    public function dashboard()
    {
        return $this->report
            ->dashboardStats();
    }
}