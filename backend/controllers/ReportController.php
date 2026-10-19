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

    public function dashboard(array $params = []): array
    {
        $resolved = $this->resolvePeriod($params);
        $stats = $this->getReportModel()->dashboardStats(
            $resolved['period'],
            $resolved['start_date'],
            $resolved['end_date']
        );
        return array_merge($resolved, $stats);
    }

    private function resolvePeriod(array $params): array
    {
        $period = strtolower(trim((string) ($params['period'] ?? 'all')));
        if (!in_array($period, ['all', 'weekly', 'monthly', 'quarterly', 'annual'], true)) {
            $period = 'all';
        }

        $now = new DateTime('now');
        $offset = (int) ($params['offset'] ?? 0);
        $year = !empty($params['year']) ? (int) $params['year'] : null;
        $month = !empty($params['month']) ? (int) $params['month'] : null;
        $quarter = !empty($params['quarter']) ? (int) $params['quarter'] : null;

        $startDate = null;
        $endDate = null;
        $label = 'All Time';
        $sublabel = 'All recorded data in the system';

        if ($period === 'weekly') {
            $ref = clone $now;
            if ($offset !== 0) {
                $ref->modify("{$offset} week");
            }
            $dayOfWeek = (int) $ref->format('N'); // 1 = Monday, 7 = Sunday
            $start = clone $ref;
            $start->modify('-' . ($dayOfWeek - 1) . ' days');
            $end = clone $start;
            $end->modify('+6 days');
            $startDate = $start->format('Y-m-d');
            $endDate = $end->format('Y-m-d');
            $label = 'Week of ' . $start->format('M j') . ' – ' . $end->format('M j, Y');
            $sublabel = 'Monday to Sunday case activity';
        } elseif ($period === 'monthly') {
            $ref = clone $now;
            if ($year && $month) {
                $ref->setDate($year, $month, 1);
            } elseif ($offset !== 0) {
                $ref->modify("{$offset} month");
            }
            $startDate = $ref->format('Y-m-01');
            $endDate = $ref->format('Y-m-t');
            $label = $ref->format('F Y');
            $sublabel = 'Monthly overview for ' . $label;
        } elseif ($period === 'quarterly') {
            $ref = clone $now;
            $currQuarter = (int) ceil(((int) $ref->format('n')) / 3);
            $targetYear = $year ?: (int) $ref->format('Y');
            $targetQuarter = $quarter ?: $currQuarter;
            if ($offset !== 0 && !$quarter) {
                $targetQuarter += $offset;
                while ($targetQuarter < 1) {
                    $targetQuarter += 4;
                    $targetYear--;
                }
                while ($targetQuarter > 4) {
                    $targetQuarter -= 4;
                    $targetYear++;
                }
            }
            $startMonth = (($targetQuarter - 1) * 3) + 1;
            $startDate = sprintf('%04d-%02d-01', $targetYear, $startMonth);
            $endDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $targetYear, $startMonth + 2)));
            $label = 'Q' . $targetQuarter . ' ' . $targetYear . ' (' . date('M', strtotime($startDate)) . ' – ' . date('M', strtotime($endDate)) . ')';
            $sublabel = 'Quarterly overview for ' . $targetYear;
        } elseif ($period === 'annual') {
            $targetYear = $year ?: ((int) $now->format('Y') + $offset);
            $startDate = sprintf('%04d-01-01', $targetYear);
            $endDate = sprintf('%04d-12-31', $targetYear);
            $label = 'Year ' . $targetYear;
            $sublabel = 'Annual case records for ' . $targetYear;
        }

        return [
            'period' => $period,
            'period_label' => $label,
            'period_sublabel' => $sublabel,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'offset' => $offset,
            'year' => $year ?: (int) $now->format('Y'),
            'month' => $month ?: (int) $now->format('n'),
            'quarter' => $quarter ?: (int) ceil(((int) $now->format('n')) / 3),
        ];
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
