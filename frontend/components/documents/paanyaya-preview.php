<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Allow standalone query parameters or passed variable data
$complaintId = filter_var($_GET['complaint_id'] ?? ($_GET['id'] ?? null), FILTER_VALIDATE_INT) ?: 0;
$stageType = in_array($_GET['stage_type'] ?? '', ['Paanyaya 1', 'Paanyaya 2'], true) ? $_GET['stage_type'] : 'Paanyaya 1';

// Base defaults
$complainantName = trim((string)($_GET['complainant_name'] ?? 'NAGPAANYAYA'));
$respondentName = trim((string)($_GET['respondent_name'] ?? 'INANYAYAHAN'));
$tanggapan = trim((string)($_GET['tanggapan'] ?? 'Barangay Tumana'));

$invitationDate = trim((string)($_GET['invitation_date'] ?? ''));
if (empty($invitationDate)) {
    $invitationDate = date('Y-m-d', strtotime('+3 weekdays'));
}

$ts = strtotime($invitationDate);
$petsa = $_GET['petsa'] ?? date('j', $ts);
$buwan = $_GET['buwan'] ?? strtoupper(date('F', $ts));
$taon = $_GET['taon'] ?? date('Y', $ts);
$araw = $_GET['araw'] ?? strtoupper(date('l', $ts));
$oras = $_GET['oras'] ?? (!empty($_GET['invitation_time']) ? date('h:i A', strtotime((string)$_GET['invitation_time'])) : '09:00 AM');

$salaysay = trim((string)($_GET['salaysay'] ?? 'Naisin nilang magkausap at magkasundo nang maayos ukol sa kanilang hidwaan.'));
$hasESignature = isset($_GET['has_e_signature']) ? in_array((string)$_GET['has_e_signature'], ['1', 'true', 'on'], true) : true;
$paperSize = in_array(strtolower((string)($_GET['paper'] ?? 'a4')), ['a4', 'legal'], true) ? strtolower((string)$_GET['paper']) : 'a4';

// Logos as relative/base64
$tumanaLogoPath = __DIR__ . '/../../assets/images/tumana_logo.jpg';
$marikinaLogoPath = __DIR__ . '/../../assets/images/marikina_logo.jpg';
$bagongLogoPath = __DIR__ . '/../../assets/images/bagong_pilipinas_logo.png';
$signaturePath = __DIR__ . '/../../assets/images/captain_signature.png';

$tumanaLogoUri = file_exists($tumanaLogoPath) ? '../../assets/images/tumana_logo.jpg' : '';
$bagongLogoUri = file_exists($bagongLogoPath) ? '../../assets/images/bagong_pilipinas_logo.png' : '';
$signatureUri = file_exists($signaturePath) ? '../../assets/images/captain_signature.png' : '';

