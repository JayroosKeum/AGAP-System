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

    /**
     * exportCaseRegister()
     *
     * Generates a 1-row-per-case Excel Case Register with summons timeliness.
     */
    public function exportCaseRegister(array $filters, int $userId): array
    {
        $startDate = !empty($filters['start_date']) ? date('Y-m-d', strtotime($filters['start_date'])) : null;
        $endDate   = !empty($filters['end_date'])   ? date('Y-m-d', strtotime($filters['end_date']))   : null;

        if ($startDate === false) $startDate = null;
        if ($endDate === false)   $endDate   = null;

        $label = '';
        if ($startDate && $endDate) {
            $label = $startDate . '_to_' . $endDate;
        } elseif ($startDate) {
            $label = 'from_' . $startDate;
        } elseif ($endDate) {
            $label = 'to_' . $endDate;
        } else {
            $label = 'all_' . date('Y');
        }

        try {
            $rows     = $this->getReportModel()->getCaseRegister($startDate, $endDate);
            $filePath = $this->service->createCaseRegisterExcel($rows, $label);
            $reportId = $this->getReportModel()->createGeneratedReport('Case Register', $userId, $filePath);
            $this->getAuditService()->log($userId, 'Exported Case Register (Excel)', 'Reports', $reportId);
            return [
                'success'      => true,
                'message'      => 'Case Register exported successfully (' . count($rows) . ' case(s)).',
                'report_id'    => $reportId,
                'row_count'    => count($rows),
                'download_url' => '../../../backend/api/reports/download.php?id=' . $reportId,
            ];
        } catch (Throwable $e) {
            error_log('[exportCaseRegister] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to generate the Case Register. Please try again.'];
        }
    }

    public function exportKpMonitoring(array $payload, int $userId): array
    {
        $meta = $payload['meta'] ?? [];
        $summary = $payload['summary'] ?? [];
        $startDate = !empty($payload['start_date']) ? $payload['start_date'] : null;
        $endDate   = !empty($payload['end_date'])   ? $payload['end_date']   : null;

        try {
            $cases = $this->getReportModel()->getCaseRegister($startDate, $endDate);
            $filePath = $this->service->createKpMonitoringXlsx($meta, $summary, $cases);
            $reportId = $this->getReportModel()->createGeneratedReport('KP Monitoring Form', $userId, $filePath);
            $this->getAuditService()->log($userId, 'Exported KP Monitoring Form (Excel)', 'Reports', $reportId);
            return [
                'success'      => true,
                'message'      => 'KP Monitoring Form exported to Excel successfully (' . count($cases) . ' case(s)).',
                'report_id'    => $reportId,
                'download_url' => '../../../backend/api/reports/download.php?id=' . $reportId,
            ];
        } catch (Throwable $e) {
            error_log('[exportKpMonitoring] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to generate KP Monitoring Form Excel. Please try again.'];
        }
    }

    public function getDownload(int $reportId): ?array
    {
        return $this->getReportModel()->getGeneratedReport($reportId);
    }

    private function validateFilters(array $filters): array
    {
        $rawType = trim((string) ($filters['type'] ?? ''));
        $norm = strtolower($rawType);
        $typeMap = [
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'annual' => 'Annual',
            'dilg' => 'DILG',
            'weekly' => 'Weekly',
            'all' => 'All Time',
            'all time' => 'All Time',
        ];

        if (!isset($typeMap[$norm])) {
            return ['success' => false, 'message' => 'Choose a valid report type.'];
        }
        $type = $typeMap[$norm];

        $now = new DateTime('now');
        $year = filter_var($filters['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => (int) date('Y') + 5]]);
        if ($year === false) {
            $year = (int) $now->format('Y');
        }

        $start = !empty($filters['start_date']) ? $filters['start_date'] : null;
        $end = !empty($filters['end_date']) ? $filters['end_date'] : null;
        $label = !empty($filters['period_label']) ? $filters['period_label'] : null;
        $month = null;
        $quarter = null;

        if ($type === 'Monthly') {
            $month = filter_var($filters['month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
            if ($month === false && $start) {
                $month = (int) date('n', strtotime($start));
            } elseif ($month === false) {
                $month = (int) $now->format('n');
            }
            if (!$start || !$end) {
                $start = sprintf('%04d-%02d-01', $year, $month);
                $end = date('Y-m-t', strtotime($start));
            }
            if (!$label) {
                $label = date('F Y', strtotime($start));
            }
        } elseif ($type === 'Quarterly') {
            $quarter = filter_var($filters['quarter'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
            if ($quarter === false && $start) {
                $quarter = (int) ceil(date('n', strtotime($start)) / 3);
            } elseif ($quarter === false) {
                $quarter = (int) ceil(((int) $now->format('n')) / 3);
            }
            if (!$start || !$end) {
                $firstMonth = (($quarter - 1) * 3) + 1;
                $start = sprintf('%04d-%02d-01', $year, $firstMonth);
                $end = date('Y-m-t', strtotime($year . '-' . ($firstMonth + 2) . '-01'));
            }
            if (!$label) {
                $label = 'Quarter ' . $quarter . ' ' . $year;
            }
        } elseif ($type === 'Weekly') {
            if (!$start || !$end) {
                $dayOfWeek = (int) $now->format('N');
                $refStart = clone $now;
                $refStart->modify('-' . ($dayOfWeek - 1) . ' days');
                $refEnd = clone $refStart;
                $refEnd->modify('+6 days');
                $start = $refStart->format('Y-m-d');
                $end = $refEnd->format('Y-m-d');
            }
            if (!$label) {
                $label = 'Week of ' . date('M j', strtotime($start)) . ' – ' . date('M j, Y', strtotime($end));
            }
        } elseif ($type === 'All Time') {
            $start = null;
            $end = null;
            $label = $label ?: 'All Time';
        } else {
            // Annual or DILG
            if (!$start || !$end) {
                $start = $year . '-01-01';
                $end = $year . '-12-31';
            }
            if (!$label) {
                $label = ($type === 'DILG' ? 'DILG Annual Report ' : 'Annual Report ') . $year;
            }
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
