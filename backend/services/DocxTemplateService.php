<?php
/**
 * AGAP System — DOCX Template Integration & Document Processing Service
 * Provides full lifecycle management for KP Master DOCX templates:
 * - DOCX structure inspection (headers, footers, page dimensions, margins, placeholders)
 * - Shared Barangay Letterhead & Branding application (with body-only template support)
 * - Centralized and form-specific paper settings & precedence enforcement
 * - Case data placeholder replacement
 * - Document generation and synchronized HTML/PDF previews
 */

class DocxTemplateService
{
    private static string $storageDir = '';

    public static function getStorageDir(): string
    {
        if (self::$storageDir === '') {
            self::$storageDir = dirname(__DIR__, 2) . '/storage/uploads/templates';
        }
        if (!is_dir(self::$storageDir)) {
            @mkdir(self::$storageDir, 0777, true);
        }
        return self::$storageDir;
    }

    public static function getRegistryFile(): string
    {
        return self::getStorageDir() . '/templates_registry.json';
    }

    public static function getRegistry(): array
    {
        $file = self::getRegistryFile();
        if (file_exists($file)) {
            $data = json_decode(@file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }

    public static function saveRegistry(array $registry): bool
    {
        $file = self::getRegistryFile();
        return (bool) @file_put_contents($file, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Extracts a file from a ZIP / DOCX archive using ZipArchive if available,
     * with an automatic pure PHP PKZIP fallback if the PHP extension is absent.
     */
    public static function extractZipFileContent(string $zipFilePath, string $internalPath): ?string
    {
        if (!file_exists($zipFilePath) || !is_readable($zipFilePath)) {
            return null;
        }

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFilePath) === true) {
                $content = $zip->getFromName($internalPath);
                $zip->close();
                if ($content !== false) {
                    return $content;
                }
            }
        }

        return self::extractFromZipPurePhp($zipFilePath, $internalPath);
    }

    /**
     * Pure PHP PKZIP extractor for DEFLATE and uncompressed streams (no ZipArchive required).
     */
    public static function extractFromZipPurePhp(string $zipFilePath, string $targetFile): ?string
    {
        $fp = @fopen($zipFilePath, 'rb');
        if (!$fp) return null;

        while (!feof($fp)) {
            $sig = fread($fp, 4);
            if ($sig !== "PK\x03\x04") {
                break;
            }
            $rawHeader = fread($fp, 26);
            if (strlen($rawHeader) < 26) break;
            $header = unpack('vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompSize/VuncompSize/vfnLen/vextraLen', $rawHeader);
            $filename = fread($fp, $header['fnLen']);
            if ($header['extraLen'] > 0) {
                fread($fp, $header['extraLen']);
            }
            $compData = ($header['compSize'] > 0) ? fread($fp, $header['compSize']) : '';

            if ($filename === $targetFile) {
                fclose($fp);
                if ($header['method'] == 0) {
                    return $compData;
                } elseif ($header['method'] == 8) {
                    $uncompressed = @gzinflate($compData);
                    return $uncompressed !== false ? $uncompressed : null;
                }
                return null;
            }
        }
        fclose($fp);
        return null;
    }

    /**
     * Inspects an uploaded or existing DOCX file structure.
     */
    public static function inspectDocx(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [
                'valid' => false,
                'error' => 'File does not exist or is not readable.'
            ];
        }

        $docXml = self::extractZipFileContent($filePath, 'word/document.xml');
        if ($docXml === null) {
            return [
                'valid' => false,
                'error' => 'Invalid DOCX structure: word/document.xml not found.'
            ];
        }

        // 1. Detect headers and footers
        $hasHeader = false;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $name = $stat['name'] ?? '';
                    if (preg_match('#^word/header\d+\.xml$#i', $name)) {
                        $hasHeader = true;
                        break;
                    }
                }
                $zip->close();
            }
        }
        if (!$hasHeader && preg_match('#<w:headerReference\b#i', $docXml)) {
            $hasHeader = true;
        }
        // 2. Detect page dimensions & orientation from <w:pgSz>
        // 1 inch = 1440 twips; 1 mm = 56.69 twips
        $pageSize = 'Legal'; // AGAP default
        $orientation = 'portrait';
        $widthTwips = 12240; // 8.5"
        $heightTwips = 20160; // 14"

        if (preg_match('#<w:pgSz\b([^>]*)/?>#i', $docXml, $pgSzMatch)) {
            $attrs = $pgSzMatch[1];
            if (preg_match('#w:w=["\'](\d+)["\']#i', $attrs, $wM)) {
                $widthTwips = (int) $wM[1];
            }
            if (preg_match('#w:h=["\'](\d+)["\']#i', $attrs, $hM)) {
                $heightTwips = (int) $hM[1];
            }
            if (preg_match('#w:orient=["\']([a-zA-Z]+)["\']#i', $attrs, $oM)) {
                $orientation = strtolower($oM[1]);
            }
        }

        // Classify standard paper sizes
        // Legal: ~12240 x 20160 twips
        // A4: ~11906 x 16838 twips
        // Letter: ~12240 x 15840 twips
        $wIn = round($widthTwips / 1440, 2);
        $hIn = round($heightTwips / 1440, 2);

        if (abs($wIn - 8.5) < 0.3 && abs($hIn - 14.0) < 0.3) {
            $pageSize = 'Legal';
        } elseif (abs($wIn - 8.27) < 0.3 && abs($hIn - 11.69) < 0.3) {
            $pageSize = 'A4';
        } elseif (abs($wIn - 8.5) < 0.3 && abs($hIn - 11.0) < 0.3) {
            $pageSize = 'Letter';
        } else {
            $pageSize = 'Custom (' . $wIn . '" x ' . $hIn . '")';
        }

        // 3. Detect Margins from <w:pgMar>
        $marginsMm = [
            'top' => 15,
            'bottom' => 15,
            'left' => 18,
            'right' => 18
        ];
        if (preg_match('#<w:pgMar\b([^>]*)/?>#i', $docXml, $pgMarMatch)) {
            $mAttrs = $pgMarMatch[1];
            if (preg_match('#w:top=["\'](\d+)["\']#i', $mAttrs, $tm)) {
                $marginsMm['top'] = (int) round(((int)$tm[1]) / 56.7);
            }
            if (preg_match('#w:bottom=["\'](\d+)["\']#i', $mAttrs, $bm)) {
                $marginsMm['bottom'] = (int) round(((int)$bm[1]) / 56.7);
            }
            if (preg_match('#w:left=["\'](\d+)["\']#i', $mAttrs, $lm)) {
                $marginsMm['left'] = (int) round(((int)$lm[1]) / 56.7);
            }
            if (preg_match('#w:right=["\'](\d+)["\']#i', $mAttrs, $rm)) {
                $marginsMm['right'] = (int) round(((int)$rm[1]) / 56.7);
            }
        }

        // 4. Extract Text & Detect Placeholders
        $cleanText = strip_tags($docXml);
        preg_match_all('#\{([a-zA-Z0-9_\-\.]+)\}#', $cleanText, $matches);
        $placeholders = array_values(array_unique($matches[1] ?? []));

        // 5. Structure counts
        $paragraphsCount = substr_count($docXml, '<w:p ') + substr_count($docXml, '<w:p>');
        $tablesCount = substr_count($docXml, '<w:tbl ') + substr_count($docXml, '<w:tbl>');

        return [
            'valid' => true,
            'file_name' => basename($filePath),
            'file_size' => filesize($filePath),
            'has_header' => $hasHeader,
            'header_type' => $hasHeader ? 'embedded' : 'none',
            'is_body_only' => !$hasHeader,
            'page_size' => $pageSize,
            'width_twips' => $widthTwips,
            'height_twips' => $heightTwips,
            'orientation' => $orientation,
            'margins_mm' => $marginsMm,
            'placeholders' => $placeholders,
            'paragraphs_count' => $paragraphsCount,
            'tables_count' => $tablesCount
        ];
    }

    /**
     * Centralized branding default configuration.
     */
    public static function getSharedBrandingDefaults(): array
    {
        return [
            'version' => '1.0',
            'republic_heading' => 'REPUBLIKA NG PILIPINAS',
            'province_name' => 'LALAWIGAN NG RIZAL',
            'city_name' => 'LUNGSOD NG MARIKINA',
            'barangay_name' => 'BARANGAY TUMANA',
            'office_title' => 'TANGGAPAN NG LUPONG TAGAPAMAYAPA',
            'address' => 'Barangay Hall, Tumana, Marikina City, Metro Manila',
            'phone' => '(02) 8942-0123 / 0917-123-4567',
            'email' => 'barangay.tumana@marikina.gov.ph',
            'logo_primary' => 'tumana_logo.jpg',
            'logo_secondary' => 'marikina_logo.jpg',
            'logo_position' => 'both_sides', // 'left', 'both_sides', 'centered'
            'logo_size_mm' => 20,
            'header_font' => 'Arial',
            'header_align' => 'center',
            'header_spacing_mm' => 10,
            'footer_text' => 'Barangay Tumana — Katarungang Pambarangay Automated Grievance Platform',
            'page_numbering' => true,
            'officers' => [
                'punong_barangay' => [
                    'name' => 'HON. FRANCISCO G. SANDOVAL',
                    'title' => 'Punong Barangay / Lupon Chairman',
                    'signature_image' => ''
                ],
                'barangay_secretary' => [
                    'name' => 'MARIA CRISTINA SANTOS',
                    'title' => 'Barangay Secretary',
                    'signature_image' => ''
                ],
                'pangkat_secretary' => [
                    'name' => 'ANTONIO V. REYES',
                    'title' => 'Kalihim ng Pangkat',
                    'signature_image' => ''
                ]
            ]
        ];
    }

    /**
     * Centralized paper and print default configuration.
     */
    public static function getSharedPaperDefaults(): array
    {
        return [
            'paper_size' => 'Legal', // Legal (8.5" x 14") is official KP statutory format
            'orientation' => 'portrait',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 18,
            'margin_right' => 18,
            'font_family' => 'Arial',
            'font_size_pt' => 11,
            'header_spacing_mm' => 10,
            'footer_spacing_mm' => 10,
            'scaling' => 'fit_page',
            'page_break_rules' => 'keep_together_signatures'
        ];
    }

    /**
     * Resolves effective paper settings honoring precedence:
     * 1. Form-specific paper profile (if configured)
     * 2. Shared paper settings
     */
    public static function resolveEffectivePaperSettings(array $formConfig, array $sharedSettings): array
    {
        if (!empty($formConfig['custom_paper']) && is_array($formConfig['custom_paper'])) {
            return array_merge($sharedSettings, $formConfig['custom_paper']);
        }
        return $sharedSettings;
    }

    /**
     * Resolves the absolute disk path of a branding logo.
     */
    public static function resolveLogoPath(?string $logoName, string $fallback = 'tumana_logo.jpg'): string
    {
        $root = dirname(__DIR__, 2);
        $checkList = [];
        if (!empty($logoName)) {
            if (str_starts_with($logoName, 'storage/')) {
                $checkList[] = $root . '/' . ltrim($logoName, '/');
            } else {
                $checkList[] = $root . '/storage/uploads/branding/' . basename($logoName);
                $checkList[] = $root . '/frontend/assets/images/' . basename($logoName);
            }
        }
        if (!empty($fallback)) {
            $checkList[] = $root . '/frontend/assets/images/' . basename($fallback);
        }
        foreach ($checkList as $c) {
            if (file_exists($c) && is_file($c)) {
                return $c;
            }
        }
        return '';
    }

    /**
     * Returns a base64 data URI for an official logo.
     */
    public static function getLogoDataUri(?string $logoName, string $fallback = ''): string
    {
        $path = self::resolveLogoPath($logoName, $fallback);
        if ($path && file_exists($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : (($ext === 'svg') ? 'image/svg+xml' : 'image/jpeg'));
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
        }
        return '';
    }

    /**
     * Converts an uploaded or master DOCX body (word/document.xml) into high-fidelity HTML,
     * faithfully reproducing paragraph alignments, bold, italic, tables, and spacing.
     */
    public static function convertDocxBodyToHtml(string $docxFilePath, array $replacements = []): string
    {
        $xml = self::extractZipFileContent($docxFilePath, 'word/document.xml');
        if ($xml === null || trim($xml) === '') {
            return '';
        }

        if (!empty($replacements)) {
            foreach ($replacements as $k => $v) {
                $tag = '{' . trim((string)$k, '{}') . '}';
                $xml = str_replace($tag, htmlspecialchars((string)$v), $xml);
            }
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $bodyNodes = $xpath->query('/w:document/w:body/*');
        if (!$bodyNodes || $bodyNodes->length === 0) {
            return '';
        }

        $html = '<div class="docx-rendered-body" style="font-size: 10.5pt; line-height: 1.35; font-family: Arial, Helvetica, sans-serif;">';
        $count = $bodyNodes->length;
        for ($i = 0; $i < $count; $i++) {
            $node = $bodyNodes->item($i);
            if ($node->nodeName === 'w:tbl') {
                $html .= self::renderDocxTableToHtml($node, $xpath);
                continue;
            }
            if ($node->nodeName !== 'w:p') {
                continue;
            }

            // Detect signature underline paired with following title/officer designation
            $rawText = trim($node->textContent);
            $isUnderline = (bool) preg_match('/^[_\s\x{2014}\x{2013}\-]{4,}$/u', $rawText);
            if ($isUnderline && ($i + 1 < $count)) {
                $targetIdx = $i + 1;
                $targetNode = $bodyNodes->item($targetIdx);
                if (trim($targetNode->textContent) === '' && ($targetIdx + 1 < $count)) {
                    $targetIdx++;
                    $targetNode = $bodyNodes->item($targetIdx);
                }
                if ($targetNode->nodeName === 'w:p') {
                    $nextRawText = trim($targetNode->textContent);
                    if ($nextRawText !== '' && mb_strlen($nextRawText) < 80 && !preg_match('/^[_\s\x{2014}\x{2013}\-]{4,}$/u', $nextRawText)) {
                        $jcNodes = $xpath->query('.//w:pPr/w:jc/@w:val', $node);
                        $align = ($jcNodes->length > 0) ? $jcNodes->item(0)->nodeValue : 'right';
                        $justify = ($align === 'left') ? 'flex-start' : (($align === 'center') ? 'center' : 'flex-end');

                        $nextHtml = self::renderDocxParagraphInnerHtml($targetNode, $xpath);
                        $html .= '<div class="docx-sig-block" style="display: flex; justify-content: ' . $justify . '; margin-top: 14px; margin-bottom: 4px; page-break-inside: avoid;">' . "\n";
                        $html .= '  <div style="min-width: 250px; max-width: 340px; text-align: center;">' . "\n";
                        $html .= '    <div style="font-weight: bold; margin-bottom: 2px; word-break: break-all;">' . htmlspecialchars($rawText) . '</div>' . "\n";
                        $html .= '    <div style="font-size: 10pt; font-weight: bold; color: #1e293b;">' . $nextHtml . '</div>' . "\n";
                        $html .= '  </div>' . "\n";
                        $html .= '</div>' . "\n";

                        $i = $targetIdx;
                        continue;
                    }
                }
            }

            // Suppress trailing empty paragraphs at the end of the document to prevent multi-sheet spilling
            if ($rawText === '' && $i >= $count - 2) {
                continue;
            }

            $html .= self::renderDocxParagraphToHtml($node, $xpath);
        }
        $html .= '</div>';
        return $html;
    }

    private static function renderDocxParagraphInnerHtml(DOMElement $node, DOMXPath $xpath): string
    {
        $runs = $xpath->query('.//w:r', $node);
        $paraText = '';
        foreach ($runs as $r) {
            $isBold = $xpath->query('.//w:rPr/w:b', $r)->length > 0;
            $isItalic = $xpath->query('.//w:rPr/w:i', $r)->length > 0;
            $isUnderline = $xpath->query('.//w:rPr/w:u', $r)->length > 0;

            $textNodes = $xpath->query('.//w:t', $r);
            $runText = '';
            foreach ($textNodes as $t) {
                $runText .= htmlspecialchars($t->nodeValue);
            }
            if ($runText === '') continue;

            if ($isBold) $runText = "<strong>$runText</strong>";
            if ($isItalic) $runText = "<em>$runText</em>";
            if ($isUnderline) $runText = "<u>$runText</u>";

            $paraText .= $runText;
        }
        return trim($paraText);
    }

    private static function renderDocxParagraphToHtml(DOMElement $node, DOMXPath $xpath): string
    {
        $jcNodes = $xpath->query('.//w:pPr/w:jc/@w:val', $node);
        $alignVal = ($jcNodes->length > 0) ? $jcNodes->item(0)->nodeValue : 'left';
        $textAlign = 'left';
        if ($alignVal === 'center') $textAlign = 'center';
        elseif ($alignVal === 'right') $textAlign = 'right';
        elseif ($alignVal === 'both') $textAlign = 'justify';

        $afterNodes = $xpath->query('.//w:pPr/w:spacing/@w:after', $node);
        $spacingAfterPt = ($afterNodes->length > 0) ? min(8, max(1, (int)$afterNodes->item(0)->nodeValue / 20)) : 3;

        $indNodes = $xpath->query('.//w:pPr/w:ind/@w:firstLine', $node);
        $firstLineIndent = ($indNodes->length > 0 && $textAlign !== 'center') ? ((int)$indNodes->item(0)->nodeValue / 20) . 'pt' : '';

        $runs = $xpath->query('.//w:r', $node);
        $paraText = '';
        foreach ($runs as $r) {
            $isBold = $xpath->query('.//w:rPr/w:b', $r)->length > 0;
            $isItalic = $xpath->query('.//w:rPr/w:i', $r)->length > 0;
            $isUnderline = $xpath->query('.//w:rPr/w:u', $r)->length > 0;

            $szNodes = $xpath->query('.//w:rPr/w:sz/@w:val', $r);
            $fontSize = ($szNodes->length > 0) ? ((int)$szNodes->item(0)->nodeValue / 2) . 'pt' : '';

            $textNodes = $xpath->query('.//w:t', $r);
            $runText = '';
            foreach ($textNodes as $t) {
                $runText .= htmlspecialchars($t->nodeValue);
            }

            $brNodes = $xpath->query('.//w:br', $r);
            if ($brNodes->length > 0) {
                $runText .= str_repeat('<br>', $brNodes->length);
            }

            $tabNodes = $xpath->query('.//w:tab', $r);
            if ($tabNodes->length > 0) {
                $runText = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $tabNodes->length) . $runText;
            }

            if ($runText === '') continue;

            if ($isBold) $runText = "<strong>$runText</strong>";
            if ($isItalic) $runText = "<em>$runText</em>";
            if ($isUnderline) $runText = "<u>$runText</u>";
            if ($fontSize) $runText = "<span style=\"font-size: $fontSize;\">$runText</span>";

            $paraText .= $runText;
        }

        if (trim(strip_tags($paraText)) === '') {
            return "<p style=\"min-height: 0.6em; margin: 1px 0 2pt 0;\">&nbsp;</p>\n";
        }

        $style = "text-align: $textAlign; margin: 2px 0 {$spacingAfterPt}pt 0; line-height: 1.35; font-family: Arial, Helvetica, sans-serif; word-break: break-word; overflow-wrap: anywhere;";
        if ($firstLineIndent !== '') {
            $style .= " text-indent: $firstLineIndent;";
        }

        return "<p style=\"$style\">$paraText</p>\n";
    }

    private static function renderDocxTableToHtml(DOMElement $node, DOMXPath $xpath): string
    {
        $html = "<table style=\"width: 100%; table-layout: fixed; border-collapse: collapse; margin: 4px 0 8px 0; font-family: Arial, Helvetica, sans-serif;\">\n";
        $rows = $xpath->query('.//w:tr', $node);
        foreach ($rows as $row) {
            $html .= "  <tr>\n";
            $cells = $xpath->query('.//w:tc', $row);

            $totalRowTwips = 0;
            foreach ($cells as $cell) {
                $wNodes = $xpath->query('.//w:tcPr/w:tcW/@w:w', $cell);
                if ($wNodes->length > 0) {
                    $totalRowTwips += (int)$wNodes->item(0)->nodeValue;
                }
            }

            foreach ($cells as $cell) {
                $wNodes = $xpath->query('.//w:tcPr/w:tcW/@w:w', $cell);
                $cellTwips = ($wNodes->length > 0) ? (int)$wNodes->item(0)->nodeValue : 0;
                $widthStyle = ($totalRowTwips > 0 && $cellTwips > 0)
                    ? 'width: ' . round(($cellTwips / $totalRowTwips) * 100) . '%; '
                    : '';

                $html .= "    <td style=\"{$widthStyle}vertical-align: top; padding: 2px 6px; word-break: break-all; overflow-wrap: anywhere;\">\n";
                $pNodes = $xpath->query('.//w:p', $cell);
                $pCount = $pNodes->length;
                for ($pi = 0; $pi < $pCount; $pi++) {
                    $p = $pNodes->item($pi);
                    $rawP = trim($p->textContent);
                    $isUnderline = (bool) preg_match('/^[_\s\x{2014}\x{2013}\-]{4,}$/u', $rawP);
                    if ($isUnderline && ($pi + 1 < $pCount)) {
                        $nextP = $pNodes->item($pi + 1);
                        $nextRawP = trim($nextP->textContent);
                        if ($nextRawP !== '' && mb_strlen($nextRawP) < 80 && !preg_match('/^[_\s\x{2014}\x{2013}\-]{4,}$/u', $nextRawP)) {
                            $nextHtml = self::renderDocxParagraphInnerHtml($nextP, $xpath);
                            $html .= "      <div style=\"text-align: center; margin: 8px 0 2px 0;\">\n";
                            $html .= "        <div style=\"font-weight: bold; margin-bottom: 2px; word-break: break-all;\">" . htmlspecialchars($rawP) . "</div>\n";
                            $html .= "        <div style=\"font-size: 9.5pt; font-weight: bold; color: #1e293b;\">" . $nextHtml . "</div>\n";
                            $html .= "      </div>\n";
                            $pi++;
                            continue;
                        }
                    }
                    $html .= "      " . self::renderDocxParagraphToHtml($p, $xpath);
                }
                $html .= "    </td>\n";
            }
            $html .= "  </tr>\n";
        }
        $html .= "</table>\n";
        return $html;
    }

    /**
     * Generates a fully compliant OpenXML DOCX master template with required placeholders.
     */
    public static function generateMasterDocx(
        string $formKey,
        string $outputPath,
        string $headerMode = 'shared',
        array $branding = [],
        array $paper = []
    ): bool {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $branding = !empty($branding) ? $branding : self::getSharedBrandingDefaults();
        $paper = !empty($paper) ? $paper : self::getSharedPaperDefaults();

        $formContent = self::getFormBodyXml($formKey, $branding);

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Default Extension="jpg" ContentType="image/jpeg"/>
  <Default Extension="jpeg" ContentType="image/jpeg"/>
  <Default Extension="png" ContentType="image/png"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>
</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
        $zip->addFromString('_rels/.rels', $rootRels);

        // word/_rels/document.xml.rels
        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rIdHeader1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>
</Relationships>';
        $zip->addFromString('word/_rels/document.xml.rels', $docRels);

        // word/styles.xml
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults>
    <w:rPrDefault>
      <w:rPr>
        <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
        <w:sz w:val="22"/>
        <w:szCs w:val="22"/>
      </w:rPr>
    </w:rPrDefault>
  </w:docDefaults>
</w:styles>';
        $zip->addFromString('word/styles.xml', $stylesXml);

        // Header XML
        $headerXml = self::buildHeaderXml($headerMode, $branding);
        $zip->addFromString('word/header1.xml', $headerXml);

        // Dimensions: Legal is 8.5" x 14" = 12240 x 20160 twips
        $wTwips = ($paper['paper_size'] === 'A4') ? 11906 : 12240;
        $hTwips = ($paper['paper_size'] === 'A4') ? 16838 : 20160;
        $topTwips = (int) round(($paper['margin_top'] ?? 15) * 56.7);
        $bottomTwips = (int) round(($paper['margin_bottom'] ?? 15) * 56.7);
        $leftTwips = (int) round(($paper['margin_left'] ?? 18) * 56.7);
        $rightTwips = (int) round(($paper['margin_right'] ?? 18) * 56.7);

        // Document XML with body & sectPr
        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:body>
    ' . $formContent . '
    <w:sectPr>
      <w:headerReference w:type="default" r:id="rIdHeader1"/>
      <w:pgSz w:w="' . $wTwips . '" w:h="' . $hTwips . '" w:orient="' . htmlspecialchars($paper['orientation'] ?? 'portrait') . '"/>
      <w:pgMar w:top="' . $topTwips . '" w:right="' . $rightTwips . '" w:bottom="' . $bottomTwips . '" w:left="' . $leftTwips . '" w:header="720" w:footer="720"/>
    </w:sectPr>
  </w:body>
</w:document>';
        $zip->addFromString('word/document.xml', $documentXml);

        $zip->close();
        return true;
    }

    /**
     * Builds word/header1.xml based on the chosen Header Mode.
     */
    private static function buildHeaderXml(string $headerMode, array $branding): string
    {
        if ($headerMode === 'keep') {
            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:p><w:r><w:t></w:t></w:r></w:p>
</w:hdr>';
        }

        $rep = htmlspecialchars($branding['republic_heading'] ?? 'REPUBLIKA NG PILIPINAS');
        $prov = htmlspecialchars($branding['province_name'] ?? 'LALAWIGAN NG RIZAL');
        $city = htmlspecialchars($branding['city_name'] ?? 'LUNGSOD NG MARIKINA');
        $brgy = htmlspecialchars($branding['barangay_name'] ?? 'BARANGAY TUMANA');
        $office = htmlspecialchars($branding['office_title'] ?? 'TANGGAPAN NG LUPONG TAGAPAMAYAPA');
        $addr = htmlspecialchars($branding['address'] ?? '');
        $contact = htmlspecialchars(($branding['phone'] ?? '') . ' | ' . ($branding['email'] ?? ''));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:p>
    <w:pPr>
      <w:jc w:val="center"/>
      <w:spacing w:after="0" w:line="240" w:lineRule="auto"/>
    </w:pPr>
    <w:r><w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t>' . $rep . '</w:t></w:r>
  </w:p>
  <w:p>
    <w:pPr>
      <w:jc w:val="center"/>
      <w:spacing w:after="0" w:line="240" w:lineRule="auto"/>
    </w:pPr>
    <w:r><w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t>' . $prov . '</w:t></w:r>
  </w:p>
  <w:p>
    <w:pPr>
      <w:jc w:val="center"/>
      <w:spacing w:after="0" w:line="240" w:lineRule="auto"/>
    </w:pPr>
    <w:r><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/><w:b/></w:rPr><w:t>' . $city . '</w:t></w:r>
  </w:p>
  <w:p>
    <w:pPr>
      <w:jc w:val="center"/>
      <w:spacing w:after="40" w:line="240" w:lineRule="auto"/>
    </w:pPr>
    <w:r><w:rPr><w:sz w:val="22"/><w:szCs w:val="22"/><w:b/></w:rPr><w:t>' . $brgy . '</w:t></w:r>
  </w:p>
  <w:p>
    <w:pPr>
      <w:jc w:val="center"/>
      <w:pBdr><w:bottom w:val="single" w:sz="12" w:space="4" w:color="173638"/></w:pBdr>
      <w:spacing w:after="120" w:line="200" w:lineRule="auto"/>
    </w:pPr>
    <w:r><w:rPr><w:sz w:val="15"/><w:szCs w:val="15"/><w:color w:val="555555"/></w:rPr><w:t>' . $addr . ' &bull; ' . $contact . '</w:t></w:r>
  </w:p>
</w:hdr>';
    }

    /**
     * Returns standard statutory body content in OpenXML WordprocessingML for KP Forms.
     */
    private static function getFormBodyXml(string $formKey, array $branding): string
    {
        switch ($formKey) {
            case 'kp_7':
                return '
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="80"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:t>PORMULARYO NG KP BLG. 7</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>REKLAMO (COMPLAINT)</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="80"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Usaping Barangay Blg.: </w:t></w:r>
      <w:r><w:t>{case_number}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="80"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Ukol sa: </w:t></w:r>
      <w:r><w:t>{complaint_title}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="60"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>MGA MAY-SUMBONG (Complainants):</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:ind w:left="360"/><w:spacing w:after="100"/></w:pPr>
      <w:r><w:t>{complainants}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:after="100"/></w:pPr>
      <w:r><w:rPr><w:i/></w:rPr><w:t>— laban kay / kina —</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="60"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>MGA IPINAGSUSUMBONG (Respondents):</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:ind w:left="360"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:t>{respondents}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="120"/></w:pPr>
      <w:r><w:t>AKO/KAMI ay pormal na nagrereklamo laban sa ipinagsusumbong na binanggit sa itaas dahil sa paglabag sa aking/aming karapatan at kapakanan gaya ng sumusunod:</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:ind w:left="360"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:rPr><w:i/></w:rPr><w:t>{complaint_details}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:t>DAHIL DITO, magalang kong/naming hinihiling ang sumusunod na lunas o kapasyahan:</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:ind w:left="360"/><w:spacing w:after="240"/></w:pPr>
      <w:r><w:rPr><w:i/></w:rPr><w:t>{relief_prayed_for}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="180"/></w:pPr>
      <w:r><w:t>Isinagawa ngayong ika-</w:t></w:r>
      <w:r><w:t>{date_today}</w:t></w:r>
      <w:r><w:t> dito sa Barangay Tumana, Marikina City.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/><w:spacing w:before="240" w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>_____________________________________</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:t>(Lagda ng May-Sumbong)</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:before="200" w:after="40"/></w:pPr>
      <w:r><w:t>Tinatanggap at pormal na inilagak ngayong ika-</w:t></w:r>
      <w:r><w:t>{docket_date}</w:t></w:r>
      <w:r><w:t>.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/><w:spacing w:before="180" w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{punong_barangay}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/></w:pPr>
      <w:r><w:t>Punong Barangay / Lupon Chairman</w:t></w:r>
    </w:p>';

            case 'kp_8':
                return '
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="80"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:t>PORMULARYO NG KP BLG. 8</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>PAABISO NG PAGDINIG (NOTICE OF HEARING)</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="60"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Usaping Barangay Blg.: </w:t></w:r>
      <w:r><w:t>{case_number}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="100"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Ukol sa: </w:t></w:r>
      <w:r><w:t>{complaint_title}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>PARA KAY / KINA: </w:t></w:r>
      <w:r><w:t>{complainants}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:ind w:left="360"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:t>(Mga May-Sumbong)</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="120"/></w:pPr>
      <w:r><w:t>KAYO AY DITO PINAGBIBIGAYANG-ALAM na magsadya sa harap ng Punong Barangay / Lupong Tagapamayapa sa </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{hearing_date}</w:t></w:r>
      <w:r><w:t> sa ganap na </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{hearing_time}</w:t></w:r>
      <w:r><w:t> ng umaga/hapon sa </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{venue}</w:t></w:r>
      <w:r><w:t> para sa unang pagdinig ng inyong sumbong.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="180"/></w:pPr>
      <w:r><w:t>Ang inyong di-pagharap nang walang sapat na dahilan ay maaaring maging sanhi ng pagpapawalang-bisa sa inyong reklamo o pagpataw ng mga karampatang parusa alinsunod sa batas.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="180"/></w:pPr>
      <w:r><w:t>Iginawad ngayong ika-</w:t></w:r>
      <w:r><w:t>{date_today}</w:t></w:r>
      <w:r><w:t> dito sa Barangay Tumana, Lungsod ng Marikina.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/><w:spacing w:before="240" w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{punong_barangay}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/></w:pPr>
      <w:r><w:t>Punong Barangay / Tagapangulo ng Lupon</w:t></w:r>
    </w:p>';

            case 'kp_9':
                return '
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="80"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:t>PORMULARYO NG KP BLG. 9</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>PATAWAG (SUMMONS)</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="60"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Usaping Barangay Blg.: </w:t></w:r>
      <w:r><w:t>{case_number}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="100"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Ukol sa: </w:t></w:r>
      <w:r><w:t>{complaint_title}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>PARA KAY / KINA: </w:t></w:r>
      <w:r><w:t>{respondents}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:ind w:left="360"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:t>(Mga Ipinagsusumbong)</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="120"/></w:pPr>
      <w:r><w:t>KAYO AY DITO INIUTOS NA PERSONAL NA HUMARAP sa akin kasama ang inyong mga testigo sa </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{hearing_date}</w:t></w:r>
      <w:r><w:t> sa ganap na </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{hearing_time}</w:t></w:r>
      <w:r><w:t> ng umaga/hapon sa </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{venue}</w:t></w:r>
      <w:r><w:t> upang sagutin ang sumbong na ginawa laban sa inyo na nakalakip dito.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="180"/></w:pPr>
      <w:r><w:t>KAYO AY PINAPAG-IINGAT na ang inyong pagtanggi o kabiguang humarap nang walang sapat na dahilan ay magdudulot ng paghadlang sa paghahain ng ganting-sakdal at maaaring idulog sa hukuman para sa direct contempt of court.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="180"/></w:pPr>
      <w:r><w:t>Ipinag-utos ngayong ika-</w:t></w:r>
      <w:r><w:t>{date_today}</w:t></w:r>
      <w:r><w:t> sa Barangay Tumana, Marikina City.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/><w:spacing w:before="240" w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{punong_barangay}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/></w:pPr>
      <w:r><w:t>Punong Barangay / Tagapangulo ng Lupon</w:t></w:r>
    </w:p>';

            case 'kp_22':
                return '
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="80"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:t>PORMULARYO NG KP BLG. 22</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>KATIBAYAN UPANG HADLANGAN ANG GANTING-HABLA</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:rPr><w:i/><w:sz w:val="22"/></w:rPr><w:t>(Certification to Bar Counterclaim)</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="60"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Usaping Barangay Blg.: </w:t></w:r>
      <w:r><w:t>{case_number}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="100"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Ukol sa: </w:t></w:r>
      <w:r><w:t>{complaint_title}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>May-sumbong: </w:t></w:r>
      <w:r><w:t>{complainants}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="160"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Ipinagsusumbong: </w:t></w:r>
      <w:r><w:t>{respondents}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="140"/></w:pPr>
      <w:r><w:t>ITO AY PAGPAPATUNAY na matapos maisilbi ang nararapat na patawag at paabiso, ang ipinagsusumbong na si/sina </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{respondents}</w:t></w:r>
      <w:r><w:t> ay tahasang BUMIGONG HUMARAP nang walang anumang makatwirang dahilan sa itinakdang pagdinig sa harap ng Punong Barangay / Pangkat noong </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{hearing_date}</w:t></w:r>
      <w:r><w:t>.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="180"/></w:pPr>
      <w:r><w:t>Dahil dito, alinsunod sa Seksiyon 412 ng Kodigo ng Pamahalaang Lokal (Batas Pambansa Blg. 7160), ang nabanggit na ipinagsusumbong ay HINAHADLANGAN NA MAGHAIN NG ANUMANG GANTING-HABLA o sakdal sa hukuman o tanggapan ng pamahalaan ukol sa usaping ito.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="180"/></w:pPr>
      <w:r><w:t>Inilagda ngayong ika-</w:t></w:r>
      <w:r><w:t>{date_today}</w:t></w:r>
      <w:r><w:t> sa Barangay Tumana, Marikina City.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/><w:spacing w:before="240" w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{punong_barangay}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/></w:pPr>
      <w:r><w:t>Punong Barangay / Tagapangulo ng Lupon</w:t></w:r>
    </w:p>';

            case 'patawag':
            default:
                return '
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="80"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:t>PAANYAYA SA TANGGAPAN NG PUNONG BARANGAY</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>PRE-DOCKETING INVITATION (PAANYAYA)</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="60"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Talaan Blg.: </w:t></w:r>
      <w:r><w:t>{case_number}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="100"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>Ukol sa: </w:t></w:r>
      <w:r><w:t>{complaint_title}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>KAY: </w:t></w:r>
      <w:r><w:t>{respondents}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:ind w:left="360"/><w:spacing w:after="160"/></w:pPr>
      <w:r><w:t>Nakatira sa: {respondent_address}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="120"/></w:pPr>
      <w:r><w:t>IKAW AY MALUGOD NA INAANYAYAHAN na magtungo sa Tanggapan ng Punong Barangay / Lupong Tagapamayapa sa </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{hearing_date}</w:t></w:r>
      <w:r><w:t> sa ganap na </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{hearing_time}</w:t></w:r>
      <w:r><w:t> ng umaga/hapon sa </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{venue}</w:t></w:r>
      <w:r><w:t> para sa isang paunang pag-uusap at paglilinaw ukol sa sumbong na inihain ni/nina </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{complainants}</w:t></w:r>
      <w:r><w:t>.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="both"/><w:spacing w:after="180"/></w:pPr>
      <w:r><w:t>Ang paanyayang ito ay bahagi ng paunang hakbang sa ilalim ng Katarungang Pambarangay upang magkaroon ng maayos at mapayapang pag-uusap bago pormal na ipasok sa talaan ng Lupon.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:spacing w:after="180"/></w:pPr>
      <w:r><w:t>Ipinagkaloob ngayong ika-</w:t></w:r>
      <w:r><w:t>{date_today}</w:t></w:r>
      <w:r><w:t> sa Barangay Tumana, Lungsod ng Marikina.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/><w:spacing w:before="240" w:after="40"/></w:pPr>
      <w:r><w:rPr><w:b/></w:rPr><w:t>{punong_barangay}</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="right"/></w:pPr>
      <w:r><w:t>Punong Barangay</w:t></w:r>
    </w:p>';
        }
    }

    /**
     * Replaces placeholders in a DOCX file with case data and applies active branding & paper settings.
     */
    public static function processAndFillDocx(
        string $sourceDocxPath,
        string $outputDocxPath,
        array $caseData,
        string $headerMode = 'shared',
        array $branding = [],
        array $paper = []
    ): bool {
        if (!class_exists('ZipArchive') || !file_exists($sourceDocxPath)) {
            return false;
        }

        // Copy source to output
        if (!copy($sourceDocxPath, $outputDocxPath)) {
            return false;
        }

        $branding = !empty($branding) ? $branding : self::getSharedBrandingDefaults();
        $paper = !empty($paper) ? $paper : self::getSharedPaperDefaults();

        $zip = new ZipArchive();
        if ($zip->open($outputDocxPath) !== true) {
            return false;
        }

        $docXml = $zip->getFromName('word/document.xml');
        if ($docXml === false) {
            $zip->close();
            return false;
        }

        // Apply shared header if mode is shared
        if ($headerMode === 'shared') {
            $headerXml = self::buildHeaderXml('shared', $branding);
            $zip->addFromString('word/header1.xml', $headerXml);

            // Ensure header relationship exists
            $relsXml = $zip->getFromName('word/_rels/document.xml.rels') ?: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
            if (!str_contains($relsXml, 'header1.xml')) {
                $relsXml = str_replace(
                    '</Relationships>',
                    '  <Relationship Id="rIdHeader1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>' . "\n</Relationships>",
                    $relsXml
                );
                $zip->addFromString('word/_rels/document.xml.rels', $relsXml);
            }

            // Ensure document has header reference in sectPr
            if (!str_contains($docXml, 'headerReference')) {
                $docXml = preg_replace(
                    '#<w:sectPr\b[^>]*>#',
                    '$0<w:headerReference w:type="default" r:id="rIdHeader1"/>',
                    $docXml
                );
            }
        }

        // Apply paper settings
        $wTwips = ($paper['paper_size'] === 'A4') ? 11906 : 12240;
        $hTwips = ($paper['paper_size'] === 'A4') ? 16838 : 20160;
        $topTwips = (int) round(($paper['margin_top'] ?? 15) * 56.7);
        $bottomTwips = (int) round(($paper['margin_bottom'] ?? 15) * 56.7);
        $leftTwips = (int) round(($paper['margin_left'] ?? 18) * 56.7);
        $rightTwips = (int) round(($paper['margin_right'] ?? 18) * 56.7);

        // Update pgSz & pgMar
        if (preg_match('#<w:pgSz\b[^>]*/>#', $docXml)) {
            $docXml = preg_replace('#<w:pgSz\b[^>]*/>#', '<w:pgSz w:w="' . $wTwips . '" w:h="' . $hTwips . '" w:orient="' . htmlspecialchars($paper['orientation'] ?? 'portrait') . '"/>', $docXml);
        }
        if (preg_match('#<w:pgMar\b[^>]*/>#', $docXml)) {
            $docXml = preg_replace('#<w:pgMar\b[^>]*/>#', '<w:pgMar w:top="' . $topTwips . '" w:right="' . $rightTwips . '" w:bottom="' . $bottomTwips . '" w:left="' . $leftTwips . '" w:header="720" w:footer="720"/>', $docXml);
        }

        // Replace placeholders with caseData
        $substitutions = [
            '{case_number}' => htmlspecialchars($caseData['case_number'] ?? 'CASE-0000-00'),
            '{complaint_title}' => htmlspecialchars($caseData['complaint_title'] ?? 'Di Pagkakaunawaan'),
            '{complaint_details}' => htmlspecialchars($caseData['complaint_details'] ?? ($caseData['description'] ?? 'Pormal na sumbong at mga detalye ng sigalot.')),
            '{relief_prayed_for}' => htmlspecialchars($caseData['relief_prayed_for'] ?? 'Pagbabayad ng danyos at mapayapang kasunduan.'),
            '{complainants}' => htmlspecialchars($caseData['complainants'] ?? ($caseData['complainant_name'] ?? 'Juan Dela Cruz')),
            '{respondents}' => htmlspecialchars($caseData['respondents'] ?? ($caseData['respondent_name'] ?? 'Pedro Santos')),
            '{respondent_address}' => htmlspecialchars($caseData['respondent_address'] ?? 'Barangay Tumana, Marikina City'),
            '{hearing_date}' => htmlspecialchars($caseData['hearing_date'] ?? date('F d, Y')),
            '{hearing_time}' => htmlspecialchars($caseData['hearing_time'] ?? '9:00 AM'),
            '{venue}' => htmlspecialchars($caseData['venue'] ?? 'Tanggapan ng Lupong Tagapamayapa / Lupon Court Room'),
            '{docket_date}' => htmlspecialchars($caseData['docket_date'] ?? date('F d, Y')),
            '{date_today}' => htmlspecialchars($caseData['date_today'] ?? date('F d, Y')),
            '{punong_barangay}' => htmlspecialchars($branding['officers']['punong_barangay']['name'] ?? 'HON. FRANCISCO G. SANDOVAL'),
            '{barangay_secretary}' => htmlspecialchars($branding['officers']['barangay_secretary']['name'] ?? 'MARIA CRISTINA SANTOS'),
            '{lupon_chairperson}' => htmlspecialchars($branding['officers']['punong_barangay']['name'] ?? 'HON. FRANCISCO G. SANDOVAL'),
            '{pangkat_chairperson}' => htmlspecialchars($branding['officers']['pangkat_secretary']['name'] ?? 'ANTONIO V. REYES'),
        ];

        foreach ($substitutions as $search => $replace) {
            $docXml = str_replace($search, $replace, $docXml);
        }

        $zip->addFromString('word/document.xml', $docXml);
        $zip->close();
        return true;
    }

    /**
     * Renders synchronized visual HTML preview matching the DOCX layout and settings.
     */
    public static function renderDocxHtmlPreview(
        string $formKey,
        array $templateConfig = [],
        array $branding = [],
        array $paper = [],
        array $sampleData = []
    ): string {
        $branding = !empty($branding) ? $branding : self::getSharedBrandingDefaults();
        $paper = !empty($paper) ? $paper : self::getSharedPaperDefaults();

        $headerMode = $templateConfig['header_mode'] ?? 'shared';
        $paperSize = $paper['paper_size'] ?? 'Legal';
        $marginTop = (int)($paper['margin_top'] ?? 15);
        $marginBottom = (int)($paper['margin_bottom'] ?? 15);
        $marginLeft = (int)($paper['margin_left'] ?? 18);
        $marginRight = (int)($paper['margin_right'] ?? 18);
        $fontFamily = !empty($paper['font_family']) ? $paper['font_family'] : 'Arial';

        // Sample data defaults
        $data = array_merge([
            'case_number' => 'KP-2026-10-0042',
            'complaint_title' => 'Singil sa Utang at Hindi Pagkakaunawaan',
            'complaint_details' => 'Noong ika-15 ng Setyembre 2026, ang inirereklamo ay humiram ng halagang PHP 25,000 na nangakong babayaran sa loob ng isang buwan ngunit hanggang sa kasalukuyan ay hindi pa tumutupad sa kasunduan.',
            'relief_prayed_for' => 'Mabayaran nang buo ang halagang hiniram at magkaroon ng mapayapang kasunduan sa harap ng Lupon.',
            'complainants' => 'Juan Dela Cruz y Santos',
            'respondents' => 'Pedro Garcia at Maria Garcia',
            'respondent_address' => '123 Bagong Sibol St., Barangay Tumana, Marikina City',
            'hearing_date' => date('F d, Y', strtotime('+3 days')),
            'hearing_time' => '9:30 AM',
            'venue' => 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall, Tumana',
            'docket_date' => date('F d, Y'),
            'date_today' => date('F d, Y'),
        ], $sampleData);

        // Header HTML
        $headerHtml = '';
        if ($headerMode !== 'keep') {
            $tumanaLogo = self::getLogoDataUri($branding['logo_primary'] ?? 'tumana_logo.jpg', 'tumana_logo.jpg');
            $marikinaLogo = self::getLogoDataUri($branding['logo_secondary'] ?? 'marikina_logo.jpg', 'marikina_logo.jpg');

            $headerHtml = '
            <header class="preview-header">
                <div class="preview-header-layout">
                    ' . ($tumanaLogo ? '<img src="' . $tumanaLogo . '" class="preview-logo preview-logo-left" alt="Barangay Tumana Seal">' : '') . '
                    <div class="preview-header-text">
                        <div class="rep-title">' . htmlspecialchars($branding['republic_heading'] ?? 'REPUBLIKA NG PILIPINAS') . '</div>
                        <div class="prov-title">' . htmlspecialchars($branding['province_name'] ?? 'LALAWIGAN NG RIZAL') . '</div>
                        <div class="city-title">' . htmlspecialchars($branding['city_name'] ?? 'LUNGSOD NG MARIKINA') . '</div>
                        <div class="brgy-title">' . htmlspecialchars($branding['barangay_name'] ?? 'BARANGAY TUMANA') . '</div>
                    </div>
                    ' . ($marikinaLogo ? '<img src="' . $marikinaLogo . '" class="preview-logo preview-logo-right" alt="City of Marikina Seal">' : '') . '
                </div>
                <div class="preview-header-contact">
                    ' . htmlspecialchars($branding['address'] ?? '') . ' &bull; Tel: ' . htmlspecialchars($branding['phone'] ?? '') . ' &bull; Email: ' . htmlspecialchars($branding['email'] ?? '') . '
                </div>
                <div class="preview-header-divider"></div>
            </header>';
        }

        // Body content: Follow uploaded template format if available
        $bodyHtml = '';
        $customFilePath = $templateConfig['file_path'] ?? '';
        $docxCandidate = '';
        if (!empty($customFilePath)) {
            $cand = dirname(__DIR__, 2) . '/' . ltrim($customFilePath, '/');
            if (file_exists($cand) && is_file($cand)) {
                $docxCandidate = $cand;
            }
        }
        if (!$docxCandidate) {
            $masterDocx = self::getStorageDir() . "/master_{$formKey}.docx";
            if (file_exists($masterDocx) && is_file($masterDocx)) {
                $docxCandidate = $masterDocx;
            }
        }

        if ($docxCandidate && strtolower(pathinfo($docxCandidate, PATHINFO_EXTENSION)) === 'docx') {
            $bodyHtml = self::convertDocxBodyToHtml($docxCandidate, $data);
        }

        if (trim($bodyHtml) === '') {
            $bodyHtml = self::getFormBodyHtml($formKey, $data, $branding);
        }

        $paperCssSize = ($paperSize === 'Legal') ? '8.5in 14in' : '210mm 297mm';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
<meta charset="UTF-8">
<title></title>
<style>
    @page {
        size: ' . $paperCssSize . ';
        margin: ' . $marginTop . 'mm ' . $marginRight . 'mm ' . $marginBottom . 'mm ' . $marginLeft . 'mm;
    }
    * { box-sizing: border-box; }
    body, html {
        margin: 0;
        padding: 20px;
        background: #f8fafc;
        font-family: Arial, Helvetica, sans-serif !important;
        color: #0f172a;
        line-height: 1.35;
    }
    .page-sheet, .docx-rendered-body, table, td, th, p, span, div, h1, h2, h3, h4, strong, em {
        font-family: Arial, Helvetica, sans-serif !important;
    }
    .page-sheet {
        max-width: 820px;
        margin: 0 auto;
        background: #ffffff;
        padding: 28px 40px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        min-height: auto;
        position: relative;
    }
    .preview-header-layout {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }
    .preview-logo {
        width: 54px;
        height: 54px;
        object-fit: contain;
    }
    .preview-header-text {
        text-align: center;
        flex: 1;
        line-height: 1.2;
    }
    .rep-title, .prov-title { font-size: 10pt; color: #334155; }
    .city-title { font-size: 11pt; font-weight: bold; color: #1e293b; }
    .brgy-title { font-size: 12pt; font-weight: bold; color: #173638; letter-spacing: 0.04em; }
    .preview-header-contact {
        font-size: 8pt;
        color: #64748b;
        text-align: center;
        margin-top: 3px;
    }
    .preview-header-divider {
        border-bottom: 2px solid #173638;
        margin: 6px 0 12px;
    }
    .form-title-block {
        text-align: center;
        margin-bottom: 14px;
    }
    .form-code { font-size: 13pt; font-weight: bold; text-transform: uppercase; margin: 0; }
    .form-title { font-size: 12pt; font-weight: bold; margin: 3px 0 0; }
    .form-subtitle { font-size: 10pt; font-style: italic; color: #475569; margin: 2px 0 0; }
    .case-meta-table {
        width: 100%;
        margin-bottom: 12px;
        border-collapse: collapse;
    }
    .case-meta-table td {
        padding: 3px 0;
        vertical-align: top;
        font-size: 10.5pt;
    }
    .parties-block {
        margin: 12px 0;
        font-size: 10.5pt;
    }
    .party-title { font-weight: bold; }
    .party-names { padding-left: 20px; font-weight: 600; color: #1e293b; }
    .vs-divider { text-align: center; font-style: italic; margin: 6px 0; color: #64748b; }
    .narrative-body {
        text-align: justify;
        font-size: 10.5pt;
        margin: 12px 0;
        text-indent: 28px;
    }
    .narrative-quote {
        margin: 8px 16px 10px;
        padding: 8px 12px;
        background: #f8fafc;
        border-left: 3px solid #2b6b5e;
        font-style: italic;
    }
    .date-statement {
        margin: 18px 0 24px;
        font-size: 10.5pt;
    }
    .signature-row {
        display: flex;
        justify-content: flex-end;
        text-align: right;
        margin-top: 20px;
    }
    .signature-box {
        min-width: 240px;
        text-align: center;
    }
    .sig-line {
        border-top: 1px solid #000;
        margin-top: 40px;
        padding-top: 4px;
        font-weight: bold;
        font-size: 10.5pt;
    }
    .sig-title { font-size: 9.5pt; color: #475569; }
    .preview-watermark {
        position: absolute;
        top: 45%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-30deg);
        font-size: 48pt;
        color: rgba(43, 107, 94, 0.035);
        font-weight: bold;
        pointer-events: none;
        user-select: none;
        white-space: nowrap;
    }

    @media print {
        @page {
            size: auto;
            margin: 0;
        }
        *, *:before, *:after {
            box-sizing: border-box !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            font-family: Arial, Helvetica, sans-serif !important;
        }
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            background: #ffffff !important;
            width: 100% !important;
            height: auto !important;
            font-size: 10pt !important;
            font-family: Arial, Helvetica, sans-serif !important;
        }
        .page-sheet {
            max-width: 100% !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 10mm 15mm 8mm 15mm !important;
            border: none !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            min-height: 0 !important;
            font-family: Arial, Helvetica, sans-serif !important;
        }
        .preview-watermark {
            display: none !important;
        }
        .preview-header {
            width: 100% !important;
            margin-bottom: 6px !important;
        }
        .preview-header-layout {
            width: 100% !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
        }
        .preview-logo {
            width: 50px !important;
            height: 50px !important;
            object-fit: contain !important;
        }
        .preview-header-divider {
            border-bottom: 2px solid #173638 !important;
            margin: 4px 0 8px !important;
        }
        .docx-rendered-body {
            width: 100% !important;
            font-size: 10pt !important;
            line-height: 1.32 !important;
            font-family: Arial, Helvetica, sans-serif !important;
        }
        .docx-rendered-body p {
            margin-top: 1.5px !important;
            margin-bottom: 2.5pt !important;
            line-height: 1.32 !important;
        }
        .docx-rendered-body table, table {
            width: 100% !important;
            table-layout: fixed !important;
            page-break-inside: avoid !important;
            margin: 4px 0 6px 0 !important;
        }
        .docx-rendered-body td, table td {
            padding: 2px 5px !important;
            word-break: break-all !important;
            overflow-wrap: anywhere !important;
        }
        .signature-row, .parties-block, .docx-sig-block {
            page-break-inside: avoid !important;
        }
    }
</style>
</head>
<body>
<div class="page-sheet">
    <div class="preview-watermark">AGAP MASTER TEMPLATE</div>
    ' . $headerHtml . '
    ' . $bodyHtml . '
</div>
</body>
</html>';
    }

    private static function getFormBodyHtml(string $formKey, array $data, array $branding): string
    {
        $officer = $branding['officers']['punong_barangay']['name'] ?? 'HON. FRANCISCO G. SANDOVAL';
        $officerTitle = $branding['officers']['punong_barangay']['title'] ?? 'Punong Barangay / Lupon Chairman';

        switch ($formKey) {
            case 'kp_7':
                return '
                <div class="form-title-block">
                    <h2 class="form-code">PORMULARYO NG KP BLG. 7</h2>
                    <h3 class="form-title">REKLAMO (COMPLAINT)</h3>
                </div>
                <table class="case-meta-table">
                    <tr><td style="width: 170px;"><strong>Usaping Barangay Blg.:</strong></td><td>' . htmlspecialchars($data['case_number']) . '</td></tr>
                    <tr><td><strong>Ukol sa:</strong></td><td>' . htmlspecialchars($data['complaint_title']) . '</td></tr>
                </table>
                <div class="parties-block">
                    <div class="party-title">MGA MAY-SUMBONG (Complainants):</div>
                    <div class="party-names">' . htmlspecialchars($data['complainants']) . '</div>
                    <div class="vs-divider">— laban kay / kina —</div>
                    <div class="party-title">MGA IPINAGSUSUMBONG (Respondents):</div>
                    <div class="party-names">' . htmlspecialchars($data['respondents']) . '</div>
                </div>
                <div class="narrative-body">
                    AKO/KAMI ay pormal na nagrereklamo laban sa ipinagsusumbong na binanggit sa itaas dahil sa paglabag sa aking/aming karapatan at kapakanan gaya ng sumusunod:
                </div>
                <div class="narrative-quote">
                    ' . nl2br(htmlspecialchars($data['complaint_details'])) . '
                </div>
                <div class="narrative-body">
                    DAHIL DITO, magalang kong/naming hinihiling ang sumusunod na lunas o kapasyahan:
                </div>
                <div class="narrative-quote">
                    ' . nl2br(htmlspecialchars($data['relief_prayed_for'])) . '
                </div>
                <div class="date-statement">
                    Isinagawa ngayong ika-<strong>' . htmlspecialchars($data['date_today']) . '</strong> dito sa Barangay Tumana, Marikina City.
                </div>
                <div class="signature-row">
                    <div class="signature-box">
                        <div class="sig-line">Lagda ng May-Sumbong</div>
                    </div>
                </div>
                <div style="margin-top: 30px; font-size: 11pt;">
                    Tinatanggap at pormal na inilagak ngayong ika-<strong>' . htmlspecialchars($data['docket_date']) . '</strong>.
                </div>
                <div class="signature-row">
                    <div class="signature-box">
                        <div class="sig-line">' . htmlspecialchars($officer) . '</div>
                        <div class="sig-title">' . htmlspecialchars($officerTitle) . '</div>
                    </div>
                </div>';

            case 'kp_8':
                return '
                <div class="form-title-block">
                    <h2 class="form-code">PORMULARYO NG KP BLG. 8</h2>
                    <h3 class="form-title">PAABISO NG PAGDINIG (NOTICE OF HEARING)</h3>
                    <div class="form-subtitle">Para sa May-sumbong ukol sa Pamamagitan</div>
                </div>
                <table class="case-meta-table">
                    <tr><td style="width: 170px;"><strong>Usaping Barangay Blg.:</strong></td><td>' . htmlspecialchars($data['case_number']) . '</td></tr>
                    <tr><td><strong>Ukol sa:</strong></td><td>' . htmlspecialchars($data['complaint_title']) . '</td></tr>
                </table>
                <div class="parties-block">
                    <div class="party-title">PARA KAY / KINA:</div>
                    <div class="party-names">' . htmlspecialchars($data['complainants']) . '</div>
                    <div style="font-size: 10pt; color: #475569; padding-left: 20px;">(Mga May-Sumbong)</div>
                </div>
                <div class="narrative-body">
                    KAYO AY DITO PINAGBIBIGAYANG-ALAM na magsadya sa harap ng Punong Barangay / Lupong Tagapamayapa sa <strong>' . htmlspecialchars($data['hearing_date']) . '</strong> sa ganap na <strong>' . htmlspecialchars($data['hearing_time']) . '</strong> sa <strong>' . htmlspecialchars($data['venue']) . '</strong> para sa pagdinig ng inyong sumbong.
                </div>
                <div class="narrative-body">
                    Ang inyong di-pagharap nang walang sapat na dahilan ay maaaring maging sanhi ng pagpapawalang-bisa sa inyong reklamo o pagpataw ng mga karampatang parusa alinsunod sa batas.
                </div>
                <div class="date-statement">
                    Iginawad ngayong ika-<strong>' . htmlspecialchars($data['date_today']) . '</strong> sa Barangay Tumana, Lungsod ng Marikina.
                </div>
                <div class="signature-row">
                    <div class="signature-box">
                        <div class="sig-line">' . htmlspecialchars($officer) . '</div>
                        <div class="sig-title">' . htmlspecialchars($officerTitle) . '</div>
                    </div>
                </div>';

            case 'kp_9':
                return '
                <div class="form-title-block">
                    <h2 class="form-code">PORMULARYO NG KP BLG. 9</h2>
                    <h3 class="form-title">PATAWAG (SUMMONS)</h3>
                    <div class="form-subtitle">Patawag sa Inirereklamo ukol sa Pamamagitan</div>
                </div>
                <table class="case-meta-table">
                    <tr><td style="width: 170px;"><strong>Usaping Barangay Blg.:</strong></td><td>' . htmlspecialchars($data['case_number']) . '</td></tr>
                    <tr><td><strong>Ukol sa:</strong></td><td>' . htmlspecialchars($data['complaint_title']) . '</td></tr>
                </table>
                <div class="parties-block">
                    <div class="party-title">PARA KAY / KINA:</div>
                    <div class="party-names">' . htmlspecialchars($data['respondents']) . '</div>
                    <div style="font-size: 10pt; color: #475569; padding-left: 20px;">(Mga Ipinagsusumbong)</div>
                </div>
                <div class="narrative-body">
                    KAYO AY DITO INIUTOS NA PERSONAL NA HUMARAP sa akin kasama ang inyong mga testigo sa <strong>' . htmlspecialchars($data['hearing_date']) . '</strong> sa ganap na <strong>' . htmlspecialchars($data['hearing_time']) . '</strong> sa <strong>' . htmlspecialchars($data['venue']) . '</strong> upang sagutin ang sumbong na ginawa laban sa inyo na nakalakip dito.
                </div>
                <div class="narrative-body">
                    KAYO AY PINAPAG-IINGAT na ang inyong pagtanggi o kabiguang humarap nang walang sapat na dahilan ay magdudulot ng paghadlang sa paghahain ng ganting-sakdal at maaaring idulog sa hukuman para sa direct contempt of court.
                </div>
                <div class="date-statement">
                    Ipinag-utos ngayong ika-<strong>' . htmlspecialchars($data['date_today']) . '</strong> sa Barangay Tumana, Lungsod ng Marikina.
                </div>
                <div class="signature-row">
                    <div class="signature-box">
                        <div class="sig-line">' . htmlspecialchars($officer) . '</div>
                        <div class="sig-title">' . htmlspecialchars($officerTitle) . '</div>
                    </div>
                </div>';

            case 'kp_22':
                return '
                <div class="form-title-block">
                    <h2 class="form-code">PORMULARYO NG KP BLG. 22</h2>
                    <h3 class="form-title">KATIBAYAN UPANG HADLANGAN ANG GANTING-HABLA</h3>
                    <div class="form-subtitle">(Certification to Bar Counterclaim)</div>
                </div>
                <table class="case-meta-table">
                    <tr><td style="width: 170px;"><strong>Usaping Barangay Blg.:</strong></td><td>' . htmlspecialchars($data['case_number']) . '</td></tr>
                    <tr><td><strong>Ukol sa:</strong></td><td>' . htmlspecialchars($data['complaint_title']) . '</td></tr>
                </table>
                <div class="parties-block">
                    <div><strong>May-sumbong:</strong> ' . htmlspecialchars($data['complainants']) . '</div>
                    <div style="margin-top: 4px;"><strong>Ipinagsusumbong:</strong> ' . htmlspecialchars($data['respondents']) . '</div>
                </div>
                <div class="narrative-body">
                    ITO AY PAGPAPATUNAY na matapos maisilbi ang nararapat na patawag at paabiso, ang ipinagsusumbong na si/sina <strong>' . htmlspecialchars($data['respondents']) . '</strong> ay tahasang BUMIGONG HUMARAP nang walang anumang makatwirang dahilan sa itinakdang pagdinig sa harap ng Punong Barangay / Pangkat noong <strong>' . htmlspecialchars($data['hearing_date']) . '</strong>.
                </div>
                <div class="narrative-body">
                    Dahil dito, alinsunod sa Seksiyon 412 ng Kodigo ng Pamahalaang Lokal (Batas Pambansa Blg. 7160), ang nabanggit na ipinagsusumbong ay HINAHADLANGAN NA MAGHAIN NG ANUMANG GANTING-HABLA o sakdal sa hukuman o tanggapan ng pamahalaan ukol sa usaping ito.
                </div>
                <div class="date-statement">
                    Inilagda ngayong ika-<strong>' . htmlspecialchars($data['date_today']) . '</strong> sa Barangay Tumana, Marikina City.
                </div>
                <div class="signature-row">
                    <div class="signature-box">
                        <div class="sig-line">' . htmlspecialchars($officer) . '</div>
                        <div class="sig-title">' . htmlspecialchars($officerTitle) . '</div>
                    </div>
                </div>';

            case 'kp_20':
            case 'kp_20_a':
            case 'kp_20_b':
                $kpCode = ($formKey === 'kp_20_a') ? 'PORMULARYO NG KP BLG. 20-A' : (($formKey === 'kp_20_b') ? 'PORMULARYO NG KP BLG. 20-B' : 'PORMULARYO NG KP BLG. 20');
                return '
                <div class="form-title-block">
                    <p style="text-align: left; margin: 0 0 10px 0; font-weight: bold; font-size: 10pt;">' . htmlspecialchars($kpCode) . '</p>
                    <p style="text-align: center; margin: 10px 0; font-weight: bold; font-size: 11pt;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</p>
                </div>
                <table style="width: 100%; border-collapse: collapse; margin: 10px 0 18px 0;">
                    <tr>
                        <td style="width: 50%; vertical-align: top; padding: 4px 8px;">
                            <div style="font-weight: bold; text-decoration: underline;">' . htmlspecialchars($data['complainants']) . '</div>
                            <div style="font-size: 9.5pt; color: #475569;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; margin: 10px 0; font-style: italic; font-size: 10pt;">-laban kay/kina-</div>
                            <div style="font-weight: bold; text-decoration: underline;">' . htmlspecialchars($data['respondents']) . '</div>
                            <div style="font-size: 9.5pt; color: #475569;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; padding: 4px 8px;">
                            <div style="margin-bottom: 8px;"><strong>Usapin ng Barangay Blg.:</strong> ' . htmlspecialchars($data['case_number']) . '</div>
                            <div><strong>Para sa:</strong> ' . htmlspecialchars($data['complaint_title']) . '</div>
                        </td>
                    </tr>
                </table>
                <div style="text-align: center; margin: 20px 0 16px 0;">
                    <h3 style="font-size: 12pt; font-weight: bold; margin: 0; text-transform: uppercase;">KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</h3>
                    <div style="font-size: 10.5pt; font-style: italic; margin-top: 2px;">(Certification to File Action)</div>
                </div>
                <div style="margin: 14px 0 8px 0; font-weight: bold; font-size: 11pt;">ITO AY NAGPAPATUNAY NA:</div>
                <ol style="margin: 8px 0 18px 20px; padding-left: 10px; font-size: 11pt; line-height: 1.6;">
                    <li style="margin-bottom: 8px;">Nagkaroon ng personal na paghaharap sa pagitan ng mga panig sa harap ng Punong Barangay subalit ang pamamagitan ay nabigo;</li>
                    <li style="margin-bottom: 8px;">Ang Pangkat ng Tagapagkasundo ay binuo subalit ang personal na pag-uusap sa harap ng Pangkat ay di nagbunga ng pag-aayos;</li>
                    <li style="margin-bottom: 8px;">Dahil dito, ang nasabing reklamo para sa sigalot ay maaari nang idulog sa hukuman/tanggapan ng pamahalaan.</li>
                </ol>
                <div style="margin: 20px 0 35px 0; font-size: 11pt;">
                    Ngayong ika-<strong>' . htmlspecialchars($data['date_today']) . '</strong>.
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 40px;">
                    <div style="min-width: 220px; text-align: center;">
                        <div style="font-weight: bold; border-top: 1px solid #000; padding-top: 4px;">MARIA CRISTINA SANTOS</div>
                        <div style="font-size: 10pt; color: #475569;">Kalihim ng Lupon</div>
                    </div>
                    <div style="min-width: 250px; text-align: center;">
                        <div style="font-size: 10pt; margin-bottom: 2px;">Pinatutunayan:</div>
                        <div style="font-weight: bold; border-top: 1px solid #000; padding-top: 4px;">' . htmlspecialchars($officer) . '</div>
                        <div style="font-size: 10pt; color: #475569;">' . htmlspecialchars($officerTitle) . '</div>
                    </div>
                </div>';

            case 'patawag':
                return '
                <div class="form-title-block">
                    <h2 class="form-code">PAANYAYA SA TANGGAPAN NG PUNONG BARANGAY</h2>
                    <h3 class="form-title">PRE-DOCKETING INVITATION (PAANYAYA)</h3>
                </div>
                <table class="case-meta-table">
                    <tr><td style="width: 170px;"><strong>Talaan Blg.:</strong></td><td>' . htmlspecialchars($data['case_number']) . '</td></tr>
                    <tr><td><strong>Ukol sa:</strong></td><td>' . htmlspecialchars($data['complaint_title']) . '</td></tr>
                </table>
                <div class="parties-block">
                    <div class="party-title">PARA KAY:</div>
                    <div class="party-names">' . htmlspecialchars($data['respondents']) . '</div>
                    <div style="font-size: 10pt; color: #475569; padding-left: 20px;">Nakatira sa: ' . htmlspecialchars($data['respondent_address']) . '</div>
                </div>
                <div class="narrative-body">
                    IKAW AY MALUGOD NA INAANYAYAHAN na magtungo sa Tanggapan ng Punong Barangay / Lupong Tagapamayapa sa <strong>' . htmlspecialchars($data['hearing_date']) . '</strong> sa ganap na <strong>' . htmlspecialchars($data['hearing_time']) . '</strong> sa <strong>' . htmlspecialchars($data['venue']) . '</strong> para sa isang paunang pag-uusap at paglilinaw ukol sa sumbong na inihain ni/nina <strong>' . htmlspecialchars($data['complainants']) . '</strong>.
                </div>
                <div class="narrative-body">
                    Ang paanyayang ito ay bahagi ng paunang hakbang sa ilalim ng Katarungang Pambarangay upang magkaroon ng maayos at mapayapang pag-uusap bago pormal na ipasok sa talaan ng Lupon.
                </div>
                <div class="date-statement">
                    Ipinagkaloob ngayong ika-<strong>' . htmlspecialchars($data['date_today']) . '</strong> sa Barangay Tumana, Lungsod ng Marikina.
                </div>
                <div class="signature-row">
                    <div class="signature-box">
                        <div class="sig-line">' . htmlspecialchars($officer) . '</div>
                        <div class="sig-title">' . htmlspecialchars($officerTitle) . '</div>
                    </div>
                </div>';

            default:
                return '
                <div class="form-title-block">
                    <h2 class="form-code">' . htmlspecialchars(strtoupper(str_replace('_', ' ', $formKey))) . '</h2>
                    <h3 class="form-title">' . htmlspecialchars($data['complaint_title'] ?? 'KATARUNGANG PAMBARANGAY FORM') . '</h3>
                </div>
                <table class="case-meta-table">
                    <tr><td style="width: 170px;"><strong>Usaping Barangay Blg.:</strong></td><td>' . htmlspecialchars($data['case_number']) . '</td></tr>
                    <tr><td><strong>Ukol sa:</strong></td><td>' . htmlspecialchars($data['complaint_title']) . '</td></tr>
                </table>
                <div class="parties-block">
                    <div><strong>May-sumbong:</strong> ' . htmlspecialchars($data['complainants']) . '</div>
                    <div style="margin-top: 4px;"><strong>Ipinagsusumbong:</strong> ' . htmlspecialchars($data['respondents']) . '</div>
                </div>
                <div class="narrative-body">
                    ' . nl2br(htmlspecialchars($data['complaint_details'])) . '
                </div>
                <div class="date-statement">
                    Isinagawa ngayong ika-<strong>' . htmlspecialchars($data['date_today']) . '</strong> sa Barangay Tumana, Lungsod ng Marikina.
                </div>
                <div class="signature-row">
                    <div class="signature-box">
                        <div class="sig-line">' . htmlspecialchars($officer) . '</div>
                        <div class="sig-title">' . htmlspecialchars($officerTitle) . '</div>
                    </div>
                </div>';
        }
    }
}
