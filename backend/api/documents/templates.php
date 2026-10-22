<?php
/**
 * AGAP System — Katarungang Pambarangay Document Templates API
 * Manages official KP Form templates (1 to 25), file uploads, previews, and printing.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$roleId = (int) $_SESSION['role_id'];
$userId = (int) $_SESSION['user_id'];
$canUpload = in_array($roleId, [1, 2], true);

$storageDir = dirname(__DIR__, 2) . '/storage/uploads/templates';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0777, true);
}
$registryFile = $storageDir . '/templates_registry.json';

// Master List of 30 Katarungang Pambarangay & Operational Forms
$masterForms = [
    'patawag' => [
        'code' => 'PATAWAG',
        'title' => 'PATAWAG (Blangkong Paanyaya / Pre-docketing Form)',
        'subtitle' => 'Paanyaya sa Tanggapan ng Punong Barangay Bago ang Pormal na Usapin',
        'category' => 'Pre-Docketing & Case Intake',
        'description' => 'Opisyal na blangkong paanyaya o paunang patawag sa nagrereklamo at inirereklamo para sa maayos na pag-uusap bago pormal na maipasok sa docket ng Lupon.'
    ],
    'case_documentation' => [
        'code' => 'CASE DOCUMENTATION',
        'title' => 'CASE DOCUMENTATION (Blangkong Intake & Case Profile Jacket)',
        'subtitle' => 'Talaan ng Usapin / Case Profile & Intake Documentation Jacket',
        'category' => 'Pre-Docketing & Case Intake',
        'description' => 'Opisyal na blangkong pabalat at talaan ng usapin (Case Jacket) na naglalaman ng buod ng partido, kategorya, milestone checklist, at imbentaryo ng mga dokumento.'
    ],
    'minutes_form' => [
        'code' => 'MINUTES FORM',
        'title' => 'MINUTES FORM (Blangkong Katitikan ng Pagdinig)',
        'subtitle' => 'Blangkong Katitikan ng Pagdinig at Pag-uusap sa Lupon / Pangkat',
        'category' => 'Hearings & Compliance',
        'description' => 'Opisyal na blangkong pormularyo para sa pagtatala ng mga pahayag, attendance, napagkasunduan, at naging kaganapan sa bawat pagdinig o sesyon.'
    ],
    'kp_1' => [
        'code' => 'KP Form No. 1',
        'title' => 'Notice to Constitute the Lupon',
        'subtitle' => 'Paunawa ukol sa Pagbubuo ng Lupon',
        'category' => 'Lupon Organization',
        'description' => 'Official notice posted by the Punong Barangay to announce appointment of Lupon members.'
    ],
    'kp_2' => [
        'code' => 'KP Form No. 2',
        'title' => 'Appointment',
        'subtitle' => 'Pagtatalaga ng Kasapi ng Lupon',
        'category' => 'Lupon Organization',
        'description' => 'Official appointment letter issued to a designated Lupong Tagapamayapa member.'
    ],
    'kp_3' => [
        'code' => 'KP Form No. 3',
        'title' => 'Notice of Appointment',
        'subtitle' => 'Paunawa ng Pagtatalaga',
        'category' => 'Lupon Organization',
        'description' => 'Formal notice served to the appointee regarding their selection to the Lupon.'
    ],
    'kp_4' => [
        'code' => 'KP Form No. 4',
        'title' => 'List of Appointed Lupon Members',
        'subtitle' => 'Talaan ng mga Hinirang na Kasapi ng Lupon',
        'category' => 'Lupon Organization',
        'description' => 'Official roster of constituted Lupong Tagapamayapa members submitted to DILG and court.'
    ],
    'kp_5' => [
        'code' => 'KP Form No. 5',
        'title' => 'Oath of Office',
        'subtitle' => 'Panunumpa sa Katungkulan',
        'category' => 'Lupon Organization',
        'description' => 'Solemn oath taken by appointed Lupon members administered by the Punong Barangay.'
    ],
    'kp_6' => [
        'code' => 'KP Form No. 6',
        'title' => 'Withdrawal of Appointment',
        'subtitle' => 'Pagbawi ng Pagtatalaga',
        'category' => 'Lupon Organization',
        'description' => 'Formal revocation of Lupon membership due to disqualification, transfer, or cause.'
    ],
    'kp_7' => [
        'code' => 'Pormularyo ng KP Blg. 7',
        'title' => 'REKLAMO (Complaint)',
        'subtitle' => 'Pormal na Reklamo / Complaint Form',
        'category' => 'Mediation & Summons',
        'description' => 'Opisyal na pormularyo ng reklamo mula sa Tanggapan ng Punong Barangay / Lupong Tagapamayapa.'
    ],
    'kp_8' => [
        'code' => 'Pormularyo ng KP Blg. 8',
        'title' => 'PAABISO NG PAGDINIG (Mediation Proceedings)',
        'subtitle' => 'Paabiso ng Pagdinig sa May-sumbong ukol sa Pamamagitan',
        'category' => 'Mediation & Summons',
        'description' => 'Opisyal na paabiso ng pagdinig mula sa Tanggapan ng Punong Barangay / Lupong Tagapamayapa para sa (mga) nagrereklamo para sa pagdinig ng reklamo.'
    ],
    'kp_9' => [
        'code' => 'Pormularyo ng KP Blg. 9',
        'title' => 'PATAWAG (Summons)',
        'subtitle' => 'Patawag sa Inirereklamo ukol sa Pamamagitan',
        'category' => 'Mediation & Summons',
        'description' => 'Opisyal na patawag mula sa Punong Barangay para sa inirereklamo upang personal na humarap kasama ang mga testigo.'
    ],
    'kp_10' => [
        'code' => 'Pormularyo ng KP Blg. 10',
        'title' => 'PAABISO SA PAGBUBUO NG PANGKAT (Notice for Constitution of Pangkat)',
        'subtitle' => 'Paabiso sa Pagbubuo ng Pangkat ng Tagapagkasundo',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Opisyal na paabiso mula sa Punong Barangay para sa magkabilang panig upang humarap at bumuo ng 3 kasapi ng Pangkat.'
    ],
    'kp_11' => [
        'code' => 'Pormularyo ng KP Blg. 11',
        'title' => 'PAABISO SA PINILING KASAPI NG PANGKAT (Notice to Chosen Pangkat Member)',
        'subtitle' => 'Paabiso sa Napiling Kasapi ng Pangkat Tagapagkasundo',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Opisyal na paabiso sa napiling kasapi ng Lupon na bumubuo sa Pangkat Tagapagkasundo.'
    ],
    'kp_12' => [
        'code' => 'Pormularyo ng KP Blg. 12',
        'title' => 'PAABISO NG PAGDINIG (Conciliation Proceedings)',
        'subtitle' => 'Paabiso ng Pagdinig sa Harap ng Pangkat Tagapagkasundo',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Opisyal na paabiso ng pagdinig mula sa Tagapangulo ng Pangkat para sa conciliation proceedings.'
    ],
    'kp_13' => [
        'code' => 'Pormularyo ng KP Blg. 13',
        'title' => 'UTOS UPANG HUMARAP (Subpoena)',
        'subtitle' => 'Subpoena / Utos sa Pagsaksi o Pagharap ng mga Testigo',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Opisyal na utos upang humarap at tumestigo sa pagdinig ng usapin sa harap ng Punong Barangay o Pangkat.'
    ],
    'kp_14' => [
        'code' => 'Pormularyo ng KP Blg. 14',
        'title' => 'KASUNDUAN PARA SA PAGHAHATOL (Agreement for Arbitration)',
        'subtitle' => 'Kasunduan sa Paghahatol / Agreement for Arbitration',
        'category' => 'Arbitration & Settlement',
        'description' => 'Opisyal na kasunduan ng magkabilang panig na idulog ang sigalot sa paghahatol ng Punong Barangay o Pangkat.'
    ],
    'kp_15' => [
        'code' => 'Pormularyo ng KP Blg. 15',
        'title' => 'GAWAD NG PAGHAHATOL (Arbitration Award)',
        'subtitle' => 'Gawad ng Paghahatol / Arbitration Award',
        'category' => 'Arbitration & Settlement',
        'description' => 'Opisyal na hatol at gawad ng paghahatol matapos marinig ang mga salaysay at magsuri ng ebidensya sa arbitrasyon.'
    ],
    'kp_16' => [
        'code' => 'Pormularyo ng KP Blg. 16',
        'title' => 'MATIWASAY NA PAG-AAYOS (Amicable Settlement)',
        'subtitle' => 'Matiwasay na Pag-aayos / Amicable Settlement',
        'category' => 'Arbitration & Settlement',
        'description' => 'Opisyal na kasunduan ng mga nagrereklamo at inirereklamo na maayos na tinatapos ang alitan alinsunod sa batas.'
    ],
    'kp_17' => [
        'code' => 'Pormularyo ng KP Blg. 17',
        'title' => 'PAGTANGGI (Repudiation)',
        'subtitle' => 'Pagtanggi sa Kasunduan o Paghahatol / Repudiation',
        'category' => 'Arbitration & Settlement',
        'description' => 'Sinumpaang pagtanggi sa kasunduan ng pag-aayos o paghahatol dahil sa pandaraya, karahasan, o pananakot.'
    ],
    'kp_18' => [
        'code' => 'Pormularyo ng KP Blg. 18',
        'title' => 'PAABISO NG PAGDINIG (Re: Failure to Appear)',
        'subtitle' => 'Paabiso ng Pagdinig ukol sa Kabiguang Humarap ng Nagrereklamo',
        'category' => 'Hearings & Compliance',
        'description' => 'Opisyal na paabiso sa nagrereklamo upang magpaliwanag sa kabiguang humarap sa itinakdang pagdinig.'
    ],
    'kp_19' => [
        'code' => 'Pormularyo ng KP Blg. 19',
        'title' => 'PAABISO NG PAGDINIG (Re: Failure to Appear)',
        'subtitle' => 'Paabiso ng Pagdinig ukol sa Kabiguang Humarap ng Inirereklamo',
        'category' => 'Hearings & Compliance',
        'description' => 'Opisyal na paabiso sa inirereklamo upang magpaliwanag sa kabiguang humarap sa itinakdang pagdinig ukol sa pamamagitan o pagkakasundo.'
    ],
    'kp_20' => [
        'code' => 'Pormularyo ng KP Blg. 20',
        'title' => 'KATIBAYAN NA MAKAPAGHAIN NG SAKDAL (Certification to File Action)',
        'subtitle' => 'Katibayan Dulot ng Pagtanggi sa Kasunduan (Repudiated Settlement)',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan na makapaghain ng sakdal sa hukuman o tanggapan ng pamahalaan matapos tanggihan ang kasunduan sa pamamagitan ng sinumpaang salaysay.'
    ],
    'kp_20_a' => [
        'code' => 'Pormularyo ng KP Blg. 20-A',
        'title' => 'KATIBAYAN NA MAKAPAGHAIN NG SAKDAL (Certification to File Action)',
        'subtitle' => 'Katibayan Matapos Mabigo ang Pamamagitan at Paghaharap sa Pangkat',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan na makapaghain ng sakdal sa hukuman matapos mabigo ang pamamagitan sa Punong Barangay at di nagbunga ng pag-aayos sa harap ng Pangkat.'
    ],
    'kp_20_b' => [
        'code' => 'Pormularyo ng KP Blg. 20-B',
        'title' => 'KATIBAYAN NA MAKAPAGHAIN NG SAKDAL (Certification to File Action)',
        'subtitle' => 'Katibayan Dulot ng Kabiguang Humarap ng Inirereklamo sa Pangkat',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan na makapaghain ng sakdal sa hukuman matapos sinadyang tumanggi ng inirereklamo na humarap sa Pangkat.'
    ],
    'kp_21' => [
        'code' => 'Pormularyo ng KP Blg. 21',
        'title' => 'KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL (Certification to Bar Action)',
        'subtitle' => 'Katunayan sa Paghadlang sa Pagsasampa ng Usapin ng Nagrereklamo',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan sa paghadlang sa nagrereklamo na makapagsampa ng sakdal sa hukuman dahil sa sinadyang di-pagharap sa pagdinig.'
    ],
    'kp_22' => [
        'code' => 'Pormularyo ng KP Blg. 22',
        'title' => 'KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL (Certification to Bar Counterclaim)',
        'subtitle' => 'Katunayan sa Paghadlang sa Ganting-Habla ng Inirereklamo',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan sa paghadlang sa inirereklamo na maghain ng ganting-sumbong dahil sa sinadyang pagtangging humarap sa pagdinig.'
    ],
    'kp_23' => [
        'code' => 'Pormularyo ng KP Blg. 23',
        'title' => 'MOSYON SA PAGPAPATUPAD (Motion for Execution)',
        'subtitle' => 'Kahilingan sa Pagpapatupad ng Kasunduan o Gawad',
        'category' => 'Execution of Settlement',
        'description' => 'Opisyal na mosyon ng nagrereklamo o inirereklamo para sa pagpapatupad ng pinal na kasunduan o paghahatol matapos lumipas ang 10 araw na taning.'
    ],
    'kp_24' => [
        'code' => 'KP Form No. 24',
        'title' => 'Notice of Hearing (re: Motion for Execution)',
        'subtitle' => 'Paunawa ng Pagdinig ukol sa Kahilingan sa Pagpapatupad',
        'category' => 'Execution of Settlement',
        'description' => 'Notice of hearing to resolve party motion for execution of settlement.'
    ],
    'kp_25' => [
        'code' => 'Pormularyo ng KP Blg. 25',
        'title' => 'PAABISO PARA SA PAGPAPATUPAD (Notice for Execution)',
        'subtitle' => 'Kautusan sa Pagpapatupad ng Kasunduan o Hatol',
        'category' => 'Execution of Settlement',
        'description' => 'Opisyal na paabiso o utos ng pagpapatupad mula sa Punong Barangay para sa pagsasakatuparan ng kasunduan o gawad mula sa mga kalakal at ari-ariang personal ng natalong panig.'
    ],
];

// Helper to load registry
function loadRegistry($file) {
    if (file_exists($file)) {
        $json = @file_get_contents($file);
        $data = json_decode($json, true);
        if (is_array($data)) return $data;
    }
    return [];
}

// Helper to save registry
function saveRegistry($file, $data) {
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$registry = loadRegistry($registryFile);

// Handle Actions
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

// 1. PREVIEW TEMPLATE (HTML or Download or View PDF)
if ($action === 'preview') {
    $formKey = trim((string)($_GET['form_key'] ?? ''));
    if (!isset($masterForms[$formKey])) {
        http_response_code(404);
        echo 'Form not found.';
        exit;
    }

    $formMeta = $masterForms[$formKey];
    $customUpload = $registry[$formKey] ?? null;

    // If custom upload exists and is a PDF, serve it directly
    if ($customUpload && !empty($customUpload['file_path'])) {
        $fullPath = dirname(__DIR__, 3) . '/' . $customUpload['file_path'];
        if (file_exists($fullPath)) {
            $mime = mime_content_type($fullPath) ?: 'application/pdf';
            if (strpos($mime, 'pdf') !== false || strpos($mime, 'image') !== false) {
                header('Content-Type: ' . $mime);
                header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
                readfile($fullPath);
                exit;
            } else {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
                readfile($fullPath);
                exit;
            }
        }
    }

    // Otherwise render high-fidelity printable HTML template placeholder
    $settings = [];
    $isDbUp = false;
    $fp = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 0.2);
    if ($fp) {
        fclose($fp);
        $isDbUp = true;
    }
    if ($isDbUp) {
        try {
            require_once dirname(__DIR__, 2) . '/config/Database.php';
            require_once dirname(__DIR__, 2) . '/models/Setting.php';
            $settingModel = new Setting();
            $settings = $settingModel->getAllSettings();
        } catch (\Throwable $e) {
            $settings = [];
        }
    }

    $headerTitle = $settings['kp_header_title'] ?? 'REPUBLIKA NG PILIPINAS';
    $lguHeader = $settings['kp_lgu_header'] ?? 'LALAWIGAN NG RIZAL / LUNGSOD NG MARIKINA / BARANGAY TUMANA';
    $paperSize = $settings['document_paper_size'] ?? 'Legal';
    $marginTop = (int)($settings['document_margin_top'] ?? 15);
    $marginBottom = (int)($settings['document_margin_bottom'] ?? 15);
    $marginLeft = (int)($settings['document_margin_left'] ?? 18);
    $marginRight = (int)($settings['document_margin_right'] ?? 18);

    $tumanaPath = dirname(__DIR__, 2) . '/../frontend/assets/images/tumana_logo.jpg';
    $marikinaPath = dirname(__DIR__, 2) . '/../frontend/assets/images/marikina_logo.jpg';
    $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
    $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="fil">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($formMeta['code'] . ' - ' . $formMeta['title']); ?> | Template Preview</title>
        <style>
            @page {
                size: <?php echo ($paperSize === 'Legal') ? '8.5in 14in' : 'A4'; ?>;
                margin: <?php echo "{$marginTop}mm {$marginRight}mm {$marginBottom}mm {$marginLeft}mm"; ?>;
            }
            body {
                font-family: "Times New Roman", Times, serif;
                color: #111827;
                background-color: #f3f4f6;
                margin: 0;
                padding: 24px;
            }
            .page-container {
                max-width: 820px;
                margin: 0 auto;
                background: #ffffff;
                padding: 40px 50px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                border-radius: 4px;
                box-sizing: border-box;
                position: relative;
            }
            .print-bar {
                max-width: 820px;
                margin: 0 auto 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 18px;
                background: #1e293b;
                color: #ffffff;
                border-radius: 8px;
            }
            .print-bar button {
                background: #16a34a;
                color: #ffffff;
                border: none;
                padding: 8px 16px;
                border-radius: 6px;
                font-weight: 700;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .print-bar button:hover { background: #15803d; }
            .header {
                text-align: center;
                line-height: 1.35;
                font-size: 13pt;
                border-bottom: 2px solid #000;
                padding-bottom: 12px;
                margin-bottom: 20px;
            }
            .header .republic { font-weight: bold; letter-spacing: 0.5px; }
            .header .lgu { font-weight: 500; }
            .header .office { font-weight: bold; font-style: italic; margin-top: 6px; }
            .form-code {
                text-align: right;
                font-size: 11pt;
                font-weight: bold;
                margin-bottom: 8px;
            }
            .form-title {
                text-align: center;
                font-size: 16pt;
                font-weight: bold;
                text-transform: uppercase;
                margin: 18px 0 6px;
            }
            .form-subtitle {
                text-align: center;
                font-size: 12pt;
                font-style: italic;
                margin-bottom: 25px;
            }
            .case-meta {
                display: flex;
                justify-content: space-between;
                font-size: 11pt;
                margin-bottom: 25px;
                line-height: 1.6;
            }
            .parties-box {
                border-bottom: 1px dashed #6b7280;
                padding-bottom: 12px;
                margin-bottom: 20px;
            }
            .body-text {
                font-size: 12pt;
                line-height: 1.8;
                text-align: justify;
                text-indent: 2.5em;
                margin-bottom: 25px;
            }
            .placeholder-field {
                display: inline-block;
                border-bottom: 1px solid #111827;
                min-width: 180px;
                color: #6b7280;
                font-style: italic;
                text-indent: 0;
                text-align: center;
            }
            .signatures {
                margin-top: 45px;
                display: flex;
                justify-content: space-between;
                font-size: 11pt;
            }
            .sig-block {
                width: 45%;
                text-align: center;
            }
            .sig-line {
                border-top: 1px solid #000;
                margin-top: 50px;
                padding-top: 4px;
                font-weight: bold;
            }
            .sig-title {
                font-size: 10pt;
                font-style: italic;
                color: #374151;
            }
            .table-grid {
                width: 100%;
                border-collapse: collapse;
                margin: 12px 0;
                font-size: 10pt;
            }
            .table-grid th, .table-grid td {
                border: 1px solid #111827;
                padding: 6px 8px;
                vertical-align: top;
            }
            .table-grid th {
                background-color: #f1f5f9;
                font-weight: bold;
                text-align: center;
            }
            .ruled-line {
                border-bottom: 1px solid #475569;
                min-height: 22px;
                margin-bottom: 5px;
                line-height: 22px;
                font-size: 10.5pt;
            }
            .box-bordered {
                border: 1.5px solid #111827;
                border-radius: 4px;
                padding: 10px 14px;
                margin: 12px 0;
                font-size: 10.5pt;
            }
            .box-title {
                font-weight: bold;
                font-size: 10.5pt;
                text-transform: uppercase;
                margin-bottom: 6px;
                border-bottom: 1px solid #94a3b8;
                padding-bottom: 4px;
            }
            .cb-box {
                display: inline-block;
                width: 12px;
                height: 12px;
                border: 1.5px solid #000;
                vertical-align: middle;
                margin-right: 4px;
            }
            .section-tag {
                font-weight: bold;
                font-size: 10.5pt;
                margin: 14px 0 6px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #0f172a;
                border-bottom: 1px solid #cbd5e1;
                padding-bottom: 3px;
            }
            .watermark-note {
                margin-top: 35px;
                padding: 10px;
                background: #fef3c7;
                border: 1px solid #f59e0b;
                border-radius: 6px;
                font-family: sans-serif;
                font-size: 9.5pt;
                color: #92400e;
                text-align: center;
            }
            @media print {
                body { background: none; padding: 0; }
                .print-bar, .watermark-note { display: none !important; }
                .page-container { box-shadow: none; padding: 0; border: none; }
            }
        </style>
    </head>
    <body>
        <div class="print-bar">
            <div>
                <strong><?php echo htmlspecialchars($formMeta['code']); ?>: <?php echo htmlspecialchars($formMeta['title']); ?></strong>
                <div style="font-size: 0.8rem; opacity: 0.85;">Official Template Preview</div>
            </div>
            <button type="button" onclick="window.print()">
                <span>🖨️</span> Print Template
            </button>
        </div>

        <div class="page-container">
            <?php if ($formKey === 'patawag'): ?>
                <div class="header">
                    <div class="republic"><?php echo htmlspecialchars($headerTitle); ?></div>
                    <div class="lgu"><?php echo htmlspecialchars($lguHeader); ?></div>
                    <div class="office">TANGGAPAN NG PUNONG BARANGAY / LUPONG TAGAPAMAYAPA</div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; font-size: 11pt;">
                    <div>
                        <strong>Tracking / Paanyaya Blg.:</strong> <span class="placeholder-field" style="min-width: 170px;">P-____-____-20____</span>
                    </div>
                    <div>
                        <strong>Petsa:</strong> <span class="placeholder-field" style="min-width: 140px;">________________________</span>
                    </div>
                </div>

                <div class="form-code">PATAWAG / PAANYAYA</div>
                <div class="form-title">PAANYAYA SA PAG-UUSAP</div>
                <div class="form-subtitle">(Blangkong Paanyaya / Pre-Docketing Form Bago ang Pormal na Usapin)</div>

                <div class="parties-box" style="line-height: 1.7; font-size: 11pt;">
                    <div>
                        <strong>KAY (Ipinagsusumbong / Inirereklamo):</strong>
                        <span class="placeholder-field" style="width: 58%; display: inline-block;">____________________________________________________</span>
                    </div>
                    <div style="margin-top: 4px;">
                        <strong>Tirahan:</strong>
                        <span class="placeholder-field" style="width: 85%; display: inline-block;">____________________________________________________________________</span>
                    </div>
                    <div style="margin-top: 10px;">
                        <strong>MULA KAY (May-sumbong / Nagreklamo):</strong>
                        <span class="placeholder-field" style="width: 58%; display: inline-block;">____________________________________________________</span>
                    </div>
                    <div style="margin-top: 4px;">
                        <strong>Tirahan:</strong>
                        <span class="placeholder-field" style="width: 85%; display: inline-block;">____________________________________________________________________</span>
                    </div>
                </div>

                <div class="body-text" style="text-indent: 0; line-height: 1.75; margin-top: 18px;">
                    <strong>G./Gng./Bb.:</strong><br><br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Kayo ay magalang na <strong>INAANYAYAHAN AT TINATAWAGAN</strong> na magtungo sa <strong>Tanggapan ng Barangay Tumana (Lupon Office)</strong>, Lungsod ng Marikina, sa araw, petsa, at oras na nakasaad sa ibaba:
                </div>

                <div class="box-bordered" style="background: #f8fafc; font-size: 11pt; line-height: 1.8;">
                    <div><strong>Araw at Petsa ng Pag-uusap:</strong> <span class="placeholder-field" style="min-width: 220px;">____________________________________</span></div>
                    <div style="margin-top: 6px;"><strong>Oras:</strong> <span class="placeholder-field" style="min-width: 140px;">_______________</span> <strong>[ &nbsp; ] ng umaga &nbsp;&nbsp;&nbsp; [ &nbsp; ] ng hapon</strong></div>
                    <div style="margin-top: 6px;"><strong>Lugar:</strong> Tanggapan ng Lupong Tagapamayapa, Barangay Hall ng Tumana, Marikina City</div>
                    <div style="margin-top: 6px;">
                        <strong>Hinggil sa Sumbong / Di-pagkakaunawaan ukol sa:</strong>
                        <div class="ruled-line" style="margin-top: 6px;"></div>
                        <div class="ruled-line"></div>
                        <div class="ruled-line"></div>
                    </div>
                </div>

                <div class="body-text" style="font-size: 10pt; line-height: 1.5; color: #334155; margin-top: 14px; text-indent: 0;">
                    <strong>MAHALAGANG PAALALA:</strong> Ang paanyayang ito ay isang <strong>pre-docketing dialogue / paunang pag-uusap</strong> sa ilalim ng pamunuan ng Barangay Tumana. Layunin nito na bigyan ng pagkakataon ang magkabilang panig na magkausap nang mahinahon at magkasundo nang maayos bago pa man pormal na isampa ang usapin sa Lupon Tagapamayapa. Ang inyong maagap na pagdalo sa itinakdang oras ay lubos na inaasahan upang maiwasan ang pormal na pagsasampa ng demanda.
                </div>

                <div class="signatures" style="margin-top: 25px;">
                    <div class="sig-block">
                        <div class="sig-line">Tagapamayapa / Officer of the Day</div>
                        <div class="sig-title">Nagtala / Nag-asikaso</div>
                    </div>
                    <div class="sig-block">
                        <div class="sig-line">Punong Barangay / Lupon Chairman</div>
                        <div class="sig-title">Tagapangulo ng Lupon</div>
                    </div>
                </div>

                <!-- OFFICER'S RETURN OF SERVICE -->
                <div class="box-bordered" style="margin-top: 25px; font-size: 9.5pt; line-height: 1.5; border: 1.5px solid #000; background: #fafafa;">
                    <div class="box-title" style="font-size: 10pt; text-align: center;">PATUNAY NG PAGHAHATID (OFFICER'S RETURN)</div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <div><strong>Petsa ng Paghahatid:</strong> <span class="placeholder-field" style="min-width: 120px;">_______________</span></div>
                        <div><strong>Oras:</strong> <span class="placeholder-field" style="min-width: 80px;">_________</span> [ &nbsp; ] AM &nbsp; [ &nbsp; ] PM</div>
                    </div>
                    <div style="margin-bottom: 6px;">
                        <strong>Paraan ng Paghahatid:</strong><br>
                        <span class="cb-box"></span> Personal na inihatid at tinanggap ng inirereklamo<br>
                        <span class="cb-box"></span> Substituted Service (iniwan sa taong may sapat na gulang at pag-iisip sa tahanan)<br>
                        <span class="cb-box"></span> Tumangging tanggapin o lumagda (inilagak sa harapan ng dalawang saksi)
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 10px;">
                        <div style="width: 48%;">
                            <div><strong>Pangalan ng Tumanggap:</strong> ____________________________</div>
                            <div style="margin-top: 4px;"><strong>Lagda ng Tumanggap:</strong> ____________________________</div>
                            <div style="margin-top: 4px;"><strong>Relasyon sa Inirereklamo:</strong> ____________________________</div>
                        </div>
                        <div style="width: 48%; text-align: right;">
                            <div><strong>Naghatid (Tanod / Server):</strong> ____________________________</div>
                            <div style="margin-top: 4px;"><strong>Lagda ng Naghatid:</strong> ____________________________</div>
                            <div style="margin-top: 4px;"><strong>Badge / ID Blg.:</strong> ____________________________</div>
                        </div>
                    </div>
                </div>

            <?php elseif ($formKey === 'case_documentation'): ?>
                <div class="header">
                    <div class="republic"><?php echo htmlspecialchars($headerTitle); ?></div>
                    <div class="lgu"><?php echo htmlspecialchars($lguHeader); ?></div>
                    <div class="office">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
                </div>

                <div class="form-code">CASE JACKET</div>
                <div class="form-title" style="font-size: 15pt;">CASE PROFILE &amp; INTAKE DOCUMENTATION JACKET</div>
                <div class="form-subtitle" style="margin-bottom: 15px;">(Blangkong Intake &amp; Case Profile Jacket / Opisyal na Talaan ng Usapin)</div>

                <!-- Case Meta Header Box -->
                <div class="box-bordered" style="background: #f8fafc; font-size: 10pt; line-height: 1.6; margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <div style="flex: 1; min-width: 200px;">
                            <strong>Usaping Barangay Blg.:</strong> <span class="placeholder-field" style="min-width: 130px;">__________________</span>
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <strong>Tracking / Intake Blg.:</strong> <span class="placeholder-field" style="min-width: 130px;">P-____-____-20____</span>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-top: 6px;">
                        <div style="flex: 1; min-width: 200px;">
                            <strong>Petsa ng Pagkakasampa:</strong> <span class="placeholder-field" style="min-width: 110px;">______________</span>
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <strong>Uri ng Usapin:</strong> &nbsp;
                            <span class="cb-box"></span> Sibil (Civil) &nbsp;&nbsp;&nbsp;&nbsp;
                            <span class="cb-box"></span> Kriminal (Criminal)
                        </div>
                    </div>
                    <div style="margin-top: 6px;">
                        <strong>Kategorya ng Sumbong:</strong> <span class="placeholder-field" style="width: 75%;">____________________________________________________________</span>
                    </div>
                </div>

                <!-- Section 1: Parties Information -->
                <div class="section-tag">I. Mga Partido sa Usapin (Parties Profile)</div>
                <table class="table-grid">
                    <thead>
                        <tr>
                            <th style="width: 22%;">Katayuan (Role)</th>
                            <th style="width: 30%;">Pangalan (Full Name)</th>
                            <th style="width: 32%;">Tirahan / Purok</th>
                            <th style="width: 16%;">Telepono / Edad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>NAGREKLAMO</strong><br><span style="font-size: 8pt; color: #64748b;">(Complainant 1)</span></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>NAGREKLAMO</strong><br><span style="font-size: 8pt; color: #64748b;">(Complainant 2)</span></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>INIREREKLAMO</strong><br><span style="font-size: 8pt; color: #64748b;">(Respondent 1)</span></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>INIREREKLAMO</strong><br><span style="font-size: 8pt; color: #64748b;">(Respondent 2)</span></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Section 2: Narrative & Relief -->
                <div class="section-tag">II. Buod ng Sumbong at Hinihiling na Lunas (Narrative Summary &amp; Relief)</div>
                <div class="box-bordered" style="min-height: 80px;">
                    <div style="font-size: 9pt; color: #475569; margin-bottom: 4px;"><strong>Maikling Salaysay ng Pangyayari (Facts of the Dispute):</strong></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                    <div style="font-size: 9pt; color: #475569; margin: 8px 0 4px;"><strong>Hinihiling na Lunas o Solusyon (Resolution / Relief Requested):</strong></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                </div>

                <!-- Section 3: Procedural Progress Checklist -->
                <div class="section-tag">III. Tsart ng Pag-usad ng Usapin (Procedural Case Timeline)</div>
                <table class="table-grid">
                    <thead>
                        <tr>
                            <th style="width: 28%;">Yugto ng Usapin (Stage)</th>
                            <th style="width: 16%;">Petsa (Date)</th>
                            <th style="width: 40%;">Naging Aksyon / Resulta (Action / Outcome)</th>
                            <th style="width: 16%;">Lagda ng Lupon</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>1. Intake &amp; Screening</strong></td>
                            <td></td>
                            <td>Preliminary screen verified</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>2. 1st Paanyaya Dialogue</strong></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>3. 2nd Paanyaya Dialogue</strong></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>4. Formal Docketing (KP 7)</strong></td>
                            <td></td>
                            <td>Case docketed; summons issued</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>5. 1st Mediation Hearing</strong></td>
                            <td></td>
                            <td>Mediation by Punong Barangay</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>6. 2nd Mediation Hearing</strong></td>
                            <td></td>
                            <td>Final mediation session</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>7. Constitution of Pangkat</strong></td>
                            <td></td>
                            <td>3 Lupon members chosen</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>8. Pangkat Conciliation</strong></td>
                            <td></td>
                            <td>Conciliation proceedings</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>9. Settlement / Award (KP 16)</strong></td>
                            <td></td>
                            <td>Amicable settlement executed</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>10. Certification (KP 20 / CFA)</strong></td>
                            <td></td>
                            <td>CFA issued upon failure</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Section 4: Document Checklist & Archival -->
                <div class="section-tag">IV. Imbentaryo ng Dokumento at Pagpapasara (Checklist &amp; Closeout)</div>
                <div class="box-bordered" style="font-size: 9.5pt; line-height: 1.6;">
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        <div style="flex: 1; min-width: 170px;"><span class="cb-box"></span> KP Form 7 (Complaint)</div>
                        <div style="flex: 1; min-width: 170px;"><span class="cb-box"></span> KP Form 8 (Notice)</div>
                        <div style="flex: 1; min-width: 170px;"><span class="cb-box"></span> KP Form 9 (Summons)</div>
                        <div style="flex: 1; min-width: 170px;"><span class="cb-box"></span> Officer Return of Service</div>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px;">
                        <div style="flex: 1; min-width: 170px;"><span class="cb-box"></span> Katitikan ng Pagdinig (Minutes)</div>
                        <div style="flex: 1; min-width: 170px;"><span class="cb-box"></span> KP Form 10/11 (Pangkat)</div>
                        <div style="flex: 1; min-width: 170px;"><span class="cb-box"></span> KP Form 16 (Kasunduan)</div>
                        <div style="flex: 1; min-width: 170px;"><span class="cb-box"></span> KP Form 20 / 20-A (CFA)</div>
                    </div>
                    <div style="border-top: 1px dashed #cbd5e1; margin-top: 8px; padding-top: 8px; display: flex; justify-content: space-between; align-items: flex-end;">
                        <div>
                            <strong>Huling Katayuan ng Usapin:</strong><br>
                            <span class="cb-box"></span> Naayos sa Pamamagitan &nbsp;&nbsp;
                            <span class="cb-box"></span> Naayos sa Pangkat &nbsp;&nbsp;
                            <span class="cb-box"></span> Ibinigay ang CFA &nbsp;&nbsp;
                            <span class="cb-box"></span> Na-dismiss
                        </div>
                        <div style="text-align: center;">
                            <div class="placeholder-field" style="min-width: 190px;"></div>
                            <div style="font-size: 8.5pt; font-weight: bold; margin-top: 2px;">Kalihim ng Lupon / Records Officer</div>
                        </div>
                    </div>
                </div>

            <?php elseif ($formKey === 'minutes_form'): ?>
                <div class="header">
                    <div class="republic"><?php echo htmlspecialchars($headerTitle); ?></div>
                    <div class="lgu"><?php echo htmlspecialchars($lguHeader); ?></div>
                    <div class="office">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
                </div>

                <div class="form-code">KATITIKAN / MINUTES</div>
                <div class="form-title" style="font-size: 15pt;">KATITIKAN NG PAGDINIG AT PAG-UUSAP</div>
                <div class="form-subtitle" style="margin-bottom: 15px;">(Blangkong Katitikan para sa Pagdinig ng Lupon / Pangkat Tagapagkasundo)</div>

                <!-- Meta Information Box -->
                <div class="box-bordered" style="background: #f8fafc; font-size: 10pt; line-height: 1.6; margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <strong>Usaping Barangay Blg.:</strong> <span class="placeholder-field" style="min-width: 130px;">__________________</span>
                        </div>
                        <div>
                            <strong>Tracking Blg. (kung Paanyaya):</strong> <span class="placeholder-field" style="min-width: 130px;">__________________</span>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-top: 6px;">
                        <div>
                            <strong>Yugto ng Pagdinig:</strong> &nbsp;
                            <span class="cb-box"></span> Paanyaya &nbsp;&nbsp;
                            <span class="cb-box"></span> Pamamagitan (Mediation) &nbsp;&nbsp;
                            <span class="cb-box"></span> Pangkat Conciliation &nbsp;&nbsp;
                            <span class="cb-box"></span> Arbitrasyon
                        </div>
                        <div>
                            <strong>Ika-ilang Sesyon:</strong> &nbsp;
                            <span class="cb-box"></span> Una (1st) &nbsp;&nbsp;
                            <span class="cb-box"></span> Ikalawa (2nd) &nbsp;&nbsp;
                            <span class="cb-box"></span> Ikatlo (3rd)
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-top: 6px;">
                        <div>
                            <strong>Petsa ng Pagdinig:</strong> <span class="placeholder-field" style="min-width: 120px;">__________________</span>
                        </div>
                        <div>
                            <strong>Oras Nagsimula:</strong> <span class="placeholder-field" style="min-width: 60px;">_______</span> &nbsp;
                            <strong>Oras Natapos:</strong> <span class="placeholder-field" style="min-width: 60px;">_______</span>
                        </div>
                        <div>
                            <strong>Lugar:</strong> Lupon Court Room / Brgy. Hall
                        </div>
                    </div>
                </div>

                <!-- Section 1: Attendance Roster -->
                <div class="section-tag">I. Talaan ng mga Dumalo (Attendance Roster)</div>
                <table class="table-grid">
                    <thead>
                        <tr>
                            <th style="width: 28%;">Pangalan (Full Name)</th>
                            <th style="width: 24%;">Katayuan sa Usapin</th>
                            <th style="width: 32%;">Lagda sa Pagdalo (Signature)</th>
                            <th style="width: 16%;">Oras (Time-in)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td></td>
                            <td>May-Sumbong (Complainant)</td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td></td>
                            <td>May-Sumbong (Kasama)</td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td></td>
                            <td>Ipinagsusumbong (Respondent)</td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td></td>
                            <td>Ipinagsusumbong (Kasama)</td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td></td>
                            <td>Tagapamagitan / Mediator</td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td></td>
                            <td>Kalihim ng Lupon / Pangkat</td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Section 2: Summary of Proceedings -->
                <div class="section-tag">II. Mga Mahahalagang Pahayag at Napag-usapan (Proceedings &amp; Statements)</div>
                <div class="box-bordered" style="min-height: 140px;">
                    <div style="font-size: 9pt; color: #475569; margin-bottom: 4px;"><strong>Pahayag ng Nagreklamo ukol sa Sumbong (Complainant's Statements):</strong></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                    <div style="font-size: 9pt; color: #475569; margin: 8px 0 4px;"><strong>Tugon at Paliwanag ng Inirereklamo (Respondent's Counter-Statements):</strong></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                    <div style="font-size: 9pt; color: #475569; margin: 8px 0 4px;"><strong>Puna at Mungkahi ng Tagapamagitan / Kasunduang Nabuo (Discussions &amp; Interventions):</strong></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                    <div class="ruled-line"></div>
                </div>

                <!-- Section 3: Result & Next Steps -->
                <div class="section-tag">III. Bunga ng Pagdinig at Kasunod na Hakbang (Outcome &amp; Next Steps)</div>
                <div class="box-bordered" style="font-size: 10pt; line-height: 1.7;">
                    <div>
                        <span class="cb-box"></span> <strong>GANAP NA NAGKASUNDO (Amicably Settled)</strong> — Ihahanda ang Kasunduang Pag-aayos (KP Form 16).
                    </div>
                    <div>
                        <span class="cb-box"></span> <strong>PANSAMANTALANG KASUNDUAN (Interim Agreement)</strong> — May panimulang napagkasunduan habang hinihintay ang susunod na sesyon.
                    </div>
                    <div>
                        <span class="cb-box"></span> <strong>IPINAGPALIBAN (Rescheduled / Reset)</strong> — Itinakda ang susunod na pagdinig sa:
                        <span style="margin-left: 8px;">Petsa: <span class="placeholder-field" style="min-width: 110px;">______________</span> Oras: <span class="placeholder-field" style="min-width: 70px;">_______</span> Lugar: <span class="placeholder-field" style="min-width: 120px;">______________</span></span>
                    </div>
                    <div>
                        <span class="cb-box"></span> <strong>DI-PAGSIPOT (Non-Appearance)</strong> —
                        [ &nbsp; ] Nagreklamo ay di sumipot (KP 18) &nbsp;&nbsp;&nbsp;&nbsp;
                        [ &nbsp; ] Inirereklamo ay di sumipot (KP 19)
                    </div>
                    <div>
                        <span class="cb-box"></span> <strong>NABIGO ANG PAMAMAGITAN (Failed Mediation)</strong> — I-aakyat ang usapin sa Pangkat Tagapagkasundo o mag-iisyu ng Certificate to File Action (KP 20).
                    </div>
                </div>

                <!-- Section 4: Signatures -->
                <div class="signatures" style="margin-top: 25px;">
                    <div class="sig-block" style="width: 22%;">
                        <div class="sig-line">Nagreklamo</div>
                        <div class="sig-title">Complainant</div>
                    </div>
                    <div class="sig-block" style="width: 22%;">
                        <div class="sig-line">Inirereklamo</div>
                        <div class="sig-title">Respondent</div>
                    </div>
                    <div class="sig-block" style="width: 24%;">
                        <div class="sig-line">Kalihim na Nagtala</div>
                        <div class="sig-title">Recording Secretary</div>
                    </div>
                    <div class="sig-block" style="width: 24%;">
                        <div class="sig-line">Punong Barangay / Mediator</div>
                        <div class="sig-title">Presiding Officer</div>
                    </div>
                </div>

            <?php elseif ($formKey === 'kp_8'): ?>
                <!-- Official Barangay Tumana KP Form 8: PAABISO NG PAGDINIG -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 8</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
                    <tr>
                        <td style="width: 80px; vertical-align: top; text-align: left;">
                            <?php if (!empty($tumanaLogo)): ?>
                                <img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">
                            <?php endif; ?>
                        </td>
                        <td style="vertical-align: top; text-align: center; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div>Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; vertical-align: top; text-align: right;">
                            <?php if (!empty($marikinaLogo)): ?>
                                <img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 14px; margin-bottom: 2px; letter-spacing: 0.3px;">
                    TANGGAPAN NG PUNONG BARANGAY
                </div>
                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-bottom: 22px; letter-spacing: 0.3px;">
                    TANGGAPAN NG LUPONG TAGAPAMAYAPA
                </div>

                <!-- Right aligned Case Number & Para sa -->
                <div style="text-align: right; font-size: 10.5pt; line-height: 1.6; margin-bottom: 26px;">
                    <div>Usapin ng Barangay Blg: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px;">&nbsp;</span></div>
                    <div>Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px;">&nbsp;</span></div>
                </div>

                <!-- Title -->
                <div style="text-align: center; margin-bottom: 28px;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO NG PAGDINIG</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Mediation Proceedings)</div>
                </div>

                <!-- Addressee -->
                <div style="margin-bottom: 22px;">
                    <div style="display: flex; align-items: flex-end; margin-bottom: 6px;">
                        <span style="font-size: 11pt; white-space: nowrap; margin-right: 8px;">Para kay/Kina:</span>
                        <span style="flex: 1; border-bottom: 1px solid #111; min-height: 18px;">&nbsp;</span>
                    </div>
                    <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px; margin-left: 95px;">&nbsp;</div>
                    <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px; margin-left: 95px;">&nbsp;</div>
                    <div style="text-align: center; font-size: 10.5pt; margin-top: 4px;">
                        (Mga) Nagrereklamo
                    </div>
                </div>

                <!-- Body -->
                <div style="font-size: 11pt; line-height: 2.2; text-align: justify; margin-top: 30px; margin-bottom: 30px;">
                    <p style="text-indent: 40px; margin: 0 0 20px 0;">
                        Ikaw ay hinihilingang humarap sa akin sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 200px;">&nbsp;</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span>, sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span> para sa pagdinig ng iyong reklamo.
                    </p>
                    <p style="text-indent: 40px; margin: 0;">
                        Ngayong Ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span>, 2026.
                    </p>
                </div>

                <!-- Chairman Signature -->
                <div style="margin-top: 45px; margin-bottom: 50px; display: flex; justify-content: flex-end;">
                    <div style="text-align: center; min-width: 290px;">
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt; letter-spacing: 0.3px;">
                            HON.AKIKO LYNNE CENTENO
                        </div>
                        <div style="font-size: 10pt;">Tagapangulo ng Lupon</div>
                    </div>
                </div>

                <!-- Notice Served & Acknowledgment -->
                <div style="margin-top: 40px; font-size: 11pt;">
                    <div style="margin-bottom: 35px;">
                        Ipinagbigay-alam ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span>, 2026.
                    </div>
                    
                    <div style="display: flex; justify-content: flex-end;">
                        <div style="text-align: center; min-width: 260px;">
                            <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                            <div style="font-size: 10.5pt;">(Mga) Nagrereklamo</div>
                        </div>
                    </div>
                </div>


            <?php elseif ($formKey === 'kp_7'): ?>
                <!-- Official Barangay Tumana KP Form 7: REKLAMO (Complaint) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 7</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 2px;">TANGGAPAN NG PUNONG BARANGAY</div>
                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 20px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">REKLAMO</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Complaint)</div>
                </div>

                <div style="font-size: 11pt; line-height: 1.9; text-align: justify; margin-bottom: 20px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        AKO/KAMI ay nagrereklamo laban sa mga taong pinangalanan sa itaas dahil sa paglabag sa akin/aming karapatan at interes sa mga sumusunod:
                    </p>
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Personal na nagtungo sa tanggapan sa Barangay si <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 180px;">&nbsp;</span> upang ireklamo si <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 180px;">&nbsp;</span> sa kadahilanang kami ay may kasunduan na ngunit hindi niya ito natupad.
                    </p>
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        DAHIL DITO, AKO/KAMI ay nakikiusap na ang mga sumusunod na lunas ay ipagkaloob sa akin/amin nang naaayon sa batas at/sa katarungan.
                    </p>
                    <p style="margin: 0 0 10px 0;">
                        Ginawa ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px;">&nbsp;</span>, 2026.
                    </p>
                    <p style="margin: 0;">
                        Tinanggap ngayong ikaw-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px;">&nbsp;</span>, 2026.
                    </p>
                </div>

                <div style="margin-top: 35px; margin-bottom: 30px; display: flex; justify-content: flex-end;">
                    <div style="text-align: center; min-width: 290px;">
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">HON. AKIKO LYNNE CENTENO</div>
                        <div style="font-size: 10pt;">Punong Barangay /Tagapangulo ng lupon</div>
                    </div>
                </div>

                <div style="margin-top: 25px;">
                    <div style="border-top: 1px solid #111; width: 220px; margin-bottom: 4px;"></div>
                    <div style="font-size: 10.5pt;">(Mga) Nagrereklamo</div>
                </div>

            <?php elseif ($formKey === 'kp_9'): ?>
                <!-- Official Barangay Tumana KP Form 9: PATAWAG (Summons) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 9</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 2px;">TANGGAPAN NG PUNONG BARANGAY</div>
                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PATAWAG</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Summons)</div>
                </div>

                <div style="margin-bottom: 18px; font-size: 11pt;">
                    <div>Para kay: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 220px;">&nbsp;</span></div>
                    <div style="text-align: center; font-size: 10.5pt; margin-top: 4px;">(Mga) inerereklamo</div>
                </div>

                <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 25px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Ikaw ay ipinatawag upang humarap sa akin nang personal kasama ang iyong mga testigo, sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span> ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px;">&nbsp;</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px;">&nbsp;</span> upang sagutin ang reklamo laban sa iyo na idinulog sa harap ko, kakabit nito ang kopya, para sa pamamagitan/pag-aayos ng inyong mga sigalot ng nagrereklamo/mga nagrereklamo.
                    </p>
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Binabalaan ka na ang iyong pagtanggi o sadyang di-pagharap upang sundin ang patawag na ito ay maghahadlang sa iyo na makapaghain ng ganting-sumbong na magmumula sa reklamong ito.
                    </p>
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Kung sakali at mabigo ka, mahaharap ka sa kaparusahang paglapastangan sa hukuman.
                    </p>
                    <p style="text-indent: 40px; margin: 0;">
                        Ngayong Ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span>, 2026.
                    </p>
                </div>

                <div style="margin-top: 40px; display: flex; justify-content: flex-end;">
                    <div style="text-align: center; min-width: 290px;">
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">HON. AKIKO LYNNE CENTENO</div>
                        <div style="font-size: 10pt;">Punong Barangay/ Tagapangulo ng Lupon</div>
                    </div>
                </div>

            <?php elseif ($formKey === 'kp_10'): ?>
                <!-- Official Barangay Tumana KP Form 10: PAABISO SA PAGBUBUO NG PANGKAT -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 10</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 18px;">TANGGAPAN NG PUNONG BARANGAY</div>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO SA PAGBUBUO NG PANGKAT</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Notice for the Constitution of Pangkat)</div>
                </div>

                <div style="font-size: 11pt; margin-bottom: 20px;">
                    <div style="margin-bottom: 8px;">Para kay/kina:</div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 10.5pt;">
                        <tr>
                            <td style="width: 48%; vertical-align: top;">
                                <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                                <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                                <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                                <div style="text-align: center; font-size: 10pt;">(Mga) Nagrereklamo</div>
                            </td>
                            <td style="width: 4%;"></td>
                            <td style="width: 48%; vertical-align: top;">
                                <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                                <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                                <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                                <div style="text-align: center; font-size: 10pt;">Mga) Inirereklamo</div>
                            </td>
                        </tr>
                    </table>
                </div>

                <div style="font-size: 11pt; line-height: 2.1; text-align: justify; margin-bottom: 25px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Kayo ay hinihilingang humarap sa akin nang personal sa ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px;">&nbsp;</span> 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px;">&nbsp;</span>, sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px;">&nbsp;</span> ng umaga/hapon/gabi para sa pagbubuo ng Pangkat ng Tagapagkasundo na siyang mag-aayos ng inyong mga sigalot. Kung sakali at mabigo kayong magkasundo sa kasapian ng Pangkat o humarap sa nasabing petsa para magbuo ng Pangkat, pagpapasyahan ko ito sa pamamagitan ng palabunutan.
                    </p>
                    <p style="text-indent: 40px; margin: 0;">
                        Ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> araw ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px;">&nbsp;</span>.
                    </p>
                </div>

                <div style="margin-top: 35px; display: flex; justify-content: flex-end;">
                    <div style="text-align: center; min-width: 280px;">
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">AKIKO LYNNE CENTENO</div>
                        <div style="font-size: 10pt;">Punong Barangay</div>
                    </div>
                </div>

                <div style="margin-top: 30px; font-size: 10.5pt;">
                    <div style="margin-bottom: 18px;">
                        Ipinagbibigay-alam ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px;">&nbsp;</span>.
                    </div>
                    <div>Kay/Kina:</div>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 25px;">
                        <tr>
                            <td style="width: 45%; text-align: center;">
                                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                                <div>(Mga) Nagrereklamo</div>
                            </td>
                            <td style="width: 10%;"></td>
                            <td style="width: 45%; text-align: center;">
                                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                                <div>(Mga) Inirereklamo</div>
                            </td>
                        </tr>
                    </table>
                </div>

            <?php elseif ($formKey === 'kp_11'): ?>
                <!-- Official Barangay Tumana KP Form 11: PAABISO SA PINILING KASAPI NG PANGKAT -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 11</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO SA PINILING KASAPI NG PANGKAT</div>
                    <div style="font-size: 11pt; font-weight: bold;">(Notice to Chosen Pangkat Member)</div>
                </div>

                <div style="text-align: right; margin-bottom: 15px; font-size: 10.5pt;">
                    <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px;">&nbsp;</span> Petsa
                </div>

                <div style="margin-bottom: 20px; font-size: 11pt;">
                    Para kay: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px;">&nbsp;</span>
                </div>

                <div style="font-size: 11pt; line-height: 2.1; text-align: justify; margin-bottom: 30px;">
                    <p style="text-indent: 40px; margin: 0;">
                        Isang paabiso ang ibinibigay sa iyo na ikaw ay napiling kasapi ng Pangkat ng Tagapagkasundo upang mapayapang ayusin ang sigalot sa pagitan ng mga panig sa nakasaad sa usapin sa itaas.
                    </p>
                </div>

                <div style="margin-top: 30px; display: flex; justify-content: flex-end;">
                    <div style="text-align: center; min-width: 280px;">
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">PAMELA D. CONSULTA</div>
                        <div style="font-size: 10pt; margin-bottom: 25px;">Kalihim ng Lupon</div>

                        <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">HON. AKIKO LYNNE CENTENO</div>
                        <div style="font-size: 10pt;">Punong Barangay</div>
                    </div>
                </div>

                <div style="margin-top: 25px; font-size: 10.5pt;">
                    <div>Tinanggap ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px;">&nbsp;</span>.</div>
                    <div style="margin-top: 30px; text-align: right;">
                        <div style="display: inline-block; text-align: center; min-width: 200px;">
                            <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                            <div>Kasapi</div>
                        </div>
                    </div>
                </div>

            <?php elseif ($formKey === 'kp_12'): ?>
                <!-- Official Barangay Tumana KP Form 12: PAABISO NG PAGDINIG (Conciliation Proceedings) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 12</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO NG PAGDINIG</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Conciliation Proceedings)</div>
                </div>

                <div style="font-size: 11pt; line-height: 2.1; text-align: justify; margin-bottom: 30px;">
                    <p style="text-indent: 40px; margin: 0;">
                        Ikaw ay hinihilingang humarap sa Pangkat sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span> araw ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px;">&nbsp;</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px;">&nbsp;</span> para sa pagdinig ng usaping nakasaad sa itaas.
                    </p>
                </div>

                <div style="margin-top: 35px; display: flex; justify-content: flex-end;">
                    <div style="text-align: center; min-width: 280px;">
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">&nbsp;</div>
                        <div style="font-size: 10pt;">Tagapangulo ng Pangkat</div>
                    </div>
                </div>

                <div style="margin-top: 30px; font-size: 10.5pt;">
                    <div style="margin-bottom: 25px;">
                        Ipinagbibigay-alam ngayong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px;">&nbsp;</span>.
                    </div>

                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: 45%; text-align: center;">
                                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                                <div>(Mga) Nagrereklamo</div>
                            </td>
                            <td style="width: 10%;"></td>
                            <td style="width: 45%; text-align: center;">
                                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                                <div>(Mga) Inirereklamo</div>
                            </td>
                        </tr>
                    </table>
                </div>


            <?php elseif ($formKey === 'kp_13'): ?>
                <!-- Official Barangay Tumana KP Form 13: UTOS UPANG HUMARAP (Subpoena) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 13</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">UTOS UPANG HUMARAP</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Subpoena)</div>
                </div>

                <div style="margin-bottom: 18px; font-size: 11pt;">
                    <div>Para kay/kina: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px;">&nbsp;</span></div>
                    <div style="text-align: center; font-size: 10.5pt; margin-top: 6px; font-weight: bold;">Mga Testigo</div>
                </div>

                <div style="font-size: 11pt; line-height: 2.1; text-align: justify; margin-bottom: 25px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Ikaw ay inuutusan na humarap sa akin sa ika - <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px;">&nbsp;</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span> upang tumestigo sa pagdinig sa usaping nakasaad sa itaas.
                    </p>
                    <p style="text-indent: 40px; margin: 0;">
                        Ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px;">&nbsp;</span> araw ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px;">&nbsp;</span>.
                    </p>
                </div>

                <div style="margin-top: 40px; display: flex; justify-content: flex-end;">
                    <div style="text-align: center; min-width: 320px;">
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">HON. AKIKO LYNNE CENTENO</div>
                        <div style="font-size: 10pt;">Punong Barangay/Tagapangulo ng Pangkat</div>
                    </div>
                </div>

            <?php elseif ($formKey === 'kp_14'): ?>
                <!-- Official Barangay Tumana KP Form 14: KASUNDUAN PARA SA PAGHAHATOL -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 14</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KASUNDUAN PARA SA PAGHAHATOL</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Agreement for Arbitration)</div>
                </div>

                <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 22px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Kami ay nagkasundo na idulog ang aming sigalot sa paghahatol sa Punong Barangay/Pangkat ng Tagapagkasundo (Alisin ang di-angkop) at pagkakaisahan namin na aming susundin ang hatol na maaaring igawad dito. Ginawa namin ang kasunduang ito nang malaya at kusang-loob na mayroong ganap na pang-unawa sa anumang uri at magiging bunga nito.
                    </p>
                    <p style="text-indent: 40px; margin: 0;">
                        Itinala ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px;">&nbsp;</span>.
                    </p>
                </div>

                <table style="width: 100%; border-collapse: collapse; margin-top: 25px; margin-bottom: 25px;">
                    <tr>
                        <td style="width: 45%; text-align: center;">
                            <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                            <div>(Mga) Nagrereklamo</div>
                        </td>
                        <td style="width: 10%;"></td>
                        <td style="width: 45%; text-align: center;">
                            <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                            <div>(Mga) Inirereklamo</div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin: 20px 0 10px;">PAGPAPATOTOO</div>
                <div style="font-size: 10.5pt; line-height: 1.8; text-align: justify; margin-bottom: 25px;">
                    Pinapatunayan ko na ang sinusundang Kasunduan ng Paghahatol ay malaya at kusang-loob na ginawa ng mga panig, matapos na aking maipaliwanag sa kanila ang uri at ang magiging bunga nito ng gayong kasunduan.
                </div>

                <table style="width: 100%; border-collapse: collapse; margin-top: 25px;">
                    <tr>
                        <td style="width: 45%; vertical-align: top;">
                            <div style="font-weight: bold; font-size: 11pt;">HON. AKIKO LYNNE CENTENO</div>
                            <div style="font-size: 10pt;">Punong Barangay</div>
                        </td>
                        <td style="width: 10%;"></td>
                        <td style="width: 45%; vertical-align: top; text-align: center;">
                            <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt;">Tagapangulo ng Pangkat</div>
                        </td>
                    </tr>
                </table>

            <?php elseif ($formKey === 'kp_15'): ?>
                <!-- Official Barangay Tumana KP Form 15: GAWAD NG PAGHAHATOL -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 15</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">GAWAD NG PAGHAHATOL</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Arbitration Award)</div>
                </div>

                <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 20px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Matapos na marinig ang mga salaysay na ipinahayag at sa maingat na pagsusuri ng ebidensya na iniharap sa usaping ito, ang hatol ay ginawa tulad ng sumusunod:
                    </p>
                    <div style="border-bottom: 1px solid #111; min-height: 24px; margin-bottom: 12px;">&nbsp;</div>
                    <div style="border-bottom: 1px solid #111; min-height: 24px; margin-bottom: 16px;">&nbsp;</div>
                    <p style="margin: 0;">
                        Ginawa ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px;">&nbsp;</span> sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 180px;">&nbsp;</span>.
                    </p>
                </div>

                <div style="margin-top: 25px; display: flex; justify-content: flex-end;">
                    <div style="text-align: center; min-width: 260px;">
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt; margin-bottom: 22px;">Tagapangulo ng Pangkat</div>
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt; margin-bottom: 22px;">Kasapi</div>
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt;">Kasapi</div>
                    </div>
                </div>

                <div style="margin-top: 25px; font-size: 10pt;">
                    <div style="font-weight: bold; margin-bottom: 20px;">Pinatotohanan:</div>
                    <div style="width: 250px; border-top: 1px solid #111; padding-top: 4px; font-weight: bold;">
                        Punong Barangay/Kalihim ng Lupon**
                    </div>
                    <div style="margin-top: 25px; font-size: 8.5pt; color: #475569; line-height: 1.4;">
                        <div>*Lalagdaan ng sinumang gumawa ng gawad ng paghahatol.</div>
                        <div>**Lalagdaan ng Punong Barangay kung ang gawad ay ginawa ng Tagapangulo ng Pangkat, at ng Kalihim ng Lupon, kung ginawa ng Punong Barangay.</div>
                    </div>
                </div>


            <?php elseif ($formKey === 'kp_16'): ?>
                <!-- Official Barangay Tumana KP Form 16: MATIWASAY NA PAG-AAYOS -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 16</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">MATIWASAY NA PAG-AAYOS</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Amicable Settlement)</div>
                </div>

                <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 16px;">
                    KAMI, mga nagrereklamo/at inirereklamo sa usaping nakasaad sa itaas, ay nagkasundo na aayusin ang aming sigalot sa mga sumusunod:
                </div>

                <div style="margin-bottom: 20px;">
                    <?php for ($line = 0; $line < 10; $line++): ?>
                        <div style="border-bottom: 1px solid #111; min-height: 22px; margin-bottom: 6px;">&nbsp;</div>
                    <?php endfor; ?>
                </div>

                <div style="font-size: 10.5pt; line-height: 1.8; margin-bottom: 18px;">
                    At magkakaisa na tapat naming susundin ang mga kondisyon sa pag-aayos na nakasaad sa itaas.
                </div>

                <div style="font-size: 10.5pt; margin-bottom: 20px;">
                    Itinala ngayong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px;">&nbsp;</span>.
                </div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 25px;">
                    <tr>
                        <td style="width: 45%; text-align: center;">
                            <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                            <div>(Mga) Nagrereklamo</div>
                        </td>
                        <td style="width: 10%;"></td>
                        <td style="width: 45%; text-align: center;">
                            <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                            <div>(Mga) Inirereklamo</div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-bottom: 8px;">PAGPAPATOTOO</div>
                <div style="font-size: 10pt; line-height: 1.7; text-align: justify; margin-bottom: 25px;">
                    PINATUTUNAYAN ko ang sinusundang Matiwasay na Pag-aayos na ginawa ng mga panig ay malaya at kusang-loob, matapos na aking maipaliwanag sa kanila ang uri at magiging bunga ng gayong pag-aayos.
                </div>

                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="width: 45%; vertical-align: top;">
                            <div style="border-top: 1px solid #111; width: 220px; margin-bottom: 4px;"></div>
                            <div style="font-weight: bold; font-size: 10.5pt;">HON. AKIKO LYNNE CENTENO</div>
                            <div style="font-size: 9.5pt;">Punong Barangay</div>
                        </td>
                        <td style="width: 10%;"></td>
                        <td style="width: 45%; vertical-align: top; text-align: center;">
                            <div style="border-top: 1px solid #111; width: 220px; margin: 0 auto 4px;"></div>
                            <div style="font-size: 10pt;">Tagapangulo ng Pangkat</div>
                        </td>
                    </tr>
                </table>

            <?php elseif ($formKey === 'kp_17'): ?>
                <!-- Official Barangay Tumana KP Form 17: PAGTANGGI (Repudiation) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 17</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAGTANGGI</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Repudiation)</div>
                </div>

                <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 16px;">
                    Aking/Aming tinatanggihan ang pag-aayos/kasunduan para sa paghahatol sa dahilan na ang aking pagpayag ay pinawalang-bisa ng:
                </div>

                <div style="font-size: 10.5pt; line-height: 1.8; margin-bottom: 18px;">
                    <div style="margin-bottom: 8px;">(Lagyan ng tsek kung alin ang angkop)</div>
                    <div style="margin-bottom: 10px;">
                        ( &nbsp; ) Pandaraya (Isaad ang detalye)<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 480px;">&nbsp;</span>
                        <div style="border-bottom: 1px solid #111; min-height: 20px; margin-top: 6px;">&nbsp;</div>
                    </div>
                    <div style="margin-bottom: 10px;">
                        ( &nbsp; ) Karahasan (Isaad ang detalye)<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 480px;">&nbsp;</span>
                        <div style="border-bottom: 1px solid #111; min-height: 20px; margin-top: 6px;">&nbsp;</div>
                    </div>
                    <div style="margin-bottom: 10px;">
                        ( &nbsp; ) Pananakot (Isaad ang detalye)<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 480px;">&nbsp;</span>
                        <div style="border-bottom: 1px solid #111; min-height: 20px; margin-top: 6px;">&nbsp;</div>
                    </div>
                </div>

                <div style="font-size: 10.5pt; margin-bottom: 20px;">
                    Ngayong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> araw ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px;">&nbsp;</span>.
                </div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 22px;">
                    <tr>
                        <td style="width: 45%; text-align: center;">
                            <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                            <div>(Mga) Nagrereklamo</div>
                        </td>
                        <td style="width: 10%;"></td>
                        <td style="width: 45%; text-align: center;">
                            <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                            <div>(Mga) Inirereklamo</div>
                        </td>
                    </tr>
                </table>

                <div style="font-size: 10.5pt; line-height: 1.8; margin-bottom: 25px;">
                    Tinanggap at pinanumpaan sa harap ko ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px;">&nbsp;</span> sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 220px;">&nbsp;</span>.
                </div>

                <div style="display: flex; justify-content: flex-end; margin-bottom: 25px;">
                    <div style="text-align: center; min-width: 280px;">
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt; margin-bottom: 20px;">Tagapangulo ng Pangkat/Kasapi</div>
                        <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 10.5pt;">HON. AKIKO LYNNE CENTENO</div>
                        <div style="font-size: 10pt;">Punong Barangay</div>
                    </div>
                </div>

                <div style="font-size: 8.5pt; color: #475569; line-height: 1.4; border-top: 1px dashed #cbd5e1; padding-top: 10px;">
                    *Ang pagkabigong tanggihan ang pag-aayos o kasunduan ng paghahatol na nalolooban sa itinakdang sampung (10) araw mula sa petsa ng pag-aayos at limang (5) araw mula sa petsa ng kasunduan ng paghahatol ayon sa pag-kakasunud-sunod ay ipapalagay na tinalikdan na niya ang karapatang tumutol sa nasabing mga dahilan.
                </div>

            <?php elseif ($formKey === 'kp_18'): ?>
                <!-- Official Barangay Tumana KP Form 18: PAABISO NG PAGDINIG (Re: Failure to Appear) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 18</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 18px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 10px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 4px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 170px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO NG PAGDINIG</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Re: Failure to Appear)</div>
                </div>

                <div style="margin-bottom: 18px; font-size: 11pt;">
                    <div>Para kay/kina: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px;">&nbsp;</span></div>
                    <div style="text-align: center; font-size: 10.5pt; margin-top: 4px;">(Mga) nagrereklamo</div>
                </div>

                <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 25px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Ikaw ay hinihilingan na humarap sa akin/Pangkat <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px;">&nbsp;</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span> upang ipaliwanag kung bakit nabigo kang humarap para sa pamamagitan/pagkakasundo na itinakda noong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px;">&nbsp;</span>, 2026 at kung bakit ang iyong reklamo ay hindi dapat ipawalang-saysay, ang katibayan upang humadlang sa paghahain ng sakdal sa hukuman/tanggapan ng pamahalaan ay di-dapat ipalabas, at ang paglilitis ng paglapastangan sa hukuman ay di-dapat ihain sa hukuman dahil sa pagkabigo o pagtangging humarap sa Punong Barangay/Pangkat ng Tagapagkasundo.
                    </p>
                    <p style="text-indent: 40px; margin: 0;">
                        Ipinagbibigay-alam ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, 2026.
                    </p>
                </div>

                <div style="margin-top: 35px;">
                    <div style="border-top: 1px solid #111; width: 260px; margin-bottom: 4px;"></div>
                    <div style="font-weight: bold; font-size: 11pt;">HON. AKIKO LYNNE CENTENO</div>
                    <div style="font-size: 10pt;">Punong Barangay/Tagapangulo ng Pangkat</div>
                </div>

            <?php elseif ($formKey === 'kp_19'): ?>
                <!-- Official Barangay Tumana KP Form 19: PAABISO NG PAGDINIG (Re: Failure to Appear - Respondent) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 19</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 6px; margin-bottom: 2px;">TANGAPAN NG PUNONG BARANGAY</div>
                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-bottom: 16px;">TANGGAPAN NG LUPON TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="font-size: 10pt; margin-bottom: 4px;">Barangay Tumana, Marikina City</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 16px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO NG PAGDINIG</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">(Re: Failure to Appear)</div>
                </div>

                <div style="margin-bottom: 16px; font-size: 11pt;">
                    <div>Para kay: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px;">&nbsp;</span></div>
                    <div style="text-align: center; font-size: 10.5pt; margin-top: 4px;">(Mga) Inerereklamo</div>
                </div>

                <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 25px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        Ikaw ay hinihilingan na humarap sa akin/Pangkat sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span> ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px;">&nbsp;</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px;">&nbsp;</span> upang ipaliwanag kung bakit nabigo kang humarap para sa pamamagitan/pagkakasundo na itinakda noong, 2025 at kung bakit ang iyong reklamo ay hindi dapat ipawalang-saysay, ang katibayan upang humadlang sa paghahain ng sakdal sa hukuman/tanggapan ng pamahalaan ay di-dapat ipalabas, at ang paglilitis ng paglapastangan sa hukuman ay di-dapat ihain sa hukuman dahil sa pagkabigo o pagtangging humarap sa Punong Barangay/Pangkat ng Tagapagkasundo.
                    </p>
                    <p style="text-indent: 40px; margin: 0;">
                        Ipinagbibigay-alam ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, 2026.
                    </p>
                </div>

                <table style="width: 100%; border-collapse: collapse; margin-top: 35px;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-top: 1px solid #111; width: 220px; margin-bottom: 4px;"></div>
                            <div style="font-size: 10pt;">Inerereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right;">
                            <div style="display: inline-block; text-align: center;">
                                <div style="border-top: 1px solid #111; width: 260px; margin-bottom: 4px;"></div>
                                <div style="font-weight: bold; font-size: 11pt;">HON. AKIKO LYNNE CENTENO</div>
                                <div style="font-size: 10pt;">Punong Barangay</div>
                            </div>
                        </td>
                    </tr>
                </table>

            <?php elseif ($formKey === 'kp_20'): ?>
                <!-- Official Barangay Tumana KP Form 20: KATIBAYAN NA MAKAPAGHAIN NG SAKDAL (Repudiation) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 20</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</div>
                    <div style="font-size: 11pt; font-weight: bold;">(Certification to File Action)</div>
                </div>

                <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 20px;">
                    <div style="font-weight: bold; margin-bottom: 8px;">ITO AY NAGPAPATUNAY NA:</div>
                    <ol style="margin: 0; padding-left: 24px; text-align: justify;">
                        <li style="margin-bottom: 10px;">Nagkaroon ng personal na paghaharap sa pagitan ng mga panig sa harap ng Punong Barangay/Pangkat ng Tagapagkasundo;</li>
                        <li style="margin-bottom: 10px;">Na nagkaroon ng pag-aayos;</li>
                        <li style="margin-bottom: 10px;">Na ang pag-aayos ay tinanggihan sa isang sinumpaang salaysay sa harap ng Punong Barangay sa pamamagitan ni <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px;">&nbsp;</span> sa dahilan ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 320px;">&nbsp;</span><div style="border-bottom: 1px solid #111; min-height: 18px; margin-top: 8px;">&nbsp;</div><div style="border-bottom: 1px solid #111; min-height: 18px; margin-top: 8px;">&nbsp;</div>;</li>
                        <li style="margin-bottom: 10px;">Dahil dito, ang nasabing sigalot ay maaari nang ihain sa hukuman/tanggapan ng pamahalaan.</li>
                    </ol>
                </div>

                <div style="font-size: 11pt; margin-top: 25px; margin-bottom: 35px; text-indent: 40px;">
                    Ngayong ika- <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span>, 2026.
                </div>

                <div style="text-align: right; margin-top: 40px;">
                    <div style="display: inline-block; text-align: center;">
                        <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
                        <div style="font-size: 10.5pt;">Tagapangulo ng Pangkat</div>
                    </div>
                </div>

            <?php elseif ($formKey === 'kp_20_a'): ?>
                <!-- Official Barangay Tumana KP Form 20-A: KATIBAYAN NA MAKAPAGHAIN NG SAKDAL (Conciliation Failed) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 20-A</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</div>
                    <div style="font-size: 11pt; font-weight: bold;">(Certification to File Action)</div>
                </div>

                <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 20px;">
                    <div style="font-weight: bold; margin-bottom: 8px;">ITO AY NAGPAPATUNAY NA:</div>
                    <ol style="margin: 0; padding-left: 24px; text-align: justify;">
                        <li style="margin-bottom: 10px;">Nagkaroon ng personal na paghaharap sa pagitan ng mga panig sa harap ng Punong Barangay subalit ang pamamagitan ay nabigo;</li>
                        <li style="margin-bottom: 10px;">Ang Pangkat ng Tagapagkasundo ay binuo subalit ang personal na pag-uusap sa harap ng Pangkat ay di nagbunga ng pag-aayos;</li>
                        <li style="margin-bottom: 10px;">Dahil dito, ang nasabing reklamo para sa sigalot ay maaari nang idulog sa hukuman/tanggapan ng pamahalaan.</li>
                    </ol>
                </div>

                <div style="font-size: 11pt; margin-top: 25px; margin-bottom: 35px; text-indent: 40px;">
                    Ngayong ika- <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span>, 2026.
                </div>

                <div style="text-align: right; margin-top: 40px;">
                    <div style="display: inline-block; text-align: center;">
                        <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
                        <div style="font-size: 10.5pt;">Tagapangulo ng Pangkat</div>
                    </div>
                </div>

            <?php elseif ($formKey === 'kp_20_b'): ?>
                <!-- Official Barangay Tumana KP Form 20-B: KATIBAYAN NA MAKAPAGHAIN NG SAKDAL (Respondent Willful Nonappearance) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 20-B</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</div>
                    <div style="font-size: 11pt; font-weight: bold;">(Certification to File Action)</div>
                </div>

                <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 20px;">
                    <div style="font-weight: bold; margin-bottom: 8px;">ITO AY NAGPAPATUNAY NA:</div>
                    <ol style="margin: 0; padding-left: 24px; text-align: justify;">
                        <li style="margin-bottom: 10px;">Nagkaroon ng personal na paghaharap sa pagitan ng mga panig sa harap ng Punong Barangay, subalit ang pamamagitan ay nabigo;</li>
                        <li style="margin-bottom: 10px;">Ang Punong Barangay ay nagtakda ng pulong ng mga panig para magbuo ng Pangkat;</li>
                        <li style="margin-bottom: 10px;">Sinadya ng inirereklamo o tumangging humarap sa Pangkat para sa hakbang ng pagkakasundo ng walang makatwirang dahilan; at</li>
                        <li style="margin-bottom: 10px;">Dahil dito, ang nasabing reklamo para sa sigalot ay maaari nang idulog sa hukuman/tanggapan ng pamahalaan.</li>
                    </ol>
                </div>

                <div style="font-size: 11pt; margin-top: 25px; margin-bottom: 35px; text-indent: 40px;">
                    Ngayong ika- <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px;">&nbsp;</span>, 2026.
                </div>

                <div style="text-align: right; margin-top: 40px;">
                    <div style="display: inline-block; text-align: center;">
                        <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
                        <div style="font-size: 10.5pt;">Tagapangulo ng Pangkat</div>
                    </div>
                </div>

            <?php elseif ($formKey === 'kp_21'): ?>
                <!-- Official Barangay Tumana KP Form 21: KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL (Bar Action) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 21</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL</div>
                    <div style="font-size: 11pt; font-weight: bold;">(Certification to Bar Action)</div>
                </div>

                <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 25px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        ITO AY NAGPAPATUNAY NA ang usaping nakasaad sa itaas ay ipinawalang-saysay sa Kautusan na may petsang <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 180px;">&nbsp;</span> para sa mga nagrereklamong si <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 220px;">&nbsp;</span>, dahil sa sinadya o kusang di-pagharap sa pagdinig sa harap ng Punong Barangay/Pangkat ng Tagapagkasundo at dahil dito, ang nagrereklamo/mga nagrereklamo ay hinahadlangang makapaghain ng sakdal sa hukuman/tanggapan ng pamahalaan.
                    </p>
                    <p style="text-indent: 40px; margin: 0;">
                        Ngayong ika- <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, 2026.
                    </p>
                </div>

                <table style="width: 100%; border-collapse: collapse; margin-top: 30px;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-top: 1px solid #111; width: 220px; margin-bottom: 4px;"></div>
                            <div style="font-size: 10pt; margin-bottom: 35px;">Tagapangulo ng Pangkat</div>

                            <div style="border-top: 1px solid #111; width: 260px; margin-bottom: 4px;"></div>
                            <div style="font-weight: bold; font-size: 11pt;">HON. AKIKO LYNNE CENTENO</div>
                            <div style="font-size: 10pt;">Punong Barangay</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right;">
                            <div style="display: inline-block; text-align: center;">
                                <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
                                <div style="font-size: 10pt;">Kalihim ng Lupon/Pangkat</div>
                            </div>
                        </td>
                    </tr>
                </table>

            <?php elseif ($formKey === 'kp_22'): ?>
                <!-- Official Barangay Tumana KP Form 22: KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL (Bar Counterclaim) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 22</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL</div>
                    <div style="font-size: 11pt; font-weight: bold;">(Certification to Bar Counterclaim)</div>
                </div>

                <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 25px;">
                    <p style="text-indent: 40px; margin: 0 0 16px 0;">
                        ITO AY NAGPAPATUNAY NA matapos ang mga nauunang paabiso at pagdinig, ang inirereklamo/mga inirereklamo na sina <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 180px;">&nbsp;</span>(pangalan) at <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 180px;">&nbsp;</span>(pangalan), ay napag-alamang sinadya o tumangging humarap nang walang makatwirang dahilan sa harap ng Punong Barangay/Pangkat Tagapagkasundo at dahil dito ang inirereklamo/mga inirereklamo ay hinahadlangang maghain ng kanilang ganting-sumbong (kung meron) na nagmula sa sigalot sa hukuman/tanggapan ng pamahalaan.
                    </p>
                    <p style="text-indent: 40px; margin: 0;">
                        Ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 30px;">&nbsp;</span>.
                    </p>
                </div>

                <table style="width: 100%; border-collapse: collapse; margin-top: 25px;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-top: 1px solid #111; width: 220px; margin-bottom: 4px;"></div>
                            <div style="font-size: 10pt; margin-bottom: 35px;">Tagapangulo ng Pangkat</div>

                            <div style="border-top: 1px solid #111; width: 260px; margin-bottom: 4px;"></div>
                            <div style="font-size: 10pt;">Tagapangulo ng Lupon/Tagapangulo ng Pangkat</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right;">
                            <div style="display: inline-block; text-align: center;">
                                <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
                                <div style="font-size: 10pt;">Kalihim ng Lupon/Pangkat</div>
                            </div>
                        </td>
                    </tr>
                </table>

                <div style="font-size: 9.5pt; font-style: italic; color: #374151; margin-top: 25px; line-height: 1.4;">
                    <strong>Mahalaga:</strong> Kung ang Kalihim ng Lupon ang gumawa ng katibayan, ang Tagapangulo ng Lupon ang magpapatunay. Kung ang Kalihim ng Pangkat ang gumawa ng katibayan, ang Tagapangulo ng Pangkat ang magpapatunay.
                </div>

            <?php elseif ($formKey === 'kp_23'): ?>
                <!-- Official Barangay Tumana KP Form 23: MOSYON SA PAGPAPATUPAD (Motion for Execution) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 23</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">MOSYON SA PAGPAPATUPAD</div>
                    <div style="font-size: 11pt; font-weight: bold;">(Motion for Execution)</div>
                </div>

                <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 25px;">
                    <ol style="margin: 0; padding-left: 24px; text-align: justify;">
                        <li style="margin-bottom: 12px;">Na noong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span> ang mga panig sa usaping ito ay lumagda sa isang matiwasay na pag-aayos/tumanggap ng gawad ng paghahatol ng Tagapangulo ng Lupon/Pangkat ng Tagapagkasundo;</li>
                        <li style="margin-bottom: 12px;">Ang sampung (10) araw na taning mula sa petsang nakasaad sa itaas ay natapos nang walang anumang sinumpaang salaysay ng pagtanggi sa pag-aayos ang inihain ng sinumang panig sa harap ng Tagapangulo ng Lupon, isang petisyon na nagpapawalang-saysay ng gawad ng paghahatol sa hukuman; at</li>
                        <li style="margin-bottom: 12px;">Ang matiwasay na pag-aayos/gawad ng paghahatol ay pinal at maaari nang ipatupad.</li>
                    </ol>
                    <p style="text-indent: 40px; margin: 18px 0 0 0; text-align: justify;">
                        DAHIL DITO, ang Nagrereklamo/Inirereklamo ay humiling na ang katumbas na kasulatan sa pagpapatupad sa usaping ito ay ipalabas na ng Tagapangulo ng Lupon.
                    </p>
                </div>

                <table style="width: 100%; border-collapse: collapse; margin-top: 50px;">
                    <tr>
                        <td style="width: 45%; vertical-align: top;">
                            <div style="border-top: 1px solid #111; width: 180px; margin-bottom: 4px;"></div>
                            <div style="font-size: 10pt;">Petsa</div>
                        </td>
                        <td style="width: 55%; vertical-align: top; text-align: right;">
                            <div style="display: inline-block; text-align: center;">
                                <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
                                <div style="font-size: 10pt;">Inirereklamo</div>
                            </div>
                        </td>
                    </tr>
                </table>

            <?php elseif ($formKey === 'kp_25'): ?>
                <!-- Official Barangay Tumana KP Form 25: PAABISO PARA SA PAGPAPATUPAD (Notice for Execution) -->
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 6px;">Pormularyo ng KP Blg. 25</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
                    <tr>
                        <td style="width: 80px; text-align: left; vertical-align: top;">
                            <?php if (!empty($tumanaLogo)): ?><img src="<?php echo $tumanaLogo; ?>" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: top; font-size: 10pt; line-height: 1.25;">
                            <div>Republika ng Pilipinas</div>
                            <div>Lalawigan ng NCR</div>
                            <div>Lungsod ng Marikina</div>
                            <div style="font-weight: bold;">Barangay Tumana</div>
                            <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                            <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
                        </td>
                        <td style="width: 80px; text-align: right; vertical-align: top;">
                            <?php if (!empty($marikinaLogo)): ?><img src="<?php echo $marikinaLogo; ?>" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                            <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>
                            <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
                        </td>
                        <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                            <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px;">&nbsp;</span></div>
                            <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span></div>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 18px 0;">
                    <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO PARA SA PAGPAPATUPAD</div>
                    <div style="font-size: 11pt; font-weight: bold;">(Notice for Execution)</div>
                </div>

                <div style="font-size: 11pt; line-height: 1.8; text-align: justify; margin-bottom: 25px;">
                    <p style="text-indent: 40px; margin: 0 0 14px 0;">
                        SSAPAGKAT, noong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px;">&nbsp;</span> (petsa), isang matiwasay na pag-aayos ang nilagdaan ng mga panig sa usaping nakasaad sa itaas (o isang gawad ng paghahatol ang iginawad ng Punong Barangay/Pangkat ng Tagapagkasundo);
                    </p>
                    <p style="text-indent: 40px; margin: 0 0 10px 0;">
                        SAPAGKAT, ang bahagi ng mga probisyon at mga kondisyon ng hatol ay mababasa tulad ng sumusunod:
                    </p>
                    <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                    <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                    <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                    <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 14px;">&nbsp;</div>

                    <p style="margin: 0 0 14px 0;">
                        Ang nasabing pag-aayos/hatol ay pinal at maaari nang ipatupad;
                    </p>

                    <p style="text-indent: 40px; margin: 0 0 14px 0;">
                        SAPAGKAT, ang natalong panig na si <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 220px;">&nbsp;</span>(pangalan) ay hindi tumupad nang kusang-loob sa nasabing matiwasay na pag-aayos/gawad ng paghahatol sa loob ng limang (5) araw mula sa petsa ng pagdinig ng mosyon ng pagpapatupad;
                    </p>

                    <p style="text-indent: 40px; margin: 0 0 14px 0;">
                        DAHIL DITO, sa ngalan ng Lupong Tagapamayapa at sa pamamagitan ng mga kapangyarihang ibinigay sa akin ng Lupon sa pamamagitan ng Batas at mga Patakaran ng Katarungang Pambarangay aking gagawin upang maisakatuparan mula sa mga kalakal at ari-ariang personal ni <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 220px;">&nbsp;</span>(pangalan ng natalong panig) ang halagang <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 200px;">&nbsp;</span>(₱<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px;">&nbsp;</span>) (isaad ang halaga ng pag-aayos o hatol) na pinagkasunduan sa nasabing matiwasay na pag-aayos (o inihatol sa nasabing gawad ng paghahatol), maliban kung kusang-loob na tinupad ang nasabing pag-aayos o hatol ay ginawa sa sandaling matanggap ito.
                    </p>

                    <p style="text-indent: 40px; margin: 0;">
                        Nilagdaan ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px;">&nbsp;</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px;">&nbsp;</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 30px;">&nbsp;</span>.
                    </p>
                </div>

                <div style="text-align: right; margin-top: 40px;">
                    <div style="display: inline-block; text-align: center;">
                        <div style="border-top: 1px solid #111; width: 280px; margin-bottom: 4px;"></div>
                        <div style="font-weight: bold; font-size: 11pt;">HON. AKIKO LYNNE CENTENO</div>
                        <div style="font-size: 10pt;">Punong Barangay</div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Standard KP Form Placeholder (KP Forms 1 - 25) -->
                <div class="header">
                    <div class="republic"><?php echo htmlspecialchars($headerTitle); ?></div>
                    <div class="lgu"><?php echo htmlspecialchars($lguHeader); ?></div>
                    <div class="office">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
                </div>

                <div class="form-code"><?php echo htmlspecialchars($formMeta['code']); ?></div>
                <div class="form-title"><?php echo htmlspecialchars($formMeta['title']); ?></div>
                <div class="form-subtitle"><?php echo htmlspecialchars($formMeta['subtitle']); ?></div>

                <div class="case-meta">
                    <div style="width: 55%;">
                        <div style="font-weight: bold;">MGA MAY-SUMBONG / NAGREKLAMO:</div>
                        <div class="placeholder-field" style="width: 90%;">[ Pangalan ng May-Sumbong / Complainant ]</div>
                        <div style="margin-top: 4px; font-size: 10pt; color: #4b5563;">— laban kay / kina —</div>
                        <div style="font-weight: bold; margin-top: 4px;">MGA IPINAGSUSUMBONG / INIREREKLAMO:</div>
                        <div class="placeholder-field" style="width: 90%;">[ Pangalan ng Ipinagsusumbong / Respondent ]</div>
                    </div>
                    <div style="width: 40%; text-align: right;">
                        <div><strong>Usaping Barangay Blg.:</strong> <span class="placeholder-field" style="min-width: 120px;">[ Case / Docket No. ]</span></div>
                        <div style="margin-top: 6px;"><strong>Ukol sa:</strong> <span class="placeholder-field" style="min-width: 120px;">[ Uri ng Usapin / Dispute ]</span></div>
                    </div>
                </div>

                <div class="body-text">
                    Ito ay opisyal na dokumento ng Katarungang Pambarangay para sa <strong><?php echo htmlspecialchars($formMeta['title']); ?></strong> alinsunod sa mga probisyon ng <em>Batas Republika Blg. 7160 (Local Government Code of 1991, Chapter VII)</em> at ng mga Tuntunin ng Lupong Tagapamayapa ng Barangay Tumana, Lungsod ng Marikina.
                </div>

                <div class="body-text">
                    Sa pamamagitan nito, pinatutunayan na ang mga tala at prosesong may kinalaman sa usapin sa pagitan ng mga partidong nabanggit sa itaas ay pormal na pinagtibay sa tanggapan ng Barangay Tumana ngayong ika-<span class="placeholder-field" style="min-width: 60px;">[ Araw ]</span> ng <span class="placeholder-field" style="min-width: 100px;">[ Buwan ]</span>, 20<span class="placeholder-field" style="min-width: 30px;">[ Taon ]</span>.
                </div>

                <div class="signatures">
                    <div class="sig-block">
                        <div class="sig-line">[ Nilagdaan ng Kalihim ng Lupon ]</div>
                        <div class="sig-title">Kalihim ng Lupon / Pangkat</div>
                    </div>
                    <div class="sig-block">
                        <div class="sig-line">[ Nilagdaan ng Punong Barangay ]</div>
                        <div class="sig-title">Punong Barangay / Tagapangulo</div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="watermark-note">
                ℹ️ <strong>Standard Template Placeholder:</strong> You can upload your custom Word or PDF format for this form using the <strong>Upload Template</strong> button on the KP Documents and Forms page.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 2. UPLOAD CUSTOM TEMPLATE
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'upload') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator or Officer privileges required to upload templates.']);
        exit;
    }

    $formKey = trim((string)($_POST['form_key'] ?? ''));
    if (!isset($masterForms[$formKey])) {
        echo json_encode(['success' => false, 'message' => 'Invalid KP Form code.']);
        exit;
    }

    if (empty($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or file upload error occurred.']);
        exit;
    }

    $file = $_FILES['template_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'];

    if (!in_array($ext, $allowedExts, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file format. Allowed: PDF, DOC, DOCX, PNG, JPG.']);
        exit;
    }

    if ($file['size'] > 12 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds maximum allowable limit of 12 MB.']);
        exit;
    }

    $filename = "{$formKey}_template_" . date('Ymd_His') . ".{$ext}";
    $destination = $storageDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save template to storage directory.']);
        exit;
    }

    // Save into registry
    $registry[$formKey] = [
        'code' => $masterForms[$formKey]['code'],
        'title' => $masterForms[$formKey]['title'],
        'file_path' => 'storage/uploads/templates/' . $filename,
        'file_name' => $file['name'],
        'file_size' => $file['size'],
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => $userId
    ];
    saveRegistry($registryFile, $registry);

    echo json_encode([
        'success' => true,
        'message' => "Successfully uploaded template for {$masterForms[$formKey]['code']}.",
        'template' => $registry[$formKey]
    ]);
    exit;
}

// 3. DELETE CUSTOM TEMPLATE (RESTORE PLACEHOLDER)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator privileges required.']);
        exit;
    }

    $formKey = trim((string)($_POST['form_key'] ?? ''));
    if (isset($registry[$formKey])) {
        $storedPath = dirname(__DIR__, 3) . '/' . $registry[$formKey]['file_path'];
        if (file_exists($storedPath)) {
            @unlink($storedPath);
        }
        unset($registry[$formKey]);
        saveRegistry($registryFile, $registry);
    }

    echo json_encode([
        'success' => true,
        'message' => "Reset template for {$masterForms[$formKey]['code']} to official system placeholder."
    ]);
    exit;
}

// 4. LIST ALL FORMS & TEMPLATE STATUS
header('Content-Type: application/json; charset=utf-8');
$list = [];
foreach ($masterForms as $k => $f) {
    $hasUpload = isset($registry[$k]);
    $list[] = [
        'key' => $k,
        'code' => $f['code'],
        'title' => $f['title'],
        'subtitle' => $f['subtitle'],
        'category' => $f['category'],
        'description' => $f['description'],
        'has_custom_template' => $hasUpload,
        'custom_template' => $hasUpload ? $registry[$k] : null,
        'preview_url' => "../../../backend/api/documents/templates.php?action=preview&form_key=" . urlencode($k)
    ];
}

echo json_encode([
    'success' => true,
    'data' => $list,
    'total_forms' => count($list),
    'uploaded_count' => count($registry)
]);
exit;
