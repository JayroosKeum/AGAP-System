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
$documentId = filter_input(INPUT_GET, 'document_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (isset($_GET['document_id']) ? (int) $_GET['document_id'] : null);
$caseId = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (isset($_GET['case_id']) ? (int) $_GET['case_id'] : null);
$hearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (isset($_GET['hearing_id']) ? (int) $_GET['hearing_id'] : null);
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

// Determine hearing sequence for mediation
$hearingSequence = 1;
if (!empty($hearing['hearing_id'])) {
    $seqStmt = $conn->prepare("
        SELECT COUNT(*) FROM hearings 
        WHERE case_id = ? AND hearing_type IN ('Initial Hearing', 'Mediation') AND hearing_id <= ?
    ");
    $seqStmt->execute([$caseId, $hearing['hearing_id']]);
    $hearingSequence = (int) $seqStmt->fetchColumn() ?: 1;
}

$isPreview = isset($_GET['preview']) && in_array((string) $_GET['preview'], ['1', 'true'], true);

// Enforce strict party eligibility and locking rules
if ($formCode === 'KP Form 18') {
    // KP Form 18 is strictly for Complainant failure to appear in 2nd (or later) mediation session
    if ($hearingSequence < 2) {
        http_response_code(422);
        exit('Data Validation Error: KP Form 18 cannot be generated for the 1st Mediation session. Under Katarungang Pambarangay rules, after the 1st mediation, proceed directly to book the 2nd mediation without sending another summon. KP Form 18 (Notice of Hearing - Complainant Non-appearance) may only be previewed or printed after non-appearance is recorded in the 2nd mediation.');
    }

    $isCompAbsent = ($hearing && ($hearing['complainant_attendance'] ?? '') === 'Absent');
    if (!$isCompAbsent && !empty($hearing['hearing_id'])) {
        $haStmt = $conn->prepare("
            SELECT COUNT(*) FROM hearing_attendance ha
            INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?
            WHERE ha.hearing_id = ? AND cp.party_type = 'Complainant' AND ha.attendance_status = 'Absent'
        ");
        $haStmt->execute([(int) $case['complaint_id'], $hearing['hearing_id']]);
        $isCompAbsent = ((int) $haStmt->fetchColumn() > 0);
    }
    if (!$isCompAbsent && !$isPreview) {
        http_response_code(403);
        exit('KP Form 18 (Notice of Hearing - Complainant Non-appearance) is locked: The Complainant has not been recorded absent in this 2nd Mediation session.');
    }
} elseif ($formCode === 'KP Form 19') {
    // KP Form 19 is strictly for Respondent failure to appear in 2nd (or later) mediation session
    if ($hearingSequence < 2) {
        http_response_code(422);
        exit('Data Validation Error: KP Form 19 cannot be generated for the 1st Mediation session. Under Katarungang Pambarangay rules, after the 1st mediation, proceed directly to book the 2nd mediation without sending another summon. KP Form 19 (Notice of Hearing - Respondent Non-appearance) may only be previewed or printed after non-appearance is recorded in the 2nd mediation.');
    }

    $isRespAbsent = ($hearing && ($hearing['respondent_attendance'] ?? '') === 'Absent');
    if (!$isRespAbsent && !empty($hearing['hearing_id'])) {
        $haStmt = $conn->prepare("
            SELECT COUNT(*) FROM hearing_attendance ha
            INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?
            WHERE ha.hearing_id = ? AND cp.party_type = 'Respondent' AND ha.attendance_status = 'Absent'
        ");
        $haStmt->execute([(int) $case['complaint_id'], $hearing['hearing_id']]);
        $isRespAbsent = ((int) $haStmt->fetchColumn() > 0);
    }
    if (!$isRespAbsent && !$isPreview) {
        http_response_code(403);
        exit('KP Form 19 (Notice of Hearing - Respondent Non-appearance) is locked: The Respondent has not been recorded absent in this 2nd Mediation session.');
    }
} elseif ($formCode === 'KP Form 21') {
    // KP Form 21: Certificate to Bar Action (Complainant)
    // Strictly requires a recorded show-cause evaluation where party_type = 'Complainant' and is_justified = 0
    $scStmt = $conn->prepare("
        SELECT evaluation_id, is_justified, action_taken, justification_notes
        FROM show_cause_evaluations
        WHERE case_id = ? AND party_type = 'Complainant'
        ORDER BY created_at DESC LIMIT 1
    ");
    $scStmt->execute([$caseId]);
    $eval = $scStmt->fetch(PDO::FETCH_ASSOC);

    if (!$eval) {
        http_response_code(403);
        exit('KP Form 21 (Certificate to Bar Action) is locked: The Complainant has not undergone a show-cause hearing evaluation. Required action: Schedule and evaluate the Complainant\'s show-cause hearing before issuing KP Form 21.');
    }
    if ((int) $eval['is_justified'] === 1) {
        http_response_code(403);
        exit('KP Form 21 cannot be issued: The Complainant\'s absence was ruled justified. The dispute was rescheduled for mediation.');
    }
} elseif ($formCode === 'KP Form 22') {
    // KP Form 22: Certificate to Bar Counterclaim (Respondent)
    // Strictly requires a recorded show-cause evaluation where party_type = 'Respondent' and is_justified = 0
    $scStmt = $conn->prepare("
        SELECT evaluation_id, is_justified, action_taken, justification_notes
        FROM show_cause_evaluations
        WHERE case_id = ? AND party_type = 'Respondent'
        ORDER BY created_at DESC LIMIT 1
    ");
    $scStmt->execute([$caseId]);
    $eval = $scStmt->fetch(PDO::FETCH_ASSOC);

    if (!$eval) {
        http_response_code(403);
        exit('KP Form 22 (Certificate to Bar Counterclaim) is locked: The Respondent has not undergone a show-cause hearing evaluation. Required action: Schedule and evaluate the Respondent\'s show-cause hearing before issuing KP Form 22.');
    }
    if ((int) $eval['is_justified'] === 1) {
        http_response_code(403);
        exit('KP Form 22 cannot be issued: The Respondent\'s absence was ruled justified. The dispute was rescheduled for mediation.');
    }
} elseif ($formCode === 'KP Form 16') {
    // Fetch settlement agreement details from settlements or latest hearing minutes
    $settleStmt = $conn->prepare("SELECT * FROM settlements WHERE case_id = ? LIMIT 1");
    $settleStmt->execute([$caseId]);
    $settle = $settleStmt->fetch(PDO::FETCH_ASSOC);

    $agreementText = $settle['agreement_details'] ?? '';
    if (!$agreementText) {
        $minStmt = $conn->prepare("
            SELECT hm.agreements_action_items, hm.settlement_discussion_notes
            FROM hearing_minutes hm
            INNER JOIN hearings h ON h.hearing_id = hm.hearing_id
            WHERE h.case_id = ? AND (hm.session_outcome = 'Settled' OR hm.agreements_action_items != '')
            ORDER BY hm.updated_at DESC LIMIT 1
        ");
        $minStmt->execute([$caseId]);
        $minRow = $minStmt->fetch(PDO::FETCH_ASSOC);
        $agreementText = $minRow['agreements_action_items'] ?? $minRow['settlement_discussion_notes'] ?? '';
    }
    $pdfData['agreement_details'] = $agreementText;
    $pdfData['settlement_date'] = $settle['settlement_date'] ?? date('Y-m-d');
}

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
    'agreement_details' => $pdfData['agreement_details'] ?? '',
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
        <?php elseif ($formCode === 'KP Form 16'): ?>
            <div style="font-weight: bold; margin-top: 4px;">KASUNDUANG PAG-AAYOS (Amicable Settlement)</div>
        <?php elseif ($formCode === 'KP Form 8'): ?>
            <div style="font-weight: bold; margin-top: 4px;">PAABISO NG PAGDINIG (Mediation Proceedings)</div>
        <?php elseif ($formCode === 'KP Form 9'): ?>
            <div style="font-weight: bold; margin-top: 4px;">PATAWAG (Summons - Ipinagsusumbong)</div>
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
    <?php elseif ($formCode === 'KP Form 16'): ?>
        <p class="content">
            Kami, ang (mga) may-sumbong at (mga) ipinagsusumbong sa usaping ito, ay nagkasundo nang buong laya at kusang-loob na ayusin ang aming alitan sa ilalim ng mga sumusunod na kasunduan:
        </p>
        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 14px 18px; margin: 15px 0; font-family: inherit; font-size: 10.5pt; line-height: 1.7; white-space: pre-wrap;">
            <?= htmlspecialchars(!empty($pdfData['agreement_details']) ? $pdfData['agreement_details'] : 'Ang mga panig ay nagkasundo na tapusin at ayusin ang lahat ng usapin at alitan kaugnay ng sumbong na ito sa mapayapang paraan.') ?>
        </div>
        <p class="content">
            Kami ay nangangako na tutupad nang buong katapatan sa mga nakasaad sa kasunduang ito. Aming nauunawaan na ang kasunduang ito, matapos ang sampung (10) araw mula sa araw ng paglagda, ay magkakaroon ng bisa at lakas ng isang pinal na hatol ng hukuman alinsunod sa Seksiyon 416 at 417 ng Batas Republika Blg. 7160.
        </p>
        <table style="width: 100%; margin-top: 35px; border-collapse: collapse;">
            <tr>
                <td style="width: 48%; text-align: center; vertical-align: top;">
                    <div style="border-top: 1px solid #111; margin-top: 35px; padding-top: 4px; font-weight: bold;"><?= htmlspecialchars($parties['complainant']['full_name'] ?? 'May-sumbong') ?></div>
                    <div style="font-size: 9.5pt; color: #555;">May-sumbong</div>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; text-align: center; vertical-align: top;">
                    <div style="border-top: 1px solid #111; margin-top: 35px; padding-top: 4px; font-weight: bold;"><?= htmlspecialchars($parties['respondent']['full_name'] ?? 'Ipinagsusumbong') ?></div>
                    <div style="font-size: 9.5pt; color: #555;">Ipinagsusumbong</div>
                </td>
            </tr>
        </table>
        <p style="margin-top: 35px; font-style: italic; font-size: 10pt;">
            PINATUTUNAYAN KO na ang kasunduang ito ay pinagtibay sa aking harapan nang malaya at kusang-loob ng mga panig matapos kong maipaliwanag sa kanila ang mga kahihinatnan nito.
        </p>
    <?php elseif ($formCode === 'KP Form 8'): ?>
        <p style="font-weight: bold; font-size: 11pt;">Para kay/Kina: <?= htmlspecialchars($parties['complainant']['full_name'] ?? $targetPartyName) ?></p>
        <div style="text-align: center; font-size: 10pt; margin-bottom: 15px;">(Mga) Nagrereklamo</div>
        <p class="content">
            Ikaw ay hinihilingang humarap sa akin sa <strong><?= htmlspecialchars($hearing['venue'] ?? 'Tanggapan ng Punong Barangay') ?></strong> sa darating na <strong><?= !empty($hearing['hearing_date']) ? date('F j, Y', strtotime($hearing['hearing_date'])) : 'itinakdang araw' ?></strong> sa ganap na ika-<strong><?= !empty($hearing['hearing_date']) ? date('g:i A', strtotime($hearing['hearing_date'])) : 'itinakdang oras' ?></strong> para sa pagdinig ng iyong reklamo.
        </p>
        <p class="content" style="font-size: 10pt; color: #444; font-style: italic;">
            PAUNAWA: Ang pagdinig na ito ay itinakda/muling itinakda (rescheduled) alinsunod sa mga alituntunin ng Katarungang Pambarangay (Batas Republika Blg. 7160).
        </p>
    <?php elseif ($formCode === 'KP Form 9'): ?>
        <p>KAY: <strong><?= htmlspecialchars($parties['respondent']['full_name'] ?? $targetPartyName) ?></strong> (Ipinagsusumbong)</p>
        <p class="content">
            Kayo ay tinatawagan at inaatasan na humarap sa akin sa <?= htmlspecialchars($hearing['venue'] ?? 'Lupon Court Room') ?> sa darating na <strong><?= !empty($hearing['hearing_date']) ? date('F j, Y \s\a \g\a\n\a\p \n\g g:i A', strtotime($hearing['hearing_date'])) : 'itinakdang araw' ?></strong>, upang sagutin ang sumbong na inihain laban sa inyo kaugnay ng usaping ito. Kayo ay binabalaan na ang inyong pagtanggi o kabiguang humarap ay magkakaroon ng karampatang pananagutan alinsunod sa batas.
        </p>
        <p class="content" style="font-size: 10pt; color: #444; font-style: italic;">
            PAUNAWA: Ang patawag na ito ay inilabas kaugnay ng itinakda/muling itinakdang pagdinig alinsunod sa mga alituntunin ng Katarungang Pambarangay (Batas Republika Blg. 7160).
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
