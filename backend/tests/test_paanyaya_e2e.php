<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/Paanyaya.php';
require_once __DIR__ . '/../models/CaseModel.php';
require_once __DIR__ . '/../services/PDFService.php';
require_once __DIR__ . '/TestDataCleanup.php';

echo "Testing Paanyaya flow...\n";
$db = (new Database())->connect();
$cleanup = new TestDataCleanup($db);

// Ensure we have 2 residents
$res = $db->query("SELECT resident_id FROM residents LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$c_res = (int) ($res[0] ?? 1);
$r_res = (int) ($res[1] ?? 2);

// 1. Create a complaint
$complaintModel = new Complaint();
$createRes = $complaintModel->create([
    'complaint_title' => 'Test Paanyaya Boundary Dispute',
    'category_id' => 1,
    'case_type' => 'Civil',
    'incident_date' => '2026-10-15',
    'incident_time' => '10:00',
    'incident_street' => 'Block 4 Lot 2, Barangay Tumana',
    'incident_barangay' => 'Tumana',
    'incident_city' => 'Marikina City',
    'narrative' => 'Nagkaroon ng hindi pagkakaunawaan tungkol sa bakod sa pagitan ng magkapitbahay.',
    'requested_resolution' => 'Maayos na pag-uusap at pagtatakda ng tamang boundary.',
    'complainant_resident_id' => $c_res,
    'respondent_resident_id' => $r_res,
    'latitude' => 14.6521,
    'longitude' => 121.0854,
    'duplicate_acknowledged' => 1,
    'jurisdiction_screened' => 1,
    'created_by' => 1
]);
$cid = (int)($createRes['complaint_id'] ?? 0);
$cleanup->trackComplaint($cid);
if (empty($createRes['success']) || $cid <= 0) {
    throw new RuntimeException('Complaint setup failed: ' . ($createRes['message'] ?? 'Unknown error'));
}
echo "Complaint created: ID {$cid}\n";

// 2. Schedule Paanyaya
$paanyayaModel = new Paanyaya();
$scheduleRes = $paanyayaModel->schedule([
    'complaint_id' => $cid,
    'invitation_date' => '2026-10-22',
    'invitation_time' => '14:00',
    'venue' => 'Lupon Court Room',
    'has_e_signature' => 1,
    'salaysay' => 'PAG-AAYOS SA HINDI PAGKAKAUNAWAAN HINGGIL SA BAKOD',
    'remarks' => 'Pre-docketing dialogue'
], 1);
echo "Paanyaya scheduled: " . json_encode($scheduleRes) . "\n";
$paanyayaId = (int)($scheduleRes['session_id'] ?? 0);
if (empty($scheduleRes['success']) || $paanyayaId <= 0) {
    throw new RuntimeException('Paanyaya scheduling failed: ' . ($scheduleRes['message'] ?? 'Unknown error'));
}

// Verify complaint status
$comp = $complaintModel->getById($cid);
echo "Complaint status: {$comp['status']} (Expected: Pre-Docketed (Paanyaya))\n";

// Verify PDF generation
$pdfService = new PDFService();
$testPdfFile = __DIR__ . '/test_output_paanyaya.pdf';
$pdfPath = $pdfService->generatePaanyaya($cid, ['save_path' => $testPdfFile]);
echo "PDF generated at: {$pdfPath} (File size: " . (file_exists($pdfPath) ? filesize($pdfPath) : 0) . " bytes)\n";

// 3. Test HTML preview rendering
$html = $pdfService->renderPaanyayaHtml($cid);
$hasTumana = strpos($html, 'BARANGAY TUMANA') !== false;
$hasSignature = strpos($html, 'AKIKO LYNNE CENTENO') !== false;
$hasSalaysay = strpos($html, 'PAG-AAYOS SA HINDI PAGKAKAUNAWAAN HINGGIL SA BAKOD') !== false;
echo "HTML Verification -> Tumana: " . ($hasTumana ? 'YES' : 'NO') . ", Signature: " . ($hasSignature ? 'YES' : 'NO') . ", Salaysay: " . ($hasSalaysay ? 'YES' : 'NO') . "\n";

// 3B. Test Outcome 1: Resolved Informally
$resolvedResult = $paanyayaModel->recordOutcome($paanyayaId, 'Resolved', 'Parties met and shook hands informally.', 1);
echo "Resolved Outcome result: " . json_encode($resolvedResult['success'] ?? false) . "\n";
if (empty($resolvedResult['success'])) {
    throw new RuntimeException('Paanyaya settlement failed: ' . ($resolvedResult['message'] ?? 'Unknown error'));
}
$compResolved = $complaintModel->getById($cid);
echo "Complaint status after resolution: {$compResolved['status']} (Expected: Resolved at Paanyaya / Closed)\n";
if (($compResolved['status'] ?? '') !== 'Settled') {
    throw new RuntimeException('Expected settled Paanyaya status, got: ' . ($compResolved['status'] ?? 'missing'));
}

if (file_exists($testPdfFile)) @unlink($testPdfFile);

echo "Test completed successfully; registered shutdown cleanup will remove test data.\n";
