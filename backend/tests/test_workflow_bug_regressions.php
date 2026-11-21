<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/ProofOfService.php';
require_once __DIR__ . '/../services/PDFService.php';
require_once __DIR__ . '/../services/SummonDeliveryService.php';
require_once __DIR__ . '/../services/ResidentComplaintTimeline.php';
require_once __DIR__ . '/TestDataCleanup.php';

$db = (new Database())->connect();
$cleanup = new TestDataCleanup($db);
$token = bin2hex(random_bytes(5));
$_SESSION = ['user_id' => 1, 'role_id' => 1, 'role_name' => 'Administrator'];
function verifyWorkflowBug(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, category_id, status, narrative, encoded_by)
    VALUES (?, 'Workflow regression', 1, 'Submitted', 'Synthetic workflow regression.', 1)")->execute(['BUG-' . $token]);
$complaintId = (int) $db->lastInsertId();
$cleanup->trackComplaint($complaintId);
$model = new Complaint();
verifyWorkflowBug(empty($model->review($complaintId, 'Rejected', null, 1)['success']), 'Rejection requires a reason on the server');
verifyWorkflowBug(!empty($model->review($complaintId, 'Rejected', 'Outside territorial jurisdiction.', 1)['success']), 'Ineligible intake leaves screening with a saved basis');
verifyWorkflowBug($db->query("SELECT status FROM complaints WHERE complaint_id = $complaintId")->fetchColumn() === 'Rejected', 'Rejection persists the terminal intake status');

$residents = $db->query('SELECT resident_id FROM residents ORDER BY resident_id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
verifyWorkflowBug(count($residents) === 3, 'Three resident fixtures are available');
$db->prepare("UPDATE complaints SET status = 'Conciliation', screening_decision = 'Eligible' WHERE complaint_id = ?")->execute([$complaintId]);
$party = $db->prepare('INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, ?)');
$party->execute([$complaintId, $residents[0], 'Complainant']);
$party->execute([$complaintId, $residents[1], 'Respondent']);
$party->execute([$complaintId, $residents[2], 'Respondent']);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date) VALUES (?, ?, 'Conciliation', CURDATE())")->execute([$complaintId, 'BUG-' . $token]);
$caseId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Conciliation', '2026-11-23 14:00:00', 'Lupon Court Room', 'Completed')")->execute([$caseId]);
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Conciliation', '2026-11-25 14:00:00', 'Lupon Court Room', 'Scheduled')")->execute([$caseId]);
$hearingId = (int) $db->lastInsertId();
$delivery = $db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, resident_id, party_type, form_type, delivery_status, attempt_number) VALUES (?, ?, ?, ?, 'KP Form 12', ?, ?)");
foreach ($residents as $index => $residentId) $delivery->execute([$caseId, $hearingId, $residentId, $index === 0 ? 'Complainant' : 'Respondent', 'Served Personal', 1]);
$read = static function () use ($caseId): array {
    foreach ((new ProofOfService())->getAvailableCases() as $row) if ((int) $row['case_id'] === $caseId) return $row;
    throw new RuntimeException('Case missing from service queue');
};
verifyWorkflowBug($read()['respondent_service_status'] === 'Served', 'All current recipient returns permit the served badge');
$delivery->execute([$caseId, $hearingId, $residents[2], 'Respondent', 'Pending', 2]);
$retryId = (int) $db->lastInsertId();
(new SummonDeliveryService($db))->initializeHearingDeliveries($hearingId, 1);
verifyWorkflowBug((int) $db->query("SELECT attempt_number FROM summon_deliveries WHERE delivery_id = $retryId")->fetchColumn() === 2, 'Opening second Conciliation preserves attempt numbers without colliding with a retry');
verifyWorkflowBug($read()['respondent_service_status'] === 'Pending', 'A pending second attempt overrides an earlier served return for one co-respondent');
$db->prepare("UPDATE summon_deliveries SET delivery_status = 'Superseded' WHERE delivery_id = ?")->execute([$retryId]);
verifyWorkflowBug($read()['respondent_service_status'] === 'Served', 'Superseded attempts are excluded from the operative badge');

$html = (new PDFService($db))->renderKp12Html(['hearing_date' => '2026-11-25 14:00:00', 'venue' => 'Lupon Court Room']);
$text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
verifyWorkflowBug(str_contains($text, '25 ng Nobyembre'), 'KP Form 12 includes the hearing day and Filipino month');
verifyWorkflowBug(!str_contains($text, '2:00 PM ng Nobyembre'), 'KP Form 12 does not append an incomplete date to the hearing time');

$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, category_id, status, narrative, encoded_by, submitted_by, created_at)
    VALUES (?, 'Timeline regression', 1, 'Pre-Docketed (Paanyaya)', 'Synthetic timeline.', 1, 1, '2026-11-21 08:00:00')")->execute(['TIME-' . $token]);
$timelineId = (int) $db->lastInsertId();
$cleanup->trackComplaint($timelineId);
$version = str_repeat('a', 64);
$db->prepare("INSERT INTO paanyaya_preparations (complaint_id, invitation_number, appointment_at, venue, verified_parties, document_pdf, document_version)
    VALUES (?, 1, '2026-11-23 09:00:00', 'Lupon Court Room', '[]', 'test-only', ?)")->execute([$timelineId, $version]);
$db->prepare("INSERT INTO paanyaya_service_attempts (complaint_id, invitation_number, appointment_at, document_version, assigned_to, outcome, recorded_at)
    VALUES (?, 1, '2026-11-23 09:00:00', ?, 1, 'Delivered', '2026-11-21 09:00:45')")->execute([$timelineId, $version]);
$db->prepare("INSERT INTO paanyaya_minutes (complaint_id, stage_type, dialogue_date, discussion_summary, outcome, complainant_choice, recorded_by)
    VALUES (?, 'Paanyaya 1', '2026-11-21 09:00:00', 'Synthetic dialogue.', 'No_Agreement', 'Escalate_Mediation', 1)")->execute([$timelineId]);
$titles = array_column((new ResidentComplaintTimeline($db))->find($timelineId, 1)['events'], 'title');
verifyWorkflowBug(array_search('Paanyaya appointment confirmed', $titles, true) < array_search('Dialogue ended without agreement. Formal mediation requested.', $titles, true), 'Same-minute appointment confirmation precedes the dialogue outcome');
