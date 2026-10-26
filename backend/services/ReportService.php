<?php

declare(strict_types=1);

require_once __DIR__ . '/XlsxService.php';

class ReportService
{
    public function createCsv(array $report): string
    {
        $safeLabel = preg_replace('/[^A-Za-z0-9_-]+/', '-', $report['period_label'] ?? 'report');
        $year = !empty($report['year']) ? $report['year'] : date('Y');
        $directory = dirname(__DIR__, 2) . '/storage/generated-reports/' . $year;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create report storage directory.');
        }
        $filename = strtolower($report['type'] ?? 'report') . '-' . $safeLabel . '-' . date('YmdHis') . '.csv';
        $absolutePath = $directory . '/' . $filename;
        $file = fopen($absolutePath, 'wb');
        if ($file === false) throw new RuntimeException('Unable to create report file.');
        fputcsv($file, ['AGAP ' . ($report['type'] ?? '') . ' Report']);
        fputcsv($file, ['Period', $report['period_label'] ?? '']);
        fputcsv($file, []);
        fputcsv($file, ['Summary', 'Total']);
        foreach (($report['totals'] ?? []) as $label => $value) fputcsv($file, [ucwords(str_replace('_', ' ', $label)), $value]);
        fputcsv($file, []);
        fputcsv($file, ['Case Status', 'Total']);
        foreach (($report['status_totals'] ?? []) as $status => $value) fputcsv($file, [$status, $value]);
        fputcsv($file, []);
        fputcsv($file, ['Complaint Category', 'Cases Docketed']);
        foreach (($report['categories'] ?? []) as $category) fputcsv($file, [$category['category_name'], $category['total']]);
        fputcsv($file, []);
        fputcsv($file, ['Case Number', 'Complaint Number', 'Title', 'Category', 'Type', 'Status', 'Docket Date', 'Hearings', 'Last Hearing', 'Settlement Date', 'Arbitration Award Date', 'CFA Issuance Date', 'Archived Date']);
        foreach (($report['cases'] ?? []) as $case) {
            fputcsv($file, [$case['case_number'], $case['complaint_number'], $case['complaint_title'], $case['category_name'], $case['case_type'], $case['case_status'], $case['docket_date'], $case['hearing_count'], $case['last_hearing_date'], $case['settlement_date'], $case['award_date'], $case['cfa_issuance_date'], $case['archived_date']]);
        }
        fclose($file);
        return 'storage/generated-reports/' . $year . '/' . $filename;
    }

    /**
     * createCaseRegisterExcel()
     *
     * Generates a 1-row-per-case Microsoft Excel (.xlsx) workbook.
     * Contains the exact 21 columns specified for monthly/period case register.
     *
     * @param  array  $rows     Output of Report::getCaseRegister()
     * @param  string $label    Period label, e.g. "2026-Q3" or "2026-01"
     * @return string           Relative file path (from project root)
     */
    public function createCaseRegisterExcel(array $rows, string $label = ''): string
    {
        $safeLabel = $label ? preg_replace('/[^A-Za-z0-9_-]+/', '-', $label) : date('Y');
        $year = date('Y');
        $directory = dirname(__DIR__, 2) . '/storage/generated-reports/' . $year;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create report storage directory.');
        }

        $filename = 'case-register-' . $safeLabel . '-' . date('YmdHis') . '.xlsx';
        $absolutePath = $directory . '/' . $filename;

        $headers = [
            'Case No.',
            'Complaint No.',
            'Case Type',
            'Category',
            'Complaint / Dispute Title',
            'Complainant',
            'Respondent',
            'Incident Date',
            'Date Docketed',
            'Summons Issued Date',
            'Summons Served Date',
            'Serving Officer',
            'Delivery Status',
            'Summons SLA (1-day)',
            'Mediation Sessions',
            'Conciliation Sessions',
            'Settlement Date',
            'Settlement Compliance',
            'CFA Issuance Date',
            'CFA Reason',
            'Case Status',
        ];

        $dataRows = [];
        foreach ($rows as $r) {
            $dataRows[] = [
                $r['case_number']           ?? '',
                $r['complaint_number']      ?? '',
                $r['case_type']             ?? '',
                $r['category_name']         ?? '',
                $r['complaint_title']       ?? '',
                $r['complainant_name']      ?? '',
                $r['respondent_name']       ?? '',
                $r['incident_date']         ?? '',
                $r['docket_date']           ?? '',
                $r['summons_issued_date']   ?? '',
                $r['summons_served_date']   ?? '',
                $r['serving_officer']       ?? '',
                $r['delivery_status']       ?? '',
                $r['summons_timeliness']    ?? '',
                (int)($r['mediation_sessions'] ?? 0),
                (int)($r['conciliation_sessions'] ?? 0),
                $r['settlement_date']       ?? '',
                $r['compliance_status']     ?? '',
                $r['cfa_issuance_date']     ?? '',
                $r['cfa_reason']            ?? '',
                $r['case_status']           ?? '',
            ];
        }

        $created = XlsxService::createTable($absolutePath, $headers, $dataRows, 'Case Register');
        if (!$created) {
            throw new RuntimeException('Unable to generate Excel XLSX file.');
        }

        return 'storage/generated-reports/' . $year . '/' . $filename;
    }

    /**
     * createKpMonitoringXlsx()
     *
     * Generates a KP Monitoring Form (.xlsx) workbook.
     */
    public function createKpMonitoringXlsx(array $meta, array $summary, array $cases): string
    {
        $safeMonth = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($meta['month_label'] ?? 'KP-Report'));
        $year = (string)($meta['cy'] ?? date('Y'));
        $directory = dirname(__DIR__, 2) . '/storage/generated-reports/' . $year;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create report storage directory.');
        }

        $filename = 'kp-monitoring-' . $safeMonth . '-' . date('YmdHis') . '.xlsx';
        $absolutePath = $directory . '/' . $filename;

        $created = XlsxService::createKpMonitoringForm($absolutePath, $meta, $summary, $cases);
        if (!$created) {
            throw new RuntimeException('Unable to generate KP Monitoring Excel file.');
        }

        return 'storage/generated-reports/' . $year . '/' . $filename;
    }
}
