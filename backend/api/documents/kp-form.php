<?php

session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/PDFService.php';
require_once __DIR__ . '/../../services/ShowCauseService.php';

$conn = (new Database())->connect();
$documentId = filter_input(INPUT_GET, 'document_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$caseId = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$formCode = trim((string) ($_GET['form_code'] ?? 'KP Form 18'));
$asPdf = isset($_GET['pdf']) && $_GET['pdf'] == '1';

if ($documentId) {
    // If document_id is specified, lookup document
    $docStmt = $conn->prepare("
        SELECT gd.*, dt.template_name, c.case_id, c.case_number, c.complaint_id
        FROM generated_documents gd
        INNER JOIN document_templates dt ON dt.template_id = gd.template_id
        INNER JOIN cases c ON c.case_id = gd.case_id
        WHERE gd.document_id = ?
    ");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if ($doc) {
        $caseId = (int) $doc['case_id'];
        $formCode = $doc['template_name'];

        // If file exists, serve it
        $root = realpath(dirname(__DIR__, 3));
        $filePath = realpath($root . '/' . $doc['file_path']);
        if ($filePath && is_file($filePath)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
            exit;
        }
    }
}

if (!$caseId) {
    http_response_code(404);
    exit('Valid case ID or document ID required.');
}

// Fetch case details
$caseStmt = $conn->prepare("
    SELECT c.*, co.complaint_number, co.complaint_title
    FROM cases c
    INNER JOIN complaints co ON co.complaint_id = c.complaint_id
    WHERE c.case_id = ?
");
$caseStmt->execute([$caseId]);
$case = $caseStmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    http_response_code(404);
    exit('Case not found.');
}

// Fetch hearing details if provided or find latest hearing
if ($hearingId) {
    $hStmt = $conn->prepare("SELECT * FROM hearings WHERE hearing_id = ? AND case_id = ?");
    $hStmt->execute([$hearingId, $caseId]);
    $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
} else {
    $hStmt = $conn->prepare("SELECT * FROM hearings WHERE case_id = ? ORDER BY hearing_date DESC LIMIT 1");
    $hStmt->execute([$caseId]);
    $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
}

$showCauseService = new ShowCauseService($conn);
$parties = $showCauseService->getCaseParties((int) $case['complaint_id']);

$targetPartyType = (str_contains($formCode, '19') || str_contains($formCode, '22')) ? 'Respondent' : 'Complainant';
$targetPartyName = ($targetPartyType === 'Complainant')
    ? ($parties['complainant']['full_name'] ?? 'Complainant')
    : ($parties['respondent']['full_name'] ?? 'Respondent');

// Get Lupon Chairman name
$chairStmt = $conn->prepare("
    SELECT TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS chair_name
    FROM case_assignments ca
    INNER JOIN users u ON u.user_id = ca.member_id
    WHERE ca.case_id = ? AND ca.assignment_role IN ('Head', 'Mediator')
    ORDER BY ca.assigned_date DESC LIMIT 1
");
$chairStmt->execute([$caseId]);
$chairName = $chairStmt->fetchColumn() ?: 'Punong Barangay / Lupon Chairman';

$pdfData = [
    'case_number' => $case['case_number'],
    'complaint_title' => $case['complaint_title'],
    'complainants' => isset($parties['complainant']) ? [$parties['complainant']] : [],
    'respondents' => isset($parties['respondent']) ? [$parties['respondent']] : [],
    'target_party_name' => $targetPartyName,
    'venue' => $hearing['venue'] ?? 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall',
    'hearing_date' => $hearing['hearing_date'] ?? date('Y-m-d H:i:s'),
    'explanation_date' => date('Y-m-d 09:00:00', strtotime('+3 days')),
    'notice_date' => date('Y-m-d'),
    'official_name' => $chairName,
];

try {
    $pdfService = new PDFService();
    if ($asPdf) {
        $tempPath = sys_get_temp_dir() . '/notice_' . time() . '.pdf';
        $pdfService->generateNotice($formCode, $pdfData, $tempPath);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $formCode) . '.pdf"');
        readfile($tempPath);
        @unlink($tempPath);
        exit;
    }
} catch (Throwable $e) {
    // Fall back to HTML
}

