<?php

require_once __DIR__ . '/../../backend/config/Database.php';
require_once __DIR__ . '/../../backend/models/Complaint.php';
require_once __DIR__ . '/../../backend/models/Paanyaya.php';
require_once __DIR__ . '/../../backend/models/CaseModel.php';
require_once __DIR__ . '/../../backend/services/PDFService.php';

echo "Testing Paanyaya flow...\n";
$db = (new Database())->connect();

// 1. Create a dummy complaint
$complaintModel = new Complaint();
$createRes = $complaintModel->create([
    'complaint_title' => 'Test Paanyaya Boundary Dispute',
    'category_id' => 1,
    'incident_date' => '2026-10-15',
    'incident_time' => '10:00:00',
    'incident_place' => 'Block 4 Lot 2, Barangay Tumana',
    'narrative' => 'Nagkaroon ng hindi pagkakaunawaan tungkol sa bakod sa pagitan ng magkapitbahay.',
    'relief_sought' => 'Maayos na pag-uusap at pagtatakda ng tamang boundary.',
    'is_draft' => 1,
    'created_by' => 1
]);
$cid = (int)($createRes['complaint_id'] ?? 0);
echo "Complaint created: ID {$cid}\n";

// Ensure we have 2 residents
$res = $db->query("SELECT resident_id FROM residents LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$c_res = $res[0] ?? 1;
$r_res = $res[1] ?? 2;

// Link a complainant and respondent
$stmt = $db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant')");
$stmt->execute([$cid, $c_res]);
$stmt = $db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Respondent')");
$stmt->execute([$cid, $r_res]);

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
$paanyayaId = (int)($scheduleRes['paanyaya_id'] ?? 0);

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
$compResolved = $complaintModel->getById($cid);
echo "Complaint status after resolution: {$compResolved['status']} (Expected: Resolved at Paanyaya / Closed)\n";

// Reset to Paanyaya for escalation test
$db->prepare("UPDATE complaints SET status = 'Pre-Docketed (Paanyaya)' WHERE complaint_id = ?")->execute([$cid]);
$db->prepare("UPDATE complaint_paanyaya SET status = 'Scheduled' WHERE paanyaya_id = ?")->execute([$paanyayaId]);

// 4. Test Escalation
$escalateResult = $paanyayaModel->recordOutcome($paanyayaId, 'Escalate', 'Respondent failed to appear for dialogue.', 1, [
    'hearing_date' => '2026-11-03',
    'hearing_time' => '14:00',
    'case_type' => 'Civil'
]);
echo "Escalation result: " . json_encode($escalateResult) . "\n";
echo "Escalation completed: " . json_encode($escalateResult['success'] ?? false) . ". Case Number: " . ($escalateResult['case_number'] ?? 'N/A') . "\n";

// Verify case created and status
$compUpdated = $complaintModel->getById($cid);
echo "Updated complaint status: {$compUpdated['status']}\n";
$caseId = (int)($escalateResult['case_id'] ?? 0);

// Check mediation hearing
$stmt = $db->prepare("SELECT * FROM hearings WHERE case_id = ?");
$stmt->execute([$caseId]);
$hearing = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Mediation Hearing created: " . ($hearing ? "Hearing ID {$hearing['hearing_id']} / Type: {$hearing['hearing_type']} / Date: {$hearing['hearing_date']} / Venue: {$hearing['venue']}" : "None") . "\n";

// Check generated documents (KP Form 8 & 9)
$stmt = $db->prepare("SELECT document_id, template_id, file_path FROM generated_documents WHERE case_id = ?");
$stmt->execute([$caseId]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Generated Documents count: " . count($docs) . "\n";

// Check summon deliveries
$stmt = $db->prepare("SELECT delivery_id, party_type, delivery_status FROM summon_deliveries WHERE case_id = ?");
$stmt->execute([$caseId]);
$delivs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Summon Deliveries count: " . count($delivs) . "\n";

// Clean up test data
if ($caseId > 0) {
    $db->prepare("DELETE FROM summon_deliveries WHERE case_id = ?")->execute([$caseId]);
    $db->prepare("DELETE FROM generated_documents WHERE case_id = ?")->execute([$caseId]);
    $db->prepare("DELETE FROM case_history WHERE case_id = ?")->execute([$caseId]);
    $db->prepare("DELETE FROM case_deadlines WHERE case_id = ?")->execute([$caseId]);
    $db->prepare("DELETE FROM case_assignments WHERE case_id = ?")->execute([$caseId]);
    $db->prepare("DELETE FROM hearings WHERE case_id = ?")->execute([$caseId]);
    $db->prepare("DELETE FROM cases WHERE case_id = ?")->execute([$caseId]);
}
$db->prepare("DELETE FROM complaint_paanyaya WHERE complaint_id = ?")->execute([$cid]);
$db->prepare("DELETE FROM complaint_parties WHERE complaint_id = ?")->execute([$cid]);
$db->prepare("DELETE FROM complaints WHERE complaint_id = ?")->execute([$cid]);
if (file_exists($testPdfFile)) @unlink($testPdfFile);

echo "Test completed successfully and test data cleaned up!\n";
