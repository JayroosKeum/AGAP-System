<?php

class ReportService
{
    public function createCsv(array $report): string
    {
        $safeLabel = preg_replace('/[^A-Za-z0-9_-]+/', '-', $report['period_label']);
        $directory = dirname(__DIR__, 2) . '/storage/generated-reports/' . $report['year'];
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create report storage directory.');
        }
        $filename = strtolower($report['type']) . '-' . $safeLabel . '-' . date('YmdHis') . '.csv';
        $absolutePath = $directory . '/' . $filename;
        $file = fopen($absolutePath, 'wb');
        if ($file === false) throw new RuntimeException('Unable to create report file.');
        fputcsv($file, ['AGAP ' . $report['type'] . ' Report']);
        fputcsv($file, ['Period', $report['period_label']]);
        fputcsv($file, []);
        fputcsv($file, ['Summary', 'Total']);
        foreach ($report['totals'] as $label => $value) fputcsv($file, [ucwords(str_replace('_', ' ', $label)), $value]);
        fputcsv($file, []);
        fputcsv($file, ['Case Status', 'Total']);
        foreach ($report['status_totals'] as $status => $value) fputcsv($file, [$status, $value]);
        fputcsv($file, []);
        fputcsv($file, ['Complaint Category', 'Cases Docketed']);
        foreach ($report['categories'] as $category) fputcsv($file, [$category['category_name'], $category['total']]);
        fputcsv($file, []);
        fputcsv($file, ['Case Number', 'Complaint Number', 'Title', 'Category', 'Type', 'Status', 'Docket Date', 'Hearings', 'Last Hearing', 'Settlement Date', 'Arbitration Award Date', 'CFA Issuance Date', 'Archived Date']);
        foreach ($report['cases'] as $case) {
            fputcsv($file, [$case['case_number'], $case['complaint_number'], $case['complaint_title'], $case['category_name'], $case['case_type'], $case['case_status'], $case['docket_date'], $case['hearing_count'], $case['last_hearing_date'], $case['settlement_date'], $case['award_date'], $case['cfa_issuance_date'], $case['archived_date']]);
        }
        fclose($file);
        return 'storage/generated-reports/' . $report['year'] . '/' . $filename;
    }
}
