<?php

declare(strict_types=1);

/**
 * XlsxService - Native OpenXML (.xlsx) Workbook Generator
 *
 * Generates valid Microsoft Excel (.xlsx) workbooks using PHP's native ZipArchive.
 * Zero external library dependencies required.
 */
class XlsxService
{
    /**
     * Create a standard tabular spreadsheet.
     *
     * @param string $filePath Absolute path where .xlsx will be saved.
     * @param array $headers List of column header strings.
     * @param array $rows 2D array of data rows.
     * @param string $sheetTitle Title displayed on sheet tab.
     * @return bool
     */
    public static function createTable(string $filePath, array $headers, array $rows, string $sheetTitle = 'Report'): bool
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // xl/workbook.xml
        $safeSheetName = htmlspecialchars(substr($sheetTitle, 0, 31), ENT_XML1, 'UTF-8');
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' .
            '<sheet name="' . $safeSheetName . '" sheetId="1" r:id="rId1"/>' .
            '</sheets>' .
            '</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // xl/styles.xml
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="3">' .
            '<font><sz val="10.5"/><name val="Segoe UI"/></font>' .
            '<font><b/><sz val="10.5"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>' .
            '<font><b/><sz val="14"/><color rgb="FF102B2D"/><name val="Segoe UI"/></font>' .
            '</fonts>' .
            '<fills count="4">' .
            '<fill><patternFill patternType="none"/></fill>' .
            '<fill><patternFill patternType="gray125"/></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FF102B2D"/></patternFill></fill>' . // Dark teal header
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>' . // Light zebra
            '</fills>' .
            '<borders count="2">' .
            '<border><left/><right/><top/><bottom/></border>' .
            '<border>' .
            '<left style="thin"><color rgb="FFE2E8F0"/></left>' .
            '<right style="thin"><color rgb="FFE2E8F0"/></right>' .
            '<top style="thin"><color rgb="FFE2E8F0"/></top>' .
            '<bottom style="thin"><color rgb="FFE2E8F0"/></bottom>' .
            '</border>' .
            '</borders>' .
            '<cellXfs count="4">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' . // 0: Normal
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>' . // 1: Header
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' . // 2: Data cell
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" applyFont="1"/>' . // 3: Title
            '</cellXfs>' .
            '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // xl/worksheets/sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>';

        $rowIdx = 1;

        // Header Row
        $sheetXml .= '<row r="' . $rowIdx . '" customHeight="1" ht="28">';
        $colIdx = 0;
        foreach ($headers as $h) {
            $colLetter = self::colLetter($colIdx);
            $val = htmlspecialchars((string)$h, ENT_XML1, 'UTF-8');
            $sheetXml .= '<c r="' . $colLetter . $rowIdx . '" t="inlineStr" s="1"><is><t>' . $val . '</t></is></c>';
            $colIdx++;
        }
        $sheetXml .= '</row>';
        $rowIdx++;

        // Data Rows
        foreach ($rows as $r) {
            $sheetXml .= '<row r="' . $rowIdx . '" customHeight="1" ht="20">';
            $colIdx = 0;
            foreach ($r as $val) {
                $colLetter = self::colLetter($colIdx);
                if (is_int($val) || (is_numeric($val) && !preg_match('/^0\d+/', (string)$val) && !str_contains((string)$val, '-'))) {
                    $sheetXml .= '<c r="' . $colLetter . $rowIdx . '" s="2"><v>' . $val . '</v></c>';
                } else {
                    $escaped = htmlspecialchars((string)($val ?? ''), ENT_XML1, 'UTF-8');
                    $sheetXml .= '<c r="' . $colLetter . $rowIdx . '" t="inlineStr" s="2"><is><t>' . $escaped . '</t></is></c>';
                }
                $colIdx++;
            }
            $sheetXml .= '</row>';
            $rowIdx++;
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $zip->close();
        return true;
    }

