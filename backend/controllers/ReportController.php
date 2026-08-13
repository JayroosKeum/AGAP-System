<?php

require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/ReportService.php';

class ReportController
{
    private $report;
    private $audit;
    private $service;

    public function __construct()
    {
        $this->service = new ReportService();
    }

    public function dashboard(): array
    {
        return $this->getReportModel()->dashboardStats();
    }

    public function generate(array $filters): array
    {
        $validated = $this->validateFilters($filters);
        if (!$validated['success']) {
            return $validated;
        }
        $data = $this->getReportModel()->getReport($validated['start_date'], $validated['end_date']);
        return ['success' => true, 'data' => array_merge($validated['data'], $data)];
    }

    public function export(array $filters, int $userId): array
    {
        $generated = $this->generate($filters);
        if (!$generated['success']) {
            return $generated;
        }
        try {
            $filePath = $this->service->createCsv($generated['data']);
            $reportId = $this->getReportModel()->createGeneratedReport($generated['data']['type'], $userId, $filePath);
            $this->getAuditService()->log($userId, 'Exported ' . $generated['data']['type'] . ' report', 'Reports', $reportId);
            return [
                'success' => true,
                'message' => 'CSV report generated successfully.',
                'report_id' => $reportId,
                'download_url' => '../../../backend/api/reports/download.php?id=' . $reportId,
            ];
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to generate the CSV report.'];
        }
    }

    public function getDownload(int $reportId): ?array
    {
        return $this->getReportModel()->getGeneratedReport($reportId);
    }

    private function validateFilters(array $filters): array
    {
        $type = trim((string) ($filters['type'] ?? ''));
        $allowed = ['Monthly', 'Quarterly', 'Annual', 'DILG'];
        if (!in_array($type, $allowed, true)) {
            return ['success' => false, 'message' => 'Choose a valid report type.'];
        }
        $year = filter_var($filters['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => (int) date('Y') + 1]]);
        if ($year === false) {
            return ['success' => false, 'message' => 'Choose a valid report year.'];
        }
        $month = null;
        $quarter = null;
        if ($type === 'Monthly') {
            $month = filter_var($filters['month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
            if ($month === false) return ['success' => false, 'message' => 'Choose a valid month.'];
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = date('Y-m-t', strtotime($start));
            $label = date('F Y', strtotime($start));
        } elseif ($type === 'Quarterly') {
            $quarter = filter_var($filters['quarter'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
            if ($quarter === false) return ['success' => false, 'message' => 'Choose a valid quarter.'];
            $firstMonth = (($quarter - 1) * 3) + 1;
            $start = sprintf('%04d-%02d-01', $year, $firstMonth);
            $end = date('Y-m-t', strtotime($year . '-' . ($firstMonth + 2) . '-01'));
            $label = 'Quarter ' . $quarter . ' ' . $year;
        } else {
            $start = $year . '-01-01';
            $end = $year . '-12-31';
            $label = ($type === 'DILG' ? 'DILG Annual Report ' : 'Annual Report ') . $year;
        }
        return ['success' => true, 'start_date' => $start, 'end_date' => $end, 'data' => [
            'type' => $type, 'year' => (int) $year, 'month' => $month, 'quarter' => $quarter,
            'period_label' => $label, 'start_date' => $start, 'end_date' => $end,
        ]];
    }

    private function getReportModel(): Report
    {
        if ($this->report === null) {
            $this->report = new Report();
        }
        return $this->report;
    }

    private function getAuditService(): AuditService
    {
        if ($this->audit === null) {
            $this->audit = new AuditService();
        }
        return $this->audit;
    }
}
