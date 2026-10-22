<?php
/**
 * AGAP System — Katarungang Pambarangay Document Templates API
 * Manages official KP Form templates (1 to 25), file uploads, previews, and printing.
 */

session_start();

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

// Master List of 27 Katarungang Pambarangay Forms
$masterForms = [
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
        'code' => 'KP Form No. 7',
        'title' => 'Complaint',
        'subtitle' => 'Reklamo / Pagsasampa ng Usapin',
        'category' => 'Mediation & Summons',
        'description' => 'Formal statement of grievance filed by the complainant before the Punong Barangay.'
    ],
    'kp_8' => [
        'code' => 'KP Form No. 8',
        'title' => 'Notice of Hearing (Mediation Process)',
        'subtitle' => 'Paunawa ng Pagdinig (Pamamagitan ng Punong Barangay)',
        'category' => 'Mediation & Summons',
        'description' => 'Hearing notice served to the complainant for the initial mediation hearing.'
    ],
    'kp_9' => [
        'code' => 'KP Form No. 9',
        'title' => 'Summons',
        'subtitle' => 'Patawag sa Inirereklamo',
        'category' => 'Mediation & Summons',
        'description' => 'Statutory summons served to respondent(s) requiring appearance at mediation proceedings.'
    ],
    'kp_10' => [
        'code' => 'KP Form No. 10',
        'title' => 'Notice for Constitute of Pangkat',
        'subtitle' => 'Paunawa sa Pagbubuo ng Pangkat Tagapagkasundo',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Notice issued upon mediation failure to convene parties for 3-member Pangkat selection.'
    ],
    'kp_11' => [
        'code' => 'KP Form No. 11',
        'title' => 'Notice to Chosen Pangkat Member',
        'subtitle' => 'Paunawa sa Napiling Kasapi ng Pangkat',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Written notification to appointed Lupon members constituting the Pangkat panel.'
    ],
    'kp_12' => [
        'code' => 'KP Form No. 12',
        'title' => 'Notice of Hearing (Conciliation Proceedings)',
        'subtitle' => 'Paabiso ng Pagdinig (Pagkakasundo sa Harap ng Pangkat)',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Notice of conciliation proceedings scheduled by the Pangkat Tagapagkasundo.'
    ],
    'kp_13' => [
        'code' => 'KP Form No. 13',
        'title' => 'Subpoena',
        'subtitle' => 'Subpoena / Pautos sa Pagsaksi o Pagharap ng Katibayan',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Compulsory directive commanding witnesses or document presentation at proceedings.'
    ],
    'kp_14' => [
        'code' => 'KP Form No. 14',
        'title' => 'Agreement for Arbitration',
        'subtitle' => 'Kasunduan sa Pag-aarbitro',
        'category' => 'Arbitration & Settlement',
        'description' => 'Formal agreement submitting dispute to binding arbitration by Punong Barangay or Pangkat.'
    ],
    'kp_15' => [
        'code' => 'KP Form No. 15',
        'title' => 'Arbitration Award',
        'subtitle' => 'Gawad ng Arbitrasyon',
        'category' => 'Arbitration & Settlement',
        'description' => 'Binding resolution and award rendered following an arbitration agreement.'
    ],
    'kp_16' => [
        'code' => 'KP Form No. 16',
        'title' => 'Amicable Settlement',
        'subtitle' => 'Kasunduang Pag-aayos',
        'category' => 'Arbitration & Settlement',
        'description' => 'Official compromise agreement executed by parties settling dispute amicably.'
    ],
    'kp_17' => [
        'code' => 'KP Form No. 17',
        'title' => 'Repudiation',
        'subtitle' => 'Pagtanggi o Pagsalungat sa Kasunduan',
        'category' => 'Arbitration & Settlement',
        'description' => 'Sworn repudiation of settlement or arbitration agreement on grounds of fraud or duress.'
    ],
    'kp_18' => [
        'code' => 'KP Form No. 18',
        'title' => 'Notice of Hearing for Complainant (re: Failure to Appear)',
        'subtitle' => 'Paunawa ng Pagdinig ukol sa Di-Pagsipot ng Nagrereklamo',
        'category' => 'Hearings & Compliance',
        'description' => 'Show-cause notice requiring complainant to justify willful nonappearance at hearing.'
    ],
    'kp_19' => [
        'code' => 'KP Form No. 19',
        'title' => 'Notice of Hearing for Respondent (re: Failure to Appear)',
        'subtitle' => 'Paunawa ng Pagdinig ukol sa Di-Pagsipot ng Inirereklamo',
        'category' => 'Hearings & Compliance',
        'description' => 'Show-cause notice requiring respondent to justify willful nonappearance at hearing.'
    ],
    'kp_20' => [
        'code' => 'KP Form No. 20',
        'title' => 'Certificate to File Action (From Lupon Secretary)',
        'subtitle' => 'Katunayan Upang Makadulog sa Hukuman (Mula sa Kalihim ng Lupon)',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Certification issued when mediation fails without settlement before the Punong Barangay.'
    ],
    'kp_20_a' => [
        'code' => 'KP Form No. 20-A',
        'title' => 'Certificate to File Action (From Pangkat Secretary)',
        'subtitle' => 'Katunayan Upang Makadulog sa Hukuman (Mula sa Kalihim ng Pangkat)',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Certification issued when conciliation fails without settlement before the Pangkat panel.'
    ],
    'kp_20_b' => [
        'code' => 'KP Form No. 20-B',
        'title' => 'Certificate to File Action',
        'subtitle' => 'Katunayan Upang Makadulog sa Hukuman (Repudiated Settlement)',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Certification issued when a previous settlement or arbitration award was repudiated.'
    ],
    'kp_21' => [
        'code' => 'KP Form No. 21',
        'title' => 'Certificate to Bar Action',
        'subtitle' => 'Katunayan sa Paghadlang sa Pagsasampa ng Usapin',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Bar certificate issued against complainant for unjustified failure to appear.'
    ],
    'kp_22' => [
        'code' => 'KP Form No. 22',
        'title' => 'Certificate to Bar Action Counterclaim',
        'subtitle' => 'Katunayan sa Paghadlang sa Ganting-Habla',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Bar certificate issued against respondent for unjustified failure to appear.'
    ],
    'kp_23' => [
        'code' => 'KP Form No. 23',
        'title' => 'Motion for Execution',
        'subtitle' => 'Kahilingan sa Pagpapatupad ng Kasunduan o Gawad',
        'category' => 'Execution of Settlement',
        'description' => 'Party motion requesting enforcement of amicable settlement or arbitration award.'
    ],
    'kp_24' => [
        'code' => 'KP Form No. 24',
        'title' => 'Notice of Hearing (re: Motion for Execution)',
        'subtitle' => 'Paunawa ng Pagdinig ukol sa Kahilingan sa Pagpapatupad',
        'category' => 'Execution of Settlement',
        'description' => 'Notice of hearing to resolve party motion for execution of settlement.'
    ],
    'kp_25' => [
        'code' => 'KP Form No. 25',
        'title' => 'Notice of Execution',
        'subtitle' => 'Paunawa ng Pagpapatupad',
        'category' => 'Execution of Settlement',
        'description' => 'Order issued by the Punong Barangay executing the terms of amicable settlement.'
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
    require_once dirname(__DIR__, 2) . '/config/Database.php';
    require_once dirname(__DIR__, 2) . '/models/Setting.php';
    $settingModel = new Setting();
    $settings = $settingModel->getAllSettings();

    $headerTitle = $settings['kp_header_title'] ?? 'REPUBLIKA NG PILIPINAS';
    $lguHeader = $settings['kp_lgu_header'] ?? 'LALAWIGAN NG RIZAL / LUNGSOD NG MARIKINA / BARANGAY TUMANA';
    $paperSize = $settings['document_paper_size'] ?? 'Legal';
    $marginTop = (int)($settings['document_margin_top'] ?? 15);
    $marginBottom = (int)($settings['document_margin_bottom'] ?? 15);
    $marginLeft = (int)($settings['document_margin_left'] ?? 18);
    $marginRight = (int)($settings['document_margin_right'] ?? 18);

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
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
