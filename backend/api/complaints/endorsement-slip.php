<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../services/PiiCryptoService.php';

$db = (new Database())->connect();
$userId = agapSessionUserId();
$roleName = agapGetRoleName($db);
if ($userId <= 0 || !in_array($roleName, ['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member', 'BPSO', 'Resident'], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$complaintId = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId || !agapCanAccessComplaint($db, (int) $complaintId, $userId, $roleName, 'read')) {
    http_response_code(403);
    exit('You are not authorized to view this endorsement slip.');
}

$stmt = $db->prepare(
    "SELECT c.complaint_id, c.intake_reference, c.bpso_blotter_no, c.complaint_title,
            c.incident_date, c.incident_location, c.narrative,
            c.liso_endorsement_agency, c.liso_counseling_notes, c.liso_endorsed_at, c.liso_reviewed_at,
            TRIM(CONCAT_WS(' ', liso_u.first_name, liso_u.last_name)) AS liso_officer_name
     FROM complaints c
     LEFT JOIN users liso_u ON liso_u.user_id = c.liso_reviewed_by
     WHERE c.complaint_id = ? AND c.deleted_at IS NULL
     LIMIT 1"
);
$stmt->execute([$complaintId]);
$raw = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$raw || trim((string) ($raw['liso_endorsement_agency'] ?? '')) === '') {
    http_response_code(422);
    exit('No LISO external agency endorsement has been recorded for this complaint.');
}
$row = PiiCryptoService::decryptPayload($raw);

$partyStmt = $db->prepare(
    "SELECT cp.party_type, r.first_name, r.middle_name, r.last_name, r.address, r.purok
     FROM complaint_parties cp
     INNER JOIN residents r ON r.resident_id = cp.resident_id
     WHERE cp.complaint_id = ?
     ORDER BY cp.party_type ASC, cp.party_id ASC"
);
$partyStmt->execute([$complaintId]);
$parties = PiiCryptoService::decryptPayload($partyStmt->fetchAll(PDO::FETCH_ASSOC));
$complainants = [];
$respondents = [];
foreach ($parties as $p) {
    $nm = trim(implode(' ', array_filter([$p['first_name'] ?? '', $p['middle_name'] ?? '', $p['last_name'] ?? ''])));
    $ad = trim(implode(', ', array_filter([$p['address'] ?? '', $p['purok'] ?? ''])));
    $entry = $nm . ($ad !== '' ? " ({$ad})" : '');
    if (strcasecmp((string) ($p['party_type'] ?? ''), 'Respondent') === 0) {
        $respondents[] = $entry;
    } elseif (strcasecmp((string) ($p['party_type'] ?? ''), 'Complainant') === 0) {
        $complainants[] = $entry;
    }
}

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ref = $row['intake_reference'] ?: ('CMP-' . $complaintId);
$endorsedDate = $row['liso_endorsed_at'] ?: ($row['liso_reviewed_at'] ?: date('Y-m-d H:i:s'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>LISO Agency Endorsement Slip · <?= $esc($ref) ?></title>
    <style>
        body { font-family: Georgia, "Times New Roman", serif; color: #111827; margin: 28px auto; max-width: 780px; padding: 0 18px; line-height: 1.5; }
        .sheet { border: 1.5px solid #1f2937; padding: 28px 34px; background: #fff; }
        .hdr { text-align: center; border-bottom: 2px solid #111827; padding-bottom: 12px; margin-bottom: 18px; }
        .hdr h1 { margin: 4px 0 2px; font-size: 1.15rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .hdr p { margin: 0; font-size: 0.9rem; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; margin-bottom: 16px; font-size: 0.92rem; }
        .box { border: 1px solid #cbd5e1; background: #f8fafc; padding: 12px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 0.93rem; }
        .box strong.lbl { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: #334155; margin-bottom: 4px; }
        .sig { margin-top: 34px; display: flex; justify-content: space-between; gap: 24px; }
        .sig-block { width: 46%; text-align: center; }
        .sig-line { border-top: 1px solid #111827; padding-top: 6px; margin-top: 36px; font-weight: bold; font-size: 0.9rem; }
        .print-bar { margin-bottom: 14px; display: flex; justify-content: flex-end; gap: 8px; font-family: system-ui, sans-serif; }
        .btn { padding: 8px 16px; border-radius: 6px; border: 1px solid #1b6553; background: #1b6553; color: #fff; font-weight: 700; cursor: pointer; }
        @media print { .print-bar { display: none; } body { margin: 0; } .sheet { border: none; } }
    </style>
</head>
<body>
    <div class="print-bar">
        <button type="button" class="btn" onclick="window.print()">Print / Save PDF</button>
    </div>
    <div class="sheet">
        <div class="hdr">
            <p>Republic of the Philippines · City of Marikina</p>
            <p><strong>BARANGAY TUMANA</strong></p>
            <p>Office of the Lupon Tagapamayapa · Lupon Information &amp; Screening Office (LISO)</p>
            <h1>Pre-Filing Counseling &amp; External Agency Endorsement</h1>
        </div>

        <div class="meta">
            <div><strong>Complaint Reference No.:</strong> <?= $esc($ref) ?></div>
            <div><strong>Date Endorsed:</strong> <?= $esc(date('F j, Y · g:i A', strtotime((string) $endorsedDate))) ?></div>
            <div><strong>BPSO Blotter Ref No.:</strong> <?= $esc($row['bpso_blotter_no'] ?: '—') ?></div>
            <div><strong>Endorsed Government Agency:</strong> <?= $esc($row['liso_endorsement_agency']) ?></div>
        </div>

        <div class="box">
            <strong class="lbl">Parties Involved</strong>
            <div><strong>Complainant(s):</strong> <?= $esc($complainants ? implode('; ', $complainants) : 'On record') ?></div>
            <div><strong>Respondent(s):</strong> <?= $esc($respondents ? implode('; ', $respondents) : 'On record') ?></div>
        </div>

        <div class="box">
            <strong class="lbl">Subject &amp; Incident Summary</strong>
            <div><strong><?= $esc($row['complaint_title']) ?></strong></div>
            <div style="margin-top:4px; white-space:pre-line;"><?= $esc($row['narrative']) ?></div>
        </div>

        <div class="box" style="background:#fff;">
            <strong class="lbl">Jurisdictional Screening &amp; Pre-Filing Counseling Notes</strong>
            <div style="white-space:pre-line;"><?= $esc($row['liso_counseling_notes']) ?></div>
        </div>

        <p style="font-size:0.9rem;">
            Pursuant to the screening assessment under Republic Act No. 7160 (Katarungang Pambarangay Law), this matter falls outside barangay conciliation jurisdiction and is respectfully endorsed to <strong><?= $esc($row['liso_endorsement_agency']) ?></strong> for appropriate action.
        </p>

        <div class="sig">
            <div class="sig-block">
                <div class="sig-line"><?= $esc($row['liso_officer_name'] ?: 'Duty LISO Officer') ?></div>
                <div style="font-size:0.8rem;">Lupon Information &amp; Screening Officer (LISO)</div>
            </div>
            <div class="sig-block">
                <div class="sig-line">Received By / Complainant</div>
                <div style="font-size:0.8rem;">Signature over Printed Name &amp; Date</div>
            </div>
        </div>
    </div>
</body>
</html>
