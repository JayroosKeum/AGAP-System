<?php
require_once __DIR__ . '/../backend/services/PDFService.php';

try {
    $pdf = new PDFService();
    $html = $pdf->renderPaanyayaHtml(64, ['stage_type' => 'Paanyaya 1']);
    echo "Length: " . strlen($html) . "\n";
    echo "Preview:\n" . substr($html, 0, 300) . "\n";
    if (strpos($html, 'PAANYAYA') !== false) {
        echo "Found PAANYAYA\n";
    } else {
        echo "PAANYAYA NOT found in text. Check case sensitivity.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