// Render printable HTML page
?>
<!DOCTYPE html>
<html lang="tl">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($formCode) ?> - <?= htmlspecialchars($case['case_number']) ?></title>
    <style>
        body { font-family: "Georgia", serif; font-size: 11pt; color: #111; line-height: 1.5; margin: 30px auto; max-width: 800px; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h3, .header h4 { margin: 2px 0; font-weight: normal; }
        .header h2 { margin: 10px 0 0 0; text-transform: uppercase; font-size: 14pt; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .meta-table td { vertical-align: top; padding: 4px 8px; width: 50%; }
        .party-name { font-weight: bold; border-bottom: 1px solid #333; min-height: 22px; padding-bottom: 2px; }
        .party-sub { font-size: 9.5pt; font-style: italic; color: #555; }
        .vs { text-align: center; font-weight: bold; margin: 8px 0; }
        .title-box { text-align: center; margin: 25px 0 15px; }
        .title-box h3 { margin: 0; font-size: 13pt; text-transform: uppercase; letter-spacing: 0.5px; }
        .content { text-align: justify; text-indent: 30px; margin: 20px 0; line-height: 1.8; }
        .sig-block { margin-top: 40px; float: right; width: 250px; text-align: center; }
        .sig-line { border-top: 1px solid #111; margin-top: 40px; padding-top: 4px; font-weight: bold; }
        .actions-bar { margin-bottom: 20px; padding: 10px 15px; background: #f0f4f8; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #d0d7de; }
        .btn { padding: 8px 14px; font-size: 10pt; font-family: sans-serif; cursor: pointer; border-radius: 4px; border: 1px solid #ccc; text-decoration: none; display: inline-block; font-weight: 600; }
        .btn-primary { background: #007bff; color: #fff; border-color: #007bff; }
        .btn-secondary { background: #fff; color: #333; }
        @media print {
            .actions-bar { display: none; }
            body { margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="actions-bar">
        <div><strong><?= htmlspecialchars($formCode) ?></strong> &mdash; Case #<?= htmlspecialchars($case['case_number']) ?></div>
        <div>
            <button class="btn btn-secondary" onclick="window.print()">Print Form</button>
            <a href="kp-form.php?case_id=<?= $caseId ?>&hearing_id=<?= $hearingId ?>&form_code=<?= urlencode($formCode) ?>&pdf=1" class="btn btn-primary">Download PDF</a>
        </div>
    </div>

    <div class="header">
        <h4>Republika ng Pilipinas</h4>
        <h4>Lungsod ng Marikina</h4>
        <h4>Barangay Tumana</h4>
        <h2>Tanggapan ng Lupong Tagapamayapa</h2>
    </div>

    <table class="meta-table">
        <tr>
            <td>
                <div class="party-name"><?= htmlspecialchars($parties['complainant']['full_name'] ?? 'Complainant') ?></div>
                <div class="party-sub">(Mga) May-sumbong</div>
                <div class="vs">&mdash; laban kay / kina &mdash;</div>
                <div class="party-name"><?= htmlspecialchars($parties['respondent']['full_name'] ?? 'Respondent') ?></div>
                <div class="party-sub">(Mga) Ipinagsusumbong</div>
            </td>
            <td style="padding-left: 30px;">
                <div><strong>Usaping Barangay Blg.:</strong> <?= htmlspecialchars($case['case_number']) ?></div>
                <div style="margin-top: 6px;"><strong>Ukol sa:</strong> <?= htmlspecialchars($case['complaint_title']) ?></div>
            </td>
        </tr>
    </table>

    <div class="title-box">
        <h3><?= htmlspecialchars($formCode) ?></h3>
        <?php if ($formCode === 'KP Form 18'): ?>
            <div style="font-weight: bold; margin-top: 4px;">PAUNAWA SA PAGDINIG (Para sa May-sumbong ukol sa kabiguang humarap)</div>
        <?php elseif ($formCode === 'KP Form 19'): ?>
            <div style="font-weight: bold; margin-top: 4px;">PAUNAWA SA PAGDINIG (Para sa Ipinagsusumbong ukol sa kabiguang humarap)</div>
        <?php elseif ($formCode === 'KP Form 21'): ?>
            <div style="font-weight: bold; margin-top: 4px;">KATIBAYAN UPANG HADLANGAN ANG PAGHAHAIN NG AKSYON</div>
        <?php elseif ($formCode === 'KP Form 22'): ?>
            <div style="font-weight: bold; margin-top: 4px;">KATIBAYAN UPANG HADLANGAN ANG GANTING-SAKDAL</div>
        <?php endif; ?>
    </div>

    <?php if ($formCode === 'KP Form 18'): ?>
        <p>KAY: <strong><?= htmlspecialchars($targetPartyName) ?></strong> (May-sumbong)</p>
        <p class="content">
            Kayo ay tinatawagan at inaatasan na humarap sa akin sa <?= htmlspecialchars($hearing['venue'] ?? 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall') ?> sa darating na <strong><?= date('F j, Y \s\a \g\a\n\a\p \n\g g:i A', strtotime('+3 days 09:00:00')) ?></strong>, upang magpaliwanag kung bakit hindi dapat ipag-utos ang pagwawalang-bisa sa inyong reklamo o hadlangan ang inyong karapatang maghain ng nasabing aksyon sa hukuman dahil sa inyong kabiguang humarap sa itinakdang pagdinig noong <strong><?= !empty($hearing['hearing_date']) ? date('F j, Y', strtotime($hearing['hearing_date'])) : 'itinakdang araw' ?></strong> nang walang makatwirang dahilan, matapos kayong maabisuhan nang buong husay alinsunod sa Seksiyon 415 ng Batas Republika Blg. 7160.
        </p>
    <?php elseif ($formCode === 'KP Form 19'): ?>
        <p>KAY: <strong><?= htmlspecialchars($targetPartyName) ?></strong> (Ipinagsusumbong)</p>
        <p class="content">
            Kayo ay tinatawagan at inaatasan na humarap sa akin sa <?= htmlspecialchars($hearing['venue'] ?? 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall') ?> sa darating na <strong><?= date('F j, Y \s\a \g\a\n\a\p \n\g g:i A', strtotime('+3 days 09:00:00')) ?></strong>, upang magpaliwanag kung bakit hindi dapat ipag-utos ang paghadlang sa inyong karapatang maghain ng ganting-sakdal (counterclaim) kaugnay ng usaping ito dahil sa inyong kabiguang humarap sa itinakdang pagdinig noong <strong><?= !empty($hearing['hearing_date']) ? date('F j, Y', strtotime($hearing['hearing_date'])) : 'itinakdang araw' ?></strong> nang walang makatwirang dahilan, matapos mapatunayang kayo ay maayos na napagsilbihan ng patawag alinsunod sa batas.
        </p>
    <?php elseif ($formCode === 'KP Form 21'): ?>
        <p class="content">
            Ito ay nagpapatunay na ang may-sumbong na si <strong><?= htmlspecialchars($targetPartyName) ?></strong> ay nabigong humarap sa itinakdang pagdinig noong <strong><?= !empty($hearing['hearing_date']) ? date('F j, Y', strtotime($hearing['hearing_date'])) : 'itinakdang araw' ?></strong> at walang maibigay na makatwiran at sapat na dahilan para sa kanyang hindi pagharap.
        </p>
        <p class="content">
            Dahil dito, alinsunod sa itinatakda ng Seksiyon 415 ng Kodigo ng Pamahalaang Lokal ng 1991 (Batas Republika Blg. 7160), ang may-sumbong ay <strong>HINAHADLANGAN</strong> sa paghahain ng nasabing usapin o aksyon sa hukuman o anumang tanggapan ng pamahalaan.
        </p>
    <?php elseif ($formCode === 'KP Form 22'): ?>
        <p class="content">
            Ito ay nagpapatunay na ang ipinagsusumbong na si <strong><?= htmlspecialchars($targetPartyName) ?></strong> ay nabigong humarap sa itinakdang pagdinig noong <strong><?= !empty($hearing['hearing_date']) ? date('F j, Y', strtotime($hearing['hearing_date'])) : 'itinakdang araw' ?></strong> at walang maibigay na makatwiran at sapat na dahilan para sa kanyang hindi pagharap matapos mapatunayang maayos na napagsilbihan ng patawag.
        </p>
        <p class="content">
            Dahil dito, alinsunod sa itinatakda ng Seksiyon 415 ng Kodigo ng Pamahalaang Lokal ng 1991 (Batas Republika Blg. 7160), ang ipinagsusumbong ay <strong>HINAHADLANGAN</strong> sa paghahain ng ganting-sakdal (counterclaim) na nagmumula sa nasabing sumbong.
        </p>
    <?php endif; ?>

    <p style="margin-top: 30px;">
        Iginawad ngayong ika-<strong><?= date('j') ?></strong> araw ng <strong><?= date('F, Y') ?></strong> sa Barangay Tumana, Marikina City.
    </p>

    <div class="sig-block">
        <div class="sig-line"><?= htmlspecialchars($chairName) ?></div>
        <div>Punong Barangay / Tagapangulo</div>
    </div>
</body>
</html>