    /**
     * Create the KP Monitoring Form (.xlsx) matching the official format.
     */
    public static function createKpMonitoringForm(string $filePath, array $meta, array $summary, array $cases): bool
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' .
            '<sheet name="KP Monitoring Form" sheetId="1" r:id="rId1"/>' .
            '</sheets>' .
            '</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // Styles: 0: normal, 1: header title, 2: table header green, 3: table header sub, 4: cell bordered, 5: cell bordered bold, 6: metadata
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="5">' .
            '<font><sz val="10"/><name val="Arial"/></font>' .
            '<font><b/><sz val="12"/><name val="Arial"/></font>' .
            '<font><b/><sz val="9.5"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>' .
            '<font><b/><sz val="9.5"/><name val="Arial"/></font>' .
            '<font><b/><sz val="14"/><name val="Arial"/></font>' .
            '</fonts>' .
            '<fills count="4">' .
            '<fill><patternFill patternType="none"/></fill>' .
            '<fill><patternFill patternType="gray125"/></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FF1A365D"/></fgColor></fill>' . // Dark blue
            '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></fgColor></fill>' . // Light gray
            '</fills>' .
            '<borders count="2">' .
            '<border><left/><right/><top/><bottom/></border>' .
            '<border>' .
            '<left style="thin"><color rgb="FF000000"/></left>' .
            '<right style="thin"><color rgb="FF000000"/></right>' .
            '<top style="thin"><color rgb="FF000000"/></top>' .
            '<bottom style="thin"><color rgb="FF000000"/></bottom>' .
            '</border>' .
            '</borders>' .
            '<cellXfs count="7">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' . // 0: Normal
            '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>' . // 1: Title
            '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' . // 2: Main header
            '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' . // 3: Sub header
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' . // 4: Cell bordered center
            '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' . // 5: Cell bordered bold
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment vertical="center"/></xf>' . // 6: Meta label
            '</cellXfs>' .
            '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // Build sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>';

        $monthLabel = htmlspecialchars((string)($meta['month_label'] ?? 'FEBRUARY 2026'), ENT_XML1, 'UTF-8');
        $quarterLabel = htmlspecialchars((string)($meta['quarter_label'] ?? 'Quarter 1'), ENT_XML1, 'UTF-8');
        $cyLabel = htmlspecialchars((string)($meta['cy'] ?? '2026'), ENT_XML1, 'UTF-8');
        $dateAccomplished = htmlspecialchars((string)($meta['date_accomplished'] ?? date('F j, Y')), ENT_XML1, 'UTF-8');

        // Row 1-4: Title
        $sheetXml .= '<row r="1"><c r="A1" t="inlineStr" s="6"><is><t>REGION: NCR</t></is></c><c r="N1" t="inlineStr" s="6"><is><t>REPORTING QUARTER: ' . $quarterLabel . '</t></is></c></row>';
        $sheetXml .= '<row r="2"><c r="A2" t="inlineStr" s="6"><is><t>CITY: MARIKINA</t></is></c><c r="N2" t="inlineStr" s="6"><is><t>CY: ' . $cyLabel . '</t></is></c></row>';
        $sheetXml .= '<row r="3"><c r="A3" t="inlineStr" s="6"><is><t>BARANGAY: TUMANA</t></is></c><c r="N3" t="inlineStr" s="6"><is><t>DATE ACCOMPLISHED: ' . $dateAccomplished . '</t></is></c></row>';
        $sheetXml .= '<row r="4"/>';
        $sheetXml .= '<row r="5" customHeight="1" ht="22"><c r="F5" t="inlineStr" s="1"><is><t>LUPONG TAGAPAMAYAPA REPORT</t></is></c></row>';
        $sheetXml .= '<row r="6"><c r="F6" t="inlineStr" s="1"><is><t>SUMMARY OF CASES FILED AND ACTIONS TAKEN</t></is></c></row>';
        $sheetXml .= '<row r="7"><c r="F7" t="inlineStr" s="1"><is><t>for the period: ' . $monthLabel . '</t></is></c></row>';
        $sheetXml .= '<row r="8"/>';

        // Row 9: Table Header 1
        // Cols: A-D (Cases Filed), E-O (Actions Taken), P (Savings)
        $sheetXml .= '<row r="9" customHeight="1" ht="24">';
        $sheetXml .= '<c r="A9" t="inlineStr" s="2"><is><t>NUMBER OF CASES FILED</t></is></c>';
        $sheetXml .= '<c r="B9" s="2"/><c r="C9" s="2"/><c r="D9" s="2"/>';
        $sheetXml .= '<c r="E9" t="inlineStr" s="2"><is><t>ACTION TAKEN BY THE LUPONG TAGAPAMAYAPA</t></is></c>';
        for ($i = 5; $i <= 14; $i++) {
            $sheetXml .= '<c r="' . self::colLetter($i) . '9" s="2"/>';
        }
        $sheetXml .= '<c r="P9" t="inlineStr" s="2"><is><t>Estimated Government Savings</t></is></c>';
        $sheetXml .= '</row>';

