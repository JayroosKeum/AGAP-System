<?php
require_once __DIR__ . '/../backend/services/DocxTemplateService.php';

$storage = DocxTemplateService::getStorageDir();
$testPath = $storage . '/test_kp7.docx';

$genOk = DocxTemplateService::generateMasterDocx('kp_7', $testPath);
echo "Generate: " . ($genOk ? "OK" : "FAILED") . "\n";

$inspection = DocxTemplateService::inspectDocx($testPath);
echo "Inspection:\n";
print_r($inspection);

$outputPath = $storage . '/test_kp7_filled.docx';
$fillOk = DocxTemplateService::processAndFillDocx(
    $testPath,
    $outputPath,
    [
        'case_number' => 'KP-2026-10-TEST-001',
        'complaint_title' => 'Test Complaint Title',
        'complainants' => 'Ana Batungbakal',
        'respondents' => 'Pedro Penduko'
    ]
);
echo "Fill: " . ($fillOk ? "OK" : "FAILED") . "\n";

$inspectionFilled = DocxTemplateService::inspectDocx($outputPath);
echo "Filled Inspection:\n";
print_r($inspectionFilled);