$docTitle = ($stageType === 'Paanyaya 2') ? 'IKALAWANG PAANYAYA' : 'PAANYAYA';
$docSubtitle = ($stageType === 'Paanyaya 2') ? 'Ikalawang Paunang Imbitasyon para sa Pag-uusap' : 'Paunang Imbitasyon para sa Mapayapang Pag-uusap';
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($docTitle) ?> - Barangay Tumana</title>
    <style>
        :root {
            --sheet-width: <?= $paperSize === 'legal' ? '8.5in' : '8.27in' ?>;
            --sheet-height: <?= $paperSize === 'legal' ? '14in' : '11.69in' ?>;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: #525659;
            font-family: "Times New Roman", Times, Georgia, serif;
            color: #111111;
            display: flex;
            justify-content: center;
            padding: 24px 12px;
            font-size: 11.5pt;
            line-height: 1.5;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .sheet {
            width: var(--sheet-width);
            min-height: var(--sheet-height);
            background: #ffffff;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.35);
            padding: 0.75in 0.85in 0.85in 0.85in;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Barangay Header */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        .header-table td {
            vertical-align: middle;
            border: none;
        }

        .logo-col {
            width: 90px;
            text-align: center;
        }

        .logo-img {
            max-width: 80px;
            max-height: 80px;
            object-fit: contain;
        }

        .header-text {
            text-align: center;
            line-height: 1.25;
        }

        .header-text .republic {
            font-size: 10pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #333333;
        }

        .header-text .city {
            font-size: 10.5pt;
            font-weight: 600;
            text-transform: uppercase;
            color: #111111;
        }

        .header-text .barangay {
            font-size: 15pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #0b3b24;
            letter-spacing: 1px;
            margin: 2px 0;
        }

        .header-text .office {
            font-size: 11pt;
            font-weight: bold;
            font-style: italic;
            color: #1e293b;
            text-transform: uppercase;
        }

        .header-divider {
            height: 2px;
            background: #0b3b24;
            margin-top: 10px;
            margin-bottom: 22px;
        }

        /* Document Title */
        .title-block {
            text-align: center;
            margin-bottom: 24px;
        }

        .main-title {
            font-size: 18pt;
            font-weight: 800;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #0f172a;
            border-bottom: 1.5px solid #0f172a;
            display: inline-block;
            padding-bottom: 2px;
        }

        .subtitle {
            font-size: 9.5pt;
            font-style: italic;
            color: #475569;
            margin-top: 4px;
        }

        /* Parties Info */
        .parties-box {
            margin-bottom: 20px;
            font-size: 11pt;
            line-height: 1.4;
        }

        .party-row {
            margin-bottom: 10px;
        }

        .party-label {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10pt;
            color: #334155;
            display: inline-block;
            width: 90px;
        }

        .party-name {
            font-size: 12pt;
            font-weight: bold;
            color: #0f172a;
            text-decoration: underline;
        }

        .party-address {
            margin-left: 90px;
            font-size: 10pt;
            color: #475569;
            font-style: italic;
        }

        /* Letter Body */
        .letter-content {
            font-size: 11.5pt;
            text-align: justify;
            line-height: 1.7;
            margin-bottom: 24px;
        }

        .greeting {
            margin-bottom: 14px;
            font-weight: 600;
        }

        .paragraph {
            text-indent: 0.5in;
            margin-bottom: 14px;
        }

        .highlight-phrase {
            font-weight: bold;
            color: #0f172a;
            text-decoration: underline;
        }

        .salaysay-card {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #0b3b24;
            padding: 12px 18px;
            margin: 16px 0;
            font-style: italic;
            font-size: 11pt;
            line-height: 1.5;
            border-radius: 4px;
        }

        .salaysay-label {
            font-weight: bold;
            font-style: normal;
            font-size: 9.5pt;
            text-transform: uppercase;
            color: #0b3b24;
            margin-bottom: 4px;
        }

        .closing-date {
            margin-top: 24px;
            text-indent: 0.5in;
        }

        /* Signatures Section */
        .signatures-container {
            margin-top: 40px;
            display: flex;
            justify-content: flex-end;
        }

        .sig-block {
            width: 280px;
            text-align: center;
        }

        .sig-image-wrap {
            height: 65px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            margin-bottom: -15px;
        }

        .sig-image {
            max-height: 70px;
            object-fit: contain;
            pointer-events: none;
        }

        .sig-blank-line {
            width: 220px;
            margin: 0 auto 8px auto;
            border-bottom: 1.5px solid #000000;
            height: 50px;
        }

        .captain-name {
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #0f172a;
            letter-spacing: 0.5px;
        }

        .captain-title {
            font-size: 10pt;
            color: #334155;
            font-style: italic;
        }

        .doc-footer {
            margin-top: 30px;
            border-top: 1px dashed #cbd5e1;
            padding-top: 10px;
            font-size: 8.5pt;
            color: #64748b;
            text-align: center;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .sheet {
                box-shadow: none;
                margin: 0;
                padding: 0.5in 0.6in;
                width: 100%;
                min-height: auto;
            }
        }
    </style>
</head>
<body>
    <div class="sheet" id="paanyayaSheet">
        <div>
            <!-- Header with Official Tumana & Bagong Pilipinas Seals -->
            <table class="header-table">
                <tr>
                    <td class="logo-col">
                        <?php if ($tumanaLogoUri): ?>
                            <img src="<?= $tumanaLogoUri ?>" alt="Barangay Tumana Seal" class="logo-img">
                        <?php endif; ?>
                    </td>
                    <td class="header-text">
                        <div class="republic">Republika ng Pilipinas</div>
                        <div class="city">Lungsod ng Marikina</div>
                        <div class="barangay">BARANGAY TUMANA</div>
                        <div class="office">Tanggapan ng Punong Barangay</div>
                    </td>
                    <td class="logo-col">
                        <?php if ($bagongLogoUri): ?>
                            <img src="<?= $bagongLogoUri ?>" alt="Bagong Pilipinas" class="logo-img">
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <div class="header-divider"></div>

            <!-- Document Title -->
            <div class="title-block">
                <h1 class="main-title" id="viewDocTitle"><?= htmlspecialchars($docTitle) ?></h1>
                <div class="subtitle" id="viewDocSubtitle"><?= htmlspecialchars($docSubtitle) ?></div>
            </div>

            <!-- Parties In Attendance -->
            <div class="parties-box">
                <div class="party-row">
                    <span class="party-label">KAY (Para kay):</span>
                    <span class="party-name" id="viewRespondentName"><?= htmlspecialchars($respondentName) ?></span>
                    <div class="party-address">Inanyayahan &bull; Barangay Tumana, Lungsod ng Marikina</div>
                </div>
                <div class="party-row">
                    <span class="party-label">MULA KAY:</span>
                    <span class="party-name" id="viewComplainantName"><?= htmlspecialchars($complainantName) ?></span>
                    <div class="party-address">Nagpaanyaya &bull; Barangay Tumana, Lungsod ng Marikina</div>
                </div>
            </div>

            <!-- Main Invitation Message -->
            <div class="letter-content">
                <p class="greeting">Ginoo / Ginang / Binibini:</p>

                <p class="paragraph">
                    Kayo po ay magalang at taos-pusong inaanyayahan na magtungo sa aming
                    <strong class="highlight-phrase" id="viewTanggapan"><?= htmlspecialchars($tanggapan) ?></strong>
                    sa darating na ika-<strong class="highlight-phrase" id="viewPetsa"><?= htmlspecialchars((string)$petsa) ?></strong>
                    ng <strong class="highlight-phrase" id="viewBuwan"><?= htmlspecialchars((string)$buwan) ?></strong>,
                    taong <strong class="highlight-phrase" id="viewTaon"><?= htmlspecialchars((string)$taon) ?></strong>,
                    sa araw ng <strong class="highlight-phrase" id="viewAraw"><?= htmlspecialchars((string)$araw) ?></strong>,
                    sa ganap na ika-<strong class="highlight-phrase" id="viewOras"><?= htmlspecialchars((string)$oras) ?></strong>,
                    upang magkaroon ng maayos, mapayapa, at pribadong pag-uusap patungkol sa idinulog na salaysay:
                </p>

                <!-- Salaysay Box -->
                <div class="salaysay-card">
                    <div class="salaysay-label">Salaysay / Paksa ng Usapin:</div>
                    <div id="viewSalaysay">"<?= nl2br(htmlspecialchars($salaysay)) ?>"</div>
                </div>

                <p class="paragraph">
                    Ang paanyayang ito ay isinasagawa bilang paunang pagkakataon para sa maayos na pagkakaunawaan at kapayapaan bago ang anumang pormal na hakbang alinsunod sa umiiral na patakaran ng Katarungang Pambarangay. Inaasahan po ang inyong maagap at positibong pagtugon.
                </p>

                <p class="closing-date">
                    Iginawad ngayong ika-<?= date('j') ?> ng <?= (new PaanyayaModel())->filipinoMonth((int)date('n')) ?>, <?= date('Y') ?> dito sa Barangay Tumana, Lungsod ng Marikina.
                </p>
            </div>
        </div>

        <div>
            <!-- Punong Barangay Signature Block -->
            <div class="signatures-container">
                <div class="sig-block">
                    <div id="sigToggleArea">
                        <?php if ($hasESignature && $signatureUri): ?>
                            <div class="sig-image-wrap">
                                <img src="<?= $signatureUri ?>" alt="Hon. Akiko Lynne Centeno Signature" class="sig-image">
                            </div>
                        <?php else: ?>
                            <div class="sig-blank-line"></div>
                        <?php endif; ?>
                    </div>
                    <div class="captain-name">HON. AKIKO LYNNE CENTENO</div>
                    <div class="captain-title">Punong Barangay</div>
                </div>
            </div>

            <!-- Footer Meta -->
            <div class="doc-footer">
                Paanyaya Form &bull; Pre-Docketed Stage 0 Informal Dialogue &bull; Barangay Tumana, Marikina City
            </div>
        </div>
    </div>

    <!-- Live PostMessage Listener for Instant Split-Screen Updating -->
    <script>
        window.addEventListener('message', function(event) {
            if (!event.data || typeof event.data !== 'object') return;
            const d = event.data;

            if (d.type === 'UPDATE_PAANYAYA_PREVIEW') {
                if (d.complainant_name !== undefined) {
                    document.getElementById('viewComplainantName').textContent = d.complainant_name || 'NAGPAANYAYA';
                }
                if (d.respondent_name !== undefined) {
                    document.getElementById('viewRespondentName').textContent = d.respondent_name || 'INANYAYAHAN';
                }
                if (d.tanggapan !== undefined) {
                    document.getElementById('viewTanggapan').textContent = d.tanggapan || 'Barangay Tumana';
                }
                if (d.petsa !== undefined) {
                    document.getElementById('viewPetsa').textContent = d.petsa || '';
                }
                if (d.buwan !== undefined) {
                    document.getElementById('viewBuwan').textContent = d.buwan || '';
                }
                if (d.taon !== undefined) {
                    document.getElementById('viewTaon').textContent = d.taon || '';
                }
                if (d.araw !== undefined) {
                    document.getElementById('viewAraw').textContent = d.araw || '';
                }
                if (d.oras !== undefined) {
                    document.getElementById('viewOras').textContent = d.oras || '';
                }
                if (d.salaysay !== undefined) {
                    document.getElementById('viewSalaysay').textContent = '"' + (d.salaysay || '') + '"';
                }
                if (d.stage_type !== undefined) {
                    const isP2 = d.stage_type === 'Paanyaya 2';
                    document.getElementById('viewDocTitle').textContent = isP2 ? 'IKALAWANG PAANYAYA' : 'PAANYAYA';
                    document.getElementById('viewDocSubtitle').textContent = isP2
                        ? 'Ikalawang Paunang Imbitasyon para sa Pag-uusap'
                        : 'Paunang Imbitasyon para sa Mapayapang Pag-uusap';
                }
                if (d.has_e_signature !== undefined) {
                    const sigArea = document.getElementById('sigToggleArea');
                    const sigUri = <?= json_encode($signatureUri) ?>;
                    if (d.has_e_signature && sigUri) {
                        sigArea.innerHTML = `<div class="sig-image-wrap"><img src="${sigUri}" alt="Signature" class="sig-image"></div>`;
                    } else {
                        sigArea.innerHTML = `<div class="sig-blank-line"></div>`;
                    }
                }
            }
        });
    </script>
</body>
</html>