        // Row 10: Table Header 2
        $sheetXml .= '<row r="10" customHeight="1" ht="22">';
        $sheetXml .= '<c r="A10" t="inlineStr" s="3"><is><t>Nature of Disputes</t></is></c><c r="B10" s="3"/><c r="C10" s="3"/><c r="D10" s="3"/>';
        $sheetXml .= '<c r="E10" t="inlineStr" s="3"><is><t>Settled Cases</t></is></c><c r="F10" s="3"/><c r="G10" s="3"/><c r="H10" s="3"/>';
        $sheetXml .= '<c r="I10" t="inlineStr" s="3"><is><t>Unsettled Cases</t></is></c>';
        for ($i = 9; $i <= 14; $i++) {
            $sheetXml .= '<c r="' . self::colLetter($i) . '10" s="3"/>';
        }
        $sheetXml .= '<c r="P10" t="inlineStr" s="3"><is><t>(P 9,500.00 per case)</t></is></c>';
        $sheetXml .= '</row>';

        // Row 11: Table Header 3 (Sub-columns)
        $headersRow11 = [
            'Criminal', 'Civil', 'Others', 'Total',
            'Mediation', 'Conciliation', 'Arbitration', 'Total',
            'Repudiated', 'Dropped/Withdrawn', 'Pending', 'Dismissed', 'Certified (CFA)', 'Referred', 'Total',
            'Total Savings (PHP)'
        ];
        $sheetXml .= '<row r="11" customHeight="1" ht="26">';
        foreach ($headersRow11 as $idx => $hTitle) {
            $colL = self::colLetter($idx);
            $sheetXml .= '<c r="' . $colL . '11" t="inlineStr" s="3"><is><t>' . htmlspecialchars($hTitle, ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $sheetXml .= '</row>';

        // Row 12: Data Row
        $s = $summary;
        $dataRow12 = [
            $s['criminal'] ?? 0,
            $s['civil'] ?? 0,
            $s['others'] ?? 0,
            $s['total_filed'] ?? 0,
            $s['mediation'] ?? 0,
            $s['conciliation'] ?? 0,
            $s['arbitration'] ?? 0,
            $s['total_settled'] ?? 0,
            $s['repudiated'] ?? 0,
            $s['dropped'] ?? 0,
            $s['pending'] ?? 0,
            $s['dismissed'] ?? 0,
            $s['certified'] ?? 0,
            $s['referred'] ?? 0,
            $s['total_unsettled'] ?? 0,
            $s['savings'] ?? number_format((float)(($s['total_settled'] ?? 0) * 9500), 2)
        ];

        $sheetXml .= '<row r="12" customHeight="1" ht="24">';
        foreach ($dataRow12 as $idx => $val) {
            $colL = self::colLetter($idx);
            if (is_numeric($val)) {
                $sheetXml .= '<c r="' . $colL . '12" s="4"><v>' . $val . '</v></c>';
            } else {
                $sheetXml .= '<c r="' . $colL . '12" t="inlineStr" s="4"><is><t>' . htmlspecialchars((string)$val, ENT_XML1, 'UTF-8') . '</t></is></c>';
            }
        }
        $sheetXml .= '</row>';
        $sheetXml .= '<row r="13"/>';

        // Signatories
        $preparedBy = htmlspecialchars((string)($meta['prepared_by'] ?? 'Lupon Member / Secretary'), ENT_XML1, 'UTF-8');
        $notedBy = htmlspecialchars((string)($meta['noted_by'] ?? 'HON. AKIKO LYNNE CENTENO'), ENT_XML1, 'UTF-8');
        $attestedBy = htmlspecialchars((string)($meta['attested_by'] ?? 'PAMELA CONSULTA'), ENT_XML1, 'UTF-8');

        $sheetXml .= '<row r="14"><c r="B14" t="inlineStr" s="6"><is><t>Prepared by:</t></is></c><c r="F14" t="inlineStr" s="6"><is><t>Noted by:</t></is></c><c r="L14" t="inlineStr" s="6"><is><t>Attested by:</t></is></c></row>';
        $sheetXml .= '<row r="15"/>';
        $sheetXml .= '<row r="16"><c r="B16" t="inlineStr" s="5"><is><t>' . $preparedBy . '</t></is></c><c r="F16" t="inlineStr" s="5"><is><t>' . $notedBy . '</t></is></c><c r="L16" t="inlineStr" s="5"><is><t>' . $attestedBy . '</t></is></c></row>';
        $sheetXml .= '<row r="17"><c r="B17" t="inlineStr" s="6"><is><t>Lupon Member/Secretary</t></is></c><c r="F17" t="inlineStr" s="6"><is><t>Barangay Chairman</t></is></c><c r="L17" t="inlineStr" s="6"><is><t>Barangay Secretary</t></is></c></row>';
        $sheetXml .= '<row r="18"/>';
        $sheetXml .= '<row r="19"/>';

        // Section 2: Detailed Case Records
        $sheetXml .= '<row r="20" customHeight="1" ht="22"><c r="A20" t="inlineStr" s="1"><is><t>DETAILED CASE RECORDS (' . count($cases) . ' Cases)</t></is></c></row>';

        $caseHeaders = [
            'Case No.', 'Complaint No.', 'Case Type', 'Category', 'Complaint / Dispute Title',
            'Complainant', 'Respondent', 'Incident Date', 'Date Docketed',
            'Summons Issued', 'Summons Served', 'Serving Officer', 'Delivery Status', 'Summons SLA (1-day)',
            'Mediation', 'Conciliation', 'Settlement Date', 'Compliance', 'CFA Issuance', 'CFA Reason', 'Case Status'
        ];

        $sheetXml .= '<row r="21" customHeight="1" ht="26">';
        foreach ($caseHeaders as $idx => $ch) {
            $sheetXml .= '<c r="' . self::colLetter($idx) . '21" t="inlineStr" s="2"><is><t>' . htmlspecialchars($ch, ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $sheetXml .= '</row>';

        $rIdx = 22;
        foreach ($cases as $c) {
            $sheetXml .= '<row r="' . $rIdx . '">';
            $vals = [
                $c['case_number'] ?? '',
                $c['complaint_number'] ?? '',
                $c['case_type'] ?? '',
                $c['category_name'] ?? '',
                $c['complaint_title'] ?? '',
                $c['complainant_name'] ?? '',
                $c['respondent_name'] ?? '',
                $c['incident_date'] ?? '',
                $c['docket_date'] ?? '',
                $c['summons_issued_date'] ?? '',
                $c['summons_served_date'] ?? '',
                $c['serving_officer'] ?? '',
                $c['delivery_status'] ?? '',
                $c['summons_timeliness'] ?? '',
                $c['mediation_sessions'] ?? 0,
                $c['conciliation_sessions'] ?? 0,
                $c['settlement_date'] ?? '',
                $c['compliance_status'] ?? '',
                $c['cfa_issuance_date'] ?? '',
                $c['cfa_reason'] ?? '',
                $c['case_status'] ?? ''
            ];
            foreach ($vals as $cIdx => $v) {
                $colL = self::colLetter($cIdx);
                if (is_int($v) || (is_numeric($v) && !str_contains((string)$v, '-') && !preg_match('/^0\d+/', (string)$v))) {
                    $sheetXml .= '<c r="' . $colL . $rIdx . '" s="4"><v>' . $v . '</v></c>';
                } else {
                    $sheetXml .= '<c r="' . $colL . $rIdx . '" t="inlineStr" s="4"><is><t>' . htmlspecialchars((string)($v ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>';
                }
            }
            $sheetXml .= '</row>';
            $rIdx++;
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $zip->close();
        return true;
    }

    public static function colLetter(int $colIndex): string
    {
        $letter = '';
        while ($colIndex >= 0) {
            $letter = chr(($colIndex % 26) + 65) . $letter;
            $colIndex = intval($colIndex / 26) - 1;
        }
        return $letter;
    }
}
